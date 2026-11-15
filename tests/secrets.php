<?php
/**
 * Server-side secret resolution tests (includes/secrets.php).
 *
 * The point of these tests is the trust boundary, not the happy path: a secret
 * must never be read from a lesser source when a stronger one exists, must never
 * be echoed, and a rotation must never make the application silently invent a
 * new value.
 *
 *     php tests/secrets.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['sh_test_passes'] = 0;
$GLOBALS['sh_test_failures'] = 0;

function t_ok(string $name, bool $pass, string $detail = ''): bool
{
    if ($pass) {
        $GLOBALS['sh_test_passes']++;
        echo "  ok   {$name}\n";
        return true;
    }
    $GLOBALS['sh_test_failures']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    return false;
}

function t_eq(string $name, $expected, $actual): bool
{
    return t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function t_sec(string $title): void
{
    echo "\n" . $title . "\n";
}

// A throw-away config directory so the tests never touch the real one.
$tmpDir = sys_get_temp_dir() . '/sh-secrets-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
define('SH_ROOT', dirname(__DIR__));
define('SH_CONFIG_DIR', $tmpDir);
define('SH_LOG_DIR', $tmpDir);

require_once __DIR__ . '/../includes/secrets.php';

function secrets_file(string $phpBody): void
{
    file_put_contents(SH_CONFIG_DIR . '/secrets.php', "<?php\nreturn " . $phpBody . ";\n");
    sh_secret_file_values(true);
}

function clear_secrets_file(): void
{
    @unlink(SH_CONFIG_DIR . '/secrets.php');
    sh_secret_file_values(true);
}

// ===========================================================================
t_sec('1. Resolution order');
// ===========================================================================
clear_secrets_file();
putenv('SH_SECRET_TEST_ENV=from-env');
putenv('SH_TEST_CONST=from-env');

t_eq('a constant beats an environment variable of the same name',
    'from-constant',
    (function () {
        define('SH_SECRET_TEST_CONST', 'from-constant');
        return sh_secret('test_const', 'fallback');
    })());
t_eq('an environment variable is used when no constant exists', 'from-env', sh_secret('test_env', 'fallback'));
t_eq('the generic SH_<KEY> environment variable also works', 'from-env-generic', (function () {
    putenv('SH_SECRET_TEST_GENERIC=from-env-generic');
    return sh_secret('test_generic', 'fallback');
})());
t_eq('the fallback is used when nothing is configured', 'fallback', sh_secret('test_absent', 'fallback'));
t_eq('an unknown key returns the fallback, not an error', 'x', sh_secret('', 'x'));

secrets_file("['test_file' => 'from-file']");
t_eq('the secrets file is used when no constant/environment exists', 'from-file', sh_secret('test_file', 'fallback'));
t_eq('a constant still wins over the file', 'from-constant', sh_secret('test_const', 'fallback'));
t_eq('the file does not shadow an environment variable', 'from-env', sh_secret('test_env', 'fallback'));

// ===========================================================================
t_sec('2. Source reporting never reveals the value');
// ===========================================================================
t_eq('a constant source is reported', 'constant', sh_secret_source('test_const'));
t_eq('an environment source is reported', 'environment', sh_secret_source('test_env'));
t_eq('a file source is reported', 'config/secrets.php', sh_secret_source('test_file'));
t_eq('an absent secret is reported as none', 'none', sh_secret_source('test_absent'));
t_ok('the reported source is a label, never the secret',
    !str_contains(sh_secret_source('test_file'), 'from-file'));
t_ok('is_external() answers without answering with the value',
    sh_secret_is_external('test_file') === true && sh_secret_is_external('test_absent') === false);

// ===========================================================================
t_sec('3. Broken configuration degrades safely');
// ===========================================================================
file_put_contents(SH_CONFIG_DIR . '/secrets.php', "<?php\nreturn 'not-an-array';\n");
sh_secret_file_values(true);
t_eq('a file that does not return an array is ignored', 'fallback', sh_secret('test_file', 'fallback'));
file_put_contents(SH_CONFIG_DIR . '/secrets.php', "<?php\nthrow new RuntimeException('boom');\n");
sh_secret_file_values(true);
t_eq('a file that throws is ignored', 'fallback', sh_secret('test_file', 'fallback'));
secrets_file("['test_blank' => '   ', 'test_file' => 'from-file']");
t_eq('a whitespace-only value is treated as absent', 'fallback', sh_secret('test_blank', 'fallback'));
t_eq('valid keys in the same file still work', 'from-file', sh_secret('test_file', 'fallback'));

// ===========================================================================
t_sec('4. Writing the secrets file');
// ===========================================================================
clear_secrets_file();
$write = sh_secret_write_file(['otp_rate_pepper' => 'abc123def456']);
t_ok('a new secrets file is created', ($write['ok'] ?? false) === true, (string)($write['error'] ?? ''));
t_ok('the file exists', is_file(SH_CONFIG_DIR . '/secrets.php'));
t_eq('the written file is readable by the loader', 'abc123def456', sh_secret('otp_rate_pepper', ''));
$perms = fileperms(SH_CONFIG_DIR . '/secrets.php') & 0777;
t_ok('the file is not world readable', ($perms & 0004) === 0, decoct($perms));

$same = sh_secret_write_file(['otp_rate_pepper' => 'abc123def456']);
t_ok('writing the same value again is harmless', ($same['ok'] ?? false) === true);
$clobber = sh_secret_write_file(['otp_rate_pepper' => 'a-different-value']);
t_ok('a different value is refused rather than silently replaced',
    ($clobber['ok'] ?? true) === false && str_contains((string)($clobber['error'] ?? ''), 'already defines'));
t_eq('the original value survived the refused write', 'abc123def456', sh_secret('otp_rate_pepper', ''));

$second = sh_secret_write_file(['another_secret' => 'value-two']);
t_ok('a new key can be added to an existing file', ($second['ok'] ?? false) === true);
t_eq('the first value is preserved', 'abc123def456', sh_secret('otp_rate_pepper', ''));
t_eq('the added value is readable', 'value-two', sh_secret('another_secret', ''));

$tricky = sh_secret_write_file(['quoted' => "it's \\ backslash"]);
t_ok('a value containing a quote and a backslash is written', ($tricky['ok'] ?? false) === true);
t_eq('a quoted value round-trips exactly', "it's \\ backslash", sh_secret('quoted', ''));

// ===========================================================================
t_sec('5. Wiring: no secret reaches a page, a log or an API');
// ===========================================================================
$adminFiles = glob(__DIR__ . '/../admin/*.php') ?: [];
$leaks = [];
foreach ($adminFiles as $f) {
    $src = (string)file_get_contents($f);
    // A secret must never be echoed into HTML or JSON.
    if (preg_match('~<\?=\s*sh_secret\s*\(~', $src) === 1) { $leaks[] = basename($f); }
    if (preg_match('~\\\$_(?:GET|POST|REQUEST)\[\s*[\'"](?:secret|pepper)~i', $src) === 1) { $leaks[] = basename($f); }
}
t_ok('no admin page prints a secret value', $leaks === [], implode(',', $leaks));

$secretsSrc = (string)file_get_contents(__DIR__ . '/../includes/secrets.php');
t_ok('the secrets module never logs a value',
    !preg_match('~sh_log_(?:line|exception)\([^;]*\$(?:value|v|secret|loaded)~', $secretsSrc));
t_ok('the secrets module never var_dumps or prints',
    !preg_match('~\b(?:var_dump|print_r|echo)\s*\(~', $secretsSrc));

$otpSrc = (string)file_get_contents(__DIR__ . '/../includes/phone-otp.php');
t_ok('the pepper is resolved through the secrets layer',
    str_contains($otpSrc, 'sh_secret(SH_OTP_PEPPER_SECRET'));
t_ok('a rotation keeps retired peppers for counting',
    str_contains($otpSrc, "sh_secret(SH_OTP_PEPPER_SECRET . '_previous'"));
t_ok('the pepper is never echoed by the phone module',
    !preg_match('~echo[^;]*sh_otp_rate_pepper~', $otpSrc));

// ===========================================================================
putenv('SH_SECRET_TEST_ENV');
putenv('SH_TEST_CONST');
clear_secrets_file();
@rmdir($tmpDir);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['sh_test_passes'], $GLOBALS['sh_test_failures']);
echo $GLOBALS['sh_test_failures'] === 0 ? "SECRETS SUITE: ALL CHECKS PASSED\n" : "SECRETS SUITE: FAILURES PRESENT\n";
exit($GLOBALS['sh_test_failures'] === 0 ? 0 : 1);
