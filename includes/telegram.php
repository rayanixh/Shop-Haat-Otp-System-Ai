<?php
/**
 * Telegram admin control panel.
 *
 * Turns the existing Telegram notification channel into a real control surface:
 * order cards carry inline keyboards, and each button performs a genuine
 * database action through the EXISTING payment/order functions in payment.php.
 *
 * Design rules:
 *  - Never re-implement business logic. sh_approve_payment(), sh_reject_payment(),
 *    sh_deliver_digital_codes() and sh_complete_order() already handle locking,
 *    transactions, duplicate protection and customer notifications.
 *  - The bot token never appears in a message, a log line or a response.
 *  - Nothing here throws out to the webhook; every entry point returns a result.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/notifications.php';

/* ------------------------------------------------------------------ *
 * Configuration
 * ------------------------------------------------------------------ */

/** Authorised Telegram numeric user IDs. Usernames are never trusted. */
function sh_tg_admin_ids(): array
{
    $raw = (string)sh_setting('telegram_admin_ids', '');
    if (trim($raw) === '') {
        // Fall back to the notification chat when it is a personal (positive) ID.
        $chat = trim((string)sh_setting('telegram_chat_id', ''));
        $raw = ($chat !== '' && !str_starts_with($chat, '-')) ? $chat : '';
    }
    $ids = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $id) {
        $id = trim($id);
        if ($id !== '' && preg_match('/^-?\d{4,20}$/', $id)) { $ids[] = $id; }
    }
    return array_values(array_unique($ids));
}

/** Is this Telegram user allowed to control orders? */
function sh_tg_is_admin($userId): bool
{
    $userId = trim((string)$userId);
    if ($userId === '') { return false; }
    foreach (sh_tg_admin_ids() as $known) {
        if (hash_equals($known, $userId)) { return true; }
    }
    return false;
}

/** Secret header value Telegram echoes back on every webhook call. */
function sh_tg_webhook_secret(): string
{
    return trim((string)sh_setting('telegram_webhook_secret', ''));
}

function sh_tg_webhook_url(): string
{
    return rtrim(sh_site_url(), '/') . '/telegram-webhook.php';
}

function sh_tg_debug(): bool
{
    return (string)sh_setting('telegram_debug', '0') === '1';
}

/** Diagnostic log that can never contain the bot token. */
function sh_tg_log(string $message, bool $isError = false): void
{
    if (!$isError && !sh_tg_debug()) { return; }
    $token = trim((string)sh_setting('telegram_bot_token', ''));
    if ($token !== '') { $message = str_replace($token, '[redacted]', $message); }
    sh_log_line('telegram', $message);
}

/* ------------------------------------------------------------------ *
 * Telegram Bot API
 * ------------------------------------------------------------------ */

/**
 * Call a Bot API method. Returns ['ok'=>bool, 'result'=>mixed, 'error'=>string].
 * A Telegram outage must never break the caller, so this never throws.
 */
function sh_tg_api(string $method, array $params = []): array
{
    $token = trim((string)sh_setting('telegram_bot_token', ''));
    if ($token === '') { return ['ok' => false, 'error' => 'No bot token configured.']; }

    $base = rtrim((string)sh_setting('telegram_api_base', 'https://api.telegram.org'), '/');
    // The token contains a colon and must NOT be URL-encoded.
    $url = $base . '/bot' . $token . '/' . $method;

    try {
        $res = sh_http_post($url, $params);
    } catch (Throwable $e) {
        sh_tg_log('API ' . $method . ' threw: ' . $e->getMessage(), true);
        return ['ok' => false, 'error' => 'Telegram request failed.'];
    }

    $json = json_decode((string)($res['body'] ?? ''), true);
    if (!empty($res['ok']) && is_array($json) && !empty($json['ok'])) {
        return ['ok' => true, 'result' => $json['result'] ?? null];
    }
    $desc = is_array($json) && !empty($json['description'])
        ? (string)$json['description']
        : (string)($res['error'] ?? 'Telegram API error.');
    sh_tg_log('API ' . $method . ' failed: ' . $desc, true);
    return ['ok' => false, 'error' => $desc];
}

