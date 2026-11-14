<?php
/**
 * SMS transport contract tests: real driver code, stubbed outbound HTTP.
 *
 * These tests execute the shipped provider helpers from includes/phone-otp.php
 * without requiring a live carrier. They cover the current TextBee account
 * endpoint, explicit provider acceptance, secret-safe diagnostics, cURL/SSL
 * error translation, HTTPS-only generic endpoints and custom driver cfg passing.
 * No real SMS is sent and no provider credential is required.
 *
 *     php tests/support-sms-transport.php
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';
sh_test_schema();

// Minimal functions normally supplied by config/config.php and functions.php.
function sh_phone_normalize(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : '';
}
function sh_phone_mask(string $phone): string { return '017****5678'; }

// Load the real driver source without the production bootstrap (which would
// redeclare the test harness helpers). No line in that bootstrap is needed by
// the pure transport helpers below.
$driverSource = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');
$driverSource = (string)preg_replace('/^require_once SH_ROOT .*$/m', '', $driverSource);
// The harness already supplies sh_security_log(); remove the production DB
// implementation from the copied source so the pure transport can be tested.
$logStart = strpos($driverSource, 'function sh_security_log(');
$logEnd = $logStart !== false ? strpos($driverSource, 'function sh_otp_log(', $logStart) : false;
if ($logStart !== false && $logEnd !== false) {
    $driverSource = substr($driverSource, 0, $logStart) . substr($driverSource, $logEnd);
}
$driverFile = tempnam(sys_get_temp_dir(), 'sh-sms-driver-');
if ($driverFile === false) { fwrite(STDERR, "Could not create a temporary driver file.\n"); exit(1); }
file_put_contents($driverFile, $driverSource);
require $driverFile;
@unlink($driverFile);

$phoneOtpSrc = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');

[$passed, $failed] = sh_test_run('SMS transport contract and diagnostics (no live provider)', static function ($ok, $eq) use ($phoneOtpSrc) {
    /* --- 1. Current TextBee contract -------------------------------------- */
    $ok(str_contains($phoneOtpSrc, 'https://api.textbee.dev/api/v1/gateway/send-sms')
        && !str_contains($phoneOtpSrc, '/gateway/devices/')
        && str_contains($phoneOtpSrc, "x-api-key")
        && str_contains($phoneOtpSrc, "'deviceId'"),
        'TextBee uses the account endpoint with x-api-key and optional deviceId in JSON');
    $ok(str_contains($phoneOtpSrc, 'CURLOPT_SSL_VERIFYHOST')
        && str_contains($phoneOtpSrc, 'CURLOPT_SSL_VERIFYPEER')
        && str_contains($phoneOtpSrc, 'CURLOPT_TIMEOUT')
        && str_contains($phoneOtpSrc, 'function_exists(\'curl_init\')'),
        'cURL availability, TLS verification and timeouts are explicit');

    $validResponse = '{"data":{"success":true,"smsBatchId":"batch-123","recipientCount":1}}';
    $ok(!empty(sh_sms_textbee_response_state($validResponse)['ok']), 'TextBee data.success=true plus smsBatchId is accepted');
    foreach ([
        'not json',
        '{}',
        '{"data":{"success":false,"smsBatchId":"batch-123"}}',
        '{"data":{"success":true}}',
    ] as $invalid) {
        $ok(empty(sh_sms_textbee_response_state($invalid)['ok']), 'TextBee response without explicit acceptance is refused: ' . substr($invalid, 0, 24));
    }

    /* --- 2. Generic provider acceptance ----------------------------------- */
    $ok(!empty(sh_sms_generic_response_state('{}', '', '')['ok']), 'HTTP 2xx alone is accepted when no success marker is configured');
    $ok(!empty(sh_sms_generic_response_state('{"status":"accepted"}', 'status', 'accepted')['ok']), 'a matching success marker is accepted');
    $ok(empty(sh_sms_generic_response_state('{"status":"failed"}', 'status', 'accepted')['ok']), 'a mismatched success marker is refused');
    $ok(!empty(sh_sms_generic_response_state('{"status":"queued"}', 'status', '')['ok']), 'a configured field may be accepted by presence');
    $ok(empty(sh_sms_generic_response_state('{}', 'status', '')['ok']), 'a missing configured success field is refused');

    /* --- 3. Secret-free diagnostics --------------------------------------- */
    $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcd';
    $scrubbed = sh_sms_scrub_diagnostic('x-api-key: ' . $secret . ' to +8801712345678 status=ok');
    $ok(!str_contains($scrubbed, $secret) && !str_contains($scrubbed, '+8801712345678')
        && (str_contains($scrubbed, '[redacted]') || str_contains($scrubbed, '[redacted-token]'))
        && str_contains($scrubbed, '[phone]'),
        'provider diagnostics redact keys, long tokens and phone numbers');
    $ok(str_contains(sh_sms_curl_error_message(7, ''), 'outbound HTTPS')
        && str_contains(sh_sms_curl_error_message(28, ''), 'timed out')
        && str_contains(sh_sms_curl_error_message(60, ''), 'TLS'),
        'cPanel outbound HTTPS, timeout and TLS failures get understandable explanations');
    $ok(str_contains(sh_sms_http_error('textbee', 401), 'API key')
        && str_contains(sh_sms_http_error('textbee', 429), 'rate limit')
        && str_contains(sh_sms_http_error('textbee', 400), 'recipient')
        && str_contains(sh_sms_http_error('textbee', 500), 'server error'),
        'HTTP 400/401/429/5xx produce distinct admin-safe failures');

    /* --- 4. Drivers fail closed without live HTTP ------------------------- */
    $missingKey = sh_otp_provider_textbee('+8801712345678', 'test', [
        'otp_textbee_api_key' => '', 'otp_textbee_device_id' => '',
    ]);
    $eq('credentials_missing', $missingKey['code'], 'TextBee missing API key is reported before any cURL call');
    $ok(str_contains((string)($missingKey['diagnostic']['endpoint'] ?? ''), '/gateway/send-sms')
        && !str_contains((string)($missingKey['diagnostic']['endpoint'] ?? ''), '/devices/'),
        'TextBee diagnostic records the current account endpoint');

    $insecure = sh_otp_provider_generic('+8801712345678', 'test', [
        'otp_api_url' => 'http://sms.example.test/send', 'otp_api_method' => 'post_json',
    ]);
    $eq('insecure_endpoint', $insecure['code'], 'a plain-HTTP generic endpoint is refused before any cURL call');
    $ok(str_contains((string)$insecure['error'], 'HTTPS'), 'the HTTPS requirement is shown to the admin');

    $offline = sh_sms_deliver('offline', '+8801712345678', 'test');
    $eq('offline_test_only', $offline['code'], 'offline driver never pretends a real SMS was accepted');
    $firebase = sh_sms_deliver('firebase', '+8801712345678', 'test');
    $eq('unsupported_provider', $firebase['code'], 'Firebase is reported as unable to carry this text');
    $unknown = sh_sms_deliver('not_a_provider', '+8801712345678', 'test');
    $eq('provider_not_configured', $unknown['code'], 'unknown providers fail closed with a diagnostic');

    /* --- 5. Custom driver registry and per-channel credentials ------------ */
    $seen = [];
    sh_otp_register_driver('unit_carrier', static function (string $phone, string $message, array $cfg) use (&$seen): array {
        $seen = ['phone' => $phone, 'message' => $message, 'cfg' => $cfg];
        return ['ok' => true, 'error' => null];
    });
    $custom = sh_sms_deliver('unit_carrier', '+8801712345678', 'hello', ['otp_api_key' => 'DEDICATED-ONLY']);
    $ok(!empty($custom['ok']) && ($seen['cfg']['otp_api_key'] ?? '') === 'DEDICATED-ONLY'
        && !empty($custom['diagnostic']['id']),
        'registered custom drivers persist and receive the dedicated SMS configuration');
    $ok(str_contains($phoneOtpSrc, 'function &sh_otp_driver_registry(): array')
        && str_contains($phoneOtpSrc, '$drivers[$provider]($phone, $message, $cfg)'),
        'registry returns by reference and custom driver invocation includes cfg');
    $ok(str_contains($phoneOtpSrc, 'sh_sms_result(') && str_contains($phoneOtpSrc, 'sh_sms_diagnostic('),
        'every transport outcome has a uniform scrubbed diagnostic');
});

exit($failed > 0 ? 1 : 0);
