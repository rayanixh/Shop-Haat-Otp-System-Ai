<?php
/** Settings history & safe configuration restore (built on the audit log). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_perm('system.audit');
sh_admin_schema_ensure();

/** Map a setting key to its admin section. */
function sh_setting_section(string $key): string
{
    $rules = [
        'Page Transition' => '~^transition_~', 'Theme' => '~^theme_~', 'Backups' => '~^backup_~',
        'Payment Settings' => '~^(payment|bkash|nagad|rocket|cod|gateway|currency|delivery_fee|free_delivery)~',
        'Courier API Configuration' => '~^(courier|steadfast|pathao|redx|shipping|verify_)~',
        'Notification Settings' => '~^(telegram|whatsapp|messenger|smtp|mail|email_|notif|sms_)~',
        'Security Settings' => '~^(otp|authentication_mode|auth_|firebase|google_|session|login_|security|recaptcha|textbee)~',
        'AI Settings' => '~^ai_~', 'Homepage' => '~^(home|hero|banner|slider)~',
    ];
    foreach ($rules as $section => $re) { if (preg_match($re, $key)) { return $section; } }
    return 'Website Settings';
}
function sh_history_is_masked(?string $v): bool { return $v !== null && (str_starts_with($v, '••••') || str_starts_with($v, '(empty)')); }
function sh_history_value(?string $v): string
{
    if ($v === null || $v === '') { return '<span class="sh-muted">(empty)</span>'; }
    if (sh_history_is_masked($v)) { return '<span class="sh-badge sh-badge--muted">************</span>'; }
    $v = trim($v, '"');
    return '<code class="sh-code-wrap">' . e(mb_strlen($v) > 160 ? mb_substr($v, 0, 160) . '…' : $v) . '</code>';
}

