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

require_once SH_ROOT . '/includes/admin-tools.php';

$errors = [];
$newUrl = sh_url('admin/payment-gateway-edit.php');

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
        // The gateway form has its own screen now: a save posted here (an open tab
        // from before the change) is forwarded with its id, never dropped.
        sh_flash('info', 'The gateway form has moved to its own page.');
        $id = sh_int($_POST['id'] ?? 0);
        sh_redirect('admin/payment-gateway-edit.php' . ($id > 0 ? '?edit=' . $id : ''));
    }
}

// An old bookmark to ?edit= opens the dedicated screen instead of silently
// showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit']);
    if ($forwardId > 0) { sh_redirect('admin/payment-gateway-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
}

$rows = sh_gateways();

$adminPage = 'gateways';
$adminTitle = 'Payment Gateways';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Payment Gateways',
    'Each row is a driver. Only a driver with a real server-side verification routine can settle an order automatically.',
    sh_admin_add_button('Register gateway', $newUrl, '', 'sh-btn sh-btn--sm', 'integrations.manage'),
    'credit-card'
) ?>

<div class="sh-panel">
    <div class="sh-panel__head"><span class="sh-formcard__note"><?= (int)count($rows) ?> gateway<?= count($rows) === 1 ? '' : 's' ?> registered</span></div>
    <div class="sh-tablewrap sh-tablewrap--cards">
      <table class="sh-table">
        <thead><tr><th>Gateway</th><th>Driver</th><th>Mode</th><th>Verification</th><th>Status</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="6">No gateways registered yet.</td></tr>
        <?php else: foreach ($rows as $g):
          $ok = sh_gateway_is_configured($g);
          $canVerify = in_array($g['driver'], $verifiable, true);
          $canInitiate = sh_gateway_can_initiate($g); ?>
          <tr>
            <td data-label="Gateway"><div class="sh-table__name"><?= e($g['name']) ?></div>
              <div class="sh-table__meta"><?= e($g['code']) ?></div></td>
            <td data-label="Driver"><?= e($drivers[$g['driver']] ?? $g['driver']) ?></td>
            <td data-label="Mode"><span class="sh-badge <?= $g['mode'] === 'live' ? 'sh-badge--ok' : 'sh-badge--warn' ?>"><?= e(ucfirst($g['mode'])) ?></span></td>
            <td data-label="Verification" class="sh-table__meta" style="max-width:250px">
              <div><?= $canVerify ? 'Server-side validation implemented' : 'No verifier — cannot settle orders' ?></div>
              <div><?= $canInitiate ? 'Customer checkout initiation implemented' : 'Customer checkout unavailable: no initiation driver' ?></div>
            </td>
            <td data-label="Status">
              <?php if (!$ok): ?><span class="sh-statuspill sh-statuspill--off">Not configured</span>
              <?php elseif ((int)$g['status'] === 1 && $canInitiate): ?><span class="sh-statuspill sh-statuspill--on">Checkout enabled</span>
              <?php elseif ((int)$g['status'] === 1): ?><span class="sh-statuspill sh-statuspill--off">Callback only</span>
              <?php else: ?><span class="sh-statuspill sh-statuspill--off">Disabled</span><?php endif; ?>
            </td>
            <td data-label="Actions"><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/payment-gateway-edit.php?edit=' . (int)$g['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
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
        <p class="sh-panel__note"><strong>Callback / webhook URL for a future official initiation driver:</strong><br>
          <code style="word-break:break-all"><?= e(sh_base_url()) ?>/api/payment.php?action=callback&amp;gateway=<em>CODE</em></code><br>
          A callback is accepted only for a transaction reference that a real server-side initiator pre-bound to the selected order. Saving credentials or enabling this row alone does not create customer checkout.</p>
      </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
