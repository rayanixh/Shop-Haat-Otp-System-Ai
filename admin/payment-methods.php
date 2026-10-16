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
require_once SH_ROOT . '/includes/admin-tools.php';

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

    // The full record now lives on admin/payment-method-edit.php. Anything that
    // still posts a save here is forwarded rather than dropped.
    if ($form === 'save') {
        sh_flash('info', 'The full payment-method form has moved to its own page. Please submit again there.');
        sh_redirect('admin/payment-method-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($editId > 0) { sh_redirect('admin/payment-method-edit.php?edit=' . $editId); }
    if (isset($_GET['new'])) { sh_redirect('admin/payment-method-edit.php?new=1'); }
}
$editing = null;
$val = static function (string $k, $fb = ''): string { return (string)$fb; };
$rows = sh_all('SELECT m.*, g.name AS gateway_name FROM payment_methods m
                LEFT JOIN payment_gateways g ON g.id = m.gateway_id
                ORDER BY m.sort_order ASC, m.id ASC');
$gateways = sh_all('SELECT id, name FROM payment_gateways ORDER BY name ASC');

$adminPage = 'methods';
$adminTitle = 'Payment Methods';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Payment methods',
    'Manual methods are verified from the Payments screen; a gateway method needs its gateway linked.',
    sh_admin_add_button('Add payment method', sh_url('admin/payment-method-edit.php?new=1'), '', 'sh-btn sh-btn--sm', 'integrations.manage'),
    'credit-card'
) ?>

<div class="sh-cards sh-cards--3" id="sh-payment-method-list">
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
        <a class="sh-paycard__edit" href="<?= e(sh_url('admin/payment-method-edit.php?edit=' . (int)$m['id'])) ?>"><?= sh_icon('pencil', 13) ?> Advanced</a>
      </div>
    </form>
  </section>
  <?php endforeach; ?>
  <?php if (!$rows): ?><div class="sh-panel sh-cards__full"><div class="sh-panel__body sh-panel__note">No payment methods configured yet.</div></div><?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
