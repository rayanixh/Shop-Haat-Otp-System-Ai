<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$orderId = sh_int($_GET['id'] ?? 0);
$order = $orderId > 0 ? sh_order_get($orderId) : null;

// IDOR protection: only the owner (or the guest session that created it) may view.
if ($order === null || !sh_order_can_view($order)) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('x-circle', 26) . '</span>'
        . '<h1 class="sh-empty__title">Order not found</h1>'
        . '<p class="sh-empty__text">We could not find this order, or you do not have permission to view it.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('orders.php')) . '">My orders</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

$items = sh_order_items($orderId);
$payment = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
$codes = (int)$order['has_digital'] === 1 ? sh_order_codes($orderId) : [];

$digital = (int)$order['has_digital'] === 1;
$steps = $digital
    ? ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Code Delivered', 'Delivery']
    : ['Order Placed', 'Payment Submitted', 'Payment Verified', 'Processing', 'Delivery'];
$stateIndex = match ($order['status']) {
    'pending', 'awaiting_payment' => 0,
    'payment_submitted'           => 1,
    'payment_verified'            => 2,
    'processing'                  => $digital ? 2 : 3,
    'completed'                   => 4,
    default                       => 0,
};
$rejected = in_array($order['status'], ['payment_rejected', 'cancelled', 'returned'], true);

$accountPage = 'orders';
$pageTitle = 'Order ' . $order['order_number'];
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb">
    <a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?>
    <a href="<?= e(sh_url('orders.php')) ?>">My Orders</a> <?= sh_icon('chevron-right', 13) ?>
    <span aria-current="page"><?= e($order['order_number']) ?></span>
  </nav>

  <div class="sh-section">
    <div class="sh-section__head">
      <h1 class="sh-section__title"><?= sh_icon('package', 18) ?> Order <?= e($order['order_number']) ?></h1>
      <span class="sh-badge <?= e(sh_status_class($order['status'])) ?>" style="margin-left:auto"><?= e(sh_status_label($order['status'])) ?></span>
    </div>
    <p style="font-size:13px;color:var(--sh-muted)">Placed on <?= e(date('d M Y \a\t h:i A', strtotime($order['created_at']))) ?></p>

    <?php if ($rejected): ?>
      <div class="sh-alert sh-alert--error" style="margin-top:12px">
        <?= sh_icon('x-circle', 16) ?>
        <div><strong><?= e(sh_status_label($order['status'])) ?></strong>
          <?php if (!empty($payment['admin_note'])): ?><p style="margin-top:3px"><?= e($payment['admin_note']) ?></p><?php endif; ?></div>
      </div>
    <?php else: ?>
      <?php
        // A finished order has no "in progress" step: everything reached is done.
        $trackFinished = $order['status'] === 'completed';
      ?>
      <div class="sh-track" style="margin-top:14px">
        <?php foreach ($steps as $i => $label):
          if ($i < $stateIndex || ($trackFinished && $i <= $stateIndex)) { $stepClass = 'sh-track__step--done'; }
          elseif ($i === $stateIndex) { $stepClass = 'sh-track__step--on'; }
          else { $stepClass = ''; }
        ?>
          <div class="sh-track__step <?= $stepClass ?>">
            <span class="sh-track__dot"><?= $i <= $stateIndex ? sh_icon('check-circle', 11) : '' ?></span>
            <span class="sh-track__label"><?= e($label) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($codes): ?>
    <div class="sh-section">
      <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('key', 18) ?> Digital Codes</h2></div>
      <div class="sh-codebox">
        <?php foreach ($codes as $c): ?>
          <div class="sh-codebox__row">
            <span class="sh-codebox__label"><?= e($c['product_name']) ?></span>
            <span class="sh-codebox__code"><?= e($c['code']) ?></span>
            <button class="sh-btn sh-btn--sm" type="button" data-copy="<?= e($c['code']) ?>"><?= sh_icon('copy', 13) ?> <span data-copy-label>Copy</span></button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="sh-cartlayout">
    <div>
      <div class="sh-section">
        <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('box', 18) ?> Items (<?= count($items) ?>)</h2></div>
        <?php foreach ($items as $it): ?>
          <div class="sh-mini-item">
            <img class="sh-mini-item__img" src="<?= e(sh_product_image($it['product_image'])) ?>" alt="" loading="lazy">
            <div class="sh-mini-item__name"><?= e($it['product_name']) ?>
              <div class="sh-mini-item__qty"><?= e(ucfirst($it['product_type'])) ?> · Qty <?= (int)$it['quantity'] ?> × <?= e(sh_money($it['unit_price'])) ?></div></div>
            <span class="sh-mini-item__price"><?= e(sh_money($it['line_total'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($order['shipping_address']): ?>
      <div class="sh-section">
        <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('map-pin', 18) ?> Delivery Address</h2></div>
        <p style="font-size:13.5px;line-height:1.8">
          <strong><?= e($order['customer_name']) ?></strong><br>
          <?= e($order['shipping_address']) ?><br>
          <?= e(trim(($order['shipping_area'] ? $order['shipping_area'] . ', ' : '') . $order['shipping_city'] . ' ' . (string)$order['shipping_postcode'])) ?><br>
          <?= sh_icon('phone', 13) ?> <?= e($order['customer_phone']) ?><br>
          <?= sh_icon('mail', 13) ?> <?= e($order['customer_email']) ?>
        </p>
        <?php if ($order['order_note']): ?>
          <p style="font-size:13px;color:var(--sh-muted);margin-top:8px">Note: <?= e($order['order_note']) ?></p>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <aside class="sh-summary">
      <h2 class="sh-summary__title">Payment</h2>
      <div class="sh-summary__row"><span>Method</span><span><?= e($order['payment_method_name']) ?></span></div>
      <div class="sh-summary__row"><span>Status</span><span class="sh-badge <?= e(sh_status_class($order['payment_status'])) ?>"><?= e(sh_status_label($order['payment_status'])) ?></span></div>
      <?php if (!empty($payment['transaction_id'])): ?>
        <div class="sh-summary__row"><span>Transaction ID</span><span style="font-family:monospace"><?= e($payment['transaction_id']) ?></span></div>
      <?php endif; ?>
      <div class="sh-summary__row"><span>Subtotal</span><span><?= e(sh_money($order['subtotal'])) ?></span></div>
      <?php if ((float)$order['discount'] > 0): ?>
        <div class="sh-summary__row sh-summary__row--discount"><span>Discount</span><span>-<?= e(sh_money($order['discount'])) ?></span></div>
      <?php endif; ?>
      <div class="sh-summary__row"><span>Delivery</span><span><?= (float)$order['delivery_fee'] > 0 ? e(sh_money($order['delivery_fee'])) : 'Free' ?></span></div>
      <div class="sh-summary__total"><span>Total</span><span><?= e(sh_money($order['total'])) ?></span></div>
      <?php if ($order['payment_status'] === 'unpaid' && $order['status'] === 'awaiting_payment'): ?>
        <a class="sh-btn sh-btn--block" style="margin-top:12px" href="<?= e(sh_url('payment.php?id=' . $orderId)) ?>">
          <?= sh_icon('credit-card', 15) ?> Complete payment</a>
      <?php endif; ?>
      <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('support.php')) ?>">
        <?= sh_icon('headphones', 15) ?> Need help with this order</a>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
