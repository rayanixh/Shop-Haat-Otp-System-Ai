<?php
/**
 * Shopping cart. Totals are always computed on the server.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/cart.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
$couponError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form === 'coupon') {
        $code = strtoupper(sh_post('coupon_code'));
        $s = sh_cart_summary(null);
        $res = sh_coupon_evaluate($code, $s['subtotal']);
        if ($res['valid']) {
            $_SESSION['coupon_code'] = $code;
            sh_flash('success', 'Coupon ' . $code . ' applied — you saved ' . sh_money($res['discount']) . '.');
        } else {
            $couponError = $res['error'];
        }
    } elseif ($form === 'coupon_remove') {
        unset($_SESSION['coupon_code']);
        sh_flash('info', 'Coupon removed.');
        sh_redirect('cart.php');
    }
}

$coupon = $_SESSION['coupon_code'] ?? null;
$summary = sh_cart_summary($coupon);
// A coupon that has become invalid must not linger in the session.
if ($coupon !== null && $summary['coupon'] === null) { unset($_SESSION['coupon_code']); }

$pageTitle = 'Shopping Cart';
$pageDescription = 'Review the items in your shopping cart before checkout.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?>
    <span aria-current="page">Shopping Cart</span>
  </nav>

  <?php if ($summary['issues']): ?>
    <div class="sh-alert sh-alert--warning">
      <?= sh_icon('alert', 16) ?>
      <div><strong>Some items changed:</strong>
        <ul><?php foreach ($summary['issues'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($couponError): ?>
    <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($couponError) ?></span></div>
  <?php endif; ?>

  <?php if (!$summary['items']): ?>
    <div class="sh-empty">
      <span class="sh-empty__icon"><?= sh_icon('shopping-cart', 26) ?></span>
      <h1 class="sh-empty__title">Your cart is empty</h1>
      <p class="sh-empty__text">Browse our catalogue and add the products you like — they will appear here.</p>
      <a class="sh-btn sh-btn--lg" href="<?= e(sh_url('products.php')) ?>">Start shopping</a>
    </div>
  <?php else: ?>
    <div class="sh-cartlayout">
      <div>
        <div class="sh-section" style="margin-bottom:12px">
          <div class="sh-section__head">
            <h1 class="sh-section__title"><?= sh_icon('shopping-cart', 19) ?> Shopping Cart (<?= (int)$summary['count'] ?>)</h1>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" style="margin-left:auto" type="button" data-cart-clear>
              <?= sh_icon('trash', 14) ?> Clear cart
            </button>
          </div>

          <?php foreach ($summary['items'] as $it): ?>
            <div class="sh-cart-item" data-cart-row="<?= (int)$it['product_id'] ?>">
              <a href="<?= e(sh_url('product.php?slug=' . urlencode($it['slug']))) ?>">
                <img class="sh-cart-item__img" src="<?= e(sh_product_image($it['image'])) ?>" alt="<?= e($it['name']) ?>" loading="lazy">
              </a>
              <div>
                <a href="<?= e(sh_url('product.php?slug=' . urlencode($it['slug']))) ?>">
                  <p class="sh-cart-item__name"><?= e($it['name']) ?></p>
                </a>
                <?php if ($it['product_type'] === 'digital'): ?>
                  <span class="sh-cart-item__tag">Digital delivery</span>
                <?php endif; ?>
                <p class="sh-cart-item__price"><?= e(sh_money($it['unit_price'])) ?>
                  <?php if ($it['compare_price'] > $it['unit_price']): ?>
                    <span style="font-size:12px;color:var(--sh-muted);text-decoration:line-through;font-weight:400">
                      <?= e(sh_money($it['compare_price'])) ?></span>
                  <?php endif; ?>
                </p>
                <div class="sh-qty" style="margin-top:8px">
                  <button type="button" data-cart-update="-1" aria-label="Decrease quantity"><?= sh_icon('minus', 15) ?></button>
                  <input type="number" data-qty-input value="<?= (int)$it['quantity'] ?>" min="1" max="<?= min(20, (int)$it['stock']) ?>" aria-label="Quantity">
                  <button type="button" data-cart-update="1" aria-label="Increase quantity"><?= sh_icon('plus', 15) ?></button>
                </div>
              </div>
              <div class="sh-cart-item__side">
                <span class="sh-cart-item__total"><?= e(sh_money($it['line_total'])) ?></span>
                <button class="sh-cart-item__remove" type="button" data-cart-remove="<?= (int)$it['product_id'] ?>">
                  <?= sh_icon('trash', 14) ?> Remove
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('products.php')) ?>">
          <?= sh_icon('chevron-left', 15) ?> Continue shopping
        </a>
      </div>

      <aside class="sh-summary">
        <h2 class="sh-summary__title">Order Summary</h2>

        <form method="post" class="sh-coupon" data-no-lock>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="coupon">
          <input type="text" name="coupon_code" placeholder="Coupon code" maxlength="50"
                 value="<?= e($summary['coupon']['code'] ?? '') ?>" aria-label="Coupon code">
          <button class="sh-btn sh-btn--sm" type="submit">Apply</button>
        </form>
        <?php if ($summary['coupon']): ?>
          <form method="post" style="margin:-6px 0 10px">
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="coupon_remove">
            <button type="submit" style="font-size:12px;color:var(--sh-bad)">Remove coupon <?= e($summary['coupon']['code']) ?></button>
          </form>
        <?php endif; ?>

        <div class="sh-summary__row"><span>Subtotal (<?= (int)$summary['count'] ?> items)</span><span><?= e(sh_money($summary['subtotal'])) ?></span></div>
        <?php if ($summary['savings'] > 0): ?>
          <div class="sh-summary__row sh-summary__row--discount"><span>Product savings</span><span>-<?= e(sh_money($summary['savings'])) ?></span></div>
        <?php endif; ?>
        <?php if ($summary['discount'] > 0): ?>
          <div class="sh-summary__row sh-summary__row--discount"><span>Coupon discount</span><span>-<?= e(sh_money($summary['discount'])) ?></span></div>
        <?php endif; ?>
        <div class="sh-summary__row"><span>Delivery (inside city)</span>
          <span><?= $summary['delivery'] > 0 ? e(sh_money($summary['delivery'])) : 'Free' ?></span></div>
        <div class="sh-summary__total"><span>Total</span><span><?= e(sh_money($summary['total'])) ?></span></div>

        <a class="sh-btn sh-btn--lg sh-btn--block" style="margin-top:14px"
           href="<?= e(sh_user() !== null ? sh_url('checkout.php') : sh_url('login.php?redirect=' . urlencode('checkout.php'))) ?>">
          Proceed to Checkout <?= sh_icon('chevron-right', 16) ?>
        </a>
        <p style="font-size:12px;color:var(--sh-muted);margin-top:10px;text-align:center">
          Delivery is recalculated at checkout based on your address.
        </p>
      </aside>
    </div>
  <?php endif; ?>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
