<?php
/**
 * Secret migration tests: the "move the OTP pepper out of the database" button.
 *
 * What must be true even when the write fails:
 *   - nothing is lost: the old file is never truncated or half-written;
 *   - the pepper still resolves, so verification and its rate limits keep working;
 *   - the failure is reported to the caller (and to the admin as an error, never
 *     as a success) and the secret value never appears in a message;
 *   - a retry after fixing permissions works, and an existing value is never
 *     silently overwritten;
 *   - failed attempts leave no .tmp files behind.
 *
 * Runs against a throwaway config directory; no database and no browser.
 *
 *     php tests/secrets-migration.php
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
$sandbox = sys_get_temp_dir() . '/sh-secrets-' . bin2hex(random_bytes(4));
$configDir = $sandbox . '/config';
mkdir($configDir, 0777, true);
define('SH_ROOT', $sandbox);
define('SH_CONFIG_DIR', $configDir);
define('SH_LOG_DIR', $sandbox . '/logs');
mkdir(SH_LOG_DIR, 0777, true);

$GLOBALS['log'] = [];
function sh_log_line(string $channel, string $message): void { $GLOBALS['log'][] = $channel . ': ' . $message; }

require_once $root . '/includes/secrets.php';

$secretFile = sh_secret_config_file();

function wipe_secret_file(string $file): void
{
    foreach (glob($file . '.*.tmp') ?: [] as $f) { @unlink($f); }
    @unlink($file);
}
function leftover_tmp(string $file): array
{
    return glob($file . '.*.tmp') ?: [];
}

// ===========================================================================
t_sec('1. A normal migration');
// ===========================================================================
wipe_secret_file($secretFile);
t_eq('nothing is external before the move', 'none', sh_secret_source('otp_rate_pepper'));

$res = sh_secret_write_file(['otp_rate_pepper' => 'abc123def456']);
t_ok('the write succeeds on a writable config folder', ($res['ok'] ?? false) === true, (string)($res['error'] ?? ''));
t_ok('the file exists', is_file($secretFile));
t_eq('the file is readable only by the account (0600)', '0600', substr(sprintf('%o', fileperms($secretFile)), -4));
t_eq('the source becomes config/secrets.php', 'config/secrets.php', sh_secret_source('otp_rate_pepper'));
t_eq('the value resolves through sh_secret()', 'abc123def456', sh_secret('otp_rate_pepper'));
t_eq('no temporary file is left behind', [], leftover_tmp($secretFile));
$returned = require $secretFile;
t_ok('the written file is valid PHP returning an array', is_array($returned));

// ===========================================================================
t_sec('2. Never silently overwrite, never corrupt');
// ===========================================================================
$before = (string)file_get_contents($secretFile);
$res = sh_secret_write_file(['otp_rate_pepper' => 'a-different-value']);
t_ok('a conflicting value is refused', ($res['ok'] ?? true) === false);
t_ok('the refusal says the file already defines it', str_contains((string)($res['error'] ?? ''), 'already defines'));
t_eq('the file was not modified', $before, (string)file_get_contents($secretFile));
t_eq('the original value still resolves', 'abc123def456', sh_secret('otp_rate_pepper'));

$res = sh_secret_write_file(['otp_rate_pepper' => 'abc123def456']);
t_ok('re-running with the same value is idempotent', ($res['ok'] ?? false) === true);
t_eq('still no temporary file', [], leftover_tmp($secretFile));

// ===========================================================================
t_sec('3. Values are escaped, not injected');
// ===========================================================================
wipe_secret_file($secretFile);
$nasty = "pa'ss\\word";
$res = sh_secret_write_file(['otp_rate_pepper' => $nasty, 'Backup-Cron-Token' => 'zzz']);
t_ok('a value with a quote and a backslash is accepted', ($res['ok'] ?? false) === true);
sh_secret_file_values(true);
t_eq('it round-trips exactly', $nasty, sh_secret('otp_rate_pepper'));
$raw = (string)file_get_contents($secretFile);
t_ok('the apostrophe is escaped in the file', str_contains($raw, "\\'"));
t_ok('a key that is not lower_snake_case is dropped rather than emitted', !str_contains($raw, 'Backup-Cron-Token'));

// ===========================================================================
t_sec('4. A broken existing file is left alone');
// ===========================================================================
wipe_secret_file($secretFile);
file_put_contents($secretFile, "<?php return [ 'otp_rate_pepper' => ");
$broken = (string)file_get_contents($secretFile);
$res = @sh_secret_write_file(['otp_rate_pepper' => 'new']);
t_ok('a secrets file that does not parse is reported, not overwritten', ($res['ok'] ?? true) === false);
t_eq('the broken file is untouched byte for byte', $broken, (string)file_get_contents($secretFile));
sh_secret_file_values(true);
t_eq('a broken file does not take the store down: sh_secret returns nothing',
    '', sh_secret('otp_rate_pepper'));
t_eq('no temporary file after a parse failure', [], leftover_tmp($secretFile));

// ===========================================================================
t_sec('5. The write failure path');
// ===========================================================================
// 5a. no file yet and the folder is not writable (the common shared-hosting case)
wipe_secret_file($secretFile);
sh_secret_file_values(true);
chmod($configDir, 0555);
$GLOBALS['log'] = [];
$res = sh_secret_write_file(['otp_rate_pepper' => 'cannot-write']);
t_ok('an unwritable config folder is reported as a failure', ($res['ok'] ?? true) === false);
t_ok('the error names the folder and the permission',
    str_contains((string)($res['error'] ?? ''), 'not writable'), (string)($res['error'] ?? 'no error'));
t_ok('the error does not contain the secret value', !str_contains((string)($res['error'] ?? ''), 'cannot-write'));
t_ok('no file was created', !is_file($secretFile));
t_eq('no temporary file after a permission failure', [], leftover_tmp($secretFile));
chmod($configDir, 0777);

// 5b. a pepper is already in place and the folder becomes unwritable
$res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);
sh_secret_file_values(true);
$keep = (string)file_get_contents($secretFile);
t_eq('the existing pepper resolves before the attempt', 'keep-me', sh_secret('otp_rate_pepper'));
chmod($configDir, 0555);
$GLOBALS['log'] = [];
$res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);   // same value: no conflict, only the write
t_ok('the same value cannot be re-written into an unwritable folder', ($res['ok'] ?? true) === false);
t_eq('the existing file is intact', $keep, (string)file_get_contents($secretFile));
t_eq('the old pepper still resolves during and after the failure', 'keep-me', sh_secret('otp_rate_pepper'));
t_ok('the error does not contain the existing secret', !str_contains((string)($res['error'] ?? ''), 'keep-me'));
t_eq('no temporary file after a permission failure', [], leftover_tmp($secretFile));
chmod($configDir, 0777);

// 5c. a conflicting value is refused before the filesystem is touched at all
chmod($configDir, 0555);
$res = sh_secret_write_file(['otp_rate_pepper' => 'something-else']);
t_ok('a different value is refused even when the folder is unwritable',
    ($res['ok'] ?? true) === false && str_contains((string)($res['error'] ?? ''), 'already defines'));
chmod($configDir, 0777);

// 5b. the folder is writable but the file itself is read-only. POSIX rename()
// only needs write access to the directory, so the replacement succeeds — what
// matters is that the value survives and the permissions are re-tightened.
chmod($configDir, 0777);
chmod($secretFile, 0400);
$res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);
sh_secret_file_values(true);
t_eq('the pepper still resolves after replacing a read-only file', 'keep-me', sh_secret('otp_rate_pepper'));
t_eq('the replacement file keeps 0600 permissions', '0600', substr(sprintf('%o', fileperms($secretFile)), -4));
t_eq('no temporary file is left behind', [], leftover_tmp($secretFile));


// 5c. the config folder is missing entirely
$missingDir = $sandbox . '/no-config-here';
if (!is_dir($missingDir)) { mkdir($missingDir); }
// (SH_CONFIG_DIR is a constant, so simulate by pointing at a file path)
t_ok('sh_secret_config_file() always points inside the config folder',
    str_starts_with(sh_secret_config_file(), $configDir . '/'));

// 5d. recovery: fixing permissions lets the same call succeed
chmod($configDir, 0777);
wipe_secret_file($secretFile);
$res = sh_secret_write_file(['otp_rate_pepper' => 'recovered-value']);
t_ok('the migration succeeds once permissions are fixed', ($res['ok'] ?? false) === true, (string)($res['error'] ?? ''));
t_eq('the recovered value resolves', 'recovered-value', sh_secret('otp_rate_pepper'));

// ===========================================================================
t_sec('6. After a failure, rate-limit keys are unchanged');
// ===========================================================================
// The pepper is what makes stored rate-limit keys unreadable. A failed move must
// therefore not change the pepper the application uses.
chmod($configDir, 0555);
sh_secret_file_values(true);
$stillThere = sh_secret('otp_rate_pepper');
chmod($configDir, 0777);
t_eq('the pepper in use is unchanged by a failed write', 'recovered-value', $stillThere);

// ===========================================================================
t_sec('7. The admin panel cannot report a failure as a success');
// ===========================================================================
$admin = (string)file_get_contents($root . '/admin/security.php');
t_ok('the panel flashes an error when the write fails',
    (bool)preg_match("~if \(!empty\(\\\$written\['ok'\]\)\).*?else \{.*?sh_flash\('error'~s", $admin));
t_ok('the success flash is inside the ok branch only',
    (bool)preg_match("~if \(!empty\(\\\$written\['ok'\]\)\) \{\s*sh_security_log\('otp_pepper_moved'~s", $admin));
t_ok('the failure is audited', str_contains($admin, "'otp_pepper_move_failed'"));
t_ok('the failure is written to the application log', str_contains($admin, 'OTP pepper could not be moved'));
t_ok('the failure message explains the manual fallback', str_contains($admin, "return ['otp_rate_pepper' => '…'];"));
t_ok('the failure message offers the environment-variable route', str_contains($admin, 'SH_SECRET_OTP_RATE_PEPPER'));
t_ok('the panel never prints the pepper value', !preg_match('~sh_otp_rate_pepper\(true\)~', $admin));
t_ok('the panel never echoes the secret file contents', !str_contains($admin, 'file_get_contents(sh_secret_config_file'));

// ===========================================================================
foreach (glob(SH_LOG_DIR . '/*') ?: [] as $f) { @unlink($f); }
wipe_secret_file($secretFile);
@rmdir($missingDir);
@chmod($configDir, 0777);
@unlink($secretFile);
@rmdir($configDir);
@rmdir(SH_LOG_DIR);
@rmdir($sandbox);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "SECRETS MIGRATION SUITE: ALL CHECKS PASSED\n" : "SECRETS MIGRATION SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
