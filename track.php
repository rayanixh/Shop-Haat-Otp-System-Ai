<?php
/** Public order lookup using the persisted order number and customer phone. */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/order-timeline.php';
require_once SH_ROOT . '/includes/order-tracking.php';

sh_session_start();
$order = null;
$timeline = null;
$lookupError = '';
$orderNumber = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    header('Cache-Control: no-store, private');
    $orderNumber = mb_strtoupper(mb_substr(trim(sh_post('order_number')), 0, 40));
    $phone = mb_substr(trim(sh_post('phone')), 0, 30);

    if (sh_order_tracking_rate_limited($orderNumber, $phone)) {
        http_response_code(429);
        if (!headers_sent()) { header('Retry-After: 600'); }
        $lookupError = 'Too many tracking attempts. Please wait and try again.';
    } else {
        $variants = sh_phone_variants($phone);
        if (!preg_match('/^[A-Z0-9-]{4,40}$/', $orderNumber) || !$variants) {
            http_response_code(404);
            $lookupError = 'No order matched those details.';
        } else {
            try {
                $holders = implode(',', array_fill(0, count($variants), '?'));
                // The public tracker loads only status data, never the customer's
                // phone, email, address, notes, payment record or line-item PII.
                $order = sh_one(
                    'SELECT id, order_number, status, payment_status, payment_method_id, created_at
                     FROM orders WHERE order_number = ? AND customer_phone IN (' . $holders . ') LIMIT 1',
                    array_merge([$orderNumber], $variants)
                );
                if ($order === null) {
                    http_response_code(404);
                    $lookupError = 'No order matched those details.';
                } else {
                    $payment = sh_one(
                        'SELECT kind, status, submitted_at, verified_at, transaction_id, gateway_reference
                         FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1',
                        [(int)$order['id']]
                    );
                    $items = sh_all('SELECT product_type FROM order_items WHERE order_id = ? ORDER BY id', [(int)$order['id']]);
                    $timeline = sh_order_timeline($order, $payment, $items);
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'public-order-track');
                http_response_code(503);
                $lookupError = 'Order tracking is temporarily unavailable. Please try again shortly.';
            }
        }
    }
}

$pageTitle = 'Track Your Order';
$pageDescription = 'Check the saved status of your ShopHaat order.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Track Your Order</span></nav>

  <section class="sh-section" style="max-width:760px;margin:0 auto">
    <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('package', 18) ?> Track Your Order</h1></div>
    <p class="sh-panel__note" style="margin:0 0 14px">Enter the order number and phone number used at checkout.</p>
    <?php if ($lookupError !== ''): ?><div class="sh-alert sh-alert--error" style="margin-bottom:14px"><?= sh_icon('x-circle', 16) ?><span><?= e($lookupError) ?></span></div><?php endif; ?>
    <form class="sh-filterbar" method="post">
      <?= sh_csrf_field() ?>
      <div class="sh-field"><label class="sh-field__label" for="track-number">Order number</label><input class="sh-input" id="track-number" name="order_number" value="<?= e($orderNumber) ?>" maxlength="40" autocomplete="off" required></div>
      <div class="sh-field"><label class="sh-field__label" for="track-phone">Phone number</label><input class="sh-input" id="track-phone" name="phone" value="" maxlength="30" inputmode="tel" autocomplete="tel" required></div>
      <button class="sh-btn" type="submit"><?= sh_icon('search', 15) ?> Track order</button>
    </form>
  </section>

  <?php if ($order !== null && $timeline !== null): ?>
    <section class="sh-section" style="max-width:760px;margin:14px auto 0">
      <div class="sh-section__head" style="gap:8px;flex-wrap:wrap">
        <h2 class="sh-section__title">Order <?= e((string)$order['order_number']) ?></h2>
        <span class="sh-badge <?= e(sh_status_class((string)$order['status'])) ?>" style="margin-left:auto"><?= e(sh_status_label((string)$order['status'])) ?></span>
        <span class="sh-badge <?= e(sh_status_class((string)$order['payment_status'])) ?>">Payment: <?= e(sh_status_label((string)$order['payment_status'])) ?></span>
      </div>
      <p class="sh-panel__note" style="margin:0 0 13px">Placed <?= e(date('d M Y, h:i A', strtotime((string)$order['created_at']))) ?></p>
      <?= sh_order_timeline_problem_render($timeline) ?>
      <div style="margin-top:<?= !empty($timeline['problem']) ? '14px' : '0' ?>"><?= sh_order_timeline_render($timeline) ?></div>
    </section>
  <?php endif; ?>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