/** Send a message with an optional inline keyboard. Returns the message_id. */
function sh_tg_send(string $text, ?array $keyboard = null, ?string $chatId = null): array
{
    $chat = $chatId ?? trim((string)sh_setting('telegram_chat_id', ''));
    if ($chat === '') { return ['ok' => false, 'error' => 'No chat ID configured.']; }
    $params = [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== null) { $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]); }
    $r = sh_tg_api('sendMessage', $params);
    if (!empty($r['ok'])) {
        return ['ok' => true, 'message_id' => (string)(($r['result']['message_id'] ?? '')), 'chat_id' => $chat];
    }
    return $r;
}

/** Replace the text and buttons of an existing message. */
function sh_tg_edit(string $chatId, string $messageId, string $text, ?array $keyboard = null): array
{
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard ?? []]);
    return sh_tg_api('editMessageText', $params);
}

/** Always call this so the button stops spinning in the client. */
function sh_tg_answer(string $callbackId, string $text = '', bool $alert = false): array
{
    return sh_tg_api('answerCallbackQuery', array_filter([
        'callback_query_id' => $callbackId,
        'text' => mb_substr($text, 0, 190),
        'show_alert' => $alert ? 'true' : null,
    ], static fn($v) => $v !== null && $v !== ''));
}

/** Register the webhook with Telegram. */
function sh_tg_set_webhook(): array
{
    $params = [
        'url' => sh_tg_webhook_url(),
        'allowed_updates' => json_encode(['message', 'callback_query']),
        'drop_pending_updates' => 'false',
    ];
    $secret = sh_tg_webhook_secret();
    if ($secret !== '') { $params['secret_token'] = $secret; }
    return sh_tg_api('setWebhook', $params);
}

function sh_tg_delete_webhook(): array { return sh_tg_api('deleteWebhook', []); }
function sh_tg_webhook_info(): array { return sh_tg_api('getWebhookInfo', []); }

/* ------------------------------------------------------------------ *
 * Order presentation
 * ------------------------------------------------------------------ */

function sh_tg_esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Human labels for the store's own status values. */
function sh_tg_payment_label(string $status): string
{
    return match ($status) {
        'verified'  => '✅ Verified',
        'rejected'  => '❌ Rejected',
        'submitted' => '⏳ Awaiting verification',
        'refunded'  => '↩️ Refunded',
        default     => '⏳ Pending',
    };
}

function sh_tg_order_label(string $status): string
{
    return match ($status) {
        'completed'        => '✅ Completed',
        'cancelled'        => '🚫 Cancelled',
        'processing'       => '⚙️ Processing',
        'payment_rejected' => '❌ Payment rejected',
        'payment_verified' => '💳 Payment verified',
        'payment_submitted'=> '💳 Payment submitted',
        'awaiting_payment' => '⏳ Awaiting payment',
        default            => '⏳ Pending',
    };
}

