<?php
/** Admin audit log — who changed what, when, from where. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
sh_admin_schema_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('form') === 'prune') {
        if (!sh_admin_is_superadmin()) { sh_flash('error', 'Only a super admin can delete audit entries.'); sh_redirect('admin/audit-log.php'); }
        $days = max(30, sh_int($_POST['days'] ?? 180));
        try {
            $n = sh_query('DELETE FROM admin_audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days])->rowCount();
            sh_audit('audit_pruned', 'audit', null, 'Entries older than ' . $days . ' days', null, ['deleted' => $n]);
            sh_flash('success', number_format($n) . ' audit entries older than ' . $days . ' days were deleted.');
        } catch (Throwable $e) { sh_log_exception($e, 'audit'); sh_flash('error', 'The entries could not be deleted.'); }
        sh_redirect('admin/audit-log.php');
    }
}

$module = sh_get('module'); $action = sh_get('action'); $who = sh_get('admin'); $q = sh_get('q');
$where = ['1=1']; $args = [];
if ($module !== '') { $where[] = 'module = ?'; $args[] = $module; }
if ($action !== '') { $where[] = 'action = ?'; $args[] = $action; }
if ($who !== '') { $where[] = 'admin_email = ?'; $args[] = $who; }
if ($q !== '') { $where[] = '(target_label LIKE ? OR CAST(target_id AS CHAR) = ? OR ip_address LIKE ? OR old_value LIKE ? OR new_value LIKE ?)'; array_push($args, "%$q%", $q, "%$q%", "%$q%", "%$q%"); }
$whereSql = implode(' AND ', $where);
$page = max(1, sh_int($_GET['page'] ?? 1)); $per = 40;
$total = (int)sh_val("SELECT COUNT(*) FROM admin_audit_logs WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$rows = sh_all("SELECT * FROM admin_audit_logs WHERE $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$modules = sh_all('SELECT DISTINCT module FROM admin_audit_logs ORDER BY module');
$actions = sh_all('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action');
$admins = sh_all('SELECT DISTINCT admin_email FROM admin_audit_logs ORDER BY admin_email');
$links = ['order' => 'admin/orders.php?id=', 'payment' => 'admin/payments.php?id=', 'product' => 'admin/products.php?edit=', 'customer' => 'admin/customers.php?id='];

function sh_audit_value(?string $v): string
{
    if ($v === null || $v === '') { return '—'; }
    $d = json_decode($v, true);
    if (is_array($d)) {
        $parts = [];
        foreach ($d as $k => $val) { $parts[] = e((string)$k) . ': <strong>' . e(is_scalar($val) || $val === null ? (string)$val : json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</strong>'; }
        return implode('<br>', $parts);
    }
    return '<strong>' . e($v) . '</strong>';
}

$adminPage = 'audit';
$adminTitle = 'Admin Audit Log';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <h2 class="sh-panel__title"><?= sh_icon('file-text', 17) ?> Audit Log (<?= number_format($total) ?>)</h2>
    <?php if (sh_admin_is_superadmin()): ?>
      <form method="post" class="sh-inline-form" data-confirm="Delete audit entries older than the selected period? This cannot be undone."><?= sh_csrf_field() ?><input type="hidden" name="form" value="prune">
        <select class="sh-select sh-select--sm" name="days"><option value="90">older than 90 days</option><option value="180" selected>older than 180 days</option><option value="365">older than 1 year</option></select>
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Delete</button></form>
    <?php endif; ?>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-module">Module</label><select class="sh-select" id="f-module" name="module"><option value="">All</option>
        <?php foreach ($modules as $m): ?><option value="<?= e($m['module']) ?>" <?= $module === $m['module'] ? 'selected' : '' ?>><?= e(ucfirst($m['module'])) ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-action">Action</label><select class="sh-select" id="f-action" name="action"><option value="">All</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>><?= e(sh_audit_action_label($a['action'])) ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-admin">Admin</label><select class="sh-select" id="f-admin" name="admin"><option value="">All</option>
        <?php foreach ($admins as $a): ?><option value="<?= e($a['admin_email']) ?>" <?= $who === $a['admin_email'] ? 'selected' : '' ?>><?= e($a['admin_email']) ?></option><?php endforeach; ?></select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label><input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Target, value or IP"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($module !== '' || $action !== '' || $who !== '' || $q !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/audit-log.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Date &amp; time</th><th>Admin</th><th>Action</th><th>Target</th><th>Previous</th><th>New</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No data available</td></tr>
      <?php else: foreach ($rows as $r): $href = isset($links[$r['module']]) && $r['target_id'] ? sh_url($links[$r['module']] . (int)$r['target_id']) : ''; ?>
        <tr>
          <td class="sh-table__meta" style="white-space:nowrap"><?= e(date('d M Y H:i:s', strtotime($r['created_at']))) ?></td>
          <td class="sh-table__meta"><?= e($r['admin_email']) ?></td>
          <td><span class="sh-badge <?= str_contains($r['action'], 'delete') || str_contains($r['action'], 'reject') || str_contains($r['action'], 'block') ? 'sh-badge--bad' : 'sh-badge--muted' ?>"><?= e(sh_audit_action_label($r['action'])) ?></span></td>
          <td><span class="sh-table__meta"><?= e(ucfirst($r['module'])) ?></span><br><?php if ($href): ?><a href="<?= e($href) ?>"><?php endif; ?><strong><?= e($r['target_label'] ?: ($r['target_id'] ? '#' . (int)$r['target_id'] : '—')) ?></strong><?php if ($href): ?></a><?php endif; ?></td>
          <td class="sh-audit-val"><?= sh_audit_value($r['old_value']) ?></td>
          <td class="sh-audit-val"><?= sh_audit_value($r['new_value']) ?></td>
          <td class="sh-table__meta"><?= e((string)$r['ip_address']) ?: '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?><div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/audit-log.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
