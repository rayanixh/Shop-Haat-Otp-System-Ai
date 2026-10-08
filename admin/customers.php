<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/firebase.php';
sh_fb_schema_ensure();
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('customers.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $id = sh_int($_POST['id'] ?? 0);
    if (sh_post('form') === 'toggle') {
        if (sh_perm_denied_flash('customers.manage')) { sh_redirect('admin/customers.php?id=' . $id); }
        $u = sh_one('SELECT status FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($u !== null) {
            $new = $u['status'] === 'active' ? 'blocked' : 'active';
            sh_query('UPDATE users SET status = ? WHERE id = ?', [$new, $id]);
            sh_log_line('admin', 'Customer #' . $id . ' set to ' . $new . ' by ' . $admin['email']);
            $un = sh_one('SELECT name FROM users WHERE id = ?', [$id]);
            sh_audit($new === 'active' ? 'customer_activated' : 'customer_blocked', 'customer', $id, (string)($un['name'] ?? ''), $u['status'], $new);
            sh_flash('success', 'Customer account ' . ($new === 'active' ? 'reactivated' : 'blocked') . '.');
        }
        sh_redirect('admin/customers.php?id=' . $id);
    }

    // Phone verification management — superadmin only, always logged.
    if (sh_post('form') === 'verify' || sh_post('form') === 'unverify') {
        if (!sh_admin_is_superadmin()) {
            sh_flash('error', 'Only the store owner can change phone verification.');
        } else {
            $u = sh_one('SELECT phone FROM users WHERE id = ? LIMIT 1', [$id]);
            if ($u !== null) {
                if (sh_post('form') === 'verify') {
                    sh_query('UPDATE users SET phone_verified = 1, phone_verified_at = NOW(), phone_verification_method = ? WHERE id = ?', ['manual', $id]);
                    sh_security_log('phone_verified_manual', $id, ['phone' => sh_phone_mask((string)$u['phone'])]);
                    sh_audit('customer_phone_verified', 'customer', $id, sh_phone_mask((string)$u['phone']), 'unverified', 'verified');
                    sh_flash('success', 'Phone marked as verified.');
                } else {
                    sh_query('UPDATE users SET phone_verified = 0, phone_verified_at = NULL, phone_verification_method = NULL WHERE id = ?', [$id]);
                    sh_security_log('phone_verification_reset', $id, ['phone' => sh_phone_mask((string)$u['phone'])]);
                    sh_audit('customer_phone_unverified', 'customer', $id, sh_phone_mask((string)$u['phone']), 'verified', 'unverified');
                    sh_flash('success', 'Phone verification reset (marked unverified).');
                }
            } else {
                sh_flash('error', 'Customer not found.');
            }
        }
        sh_redirect('admin/customers.php?id=' . $id);
    }
}

$adminPage = 'customers';
$viewId = sh_int($_GET['id'] ?? 0);

