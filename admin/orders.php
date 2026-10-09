<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/courier.php';
require_once SH_ROOT . '/includes/verification.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('orders.view');

$viewId = sh_int($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $id = sh_int($_POST['id'] ?? 0);
    $order = sh_order_get($id);
    if ($order === null) {
        sh_flash('error', 'That order no longer exists.');
        sh_redirect('admin/orders.php');
    }
    $form = sh_post('form');

    if ($form === 'status') {
        $new = sh_post('status');
        if (sh_perm_denied_flash('orders.update_status')) { sh_redirect('admin/orders.php?id=' . $id); }
        if (in_array($new, ['cancelled', 'returned'], true) && sh_perm_denied_flash('orders.cancel')) { sh_redirect('admin/orders.php?id=' . $id); }
        $allowed = ['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'returned'];
        if (!in_array($new, $allowed, true)) {
            sh_flash('error', 'That status is not valid.');
        } elseif ($new === $order['status']) {
            sh_flash('success', 'The order is already ' . sh_status_label($new) . '.');
        } else {
            try {
                sh_update('orders', ['status' => $new], 'id = ?', [$id]);
                sh_log_line('admin', 'Order ' . $order['order_number'] . ' status -> ' . $new . ' by ' . $admin['email']);
                sh_audit('order_status_changed', 'order', $id, (string)$order['order_number'], $order['status'], $new);
                if ($new === 'returned') {
                    sh_admin_notify('returned', 'Order returned · ' . $order['order_number'], $order['customer_name'] . ' · ' . sh_money($order['total']), 'admin/orders.php?id=' . $id, 'returned-' . $id);
                }

                // Deliver digital codes when an order reaches a fulfilled state.
                if (in_array($new, ['processing', 'completed'], true)
                    && (int)$order['has_digital'] === 1 && (int)$order['codes_delivered'] === 0
                    && $order['payment_status'] === 'verified') {
                    $d = sh_deliver_digital_codes($id);
                    if (!empty($d['ok'])) { sh_flash('success', 'Digital codes delivered (' . count($d['codes'] ?? []) . ').'); }
                    else { sh_flash('error', 'Codes could not be delivered: ' . ($d['error'] ?? 'unknown error')); }
                }
                $event = $new === 'completed' ? 'order_completed' : ($new === 'processing' ? 'order_processing' : null);
                if ($event !== null) { sh_notify($event, sh_order_notify_payload(sh_order_get($id))); }
                sh_flash('success', 'Order status updated to ' . sh_status_label($new) . '.');
            } catch (Throwable $e) {
                sh_log_exception($e, 'order-status');
                sh_flash('error', 'The status could not be updated. The error has been logged.');
            }
        }
        sh_redirect('admin/orders.php?id=' . $id);
    }

    if ($form === 'verify_manual') {
        $st = sh_post('manual_status');
        if (in_array($st, ['', 'verified', 'unable'], true)) {
            sh_verify_manual_set($id, $st, (int)$admin['id'], sh_post('manual_note'));
            sh_audit('order_manual_verification', 'order', $id, (string)$order['order_number'], null, $st ?: 'cleared');
            sh_log_line('admin', 'Order ' . $order['order_number'] . ' manual verification -> ' . ($st ?: 'cleared') . ' by ' . $admin['email']);
            sh_flash('success', $st === '' ? 'Manual verification cleared.' : 'Manual verification saved.');
        }
        sh_redirect('admin/orders.php?id=' . $id . '#customer-verification');
    }

    if ($form === 'refund') {
        if (sh_perm_denied_flash('payments.refund')) { sh_redirect('admin/orders.php?id=' . $id); }
        if ($order['payment_status'] !== 'verified') { sh_flash('error', 'Only orders with a verified payment can be marked as refunded.'); sh_redirect('admin/orders.php?id=' . $id); }
        try {
            sh_update('orders', ['payment_status' => 'refunded'], 'id = ?', [$id]);
            sh_audit('order_refunded', 'order', $id, (string)$order['order_number'], 'verified', ['payment_status' => 'refunded', 'note' => mb_substr(sh_post('refund_note'), 0, 250)]);
            sh_log_line('admin', 'Order ' . $order['order_number'] . ' marked refunded by ' . $admin['email']);
            sh_flash('success', 'Order marked as refunded. Please complete the actual refund with your payment provider.');
        } catch (Throwable $e) { sh_log_exception($e, 'order-refund'); sh_flash('error', 'The order could not be marked as refunded.'); }
        sh_redirect('admin/orders.php?id=' . $id);
    }

    if ($form === 'note') {
        if (sh_perm_denied_flash('orders.details')) { sh_redirect('admin/orders.php?id=' . $id); }
        $newNote = mb_substr(sh_post('order_note'), 0, 900);
        sh_update('orders', ['order_note' => $newNote], 'id = ?', [$id]);
        if ($newNote !== (string)$order['order_note']) { sh_audit('order_note_updated', 'order', $id, (string)$order['order_number'], (string)$order['order_note'], $newNote); }
        sh_flash('success', 'Order note saved.');
        sh_redirect('admin/orders.php?id=' . $id);
    }
}

$adminPage = 'orders';

/* ---------------- Single order view ---------------- */
if ($viewId > 0) {
    sh_require_perm('orders.details');
    $order = sh_order_get($viewId);
    if ($order === null) {
        http_response_code(404);
        $adminTitle = 'Order not found';
        require __DIR__ . '/_layout.php';
        echo '<div class="sh-panel"><div class="sh-panel__body"><p class="sh-panel__note">This order does not exist.</p>'
            . '<a class="sh-btn sh-btn--sm" style="margin-top:10px" href="' . e(sh_url('admin/orders.php')) . '">Back to orders</a></div></div>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $items = sh_order_items($viewId);
    $codes = sh_order_codes($viewId);
    $payments = sh_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$viewId]);
    $shipments = [];
    try {
        $shipments = sh_all(
            'SELECT s.*, c.name AS courier_name FROM shipments s
             LEFT JOIN couriers c ON c.id = s.courier_id
             WHERE s.order_id = ? ORDER BY s.id DESC',
            [$viewId]
        );
    } catch (Throwable $e) {
        // Courier tables not present on an install that predates this feature.
    }

    $adminTitle = 'Order ' . $order['order_number'];
    require __DIR__ . '/_layout.php';
    ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>"><?= sh_icon('chevron-left', 14) ?> All orders</a>
      <span class="sh-badge <?= e(sh_status_class($order['status'])) ?>"><?= e(sh_status_label($order['status'])) ?></span>
      <span class="sh-badge <?= e(sh_status_class($order['payment_status'])) ?>">Payment: <?= e(sh_status_label($order['payment_status'])) ?></span>
    </div>

    <?php if ($items): $hero = $items[0]; $heroVariant = sh_order_item_variant_text($hero); ?>
    <section class="sh-panel sh-ohero">
      <div class="sh-ohero__media"><img src="<?= e(sh_order_item_image($hero)) ?>" alt="<?= e($hero['product_name']) ?>"></div>
      <div class="sh-ohero__body">
        <p class="sh-ocard__eyebrow">Ordered product<?= count($items) > 1 ? 's (' . count($items) . ')' : '' ?></p>
        <h2 class="sh-ohero__name"><?= e($hero['product_name']) ?></h2>
        <?php if ($heroVariant !== ''): ?><p class="sh-ohero__variant"><?= e($heroVariant) ?></p><?php endif; ?>
        <p class="sh-ohero__qty">Quantity: <strong><?= (int)$hero['quantity'] ?></strong> · <?= e(ucfirst((string)$hero['product_type'])) ?> · <?= e(sh_money($hero['line_total'])) ?></p>
        <?php if (count($items) > 1): ?>
          <ul class="sh-ohero__list">
            <?php foreach (array_slice($items, 1) as $it): $v = sh_order_item_variant_text($it); ?>
              <li>
                <img src="<?= e(sh_order_item_image($it)) ?>" alt="" loading="lazy">
                <span class="sh-ohero__liname"><?= e($it['product_name']) ?><?php if ($v !== ''): ?><small><?= e($v) ?></small><?php endif; ?></span>
                <span class="sh-ohero__liqty">× <?= (int)$it['quantity'] ?></span>
                <span class="sh-ohero__liprice"><?= e(sh_money($it['line_total'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px" class="sh-ordgrid">
      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Items (<?= count($items) ?>)</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th style="text-align:right">Line total</th></tr></thead>
              <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><div class="sh-table__cell">
                    <img class="sh-table__thumb" src="<?= e(sh_order_item_image($it)) ?>" alt="" loading="lazy">
                    <div><div class="sh-table__name"><?= e($it['product_name']) ?></div>
                      <div class="sh-table__meta"><?= e(ucfirst((string)$it['product_type'])) ?><?php $v = sh_order_item_variant_text($it); if ($v !== ''): ?> · <?= e($v) ?><?php endif; ?></div></div></div></td>
                  <td><?= e(sh_money($it['unit_price'])) ?></td>
                  <td><?= (int)$it['quantity'] ?></td>
                  <td style="text-align:right;font-weight:700"><?= e(sh_money($it['line_total'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)">
            <div style="max-width:290px;margin-left:auto;font-size:13.2px;display:flex;flex-direction:column;gap:6px">
              <div style="display:flex;justify-content:space-between"><span>Subtotal</span><span><?= e(sh_money($order['subtotal'])) ?></span></div>
              <?php if ((float)$order['discount'] > 0): ?>
                <div style="display:flex;justify-content:space-between;color:#168c56"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><span>−<?= e(sh_money($order['discount'])) ?></span></div>
              <?php endif; ?>
              <div style="display:flex;justify-content:space-between"><span>Delivery</span><span><?= e(sh_money($order['delivery_fee'])) ?></span></div>
              <div style="display:flex;justify-content:space-between;font-size:15.5px;font-weight:800;border-top:1px solid var(--sh-line);padding-top:7px">
                <span>Total</span><span style="color:var(--sh-brand)"><?= e(sh_money($order['total'])) ?></span></div>
            </div>
          </div>
        </div>

        <?php if ($codes): ?>
          <div class="sh-panel">
            <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('key', 17) ?> Delivered digital codes</h2></div>
            <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:8px">
              <?php foreach ($codes as $c): ?>
                <div style="display:flex;gap:10px;align-items:center;background:var(--sh-bg);border-radius:7px;padding:9px 11px">
                  <div style="flex:1 1 auto;min-width:0">
                    <div class="sh-table__meta"><?= e((string)$c['product_name']) ?></div>
                    <code style="font-size:13.5px;font-weight:700;word-break:break-all"><?= e($c['code']) ?></code>
                  </div>
                  <span class="sh-table__meta"><?= $c['delivered_at'] ? e(date('d M, h:i A', strtotime($c['delivered_at']))) : '' ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Payment records</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Method</th><th>Transaction</th><th>Amount</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
              <tbody>
              <?php if (!$payments): ?><tr class="sh-table--empty"><td colspan="5">No payment submitted yet.</td></tr>
              <?php else: foreach ($payments as $p): ?>
                <tr>
                  <td><?= e((string)$p['method_name']) ?><div class="sh-table__meta"><?= e((string)$p['kind']) ?></div></td>
                  <td><?= $p['transaction_id'] ? '<code>' . e($p['transaction_id']) . '</code>' : '—' ?>
                    <?php if ($p['sender_phone']): ?><div class="sh-table__meta">From <?= e($p['sender_phone']) ?></div><?php endif; ?></td>
                  <td><?= e(sh_money($p['amount'])) ?></td>
                  <td><span class="sh-badge <?= e(sh_status_class($p['status'])) ?>"><?= e(sh_status_label($p['status'])) ?></span></td>
                  <td style="text-align:right">
                    <?php if ($p['status'] === 'pending' && $p['transaction_id']): ?>
                      <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/payments.php?id=' . (int)$p['id'])) ?>">Review</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('refresh', 17) ?> Update status</h2></div>
          <div class="sh-panel__body">
            <?php if ($order['payment_status'] !== 'verified' && (int)$order['has_digital'] === 1): ?>
              <p class="sh-panel__note" style="margin-bottom:10px">Digital codes are only released once the payment is verified.</p>
            <?php endif; ?>
            <form method="post">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="status"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <div class="sh-field">
                <label class="sh-field__label" for="o-status">Order status</label>
                <select class="sh-select" id="o-status" name="status">
                  <?php foreach (['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'returned'] as $s): if (in_array($s, ['cancelled', 'returned'], true) && !sh_admin_can('orders.cancel') && $order['status'] !== $s) { continue; } ?>
                    <option value="<?= e($s) ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(sh_status_label($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if (sh_admin_can('orders.update_status')): ?><button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> Apply status</button>
              <?php else: ?><p class="sh-panel__note">Your role can view this order but cannot change its status.</p><?php endif; ?>
            </form>
          </div>
        </div>

        <?php if ($order['payment_status'] === 'verified' && in_array($order['status'], ['cancelled', 'returned'], true) && sh_admin_can('payments.refund')): ?>
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('rotate', 17) ?> Refund</h2></div>
          <div class="sh-panel__body">
            <p class="sh-panel__note" style="margin-bottom:10px">This <?= e(sh_status_label($order['status'])) ?> order has a verified payment of <?= e(sh_money($order['total'])) ?>. Record the refund here once it has been sent to the customer.</p>
            <form method="post" data-confirm="Mark this order as refunded?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="refund"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <div class="sh-field"><label class="sh-field__label" for="o-refund">Refund reference / note</label><input class="sh-input" id="o-refund" name="refund_note" maxlength="250" placeholder="e.g. bKash TrxID of the refund"></div>
              <button class="sh-btn sh-btn--ghost sh-btn--block" type="submit">Mark as refunded</button></form>
          </div>
        </div>
        <?php endif; ?>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('user', 17) ?> Customer</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.85">
            <strong><?= e($order['customer_name']) ?></strong><br>
            <?= sh_icon('mail', 13) ?> <?= e($order['customer_email']) ?><br>
            <?= sh_icon('phone', 13) ?> <?= e($order['customer_phone']) ?><br>
            <?php if ($order['user_id']): ?>
              <a href="<?= e(sh_url('admin/customers.php?id=' . (int)$order['user_id'])) ?>">View customer profile</a>
            <?php else: ?><span class="sh-table__meta">Guest checkout</span><?php endif; ?>
          </div>
        </div>

        <?php $vb = sh_verify_order_bundle($order); $manualNow = $vb['manual']['manual_status'] ?? ''; ?>
        <div class="sh-panel" id="customer-verification">
          <div class="sh-panel__head sh-cv__head">
            <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Customer Verification</h2>
            <div class="sh-cv__tools">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-cv-refresh="courier" data-id="<?= (int)$order['id'] ?>" <?= $vb['courier']['configured'] ? '' : 'disabled' ?>><?= sh_icon('refresh', 13) ?> Refresh courier history</button>
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-cv-refresh="ip" data-id="<?= (int)$order['id'] ?>" <?= ($vb['ip'] !== '' && $vb['ip_configured']) ? '' : 'disabled' ?>><?= sh_icon('refresh', 13) ?> Refresh IP information</button>
            </div>
          </div>
          <div class="sh-panel__body" data-cv-body data-id="<?= (int)$order['id'] ?>">
            <?php require __DIR__ . '/_verification-card.php'; ?>
          </div>
          <div class="sh-panel__body sh-cv__manualbox">
            <form method="post" class="sh-cv__manualform">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="verify_manual"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <label class="sh-field__label" for="cv-manual">Manual verification</label>
              <div class="sh-cv__manualrow">
                <select class="sh-select" id="cv-manual" name="manual_status">
                  <option value="" <?= $manualNow === '' ? 'selected' : '' ?>>Not set</option>
                  <option value="verified" <?= $manualNow === 'verified' ? 'selected' : '' ?>>Verified (e.g. by phone call)</option>
                  <option value="unable" <?= $manualNow === 'unable' ? 'selected' : '' ?>>Unable to verify</option>
                </select>
                <input class="sh-input" name="manual_note" maxlength="255" placeholder="Optional note" value="<?= e((string)($vb['manual']['manual_note'] ?? '')) ?>">
                <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 13) ?> Save</button>
              </div>
              <span class="sh-field__hint">Recorded separately — it never changes the courier API history above.</span>
            </form>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Delivery</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.85">
            <?php if ((int)$order['has_digital'] === 1 && !$order['shipping_address']): ?>
              <span class="sh-table__meta">Digital order — no shipping required.</span>
            <?php else: ?>
              <?= e((string)$order['shipping_address']) ?><br>
              <?= e(trim((string)$order['shipping_area'] . ', ' . (string)$order['shipping_city'] . ' ' . (string)$order['shipping_postcode'], ', ')) ?>
            <?php endif; ?>
            <div style="margin-top:9px" class="sh-table__meta">
              Placed <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?><br>
              Method: <?= e((string)$order['payment_method_name']) ?>
            </div>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Parcels</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.7">
            <?php if ((int)$order['has_digital'] === 1 && !$order['shipping_address']): ?>
              <span class="sh-table__meta">Digital order — no shipping required.</span>
            <?php else: ?>
              <?php if (!$shipments): ?>
                <span class="sh-table__meta">No parcel created for this order yet.</span>
              <?php else: foreach ($shipments as $s): ?>
                <div style="display:flex;gap:8px;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--sh-line)">
                  <div style="min-width:0">
                    <a href="<?= e(sh_url('admin/parcels.php?id=' . (int)$s['id'])) ?>" style="font-weight:600"><?= e($s['shipment_number']) ?></a>
                    <div class="sh-table__meta"><?= e((string)($s['courier_name'] ?? 'Unassigned')) ?>
                      <?= $s['tracking_number'] ? ' · ' . e($s['tracking_number']) : '' ?></div>
                  </div>
                  <span class="sh-badge <?= e(sh_shipment_status_class($s['status'])) ?>"><?= e(sh_shipment_status_label($s['status'])) ?></span>
                </div>
              <?php endforeach; endif; ?>
              <a class="sh-btn sh-btn--sm sh-btn--block" style="margin-top:10px"
                 href="<?= e(sh_url('admin/parcels.php?order_id=' . (int)$order['id'])) ?>"><?= sh_icon('plus', 14) ?> Create parcel</a>
            <?php endif; ?>
          </div>
        </div>

        <?php
        $audit = sh_audit_for('order', (int)$order['id'], 30);
        $timeline = [['label' => 'Order placed', 'sub' => (string)($order['payment_method_name'] ?: ''), 'at' => $order['created_at'], 'cls' => 'is-done']];
        foreach ($payments as $p) {
            if ($p['transaction_id'] || $p['kind'] === 'gateway') { $timeline[] = ['label' => 'Payment submitted', 'sub' => (string)$p['method_name'] . ($p['transaction_id'] ? ' · TrxID ' . $p['transaction_id'] : ''), 'at' => $p['created_at'], 'cls' => 'is-done']; }
            if ($p['verified_at']) { $timeline[] = ['label' => $p['status'] === 'verified' ? 'Payment verified' : 'Payment ' . sh_status_label($p['status']), 'sub' => sh_money($p['amount']), 'at' => $p['verified_at'], 'cls' => $p['status'] === 'verified' ? 'is-done' : 'is-bad']; }
        }
        foreach (array_reverse($audit) as $a) {
            if ($a['action'] === 'order_status_changed') { $timeline[] = ['label' => 'Status changed to ' . sh_status_label(trim((string)$a['new_value'], '"')), 'sub' => 'by ' . $a['admin_email'], 'at' => $a['created_at'], 'cls' => in_array(trim((string)$a['new_value'], '"'), ['cancelled', 'returned', 'payment_rejected'], true) ? 'is-bad' : 'is-done']; }
        }
        if (!array_filter($audit, static fn($a) => $a['action'] === 'order_status_changed') && $order['updated_at'] !== $order['created_at'] && !in_array($order['status'], ['pending', 'awaiting_payment'], true)) {
            $timeline[] = ['label' => 'Current status: ' . sh_status_label($order['status']), 'sub' => 'Last update', 'at' => $order['updated_at'], 'cls' => in_array($order['status'], ['cancelled', 'returned', 'payment_rejected'], true) ? 'is-bad' : 'is-done'];
        }
        usort($timeline, static fn($x, $y) => strcmp((string)$x['at'], (string)$y['at']));
        ?>
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Status timeline</h2></div>
          <div class="sh-panel__body">
            <ul class="sh-timeline">
              <?php foreach ($timeline as $t): ?>
                <li class="<?= e($t['cls']) ?>"><strong><?= e($t['label']) ?></strong><small><?= e(date('d M Y, h:i A', strtotime((string)$t['at']))) ?><?= $t['sub'] !== '' ? ' · ' . e($t['sub']) : '' ?></small></li>
              <?php endforeach; ?>
            </ul>
            <?php if ($audit): ?>
              <details class="sh-cv__prev" style="margin-top:6px"><summary>Admin activity (<?= count($audit) ?>)</summary>
                <ul>
                  <?php foreach ($audit as $a): ?>
                    <li class="sh-muted"><?= e(date('d M, h:i A', strtotime($a['created_at']))) ?> · <?= e(sh_audit_action_label($a['action'])) ?> · <?= e($a['admin_email']) ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('pencil', 17) ?> Order note</h2></div>
          <div class="sh-panel__body">
            <form method="post">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="note"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
              <div class="sh-field"><label class="sh-field__label sh-sr-only" for="o-note">Note</label>
                <textarea class="sh-textarea" id="o-note" name="order_note" rows="4"><?= e((string)$order['order_note']) ?></textarea></div>
              <button class="sh-btn sh-btn--ghost sh-btn--block" type="submit">Save note</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <style>@media (max-width: 1050px){.sh-ordgrid{grid-template-columns:minmax(0,1fr)!important}}</style>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

/* ---------------- Order list ---------------- */
$q = sh_get('q');
$fStatus = sh_get('status');
$fPay = sh_get('payment');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1']; $args = [];
if ($q !== '') {
    $like = "%$q%";
    $w = ['o.order_number LIKE ?', 'o.customer_name LIKE ?', 'o.customer_phone LIKE ?', 'o.customer_email LIKE ?',
          'EXISTS (SELECT 1 FROM payments px WHERE px.order_id = o.id AND px.transaction_id LIKE ?)',
          'EXISTS (SELECT 1 FROM order_items ox WHERE ox.order_id = o.id AND ox.product_name LIKE ?)'];
    array_push($args, $like, $like, $like, $like, $like, $like);
    foreach (sh_phone_variants(sh_phone_normalize($q)) as $v) { $w[] = 'o.customer_phone = ?'; $args[] = $v; }
    $where[] = '(' . implode(' OR ', $w) . ')';
}
$statusFilters = [
    'pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'completed' => 'Completed',
    'cancelled' => 'Cancelled', 'returned' => 'Returned', 'awaiting_payment' => 'Awaiting Payment', 'payment_submitted' => 'Payment Submitted',
    'payment_rejected' => 'Payment Rejected', 'pay_pending' => 'Payment Pending', 'pay_verified' => 'Payment Verified', 'cod' => 'Cash on Delivery', 'online' => 'Online Payment',
];
if ($fStatus === 'pay_pending') { $where[] = "(o.payment_status IN ('unpaid','submitted') AND o.status NOT IN ('cancelled','returned'))"; }
elseif ($fStatus === 'pay_verified') { $where[] = "o.payment_status = 'verified'"; }
elseif ($fStatus === 'pending') { $where[] = "o.status IN ('pending','awaiting_payment','payment_submitted')"; }
elseif ($fStatus === 'cod') { $where[] = "(EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.id = o.payment_method_id AND pm.type = 'cod') OR EXISTS (SELECT 1 FROM payments pc WHERE pc.order_id = o.id AND pc.kind = 'cod'))"; }
elseif ($fStatus === 'online') { $where[] = "NOT (EXISTS (SELECT 1 FROM payment_methods pm WHERE pm.id = o.payment_method_id AND pm.type = 'cod') OR EXISTS (SELECT 1 FROM payments pc WHERE pc.order_id = o.id AND pc.kind = 'cod'))"; }
elseif ($fStatus !== '' && isset($statusFilters[$fStatus])) { $where[] = 'o.status = ?'; $args[] = $fStatus; }
if ($fPay !== '') { $where[] = 'o.payment_status = ?'; $args[] = $fPay; }
$whereSql = implode(' AND ', $where);

$total = (int)sh_val("SELECT COUNT(*) FROM orders o WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all("SELECT o.* FROM orders o WHERE $whereSql ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
$itemsByOrder = sh_order_items_for(array_column($rows, 'id'));
$riskByOrder = sh_verify_risk_for_orders($rows);

if (in_array($fStatus, ['pending', 'processing', 'completed', 'cancelled', 'returned'], true) && $q === '' && $fPay === '') { $adminPage = 'orders:' . $fStatus; }
$adminTitle = 'Orders';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Orders (<?= number_format($total) ?>)</h2>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Order no, name, phone, email, TrxID, product"></div>
      <div class="sh-field"><label class="sh-field__label" for="f-st">Order status</label>
        <select class="sh-select" id="f-st" name="status">
          <option value="">All</option>
          <?php foreach ($statusFilters as $s => $lbl): ?>
            <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-pay">Payment</label>
        <select class="sh-select" id="f-pay" name="payment">
          <option value="">All</option>
          <?php foreach (['unpaid', 'submitted', 'verified', 'rejected', 'refunded'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $fPay === $s ? 'selected' : '' ?>><?= e(sh_status_label($s)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($q !== '' || $fStatus !== '' || $fPay !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-panel__body">
    <?php if (!$rows): ?>
      <div class="sh-admin-empty"><?= sh_icon('package', 26) ?><p>No data available for these filters.</p></div>
    <?php else: ?>
    <div class="sh-ocards">
      <?php foreach ($rows as $o):
        $its = $itemsByOrder[(int)$o['id']] ?? [];
        $first = $its[0] ?? null;
        $extra = max(0, count($its) - 1);
        $qty = array_sum(array_map(static fn($i) => (int)$i['quantity'], $its));
        $openUrl = sh_url('admin/orders.php?id=' . (int)$o['id']);
      ?>
      <article class="sh-ocard">
        <a class="sh-ocard__product" href="<?= e($openUrl) ?>">
          <span class="sh-ocard__thumbs">
            <?php if ($first === null): ?>
              <img class="sh-ocard__thumb" src="<?= e(sh_product_image(null)) ?>" alt="">
            <?php else: foreach (array_slice($its, 0, 3) as $k => $it): ?>
              <img class="sh-ocard__thumb <?= $k > 0 ? 'sh-ocard__thumb--stack' : '' ?>" src="<?= e(sh_order_item_image($it)) ?>" alt="" loading="lazy">
            <?php endforeach; endif; ?>
          </span>
          <span class="sh-ocard__pinfo">
            <span class="sh-ocard__eyebrow">Ordered product</span>
            <span class="sh-ocard__pname"><?= $first ? e($first['product_name']) : 'No items recorded' ?></span>
            <?php if ($first): $variant = sh_order_item_variant_text($first); ?>
              <span class="sh-ocard__pmeta">
                <?= $variant !== '' ? e($variant) : e(ucfirst((string)$first['product_type'])) ?>
                · Qty <?= (int)$first['quantity'] ?>
              </span>
            <?php endif; ?>
            <?php if ($extra > 0): ?>
              <span class="sh-ocard__more">+<?= $extra ?> more item<?= $extra === 1 ? '' : 's' ?> · <?= $qty ?> units total</span>
            <?php endif; ?>
          </span>
        </a>

        <dl class="sh-ocard__facts">
          <div><dt><?= sh_icon('package', 12) ?> Order</dt>
            <dd><a class="sh-ocard__num" href="<?= e($openUrl) ?>"><?= e($o['order_number']) ?></a>
              <span class="sh-ocard__sub"><?= e(date('d M Y, h:i A', strtotime($o['created_at']))) ?></span></dd></div>
          <div><dt><?= sh_icon('user', 12) ?> Customer</dt>
            <dd><?= e($o['customer_name']) ?><span class="sh-ocard__sub"><?= e($o['customer_phone']) ?></span></dd></div>
          <div><dt><?= sh_icon('credit-card', 12) ?> Payment</dt>
            <dd><?= e((string)($o['payment_method_name'] ?: '—')) ?><span class="sh-ocard__sub sh-ocard__total"><?= e(sh_money($o['total'])) ?></span></dd></div>
        </dl>

        <div class="sh-ocard__foot">
          <div class="sh-ocard__statuses">
            <span class="sh-ocard__stat"><small>Payment</small><span class="sh-pill <?= e(sh_status_class($o['payment_status'])) ?>"><?= e(sh_status_label($o['payment_status'])) ?></span></span>
            <span class="sh-ocard__stat"><small>Order</small><span class="sh-pill <?= e(sh_status_class($o['status'])) ?> <?= $o['status'] === 'processing' ? 'sh-pill--info' : '' ?>"><?= e(sh_status_label($o['status'])) ?></span></span>
            <?php if (isset($riskByOrder[(int)$o['id']])): $rk = $riskByOrder[(int)$o['id']]; ?>
              <span class="sh-ocard__stat"><small>Delivery risk</small><span class="sh-pill <?= e($rk['class']) ?>"><?= e($rk['label']) ?></span></span>
            <?php endif; ?>
          </div>
          <a class="sh-btn sh-btn--sm" href="<?= e($openUrl) ?>"><?= sh_icon('external', 14) ?> Open order</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/orders.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
