<?php
/**
 * Unified notification engine.
 *
 * Design rules:
 *  - One entry point: sh_notify($event, $data).
 *  - Each channel is independent; a failure never breaks the caller.
 *  - Nothing is ever reported as "sent" unless the provider confirmed it.
 *  - Credentials are never written to notification_logs.
 */

require_once __DIR__ . '/../config/mail.php';
// The dedicated WhatsApp module owns encrypted-token handling and webhook
// configuration; outbound order media reuses that same configured integration.
require_once __DIR__ . '/whatsapp.php';

const SH_EVENTS = [
    'order_created'     => 'New Order',
    'payment_submitted' => 'Payment Submitted',
    'payment_approved'  => 'Payment Approved',
    'payment_rejected'  => 'Payment Rejected',
    'order_processing'  => 'Order Processing',
    'order_completed'   => 'Order Completed',
    'order_cancelled'   => 'Order Cancelled',
    'code_delivered'    => 'Digital Code Delivered',
    'low_stock'         => 'Low Stock',
];

const SH_CHANNELS = ['telegram', 'whatsapp', 'messenger', 'email'];

/**
 * Add delivery-idempotency fields to the existing notification log without
 * replacing historical records.  The compact 100-character key keeps the
 * composite unique index compatible with older MySQL/MariaDB installations.
 */
function sh_notification_log_schema_ensure(): bool
{
    static $done = false;
    static $ok = false;
    if ($done) { return $ok; }
    $done = true;

    try {
        if (!sh_table_exists('notification_logs')) { return false; }
        $pdo = sh_db();
        $st = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute(['notification_logs']);
        $columns = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        $add = [
            'idempotency_key' => 'VARCHAR(100) DEFAULT NULL AFTER event',
            'attempts'        => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
            'updated_at'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ];
        foreach ($add as $column => $ddl) {
            if (!isset($columns[$column])) {
                $pdo->exec("ALTER TABLE notification_logs ADD COLUMN `$column` $ddl");
            }
        }

        $idx = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $idx->execute(['notification_logs', 'uq_notification_idempotency']);
        if ((int)$idx->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE notification_logs ADD UNIQUE KEY uq_notification_idempotency (channel, event, idempotency_key)');
        }
        $ok = true;
    } catch (Throwable $e) {
        // Notification delivery must never block a paid/order submission.
        sh_log_exception($e, 'notification-log-schema');
    }
    return $ok;
}

/** Only application-generated compact keys may be used for delivery locking. */
function sh_notification_key(string $key): string
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 100 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) { return ''; }
    return $key;
}

/** Reserve a keyed delivery before an outbound API call, preventing duplicates. */
function sh_notification_claim(?int $orderId, string $channel, string $event, string $key, bool $retry = false): array
{
    $key = sh_notification_key($key);
    // Legacy, non-keyed lifecycle messages retain their historical best-effort
    // logging. Initial order/payment and image events always have a key and must
    // fail closed if the persistence lock cannot be established.
    if ($key === '') {
        return ['claimed' => true, 'log_id' => 0];
    }
    if (!sh_notification_log_schema_ensure()) {
        return ['claimed' => false, 'log_id' => 0, 'error' => 'Notification idempotency storage is unavailable; delivery was not attempted.'];
    }

    try {
        $id = sh_insert('notification_logs', [
            'order_id'        => $orderId,
            'channel'         => $channel,
            'event'           => $event,
            'idempotency_key' => $key,
            // "skipped" is a valid legacy ENUM value and is only a short-lived
            // reservation until the provider call below finishes.
            'status'          => 'skipped',
            'attempts'        => 1,
            'error_message'   => 'Delivery in progress.',
        ]);
        return ['claimed' => true, 'log_id' => $id];
    } catch (Throwable $e) {
        try {
            $existing = sh_one(
                'SELECT id, status FROM notification_logs
                 WHERE channel = ? AND event = ? AND idempotency_key = ? LIMIT 1',
                [$channel, $event, $key]
            );
            if ($existing !== null && $retry && (string)$existing['status'] === 'failed') {
                $updated = sh_query(
                    "UPDATE notification_logs
                     SET status = 'skipped', attempts = attempts + 1, error_message = 'Delivery retry in progress.'
                     WHERE id = ? AND status = 'failed'",
                    [(int)$existing['id']]
                )->rowCount();
                if ($updated === 1) { return ['claimed' => true, 'log_id' => (int)$existing['id']]; }
            }
            if ($existing !== null) {
                return ['claimed' => false, 'log_id' => (int)$existing['id'], 'status' => (string)$existing['status']];
            }
        } catch (Throwable $lookupError) {
            sh_log_exception($lookupError, 'notification-claim-lookup');
        }

        // A keyed initial event must not go out without its database reservation:
        // otherwise concurrent checkout/webhook requests could duplicate it.
        sh_log_exception($e, 'notification-claim');
        return ['claimed' => false, 'log_id' => 0, 'error' => 'Notification idempotency storage failed; delivery was not attempted.'];
    }
}

/** Finish a delivery reservation with the provider's final outcome. */
function sh_notification_finish_claim(int $logId, string $status, ?string $recipient = null, ?string $error = null): void
{
    if ($logId <= 0) { return; }
    try {
        sh_update('notification_logs', [
            'recipient'     => $recipient !== null ? mb_substr(sh_scrub_secrets($recipient), 0, 190) : null,
            'status'        => in_array($status, ['sent', 'failed', 'skipped'], true) ? $status : 'failed',
            'error_message' => $error !== null ? mb_substr(sh_scrub_secrets($error), 0, 500) : null,
        ], 'id = ?', [$logId]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'notification-finish');
    }
}

function sh_notification_matrix(): array
{
    $m = [];
    try {
        foreach (sh_all('SELECT channel, event, enabled FROM notifications') as $r) {
            $m[$r['channel']][$r['event']] = (int)$r['enabled'] === 1;
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'notify-matrix');
    }
    return $m;
}

