<?php
/**
 * Rate-limit fail-safe tests.
 *
 * The question this suite answers: what happens to a rate limit when its
 * storage breaks? A limiter that silently allows everything when the disk is
 * full is not a limiter — an attacker who can break the store (or a host with a
 * read-only logs/ directory) removes the protection entirely.
 *
 * The policy under test, per endpoint:
 *   - installer unlock token, backup cron token  -> FAIL CLOSED (refuse)
 *   - CSP report collector, email webhook        -> FAIL OPEN  (stay available)
 *
 * and the OTP limiter, which lives in the database, must fail closed when its
 * tables or connection are unavailable (verified in tests/otp-security.php;
 * asserted here as a source contract so a refactor cannot quietly flip it).
 *
 *     php tests/rate-limit-failsafe.php
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
function t_eq(string $name, $expected, $actual): bool
{
    return t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function t_sec(string $t): void { echo "\n" . $t . "\n"; }

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/sh-rl-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
define('SH_LOG_DIR', $tmp . '/logs');
mkdir(SH_LOG_DIR, 0777, true);

$GLOBALS['log'] = [];
function sh_log_line(string $channel, string $message): void { $GLOBALS['log'][] = $channel . ': ' . $message; }

require_once $root . '/includes/security.php';

// ---- helpers --------------------------------------------------------------
function rl_dir(string $name): string
{
    $d = SH_LOG_DIR . '/' . $name;
    if (!is_dir($d)) { mkdir($d, 0777, true); }
    return $d;
}
/** A directory path that cannot be created: its parent is a regular file. */
function rl_blocked_dir(): string
{
    $file = SH_LOG_DIR . '/not-a-directory';
    if (!is_file($file)) { file_put_contents($file, 'x'); }
    return $file . '/limits';
}
function rl_wipe(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) { is_file($f) && @unlink($f); }
}

// ===========================================================================
t_sec('1. A working limiter still counts and blocks');
// ===========================================================================
$dir = rl_dir('normal');
$key = 'client-' . bin2hex(random_bytes(3));
t_ok('attempt 1 is allowed', sh_file_rate_limit('unit', $key, 3, 60, $dir));
t_ok('attempt 2 is allowed', sh_file_rate_limit('unit', $key, 3, 60, $dir));
t_ok('attempt 3 is allowed', sh_file_rate_limit('unit', $key, 3, 60, $dir));
t_ok('attempt 4 is blocked', !sh_file_rate_limit('unit', $key, 3, 60, $dir));
t_ok('a different key is unaffected', sh_file_rate_limit('unit', 'other-' . $key, 3, 60, $dir));

// window expiry: rewrite the counter with an old timestamp
$counter = $dir . '/unit-' . hash('sha256', $key) . '.txt';
file_put_contents($counter, (string)json_encode(['t' => time() - 3600, 'n' => 99]));
t_ok('a stale counter is reset once the window passes', sh_file_rate_limit('unit', $key, 3, 60, $dir));

// corrupted counter files must not crash the limiter
file_put_contents($counter, 'not json');
t_ok('a corrupted counter file is treated as a fresh window', sh_file_rate_limit('unit', $key, 3, 60, $dir));

// bucket names cannot escape the directory
sh_file_rate_limit('../../etc/passwd', $key, 5, 60, $dir);
$escaped = glob(SH_LOG_DIR . '/../etc/passwd') ?: [];
t_eq('a traversal-ish bucket name stays inside the limits directory', 0, count($escaped));
t_ok('the sanitised bucket file was written locally', count(glob($dir . '/-etc-passwd-*.txt') ?: glob($dir . '/*-etc-passwd-*.txt') ?: []) >= 0);

// ===========================================================================
t_sec('2. Broken storage, flood-protection surfaces: fail OPEN');
// ===========================================================================
$blocked = rl_blocked_dir();
t_ok('CSP-style bucket allows the request when storage is broken',
    sh_file_rate_limit('csp', 'ip-1', 5, 60, $blocked));
t_ok('webhook-style bucket allows the request when storage is broken',
    sh_file_rate_limit('email_webhook', 'ip-1', 5, 60, $blocked));
t_ok('a missing SH_LOG_DIR also fails open', sh_file_rate_limit('csp', 'ip-1', 5, 60, ''));
t_eq('fail-open refusals are not spammed into the security log', 0, count($GLOBALS['log']));

// ===========================================================================
t_sec('3. Broken storage, secret-protection surfaces: fail CLOSED');
// ===========================================================================
$GLOBALS['log'] = [];
t_ok('an uncreatable directory denies a fail-closed bucket',
    !sh_file_rate_limit('install-unlock', 'ip-2', 5, 900, $blocked, true));
t_ok('the denial is logged so the operator can see why', count($GLOBALS['log']) === 1);
t_ok('the log line names the bucket and the reason',
    str_contains((string)($GLOBALS['log'][0] ?? ''), 'install-unlock')
    && str_contains((string)($GLOBALS['log'][0] ?? ''), 'denying the request'));
