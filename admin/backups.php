<?php
/** Database backups — create, list, download, delete (super admin), schedule. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
sh_admin_schema_ensure();

// Download is proxied through PHP so the file is never web-accessible directly.
$dl = sh_int($_GET['download'] ?? 0);
if ($dl > 0) {
    $b = sh_one('SELECT * FROM db_backups WHERE id = ? AND status = \'completed\'', [$dl]);
    $path = $b ? sh_backup_path($b) : '';
    if (!$b || !is_file($path) || basename($path) !== $b['file_name'] || dirname(realpath($path)) !== realpath(sh_backup_dir())) {
        sh_flash('error', 'That backup file is no longer available.'); sh_redirect('admin/backups.php');
    }
    sh_audit('backup_downloaded', 'backup', (int)$b['id'], (string)$b['file_name']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level()) { ob_end_clean(); }
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form === 'create') {
        $r = sh_backup_create((string)$admin['email'], 'manual');
        if (!empty($r['ok'])) {
            sh_audit('backup_created', 'backup', (int)$r['id'], (string)$r['file'], null, ['size' => sh_bytes_human((int)$r['size']), 'tables' => $r['tables']]);
            sh_flash('success', 'Backup created: ' . $r['file'] . ' (' . sh_bytes_human((int)$r['size']) . ', ' . (int)$r['tables'] . ' tables).');
        } else { sh_flash('error', $r['error'] ?? 'The backup failed.'); }
        sh_redirect('admin/backups.php');
    }
    if ($form === 'delete') {
        if (!sh_admin_is_superadmin()) { sh_flash('error', 'Only a super admin can delete backups.'); sh_redirect('admin/backups.php'); }
        $b = sh_one('SELECT * FROM db_backups WHERE id = ?', [sh_int($_POST['id'] ?? 0)]);
        if ($b) {
            $path = sh_backup_path($b);
            if (is_file($path)) { @unlink($path); }
            sh_query('DELETE FROM db_backups WHERE id = ?', [(int)$b['id']]);
            sh_audit('backup_deleted', 'backup', (int)$b['id'], (string)$b['file_name']);
            sh_flash('success', 'Backup deleted.');
        }
        sh_redirect('admin/backups.php');
    }
    if ($form === 'schedule') {
        $freq = in_array(sh_post('backup_schedule'), ['off', 'daily', 'weekly', 'monthly'], true) ? sh_post('backup_schedule') : 'off';
        $keep = min(60, max(1, sh_int($_POST['backup_keep'] ?? 10)));
        $old = ['schedule' => sh_setting('backup_schedule', 'off'), 'keep' => sh_setting('backup_keep', '10')];
        sh_setting_save('backup_schedule', $freq);
        sh_setting_save('backup_keep', (string)$keep);
        if ((string)sh_setting('backup_cron_token', '') === '') { sh_setting_save('backup_cron_token', bin2hex(random_bytes(16))); }
        sh_audit('settings_changed', 'settings', null, 'Backup schedule', $old, ['schedule' => $freq, 'keep' => $keep]);
        sh_flash('success', 'Backup schedule saved.');
        sh_redirect('admin/backups.php');
    }
}

$list = sh_backup_list();
$schedule = (string)sh_setting('backup_schedule', 'off');
$keep = (int)sh_setting('backup_keep', '10');
$token = (string)sh_setting('backup_cron_token', '');
$lastAuto = (string)sh_setting('backup_last_auto', '');
$dir = sh_backup_dir();
$writable = is_writable($dir);
$cronUrl = $token !== '' ? sh_site_url() . '/cron/backup.php?token=' . $token : '';

$adminPage = 'backups';
$adminTitle = 'Database Backup';
require __DIR__ . '/_layout.php';
?>
<div class="sh-settings-grid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('database', 17) ?> Create backup</h2></div>
    <div class="sh-panel__body">
      <p class="sh-muted" style="margin:0 0 12px">Exports the full database structure and data as a <?= function_exists('gzopen') ? 'compressed .sql.gz' : '.sql' ?> file. Files are stored outside public reach and only downloadable from this page. Database credentials are never included.</p>
      <?php if (!$writable): ?><div class="sh-alert sh-alert--error">The folder <code>storage/backups</code> is not writable. Please set its permissions to 755 (or 775) on the server.</div><?php endif; ?>
      <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="create">
        <button class="sh-btn" type="submit" <?= $writable ? '' : 'disabled' ?>><?= sh_icon('download', 15) ?> Create backup now</button></form>
    </div>
  </div>
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('clock', 17) ?> Automatic schedule</h2></div>
    <div class="sh-panel__body">
      <form method="post" class="sh-form"><?= sh_csrf_field() ?><input type="hidden" name="form" value="schedule">
        <div class="sh-form__row">
          <div class="sh-field"><label class="sh-field__label" for="b-freq">Frequency</label>
            <select class="sh-select" id="b-freq" name="backup_schedule">
              <?php foreach (['off' => 'Off', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $k => $l): ?><option value="<?= $k ?>" <?= $schedule === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="b-keep">Keep last</label><input class="sh-input" id="b-keep" type="number" min="1" max="60" name="backup_keep" value="<?= (int)$keep ?>"></div>
        </div>
        <button class="sh-btn sh-btn--sm" type="submit">Save schedule</button>
      </form>
      <?php if ($schedule !== 'off'): ?>
        <div class="sh-form__hint" style="margin-top:12px">
          <?php if ($cronUrl !== ''): ?>
            Add a cron job in your hosting panel (cPanel → Cron Jobs) that opens this URL once per day:<br>
            <code class="sh-code-wrap"><?= e($cronUrl) ?></code><br>
            Example command: <code class="sh-code-wrap">wget -q -O /dev/null "<?= e($cronUrl) ?>"</code><br>
            If your hosting does not provide cron, the schedule still runs when an admin opens the dashboard after the backup becomes due.
          <?php endif; ?>
          <?php if ($lastAuto !== ''): ?><br>Last automatic backup: <?= e(date('d M Y H:i', strtotime($lastAuto))) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Backup history</h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>File</th><th>Date &amp; time</th><th>Size</th><th>Created by</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr class="sh-table--empty"><td colspan="6">No data available</td></tr>
      <?php else: foreach ($list as $b): $exists = $b['status'] === 'completed' && is_file(sh_backup_path($b)); ?>
        <tr>
          <td><strong class="sh-code-wrap"><?= e($b['file_name']) ?></strong><?php if ((int)$b['tables_count'] > 0): ?><div class="sh-table__meta"><?= (int)$b['tables_count'] ?> tables · <?= e($b['source']) ?></div><?php endif; ?></td>
          <td class="sh-table__meta"><?= e(date('d M Y H:i', strtotime($b['created_at']))) ?></td>
          <td><?= sh_bytes_human((int)$b['file_size']) ?></td>
          <td class="sh-table__meta"><?= e($b['created_by']) ?></td>
          <td><?php if ($b['status'] === 'completed'): ?><span class="sh-badge <?= $exists ? 'sh-badge--ok' : 'sh-badge--muted' ?>"><?= $exists ? 'Completed' : 'File missing' ?></span>
              <?php else: ?><span class="sh-badge sh-badge--bad">Failed</span><?php if ($b['error']): ?><div class="sh-table__meta"><?= e($b['error']) ?></div><?php endif; ?><?php endif; ?></td>
          <td><div class="sh-actions">
            <?php if ($exists): ?><a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/backups.php?download=' . (int)$b['id'])) ?>"><?= sh_icon('download', 14) ?> Download</a><?php endif; ?>
            <?php if (sh_admin_is_superadmin()): ?><form method="post" data-confirm="Delete this backup permanently?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?></button></form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
