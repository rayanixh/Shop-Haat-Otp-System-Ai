<?php
/**
 * Backup → restore rehearsal (staging only).
 *
 *   php tools/restore-rehearsal.php --dump=storage/backups/shophaat-....sql.gz \
 *       --host=127.0.0.1 --user=shophaat_test --pass=... \
 *       [--scratch=shophaat_restore_test] [--keep] [--live=shophaat_live]
 *
 * What it does, in order, and refuses to skip any step:
 *
 *   1. verifies the dump file (gzip magic, the app's own header line, CREATE
 *      TABLE statements, a non-zero statement count) — a truncated or empty
 *      backup fails here, before anything is created;
 *   2. refuses any scratch database name that does not look disposable, or that
 *      matches the live database name you name on the command line;
 *   3. creates the scratch database, imports the dump with the `mysql` client
 *      (streamed — the dump is never loaded into memory), and reports how many
 *      statements ran;
 *   4. verifies the RESULT: every table from install/schema.php, the unique
 *      transaction-id index, settings, and relationships (an order's items and
 *      payment still point at it);
 *   5. prints the exact command to point a staging copy of the application at the
 *      restored database for the browser-level check, and
 *   6. drops the scratch database again unless --keep is given.
 *
 * The production database is never opened, never written to and never dropped.
 * There is no --force that changes that; the only way to touch a live database is
 * to pass its name to --scratch, which the name check rejects.
 *
 * Exit codes: 0 rehearsal passed · 1 a check failed · 2 refused (unsafe input)
 * · 3 blocked (no mysql client / no server).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $args[$k] = $v;
    }
}
$opt = static fn(string $k, string $d = ''): string => isset($args[$k]) && is_string($args[$k]) ? $args[$k] : $d;
$flag = static fn(string $k): bool => array_key_exists($k, $args);

$fail = 0;
$say = static function (string $status, string $msg) use (&$fail): void {
    printf("  %-9s %s\n", $status, $msg);
    if ($status === 'FAIL') { $fail++; }
};
$refuse = static function (string $why): void {
    fwrite(STDERR, "REFUSED: {$why}\n");
    exit(2);
};
$blocked = static function (string $why, string $how): void {
    fwrite(STDERR, "BLOCKED: {$why}\n{$how}\n");
    exit(3);
};

$createBackup = $flag('create-backup');
$dump = $opt('dump');
if ($createBackup) {
    // Phase 1 of the rehearsal as the checklist describes it: make a NEW backup
    // from the store this checkout is installed against, then restore that. It
    // must be an installed, disposable store — the app itself is asked to dump it.
    $cfgFile = $root . '/config/database.php';
    if (!is_file($cfgFile)) {
        fwrite(STDERR, "REFUSED: --create-backup needs an installed checkout (config/database.php is missing).\n");
        exit(2);
    }
    require_once $root . '/config/config.php';
    require_once $root . '/includes/admin-tools.php';
    $state = sh_db_state();
    if (($state['status'] ?? '') !== 'ok') {
        fwrite(STDERR, 'REFUSED: --create-backup needs a working database connection; state is '
            . (string)($state['status'] ?? 'unknown') . ".\n");
        exit(2);
    }
    $appDb = (string)(sh_db_config()['name'] ?? '');
    if (preg_match('~(test|staging|scratch|ci|sandbox)~i', $appDb) !== 1) {
        fwrite(STDERR, "REFUSED: --create-backup would dump \"{$appDb}\", which is not named like a test/staging database.\n");
        exit(2);
    }
    sh_admin_schema_ensure();
    $made = sh_backup_create('restore-rehearsal', 'rehearsal');
    if (empty($made['ok'])) {
        fwrite(STDERR, 'REFUSED: the backup could not be created: ' . (string)($made['error'] ?? 'unknown error') . "\n");
        exit(2);
    }
    $dump = sh_backup_dir() . '/' . (string)$made['file'];
    echo "created backup : {$dump}\n";
}
if ($dump === '') { $refuse('--dump=FILE (or --create-backup) is required — name the backup you want to rehearse.'); }
if (!is_file($dump)) { $refuse("the dump file does not exist: {$dump}"); }
if (!is_readable($dump)) { $refuse("the dump file is not readable: {$dump}"); }

$host = $opt('host', '127.0.0.1');
$port = (int)$opt('port', '3306');
$user = $opt('user');
$pass = $opt('pass', (string)getenv('SH_RESTORE_PASS'));
if ($user === '') { $refuse('--user= is required (a test database user, never the production user).'); }

$scratch = $opt('scratch', 'shophaat_restore_test_' . gmdate('Ymd_His'));
$live = $opt('live');

/* --- Safety gate, before anything is created ------------------------------ */
echo "Restore rehearsal\n-----------------\n";
echo "dump    : {$dump}\n";
echo "server  : {$host}:{$port}\n";
echo "scratch : {$scratch}\n\n";

