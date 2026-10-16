<?php
/**
 * Admin — create or edit one coupon.
 *
 * admin/coupons.php is a list: it keeps the code, discount, conditions, usage
 * and validity columns plus the delete action, and the create/edit form has its
 * own screen so the listing is never squeezed into a sidebar next to a form.
 *
 *   admin/coupon-edit.php          → add
 *   admin/coupon-edit.php?edit=7   → edit coupon 7
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/cart.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/coupons.php') . ($keepQs !== '' ? '?' . $keepQs : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'save') { sh_redirect($listUrl); }
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
        } catch (Throwable $e) {
            sh_log_exception($e, 'coupon-save');
            $errors['general'] = 'The coupon could not be saved.';
        }
        if (!$errors) { sh_redirect($listUrl); }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_one('SELECT * FROM coupons WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That coupon no longer exists.');
    sh_redirect($listUrl);
}
$isNew = $editing === null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
// datetime-local needs "Y-m-d\TH:i"; the stored value is a normal DATETIME.
$dt = static function (string $k) use ($editing): string {
    if ($editing === null || empty($editing[$k])) { return ''; }
    $ts = strtotime((string)$editing[$k]);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
};
$statusOn  = $errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1;
$usedCount = $editing !== null ? (int)sh_val('SELECT COUNT(*) FROM orders WHERE coupon_id = ?', [(int)$editing['id']], 0) : 0;
$expired   = $editing !== null && !empty($editing['expires_at']) && strtotime((string)$editing['expires_at']) < time();
$exhausted = $editing !== null && $editing['usage_limit'] !== null && (int)$editing['used_count'] >= (int)$editing['usage_limit'];

$adminPage = 'coupons';
$adminTitle = $isNew ? 'Add coupon' : 'Edit coupon';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_breadcrumb([
    'Coupons' => $listUrl,
    ($isNew ? 'Add coupon' : (string)$editing['code']) => '',
]) ?>

<?= sh_admin_header(
    $isNew ? 'Create a coupon' : 'Edit ' . (string)$editing['code'],
    $isNew
        ? 'Codes are matched against the cart total at checkout; the discount is recalculated on the server every time.'
        : 'Editing a code does not change orders that already used it — their discount is stored with the order.',
    '<a class="sh-btn sh-btn--sm sh-btn--ghost" href="' . e($listUrl) . '">' . sh_icon('chevron-left', 14) . ' Back to coupons</a>',
    $isNew ? 'plus' : 'tag'
) ?>

<?= sh_admin_error_summary($errors) ?>

<?php if ($errors): ?>
  <div class="sh-panel" style="margin-bottom:14px"><div class="sh-panel__body">
    <p class="sh-formcard__note">Nothing was saved, so the values below are the ones you submitted.</p>
  </div></div>
<?php elseif (!$isNew): ?>
  <div class="sh-stats" style="margin-bottom:14px">
    <div class="sh-stat"><span class="sh-stat__label">Orders using this coupon</span>
      <span class="sh-stat__value"><?= (int)$usedCount ?></span>
      <?php if ($usedCount > 0): ?><a class="sh-stat__hint" href="<?= e(sh_url('admin/orders.php?q=' . urlencode((string)$editing['code']))) ?>">Search orders</a><?php endif; ?>
    </div>
    <div class="sh-stat"><span class="sh-stat__label">Times used</span>
      <span class="sh-stat__value"><?= (int)$editing['used_count'] ?><?= $editing['usage_limit'] !== null ? ' / ' . (int)$editing['usage_limit'] : '' ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Validity</span>
      <span class="sh-stat__value" style="font-size:14px"><?= $expired ? 'Expired' : (empty($editing['expires_at']) ? 'No end date' : 'Until ' . e(date('d M Y', strtotime((string)$editing['expires_at'])))) ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Status</span>
      <span class="sh-stat__value" style="font-size:14px">
        <?php if ((int)$editing['status'] !== 1): ?>Disabled
        <?php elseif ($expired): ?>Expired
        <?php elseif ($exhausted): ?>Limit reached
        <?php else: ?>Active<?php endif; ?>
      </span></div>
  </div>
<?php endif; ?>

<form method="post" novalidate id="sh-coupon-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('tag', 17) ?> Coupon details</h2>
      <div class="sh-panel__actions">
        <span class="sh-formcard__note">Fields marked <span class="sh-field__req">*</span> are required</span>
      </div>
    </div>
    <div class="sh-panel__body">

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('tag', 15) ?> Code and discount</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Coupon code',
              '<input class="sh-input" id="cp-code" name="code" required maxlength="40" style="text-transform:uppercase" value="' . e($val('code')) . '" placeholder="EID25">',
              'Letters, numbers, dash and underscore. Stored and matched in uppercase.', $errors['code'] ?? null, 'cp-code', true) ?>
          <?= sh_admin_field('Discount type',
              '<select class="sh-select" id="cp-type" name="type">'
              . '<option value="percent"' . ($val('type', 'percent') === 'percent' ? ' selected' : '') . '>Percentage of the subtotal</option>'
              . '<option value="fixed"' . ($val('type') === 'fixed' ? ' selected' : '') . '>Fixed amount off</option>'
              . '</select>',
              '', null, 'cp-type') ?>
        </div>
        <div class="sh-grid2">
          <?= sh_admin_field('Value',
              '<input class="sh-input" id="cp-val" name="value" required inputmode="decimal" value="' . e($val('value')) . '">',
              'A percentage (1–100) or an amount in ' . e((string)sh_setting('currency_code', 'BDT')) . '.',
              $errors['value'] ?? null, 'cp-val', true) ?>
          <?= sh_admin_field('Maximum discount',
              '<input class="sh-input" id="cp-max" name="max_discount" inputmode="decimal" value="' . e($val('max_discount')) . '" placeholder="No cap">',
              'Caps a percentage coupon, e.g. 25% up to 500.', $errors['max_discount'] ?? null, 'cp-max') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('package', 15) ?> Conditions and usage</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Minimum order',
              '<input class="sh-input" id="cp-min" name="min_order" inputmode="decimal" value="' . e($val('min_order', '0')) . '">',
              'The cart subtotal must reach this before the coupon applies.', null, 'cp-min') ?>
          <?= sh_admin_field('Usage limit',
              '<input class="sh-input" id="cp-lim" name="usage_limit" type="number" min="0" value="' . e($val('usage_limit')) . '" placeholder="Unlimited">',
              'Total number of orders the coupon may be used on.', null, 'cp-lim') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('clock', 15) ?> Validity</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Starts at',
              '<input class="sh-input" id="cp-st" type="datetime-local" name="starts_at" value="' . e($dt('starts_at')) . '">',
              'Leave blank to start immediately.', null, 'cp-st') ?>
          <?= sh_admin_field('Expires at',
              '<input class="sh-input" id="cp-ex" type="datetime-local" name="expires_at" value="' . e($dt('expires_at')) . '">',
              'Leave blank for no end date.', $errors['expires_at'] ?? null, 'cp-ex') ?>
        </div>
        <label class="sh-check" style="margin-bottom:4px">
          <input type="checkbox" name="status" value="1" <?= $statusOn ? 'checked' : '' ?>>
          <span>Active — customers can apply this code at checkout</span></label>
      </div>

    </div>
    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Create coupon' : 'Save changes' ?></button>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      <?php if (!$isNew): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-coupon-delete"
                data-confirm="Delete coupon &quot;<?= e((string)$editing['code']) ?>&quot;? A coupon used on orders is deactivated instead.">
          <?= sh_icon('trash', 15) ?> Delete coupon</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" action="<?= e(sh_url('admin/coupons.php')) ?>" id="sh-coupon-delete">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