function sh_notification_enabled(string $channel, string $event): bool
{
    static $m = null;
    if ($m === null) { $m = sh_notification_matrix(); }
    return !empty($m[$channel][$event]);
}

function sh_notify_log(?int $orderId, string $channel, string $event, string $status, ?string $recipient = null, ?string $error = null): void
{
    try {
        $data = [
            'order_id'      => $orderId,
            'channel'       => $channel,
            'event'         => $event,
            'recipient'     => $recipient !== null ? mb_substr(sh_scrub_secrets($recipient), 0, 190) : null,
            'status'        => in_array($status, ['sent', 'failed', 'skipped'], true) ? $status : 'failed',
            'error_message' => $error !== null ? mb_substr(sh_scrub_secrets($error), 0, 500) : null,
        ];
        if (sh_notification_log_schema_ensure()) { $data['attempts'] = 1; }
        sh_insert('notification_logs', $data);
    } catch (Throwable $e) {
        sh_log_exception($e, 'notify-log');
    }
}

/** Events whose delivery is one order summary plus supporting per-item media. */
function sh_is_order_media_event(string $event): bool
{
    return in_array($event, ['order_created', 'payment_submitted'], true);
}

/**
 * Rehydrate an initial-order payload at dispatch time. This deliberately ignores
 * browser-provided names, prices, images and totals. `order_created` is allowed
 * only for a persisted COD payment record; this is a second server-side barrier
 * in addition to the trigger in sh_create_order().
 *
 * @return array{ok:bool,payload?:array,error?:string}
 */
function sh_notification_order_event_payload(string $event, array $data): array
{
    if (!sh_is_order_media_event($event)) {
        return ['ok' => false, 'error' => 'This event does not carry order media.'];
    }
    $orderId = (int)($data['order_id'] ?? 0);
    $order = $orderId > 0 && function_exists('sh_order_get') ? sh_order_get($orderId) : null;
    if ($order === null) { return ['ok' => false, 'error' => 'Saved order details were not found.']; }

    $payment = function_exists('sh_order_latest_payment')
        ? sh_order_latest_payment($orderId)
        : sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);

    if ($event === 'order_created') {
        if (!function_exists('sh_order_is_cod') || !sh_order_is_cod($order, $payment)) {
            return ['ok' => false, 'error' => 'Initial order notifications are suppressed until payment submission for non-COD orders.'];
        }
        if (!function_exists('sh_order_created_notify_payload')) {
            return ['ok' => false, 'error' => 'COD order notification support is unavailable.'];
        }
        $payload = sh_order_created_notify_payload($order, $payment);
    } else {
        $paymentId = (int)($data['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $payment = sh_one('SELECT * FROM payments WHERE id = ? AND order_id = ? LIMIT 1', [$paymentId, $orderId]);
        }
        if ($payment === null || (trim((string)($payment['transaction_id'] ?? '')) === ''
            && trim((string)($payment['gateway_reference'] ?? '')) === '')) {
            return ['ok' => false, 'error' => 'Saved payment details were not found.'];
        }
        if (function_exists('sh_order_is_cod') && sh_order_is_cod($order, $payment)) {
            return ['ok' => false, 'error' => 'COD orders use the one order-created notification and do not emit payment-submitted notifications.'];
        }
        if (!function_exists('sh_payment_submitted_notify_payload')) {
            return ['ok' => false, 'error' => 'Payment notification support is unavailable.'];
        }
        $payload = sh_payment_submitted_notify_payload($order, $payment);
    }

    // A retry must continue to represent the same persisted event. Never turn a
    // stale failed row into a later payment submission after a customer resubmits.
    $requestedKey = sh_notification_key((string)($data['notification_key'] ?? ''));
    if ($requestedKey !== '' && !hash_equals($requestedKey, (string)$payload['notification_key'])) {
        return ['ok' => false, 'error' => 'A newer order or payment event exists; the older notification will not be sent.'];
    }
    return ['ok' => true, 'payload' => $payload];
}

/** Notification-log event name for one supporting product image. */
function sh_order_media_log_event(string $event): string
{
    return $event . '_image';
}

/** Stable key for an individual saved order-item image, per channel/event version. */
function sh_order_media_key(array $payload, array $item): string
{
    $base = sh_notification_key((string)($payload['notification_key'] ?? ''));
    $itemId = (int)($item['id'] ?? 0);
    if ($base === '' || $itemId <= 0) { return ''; }
    return sh_notification_key($base . ':item:' . $itemId);
}

/**
 * Reserve one supporting image delivery and resolve its genuine public URL.
 * Every image gets an independent log/key so a failed photo can later be retried
 * without re-sending the already-confirmed full order summary.
 */
function sh_notification_prepare_order_image(string $channel, string $event, array $payload, array $item, bool $retry = false): array
{
    $key = sh_order_media_key($payload, $item);
    $logEvent = sh_order_media_log_event($event);
    $claim = sh_notification_claim((int)($payload['order_id'] ?? 0), $channel, $logEvent, $key, $retry);
    if (empty($claim['claimed'])) {
        return [
            'ok' => empty($claim['error']),
            'claimed' => false,
            'log_id' => (int)($claim['log_id'] ?? 0),
            'error' => (string)($claim['error'] ?? ''),
        ];
    }

    $url = function_exists('sh_order_item_public_image_url') ? sh_order_item_public_image_url($item) : '';
    if ($url === '') {
        $error = 'No public HTTPS image is available for this saved order item.';
        sh_notification_finish_claim((int)($claim['log_id'] ?? 0), 'failed', null, $error);
        return ['ok' => false, 'claimed' => true, 'log_id' => (int)($claim['log_id'] ?? 0), 'error' => $error];
    }
    return ['ok' => true, 'claimed' => true, 'log_id' => (int)($claim['log_id'] ?? 0), 'url' => $url, 'key' => $key];
}

