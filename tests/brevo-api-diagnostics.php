<?php
/**
 * Brevo HTTPS API diagnostics and credential-isolation test.
 *
 * Drives the real code in config/mail.php against a local HTTPS stub that
 * imitates Brevo's documented HTTP semantics, including the exact failure that
 * prompted this suite: a key that Brevo accepts for transactional email but
 * refuses for the `/v3/account` resource with 403 permission_denied.
 *
 * Nothing here is faked: the stub is a real TLS server, the requests are real
 * HTTPS requests with certificate verification on, and a message counts as
 * sent only when the stub answers POST /v3/smtp/email with 201.
 *
 * Usage: php tests/brevo-api-diagnostics.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
define('SH_ROOT', $root);
$tmp = sys_get_temp_dir() . '/shophaat-brevo-api-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);

$GLOBALS['test_settings'] = [];
$GLOBALS['test_servers'] = [];
$GLOBALS['test_log'] = [];

// --------------------------------------------------------------------------
// Minimal environment stubs (no database, no framework bootstrap)
// --------------------------------------------------------------------------
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
function sh_log_line(string $channel, string $message): void { $GLOBALS['test_log'][] = $channel . ': ' . $message; }
function sh_log_exception(Throwable $e, string $context): void {}
function sh_query(string $sql, array $params = []): void {}
function sh_val(string $sql, array $params = [], $default = null) { return $default; }

function api_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function api_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}
function api_notice(string $message): void
{
    echo '::notice title=Brevo API diagnostics::' . $message . "\n";
}
function api_run(string $command): array
{
    $out = [];
    $code = 0;
    @exec($command . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}
function api_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) { throw new RuntimeException('Could not allocate a test port: ' . $errstr); }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    if ($port < 1) { throw new RuntimeException('Could not read the allocated test port.'); }
    return $port;
}
function api_start_server(string $script, array $args, string $dir): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg((string)$arg); }
    $log = $dir . '/server-' . bin2hex(random_bytes(3)) . '.log';
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
function api_stop(array $server): void
{
    if (empty($server['pid'])) { return; }
    if (function_exists('posix_kill')) { @posix_kill((int)$server['pid'], 15); }
    else { @exec('kill ' . (int)$server['pid'] . ' 2>/dev/null'); }
}
function api_stop_all(): void
{
    foreach ($GLOBALS['test_servers'] as $server) { api_stop($server); }
    $GLOBALS['test_servers'] = [];
}
/** Every request the stub has recorded so far, in order. */
function api_requests(string $dir): array
{
    $rows = [];
    foreach (glob($dir . '/request-*.json') ?: [] as $file) {
        $row = json_decode((string)@file_get_contents($file), true);
        if (is_array($row)) { $rows[] = $row; }
    }
    return $rows;
}
function api_posts(string $dir): array
{
    return array_values(array_filter(api_requests($dir), static fn(array $r): bool => ($r['method'] ?? '') === 'POST'));
}

if (!function_exists('exec')) {
    api_notice('exec() is disabled, so the HTTPS stub cannot be started; skipped.');
    exit(0);
}
[$opensslCode] = api_run('openssl version');
if ($opensslCode !== 0) {
    api_notice('OpenSSL CLI is unavailable; the Brevo API diagnostics were skipped.');
    exit(0);
}

// --------------------------------------------------------------------------
// Test certificates: a real CA which is the only trust anchor in this run
// --------------------------------------------------------------------------
$caKey = $tmp . '/ca.key';
$caPem = $tmp . '/ca.pem';
$srvKey = $tmp . '/srv.key';
$srvCsr = $tmp . '/srv.csr';
$srvPem = $tmp . '/srv.pem';
$srvExt = $tmp . '/srv.ext';
file_put_contents($srvExt, "subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n");
$steps = [
    'req -x509 -newkey rsa:2048 -sha256 -nodes -days 2 -keyout ' . escapeshellarg($caKey) . ' -out ' . escapeshellarg($caPem)
        . ' -subj ' . escapeshellarg('/CN=ShopHaat Brevo Test CA') . ' -addext ' . escapeshellarg('basicConstraints=critical,CA:TRUE')
        . ' -addext ' . escapeshellarg('keyUsage=critical,keyCertSign,cRLSign'),
    'req -newkey rsa:2048 -sha256 -nodes -keyout ' . escapeshellarg($srvKey) . ' -out ' . escapeshellarg($srvCsr)
        . ' -subj ' . escapeshellarg('/CN=localhost'),
    'x509 -req -in ' . escapeshellarg($srvCsr) . ' -CA ' . escapeshellarg($caPem) . ' -CAkey ' . escapeshellarg($caKey)
        . ' -CAcreateserial -out ' . escapeshellarg($srvPem) . ' -days 2 -sha256 -extfile ' . escapeshellarg($srvExt),
];
foreach ($steps as $step) {
    [$stepCode, $stepOut] = api_run('openssl ' . $step);
    if ($stepCode !== 0) {
        fwrite(STDERR, "Could not generate test certificates:\n" . $stepOut . "\n");
        exit(1);
    }
}