$restoreId = sh_int($_GET['restore'] ?? $_POST['restore_id'] ?? 0);
$entry = $restoreId > 0 ? sh_one("SELECT * FROM admin_audit_logs WHERE id = ? AND module = 'settings' AND action IN ('settings_changed','settings_restored')", [$restoreId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && sh_post('form') === 'restore') {
    sh_csrf_require();
    if (!sh_admin_is_superadmin()) { sh_admin_deny('Only a Super Admin can restore a configuration version.'); }
    if ($entry === null || $entry['target_label'] === '' || $entry['target_id'] !== null) { sh_flash('error', 'That history entry cannot be restored.'); sh_redirect('admin/settings-history.php'); }
    $key = (string)$entry['target_label'];
    $prev = (string)($entry['old_value'] ?? '');
    if (sh_history_is_masked($prev)) { sh_flash('error', 'Sensitive values are never stored, so this entry cannot be restored. Re-enter the value on its settings page.'); sh_redirect('admin/settings-history.php'); }
    $current = (string)sh_setting($key, '');
    if ($current === $prev) { sh_flash('info', 'The current value already matches that version.'); sh_redirect('admin/settings-history.php'); }
    try {
        sh_setting_save($key, $prev); // writes its own settings_changed entry
        sh_audit('settings_restored', 'settings', null, $key, $current, $prev);
        sh_log_line('admin', 'Setting ' . $key . ' restored from history #' . $restoreId . ' by ' . $admin['email']);
        sh_flash('success', 'Configuration restored: ' . $key . ' now uses the version from ' . date('d M Y, h:i A', strtotime($entry['created_at'])) . '.');
    } catch (Throwable $e) { sh_log_exception($e, 'settings-restore'); sh_flash('error', 'The setting could not be restored.'); }
    sh_redirect('admin/settings-history.php');
}

$q = sh_get('q'); $who = sh_get('admin'); $section = sh_get('section'); $from = sh_get('from'); $to = sh_get('to');
$where = ["module = 'settings'", "action IN ('settings_changed','settings_restored')"]; $args = [];
if ($who !== '') { $where[] = 'admin_email = ?'; $args[] = $who; }
if ($q !== '') { $where[] = '(target_label LIKE ? OR old_value LIKE ? OR new_value LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'created_at <= ?'; $args[] = $to . ' 23:59:59'; }
$whereSql = implode(' AND ', $where);
$page = max(1, sh_int($_GET['page'] ?? 1)); $per = 40;
// Section is derived from the key, so filter it in PHP over a bounded window when requested.
if ($section !== '') {
    $all = sh_all("SELECT * FROM admin_audit_logs WHERE $whereSql ORDER BY id DESC LIMIT 2000", $args);
    $all = array_values(array_filter($all, static fn($r) => sh_setting_section((string)$r['target_label']) === $section));
    $total = count($all); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $rows = array_slice($all, ($page - 1) * $per, $per);
} else {
    $total = (int)sh_val("SELECT COUNT(*) FROM admin_audit_logs WHERE $whereSql", $args, 0);
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $rows = sh_all("SELECT * FROM admin_audit_logs WHERE $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
}
$admins = sh_all("SELECT DISTINCT admin_email FROM admin_audit_logs WHERE module = 'settings' ORDER BY admin_email");
$sections = ['Website Settings', 'Payment Settings', 'Page Transition', 'Theme', 'Courier API Configuration', 'Notification Settings', 'Security Settings', 'Backups', 'AI Settings', 'Homepage'];
$detailId = sh_int($_GET['view'] ?? 0);
$detail = $detailId > 0 ? sh_one("SELECT * FROM admin_audit_logs WHERE id = ? AND module = 'settings'", [$detailId]) : null;

$adminPage = 'settings_history';
$adminTitle = 'Settings History';
require __DIR__ . '/_layout.php';
?>
<?php if ($entry !== null && isset($_GET['restore'])): $key = (string)$entry['target_label']; $cur = (string)sh_setting($key, ''); $masked = sh_history_is_masked($entry['old_value']); ?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('rotate', 17) ?> Restore this configuration?</h2></div>
  <div class="sh-panel__body">
    <?php if (!sh_admin_is_superadmin()): ?><div class="sh-alert sh-alert--error"><?= sh_icon('lock', 16) ?><span>Only a Super Admin can restore a configuration version.</span></div>
    <?php elseif ($masked): ?><div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?><span>This is a sensitive value (API key, token or password). Sensitive values are never stored in history, so they cannot be restored here — re-enter it on its settings page.</span></div>
    <?php else: ?>
    <dl class="sh-cv__kv">
      <dt>Setting</dt><dd><strong><?= e($key) ?></strong> <span class="sh-badge sh-badge--muted"><?= e(sh_setting_section($key)) ?></span></dd>
      <dt>Current value</dt><dd><?= sh_history_value($cur) ?></dd>
      <dt>Value to restore</dt><dd><?= sh_history_value($entry['old_value']) ?></dd>
      <dt>Version from</dt><dd><?= e(date('d M Y, h:i A', strtotime($entry['created_at']))) ?> · changed by <?= e($entry['admin_email']) ?></dd>
    </dl>
    <form method="post" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap" data-confirm="Restore this configuration? A new history entry will be recorded."><?= sh_csrf_field() ?><input type="hidden" name="form" value="restore"><input type="hidden" name="restore_id" value="<?= (int)$entry['id'] ?>">
      <button class="sh-btn" type="submit"><?= sh_icon('rotate', 15) ?> Restore this version</button>
      <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/settings-history.php')) ?>">Cancel</a></form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($detail !== null): ?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('eye', 17) ?> Change details</h2><div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/settings-history.php')) ?>">Close</a></div></div>
  <div class="sh-panel__body">
    <dl class="sh-cv__kv">
      <dt>Setting</dt><dd><strong><?= e($detail['target_label']) ?></strong> · <?= e(sh_setting_section((string)$detail['target_label'])) ?></dd>
      <dt>Action</dt><dd><?= e(sh_audit_action_label($detail['action'])) ?></dd>
      <dt>Previous value</dt><dd><?= sh_history_value($detail['old_value']) ?></dd>
      <dt>New value</dt><dd><?= sh_history_value($detail['new_value']) ?></dd>
      <dt>Admin</dt><dd><?= e($detail['admin_email']) ?></dd>
      <dt>Date &amp; time</dt><dd><?= e(date('d M Y, h:i:s A', strtotime($detail['created_at']))) ?></dd>
      <dt>IP address</dt><dd><?= e((string)$detail['ip_address']) ?: '—' ?></dd>
    </dl>
  </div>
</div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Settings History (<?= number_format($total) ?>)</h2></div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="h-q">Search</label><input class="sh-input" id="h-q" name="q" value="<?= e($q) ?>" placeholder="Setting name or value"></div>
      <div class="sh-field"><label class="sh-field__label" for="h-admin">Admin</label><select class="sh-select" id="h-admin" name="admin"><option value="">All</option><?php foreach ($admins as $a): ?><option value="<?= e($a['admin_email']) ?>" <?= $who === $a['admin_email'] ? 'selected' : '' ?>><?= e($a['admin_email']) ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label class="sh-field__label" for="h-sec">Module</label><select class="sh-select" id="h-sec" name="section"><option value="">All</option><?php foreach ($sections as $s): ?><option value="<?= e($s) ?>" <?= $section === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label class="sh-field__label" for="h-from">From</label><input class="sh-input" id="h-from" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="sh-field"><label class="sh-field__label" for="h-to">To</label><input class="sh-input" id="h-to" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($q !== '' || $who !== '' || $section !== '' || $from !== '' || $to !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/settings-history.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Date &amp; time</th><th>Module</th><th>Setting</th><th>Previous</th><th>New</th><th>Admin</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No data available</td></tr>
      <?php else: foreach ($rows as $r): $restorable = $r['action'] === 'settings_changed' && !sh_history_is_masked($r['old_value']) && $r['target_id'] === null; ?>
        <tr>
          <td class="sh-table__meta" style="white-space:nowrap"><?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></td>
          <td><span class="sh-badge sh-badge--muted"><?= e(sh_setting_section((string)$r['target_label'])) ?></span><?= $r['action'] === 'settings_restored' ? ' <span class="sh-badge sh-badge--ok">Restored</span>' : '' ?></td>
          <td><strong><?= e($r['target_label']) ?></strong></td>
          <td class="sh-audit-val"><?= sh_history_value($r['old_value']) ?></td>
          <td class="sh-audit-val"><?= sh_history_value($r['new_value']) ?></td>
          <td class="sh-table__meta"><?= e($r['admin_email']) ?><br><?= e((string)$r['ip_address']) ?></td>
          <td><div class="sh-actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/settings-history.php?view=' . (int)$r['id'])) ?>">Details</a>
            <?php if ($restorable && sh_admin_is_superadmin()): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/settings-history.php?restore=' . (int)$r['id'])) ?>"><?= sh_icon('rotate', 12) ?> Restore</a><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?><div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/settings-history.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
