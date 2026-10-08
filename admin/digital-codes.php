<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');

$errors = [];
$added = 0; $skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'add') {
        $productId = sh_int($_POST['product_id'] ?? 0);
        $product = $productId > 0 ? sh_one("SELECT id, name, product_type FROM products WHERE id = ? LIMIT 1", [$productId]) : null;
        if ($product === null) { $errors['product_id'] = 'Choose a product to attach these codes to.'; }
        elseif ($product['product_type'] !== 'digital') { $errors['product_id'] = 'Only digital products can hold codes.'; }

        $raw = (string)($_POST['codes'] ?? '');
        $codes = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
        if (!$codes) { $errors['codes'] = 'Paste at least one code, one per line.'; }
        if (count($codes) > 2000) { $errors['codes'] = 'Please add at most 2000 codes at a time.'; }

        if (!$errors) {
            $pdo = sh_db();
            try {
                $pdo->beginTransaction();
                foreach (array_unique($codes) as $code) {
                    if (mb_strlen($code) > 190) { $skipped++; continue; }
                    // Codes are globally unique so the same code can never be sold twice.
                    $dupe = sh_one('SELECT id FROM product_codes WHERE code = ? LIMIT 1', [$code]);
                    if ($dupe) { $skipped++; continue; }
                    sh_insert('product_codes', ['product_id' => $productId, 'code' => $code, 'status' => 'available']);
                    $added++;
                }
                sh_query("UPDATE products SET stock = (SELECT COUNT(*) FROM product_codes WHERE product_id = ? AND status = 'available') WHERE id = ?",
                    [$productId, $productId]);
                $pdo->commit();
                sh_log_line('admin', $added . ' digital codes added to product #' . $productId . ' by ' . $admin['email']);
                sh_flash('success', $added . ' code(s) added.' . ($skipped > 0 ? ' ' . $skipped . ' duplicate or invalid code(s) were skipped.' : ''));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                sh_log_exception($e, 'codes-add');
                sh_flash('error', 'The codes could not be saved. The error has been logged.');
            }
            sh_redirect('admin/digital-codes.php?product=' . $productId);
        }
    }

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        $row = sh_one('SELECT product_id, status FROM product_codes WHERE id = ? LIMIT 1', [$id]);
        if ($row === null) { sh_flash('error', 'That code no longer exists.'); }
        elseif ($row['status'] !== 'available') {
            // A delivered code is a customer record — never destroy it.
            sh_flash('error', 'This code has already been delivered to a customer and cannot be deleted.');
        } else {
            sh_query('DELETE FROM product_codes WHERE id = ?', [$id]);
            sh_query("UPDATE products SET stock = (SELECT COUNT(*) FROM product_codes WHERE product_id = ? AND status = 'available') WHERE id = ?",
                [(int)$row['product_id'], (int)$row['product_id']]);
            sh_flash('success', 'Unused code deleted.');
        }
        sh_redirect('admin/digital-codes.php?' . http_build_query(array_diff_key($_GET, [])));
    }
}

$fProduct = sh_int($_GET['product'] ?? 0);
$fStatus = sh_get('status');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 40;

$where = ['1=1']; $args = [];
if ($fProduct > 0) { $where[] = 'pc.product_id = ?'; $args[] = $fProduct; }
if (in_array($fStatus, ['available', 'reserved', 'used'], true)) { $where[] = 'pc.status = ?'; $args[] = $fStatus; }
$whereSql = implode(' AND ', $where);

$total = (int)sh_val("SELECT COUNT(*) FROM product_codes pc WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT pc.*, p.name AS product_name, o.order_number
     FROM product_codes pc
     JOIN products p ON p.id = pc.product_id
     LEFT JOIN orders o ON o.id = pc.order_id
     WHERE $whereSql ORDER BY pc.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);