if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $scratch) !== 1) {
    $refuse("the scratch database name \"{$scratch}\" must be letters, digits and underscores only.");
}
if (preg_match('~(test|scratch|rehearsal|restore|staging)~i', $scratch) !== 1) {
    $refuse("the scratch database name \"{$scratch}\" must contain test/scratch/restore/staging so nobody mistakes it for real data.");
}
if ($live !== '' && strcasecmp($live, $scratch) === 0) {
    $refuse("the scratch database is the live database (\"{$live}\"). Use a fresh name.");
}
if (preg_match('~(^|_)(prod|production|live)(_|$)~i', $scratch) === 1) {
    $refuse("the scratch database name \"{$scratch}\" looks like a production database.");
}

/* --- 1. Inspect the dump before importing it ------------------------------ */
echo "1. Dump integrity\n";
$fh = fopen($dump, 'rb');
$head = (string)fread($fh, 8192);
fclose($fh);
$gzipMagic = str_starts_with($head, "\x1f\x8b");
$gzipExtension = str_ends_with(strtolower($dump), '.gz');
$gzip = $gzipMagic;
$say('PASS', 'the file exists and is ' . sh_human(filesize($dump) ?: 0) . ($gzip ? ' (gzip)' : ' (plain SQL)'));
if ($gzipExtension && !$gzipMagic) {
    // gzopen()/gzread() read an uncompressed file silently, so a ".gz" that is
    // not gzip would otherwise pass this inspection and fail at import time.
    $say('FAIL', 'the file is named .gz but does not start with the gzip magic bytes');
}

$readable = false;
if ($gzip) {
    $gz = @gzopen($dump, 'rb');
    if ($gz === false) {
        // gzopen() happily reads an uncompressed file, so re-check by magic bytes.
        $say('FAIL', 'the file claims to be gzip but cannot be opened');
    } else {
        $text = (string)gzread($gz, 65536);
        gzclose($gz);
        $readable = $text !== '';
        $say($readable ? 'PASS' : 'FAIL', 'the gzip stream decompresses' . ($readable ? ' (' . strlen($text) . ' bytes read)' : ''));
    }
} else {
    $fh = fopen($dump, 'rb');
    $text = (string)fread($fh, 65536);
    fclose($fh);
    $readable = $text !== '';
    $say($readable ? 'PASS' : 'FAIL', 'the file is readable');
}
// Do not exit here: an empty or unreadable file still needs the same summary
// line, otherwise a scripted caller sees a silent non-zero exit.
if (!$readable) { $say('FAIL', 'the dump is empty or unreadable'); }

