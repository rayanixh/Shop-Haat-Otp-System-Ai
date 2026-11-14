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
require_once SH_ROOT . '/includes/product-schema.php';

// SEO columns: the save handler writes to them, so ensure they exist first.
require_once SH_ROOT . '/includes/seo.php';
sh_seo_schema_ensure();
sh_product_schema_ensure();

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

    $productBefore = $id > 0 ? sh_one('SELECT image FROM products WHERE id = ? LIMIT 1', [$id]) : null;
    $savedGallery = $id > 0
        ? sh_product_gallery_rows($id, (string)($productBefore['image'] ?? ''), true)
        : [];
    // A brand-new product needs one photo. A legacy product that has none may
    // still be edited without being forced to invent one; once it has photos,
    // removing every photo is rejected.
    $requireImage = $id <= 0 || $savedGallery !== [];
    foreach (sh_product_gallery_submission_errors($savedGallery, $_POST, $_FILES, $requireImage) as $i => $message) {
        $errors['gallery_' . $i] = $message;
    }
    $plan = ['ok' => false, 'rows' => [], 'primary' => '', 'uploaded' => []];
    $cleanupUploads = static function () use (&$plan): void {
        foreach (($plan['uploaded'] ?? []) as $file) { sh_product_delete_image_file((string)$file); }
        $plan['uploaded'] = [];
    };
    // Upload only when the rest of the form is valid: no orphan files are left
    // behind by an unrelated validation error.
    if (!$errors) {
        $plan = sh_product_gallery_plan($savedGallery, $_POST, $_FILES, $requireImage);
        if (!$plan['ok']) {
            foreach ($plan['errors'] as $i => $message) { $errors['gallery_' . $i] = $message; }
        }
    }
    $image = (string)($plan['primary'] ?? '');

    if (!$errors) {
        $brandId = sh_int($_POST['brand_id'] ?? 0);
        $catalogTypeId = sh_int($_POST['catalog_type_id'] ?? 0);
        $colourIds = isset($_POST['colour_ids']) && is_array($_POST['colour_ids'])
            ? array_slice(array_map('intval', $_POST['colour_ids']), 0, 40) : [];
        $customNames = isset($_POST['custom_colour_name']) && is_array($_POST['custom_colour_name'])
            ? array_slice(array_map('strval', $_POST['custom_colour_name']), 0, 20) : [];
        $customHexes = isset($_POST['custom_colour_hex']) && is_array($_POST['custom_colour_hex'])
            ? array_slice(array_map('strval', $_POST['custom_colour_hex']), 0, 20) : [];
        $data = [
            'category_id' => sh_int($_POST['category_id'] ?? 0) ?: null,
            'name' => sh_post('name'),
            'slug' => $slug,
            'sku' => sh_post('sku') ?: null,
            'short_description' => sh_post('short_description') ?: null,
            'description' => sh_post('description') ?: null,
            'specifications' => sh_post('specifications') ?: null,
            // Alt text for the featured image; empty falls back to the name.
            'image_alt' => mb_substr(trim(sh_post('image_alt')), 0, 190) ?: null,
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
            // SEO copy has no hard character limit; the columns are TEXT.
            'meta_title'          => trim(sh_post('meta_title')) ?: null,
            'meta_description'    => trim(sh_post('meta_description')) ?: null,
            'meta_keywords'       => mb_substr(trim(sh_post('meta_keywords')), 0, 300) ?: null,
            'seo_focus_keyword'   => mb_substr(trim(sh_post('seo_focus_keyword')), 0, 120) ?: null,
            'seo_related_phrases' => mb_substr(trim(sh_post('seo_related_phrases')), 0, 500) ?: null,
            'seo_og_image'        => mb_substr(sh_seo_clean_url(sh_post('seo_og_image')), 0, 190) ?: null,
            'seo_canonical'       => mb_substr(sh_seo_clean_url(sh_post('seo_canonical')), 0, 255) ?: null,
            'seo_noindex'         => !empty($_POST['seo_noindex']) ? 1 : 0,
        ];
        $pdo = sh_db();
        $ownsTransaction = !$pdo->inTransaction();
        try {
            // Admin tooling tables must exist before a transaction: MySQL DDL
            // commits implicitly and would break the product save's rollback.
            sh_admin_schema_ensure();
            if ($ownsTransaction) { $pdo->beginTransaction(); }
            if (trim(sh_post('new_brand')) !== '') {
                $brandId = sh_product_catalog_create_brand(sh_post('new_brand'));
            }
            if (trim(sh_post('new_catalog_type')) !== '') {
                $catalogTypeId = sh_product_catalog_create_type(sh_post('new_catalog_type'));
            }
            if ($id > 0) {
                $before = sh_one('SELECT * FROM products WHERE id = ?', [$id]) ?? [];
                $stockReason = trim(sh_post('stock_reason'));
                $stockChanged = isset($before['stock']) && (int)$before['stock'] !== (int)$data['stock'];
                $applyData = $data;
                if ($stockChanged) { unset($applyData['stock']); } // applied through sh_stock_adjust so the history is recorded
                sh_update('products', $applyData, 'id = ?', [$id]);
                if ($stockChanged) {
                    $stockResult = sh_stock_adjust($id, (int)$data['stock'], $stockReason !== '' ? $stockReason : 'Edited in product form', 'manual');
                    if (empty($stockResult['ok'])) { throw new RuntimeException('The stock history could not be saved.'); }
                }
                $diff = [];
                foreach (['name', 'sku', 'category_id', 'status', 'low_stock_threshold', 'is_featured', 'is_flash_sale', 'compare_price'] as $k) {
                    if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$data[$k]) { $diff[$k] = [$before[$k], $data[$k]]; }
                }
                if (isset($before['price']) && abs((float)$before['price'] - (float)$data['price']) >= 0.005) {
                    sh_audit('price_changed', 'product', $id, (string)$data['name'], (float)$before['price'], (float)$data['price']);
                }
                if ($diff) {
                    sh_audit('product_updated', 'product', $id, (string)$data['name'], array_map(static fn($d) => $d[0], $diff), array_map(static fn($d) => $d[1], $diff));
                }
                sh_flash('success', 'Product "' . $data['name'] . '" updated.');
            } else {
                $id = sh_insert('products', $data);
                sh_audit('product_created', 'product', $id, (string)$data['name'], null, ['price' => $data['price'], 'stock' => $data['stock'], 'type' => 'physical']);
                if ((int)$data['stock'] > 0) {
                    $stockResult = sh_stock_adjust($id, (int)$data['stock'], 'Initial stock', 'initial');
                    if (empty($stockResult['ok'])) { throw new RuntimeException('The initial stock history could not be saved.'); }
                }
                sh_flash('success', 'Product "' . $data['name'] . '" created.');
            }
            // File deletion is intentionally deferred until AFTER commit.
            $oldFiles = sh_product_gallery_commit($id, $plan, $savedGallery, false);
            sh_product_catalog_save_relations(
                $id, $brandId, $catalogTypeId, $colourIds, $customNames, $customHexes
            );
            if ($ownsTransaction) { $pdo->commit(); }
            foreach ($oldFiles as $file) {
                try { sh_product_delete_unreferenced_image((string)$file); }
                catch (Throwable $ignored) { sh_log_exception($ignored, 'product-old-image-cleanup'); }
            }
            // Uploaded files are now referenced by committed DB rows.
            $plan['uploaded'] = [];
            sh_stock_check_alert($id);
            // The product list, its slugs and the sitemap all just changed.
            sh_seo_cache_invalidate();
            sh_redirect($listUrl);
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            sh_log_exception($e, 'product-save');
            $cleanupUploads();
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
$editingId = (int)($editing['id'] ?? 0);
$brands = sh_product_brands_all(true);
$types = sh_product_types_all(true);
$commonColours = sh_product_colours_all(true);
$colourState = sh_product_colour_form_state($editingId, $errors ? $_POST : []);
$brandIdValue = $errors ? sh_int($_POST['brand_id'] ?? 0) : (int)($editing['brand_id'] ?? 0);
$catalogTypeIdValue = $errors ? sh_int($_POST['catalog_type_id'] ?? 0) : (int)($editing['catalog_type_id'] ?? 0);
$galleryRows = $editingId > 0
    ? sh_product_gallery_rows($editingId, (string)($editing['image'] ?? ''), false)
    : [];
$renderRows = $galleryRows;
if ($errors && isset($_POST['image_row']) && is_array($_POST['image_row'])) {
    $byToken = [];
    foreach ($galleryRows as $row) { $byToken['e:' . (int)$row['id']] = $row; }
    $ordered = [];
    foreach ($_POST['image_row'] as $token) {
        $token = (string)$token;
        if (isset($byToken[$token])) { $ordered[] = $byToken[$token]; unset($byToken[$token]); }
    }
    $renderRows = array_merge($ordered, array_values($byToken));
}
$postedRemove = $errors && isset($_POST['image_remove']) && is_array($_POST['image_remove'])
    ? array_map('intval', array_keys($_POST['image_remove'])) : [];
$serverLimit = (int)sh_server_upload_limit();
$galleryMaxBytes = $serverLimit > 0 ? min(SH_PRODUCT_MAX_IMAGE_BYTES, $serverLimit) : SH_PRODUCT_MAX_IMAGE_BYTES;
$customRows = $colourState['custom'];
$customRows[] = ['name' => '', 'hex' => '#888888']; // one no-JS custom-colour row
$adminScripts = ['product_form' => 'assets/js/product-form.js'];
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
      <form method="post" enctype="multipart/form-data" novalidate data-dirty-guard data-product-form>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" id="p-id" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" id="p-current-image" value="<?= e($val('image')) ?>">

        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="p-name">Product name <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="p-name" name="name" required maxlength="190" value="<?= e($val('name')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="p-slug">URL slug</label>
            <input class="sh-input" id="p-slug" name="slug" maxlength="190" value="<?= e($val('slug')) ?>" placeholder="auto-generated from name"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="p-cat">Category <span class="sh-field__req">*</span></label>
            <select class="sh-select" id="p-cat" name="category_id" required>
              <option value="">Select category</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$val('category_id') === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="p-sku">SKU</label>
            <input class="sh-input" id="p-sku" name="sku" maxlength="60" value="<?= e($val('sku')) ?>"></div>
        </div>
        <div class="sh-pa__row">
          <div class="sh-field">
            <label class="sh-field__label" for="p-brand">Brand</label>
            <select class="sh-select" id="p-brand" name="brand_id">
              <option value="0" <?= $brandIdValue === 0 ? 'selected' : '' ?>>Unbranded / No brand</option>
              <?php foreach ($brands as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $brandIdValue === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?><?= (int)$b['status'] !== 1 ? ' (hidden)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="sh-pa__quick">
              <input class="sh-input" name="new_brand" maxlength="120" placeholder="Add a new brand" value="<?= e($errors ? sh_post('new_brand') : '') ?>">
              <a class="sh-pa__link" href="<?= e(sh_url('admin/product-attributes.php#brands')) ?>">Manage brands</a>
            </div>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="p-catalog-type">Product type</label>
            <select class="sh-select" id="p-catalog-type" name="catalog_type_id">
              <option value="0" <?= $catalogTypeIdValue === 0 ? 'selected' : '' ?>>Not specified</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $catalogTypeIdValue === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= (int)$t['status'] !== 1 ? ' (hidden)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="sh-pa__quick">
              <input class="sh-input" name="new_catalog_type" maxlength="120" placeholder="Add a new type" value="<?= e($errors ? sh_post('new_catalog_type') : '') ?>">
              <a class="sh-pa__link" href="<?= e(sh_url('admin/product-attributes.php#types')) ?>">Manage types</a>
            </div>
          </div>
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
        <div class="sh-field">
          <div class="sh-pg" data-product-gallery-editor>
            <div class="sh-pg__head">
              <div class="sh-pg__heading">
                <strong id="p-images-label">Product Images</strong>
                <span class="sh-pg__count" data-pg-count><?= count($renderRows) ?> / <?= SH_PRODUCT_MAX_IMAGES ?> images</span>
              </div>
              <span class="sh-pg__limits">JPG, PNG, WebP or GIF · max <?= e(sh_bytes_label($galleryMaxBytes)) ?> each</span>
            </div>
            <div class="sh-pg__drop" data-pg-input-wrap>
              <label class="sh-pg__dropzone">
                <span class="sh-pg__dropicon" aria-hidden="true"><?= sh_icon('upload', 19) ?></span>
                <span class="sh-pg__droptext">
                  <strong data-pg-drop-title>Add images</strong>
                  <small data-pg-drop-sub>Upload 1–<?= SH_PRODUCT_MAX_IMAGES ?> real photos. New previews appear before you save.</small>
                </span>
                <span class="sh-pg__dropbtn"><?= sh_icon('image', 14) ?> Browse files</span>
                <input class="sh-pg__input" type="file" name="images[]" multiple data-pg-input
                       accept="image/jpeg,image/png,image/webp,image/gif" aria-labelledby="p-images-label">
              </label>
            </div>
            <div class="sh-pg__grid" data-pg-grid role="list" aria-label="Product image previews">
              <?php foreach ($renderRows as $i => $row): ?>
                <?php $rid = (int)$row['id']; $removed = in_array($rid, $postedRemove, true); ?>
                <div class="sh-pg__tile <?= $i === 0 ? 'is-primary' : '' ?><?= $removed ? ' is-removed' : '' ?>" data-pg-tile="existing" data-pg-token="e:<?= $rid ?>" role="listitem">
                  <span class="sh-pg__primary" data-pg-primary <?= $i === 0 ? '' : 'hidden' ?>>PRIMARY</span>
                  <div class="sh-pg__preview">
                    <?php if ($row['url'] !== ''): ?>
                      <img src="<?= e($row['url']) ?>" alt="Saved product image <?= $i + 1 ?>">
                    <?php else: ?>
                      <span class="sh-pg__hint">Image file missing</span>
                    <?php endif; ?>
                  </div>
                  <input type="hidden" name="image_row[]" value="e:<?= $rid ?>">
                  <div class="sh-pg__actions">
                    <button class="sh-pg__btn" type="button" data-pg-move="-1" aria-label="Move image <?= $i + 1 ?> earlier">←</button>
                    <button class="sh-pg__btn" type="button" data-pg-move="1" aria-label="Move image <?= $i + 1 ?> later">→</button>
                    <button class="sh-pg__btn sh-pg__btn--danger" type="button" data-pg-remove-btn aria-pressed="<?= $removed ? 'true' : 'false' ?>"><?= $removed ? 'Undo' : 'Remove' ?></button>
                    <label class="sh-pg__replace" title="Replace this image">
                      <?= sh_icon('refresh', 12) ?> Replace
                      <input class="sh-pg__file" type="file" name="image_replace[<?= $rid ?>]" data-pg-replace
                             accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Replace saved image <?= $i + 1 ?>" <?= $removed ? 'disabled' : '' ?>>
                    </label>
                    <input class="sh-pg__remove-label" type="checkbox" name="image_remove[<?= $rid ?>]" value="1" data-pg-remove <?= $removed ? 'checked' : '' ?>>
                  </div>
                  <div class="sh-pg__removed">Will be removed</div>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="sh-pg__note"><?= sh_icon('info', 13) ?> The first image is the primary image shown in listings. Use ← → to reorder.</p>
            <p class="sh-pg__error" data-pg-error hidden></p>
          </div>
        </div>

        <div class="sh-field">
          <label class="sh-field__label">Product colours</label>
          <div class="sh-pc" data-product-colour-editor>
            <div class="sh-pg__head"><strong>Available colours</strong>
              <a class="sh-pa__link" href="<?= e(sh_url('admin/product-attributes.php#colours')) ?>">Manage swatches</a></div>
            <p class="sh-pg__hint">Select common swatches or add product-specific custom colours. Only selected colours appear to customers and are stored as structured attributes.</p>
            <?php if ($commonColours): ?>
              <div class="sh-pc__list">
                <?php foreach ($commonColours as $c): ?>
                  <label class="sh-pc__chip">
                    <input type="checkbox" name="colour_ids[]" value="<?= (int)$c['id'] ?>"
                           data-colour-option data-colour-name="<?= e($c['name']) ?>"
                           <?= isset($colourState['selected'][(int)$c['id']]) ? 'checked' : '' ?>>
                    <span class="sh-pc__swatch" style="background:<?= e(sh_product_clean_hex($c['hex'])) ?>"></span>
                    <span class="sh-pc__chip-name"><?= e($c['name']) ?><?= (int)$c['status'] !== 1 ? ' (hidden)' : '' ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="sh-pc__custom" data-pc-custom>
              <?php foreach ($customRows as $row): ?>
                <div class="sh-pc__row" data-pc-row>
                  <input class="sh-input" type="text" name="custom_colour_name[]" maxlength="120" data-pc-name
                         value="<?= e($row['name']) ?>" placeholder="Custom colour name" aria-label="Custom colour name">
                  <input type="color" name="custom_colour_hex[]" value="<?= e(sh_product_clean_hex($row['hex'])) ?>" aria-label="Custom colour swatch">
                  <button class="sh-pg__btn sh-pg__btn--danger" type="button" data-pc-remove aria-label="Remove custom colour">×</button>
                </div>
              <?php endforeach; ?>
            </div>
            <template data-pc-template>
              <div class="sh-pc__row" data-pc-row>
                <input class="sh-input" type="text" name="custom_colour_name[]" maxlength="120" data-pc-name
                       value="" placeholder="Custom colour name" aria-label="Custom colour name">
                <input type="color" name="custom_colour_hex[]" value="#888888" aria-label="Custom colour swatch">
                <button class="sh-pg__btn sh-pg__btn--danger" type="button" data-pc-remove aria-label="Remove custom colour">×</button>
              </div>
            </template>
            <div class="sh-pc__tools">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-pc-add><?= sh_icon('plus', 13) ?> Add custom colour</button>
              <span class="sh-pc__summary" data-pc-summary></span>
            </div>
          </div>
        </div>

        <div class="sh-field"><label class="sh-field__label" for="p-img-alt">Image alt text</label>
          <input class="sh-input" id="p-img-alt" name="image_alt" maxlength="190" value="<?= e($val('image_alt')) ?>"
                 placeholder="Describe the product for screen readers and search engines">
          <span class="sh-field__hint">Leave empty to use the product name. AI Auto Work fills this when it generates the draft.</span></div>
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
        // The preview must show THIS product's picture, not a placeholder. The
        // helper returns '' when the file is gone, so the preview can say so.
        $seoImage        = $val('image') !== '' ? sh_seo_product_image(['image' => $val('image')]) : '';
        $seoImageInput   = '';
        $seoImageCurrent = 'p-current-image';
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
          <span class="sh-dirty" data-dirty-note hidden><?= sh_icon('info', 13) ?> Unsaved changes</span>
        </div>
      </form>
    </div>
  </div>
<?php require __DIR__ . '/_footer.php'; ?>