/** Build the order card. Shows no more customer data than an admin needs. */
function sh_tg_order_card(array $order, string $heading = ''): string
{
    $items = sh_order_items((int)$order['id']);
    $lines = [];
    $bar = '━━━━━━━━━━━━━━━━━━━━';

    $lines[] = $bar;
    $lines[] = '<b>' . sh_tg_esc($heading !== '' ? $heading : '🛒 ORDER ' . $order['order_number']) . '</b>';
    $lines[] = $bar;
    $lines[] = '';
    $lines[] = '<b>Order:</b> ' . sh_tg_esc($order['order_number']);
    $lines[] = '<b>Customer:</b> ' . sh_tg_esc($order['customer_name']);
    if (!empty($order['customer_phone'])) {
        $lines[] = '<b>Phone:</b> ' . sh_tg_esc($order['customer_phone']);
    }

    if ($items) {
        $lines[] = '';
        $lines[] = '<b>Items:</b>';
        foreach (array_slice($items, 0, 8) as $it) {
            $lines[] = '• ' . sh_tg_esc($it['product_name']) . ' × ' . (int)$it['quantity']
                     . ' — ' . sh_tg_esc(sh_money($it['line_total']));
        }
        if (count($items) > 8) { $lines[] = '• …and ' . (count($items) - 8) . ' more'; }
    }

    $lines[] = '';
    $lines[] = '<b>Amount:</b> ' . sh_tg_esc(sh_money($order['total']));
    $lines[] = '<b>Method:</b> ' . sh_tg_esc($order['payment_method_name'] ?: '—');

    $pay = sh_one('SELECT transaction_id, sender_phone, amount FROM payments
                   WHERE order_id = ? ORDER BY id DESC LIMIT 1', [(int)$order['id']]);
    if ($pay && !empty($pay['transaction_id'])) {
        $lines[] = '<b>Transaction:</b> <code>' . sh_tg_esc($pay['transaction_id']) . '</code>';
    }

    $lines[] = '';
    $lines[] = '<b>Payment:</b> ' . sh_tg_payment_label((string)$order['payment_status']);
    $lines[] = '<b>Status:</b> ' . sh_tg_order_label((string)$order['status']);

    if ((int)$order['has_digital'] === 1) {
        $lines[] = '<b>Delivery:</b> ' . ((int)$order['codes_delivered'] === 1 ? '✅ Codes delivered' : '⏳ Pending');
    }

    $lines[] = '';
    $lines[] = '<b>Placed:</b> ' . sh_tg_esc(date('d M Y, h:i A', strtotime((string)$order['created_at'])));
    $lines[] = $bar;

    return implode("\n", $lines);
}

/**
 * Inline keyboard for the order's CURRENT state.
 * Buttons that would be invalid simply do not appear.
 */
function sh_tg_order_keyboard(array $order): array
{
    $id = (int)$order['id'];
    $status = (string)$order['status'];
    $pay = (string)$order['payment_status'];
    $rows = [];

    // Terminal states expose no action buttons.
    if ($status === 'completed' || $status === 'cancelled') {
        return [[['text' => '🔎 View Order', 'callback_data' => 'order_view:' . $id]]];
    }

    if ($pay !== 'verified' && $pay !== 'rejected') {
        $rows[] = [
            ['text' => '💳 Payment Verified', 'callback_data' => 'payment_verify:' . $id],
            ['text' => '❌ Reject Payment',   'callback_data' => 'payment_reject:' . $id],
        ];
    }

    if ($pay === 'verified') {
        $line = [];
        if ($status !== 'processing') {
            $line[] = ['text' => '⚙️ Processing', 'callback_data' => 'order_processing:' . $id];
        }
        if ((int)$order['has_digital'] === 1 && (int)$order['codes_delivered'] === 0) {
            $line[] = ['text' => '📦 Delivery', 'callback_data' => 'order_delivery:' . $id];
        }
        if ($line) { $rows[] = $line; }
        $rows[] = [['text' => '✅ Complete', 'callback_data' => 'order_complete:' . $id]];
    }

    $rows[] = [
        ['text' => '🔎 View Order', 'callback_data' => 'order_view:' . $id],
        ['text' => '🚫 Cancelled',  'callback_data' => 'order_cancel:' . $id],
    ];
    return $rows;
}

/** Two-step confirmation keyboard. */
function sh_tg_confirm_keyboard(string $action, int $orderId, string $label): array
{
    return [
        [['text' => $label, 'callback_data' => $action . '_confirm:' . $orderId]],
        [['text' => '↩️ Back', 'callback_data' => 'order_view:' . $orderId]],
    ];
}

/* ------------------------------------------------------------------ *
 * Outbound notifications with controls
 * ------------------------------------------------------------------ */

/**
 * Send (or re-send) the controllable order card and remember its message id so
 * later actions can edit the same message instead of spamming the chat.
 */
function sh_tg_push_order(int $orderId, string $heading = ''): array
{
    if ((string)sh_setting('telegram_enabled', '0') !== '1') {
        return ['ok' => false, 'skipped' => true, 'error' => 'Telegram is disabled.'];
    }
    $order = sh_order_get($orderId);
    if ($order === null) { return ['ok' => false, 'error' => 'Order not found.']; }

    $res = sh_tg_send(sh_tg_order_card($order, $heading), sh_tg_order_keyboard($order));
    if (!empty($res['ok']) && !empty($res['message_id'])) {
        try {
            sh_query('UPDATE orders SET telegram_message_id = ? WHERE id = ?', [$res['message_id'], $orderId]);
        } catch (Throwable $e) { sh_log_exception($e, 'tg-store-msgid'); }
    }
    return $res;
}

/** Refresh the stored card after a status change. */
function sh_tg_refresh_order(int $orderId, string $heading = ''): void
{
    try {
        $order = sh_order_get($orderId);
        if ($order === null) { return; }
        $mid = trim((string)($order['telegram_message_id'] ?? ''));
        $chat = trim((string)sh_setting('telegram_chat_id', ''));
        if ($mid === '' || $chat === '') { return; }
        sh_tg_edit($chat, $mid, sh_tg_order_card($order, $heading), sh_tg_order_keyboard($order));
    } catch (Throwable $e) {
        sh_log_exception($e, 'tg-refresh');
    }
}

/* ------------------------------------------------------------------ *
 * Audit log
 * ------------------------------------------------------------------ */

function sh_tg_audit(?int $orderId, string $adminId, string $name, string $action,
                     ?string $prev, ?string $new, string $result = 'success', string $reason = ''): void
{
    try {
        sh_insert('telegram_admin_log', [
            'order_id' => $orderId ?: null,
            'telegram_admin_id' => mb_substr($adminId, 0, 32),
            'telegram_name' => mb_substr($name, 0, 190) ?: null,
            'action' => mb_substr($action, 0, 60),
            'previous_status' => $prev !== null ? mb_substr($prev, 0, 40) : null,
            'new_status' => $new !== null ? mb_substr($new, 0, 40) : null,
            'result' => in_array($result, ['success', 'denied', 'failed', 'noop'], true) ? $result : 'failed',
            'reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'tg-audit');
    }
}

/* ------------------------------------------------------------------ *
 * Action handlers — each returns ['toast'=>string, 'alert'=>bool, 'heading'=>string]
 * ------------------------------------------------------------------ */

/**
 * Execute one admin action. All persistence is delegated to the existing
 * payment/order functions, which already run inside transactions.
 */
function sh_tg_run_action(string $action, int $orderId, string $adminId, string $adminName): array
{
    $order = sh_order_get($orderId);
    if ($order === null) {
        sh_tg_audit($orderId, $adminId, $adminName, $action, null, null, 'failed', 'Order not found');
        return ['toast' => '❌ Order not found.', 'alert' => true, 'heading' => ''];
    }

    $prevStatus = (string)$order['status'];
    $prevPay = (string)$order['payment_status'];

    switch ($action) {

        case 'payment_verify':
            if ($prevPay === 'verified') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'noop', 'Already verified');
                return ['toast' => 'ℹ️ Payment is already verified.', 'alert' => false, 'heading' => ''];
            }
            if (in_array($prevStatus, ['cancelled', 'completed'], true)) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Order is ' . $prevStatus);
                return ['toast' => '❌ A ' . $prevStatus . ' order cannot be verified.', 'alert' => true, 'heading' => ''];
            }
            $pay = sh_one('SELECT id FROM payments WHERE order_id = ? AND status <> \'verified\' ORDER BY id DESC LIMIT 1', [$orderId]);
            if ($pay === null) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', 'No payment record');
                return ['toast' => '❌ No payment submission found for this order.', 'alert' => true, 'heading' => ''];
            }
            // Reuses the existing approver: transaction, notifications, code delivery.
            $r = sh_approve_payment((int)$pay['id'], 0, 'Verified from Telegram by ' . $adminName);
            if (empty($r['ok'])) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', (string)($r['error'] ?? ''));
                return ['toast' => '❌ ' . (string)($r['error'] ?? 'Could not verify.'), 'alert' => true, 'heading' => ''];
            }
            sh_tg_audit($orderId, $adminId, $adminName, 'PAYMENT_VERIFIED', $prevStatus, 'processing');
            return ['toast' => '✅ Payment verified.', 'alert' => false, 'heading' => '💳 PAYMENT VERIFIED'];

        case 'payment_reject':
            if ($prevPay === 'rejected') {
                return ['toast' => 'ℹ️ Payment is already rejected.', 'alert' => false, 'heading' => ''];
            }
            if ($prevPay === 'verified') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Already verified');
                return ['toast' => '❌ A verified payment cannot be rejected here.', 'alert' => true, 'heading' => ''];
            }
            $pay = sh_one('SELECT id FROM payments WHERE order_id = ? AND status <> \'verified\' ORDER BY id DESC LIMIT 1', [$orderId]);
            if ($pay === null) {
                return ['toast' => '❌ No payment submission found.', 'alert' => true, 'heading' => ''];
            }
            $r = sh_reject_payment((int)$pay['id'], 0, 'Rejected from Telegram by ' . $adminName);
            if (empty($r['ok'])) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', (string)($r['error'] ?? ''));
                return ['toast' => '❌ ' . (string)($r['error'] ?? 'Could not reject.'), 'alert' => true, 'heading' => ''];
            }
            sh_tg_audit($orderId, $adminId, $adminName, 'PAYMENT_REJECTED', $prevStatus, 'payment_rejected');
            return ['toast' => '❌ Payment rejected.', 'alert' => false, 'heading' => '❌ PAYMENT REJECTED'];

        case 'order_processing':
            if ($prevPay !== 'verified') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Payment not verified');
                return ['toast' => '❌ Verify the payment first.', 'alert' => true, 'heading' => ''];
            }
            if (in_array($prevStatus, ['cancelled', 'completed'], true)) {
                return ['toast' => '❌ A ' . $prevStatus . ' order cannot move to processing.', 'alert' => true, 'heading' => ''];
            }
            if ($prevStatus === 'processing') {
                return ['toast' => 'ℹ️ Order is already processing.', 'alert' => false, 'heading' => ''];
            }
            try {
                sh_query('UPDATE orders SET status = \'processing\' WHERE id = ?', [$orderId]);
            } catch (Throwable $e) {
                sh_log_exception($e, 'tg-processing');
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', 'DB error');
                return ['toast' => '❌ Database update failed.', 'alert' => true, 'heading' => ''];
            }
            sh_notify('order_processing', sh_order_notify_payload(sh_order_get($orderId)));
            sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_PROCESSING', $prevStatus, 'processing');
            return ['toast' => '⚙️ Order moved to processing.', 'alert' => false, 'heading' => '⚙️ ORDER PROCESSING'];

        case 'order_delivery':
            if ($prevPay !== 'verified') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Payment not verified');
                return ['toast' => '❌ Delivery needs a verified payment.', 'alert' => true, 'heading' => ''];
            }
            if (in_array($prevStatus, ['cancelled', 'payment_rejected'], true)) {
                return ['toast' => '❌ A ' . $prevStatus . ' order cannot be delivered.', 'alert' => true, 'heading' => ''];
            }
            if ((int)$order['has_digital'] !== 1) {
                sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_DELIVERY', $prevStatus, $prevStatus, 'noop', 'No digital items');
                return ['toast' => 'ℹ️ This order has no digital items to deliver.', 'alert' => false, 'heading' => ''];
            }
            if ((int)$order['codes_delivered'] === 1) {
                return ['toast' => 'ℹ️ Codes have already been delivered.', 'alert' => false, 'heading' => ''];
            }
            // Existing routine: locks rows, never issues a code twice.
            $r = sh_deliver_digital_codes($orderId);
            if (empty($r['ok'])) {
                sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_DELIVERY', $prevStatus, $prevStatus, 'failed', (string)($r['error'] ?? ''));
                return ['toast' => '❌ ' . (string)($r['error'] ?? 'Delivery failed.'), 'alert' => true,
                        'heading' => '❌ DELIVERY FAILED'];
            }
            if (!empty($r['already'])) {
                return ['toast' => 'ℹ️ Codes have already been delivered.', 'alert' => false, 'heading' => ''];
            }
            $n = count($r['codes'] ?? []);
            sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_DELIVERY', $prevStatus, (string)sh_order_get($orderId)['status']);
            return ['toast' => '📦 Delivered ' . $n . ' code(s).', 'alert' => false, 'heading' => '📦 DELIVERY SUCCESSFUL'];

        case 'order_complete':
            if ($prevStatus === 'completed') {
                return ['toast' => 'ℹ️ Order is already complete.', 'alert' => false, 'heading' => ''];
            }
            if ($prevStatus === 'cancelled') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Order cancelled');
                return ['toast' => '❌ A cancelled order cannot be completed.', 'alert' => true, 'heading' => ''];
            }
            if ($prevPay !== 'verified') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Payment not verified');
                return ['toast' => '❌ Verify the payment before completing.', 'alert' => true, 'heading' => ''];
            }
            if ((int)$order['has_digital'] === 1 && (int)$order['codes_delivered'] !== 1) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Codes not delivered');
                return ['toast' => '❌ Deliver the digital codes first.', 'alert' => true, 'heading' => ''];
            }
            $r = sh_complete_order($orderId);
            if (empty($r['ok'])) {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', (string)($r['error'] ?? ''));
                return ['toast' => '❌ ' . (string)($r['error'] ?? 'Could not complete.'), 'alert' => true, 'heading' => ''];
            }
            sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_COMPLETED', $prevStatus, 'completed');
            return ['toast' => '✅ Order completed.', 'alert' => false, 'heading' => '✅ ORDER COMPLETED'];

        case 'order_cancel':
            if ($prevStatus === 'cancelled') {
                return ['toast' => 'ℹ️ Order is already cancelled.', 'alert' => false, 'heading' => ''];
            }
            if ($prevStatus === 'completed') {
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'denied', 'Order completed');
                return ['toast' => '❌ A completed order cannot be cancelled here.', 'alert' => true, 'heading' => ''];
            }
            $pdo = sh_db();
            try {
                $pdo->beginTransaction();
                $row = sh_one('SELECT status, codes_delivered FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
                if ($row === null) { throw new RuntimeException('Order not found.'); }
                if ($row['status'] === 'cancelled') { $pdo->commit(); return ['toast' => 'ℹ️ Order is already cancelled.', 'alert' => false, 'heading' => '']; }
                if ($row['status'] === 'completed') { throw new RuntimeException('A completed order cannot be cancelled.'); }
                sh_query('UPDATE orders SET status = \'cancelled\' WHERE id = ?', [$orderId]);
                // Release any codes that were reserved but not yet handed over.
                sh_query('UPDATE product_codes SET status = \'available\', order_id = NULL, order_item_id = NULL
                          WHERE order_id = ? AND status = \'reserved\'', [$orderId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                sh_log_exception($e, 'tg-cancel');
                sh_tg_audit($orderId, $adminId, $adminName, $action, $prevStatus, $prevStatus, 'failed', 'DB error');
                return ['toast' => '❌ ' . ($e instanceof RuntimeException ? $e->getMessage() : 'Could not cancel.'),
                        'alert' => true, 'heading' => ''];
            }
            sh_notify('order_cancelled', sh_order_notify_payload(sh_order_get($orderId)));
            sh_tg_audit($orderId, $adminId, $adminName, 'ORDER_CANCELLED', $prevStatus, 'cancelled');
            return ['toast' => '🚫 Order cancelled.', 'alert' => false, 'heading' => '🚫 ORDER CANCELLED'];

        case 'order_view':
            return ['toast' => 'ℹ️ Refreshed.', 'alert' => false, 'heading' => ''];
    }

    return ['toast' => '❌ Unknown action.', 'alert' => true, 'heading' => ''];
}

/* ------------------------------------------------------------------ *
 * Update dispatch
 * ------------------------------------------------------------------ */

/** Reject a Telegram update we have already processed. */
function sh_tg_update_seen(int $updateId, string $kind): bool
{
    if ($updateId <= 0) { return false; }
    try {
        $st = sh_db()->prepare('INSERT IGNORE INTO telegram_updates (update_id, kind) VALUES (?,?)');
        $st->execute([$updateId, mb_substr($kind, 0, 30)]);
        return $st->rowCount() === 0; // 0 rows inserted => already seen
    } catch (Throwable $e) {
        sh_log_exception($e, 'tg-dedupe');
        return false; // never block a real action because bookkeeping failed
    }
}

/** Handle a callback_query (an inline button press). */
function sh_tg_handle_callback(array $cb): void
{
    $cbId = (string)($cb['id'] ?? '');
    $from = $cb['from'] ?? [];
    $userId = (string)($from['id'] ?? '');
    $name = trim((string)(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''))) ?: ('ID ' . $userId);
    $data = (string)($cb['data'] ?? '');
    $chatId = (string)($cb['message']['chat']['id'] ?? '');
    $msgId = (string)($cb['message']['message_id'] ?? '');

    // Authorisation happens BEFORE the payload is trusted.
    if (!sh_tg_is_admin($userId)) {
        sh_tg_log('Unauthorised callback from Telegram user ' . preg_replace('/\D/', '', $userId), true);
        sh_tg_audit(null, $userId, $name, 'UNAUTHORISED', null, null, 'denied', 'Not an authorised admin ID');
        if ($cbId !== '') { sh_tg_answer($cbId, '❌ Unauthorized access.', true); }
        return;
    }

    if (!preg_match('/^([a-z_]+):(\d{1,12})$/', $data, $m)) {
        if ($cbId !== '') { sh_tg_answer($cbId, '❌ Unrecognised action.', true); }
        return;
    }
    $action = $m[1];
    $orderId = (int)$m[2];

    // Destructive actions ask for confirmation first.
    if ($action === 'payment_reject' || $action === 'order_cancel') {
        $order = sh_order_get($orderId);
        if ($order === null) {
            if ($cbId !== '') { sh_tg_answer($cbId, '❌ Order not found.', true); }
            return;
        }
        $isReject = $action === 'payment_reject';
        $text = sh_tg_order_card($order, $isReject ? '⚠️ CONFIRM PAYMENT REJECTION' : '⚠️ CONFIRM ORDER CANCELLATION');
        $kb = sh_tg_confirm_keyboard($action, $orderId,
            $isReject ? '❌ Yes, Reject Payment' : '🚫 Yes, Cancel Order');
        if ($chatId !== '' && $msgId !== '') { sh_tg_edit($chatId, $msgId, $text, $kb); }
        if ($cbId !== '') { sh_tg_answer($cbId, 'Please confirm.'); }
        return;
    }

    // A *_confirm press maps back onto the real action.
    $real = $action;
    if (str_ends_with($action, '_confirm')) { $real = substr($action, 0, -8); }

    $res = sh_tg_run_action($real, $orderId, $userId, $name);

    // Refresh the card so the buttons match the new state.
    if ($chatId !== '' && $msgId !== '') {
        $order = sh_order_get($orderId);
        if ($order !== null) {
            sh_tg_edit($chatId, $msgId, sh_tg_order_card($order, (string)$res['heading']), sh_tg_order_keyboard($order));
        }
    }
    if ($cbId !== '') { sh_tg_answer($cbId, (string)$res['toast'], (bool)$res['alert']); }
}