/** Mark a reserved supporting image using the provider-confirmed outcome. */
function sh_notification_finish_order_image(array $prepared, bool $sent, ?string $recipient = null, ?string $error = null): void
{
    if (empty($prepared['claimed'])) { return; }
    sh_notification_finish_claim(
        (int)($prepared['log_id'] ?? 0),
        $sent ? 'sent' : 'failed',
        $recipient,
        $sent ? $error : ($error ?? 'The provider did not accept this product image.')
    );
}

/**
 * Record that an available item image was intentionally not sent because a
 * channel lacks a real attachment/media API. This is used for the existing
 * mailer, which supports text/HTML but not MIME image attachments. It never
 * marks a picture as delivered.
 */
function sh_notification_skip_order_images(string $channel, string $event, array $payload, ?string $recipient, string $reason, bool $retry = false): array
{
    $skipped = 0;
    $failures = [];
    foreach ((is_array($payload['items'] ?? null) ? $payload['items'] : []) as $item) {
        if (!is_array($item)) { continue; }
        $prepared = sh_notification_prepare_order_image($channel, $event, $payload, $item, $retry);
        if (empty($prepared['claimed'])) {
            if (empty($prepared['ok']) && !empty($prepared['error'])) { $failures[] = (string)$prepared['error']; }
            continue;
        }
        // prepare() already marks absent/non-public product files failed.
        if (empty($prepared['ok']) || empty($prepared['url'])) {
            $failures[] = (string)($prepared['error'] ?? 'No public product image is available.');
            continue;
        }
        sh_notification_finish_claim((int)$prepared['log_id'], 'skipped', $recipient, $reason);
        $skipped++;
    }
    return ['skipped' => $skipped, 'failures' => array_values(array_unique($failures))];
}

/** Short plain-text caption that stays associated with one ordered product. */
function sh_order_media_caption(array $payload, array $item, int $number, int $total): string
{
    $clip = static function ($value, int $limit): string {
        $value = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? (string)$value);
        return mb_strlen($value) > $limit ? mb_substr($value, 0, max(1, $limit - 1)) . '…' : $value;
    };
    $name = $clip($item['product_name'] ?? $item['name'] ?? 'Item', 160);
    $variant = function_exists('sh_order_item_variant_text') ? sh_order_item_variant_text($item) : (string)($item['product_variant'] ?? '');
    $lines = [
        'Order #' . $clip($payload['order_number'] ?? '—', 80) . ' · Item ' . $number . ' of ' . $total,
        'Product: ' . ($name !== '' ? $name : 'Item'),
    ];
    if (trim($variant) !== '') { $lines[] = 'Package: ' . $clip($variant, 140); }
    $lines[] = 'Quantity: ' . max(0, (int)($item['quantity'] ?? 0));
    $lines[] = 'Unit price: ' . sh_money($item['unit_price'] ?? 0);
    $lines[] = 'Subtotal: ' . sh_money($item['line_total'] ?? 0);
    return implode("\n", $lines);
}

/** Split only when a provider's text ceiling requires a continuation. */
function sh_notification_text_chunks(string $text, int $limit, string $continuation): array
{
    $limit = max(200, $limit);
    if (mb_strlen($text) <= $limit) { return [$text]; }
    $chunks = [];
    $current = '';
    foreach (explode("\n", $text) as $line) {
        $candidate = $current === '' ? $line : $current . "\n" . $line;
        if (mb_strlen($candidate) > $limit && $current !== '') {
            $chunks[] = $current;
            $current = $continuation;
            $candidate = $current . "\n" . $line;
        }
        if (mb_strlen($candidate) > $limit) {
            $line = mb_substr($line, 0, max(1, $limit - mb_strlen($current) - 2)) . '…';
            $candidate = $current === '' ? $line : $current . "\n" . $line;
        }
        $current = $candidate;
    }
    if ($current !== '') { $chunks[] = $current; }
    return $chunks ?: [mb_substr($text, 0, $limit)];
}

/**
 * Main dispatcher. Returns a per-channel result map.
 * Never throws — the calling order/payment flow must always continue.
 */
