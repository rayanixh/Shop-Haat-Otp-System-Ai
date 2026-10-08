<?php
if (!defined('SH_BOOTSTRAPPED')) { require_once dirname(__DIR__) . '/config/config.php'; }
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/payment.php';
$fCats = $shCategories ?? sh_categories();
?>
</main>

<footer class="sh-footer">
  <div class="sh-wrap">
    <div class="sh-footer__grid">
      <div class="sh-footer__col sh-footer__col--about">
        <div class="sh-brand sh-brand--footer">
          <span class="sh-brand__mark">SH</span>
          <span class="sh-brand__text"><?= e(sh_setting('site_name', 'ShopHaat')) ?></span>
        </div>
        <p class="sh-footer__about"><?= e(sh_setting('footer_about', '')) ?></p>
        <ul class="sh-footer__contact">
          <li><?= sh_icon('phone', 15) ?><span><?= e(sh_setting('contact_phone', '')) ?></span></li>
          <li><?= sh_icon('mail', 15) ?><span><?= e(sh_setting('contact_email', '')) ?></span></li>
          <li><?= sh_icon('map-pin', 15) ?><span><?= e(sh_setting('contact_address', '')) ?></span></li>
        </ul>
      </div>
      <div class="sh-footer__col">
        <h3 class="sh-footer__title">Shop</h3>
        <ul class="sh-footer__links">
          <?php foreach (array_slice($fCats, 0, 6) as $c): ?>
            <li><a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>"><?= e($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="sh-footer__col">
        <h3 class="sh-footer__title">Customer Service</h3>
        <ul class="sh-footer__links">
          <li><a href="<?= e(sh_url('help.php')) ?>">Help Centre</a></li>
          <li><a href="<?= e(sh_url('track.php')) ?>">Track Your Order</a></li>
          <li><a href="<?= e(sh_url('support.php')) ?>">Contact Support</a></li>
          <li><a href="<?= e(sh_url('page.php?p=returns')) ?>">Returns &amp; Refunds</a></li>
          <li><a href="<?= e(sh_url('page.php?p=shipping')) ?>">Shipping Information</a></li>
        </ul>
      </div>
      <div class="sh-footer__col">
        <h3 class="sh-footer__title">My Account</h3>
        <ul class="sh-footer__links">
          <li><a href="<?= e(sh_url('account.php')) ?>">My Profile</a></li>
          <li><a href="<?= e(sh_url('orders.php')) ?>">My Orders</a></li>
          <li><a href="<?= e(sh_url('wishlist.php')) ?>">Wishlist</a></li>
          <li><a href="<?= e(sh_url('cart.php')) ?>">Shopping Cart</a></li>
          <li><a href="<?= e(sh_url('register.php')) ?>">Create Account</a></li>
        </ul>
      </div>
      <div class="sh-footer__col">
        <h3 class="sh-footer__title">Information</h3>
        <ul class="sh-footer__links">
          <li><a href="<?= e(sh_url('page.php?p=about')) ?>">About Us</a></li>
          <li><a href="<?= e(sh_url('page.php?p=terms')) ?>">Terms &amp; Conditions</a></li>
          <li><a href="<?= e(sh_url('page.php?p=privacy')) ?>">Privacy Policy</a></li>
        </ul>
      </div>
    </div>

    <div class="sh-footer__pay">
      <span class="sh-footer__paylabel">We accept</span>
      <div class="sh-footer__paylist">
        <?php foreach (sh_payment_methods_available(true) as $pm):
            $lg = sh_payment_logo_url($pm); ?>
          <span class="sh-paychip">
            <?php if ($lg !== ''): ?><img src="<?= e($lg) ?>" alt="<?= e($pm['name']) ?>" height="18">
            <?php else: ?><?= sh_icon($pm['type'] === 'cod' ? 'truck' : 'credit-card', 15) ?><?php endif; ?>
            <?= e($pm['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="sh-footer__bar">
      <p><?= e(sh_setting('footer_copyright', '')) ?></p>
      <p class="sh-footer__built">Secure checkout <?= sh_icon('lock', 13) ?></p>
    </div>
  </div>
</footer>

<!-- Mobile bottom navigation -->
<nav class="sh-bottomnav" aria-label="Mobile navigation">
  <a href="<?= e(sh_url('index.php')) ?>"><?= sh_icon('home', 20) ?><span>Home</span></a>
  <a href="<?= e(sh_url('products.php')) ?>"><?= sh_icon('grid', 20) ?><span>Categories</span></a>
  <a class="sh-bottomnav__cart" href="<?= e(sh_url('cart.php')) ?>">
    <?= sh_icon('shopping-cart', 20) ?><span>Cart</span>
    <em class="sh-badge-count" data-cart-count<?= ($shCartCount ?? 0) > 0 ? '' : ' hidden' ?>><?= (int)($shCartCount ?? 0) ?></em>
  </a>
  <a href="<?= e(sh_url('orders.php')) ?>"><?= sh_icon('package', 20) ?><span>Orders</span></a>
  <a href="<?= e(sh_url('account.php')) ?>"><?= sh_icon('user', 20) ?><span>Account</span></a>
</nav>

<div class="sh-toasts" id="sh-toasts" aria-live="polite" aria-atomic="true"></div>
<script src="<?= e(sh_asset('assets/js/app.js')) ?>" defer></script>
</body>
</html>
