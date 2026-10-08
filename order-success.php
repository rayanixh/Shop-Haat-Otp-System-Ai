<?php
/**
 * Order confirmation + live tracking + digital code delivery view.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$orderId = sh_int($_GET['id'] ?? 0);
$order = $orderId > 0 ? sh_order_get($orderId) : null;

if ($order === null || !sh_order_can_view($order)) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('x-circle', 26) . '</span>'
        . '<h1 class="sh-empty__title">Order not found</h1>'
        . '<p class="sh-empty__text">We could not find this order, or you do not have permission to view it.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('index.php')) . '">Back to homepage</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

$items = sh_order_items($orderId);
$codes = (int)$order['has_digital'] === 1 ? sh_order_codes($orderId) : [];
$isCod = false;
if ($order['payment_method_id']) {
    $m = sh_one('SELECT type FROM payment_methods WHERE id = ?', [(int)$order['payment_method_id']]);
    $isCod = $m && $m['type'] === 'cod';
}

$pageTitle = 'Order ' . $order['order_number'];
$pageDescription = 'Your order confirmation.';
require_once SH_ROOT . '/includes/header.php';

// Tracking steps
$digital = (int)$order['has_digital'] === 1;
$steps = $digital
    ? ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Code Delivered', 'Completed']
    : ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Processing', 'Completed'];
$stateIndex = match ($order['status']) {
    'pending', 'awaiting_payment' => 0,
    'payment_submitted'           => 1,
    'payment_verified'            => 2,
    'processing'                  => $digital ? 2 : 3,
    'completed'                   => 4,
    default                       => 0,
};
if ($isCod && $order['status'] === 'processing') { $stateIndex = 3; }
$rejected = in_array($order['status'], ['payment_rejected', 'cancelled', 'returned'], true);

// Real completion signals from the order row (never assumed):
//  - payment confirmed  → payment_status 'verified' (or the order already moved past verification)
//  - codes delivered    → codes_delivered flag set by the delivery routine
//  - order completed    → status 'completed'
$orderDone     = $order['status'] === 'completed';
$paymentDone   = $orderDone || $order['payment_status'] === 'verified'
              || (!$isCod && in_array($order['status'], ['payment_verified', 'processing'], true));
$codesDone     = $digital && ((int)($order['codes_delivered'] ?? 0) === 1 || $orderDone);
if ($codesDone && $stateIndex < 3) { $stateIndex = 3; }
// Every step up to and including the current one is "done" once the order is completed;
// otherwise the current step stays in the pending (orange) state.
$trackDoneUpTo = $orderDone ? count($steps) - 1 : $stateIndex - 1;
?>
<div class="sh-wrap">
  <div class="sh-steps" style="margin-top:12px">
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Cart</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Checkout</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Payment</span>
    <span class="sh-steps__sep"></span>
    <?php if ($paymentDone && !$rejected): ?>
      <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Confirmation</span>
    <?php else: ?>
      <span class="sh-steps__item sh-steps__item--on"><span class="sh-steps__num">4</span> Confirmation</span>
    <?php endif; ?>
  </div>

  <div class="sh-section" style="text-align:center;padding:26px 16px">
    <span style="display:inline-grid;place-items:center;width:62px;height:62px;border-radius:50%;
      background:<?= $rejected ? 'var(--sh-bad-soft)' : 'var(--sh-ok-soft)' ?>;
      color:<?= $rejected ? 'var(--sh-bad)' : 'var(--sh-ok)' ?>;margin-bottom:12px">
      <?= sh_icon($rejected ? 'x-circle' : 'check-circle', 30) ?>
    </span>
    <h1 style="font-size:21px;font-weight:800;margin-bottom:6px">
      <?= $rejected ? 'There is an issue with your order' : 'Thank you — your order is confirmed' ?>
    </h1>
    <p style="font-size:14px;color:var(--sh-muted);max-width:520px;margin:0 auto 12px">
      <?php if ($rejected): ?>
        Your payment could not be verified. Please review the details below or contact our support team.
      <?php elseif ($isCod): ?>
        Order <strong><?= e($order['order_number']) ?></strong> has been placed. Please keep <?= e(sh_money($order['total'])) ?> ready for the delivery agent.
      <?php elseif ($order['payment_status'] === 'submitted'): ?>
        Order <strong><?= e($order['order_number']) ?></strong> has been placed and your payment is awaiting verification.
      <?php elseif ($order['payment_status'] === 'verified'): ?>
        Order <strong><?= e($order['order_number']) ?></strong> is paid and being processed.
      <?php else: ?>
        Order <strong><?= e($order['order_number']) ?></strong> has been created. Please complete the payment to continue.
      <?php endif; ?>
    </p>
    <div style="display:flex;gap:9px;justify-content:center;flex-wrap:wrap">
      <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('order-details.php?id=' . $orderId)) ?>"><?= sh_icon('eye', 15) ?> Order details</a>
      <?php if ($order['payment_status'] === 'unpaid' && !$isCod): ?>
        <a class="sh-btn" href="<?= e(sh_url('payment.php?id=' . $orderId)) ?>"><?= sh_icon('credit-card', 15) ?> Complete payment</a>
      <?php endif; ?>
      <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('products.php')) ?>">Continue shopping</a>
    </div>
  </div>

  <?php if (!$rejected): ?>
  <div class="sh-section">
    <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('truck', 18) ?> Order Progress</h2></div>
    <div class="sh-track">
      <?php foreach ($steps as $i => $label): ?>
        <div class="sh-track__step <?= $i <= $trackDoneUpTo ? 'sh-track__step--done' : ($i === $stateIndex ? 'sh-track__step--on' : '') ?>">
          <span class="sh-track__dot"><?= $i <= $stateIndex ? sh_icon('check-circle', 11) : '' ?></span>
          <span class="sh-track__label"><?= e($label) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($codes): ?>
  <div class="sh-section">
    <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('key', 18) ?> Your Digital Codes</h2></div>
    <p style="font-size:13px;color:var(--sh-muted);margin-bottom:8px">
      These codes are unique to your order. Keep them private — each code can only be used once.
    </p>
    <div class="sh-codebox">
      <?php foreach ($codes as $c): ?>
        <div class="sh-codebox__row">
          <span class="sh-codebox__label"><?= e($c['product_name']) ?></span>
          <span class="sh-codebox__code"><?= e($c['code']) ?></span>
          <button class="sh-btn sh-btn--sm" type="button" data-copy="<?= e($c['code']) ?>">
            <?= sh_icon('copy', 13) ?> <span data-copy-label>Copy</span>
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php elseif ((int)$order['has_digital'] === 1): ?>
  <div class="sh-section">
    <div class="sh-alert sh-alert--info" style="margin:0">
      <?= sh_icon('clock', 16) ?>
      <span>Your digital codes will appear here automatically as soon as the payment is verified.</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="sh-cartlayout">
    <div class="sh-section">
      <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('package', 18) ?> Items</h2></div>
      <?php foreach ($items as $it): ?>
        <div class="sh-mini-item">
          <img class="sh-mini-item__img" src="<?= e(sh_product_image($it['product_image'])) ?>" alt="" loading="lazy">
          <div class="sh-mini-item__name"><?= e($it['product_name']) ?>
            <div class="sh-mini-item__qty">Qty <?= (int)$it['quantity'] ?> × <?= e(sh_money($it['unit_price'])) ?></div>
          </div>
          <span class="sh-mini-item__price"><?= e(sh_money($it['line_total'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <aside class="sh-summary">
      <h2 class="sh-summary__title">Payment Summary</h2>
      <div class="sh-summary__row"><span>Order status</span><span class="sh-badge <?= e(sh_status_class($order['status'])) ?>"><?= e(sh_status_label($order['status'])) ?></span></div>
      <div class="sh-summary__row"><span>Payment status</span><span class="sh-badge <?= e(sh_status_class($order['payment_status'])) ?>"><?= e(sh_status_label($order['payment_status'])) ?></span></div>
      <div class="sh-summary__row"><span>Method</span><span><?= e($order['payment_method_name']) ?></span></div>
      <div class="sh-summary__row"><span>Subtotal</span><span><?= e(sh_money($order['subtotal'])) ?></span></div>
      <?php if ((float)$order['discount'] > 0): ?>
        <div class="sh-summary__row sh-summary__row--discount"><span>Discount</span><span>-<?= e(sh_money($order['discount'])) ?></span></div>
      <?php endif; ?>
      <div class="sh-summary__row"><span>Delivery</span><span><?= (float)$order['delivery_fee'] > 0 ? e(sh_money($order['delivery_fee'])) : 'Free' ?></span></div>
      <div class="sh-summary__total"><span>Total</span><span><?= e(sh_money($order['total'])) ?></span></div>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
