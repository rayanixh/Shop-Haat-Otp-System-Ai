<?php
/**
 * Admin — create or edit one product category.
 *
 * A dedicated screen rather than a form appended below the listing. The
 * handler, validation, upload rules, SEO fields and redirects are the ones the
 * old inline form used, so nothing about the stored data changes — only where
 * the form lives and how it is laid out.
 *
 *   admin/category-edit.php             → create
 *   admin/category-edit.php?edit=12     → edit category 12
 *
 * The listing's current search/filters are carried through so Cancel and Save
 * return the operator to the page they were on.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/seo.php';
require_once SH_ROOT . '/includes/category-image.php';
sh_seo_schema_ensure();
sh_category_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$editId      = sh_int($_GET['edit'] ?? 0);
$iconChoices = ['zap', 'home', 'tag', 'shopping-cart', 'heart', 'key', 'list', 'trending-up', 'box', 'grid', 'star', 'truck'];
$errors      = [];
$notice      = '';

// The list page's query string, kept across save/cancel so filters survive.
$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id', 'remove_image']);
$listUrl = sh_url('admin/categories.php') . ($keepQs !== '' ? '?' . $keepQs : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        $count = (int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id], 0);
        $children = (int)sh_val('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id], 0);
        if ($count > 0 || $children > 0) {
            sh_flash('error', $count > 0
                ? 'This category still holds ' . $count . ' product(s). Move them first.'
                : 'This category has ' . $children . ' sub-category(ies). Move or delete those first.');
            sh_redirect('admin/category-edit.php?edit=' . $id);
        }
        $row = sh_one('SELECT name, image, image_orig FROM categories WHERE id = ? LIMIT 1', [$id]);
        sh_query('DELETE FROM categories WHERE id = ?', [$id]);
        if ($row !== null) {
            sh_category_delete_file($row['image'] ?? null);
            if (!empty($row['image_orig']) && $row['image_orig'] !== ($row['image'] ?? null)) {
                sh_category_delete_file($row['image_orig']);
            }
            sh_audit('category_deleted', 'category', $id, (string)$row['name'], ['name' => $row['name']], null);
        }
        sh_seo_cache_invalidate();
        sh_flash('success', 'Category deleted.');
        sh_redirect($listUrl);
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $editingRow = $id > 0 ? sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]) : null;
        if ($id > 0 && $editingRow === null) {
            sh_flash('error', 'That category no longer exists.');
            sh_redirect($listUrl);
        }

        $v = new ShValidator($_POST);
        $v->required('name', 'Category name')->maxLen('name', 120, 'Category name');
        $errors = $v->errors();

        $slug = sh_post('slug') !== '' ? sh_slug(sh_post('slug')) : sh_slug(sh_post('name'));
        if ($slug === '') { $errors['slug'] = 'Could not build a URL slug.'; }
        if (sh_one('SELECT id FROM categories WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $id])) {
            $errors['slug'] = 'Another category already uses the slug "' . $slug . '".';
        }

        $parent = sh_int($_POST['parent_id'] ?? 0);
        if ($parent > 0 && $parent === $id) { $errors['parent_id'] = 'A category cannot be its own parent.'; }
        if ($parent > 0 && $id > 0) {
            // A category must not be moved underneath one of its own descendants.
            $descendants = sh_all('SELECT id, parent_id FROM categories', []);
            $byId = [];
            foreach ($descendants as $d) { $byId[(int)$d['id']] = $d['parent_id'] !== null ? (int)$d['parent_id'] : null; }
            $walk = $parent;
            $guard = 0;
            while ($walk !== null && $guard++ < 50) {
                if ($walk === $id) { $errors['parent_id'] = 'A category cannot be moved inside one of its own sub-categories.'; break; }
                $walk = $byId[$walk] ?? null;
            }
        }

        // Image first: a replacement is validated (and uploaded) before the old
        // file is touched, and the old file is deleted only after the row saves.
        $imgResult = sh_category_resolve_image($editingRow, $_POST, $_FILES);
        if ($imgResult['error'] !== null) { $errors['image'] = $imgResult['error']; }

        if ($errors && $imgResult['uploaded'] !== null) {
            // Nothing was stored, so do not leave an orphan file behind.
            sh_category_delete_file($imgResult['uploaded']);
            if (!empty($imgResult['uploaded_orig']) && $imgResult['uploaded_orig'] !== $imgResult['uploaded']) {
                sh_category_delete_file($imgResult['uploaded_orig']);
            }
            $imgResult['uploaded'] = null;
        }

        if (!$errors) {
            $data = [
                'parent_id' => $parent ?: null,
                'name' => sh_post('name'),
                'slug' => $slug,
                'icon' => in_array(sh_post('icon'), $iconChoices, true) ? sh_post('icon') : 'grid',
                'image' => $imgResult['file'],
                'image_orig' => $imgResult['orig_file'] ?? ($editingRow['image_orig'] ?? $imgResult['file']),
                'image_crop' => $imgResult['crop_data'] ?? ($editingRow['image_crop'] ?? null),
                'description' => sh_post('description') ?: null,
                // Card size: only one of the three known tokens is ever stored.
                // sh_category_card_size() maps anything else to "medium", so a
                // tampered POST cannot write a class name, CSS or markup.
                'card_size' => sh_category_card_size($_POST['card_size'] ?? 'medium'),
                'sort_order' => sh_int($_POST['sort_order'] ?? 0),
                'status' => !empty($_POST['status']) ? 1 : 0,
                // SEO columns. Each value is validated rather than stored raw:
                // URLs must be http(s) or a site path, and the flag is a 0/1.
                // SEO copy has no hard character limit; the columns are TEXT.
                'meta_title'           => trim(sh_post('meta_title')) ?: null,
                'meta_description'     => trim(sh_post('meta_description')) ?: null,
                'meta_keywords'        => mb_substr(trim(sh_post('meta_keywords')), 0, 300) ?: null,
                'seo_focus_keyword'    => mb_substr(trim(sh_post('seo_focus_keyword')), 0, 120) ?: null,
                'seo_related_phrases'  => mb_substr(trim(sh_post('seo_related_phrases')), 0, 500) ?: null,
                'seo_og_image'         => mb_substr(sh_seo_clean_url(sh_post('seo_og_image')), 0, 190) ?: null,
                'seo_canonical'        => mb_substr(sh_seo_clean_url(sh_post('seo_canonical')), 0, 255) ?: null,
                'seo_noindex'          => !empty($_POST['seo_noindex']) ? 1 : 0,
            ];
            try {
                if ($id > 0) {
                    $before = $editingRow ?? [];
                    sh_update('categories', $data, 'id = ?', [$id]);
                    // The row now points at the new file (or at nothing), so the
                    // previous image can safely be removed.
                    if ($imgResult['action'] !== 'keep') {
                        $old = (string)($before['image'] ?? '');
                        if ($old !== '' && $old !== (string)($data['image'] ?? '') && $old !== (string)($data['image_orig'] ?? '')) {
                            sh_category_delete_file($old);
                        }
                        if ($imgResult['action'] === 'remove') {
                            $oldOrig = (string)($before['image_orig'] ?? '');
                            if ($oldOrig !== '' && $oldOrig !== $old) {
                                sh_category_delete_file($oldOrig);
                            }
                        }
                    }
                    $diff = [];
                    foreach (['name', 'slug', 'parent_id', 'status', 'sort_order', 'card_size'] as $k) {
                        if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$data[$k]) {
                            $diff[$k] = [$before[$k], $data[$k]];
                        }
                    }
                    if ((string)($before['image'] ?? '') !== (string)($data['image'] ?? '')) { $diff['image'] = [$before['image'] ?? null, $data['image']]; }
                    if ($diff) {
                        sh_audit('category_updated', 'category', $id, (string)$data['name'],
                            array_map(static fn($d) => $d[0], $diff), array_map(static fn($d) => $d[1], $diff));
                    }
                    sh_flash('success', 'Category "' . $data['name'] . '" updated.');
                } else {
                    $id = sh_insert('categories', $data);
                    sh_audit('category_created', 'category', $id, (string)$data['name'], null, ['name' => $data['name'], 'slug' => $data['slug']]);
                    sh_flash('success', 'Category "' . $data['name'] . '" created.');
                }
                // The sitemap and its cached copy both depend on this row.
                sh_seo_cache_invalidate();
                sh_redirect($listUrl);
            } catch (Throwable $e) {
                // The row was not written, so a just-uploaded file is orphaned:
                // remove it instead of leaving it unreferenced on disk.
                if ($imgResult['uploaded'] !== null) { sh_category_delete_file($imgResult['uploaded']); }
                sh_log_exception($e, 'category-save');
                $errors['general'] = 'The category could not be saved. The error has been logged.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That category no longer exists.');
    sh_redirect($listUrl);
}
$isEdit = $editing !== null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$chkStatus = $errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1;
$curImage  = sh_category_image($val('image'));
// The saved size wins; a validation failure keeps what was just posted.
$cardSize  = sh_category_card_size($errors ? ($_POST['card_size'] ?? 'medium') : ($editing['card_size'] ?? 'medium'));
$sizePhoto = $curImage !== '' ? $curImage : sh_category_placeholder_image();
$sizeName  = trim((string)$val('name')) !== '' ? trim((string)$val('name')) : 'Example category';
$storedImage = trim((string)$val('image'));
$missingImage = $curImage === '' && $storedImage !== '';
$curOrigImage = sh_category_orig_image($editing['image_orig'] ?? null, $editing['image'] ?? null);

// Parent choices: top-level categories only, never the row being edited.
$parents = sh_all('SELECT id, name FROM categories WHERE parent_id IS NULL AND (id <> ?) ORDER BY name ASC', [(int)($editing['id'] ?? 0)]);
$childCount = $isEdit ? (int)sh_val('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [(int)$editing['id']], 0) : 0;
$productCount = $isEdit ? (int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [(int)$editing['id']], 0) : 0;

$adminPage = 'categories';
$adminTitle = $isEdit ? 'Edit category' : 'New category';
$adminScripts = ['assets/js/category-cropper.js', 'assets/js/category-size.js'];
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Categories' => $listUrl,
    ($isEdit ? (string)($editing['name'] ?? 'Edit category') : 'Add category') => '',
]) ?>

<?= sh_admin_error_summary($errors) ?>
<?php if ($notice !== ''): ?>
  <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?><div><?= e($notice) ?></div></div>
<?php endif; ?>

<?php if ($isEdit): ?>
  <div class="sh-stats" style="margin-bottom:14px">
    <div class="sh-stat"><span class="sh-stat__label">Products in this category</span>
      <span class="sh-stat__value"><?= (int)$productCount ?></span>
      <?php if ($productCount > 0): ?><a class="sh-stat__hint" href="<?= e(sh_url('admin/products.php?category=' . (int)$editing['id'])) ?>">Manage products</a><?php endif; ?>
    </div>
    <div class="sh-stat"><span class="sh-stat__label">Sub-categories</span>
      <span class="sh-stat__value"><?= (int)$childCount ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Storefront page</span>
      <span class="sh-stat__value" style="font-size:13px">
        <a href="<?= e(sh_url('category.php?slug=' . urlencode((string)$editing['slug']))) ?>" target="_blank" rel="noopener">
          <?= sh_icon('external', 13) ?> Open</a></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Status</span>
      <span class="sh-stat__value" style="font-size:14px"><?= (int)$editing['status'] === 1 ? 'Visible' : 'Hidden' ?></span></div>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate id="sh-cat-form" data-dirty-guard>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
  <input type="hidden" name="keep" value="<?= e($keepQs) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__body">

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('tag', 15) ?> Name and URL</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Category name',
              '<input class="sh-input" id="c-name" name="name" required maxlength="120" value="' . e($val('name')) . '" autocomplete="off">',
              '', $errors['name'] ?? null, 'c-name', true) ?>
          <?= sh_admin_field('URL slug',
              '<input class="sh-input" id="c-slug" name="slug" maxlength="120" value="' . e($val('slug')) . '" placeholder="auto-generated from the name" autocomplete="off">',
              'Leave empty to build it from the name. Changing this changes the public URL.', $errors['slug'] ?? null, 'c-slug') ?>
        </div>
        <div class="sh-grid2">
          <?= sh_admin_field('Parent category',
              '<select class="sh-select" id="c-parent" name="parent_id"><option value="">Top level</option>'
              . implode('', array_map(static function (array $p) use ($val): string {
                    $sel = (int)$val('parent_id') === (int)$p['id'] ? ' selected' : '';
                    return '<option value="' . (int)$p['id'] . '"' . $sel . '>' . e((string)$p['name']) . '</option>';
                }, $parents))
              . '</select>',
              'Sub-categories appear as their own cards on the parent category page.', $errors['parent_id'] ?? null, 'c-parent') ?>
          <?= sh_admin_field('Sort order',
              '<input class="sh-input" id="c-sort" name="sort_order" type="number" step="1" value="' . e($val('sort_order', '0')) . '">',
              'Lower numbers appear first in the storefront.', null, 'c-sort') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('image', 15) ?> Category image</h3>
        <?php if ($missingImage): ?>
          <div class="sh-alert sh-alert--error"><?= sh_icon('alert', 17) ?>
            <div>The saved image file is missing from the server, so the neutral placeholder is shown on the storefront. Upload a replacement below.</div></div>
        <?php endif; ?>
        <div class="sh-catimage">
          <div class="sh-catimage__preview">
            <img id="cat-preview" src="<?= e($curImage !== '' ? $curImage : sh_category_placeholder_image()) ?>" alt="Category image preview"
                 data-placeholder-src="<?= e(sh_category_placeholder_image()) ?>"
                 class="<?= $curImage === '' ? 'is-placeholder' : '' ?>">
            <span class="sh-catimage__cap" id="cat-preview-cap"><?= $curImage !== '' ? 'Current image' : 'No image — placeholder shown' ?></span>
            <button type="button" class="sh-btn sh-btn--sm" id="btn-crop-image"
                    data-orig-src="<?= e($curOrigImage) ?>"
                    style="margin-top:8px; width:100%; justify-content:center; <?= $curImage === '' ? 'display:none;' : '' ?>">
              <?= sh_icon('crop', 14) ?> Edit / Crop Image
            </button>
            <span class="sh-badge" id="cat-crop-indicator"
                  style="margin-top:6px; font-size:11px; <?= !empty($editing['image_crop']) ? '' : 'display:none;' ?>">
              <?= sh_icon('check-circle', 11) ?> Custom crop saved
            </span>
          </div>
          <div class="sh-catimage__fields">
            <input type="hidden" name="crop_data" id="c-crop-data" value="<?= e($val('image_crop')) ?>">
            <input type="hidden" name="cropped_image_data" id="c-cropped-image-data">
            <?= sh_admin_field('Upload a new image',
                '<input class="sh-input" id="c-img" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-image-preview="cat-preview">',
                'JPG, PNG, WebP or GIF, up to ' . e(sh_bytes_label(sh_server_upload_limit())) . '. A 4:3 or square image looks best. Use the crop editor to reposition the visible subject.',
                $errors['image'] ?? null, 'c-img') ?>
            <?php if ($isEdit && $storedImage !== ''): ?>
              <label class="sh-check">
                <input type="checkbox" name="remove_image" value="1" data-image-remove="cat-preview">
                <span>Remove the current image (the neutral placeholder is used instead)</span></label>
              <p class="sh-field__hint">Your existing image is kept unless you upload a replacement or tick this box.</p>
            <?php endif; ?>
            <div class="sh-field" style="margin-top:10px">
              <label class="sh-field__label" for="c-icon">Small icon (menus only)</label>
              <select class="sh-select" id="c-icon" name="icon">
                <?php foreach ($iconChoices as $ic): ?>
                  <option value="<?= e($ic) ?>" <?= $val('icon', 'grid') === $ic ? 'selected' : '' ?>><?= e($ic) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="sh-field__hint">Used in the navigation menus. Category cards always use the image above.</span>
            </div>
          </div>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('grid', 15) ?> Category card size</h3>
        <p class="sh-formsec__desc">
          How this category is drawn in the storefront category grids (homepage and category pages).
          Each category keeps its own size, so the grid can mix them. <strong>Medium</strong> is the default.
        </p>
        <div class="sh-catsize" data-cat-size-group role="radiogroup" aria-label="Category card size">
          <?php foreach (sh_category_card_sizes() as $sizeKey => $sizeLabel): ?>
            <label class="sh-catsize__opt<?= $cardSize === $sizeKey ? ' is-on' : '' ?>">
              <input type="radio" name="card_size" value="<?= e($sizeKey) ?>"
                     <?= $cardSize === $sizeKey ? 'checked' : '' ?> data-cat-size>
              <span class="sh-catsize__label"><span class="sh-catsize__dot" aria-hidden="true"></span><?= e($sizeLabel) ?></span>
              <span class="sh-catsize__preview" aria-hidden="true">
                <span class="sh-category-card sh-category-card--<?= e($sizeKey) ?>">
                  <span class="sh-category-card__media">
                    <img class="sh-category-card__img<?= $curImage === '' ? ' sh-category-card__img--fallback' : '' ?>"
                         src="<?= e($sizePhoto) ?>" alt="" loading="lazy" decoding="async">
                  </span>
                  <span class="sh-category-card__name" title="<?= e($sizeName) ?>"><?= e($sizeName) ?></span>
                </span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
        <p class="sh-field__hint">The previews use this category's own picture and name at their real size.</p>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('file-text', 15) ?> Description and visibility</h3>
        <?= sh_admin_field('Description',
            '<textarea class="sh-textarea" id="c-desc" name="description" rows="3">' . e($val('description')) . '</textarea>',
            'Shown at the top of the category page and used as the page description when no meta description is set.',
            null, 'c-desc') ?>
        <label class="sh-check">
          <input type="checkbox" name="status" value="1" <?= $chkStatus ? 'checked' : '' ?>>
          <span>Visible in the storefront navigation and category grid</span></label>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('search', 15) ?> Search engine fields</h3>
        <p class="sh-formsec__desc">Optional. Empty fields fall back to the generated values, exactly as before.</p>
        <?php
        // The same shared SEO block the product editor uses. $editing holds the
        // saved row, so an existing value always wins over the placeholder.
        $seoPrefix  = 'cat-seo';
        $seoRow     = $editing ?: ['name' => (string)$val('name'), 'slug' => (string)$val('slug')];
        $seoLabel   = 'category';
        $seoBaseUrl = sh_seo_base();
        $seoPath    = 'category.php?slug={slug}';
        // The category's own picture, so the preview matches the real page.
        $seoImage   = sh_seo_category_image(['image' => (string)($seoRow['image'] ?? '')]);
        require __DIR__ . '/_seo-fields.php';
        ?>
      </div>
    </div>

    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isEdit ? 'Save changes' : 'Create category' ?></button>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      <span class="sh-dirty" data-dirty-note hidden><?= sh_icon('info', 13) ?> Unsaved changes</span>
      <?php if ($isEdit): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-cat-delete"
                data-confirm="Delete &quot;<?= e((string)$editing['name']) ?>&quot;? Products and sub-categories must be moved first.">
          <?= sh_icon('trash', 15) ?> Delete category</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <form method="post" id="sh-cat-delete" hidden>
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<!-- Category Image Crop & Position Modal -->
<div class="sh-modal sh-cropper-modal" id="modal-category-cropper" hidden>
  <div class="sh-modal__scrim" data-cropper-cancel></div>
  <div class="sh-modal__card sh-cropper-modal__card">
    <div class="sh-cropper-modal__head">
      <h3><?= sh_icon('crop', 18) ?> Crop &amp; Position Category Image</h3>
      <p class="sh-cropper-modal__sub">Drag to reposition, use handles to adjust visible area, or zoom. Previews update live.</p>
      <button type="button" class="sh-modal__x" data-cropper-cancel aria-label="Close"><?= sh_icon('x', 18) ?></button>
    </div>
    <div class="sh-cropper-modal__body">
      <div class="sh-cropper-layout">
        <div class="sh-cropper-pane">
          <div class="sh-cropper-viewport" id="cropper-viewport">
            <img id="cropper-image" alt="Image to crop" draggable="false">
            <div class="sh-cropper-box" id="cropper-box">
              <div class="sh-cropper-grid"><span></span><span></span><span></span><span></span></div>
              <div class="sh-cropper-handle sh-cropper-handle--nw" data-dir="nw"></div>
              <div class="sh-cropper-handle sh-cropper-handle--ne" data-dir="ne"></div>
              <div class="sh-cropper-handle sh-cropper-handle--se" data-dir="se"></div>
              <div class="sh-cropper-handle sh-cropper-handle--sw" data-dir="sw"></div>
              <div class="sh-cropper-handle sh-cropper-handle--n" data-dir="n"></div>
              <div class="sh-cropper-handle sh-cropper-handle--s" data-dir="s"></div>
              <div class="sh-cropper-handle sh-cropper-handle--w" data-dir="w"></div>
              <div class="sh-cropper-handle sh-cropper-handle--e" data-dir="e"></div>
            </div>
          </div>
          <div class="sh-cropper-zoombar">
            <button type="button" class="sh-cropper-toolbtn" id="crop-zoom-out" title="Zoom Out"><?= sh_icon('minus', 14) ?></button>
            <input type="range" class="sh-cropper-slider" id="crop-zoom-slider" min="1" max="3" step="0.05" value="1" aria-label="Zoom level">
            <button type="button" class="sh-cropper-toolbtn" id="crop-zoom-in" title="Zoom In"><?= sh_icon('plus', 14) ?></button>
            <span class="sh-cropper-zoomval" id="crop-zoom-val">100%</span>
            <button type="button" class="sh-cropper-toolbtn sh-cropper-toolbtn--text" id="crop-fit-btn">Fit</button>
          </div>
        </div>
        <div class="sh-cropper-sidebar">
          <div class="sh-cropper-group">
            <label class="sh-cropper-label">Target Format</label>
            <div class="sh-cropper-aspects">
              <button type="button" class="sh-cropper-aspect is-active" data-aspect="4:3">4:3 Card</button>
              <button type="button" class="sh-cropper-aspect" data-aspect="1:1">1:1 Square</button>
              <button type="button" class="sh-cropper-aspect" data-aspect="16:9">16:9 Banner</button>
              <button type="button" class="sh-cropper-aspect" data-aspect="free">Free</button>
            </div>
          </div>
          <div class="sh-cropper-group">
            <label class="sh-cropper-label">Live Storefront Card</label>
            <div class="sh-category-card sh-cropper-card-preview">
              <span class="sh-category-card__media">
                <canvas id="crop-live-card" width="400" height="300" class="sh-category-card__img"></canvas>
              </span>
              <span class="sh-category-card__name" id="crop-live-name"><?= e((string)$val('name', 'Category Name')) ?></span>
            </div>
          </div>
          <div class="sh-cropper-group">
            <label class="sh-cropper-label">Navigation Menu (1:1)</label>
            <div style="display:flex;align-items:center;gap:10px;padding:6px 10px;background:var(--sh-bg-2);border-radius:6px;border:1px solid var(--sh-line);">
              <canvas id="crop-live-nav" width="40" height="40" style="width:24px;height:24px;border-radius:4px;object-fit:cover;"></canvas>
              <span style="font-size:13px;font-weight:600;color:var(--sh-ink);" id="crop-live-navname"><?= e((string)$val('name', 'Category Name')) ?></span>
            </div>
          </div>
          <p class="sh-field__hint" style="margin-top:auto;font-size:11.5px;line-height:1.4;">
            Position the main subject in the center or focus area. What you see in the live preview is exactly what customers will see.
          </p>
        </div>
      </div>
    </div>
    <div class="sh-cropper-modal__foot">
      <button type="button" class="sh-btn sh-btn--ghost" id="crop-btn-reset"><?= sh_icon('rotate', 13) ?> Reset</button>
      <div style="margin-left:auto;display:flex;gap:8px;">
        <button type="button" class="sh-btn sh-btn--ghost" data-cropper-cancel>Cancel</button>
        <button type="button" class="sh-btn" id="crop-btn-save"><?= sh_icon('check-circle', 14) ?> Apply crop</button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
