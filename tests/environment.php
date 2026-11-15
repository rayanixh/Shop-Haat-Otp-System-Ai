<?php
/**
 * Environment resolution tests (includes/environment.php).
 *
 * The environment decides whether the offline OTP driver may run, whether an OTP
 * code may ever be echoed, and whether verbose diagnostics are produced. A
 * wrong answer here is a security regression, so every accepted spelling, the
 * default, and the typo path are asserted explicitly.
 *
 *     php tests/environment.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['sh_test_passes'] = 0;
$GLOBALS['sh_test_failures'] = 0;

function t_ok(string $name, bool $pass, string $detail = ''): bool
{
    if ($pass) { $GLOBALS['sh_test_passes']++; echo "  ok   {$name}\n"; return true; }
    $GLOBALS['sh_test_failures']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    return false;
}

function t_eq(string $name, $expected, $actual): bool
{
    return t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function t_sec(string $title): void { echo "\n" . $title . "\n"; }

define('SH_ROOT', dirname(__DIR__));
require_once __DIR__ . '/../includes/environment.php';

/** Resolve the environment for a raw configured value ('' = unset). */
function env_of(string $raw): string
{
    if ($raw === '') { putenv('SH_ENVIRONMENT'); unset($_SERVER['SH_ENVIRONMENT']); }
    else { putenv('SH_ENVIRONMENT=' . $raw); }
    return sh_environment();
}

// ===========================================================================
t_sec('1. Accepted values');
// ===========================================================================
foreach (['development', 'dev', 'local', 'localhost', 'sandbox', 'testing', 'test', 'DEVELOPMENT', ' Development '] as $raw) {
    t_eq('"' . trim($raw) . '" is development', 'development', env_of($raw));
}
foreach (['staging', 'stage', 'preprod', 'pre-production', 'preproduction', 'uat', 'STAGING'] as $raw) {
    t_eq('"' . trim($raw) . '" is staging', 'staging', env_of($raw));
}
foreach (['production', 'prod', 'live', 'PRODUCTION'] as $raw) {
    t_eq('"' . trim($raw) . '" is production', 'production', env_of($raw));
}

// ===========================================================================
t_sec('2. The safe default');
// ===========================================================================
t_eq('nothing configured is production', 'production', env_of(''));
foreach (['stagingg', 'devlopment', 'produciton', '0', 'yes', 'null'] as $typo) {
    t_eq('the typo "' . $typo . '" fails closed to production', 'production', env_of($typo));
}

// ===========================================================================
t_sec('3. Privileges per environment');
// ===========================================================================
env_of('development');
t_ok('development: is_development', sh_is_development());
t_ok('development: not staging', !sh_is_staging());
t_ok('development: not production', !sh_is_production());
t_ok('development: debug allowed', sh_debug_allowed());
t_eq('development: label', 'Development', sh_environment_label());

env_of('staging');
t_ok('staging: is staging', sh_is_staging());
t_ok('staging: NOT development', !sh_is_development());
t_ok('staging: not production', !sh_is_production());
t_ok('staging: no debug output', !sh_debug_allowed());
t_eq('staging: label', 'Staging', sh_environment_label());

env_of('production');
t_ok('production: is production', sh_is_production());
t_ok('production: not development', !sh_is_development());
t_ok('production: not staging', !sh_is_staging());
t_ok('production: no debug output', !sh_debug_allowed());
t_eq('production: label', 'Production', sh_environment_label());

// ===========================================================================
t_sec('4. Staging access gate');
// ===========================================================================
env_of('staging');
t_ok('staging without credentials stays reachable (host-level protection)', sh_staging_authenticated());
t_ok('staging without credentials is reported as unprotected', sh_staging_unprotected());
t_ok('the gate lets an unprotected staging request through', sh_staging_gate() === false);

define('SH_STAGING_USER', 'reviewer');
define('SH_STAGING_PASS', 'a-long-random-value');
t_ok('with credentials configured the site is protected', !sh_staging_unprotected());
t_ok('anonymous access is refused', !sh_staging_authenticated());
$_SERVER['PHP_AUTH_USER'] = 'reviewer';
$_SERVER['PHP_AUTH_PW'] = 'wrong-password';
t_ok('a wrong password is refused', !sh_staging_authenticated());
$_SERVER['PHP_AUTH_USER'] = 'wrong-user';
$_SERVER['PHP_AUTH_PW'] = 'a-long-random-value';
t_ok('a wrong username is refused', !sh_staging_authenticated());
$_SERVER['PHP_AUTH_USER'] = 'reviewer';
$_SERVER['PHP_AUTH_PW'] = 'a-long-random-value';
t_ok('correct credentials are accepted', sh_staging_authenticated());
$out = '';
ob_start();
$blocked = sh_staging_gate();
$out = (string)ob_get_clean();
t_ok('an authenticated request passes the gate', $blocked === false && $out === '');

unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
ob_start();
$blocked = sh_staging_gate();
$body = (string)ob_get_clean();
t_ok('an anonymous request is blocked', $blocked === true);
t_ok('the block page is marked noindex', str_contains($body, 'noindex'));
t_ok('the block page reveals no configuration detail',
    !str_contains($body, 'reviewer') && !str_contains($body, 'a-long-random-value'));

env_of('production');
t_ok('the gate never applies outside staging', !sh_staging_authenticated() === false);
t_ok('production is unaffected by staging credentials', sh_staging_gate() === false);

// ===========================================================================
t_sec('5. Wiring');
// ===========================================================================
$configSrc = (string)file_get_contents(__DIR__ . '/../config/config.php');
$otpSrc = (string)file_get_contents(__DIR__ . '/../includes/phone-otp.php');
t_ok('config.php applies the staging gate', str_contains($configSrc, 'sh_staging_gate()'));
t_ok('config.php still forces display_errors off', str_contains($configSrc, "ini_set('display_errors', '0')"));
t_ok('the offline driver is gated on development only', str_contains($otpSrc, "if (!sh_is_development())"));
t_ok('code echo requires development', str_contains($otpSrc, "return sh_is_development() && sh_otp_provider() === 'offline';"));
t_ok('the example config documents all three environments',
    str_contains((string)file_get_contents(__DIR__ . '/../config/environment.example.php'), 'staging'));

putenv('SH_ENVIRONMENT');

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['sh_test_passes'], $GLOBALS['sh_test_failures']);
echo $GLOBALS['sh_test_failures'] === 0 ? "ENVIRONMENT SUITE: ALL CHECKS PASSED\n" : "ENVIRONMENT SUITE: FAILURES PRESENT\n";
exit($GLOBALS['sh_test_failures'] === 0 ? 0 : 1);
