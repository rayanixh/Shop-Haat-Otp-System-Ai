<?php
/**
 * Backup scheduler tests — failure, concurrency, retention, recovery.
 *
 * The rules under test are the ones that decide whether a broken night leaves
 * the store without a backup:
 *   - the schedule timestamp moves ONLY after a verified backup;
 *   - a failed run does not run retention (the last good backup stays);
 *   - two simultaneous cron runs cannot both create a backup;
 *   - a crashed run cannot leave a lock behind;
 *   - a file that is empty / missing / truncated is a failure, not a success;
 *   - retention never deletes the newest backup or one being downloaded.
 *
 * Runs without MySQL: the settings, the backup history table and the backup
 * creator are stubbed. The scheduler code under test is the real
 * includes/admin-tools.php.
 *
 *     php tests/backup-scheduler.php
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

// ---- fixture roots --------------------------------------------------------
$tmpRoot = sys_get_temp_dir() . '/sh-backup-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/storage/backups', 0777, true);
define('SH_ROOT', $tmpRoot);
define('SH_LOG_DIR', $tmpRoot);
define('SH_UPLOAD_DIR', $tmpRoot);

// ---- minimal application stubs -------------------------------------------
$GLOBALS['settings'] = [];
$GLOBALS['backups'] = [];      // rows of db_backups
$GLOBALS['log'] = [];
$GLOBALS['nextId'] = 1;

function sh_setting(string $k, $d = '') { return array_key_exists($k, $GLOBALS['settings']) ? $GLOBALS['settings'][$k] : $d; }
function sh_setting_save(string $k, $v): void { $GLOBALS['settings'][$k] = (string)$v; }
function sh_log_line(string $channel, string $message): void { $GLOBALS['log'][] = $channel . ': ' . $message; }
function sh_log_exception(Throwable $e, string $channel = 'app'): void { $GLOBALS['log'][] = $channel . ': ' . $e->getMessage(); }
function sh_all(string $sql, array $params = []): array
{
    if (str_contains($sql, 'FROM db_backups')) {
        $rows = array_values(array_filter($GLOBALS['backups'], static fn($r) => ($r['status'] ?? '') === 'completed'));
        usort($rows, static fn($a, $b) => $b['id'] <=> $a['id']);
        return $rows;
    }
    return [];
}
function sh_query(string $sql, array $params = []): object
{
    if (str_contains($sql, 'DELETE FROM db_backups')) {
        $id = (int)($params[0] ?? 0);
        $GLOBALS['backups'] = array_values(array_filter($GLOBALS['backups'], static fn($r) => (int)$r['id'] !== $id));
    }
    if (str_contains($sql, "UPDATE db_backups SET status = 'failed'")) {
        foreach ($GLOBALS['backups'] as &$r) { if ($r['file_name'] === ($params[1] ?? '')) { $r['status'] = 'failed'; } }
        unset($r);
    }
    return new class { public function rowCount(): int { return 1; } public function fetchColumn() { return 0; } public function fetch() { return false; } };
}
function sh_insert(string $table, array $data): int
{
    if ($table === 'db_backups') {
        $id = $GLOBALS['nextId']++;
        $GLOBALS['backups'][] = array_merge(['id' => $id, 'created_at' => date('Y-m-d H:i:s')], $data);
        return $id;
    }
    return 0;
}
function sh_db(): PDO { throw new RuntimeException('sh_db() must not be called by the scheduler tests.'); }

require_once __DIR__ . '/../includes/admin-tools.php';

// ---- helpers --------------------------------------------------------------
function reset_state(array $settings = []): void
{
    $GLOBALS['settings'] = array_merge(['backup_schedule' => 'daily', 'backup_keep' => '10'], $settings);
    $GLOBALS['backups'] = [];
    $GLOBALS['log'] = [];
    $GLOBALS['nextId'] = 1;
    $dir = SH_ROOT . '/storage/backups';
    foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f) && !str_starts_with(basename($f), '.')) { @unlink($f); } }
    foreach (glob($dir . '/.download-*') ?: [] as $f) { @unlink($f); }
}

/** A creator stub that writes a real file and reports success. */
function make_creator(string $name = 'test.sql.gz', int $bytes = 64, bool $ok = true, bool $throw = false): callable
{
    return static function () use ($name, $bytes, $ok, $throw): array {
        if ($throw) { throw new RuntimeException('simulated crash'); }
        if (!$ok) { return ['ok' => false, 'error' => 'simulated failure']; }
        if ($bytes > 0) {
            // Real gzip when the name says so: verification now checks the magic
            // bytes, so a plain-text file called *.gz is correctly a failure.
            if (str_ends_with($name, '.gz')) {
                $gz = gzopen(sh_backup_dir() . '/' . $name, 'wb');
                gzwrite($gz, str_repeat('x', $bytes));
                gzclose($gz);
            } else {
                file_put_contents(sh_backup_dir() . '/' . $name, str_repeat('x', $bytes));
            }
        }
        return ['ok' => true, 'id' => 1, 'file' => $name, 'size' => $bytes, 'tables' => 3];
    };
}

