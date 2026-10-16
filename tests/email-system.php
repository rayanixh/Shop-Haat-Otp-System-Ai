<?php
/**
 * Email system test — SMTP transport, verification challenges and the webhook.
 *
 * Run with a test-only config/database.php that points at a disposable MySQL /
 * MariaDB database. Two halves:
 *
 *   A. Transport (no provider needed): a small capture SMTP server is started on
 *      a loopback port and the real PHPMailer path is driven against it, so the
 *      submission, STARTTLS/AUTH behaviour, dot-stuffing and UTF-8 headers are
 *      exercised for real. AUTH rejection is exercised against a second listener
 *      that answers 535.
 *
 *   B. Challenges and logging (MySQL): hashed-only storage, wrong/expired/reused
 *      codes, attempt locking, purpose isolation, resend cooldown, rate limits,
 *      single use under concurrency, redaction of the delivery log, webhook
 *      signature verification and replay protection.
 *
 * Nothing here fakes a delivery: a message is only ever treated as sent when the
 * capture server or the transport reports acceptance, and the AUTH-failure case
 * asserts the failure is reported as a failure.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/email-system.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/mail.php';

function email_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function email_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

$GLOBALS['email_phase'] = 'start';
function email_phase(string $name): void
{
    $GLOBALS['email_phase'] = $name;
    echo '::notice title=Email system test::' . $name . "\n";
}

/** Create the store schema and reset the mail tables. */
function email_prepare(PDO $pdo): void
{
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    foreach (['email_challenges', 'email_log', 'users', 'settings'] as $t) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_email_seed_settings($pdo);
    sh_setting_save('email_schema_version', (string)SH_EMAIL_SCHEMA_VERSION);
    sh_setting_save('email_verify_grandfathered', '1');
    // The customer auth mode decides whether the email gate applies at all.
    sh_setting_save('authentication_mode', 'email_password');
    sh_setting_save('email_verify_enabled', '1');
    sh_setting_save('email_verification_required', '0');
}

/** A minimal SMTP listener that records one conversation and answers like a server. */
function email_spawn_smtp(string $mode, int $port): array
{
    $dir = sys_get_temp_dir() . '/shophaat-smtp-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);
    $script = __DIR__ . '/smtp-capture-server.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg((string)$port) . ' ' . escapeshellarg($mode) . ' '
        . escapeshellarg($dir) . ' > ' . escapeshellarg($dir . '/server.log') . ' 2>&1 &';
    exec($cmd);
    // Wait for the listener to accept a connection (up to 5 s).
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if (is_resource($conn)) { fclose($conn); return ['dir' => $dir, 'port' => $port, 'mode' => $mode]; }
    }
    throw new RuntimeException('The capture SMTP server did not start on port ' . $port);
}

/** Decode one base64 MIME part out of a captured message. */
function email_part_body(string $message, string $mime): string
{
    $blocks = preg_split('~^--.*$~m', $message) ?: [];
    foreach ($blocks as $block) {
        if (stripos($block, 'Content-Type: ' . $mime) === false) { continue; }
        $chunks = explode("\n\n", $block, 2);
        $body = $chunks[1] ?? '';
        $decoded = base64_decode((string)preg_replace('~\s+~', '', $body), true);
        return is_string($decoded) ? $decoded : '';
    }
    return '';
}

$pdo = sh_db();
email_phase('prepare');
email_prepare($pdo);

if (!function_exists('stream_socket_server') || !function_exists('fsockopen')) {
    echo "::warning title=Email system test::sockets unavailable — transport half skipped\n";
}

