<?php
/**
 * Admin — register or edit one payment gateway.
 *
 * admin/payment-gateways.php lists the gateways with their verification status
 * and the enable/disable action; the driver, mode and credentials are edited
 * here, on their own screen, so credentials are never rendered inside a listing.
 *
 *   admin/payment-gateway-edit.php        → register
 *   admin/payment-gateway-edit.php?edit=2 → edit gateway 2
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

/*
 * Modular gateway architecture. Credentials are stored as JSON server-side and
 * are never emitted to the browser. Only drivers with a real server-side
 * verification routine can settle an order automatically.
 */
$drivers = [
    'sslcommerz' => 'SSLCommerz',
    'aamarpay'   => 'aamarPay',
    'shurjopay'  => 'shurjoPay',
    'custom'     => 'Custom / other provider',
];
// Drivers with an implemented server-side validation call.
$verifiable = ['sslcommerz'];

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/payment-gateways.php') . ($keepQs !== '' ? '?' . $keepQs : '');

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF first: nothing below runs without a valid token.
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'save') { sh_redirect($listUrl); }

    $id = sh_int($_POST['id'] ?? 0);
    $existing = $id > 0 ? sh_gateway_by_id($id) : null;
    $driver = sh_post('driver');
    if (!isset($drivers[$driver])) { $driver = 'custom'; }

    $v = new ShValidator($_POST);
    $v->required('name', 'Gateway name')->maxLen('name', 90, 'Gateway name');
    $errors = $v->errors();

    $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', sh_post('code')) ?? '');
    if ($code === '') { $code = str_replace('-', '_', sh_slug(sh_post('name'))); }
    if ($code === '') { $errors['code'] = 'Enter a short machine code.'; }
    if (sh_one('SELECT id FROM payment_gateways WHERE code = ? AND id <> ? LIMIT 1', [$code, $id])) {
        $errors['code'] = 'That gateway code is already in use.';
    }

    // Merge credentials: a blank field keeps the stored secret.
    $creds = $existing !== null ? sh_gateway_credentials($existing) : [];
    foreach (array_keys(sh_gateway_fields($driver)) as $field) {
        $posted = trim((string)($_POST['cred_' . $field] ?? ''));
        if ($posted !== '') { $creds[$field] = $posted; }
        if (!empty($_POST['clear_credentials'])) { unset($creds[$field]); }
    }

    if (!$errors) {
        $mode = sh_post('mode') === 'live' ? 'live' : 'sandbox';
        $data = [
            'code' => $code,
            'name' => sh_post('name'),
            'driver' => $driver,
            'description' => sh_post('description') ?: null,
            'credentials' => $creds ? json_encode($creds, JSON_UNESCAPED_SLASHES) : null,
            'mode' => $mode,
            'callback_url' => sh_base_url() . '/api/payment.php?action=callback&gateway=' . $code,
            'return_url' => sh_base_url() . '/order-success.php',
            'webhook_url' => sh_base_url() . '/api/payment.php?action=callback&gateway=' . $code,
            'sort_order' => sh_int($_POST['sort_order'] ?? 0),
        ];
        // Never silently enable a gateway that has no credentials.
        $wantEnabled = !empty($_POST['status']);
        $tmp = array_merge($data, ['driver' => $driver]);
        $data['status'] = ($wantEnabled && sh_gateway_is_configured($tmp)) ? 1 : 0;
        if ($wantEnabled && $data['status'] === 0) {
            sh_flash('info', 'The gateway was saved but stays disabled until all credentials are supplied.');
        }
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            $gwOld = $id > 0 ? sh_one('SELECT status, mode, name FROM payment_gateways WHERE id = ?', [$id]) : null;
            if ($id > 0) { sh_update('payment_gateways', $data, 'id = ?', [$id]); sh_flash('success', 'Gateway updated.'); }
            else { $id = sh_insert('payment_gateways', $data); sh_flash('success', 'Gateway created.'); }
            sh_audit('settings_changed', 'settings', $id, 'payment_gateway_' . $code,
                $gwOld ? ['enabled' => (int)$gwOld['status'], 'mode' => $gwOld['mode'], 'credentials' => '•••• (hidden)'] : null,
                ['enabled' => (int)$data['status'], 'mode' => $data['mode'] ?? '', 'credentials' => '•••• (hidden)']);
            sh_log_line('admin', 'Gateway "' . $data['name'] . '" saved by ' . $admin['email']);
            sh_redirect($listUrl);
        } catch (Throwable $e) {
            sh_log_exception($e, 'gateway-save');
            $errors['general'] = 'The gateway could not be saved.';
        }
    }
}