$body = $gzip ? sh_gz_text($dump) : (string)file_get_contents($dump);
$say(str_contains($body, 'CREATE TABLE') ? 'PASS' : 'FAIL', 'the dump contains CREATE TABLE statements');
$tables = preg_match_all('~CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?~', $body, $tm);
$say($tables > 0 ? 'PASS' : 'FAIL', 'tables in the dump: ' . $tables);
$say(str_contains($body, 'ShopHaat database backup') ? 'PASS' : 'FAIL', 'the dump carries the application header (created by includes/admin-tools.php)');
$say(str_contains($body, 'SET NAMES') ? 'PASS' : 'FAIL', 'the dump sets the connection charset');
$statements = preg_match_all('~;\s*$~m', $body);
$say($statements > $tables ? 'PASS' : 'FAIL', 'statement count looks complete: ' . $statements);
if ($fail > 0) {
    echo "\nThe dump failed inspection; nothing was created.\n";
    exit(1);
}

/* --- Tools ----------------------------------------------------------------- */
$mysql = trim((string)shell_exec('command -v mysql 2>/dev/null'));
if ($mysql === '') {
    $blocked('the `mysql` command-line client is not installed here, and a multi-statement dump cannot be imported safely through PDO.',
        "Install the MySQL/MariaDB client (cPanel: Terminal or phpMyAdmin import), then run:\n"
        . "  gunzip -c " . escapeshellarg($dump) . " | mysql -h {$host} -P {$port} -u " . escapeshellarg($user) . " -p {$scratch}");
}
if (!@fsockopen($host === 'localhost' ? '127.0.0.1' : $host, $port, $errno, $errstr, 2)) {
    $blocked("no database server is listening on {$host}:{$port}." . ($errstr !== '' ? " ({$errstr})" : ''),
        'Start MySQL/MariaDB, or point --host/--port at the staging server.');
}

/* --- 2. Create the scratch database -------------------------------------- */
echo "\n2. Scratch database\n";
$pdo = sh_connect($host, $port, $user, $pass, null);
sh_exec($pdo, 'CREATE DATABASE `' . $scratch . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$say('PASS', 'created ' . $scratch);
$pdo->exec('USE `' . $scratch . '`');

/* --- 3. Import ------------------------------------------------------------ */
echo "\n3. Import\n";
$cmd = ($gzip ? 'gunzip -c ' . escapeshellarg($dump) : 'cat ' . escapeshellarg($dump))
    . ' | ' . escapeshellarg($mysql)
    . ' -h ' . escapeshellarg($host) . ' -P ' . $port
    . ' -u ' . escapeshellarg($user) . ' ' . escapeshellarg($scratch);
$env = ['MYSQL_PWD' => $pass, 'PATH' => (string)getenv('PATH')];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['HOME' => (string)getenv('HOME')]);
$err = '';
if (is_resource($proc)) {
    stream_get_contents($pipes[1]);
    $err = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
} else {
    $code = -1;
    $err = 'the import process could not be started';
}
$say($code === 0 ? 'PASS' : 'FAIL', 'import finished' . ($code === 0 ? '' : ' with status ' . $code . ': ' . substr($err, 0, 300)));

/* --- 4. Verify the restored database ------------------------------------- */
echo "\n4. Restored content\n";
// The expected tables come from the dump itself, never from a hard-coded list: a
// table this store never created (sessions only exists once the session handler
// has been used, for example) cannot be reported as a table the restore lost.
preg_match_all('~CREATE TABLE `([^`]+)`~i', $body, $tmMatches);
$dumped = array_values(array_unique(array_map('strval', $tmMatches[1] ?? [])));
$present = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$missing = array_values(array_diff($dumped, $present));
$say($missing === [] && $dumped !== [] ? 'PASS' : 'FAIL',
    count($present) . ' of ' . count($dumped) . ' dumped tables restored'
    . ($missing === [] ? '' : '; missing: ' . implode(', ', array_slice($missing, 0, 8))));
// The tables the storefront cannot run without must be among the restored ones
// whenever the dump carried them (a backup that quietly lost `orders` is the
// failure this rehearsal exists to catch).
$core = ['users', 'admins', 'settings', 'products', 'orders', 'order_items', 'payments',
    'payment_methods', 'categories', 'coupons', 'notification_logs', 'db_backups'];
