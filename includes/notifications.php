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
        sh_insert('notification_logs', [
            'order_id'      => $orderId,
            'channel'       => $channel,
            'event'         => $event,
            'recipient'     => $recipient !== null ? mb_substr(sh_scrub_secrets($recipient), 0, 190) : null,
            'status'        => $status,
            'error_message' => $error !== null ? mb_substr(sh_scrub_secrets($error), 0, 500) : null,
        ]);
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

    foreach (SH_CHANNELS as $channel) {
        try {
            if (!sh_notification_enabled($channel, $event)) {
                $results[$channel] = ['status' => 'skipped', 'error' => 'Event disabled for this channel.'];
                continue;
            }
            $res = match ($channel) {
                'telegram'  => sh_send_telegram($message['text']),
                'whatsapp'  => sh_send_whatsapp($message['text'], $data),
                'messenger' => sh_send_messenger($message['text']),
                'email'     => sh_send_email_notification($event, $message, $data),
                default     => ['ok' => false, 'error' => 'Unknown channel.', 'skipped' => true],
            };
            if (!empty($res['skipped'])) {
                $results[$channel] = ['status' => 'skipped', 'error' => $res['error'] ?? 'Not configured.'];
                sh_notify_log($orderId, $channel, $event, 'skipped', $res['recipient'] ?? null, $res['error'] ?? 'Not configured.');
            } elseif (!empty($res['ok'])) {
                $results[$channel] = ['status' => 'sent', 'error' => null];
                sh_notify_log($orderId, $channel, $event, 'sent', $res['recipient'] ?? null, null);
            } else {
                $results[$channel] = ['status' => 'failed', 'error' => $res['error'] ?? 'Unknown error.'];
                sh_notify_log($orderId, $channel, $event, 'failed', $res['recipient'] ?? null, $res['error'] ?? 'Unknown error.');
            }
        } catch (Throwable $e) {
            // A channel blowing up must never abort the order.
            sh_log_exception($e, 'notify-' . $channel);
            $results[$channel] = ['status' => 'failed', 'error' => $e->getMessage()];
            sh_notify_log($orderId, $channel, $event, 'failed', null, $e->getMessage());
        }
    }
    return $results;
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
    if (isset($data['total'])) { $lines[] = 'Amount: ' . sh_money($data['total']); }
    if (!empty($data['payment_method'])) { $lines[] = 'Payment method: ' . $data['payment_method']; }
    if (!empty($data['transaction_id'])) { $lines[] = 'Transaction ID: ' . $data['transaction_id']; }
    if (!empty($data['status'])) { $lines[] = 'Status: ' . sh_status_label((string)$data['status']); }
    if (!empty($data['items']) && is_array($data['items'])) {
        $lines[] = 'Items:';
        foreach ($data['items'] as $it) {
            $lines[] = '  - ' . ($it['product_name'] ?? $it['name'] ?? 'Item') . ' x' . (int)($it['quantity'] ?? 1);
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
    $payload = is_string($body) ? $body : ($isJson ? json_encode($body) : http_build_query($body));
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