$editing = $editId > 0 ? sh_gateway_by_id($editId) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That gateway no longer exists.');
    sh_redirect($listUrl);
}
$isNew = $editing === null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$editDriver = $val('driver', 'sslcommerz');
$editCreds = $editing !== null ? sh_gateway_credentials($editing) : [];

$adminPage = 'gateways';
$adminTitle = $isNew ? 'Register gateway' : 'Edit gateway';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Payment gateways' => $listUrl,
    ($isNew ? 'Register gateway' : (string)($editing['name'] ?? 'Gateway')) => '',
]) ?>

<?php if (!$isNew): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('shield', 16) ?>
    <span>Leaving a credential field empty keeps the value already stored. Secrets are kept server-side and are never shown in full.</span></div>
<?php endif; ?>

<?= sh_admin_error_summary($errors) ?>

<div class="sh-panel sh-formcard">
  <div class="sh-panel__body">
  <form method="post" novalidate id="sh-gw-form">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="sh-field"><label class="sh-field__label" for="gw-name">Gateway name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="gw-name" name="name" required maxlength="90" value="<?= e($val('name')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="gw-code">Machine code</label>
          <input class="sh-input" id="gw-code" name="code" maxlength="40" value="<?= e($val('code')) ?>" placeholder="auto"></div>
        <div class="sh-field"><label class="sh-field__label" for="gw-driver">Driver</label>
          <select class="sh-select" id="gw-driver" name="driver">
            <?php foreach ($drivers as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $editDriver === $k ? 'selected' : '' ?>>
                <?= e($label) ?><?= in_array($k, $verifiable, true) ? ' — verification ready' : ' — verifier required' ?></option>
            <?php endforeach; ?>
          </select>
          <span class="sh-field__hint">Save and reopen after changing the driver to see its credential fields.</span></div>
        <div class="sh-field"><label class="sh-field__label" for="gw-mode">Mode</label>
          <select class="sh-select" id="gw-mode" name="mode">
            <option value="sandbox" <?= $val('mode', 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox / testing</option>
            <option value="live" <?= $val('mode') === 'live' ? 'selected' : '' ?>>Live</option>
          </select></div>

        <p style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--sh-muted);margin:14px 0 8px">
          Merchant credentials</p>
        <?php foreach (sh_gateway_fields($editDriver) as $field => $label):
          $has = trim((string)($editCreds[$field] ?? '')) !== ''; ?>
          <div class="sh-field">
            <label class="sh-field__label" for="gw-<?= e($field) ?>"><?= e($label) ?></label>
            <input class="sh-input" id="gw-<?= e($field) ?>" type="password" name="cred_<?= e($field) ?>" autocomplete="new-password"
                   placeholder="<?= $has ? 'Saved — leave blank to keep' : 'Issued by the provider' ?>">
          </div>
        <?php endforeach; ?>
        <?php if ($editCreds): ?>
          <label class="sh-check" style="margin-bottom:12px">
            <input type="checkbox" name="clear_credentials" value="1"><span>Remove all stored credentials</span></label>
        <?php endif; ?>

        <div class="sh-field"><label class="sh-field__label" for="gw-desc">Description</label>
          <input class="sh-input" id="gw-desc" name="description" maxlength="190" value="<?= e($val('description')) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="gw-sort">Sort order</label>
          <input class="sh-input" id="gw-sort" name="sort_order" type="number" value="<?= e($val('sort_order', '0')) ?>"></div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 0) === 1) ? 'checked' : '' ?>>
          <span>Enable this gateway</span></label>

      <div class="sh-formcard__foot sh-formcard__foot--sticky">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Register gateway' : 'Save changes' ?></button>
        <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      </div>
  </form>
  </div>
</div>

<style>@media (max-width: 1100px){.sh-gwgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
