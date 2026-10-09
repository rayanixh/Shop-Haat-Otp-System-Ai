<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('products.view');

$editId = sh_int($_GET['edit'] ?? 0);
$errors = [];

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
        sh_redirect('admin/products.php');
    }

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        if (sh_perm_denied_flash('products.edit')) { sh_redirect('admin/products.php'); }
        $tg = sh_one('SELECT name, status FROM products WHERE id = ?', [$id]);
        sh_query('UPDATE products SET status = 1 - status WHERE id = ?', [$id]);
        if ($tg) { sh_audit('product_updated', 'product', $id, (string)$tg['name'], ['visible' => (int)$tg['status']], ['visible' => 1 - (int)$tg['status']]); }
        sh_flash('success', 'Product visibility updated.');
        sh_redirect('admin/products.php?' . http_build_query(array_diff_key($_GET, ['edit' => 1])));
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        if (sh_perm_denied_flash($id > 0 ? 'products.edit' : 'products.create')) { sh_redirect('admin/products.php'); }
        $v = new ShValidator($_POST);
        $v->required('name', 'Product name')->maxLen('name', 190, 'Product name')
          ->required('price', 'Price')
          ->custom('price', is_numeric(sh_post('price')) && (float)sh_post('price') >= 0, 'Price must be a number of 0 or more.')
          ->custom('compare_price', sh_post('compare_price') === '' || is_numeric(sh_post('compare_price')), 'Compare-at price must be a number.')
          ->custom('stock', sh_post('stock') === '' || (ctype_digit(sh_post('stock'))), 'Stock must be a whole number of 0 or more.')
          ->custom('low_stock_threshold', sh_post('low_stock_threshold') === '' || ctype_digit(sh_post('low_stock_threshold')), 'Low-stock threshold must be a whole number of 0 or more.')
          ->required('category_id', 'Category');
        $errors = $v->errors();

        $slug = sh_post('slug') !== '' ? sh_slug(sh_post('slug')) : sh_slug(sh_post('name'));
        if ($slug === '') { $errors['slug'] = 'Could not build a URL slug from that name.'; }
        $dupe = sh_one('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $id]);
        if ($dupe) { $errors['slug'] = 'Another product already uses the URL slug "' . $slug . '".'; }

        $image = sh_post('current_image');
        if (!empty($_FILES['image']['name'])) {
            $up = sh_upload_image($_FILES['image'], 'products');
            if (!empty($up['ok'])) { $image = $up['file']; }
            else { $errors['image'] = $up['error'] ?? 'The image could not be uploaded.'; }
        }

        if (!$errors) {
            $data = [
                'category_id' => sh_int($_POST['category_id'] ?? 0) ?: null,
                'brand_id' => sh_int($_POST['brand_id'] ?? 0) ?: null,
                'name' => sh_post('name'),
                'slug' => $slug,
                'sku' => sh_post('sku') ?: null,
                'short_description' => sh_post('short_description') ?: null,
                'description' => sh_post('description') ?: null,
                'specifications' => sh_post('specifications') ?: null,
                'price' => (float)sh_post('price'),
                // compare_price is NOT NULL in the schema; 0 means "no compare-at price".
                'compare_price' => sh_post('compare_price') !== '' ? (float)sh_post('compare_price') : 0,
                'stock' => max(0, sh_int($_POST['stock'] ?? 0)),
                'low_stock_threshold' => max(0, sh_int($_POST['low_stock_threshold'] ?? 5)),
                'product_type' => sh_post('product_type') === 'digital' ? 'digital' : 'physical',
                'image' => $image ?: null,
                'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
                'is_flash_sale' => !empty($_POST['is_flash_sale']) ? 1 : 0,
                'flash_sale_ends_at' => sh_post('flash_sale_ends_at') !== '' ? str_replace('T', ' ', sh_post('flash_sale_ends_at')) . ':00' : null,
                'status' => !empty($_POST['status']) ? 1 : 0,
                'meta_title' => sh_post('meta_title') ?: null,
                'meta_description' => sh_post('meta_description') ?: null,
            ];
            try {
                if ($id > 0) {
                    $before = sh_one('SELECT * FROM products WHERE id = ?', [$id]) ?? [];
                    $stockReason = trim(sh_post('stock_reason'));
                    $stockChanged = isset($before['stock']) && (int)$before['stock'] !== (int)$data['stock'];
                    $applyData = $data;
                    if ($stockChanged) { unset($applyData['stock']); } // applied through sh_stock_adjust so the history is recorded
                    sh_update('products', $applyData, 'id = ?', [$id]);
                    if ($stockChanged) { sh_stock_adjust($id, (int)$data['stock'], $stockReason !== '' ? $stockReason : 'Edited in product form', 'manual'); }
                    $diff = [];
                    foreach (['name', 'sku', 'category_id', 'brand_id', 'product_type', 'status', 'low_stock_threshold', 'is_featured', 'is_flash_sale', 'compare_price'] as $k) {
                        if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$data[$k]) { $diff[$k] = [$before[$k], $data[$k]]; }
                    }
                    if (isset($before['price']) && abs((float)$before['price'] - (float)$data['price']) >= 0.005) {
                        sh_audit('price_changed', 'product', $id, (string)$data['name'], (float)$before['price'], (float)$data['price']);
                    }
                    if ($diff) {
                        sh_audit('product_updated', 'product', $id, (string)$data['name'], array_map(static fn($d) => $d[0], $diff), array_map(static fn($d) => $d[1], $diff));
                    }
                    sh_stock_check_alert($id);
                    sh_flash('success', 'Product "' . $data['name'] . '" updated.');
                } else {
                    $id = sh_insert('products', $data);
                    sh_audit('product_created', 'product', $id, (string)$data['name'], null, ['price' => $data['price'], 'stock' => $data['stock'], 'type' => $data['product_type']]);
                    if ((int)$data['stock'] > 0) { sh_stock_adjust($id, (int)$data['stock'], 'Initial stock', 'initial'); }
                    sh_flash('success', 'Product "' . $data['name'] . '" created.');
                }
                sh_redirect('admin/products.php?edit=' . $id);
            } catch (Throwable $e) {
                sh_log_exception($e, 'product-save');
                $errors['general'] = 'The product could not be saved. The error has been logged.';
            }
        }
        $editId = $id;
    }
}