function sh_notify(string $event, array $data = []): array
{
    $results = [];
    if (!isset(SH_EVENTS[$event])) {
        sh_log_line('notify', 'Unknown notification event: ' . $event);
        return $results;
    }

    // Initial-order events are always rebuilt after their database transaction.
    // This makes the dispatcher safe even if an old controller, webhook or bot
    // handler supplies a forged browser payload directly.
    $isOrderMediaEvent = sh_is_order_media_event($event);
    $isRetry = !empty($data['retry_notification']);
    $retryChannel = trim((string)($data['retry_channel'] ?? ''));
    if ($isOrderMediaEvent) {
        $rebuilt = sh_notification_order_event_payload($event, $data);
        if (empty($rebuilt['ok']) || empty($rebuilt['payload']) || !is_array($rebuilt['payload'])) {
            $error = (string)($rebuilt['error'] ?? 'The saved order notification could not be rebuilt.');
            sh_log_line('notify', $event . ' suppressed: ' . sh_scrub_secrets($error));
            foreach (SH_CHANNELS as $channel) {
                $results[$channel] = ['status' => 'skipped', 'error' => $error];
            }
            return $results;
        }
        $data = $rebuilt['payload'];
        // Retry routing is operational metadata, not notification content.
        if ($isRetry) { $data['retry_notification'] = true; }
        if ($retryChannel !== '') { $data['retry_channel'] = $retryChannel; }
    }

    $orderId = isset($data['order_id']) ? (int)$data['order_id'] : null;
    $message = sh_notify_message($event, $data);
    $notificationKey = sh_notification_key((string)($data['notification_key'] ?? ''));

    foreach (SH_CHANNELS as $channel) {
        $claim = ['claimed' => true, 'log_id' => 0];
        try {
            if ($isRetry && $retryChannel !== '' && $channel !== $retryChannel) {
                $results[$channel] = ['status' => 'skipped', 'error' => 'Not selected for this channel-specific retry.'];
                continue;
            }
            if (!sh_notification_enabled($channel, $event)) {
                $results[$channel] = ['status' => 'skipped', 'error' => 'Event disabled for this channel.'];
                continue;
            }

            $claim = sh_notification_claim(
                $orderId,
                $channel,
                $event,
                $notificationKey,
                $isRetry && ($retryChannel === '' || $retryChannel === $channel)
            );
            if (empty($claim['claimed'])) {
                $results[$channel] = [
                    'status' => 'skipped',
                    'error' => (string)($claim['error'] ?? 'This notification event was already delivered or is being processed.'),
                ];
                continue;
            }

            $res = match ($channel) {
                // These send one validated full summary first, then each real
                // item image through the provider's native media API. The
                // independent image claims make retries precise and race-safe.
                'telegram'  => $isOrderMediaEvent && function_exists('sh_tg_send_order_event')
                    ? sh_tg_send_order_event($event, $data, $isRetry)
                    : ($isOrderMediaEvent
                        ? ['ok' => false, 'error' => 'Telegram order-media support is unavailable.']
                        : sh_send_telegram($message['text'])),
                'whatsapp'  => $isOrderMediaEvent
                    ? sh_send_whatsapp_order_event($event, $message, $data, $isRetry)
                    : sh_send_whatsapp($message['text'], $data),
                'messenger' => $isOrderMediaEvent
                    ? sh_send_messenger_order_event($event, $message, $data, $isRetry)
                    : sh_send_messenger($message['text']),
                'email'     => sh_send_email_notification($event, $message, $data),
                default     => ['ok' => false, 'error' => 'Unknown channel.', 'skipped' => true],
            };

            $logId = (int)($claim['log_id'] ?? 0);
            if (!empty($res['skipped'])) {
                $error = (string)($res['error'] ?? 'Not configured.');
                $results[$channel] = ['status' => 'skipped', 'error' => $error];
                if ($logId > 0) { sh_notification_finish_claim($logId, 'skipped', $res['recipient'] ?? null, $error); }
                else { sh_notify_log($orderId, $channel, $event, 'skipped', $res['recipient'] ?? null, $error); }
            } elseif (!empty($res['ok'])) {
                // The summary can be confirmed while one image fails. Keep the
                // warning actionable; each failed image also has its own log.
                $warning = !empty($res['warning']) ? (string)$res['warning'] : null;
                $results[$channel] = ['status' => 'sent', 'error' => $warning];
                if ($logId > 0) { sh_notification_finish_claim($logId, 'sent', $res['recipient'] ?? null, $warning); }
                else { sh_notify_log($orderId, $channel, $event, 'sent', $res['recipient'] ?? null, $warning); }
            } else {
                $error = (string)($res['error'] ?? 'Unknown error.');
                $results[$channel] = ['status' => 'failed', 'error' => $error];
                if ($logId > 0) { sh_notification_finish_claim($logId, 'failed', $res['recipient'] ?? null, $error); }
                else { sh_notify_log($orderId, $channel, $event, 'failed', $res['recipient'] ?? null, $error); }
            }
        } catch (Throwable $e) {
            // A channel blowing up must never abort the order.
            sh_log_exception($e, 'notify-' . $channel);
            $error = sh_scrub_secrets($e->getMessage());
            $results[$channel] = ['status' => 'failed', 'error' => $error];
            $logId = (int)($claim['log_id'] ?? 0);
            if ($logId > 0) { sh_notification_finish_claim($logId, 'failed', null, $error); }
            else { sh_notify_log($orderId, $channel, $event, 'failed', null, $error); }
        }
    }
    return $results;
}

/**
 * Safely retry one failed initial-order summary or one failed supporting image.
 * The log's event/key are verified against current persisted records before any
 * provider call, so a stale retry cannot send a later payment submission.
 */
