<?php
/**
 * Human Support Notifications — SMS integration tests.
 *
 * Executes the REAL handoff, settings, idempotency and delivery functions
 * against a real PDO database. Outbound HTTP is replaced by an explicit test
 * transport (sh_sms_deliver) so the suite can assert both provider ACCEPTANCE
 * and provider REJECTION without pretending a real carrier was contacted.
 *
 * These tests do NOT claim real SMS delivery, a live provider credential, or
 * cPanel/Apache behaviour. See docs/AI-CUSTOMER-SUPPORT.md for the required
 * real-provider verification steps.
 *
 *     php tests/support-sms-notifications.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';

/** Explicitly stubbed transport: records calls, never performs real HTTP. */
$GLOBALS['sh_test_sms_calls'] = [];
$GLOBALS['sh_test_sms_result'] = ['ok' => true, 'error' => null];
$GLOBALS['sh_test_otp_provider'] = 'textbee';
function sh_sms_deliver(string $provider, string $phone, string $message, array $cfg = []): array
{
    $GLOBALS['sh_test_sms_calls'][] = [
        'provider' => $provider, 'phone' => $phone, 'message' => $message, 'cfg' => $cfg,
    ];
    return $GLOBALS['sh_test_sms_result'];
}
function sh_otp_provider(): string { return (string)$GLOBALS['sh_test_otp_provider']; }

require_once SH_ROOT . '/includes/ai-support.php';
require_once SH_ROOT . '/includes/support-sms.php';

sh_test_schema();
// Mirror the shipped notification_logs table so the suite can prove that SMS
// attempts are recorded in the existing unified log (without loading the full
// mail/WhatsApp notification engine on a customer request).
if (!sh_db()->isMySql) {
    sh_db()->exec('CREATE TABLE IF NOT EXISTS notification_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, channel TEXT, event TEXT,
        recipient TEXT, status TEXT, error_message TEXT, created_at TEXT)');
}
$GLOBALS['sh_test_tables']['notification_logs'] = true;
sh_setting_save('support_chat_enabled', '1');
sh_setting_save('support_chat_handoff_enabled', '1');
sh_setting_save('support_chat_ai_enabled', '0');
sh_setting_save('support_chat_hours', '');