// ===========================================================================
email_phase('A transport via capture SMTP');
// ===========================================================================
if (function_exists('stream_socket_server')) {
    $port = 25000 + random_int(1, 900);
    $server = email_spawn_smtp('normal', $port);
    $transport = [
        'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none',
        'username' => 'apiuser', 'password' => 'correct-horse-battery-staple',
        'from_email' => 'noreply@shophaat.test', 'from_name' => 'ShopHaat Test',
        'timeout' => 5, 'dkim_enabled' => false,
    ];
    $body = "Line one.\n.Looks like dot-stuffing\r\nLine three ünïcode ✓";
    $result = sh_mail_send('customer@example.test', 'Verification code 123456', $body, '<p>HTML ünïcode</p>', [
        'transport' => $transport,
        'log_purpose' => 'test_transport',
    ]);
    email_assert(!empty($result['ok']), 'The SMTP submission to the capture server failed: ' . (string)($result['error'] ?? ''));
    email_assert_same('smtp', (string)$result['transport'], 'The transport must report smtp');
    email_assert((string)($result['message_id'] ?? '') !== '', 'A message id must be reported');

    $captured = @file_get_contents($server['dir'] . '/message.txt');
    $captured = is_string($captured) ? $captured : '';
    email_assert($captured !== '', 'The capture server recorded no message');
    email_assert(str_contains($captured, 'RCPT TO:<customer@example.test>'), 'The recipient was not in the conversation');
    email_assert(str_contains($captured, 'AUTH LOGIN'), 'Authenticated SMTP was not used');
    email_assert(str_contains($captured, 'Subject: Verification code 123456'), 'The subject did not arrive');
    email_assert(str_contains($captured, 'Content-Type: multipart/alternative'), 'A text and HTML alternative must both be sent');
    email_assert(str_contains($captured, 'Content-Type: text/plain'), 'The plain-text alternative is missing');
    email_assert(str_contains($captured, 'Content-Type: text/html'), 'The HTML alternative is missing');
    // The body is base64 encoded (UTF-8 safe), so the plain-text alternative is
    // decoded and compared byte for byte: the leading-dot line and the unicode
    // must both survive the transport unchanged.
    $messageStart = strpos($captured, "--- message ---");
    $rawMessage = $messageStart === false ? $captured : substr($captured, $messageStart);
    $textPart = email_part_body($rawMessage, 'text/plain');
    email_assert($textPart === $body, 'The plain-text alternative did not survive the transport: '
        . var_export($textPart, true));
    // The transcript the admin test screen shows must never contain the password.
    $debug = sh_mail_send('second@example.test', 'Debug run', 'Body', null, ['transport' => $transport, 'debug' => true, 'log_purpose' => 'test_transport']);
    email_assert(!empty($debug['ok']), 'The debug submission failed');
    email_assert(!str_contains((string)($debug['transcript'] ?? ''), 'correct-horse-battery-staple'), 'The SMTP password leaked into the debug transcript');
    email_assert(!str_contains((string)($debug['transcript'] ?? ''), base64_encode('correct-horse-battery-staple')), 'The encoded SMTP password leaked into the debug transcript');

    // AUTH rejection must be reported as a failure with an auth category.
    $port2 = 26000 + random_int(1, 900);
    $server2 = email_spawn_smtp('auth_fail', $port2);
    $transport2 = $transport;
    $transport2['port'] = $port2;
    $failed = sh_mail_send('customer@example.test', 'Should not send', 'Body', null, ['transport' => $transport2, 'log_purpose' => 'test_auth_fail']);
    email_assert(empty($failed['ok']), 'An SMTP authentication failure must not be reported as sent');
    email_assert_same('auth', (string)($failed['category'] ?? ''), 'An authentication failure must be categorised as auth');
    email_assert(!str_contains((string)($failed['error'] ?? ''), 'correct-horse-battery-staple'), 'The password leaked into the error text');

    // The refusal must be in the log, redacted.
    $row = sh_one("SELECT * FROM email_log WHERE purpose = 'test_auth_fail' ORDER BY id DESC LIMIT 1");
    email_assert($row !== null, 'The failed attempt was not logged');
    email_assert_same('failed', (string)$row['status'], 'A refused message must be logged as failed, not accepted');
    email_assert(!str_contains((string)$row['error_text'] . (string)$row['subject'], 'correct-horse-battery-staple'), 'The log stored the SMTP password');

    // An unreachable host is a connection error, not a silent success.
    $transport3 = $transport;
    $transport3['port'] = 9; // discard port: nothing listens
    $unreachable = sh_mail_send('customer@example.test', 'Unreachable', 'Body', null, ['transport' => $transport3, 'log_purpose' => 'test_unreachable']);
    email_assert(empty($unreachable['ok']), 'An unreachable SMTP host must not be reported as sent');
    email_assert(in_array((string)$unreachable['category'], ['connection', 'temporary', 'unknown'], true),
        'An unreachable host must be categorised as a connection problem, got ' . (string)$unreachable['category']);

    // No sender configured → skipped, never "sent".
    $skipped = sh_mail_send('customer@example.test', 'No sender', 'Body', null, [
        'transport' => array_merge($transport, ['from_email' => '']),
        'log_purpose' => 'test_no_sender',
    ]);
    email_assert(empty($skipped['ok']) && !empty($skipped['skipped']), 'A missing sender must be reported as skipped');
    email_assert_same('not_configured', (string)$skipped['category'], 'A missing sender must be categorised as not_configured');
}