function sh_retry_order_notification(int $logId): array
{
    if ($logId <= 0 || !sh_notification_log_schema_ensure()) {
        return ['ok' => false, 'error' => 'This notification cannot be retried yet.'];
    }

    try {
        $log = sh_one('SELECT * FROM notification_logs WHERE id = ? LIMIT 1', [$logId]);
        if ($log === null) { return ['ok' => false, 'error' => 'Notification record not found.']; }
        if ((string)($log['status'] ?? '') !== 'failed') {
            return ['ok' => false, 'error' => 'Only failed deliveries can be retried.'];
        }
        $channel = (string)($log['channel'] ?? '');
        if (!in_array($channel, ['telegram', 'whatsapp', 'messenger'], true)) {
            return ['ok' => false, 'error' => 'This channel has no supported product-media retry operation.'];
        }
        $loggedEvent = (string)($log['event'] ?? '');
        $isImage = str_ends_with($loggedEvent, '_image');
        $event = $isImage ? substr($loggedEvent, 0, -6) : $loggedEvent;
        if (!sh_is_order_media_event($event)) {
            return ['ok' => false, 'error' => 'This is not an initial order notification delivery.'];
        }
        if ((int)($log['order_id'] ?? 0) <= 0 || sh_notification_key((string)($log['idempotency_key'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'This legacy notification has no safe retry key.'];
        }
        if (!sh_notification_enabled($channel, $event)) {
            return ['ok' => false, 'error' => 'This event is currently disabled for ' . ucfirst($channel) . '.'];
        }

        if (!$isImage) {
            // sh_notify rehydrates and checks the exact event key again.
            $result = sh_notify($event, [
                'order_id' => (int)$log['order_id'],
                'notification_key' => (string)$log['idempotency_key'],
                'retry_notification' => true,
                'retry_channel' => $channel,
            ]);
            $delivery = $result[$channel] ?? ['status' => 'failed', 'error' => 'The provider delivery was not attempted.'];
            return [
                'ok' => (string)($delivery['status'] ?? '') === 'sent',
                'error' => (string)($delivery['error'] ?? ''),
            ];
        }

        // Image retries intentionally bypass the summary sender. Find the saved
        // item by recomputing its stable key; never trust an ID parsed from a log.
        $rebuilt = sh_notification_order_event_payload($event, ['order_id' => (int)$log['order_id']]);
        if (empty($rebuilt['ok']) || empty($rebuilt['payload']) || !is_array($rebuilt['payload'])) {
            return ['ok' => false, 'error' => (string)($rebuilt['error'] ?? 'The saved order notification could not be rebuilt.')];
        }
        $payload = $rebuilt['payload'];
        $item = null;
        foreach (($payload['items'] ?? []) as $candidate) {
            if (is_array($candidate) && hash_equals((string)$log['idempotency_key'], sh_order_media_key($payload, $candidate))) {
                $item = $candidate;
                break;
            }
        }
        if ($item === null) {
            return ['ok' => false, 'error' => 'This image no longer belongs to the current saved order event.'];
        }
        $message = sh_notify_message($event, $payload);
        $itemId = (int)($item['id'] ?? 0);
        $delivery = match ($channel) {
            'telegram' => function_exists('sh_tg_send_order_event')
                ? sh_tg_send_order_event($event, $payload, true, $itemId, false)
                : ['ok' => false, 'error' => 'Telegram order-media support is unavailable.'],
            'whatsapp' => sh_send_whatsapp_order_event($event, $message, $payload, true, $itemId, false),
            'messenger' => sh_send_messenger_order_event($event, $message, $payload, true, $itemId, false),
            default => ['ok' => false, 'error' => 'Unsupported channel.'],
        };
        return [
            'ok' => !empty($delivery['ok']),
            'error' => (string)($delivery['warning'] ?? $delivery['error'] ?? ''),
        ];
    } catch (Throwable $e) {
        sh_log_exception($e, 'notification-retry');
        return ['ok' => false, 'error' => 'The notification could not be retried.'];
    }
}

/** Backward-compatible entry point used by the original Telegram-only admin UI. */
function sh_retry_payment_submitted_telegram(int $logId): array
{
    return sh_retry_order_notification($logId);
}

/** Builds the human-readable message body for an event. */
function sh_notify_message(string $event, array $data): array
{
    $site = sh_setting('site_name', 'ShopHaat');
    $title = SH_EVENTS[$event] . ' - ' . $site;
    $lines = [strtoupper(SH_EVENTS[$event]), str_repeat('-', 28)];

    if (!empty($data['order_number'])) { $lines[] = 'Order: ' . $data['order_number']; }
    if (!empty($data['customer_name'])) { $lines[] = 'Customer: ' . $data['customer_name']; }
    if (!empty($data['customer_username'])) { $lines[] = 'Username: ' . $data['customer_username']; }
    if (!empty($data['customer_phone'])) { $lines[] = 'Phone: ' . $data['customer_phone']; }
    if (!empty($data['customer_email']) && (!function_exists('sh_is_synthetic_email') || !sh_is_synthetic_email((string)$data['customer_email']))) {
        $lines[] = 'Email: ' . $data['customer_email'];
    }
    $address = array_filter([
        (string)($data['shipping_address'] ?? ''), (string)($data['shipping_area'] ?? ''),
        (string)($data['shipping_city'] ?? ''), (string)($data['shipping_postcode'] ?? ''),
    ], static fn(string $part): bool => trim($part) !== '');
    if ($address) { $lines[] = 'Delivery address: ' . implode(', ', $address); }
    if (!empty($data['order_created_at'])) { $lines[] = 'Order placed: ' . $data['order_created_at']; }
    if (!empty($data['order_note'])) { $lines[] = 'Order note: ' . $data['order_note']; }
    if (isset($data['subtotal'])) { $lines[] = 'Items subtotal: ' . sh_money($data['subtotal']); }
    if (isset($data['discount'])) { $lines[] = 'Discount: ' . sh_money($data['discount']); }
    if (isset($data['delivery_fee'])) { $lines[] = 'Delivery fee: ' . sh_money($data['delivery_fee']); }
    if (isset($data['total'])) { $lines[] = 'Amount: ' . sh_money($data['total']); }
    if (!empty($data['coupon_code'])) { $lines[] = 'Coupon: ' . $data['coupon_code']; }
    if (!empty($data['payment_method'])) { $lines[] = 'Payment method: ' . $data['payment_method']; }
    if (isset($data['payment_amount'])) { $lines[] = 'Payment amount: ' . sh_money($data['payment_amount']); }
    if (!empty($data['transaction_id'])) { $lines[] = 'Transaction ID: ' . $data['transaction_id']; }
    if (!empty($data['gateway_reference'])) { $lines[] = 'Gateway reference: ' . $data['gateway_reference']; }
    if (!empty($data['sender_phone'])) { $lines[] = 'Sender phone: ' . $data['sender_phone']; }
    if (!empty($data['payment_submitted_at'])) { $lines[] = 'Payment submitted: ' . $data['payment_submitted_at']; }
    if (!empty($data['payment_status'])) { $lines[] = 'Payment status: ' . sh_status_label((string)$data['payment_status']); }
    if (!empty($data['status'])) { $lines[] = 'Order status: ' . sh_status_label((string)$data['status']); }
    if (!empty($data['items']) && is_array($data['items'])) {
        $itemCount = 0; $quantityCount = 0;
        foreach ($data['items'] as $it) {
            if (is_array($it)) { $itemCount++; $quantityCount += max(0, (int)($it['quantity'] ?? 0)); }
        }
        $lines[] = 'Different products: ' . $itemCount;
        $lines[] = 'Total item quantity: ' . $quantityCount;
        $lines[] = 'Items:';
        foreach ($data['items'] as $index => $it) {
            if (!is_array($it)) { continue; }
            $line = '  ' . ((int)$index + 1) . '. ' . ($it['product_name'] ?? $it['name'] ?? 'Item')
                . ' — Qty ' . (int)($it['quantity'] ?? 1)
                . ' · Unit ' . sh_money($it['unit_price'] ?? 0)
                . ' · Subtotal ' . sh_money($it['line_total'] ?? 0);
            $variant = function_exists('sh_order_item_variant_text') ? sh_order_item_variant_text($it) : (string)($it['product_variant'] ?? '');
            if ($variant !== '') { $line .= ' · Package ' . $variant; }
            $lines[] = $line;
        }
    }
    if (!empty($data['codes']) && is_array($data['codes'])) {
        $lines[] = 'Delivered codes: ' . count($data['codes']);
    }
    if (!empty($data['product_name'])) { $lines[] = 'Product: ' . $data['product_name']; }
    if (isset($data['stock'])) { $lines[] = 'Remaining stock: ' . (int)$data['stock']; }
    if (!empty($data['note'])) { $lines[] = 'Note: ' . $data['note']; }
    $lines[] = 'Time: ' . date('d M Y, h:i A');

    return ['title' => $title, 'text' => implode("\n", $lines)];
}

// ---------------------------------------------------------------------------
// HTTP helper (cURL with a stream fallback for hosts without cURL)
// ---------------------------------------------------------------------------
function sh_http_post(string $url, $body, array $headers = [], int $timeout = 15): array
{
    $isJson = is_array($body) && !isset($body['__form']);
    if (is_string($body)) {
        $payload = $body;
    } elseif ($isJson) {
        // Telegram's sendMediaGroup accepts a real JSON media array. Substitute
        // malformed legacy UTF-8 rather than issuing an empty/broken request.
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not encode the notification request.'];
        }
    } else {
        $payload = http_build_query($body);
    }
    if ($isJson) { $headers[] = 'Content-Type: application/json'; }
    elseif (!is_string($body)) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) { return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err ?: 'Connection failed.']; }
        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => (string)$res, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $payload,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    // Network failure is an expected outcome here and is reported honestly to the caller.
    set_error_handler(static fn(): bool => true);
    try {
        $res = file_get_contents($url, false, $ctx);
    } finally {
        restore_error_handler();
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    if ($res === false) { return ['ok' => false, 'status' => $code, 'body' => '', 'error' => 'Connection failed.']; }
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => (string)$res, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
}

