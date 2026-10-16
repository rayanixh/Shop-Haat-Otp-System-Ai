<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/cart.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$newUrl = sh_url('admin/coupon-edit.php?new=1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'save') {
        // The coupon form has its own screen now: a save posted here (an open tab
        // from before the change) is forwarded with its id, never dropped.
        sh_flash('info', 'The coupon form has moved to its own page.');
        $id = sh_int($_POST['id'] ?? 0);
        sh_redirect('admin/coupon-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM orders WHERE coupon_id = ?', [$id], 0) > 0) {
            sh_query('UPDATE coupons SET status = 0 WHERE id = ?', [$id]);
            sh_flash('info', 'This coupon has been used on orders, so it was deactivated instead of deleted.');
        } else {
            sh_query('DELETE FROM coupons WHERE id = ?', [$id]);
            sh_flash('success', 'Coupon deleted.');
        }
        sh_redirect('admin/coupons.php');
    }
}


// An old bookmark to ?edit= / ?new= opens the dedicated screen instead of
// silently showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit', 'new']);
    if ($forwardId > 0) { sh_redirect('admin/coupon-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
    if (isset($_GET['new'])) { sh_redirect('admin/coupon-edit.php?new=1' . ($keep !== '' ? '&' . $keep : '')); }
}

$rows = sh_all('SELECT * FROM coupons ORDER BY id DESC');

$adminPage = 'coupons';
$adminTitle = 'Coupons';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Coupons',
    'A coupon used on an order is deactivated instead of deleted, so order history keeps its discount.',
    sh_admin_add_button('Add coupon', $newUrl, '', 'sh-btn sh-btn--sm', 'catalog.manage'),
    'tag'
) ?>

<section class="sh-panel">
  <div class="sh-panel__head">
    <span class="sh-formcard__note"><?= (int)count($rows) ?> coupon<?= count($rows) === 1 ? '' : 's' ?></span>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Code</th><th>Discount</th><th>Conditions</th><th>Used</th><th>Validity</th><th>Status</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="7">
          No coupons yet. Use <strong>Add coupon</strong> above to create the first one.
        </td></tr>
      <?php else: foreach ($rows as $c):
        $expired = $c['expires_at'] && strtotime((string)$c['expires_at']) < time();
        $exhausted = $c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']; ?>
        <tr>
          <td data-label="Code"><code style="font-weight:700;font-size:13px"><?= e($c['code']) ?></code></td>
          <td data-label="Discount"><strong><?= $c['type'] === 'percent' ? (float)$c['value'] . '%' : e(sh_money($c['value'])) ?></strong>
            <?php if ($c['max_discount'] !== null): ?>
              <div class="sh-table__meta">max <?= e(sh_money($c['max_discount'])) ?></div><?php endif; ?></td>
          <td data-label="Conditions" class="sh-table__meta"><?= (float)$c['min_order'] > 0 ? 'Min order ' . e(sh_money($c['min_order'])) : 'No minimum' ?></td>
          <td data-label="Used"><?= (int)$c['used_count'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int)$c['usage_limit'] : '' ?></td>
          <td data-label="Validity" class="sh-table__meta">
            <?php if ($c['expires_at']): ?>Until <?= e(date('d M Y', strtotime((string)$c['expires_at']))) ?>
            <?php else: ?>No end date<?php endif; ?></td>
          <td data-label="Status">
            <?php if ((int)$c['status'] !== 1): ?><span class="sh-statuspill sh-statuspill--off">Disabled</span>
            <?php elseif ($expired): ?><span class="sh-statuspill sh-statuspill--off">Expired</span>
            <?php elseif ($exhausted): ?><span class="sh-statuspill sh-statuspill--off">Limit reached</span>
            <?php else: ?><span class="sh-statuspill sh-statuspill--on">Active</span><?php endif; ?>
          </td>
          <td data-label="Actions"><div class="sh-table__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/coupon-edit.php?edit=' . (int)$c['id'])) ?>" title="Edit <?= e($c['code']) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
            <form method="post" data-confirm="Delete coupon <?= e($c['code']) ?>? A coupon used on orders is deactivated instead."><?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" aria-label="Delete coupon <?= e($c['code']) ?>"><?= sh_icon('trash', 13) ?></button></form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
