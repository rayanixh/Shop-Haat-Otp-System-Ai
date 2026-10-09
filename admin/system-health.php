<?php
/** System health — lightweight, cached checks; no secrets, no shell commands. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_perm('system.health');

const SH_HEALTH_TTL = 600; // seconds; "Refresh Status" bypasses the cache

function sh_health_item(string $label, string $value, string $state, string $note = ''): array
{
    return ['label' => $label, 'value' => $value, 'state' => $state, 'note' => $note];
}

function sh_health_dir_size(string $dir, int $maxFiles = 30000): ?int
{
    if (!is_dir($dir)) { return null; }
    $size = 0; $n = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) { if ($f->isFile()) { $size += $f->getSize(); if (++$n > $maxFiles) { break; } } }
    } catch (Throwable $e) { return null; }
    return $size;
}

function sh_health_collect(): array
{
    $items = [];
    // Database
    try {
        $pdo = sh_db();
        $t0 = microtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $items['db'] = sh_health_item('Database connection', 'Connected', 'healthy', 'Query round-trip ' . $ms . ' ms');
        $ver = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $items['dbver'] = sh_health_item('MySQL / MariaDB version', $ver !== '' ? $ver : 'Unavailable', $ver !== '' ? 'healthy' : 'unavailable');
        $size = sh_val('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()', [], null);
        $tables = (int)sh_val('SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()', [], 0);
        $items['dbsize'] = $size === null ? sh_health_item('Database size', 'Unavailable', 'unavailable') : sh_health_item('Database size', sh_bytes_human((int)$size), (int)$size > 900 * 1048576 ? 'warning' : 'healthy', $tables . ' tables');
    } catch (Throwable $e) {
        $items['db'] = sh_health_item('Database connection', 'Connection failed', 'error', 'See error logs for details.');
        $items['dbver'] = sh_health_item('MySQL / MariaDB version', 'Unavailable', 'unavailable');
        $items['dbsize'] = sh_health_item('Database size', 'Unavailable', 'unavailable');
    }
    // PHP
    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $items['php'] = sh_health_item('PHP version', PHP_VERSION, $phpOk ? 'healthy' : 'warning', $phpOk ? '' : 'PHP 8.0 or newer is recommended.');
    $missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'curl', 'openssl', 'json', 'gd'], static fn($x) => !extension_loaded($x)));
    $items['ext'] = sh_health_item('PHP extensions', $missing ? 'Missing: ' . implode(', ', $missing) : 'All required extensions loaded', $missing ? (in_array('pdo_mysql', $missing, true) ? 'error' : 'warning') : 'healthy', 'Checked: pdo_mysql, mbstring, curl, openssl, json, gd');
    // Storage (disk)
    $total = @disk_total_space(SH_ROOT); $free = @disk_free_space(SH_ROOT);
    if ($total && $free !== false) {
        $pct = (int)round(($total - $free) / $total * 100);
        $items['disk'] = sh_health_item('Storage usage', $pct . '% used', $pct >= 90 ? 'error' : ($pct >= 70 ? 'warning' : 'healthy'), sh_bytes_human((int)$free) . ' free of ' . sh_bytes_human((int)$total));
    } else {
        $items['disk'] = sh_health_item('Storage usage', 'Unavailable', 'unavailable', 'Disk statistics are not exposed on this hosting.');
    }
    $up = sh_health_dir_size(SH_UPLOAD_DIR);
    $items['uploads'] = !is_dir(SH_UPLOAD_DIR) ? sh_health_item('Upload directory', 'Missing', 'error', 'uploads/ does not exist.')
        : (!is_writable(SH_UPLOAD_DIR) ? sh_health_item('Upload directory', 'Not writable', 'error', 'Set uploads/ permissions to 755 or 775.')
        : sh_health_item('Upload directory', 'Writable', 'healthy', $up === null ? '' : 'Uses ' . sh_bytes_human($up)));
    $items['logs'] = is_dir(SH_LOG_DIR) && is_writable(SH_LOG_DIR) ? sh_health_item('Log directory', 'Writable', 'healthy', 'Uses ' . sh_bytes_human((int)sh_health_dir_size(SH_LOG_DIR))) : sh_health_item('Log directory', 'Not writable', 'warning', 'Application errors cannot be recorded.');
    // Sessions / cache
    $sp = session_save_path() ?: (string)ini_get('session.save_path');
    $handler = (string)ini_get('session.save_handler');
    if ($handler !== 'files' && $handler !== '') { $items['session'] = sh_health_item('Session storage', 'Handler: ' . $handler, 'healthy'); }
    elseif ($sp === '' || !is_dir($sp)) { $items['session'] = sh_health_item('Session storage', 'Unavailable', 'unavailable', 'Session path is managed by the host.'); }
    else { $items['session'] = sh_health_item('Session storage', is_writable($sp) ? 'Writable' : 'Not writable', is_writable($sp) ? 'healthy' : 'error'); }
    $opc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    $items['cache'] = $opc === false ? sh_health_item('PHP cache (OPcache)', 'Unavailable', 'unavailable') : sh_health_item('PHP cache (OPcache)', !empty($opc['opcache_enabled']) ? 'Enabled' : 'Disabled', !empty($opc['opcache_enabled']) ? 'healthy' : 'warning');
    // Backups
    $last = sh_one("SELECT * FROM db_backups WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    $lastFail = sh_one("SELECT * FROM db_backups WHERE status = 'failed' ORDER BY id DESC LIMIT 1");
    $sched = (string)sh_setting('backup_schedule', 'off');
    if ($last) {
        $age = time() - strtotime($last['created_at']);
        $items['backup_last'] = sh_health_item('Last database backup', date('d M Y, h:i A', strtotime($last['created_at'])), $age > 14 * 86400 ? 'warning' : 'healthy', sh_bytes_human((int)$last['file_size']) . ' · ' . $last['source'] . ($age > 14 * 86400 ? ' · older than 14 days' : ''));
    } else { $items['backup_last'] = sh_health_item('Last database backup', 'No backup yet', 'warning', 'Create one from Database Backup.'); }
    $items['backup_status'] = $lastFail && (!$last || $lastFail['id'] > $last['id']) ? sh_health_item('Backup status', 'Last backup failed', 'error', 'Check the backup history.')
        : sh_health_item('Backup status', $sched === 'off' ? 'Manual only' : 'Scheduled ' . $sched, $sched === 'off' ? 'warning' : 'healthy', is_writable(sh_backup_dir()) ? '' : 'storage/backups is not writable');
    // Courier APIs (configuration + last known results; no live calls)
    try {
        $couriers = sh_all("SELECT name, driver FROM couriers WHERE status = 1");
        $api = array_filter($couriers, static fn($c) => $c['driver'] !== 'manual');
        $recent = sh_one("SELECT status, checked_at FROM verify_courier_cache ORDER BY checked_at DESC LIMIT 1");
        $providers = (int)sh_val('SELECT COUNT(*) FROM verify_providers WHERE enabled = 1', [], 0);
        if (!$api && $providers === 0) { $items['courier'] = sh_health_item('Courier API', 'Not configured', 'unavailable', $couriers ? count($couriers) . ' manual courier(s) enabled' : ''); }
        else {
            $state = 'healthy'; $note = ($api ? count($api) . ' shipping API(s)' : '') . ($providers ? ($api ? ' · ' : '') . $providers . ' verification provider(s)' : '');
            if ($recent) { $note .= ' · last check ' . sh_time_ago($recent['checked_at']) . ' (' . $recent['status'] . ')'; if ($recent['status'] === 'error') { $state = 'warning'; } }
            $items['courier'] = sh_health_item('Courier API', $state === 'healthy' ? 'Configured' : 'Last check failed', $state, $note);
        }
    } catch (Throwable $e) { $items['courier'] = sh_health_item('Courier API', 'Unavailable', 'unavailable'); }
    // Payment gateways
    try {
        $gw = sh_all('SELECT name, mode FROM payment_gateways WHERE status = 1');
        $manual = (int)sh_val('SELECT COUNT(*) FROM payment_methods WHERE status = 1', [], 0);
        if (!$gw) { $items['payment'] = sh_health_item('Payment provider API', 'No online gateway enabled', $manual > 0 ? 'unavailable' : 'warning', $manual > 0 ? $manual . ' manual/COD method(s) active' : 'No payment methods are active.'); }
        else {
            $lastGw = sh_one("SELECT status, created_at FROM payments WHERE kind = 'gateway' ORDER BY id DESC LIMIT 1");
            $fails = (int)sh_val("SELECT COUNT(*) FROM payments WHERE kind = 'gateway' AND status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)], 0);
            $items['payment'] = sh_health_item('Payment provider API', implode(', ', array_map(static fn($g) => $g['name'] . ' (' . $g['mode'] . ')', $gw)), $fails >= 3 ? 'warning' : 'healthy', ($lastGw ? 'Last gateway payment ' . sh_time_ago($lastGw['created_at']) . ' · ' . $lastGw['status'] : 'No gateway payments yet') . ($fails ? ' · ' . $fails . ' failed in 24h' : ''));
        }
    } catch (Throwable $e) { $items['payment'] = sh_health_item('Payment provider API', 'Unavailable', 'unavailable'); }
    // Notifications
    try {
        $nf = (int)sh_val("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)], 0);
        $items['notify'] = sh_health_item('Notification delivery (24h)', $nf === 0 ? 'No failures' : $nf . ' failed', $nf === 0 ? 'healthy' : ($nf > 10 ? 'error' : 'warning'));
    } catch (Throwable $e) { $items['notify'] = sh_health_item('Notification delivery (24h)', 'Unavailable', 'unavailable'); }
    // Recent application errors (scrubbed, last 24h, count + a few lines)
    $errLines = []; $errCount = 0;
    foreach ([SH_LOG_DIR . '/php-error.log', SH_LOG_DIR . '/app-' . date('Y-m-d') . '.log', SH_LOG_DIR . '/app-' . date('Y-m-d', time() - 86400) . '.log'] as $f) {
        if (!is_file($f) || !is_readable($f)) { continue; }
        $size = filesize($f);
        $fh = fopen($f, 'rb');
        if (!$fh) { continue; }
        if ($size > 65536) { fseek($fh, -65536, SEEK_END); fgets($fh); }
        while (($line = fgets($fh)) !== false) {
            if (!preg_match('/error|exception|fatal|warning/i', $line)) { continue; }
            if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4} [\d:]+)/', $line, $m) && strtotime($m[1]) < time() - 86400) { continue; }
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} [\d:]+)/', $line, $m) && strtotime($m[1]) < time() - 86400) { continue; }
            $errCount++;
            $errLines[] = mb_substr(sh_scrub_secrets(str_replace(SH_ROOT, '', trim($line))), 0, 220);
        }
        fclose($fh);
    }
    $errLines = array_slice(array_reverse($errLines), 0, 6);
    $items['errors'] = sh_health_item('Application errors (24h)', $errCount === 0 ? 'None recorded' : $errCount . ' entries', $errCount === 0 ? 'healthy' : ($errCount > 25 ? 'error' : 'warning'));
    return ['items' => $items, 'errors' => $errLines, 'checked_at' => date('Y-m-d H:i:s')];
}

$force = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('form') === 'refresh') { $force = true; }
}
$cacheRaw = (string)sh_setting('health_cache', '');
$cache = $cacheRaw !== '' ? json_decode($cacheRaw, true) : null;
if ($force || !is_array($cache) || empty($cache['checked_at']) || time() - strtotime($cache['checked_at']) > SH_HEALTH_TTL) {
    $cache = sh_health_collect();
    try { sh_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()', ['health_cache', json_encode($cache, JSON_UNESCAPED_UNICODE)]); sh_settings(true); } catch (Throwable $e) {}
    if ($force) { sh_flash('success', 'System status refreshed.'); sh_redirect('admin/system-health.php'); }
}
$items = $cache['items'];
$summary = ['healthy' => 0, 'warning' => 0, 'error' => 0, 'unavailable' => 0];
foreach ($items as $it) { $summary[$it['state']] = ($summary[$it['state']] ?? 0) + 1; }
$overall = $summary['error'] > 0 ? 'error' : ($summary['warning'] > 0 ? 'warning' : 'healthy');
$stateLabel = ['healthy' => 'HEALTHY', 'warning' => 'WARNING', 'error' => 'ERROR', 'unavailable' => 'UNAVAILABLE'];
$stateClass = ['healthy' => 'sh-badge--ok', 'warning' => 'sh-badge--warn', 'error' => 'sh-badge--bad', 'unavailable' => 'sh-badge--muted'];
$groups = [
    'Database' => ['db', 'dbver', 'dbsize'],
    'Server & storage' => ['php', 'ext', 'disk', 'uploads', 'logs', 'session', 'cache'],
    'Backups' => ['backup_last', 'backup_status'],
    'Integrations' => ['courier', 'payment', 'notify'],
    'Errors' => ['errors'],
];

$adminPage = 'health';
$adminTitle = 'System Health';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Overall status <span class="sh-badge <?= $stateClass[$overall] ?>"><?= $stateLabel[$overall] ?></span></h2>
    <div class="sh-actions">
      <span class="sh-muted">Checked <?= e(sh_time_ago($cache['checked_at'])) ?></span>
      <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="refresh"><button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('refresh', 14) ?> Refresh status</button></form>
    </div>
  </div>
  <div class="sh-panel__body">
    <div class="sh-stats sh-stats--4">
      <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('check-circle', 20) ?></span><div><div class="sh-stat__value"><?= $summary['healthy'] ?></div><div class="sh-stat__label">Healthy</div></div></div>
      <div class="sh-stat sh-stat--warn"><span class="sh-stat__icon"><?= sh_icon('alert', 20) ?></span><div><div class="sh-stat__value"><?= $summary['warning'] ?></div><div class="sh-stat__label">Warnings</div></div></div>
      <div class="sh-stat sh-stat--bad"><span class="sh-stat__icon"><?= sh_icon('x-circle', 20) ?></span><div><div class="sh-stat__value"><?= $summary['error'] ?></div><div class="sh-stat__label">Errors</div></div></div>
      <div class="sh-stat sh-stat--muted"><span class="sh-stat__icon"><?= sh_icon('help', 20) ?></span><div><div class="sh-stat__value"><?= $summary['unavailable'] ?></div><div class="sh-stat__label">Unavailable</div></div></div>
    </div>
  </div>
</div>

<div class="sh-health-grid">
  <?php foreach ($groups as $title => $keys): ?>
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= e($title) ?></h2></div>
      <div class="sh-panel__body" style="padding:0">
        <?php foreach ($keys as $k): if (!isset($items[$k])) { continue; } $it = $items[$k]; ?>
          <div class="sh-health-row">
            <div class="sh-health-row__text"><strong><?= e($it['label']) ?></strong><span><?= e($it['value']) ?></span><?php if ($it['note'] !== ''): ?><small><?= e($it['note']) ?></small><?php endif; ?></div>
            <span class="sh-badge <?= $stateClass[$it['state']] ?>"><?= $stateLabel[$it['state']] ?></span>
          </div>
        <?php endforeach; ?>
        <?php if ($title === 'Errors'): ?>
          <div class="sh-health-errors">
            <?php if (!$cache['errors']): ?><p class="sh-muted">No data available — no application errors in the last 24 hours.</p>
            <?php else: foreach ($cache['errors'] as $l): ?><code><?= e($l) ?></code><?php endforeach; ?>
              <?php if (sh_admin_can('system.logs')): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/logs.php')) ?>">Open error logs</a><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