// ---------------------------------------------------------------------------
// Telegram Bot API
// ---------------------------------------------------------------------------
function sh_send_telegram(string $text): array
{
    if ((string)sh_setting('telegram_enabled', '0') !== '1') {
        return ['skipped' => true, 'error' => 'Telegram integration is disabled.'];
    }
    $token = trim((string)sh_setting('telegram_bot_token', ''));
    $chat = trim((string)sh_setting('telegram_chat_id', ''));
    if ($token === '' || $chat === '') {
        $miss = [];
        if ($token === '') { $miss[] = 'bot token'; }
        if ($chat === '') { $miss[] = 'chat ID'; }
        return ['skipped' => true, 'error' => 'Telegram is missing: ' . implode(' and ', $miss) . '.'];
    }
    // Endpoint is overridable so the delivery pipeline can be tested against a local
    // mock server. Defaults to the real Telegram API.
    $base = rtrim((string)sh_setting('telegram_api_base', 'https://api.telegram.org'), '/');
    $res = sh_http_post(
        // The token contains a colon and must NOT be URL-encoded, or Telegram returns 404.
        $base . '/bot' . $token . '/sendMessage',
        ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true]
    );
    $json = json_decode($res['body'], true);
    if ($res['ok'] && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'recipient' => 'chat:' . $chat];
    }
    $err = is_array($json) && !empty($json['description']) ? $json['description'] : ($res['error'] ?: 'Telegram API rejected the request.');
    return ['ok' => false, 'error' => $err, 'recipient' => 'chat:' . $chat];
}

// ---------------------------------------------------------------------------
// WhatsApp Cloud API (Meta)
// ---------------------------------------------------------------------------
/** Canonical Graph base; a server-only override remains available for provider mocks. */
function sh_meta_graph_base(string $version = 'v21.0'): string
{
    $override = rtrim(trim((string)sh_setting('meta_api_base', '')), '/');
    if ($override !== '') { return $override; }
    if (preg_match('/^v\d+\.\d+$/', $version) !== 1) { $version = 'v21.0'; }
    return 'https://graph.facebook.com/' . $version;
}

/** Return WhatsApp's existing encrypted-token configuration for server-side use. */
function sh_whatsapp_outbound_config(): array
{
    $cfg = function_exists('sh_wa_config') ? sh_wa_config(true) : [];
    if (empty($cfg['enabled'])) {
        return ['ok' => false, 'skipped' => true, 'error' => 'WhatsApp integration is disabled.'];
    }
    $phoneId = trim((string)($cfg['phone_number_id'] ?? ''));
    $token = trim((string)($cfg['access_token'] ?? ''));
    $to = function_exists('sh_wa_clean_phone')
        ? sh_wa_clean_phone((string)($cfg['recipient'] ?? ''))
        : (preg_replace('/\D+/', '', (string)($cfg['recipient'] ?? '')) ?? '');
    if ($phoneId === '' || $token === '' || $to === '') {
        $missing = [];
        if ($phoneId === '') { $missing[] = 'phone number ID'; }
        if ($token === '') { $missing[] = 'access token'; }
        if ($to === '') { $missing[] = 'recipient number'; }
        return ['ok' => false, 'skipped' => true, 'error' => 'WhatsApp is missing: ' . implode(', ', $missing) . '.'];
    }
    return [
        'ok' => true,
        'phone_id' => $phoneId,
        'token' => $token,
        'recipient' => $to,
        'endpoint' => sh_meta_graph_base((string)($cfg['api_version'] ?? 'v21.0')) . '/' . rawurlencode($phoneId) . '/messages',
    ];
}

