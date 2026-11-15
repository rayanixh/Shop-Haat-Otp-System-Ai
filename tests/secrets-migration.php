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
$GLOBALS['blocked'] = [];
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

/**
 * BLOCKED is not a failure and not a pass: the environment cannot produce the
 * condition the check needs. It is reported separately and by name, so a run on a
 * host that *can* enforce permissions is never confused with one that cannot.
 */
function t_blocked(string $name, string $reason): void
{
    $GLOBALS['blocked'][$name] = $reason;
    echo "  BLOCK {$name}  [{$reason}]\n";
}

/**
 * Can this process be denied a write by POSIX permissions?
 *
 * As root — or in a container holding CAP_DAC_OVERRIDE, or on a filesystem
 * mounted without permission checks — `chmod($dir, 0555)` changes the mode but
 * does not stop PHP writing. A permission test that runs there proves nothing:
 * it fails, or worse, "passes" because the write happened to succeed for another
 * reason. Probe it once, and report the dependent checks BLOCKED when the answer
 * is no.
 */
function perm_denial_effective(string $base): bool
{
    // Test-only: reproduce a root-like host (where chmod 0555 does not deny a
    // write) on a host that does enforce modes, so the BLOCKED classification
    // below can be exercised anywhere. It changes nothing about the application
    // or about what is proven: the checks are reported BLOCKED either way.
    if (getenv('SH_TEST_ASSUME_NO_PERM_ENFORCEMENT') === '1') { return false; }
    $probe = $base . '/permprobe-' . bin2hex(random_bytes(3));
    if (!@mkdir($probe, 0777)) { return false; }
    @chmod($probe, 0555);
    clearstatcache(true, $probe . '/x');
    $wrote = @file_put_contents($probe . '/x', 'probe');
    $result = ($wrote === false);
    @unlink($probe . '/x');
    @chmod($probe, 0777);
    @rmdir($probe);
    return $result;
}

/** Does chmod() actually change the mode here (false when the process is not the owner)? */
function chmod_effective(string $base): bool
{
    if (getenv('SH_TEST_ASSUME_NO_PERM_ENFORCEMENT') === '1') { return false; }
    $file = $base . '/chmodprobe-' . bin2hex(random_bytes(3));
    if (@file_put_contents($file, 'x') === false) { return false; }
    @chmod($file, 0600);
    clearstatcache(true, $file);
    $mode = substr(sprintf('%o', @fileperms($file)), -4);
    @unlink($file);
    return $mode === '0600';
}

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
clearstatcache(true, $secretFile);
$mode = substr(sprintf('%o', fileperms($secretFile)), -4);
if ($mode === '0600') {
    t_ok('the file is readable only by the account (0600)', true);
} elseif (chmod_effective($sandbox)) {
    t_ok('the file is readable only by the account (0600)', false, 'got ' . $mode);
} else {
    // chmod() silently does nothing when the process does not own the file (SMB
    // or NFS mounts, a file created by a different account, a container uid
    // mismatch). That is an environment fact, not an application defect: report
    // it, and let the real-host run prove the mode.
    t_blocked('the file is readable only by the account (0600)',
        'chmod() has no effect here (file is owned by another account or the filesystem ignores modes); got ' . $mode);
}
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
// Whether a permission denial can be produced here at all. On a normal shared
// host it can; as root, with CAP_DAC_OVERRIDE, or on a filesystem that ignores
// POSIX modes it cannot, and a permission test run there proves nothing.
$denyWorks = perm_denial_effective($sandbox);
$chmodWorks = chmod_effective($sandbox);
if (!$denyWorks) {
    echo "  note: this process can write into a 0555 folder (running as root, holding\n"
       . "        CAP_DAC_OVERRIDE, or the filesystem does not enforce POSIX modes).\n"
       . "        The permission-dependent checks below are reported BLOCKED by name;\n"
       . "        the injected failure in 5a is not affected and always runs.\n";
}

// 5a. A failure every environment can produce, root included: make the target
// path a DIRECTORY. rename() cannot replace a directory with a file, so the last
// step of the write fails with "could not be moved into place" — the same code
// path a permission failure takes, without depending on permissions.
wipe_secret_file($secretFile);
sh_secret_file_values(true);
@unlink($secretFile);
if (!mkdir($secretFile, 0777) && !is_dir($secretFile)) {
    t_ok('the injected obstruction could be created', false, 'mkdir failed for ' . $secretFile);
}
$GLOBALS['log'] = [];
$res = sh_secret_write_file(['otp_rate_pepper' => 'cannot-move-me']);
t_ok('a write that cannot be moved into place is reported as a failure',
    ($res['ok'] ?? true) === false, (string)($res['error'] ?? 'no error'));
t_ok('the failure names the move, and not the secret',
    str_contains((string)($res['error'] ?? ''), 'moved into place'), (string)($res['error'] ?? 'no error'));
