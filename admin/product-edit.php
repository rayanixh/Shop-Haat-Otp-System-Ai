<?php
/**
 * Admin — create or edit one product.
 *
 * The product form is long, so it lives on its own screen: admin/products.php
 * keeps the title, the filters and the Add/Edit actions at the top of the
 * listing, and every save lands back on the exact filtered page the operator
 * came from. The handler, validation, stock adjustment, audit entries and SEO
 * writes are unchanged — only their location moved.
 *
 *   admin/product-edit.php            → create
 *   admin/product-edit.php?edit=12    → edit product 12
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

// SEO columns: the save handler writes to them, so ensure they exist first.
require_once SH_ROOT . '/includes/seo.php';
sh_seo_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('products.view');
require_once SH_ROOT . '/includes/admin-tools.php';

$editId = sh_int($_GET['edit'] ?? 0);
$errors = [];

// The listing's search, filters, sort and page, carried through save/cancel.
$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id', 'current_image']);
$listUrl = sh_url('admin/products.php') . ($keepQs !== '' ? '?' . $keepQs : '');

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Same guard order as the listing had: CSRF token first, then the
    // per-action permission check inside the handler below.
    sh_csrf_require();
    $form = sh_post('form');
    // A delete/toggle posted here (e.g. a stale form) belongs to the listing.
    if ($form !== 'save') { sh_redirect($listUrl); }
}

if ($form === 'save') {
    $id = sh_int($_POST['id'] ?? 0);
    if (sh_perm_denied_flash($id > 0 ? 'products.edit' : 'products.create')) { sh_redirect($listUrl); }
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
            'image' => $image ?: null,
            'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
            'is_flash_sale' => !empty($_POST['is_flash_sale']) ? 1 : 0,
            'flash_sale_ends_at' => sh_post('flash_sale_ends_at') !== '' ? str_replace('T', ' ', sh_post('flash_sale_ends_at')) . ':00' : null,
            'status' => !empty($_POST['status']) ? 1 : 0,
            // SEO. Values are validated here rather than trusted: URLs must
            // be http(s) or a site path, and the indexing flag is a 0/1.
            'meta_title'          => mb_substr(trim(sh_post('meta_title')), 0, 190) ?: null,
            'meta_description'    => mb_substr(trim(sh_post('meta_description')), 0, 300) ?: null,
            'meta_keywords'       => mb_substr(trim(sh_post('meta_keywords')), 0, 300) ?: null,
            'seo_focus_keyword'   => mb_substr(trim(sh_post('seo_focus_keyword')), 0, 120) ?: null,
            'seo_related_phrases' => mb_substr(trim(sh_post('seo_related_phrases')), 0, 500) ?: null,
            'seo_og_image'        => mb_substr(sh_seo_clean_url(sh_post('seo_og_image')), 0, 190) ?: null,
            'seo_canonical'       => mb_substr(sh_seo_clean_url(sh_post('seo_canonical')), 0, 255) ?: null,
            'seo_noindex'         => !empty($_POST['seo_noindex']) ? 1 : 0,
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
                foreach (['name', 'sku', 'category_id', 'brand_id', 'status', 'low_stock_threshold', 'is_featured', 'is_flash_sale', 'compare_price'] as $k) {
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
                sh_audit('product_created', 'product', $id, (string)$data['name'], null, ['price' => $data['price'], 'stock' => $data['stock'], 'type' => 'physical']);
                if ((int)$data['stock'] > 0) { sh_stock_adjust($id, (int)$data['stock'], 'Initial stock', 'initial'); }
                sh_flash('success', 'Product "' . $data['name'] . '" created.');
            }
            // The product list, its slugs and the sitemap all just changed.
            sh_seo_cache_invalidate();
            sh_redirect($listUrl);
        } catch (Throwable $e) {
            sh_log_exception($e, 'product-save');
            $errors['general'] = 'The product could not be saved. The error has been logged.';
        }
    }
    $editId = $id;
}

$editing = null;
if ($editId > 0) {
    $editing = sh_one('SELECT * FROM products WHERE id = ? LIMIT 1', [$editId]);
    if ($editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        sh_flash('error', 'That product no longer exists.');
        sh_redirect($listUrl);
    }
}
$isNew = isset($_GET['new']) || ($editId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && $errors);
if ($editing === null && !$isNew) {
    sh_flash('error', 'Choose a product to edit, or add a new one.');
    sh_redirect($listUrl);
}
// A brand-new product with no validation errors yet is expected: the form below
// renders empty defaults in that case.
$showForm = true;
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

$cats = sh_all('SELECT id, name FROM categories ORDER BY name ASC');
$brands = sh_all('SELECT id, name FROM brands ORDER BY name ASC');

$adminPage = 'products';
$adminTitle = $editing !== null ? 'Edit product' : 'New product';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Products' => $listUrl,
    ($editing !== null ? (string)$editing['name'] : 'Add product') => '',
], '', $editing !== null
    ? '<a class="sh-btn sh-btn--sm sh-btn--ghost" target="_blank" rel="noopener" href="'
        . e(sh_url('product.php?slug=' . urlencode((string)$editing['slug']))) . '">' . sh_icon('external', 14) . ' View on store</a>'
    : '') ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error" role="alert"><?= sh_icon('x-circle', 17) ?>
    <div><strong>Please fix the following:</strong><ul class="sh-alert__list"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

  <div class="sh-panel sh-formcard">
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
            <input class="sh-input" id="p-stock" name="stock" type="number" min="0" step="1" value="<?= e($val('stock', '0')) ?>"></div>
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
        <div class="sh-field"><label class="sh-field__label" for="p-img">Product image</label>
          <input class="sh-input" id="p-img" type="file" name="image" accept="image/*">
          <span class="sh-field__hint">PNG, JPG, WebP or GIF. Maximum <?= e(sh_bytes_label(sh_server_upload_limit())) ?> (your server's limit).</span>
          <span class="sh-field__hint">JPG, PNG or WebP. Max 3 MB.</span></div>
        <?php if ($val('image') !== ''): ?>
          <p style="margin:-4px 0 12px"><img src="<?= e(sh_product_image($val('image'))) ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:7px;border:1px solid var(--sh-line)"></p>
        <?php endif; ?>
        <div class="sh-field"><label class="sh-field__label" for="p-short">Short description</label>
          <input class="sh-input" id="p-short" name="short_description" maxlength="255" value="<?= e($val('short_description')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="p-desc">Full description</label>
          <textarea class="sh-textarea" id="p-desc" name="description" rows="6"><?= e($val('description')) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="p-spec">Specifications</label>
          <textarea class="sh-textarea" id="p-spec" name="specifications" rows="4" placeholder="One per line: Label: Value"><?= e($val('specifications')) ?></textarea></div>

        <?php
        // The full SEO editor: title, description, focus keyword, related
        // phrases, keywords, canonical, sharing image, indexing flag and a live
        // preview. $editing holds the saved row on an update.
        $seoPrefix  = 'prod-seo';
        $seoRow     = $editing ?: [
            'name' => (string)$val('name'),
            'slug' => (string)$val('slug'),
            'short_description' => (string)$val('short_description'),
            'description' => (string)$val('description'),
        ];
        $seoLabel   = 'product';
        $seoBaseUrl = sh_seo_base();
        $seoPath    = 'product.php?slug={slug}';
        require __DIR__ . '/_seo-fields.php';
        ?>
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
        <div class="sh-formcard__foot sh-formcard__foot--sticky">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create product' ?></button>
          <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
        </div>
      </form>
    </div>
  </div>
<?php require __DIR__ . '/_footer.php'; ?>
