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

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        sh_query('UPDATE payment_methods SET status = 1 - status WHERE id = ?', [$id]);
        sh_flash('success', 'Payment method updated.');
        sh_redirect('admin/payment-methods.php');
    }

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM orders WHERE payment_method_id = ?', [$id], 0) > 0) {
            sh_query('UPDATE payment_methods SET status = 0 WHERE id = ?', [$id]);
            sh_flash('info', 'This method has been used on orders, so it was disabled instead of deleted.');
        } else {
            sh_query('DELETE FROM payment_methods WHERE id = ?', [$id]);
            sh_flash('success', 'Payment method deleted.');
        }
        sh_redirect('admin/payment-methods.php');
    }

    // Per-method card: enable toggle, merchant account, extra charge only.
    if ($form === 'card') {
        $id = sh_int($_POST['id'] ?? 0);
        $m = sh_one('SELECT * FROM payment_methods WHERE id = ? LIMIT 1', [$id]);
        if ($m === null) { sh_flash('error', 'That payment method does not exist.'); sh_redirect('admin/payment-methods.php'); }
        $data = [
            'status'       => !empty($_POST['status']) ? 1 : 0,
            'extra_charge' => (float)(sh_post('extra_charge') ?: 0),
        ];
        if ($m['type'] === 'manual') {
            $data['account_number'] = sh_post('account_number') ?: null;
            $data['account_type']   = sh_post('account_type') ?: null;
        }
        if (array_key_exists('instructions', $_POST)) { $data['instructions'] = sh_post('instructions') ?: null; }
        require_once SH_ROOT . '/includes/admin-tools.php';
        $pmOld = sh_one('SELECT * FROM payment_methods WHERE id = ?', [$id]) ?? [];
        sh_update('payment_methods', $data, 'id = ?', [$id]);
        $pmDiff = []; foreach ($data as $k => $v) { if ((string)($pmOld[$k] ?? '') !== (string)$v) { $pmDiff[$k] = [$pmOld[$k] ?? null, $v]; } }
        if ($pmDiff) { sh_audit('settings_changed', 'settings', $id, 'payment_method_' . ($m['code'] ?? $id), array_map(static fn($d) => $d[0], $pmDiff), array_map(static fn($d) => $d[1], $pmDiff)); }
        sh_log_line('admin', 'Payment method "' . $m['name'] . '" updated by ' . $admin['email']);
        sh_flash('success', $m['name'] . ' saved.');
        sh_redirect('admin/payment-methods.php#method-' . $id);
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
                sh_redirect('admin/payment-methods.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'method-save');
                $errors['general'] = 'The payment method could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM payment_methods WHERE id = ? LIMIT 1', [$editId]) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$rows = sh_all('SELECT m.*, g.name AS gateway_name FROM payment_methods m
                LEFT JOIN payment_gateways g ON g.id = m.gateway_id
                ORDER BY m.sort_order ASC, m.id ASC');
$gateways = sh_all('SELECT id, name FROM payment_gateways ORDER BY name ASC');

$adminPage = 'methods';
$adminTitle = 'Payment Methods';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php $showNew = isset($_GET['new']); ?>
<div class="sh-cards sh-cards--3">
  <?php foreach ($rows as $m):
    $logo = sh_payment_logo_url($m);
    $on = (int)$m['status'] === 1;
    $typeLabel = match ($m['type']) { 'manual' => 'Manual MFS', 'cod' => 'Cash on delivery', default => 'Gateway' };
    $ready = $m['type'] === 'cod' || ($m['type'] === 'manual' && trim((string)$m['account_number']) !== '') || ($m['type'] === 'gateway' && $m['gateway_name']);
  ?>
  <section class="sh-panel sh-paycard" id="method-<?= (int)$m['id'] ?>">
    <div class="sh-paycard__head">
      <div class="sh-paycard__logo <?= $logo === '' && $m['type'] === 'cod' ? 'sh-paycard__logo--cod' : '' ?>">
        <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($m['name']) ?> logo" loading="lazy">
        <?php else: ?><?= sh_icon($m['type'] === 'cod' ? 'truck' : 'credit-card', 26) ?><?php endif; ?>
      </div>
      <div class="sh-paycard__meta">
        <h2 class="sh-paycard__name"><?= e($m['name']) ?></h2>
        <div class="sh-paycard__tags">
          <span class="sh-statuspill <?= $on ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $on ? 'Enabled' : 'Disabled' ?></span>
          <span class="sh-badge sh-badge--muted"><?= e($typeLabel) ?><?= $m['gateway_name'] ? ' · ' . e($m['gateway_name']) : '' ?></span>
          <?php if (!$ready): ?><span class="sh-badge sh-badge--warn"><?= $m['type'] === 'manual' ? 'Account number needed' : 'Gateway not linked' ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <form method="post" novalidate class="sh-paycard__form">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="card">
      <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <label class="sh-toggle">
        <input type="checkbox" name="status" value="1" <?= $on ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span>Enabled at checkout</span>
      </label>
      <?php if ($m['type'] === 'manual'): ?>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="acc-<?= (int)$m['id'] ?>">Merchant account number</label>
            <input class="sh-input" id="acc-<?= (int)$m['id'] ?>" name="account_number" maxlength="40" value="<?= e((string)$m['account_number']) ?>" placeholder="01XXXXXXXXX" inputmode="tel"></div>
          <div class="sh-field"><label class="sh-field__label" for="at-<?= (int)$m['id'] ?>">Account type</label>
            <input class="sh-input" id="at-<?= (int)$m['id'] ?>" name="account_type" maxlength="40" value="<?= e((string)$m['account_type']) ?>" placeholder="Merchant / Personal"></div>
        </div>
      <?php endif; ?>
      <div class="sh-field"><label class="sh-field__label" for="fee-<?= (int)$m['id'] ?>">Extra charge</label>
        <input class="sh-input" id="fee-<?= (int)$m['id'] ?>" name="extra_charge" inputmode="decimal" value="<?= e((string)$m['extra_charge']) ?>" style="max-width:160px"></div>
      <div class="sh-field"><label class="sh-field__label" for="ins-<?= (int)$m['id'] ?>">Customer instructions</label>
        <textarea class="sh-textarea" id="ins-<?= (int)$m['id'] ?>" name="instructions" rows="3"><?= e((string)$m['instructions']) ?></textarea></div>
      <div class="sh-actions">
        <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 14) ?> Save changes</button>
        <a class="sh-paycard__edit" href="<?= e(sh_url('admin/payment-methods.php?edit=' . (int)$m['id'])) ?>#edit"><?= sh_icon('pencil', 13) ?> Advanced</a>
      </div>
    </form>
  </section>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="sh-panel sh-cards__full"><div class="sh-panel__body sh-panel__note">No payment methods configured yet.</div></div><?php endif; ?>
</div>

<?php if ($editing !== null || $errors || $showNew): ?>
<div class="sh-cards" style="margin-top:14px" id="edit">
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?> <?= $editing ? 'Edit method — ' . e($editing['name']) : 'New method' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="current_logo" value="<?= e($val('logo')) ?>">

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
        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Create method' ?></button>
          <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/payment-methods.php')) ?>">Cancel</a>
        </div>
      </form>
      <?php if ($editing !== null): ?>
        <form method="post" data-confirm="Delete <?= e($editing['name']) ?>? Orders that used it are kept." style="margin-top:12px">
          <?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?> Delete this method</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php else: ?>
  <div class="sh-actions" style="margin-top:14px">
    <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/payment-methods.php?new=1')) ?>#edit"><?= sh_icon('plus', 15) ?> Add a payment method</a>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