// A real CA file. Certificate verification is never disabled anywhere.
define('SH_MAIL_CA_BUNDLE', $caPem);
$apiPort = api_free_port();
define('SH_BREVO_API_BASE', 'https://127.0.0.1:' . $apiPort . '/v3');
$apiDir = $tmp . '/api';
@mkdir($apiDir, 0777, true);

require_once $root . '/config/mail.php';

$GLOBALS['test_settings'] = [
    'mail_transport' => 'brevo_api',
    'smtp_enabled' => '0',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_from_email' => 'noreply@example.test',
    'smtp_from_name' => 'ShopHaat API Test',
    'smtp_reply_to' => '',
    'smtp_timeout' => '8',
    'email_enabled' => '1',
    'email_delivery_provider' => 'smtp',
    'brevo_api_enabled' => '1',
    'brevo_api_key' => '',
];

$exit = 1;
try {
    $server = api_start_server($root . '/tests/brevo-api-server.php', [$apiPort, $srvPem, $srvKey, $apiDir], $apiDir);
    $GLOBALS['test_servers'][] = $server;

    // ------------------------------------------------------------------
    echo "1. HTTPS/TLS reachability is tested without any credential\n";
    // ------------------------------------------------------------------
    $noKey = sh_brevo_api_test_connection(['timeout' => 8]);
    api_same('missing', $noKey['credential']['state'], 'An empty key was not reported as missing.');
    api_assert(($noKey['checks']['tls']['status'] ?? '') === 'passed', 'Test A should pass against the local HTTPS stub.');
    api_assert(($noKey['checks']['tls']['http_status'] ?? 0) === 401, 'Test A must use an unauthenticated request and expect 401.');
    api_assert(empty($noKey['checks']['account']['http_status']), 'Test B must not run without a key.');
    api_same('incomplete', $noKey['verdict'], 'A missing key must leave the verdict incomplete.');
    $tlsRequests = api_requests($apiDir);
    api_assert(count($tlsRequests) >= 1, 'Test A did not reach the stub.');
    api_assert(($tlsRequests[0]['key_present'] ?? true) === false, 'Test A sent an api-key header; it must not.');
    api_assert(!empty($noKey['certificate']) && ($noKey['certificate']['hostname_valid'] ?? null) === true, 'The TLS certificate was not validated by hostname.');

    // ------------------------------------------------------------------
    echo "2. the reported 403 on /v3/account is informational, not a failure\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';
    $denied = sh_brevo_api_test_connection(['timeout' => 8]);
    api_same('permission_denied', $denied['checks']['account']['status'], 'The 403 was not reported as permission_denied.');
    api_same(403, $denied['checks']['account']['http_status'], 'The 403 status was not recorded.');
    api_same('permission_denied', $denied['checks']['account']['provider']['code'], 'The provider error code was not captured.');
    api_assert(str_contains((string)$denied['checks']['account']['detail'], 'account-information access was denied'), 'The 403 explanation does not say account access was denied.');
    api_assert(str_contains((string)$denied['checks']['account']['detail'], 'only a real Send Test Email'), 'The 403 explanation must point at a real send.');
    api_assert(!empty($denied['ok']), 'A 403 on the account resource alone must not fail the diagnostics.');
    api_same('account_denied', $denied['verdict'], 'The verdict should record an account permission denial.');
    api_assert(str_contains((string)$denied['message'], 'does not by itself block transactional email'), 'The summary did not explain the account 403.');
    api_assert(empty($denied['transactional_verified']), 'The diagnostics must not claim transactional email is verified.');
    api_assert(!str_contains(json_encode($denied), 'account-denied-key'), 'The credential value leaked into the diagnostic result.');

    // ------------------------------------------------------------------
    echo "3. the incorrect historical-email probe is gone and diagnostics send nothing\n";
    // ------------------------------------------------------------------
    $paths = array_map(static fn(array $r): string => (string)($r['path'] ?? ''), api_requests($apiDir));
    foreach ($paths as $path) {
        api_assert(!str_starts_with($path, '/v3/smtp/emails'), 'The diagnostics called the historical list endpoint: ' . $path);
    }
    api_same([], api_posts($apiDir), 'The non-delivery diagnostics sent a POST; they must never send mail.');
    $tx = $denied['checks']['transactional'] ?? null;
    api_assert(is_array($tx), 'The transactional check row is missing from the diagnostics.');
    api_same('not_tested', $tx['status'], 'The transactional check must be reported as not tested.');
    api_same('POST /v3/smtp/email', $tx['endpoint'], 'The transactional row does not name the documented endpoint.');
    api_assert(str_contains((string)$tx['detail'], 'Not run'), 'The transactional row does not say it was not run.');
    api_assert(str_contains((string)$tx['detail'], 'Send Test Email'), 'The transactional row does not name the action that runs it.');

    // ------------------------------------------------------------------
    echo "4. an unknown API key is an authentication failure, not a permission result\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'definitely-not-a-real-key';
    $bad = sh_brevo_api_test_connection(['timeout' => 8]);
    api_assert(empty($bad['ok']), 'An unknown API key was accepted.');
    api_same('invalid_credentials', $bad['verdict'], 'An unknown key was not classified as invalid credentials.');
    api_same('invalid_key', $bad['checks']['account']['status'], 'The 401 was not reported as an invalid key.');
    api_same(401, $bad['checks']['account']['http_status'], 'The 401 status was not recorded.');
    api_assert(str_contains((string)$bad['error'], '401'), 'The reported error does not include HTTP 401.');
    api_assert((string)$bad['step'] !== 'api_auth', 'The old conflating api_auth stage is still being reported.');

    // ------------------------------------------------------------------
    echo "5. an SMTP key is refused in the API field and never transmitted\n";
    // ------------------------------------------------------------------
    $before = api_requests($apiDir);
    $GLOBALS['test_settings']['brevo_api_key'] = 'xsmtpsib-0123456789abcdef-SENTINEL-SMTP-KEY';
    $smtpKey = sh_brevo_api_test_connection(['timeout' => 8]);
    api_same('smtp_key', $smtpKey['credential']['state'], 'An SMTP key in the API field was not recognised.');
    api_same('incomplete', $smtpKey['verdict'], 'An SMTP key must not produce a reachable verdict.');
    $newRecords = array_slice(api_requests($apiDir), count($before));
    api_assert($newRecords !== [], 'The unauthenticated reachability check did not run.');
    foreach ($newRecords as $record) {
        api_assert(($record['key_present'] ?? true) === false, 'An SMTP key was transmitted to the API endpoint.');
    }
    $smtpKeySend = sh_brevo_api_send('inbox@example.test', 'refused', 'refused', null, sh_mail_transport());
    api_assert(empty($smtpKeySend['ok']) && !empty($smtpKeySend['skipped']), 'An SMTP key was allowed to attempt an API send.');
    api_assert(!str_contains(json_encode([$smtpKey, $smtpKeySend]), 'SENTINEL-SMTP-KEY'), 'The SMTP key leaked into a result.');

    // ------------------------------------------------------------------
    echo "6. an API key in the SMTP password is refused, not used\n";
    // ------------------------------------------------------------------
    $smtpWithApiKey = [
        'driver' => 'smtp', 'transport' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587,
        'encryption' => 'tls', 'username' => 'user@example.test',
        'password' => 'xkeysib-0123456789abcdef-SENTINEL-API-KEY', 'timeout' => 5,
    ];
    $mismatch = sh_mail_credential_mismatch($smtpWithApiKey);
    api_assert(is_array($mismatch), 'An API key in the SMTP password was not detected.');
    api_same('api_key_in_smtp_password', $mismatch['code'], 'The mismatch has the wrong code.');
    $smtpTest = sh_mail_test_connection($smtpWithApiKey);
    api_same('credentials', $smtpTest['step'], 'The SMTP test did not stop at the credential stage.');
    $smtpSend = sh_mail_send('inbox@example.test', 'refused', 'refused', null, ['transport' => $smtpWithApiKey]);
    api_assert(empty($smtpSend['ok']) && !empty($smtpSend['skipped']), 'A cross-transport credential was allowed to attempt SMTP delivery.');
    api_assert(!str_contains(json_encode([$smtpTest, $smtpSend]), 'SENTINEL-API-KEY'), 'The API key leaked into an SMTP result.');

    // ------------------------------------------------------------------
    echo "7. Test C: a real POST /v3/smtp/email is accepted with a message id\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';
    $send = sh_mail_send_test('inbox@example.test', sh_mail_transport(), ['admin_id' => 1]);
    api_assert(!empty($send['ok']), 'The API test message failed for a key that may send: ' . ($send['error'] ?? ''));
    api_same('brevo_api', $send['transport'], 'The test message used the wrong transport.');
    api_same('accepted', $send['category'], 'An accepted message was not categorised as accepted.');
    api_same('<test-message@brevo>', $send['message_id'] ?? '', 'The message id was not recorded.');
    api_same('POST', $send['request']['method'] ?? '', 'The send did not record its HTTP method.');
    api_same('/smtp/email', $send['request']['path'] ?? '', 'The send did not use the transactional email endpoint.');
    api_same(201, $send['http_status'] ?? 0, 'The send did not record HTTP 201.');
    api_same('unconfirmed', $send['delivery'] ?? '', 'Acceptance must leave delivery unconfirmed.');
    api_assert(str_contains((string)$send['response'], 'not inbox delivery'), 'Acceptance was presented as inbox delivery.');
    $posts = api_posts($apiDir);
    api_assert(count($posts) === 1, 'Exactly one real POST should have been made, got ' . count($posts) . '.');
    $post = $posts[0];
    api_same('inbox@example.test', $post['recipient'] ?? '', 'The POST had the wrong recipient.');
    api_same('noreply@example.test', $post['sender'] ?? '', 'The POST did not use the configured sender.');
    api_same('ShopHaat API Test', $post['sender_name'] ?? '', 'The POST did not use the configured sender name.');
    api_assert(str_starts_with((string)($post['subject'] ?? ''), 'ShopHaat Brevo API test'), 'The test subject does not identify the Brevo API test: ' . (string)($post['subject'] ?? ''));
    api_assert(($post['has_text'] ?? false) === true, 'The POST had no textContent.');
    api_assert(str_contains((string)($post['accept'] ?? ''), 'application/json'), 'The POST did not send an Accept: application/json header.');
    api_assert(str_contains((string)($post['content_type'] ?? ''), 'application/json'), 'The POST did not send a JSON content type.');
    api_assert(($post['key_present'] ?? false) === true, 'The POST did not carry the api-key header.');
    api_assert(($post['authorized'] ?? false) === true, 'The POST did not carry a key the stub recognises.');
    api_assert(!str_contains(json_encode($send), 'account-denied-key'), 'The API key leaked into the send result.');

    // ------------------------------------------------------------------
    echo "8. an invalid sender is a sanitized 400 that names the field\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'invalid-sender-key';
    $invalidSender = sh_mail_send_test('inbox@example.test', sh_mail_transport());
    api_assert(empty($invalidSender['ok']), 'A 400 invalid-sender response was reported as success.');
    api_same(400, $invalidSender['http_status'] ?? 0, 'The 400 status was not recorded.');
    api_same('rejected', $invalidSender['category'], 'A 400 was not categorised as rejected.');
    api_same('invalid_parameter', $invalidSender['provider']['code'] ?? '', 'The provider code was not captured.');
    api_assert(!empty($invalidSender['fields']), 'The 400 did not identify the offending field.');
    api_assert(str_contains(strtolower(implode(',', (array)$invalidSender['fields'])), 'sender'), 'The 400 field list does not name the sender.');
    api_assert(empty($invalidSender['message_id']), 'A failed send exposed a message id.');
    api_assert(str_contains((string)$invalidSender['error'], 'Field Brevo objected to'), 'The error does not tell the operator which field to fix.');

    $GLOBALS['test_settings']['brevo_api_key'] = 'missing-field-key';
    $missingField = sh_mail_send_test('inbox@example.test', sh_mail_transport());
    api_same(400, $missingField['http_status'] ?? 0, 'The missing_parameter 400 was not recorded.');
    api_assert(str_contains(implode(',', (array)($missingField['fields'] ?? [])), 'sender.email'), 'The missing field name was not extracted from the provider message.');

    // ------------------------------------------------------------------
    echo "9. a denied send is a failure, never a success, and is not retried\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'send-denied-key';
    $beforeDenied = count(api_posts($apiDir));
    $deniedSend = sh_mail_send_test('inbox@example.test', sh_mail_transport());
    api_assert(empty($deniedSend['ok']), 'A 403 send was reported as success.');
    api_same('permission', $deniedSend['category'], 'A 403 send was not categorised as a permission failure.');
    api_assert(str_contains((string)$deniedSend['error'], '403'), 'The denied send does not include HTTP 403.');
    api_assert(!empty($deniedSend['action']), 'The denied send has no recommended action.');
    api_same($beforeDenied + 1, count(api_posts($apiDir)), 'A denied request was retried automatically.');
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';

    // ------------------------------------------------------------------
    echo "10. an invalid key on the send path is an authentication failure\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'definitely-not-a-real-key';
    $unauthorizedSend = sh_mail_send_test('inbox@example.test', sh_mail_transport());
    api_assert(empty($unauthorizedSend['ok']), 'A 401 send was reported as success.');
    api_same(401, $unauthorizedSend['http_status'] ?? 0, 'The 401 was not recorded on the send path.');
    api_same('auth', $unauthorizedSend['category'], 'A 401 send was not categorised as an authentication failure.');
    api_same('unauthorized', $unauthorizedSend['provider']['code'] ?? '', 'The provider 401 code was not captured.');
    api_assert(empty($unauthorizedSend['message_id']), 'A rejected send exposed a message id.');
    api_assert(str_contains((string)$unauthorizedSend['error'], '401'), 'The 401 error does not include the HTTP status.');
    api_same('unconfirmed', $unauthorizedSend['delivery'] ?? '', 'A failed send changed the delivery state.');
    api_assert(str_contains(strtolower((string)$unauthorizedSend['error']), 'did not recognise'), 'The 401 error does not explain that the key was not recognised.');
    api_assert(!str_contains(json_encode($unauthorizedSend), 'definitely-not-a-real-key'), 'The API key leaked into the 401 result.');
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';

    // ------------------------------------------------------------------
    echo "11. status codes map to distinct, non-overlapping meanings\n";
    // ------------------------------------------------------------------
    $provider = ['code' => 'permission_denied', 'message' => 'api key is not authorized for resource "account"', 'detail' => '', 'fields' => []];
    $m403 = sh_brevo_api_status_meaning(403, $provider, 'GET /v3/account');
    api_assert($m403['kind'] === 'permission_denied', '403 was not classified as a permission result.');
    api_assert(!str_contains(strtolower($m403['label']), 'invalid key'), '403 must not be described as an invalid key.');
    api_same('invalid_credentials', sh_brevo_api_status_meaning(401, $provider, 'GET /v3/account')['kind'], '401 was not classified as invalid credentials.');
    api_same('payment_required', sh_brevo_api_status_meaning(402, ['code' => 'not_enough_credits', 'message' => 'Not enough credits', 'detail' => '', 'fields' => []], 'POST /v3/smtp/email')['kind'], '402 was not classified as a billing problem.');
    $m429 = sh_brevo_api_status_meaning(429, $provider, 'GET /v3/account', 7);
    api_same('rate_limited', $m429['kind'], '429 was not classified as a rate limit.');
    api_same(7, $m429['retry_after'], 'The Retry-After value was not carried into the meaning.');
    api_same('invalid_request', sh_brevo_api_status_meaning(400, $provider, 'POST /v3/smtp/email')['kind'], '400 was not classified as a validation problem.');
    api_same('network', sh_brevo_api_status_meaning(0, $provider)['kind'], 'A transport failure was not classified as network.');
    api_same('provider_error', sh_brevo_api_status_meaning(503, $provider)['kind'], '503 was not classified as a provider error.');
    foreach ([200, 201] as $code) {
        api_same('ok', sh_brevo_api_status_meaning($code, $provider, 'POST /v3/smtp/email')['kind'], $code . ' was not treated as success.');
    }

    // ------------------------------------------------------------------
    echo "12. a 402 from the account endpoint is billing, not a broken key\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'payment-required-key';
    $billing = sh_brevo_api_test_connection(['timeout' => 8]);
    api_assert(empty($billing['ok']), 'A 402 account response was treated as success.');
    api_same('payment_required', $billing['checks']['account']['status'], 'The 402 was not reported as payment_required.');
    api_same('payment_required', $billing['verdict'], 'The 402 was not carried into the verdict.');

    // ------------------------------------------------------------------
    echo "13. a rate limit carries retry guidance and is not retried\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'rate-limited-key';
    $beforePosts = count(api_posts($apiDir));
    $limited = sh_brevo_api_test_connection(['timeout' => 8]);
    api_same('rate_limited', $limited['checks']['account']['status'], 'A 429 was not reported as a rate limit.');
    api_same(7, $limited['checks']['account']['retry_after'], 'The Retry-After value was not surfaced.');
    api_same('rate_limited', $limited['verdict'], 'The 429 was not carried into the verdict.');
    api_same($beforePosts, count(api_posts($apiDir)), 'A 429 caused a send attempt.');
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';

    // ------------------------------------------------------------------
    echo "14. a readable account is reachable, but email still unproven\n";
    // ------------------------------------------------------------------
    $GLOBALS['test_settings']['brevo_api_key'] = 'test-api-key';
    $beforeReadable = count(api_posts($apiDir));
    $readable = sh_brevo_api_test_connection(['timeout' => 8]);
    api_assert(!empty($readable['ok']), 'A fully readable account was not reported as reachable.');
    api_same('reachable', $readable['verdict'], 'A readable account did not produce the reachable verdict.');
    api_same('complete', $readable['step'], 'The diagnostics did not finish for a readable account.');
    api_same('passed', $readable['checks']['account']['status'], 'Test B did not pass for a readable account.');
    api_same('ShopHaat TLS Test', $readable['company'] ?? '', 'The account company name was not captured.');
    api_same('not_tested', $readable['checks']['transactional']['status'], 'Test C must stay not_tested until a real send.');
    api_same($beforeReadable, count(api_posts($apiDir)), 'A readable account caused an automatic test email.');
    api_assert(str_contains((string)$readable['message'], 'has not been tested'), 'The summary did not say transactional email is still unproven.');
    $GLOBALS['test_settings']['brevo_api_key'] = 'account-denied-key';

    // ------------------------------------------------------------------
    echo "15. an unreachable endpoint is a network failure, not an auth failure\n";
    // ------------------------------------------------------------------
    api_stop($server);
    usleep(300000);
    $down = sh_brevo_api_test_connection(['timeout' => 5]);
    api_assert(empty($down['ok']), 'A dead endpoint was reported as success.');
    api_same('unreachable', $down['verdict'], 'A dead endpoint was not reported as unreachable.');
    api_assert(in_array((string)$down['step'], ['connect', 'tls', 'tls_cert', 'tls_hostname'], true), 'A transport failure was reported at the wrong stage: ' . (string)$down['step']);
    api_assert(!str_contains(strtolower((string)$down['error']), 'invalid key'), 'A transport failure was described as an invalid key.');

    // ------------------------------------------------------------------
    echo "16. one transport failing does not corrupt the other\n";
    // ------------------------------------------------------------------
    api_same('brevo_api', sh_mail_selected_transport(), 'The selected API transport changed after a failure.');
    $GLOBALS['test_settings']['mail_transport'] = 'smtp';
    $GLOBALS['test_settings']['smtp_enabled'] = '1';
    $GLOBALS['test_settings']['smtp_host'] = 'smtp-relay.brevo.com';
    $GLOBALS['test_settings']['smtp_username'] = 'user@example.test';
    $GLOBALS['test_settings']['smtp_password'] = 'xsmtpsib-smtp-only-key';
    $tr = sh_mail_transport();
    api_same('smtp', $tr['driver'], 'Switching to SMTP after an API failure did not take effect.');
    api_same('user@example.test', $tr['username'], 'The SMTP username changed.');
    api_assert($tr['password'] === 'xsmtpsib-smtp-only-key', 'The SMTP password changed.');
    $apiCredAfter = sh_brevo_api_key_info();
    api_assert(in_array($apiCredAfter['state'], ['ok', 'unverified'], true), 'The API credential was altered or lost by an SMTP change.');
    api_same('account-denied-key', $apiCredAfter['key'], 'The stored API key changed when the SMTP transport was selected.');

    echo "OK: all Brevo API diagnostics passed\n";
    $exit = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    api_stop_all();
    foreach (glob($tmp . '/*') ?: [] as $file) {
        if (is_file($file)) { @unlink($file); }
    }
    foreach (glob($tmp . '/*/*') ?: [] as $file) {
        if (is_file($file)) { @unlink($file); }
    }
    foreach (glob($tmp . '/*', GLOB_ONLYDIR) ?: [] as $dir) { @rmdir($dir); }
    @rmdir($tmp);
}
exit($exit);