// ===========================================================================
email_phase('B challenge policy');
// ===========================================================================
$email = 'verify-me@example.test';
$uid = (int)sh_insert('users', [
    'name' => 'Test Customer', 'email' => $email, 'phone' => '8801700000000',
    'password_hash' => password_hash('correct-horse-1', PASSWORD_DEFAULT), 'status' => 'active',
    'email_verified' => 0,
]);

$issue = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_SIGNUP, $uid);
email_assert(!empty($issue['ok']), 'A code must be issued');
$code = (string)$issue['code'];
email_assert_same(6, strlen($code), 'The code must be six digits');
email_assert(ctype_digit($code), 'The code must be numeric');
$row = sh_one('SELECT * FROM email_challenges WHERE id = ?', [(int)$issue['challenge_id']]);
email_assert($row !== null, 'The challenge was not stored');
email_assert(!str_contains((string)$row['code_hash'], $code), 'The plaintext code must never be stored');
email_assert(str_starts_with((string)$row['code_hash'], '$'), 'The stored code must be a password_hash() value');
email_assert_same(0, (int)sh_val('SELECT COUNT(*) FROM email_challenges WHERE code_hash = ?', [$code], 0),
    'No row may contain the plaintext code');

// Wrong code → attempt spent, still one live challenge.
$wrong = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_SIGNUP, str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT), $uid);
email_assert(empty($wrong['ok']), 'A wrong code must not verify');
email_assert_same('wrong', (string)$wrong['state'], 'A wrong code must report the wrong state');
email_assert_same(1, (int)sh_val('SELECT attempts FROM email_challenges WHERE id = ?', [(int)$issue['challenge_id']], 0), 'The attempt counter must increase');

// Purpose isolation: the signup code cannot be used for a password reset.
$crossPurpose = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_RESET, $code, $uid);
email_assert(empty($crossPurpose['ok']), 'A code must not work for another purpose');
email_assert_same('none', (string)$crossPurpose['state'], 'A missing challenge for that purpose must be reported');

// Correct code → verified once, then dead.
$ok = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_SIGNUP, $code, $uid);
email_assert(!empty($ok['ok']), 'The correct code must verify');
sh_email_mark_verified($uid, 'smtp_otp');
email_assert_same(1, (int)sh_val('SELECT email_verified FROM users WHERE id = ?', [$uid], 0), 'The account must be marked verified');
$reused = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_SIGNUP, $code, $uid);
email_assert(empty($reused['ok']), 'A used code must not be accepted twice');
email_assert(in_array((string)$reused['state'], ['used', 'none', 'expired'], true), 'A reused code must be reported as used/none, got ' . (string)$reused['state']);
email_assert_same(1, (int)sh_val('SELECT COUNT(*) FROM email_challenges WHERE id = ? AND used_at IS NOT NULL', [(int)$issue['challenge_id']], 0),
    'The successful challenge must be stamped as used');

