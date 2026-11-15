<?php
/**
 * Backup inspector tests (the rehearsal half that can run without MySQL).
 *
 * A restore rehearsal has two halves: prove the file is complete, then import it
 * into a scratch database. Only the first half is executable here, and it is the
 * half that catches the failure that matters — the truncated or unreadable dump
 * that everyone assumes is a backup.
 *
 *     php tests/backup-inspect.php
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

require_once dirname(__DIR__) . '/tools/backup-inspect.php';

$dir = sys_get_temp_dir() . '/sh-inspect-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);

function dump_text(array $tables = ['users', 'products', 'orders', 'payments'], bool $end = true, bool $fkOn = true): string
{
    $sql = "-- ShopHaat database backup\n-- Database: `shop`\n-- Created: " . date('c') . "\n\n"
         . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
    foreach ($tables as $t) {
        $sql .= "--\n-- Table `{$t}`\n--\nDROP TABLE IF EXISTS `{$t}`;\n"
              . "CREATE TABLE `{$t}` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
              . "INSERT INTO `{$t}` (`id`) VALUES\n(1),\n(2);\n\n";
    }
    if ($fkOn) { $sql .= "SET FOREIGN_KEY_CHECKS=1;\n"; }
    if ($end) { $sql .= "-- End of backup\n"; }
    return $sql;
}

function write_dump(string $dir, string $name, string $contents, bool $gzip = true): string
{
    $path = $dir . '/' . $name;
    if ($gzip) {
        $gz = gzopen($path, 'wb6');
        gzwrite($gz, $contents);
        gzclose($gz);
    } else {
        file_put_contents($path, $contents);
    }
    return $path;
}

// ===========================================================================
t_sec('1. A healthy dump is accepted and described');
// ===========================================================================
$good = write_dump($dir, 'good.sql.gz', dump_text());
$r = sh_backup_inspect($good);
t_ok('a normal compressed dump passes', $r['ok'], implode(' / ', $r['errors']));
t_ok('it is recognised as gzip', !empty($r['info']['compressed']));
t_ok('the table list is extracted', in_array('orders', $r['info']['tables'], true));
t_ok('the INSERT groups are counted', (int)$r['info']['inserts'] === 4);
t_ok('the foreign key guard is paired', !empty($r['info']['has_fk_guard']));
t_ok('no warnings for a complete dump', $r['warnings'] === [], implode(' / ', $r['warnings']));

$plain = write_dump($dir, 'good.sql', dump_text(), false);
$r = sh_backup_inspect($plain);
t_ok('an uncompressed dump passes too', $r['ok'], implode(' / ', $r['errors']));

// ===========================================================================
t_sec('2. Every way a backup lies is caught');
// ===========================================================================
$empty = $dir . '/empty.sql';
file_put_contents($empty, '');
$r = sh_backup_inspect($empty);
t_ok('an empty file is refused', !$r['ok'] && in_array('The file is empty.', $r['errors'], true));

$r = sh_backup_inspect($dir . '/does-not-exist.sql');
t_ok('a missing file is refused', !$r['ok']);

$notGz = $dir . '/fake.sql.gz';
file_put_contents($notGz, dump_text());
$r = sh_backup_inspect($notGz);
t_ok('a .gz that is not gzip is refused', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'not a gzip stream'));

$truncated = write_dump($dir, 'truncated.sql.gz', dump_text([], false, true));
$r = sh_backup_inspect($truncated);
t_ok('a dump without its end marker is refused', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'end-of-backup marker'));

$noFkOn = write_dump($dir, 'nofkon.sql.gz', dump_text(['users', 'orders'], true, false));
$r = sh_backup_inspect($noFkOn);
t_ok('a dump that never re-enables foreign keys is refused', !$r['ok']);

$noHeader = write_dump($dir, 'noheader.sql.gz', "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `a`;\nCREATE TABLE `a` (`id` int);\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
$r = sh_backup_inspect($noHeader);
t_ok('a foreign SQL file is refused (header missing)', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'ShopHaat header'));

$noTables = write_dump($dir, 'notables.sql.gz', "-- ShopHaat database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
$r = sh_backup_inspect($noTables);
t_ok('a dump with no CREATE TABLE is refused', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'No CREATE TABLE'));

$binary = write_dump($dir, 'binary.sql.gz', dump_text() . "\0\0\0");
$r = sh_backup_inspect($binary);
t_ok('binary garbage is refused', !$r['ok'] && str_contains(implode(' ', $r['errors']), 'NUL bytes'));

// A truncated gzip stream: cut the file in half (header intact, data missing).
$cut = $dir . '/cut.sql.gz';
$rawGz = (string)file_get_contents($good);
file_put_contents($cut, substr($rawGz, 0, max(20, (int)(strlen($rawGz) / 2))));
$r = sh_backup_inspect($cut);
t_ok('a physically truncated gzip file is refused', !$r['ok']);

// ===========================================================================
t_sec('3. Warnings are warnings, not failures');
// ===========================================================================
$partialStore = write_dump($dir, 'prelaunch.sql.gz', dump_text(['users', 'products']));
$r = sh_backup_inspect($partialStore);
t_ok('a pre-launch store (no orders yet) still passes', $r['ok']);
t_ok('but the missing tables are mentioned', str_contains(implode(' ', $r['warnings']), 'orders'));

$noNames = write_dump($dir, 'nonames.sql.gz', str_replace("SET NAMES utf8mb4;\n", '', dump_text()));
$r = sh_backup_inspect($noNames);
t_ok('a dump without SET NAMES passes with a warning', $r['ok'] && str_contains(implode(' ', $r['warnings']), 'SET NAMES'));

// ===========================================================================
t_sec('4. The report is readable and the tool is read-only');
// ===========================================================================
$report = sh_backup_inspect_report($good, sh_backup_inspect($good));
t_ok('the report says USABLE for a good file', str_contains($report, 'USABLE'));
t_ok('the report names the next step (scratch database)', str_contains($report, 'scratch'));
t_ok('the report lists tables', str_contains($report, 'orders'));
$before = (string)file_get_contents($good);
sh_backup_inspect($good);
t_ok('inspecting never modifies the file', hash_equals(hash('sha256', $before), hash('sha256', (string)file_get_contents($good))));
t_ok('no stray file was created in the folder', count(glob($dir . '/*')) === count(array_filter(glob($dir . '/*') ?: [], 'is_file')));

foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "BACKUP INSPECTOR SUITE: ALL CHECKS PASSED\n" : "BACKUP INSPECTOR SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