/** Handle a plain text message (admin commands). */
function sh_tg_handle_message(array $msg): void
{
    $from = $msg['from'] ?? [];
    $userId = (string)($from['id'] ?? '');
    $chatId = (string)($msg['chat']['id'] ?? '');
    $text = trim((string)($msg['text'] ?? ''));
    if ($text === '' || $chatId === '') { return; }

    if (!sh_tg_is_admin($userId)) {
        sh_tg_log('Unauthorised command from Telegram user ' . preg_replace('/\D/', '', $userId), true);
        sh_tg_send('❌ Unauthorized access.', null, $chatId);
        return;
    }

    $parts = preg_split('/\s+/', $text) ?: [];
    $cmd = strtolower(ltrim((string)($parts[0] ?? ''), '/'));
    if (str_contains($cmd, '@')) { $cmd = explode('@', $cmd)[0]; }
    $arg = trim((string)($parts[1] ?? ''));

    switch ($cmd) {
        case 'start':
        case 'help':
            sh_tg_send(
                "<b>ShopHaat admin bot</b>\n\n"
                . "/order &lt;number&gt; — open an order with action buttons\n"
                . "/status &lt;number&gt; — short status line\n"
                . "/pending — payments awaiting verification\n"
                . "/today — today's order summary\n\n"
                . "Use the buttons on an order card to verify payments, deliver codes, "
                . "complete or cancel. Every action updates the store database.",
                null, $chatId);
            return;

        case 'order':
        case 'status':
            if ($arg === '') { sh_tg_send('Usage: /' . $cmd . ' 12345', null, $chatId); return; }
            $order = sh_one('SELECT * FROM orders WHERE order_number = ? OR id = ? LIMIT 1',
                [$arg, (int)preg_replace('/\D/', '', $arg)]);
            if ($order === null) { sh_tg_send('❌ Order not found.', null, $chatId); return; }
            if ($cmd === 'status') {
                sh_tg_send('<b>' . sh_tg_esc($order['order_number']) . "</b>\nPayment: "
                    . sh_tg_payment_label((string)$order['payment_status']) . "\nStatus: "
                    . sh_tg_order_label((string)$order['status']), null, $chatId);
                return;
            }
            $sent = sh_tg_send(sh_tg_order_card($order), sh_tg_order_keyboard($order), $chatId);
            if (!empty($sent['ok']) && !empty($sent['message_id'])) {
                try { sh_query('UPDATE orders SET telegram_message_id = ? WHERE id = ?',
                    [$sent['message_id'], (int)$order['id']]); } catch (Throwable $e) {}
            }
            return;

        case 'pending':
            $rows = sh_all("SELECT order_number, total, customer_name FROM orders
                            WHERE payment_status = 'submitted' ORDER BY id DESC LIMIT 10");
            if (!$rows) { sh_tg_send('✅ No payments are waiting for verification.', null, $chatId); return; }
            $out = "<b>⏳ Awaiting verification</b>\n";
            foreach ($rows as $r) {
                $out .= "\n• <code>" . sh_tg_esc($r['order_number']) . '</code> — '
                     . sh_tg_esc(sh_money($r['total'])) . ' — ' . sh_tg_esc($r['customer_name']);
            }
            $out .= "\n\nOpen one with /order &lt;number&gt;";
            sh_tg_send($out, null, $chatId);
            return;

        case 'today':
            $n = (int)sh_val('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()', [], 0);
            $sum = (float)sh_val("SELECT COALESCE(SUM(total),0) FROM orders
                                  WHERE DATE(created_at) = CURDATE() AND payment_status = 'verified'", [], 0);
            $pend = (int)sh_val("SELECT COUNT(*) FROM orders WHERE payment_status = 'submitted'", [], 0);
            sh_tg_send("<b>📊 Today</b>\n\nOrders: " . $n . "\nVerified revenue: "
                . sh_tg_esc(sh_money($sum)) . "\nAwaiting verification: " . $pend, null, $chatId);
            return;
    }

    sh_tg_send('Unknown command. Send /help for the list.', null, $chatId);
}

/** Entry point used by the webhook. Never throws. */
function sh_tg_process_update(array $update): void
{
    $updateId = (int)($update['update_id'] ?? 0);
    $kind = isset($update['callback_query']) ? 'callback_query' : (isset($update['message']) ? 'message' : 'other');

    if (sh_tg_update_seen($updateId, $kind)) {
        sh_tg_log('Duplicate update ' . $updateId . ' ignored.');
        return;
    }

    try {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            sh_tg_handle_callback($update['callback_query']);
        } elseif (isset($update['message']) && is_array($update['message'])) {
            sh_tg_handle_message($update['message']);
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'telegram-update');
    }
}

/* ------------------------------------------------------------------ *
 * Diagnostics
 * ------------------------------------------------------------------ */

function sh_tg_health(): array
{
    $checks = [];
    $token = trim((string)sh_setting('telegram_bot_token', ''));
    $admins = sh_tg_admin_ids();

    $checks[] = ['label' => 'PHP version', 'ok' => PHP_VERSION_ID >= 80100, 'value' => PHP_VERSION];
    $checks[] = ['label' => 'Webhook file present', 'ok' => is_file(SH_ROOT . '/telegram-webhook.php'),
                 'value' => is_file(SH_ROOT . '/telegram-webhook.php') ? 'Found' : 'Missing'];
    $https = str_starts_with(sh_site_url(), 'https://');
    $checks[] = ['label' => 'HTTPS', 'ok' => $https, 'value' => $https ? 'Enabled' : 'Not detected — Telegram requires HTTPS'];
    $checks[] = ['label' => 'Bot token', 'ok' => $token !== '', 'value' => $token !== '' ? 'Configured' : 'Not set'];
    $checks[] = ['label' => 'Admin IDs', 'ok' => count($admins) > 0,
                 'value' => count($admins) > 0 ? count($admins) . ' authorised' : 'None — buttons will be refused'];
    $checks[] = ['label' => 'Webhook secret', 'ok' => sh_tg_webhook_secret() !== '',
                 'value' => sh_tg_webhook_secret() !== '' ? 'Set' : 'Not set (recommended)'];

    $tablesOk = false;
    try {
        $n = (int)sh_val("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                            AND table_name IN ('telegram_admin_log','telegram_updates')", [], 0);
        $tablesOk = $n === 2;
    } catch (Throwable $e) { $tablesOk = false; }
    $checks[] = ['label' => 'Control tables', 'ok' => $tablesOk, 'value' => $tablesOk ? 'Present' : 'Missing — re-run the installer'];

    return $checks;
}
