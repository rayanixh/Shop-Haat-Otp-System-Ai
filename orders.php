<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$user = sh_require_login();

$page = max(1, sh_int($_GET['page'] ?? 1, 1));
$perPage = 10;
$total = (int)sh_val('SELECT COUNT(*) FROM orders WHERE user_id = ?', [(int)$user['id']], 0);
$offset = ($page - 1) * $perPage;
$orders = sh_all('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset, [(int)$user['id']]);

$accountPage = 'orders';
$pageTitle = 'My Orders';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">My Orders</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-section" style="margin-bottom:12px">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('package', 18) ?> My Orders (<?= $total ?>)</h1></div>
      </div>

      <?php if (!$orders): ?>
        <div class="sh-empty">
          <span class="sh-empty__icon"><?= sh_icon('package', 26) ?></span>
          <h2 class="sh-empty__title">No orders yet</h2>
          <p class="sh-empty__text">When you place an order it will appear here with live status tracking.</p>
          <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Start shopping</a>
        </div>
      <?php else: ?>
        <?php foreach ($orders as $o):
          $items = sh_order_items((int)$o['id']); ?>
          <article class="sh-order-card">
            <div class="sh-order-card__head">
              <div>
                <p class="sh-order-card__num"><?= e($o['order_number']) ?></p>
                <p class="sh-order-card__date"><?= e(date('d M Y, h:i A', strtotime($o['created_at']))) ?></p>
              </div>
              <span class="sh-badge <?= e(sh_status_class($o['status'])) ?>" style="margin-left:auto"><?= e(sh_status_label($o['status'])) ?></span>
            </div>
            <div class="sh-order-card__body">
              <?php foreach (array_slice($items, 0, 3) as $it): ?>
                <div class="sh-mini-item">
                  <img class="sh-mini-item__img" src="<?= e(sh_product_image($it['product_image'])) ?>" alt="" loading="lazy">
                  <div class="sh-mini-item__name"><?= e($it['product_name']) ?>
                    <div class="sh-mini-item__qty">Qty <?= (int)$it['quantity'] ?></div></div>
                  <span class="sh-mini-item__price"><?= e(sh_money($it['line_total'])) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (count($items) > 3): ?>
                <p style="font-size:12.5px;color:var(--sh-muted);padding-top:7px">+ <?= count($items) - 3 ?> more item(s)</p>
              <?php endif; ?>
            </div>
            <div class="sh-order-card__foot">
              <span style="font-size:13px;color:var(--sh-muted)">Payment: <?= e($o['payment_method_name']) ?></span>
              <span class="sh-badge <?= e(sh_status_class($o['payment_status'])) ?>"><?= e(sh_status_label($o['payment_status'])) ?></span>
              <strong style="margin-left:auto;font-size:15px"><?= e(sh_money($o['total'])) ?></strong>
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('order-details.php?id=' . (int)$o['id'])) ?>">Details</a>
              <?php if ($o['payment_status'] === 'unpaid' && $o['status'] === 'awaiting_payment'): ?>
                <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('payment.php?id=' . (int)$o['id'])) ?>">Pay now</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        <?= sh_paginate($total, $perPage, $page, sh_url('orders.php')) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
