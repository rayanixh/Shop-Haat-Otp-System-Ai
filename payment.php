<?php
/**
 * Merchant-style payment page (manual MFS + automatic gateway entry point).
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
// Payment confirmation requires an authenticated account — guests are sent to
// Login/Signup and returned here after authenticating (cart/order preserved).
sh_require_login();

$orderId = sh_int($_GET['id'] ?? $_POST['order_id'] ?? 0);
$order = $orderId > 0 ? sh_order_get($orderId) : null;

if ($order === null || !sh_order_can_view($order)) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('x-circle', 26) . '</span>'
        . '<h1 class="sh-empty__title">Order not found</h1>'
        . '<p class="sh-empty__text">We could not find this order, or you do not have permission to view it.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('orders.php')) . '">View my orders</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

if ($order['payment_status'] === 'verified' || $order['status'] === 'completed') {
    sh_redirect('order-success.php?id=' . $orderId);
}

$items = sh_order_items($orderId);
$method = $order['payment_method_id'] ? sh_payment_method((int)$order['payment_method_id']) : null;
$allMethods = sh_payment_methods_available((bool)sh_val('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND product_type = \'physical\'', [$orderId], 0));
$error = '';
$submitted = ($order['payment_status'] === 'submitted');

// Allow switching method before payment is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && sh_post('form') === 'switch' && !$submitted) {
    sh_csrf_require();
    $newId = sh_int($_POST['payment_method_id'] ?? 0);
    foreach ($allMethods as $m) {
        if ((int)$m['id'] === $newId) {
            // Keep the order status consistent with the method type (COD orders skip the payment wait).
            $status = $m['type'] === 'cod' ? 'processing' : 'awaiting_payment';
            sh_query("UPDATE orders SET payment_method_id = ?, payment_method_name = ?,
                             status = IF(status IN ('processing','awaiting_payment'), ?, status) WHERE id = ?",
                [$newId, $m['name'], $status, $orderId]);
            $_SESSION['checkout_payment_method_id'] = $newId;   // remembered for the next checkout
            sh_query('UPDATE payments SET payment_method_id = ?, method_name = ?, kind = ? WHERE order_id = ? AND status = \'pending\'',
                [$newId, $m['name'], $m['type'], $orderId]);
            sh_redirect('payment.php?id=' . $orderId);
        }
    }
    $error = 'That payment method is not available.';
}

// Manual payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && sh_post('form') === 'manual') {
    sh_csrf_require();
    $res = sh_submit_manual_payment($orderId, sh_post('transaction_id'), sh_post('sender_phone'));
    if ($res['ok']) {
        sh_flash('success', 'Payment submitted. We will verify your transaction shortly.');
        sh_redirect('order-success.php?id=' . $orderId);
    }
    $error = $res['error'];
}

$order = sh_order_get($orderId);
$submitted = ($order['payment_status'] === 'submitted');
$payment = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);

$pageTitle = 'Complete Payment — ' . $order['order_number'];
$pageDescription = 'Complete the payment for your order.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-steps" style="margin-top:12px">
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Cart</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Checkout</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item sh-steps__item--on"><span class="sh-steps__num">3</span> Payment</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item"><span class="sh-steps__num">4</span> Confirmation</span>
  </div>

  <?php if ($error): ?>
    <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
  <?php endif; ?>

  <div class="sh-paylayout">
    <!-- LEFT: payment interface -->
    <div>
      <div class="sh-payment-card">
        <div class="sh-payment-card__head">
          <?= sh_icon('credit-card', 21) ?>
          <div>
            <h1>Complete your payment</h1>
            <p class="sh-payment-card__sub">Order <?= e($order['order_number']) ?> · placed <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?></p>
          </div>
        </div>

        <?php if (count($allMethods) > 1 && !$submitted): ?>
          <p class="sh-payment-card__sub" style="padding:0 17px;margin:12px 0 -4px">Choose how you want to pay:</p>
          <form class="sh-paytabs" method="post" data-no-lock>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="switch">
            <?php foreach ($allMethods as $m): $lg = sh_payment_logo_url($m); ?>
              <button class="sh-paytab <?= $method && (int)$m['id'] === (int)$method['id'] ? 'sh-paytab--on' : '' ?>"
                      type="submit" name="payment_method_id" value="<?= (int)$m['id'] ?>">
                <?php if ($lg !== ''): ?><img src="<?= e($lg) ?>" alt="">
                <?php else: ?><?= sh_icon($m['type'] === 'cod' ? 'truck' : 'credit-card', 16) ?><?php endif; ?>
                <?= e($m['name']) ?>
              </button>
            <?php endforeach; ?>
          </form>
        <?php endif; ?>

        <div class="sh-payment-card__body">
          <?php if ($submitted): ?>
            <div class="sh-alert sh-alert--warning">
              <?= sh_icon('clock', 17) ?>
              <div>
                <strong>Payment pending verification</strong>
                <p style="margin-top:3px">We have received your transaction details and our team is verifying the payment.
                  You will be notified as soon as it is approved. This usually takes a few minutes during business hours.</p>
              </div>
            </div>
            <?php if ($payment && $payment['transaction_id']): ?>
              <dl class="sh-pdp__rows">
                <div class="sh-pdp__row"><dt>Method</dt><dd><?= e($payment['method_name']) ?></dd></div>
                <div class="sh-pdp__row"><dt>Transaction ID</dt><dd><strong><?= e($payment['transaction_id']) ?></strong></dd></div>
                <div class="sh-pdp__row"><dt>Paid from</dt><dd><?= e($payment['sender_phone']) ?></dd></div>
                <div class="sh-pdp__row"><dt>Amount</dt><dd><?= e(sh_money($payment['amount'])) ?></dd></div>
                <div class="sh-pdp__row"><dt>Status</dt><dd><span class="sh-badge sh-badge--warn"><?= sh_icon('clock', 12) ?> Pending verification</span></dd></div>
              </dl>
            <?php endif; ?>
            <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('order-details.php?id=' . $orderId)) ?>">View order details</a>

          <?php elseif ($order['payment_status'] === 'rejected'): ?>
            <div class="sh-alert sh-alert--error">
              <?= sh_icon('x-circle', 17) ?>
              <div><strong>Payment rejected</strong>
                <p style="margin-top:3px"><?= e($payment['admin_note'] ?? 'The transaction could not be verified.') ?>
                  Please submit the correct transaction details or contact support.</p></div>
            </div>

          <?php elseif ($method === null): ?>
            <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?><span>No payment method is selected for this order.</span></div>

          <?php elseif ($method['type'] === 'cod'): ?>
            <div class="sh-alert sh-alert--info"><?= sh_icon('truck', 17) ?>
              <div><strong>Cash on Delivery</strong>
                <p style="margin-top:3px"><?= e($method['instructions']) ?></p></div>
            </div>
            <a class="sh-btn sh-btn--lg" href="<?= e(sh_url('order-success.php?id=' . $orderId)) ?>">Continue <?= sh_icon('chevron-right', 16) ?></a>

          <?php elseif ($method['type'] === 'gateway'):
            $gw = $method['gateway_id'] ? sh_gateway_by_id((int)$method['gateway_id']) : null; ?>
            <div class="sh-amount-box">
              <p class="sh-amount-box__label">Amount to pay</p>
              <p class="sh-amount-box__value"><?= e(sh_money($order['total'])) ?></p>
            </div>
            <?php if ($gw === null || !sh_gateway_is_configured($gw)): ?>
              <div class="sh-alert sh-alert--warning">
                <?= sh_icon('alert', 17) ?>
                <div><strong>This gateway is not configured</strong>
                  <p style="margin-top:3px">Automatic payment is unavailable because the merchant credentials have not been
                    added yet. Please choose another payment method above or contact support.</p></div>
              </div>
            <?php else: ?>
              <div class="sh-alert sh-alert--info">
                <?= sh_icon('external', 17) ?>
                <div><strong>You will be redirected to <?= e($gw['name']) ?></strong>
                  <p style="margin-top:3px">Complete the payment on the provider's secure page. Your order is confirmed only
                    after our server verifies the transaction with <?= e($gw['name']) ?> — a browser redirect alone is never trusted.</p></div>
              </div>
              <p style="font-size:12.5px;color:var(--sh-muted)">Mode: <?= e(ucfirst($gw['mode'])) ?></p>
            <?php endif; ?>

          <?php else: /* manual MFS */ ?>
            <div class="sh-amount-box">
              <p class="sh-amount-box__label">Amount to send</p>
              <p class="sh-amount-box__value"><?= e(sh_money($order['total'])) ?></p>
            </div>

            <div class="sh-merchant">
              <div>
                <p class="sh-merchant__label"><?= e($method['name']) ?> merchant number</p>
                <p class="sh-merchant__number"><?= e($method['account_number']) ?></p>
                <?php if ($method['account_type']): ?>
                  <p class="sh-merchant__type">Account type: <?= e($method['account_type']) ?></p>
                <?php endif; ?>
              </div>
              <button class="sh-copy" type="button" data-copy="<?= e($method['account_number']) ?>">
                <?= sh_icon('copy', 15) ?> <span data-copy-label>Copy number</span>
              </button>
            </div>

            <?php if ($method['instructions']): ?>
              <div class="sh-instructions">
                <p class="sh-instructions__title">How to pay with <?= e($method['name']) ?></p>
                <div class="sh-instructions__list"><?= e($method['instructions']) ?></div>
              </div>
            <?php endif; ?>

            <form method="post" novalidate>
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="manual">
              <input type="hidden" name="order_id" value="<?= $orderId ?>">
              <div class="sh-grid2">
                <div class="sh-field">
                  <label class="sh-field__label" for="pay-trx">Transaction ID (TrxID) <span class="sh-field__req">*</span></label>
                  <input class="sh-input" id="pay-trx" name="transaction_id" required maxlength="60"
                         placeholder="e.g. 9F7HD3K1QA" style="text-transform:uppercase">
                  <p class="sh-field__hint">Copy it exactly from your <?= e($method['name']) ?> confirmation message.</p>
                </div>
                <div class="sh-field">
                  <label class="sh-field__label" for="pay-phone">Your <?= e($method['name']) ?> number <span class="sh-field__req">*</span></label>
                  <input class="sh-input" id="pay-phone" name="sender_phone" required maxlength="20"
                         placeholder="01XXXXXXXXX" value="<?= e($order['customer_phone']) ?>">
                  <p class="sh-field__hint">The number you sent the money from.</p>
                </div>
              </div>
              <button class="sh-btn sh-btn--lg sh-btn--block" type="submit">
                <?= sh_icon('check-circle', 17) ?> Submit Payment
              </button>
              <p style="font-size:12px;color:var(--sh-muted);margin-top:10px;text-align:center">
                Your payment will be marked <strong>Pending verification</strong> until our team confirms the transaction.
                Payments are never approved automatically.
              </p>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: order summary -->
    <aside class="sh-summary">
      <h2 class="sh-summary__title">Order Summary</h2>
      <?php foreach ($items as $it): ?>
        <div class="sh-mini-item">
          <img class="sh-mini-item__img" src="<?= e(sh_product_image($it['product_image'])) ?>" alt="" loading="lazy">
          <div class="sh-mini-item__name">
            <?= e($it['product_name']) ?>
            <div class="sh-mini-item__qty">Qty <?= (int)$it['quantity'] ?> × <?= e(sh_money($it['unit_price'])) ?></div>
          </div>
          <span class="sh-mini-item__price"><?= e(sh_money($it['line_total'])) ?></span>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:12px">
        <div class="sh-summary__row"><span>Subtotal</span><span><?= e(sh_money($order['subtotal'])) ?></span></div>
        <?php if ((float)$order['discount'] > 0): ?>
          <div class="sh-summary__row sh-summary__row--discount"><span>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><span>-<?= e(sh_money($order['discount'])) ?></span></div>
        <?php endif; ?>
        <div class="sh-summary__row"><span>Delivery</span><span><?= (float)$order['delivery_fee'] > 0 ? e(sh_money($order['delivery_fee'])) : 'Free' ?></span></div>
        <div class="sh-summary__total"><span>Total</span><span><?= e(sh_money($order['total'])) ?></span></div>
      </div>

      <div class="sh-secure-note">
        <?= sh_icon('shield', 16) ?>
        <span><strong>Secure payment.</strong> We never ask for your PIN or OTP. Only the transaction ID is required.
          All amounts are verified against our server records before an order is approved.</span>
      </div>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