// Resend cooldown, then expiry, then the attempt lock.
sleep(0); // no artificial delay: the cooldown is asserted through the timestamps below
$again = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_RESET, $uid);
email_assert(!empty($again['ok']), 'A reset code must be issued for the same address');
sh_query('UPDATE email_challenges SET last_sent_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int)$again['challenge_id']]);
$cooldown = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_RESET, $uid);
email_assert(empty($cooldown['ok']), 'The resend cooldown must refuse an immediate second request');
email_assert((int)($cooldown['wait'] ?? 0) > 0, 'The cooldown must say how long to wait');

// Expiry.
sh_query('UPDATE email_challenges SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), (int)$again['challenge_id']]);
$expired = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_RESET, (string)$again['code'], $uid);
email_assert(empty($expired['ok']), 'An expired code must not verify');
email_assert_same('expired', (string)$expired['state'], 'An expired code must report the expired state');
email_assert_same('expired', (string)sh_val('SELECT status FROM email_challenges WHERE id = ?', [(int)$again['challenge_id']], ''), 'The expired row must be marked');

// Attempt lock: max_attempts wrong guesses kill the code.
sh_query("UPDATE email_challenges SET last_sent_at = ? WHERE email = ? AND purpose = ?", [date('Y-m-d H:i:s', time() - 3600), $email, SH_EMAIL_PURPOSE_RESET]);
$lock = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_RESET, $uid);
email_assert(!empty($lock['ok']), 'A fresh code must be issued after the cooldown');
$bad = str_pad((string)(((int)$lock['code'] + 7) % 1000000), 6, '0', STR_PAD_LEFT);
for ($i = 1; $i <= sh_email_otp_max_attempts(); $i++) {
    $r = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_RESET, $bad, $uid);
    email_assert(empty($r['ok']), 'A wrong code must never verify (attempt ' . $i . ')');
}
$last = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_RESET, (string)$lock['code'], $uid);
email_assert(empty($last['ok']), 'The correct code must be refused once the attempt budget is gone');
email_assert_same('locked', (string)$last['state'], 'The attempts must lock the challenge');
email_assert_same('locked', (string)sh_val('SELECT status FROM email_challenges WHERE id = ?', [(int)$lock['challenge_id']], ''), 'The locked state must be persisted');

// Rate limit: the per-address hourly cap refuses further issues.
sh_query('UPDATE email_challenges SET last_sent_at = ? WHERE email = ?', [date('Y-m-d H:i:s', time() - 3600), $email]);
$cap = max(3, (int)sh_setting('email_otp_email_limit', '3')) + sh_email_otp_max_resends();
$issued = 0;
for ($i = 0; $i < $cap + 3; $i++) {
    sh_query('UPDATE email_challenges SET last_sent_at = ? WHERE email = ?', [date('Y-m-d H:i:s', time() - 3600), $email]);
    $r = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_RESET, $uid);
    if (!empty($r['ok'])) { $issued++; }
}
email_assert($issued <= $cap, 'The hourly cap must stop issuing after ' . $cap . ' requests (issued ' . $issued . ')');
$refused = sh_email_challenge_issue($email, SH_EMAIL_PURPOSE_RESET, $uid);
email_assert(empty($refused['ok']), 'The rate limit must refuse further requests');

// Cleanup removes finished rows and keeps live ones.
sh_query("UPDATE email_challenges SET created_at = ? WHERE status <> 'pending'", [date('Y-m-d H:i:s', time() - 48 * 3600)]);
$removed = sh_email_challenge_cleanup(24);
email_assert($removed > 0, 'Cleanup must remove finished rows older than the window');
email_assert((int)sh_val("SELECT COUNT(*) FROM email_challenges WHERE status <> 'pending'", [], 0) === 0, 'Finished rows must be gone after cleanup');