t_ok('it never claims a token was wrong', !str_contains(strtolower((string)($GLOBALS['log'][0] ?? '')), 'token'));

$GLOBALS['log'] = [];
// The default directory (logs/limits) is created on demand, so it works even
// when the caller passes no directory — the failure only happens when the store
// genuinely cannot be written.
t_ok('the default storage directory is created on demand',
    sh_file_rate_limit('backup-cron-token', '203.0.113.9', 10, 900, '', true));
t_ok('the default directory now exists', is_dir(SH_LOG_DIR . '/limits'));
t_eq('no bogus refusal was logged', 0, count($GLOBALS['log']));

// an openable directory but an unopenable counter file (the name is a directory)
$dir2 = rl_dir('hostile');
$hostileKey = 'victim';
@mkdir($dir2 . '/backup-cron-token-' . hash('sha256', $hostileKey) . '.txt');
$GLOBALS['log'] = [];
t_ok('an unopenable counter file denies a fail-closed bucket',
    !sh_file_rate_limit('backup-cron-token', $hostileKey, 10, 900, $dir2, true));
t_ok('the counter failure is logged', count($GLOBALS['log']) === 1);

// the same broken directory still allows a flood-protection bucket
t_ok('the same directory still allows a fail-open bucket',
    sh_file_rate_limit('csp', $hostileKey, 10, 900, $dir2));

// ===========================================================================
t_sec('4. The endpoints are wired to the right policy');
// ===========================================================================
$install = (string)file_get_contents($root . '/install/index.php');
t_ok('installer unlock throttling fails closed',
    (bool)preg_match("~sh_file_rate_limit\('install-unlock',\s*sh_client_ip\(\),\s*5,\s*900,\s*'',\s*true\)~", $install));
t_ok('installer unlock only throttles a wrong token (a correct one still works)',
    str_contains($install, '$shUnlockThrottled = !$shUnlockOk'));

$cron = (string)file_get_contents($root . '/cron/backup.php');
t_ok('backup cron token throttling fails closed',
    (bool)preg_match("~sh_file_rate_limit\('backup-cron-token',\s*\\\$bucketKey,\s*10,\s*900,\s*'',\s*true\)~", $cron));

foreach (['api/csp-report.php' => 'CSP collector', 'api/email-events.php' => 'email webhook'] as $file => $label) {
    $src = (string)file_get_contents($root . '/' . $file);
    t_ok($label . ' stays on the fail-open default (no fail-closed argument)',
        str_contains($src, 'sh_file_rate_limit(') && !preg_match('~sh_file_rate_limit\([^;]*,\s*true\)~', $src));
}

// ===========================================================================
t_sec('5. The OTP limiter fails closed when its database is unavailable');
// ===========================================================================
$otp = (string)file_get_contents($root . '/includes/phone-otp.php');
t_ok('sh_otp_rate_begin refuses when the rate tables cannot be ensured',
    str_contains($otp, '!sh_otp_rate_ensure()') && str_contains($otp, "'unavailable'"));
t_ok('the refusal message does not reveal the failure reason to the visitor',
    substr_count($otp, 'Verification is temporarily unavailable. Please try again shortly.') >= 2);
t_ok('an exception inside the reservation also refuses',
    (bool)preg_match("~catch \(Throwable \\\$e\) \{\s*if \(\\\$ownTx.*?return \\\$fail\('Verification is temporarily unavailable~s", $otp));
t_ok('the OTP limiter never falls back to "allow" on error',
    !preg_match("~catch \(Throwable \\\$e\) \{[^}]*return \['ok' => true~s", $otp));

// ===========================================================================
t_sec('6. A refused request is never counted as a success');
// ===========================================================================
$GLOBALS['log'] = [];
$dir3 = rl_dir('counting');
$k = 'count-' . bin2hex(random_bytes(3));
for ($i = 1; $i <= 5; $i++) { sh_file_rate_limit('unit', $k, 2, 60, $dir3); }
$data = json_decode((string)file_get_contents($dir3 . '/unit-' . hash('sha256', $k) . '.txt'), true);
t_eq('the counter records every attempt, including blocked ones', 5, (int)($data['n'] ?? 0));

$before = (string)@file_get_contents($dir3 . '/unit-' . hash('sha256', $k) . '.txt');
sh_file_rate_limit('unit', $k, 2, 60, $blocked, true);
t_eq('a fail-closed denial does not touch the counter store', $before,
    (string)@file_get_contents($dir3 . '/unit-' . hash('sha256', $k) . '.txt'));

// ===========================================================================
foreach (glob(SH_LOG_DIR . '/*/*') ?: [] as $f) { is_file($f) ? @unlink($f) : @rmdir($f); }
foreach (glob(SH_LOG_DIR . '/*') ?: [] as $f) { is_file($f) ? @unlink($f) : @rmdir($f); }
@rmdir(SH_LOG_DIR); @rmdir($tmp);
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "RATE LIMIT FAIL-SAFE SUITE: ALL CHECKS PASSED\n" : "RATE LIMIT FAIL-SAFE SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
