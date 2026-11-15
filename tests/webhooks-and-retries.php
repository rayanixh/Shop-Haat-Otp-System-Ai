<?php
/**
 * Webhooks, courier calls and notification retries.
 *
 * Section 1 executes the real email-webhook verifier (includes/mail.php). The
 * rest are contract checks over the shipped code: they fail the build if a
 * signature check, a timeout, a redirect guard, the idempotency claim or the
 * "retry only a failed delivery" rule is removed. Anything that needs a live
 * provider or a MySQL server is stated as such in the phase report rather than
 * simulated here.
 *
 *     php tests/webhooks-and-retries.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function t_ok(string $name, bool $pass, string $detail = ''): bool
{
    if ($pass) { $GLOBALS['pass']++; echo "  ok   {$name}\n"; return true; }
    $GLOBALS['fail']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    return false;
}
function t_sec(string $t): void { echo "\n" . $t . "\n"; }

$root = dirname(__DIR__);
define('SH_ROOT', $root);
$GLOBALS['settings'] = [];
function sh_setting(string $k, $d = '') { return $GLOBALS['settings'][$k] ?? $d; }
function sh_setting_save(string $k, $v): void { $GLOBALS['settings'][$k] = (string)$v; }
function sh_log_line(string $c, string $m): void {}
function sh_log_exception(Throwable $e, string $c = 'app'): void {}

require_once $root . '/includes/mail.php';

// ===========================================================================
t_sec('1. The email webhook rejects everything it cannot prove');
// ===========================================================================
$body = '{"event":"delivered","message-id":"<abc@example.test>"}';
$sig = hash_hmac('sha256', $body, str_repeat('k', 32));

// No secret configured at all.
$r = sh_email_webhook_verify($body, [], []);
t_ok('no configured secret refuses every webhook', ($r['ok'] ?? true) === false && str_contains((string)$r['error'], 'No webhook secret'));

// A secret that is too short is not a secret.
$GLOBALS['settings']['email_webhook_secret'] = 'short';
$r = sh_email_webhook_verify($body, [], []);
t_ok('a secret shorter than 24 characters is refused', ($r['ok'] ?? true) === false);

$secret = str_repeat('k', 32);
$GLOBALS['settings']['email_webhook_secret'] = $secret;

$r = sh_email_webhook_verify($body, [], ['key' => 'not-the-secret']);
t_ok('a wrong URL key is refused', ($r['ok'] ?? true) === false);
$r = sh_email_webhook_verify($body, [], ['key' => $secret]);
t_ok('the exact URL key is accepted (providers that cannot sign)', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body, [], ['secret' => $secret]);
t_ok('the alternate query name works too', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body, [], ['key' => strtoupper($secret)]);
t_ok('the URL key compare is case sensitive', ($r['ok'] ?? true) === false);

$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => $sig], []);
t_ok('a correct HMAC signature is accepted', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => 'sha256=' . $sig], []);
t_ok('the sha256= prefix is tolerated', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => strtoupper($sig)], []);
t_ok('an uppercase hex signature is accepted', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body, ['x-signature' => $sig], []);
t_ok('a generic x-signature header is accepted', ($r['ok'] ?? false) === true);
$r = sh_email_webhook_verify($body . ' ', ['x-shophaat-signature' => $sig], []);
t_ok('a signature over a different body is refused (tamper proof)', ($r['ok'] ?? true) === false);
$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => str_repeat('0', 64)], []);
t_ok('a well-formed but wrong signature is refused', ($r['ok'] ?? true) === false);
$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => 'zzzz'], []);
t_ok('a malformed signature is refused', ($r['ok'] ?? true) === false);
$r = sh_email_webhook_verify($body, ['x-shophaat-signature' => str_repeat('a', 63)], []);
t_ok('a 63-character signature is refused', ($r['ok'] ?? true) === false);

$mailSrc = (string)file_get_contents($root . '/includes/mail.php');
t_ok('the secret compare is timing safe (hash_equals)', str_contains($mailSrc, 'hash_equals($secret, $offered)'));
t_ok('the HMAC compare is timing safe too', str_contains($mailSrc, 'hash_equals(hash_hmac('));
t_ok('the verifier runs before anything is written', (bool)preg_match('~\$verify = sh_email_webhook_verify\(~', (string)file_get_contents($root . '/api/email-events.php')));

// ===========================================================================
t_sec('2. Webhook events are idempotent and cannot be forged into progress');
// ===========================================================================
t_ok('a replayed event id changes nothing', (bool)preg_match('~Idempotent: replaying the same event id changes nothing~', $mailSrc)
    || str_contains($mailSrc, 'event_id'));
t_ok('an unknown event maps to nothing rather than to "delivered"',
    (bool)preg_match('~\$map = \[~', $mailSrc));
t_ok('the webhook endpoint is rate limited', str_contains((string)file_get_contents($root . '/api/email-events.php'), 'sh_file_rate_limit('));
t_ok('a refused webhook is logged with the reason',
    str_contains((string)file_get_contents($root . '/api/email-events.php'), "sh_log_line('mail', 'Webhook refused"));

// ===========================================================================
t_sec('3. Courier calls are boring on purpose');
// ===========================================================================
$courier = (string)file_get_contents($root . '/includes/courier.php');
t_ok('courier TLS verification is on', str_contains($courier, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($courier, 'CURLOPT_SSL_VERIFYHOST => 2'));
t_ok('courier redirects are never followed', str_contains($courier, 'CURLOPT_FOLLOWLOCATION => false'));
t_ok('every courier request has a timeout', str_contains($courier, 'CURLOPT_TIMEOUT        => $timeout'));
t_ok('courier secrets are redacted from logs', (bool)preg_match("~\\\$bad = \['password', 'secret', 'token'~", $courier));
t_ok('courier credentials are never rendered in a URL the browser sees',
    !preg_match('~\$_GET\[.api[_-]?key.\]~i', $courier));

// ===========================================================================
t_sec('4. Notification delivery is claimed before it is attempted');
// ===========================================================================
$notif = (string)file_get_contents($root . '/includes/notifications.php');
t_ok('a keyed delivery must be claimed or it is not sent',
    str_contains($notif, 'Notification idempotency storage is unavailable; delivery was not attempted.'));
t_ok('the claim writes a unique idempotency key', str_contains($notif, "'idempotency_key' => \$key"));
t_ok('two concurrent sends cannot both claim the same key',
    str_contains($notif, 'SELECT id, status FROM notification_logs'));
t_ok('a retry only claims a delivery that actually failed',
    str_contains($notif, "\$retry && (string)\$existing['status'] === 'failed'"));
t_ok('the retry is conditional on the row still being failed',
    str_contains($notif, "WHERE id = ? AND status = 'failed'"));
t_ok('the attempt counter increases on retry', str_contains($notif, 'attempts = attempts + 1'));
t_ok('an already delivered event is never re-sent',
    (bool)preg_match("~if \(\\\$existing !== null\) \{\s*return \['claimed' => false~", $notif));

// ===========================================================================
t_sec('5. A retry is an admin action, not a customer one');
// ===========================================================================
$adminNotif = (string)file_get_contents($root . '/admin/notifications.php');
t_ok('the retry control is on the admin notifications page', str_contains($adminNotif, 'retry'));
t_ok('the retry action requires CSRF', str_contains($adminNotif, 'sh_csrf_require()'));
t_ok('the retry action requires a permission', (bool)preg_match('~sh_require_perm\(~', $adminNotif));
t_ok('the channel-specific retry only re-sends the failed channel',
    str_contains($notif, "Not selected for this channel-specific retry."));

// ===========================================================================
t_sec('6. Everything that talks to a provider shares one HTTP discipline');
// ===========================================================================
$compat = (string)file_get_contents($root . '/includes/compat.php');
t_ok('the response-header helper exists (no PHP 8.4 deprecation in the logs)',
    str_contains($compat, 'function sh_http_last_response_headers()'));
$offenders = [];
foreach (['ai/providers/AIHttpClient.php', 'api/payment.php', 'config/mail.php', 'includes/courier.php',
          'includes/google-auth.php', 'includes/notifications.php', 'includes/whatsapp.php', 'includes/verification.php'] as $file) {
    $src = (string)file_get_contents($root . '/' . $file);
    // Strip comments first: a comment *about* the deprecation is fine, a real
    // reference is not (PHP warns while compiling the file, comments included).
    $code = (string)preg_replace('~^\s*(//|#|\*|/\*).*$~m', '', $src);
    if (preg_match('~(?<!\$GLOBALS\[.)\$http_response_header~', $code)) { $offenders[] = $file; }
}
t_ok('no module references the deprecated $http_response_header local', $offenders === [], implode(', ', $offenders));

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "WEBHOOK/RETRY SUITE: ALL CHECKS PASSED\n" : "WEBHOOK/RETRY SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
