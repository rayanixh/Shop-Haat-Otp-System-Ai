<?php
/**
 * Admin-only notification test endpoint.
 * Reports the true provider result — never a fabricated success.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-notify-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
sh_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sh_json(['success' => false, 'error' => 'POST required.'], 405); }
sh_csrf_require();

$channel = sh_post('channel');
if (!in_array($channel, SH_CHANNELS, true)) {
    sh_json(['success' => false, 'error' => 'Unknown channel.'], 400);
}

try {
    $site = sh_setting('site_name', 'ShopHaat');
    $text = "TEST NOTIFICATION\n" . str_repeat('-', 28) . "\n"
        . "This is a test message from your {$site} admin panel.\n"
        . 'Channel: ' . ucfirst($channel) . "\n"
        . 'Time: ' . date('d M Y, h:i A');

    $res = match ($channel) {
        'telegram'  => sh_send_telegram($text),
        'whatsapp'  => sh_send_whatsapp($text),
        'messenger' => sh_send_messenger($text),
        'email'     => sh_mail_send(
            (string)sh_setting('admin_notify_email', (string)sh_setting('contact_email', '')),
            'Test email from ' . $site,
            $text
        ),
    };

    if (!empty($res['skipped'])) {
        sh_notify_log(null, $channel, 'test', 'skipped', $res['recipient'] ?? null, $res['error'] ?? null);
        sh_json(['success' => false, 'skipped' => true, 'error' => $res['error'] ?? 'This integration is not configured.']);
    }
    if (!empty($res['ok'])) {
        sh_notify_log(null, $channel, 'test', 'sent', $res['recipient'] ?? null, null);
        sh_json(['success' => true, 'message' => 'Test message accepted by the provider.']);
    }
    sh_notify_log(null, $channel, 'test', 'failed', $res['recipient'] ?? null, $res['error'] ?? 'Unknown error.');
    sh_json(['success' => false, 'error' => $res['error'] ?? 'The provider rejected the request.']);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-notify');
    sh_json(['success' => false, 'error' => 'Test failed: ' . $e->getMessage()]);
}