$digitalProducts = sh_all("SELECT id, name,
    (SELECT COUNT(*) FROM product_codes c WHERE c.product_id = p.id AND c.status = 'available') AS available,
    (SELECT COUNT(*) FROM product_codes c WHERE c.product_id = p.id AND c.status = 'used') AS used
    FROM products p WHERE p.product_type = 'digital' ORDER BY p.name ASC");

$adminPage = 'codes';
$adminTitle = 'Digital Codes';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 350px;gap:14px" class="sh-codegrid">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('key', 17) ?> Code inventory (<?= number_format($total) ?>)</h2>
    </div>
    <div class="sh-panel__body" style="padding-bottom:0">
      <form class="sh-filterbar" method="get">
        <div class="sh-field"><label class="sh-field__label" for="f-p">Product</label>
          <select class="sh-select" id="f-p" name="product">
            <option value="">All digital products</option>
            <?php foreach ($digitalProducts as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $fProduct === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="f-s">Status</label>
          <select class="sh-select" id="f-s" name="status">
            <option value="">All</option>
            <?php foreach (['available' => 'Available', 'reserved' => 'Reserved', 'used' => 'Delivered'] as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
        <?php if ($fProduct || $fStatus !== ''): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/digital-codes.php')) ?>">Reset</a><?php endif; ?>
      </form>
    </div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Code</th><th>Product</th><th>Status</th><th>Order</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="5">No codes match this view.</td></tr>
        <?php else: foreach ($rows as $c): ?>
          <tr>
            <td><code style="font-size:12.5px;font-weight:600"><?= e($c['code']) ?></code></td>
            <td><?= e($c['product_name']) ?></td>
            <td><span class="sh-badge <?= $c['status'] === 'available' ? 'sh-badge--ok' : ($c['status'] === 'used' ? '' : 'sh-badge--warn') ?>">
              <?= e($c['status'] === 'used' ? 'Delivered' : ucfirst($c['status'])) ?></span>
              <?php if ($c['delivered_at']): ?><div class="sh-table__meta"><?= e(date('d M, H:i', strtotime($c['delivered_at']))) ?></div><?php endif; ?></td>
            <td><?php if ($c['order_number']): ?>
              <a href="<?= e(sh_url('admin/orders.php?id=' . (int)$c['order_id'])) ?>"><?= e($c['order_number']) ?></a>
            <?php else: ?><span class="sh-table__meta">—</span><?php endif; ?></td>
            <td style="text-align:right">
              <?php if ($c['status'] === 'available'): ?>
                <form method="post" data-confirm="Delete this unused code?"><?= sh_csrf_field() ?>
                  <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?></button></form>
              <?php else: ?><span class="sh-table__meta">locked</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/digital-codes.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
    <?php endif; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('plus', 17) ?> Add codes</h2></div>
      <div class="sh-panel__body">
        <?php if (!$digitalProducts): ?>
          <p class="sh-panel__note">Create a product with type "Digital" first, then you can load codes for it.</p>
        <?php else: ?>
          <form method="post" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="add">
            <div class="sh-field">
              <label class="sh-field__label" for="cd-p">Digital product <span class="sh-field__req">*</span></label>
              <select class="sh-select" id="cd-p" name="product_id" required>
                <option value="">Select product</option>
                <?php foreach ($digitalProducts as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= $fProduct === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= e($p['name']) ?> (<?= (int)$p['available'] ?> left)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="cd-c">Codes <span class="sh-field__req">*</span></label>
              <textarea class="sh-textarea" id="cd-c" name="codes" rows="9" required
                        placeholder="One code per line&#10;ABCD-1234-EFGH&#10;WXYZ-5678-IJKL"></textarea>
              <span class="sh-field__hint">Duplicates already in the system are skipped automatically.</span>
            </div>
            <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('upload', 15) ?> Add codes to inventory</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($digitalProducts): ?>
      <div class="sh-panel">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Stock by product</h2></div>
        <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ($digitalProducts as $p): ?>
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.9px;align-items:center">
              <a href="<?= e(sh_url('admin/digital-codes.php?product=' . (int)$p['id'])) ?>"
                 style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['name']) ?></a>
              <strong style="white-space:nowrap;color:<?= (int)$p['available'] === 0 ? '#c33' : ((int)$p['available'] <= 5 ? '#b8760a' : 'inherit') ?>">
                <?= (int)$p['available'] ?> left</strong>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<style>@media (max-width: 1050px){.sh-codegrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