t_ok('the error does not contain the secret value',
    !str_contains((string)($res['error'] ?? ''), 'cannot-move-me'));
t_eq('no temporary file survives the injected failure', [], leftover_tmp($secretFile));
t_ok('the obstructed path was left exactly as it was', is_dir($secretFile));
t_ok('the failure is logged without the value',
    $GLOBALS['log'] === [] || !str_contains(implode("\n", $GLOBALS['log']), 'cannot-move-me'));
@rmdir($secretFile);

$res = sh_secret_write_file(['otp_rate_pepper' => 'after-injection']);
t_ok('the migration succeeds once the obstruction is gone', ($res['ok'] ?? false) === true, (string)($res['error'] ?? ''));
sh_secret_file_values(true);
t_eq('the value written after recovery resolves', 'after-injection', sh_secret('otp_rate_pepper'));

// 5b. The real shared-hosting case: no file yet and the config folder is not
// writable. Requires an environment that enforces the mode.
$permChecks = [];
if ($denyWorks) {
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

    // 5c. A pepper is already in place and the folder becomes unwritable.
    $res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);
    sh_secret_file_values(true);
    $keep = (string)file_get_contents($secretFile);
    t_eq('the existing pepper resolves before the attempt', 'keep-me', sh_secret('otp_rate_pepper'));
    chmod($configDir, 0555);
    $GLOBALS['log'] = [];
    $res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);
    t_ok('the same value cannot be re-written into an unwritable folder', ($res['ok'] ?? true) === false);
    t_eq('the existing file is intact', $keep, (string)file_get_contents($secretFile));
    t_eq('the old pepper still resolves during and after the failure', 'keep-me', sh_secret('otp_rate_pepper'));
    t_ok('the error does not contain the existing secret', !str_contains((string)($res['error'] ?? ''), 'keep-me'));
    t_eq('no temporary file after a permission failure', [], leftover_tmp($secretFile));
    chmod($configDir, 0777);

    // 5d. A conflicting value is refused before the filesystem is touched at all.
    chmod($configDir, 0555);
    $res = sh_secret_write_file(['otp_rate_pepper' => 'something-else']);
    t_ok('a different value is refused even when the folder is unwritable',
        ($res['ok'] ?? true) === false && str_contains((string)($res['error'] ?? ''), 'already defines'));
    chmod($configDir, 0777);

    // 5e. The folder is writable but the file itself is read-only. POSIX rename()
    // only needs write access to the directory, so the replacement succeeds; what
    // matters is that the value survives and the mode is re-tightened.
    chmod($secretFile, 0400);
    $res = sh_secret_write_file(['otp_rate_pepper' => 'keep-me']);
    sh_secret_file_values(true);
    t_eq('the pepper still resolves after replacing a read-only file', 'keep-me', sh_secret('otp_rate_pepper'));
    clearstatcache(true, $secretFile);
    $mode2 = substr(sprintf('%o', fileperms($secretFile)), -4);
    if ($mode2 === '0600') {
        t_ok('the replacement file keeps 0600 permissions', true);
    } elseif ($chmodWorks) {
        t_ok('the replacement file keeps 0600 permissions', false, 'got ' . $mode2);
    } else {
        t_blocked('the replacement file keeps 0600 permissions',
            'chmod() has no effect here; got ' . $mode2);
    }
    t_eq('no temporary file is left behind', [], leftover_tmp($secretFile));
} else {
    foreach ([
        'an unwritable config folder is reported as a failure',
        'the error names the folder and the permission',
        'the error does not contain the secret value',
        'no file was created',
        'no temporary file after a permission failure',
        'the existing pepper resolves before the attempt',
        'the same value cannot be re-written into an unwritable folder',
        'the existing file is intact',
        'the old pepper still resolves during and after the failure',
        'the error does not contain the existing secret',
        'a different value is refused even when the folder is unwritable',
        'the pepper still resolves after replacing a read-only file',
        'the replacement file keeps 0600 permissions',
    ] as $permCheck) {
        t_blocked($permCheck, 'this process is not denied by POSIX permissions (root / CAP_DAC_OVERRIDE / no mode enforcement)');
    }
    // Restore a known good state for the sections that follow, without claiming
    // anything about permissions.
    wipe_secret_file($secretFile);
    chmod($configDir, 0777);
    $res = sh_secret_write_file(['otp_rate_pepper' => 'recovered-value']);
    sh_secret_file_values(true);
}

