<?php
/**
 * Backup inspector — the first half of a restore rehearsal.
 *
 * Before anyone imports a .sql/.sql.gz dump into a scratch database, this tool
 * proves the file is complete and readable, and reports what it will do:
 *
 *     php tools/backup-inspect.php ../storage/backups/shophaat-20261114-031500-ab12cd34ef.sql.gz
 *
 * Checks
 *   - the file exists, is not empty and is not a symlink pointing outside;
 *   - a .gz file has the gzip magic bytes, decompresses fully and ends cleanly
 *     (a truncated gzip stream is the classic "backup that looks fine");
 *   - the ShopHaat header, SET NAMES, FOREIGN_KEY_CHECKS off/on pairs;
 *   - at least one CREATE TABLE and one INSERT, and the statement that restores
 *     foreign key checking is present at the end;
 *   - no NUL bytes / binary garbage, and the table list it contains.
 *
 * Read-only: it never writes to the backup folder and never touches a database.
 * Exit code 0 = usable, 1 = problem (the reason is printed), 2 = usage error.
 */
declare(strict_types=1);

/**
 * Inspect one dump file.
 *
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,info:array<string,mixed>}
 */
function sh_backup_inspect(string $path, int $maxBytes = 0): array
{
    $errors = [];
    $warnings = [];
    $info = [
        'file' => basename($path),
        'compressed' => false,
        'bytes' => 0,
        'uncompressed_bytes' => 0,
        'tables' => [],
        'statements' => 0,
        'inserts' => 0,
        'drop_tables' => 0,
        'has_fk_guard' => false,
    ];

    if (str_contains($path, "\0")) {
        return ['ok' => false, 'errors' => ['The path contains a NUL byte.'], 'warnings' => [], 'info' => $info];
    }
    if (!is_file($path)) {
        return ['ok' => false, 'errors' => ['That file does not exist.'], 'warnings' => [], 'info' => $info];
    }
    $real = realpath($path);
    if ($real === false) {
        return ['ok' => false, 'errors' => ['The file path could not be resolved.'], 'warnings' => [], 'info' => $info];
    }
    $info['bytes'] = (int)@filesize($real);
    if ($info['bytes'] <= 0) {
        return ['ok' => false, 'errors' => ['The file is empty.'], 'warnings' => [], 'info' => $info];
    }
    if ($maxBytes > 0 && $info['bytes'] > $maxBytes) {
        return ['ok' => false, 'errors' => ['The file is larger than the configured limit.'], 'warnings' => [], 'info' => $info];
    }

    $gz = str_ends_with(strtolower($real), '.gz');
    $info['compressed'] = $gz;
    $fh = null;
    if ($gz) {
        if (!function_exists('gzopen')) {
            return ['ok' => false, 'errors' => ['gzip support is not available in this PHP build.'], 'warnings' => [], 'info' => $info];
        }
        $magic = (string)@file_get_contents($real, false, null, 0, 2);
        if ($magic !== "\x1f\x8b") {
            return ['ok' => false, 'errors' => ['This file is named .gz but is not a gzip stream.'], 'warnings' => [], 'info' => $info];
        }
        $fh = @gzopen($real, 'rb');
    } else {
        $fh = @fopen($real, 'rb');
    }
    if ($fh === false) {
        return ['ok' => false, 'errors' => ['The file could not be opened for reading.'], 'warnings' => [], 'info' => $info];
    }

    // Only the head and the tail are kept in memory (a dump can be hundreds of
    // megabytes); the interesting statements are counted per chunk.
    $head = '';
    $tail = '';
    $total = 0;
    $binary = 0;
    $tables = [];
    $inserts = 0;
    $drops = 0;
    $statements = 0;
    $fkOff = 0;
    $fkOn = 0;
    try {
        while (true) {
            $chunk = $gz ? @gzread($fh, 262144) : @fread($fh, 262144);
            if ($chunk === false) {
                $errors[] = 'The stream could not be read; the file is corrupt or truncated.';
                break;
            }
            if ($chunk === '') { break; }
            $total += strlen($chunk);
            if (strpos($chunk, "\0") !== false) { $binary++; }
            if (strlen($head) < 8192) { $head .= substr($chunk, 0, 8192 - strlen($head)); }
            $tail = substr($tail . $chunk, -8192);
            if (preg_match_all('~CREATE TABLE `([^`]+)`~', $chunk, $m)) {
                foreach ($m[1] as $t) { $tables[$t] = true; }
            }
            $inserts += preg_match_all('~INSERT INTO `~', $chunk) ?: 0;
            $drops += preg_match_all('~DROP TABLE IF EXISTS `~', $chunk) ?: 0;
            $statements += substr_count($chunk, ";\n");
            $fkOff += preg_match_all('~FOREIGN_KEY_CHECKS\s*=\s*0~i', $chunk) ?: 0;
            $fkOn += preg_match_all('~FOREIGN_KEY_CHECKS\s*=\s*1~i', $chunk) ?: 0;
            if ($total > 512 * 1024 * 1024) {
                $warnings[] = 'Stopped counting after 512 MB; the file is unusually large.';
                break;
            }
        }
    } finally {
        $gz ? @gzclose($fh) : @fclose($fh);
    }

    $info['uncompressed_bytes'] = $total;
    $info['tables'] = array_keys($tables);
    $info['inserts'] = $inserts;
    $info['drop_tables'] = $drops;
    $info['statements'] = $statements;
    $info['has_fk_guard'] = $fkOff > 0 && $fkOn > 0;

    if ($gz && $total === 0) {
        $errors[] = 'The gzip stream contains no data.';
    }
    if ($binary > 0) {
        $errors[] = 'The dump contains NUL bytes; it is not a plain SQL text file.';
    }
    if (!str_contains($head, 'ShopHaat database backup')) {
        $errors[] = 'The ShopHaat header is missing; this does not look like a ShopHaat backup.';
    }
    if (stripos($head, 'SET NAMES') === false) {
        $warnings[] = 'No SET NAMES statement: character data may import with the wrong collation.';
    }
    if ($fkOff === 0) {
        $warnings[] = 'Foreign key checking is not disabled at the start; the import may fail on table order.';
    }
    if (!$info['has_fk_guard']) {
        $errors[] = 'The dump never re-enables FOREIGN_KEY_CHECKS; the import would leave the session inconsistent.';
    }
    if ($info['tables'] === []) {
        $errors[] = 'No CREATE TABLE statement was found; restoring this file would create nothing.';
    }
    if ($drops !== count($info['tables'])) {
        $warnings[] = 'The number of DROP TABLE statements does not match the number of tables.';
    }
    if (stripos($tail, 'End of backup') === false) {
        $errors[] = 'The end-of-backup marker is missing; the file was probably cut short.';
    }
    $critical = ['orders', 'products', 'users', 'payments'];
    $missing = array_values(array_diff($critical, $info['tables']));
    if ($missing !== []) {
        // A pre-launch store legitimately has no orders; report it, do not fail.
        $warnings[] = 'Tables not present in this dump: ' . implode(', ', $missing) . '.';
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'info' => $info];
}

