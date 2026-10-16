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
        $row = sh_one('SELECT name, image FROM categories WHERE id = ? LIMIT 1', [$id]);
        sh_query('DELETE FROM categories WHERE id = ?', [$id]);
        if ($row !== null) {
            sh_category_delete_file($row['image'] ?? null);
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
            $imgResult['uploaded'] = null;
        }

        if (!$errors) {
            $data = [
                'parent_id' => $parent ?: null,
                'name' => sh_post('name'),
                'slug' => $slug,
                'icon' => in_array(sh_post('icon'), $iconChoices, true) ? sh_post('icon') : 'grid',
                'image' => $imgResult['file'],
                'description' => sh_post('description') ?: null,
                'sort_order' => sh_int($_POST['sort_order'] ?? 0),
                'status' => !empty($_POST['status']) ? 1 : 0,
                // SEO columns. Each value is validated rather than stored raw:
                // URLs must be http(s) or a site path, and the flag is a 0/1.
                'meta_title'           => mb_substr(trim(sh_post('meta_title')), 0, 190) ?: null,
                'meta_description'     => mb_substr(trim(sh_post('meta_description')), 0, 300) ?: null,
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
                        if ($old !== '' && $old !== (string)($data['image'] ?? '')) { sh_category_delete_file($old); }
                    }
                    $diff = [];
                    foreach (['name', 'slug', 'parent_id', 'status', 'sort_order'] as $k) {
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
$storedImage = trim((string)$val('image'));
$missingImage = $curImage === '' && $storedImage !== '';

// Parent choices: top-level categories only, never the row being edited.
$parents = sh_all('SELECT id, name FROM categories WHERE parent_id IS NULL AND (id <> ?) ORDER BY name ASC', [(int)($editing['id'] ?? 0)]);
$childCount = $isEdit ? (int)sh_val('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [(int)$editing['id']], 0) : 0;
$productCount = $isEdit ? (int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [(int)$editing['id']], 0) : 0;

$adminPage = 'categories';
$adminTitle = $isEdit ? 'Edit category' : 'New category';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_breadcrumb([
    'Categories' => $listUrl,
    ($isEdit ? (string)($editing['name'] ?? 'Edit category') : 'New category') => '',
]) ?>

<?= sh_admin_header(
    $isEdit ? 'Edit category: ' . (string)($editing['name'] ?? '') : 'Add a new category',
    $isEdit
        ? 'Change the name, image, parent, ordering and search-engine fields. Products keep their existing category until you change it.'
        : 'Create a category, upload its image and choose where it sits in the storefront navigation.',
    '<a class="sh-btn sh-btn--sm sh-btn--ghost" href="' . e($listUrl) . '">' . sh_icon('chevron-left', 14) . ' Back to categories</a>',
    $isEdit ? 'pencil' : 'plus'
) ?>

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

<form method="post" enctype="multipart/form-data" novalidate id="sh-cat-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
  <input type="hidden" name="keep" value="<?= e($keepQs) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> Category details</h2>
      <div class="sh-panel__actions">
        <span class="sh-formcard__note">Fields marked <span class="sh-field__req">*</span> are required</span>
      </div>
    </div>
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
          </div>
          <div class="sh-catimage__fields">
            <?= sh_admin_field('Upload a new image',
                '<input class="sh-input" id="c-img" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-image-preview="cat-preview">',
                'JPG, PNG, WebP or GIF, up to ' . e(sh_bytes_label(sh_server_upload_limit())) . '. A square or 4:3 image around 400&times;300 px looks best. It is shown uncropped on the category card.',
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
        require __DIR__ . '/_seo-fields.php';
        ?>
      </div>
    </div>

    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isEdit ? 'Save changes' : 'Create category' ?></button>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
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

<?php require __DIR__ . '/_footer.php'; ?>