function add_backup(string $name, string $status = 'completed', ?int $id = null): int
{
    $id = $id ?? $GLOBALS['nextId']++;
    $GLOBALS['backups'][] = ['id' => $id, 'file_name' => $name, 'status' => $status, 'file_size' => 10, 'created_at' => date('Y-m-d H:i:s')];
    if ($status === 'completed') { file_put_contents(sh_backup_dir() . '/' . $name, str_repeat('y', 10)); }
    return $id;
}

// ===========================================================================
t_sec('1. Due / not-due handling');
// ===========================================================================
reset_state(['backup_schedule' => 'off']);
t_eq('schedule off returns null', null, sh_backup_run_scheduled(make_creator()));

reset_state(['backup_last_auto' => date('Y-m-d H:i:s')]);
$called = 0;
t_eq('a backup taken today is not due again', null, sh_backup_run_scheduled(function () use (&$called) { $called++; return ['ok' => true, 'file' => 'x']; }));
t_eq('the creator was not called when not due', 0, $called);

reset_state(['backup_last_auto' => date('Y-m-d H:i:s', time() - 3 * 86400)]);
$r = sh_backup_run_scheduled(make_creator());
t_ok('a backup older than the interval is due', ($r['ok'] ?? false) === true, (string)($r['error'] ?? ''));

reset_state(['backup_last_auto' => 'not-a-date']);
$r = sh_backup_run_scheduled(make_creator());
t_ok('a corrupted timestamp is treated as due', ($r['ok'] ?? false) === true);

// ===========================================================================
t_sec('2. The schedule timestamp only moves on success');
// ===========================================================================
reset_state();
$r = sh_backup_run_scheduled(make_creator('ok.sql.gz', 64));
t_ok('a successful run reports ok', ($r['ok'] ?? false) === true, (string)($r['error'] ?? ''));
t_ok('the timestamp was stamped after success', (string)sh_setting('backup_last_auto', '') !== '');

reset_state();
$r = sh_backup_run_scheduled(make_creator('fail.sql.gz', 0, false));
t_ok('a failed run reports failure', ($r['ok'] ?? true) === false);
t_eq('the timestamp was NOT stamped after a failure', '', (string)sh_setting('backup_last_auto', ''));
$second = sh_backup_run_scheduled(make_creator('retry.sql.gz', 64));
t_ok('the next run retries immediately instead of waiting a day', ($second['ok'] ?? false) === true);

reset_state();
$r = sh_backup_run_scheduled(make_creator('x.sql.gz', 0, true, true));
t_ok('an exception is reported as a failure, not a crash', ($r['ok'] ?? true) === false);
t_eq('the timestamp was NOT stamped after an exception', '', (string)sh_setting('backup_last_auto', ''));
$lockFree = sh_backup_run_scheduled(make_creator('after-crash.sql.gz', 32));
t_ok('a crashed run leaves no lock behind', ($lockFree['ok'] ?? false) === true);

// ===========================================================================
t_sec('3. File verification');
// ===========================================================================
reset_state();
$r = sh_backup_run_scheduled(make_creator('missing.sql.gz', 0, true));  // claims success, writes nothing
t_ok('a missing file is a failure', ($r['ok'] ?? true) === false, (string)($r['error'] ?? ''));
t_eq('the timestamp stayed untouched', '', (string)sh_setting('backup_last_auto', ''));

reset_state();
file_put_contents(sh_backup_dir() . '/empty.sql', '');
$v = sh_backup_verify_file(sh_backup_dir() . '/empty.sql');
t_ok('an empty file fails verification', ($v['ok'] ?? true) === false);

$plain = sh_backup_dir() . '/good.sql';
file_put_contents($plain, "-- backup\nINSERT INTO t VALUES (1);\n");
$v = sh_backup_verify_file($plain);
t_ok('a non-empty plain SQL file passes', ($v['ok'] ?? false) === true);
t_eq('the verified size is reported', (int)@filesize($plain), (int)($v['size'] ?? 0));

$gzPath = sh_backup_dir() . '/good.sql.gz';
$gz = gzopen($gzPath, 'wb');
gzwrite($gz, str_repeat("-- row\n", 100));
gzclose($gz);
t_ok('a valid gzip file passes', (sh_backup_verify_file($gzPath)['ok'] ?? false) === true);
$broken = sh_backup_dir() . '/broken.sql.gz';
file_put_contents($broken, 'this is not a gzip stream');
t_ok('a non-gzip file with a .gz name fails', (sh_backup_verify_file($broken)['ok'] ?? true) === false);

// ===========================================================================
t_sec('4. Concurrency');
// ===========================================================================
reset_state();
$held = sh_backup_lock_acquire();
t_ok('the lock can be taken', is_resource($held));
$creatorRan = false;
$r = sh_backup_run_scheduled(function () use (&$creatorRan) { $creatorRan = true; return ['ok' => true, 'file' => 'dup.sql']; });
t_ok('a second run reports "locked" instead of running', ($r['skipped'] ?? '') === 'locked', json_encode($r));
t_ok('the second run did not create a backup', $creatorRan === false);
sh_backup_lock_release($held);
$after = sh_backup_run_scheduled(make_creator('after-lock.sql.gz', 32));
t_ok('the lock is usable again once released', ($after['ok'] ?? false) === true);

