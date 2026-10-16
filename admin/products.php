<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

require_once SH_ROOT . '/includes/admin-tools.php';
// The listing reads the SEO columns (the noindex marker) and links into the
// editor, which owns the save handler.
require_once SH_ROOT . '/includes/seo.php';
sh_seo_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('products.view');

$editId = sh_int($_GET['edit'] ?? 0);

// The listing never renders a create/edit form: both live on
// admin/product-edit.php, which keeps this page short at every screen size.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Old bookmarks to ?edit= / ?new= keep working: they now open the editor
    // with the current filters carried across.
    $keep = sh_admin_keep_query(['edit', 'new']);
    if ($editId > 0) {
        sh_redirect('admin/product-edit.php?edit=' . $editId . ($keep !== '' ? '&' . $keep : ''));
    }
    if (isset($_GET['new'])) {
        sh_redirect('admin/product-edit.php?new=1' . ($keep !== '' ? '&' . $keep : ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if (sh_perm_denied_flash('products.delete')) { sh_redirect('admin/products.php'); }
        $used = (int)sh_val('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id], 0);
        $delTarget = sh_one('SELECT id, name, price, stock, status FROM products WHERE id = ?', [$id]);
        if ($used > 0) {
            // Keep order history intact — deactivate rather than destroy.
            sh_query('UPDATE products SET status = 0 WHERE id = ?', [$id]);
            if ($delTarget) { sh_audit('product_updated', 'product', $id, (string)$delTarget['name'], ['status' => (int)$delTarget['status']], ['status' => 0, 'reason' => 'deactivated instead of deleted (used in orders)']); }
            sh_flash('info', 'This product appears in existing orders, so it was deactivated instead of deleted.');
        } else {
            sh_query('DELETE FROM products WHERE id = ?', [$id]);
            if ($delTarget) { sh_audit('product_deleted', 'product', $id, (string)$delTarget['name'], ['price' => $delTarget['price'], 'stock' => (int)$delTarget['stock']], null); }
            sh_flash('success', 'Product deleted.');
        }
        sh_seo_cache_invalidate();
        sh_redirect('admin/products.php');
    }

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        if (sh_perm_denied_flash('products.edit')) { sh_redirect('admin/products.php'); }
        $tg = sh_one('SELECT name, status FROM products WHERE id = ?', [$id]);
        sh_query('UPDATE products SET status = 1 - status WHERE id = ?', [$id]);
        if ($tg) { sh_audit('product_updated', 'product', $id, (string)$tg['name'], ['visible' => (int)$tg['status']], ['visible' => 1 - (int)$tg['status']]); }
        sh_seo_cache_invalidate();
        sh_flash('success', 'Product visibility updated.');
        sh_redirect('admin/products.php?' . http_build_query(array_diff_key($_GET, ['edit' => 1])));
    }

    // The form itself now lives on its own screen (admin/product-edit.php), so a
    // save posted to the listing is handed straight to the editor. Keeping the
    // branch here means an in-flight form from a cached page still saves.
    if ($form === 'save') {
        // The form now lives on admin/product-edit.php, so this can only be a
        // tab that was open before the change. Nothing is stored from the body:
        // the operator is sent to the editor and told to submit again.
        $id = sh_int($_POST['id'] ?? 0);
        sh_flash('info', 'The product form has moved to its own page. Please submit your changes there.');
        sh_redirect('admin/product-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }
}

$q = sh_get('q');$q = sh_get('q');
$fCat = sh_int($_GET['category'] ?? 0);
$fStatus = sh_get('status');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 20;

$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($fCat > 0) { $where[] = 'p.category_id = ?'; $args[] = $fCat; }
if ($fStatus === 'active') { $where[] = 'p.status = 1'; }
if ($fStatus === 'inactive') { $where[] = 'p.status = 0'; }
if ($fStatus === 'low') { $where[] = "p.product_type = 'physical' AND p.stock <= p.low_stock_threshold AND p.stock > 0"; }
if ($fStatus === 'out') { $where[] = "p.product_type = 'physical' AND p.stock <= 0"; }
$whereSql = implode(' AND ', $where);

$total = (int)sh_val("SELECT COUNT(*) FROM products p WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT p.*, c.name AS category_name, b.name AS brand_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN brands b ON b.id = p.brand_id
     WHERE $whereSql ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);
$cats = sh_all('SELECT id, name FROM categories ORDER BY name ASC');
$brands = sh_all('SELECT id, name FROM brands ORDER BY name ASC');

$adminPage = 'products';
$adminTitle = 'Products';
require __DIR__ . '/_layout.php';
?>
<?php
// If uploaded images cannot be served, say so plainly here rather than letting
// every product silently fall back to the "No image" placeholder.
$shUploadCheck = sh_uploads_reachable('products');
if (empty($shUploadCheck['ok']) && (int)$shUploadCheck['status'] !== 0): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('alert', 17) ?>
    <div><strong>Uploaded images cannot be displayed.</strong>
      <p style="margin-top:4px"><?= e((string)($shUploadCheck['error'] ?? '')) ?></p>
      <p class="sh-table__meta" style="margin-top:4px">Tested: <code><?= e((string)($shUploadCheck['url'] ?? '')) ?></code>
        &rarr; HTTP <?= (int)$shUploadCheck['status'] ?></p>
    </div>
  </div>
<?php endif; ?>

<?php
// Current filters, kept so Edit and Add keep the operator's place in the list.
$listQuery = sh_admin_keep_query(['edit', 'new']);
?>
<?= sh_admin_header(
    'Products',
    'Everything the storefront sells. The table shows the filtered set; use the buttons on the right of a row to edit, show/hide or delete.',
    sh_admin_add_button('Add product', sh_url('admin/product-edit.php?new=1') . ($listQuery !== '' ? '&' . $listQuery : ''), '', 'sh-btn sh-btn--sm', 'products.create'),
    'box'
) ?>

<div class="sh-panel" style="margin-bottom:14px">
  <div class="sh-panel__body" style="padding-bottom:12px">
    <form class="sh-filterbar sh-filterbar--top" method="get" action="<?= e(sh_url('admin/products.php')) ?>">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Name or SKU" autocomplete="off"></div>
      <div class="sh-field"><label class="sh-field__label" for="f-cat">Category</label>
        <select class="sh-select" id="f-cat" name="category">
          <option value="">All</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $fCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-st">Status</label>
        <select class="sh-select" id="f-st" name="status">
          <option value="">All</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Visible</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Hidden</option>
          <option value="low" <?= $fStatus === 'low' ? 'selected' : '' ?>>Low stock</option>
          <option value="out" <?= $fStatus === 'out' ? 'selected' : '' ?>>Out of stock</option>
        </select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($q !== '' || $fCat || $fStatus !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/products.php')) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Products
      <span class="sh-table__meta"><?= e(sh_admin_range_label($total, $per, $page)) ?></span></h2>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="6">No products match these filters.</td></tr>
      <?php else: foreach ($rows as $p): ?>
        <tr>
          <td data-label="Product">
            <div class="sh-table__cell">
              <img class="sh-table__thumb" src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy">
              <div style="min-width:0">
                <div class="sh-table__name"><?= e($p['name']) ?></div>
                <div class="sh-table__meta"><?= e($p['sku'] ?: 'No SKU') ?>
                  <?php if ((int)$p['is_featured'] === 1): ?> · Featured<?php endif; ?>
                  <?php if ((int)$p['is_flash_sale'] === 1): ?> · Flash<?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td data-label="Category"><?= e($p['category_name'] ?? '—') ?></td>
          <td data-label="Price"><strong><?= e(sh_money($p['price'])) ?></strong>
            <?php if ((float)$p['compare_price'] > (float)$p['price']): ?>
              <div class="sh-table__meta"><s><?= e(sh_money($p['compare_price'])) ?></s></div><?php endif; ?></td>
          <td data-label="Stock">
            <?php $st = sh_stock_status($p); ?>
            <strong><?= (int)$p['stock'] ?></strong>
            <div><span class="sh-badge <?= e($st['class']) ?>" style="font-size:10.5px"><?= e(strtoupper($st['label'])) ?></span></div>
          </td>
          <td data-label="Status"><span class="sh-statuspill <?= (int)$p['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
            <?= (int)$p['status'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
          <td>
            <div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" title="Edit <?= e($p['name']) ?>" href="<?= e(sh_url('admin/product-edit.php?edit=' . (int)$p['id'] . ($listQuery !== '' ? '&' . $listQuery : ''))) ?>"><?= sh_icon('pencil', 13) ?><span class="sh-table__actionlabel"> Edit</span></a>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="<?= (int)$p['status'] === 1 ? 'Hide' : 'Show' ?>"><?= sh_icon('eye', 13) ?></button>
              </form>
              <form method="post" data-confirm="Delete &quot;<?= e($p['name']) ?>&quot;?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/products.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
