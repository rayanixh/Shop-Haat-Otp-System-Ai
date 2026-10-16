<?php
/**
 * Admin — product brands.
 *
 * A short form, so it uses the shared modal: "Add brand" sits in the page
 * header at the top of the section, and Edit opens the same modal with the
 * record's values filled in. The form still POSTs to this page with a CSRF
 * field, so nothing about the save path changed.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$openModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM products WHERE brand_id = ?', [$id], 0) > 0) {
            sh_flash('error', 'Products are still assigned to this brand.');
        } else {
            $row = sh_one('SELECT name FROM brands WHERE id = ? LIMIT 1', [$id]);
            sh_query('DELETE FROM brands WHERE id = ?', [$id]);
            if ($row !== null) { sh_audit('brand_deleted', 'brand', $id, (string)$row['name'], ['name' => $row['name']], null); }
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
                if ($id > 0) {
                    sh_update('brands', $data, 'id = ?', [$id]);
                    sh_audit('brand_updated', 'brand', $id, (string)$data['name'], null, $data);
                    sh_flash('success', 'Brand updated.');
                } else {
                    $id = sh_insert('brands', $data);
                    sh_audit('brand_created', 'brand', $id, (string)$data['name'], null, $data);
                    sh_flash('success', 'Brand created.');
                }
                sh_redirect('admin/brands.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'brand-save');
                $errors['general'] = 'The brand could not be saved.';
            }
        }
        $editId = $id;
        // Keep the modal open so the operator sees the errors beside the fields.
        $openModal = true;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM brands WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editId > 0 && $editing !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') { $openModal = true; }
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$chkStatus = $errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1;

$q = trim((string)sh_get('q'));
$where = ['1=1'];
$args = [];
if ($q !== '') { $where[] = '(b.name LIKE ? OR b.slug LIKE ?)'; $args[] = '%' . $q . '%'; $args[] = '%' . $q . '%'; }
$rows = sh_all(
    'SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count
     FROM brands b WHERE ' . implode(' AND ', $where) . ' ORDER BY b.name ASC',
    $args
);
$totalBrands = (int)sh_val('SELECT COUNT(*) FROM brands', [], 0);

/** The modal body, shared by create and edit. */
$modalBody = static function () use ($val, $errors, $editing, $chkStatus): string {
    $h = sh_admin_error_summary($errors);
    $h .= '<form method="post" novalidate id="sh-brand-form">' . sh_csrf_field();
    $h .= '<input type="hidden" name="form" value="save">';
    $h .= '<input type="hidden" name="id" value="' . (int)($editing['id'] ?? 0) . '" data-fill-id>';
    $h .= sh_admin_field('Brand name',
        '<input class="sh-input" id="b-name" name="name" required maxlength="120" value="' . e($val('name')) . '" data-fill-name autocomplete="off">',
        '', $errors['name'] ?? null, 'b-name', true);
    $h .= sh_admin_field('URL slug',
        '<input class="sh-input" id="b-slug" name="slug" maxlength="120" value="' . e($val('slug')) . '" placeholder="auto-generated from the name" data-fill-slug autocomplete="off">',
        'Leave empty to build it from the name.', $errors['slug'] ?? null, 'b-slug');
    $h .= '<label class="sh-check"><input type="checkbox" name="status" value="1" ' . ($chkStatus ? 'checked' : '')
        . ' data-fill-status>' . ($chkStatus ? '' : '') . '<span>Active — shown in the brand filter on the storefront</span></label>';
    $h .= '<div class="sh-modal__foot" style="padding:14px 0 0;border:0;background:none">'
        . '<button class="sh-btn" type="submit">' . sh_icon('check-circle', 15) . ' '
        . ($editing !== null ? 'Save changes' : 'Create brand') . '</button>'
        . '<button class="sh-btn sh-btn--ghost" type="button" data-modal-close>Cancel</button></div>';
    return $h . '</form>';
};

$adminPage = 'brands';
$adminTitle = 'Brands';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Brands',
    'Brands appear as a filter on the storefront and can be assigned to products. Short form, so it opens in a dialog instead of a separate page.',
    sh_admin_add_button('Add brand', '', 'sh-brand-modal'),
    'tag'
) ?>

<?php if ($totalBrands > 0): ?>
<div class="sh-panel" style="margin-bottom:14px">
  <div class="sh-panel__body" style="padding-bottom:12px">
    <form class="sh-filterbar sh-filterbar--top" method="get" action="<?= e(sh_url('admin/brands.php')) ?>">
      <div class="sh-field"><label class="sh-field__label" for="bf-q">Search brands</label>
        <input class="sh-input" id="bf-q" name="q" value="<?= e($q) ?>" placeholder="Name or slug" autocomplete="off"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
      <?php if ($q !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/brands.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('tag', 17) ?> Brands
      <span class="sh-table__meta"><?= count($rows) ?> shown<?= $totalBrands > count($rows) ? ' of ' . $totalBrands : '' ?></span></h2>
    <div class="sh-panel__actions"><?= sh_admin_add_button('Add brand', '', 'sh-brand-modal') ?></div>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="5">
          <?= $q !== '' ? 'No brand matches that search.' : 'No brands yet — add the first one above.' ?>
        </td></tr>
      <?php else: foreach ($rows as $b): ?>
        <tr>
          <td data-label="Name" class="sh-table__name"><?= e($b['name']) ?></td>
          <td data-label="Slug" class="sh-table__meta">/<?= e($b['slug']) ?></td>
          <td data-label="Products">
            <?php if ((int)$b['product_count'] > 0): ?>
              <a href="<?= e(sh_url('admin/products.php?brand=' . (int)$b['id'])) ?>"><?= (int)$b['product_count'] ?></a>
            <?php else: ?><span class="sh-table__meta">0</span><?php endif; ?>
          </td>
          <td data-label="Status"><span class="sh-statuspill <?= (int)$b['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
            <?= (int)$b['status'] === 1 ? 'Active' : 'Hidden' ?></span></td>
          <td><div class="sh-table__actions">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button"
                    data-modal-open="sh-brand-modal"
                    data-fill-id="<?= (int)$b['id'] ?>"
                    data-fill-name="<?= e($b['name']) ?>"
                    data-fill-slug="<?= e($b['slug']) ?>"
                    data-fill-status="<?= (int)$b['status'] === 1 ? '1' : '' ?>"
                    title="Edit <?= e($b['name']) ?>"><?= sh_icon('pencil', 13) ?><span class="sh-table__actionlabel"> Edit</span></button>
            <form method="post" data-confirm="Delete &quot;<?= e($b['name']) ?>&quot;?"><?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" title="Delete"><?= sh_icon('trash', 13) ?><span class="sh-table__actionlabel"> Delete</span></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// The modal carries the same form for both create and edit. When a save failed
// with validation errors it is rendered open, so the messages sit next to the
// fields that produced them.
$brandModal = sh_admin_modal('sh-brand-modal', $editing !== null ? 'Edit brand' : 'New brand', $modalBody(), '', $editing !== null ? 'pencil' : 'plus');
echo $openModal ? str_replace('id="sh-brand-modal" hidden', 'id="sh-brand-modal"', $brandModal) : $brandModal;
?>

<?php require __DIR__ . '/_footer.php'; ?>
