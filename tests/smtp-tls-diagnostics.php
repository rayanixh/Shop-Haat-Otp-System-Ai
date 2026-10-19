<?php
/**
 * SMTP/Brevo TLS diagnostics test.
 *
 * This suite drives the real transport code against local TLS servers:
 *   - a certificate that matches 127.0.0.1, a real STARTTLS handshake, AUTH and
 *     a submitted message;
 *   - a certificate for a different hostname, which must be rejected at the
 *     hostname stage while the chain itself is trusted;
 *   - a server that answers 535, which must be reported as an AUTH failure;
 *   - a local HTTPS Brevo API stub, which must accept a valid key and reject a
 *     wrong one while TLS verification stays on.
 *
 * The purpose is to prove that verification is never bypassed in the delivery
 * path and that a certificate mismatch is reported as a hostname mismatch, not
 * as a generic cryptographic failure.
 *
 * Usage: php tests/smtp-tls-diagnostics.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
define('SH_ROOT', $root);
$tmp = sys_get_temp_dir() . '/shophaat-smtp-tls-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

$GLOBALS['test_settings'] = [];
$GLOBALS['delivery_log'] = [];
$GLOBALS['test_servers'] = [];

function tls_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function tls_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}
function tls_notice(string $message): void
{
    echo '::notice title=SMTP TLS diagnostics::' . $message . "\n";
}
function tls_run(string $command): array
{
    $out = [];
    $code = 0;
    @exec($command . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
function tls_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) { throw new RuntimeException('Could not allocate a test port: ' . $errstr); }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    if ($port < 1) { throw new RuntimeException('Could not read the allocated test port.'); }
    return $port;
}
function tls_start_server(string $script, array $args, string $dir): array
{
    if (!function_exists('shell_exec')) {
        throw new RuntimeException('shell_exec is disabled; the TLS test needs to start local servers.');
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg((string)$arg); }
    $log = $dir . '/server-' . basename($script) . '-' . bin2hex(random_bytes(3)) . '.log';
    $cmd .= ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($cmd));
    if ($pid < 1) { throw new RuntimeException('Could not start ' . $script); }
    $ready = $dir . '/ready';
    $deadline = microtime(true) + 8;
    while (microtime(true) < $deadline) {
        if (is_file($ready)) { break; }
        usleep(100000);
    }
    if (!is_file($ready)) {
        throw new RuntimeException('Server did not become ready: ' . $script . ' (' . $log . ')');
    }
    @unlink($ready);
    return ['pid' => $pid, 'log' => $log];
}
function tls_stop_servers(): void
{
    foreach ($GLOBALS['test_servers'] as $server) {
        if (!empty($server['pid'])) {
            if (function_exists('posix_kill')) { @posix_kill((int)$server['pid'], 15); }
            else { @exec('kill ' . (int)$server['pid'] . ' 2>/dev/null'); }
        }
    }
    $GLOBALS['test_servers'] = [];
}

// ---------------------------------------------------------------------------
// Test-only settings and transport stubs (no database)
// ---------------------------------------------------------------------------
function sh_setting(string $key, $default = null)
{
    return $GLOBALS['test_settings'][$key] ?? $default;
}
function sh_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
function sh_verify_decrypt(?string $stored): string { return (string)$stored; }
function sh_verify_encrypt(string $plain): string { return $plain; }
function sh_email_delivery_log(array $result): void { $GLOBALS['delivery_log'][] = $result; }
function sh_log_line(string $channel, string $message): void {}
function sh_log_exception(Throwable $e, string $context): void {}

// ---------------------------------------------------------------------------
// Generate a test CA and two leaf certificates
// ---------------------------------------------------------------------------
if (!function_exists('exec')) {
    tls_notice('exec() is disabled, so the TLS diagnostics test cannot start its local servers; skipped.');
    exit(0);
}
[$code, $version] = tls_run('openssl version');
if ($code !== 0) {
    tls_notice('OpenSSL CLI is unavailable; the TLS diagnostics test was skipped.');
    exit(0);
}

$caKey = $tmp . '/ca.key';
$caPem = $tmp . '/ca.pem';
$goodKey = $tmp . '/good.key';
$goodCsr = $tmp . '/good.csr';
$goodPem = $tmp . '/good.pem';
$wrongKey = $tmp . '/wrong.key';
$wrongCsr = $tmp . '/wrong.csr';
$wrongPem = $tmp . '/wrong.pem';
$goodExt = $tmp . '/good.ext';
$wrongExt = $tmp . '/wrong.ext';
file_put_contents($goodExt, "subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n");
file_put_contents($wrongExt, "subjectAltName=DNS:wrong.test\nextendedKeyUsage=serverAuth\n");

$steps = [
    'req -x509 -newkey rsa:2048 -sha256 -nodes -days 2 -keyout ' . escapeshellarg($caKey) . ' -out ' . escapeshellarg($caPem)
        . ' -subj ' . escapeshellarg('/CN=ShopHaat Test CA') . ' -addext ' . escapeshellarg('basicConstraints=critical,CA:TRUE')
        . ' -addext ' . escapeshellarg('keyUsage=critical,keyCertSign,cRLSign'),
    'req -newkey rsa:2048 -sha256 -nodes -keyout ' . escapeshellarg($goodKey) . ' -out ' . escapeshellarg($goodCsr)
        . ' -subj ' . escapeshellarg('/CN=localhost'),
    'x509 -req -in ' . escapeshellarg($goodCsr) . ' -CA ' . escapeshellarg($caPem) . ' -CAkey ' . escapeshellarg($caKey)
        . ' -CAcreateserial -out ' . escapeshellarg($goodPem) . ' -days 2 -sha256 -extfile ' . escapeshellarg($goodExt),
    'req -newkey rsa:2048 -sha256 -nodes -keyout ' . escapeshellarg($wrongKey) . ' -out ' . escapeshellarg($wrongCsr)
        . ' -subj ' . escapeshellarg('/CN=wrong.test'),
    'x509 -req -in ' . escapeshellarg($wrongCsr) . ' -CA ' . escapeshellarg($caPem) . ' -CAkey ' . escapeshellarg($caKey)
        . ' -CAcreateserial -out ' . escapeshellarg($wrongPem) . ' -days 2 -sha256 -extfile ' . escapeshellarg($wrongExt),
];
foreach ($steps as $step) {
    [$stepCode, $stepOut] = tls_run('openssl ' . $step);
    if ($stepCode !== 0) {
        fwrite(STDERR, "Could not generate test certificates:\n" . $stepOut . "\n");
        exit(1);
    }
}

// The trust anchor is a real CA file; verification is never disabled.
define('SH_MAIL_CA_BUNDLE', $caPem);

$goodSmtpPort = tls_free_port();
$wrongSmtpPort = tls_free_port();
$authFailPort = tls_free_port();
$apiPort = tls_free_port();
define('SH_BREVO_API_BASE', 'https://127.0.0.1:' . $apiPort . '/v3');

require_once $root . '/config/mail.php';

$GLOBALS['test_settings'] = [
    'mail_transport' => 'smtp',
    'smtp_enabled' => '1',
    'smtp_host' => '127.0.0.1',
    'smtp_port' => (string)$goodSmtpPort,
    'smtp_encryption' => 'tls',
    'smtp_username' => 'smtp-user@example.test',
    'smtp_password' => 'smtp-secret',
    'smtp_from_email' => 'noreply@example.test',
    'smtp_from_name' => 'ShopHaat TLS Test',
    'smtp_timeout' => '8',
    'email_enabled' => '1',
    'email_delivery_provider' => 'smtp',
    'brevo_api_enabled' => '0',
    'brevo_api_key' => '',
    // A legacy store may still have this set. The transport must ignore it.
    'smtp_allow_self_signed' => '1',
];

try {
    echo "1. name matching and category classification\n";
    tls_assert(sh_mail_tls_name_matches('smtp-relay.brevo.com', ['smtp-relay-offshore-southamerica-east-v2.sendinblue.com', 'smtp-relay.sendinblue.com']) === false, 'A Sendinblue-only SAN list must not match smtp-relay.brevo.com.');
    tls_assert(sh_mail_tls_name_matches('smtp-relay.sendinblue.com', ['smtp-relay-offshore-southamerica-east-v2.sendinblue.com', 'smtp-relay.sendinblue.com']) === true, 'The legacy SAN should match its exact name.');
    tls_assert(sh_mail_tls_name_matches('a.example.com', ['*.example.com']) === true, 'A single-label wildcard should match.');
    tls_assert(sh_mail_tls_name_matches('a.b.example.com', ['*.example.com']) === false, 'A wildcard must not match two labels.');
    tls_same('tls_hostname', sh_mail_category('stream_socket_enable_crypto(): Peer certificate subjectAltName did not match expected name "smtp-relay.brevo.com"'), 'The category must name hostname validation.');
    tls_same('hostname', sh_mail_tls_error_kind('Peer certificate subjectAltName did not match expected name "smtp-relay.brevo.com"'), 'The TLS classifier must name hostname validation.');

    echo "2. matching certificate: STARTTLS, EHLO after TLS, AUTH and a real submission\n";
    $GLOBALS['test_servers'][] = tls_start_server($root . '/tests/smtp-tls-server.php', [$goodSmtpPort, $goodPem, $goodKey, 'normal', $tmp], $tmp);
    $goodTransport = [
        'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $goodSmtpPort, 'encryption' => 'tls',
        'username' => 'smtp-user@example.test', 'password' => 'smtp-secret', 'timeout' => 8,
    ];
    $good = sh_mail_test_connection($goodTransport);
    tls_assert(!empty($good['ok']), 'The matching-certificate connection test failed: ' . ($good['error'] ?? ''));
    tls_same('complete', $good['step'], 'The matching-certificate test did not reach complete.');
    tls_assert(($good['certificate']['hostname_valid'] ?? null) === true, 'The matching certificate was not reported as hostname-valid.');
    tls_assert(($good['certificate']['chain_valid'] ?? null) === true, 'The matching certificate chain was not trusted.');
    tls_assert(($good['tls_protocol'] ?? '') !== '', 'No TLS protocol was captured from the live socket.');
    tls_assert(($good['certificate']['dns_sans'] ?? []) === ['localhost'], 'The test certificate SAN was not read correctly.');

    $sent = sh_mail_send_test('inbox@example.test', sh_mail_transport(), ['admin_id' => 1]);
    tls_assert(!empty($sent['ok']), 'The real test submission failed: ' . ($sent['error'] ?? ''));
    tls_same('smtp', $sent['transport'], 'The test submission used the wrong transport.');
    $message = @file_get_contents($tmp . '/message.txt');
    tls_assert(is_string($message) && str_contains($message, 'ShopHaat test email'), 'The SMTP server did not receive the test message.');
    tls_assert(str_contains($message, 'inbox@example.test'), 'The submitted message did not contain the recipient.');

    echo "3. unexpected certificate is rejected at the hostname stage, even with the legacy self-signed setting\n";
    $wrongDir = $tmp . '/wrong';
    @mkdir($wrongDir, 0777, true);
    $GLOBALS['test_servers'][] = tls_start_server($root . '/tests/smtp-tls-server.php', [$wrongSmtpPort, $wrongPem, $wrongKey, 'normal', $wrongDir], $wrongDir);
    $badTransport = $goodTransport;
    $badTransport['port'] = $wrongSmtpPort;
    $bad = sh_mail_test_connection($badTransport);
    tls_assert(empty($bad['ok']), 'A certificate for the wrong hostname was accepted.');
    tls_same('tls_hostname', $bad['step'], 'The wrong-name certificate was not reported at tls_hostname.');
    tls_assert(($bad['certificate']['hostname_valid'] ?? null) === false, 'The certificate inspector did not report the hostname mismatch.');
    tls_assert(in_array('wrong.test', (array)($bad['certificate']['dns_sans'] ?? []), true), 'The inspector did not show the presented SAN.');
    tls_assert(($bad['certificate']['chain_valid'] ?? null) === true, 'The test certificate should have a trusted chain even though the name is wrong.');
    $badText = strtolower((string)($bad['error'] ?? ''));
    tls_assert(str_contains($badText, 'subjectaltname') || str_contains($badText, 'did not match'), 'The error did not contain the OpenSSL hostname wording.');

    $sendBad = sh_mail_send('inbox@example.test', 'must not send', 'must not send', null, ['transport' => $badTransport]);
    tls_assert(empty($sendBad['ok']), 'The delivery path accepted a mismatched certificate.');
    tls_same('tls_hostname', $sendBad['category'], 'The delivery log category was not tls_hostname.');
    // The inspector connection and the rejected delivery each get a
    // transcript. Check every one: no AUTH or MAIL may appear before the
    // encrypted channel is established.
    $wrongTranscripts = [];
    $wrongDeadline = microtime(true) + 4;
    do {
        $wrongTranscripts = glob($wrongDir . '/conversation-*.txt') ?: [];
        if ($wrongTranscripts !== []) { break; }
        usleep(100000);
    } while (microtime(true) < $wrongDeadline);
    tls_assert($wrongTranscripts !== [], 'The rejected TLS connection was not recorded by the test server.');
    $wrongConversation = '';
    foreach ($wrongTranscripts as $wrongTranscript) {
        $wrongConversation .= (string)@file_get_contents($wrongTranscript);
    }
    tls_assert(!str_contains($wrongConversation, 'AUTH') && !str_contains($wrongConversation, 'MAIL FROM'), 'Credentials or mail commands were sent before TLS was verified.');

    echo "4. wrong credentials report an authentication error, not a TLS error\n";
    $authDir = $tmp . '/auth';
    @mkdir($authDir, 0777, true);
    $GLOBALS['test_servers'][] = tls_start_server($root . '/tests/smtp-tls-server.php', [$authFailPort, $goodPem, $goodKey, 'auth_fail', $authDir], $authDir);
    $authTransport = $goodTransport;
    $authTransport['port'] = $authFailPort;
    $authTransport['password'] = 'wrong-secret';
    $auth = sh_mail_test_connection($authTransport);
    tls_assert(empty($auth['ok']), 'Wrong credentials were accepted.');
    tls_same('auth', $auth['step'], 'Wrong credentials did not fail at the auth stage.');
    tls_same('auth', $auth['category'] ?? '', 'Wrong credentials were not categorised as auth.');
    tls_assert(stripos((string)($auth['error'] ?? ''), '535') !== false, 'The AUTH failure did not include the 535 response.');
    tls_assert(($auth['certificate']['hostname_valid'] ?? null) === true, 'TLS should have passed before the AUTH failure.');

    echo "5. Brevo HTTPS API transport: TLS, API key, real send, wrong key\n";
    $apiDir = $tmp . '/api';
    @mkdir($apiDir, 0777, true);
    $GLOBALS['test_servers'][] = tls_start_server($root . '/tests/brevo-api-server.php', [$apiPort, $goodPem, $goodKey, $apiDir], $apiDir);
    $GLOBALS['test_settings']['mail_transport'] = 'brevo_api';
    $GLOBALS['test_settings']['brevo_api_enabled'] = '1';
    $GLOBALS['test_settings']['brevo_api_key'] = 'test-api-key';
    $api = sh_mail_test_connection(['transport' => 'brevo_api', 'timeout' => 8]);
    tls_assert(!empty($api['ok']), 'The Brevo API connection test failed: ' . ($api['error'] ?? ''));
    tls_same('complete', $api['step'], 'The Brevo API test did not reach complete.');

    $apiSent = sh_mail_send_test('inbox@example.test', sh_mail_transport(), ['admin_id' => 1]);
    tls_assert(!empty($apiSent['ok']), 'The Brevo API test message failed: ' . ($apiSent['error'] ?? ''));
    tls_same('brevo_api', $apiSent['transport'], 'The Brevo API message used the wrong transport.');
    tls_same('<test-message@brevo>', $apiSent['message_id'] ?? '', 'The Brevo message id was not recorded.');
    tls_assert(!str_contains(json_encode($apiSent), 'test-api-key'), 'The API key leaked into the send result.');
    $request = json_decode((string)@file_get_contents($apiDir . '/request-2.json'), true);
    tls_assert(is_array($request) && $request['path'] === '/v3/smtp/email', 'The API stub did not receive the expected POST.');
    tls_assert(($request['recipient'] ?? '') === 'inbox@example.test', 'The API request did not contain the recipient.');

    $GLOBALS['test_settings']['brevo_api_key'] = 'wrong-api-key';
    $badApi = sh_brevo_api_test_connection();
    tls_assert(empty($badApi['ok']), 'A wrong Brevo API key was accepted.');
    tls_same('api_auth', $badApi['step'], 'The wrong API key was not reported at api_auth.');
    tls_assert(str_contains((string)($badApi['error'] ?? ''), '401'), 'The wrong-key error did not include HTTP 401.');

    echo "6. an incomplete API configuration never falls back to SMTP or mail()\n";
    $GLOBALS['test_settings']['brevo_api_key'] = 'test-api-key';
    $GLOBALS['test_settings']['brevo_api_enabled'] = '0';
    $tr = sh_mail_transport();
    tls_same('brevo_api', $tr['driver'], 'The selected API transport silently changed its driver.');
    tls_assert(!sh_mail_service_status()['ready'], 'An incomplete API configuration was reported as ready.');
    $fallback = sh_mail_send('inbox@example.test', 'no fallback', 'no fallback');
    tls_assert(!empty($fallback['skipped']), 'An incomplete API configuration was not reported as skipped.');
    tls_same('not_configured', $fallback['category'], 'An incomplete API configuration was not categorised as not_configured.');

    echo "OK: all SMTP/API TLS diagnostics passed\n";
    $exit = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    tls_stop_servers();
    // Best-effort cleanup of the generated keys and certificates.
    foreach (glob($tmp . '/*') ?: [] as $file) {
        if (is_file($file)) { @unlink($file); }
    }
    foreach (glob($tmp . '/*/*') ?: [] as $file) {
        if (is_file($file)) { @unlink($file); }
    }
    foreach (glob($tmp . '/*', GLOB_ONLYDIR) ?: [] as $dir) { @rmdir($dir); }
    @rmdir($tmp);
}
exit($exit ?? 1);