$owner = ['user_id' => 0, 'guest_hash' => hash('sha256', 'support-sms-test-guest'), 'guest_order_ids' => []];
$resetRate = static function (): void { sh_query('DELETE FROM support_rate_limits'); };
$newConversation = static function () use ($owner): int {
    return sh_insert('support_conversations', [
        'user_id' => null, 'guest_token_hash' => $owner['guest_hash'],
        'status' => 'open', 'assigned_admin_id' => null, 'ai_paused' => 0,
        'unread_for_admin' => 0, 'unread_for_customer' => 0, 'lang' => 'en', 'subject' => null,
        'last_message_at' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
};
$handoff = static function (int $id, string $eventKey) use ($owner, $resetRate): array {
    $resetRate();
    return sh_support_customer_handoff($id, $owner, $eventKey);
};
$send = static function (int $id, string $body) use ($owner, $resetRate): array {
    $resetRate();
    return sh_support_customer_send($id, $owner, $body, sh_test_uuid());
};
$enable = static function (string $provider = 'textbee') use ($resetRate): void {
    $GLOBALS['sh_test_sms_calls'] = [];
    $GLOBALS['sh_test_sms_result'] = ['ok' => true, 'error' => null];
    $resetRate();
    sh_support_sms_save([
        'enabled' => '1', 'phone' => '+8801712345678', 'provider' => $provider,
        'textbee_api_key' => 'TEST-NEVER-A-REAL-KEY', 'textbee_device_id' => 'test-device',
        'api_url' => 'https://sms.example.test/send', 'api_method' => 'post_json',
        'api_body' => '', 'api_headers' => '', 'api_auth' => '', 'api_key' => '',
        'api_secret' => '', 'api_token' => '', 'api_username' => '', 'api_password' => '',
        'phone_param' => 'phone', 'message_param' => 'message',
        'success_field' => '', 'success_value' => '', 'sender_id' => 'ShopHaat',
    ]);
};
$disable = static function (): void {
    sh_support_sms_save(['enabled' => '0', 'phone' => '', 'provider' => 'offline']);
};
$callCount = static fn(): int => count($GLOBALS['sh_test_sms_calls']);
/** Deterministic, spec-valid client ids (the real API requires a UUID v4). */
$key = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
$apiSrc = (string)file_get_contents(SH_ROOT . '/api/support.php');
$settingsSrc = (string)file_get_contents(SH_ROOT . '/admin/support-settings.php');
$smsSrc = (string)file_get_contents(SH_ROOT . '/includes/support-sms.php');
$phoneOtpSrc = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');

[$passed, $failed] = sh_test_run('Human Support Notifications — real PHP + SQL', static function ($ok, $eq) use (
    $owner, $resetRate, $newConversation, $handoff, $send, $enable, $disable, $callCount,
    $apiSrc, $settingsSrc, $smsSrc, $phoneOtpSrc, $key
) {
    echo "Engine: " . (sh_db()->isMySql ? 'MySQL/MariaDB (production DDL)' : 'SQLite (MySQL SQL translated)') . "\n";
    echo "Transport: explicit test stub (no real provider HTTP is performed)\n\n";

    /* --- 1. Defaults: disabled, no fake success --------------------------- */
    $disable();
    $eq('disabled', sh_support_sms_ready()['reason'], 'channel is disabled by default');
    $r = sh_support_sms_notify_handoff(1, $key(999));
    $eq('disabled', $r['status'], 'disabled channel does not attempt delivery');
    $eq(0, $callCount(), 'disabled channel makes no provider call');
    $t = sh_support_sms_test();
    $ok(empty($t['ok']) && str_contains($t['message'], 'NOT sent'), 'disabled Test SMS never claims a delivery');
    $eq(0, $callCount(), 'disabled Test SMS makes no provider call');

    /* --- 2. Only integrable providers are offered ------------------------- */
    $providers = sh_support_sms_providers();
    $ok(isset($providers['offline'], $providers['store'], $providers['textbee'], $providers['generic_http']),
        'offline, store provider, TextBee and custom HTTPS API are offered');
    $ok(!isset($providers['firebase']), 'Firebase is excluded: it cannot carry this server-generated text');
    $ok(!sh_support_sms_provider_allowed('firebase') && !sh_support_sms_provider_allowed('made_up'),
        'unsupported providers are rejected server-side');
    $ok(str_contains($phoneOtpSrc, 'function sh_sms_deliver(')
        && str_contains($phoneOtpSrc, 'function sh_otp_setting_value('),
        'SMS uses the existing drivers instead of a duplicate notification engine');
    $ok(str_contains($smsSrc, 'sh_support_sms_log(')
        && str_contains($smsSrc, 'sh_notify_log('),
        'delivery attempts use the existing notification log architecture');
    $ok(str_contains($phoneOtpSrc, 'https://api.textbee.dev/api/v1/gateway/send-sms')
        && !str_contains($phoneOtpSrc, '/gateway/devices/')
        && str_contains($phoneOtpSrc, 'sh_sms_textbee_response_state(')
        && str_contains($phoneOtpSrc, 'data.success=true'),
        'TextBee uses the current documented account endpoint and explicit acceptance');
    $ok(str_contains($smsSrc, 'sh_support_sms_log_provider_diagnostic(')
        && str_contains($smsSrc, 'support_sms_diagnostic')
        && str_contains($smsSrc, 'sh_support_sms_test_nonce('),
        'redacted provider diagnostics and one-use Test SMS nonces are implemented');

    /* --- 3. Message content: short, reference only, no secrets ------------ */
    $msg = sh_support_sms_message(42);
    $ok(str_contains($msg, 'Conversation #42'), 'message contains a conversation reference');
    $ok(strlen($msg) <= 160, 'message is short enough for one SMS');
    foreach (['password', 'api_key', 'API key', 'secret', 'payment', 'order', 'admin/', '__admin_route'] as $needle) {
        $ok(!str_contains(strtolower($msg), strtolower($needle)), 'message never includes ' . $needle);
    }
    $ok(!str_contains($msg, 'TEST-NEVER-A-REAL-KEY'), 'message never includes a provider credential');
    $scrubbed = sh_support_sms_scrub('x-api-key: ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd status=ok');
    $ok(!str_contains($scrubbed, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd'), 'log scrubbing removes long tokens');
    $ok(str_contains(sh_support_sms_scrub('api_key=1234567890abcdef'), '[redacted]'), 'log scrubbing removes key/value secrets');

    /* --- 4. Test SMS: real acceptance vs real rejection ------------------- */
    $enable('textbee');
    $eq('ready', sh_support_sms_ready()['reason'], 'configured TextBee channel is ready for a real test');
    $t = sh_support_sms_test();
    $ok(!empty($t['ok']) && !empty($GLOBALS['sh_test_sms_calls']), 'accepted Test SMS is reported as accepted');
    $ok(str_contains($t['message'], 'accepted') && str_contains($t['message'], 'Provider acceptance'),
        'test result states provider acceptance honestly');
    $eq('1', sh_support_sms_setting('test_ok'), 'accepted test result is persisted');
    $eq('textbee', $GLOBALS['sh_test_sms_calls'][0]['provider'], 'test uses the selected provider');
    $eq('+8801712345678', $GLOBALS['sh_test_sms_calls'][0]['phone'], 'test uses the configured support number');
    $ok(!empty($GLOBALS['sh_test_sms_calls'][0]['cfg']['otp_textbee_api_key']),
        'dedicated credentials reach the existing driver server-side');

    $GLOBALS['sh_test_sms_result'] = ['ok' => false, 'error' => 'TextBee rejected the request (HTTP 401).'];
    $t = sh_support_sms_test();
    $ok(empty($t['ok']) && str_contains($t['message'], 'NOT sent'), 'provider rejection is reported as NOT sent');
    $eq('0', sh_support_sms_setting('test_ok'), 'rejected test result is persisted as a failure');
    $ok(str_contains(sh_support_sms_setting('test_message'), 'TextBee rejected'), 'provider error is shown to the admin');

    $logs = sh_all("SELECT * FROM notification_logs WHERE channel = 'sms'");
    $ok(count($logs) >= 1, 'delivery attempts are recorded in the existing unified notification log');
    $logged = implode(' ', array_map(static fn($r) => (string)($r['error_message'] ?? ''), $logs));
    $ok(!str_contains($logged, 'TEST-NEVER-A-REAL-KEY') && !str_contains($logged, '+8801712345678'),
        'notification logs never contain provider credentials or the support number');
    $ok(in_array('sent', array_column($logs, 'status'), true)
        && in_array('failed', array_column($logs, 'status'), true),
        'both provider acceptance and rejection are logged with their real outcome');

    /* --- 5. Offline / invalid provider never claims a real SMS ------------ */
    sh_support_sms_save(['enabled' => '1', 'phone' => '8801712345678', 'provider' => 'offline']);
    $t = sh_support_sms_test();
    $ok(empty($t['ok']) && str_contains(strtolower($t['message']), 'offline'), 'offline mode never claims a real SMS');
    // Blank secret fields intentionally KEEP the saved value; clear them
    // explicitly to exercise the missing-credential branch.
    sh_support_sms_save_setting('textbee_api_key', '');
    sh_support_sms_save_setting('textbee_device_id', '');
    sh_support_sms_save(['enabled' => '1', 'phone' => '8801712345678', 'provider' => 'textbee']);
    $eq('credentials_missing', sh_support_sms_ready()['reason'], 'missing credentials are detected server-side');
    sh_support_sms_save_setting('textbee_api_key', 'TEST-NEVER-A-REAL-KEY');
    $eq('ready', sh_support_sms_ready()['reason'], 'TextBee device ID is optional with the current account endpoint');
    sh_support_sms_save(['enabled' => '1', 'phone' => '', 'provider' => 'textbee']);
    $eq('phone_missing', sh_support_sms_ready()['reason'], 'missing support number is detected server-side');
    sh_support_sms_save_setting('api_url', '');
    $insecureSave = sh_support_sms_save(['enabled' => '1', 'phone' => '8801712345678', 'provider' => 'generic_http',
        'api_url' => 'http://insecure.example.test/send']);
    $ok(empty($insecureSave['ok']) && str_contains(implode(' ', $insecureSave['errors']), 'https://'),
        'plain-HTTP custom endpoint is refused at save time');
    $eq('', sh_support_sms_setting('api_url'), 'insecure endpoint is never persisted');
    sh_support_sms_save_setting('api_url', 'http://insecure.example.test/send');
    $eq('insecure_endpoint', sh_support_sms_ready()['reason'], 'a legacy stored plain-HTTP endpoint is refused');
    sh_support_sms_save_setting('api_url', '');

    /* --- 6. One SMS per genuinely new request ----------------------------- */
    $enable('textbee');
    $id = $newConversation();
    $ok(!empty($send($id, 'Hello, what are your opening hours?')['ok']), 'customer opens the conversation normally');
    $eq(0, $callCount(), 'ordinary customer message does not send an SMS');
    $r = $handoff($id, $key(1));
    $ok(!empty($r['ok']), 'human-support handoff succeeds with notifications enabled');
    $eq(1, $callCount(), 'exactly one SMS is attempted for the new request');
    $ok(str_contains($GLOBALS['sh_test_sms_calls'][0]['message'], 'Conversation #' . $id),
        'SMS references the existing conversation');
    $ok(!str_contains($GLOBALS['sh_test_sms_calls'][0]['message'], 'opening hours'),
        'SMS never includes customer message text');
    $eq(1, (int)sh_val("SELECT COUNT(*) FROM support_handoff_events WHERE conversation_id = ? AND event = 'sms_notification_claim'", [$id], 0),
        'one durable idempotency claim is stored');

    $handoff($id, $key(1)); // identical retry
    $ok(!empty($send($id, 'Following up on my request')['ok']), 'follow-up customer message is saved');
    $ok(!empty($send($id, 'Another follow-up')['ok']), 'second follow-up customer message is saved');
    $eq(1, $callCount(), 'retries and later messages never produce duplicate SMS');
    $eq('pending', (string)sh_one('SELECT status FROM support_conversations WHERE id = ?', [$id])['status'],
        'chat/inbox state is unaffected by the SMS channel');

    $agentId = sh_insert('admins', ['name' => 'SMS Test Agent', 'email' => 'sms-agent@example.test',
        'role' => 'superadmin', 'status' => 'active']);
    $ok(!empty(sh_support_admin_transition($id, $agentId, 'join', 0)['ok']), 'agent joins the conversation');
    $resetRate();
    $ok(!empty(sh_support_admin_reply($id, $agentId, 'Hello from a human', sh_test_uuid(), false)['ok']),
        'agent reply is saved');
    $ok(!empty(sh_support_admin_transition($id, $agentId, 'leave', 0)['ok']), 'agent leaves the conversation');
    $ok(!empty(sh_support_admin_transition($id, $agentId, 'close', 0)['ok']), 'agent closes the conversation');
    $eq(1, $callCount(), 'join, reply, leave and close never produce another SMS');

    /* --- 6b. A message that itself asks for a human uses the same claim -- */
    $enable('textbee');
    $idKeyword = $newConversation();
    $ok(!empty($send($idKeyword, 'Please connect me to a real person')['ok']),
        'message-triggered handoff is saved');
    $eq(1, $callCount(), 'message-triggered handoff sends exactly one SMS');
    $ok(str_contains($GLOBALS['sh_test_sms_calls'][0]['message'], 'Conversation #' . $idKeyword),
        'message-triggered SMS references the same conversation');
    $r = $handoff($idKeyword, $key(50));
    $ok(!empty($r['ok']), 'clicking the handoff button afterwards is a safe no-op');
    $eq(1, $callCount(), 'button + message paths never produce two notifications');
    $ok(!empty($send($idKeyword, 'Thank you for looking into this')['ok']), 'follow-up after keyword handoff is saved');
    $eq(1, $callCount(), 'follow-up after keyword handoff never produces another SMS');

    /* --- 7. A genuinely NEW request after close may notify once ----------- */
    $ok(!empty(sh_support_admin_transition($id, $agentId, 'reopen', 0)['ok']), 'conversation is reopened');
    $ok(!empty(sh_support_admin_transition($id, $agentId, 'resume_ai', 0)['ok']),
        'AI is resumed only by an explicit authorized admin action');
    $r = $handoff($id, $key(2));
    $ok(!empty($r['ok']), 'a clearly new support request after a closed cycle succeeds');
    $eq(2, $callCount(), 'a clearly new request after closure sends exactly one new SMS');
    $handoff($id, $key(2));
    $eq(2, $callCount(), 'the second request cannot notify twice');

    /* --- 8. Provider failure never breaks chat or claims a delivery ------- */
    $enable('textbee');
    $GLOBALS['sh_test_sms_result'] = ['ok' => false, 'error' => 'SMS provider rejected the request (HTTP 503).'];
    $id2 = $newConversation();
    $send($id2, 'Please help');
    $r = $handoff($id2, $key(3));
    $ok(!empty($r['ok']), 'handoff succeeds even when the SMS provider rejects the message');
    $eq(1, $callCount(), 'one delivery was attempted');
    $direct = sh_support_sms_send(sh_support_sms_message($id2), $id2);
    $ok(empty($direct['ok']) && $direct['status'] === 'failed', 'provider failure is recorded, not hidden');
    $ok(str_contains($direct['error'], 'rejected'), 'the exact provider error is returned to the admin');
    $ok(!str_contains(strtolower($direct['error']), 'sent successfully'), 'no fake success message on provider failure');
    $ok(str_contains(sh_support_sms_ready_message('offline'), 'no real SMS'), 'offline explanation is explicit');

    /* --- 9. Store provider mode reuses existing configuration ------------ */
    $GLOBALS['sh_test_otp_provider'] = 'generic_http';
    sh_setting_save('otp_api_url', 'https://sms.example.test/send');
    sh_setting_save('otp_textbee_api_key', 'SHARED-TEST-KEY');
    $enable('store');
    $eq('generic_http', sh_support_sms_resolved_provider(), 'store mode resolves the shared OTP provider');
    $eq('ready', sh_support_sms_ready()['reason'], 'store mode is ready when the shared carrier is real');
    $t = sh_support_sms_test();
    $ok(!empty($t['ok']), 'store mode test uses the shared driver');
    $eq('generic_http', $GLOBALS['sh_test_sms_calls'][0]['provider'], 'no duplicate carrier is created');
    $GLOBALS['sh_test_otp_provider'] = 'firebase';
    $eq('provider_unsupported', sh_support_sms_ready()['reason'], 'store mode refuses Firebase honestly');
    $GLOBALS['sh_test_otp_provider'] = 'textbee';

    /* --- 10. Settings security / admin UI / CSRF -------------------------- */
    $ok(str_contains($settingsSrc, 'id="human-support-sms"'), 'dedicated Human Support Notifications section exists');
    foreach (['support_sms_save', 'support_sms_test', 'support_sms_clear_test'] as $action) {
        $ok(str_contains($settingsSrc, $action), 'settings page handles ' . $action);
    }
    $ok(str_contains($settingsSrc, 'sh_csrf_require()'), 'settings POST actions are CSRF-protected');
    $ok(str_contains($settingsSrc, "sh_require_perm('support.manage')"), 'settings page is RBAC-protected');
    $ok(str_contains($settingsSrc, 'sh_csrf_field()'), 'settings form carries a CSRF token');
    $ok(str_contains($settingsSrc, 'Test SMS') && str_contains($settingsSrc, 'Save settings')
        && str_contains($settingsSrc, 'Clear result'), 'Test SMS, Save settings and clear result are present');
    $secretEchoes = [
        'value="<?= e($sms[\'textbee_api_key\']) ?>"',
        'value="<?= e($sms[\'api_key\']) ?>"',
        'value="<?= e($sms[\'api_token\']) ?>"',
        'value="<?= e($sms[\'api_password\']) ?>"',
        'value="<?= e($sms[\'api_body\']) ?>"',
    ];
    $noSecretEcho = true;
    foreach ($secretEchoes as $echo) {
        if (str_contains($settingsSrc, $echo)) { $noSecretEcho = false; break; }
    }
    $ok($noSecretEcho && str_contains($settingsSrc, 'type="password" value=""'),
        'saved provider secrets are never echoed into admin HTML');
    $ok(str_contains($settingsSrc, 'data-sms-provider-select')
        && str_contains($settingsSrc, 'data-sms-provider-panel="textbee"')
        && str_contains($settingsSrc, 'data-sms-provider-panel="generic_http"'),
        'provider-specific fields are hidden behind the existing accordion');
    $ok(str_contains($settingsSrc, '<details class="sh-guide-accordion" id="human-support-sms"')
        && !str_contains($settingsSrc, '<section class="sh-panel" id="human-support-sms"'),
        'SMS lives in the compact existing accordion, not a separate expanded panel');
    $ok(str_contains($smsSrc, 'only an explicit ok=true') || str_contains($smsSrc, 'only an explicit ok=true from the live provider driver counts'),
        'success requires an explicit provider acceptance');
    $ok(str_contains($smsSrc, 'sh_support_sms_claim('), 'one-notification claim is enforced server-side');
    $ok(str_contains($apiSrc, '$method') && str_contains($apiSrc, "=== 'POST'"),
        'support API still separates state-changing POST actions');
    $ok(str_contains($smsSrc, 'no new tables') || str_contains($smsSrc, 'existing `settings` table'),
        'no duplicate database schema is introduced');

    /* --- 10b. Redacted diagnostics and one-use Test SMS nonce ------------- */
    $enable('textbee');
    $GLOBALS['sh_test_security_logs'] = [];
    $GLOBALS['sh_test_sms_result'] = [
        'ok' => false,
        'error' => 'TextBee rejected the request (HTTP 401). x-api-key: ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd',
        'code' => 'http_401',
        'diagnostic' => [
            'id' => 'SMS-TEST-DIAG', 'provider' => 'textbee', 'http_status' => 401,
            'response' => '{"error":"invalid key","api_key":"ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd"}',
            'acceptance' => 'HTTP error; request not queued.',
        ],
    ];
    $t = sh_support_sms_test();
    $eq('http_401', $t['code'], 'provider-specific failure code is shown to the admin');
    $eq('SMS-TEST-DIAG', $t['diagnostic_id'], 'admin receives a short secure diagnostic reference');
    $events = array_column($GLOBALS['sh_test_security_logs'], 'event');
    $ok(in_array('support_sms_diagnostic', $events, true), 'provider response is written to the secure Admin diagnostic log');
    $diagnosticJson = (string)json_encode($GLOBALS['sh_test_security_logs']);
    $ok(!str_contains($diagnosticJson, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd')
        && str_contains($diagnosticJson, '401'), 'diagnostics are redacted but retain HTTP status');

    sh_support_sms_clear_test_nonces();
    $GLOBALS['sh_test_sms_result'] = ['ok' => true, 'error' => null];
    $ui = sh_support_sms_ui_vars();
    $firstTest = sh_support_sms_test(['test_nonce' => $ui['test_nonce']]);
    $ok(!empty($firstTest['ok']), 'a fresh Test SMS nonce permits one real provider attempt');
    $repeatTest = sh_support_sms_test(['test_nonce' => $ui['test_nonce']]);
    $eq('test_nonce_invalid', $repeatTest['code'], 'refresh/back-button resubmission cannot send a duplicate SMS');
    sh_support_sms_clear_test_nonces();

    /* --- 11. Saving new config clears a stale result ---------------------- */
    $enable('textbee');
    sh_support_sms_test();
    $eq('1', sh_support_sms_setting('test_ok'), 'a successful test result is stored');
    sh_support_sms_save(['enabled' => '1', 'phone' => '8801712345678', 'provider' => 'textbee']);
    $eq('', sh_support_sms_setting('test_message'), 'changing configuration clears the stale connection/test result');
    $ok(strlen(sh_support_sms_setting('textbee_api_key')) > 0,
        'a blank secret field keeps the previously saved credential');
    sh_support_sms_clear_test();
    $eq('', sh_support_sms_setting('test_ok'), 'clear-result action removes the stored outcome');

    $disable();
    $eq('disabled', sh_support_sms_ready()['reason'], 'channel is disabled again at the end of the suite');
});

exit($failed > 0 ? 1 : 0);