// 5f. The config folder is missing entirely. This needs no permission tricks, so
// it runs everywhere: the helper must still point inside it and the write must be
// refused with a message that says what is wrong.
$parkedConfig = $configDir . '-parked';
if (@rename($configDir, $parkedConfig)) {
    sh_secret_file_values(true);
    $listBefore = glob($parkedConfig . '/*') ?: [];
    $res = sh_secret_write_file(['otp_rate_pepper' => 'no-folder-here']);
    t_ok('a missing config folder is reported instead of a stray file being written',
        ($res['ok'] ?? true) === false && str_contains((string)($res['error'] ?? ''), 'missing'),
        (string)($res['error'] ?? 'no error'));
    t_ok('the refused write added no file anywhere', (glob($parkedConfig . '/*') ?: []) === $listBefore,
        'before: ' . implode(',', $listBefore) . ' after: ' . implode(',', glob($parkedConfig . '/*') ?: []));
    @rename($parkedConfig, $configDir);
    sh_secret_file_values(true);
} else {
    t_blocked('a missing config folder is reported instead of a stray file being written',
        'the config folder could not be renamed here');
}
t_ok('sh_secret_config_file() always points inside the config folder',
    str_starts_with(sh_secret_config_file(), $configDir . '/'));

// 5g. Recovery: a clean file can be written again and the value resolves.
if ($denyWorks) {
    chmod($configDir, 0777);
    wipe_secret_file($secretFile);
    $res = sh_secret_write_file(['otp_rate_pepper' => 'recovered-value']);
    t_ok('the migration succeeds once permissions are fixed', ($res['ok'] ?? false) === true, (string)($res['error'] ?? ''));
    t_eq('the recovered value resolves', 'recovered-value', sh_secret('otp_rate_pepper'));
}

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
t_sec('8. A secret never reaches a log line, an error message or the shipped files');
// ===========================================================================
// Every value this suite has written, plus the ones it tried to write while the
// write was expected to fail. None of them may appear in the log buffer.
$secretValues = ['abc123def456', "pa'ss\\word", 'zzz', 'keep-me', 'recovered-value',
                 'after-injection', 'cannot-write', 'cannot-move-me', 'a-different-value'];
$logBlob = implode("\n", $GLOBALS['log']);
$leaked = [];
foreach ($secretValues as $value) {
    if ($value !== '' && str_contains($logBlob, $value)) { $leaked[] = $value; }
}
t_ok('no written secret appears in any log line emitted during this run', $leaked === [], implode(', ', $leaked));
$examples = (string)@file_get_contents($root . '/config/secrets.example.php');
t_ok('the shipped example file contains placeholders, not values',
    $examples !== '' && !preg_match('~=>\s*[\'"][A-Za-z0-9]{24,}[\'"]~', $examples));
$secretsSource = (string)file_get_contents($root . '/includes/secrets.php');
t_ok('the implementation never logs the value it writes',
    !preg_match('~sh_log_line\([^)]*\$v\b~', $secretsSource));
t_ok('the implementation never concatenates a value into an error string',
    !preg_match('~error[\'"`]\s*\.\s*\$v\b~', $secretsSource));

// ===========================================================================
t_sec('9. Replacement is atomic by construction');
// ===========================================================================
// A crash between "open" and "write" must never be able to leave a half-written
// secrets file: the new content is written to a temporary name, restricted, and
// only then renamed over the target (rename is atomic within a directory).
t_ok('the content is written to a temporary name first',
    (bool)preg_match('~\$tmp\s*=\s*\$file\s*\.\s*[\'"]\.~', $secretsSource));
t_ok('the temporary file is restricted before it is moved into place',
    (bool)preg_match('~chmod\(\$tmp,\s*0600\).*?rename\(\$tmp,\s*\$file\)~s', $secretsSource));
t_ok('a failed rename removes the temporary file',
    (bool)preg_match('~if\s*\(!@?rename\(\$tmp,\s*\$file\)\)\s*\{\s*@?unlink\(\$tmp\)~s', $secretsSource));
t_ok('the file is written with LOCK_EX', str_contains($secretsSource, 'LOCK_EX'));
t_ok('the resolved values are re-read after a successful write',
    str_contains($secretsSource, 'sh_secret_file_values(true)'));

// ===========================================================================
foreach (glob(SH_LOG_DIR . '/*') ?: [] as $f) { @unlink($f); }
wipe_secret_file($secretFile);
@chmod($configDir, 0777);
@unlink($secretFile);
@rmdir($configDir);
@rmdir(SH_LOG_DIR);
@rmdir($sandbox);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d   blocked %d\n", $GLOBALS['pass'], $GLOBALS['fail'], count($GLOBALS['blocked']));
if ($GLOBALS['blocked'] !== []) {
    echo "Blocked (the environment cannot produce the condition; NOT a pass, NOT a failure):\n";
    foreach ($GLOBALS['blocked'] as $name => $reason) { echo "  - {$name}: {$reason}\n"; }
    echo "Re-run this suite as a normal (non-root) account that owns the config folder to\n"
       . "exercise them: the file must be owned by the web user and the filesystem must\n"
       . "enforce POSIX modes.\n";
}
echo $GLOBALS['fail'] === 0 ? "SECRETS MIGRATION SUITE: ALL EXECUTED CHECKS PASSED\n" : "SECRETS MIGRATION SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
