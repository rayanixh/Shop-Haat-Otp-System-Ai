<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

/*
 * Modular gateway architecture. Each row is a driver; credentials are stored as JSON
 * server-side and are never emitted to the browser. Only drivers with a real
 * server-side verification routine can settle an order automatically.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        $g = sh_gateway_by_id($id);
        if ($g === null) { sh_flash('error', 'That gateway does not exist.'); }
        elseif ((int)$g['status'] === 0 && !sh_gateway_is_configured($g)) {
            sh_flash('error', 'Enter the merchant credentials before enabling ' . $g['name'] . '.');
        } else {
            sh_query('UPDATE payment_gateways SET status = 1 - status WHERE id = ?', [$id]);
            sh_flash('success', 'Gateway updated.');
        }
        sh_redirect('admin/payment-gateways.php');
    }

    if ($form === 'save') {
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
                sh_redirect('admin/payment-gateways.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'gateway-save');
                $errors['general'] = 'The gateway could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_gateway_by_id($editId) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$editDriver = $val('driver', 'sslcommerz');
$editCreds = $editing !== null ? sh_gateway_credentials($editing) : [];
$rows = sh_gateways();

$adminPage = 'gateways';
$adminTitle = 'Payment Gateways';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px" class="sh-gwgrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Registered gateways (<?= count($rows) ?>)</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Gateway</th><th>Driver</th><th>Mode</th><th>Verification</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="6">No gateways registered yet.</td></tr>
        <?php else: foreach ($rows as $g):
          $ok = sh_gateway_is_configured($g);
          $canVerify = in_array($g['driver'], $verifiable, true); ?>
          <tr>
            <td><div class="sh-table__name"><?= e($g['name']) ?></div>
              <div class="sh-table__meta"><?= e($g['code']) ?></div></td>
            <td><?= e($drivers[$g['driver']] ?? $g['driver']) ?></td>
            <td><span class="sh-badge <?= $g['mode'] === 'live' ? 'sh-badge--ok' : 'sh-badge--warn' ?>"><?= e(ucfirst($g['mode'])) ?></span></td>
            <td class="sh-table__meta" style="max-width:250px">
              <?= $canVerify ? 'Server-side validation implemented' : 'No verifier — cannot settle orders' ?></td>
            <td>
              <?php if (!$ok): ?><span class="sh-statuspill sh-statuspill--off">Not configured</span>
              <?php elseif ((int)$g['status'] === 1): ?><span class="sh-statuspill sh-statuspill--on">Enabled</span>
              <?php else: ?><span class="sh-statuspill sh-statuspill--off">Disabled</span><?php endif; ?>
            </td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/payment-gateways.php?edit=' . (int)$g['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Enable / disable"><?= sh_icon('eye', 13) ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($rows): ?>
      <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)">
        <p class="sh-panel__note"><strong>Callback / webhook URL to register with your provider:</strong><br>
          <code style="word-break:break-all"><?= e(sh_base_url()) ?>/api/payment.php?action=callback&amp;gateway=<em>CODE</em></code></p>
      </div>
    <?php endif; ?>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
      <?= $editing ? 'Edit gateway' : 'Register gateway' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
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

        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save gateway' : 'Register gateway' ?></button>
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/payment-gateways.php')) ?>">Cancel edit</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1100px){.sh-gwgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