/** Send a real Cloud API message and accept success only when Meta returns a message id. */
function sh_whatsapp_send_payload(array $cfg, array $message): array
{
    $body = array_merge([
        'messaging_product' => 'whatsapp',
        'to' => (string)$cfg['recipient'],
    ], $message);
    try {
        $res = sh_http_post((string)$cfg['endpoint'], $body, ['Authorization: Bearer ' . (string)$cfg['token']]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'WhatsApp request failed.', 'recipient' => (string)$cfg['recipient']];
    }
    $json = json_decode((string)($res['body'] ?? ''), true);
    if (!empty($res['ok']) && is_array($json) && !empty($json['messages']) && is_array($json['messages'])) {
        return ['ok' => true, 'recipient' => (string)$cfg['recipient'], 'provider_id' => (string)($json['messages'][0]['id'] ?? '')];
    }
    $error = is_array($json) && !empty($json['error']['message'])
        ? (string)$json['error']['message']
        : (string)($res['error'] ?? 'WhatsApp API rejected the request.');
    return ['ok' => false, 'error' => sh_scrub_secrets($error), 'recipient' => (string)$cfg['recipient']];
}

function sh_send_whatsapp(string $text, array $data = []): array
{
    $cfg = sh_whatsapp_outbound_config();
    if (empty($cfg['ok'])) { return $cfg; }
    return sh_whatsapp_send_payload($cfg, [
        'type' => 'text',
        'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
    ]);
}

/**
 * Send one complete saved order summary, then every available product image via
 * WhatsApp's native `image.link` Cloud API payload. Direct messages may be
 * rejected by Meta outside its allowed conversation/template window; that real
 * API response is logged as a failure rather than claimed as delivery.
 */
function sh_send_whatsapp_order_event(string $event, array $message, array $payload, bool $retry = false, ?int $onlyItemId = null, bool $sendSummary = true): array
{
    $cfg = sh_whatsapp_outbound_config();
    if (empty($cfg['ok'])) { return $cfg; }
    $recipient = (string)$cfg['recipient'];

    if ($sendSummary) {
        foreach (sh_notification_text_chunks((string)$message['text'], 4000, strtoupper(SH_EVENTS[$event] ?? 'ORDER') . ' — continued') as $chunk) {
            $sent = sh_whatsapp_send_payload($cfg, [
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $chunk],
            ]);
            if (empty($sent['ok'])) {
                return ['ok' => false, 'error' => (string)($sent['error'] ?? 'WhatsApp rejected the order summary.'), 'recipient' => $recipient];
            }
        }
    }

    $warnings = [];
    $items = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
    $total = count($items);
    $matched = $onlyItemId === null;
    foreach ($items as $index => $item) {
        if (!is_array($item)) { continue; }
        if ($onlyItemId !== null && (int)($item['id'] ?? 0) !== $onlyItemId) { continue; }
        $matched = true;
        $prepared = sh_notification_prepare_order_image('whatsapp', $event, $payload, $item, $retry);
        if (empty($prepared['claimed'])) {
            if (empty($prepared['ok']) && !empty($prepared['error'])) { $warnings[] = (string)$prepared['error']; }
            continue;
        }
        if (empty($prepared['ok']) || empty($prepared['url'])) {
            $warnings[] = (string)($prepared['error'] ?? 'No public image is available for item ' . ($index + 1) . '.');
            continue;
        }
        $caption = mb_substr(sh_order_media_caption($payload, $item, $index + 1, $total), 0, 1024);
        $sent = sh_whatsapp_send_payload($cfg, [
            'type' => 'image',
            'image' => ['link' => (string)$prepared['url'], 'caption' => $caption],
        ]);
        if (!empty($sent['ok'])) {
            sh_notification_finish_order_image($prepared, true, $recipient);
        } else {
            $error = (string)($sent['error'] ?? 'WhatsApp rejected this product image.');
            sh_notification_finish_order_image($prepared, false, $recipient, $error);
            $warnings[] = 'Image for item ' . ($index + 1) . ' failed: ' . $error;
        }
    }
    if (!$matched) { return ['ok' => false, 'error' => 'The saved order item was not found.', 'recipient' => $recipient]; }
    return [
        'ok' => true,
        'recipient' => $recipient,
        'warning' => $warnings ? implode(' ', array_slice(array_values(array_unique($warnings)), 0, 8)) : null,
    ];
}

// ---------------------------------------------------------------------------
// Messenger (Meta Send API)
// ---------------------------------------------------------------------------
function sh_messenger_outbound_config(): array
{
    if ((string)sh_setting('messenger_enabled', '0') !== '1') {
        return ['ok' => false, 'skipped' => true, 'error' => 'Messenger integration is disabled.'];
    }
    $token = trim((string)sh_setting('messenger_token', ''));
    $recipient = trim((string)sh_setting('messenger_recipient', ''));
    if ($token === '' || $recipient === '') {
        $missing = [];
        if ($token === '') { $missing[] = 'page access token'; }
        if ($recipient === '') { $missing[] = 'recipient PSID'; }
        return ['ok' => false, 'skipped' => true, 'error' => 'Messenger is missing: ' . implode(' and ', $missing) . '.'];
    }
    return [
        'ok' => true,
        'token' => $token,
        'recipient' => $recipient,
        'endpoint' => sh_meta_graph_base('v21.0') . '/me/messages?access_token=' . rawurlencode($token),
    ];
}

