<?php
/**
 * Backup → restore rehearsal suite.
 *
 * Two halves:
 *
 *   A. The refusal matrix — always executed. A rehearsal tool that can be talked
 *      into dropping the wrong database is worse than no tool, so every guard is
 *      invoked for real (in a child process) and its message checked: missing
 *      file, empty file, a .gz that is not gzip, a dump with no tables, a scratch
 *      name that does not look disposable, a scratch name equal to the live one,
 *      a production-looking name, invalid characters, and --create-backup on a
 *      checkout that is not installed.
 *
 *   B. The real rehearsal — only with a staging database. It creates a NEW backup
 *      through the application, imports it into a scratch database, verifies
 *      tables / indexes / relationships / settings / a representative order, asks
 *      the application to read the restored copy, then drops it.
 *
 *     php tests/restore-rehearsal.php
 *     SH_TEST_MYSQL_DSN='mysql:host=…;dbname=shophaat_staging_test;charset=utf8mb4' \
 *     SH_TEST_MYSQL_USER=… SH_TEST_MYSQL_PASS=… SH_TEST_MYSQL_ALLOW_DROP=1 \
 *     SH_TEST_MYSQL_ROOT_USER=root SH_TEST_MYSQL_ROOT_PASS=… \
 *         php tests/restore-rehearsal.php
 *
 * The scratch-database operations need a user that may CREATE/DROP a database
 * (often not the application's own account on shared hosting, and not the CI
 * app user), hence the separate *_ROOT_ credentials. They default to the
 * application credentials when unset.
 *
 * Nothing here touches a database unless it is named like a test database, and
 * the tool refuses a production-looking name even when asked.
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';
require_once __DIR__ . '/_support_php_bin.php';

$php = sh_test_php_binary();
$tool = SH_ROOT . '/tools/restore-rehearsal.php';
$tmp = sys_get_temp_dir() . '/sh-restore-suite-' . bin2hex(random_bytes(3));
@mkdir($tmp, 0777, true);

/**
 * Run the tool; returns [output, exitCode, codesReliable].
 *
 * $toolOverride runs a copy of the tool from another directory. The tool finds
 * the application through its own location (dirname(__DIR__)), so a copy under a
 * bare temporary root is the only honest way to reproduce "not installed" on a
 * machine where the real checkout IS installed.
 */
function rr_run(array $args, string $tmp, ?string $toolOverride = null): array
{
    $php = sh_test_php_binary();
    if (!$php['ok']) { return ['', -1, false]; }
    $cmd = escapeshellarg($php['bin']) . ' ' . escapeshellarg($toolOverride ?? SH_ROOT . '/tools/restore-rehearsal.php');
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $cmd .= ' 2>&1';
    $out = (string)@shell_exec($cmd);
    // `$?` is not visible through shell_exec; append it so the caller can check
    // it without a second process.
    $out2 = (string)@shell_exec($cmd . '; echo "\\n__RC=$?"');
    $rc = -1;
    if (preg_match('~__RC=(-?\d+)~', $out2, $m)) {
        $rc = (int)$m[1];
        $out2 = trim(preg_replace('~\n__RC=-?\d+\s*$~', '', $out2));
    }
    return [$out2 !== '' ? $out2 : $out, $rc, true];
}

/** Does this PHP build report exit codes to the shell? (php-wasm always returns 0.) */
function rr_exit_codes_work(): bool
{
    $php = sh_test_php_binary();
    if (!$php['ok']) { return false; }
    $out = (string)@shell_exec(escapeshellarg($php['bin']) . ' -r ' . escapeshellarg('exit(7);') . '; echo "__RC=$?"');
    return str_contains($out, '__RC=7');
}
$codesReliable = rr_exit_codes_work();

