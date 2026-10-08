<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/cart.php';

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
        if ((int)sh_val('SELECT COUNT(*) FROM orders WHERE coupon_id = ?', [$id], 0) > 0) {
            sh_query('UPDATE coupons SET status = 0 WHERE id = ?', [$id]);
            sh_flash('info', 'This coupon has been used on orders, so it was deactivated instead of deleted.');
        } else {
            sh_query('DELETE FROM coupons WHERE id = ?', [$id]);
            sh_flash('success', 'Coupon deleted.');
        }
        sh_redirect('admin/coupons.php');
    }

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', sh_post('code')) ?? '');
        $type = sh_post('type') === 'fixed' ? 'fixed' : 'percent';
        $value = (float)sh_post('value');

        if ($code === '') { $errors['code'] = 'Enter a coupon code (letters, numbers, dash and underscore only).'; }
        if (sh_one('SELECT id FROM coupons WHERE code = ? AND id <> ? LIMIT 1', [$code, $id])) {
            $errors['code'] = 'That coupon code already exists.';
        }
        if ($value <= 0) { $errors['value'] = 'The discount value must be greater than zero.'; }
        if ($type === 'percent' && $value > 100) { $errors['value'] = 'A percentage discount cannot exceed 100.'; }
        $starts = sh_post('starts_at'); $expires = sh_post('expires_at');
        if ($starts !== '' && $expires !== '' && strtotime($expires) <= strtotime($starts)) {
            $errors['expires_at'] = 'The end date must be after the start date.';
        }

        if (!$errors) {
            $data = [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'min_order' => (float)(sh_post('min_order') ?: 0),
                'max_discount' => sh_post('max_discount') !== '' ? (float)sh_post('max_discount') : null,
                'usage_limit' => sh_int($_POST['usage_limit'] ?? 0) ?: null,
                'starts_at' => $starts !== '' ? str_replace('T', ' ', $starts) . ':00' : null,
                'expires_at' => $expires !== '' ? str_replace('T', ' ', $expires) . ':00' : null,
                'status' => !empty($_POST['status']) ? 1 : 0,
            ];
            try {
                if ($id > 0) { sh_update('coupons', $data, 'id = ?', [$id]); sh_flash('success', 'Coupon updated.'); }
                else { sh_insert('coupons', $data); sh_flash('success', 'Coupon created.'); }
                sh_redirect('admin/coupons.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'coupon-save');
                $errors['general'] = 'The coupon could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM coupons WHERE id = ? LIMIT 1', [$editId]) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$dt = static fn(string $k): string => $editing && $editing[$k] ? date('Y-m-d\TH:i', strtotime((string)$editing[$k])) : '';
$rows = sh_all('SELECT * FROM coupons ORDER BY id DESC');

$adminPage = 'coupons';
$adminTitle = 'Coupons';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-coupgrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('tag', 17) ?> Coupons (<?= count($rows) ?>)</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Code</th><th>Discount</th><th>Conditions</th><th>Used</th><th>Validity</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No coupons yet.</td></tr>
        <?php else: foreach ($rows as $c):
          $expired = $c['expires_at'] && strtotime((string)$c['expires_at']) < time();
          $exhausted = $c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']; ?>
          <tr>
            <td><code style="font-weight:700;font-size:13px"><?= e($c['code']) ?></code></td>
            <td><strong><?= $c['type'] === 'percent' ? (float)$c['value'] . '%' : e(sh_money($c['value'])) ?></strong>
              <?php if ($c['max_discount'] !== null): ?>
                <div class="sh-table__meta">max <?= e(sh_money($c['max_discount'])) ?></div><?php endif; ?></td>
            <td class="sh-table__meta"><?= (float)$c['min_order'] > 0 ? 'Min order ' . e(sh_money($c['min_order'])) : 'No minimum' ?></td>
            <td><?= (int)$c['used_count'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int)$c['usage_limit'] : '' ?></td>
            <td class="sh-table__meta">
              <?php if ($c['expires_at']): ?>Until <?= e(date('d M Y', strtotime((string)$c['expires_at']))) ?>
              <?php else: ?>No end date<?php endif; ?></td>
            <td>
              <?php if ((int)$c['status'] !== 1): ?><span class="sh-statuspill sh-statuspill--off">Disabled</span>
              <?php elseif ($expired): ?><span class="sh-statuspill sh-statuspill--off">Expired</span>
              <?php elseif ($exhausted): ?><span class="sh-statuspill sh-statuspill--off">Limit reached</span>
              <?php else: ?><span class="sh-statuspill sh-statuspill--on">Active</span><?php endif; ?>
            </td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/coupons.php?edit=' . (int)$c['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post" data-confirm="Delete coupon <?= e($c['code']) ?>?"><?= sh_csrf_field() ?>
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
      <?= $editing ? 'Edit coupon' : 'New coupon' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="sh-field"><label class="sh-field__label" for="cp-code">Code <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="cp-code" name="code" required maxlength="40" style="text-transform:uppercase" value="<?= e($val('code')) ?>"></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="cp-type">Type</label>
            <select class="sh-select" id="cp-type" name="type">
              <option value="percent" <?= $val('type', 'percent') === 'percent' ? 'selected' : '' ?>>Percentage</option>
              <option value="fixed" <?= $val('type') === 'fixed' ? 'selected' : '' ?>>Fixed amount</option>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="cp-val">Value <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="cp-val" name="value" required inputmode="decimal" value="<?= e($val('value')) ?>"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="cp-min">Minimum order</label>
            <input class="sh-input" id="cp-min" name="min_order" inputmode="decimal" value="<?= e($val('min_order', '0')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="cp-max">Max discount</label>
            <input class="sh-input" id="cp-max" name="max_discount" inputmode="decimal" value="<?= e($val('max_discount')) ?>"
                   placeholder="No cap"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="cp-lim">Usage limit</label>
          <input class="sh-input" id="cp-lim" name="usage_limit" type="number" min="0" value="<?= e($val('usage_limit')) ?>" placeholder="Unlimited"></div>
        <div class="sh-field"><label class="sh-field__label" for="cp-st">Starts at</label>
          <input class="sh-input" id="cp-st" type="datetime-local" name="starts_at" value="<?= e($dt('starts_at')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="cp-ex">Expires at</label>
          <input class="sh-input" id="cp-ex" type="datetime-local" name="expires_at" value="<?= e($dt('expires_at')) ?>"></div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>Active</span></label>
        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create coupon' ?></button>
        <?php if ($editing): ?><a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/coupons.php')) ?>">Cancel edit</a><?php endif; ?>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1050px){.sh-coupgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