if ($viewId > 0) {
    sh_require_perm('customers.details');
    $u = sh_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$viewId]);
    if ($u === null) {
        http_response_code(404);
        $adminTitle = 'Customer not found';
        require __DIR__ . '/_layout.php';
        echo '<div class="sh-panel"><div class="sh-panel__body"><p class="sh-panel__note">This customer does not exist.</p>'
            . '<a class="sh-btn sh-btn--sm" style="margin-top:10px" href="' . e(sh_url('admin/customers.php')) . '">Back</a></div></div>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $orders = sh_all('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 40', [$viewId]);
    $spent = (float)sh_val("SELECT COALESCE(SUM(total),0) FROM orders WHERE user_id = ? AND (payment_status = 'verified' OR status IN ('completed','delivered'))", [$viewId], 0);
    $cs = sh_one("SELECT COUNT(*) total, SUM(status IN ('completed','delivered')) done, SUM(status = 'cancelled') canc, SUM(status = 'returned') ret, MAX(created_at) last_at FROM orders WHERE user_id = ?", [$viewId]) ?? [];
    $custAudit = sh_audit_for('customer', $viewId, 10);
    $addresses = sh_all('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC', [$viewId]);

    $adminTitle = $u['name'];
    require __DIR__ . '/_layout.php';
    ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/customers.php')) ?>"><?= sh_icon('chevron-left', 14) ?> All customers</a>
      <span class="sh-statuspill <?= $u['status'] === 'active' ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= e(ucfirst($u['status'])) ?></span>
    </div>

    <div class="sh-stats">
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('dollar', 20) ?></span>
        <div><div class="sh-stat__value"><?= e(sh_money($spent)) ?></div><div class="sh-stat__label">Verified spend</div></div></div>
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('package', 20) ?></span>
        <div><div class="sh-stat__value"><?= (int)($cs['total'] ?? 0) ?></div><div class="sh-stat__label">Total orders</div></div></div>
      <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('map-pin', 20) ?></span>
        <div><div class="sh-stat__value"><?= count($addresses) ?></div><div class="sh-stat__label">Saved addresses</div></div></div>
      <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('clock', 20) ?></span>
        <div><div class="sh-stat__value" style="font-size:14px"><?= e($u['last_login_at'] ? date('d M Y', strtotime($u['last_login_at'])) : 'Never') ?></div>
          <div class="sh-stat__label">Last sign in</div></div></div>
    </div>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:14px" class="sh-custgrid">
      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
      <div class="sh-panel">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('trending-up', 17) ?> Order summary</h2></div>
        <div class="sh-panel__body">
          <div class="sh-cust-stats">
            <div><b><?= (int)($cs['done'] ?? 0) ?></b><span>Completed / delivered</span></div>
            <div><b><?= (int)($cs['canc'] ?? 0) ?></b><span>Cancelled</span></div>
            <div><b><?= (int)($cs['ret'] ?? 0) ?></b><span>Returned</span></div>
            <div><b style="font-size:13.5px"><?= !empty($cs['last_at']) ? e(date('d M Y', strtotime($cs['last_at']))) : '—' ?></b><span>Last order</span></div>
          </div>
        </div>
      </div>
      <?php if (sh_admin_can('customers.orders')): ?>
      <div class="sh-panel">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Order history</h2></div>
        <div class="sh-tablewrap">
          <table class="sh-table">
            <thead><tr><th>Order</th><th>Total</th><th>Payment</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
            <tbody>
            <?php if (!$orders): ?><tr class="sh-table--empty"><td colspan="5">No data available</td></tr>
            <?php else: foreach ($orders as $o): ?>
              <tr>
                <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>" style="font-weight:600"><?= e($o['order_number']) ?></a>
                  <div class="sh-table__meta"><?= e(date('d M Y', strtotime($o['created_at']))) ?></div></td>
                <td style="font-weight:700"><?= e(sh_money($o['total'])) ?></td>
                <td><span class="sh-badge <?= e(sh_status_class($o['payment_status'])) ?>"><?= e(sh_status_label($o['payment_status'])) ?></span></td>
                <td><span class="sh-badge <?= e(sh_status_class($o['status'])) ?>"><?= e(sh_status_label($o['status'])) ?></span></td>
                <td style="text-align:right"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>">Open</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
      </div>

      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('user', 17) ?> Profile</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.9">
            <strong><?= e($u['name']) ?></strong><br>
            <?= sh_icon('mail', 13) ?> <?= e($u['email']) ?>
            <?php if (!sh_is_synthetic_email((string)$u['email'])): ?>
              <span class="sh-verify-badge <?= !empty($u['email_verified']) ? 'sh-verify-badge--ok' : 'sh-verify-badge--no' ?>"><?= sh_icon(!empty($u['email_verified']) ? 'check-circle' : 'alert', 11) ?> <?= !empty($u['email_verified']) ? 'Email verified' : 'Email not verified' ?></span>
            <?php endif; ?><br>
            <?= sh_icon('phone', 13) ?> <?= e((string)$u['phone']) ?><br>
            <span style="margin:4px 0 0;display:inline-flex">
              <?php if (!empty($u['phone_verified'])): ?>
                <span class="sh-verify-badge sh-verify-badge--ok"><?= sh_icon('check-circle', 12) ?> ✓ Verified</span>
              <?php else: ?>
                <span class="sh-verify-badge sh-verify-badge--no"><?= sh_icon('alert', 12) ?> ✗ Unverified</span>
              <?php endif; ?>
            </span><br>
            <span class="sh-table__meta">Registered <?= e(date('d M Y, h:i A', strtotime($u['created_at']))) ?></span><br>
            <span class="sh-table__meta">Last login: <strong><?= $u['last_login_at'] ? e(date('d M Y, h:i A', strtotime($u['last_login_at']))) : 'Not available' ?></strong></span><br>
            <span class="sh-table__meta">Login Method: <strong><?= e(sh_user_login_method($u)) ?></strong></span>
            <?php if (sh_admin_can('customers.manage')): ?>
            <form method="post" style="margin-top:12px" data-confirm="<?= $u['status'] === 'active' ? 'Block this customer from signing in?' : 'Reactivate this customer?' ?>">
              <?= sh_csrf_field() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="sh-btn sh-btn--sm <?= $u['status'] === 'active' ? 'sh-btn--bad' : '' ?> sh-btn--block" type="submit">
                <?= $u['status'] === 'active' ? sh_icon('lock', 14) . ' Block account' : sh_icon('check-circle', 14) . ' Reactivate account' ?></button>
            </form>
            <?php endif; ?>
            <?php if (sh_admin_is_superadmin()): ?>
              <form method="post" style="margin-top:8px" data-confirm="<?= !empty($u['phone_verified']) ? 'Reset this phone to unverified?' : 'Manually mark this phone as verified?' ?>">
                <?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="<?= !empty($u['phone_verified']) ? 'unverify' : 'verify' ?>">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button class="sh-btn sh-btn--sm <?= !empty($u['phone_verified']) ? 'sh-btn--ghost' : '' ?> sh-btn--block" type="submit">
                  <?= !empty($u['phone_verified']) ? sh_icon('rotate', 14) . ' Reset verification' : sh_icon('check-circle', 14) . ' Verify manually' ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($custAudit): ?>
          <div class="sh-panel">
            <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Admin activity</h2></div>
            <div class="sh-panel__body" style="font-size:12.8px;display:flex;flex-direction:column;gap:6px">
              <?php foreach ($custAudit as $a): ?><div><strong><?= e(sh_audit_action_label($a['action'])) ?></strong> · <?= e($a['admin_email']) ?> · <span class="sh-muted"><?= e(date('d M Y, h:i A', strtotime($a['created_at']))) ?></span></div><?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($addresses): ?>
          <div class="sh-panel">
            <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('map-pin', 17) ?> Addresses</h2></div>
            <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:10px;font-size:12.9px;line-height:1.7">
              <?php foreach ($addresses as $a): ?>
                <div style="border:1px solid var(--sh-line);border-radius:7px;padding:10px">
                  <strong><?= e($a['label']) ?></strong><?= (int)$a['is_default'] === 1 ? ' <span class="sh-badge sh-badge--ok">Default</span>' : '' ?><br>
                  <?= e($a['full_name']) ?>, <?= e($a['phone']) ?><br>
                  <?= e($a['address_line']) ?>, <?= e($a['city']) ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <style>@media (max-width: 1000px){.sh-custgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

$q = sh_get('q');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
$whereSql = implode(' AND ', $where);
$total = (int)sh_val("SELECT COUNT(*) FROM users u WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT u.*,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
        (SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE o.user_id = u.id AND o.payment_status = 'verified') AS spend
     FROM users u WHERE $whereSql ORDER BY u.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);

$adminTitle = 'Customers';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('users', 17) ?> Customers (<?= number_format($total) ?>)</h2></div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
      <?php if ($q !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/customers.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Customer</th><th>Contact</th><th>Orders</th><th>Verified spend</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="6">No customers found.</td></tr>
      <?php else: foreach ($rows as $u): ?>
        <tr>
          <td class="sh-table__name"><?= e($u['name']) ?>
            <div class="sh-table__meta">Joined <?= e(date('d M Y', strtotime($u['created_at']))) ?></div></td>
          <td><?= e(sh_is_synthetic_email((string)$u['email']) ? '' : $u['email']) ?>
            <?php if (!sh_is_synthetic_email((string)$u['email'])): ?>
              <span class="sh-verify-badge <?= !empty($u['email_verified']) ? 'sh-verify-badge--ok' : 'sh-verify-badge--no' ?>" style="margin-left:4px"><?= sh_icon(!empty($u['email_verified']) ? 'check-circle' : 'alert', 11) ?> <?= !empty($u['email_verified']) ? 'Verified' : 'Not verified' ?></span>
            <?php endif; ?>
            <div class="sh-table__meta"><?= e((string)$u['phone']) ?></div>
            <?php if (!empty($u['phone_verified'])): ?>
              <span class="sh-verify-badge sh-verify-badge--ok" style="margin-top:3px"><?= sh_icon('check-circle', 11) ?> ✓ Verified</span>
            <?php endif; ?></td>
          <td><?= (int)$u['order_count'] ?></td>
          <td style="font-weight:700"><?= e(sh_money($u['spend'])) ?></td>
          <td><span class="sh-statuspill <?= $u['status'] === 'active' ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= e(ucfirst($u['status'])) ?></span>
            <div class="sh-table__meta" style="margin-top:4px">Login: <?= e(sh_user_login_method($u)) ?></div></td>
          <td style="text-align:right"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/customers.php?id=' . (int)$u['id'])) ?>">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/customers.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
