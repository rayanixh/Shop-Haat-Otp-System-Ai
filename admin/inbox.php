<?php
/** Admin notifications inbox (in-panel only; no browser push). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('system.notifications');
sh_admin_schema_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    try {
        if ($form === 'read_all') {
            sh_query('UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0');
            sh_flash('success', 'All notifications marked as read.');
        } elseif ($form === 'read') {
            sh_query('UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?', [sh_int($_POST['id'] ?? 0)]);
        } elseif ($form === 'unread') {
            sh_query('UPDATE admin_notifications SET is_read = 0, read_at = NULL WHERE id = ?', [sh_int($_POST['id'] ?? 0)]);
        } elseif ($form === 'clear_read' && sh_admin_is_superadmin()) {
            sh_query('DELETE FROM admin_notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
            sh_flash('success', 'Read notifications older than 30 days were removed.');
        }
    } catch (Throwable $e) { sh_log_exception($e, 'admin-inbox'); sh_flash('error', 'The action could not be completed.'); }
    $back = sh_post('back') !== '' ? (string)($_SERVER['HTTP_REFERER'] ?? '') : '';
    if ($back !== '' && str_starts_with($back, sh_site_url())) { header('Location: ' . $back); exit; }
    sh_redirect('admin/inbox.php' . (sh_post('filter') === 'unread' ? '?filter=unread' : ''));
}

// Open: mark read and jump to the related item.
$open = sh_int($_GET['open'] ?? 0);
if ($open > 0) {
    $n = sh_one('SELECT * FROM admin_notifications WHERE id = ?', [$open]);
    if ($n) {
        sh_query('UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?', [$open]);
        $url = (string)($n['url'] ?? '');
        if ($url !== '' && !preg_match('~^(https?:)?//~i', $url)) { sh_redirect(ltrim($url, '/')); }
    }
    sh_redirect('admin/inbox.php');
}

$filter = sh_get('filter') === 'unread' ? 'unread' : 'all';
$type = sh_get('type');
$where = ['1=1']; $args = [];
if ($filter === 'unread') { $where[] = 'is_read = 0'; }
if ($type !== '') { $where[] = 'type = ?'; $args[] = $type; }
$whereSql = implode(' AND ', $where);
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 30;
$total = (int)sh_val("SELECT COUNT(*) FROM admin_notifications WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all("SELECT * FROM admin_notifications WHERE $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$unread = sh_admin_notifications_unread_count();
$types = ['new_order' => 'New orders', 'payment_pending' => 'Payments awaiting verification', 'payment_failed' => 'Failed payments', 'low_stock' => 'Low stock',
    'out_of_stock' => 'Out of stock', 'returned' => 'Returned orders', 'new_customer' => 'New customers', 'courier_check' => 'Courier verification', 'backup' => 'Backups'];

$adminPage = 'inbox';
$adminTitle = 'Notifications';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <h2 class="sh-panel__title"><?= sh_icon('bell', 17) ?> Notifications <?php if ($unread): ?><span class="sh-badge sh-badge--warn"><?= (int)$unread ?> unread</span><?php endif; ?></h2>
    <div class="sh-actions">
      <?php if ($unread): ?>
        <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="read_all"><input type="hidden" name="filter" value="<?= e($filter) ?>"><button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 14) ?> Mark all as read</button></form>
      <?php endif; ?>
      <?php if (sh_admin_is_superadmin()): ?>
        <form method="post" data-confirm="Remove read notifications older than 30 days?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="clear_read"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Clear old</button></form>
      <?php endif; ?>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-filter">Show</label>
        <select class="sh-select" id="f-filter" name="filter"><option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option><option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread only</option></select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-type">Type</label>
        <select class="sh-select" id="f-type" name="type"><option value="">All types</option>
          <?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($filter !== 'all' || $type !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/inbox.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-panel__body">
    <?php if (!$rows): ?>
      <div class="sh-admin-empty"><?= sh_icon('bell', 26) ?><p>No data available</p></div>
    <?php else: ?>
      <div class="sh-inbox">
        <?php foreach ($rows as $n): ?>
          <div class="sh-inbox__item <?= (int)$n['is_read'] ? '' : 'is-unread' ?>">
            <span class="sh-inbox__icon"><?= sh_icon(sh_admin_notification_icon((string)$n['type']), 17) ?></span>
            <div class="sh-inbox__text">
              <strong><?= e($n['title']) ?></strong>
              <?php if ($n['body']): ?><span><?= e($n['body']) ?></span><?php endif; ?>
              <small><?= e($types[$n['type']] ?? ucwords(str_replace('_', ' ', (string)$n['type']))) ?> · <?= e(date('d M Y H:i', strtotime($n['created_at']))) ?> · <?= e(sh_time_ago($n['created_at'])) ?></small>
            </div>
            <div class="sh-inbox__actions">
              <?php if ($n['url']): ?><a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/inbox.php?open=' . (int)$n['id'])) ?>">Open</a><?php endif; ?>
              <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>">
                <input type="hidden" name="form" value="<?= (int)$n['is_read'] ? 'unread' : 'read' ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= (int)$n['is_read'] ? 'Mark unread' : 'Mark read' ?></button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?><div style="margin-top:14px"><?= sh_paginate($total, $per, $page, sh_url('admin/inbox.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
