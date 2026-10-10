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
    if ($key === '' || !sh_notification_log_schema_ensure()) {
        return ['claimed' => true, 'log_id' => 0];
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

        // If a host cannot apply the additive migration, retain the former
        // best-effort delivery behavior rather than blocking the payment flow.
        sh_log_exception($e, 'notification-claim');
        return ['claimed' => true, 'log_id' => 0];
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

    $orderId = isset($data['order_id']) ? (int)$data['order_id'] : null;
    $message = sh_notify_message($event, $data);
    $notificationKey = sh_notification_key((string)($data['notification_key'] ?? ''));
    $isRetry = !empty($data['retry_notification']);
    $retryChannel = trim((string)($data['retry_channel'] ?? ''));

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
                    'error' => 'This notification event was already delivered or is being processed.',
                ];
                continue;
            }

            $res = match ($channel) {
                // Payment submitted has its own order-level Telegram delivery: a
                // complete validated summary plus every available product image.
                'telegram'  => $event === 'payment_submitted' && function_exists('sh_tg_send_payment_submitted')
                    ? sh_tg_send_payment_submitted($data)
                    : sh_send_telegram($message['text']),
                'whatsapp'  => sh_send_whatsapp($message['text'], $data),
                'messenger' => sh_send_messenger($message['text']),
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
                // A photo can be unavailable while the complete text summary was
                // delivered. Keep that actionable detail in the log without
                // treating the customer's saved payment as a failure.
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
 * Admin-only retry for a failed, keyed payment-submitted Telegram delivery.
 * It rebuilds every field from the current server-side order/payment snapshot;
 * no browser-supplied product, amount or transaction data is reused.
 */
function sh_retry_payment_submitted_telegram(int $logId): array
{
    if ($logId <= 0 || !sh_notification_log_schema_ensure()) {
        return ['ok' => false, 'error' => 'This notification cannot be retried yet.'];
    }

    try {
        $log = sh_one(
            "SELECT * FROM notification_logs
             WHERE id = ? AND channel = 'telegram' AND event = 'payment_submitted' LIMIT 1",
            [$logId]
        );
        if ($log === null) { return ['ok' => false, 'error' => 'Notification record not found.']; }
        if ((string)$log['status'] !== 'failed') {
            return ['ok' => false, 'error' => 'Only failed Telegram deliveries can be retried.'];
        }
        if (!preg_match('/^payment-submitted:(\d+):(\d+)$/', (string)($log['idempotency_key'] ?? ''), $match)) {
            return ['ok' => false, 'error' => 'This legacy notification has no safe retry key.'];
        }

        $payment = sh_one('SELECT * FROM payments WHERE id = ? LIMIT 1', [(int)$match[1]]);
        $order = $payment !== null ? sh_order_get((int)$payment['order_id']) : null;
        if ($payment === null || $order === null) {
            return ['ok' => false, 'error' => 'The related payment or order is no longer available.'];
        }
        if (max(1, (int)($payment['submission_version'] ?? 0)) !== (int)$match[2]) {
            return ['ok' => false, 'error' => 'A newer payment submission exists; the older notification will not be retried.'];
        }
        if (!function_exists('sh_payment_submitted_notify_payload')) {
            return ['ok' => false, 'error' => 'Payment notification support is unavailable.'];
        }

        $payload = sh_payment_submitted_notify_payload($order, $payment);
        $payload['retry_notification'] = true;
        // This admin control retries only the failed Telegram delivery; it must
        // not resend an already delivered email/WhatsApp/Messenger event.
        $payload['retry_channel'] = 'telegram';
        $result = sh_notify('payment_submitted', $payload);
        $telegram = $result['telegram'] ?? ['status' => 'failed', 'error' => 'Telegram delivery was not attempted.'];
        return [
            'ok' => (string)($telegram['status'] ?? '') === 'sent',
            'error' => (string)($telegram['error'] ?? ''),
        ];
    } catch (Throwable $e) {
        sh_log_exception($e, 'notification-retry');
        return ['ok' => false, 'error' => 'The notification could not be retried.'];
    }
}

/** Builds the human-readable message body for an event. */
function sh_notify_message(string $event, array $data): array
{
    $site = sh_setting('site_name', 'ShopHaat');
    $title = SH_EVENTS[$event] . ' - ' . $site;
    $lines = [strtoupper(SH_EVENTS[$event]), str_repeat('-', 28)];

    if (!empty($data['order_number'])) { $lines[] = 'Order: ' . $data['order_number']; }
    if (!empty($data['customer_name'])) { $lines[] = 'Customer: ' . $data['customer_name']; }
    if (!empty($data['customer_phone'])) { $lines[] = 'Phone: ' . $data['customer_phone']; }
    $address = array_filter([
        (string)($data['shipping_address'] ?? ''), (string)($data['shipping_area'] ?? ''),
        (string)($data['shipping_city'] ?? ''), (string)($data['shipping_postcode'] ?? ''),
    ], static fn(string $part): bool => trim($part) !== '');
    if ($address) { $lines[] = 'Delivery address: ' . implode(', ', $address); }
    if (isset($data['subtotal'])) { $lines[] = 'Items subtotal: ' . sh_money($data['subtotal']); }
    if (isset($data['discount'])) { $lines[] = 'Discount: ' . sh_money($data['discount']); }
    if (isset($data['delivery_fee'])) { $lines[] = 'Delivery fee: ' . sh_money($data['delivery_fee']); }
    if (isset($data['total'])) { $lines[] = 'Amount: ' . sh_money($data['total']); }
    if (!empty($data['coupon_code'])) { $lines[] = 'Coupon: ' . $data['coupon_code']; }
    if (!empty($data['payment_method'])) { $lines[] = 'Payment method: ' . $data['payment_method']; }
    if (isset($data['payment_amount'])) { $lines[] = 'Payment amount: ' . sh_money($data['payment_amount']); }
    if (!empty($data['transaction_id'])) { $lines[] = 'Transaction ID: ' . $data['transaction_id']; }
    if (!empty($data['sender_phone'])) { $lines[] = 'Sender phone: ' . $data['sender_phone']; }
    if (!empty($data['status'])) { $lines[] = 'Status: ' . sh_status_label((string)$data['status']); }
    if (!empty($data['items']) && is_array($data['items'])) {
        $lines[] = 'Items:';
        foreach ($data['items'] as $index => $it) {
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
function sh_send_whatsapp(string $text, array $data = []): array
{
    if ((string)sh_setting('whatsapp_enabled', '0') !== '1') {
        return ['skipped' => true, 'error' => 'WhatsApp integration is disabled.'];
    }
    $phoneId = trim((string)sh_setting('whatsapp_phone_id', ''));
    $token = trim((string)sh_setting('whatsapp_token', ''));
    $to = trim((string)sh_setting('whatsapp_recipient', ''));
    if ($phoneId === '' || $token === '' || $to === '') {
        $miss = [];
        if ($phoneId === '') { $miss[] = 'phone number ID'; }
        if ($token === '') { $miss[] = 'access token'; }
        if ($to === '') { $miss[] = 'recipient number'; }
        return ['skipped' => true, 'error' => 'WhatsApp is missing: ' . implode(', ', $miss) . '.'];
    }
    $to = preg_replace('/[^0-9]/', '', $to);
    $res = sh_http_post(
        rtrim((string)sh_setting('meta_api_base', 'https://graph.facebook.com/v20.0'), '/')
            . '/' . rawurlencode($phoneId) . '/messages',
        ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]],
        ['Authorization: Bearer ' . $token]
    );
    $json = json_decode($res['body'], true);
    if ($res['ok'] && is_array($json) && !empty($json['messages'])) {
        return ['ok' => true, 'recipient' => $to];
    }
    $err = is_array($json) && !empty($json['error']['message']) ? $json['error']['message'] : ($res['error'] ?: 'WhatsApp API rejected the request.');
    return ['ok' => false, 'error' => $err, 'recipient' => $to];
}

// ---------------------------------------------------------------------------
// Messenger (Meta Send API)
// ---------------------------------------------------------------------------
function sh_send_messenger(string $text): array
{
    if ((string)sh_setting('messenger_enabled', '0') !== '1') {
        return ['skipped' => true, 'error' => 'Messenger integration is disabled.'];
    }
    $token = trim((string)sh_setting('messenger_token', ''));
    $recipient = trim((string)sh_setting('messenger_recipient', ''));
    if ($token === '' || $recipient === '') {
        $miss = [];
        if ($token === '') { $miss[] = 'page access token'; }
        if ($recipient === '') { $miss[] = 'recipient PSID'; }
        return ['skipped' => true, 'error' => 'Messenger is missing: ' . implode(' and ', $miss) . '.'];
    }
    $res = sh_http_post(
        rtrim((string)sh_setting('meta_api_base', 'https://graph.facebook.com/v20.0'), '/')
            . '/me/messages?access_token=' . rawurlencode($token),
        ['recipient' => ['id' => $recipient], 'messaging_type' => 'MESSAGE_TAG', 'tag' => 'ACCOUNT_UPDATE', 'message' => ['text' => $text]]
    );
    $json = json_decode($res['body'], true);
    if ($res['ok'] && is_array($json) && !empty($json['message_id'])) {
        return ['ok' => true, 'recipient' => $recipient];
    }
    $err = is_array($json) && !empty($json['error']['message']) ? $json['error']['message'] : ($res['error'] ?: 'Messenger API rejected the request.');
    return ['ok' => false, 'error' => $err, 'recipient' => $recipient];
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
    return sh_mail_send($to, $message['title'], $message['text'], $data['html'] ?? null);
}

// The Telegram admin control panel builds on these helpers. Loaded last so the
// notification functions above are already defined (telegram.php requires
// payment.php, which requires this file).
require_once __DIR__ . '/telegram.php';
