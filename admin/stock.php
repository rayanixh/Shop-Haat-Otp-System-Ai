<?php
/** Inventory overview — low stock list, thresholds, stock adjustments with history. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('products.stock');
sh_admin_schema_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $pid = sh_int($_POST['product_id'] ?? 0);
    $back = 'admin/stock.php' . (sh_get('view') !== '' ? '?view=' . urlencode(sh_get('view')) : '');
    if ($form === 'adjust') {
        $reason = trim(sh_post('reason'));
        $new = sh_int($_POST['new_stock'] ?? -1, -1);
        if ($reason === '') { sh_flash('error', 'Please give a short reason for the stock change.'); sh_redirect($back); }
        if ($new < 0) { sh_flash('error', 'Stock cannot be negative.'); sh_redirect($back); }
        $r = sh_stock_adjust($pid, $new, $reason, 'manual');
        if (!empty($r['ok'])) { sh_flash('success', !empty($r['unchanged']) ? 'Stock is unchanged.' : 'Stock updated from ' . $r['previous'] . ' to ' . $r['new'] . '.'); }
        else { sh_flash('error', $r['error'] ?? 'The stock could not be updated.'); }
        sh_redirect($back);
    }
    if ($form === 'threshold') {
        $th = max(0, sh_int($_POST['threshold'] ?? 5));
        $p = sh_one('SELECT id, name, low_stock_threshold FROM products WHERE id = ?', [$pid]);
        if ($p && (int)$p['low_stock_threshold'] !== $th) {
            sh_update('products', ['low_stock_threshold' => $th], 'id = ?', [$pid]);
            sh_audit('product_updated', 'product', $pid, (string)$p['name'], ['low_stock_threshold' => (int)$p['low_stock_threshold']], ['low_stock_threshold' => $th]);
            sh_stock_check_alert($pid);
            sh_flash('success', 'Low-stock threshold saved.');
        }
        sh_redirect($back);
    }
}

$view = sh_get('view', 'low');
$q = trim(sh_get('q'));
$where = ["p.product_type = 'physical'"]; $args = [];
if ($view === 'low') { $where[] = 'p.stock <= p.low_stock_threshold AND p.stock > 0'; }
elseif ($view === 'out') { $where[] = 'p.stock <= 0'; }
elseif ($view === 'alerts') { $where[] = 'p.stock <= p.low_stock_threshold'; }
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
$rows = sh_all('SELECT p.id, p.name, p.slug, p.sku, p.image, p.stock, p.low_stock_threshold, p.product_type, p.status FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY (p.stock <= 0) DESC, p.stock ASC, p.name ASC LIMIT 300', $args);
$counts = sh_one("SELECT SUM(stock <= 0) o, SUM(stock > 0 AND stock <= low_stock_threshold) l, SUM(stock > low_stock_threshold) i FROM products WHERE product_type = 'physical' AND status = 1") ?? [];
$history = sh_all('SELECT sa.*, p.name AS product_name FROM stock_adjustments sa LEFT JOIN products p ON p.id = sa.product_id ORDER BY sa.id DESC LIMIT 40');
$tabs = ['low' => 'Low stock', 'out' => 'Out of stock', 'alerts' => 'All alerts', 'all' => 'All physical products'];

$adminPage = in_array($view, ['low', 'out', 'alerts'], true) ? 'stock:low' : 'stock';
$adminTitle = 'Inventory';
require __DIR__ . '/_layout.php';
?>
<div class="sh-stats sh-stats--3">
  <div class="sh-stat sh-stat--ok"><div class="sh-stat__icon"><?= sh_icon('check-circle', 20) ?></div><div><div class="sh-stat__value"><?= (int)($counts['i'] ?? 0) ?></div><div class="sh-stat__label">In stock</div></div></div>
  <div class="sh-stat sh-stat--warn"><div class="sh-stat__icon"><?= sh_icon('alert', 20) ?></div><div><div class="sh-stat__value"><?= (int)($counts['l'] ?? 0) ?></div><div class="sh-stat__label">Low stock</div></div></div>
  <div class="sh-stat sh-stat--bad"><div class="sh-stat__icon"><?= sh_icon('x-circle', 20) ?></div><div><div class="sh-stat__value"><?= (int)($counts['o'] ?? 0) ?></div><div class="sh-stat__label">Out of stock</div></div></div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> <?= e($tabs[$view] ?? 'Products') ?> (<?= count($rows) ?>)</h2>
    <div class="sh-seg">
      <?php foreach ($tabs as $k => $l): ?><a class="sh-seg__item <?= $view === $k ? 'is-active' : '' ?>" href="<?= e(sh_url('admin/stock.php?view=' . $k)) ?>"><?= e($l) ?></a><?php endforeach; ?>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get"><input type="hidden" name="view" value="<?= e($view) ?>">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label><input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Product name or SKU"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
      <?php if ($q !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/stock.php?view=' . $view)) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table sh-table--stock">
      <thead><tr><th>Product</th><th>Stock</th><th>Threshold</th><th>Status</th><th>Adjust stock</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="5">No data available</td></tr>
      <?php else: foreach ($rows as $p): $st = sh_stock_status($p); ?>
        <tr>
          <td><div class="sh-prodcell"><img src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy"><div><a href="<?= e(sh_url('admin/products.php?edit=' . (int)$p['id'])) ?>"><strong><?= e($p['name']) ?></strong></a><div class="sh-table__meta"><?= $p['sku'] ? 'SKU ' . e($p['sku']) : '' ?><?= (int)$p['status'] !== 1 ? ' · Hidden' : '' ?></div></div></div></td>
          <td><strong><?= (int)$p['stock'] ?></strong></td>
          <td><form method="post" class="sh-inline-form"><?= sh_csrf_field() ?><input type="hidden" name="form" value="threshold"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input class="sh-input sh-input--xs" type="number" min="0" name="threshold" value="<?= (int)$p['low_stock_threshold'] ?>" aria-label="Low stock threshold"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Save</button></form></td>
          <td><span class="sh-badge <?= e($st['class']) ?>"><?= e(strtoupper($st['label'])) ?></span></td>
          <td><form method="post" class="sh-inline-form"><?= sh_csrf_field() ?><input type="hidden" name="form" value="adjust"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input class="sh-input sh-input--xs" type="number" min="0" name="new_stock" value="<?= (int)$p['stock'] ?>" aria-label="New stock" required>
            <input class="sh-input sh-input--sm" name="reason" placeholder="Reason (required)" maxlength="255" required>
            <button class="sh-btn sh-btn--sm" type="submit">Update</button></form></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Recent stock adjustments</h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Date &amp; time</th><th>Product</th><th>Previous</th><th>New</th><th>Difference</th><th>Reason</th><th>By</th></tr></thead>
      <tbody>
      <?php if (!$history): ?><tr class="sh-table--empty"><td colspan="7">No data available</td></tr>
      <?php else: foreach ($history as $h): $d = (int)$h['difference']; ?>
        <tr>
          <td class="sh-table__meta" style="white-space:nowrap"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
          <td><a href="<?= e(sh_url('admin/products.php?edit=' . (int)$h['product_id'])) ?>"><?= e($h['product_name'] ?? ('#' . (int)$h['product_id'])) ?></a></td>
          <td><?= (int)$h['previous_stock'] ?></td><td><strong><?= (int)$h['new_stock'] ?></strong></td>
          <td><span class="sh-badge <?= $d < 0 ? 'sh-badge--bad' : 'sh-badge--ok' ?>"><?= $d > 0 ? '+' . $d : $d ?></span></td>
          <td class="sh-table__meta"><?= e((string)$h['reason']) ?: '—' ?><?= $h['source'] !== 'manual' ? ' <span class="sh-badge sh-badge--muted">' . e($h['source']) . '</span>' : '' ?></td>
          <td class="sh-table__meta"><?= e((string)$h['admin_email']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