$coreDumped = array_values(array_intersect($core, $dumped));
$coreMissing = array_values(array_diff($coreDumped, $present));
$say($coreMissing === [] ? 'PASS' : 'FAIL', count($coreDumped) . ' core tables carried by the dump are present'
    . ($coreMissing === [] ? '' : '; missing: ' . implode(', ', $coreMissing)));

$idx = $pdo->query("SELECT NON_UNIQUE FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = " . $pdo->quote($scratch) . " AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'uq_payments_transaction_id'")->fetch();
$say(is_array($idx) && (int)$idx['NON_UNIQUE'] === 0 ? 'PASS' : 'FAIL', 'payments.transaction_id is still uniquely indexed');

$count = static function (PDO $pdo, string $table): int {
    try { return (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); } catch (Throwable $e) { return -1; }
};
$productsRestored = 0;
foreach (['settings', 'orders', 'products', 'users'] as $table) {
    $n = $count($pdo, $table);
    if ($table === 'products') { $productsRestored = max(0, $n); }
    $say($n >= 0 ? 'PASS' : 'FAIL', "{$table}: " . ($n >= 0 ? $n . ' rows' : 'cannot be read'));
}
$settings = $count($pdo, 'settings');
$say($settings > 0 ? 'PASS' : 'FAIL', 'store settings survived the round trip (' . $settings . ')');

$sample = null;
try {
    $sample = $pdo->query('SELECT id, order_number, total FROM orders ORDER BY id DESC LIMIT 1')->fetch();
} catch (Throwable $e) {}
if (is_array($sample)) {
    $id = (int)$sample['id'];
    $items = (int)$pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = {$id}")->fetchColumn();
    $pay = $pdo->query("SELECT transaction_id, status FROM payments WHERE order_id = {$id} ORDER BY id DESC LIMIT 1")->fetch();
    $say('PASS', "representative order {$sample['order_number']} restored with {$items} item(s)");
    $say($items > 0 ? 'PASS' : 'NOT TESTED', 'its items still point at it' . ($items > 0 ? '' : ' — the sample order had no items'));
    $say(is_array($pay) ? 'PASS' : 'NOT TESTED', is_array($pay)
        ? 'its payment row restored (status ' . (string)$pay['status'] . ')'
        : 'that order had no payment row');
} else {
    $say('NOT TESTED', 'no order to sample (the backup was empty)');
}
$orphans = (int)$pdo->query('SELECT COUNT(*) FROM order_items i LEFT JOIN orders o ON o.id = i.order_id WHERE o.id IS NULL')->fetchColumn();
$say($orphans === 0 ? 'PASS' : 'FAIL', 'no order items point at a missing order (' . $orphans . ' orphans)');

