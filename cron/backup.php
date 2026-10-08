<?php
/**
 * Scheduled backup endpoint. Call from cron with ?token=... (see Admin → Database Backup).
 * Runs only when a schedule is enabled and the backup is due.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-tools.php';

header('Content-Type: text/plain; charset=utf-8');
$token = (string)sh_setting('backup_cron_token', '');
$given = (string)($_GET['token'] ?? '');
if ($token === '' || $given === '' || !hash_equals($token, $given)) { http_response_code(403); echo "Forbidden\n"; exit; }

$r = sh_backup_run_scheduled();
if ($r === null) { echo "Nothing to do (schedule off or backup not yet due).\n"; exit; }
if (!empty($r['ok'])) {
    sh_admin_notify('backup', 'Scheduled backup completed', $r['file'] . ' · ' . sh_bytes_human((int)$r['size']), 'admin/backups.php', 'backup-' . date('Ymd'));
    echo 'OK ' . $r['file'] . "\n";
} else {
    sh_admin_notify('backup', 'Scheduled backup failed', (string)($r['error'] ?? ''), 'admin/backups.php', 'backup-fail-' . date('Ymd'));
    http_response_code(500); echo 'FAILED ' . ($r['error'] ?? '') . "\n";
}
