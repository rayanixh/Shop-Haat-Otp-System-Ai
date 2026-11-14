<?php
/**
 * Scheduled backup endpoint (cron).
 *
 * Two ways to call it, both token authenticated:
 *
 *   HTTP (cPanel "Cron Jobs" with wget/curl, or an external monitor):
 *     https://example.com/cron/backup.php?token=YOUR_TOKEN
 *
 *   PHP CLI (no web server involved):
 *     php /home/USER/public_html/cron/backup.php --token=YOUR_TOKEN
 *     SH_BACKUP_CRON_TOKEN=YOUR_TOKEN php /home/USER/public_html/cron/backup.php
 *
 * Why the CLI form needs an argument or an environment variable: PHP does not
 * populate $_GET from a command line, so the old documented command
 * `php cron/backup.php?token=…` never authenticated (and PHP cannot even open a
 * file with "?token=…" in its name). The token is read from, in order:
 *
 *   1. a `--token=…` argument (or a bare token as the only argument);
 *   2. the environment variable SH_BACKUP_CRON_TOKEN;
 *   3. config/secrets.php → 'backup_cron_token' (same value the HTTP call uses).
 *
 * The configured token is resolved through includes/secrets.php first (constant,
 * environment or config/secrets.php) and falls back to the stored setting, so an
 * existing installation keeps working. Comparison uses hash_equals(); the token
 * is never echoed, never logged and never included in an error message.
 */
declare(strict_types=1);

$shIsCli = PHP_SAPI === 'cli';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/includes/secrets.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-tools.php';

/** Emit a line in the format the caller expects and stop. */
function sh_cron_reply(bool $isCli, int $status, string $message): void
{
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit($status >= 400 ? 1 : 0);
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    echo $message . "\n";
    exit;
}

/** The token this installation expects (secret source first, setting second). */
function sh_cron_expected_token(): string
{
    $token = '';
    if (function_exists('sh_secret')) { $token = sh_secret('backup_cron_token', ''); }
    if ($token === '') { $token = (string)sh_setting('backup_cron_token', ''); }
    return $token;
}

/** The token the caller supplied, by mode. Never logged. */
function sh_cron_given_token(bool $isCli, array $argv): string
{
    if (!$isCli) {
        // HTTP: a header is also accepted so the token does not have to appear
        // in a URL that may end up in an access log.
        $header = (string)($_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '');
        if ($header !== '') { return $header; }
        return (string)($_GET['token'] ?? $_POST['token'] ?? '');
    }
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, '--token=')) { return substr((string)$arg, 8); }
    }
    // A single positional argument is accepted so a host that strips "--" still
    // has a working form: `php cron/backup.php TOKEN`.
    $positional = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with((string)$a, '--')));
    if (count($positional) === 1) { return (string)$positional[0]; }
    $env = getenv('SH_BACKUP_CRON_TOKEN');
    return is_string($env) ? $env : '';
}

$argvList = $isCli ? ($_SERVER['argv'] ?? $GLOBALS['argv'] ?? []) : [];
$expected = sh_cron_expected_token();
if ($expected === '') {
    sh_log_line('backup', 'Scheduled backup refused: no cron token is configured.');
    sh_cron_reply($isCli, 403, 'Forbidden: no cron token is configured. Generate one in Admin -> Backups.');
}

$given = trim(sh_cron_given_token($isCli, is_array($argvList) ? $argvList : []));
if ($given === '' || !hash_equals($expected, $given)) {
    // Rate limit only the HTTP surface; a CLI caller is already local.
    if (!$isCli) {
        $bucketKey = function_exists('sh_client_ip') ? sh_client_ip() : 'unknown';
        if (function_exists('sh_file_rate_limit') && !sh_file_rate_limit('backup-cron-token', $bucketKey, 10, 900)) {
            sh_log_line('backup', 'Scheduled backup token failures are being rate limited.');
            sh_cron_reply(false, 429, 'Too many attempts.');
        }
    }
    sh_log_line('backup', 'Scheduled backup refused: invalid token (' . ($isCli ? 'cli' : 'http') . ').');
    sh_cron_reply($isCli, 403, 'Forbidden.');
}

$r = sh_backup_run_scheduled();
if ($r === null) {
    sh_cron_reply($isCli, 200, 'Nothing to do (schedule off or backup not yet due).');
}
if (!empty($r['skipped']) && $r['skipped'] === 'locked') {
    // Not an error: another scheduler run owns the work right now.
    sh_cron_reply($isCli, 200, 'Skipped: another backup is already running.');
}
if (!empty($r['ok'])) {
    $summary = (string)($r['file'] ?? '') . ' · ' . sh_bytes_human((int)($r['size'] ?? 0));
    if (isset($r['pruned'])) { $summary .= ' · pruned ' . (int)$r['pruned']; }
    sh_admin_notify('backup', 'Scheduled backup completed', $summary, 'admin/backups.php', 'backup-' . date('Ymd'));
    sh_cron_reply($isCli, 200, 'OK ' . $summary);
}

sh_admin_notify('backup', 'Scheduled backup failed', (string)($r['error'] ?? ''), 'admin/backups.php', 'backup-fail-' . date('Ymd'));
sh_cron_reply($isCli, 500, 'FAILED ' . (string)($r['error'] ?? 'unknown error'));
