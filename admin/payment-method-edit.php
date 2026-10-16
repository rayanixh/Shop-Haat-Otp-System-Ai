<?php
/**
 * Admin — create or edit one payment method.
 *
 * The card list on admin/payment-methods.php keeps the quick toggle, the short
 * inline "instructions" save and the Add button at the top of the section; the
 * full record (account numbers, number pattern, logo, gateway link, sort order)
 * is edited here. The save handler, validation, upload and audit logging are the
 * ones the inline form used.
 *
 *   admin/payment-method-edit.php           → add
 *   admin/payment-method-edit.php?edit=3    → edit method 3
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/payment-methods.php') . ($keepQs !== '' ? '?' . $keepQs : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'save') { sh_redirect($listUrl); }
}

if ($form === 'save') {
    $id = sh_int($_POST['id'] ?? 0);
    $type = sh_post('type');
    if (!in_array($type, ['manual', 'cod', 'gateway'], true)) { $type = 'manual'; }

    $v = new ShValidator($_POST);
    $v->required('name', 'Method name')->maxLen('name', 90, 'Method name');
    $errors = $v->errors();

    $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', sh_post('code')) ?? '');
    if ($code === '') { $code = sh_slug(sh_post('name')); $code = str_replace('-', '_', $code); }
    if ($code === '') { $errors['code'] = 'Enter a short machine code such as bkash.'; }
    if (sh_one('SELECT id FROM payment_methods WHERE code = ? AND id <> ? LIMIT 1', [$code, $id])) {
        $errors['code'] = 'That method code is already in use.';
    }
    if ($type === 'manual' && trim(sh_post('account_number')) === '') {
        $errors['account_number'] = 'A manual method needs the merchant account number customers should send money to.';
    }
    $gatewayId = sh_int($_POST['gateway_id'] ?? 0);
    if ($type === 'gateway' && $gatewayId <= 0) {
        $errors['gateway_id'] = 'Select which configured gateway this method uses.';
    }

    $logo = sh_post('current_logo');
    if (!empty($_FILES['logo']['name'])) {
        // Strict validation: MIME, size and dimensions are all checked inside sh_upload_image().
        // No application size cap: only the server's own PHP limit applies.
        $up = sh_upload_image($_FILES['logo'], 'logos', 0, 2000);
        if (!empty($up['ok'])) { $logo = $up['file']; }
        else { $errors['logo'] = $up['error'] ?? 'The logo could not be uploaded.'; }
    }

    if (!$errors) {
        $data = [
            'code' => $code,
            'name' => sh_post('name'),
            'type' => $type,
            'logo' => $logo ?: null,
            'description' => sh_post('description') ?: null,
            'instructions' => sh_post('instructions') ?: null,
            'account_number' => $type === 'manual' ? sh_post('account_number') : null,
            'account_type' => $type === 'manual' ? (sh_post('account_type') ?: null) : null,
            'gateway_id' => $type === 'gateway' ? $gatewayId : null,
            'extra_charge' => (float)(sh_post('extra_charge') ?: 0),
            'sort_order' => sh_int($_POST['sort_order'] ?? 0),
            'status' => !empty($_POST['status']) ? 1 : 0,
        ];
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            $pmOld = $id > 0 ? (sh_one('SELECT * FROM payment_methods WHERE id = ?', [$id]) ?? []) : [];
            if ($id > 0) { sh_update('payment_methods', $data, 'id = ?', [$id]); sh_flash('success', 'Payment method updated.'); }
            else { $id = sh_insert('payment_methods', $data); sh_flash('success', 'Payment method created.'); }
            $pmDiff = []; foreach ($data as $k => $v) { if ((string)($pmOld[$k] ?? '') !== (string)$v) { $pmDiff[$k] = [$pmOld[$k] ?? null, $v]; } }
            if ($pmDiff) { sh_audit('settings_changed', 'settings', $id, 'payment_method_' . ($data['code'] ?? $data['name'] ?? $id), $pmOld ? array_map(static fn($d) => $d[0], $pmDiff) : null, array_map(static fn($d) => $d[1], $pmDiff)); }
            sh_log_line('admin', 'Payment method "' . $data['name'] . '" saved by ' . $admin['email']);
            sh_redirect($listUrl);
        } catch (Throwable $e) {
            sh_log_exception($e, 'method-save');
            $errors['general'] = 'The payment method could not be saved.';
        }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_one('SELECT * FROM payment_methods WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That payment method no longer exists.');
    sh_redirect($listUrl);
}
$isNew = $editing === null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$gateways = sh_all('SELECT id, name FROM payment_gateways ORDER BY name ASC');

$adminPage = 'methods';
$adminTitle = $isNew ? 'Add payment method' : 'Edit payment method';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_breadcrumb([
    'Payment methods' => $listUrl,
    ($isNew ? 'Add method' : (string)$editing['name']) => '',
]) ?>

<?= sh_admin_header(
    $isNew ? 'Add a payment method' : 'Edit ' . (string)$editing['name'],
    'Manual mobile-banking methods (bKash, Nagad, Rocket) are verified from the Payments screen; a gateway method needs its gateway linked.',
    '<a class="sh-btn sh-btn--sm sh-btn--ghost" href="' . e($listUrl) . '">' . sh_icon('chevron-left', 14) . ' Back to payment methods</a>',
    $isNew ? 'plus' : 'pencil'
) ?>

<?= sh_admin_error_summary($errors) ?>

<form method="post" enctype="multipart/form-data" novalidate id="sh-method-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
  <input type="hidden" name="current_logo" value="<?= e($val('logo')) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Method details</h2></div>
    <div class="sh-panel__body">

        <div class="sh-field"><label class="sh-field__label" for="pm-name">Display name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="pm-name" name="name" required maxlength="90" value="<?= e($val('name')) ?>" placeholder="bKash"></div>
        <div class="sh-field"><label class="sh-field__label" for="pm-code">Machine code</label>
          <input class="sh-input" id="pm-code" name="code" maxlength="40" value="<?= e($val('code')) ?>" placeholder="bkash">
          <span class="sh-field__hint">Lowercase letters, numbers and underscores. Auto-generated if blank.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="pm-type">Type</label>
          <select class="sh-select" id="pm-type" name="type">
            <option value="manual" <?= $val('type', 'manual') === 'manual' ? 'selected' : '' ?>>Manual — customer sends money, submits transaction ID</option>
            <option value="cod" <?= $val('type') === 'cod' ? 'selected' : '' ?>>Cash on delivery</option>
            <option value="gateway" <?= $val('type') === 'gateway' ? 'selected' : '' ?>>Automatic gateway</option>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="pm-gw">Linked gateway</label>
          <select class="sh-select" id="pm-gw" name="gateway_id">
            <option value="">None (only for gateway type)</option>
            <?php foreach ($gateways as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)$val('gateway_id') === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="pm-acc">Merchant account number</label>
            <input class="sh-input" id="pm-acc" name="account_number" maxlength="40" value="<?= e($val('account_number')) ?>" placeholder="01XXXXXXXXX"></div>
          <div class="sh-field"><label class="sh-field__label" for="pm-at">Account type</label>
            <input class="sh-input" id="pm-at" name="account_type" maxlength="40" value="<?= e($val('account_type')) ?>" placeholder="Merchant / Personal"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="pm-desc">Short description</label>
          <input class="sh-input" id="pm-desc" name="description" maxlength="190" value="<?= e($val('description')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="pm-inst">Customer instructions</label>
          <textarea class="sh-textarea" id="pm-inst" name="instructions" rows="5"><?= e($val('instructions')) ?></textarea>
          <span class="sh-field__hint">Shown step by step on the payment page. One instruction per line.</span></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="pm-fee">Extra charge</label>
            <input class="sh-input" id="pm-fee" name="extra_charge" inputmode="decimal" value="<?= e($val('extra_charge', '0')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="pm-sort">Sort order</label>
            <input class="sh-input" id="pm-sort" name="sort_order" type="number" value="<?= e($val('sort_order', '0')) ?>"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="pm-logo">Logo</label>
          <input class="sh-input" id="pm-logo" type="file" name="logo" accept="image/*">
          <span class="sh-field__hint">Optional. bKash, Nagad and Rocket use their official logos automatically; upload only to override. PNG, JPG, WebP or GIF.
            Maximum <?= e(sh_bytes_label(sh_server_upload_limit())) ?> (your server's limit).</span></div>
        <?php $curLogo = sh_logo_image($val('logo')); ?>
        <?php if ($curLogo !== ''): ?>
          <p style="margin:-4px 0 12px;display:flex;align-items:center;gap:9px">
            <img src="<?= e($curLogo) ?>" alt="Current logo" style="height:34px;object-fit:contain">
            <span class="sh-field__hint">Current logo</span>
          </p>
        <?php elseif ($val('logo') !== ''): ?>
          <p class="sh-field__hint" style="margin:-4px 0 12px;color:#c62828">
            The saved logo file (<?= e($val('logo')) ?>) is missing from the server. Upload it again to replace it.
          </p>
        <?php endif; ?>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>Enabled at checkout</span></label>
    </div>
    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create method' ?></button>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      <?php if ($editing !== null): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-method-delete"
                data-confirm="Delete &quot;<?= e($editing['name']) ?>&quot;? Orders that used it are kept.">
          <?= sh_icon('trash', 15) ?> Delete this method</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($editing !== null): ?>
  <form method="post" action="<?= e(sh_url('admin/payment-methods.php')) ?>" id="sh-method-delete">
    <?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