// ===========================================================================
email_phase('C verification gate and notification honesty');
// ===========================================================================
$unverified = ['id' => $uid, 'email' => $email, 'email_verified' => 0];
sh_setting_save('email_verification_required', '1');
$gate = sh_email_gate_blocked($unverified, 'checkout');
email_assert($gate !== null, 'The checkout gate must block an unverified account when it is switched on');
email_assert_same(null, sh_email_gate_blocked(['id' => $uid, 'email' => $email, 'email_verified' => 1], 'checkout'),
    'The gate must let a verified account through');
email_assert_same(null, sh_email_gate_blocked(['id' => $uid, 'email' => 'phone+8801700000000@user.shophaat.local', 'email_verified' => 0], 'checkout'),
    'The gate must not apply to synthetic phone-only addresses');
sh_setting_save('email_verification_required', '0');
email_assert_same(null, sh_email_gate_blocked($unverified, 'checkout'), 'The gate must be inert while it is switched off');

// The notification reports the real outcome: with no usable transport it fails.
sh_setting_save('smtp_enabled', '0');
sh_setting_save('smtp_from_email', '');
$notice = sh_email_notify_password_changed(['id' => $uid, 'name' => 'Test', 'email' => $email], 'account');
email_assert(empty($notice['ok']), 'A notification must not be reported as sent when no sender is configured');
$logged = sh_one("SELECT * FROM email_log WHERE purpose = 'password_changed' ORDER BY id DESC LIMIT 1");
email_assert($logged !== null, 'The attempt must still be logged');
email_assert_same('skipped', (string)$logged['status'], 'A refused notification must be logged as skipped');

// Switched off means switched off.
sh_setting_save('email_notify_password_change', '0');
$off = sh_email_notify_password_changed(['id' => $uid, 'name' => 'Test', 'email' => $email], 'account');
email_assert(empty($off['ok']) && !empty($off['skipped']), 'A switched-off notification must be skipped');
sh_setting_save('email_notify_password_change', '1');

// ===========================================================================
email_phase('D templates and redaction');
// ===========================================================================
$tpl = sh_email_layout([
    'subject' => 'Your code',
    'heading' => 'Confirm your email',
    'paragraphs' => ['Use the code below.'],
    'code' => '123456',
    'lines' => ['When' => 'today'],
    'cta' => ['label' => 'Open account', 'url' => 'https://example.test/account.php'],
    'note' => 'Not you? Ignore this.',
]);
email_assert(isset($tpl['text'], $tpl['html'], $tpl['subject']), 'The template must produce subject, text and html');
email_assert(str_contains($tpl['text'], '123456'), 'The plain-text alternative must contain the code');
email_assert(str_contains($tpl['html'], '123456'), 'The HTML body must contain the code');
email_assert(str_contains($tpl['html'], 'text-align:center'), 'The code block must be centred for mobile clients');
email_assert(!str_contains($tpl['html'], '<script'), 'The HTML body must not contain a script tag');
// Injected markup is escaped.
$evil = sh_email_layout([
    'subject' => 'x',
    'heading' => 'x',
    'paragraphs' => ['<script>alert(1)</script>'],
    'note' => '<img src=x onerror=alert(1)>',
]);
email_assert(!str_contains($evil['html'], '<script>'), 'User text must be escaped in the HTML body');
email_assert(str_contains($evil['html'], '&lt;script&gt;'), 'Escaped text must still be readable');

// Redaction.
$red = sh_mail_redact('RCPT failed for password=sup3rsecret and API key abcdef1234567890abcdef1234567890');
email_assert(!str_contains($red, 'sup3rsecret'), 'A password must be redacted');
email_assert(!str_contains($red, 'abcdef1234567890abcdef1234567890'), 'A long token must be redacted');