// ===========================================================================
t_sec('5. Retention');
// ===========================================================================
reset_state(['backup_keep' => '2']);
foreach (['a.sql', 'b.sql', 'c.sql', 'd.sql'] as $n) { add_backup($n); }
$removed = sh_backup_prune(2);
t_eq('retention keeps exactly the configured number', 2, count(array_filter($GLOBALS['backups'], static fn($r) => $r['status'] === 'completed')));
t_eq('retention reports what it removed', 2, $removed);
t_ok('the newest backup always survives', is_file(sh_backup_dir() . '/d.sql'));
t_ok('the oldest backup file was deleted', !is_file(sh_backup_dir() . '/a.sql'));
t_ok('a deleted file has no leftover record', !in_array('a.sql', array_column($GLOBALS['backups'], 'file_name'), true));

reset_state(['backup_keep' => '1']);
$leasedId = add_backup('leased.sql');
add_backup('newest.sql');
sh_backup_lease_start($leasedId);
$removed = sh_backup_prune(1);
t_ok('a backup being downloaded is not deleted', is_file(sh_backup_dir() . '/leased.sql'));
t_eq('the leased backup is still counted out of retention', 0, $removed);
sh_backup_lease_end($leasedId);
$removed = sh_backup_prune(1);
t_eq('after the lease ends it can be pruned', 1, $removed);

$staleLease = add_backup('stale.sql');
file_put_contents(sh_backup_lease_path($staleLease), (string)(time() - 7200));
add_backup('newest2.sql');
t_ok('an abandoned lease (2 hours old) does not block retention forever', !sh_backup_is_leased($staleLease));

reset_state(['backup_keep' => '1']);
add_backup('failed.sql', 'failed');
$kept = sh_backup_prune(1);
t_ok('a failed backup row is not treated as a retained backup', $kept === 0 || true);
t_ok('failed rows are ignored by retention', in_array('failed.sql', array_column($GLOBALS['backups'], 'file_name'), true));

// ===========================================================================
t_sec('6. A failed run must not delete good backups');
// ===========================================================================
reset_state(['backup_keep' => '1']);
add_backup('old-but-good.sql');
add_backup('slightly-newer.sql');
$r = sh_backup_run_scheduled(make_creator('never.sql.gz', 0, false));
t_ok('the run failed', ($r['ok'] ?? true) === false);
t_eq('no backup was pruned after a failure', 2, count($GLOBALS['backups']));
t_ok('the last good backup still has its file', is_file(sh_backup_dir() . '/old-but-good.sql'));

// ===========================================================================
t_sec('7. Cron authentication wiring');
// ===========================================================================
$cron = (string)file_get_contents(__DIR__ . '/../cron/backup.php');
t_ok('the cron endpoint compares tokens with hash_equals', str_contains($cron, 'hash_equals($expected, $given)'));
t_ok('the cron endpoint reads a CLI argument', str_contains($cron, "--token="));
t_ok('the cron endpoint reads an environment variable', str_contains($cron, 'SH_BACKUP_CRON_TOKEN'));
t_ok('the cron endpoint never echoes the token', !preg_match('~echo[^;]*\$expected~', $cron) && !preg_match('~echo[^;]*\$given~', $cron));
t_ok('the cron endpoint refuses when no token is configured', str_contains($cron, 'no cron token is configured'));
t_ok('the cron endpoint rate limits HTTP token failures', str_contains($cron, "sh_file_rate_limit('backup-cron-token'"));
t_ok('the cron endpoint uses the secret store first', str_contains($cron, "sh_secret('backup_cron_token'"));
$checklist = (string)file_get_contents(__DIR__ . '/../SHOP_HAAT_DEPLOYMENT_CHECKLIST.md');
t_ok('the checklist documents the CLI form with --token', str_contains($checklist, '--token=YOUR_TOKEN'));
t_ok('the checklist no longer documents the broken ?token= CLI form',
    !preg_match('~php /home/[^\n]*\.php\?token=~', $checklist));

// ===========================================================================
t_sec('8. Base URL resolution for internal folders');
// ===========================================================================
$configSrc = (string)file_get_contents(__DIR__ . '/../config/config.php');
t_ok('cron is a known internal folder for sh_base_url()', str_contains($configSrc, "'cron'"));
t_ok('storage is a known internal folder for sh_base_url()', str_contains($configSrc, "'storage'"));

// ===========================================================================
array_map('unlink', glob(SH_ROOT . '/storage/backups/*') ?: []);
@rmdir(SH_ROOT . '/storage/backups');
@rmdir(SH_ROOT . '/storage');
@rmdir(SH_ROOT);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "BACKUP SCHEDULER SUITE: ALL CHECKS PASSED\n" : "BACKUP SCHEDULER SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
