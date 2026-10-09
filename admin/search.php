<?php
/** Global admin search — orders, customers, products, payments. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('dashboard.view');

$q = trim(sh_get('q'));
$results = $q !== '' ? sh_admin_search($q, 10) : null;
if ($results !== null) {
    // Only show groups the admin may open.
    foreach (['orders' => 'orders.view', 'customers' => 'customers.view', 'products' => 'products.view', 'payments' => 'payments.view'] as $g => $perm) { if (!sh_admin_can($perm)) { $results[$g] = []; } }
}
$count = $results ? array_sum(array_map('count', $results)) : 0;

$adminPage = 'search';
$adminTitle = 'Global Search';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__body">
    <form class="sh-filterbar" method="get">
      <div class="sh-field" style="flex:1 1 280px"><label class="sh-field__label" for="s-q">Search</label>
        <input class="sh-input" id="s-q" name="q" value="<?= e($q) ?>" placeholder="Order ID, phone, name, email, TrxID, product name or SKU" autocomplete="off"></div>
      <button class="sh-btn" type="submit"><?= sh_icon('search', 15) ?> Search</button>
    </form>
    <?php if ($q !== '' && mb_strlen($q) < 2): ?><p class="sh-form__hint" style="margin-top:10px">Type at least 2 characters.</p><?php endif; ?>
  </div>
</div>

<?php if ($results !== null && mb_strlen($q) >= 2): ?>
  <?php if ($count === 0): ?>
    <div class="sh-panel"><div class="sh-panel__body"><div class="sh-admin-empty"><?= sh_icon('search', 26) ?><p>No data available for “<?= e($q) ?>”.</p></div></div></div>
  <?php else: ?>
  <div class="sh-search-groups">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('package', 16) ?> Orders (<?= count($results['orders']) ?>)</h2>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php?q=' . urlencode($q))) ?>">Open in Orders</a></div>
      <?php if (!$results['orders']): ?><div class="sh-panel__body sh-muted">No data available</div><?php else: ?>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($results['orders'] as $o): ?>
          <tr><td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>"><strong><?= e($o['order_number']) ?></strong></a></td>
            <td><?= e($o['customer_name']) ?><div class="sh-table__meta"><?= e($o['customer_phone']) ?></div></td>
            <td><?= sh_money($o['total']) ?></td>
            <td><span class="sh-badge <?= e(sh_status_class($o['status'])) ?>"><?= e(sh_status_label($o['status'])) ?></span></td>
            <td class="sh-table__meta"><?= e(date('d M Y', strtotime($o['created_at']))) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('users', 16) ?> Customers (<?= count($results['customers']) ?>)</h2>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/customers.php?q=' . urlencode($q))) ?>">Open in Customers</a></div>
      <?php if (!$results['customers']): ?><div class="sh-panel__body sh-muted">No data available</div><?php else: ?>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($results['customers'] as $c): ?>
          <tr><td><a href="<?= e(sh_url('admin/customers.php?id=' . (int)$c['id'])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
            <td><?= e((string)$c['phone']) ?: '—' ?></td><td class="sh-table__meta"><?= e($c['email']) ?></td>
            <td><span class="sh-badge <?= $c['status'] === 'active' ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= e(ucfirst((string)$c['status'])) ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 16) ?> Products (<?= count($results['products']) ?>)</h2>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/products.php?q=' . urlencode($q))) ?>">Open in Products</a></div>
      <?php if (!$results['products']): ?><div class="sh-panel__body sh-muted">No data available</div><?php else: ?>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Stock</th></tr></thead><tbody>
        <?php foreach ($results['products'] as $p): $st = sh_stock_status($p); ?>
          <tr><td><div class="sh-prodcell"><img src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy"><a href="<?= e(sh_url('admin/products.php?edit=' . (int)$p['id'])) ?>"><strong><?= e($p['name']) ?></strong></a></div></td>
            <td class="sh-table__meta"><?= e((string)$p['sku']) ?: '—' ?></td><td><?= sh_money($p['price']) ?></td>
            <td><?= $p['product_type'] === 'digital' ? '<span class="sh-badge sh-badge--muted">Digital</span>' : (int)$p['stock'] . ' <span class="sh-badge ' . e($st['class']) . '">' . e($st['label']) . '</span>' ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 16) ?> Payments (<?= count($results['payments']) ?>)</h2>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/payments.php?q=' . urlencode($q))) ?>">Open in Payments</a></div>
      <?php if (!$results['payments']): ?><div class="sh-panel__body sh-muted">No data available</div><?php else: ?>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Payment</th><th>Order</th><th>TrxID</th><th>Amount</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($results['payments'] as $p): ?>
          <tr><td><a href="<?= e(sh_url('admin/payments.php?id=' . (int)$p['id'])) ?>"><strong>#<?= (int)$p['id'] ?></strong></a><div class="sh-table__meta"><?= e((string)$p['method_name']) ?></div></td>
            <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$p['order_id'])) ?>"><?= e($p['order_number']) ?></a></td>
            <td class="sh-table__meta"><?= e((string)$p['transaction_id']) ?: '—' ?></td><td><?= sh_money($p['amount']) ?></td>
            <td><span class="sh-badge <?= e(sh_status_class($p['status'])) ?>"><?= e(sh_status_label($p['status'])) ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
