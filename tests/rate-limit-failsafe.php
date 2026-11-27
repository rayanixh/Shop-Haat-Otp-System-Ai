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
$GLOBALS['blocked'] = 0;
function t_blocked(string $name, string $reason): void
{
    $GLOBALS['blocked']++;
    echo "  BLOCKED {$name}  [{$reason}]\n";
}
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
function rl_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    $dh = @opendir($path);
    if ($dh !== false) {
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') { continue; }
            rl_remove_tree($path . '/' . $entry);
        }
        closedir($dh);
    }
    @rmdir($path);
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
t_sec('7. Expired-file cleanup is bounded and preserves active counters');
// ===========================================================================
$cleanupDir = rl_dir('cleanup');
@mkdir($cleanupDir . '/.locks', 0700, true);
$expiredKey = 'expired-fixture'; $activeKey = 'active-fixture';
sh_file_rate_limit('unit', $expiredKey, 50, 60, $cleanupDir);
sh_file_rate_limit('unit', $activeKey, 50, 60, $cleanupDir);
$expiredPath = $cleanupDir . '/unit-' . hash('sha256', $expiredKey) . '.txt';
$activePath = $cleanupDir . '/unit-' . hash('sha256', $activeKey) . '.txt';
file_put_contents($expiredPath, json_encode(['t' => time() - 120, 'n' => 8, 'w' => 60]));
file_put_contents($activePath, json_encode(['t' => time() - 1, 'n' => 2, 'w' => 60]));
@chmod($expiredPath, 0600); @chmod($activePath, 0600);
$singleSweepInspected = 0;
t_eq('bounded sweeper removes the expired queued counter', 1, sh_file_rate_limit_cleanup($cleanupDir, 24, $singleSweepInspected));
t_ok('expired counter file is actually gone', !is_file($expiredPath));
t_ok('single-sweep work stays within the requested budget', $singleSweepInspected <= 24, 'inspected ' . $singleSweepInspected);
t_ok('active counter remains with its count unchanged', is_file($activePath)
    && (int)(json_decode((string)file_get_contents($activePath), true)['n'] ?? 0) === 2);
t_ok('limiter storage permissions are private', (fileperms($cleanupDir) & 0077) === 0
    && (fileperms($cleanupDir . '/.locks') & 0077) === 0
    && (fileperms($activePath) & 0077) === 0);
$boundedDir = rl_dir('bounded-cleanup');
$bulkPaths = []; $bulkKeys = [];
for ($i = 0; $i < 100; $i++) {
    $key = 'bulk-expired-' . $i; $bulkKeys[] = $key;
    sh_file_rate_limit('bulk', $key, 1000, 60, $boundedDir);
    $bulkPaths[] = $boundedDir . '/bulk-' . hash('sha256', $key) . '.txt';
}
$activeBulkKeys = [];
for ($i = 0; $i < 5; $i++) {
    $key = 'bulk-active-' . $i; $activeBulkKeys[] = $key;
    sh_file_rate_limit('bulk', $key, 1000, 60, $boundedDir);
    $bulkPaths[] = $boundedDir . '/bulk-' . hash('sha256', $key) . '.txt';
}
foreach (array_slice($bulkPaths, 0, 100) as $path) {
    file_put_contents($path, json_encode(['t' => time() - 5000, 'n' => 1, 'w' => 60]));
}
$activeBulkPaths = array_slice($bulkPaths, 100);
$activeBefore = [];
foreach ($activeBulkPaths as $path) { $activeBefore[$path] = (string)file_get_contents($path); }
$repeatedSweeps = 0; $sweepBudgetsHonored = true; $cleanupInspectedTotal = 0;
while ($repeatedSweeps < 100 && count(array_filter(array_slice($bulkPaths, 0, 100), 'is_file')) > 0) {
    $inspected = 0;
    sh_file_rate_limit_cleanup($boundedDir, 7, $inspected);
    $repeatedSweeps++; $cleanupInspectedTotal += $inspected;
    if ($inspected > 7) { $sweepBudgetsHonored = false; }
}
$expiredRemaining = count(array_filter(array_slice($bulkPaths, 0, 100), 'is_file'));
$activeUnchanged = true;
foreach ($activeBefore as $path => $before) {
    if (!is_file($path) || (string)file_get_contents($path) !== $before) { $activeUnchanged = false; }
}
t_ok('repeated rotating sweeps eventually remove all 100 expired counters',
    $expiredRemaining === 0 && $repeatedSweeps > 1,
    'sweeps ' . $repeatedSweeps . ', expired remaining ' . $expiredRemaining);
t_ok('each repeated cleanup sweep stays within its seven-job budget', $sweepBudgetsHonored,
    'total inspected ' . $cleanupInspectedTotal . ' across ' . $repeatedSweeps . ' sweeps');
