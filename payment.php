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
$payment = sh_order_latest_payment($orderId);
// The order/payment snapshot, not a query-string provider name or a posted
// method ID, is the only authority for this screen's payment route.
$route = sh_order_payment_route($order, $payment);
if (!empty($route['ok']) && (string)($route['kind'] ?? '') === 'cod') {
    // COD has already followed its order-created workflow. Never expose a
    // generic online-payment page or transaction-ID form for it.
    sh_redirect('order-success.php?id=' . $orderId);
}
$method = is_array($route['method'] ?? null) ? $route['method'] : null;
$selectedMethodName = trim((string)($route['method_name'] ?? $order['payment_method_name'] ?? ''));
if ($selectedMethodName === '') { $selectedMethodName = 'Selected payment method'; }
$routeError = empty($route['ok']) ? (string)($route['error'] ?? 'The selected payment method is unavailable.') : '';
$methodLogo = $method !== null ? sh_payment_logo_url($method) : '';
$error = '';
// Keep valid customer-entered payment details visible when validation, database
// or provider-notification work fails. They are never written until
// sh_submit_manual_payment() commits its own transaction.
$manualTransactionId = trim((string)($_POST['transaction_id'] ?? ''));
$manualSenderPhone = trim((string)($_POST['sender_phone'] ?? $order['customer_phone'] ?? ''));
$submitted = ($order['payment_status'] === 'submitted');

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
$payment = sh_order_latest_payment($orderId);
$route = sh_order_payment_route($order, $payment);
$method = is_array($route['method'] ?? null) ? $route['method'] : null;
$selectedMethodName = trim((string)($route['method_name'] ?? $order['payment_method_name'] ?? ''));
if ($selectedMethodName === '') { $selectedMethodName = 'Selected payment method'; }
$routeError = empty($route['ok']) ? (string)($route['error'] ?? 'The selected payment method is unavailable.') : '';
$methodLogo = $method !== null ? sh_payment_logo_url($method) : '';
$submitted = ($order['payment_status'] === 'submitted');

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
          <?php if ($methodLogo !== ''): ?>
            <img src="<?= e($methodLogo) ?>" alt="<?= e($selectedMethodName) ?>" style="width:32px;height:32px;object-fit:contain">
          <?php endif; ?>
          <div>
            <h1>Pay with <?= e($selectedMethodName) ?></h1>
            <p class="sh-payment-card__sub">Order <?= e($order['order_number']) ?> · <?= e($selectedMethodName) ?> · placed <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?></p>
          </div>
        </div>

        <div class="sh-payment-card__body">
          <?php if ($order['payment_status'] === 'rejected'): ?>
            <div class="sh-alert sh-alert--error">
              <?= sh_icon('x-circle', 17) ?>
              <div><strong>Payment rejected</strong>
                <p style="margin-top:3px"><?= e($payment['admin_note'] ?? 'The transaction could not be verified.') ?>
                  <?php if (!empty($route['ok']) && (string)($route['kind'] ?? '') === 'manual'): ?>
                    Please submit corrected <?= e($selectedMethodName) ?> transaction details or contact support.
                  <?php else: ?>
                    This order has not been switched to another provider. Return to Checkout to explicitly choose a new method or contact support.
                  <?php endif; ?></p></div>
            </div>
          <?php endif; ?>

          <?php if ($submitted): ?>
            <div class="sh-alert sh-alert--warning">
              <?= sh_icon('clock', 17) ?>
              <div>
                <strong>Payment pending verification</strong>
                <p style="margin-top:3px">We have received your <?= e($selectedMethodName) ?> transaction details and our team is verifying the payment.
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

          <?php elseif (empty($route['ok'])): ?>
            <div class="sh-alert sh-alert--warning">
              <?= sh_icon('alert', 17) ?>
              <div><strong><?= e($selectedMethodName) ?> is unavailable for this order</strong>
                <p style="margin-top:3px"><?= e($routeError) ?> This order has not been switched to another provider.</p></div>
            </div>
            <div class="sh-actions" style="margin-top:12px">
              <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('checkout.php?retry_order=' . $orderId)) ?>">Return to Checkout</a>
              <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('support.php')) ?>">Contact support</a>
            </div>

          <?php elseif ((string)$route['kind'] === 'gateway'):
            $gw = $method !== null && !empty($method['gateway_id']) ? sh_gateway_by_id((int)$method['gateway_id']) : null; ?>
            <div class="sh-amount-box">
              <p class="sh-amount-box__label">Amount to pay with <?= e($selectedMethodName) ?></p>
              <p class="sh-amount-box__value"><?= e(sh_money($order['total'])) ?></p>
            </div>
            <?php if ($gw === null || !sh_gateway_is_configured($gw) || !sh_gateway_can_initiate($gw)): ?>
              <div class="sh-alert sh-alert--warning">
                <?= sh_icon('alert', 17) ?>
                <div><strong><?= e($selectedMethodName) ?> is currently unavailable</strong>
                  <p style="margin-top:3px">No payment has been requested and this order has not been switched to another provider.
                    Return to Checkout to choose another method or contact support.</p></div>
              </div>
              <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('checkout.php?retry_order=' . $orderId)) ?>">Return to Checkout</a>
            <?php else: ?>
              <div class="sh-alert sh-alert--info">
                <?= sh_icon('external', 17) ?>
                <div><strong>You will be redirected to <?= e($gw['name']) ?></strong>
                  <p style="margin-top:3px">Complete the payment on the provider's secure page. Your order is confirmed only
                    after our server verifies the transaction with <?= e($gw['name']) ?> — a browser redirect alone is never trusted.</p></div>
              </div>
              <p style="font-size:12.5px;color:var(--sh-muted)">Mode: <?= e(ucfirst($gw['mode'])) ?></p>
            <?php endif; ?>

          <?php elseif ((string)$route['kind'] === 'manual' && $method !== null): ?>
            <div class="sh-amount-box">
              <p class="sh-amount-box__label">Amount to send with <?= e($selectedMethodName) ?></p>
              <p class="sh-amount-box__value"><?= e(sh_money($order['total'])) ?></p>
            </div>

            <div class="sh-merchant">
              <div>
                <p class="sh-merchant__label"><?= e($selectedMethodName) ?> merchant number</p>
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
                <p class="sh-instructions__title">How to pay with <?= e($selectedMethodName) ?></p>
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
                         value="<?= e($manualTransactionId) ?>" placeholder="e.g. 9F7HD3K1QA" style="text-transform:uppercase">
                  <p class="sh-field__hint">Copy it exactly from your <?= e($selectedMethodName) ?> confirmation message.</p>
                </div>
                <div class="sh-field">
                  <label class="sh-field__label" for="pay-phone">Your <?= e($selectedMethodName) ?> number <span class="sh-field__req">*</span></label>
                  <input class="sh-input" id="pay-phone" name="sender_phone" required maxlength="20"
                         placeholder="01XXXXXXXXX" value="<?= e($manualSenderPhone) ?>">
                  <p class="sh-field__hint">The number you sent the money from.</p>
                </div>
              </div>
              <button class="sh-btn sh-btn--lg sh-btn--block" type="submit">
                <?= sh_icon('check-circle', 17) ?> Submit <?= e($selectedMethodName) ?> payment
              </button>
              <p style="font-size:12px;color:var(--sh-muted);margin-top:10px;text-align:center">
                Your payment will be marked <strong>Pending verification</strong> until our team confirms the transaction.
                Payments are never approved automatically.
              </p>
            </form>

          <?php else: ?>
            <div class="sh-alert sh-alert--warning">
              <?= sh_icon('alert', 17) ?>
              <div><strong>The selected payment method cannot be opened</strong>
                <p style="margin-top:3px">This order has not been changed to another payment provider. Please return to Checkout or contact support.</p></div>
            </div>
            <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('checkout.php?retry_order=' . $orderId)) ?>">Return to Checkout</a>
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
        <?php if (!empty($route['ok']) && (string)($route['kind'] ?? '') === 'manual' && !$submitted): ?>
          <span><strong>Secure <?= e($selectedMethodName) ?> payment.</strong> We never ask for your PIN or OTP. Only the transaction ID is required.
            All amounts are verified against our server records before an order is approved.</span>
        <?php elseif (!empty($route['ok']) && (string)($route['kind'] ?? '') === 'gateway'): ?>
          <span><strong>Secure <?= e($selectedMethodName) ?> payment.</strong> Never share your PIN or OTP. Payment is confirmed only after server-side provider verification.</span>
        <?php else: ?>
          <span><strong>Order security.</strong> The saved order and selected payment method are verified on our server before any payment status changes.</span>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
