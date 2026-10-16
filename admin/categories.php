<?php
/**
 * Admin — product categories.
 *
 * Layout follows the shared admin pattern: the page header carries the section
 * title, its description and the "Add category" action, then the filter bar,
 * then the records. The create/edit form lives on its own screen
 * (admin/category-edit.php) so the listing never grows a long form underneath
 * it, and so an operator returning with Cancel lands back on the exact filtered
 * page they left.
 *
 * All existing behaviour is preserved: the same `categories` columns, the same
 * image upload validation, the same SEO fields, parent relationships, sort
 * order and navigation-visibility flag.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';
// SEO columns: the save handler lives in the editor, but the listing reads the
// noindex flag, so ensure the columns exist before anything is rendered.
require_once SH_ROOT . '/includes/seo.php';
sh_seo_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    // The editor handles create/update; the listing only owns the row actions.
    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0) ?: 0;
        $back = sh_admin_keep_query();
        $count = (int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id], 0);
        $children = (int)sh_val('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id], 0);
        if ($count > 0) {
            sh_flash('error', 'This category still holds ' . $count . ' product(s). Move them first.');
        } elseif ($children > 0) {
            sh_flash('error', 'This category has ' . $children . ' sub-category(ies). Move or delete those first.');
        } else {
            $row = sh_one('SELECT name, image FROM categories WHERE id = ? LIMIT 1', [$id]);
            sh_query('DELETE FROM categories WHERE id = ?', [$id]);
            if ($row !== null) {
                require_once SH_ROOT . '/includes/category-image.php';
                sh_category_delete_file($row['image'] ?? null);
                sh_audit('category_deleted', 'category', $id, (string)$row['name'], ['name' => $row['name']], null);
            }
            sh_seo_cache_invalidate();
            sh_flash('success', 'Category deleted.');
        }
        sh_redirect('admin/categories.php' . ($back !== '' ? '?' . $back : ''));
    }

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0) ?: 0;
        $back = sh_admin_keep_query();
        $row = sh_one('SELECT name, status FROM categories WHERE id = ? LIMIT 1', [$id]);
        if ($row !== null) {
            $new = (int)$row['status'] === 1 ? 0 : 1;
            sh_update('categories', ['status' => $new], 'id = ?', [$id]);
            sh_audit('category_updated', 'category', $id, (string)$row['name'], ['status' => (int)$row['status']], ['status' => $new]);
            sh_seo_cache_invalidate();
            sh_flash('success', $new === 1 ? 'Category is now visible.' : 'Category hidden from the storefront.');
        }
        sh_redirect('admin/categories.php' . ($back !== '' ? '?' . $back : ''));
    }

    if ($form === 'save') {
        // A save posted to the listing (a tab opened before the form moved) is
        // forwarded, never silently dropped. Nothing from the body is stored.
        sh_flash('info', 'The category form has moved to its own page. Please submit again there.');
        $id = sh_int($_POST['id'] ?? 0);
        $qs = $id > 0 ? '?edit=' . $id : '?new=1';
        $keep = sh_admin_keep_query(['edit', 'new', 'id', 'form']);
        if ($keep !== '') { $qs .= '&' . $keep; }
        sh_redirect('admin/category-edit.php' . $qs);
    }
}

// ---------------------------------------------------------------------------
// Listing: search, filter, sort and pagination (all optional, all safe)
// ---------------------------------------------------------------------------
$q        = trim((string)sh_get('q'));
$fStatus  = sh_get('status');
$fParent  = sh_get('parent');
$sortKey  = sh_get('sort', 'order');
$page     = max(1, sh_int($_GET['page'] ?? 1));
$per      = 25;

$where = ['1=1'];
$args  = [];
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.slug LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
if ($fStatus === 'active')  { $where[] = 'c.status = 1'; }
if ($fStatus === 'hidden')  { $where[] = 'c.status = 0'; }
if ($fParent === 'top')     { $where[] = 'c.parent_id IS NULL'; }
elseif ($fParent === 'child') { $where[] = 'c.parent_id IS NOT NULL'; }
$whereSql = implode(' AND ', $where);

// Ordering is chosen from an allowlist — never taken from the query string.
$sortSql = [
    'order'  => 'COALESCE(c.parent_id, c.id) ASC, c.sort_order ASC, c.name ASC',
    'newest' => 'c.id DESC',
    'name'   => 'c.name ASC',
];
if (!isset($sortSql[$sortKey])) { $sortKey = 'order'; }

$total = (int)sh_val('SELECT COUNT(*) FROM categories c WHERE ' . $whereSql, $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = sh_all(
    'SELECT c.*, p.name AS parent_name, p.slug AS parent_slug,
            (SELECT COUNT(*) FROM products x WHERE x.category_id = c.id) AS product_count
     FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
     WHERE ' . $whereSql . '
     ORDER BY ' . $sortSql[$sortKey] . '
     LIMIT ' . $per . ' OFFSET ' . $offset,
    $args
);

$counts = [
    'all'    => (int)sh_val('SELECT COUNT(*) FROM categories', [], 0),
    'active' => (int)sh_val('SELECT COUNT(*) FROM categories WHERE status = 1', [], 0),
    'hidden' => (int)sh_val('SELECT COUNT(*) FROM categories WHERE status = 0', [], 0),
    'top'    => (int)sh_val('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL', [], 0),
];

$keepQs    = sh_admin_keep_query();           // for Edit links and Cancel
$listQuery = sh_admin_keep_query(['edit', 'new']);
$listUrl   = sh_url('admin/categories.php') . ($listQuery !== '' ? '?' . $listQuery : '');
$newUrl    = sh_url('admin/category-edit.php?new=1') . ($keepQs !== '' ? '&' . $keepQs : '');
$filtered  = $q !== '' || $fStatus !== '' || $fParent !== '' || $sortKey !== 'order';

$adminPage = 'categories';
$adminTitle = 'Categories';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Categories',
    'Organise the storefront into the categories shoppers browse. Each card uses the image uploaded here; without one, a neutral placeholder is shown.',
    sh_admin_add_button('Add category', $newUrl),
    'grid'
) ?>

<div class="sh-panel" style="margin-bottom:14px">
  <div class="sh-panel__body" style="padding-bottom:12px">
    <form class="sh-filterbar sh-filterbar--top" method="get" action="<?= e(sh_url('admin/categories.php')) ?>">
      <div class="sh-field"><label class="sh-field__label" for="cf-q">Search</label>
        <input class="sh-input" id="cf-q" name="q" value="<?= e($q) ?>" placeholder="Name or slug" autocomplete="off"></div>
      <div class="sh-field"><label class="sh-field__label" for="cf-status">Status</label>
        <select class="sh-select" id="cf-status" name="status">
          <option value="">All statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Visible (<?= (int)$counts['active'] ?>)</option>
          <option value="hidden" <?= $fStatus === 'hidden' ? 'selected' : '' ?>>Hidden (<?= (int)$counts['hidden'] ?>)</option>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="cf-parent">Level</label>
        <select class="sh-select" id="cf-parent" name="parent">
          <option value="">All levels</option>
          <option value="top" <?= $fParent === 'top' ? 'selected' : '' ?>>Top level (<?= (int)$counts['top'] ?>)</option>
          <option value="child" <?= $fParent === 'child' ? 'selected' : '' ?>>Sub-categories</option>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="cf-sort">Sort</label>
        <select class="sh-select" id="cf-sort" name="sort">
          <option value="order" <?= $sortKey === 'order' ? 'selected' : '' ?>>Storefront order</option>
          <option value="newest" <?= $sortKey === 'newest' ? 'selected' : '' ?>>Newest first</option>
          <option value="name" <?= $sortKey === 'name' ? 'selected' : '' ?>>Name A–Z</option>
        </select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Apply</button>
      <?php if ($filtered): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/categories.php')) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Categories
      <span class="sh-table__meta"><?= e(sh_admin_range_label($total, $per, $page)) ?></span></h2>
    <div class="sh-panel__actions">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('products.php')) ?>" target="_blank" rel="noopener">
        <?= sh_icon('external', 14) ?> View storefront</a>
      <?= sh_admin_add_button('Add category', $newUrl) ?>
    </div>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Category</th><th>Parent</th><th>Products</th><th>Order</th><th>Status</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="6">
          <?php if ($filtered): ?>
            No category matches these filters. <a href="<?= e(sh_url('admin/categories.php')) ?>">Clear them</a> to see all <?= (int)$counts['all'] ?>.
          <?php else: ?>
            No categories yet. <?= sh_admin_add_button('Add the first category', $newUrl) ?>
          <?php endif; ?>
        </td></tr>
      <?php else: foreach ($rows as $c): ?>
        <tr>
          <td data-label="Category"><div class="sh-table__cell">
            <?php $rowImg = sh_category_image($c['image'] ?? null); ?>
            <?php if ($rowImg !== ''): ?>
              <img class="sh-table__thumb" src="<?= e($rowImg) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="sh-table__thumb sh-table__thumb--empty" title="No image uploaded"><?= sh_icon($c['icon'] ?: 'grid', 15) ?></span>
            <?php endif; ?>
            <div style="min-width:0">
              <div class="sh-table__name"><?= e($c['name']) ?></div>
              <div class="sh-table__meta">/<?= e($c['slug']) ?><?php if ((int)($c['seo_noindex'] ?? 0) === 1): ?>
                  <span class="sh-badge" style="font-size:10px;margin-left:6px">noindex</span><?php endif; ?></div>
            </div></div></td>
          <td data-label="Parent"><?= e($c['parent_name'] ?? '—') ?></td>
          <td data-label="Products">
            <?php if ((int)$c['product_count'] > 0): ?>
              <a href="<?= e(sh_url('admin/products.php?category=' . (int)$c['id'])) ?>"><?= (int)$c['product_count'] ?></a>
            <?php else: ?>
              <span class="sh-table__meta">0</span>
            <?php endif; ?>
          </td>
          <td data-label="Order"><?= (int)$c['sort_order'] ?></td>
          <td data-label="Status"><span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
            <?= (int)$c['status'] === 1 ? 'Visible' : 'Hidden' ?></span></td>
          <td><div class="sh-table__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/category-edit.php?edit=' . (int)$c['id'] . ($keepQs !== '' ? '&' . $keepQs : ''))) ?>"
               title="Edit <?= e($c['name']) ?>"><?= sh_icon('pencil', 13) ?><span class="sh-table__actionlabel"> Edit</span></a>
            <form method="post"><?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"
                      title="<?= (int)$c['status'] === 1 ? 'Hide from the storefront' : 'Show in the storefront' ?>">
                <?= sh_icon('eye', 13) ?><span class="sh-table__actionlabel"> <?= (int)$c['status'] === 1 ? 'Hide' : 'Show' ?></span></button>
            </form>
            <form method="post" data-confirm="Delete &quot;<?= e($c['name']) ?>&quot;? Products must be moved first."><?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" title="Delete"><?= sh_icon('trash', 13) ?><span class="sh-table__actionlabel"> Delete</span></button>
            </form>
          </div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/categories.php') . ($listQuery !== '' ? '?' . $listQuery : '')) ?></div>
  <?php endif; ?>
</div>

<p class="sh-table__meta" style="margin-top:12px">
  Category names, URLs and ordering are unchanged by this screen — it only edits what the admin panel already stored.
  Nothing is displayed on a category card except its image and name.
</p>

<?php require __DIR__ . '/_footer.php'; ?>
