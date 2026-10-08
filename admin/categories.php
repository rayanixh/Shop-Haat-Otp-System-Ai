<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$iconChoices = ['zap', 'home', 'tag', 'shopping-cart', 'heart', 'key', 'list', 'trending-up', 'box', 'grid', 'star', 'truck'];

/** Delete a category image file. basename() keeps this inside the uploads folder. */
function sh_category_delete_file(?string $image): void
{
    $name = basename(trim((string)$image));
    if ($name === '' || $name === '.' || $name === '..') { return; }
    $path = SH_UPLOAD_DIR . '/categories/' . $name;
    if (is_file($path) && !unlink($path)) {
        sh_log_line('category', 'Could not delete category image ' . $name);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        $count = (int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id], 0);
        if ($count > 0) {
            sh_flash('error', 'This category still holds ' . $count . ' product(s). Move them first.');
        } else {
            sh_query('DELETE FROM categories WHERE id = ?', [$id]);
            sh_flash('success', 'Category deleted.');
        }
        sh_redirect('admin/categories.php');
    }

    if ($form === 'remove_image') {
        $id = sh_int($_POST['id'] ?? 0);
        $row = sh_one('SELECT image FROM categories WHERE id = ? LIMIT 1', [$id]);
        if ($row !== null) {
            sh_category_delete_file($row['image']);
            sh_query('UPDATE categories SET image = NULL WHERE id = ?', [$id]);
            sh_flash('success', 'Category image removed.');
        }
        sh_redirect('admin/categories.php?edit=' . $id);
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $editingRow = $id > 0 ? sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$id]) : null;
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

        // Upload / replace / remove the category image.
        $catImage = $editingRow['image'] ?? null;
        $uploadedNow = null;
        if (!empty($_POST['remove_image'])) {
            sh_category_delete_file($catImage);
            $catImage = null;
        }
        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // Server-side validation lives in sh_upload_image(): real MIME sniffing,
            // dimension limits and a generated filename (never the user's own).
            $up = sh_upload_image($_FILES['image'], 'categories', 0, 4000);
            if (!empty($up['ok'])) {
                $uploadedNow = $up['file'];
                $catImage = $up['file'];
            } else {
                $errors['image'] = $up['error'] ?? 'The image could not be uploaded.';
            }
        }
        if ($errors && $uploadedNow !== null) {
            // Do not leave an orphan file behind when validation fails later.
            sh_category_delete_file($uploadedNow);
            $uploadedNow = null;
        }

        if (!$errors) {
            $data = [
                'parent_id' => $parent ?: null,
                'name' => sh_post('name'),
                'slug' => $slug,
                'icon' => in_array(sh_post('icon'), $iconChoices, true) ? sh_post('icon') : 'grid',
                'image' => $catImage,
                'description' => sh_post('description') ?: null,
                'sort_order' => sh_int($_POST['sort_order'] ?? 0),
                'status' => !empty($_POST['status']) ? 1 : 0,
            ];
            try {
                if ($id > 0) {
                    // A successful replacement makes the previous file redundant.
                    $prev = $editingRow['image'] ?? null;
                    if ($uploadedNow !== null && $prev && $prev !== $uploadedNow) { sh_category_delete_file($prev); }
                    sh_update('categories', $data, 'id = ?', [$id]);
                    sh_flash('success', 'Category updated.');
                }
                else { sh_insert('categories', $data); sh_flash('success', 'Category created.'); }
                sh_redirect('admin/categories.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'category-save');
                $errors['general'] = 'The category could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$editId]) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};

$rows = sh_all(
    'SELECT c.*, p.name AS parent_name,
            (SELECT COUNT(*) FROM products x WHERE x.category_id = c.id) AS product_count
     FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
     ORDER BY COALESCE(c.parent_id, c.id), c.sort_order ASC, c.name ASC'
);
$parents = sh_all('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY name ASC');

$adminPage = 'categories';
$adminTitle = 'Categories';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:14px" class="sh-catgrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('grid', 17) ?> Categories (<?= count($rows) ?>)</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Name</th><th>Parent</th><th>Products</th><th>Order</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr class="sh-table--empty"><td colspan="6">No categories yet.</td></tr>
        <?php else: foreach ($rows as $c): ?>
          <tr>
            <td><div class="sh-table__cell">
              <?php $rowImg = sh_category_image($c['image'] ?? null); ?>
              <?php if ($rowImg !== ''): ?>
                <img src="<?= e($rowImg) ?>" alt="" loading="lazy"
                     style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex:none">
              <?php else: ?>
                <span style="color:var(--sh-brand)"><?= sh_icon($c['icon'] ?: 'grid', 16) ?></span>
              <?php endif; ?>
              <div><div class="sh-table__name"><?= e($c['name']) ?></div>
                <div class="sh-table__meta">/<?= e($c['slug']) ?></div></div></div></td>
            <td><?= e($c['parent_name'] ?? '—') ?></td>
            <td><?= (int)$c['product_count'] ?></td>
            <td><?= (int)$c['sort_order'] ?></td>
            <td><span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
              <?= (int)$c['status'] === 1 ? 'Active' : 'Hidden' ?></span></td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/categories.php?edit=' . (int)$c['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post" data-confirm="Delete this category?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
      <?= $editing ? 'Edit category' : 'New category' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="sh-field"><label class="sh-field__label" for="c-name">Name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="c-name" name="name" required maxlength="120" value="<?= e($val('name')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="c-slug">URL slug</label>
          <input class="sh-input" id="c-slug" name="slug" maxlength="120" value="<?= e($val('slug')) ?>" placeholder="auto"></div>
        <div class="sh-field"><label class="sh-field__label" for="c-parent">Parent category</label>
          <select class="sh-select" id="c-parent" name="parent_id">
            <option value="">Top level</option>
            <?php foreach ($parents as $p): if ((int)$p['id'] === (int)($editing['id'] ?? 0)) { continue; } ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)$val('parent_id') === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sh-field">
          <label class="sh-field__label" for="c-img">Category image</label>
          <input class="sh-input" id="c-img" type="file" name="image" accept="image/*"
                 data-image-preview="cat-preview">
          <span class="sh-field__hint">
            JPG, PNG, WebP or GIF. Up to <?= e(sh_bytes_label(sh_server_upload_limit())) ?>.
            A square image around 300&times;300 px looks best.
            <?= $editing ? 'Leave empty to keep the current image.' : '' ?>
          </span>
        </div>
        <?php $curCat = sh_category_image($val('image')); ?>
        <div class="sh-promoprev" id="cat-preview" <?= $curCat === '' ? 'hidden' : '' ?>>
          <img src="<?= e($curCat) ?>" alt="Current category image" style="height:132px;object-fit:contain;background:#fff">
          <span class="sh-promoprev__cap" data-preview-caption>Current image</span>
        </div>
        <?php if ($curCat === '' && trim((string)$val('image')) !== ''): ?>
          <p class="sh-field__hint" style="color:#c62828;margin:-4px 0 12px">
            The saved image file is missing from the server. Upload a replacement.</p>
        <?php endif; ?>
        <?php if ($editing && trim((string)($editing['image'] ?? '')) !== ''): ?>
          <label class="sh-check" style="margin-bottom:12px">
            <input type="checkbox" name="remove_image" value="1">
            <span>Remove the current image (falls back to the placeholder)</span></label>
        <?php endif; ?>

        <div class="sh-field"><label class="sh-field__label" for="c-icon">Icon</label>
          <select class="sh-select" id="c-icon" name="icon">
            <?php foreach ($iconChoices as $ic): ?>
              <option value="<?= e($ic) ?>" <?= $val('icon', 'grid') === $ic ? 'selected' : '' ?>><?= e($ic) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="sh-field__hint">Used for the small navigation menus. Category cards use the image above.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="c-desc">Description</label>
          <textarea class="sh-textarea" id="c-desc" name="description" rows="3"><?= e($val('description')) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="c-sort">Sort order</label>
          <input class="sh-input" id="c-sort" name="sort_order" type="number" value="<?= e($val('sort_order', '0')) ?>"></div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>Show in navigation</span></label>
        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create category' ?></button>
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/categories.php')) ?>">Cancel edit</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1000px){.sh-catgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