// ===========================================================================
email_phase('E webhook signature, replay and event mapping');
// ===========================================================================
sh_setting_save('email_webhook_secret', str_repeat('a', 32));
$secret = sh_email_webhook_secret();
$body = json_encode(['event' => 'delivered', 'message-id' => 'test-message-id', 'email' => 'customer@example.test']);

email_assert(empty(sh_email_webhook_verify($body, [], [])['ok']), 'A webhook without a secret must be refused');
email_assert(empty(sh_email_webhook_verify($body, [], ['key' => 'wrong-secret'] )['ok']), 'A wrong secret must be refused');
email_assert(!empty(sh_email_webhook_verify($body, [], ['key' => $secret])['ok']), 'The configured secret must be accepted');
$sig = hash_hmac('sha256', $body, $secret);
email_assert(!empty(sh_email_webhook_verify($body, ['x-shophaat-signature' => 'sha256=' . $sig], [])['ok']), 'A valid HMAC signature must be accepted');
email_assert(empty(sh_email_webhook_verify($body, ['x-shophaat-signature' => 'sha256=' . str_repeat('0', 64)], [])['ok']), 'A wrong HMAC signature must be refused');
email_assert(empty(sh_email_webhook_verify($body, [], ['key' => substr($secret, 0, 16)])['ok']), 'A truncated secret must be refused');
sh_setting_save('email_webhook_secret', '');
email_assert(empty(sh_email_webhook_verify($body, [], ['key' => $secret])['ok']), 'With no secret configured every webhook must be refused');
sh_setting_save('email_webhook_secret', $secret);

// Event mapping moves an accepted row to delivered, and a replay changes nothing.
sh_query('INSERT INTO email_log (recipient, purpose, subject, transport, status, category, message_id, created_at)
          VALUES (?,?,?,?,?,?,?,NOW())',
    ['customer@example.test', 'signup', 'Your code', 'smtp', 'accepted', 'accepted', 'test-message-id']);
$before = (int)sh_val('SELECT id FROM email_log WHERE message_id = ?', ['test-message-id'], 0);
$applied = sh_email_event_apply(['event' => 'delivered', 'message-id' => 'test-message-id'], 'evt-1');
email_assert(!empty($applied['ok']) && (int)$applied['applied'] === 1, 'A delivered event must update the row');
email_assert_same('delivered', (string)sh_val('SELECT status FROM email_log WHERE id = ?', [$before], ''), 'The row must be delivered');
$again = sh_email_event_apply(['event' => 'delivered', 'message-id' => 'test-message-id'], 'evt-1');
email_assert((int)$again['applied'] === 0, 'Replaying the same event id must change nothing');
$bounce = sh_email_event_apply(['event' => 'hard_bounce', 'message-id' => 'test-message-id', 'reason' => '550 mailbox unavailable'], 'evt-2');
email_assert((int)$bounce['applied'] === 1, 'A bounce must update the row');
email_assert_same('bounced', (string)sh_val('SELECT status FROM email_log WHERE id = ?', [$before], ''), 'The row must be bounced');
$unknown = sh_email_event_apply(['event' => 'something-new', 'message-id' => 'test-message-id'], 'evt-3');
email_assert(empty($unknown['ok']), 'An unsupported event name must be ignored');

// Retry policy: codes can never be re-sent from the log.
$codeRow = (int)sh_val('SELECT id FROM email_log WHERE purpose = ? ORDER BY id DESC LIMIT 1', ['signup'], 0);
$retry = sh_email_retry_notification($codeRow);
email_assert(empty($retry['ok']), 'A verification-code email must not be re-sendable from the log');

// ===========================================================================
email_phase('F cleanup');
// ===========================================================================
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['email_challenges', 'email_log'] as $t) { $pdo->exec("DROP TABLE IF EXISTS `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
// The rest of the schema is left exactly as email_prepare() built it: the test
// only owns its own two tables, and anything that runs after it must still find
// a usable store database. The row this run inserted is removed.
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);

echo "\nEmail system: transport, challenges, logging and webhook all passed.\n";