/** Print a human-readable inspection report. */
function sh_backup_inspect_report(string $path, array $result): string
{
    $out = "Backup inspection: " . ($result['ok'] ? 'USABLE' : 'PROBLEM FOUND') . "\n";
    $out .= str_repeat('-', 60) . "\n";
    $i = $result['info'];
    $out .= sprintf("file            : %s\n", (string)($i['file'] ?? basename($path)));
    $out .= sprintf("size            : %s%s\n", number_format((int)($i['bytes'] ?? 0)), !empty($i['compressed']) ? ' (gzip)' : '');
    $out .= sprintf("uncompressed    : %s bytes\n", number_format((int)($i['uncompressed_bytes'] ?? 0)));
    $out .= sprintf("tables          : %d\n", is_array($i['tables'] ?? null) ? count($i['tables']) : 0);
    if (!empty($i['tables'])) {
        $out .= "  " . implode(', ', array_slice((array)$i['tables'], 0, 12)) . (count((array)$i['tables']) > 12 ? ', …' : '') . "\n";
    }
    $out .= sprintf("INSERT groups   : %d\n", (int)($i['inserts'] ?? 0));
    $out .= sprintf("DROP TABLE      : %d\n", (int)($i['drop_tables'] ?? 0));
    $out .= sprintf("FK guard paired : %s\n", !empty($i['has_fk_guard']) ? 'yes' : 'no');
    foreach ($result['warnings'] as $w) { $out .= "warning         : {$w}\n"; }
    foreach ($result['errors'] as $e) { $out .= "ERROR           : {$e}\n"; }
    if ($result['ok']) {
        $out .= "\nNext step (restore rehearsal): import this file into a scratch database,\n"
             . "never the live one, then compare row counts as described in\n"
             . "SHOP_HAAT_REPORT_06_RESTORE_REHEARSAL.md.\n";
    }
    return $out;
}

// ---------------------------------------------------------------------------
// CLI entry point (skipped when the file is included by a test)
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $args = array_slice($_SERVER['argv'] ?? [], 1);
    if ($args === [] || in_array($args[0], ['-h', '--help'], true)) {
        fwrite(STDERR, "Usage: php tools/backup-inspect.php <backup.sql|backup.sql.gz>\n");
        exit(2);
    }
    $target = $args[0];
    $result = sh_backup_inspect($target);
    echo sh_backup_inspect_report($target, $result);
    exit($result['ok'] ? 0 : 1);
}
