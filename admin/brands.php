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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM products WHERE brand_id = ?', [$id], 0) > 0) {
            sh_flash('error', 'Products are still assigned to this brand.');
        } else {
            sh_query('DELETE FROM brands WHERE id = ?', [$id]);
            sh_flash('success', 'Brand deleted.');
        }
        sh_redirect('admin/brands.php');
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $v = new ShValidator($_POST);
        $v->required('name', 'Brand name')->maxLen('name', 120, 'Brand name');
        $errors = $v->errors();
        $slug = sh_post('slug') !== '' ? sh_slug(sh_post('slug')) : sh_slug(sh_post('name'));
        if ($slug === '') { $errors['slug'] = 'Could not build a URL slug.'; }
        if (sh_one('SELECT id FROM brands WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $id])) {
            $errors['slug'] = 'Another brand already uses the slug "' . $slug . '".';
        }
        if (!$errors) {
            $data = ['name' => sh_post('name'), 'slug' => $slug, 'status' => !empty($_POST['status']) ? 1 : 0];
            try {
                if ($id > 0) { sh_update('brands', $data, 'id = ?', [$id]); sh_flash('success', 'Brand updated.'); }
                else { sh_insert('brands', $data); sh_flash('success', 'Brand created.'); }
                sh_redirect('admin/brands.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'brand-save');
                $errors['general'] = 'The brand could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM brands WHERE id = ? LIMIT 1', [$editId]) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$rows = sh_all('SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count FROM brands b ORDER BY b.name ASC');

$adminPage = 'brands';
$adminTitle = 'Brands';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px" class="sh-catgrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('tag', 17) ?> Brands (<?= count($rows) ?>)</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="5">No brands yet.</td></tr>
        <?php else: foreach ($rows as $b): ?>
          <tr>
            <td class="sh-table__name"><?= e($b['name']) ?></td>
            <td class="sh-table__meta">/<?= e($b['slug']) ?></td>
            <td><?= (int)$b['product_count'] ?></td>
            <td><span class="sh-statuspill <?= (int)$b['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
              <?= (int)$b['status'] === 1 ? 'Active' : 'Hidden' ?></span></td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/brands.php?edit=' . (int)$b['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post" data-confirm="Delete this brand?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
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
      <?= $editing ? 'Edit brand' : 'New brand' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="sh-field"><label class="sh-field__label" for="b-name">Name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="b-name" name="name" required maxlength="120" value="<?= e($val('name')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="b-slug">URL slug</label>
          <input class="sh-input" id="b-slug" name="slug" maxlength="120" value="<?= e($val('slug')) ?>" placeholder="auto"></div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>Active</span></label>
        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create brand' ?></button>
        <?php if ($editing): ?><a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/brands.php')) ?>">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1000px){.sh-catgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