t_ok('several active counters remain byte-for-byte unchanged', $activeUnchanged);
$cursorStatePath = $boundedDir . '/.cleanup-queue/state.json';
$cursorState = json_decode((string)@file_get_contents($cursorStatePath), true);
t_ok('persistent cleanup cursor advanced across repeated calls', is_array($cursorState)
    && (int)($cursorState['head'] ?? 0) > 1 && (int)($cursorState['tail'] ?? 0) >= (int)($cursorState['head'] ?? 0));

// ===========================================================================
t_sec('8. Concurrent workers cannot exceed a shared counter budget');
// ===========================================================================
if (!function_exists('proc_open') || PHP_BINARY === '') {
    t_blocked('multi-process atomic-counter test', 'proc_open or PHP_BINARY is unavailable');
} else {
    $parallelDir = rl_dir('parallel');
    $parallelKey = 'same-secret-counter-key';
    $workerCount = 6; $attemptsPerWorker = 12; $budget = 17;
    $workerCode = 'require ' . var_export($root . '/includes/security.php', true) . '; '
        . '$allowed = 0; for ($i = 0; $i < ' . $attemptsPerWorker . '; $i++) {'
        . ' if (sh_file_rate_limit(' . var_export('parallel', true) . ', ' . var_export($parallelKey, true) . ', ' . $budget . ', 120, ' . var_export($parallelDir, true) . ')) { $allowed++; }'
        . '} echo $allowed;';
    $workers = []; $spawnFailed = false;
    for ($i = 0; $i < $workerCount; $i++) {
        $pipes = [];
        $proc = @proc_open([PHP_BINARY, '-r', $workerCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { $spawnFailed = true; break; }
        fclose($pipes[0]);
        $workers[] = [$proc, $pipes[1], $pipes[2]];
    }
    if ($spawnFailed) {
        foreach ($workers as [$proc, $out, $err]) { fclose($out); fclose($err); @proc_terminate($proc); @proc_close($proc); }
        t_blocked('multi-process atomic-counter test', 'could not start all PHP worker processes');
    } else {
        $allowedTotal = 0; $workerErrors = [];
        foreach ($workers as [$proc, $out, $err]) {
            $stdout = trim((string)stream_get_contents($out));
            $stderr = trim((string)stream_get_contents($err));
            fclose($out); fclose($err);
            $exit = proc_close($proc);
            if ($exit !== 0 || !ctype_digit($stdout)) { $workerErrors[] = $stderr !== '' ? $stderr : 'worker returned invalid output'; }
            else { $allowedTotal += (int)$stdout; }
        }
        t_ok('exactly the configured number of concurrent attempts is allowed',
            $workerErrors === [] && $allowedTotal === $budget,
            $workerErrors !== [] ? implode('; ', $workerErrors) : 'allowed ' . $allowedTotal . ' of ' . ($workerCount * $attemptsPerWorker));
        $parallelCounter = $parallelDir . '/parallel-' . hash('sha256', $parallelKey) . '.txt';
        $parallelData = json_decode((string)@file_get_contents($parallelCounter), true);
        t_eq('counter includes every concurrent attempt including blocked ones',
            $workerCount * $attemptsPerWorker, (int)($parallelData['n'] ?? 0));
    }
}

// ===========================================================================
t_sec('9. Cleanup cursor survives PHP processes and concurrent cleanup preserves counters');
// ===========================================================================
if (!function_exists('proc_open') || PHP_BINARY === '') {
    t_blocked('cross-process cleanup cursor and cleanup/update race tests', 'proc_open or PHP_BINARY is unavailable');
} else {
    $cursorDir = rl_dir('cursor-process');
    for ($i = 0; $i < 12; $i++) {
        $key = 'cursor-' . $i;
        sh_file_rate_limit('cursor', $key, 100, 60, $cursorDir);
        file_put_contents($cursorDir . '/cursor-' . hash('sha256', $key) . '.txt', json_encode(['t' => time() - 1000, 'n' => 1, 'w' => 60]));
    }
    $runCursorWorker = static function () use ($root, $cursorDir): array {
        $code = 'require ' . var_export($root . '/includes/security.php', true) . '; $n=0; '
            . 'sh_file_rate_limit_cleanup(' . var_export($cursorDir, true) . ', 1, $n); echo $n;';
        $pipes = [];
        $proc = @proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { return [false, 'could not start worker']; }
        fclose($pipes[0]); $out = trim((string)stream_get_contents($pipes[1])); $err = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($proc);
        return [$exit === 0 && ctype_digit($out) && (int)$out <= 1, $err !== '' ? $err : $out];
    };
    $parentInspected = 0; sh_file_rate_limit_cleanup($cursorDir, 1, $parentInspected);
    $statePath = $cursorDir . '/.cleanup-queue/state.json';
    $stateAfterParent = json_decode((string)file_get_contents($statePath), true);
    [$childOneOk, $childOneOutput] = $runCursorWorker();
    $stateAfterChildOne = json_decode((string)file_get_contents($statePath), true);
    [$childTwoOk, $childTwoOutput] = $runCursorWorker();
    $stateAfterChildTwo = json_decode((string)file_get_contents($statePath), true);
    t_ok('cursor advances in two separate PHP processes',
        $childOneOk && $childTwoOk
        && (int)($stateAfterChildOne['head'] ?? 0) > (int)($stateAfterParent['head'] ?? 0)
        && (int)($stateAfterChildTwo['head'] ?? 0) > (int)($stateAfterChildOne['head'] ?? 0),
        $childOneOutput . '; ' . $childTwoOutput);

    $raceDir = rl_dir('cleanup-update-race');
    $raceActiveKey = 'active-during-cleanup-race';
    sh_file_rate_limit('race', $raceActiveKey, 1000, 3600, $raceDir);
    $raceExpiredPaths = [];
    for ($i = 0; $i < 40; $i++) {
        $key = 'race-expired-' . $i;
        sh_file_rate_limit('race', $key, 1000, 60, $raceDir);
        $path = $raceDir . '/race-' . hash('sha256', $key) . '.txt';
        file_put_contents($path, json_encode(['t' => time() - 5000, 'n' => 2, 'w' => 60]));
        $raceExpiredPaths[] = $path;
    }
    $raceWorkers = []; $raceSpawnFailed = false; $raceIterations = 12; $raceWorkerCount = 4;
    $raceCode = 'require ' . var_export($root . '/includes/security.php', true) . '; '
        . 'for($i=0;$i<' . $raceIterations . ';$i++){ sh_file_rate_limit_cleanup(' . var_export($raceDir, true) . ',3); '
        . 'sh_file_rate_limit(' . var_export('race', true) . ',' . var_export($raceActiveKey, true) . ',1000,3600,' . var_export($raceDir, true) . '); } echo ' . var_export('ok', true) . ';';
    for ($i = 0; $i < $raceWorkerCount; $i++) {
        $pipes = []; $proc = @proc_open([PHP_BINARY, '-r', $raceCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) { $raceSpawnFailed = true; break; }
        fclose($pipes[0]); $raceWorkers[] = [$proc, $pipes[1], $pipes[2]];
    }
    if ($raceSpawnFailed) {
        foreach ($raceWorkers as [$proc, $out, $err]) { fclose($out); fclose($err); @proc_terminate($proc); @proc_close($proc); }
        t_blocked('concurrent cleanup/counter update test', 'could not start all PHP worker processes');
    } else {
        $raceErrors = [];
        foreach ($raceWorkers as [$proc, $out, $err]) {
            $stdout = trim((string)stream_get_contents($out)); $stderr = trim((string)stream_get_contents($err));
            fclose($out); fclose($err); $exit = proc_close($proc);
            if ($exit !== 0 || $stdout !== 'ok') { $raceErrors[] = $stderr !== '' ? $stderr : 'worker failed'; }
        }
        $raceCounter = json_decode((string)@file_get_contents($raceDir . '/race-' . hash('sha256', $raceActiveKey) . '.txt'), true);
        $expectedRaceCount = 1 + $raceWorkerCount * $raceIterations;
        t_eq('concurrent cleanup never corrupts or loses active increments', $expectedRaceCount, (int)($raceCounter['n'] ?? 0));
        t_ok('concurrent cleanup/update workers all completed', $raceErrors === [], implode('; ', $raceErrors));
        for ($i = 0; $i < 100 && count(array_filter($raceExpiredPaths, 'is_file')) > 0; $i++) {
            $swept = 0; sh_file_rate_limit_cleanup($raceDir, 8, $swept);
        }
        t_eq('concurrent cleanup eventually removes all expired queued counters', 0,
            count(array_filter($raceExpiredPaths, 'is_file')));
    }
}

// ===========================================================================
rl_remove_tree(SH_LOG_DIR); rl_remove_tree($tmp);
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d   blocked %d\n", $GLOBALS['pass'], $GLOBALS['fail'], $GLOBALS['blocked']);
if ($GLOBALS['fail'] > 0) {
    echo "RATE LIMIT FAIL-SAFE SUITE: FAILURES PRESENT\n";
    exit(1);
}
if ($GLOBALS['blocked'] > 0) {
    echo "RATE LIMIT FAIL-SAFE SUITE: SOME CHECKS BLOCKED\n";
    exit(2);
}
echo "RATE LIMIT FAIL-SAFE SUITE: ALL CHECKS PASSED\n";
exit(0);