/** Meta Send API reports a message_id only when it accepts the outbound message. */
function sh_messenger_send_payload(array $cfg, array $message): array
{
    $body = [
        'recipient' => ['id' => (string)$cfg['recipient']],
        'messaging_type' => 'MESSAGE_TAG',
        'tag' => 'ACCOUNT_UPDATE',
        'message' => $message,
    ];
    try {
        $res = sh_http_post((string)$cfg['endpoint'], $body);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Messenger request failed.', 'recipient' => (string)$cfg['recipient']];
    }
    $json = json_decode((string)($res['body'] ?? ''), true);
    if (!empty($res['ok']) && is_array($json) && !empty($json['message_id'])) {
        return ['ok' => true, 'recipient' => (string)$cfg['recipient'], 'provider_id' => (string)$json['message_id']];
    }
    $error = is_array($json) && !empty($json['error']['message'])
        ? (string)$json['error']['message']
        : (string)($res['error'] ?? 'Messenger API rejected the request.');
    return ['ok' => false, 'error' => sh_scrub_secrets($error), 'recipient' => (string)$cfg['recipient']];
}

function sh_send_messenger(string $text): array
{
    $cfg = sh_messenger_outbound_config();
    if (empty($cfg['ok'])) { return $cfg; }
    return sh_messenger_send_payload($cfg, ['text' => mb_substr($text, 0, 2000)]);
}

/**
 * Messenger attachments do not support an image caption field. A short saved
 * product caption is therefore sent separately, followed by a native `image`
 * attachment. It is never another full per-product order summary.
 */
function sh_send_messenger_order_event(string $event, array $message, array $payload, bool $retry = false, ?int $onlyItemId = null, bool $sendSummary = true): array
{
    $cfg = sh_messenger_outbound_config();
    if (empty($cfg['ok'])) { return $cfg; }
    $recipient = (string)$cfg['recipient'];

    if ($sendSummary) {
        foreach (sh_notification_text_chunks((string)$message['text'], 1900, strtoupper(SH_EVENTS[$event] ?? 'ORDER') . ' — continued') as $chunk) {
            $sent = sh_messenger_send_payload($cfg, ['text' => $chunk]);
            if (empty($sent['ok'])) {
                return ['ok' => false, 'error' => (string)($sent['error'] ?? 'Messenger rejected the order summary.'), 'recipient' => $recipient];
            }
        }
    }

    $warnings = [];
    $items = is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
    $total = count($items);
    $matched = $onlyItemId === null;
    foreach ($items as $index => $item) {
        if (!is_array($item)) { continue; }
        if ($onlyItemId !== null && (int)($item['id'] ?? 0) !== $onlyItemId) { continue; }
        $matched = true;
        $prepared = sh_notification_prepare_order_image('messenger', $event, $payload, $item, $retry);
        if (empty($prepared['claimed'])) {
            if (empty($prepared['ok']) && !empty($prepared['error'])) { $warnings[] = (string)$prepared['error']; }
            continue;
        }
        if (empty($prepared['ok']) || empty($prepared['url'])) {
            $warnings[] = (string)($prepared['error'] ?? 'No public image is available for item ' . ($index + 1) . '.');
            continue;
        }

        $caption = mb_substr(sh_order_media_caption($payload, $item, $index + 1, $total), 0, 1000);
        $captionResult = sh_messenger_send_payload($cfg, ['text' => $caption]);
        $imageResult = sh_messenger_send_payload($cfg, [
            'attachment' => [
                'type' => 'image',
                'payload' => ['url' => (string)$prepared['url'], 'is_reusable' => false],
            ],
        ]);
        if (!empty($imageResult['ok'])) {
            $captionWarning = empty($captionResult['ok'])
                ? 'Product caption was not accepted: ' . (string)($captionResult['error'] ?? 'unknown provider error.')
                : null;
            sh_notification_finish_order_image($prepared, true, $recipient, $captionWarning);
            if ($captionWarning !== null) { $warnings[] = 'Caption for item ' . ($index + 1) . ': ' . $captionWarning; }
        } else {
            $error = (string)($imageResult['error'] ?? 'Messenger rejected this product image.');
            sh_notification_finish_order_image($prepared, false, $recipient, $error);
            $warnings[] = 'Image for item ' . ($index + 1) . ' failed: ' . $error;
        }
    }
    if (!$matched) { return ['ok' => false, 'error' => 'The saved order item was not found.', 'recipient' => $recipient]; }
    return [
        'ok' => true,
        'recipient' => $recipient,
        'warning' => $warnings ? implode(' ', array_slice(array_values(array_unique($warnings)), 0, 8)) : null,
    ];
}

// ---------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------
function sh_send_email_notification(string $event, array $message, array $data): array
{
    $to = trim((string)($data['customer_email'] ?? ''));
    $adminOnly = in_array($event, ['order_created', 'payment_submitted', 'low_stock'], true);
    if ($adminOnly || $to === '') {
        $to = trim((string)sh_setting('admin_notify_email', (string)sh_setting('contact_email', '')));
    }
    if ($to === '' || !sh_valid_email($to)) {
        return ['skipped' => true, 'error' => 'No valid recipient email address configured.'];
    }
    $sent = sh_mail_send($to, $message['title'], $message['text'], $data['html'] ?? null);
    if (!empty($sent['ok']) && sh_is_order_media_event($event)) {
        // config/mail.php deliberately has no MIME-attachment upload surface.
        // Log each real image as skipped rather than suggesting it was included
        // in the text/HTML summary sent through this channel.
        $media = sh_notification_skip_order_images(
            'email',
            $event,
            $data,
            $to,
            'Email image attachments are not supported by the configured mail sender.'
        );
        if ((int)($media['skipped'] ?? 0) > 0 || !empty($media['failures'])) {
            $parts = [];
            if ((int)($media['skipped'] ?? 0) > 0) {
                $parts[] = (int)$media['skipped'] . ' product image(s) skipped: email attachment media is unavailable.';
            }
            if (!empty($media['failures'])) { $parts[] = implode(' ', array_slice($media['failures'], 0, 3)); }
            $sent['warning'] = implode(' ', $parts);
        }
    }
    return $sent;
}

// The Telegram admin control panel builds on these helpers. Loaded last so the
// notification functions above are already defined (telegram.php requires
// payment.php, which requires this file).
require_once __DIR__ . '/telegram.php';