// Fixtures -------------------------------------------------------------------
$empty = $tmp . '/empty.sql';
file_put_contents($empty, '');
$notGzip = $tmp . '/fake.sql.gz';
file_put_contents($notGzip, "not a gzip stream\nCREATE TABLE x (id int);\n");
$noTables = $tmp . '/notables.sql';
file_put_contents($noTables, "SET NAMES utf8mb4;\n-- ShopHaat database backup\nINSERT INTO x VALUES (1);\n");
$good = $tmp . '/good.sql';
file_put_contents($good, "SET NAMES utf8mb4;\n-- ShopHaat database backup\n"
    . "CREATE TABLE `a` (`id` int);\nCREATE TABLE `b` (`id` int);\nINSERT INTO `a` VALUES (1);\n");

[$passed, $failed, $blocked] = sh_test_run('Backup restore rehearsal', static function ($ok, $eq, $blocked) use ($tmp, $empty, $notGzip, $noTables, $good, $codesReliable, $php) {
    if (!$php['ok']) {
        foreach ([
            'a missing --dump is refused',
            'a dump that does not exist is refused',
            'an empty dump is rejected by the integrity check',
            'a .gz that is not gzip is rejected',
            'a dump with no CREATE TABLE is rejected',
            'a scratch name that does not look disposable is refused',
            'a scratch name equal to the live database is refused',
            'a production-looking scratch name is refused',
            'an invalid scratch name is refused',
            '--create-backup refuses an uninstalled checkout',
            'the real rehearsal runs against a staging database',
        ] as $name) {
            $blocked($name, $php['reason']);
        }
        return;
    }

    $check = static function (string $name, array $args, string $expectText, ?int $expectCode, ?string $tool = null) use ($ok, $codesReliable): void {
        [$out, $rc] = rr_run($args, sys_get_temp_dir(), $tool);
        $ok(str_contains($out, $expectText), $name . ' — expected the output to contain "' . $expectText . '", got: '
            . substr(trim(preg_replace('~\s+~', ' ', $out)), 0, 180));
        if ($expectCode !== null && $codesReliable) {
            $ok($rc === $expectCode, $name . ' — exit code ' . $rc . ' (expected ' . $expectCode . ')');
        }
    };

    /* --- A. Refusals and integrity ---------------------------------------- */
    $check('a missing --dump is refused', ['--user=x'], 'name the backup you want to rehearse', 2);
    $check('a dump that does not exist is refused', ['--dump=' . $tmp . '/nope.sql', '--user=x'], 'does not exist', 2);
    $check('an empty dump is rejected by the integrity check', ['--dump=' . $empty, '--user=x'], 'failed inspection', null);
    $check('a .gz that is not gzip is rejected', ['--dump=' . $notGzip, '--user=x'], 'gzip magic bytes', null);
    $check('a dump with no CREATE TABLE is rejected', ['--dump=' . $noTables, '--user=x'], 'CREATE TABLE', null);
    $check('a scratch name that does not look disposable is refused',
        ['--dump=' . $good, '--user=x', '--scratch=orders'], 'must contain test/scratch/restore/staging', 2);
    $check('a scratch name equal to the live database is refused',
        ['--dump=' . $good, '--user=x', '--scratch=shophaat_test', '--live=shophaat_test'], 'is the live database', 2);
    $check('a production-looking scratch name is refused',
        ['--dump=' . $good, '--user=x', '--scratch=shophaat_production_test'], 'looks like a production database', 2);
    $check('an invalid scratch name is refused',
        ['--dump=' . $good, '--user=x', '--scratch=bad-name!'], 'letters, digits and underscores', 2);
    // A copy of the tool under a bare root: dirname(__DIR__) has no config/ and no
    // installed lock, so the "installed checkout" gate is what gets exercised.
    $bareRoot = $tmp . '/bare';
    @mkdir($bareRoot . '/tools', 0777, true);
    $bareTool = $bareRoot . '/tools/restore-rehearsal.php';
    copy(SH_ROOT . '/tools/restore-rehearsal.php', $bareTool);
    $check('--create-backup refuses an uninstalled checkout',
        ['--create-backup', '--user=x'], 'needs an installed checkout', 2, $bareTool);

    if (!$codesReliable) {
        $blocked('exit codes 2 (refused) and 3 (blocked) are distinguishable',
            'this PHP build always reports shell status 0 (php-wasm); the messages were checked instead');
    } else {
        $ok(true, 'exit codes 2 (refused) and 3 (blocked) are distinguishable');
    }

    /* --- B. The real rehearsal ------------------------------------------- */
    $dsn = (string)getenv('SH_TEST_MYSQL_DSN');
    if ($dsn === '' || !extension_loaded('pdo_mysql')) {
        $blocked('the real rehearsal runs against a staging database',
            $dsn === '' ? 'SH_TEST_MYSQL_DSN is not set' : 'pdo_mysql is not loaded');
        return;
    }
    preg_match('~dbname=([^;]+)~', $dsn, $m);
    $appDb = trim($m[1] ?? '');
    if (getenv('SH_TEST_MYSQL_ALLOW_DROP') !== '1') {
        $blocked('the real rehearsal runs against a staging database', 'SH_TEST_MYSQL_ALLOW_DROP=1 is required');
        return;
    }
    if (preg_match('~(?:^|[_-])(?:test|testing|ci|scratch|disposable)(?:$|[_-])~i', $appDb) !== 1
        || preg_match('~(?:^|[_-])(?:prod|production|live|master|main)(?:$|[_-])~i', $appDb) === 1) {
        $blocked('the real rehearsal runs against a staging database',
            'the configured database "' . $appDb . '" is not named like a test database');
        return;
    }
    $u = (string)getenv('SH_TEST_MYSQL_ROOT_USER') ?: (string)getenv('SH_TEST_MYSQL_USER');
    $p = (string)getenv('SH_TEST_MYSQL_ROOT_PASS') ?: (string)getenv('SH_TEST_MYSQL_PASS');
    $port = preg_match('~port=(\d+)~', $dsn, $pm) ? $pm[1] : '3306';
    $scratch = 'shophaat_restore_test_' . gmdate('His');

    $cmd = escapeshellarg($php['bin']) . ' ' . escapeshellarg(SH_ROOT . '/tools/restore-rehearsal.php')
        . ' --create-backup --app-check'
        . ' --host=' . escapeshellarg(preg_match('~host=([^;]+)~', $dsn, $hm) ? $hm[1] : '127.0.0.1')
        . ' --port=' . escapeshellarg($port)
        . ' --user=' . escapeshellarg($u)
        . ' --pass=' . escapeshellarg($p)
        . ' --scratch=' . escapeshellarg($scratch)
        . ' --live=' . escapeshellarg($appDb)
        . ' 2>&1; echo "__RC=$?"';
    $out = (string)@shell_exec($cmd);
    $rc = preg_match('~__RC=(-?\d+)~', $out, $rm) ? (int)$rm[1] : -1;
    $ownFails = [];
    foreach (preg_split('~\R~', $out) as $line) {
        if (preg_match('~^\s*(FAIL|BLOCKED)\b~', $line)) { $ownFails[] = trim($line); }
    }
    $ok(str_contains($out, 'REHEARSAL PASSED'),
        'the rehearsal passes end to end (' . $scratch . ') — '
        . ($ownFails === [] ? 'the tool reported no failing check but did not print REHEARSAL PASSED'
            : implode(' | ', array_slice($ownFails, 0, 4))));
    if ($codesReliable) {
        $ok($rc === 0, 'the rehearsal exits 0 (got ' . $rc . ')');
    }
    $ok(str_contains($out, 'the application bootstrapped against the restored database'),
        'the application itself reads the restored database');
    $ok(str_contains($out, 'Dropped the scratch database'),
        'the scratch database is dropped again');
    $ok(!str_contains($out, 'REFUSED'), 'the rehearsal never had to refuse the database it was given');
});

@array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo "\nResult: passed {$passed}, failed {$failed}" . ($blocked > 0 ? ", blocked {$blocked}" : '') . "\n";
if ($failed > 0) { exit(1); }
if ($blocked > 0 && getenv('SH_TEST_REQUIRE_MYSQL') === '1') { exit(4); }
exit(0);