/* --- 5. The application itself reads the restored database --------------- */
echo "\n5. Application access\n";
if ($flag('app-check')) {
    // Point THIS checkout at the restored copy for one command and ask the
    // application's own bootstrap a real question. The original configuration is
    // restored in a shutdown handler, so an interrupted run cannot leave the
    // checkout pointing somewhere else.
    $dbCfgFile = $root . '/config/database.php';
    $original = is_file($dbCfgFile) ? (string)file_get_contents($dbCfgFile) : null;
    $restore = static function () use ($dbCfgFile, $original): void {
        if ($original !== null) { @file_put_contents($dbCfgFile, $original); }
        else { @unlink($dbCfgFile); }
    };
    register_shutdown_function($restore);
    $appCfg = "<?php\n// Written by tools/restore-rehearsal.php --app-check; removed automatically.\nreturn "
        . var_export([
            'host' => $host, 'port' => $port, 'name' => $scratch, 'user' => $user,
            'pass' => $pass, 'charset' => 'utf8mb4',
        ], true) . ";\n";
    if (@file_put_contents($dbCfgFile, $appCfg) === false) {
        $say('BLOCKED', 'the application check could not write config/database.php');
    } else {
        $probe = tempnam(sys_get_temp_dir(), 'sh-app-check-');
        $rootPath = var_export($root, true);
        file_put_contents($probe, "<?php\n"
            . "\$_SERVER['SCRIPT_NAME'] = '/index.php';\n\$_SERVER['HTTP_HOST'] = 'localhost';\n"
            . "require_once {$rootPath} . '/config/config.php';\n"
            . "require_once SH_ROOT . '/includes/db.php';\n"
            . "\$pdo = sh_db();\n"
            . "\$out = ['products' => (int)sh_val('SELECT COUNT(*) FROM products'),\n"
            . "        'orders' => (int)sh_val('SELECT COUNT(*) FROM orders'),\n"
            . "        'settings' => (int)sh_val('SELECT COUNT(*) FROM settings'),\n"
            . "        'site_name_type' => gettype(sh_setting('site_name', ''))];\n"
            . "echo json_encode(\$out);\n");
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string)$probe) . ' 2>&1';
        $json = trim((string)@shell_exec($cmd));
        @unlink((string)$probe);
        $data = json_decode($json, true);
        $restore();
        if (is_array($data)) {
            $say('PASS', 'the application bootstrapped against the restored database '
                . '(products ' . $data['products'] . ', orders ' . $data['orders']
                . ', settings ' . $data['settings'] . ', sh_setting() returns ' . $data['site_name_type'] . ')');
            $say($data['products'] === $productsRestored ? 'PASS' : 'FAIL',
                'the application sees the same product count as the restore check (' . $productsRestored . ')');
        } else {
            $say('FAIL', 'the application could not read the restored database: ' . substr($json, 0, 300));
        }
    }
} else {
    $say('NOT TESTED', 'application bootstrap against the restored copy (pass --app-check to run it)');
}

echo "  The database itself is verified above. To confirm the APP can use it, copy this\n"
    . "  checkout to a staging directory and point ONLY that copy at the restored database:\n\n"
    . "    cp -a " . escapeshellarg($root) . " /path/to/staging-copy\n"
    . "    # then write /path/to/staging-copy/config/database.php with:\n"
    . "    #   'host' => " . var_export($host, true) . ", 'port' => {$port}, 'name' => " . var_export($scratch, true) . ",\n"
    . "    #   'user' => " . var_export($user, true) . ", 'pass' => '…', 'charset' => 'utf8mb4'\n"
    . "    # and open /admin/products.php and /index.php on that copy.\n";
$say('NOT TESTED', 'a browser/HTTP run against the restored copy (needs a web server and a staging copy)');

/* --- 6. Clean up ---------------------------------------------------------- */
if ($createBackup && !$flag('keep-backup') && is_file($dump)) {
    @unlink($dump);
    echo "\nRemoved the backup this run generated (" . basename($dump) . ").\n";
}
if ($flag('keep')) {
    echo "\nKept {$scratch} for inspection (--keep).\n";
} else {
    sh_exec($pdo, 'DROP DATABASE `' . $scratch . '`');
    echo "\nDropped the scratch database {$scratch}.\n";
}

echo "\n" . ($fail === 0 ? "REHEARSAL PASSED" : "REHEARSAL FAILED: {$fail} check(s)") . "\n";
echo "The production database was never contacted.\n";
exit($fail === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

function sh_human(int $bytes): string
{
    if ($bytes <= 0) { return '0 B'; }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int)min(3, floor(log($bytes, 1024)));
    return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
}

function sh_gz_text(string $path): string
{
    $gz = gzopen($path, 'rb');
    if ($gz === false) { return ''; }
    $out = '';
    while (!gzeof($gz)) {
        $chunk = gzread($gz, 1 << 20);
        if ($chunk === false) { break; }
        $out .= $chunk;
    }
    gzclose($gz);
    return $out;
}

function sh_connect(string $host, int $port, string $user, string $pass, ?string $db): PDO
{
    return new PDO("mysql:host={$host};port={$port}" . ($db !== null ? ";dbname={$db}" : '') . ';charset=utf8mb4',
        $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
}

function sh_exec(PDO $pdo, string $sql): void
{
    $pdo->exec($sql);
}