$editing = null;
if ($editId > 0) {
    $editing = sh_one('SELECT * FROM products WHERE id = ? LIMIT 1', [$editId]);
    if ($editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        sh_flash('error', 'That product no longer exists.');
        sh_redirect('admin/products.php');
    }
}
$isNew = isset($_GET['new']) || ($editId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && $errors);
$showForm = $editing !== null || $isNew;
// On validation failure keep the operator's typed values.
$val = static function (string $k, $fallback = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    if ($editing !== null && isset($editing[$k])) { return (string)$editing[$k]; }
    return (string)$fallback;
};
$chk = static function (string $k, bool $default = false) use ($editing, $errors): bool {
    if ($errors) { return !empty($_POST[$k]); }
    if ($editing !== null) { return (int)($editing[$k] ?? 0) === 1; }
    return $default;
};

$q = sh_get('q');
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
    "SELECT p.*, c.name AS category_name, b.name AS brand_name,
            (SELECT COUNT(*) FROM product_codes pc WHERE pc.product_id = p.id AND pc.status = 'available') AS codes_left
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
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><strong>Please fix the following:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

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

<?php if ($showForm): ?>
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
        <?= $editing ? 'Edit product: ' . e($editing['name']) : 'New product' ?></h2>
      <div class="sh-panel__actions">
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" target="_blank" rel="noopener"
             href="<?= e(sh_url('product.php?slug=' . urlencode($editing['slug']))) ?>"><?= sh_icon('external', 14) ?> View on store</a>
        <?php endif; ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/products.php')) ?>">Close</a>
      </div>
    </div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="current_image" value="<?= e($val('image')) ?>">

        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="p-name">Product name <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="p-name" name="name" required maxlength="190" value="<?= e($val('name')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="p-slug">URL slug</label>
            <input class="sh-input" id="p-slug" name="slug" maxlength="190" value="<?= e($val('slug')) ?>" placeholder="auto-generated from name"></div>
        </div>
        <div class="sh-grid3">
          <div class="sh-field"><label class="sh-field__label" for="p-cat">Category <span class="sh-field__req">*</span></label>
            <select class="sh-select" id="p-cat" name="category_id" required>
              <option value="">Select category</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$val('category_id') === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="p-brand">Brand</label>
            <select class="sh-select" id="p-brand" name="brand_id">
              <option value="">No brand</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= (int)$val('brand_id') === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="p-sku">SKU</label>
            <input class="sh-input" id="p-sku" name="sku" maxlength="60" value="<?= e($val('sku')) ?>"></div>
        </div>
        <div class="sh-grid3">
          <div class="sh-field"><label class="sh-field__label" for="p-price">Selling price <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="p-price" name="price" required inputmode="decimal" value="<?= e($val('price')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="p-cmp">Compare-at price</label>
            <input class="sh-input" id="p-cmp" name="compare_price" inputmode="decimal" value="<?= e($val('compare_price')) ?>">
            <span class="sh-field__hint">Shown struck through. Leave blank for no discount.</span></div>
          <div class="sh-field"><label class="sh-field__label" for="p-stock">Stock quantity</label>
            <input class="sh-input" id="p-stock" name="stock" type="number" min="0" step="1" value="<?= e($val('stock', '0')) ?>">
            <span class="sh-field__hint">Digital products use the codes pool instead.</span></div>
        </div>
        <div class="sh-grid3">
          <div class="sh-field"><label class="sh-field__label" for="p-th">Low-stock threshold</label>
            <input class="sh-input" id="p-th" name="low_stock_threshold" type="number" min="0" step="1" value="<?= e($val('low_stock_threshold', '5')) ?>">
            <span class="sh-field__hint">Shows LOW STOCK at or below this quantity.</span></div>
          <?php if ($editing !== null): ?>
          <div class="sh-field"><label class="sh-field__label" for="p-sreason">Reason for stock change</label>
            <input class="sh-input" id="p-sreason" name="stock_reason" maxlength="255" placeholder="e.g. New purchase received">
            <span class="sh-field__hint">Recorded in the stock history when the quantity changes.</span></div>
          <div class="sh-field"><label class="sh-field__label">Stock status</label>
            <?php $stNow = sh_stock_status($editing); ?>
            <div><span class="sh-badge <?= e($stNow['class']) ?>"><?= e(strtoupper($stNow['label'])) ?></span>
              <a class="sh-table__meta" style="margin-left:8px" href="<?= e(sh_url('admin/stock.php?view=all&q=' . urlencode((string)$editing['name']))) ?>">Stock history</a></div></div>
          <?php endif; ?>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="p-type">Product type</label>
            <select class="sh-select" id="p-type" name="product_type">
              <option value="physical" <?= $val('product_type', 'physical') === 'physical' ? 'selected' : '' ?>>Physical — shipped to customer</option>
              <option value="digital" <?= $val('product_type') === 'digital' ? 'selected' : '' ?>>Digital — delivered as a code</option>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="p-img">Product image</label>
            <input class="sh-input" id="p-img" type="file" name="image" accept="image/*">
            <span class="sh-field__hint">PNG, JPG, WebP or GIF. Maximum <?= e(sh_bytes_label(sh_server_upload_limit())) ?> (your server's limit).</span>
            <span class="sh-field__hint">JPG, PNG or WebP. Max 3 MB.</span></div>
        </div>
        <?php if ($val('image') !== ''): ?>
          <p style="margin:-4px 0 12px"><img src="<?= e(sh_product_image($val('image'))) ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:7px;border:1px solid var(--sh-line)"></p>
        <?php endif; ?>
        <div class="sh-field"><label class="sh-field__label" for="p-short">Short description</label>
          <input class="sh-input" id="p-short" name="short_description" maxlength="255" value="<?= e($val('short_description')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="p-desc">Full description</label>
          <textarea class="sh-textarea" id="p-desc" name="description" rows="6"><?= e($val('description')) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="p-spec">Specifications</label>
          <textarea class="sh-textarea" id="p-spec" name="specifications" rows="4" placeholder="One per line: Label: Value"><?= e($val('specifications')) ?></textarea></div>

        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="p-mt">Meta title</label>
            <input class="sh-input" id="p-mt" name="meta_title" maxlength="190" value="<?= e($val('meta_title')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="p-md">Meta description</label>
            <input class="sh-input" id="p-md" name="meta_description" maxlength="255" value="<?= e($val('meta_description')) ?>"></div>
        </div>
        <div class="sh-grid3">
          <label class="sh-check"><input type="checkbox" name="status" value="1" <?= $chk('status', true) ? 'checked' : '' ?>><span>Visible on store</span></label>
          <label class="sh-check"><input type="checkbox" name="is_featured" value="1" <?= $chk('is_featured') ? 'checked' : '' ?>><span>Featured product</span></label>
          <label class="sh-check"><input type="checkbox" name="is_flash_sale" value="1" <?= $chk('is_flash_sale') ? 'checked' : '' ?>><span>Include in flash sale</span></label>
        </div>
        <div class="sh-field" style="max-width:280px;margin-top:10px">
          <label class="sh-field__label" for="p-flash">Flash sale ends at</label>
          <input class="sh-input" id="p-flash" type="datetime-local" name="flash_sale_ends_at"
                 value="<?= e($val('flash_sale_ends_at') !== '' ? date('Y-m-d\TH:i', strtotime($val('flash_sale_ends_at'))) : '') ?>">
        </div>
        <button class="sh-btn sh-btn--lg" type="submit"><?= sh_icon('check-circle', 16) ?> <?= $editing ? 'Save changes' : 'Create product' ?></button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> All products (<?= number_format($total) ?>)</h2>
    <div class="sh-panel__actions">
      <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/products.php?new=1')) ?>"><?= sh_icon('plus', 14) ?> Add product</a>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Name or SKU"></div>
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
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="6">No products match these filters.</td></tr>
      <?php else: foreach ($rows as $p):
        $digital = $p['product_type'] === 'digital'; ?>
        <tr>
          <td>
            <div class="sh-table__cell">
              <img class="sh-table__thumb" src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy">
              <div style="min-width:0">
                <div class="sh-table__name"><?= e($p['name']) ?></div>
                <div class="sh-table__meta"><?= e($p['sku'] ?: 'No SKU') ?>
                  <?php if ($digital): ?> · Digital<?php endif; ?>
                  <?php if ((int)$p['is_featured'] === 1): ?> · Featured<?php endif; ?>
                  <?php if ((int)$p['is_flash_sale'] === 1): ?> · Flash<?php endif; ?>
                </div>
              </div>
            </div>
          </td>
          <td><?= e($p['category_name'] ?? '—') ?></td>
          <td><strong><?= e(sh_money($p['price'])) ?></strong>
            <?php if ((float)$p['compare_price'] > (float)$p['price']): ?>
              <div class="sh-table__meta"><s><?= e(sh_money($p['compare_price'])) ?></s></div><?php endif; ?></td>
          <td>
            <?php if ($digital): ?>
              <strong style="color:<?= (int)$p['codes_left'] === 0 ? '#c33' : 'inherit' ?>"><?= (int)$p['codes_left'] ?></strong>
              <div class="sh-table__meta">codes left</div>
            <?php else: ?>
              <?php $st = sh_stock_status($p); ?>
              <strong><?= (int)$p['stock'] ?></strong>
              <div><span class="sh-badge <?= e($st['class']) ?>" style="font-size:10.5px"><?= e(strtoupper($st['label'])) ?></span></div>
            <?php endif; ?>
          </td>
          <td><span class="sh-statuspill <?= (int)$p['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
            <?= (int)$p['status'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
          <td>
            <div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/products.php?edit=' . (int)$p['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
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
