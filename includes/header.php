<?php
/**
 * Public site header: utility bar, main header with search, category nav,
 * plus the dedicated mobile header and bottom navigation.
 *
 * Pages may set $pageTitle, $pageDescription, $pageCanonical, $bodyClass,
 * $structuredData before including this file.
 */
if (!defined('SH_BOOTSTRAPPED')) {
    require_once dirname(__DIR__) . '/config/config.php';
}
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/cart.php';
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/transition.php';

sh_session_start();

$shSiteName   = (string)sh_setting('site_name', 'ShopHaat');
$shTitle      = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' . $shSiteName : $shSiteName . ' — ' . sh_setting('site_tagline', 'Online Marketplace');
$shDesc       = isset($pageDescription) && $pageDescription !== '' ? $pageDescription : (string)sh_setting('site_description', '');
$shCanonical  = $pageCanonical ?? '';
$shCategories = sh_categories();
$shCartCount  = sh_cart_count();
$shUser       = sh_user();
// Keep the Account control useful for guests: it enters Login with a validated
// return target, while signed-in customers continue straight to their profile.
$shAccountHref = sh_url($shUser ? 'account.php' : sh_login_url('account.php'));
$shLogo       = sh_logo_image((string)sh_setting('site_logo', ''));
$shFavicon    = sh_logo_image((string)sh_setting('site_favicon', ''));
$shFlash      = sh_flash_pull();
$shSearchQ    = sh_get('q');
$shTransition = sh_transition_config();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($shTitle) ?></title>
<meta name="description" content="<?= e(sh_excerpt($shDesc, 158)) ?>">
<?php if ($shCanonical !== ''): ?><link rel="canonical" href="<?= e($shCanonical) ?>"><?php endif; ?>
<meta property="og:title" content="<?= e($shTitle) ?>">
<meta property="og:description" content="<?= e(sh_excerpt($shDesc, 158)) ?>">
<meta property="og:type" content="website">
<meta name="theme-color" content="<?= e(sh_theme_colors()['primary']) ?>">
<?php if ($shFavicon !== ''): ?>
<link rel="icon" href="<?= e($shFavicon) ?>">
<?php else: ?>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#e8501b"/><text x="16" y="22" font-family="Arial" font-size="15" font-weight="bold" fill="#fff" text-anchor="middle">S</text></svg>') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(sh_asset('assets/css/app.css')) ?>">
<?= sh_theme_style_tag() ?>
<script>window.SH_BASE = <?= json_encode(sh_base_url() . '/') ?>; window.SH_CSRF = <?= json_encode(sh_csrf_token()) ?>;</script>
<?php if (!empty($structuredData)): ?>
<script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?= sh_transition_head($shTransition) ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>"<?= sh_transition_body_attrs($shTransition) ?>>
<?= sh_transition_overlay($shTransition) ?>
<a class="sh-skip" href="#sh-main">Skip to content</a>

<header class="sh-header">
  <!-- Utility bar (desktop) -->
  <div class="sh-utility">
    <div class="sh-wrap sh-utility__inner">
      <p class="sh-utility__note"><?= e(sh_setting('site_tagline', 'Genuine products, delivered across Bangladesh')) ?></p>
      <nav class="sh-utility__links" aria-label="Utility">
        <a href="<?= e(sh_url('help.php')) ?>"><?= sh_icon('help', 14) ?> Help</a>
        <a href="<?= e(sh_url('track.php')) ?>"><?= sh_icon('package', 14) ?> Track Order</a>
        <a href="<?= e(sh_url('support.php')) ?>"><?= sh_icon('headphones', 14) ?> Customer Support</a>
        <?php if ($shUser): ?>
          <a href="<?= e(sh_url('account.php')) ?>"><?= sh_icon('user', 14) ?> Hi, <?= e(explode(' ', $shUser['name'])[0]) ?></a>
          <a href="<?= e(sh_url('logout.php')) ?>"><?= sh_icon('log-out', 14) ?> Sign out</a>
        <?php else: ?>
          <a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('log-in', 14) ?> Login</a>
          <a href="<?= e(sh_url('register.php')) ?>"><?= sh_icon('user-plus', 14) ?> Sign Up</a>
        <?php endif; ?>
      </nav>
    </div>
  </div>

  <!-- Main header (desktop) -->
  <div class="sh-main-header">
    <div class="sh-wrap sh-main-header__inner">
      <a class="sh-brand" href="<?= e(sh_url('index.php')) ?>">
        <?php if ($shLogo !== ''): ?>
          <img class="sh-brand__img" src="<?= e($shLogo) ?>" alt="<?= e($shSiteName) ?>" width="150" height="40">
        <?php else: ?>
          <span class="sh-brand__mark">SH</span>
          <span class="sh-brand__text"><?= e($shSiteName) ?></span>
        <?php endif; ?>
      </a>

      <div class="sh-search" id="sh-search">
        <form class="sh-search__form" action="<?= e(sh_url('products.php')) ?>" method="get" role="search" autocomplete="off">
          <input class="sh-search__input" type="search" name="q" id="sh-search-input"
                 value="<?= e($shSearchQ) ?>" placeholder="Search for products, brands and categories"
                 aria-label="Search products">
          <button class="sh-search__btn" type="submit" aria-label="Search"><?= sh_icon('search', 18) ?><span>Search</span></button>
        </form>
        <div class="sh-suggest" id="sh-suggest" hidden role="listbox" aria-label="Search suggestions"></div>
      </div>

      <div class="sh-actions">
        <a class="sh-action" href="<?= e($shAccountHref) ?>">
          <?= sh_icon('user', 21) ?><span class="sh-action__label"><?= $shUser ? 'Account' : 'Login' ?></span>
        </a>
        <?php if (!$shUser): ?>
          <a class="sh-action" href="<?= e(sh_url('register.php')) ?>">
            <?= sh_icon('user-plus', 21) ?><span class="sh-action__label">Sign Up</span>
          </a>
        <?php endif; ?>
        <a class="sh-action" href="<?= e(sh_url('orders.php')) ?>">
          <?= sh_icon('package', 21) ?><span class="sh-action__label">Orders</span>
        </a>
        <a class="sh-action" href="<?= e(sh_url('wishlist.php')) ?>">
          <?= sh_icon('heart', 21) ?><span class="sh-action__label">Wishlist</span>
        </a>
        <a class="sh-action sh-action--cart" href="<?= e(sh_url('cart.php')) ?>">
          <?= sh_icon('shopping-cart', 21) ?>
          <span class="sh-action__label">Cart</span>
          <span class="sh-badge-count" data-cart-count<?= $shCartCount > 0 ? '' : ' hidden' ?>><?= (int)$shCartCount ?></span>
        </a>
      </div>
    </div>
  </div>

  <!-- Mobile header -->
  <div class="sh-mheader">
    <div class="sh-mheader__row">
      <button class="sh-mheader__btn" type="button" data-drawer-open aria-label="Open menu" aria-expanded="false"><?= sh_icon('menu', 22) ?></button>
      <a class="sh-mheader__brand" href="<?= e(sh_url('index.php')) ?>">
        <?php if ($shLogo !== ''): ?>
          <img src="<?= e($shLogo) ?>" alt="<?= e($shSiteName) ?>" height="30">
        <?php else: ?>
          <span class="sh-brand__mark sh-brand__mark--sm">SH</span><span><?= e($shSiteName) ?></span>
        <?php endif; ?>
      </a>
      <a class="sh-mheader__btn" href="<?= e($shAccountHref) ?>" aria-label="Account"><?= sh_icon('user', 21) ?></a>
      <a class="sh-mheader__btn sh-mheader__btn--cart" href="<?= e(sh_url('cart.php')) ?>" aria-label="Cart">
        <?= sh_icon('shopping-cart', 21) ?>
        <span class="sh-badge-count" data-cart-count<?= $shCartCount > 0 ? '' : ' hidden' ?>><?= (int)$shCartCount ?></span>
      </a>
    </div>
    <form class="sh-mheader__search" action="<?= e(sh_url('products.php')) ?>" method="get" role="search">
      <?= sh_icon('search', 17) ?>
      <input type="search" name="q" value="<?= e($shSearchQ) ?>" placeholder="Search products" aria-label="Search products">
    </form>
  </div>

  <!-- Category / primary navigation -->
  <nav class="sh-nav" aria-label="Primary">
    <div class="sh-wrap sh-nav__inner">
      <div class="sh-nav__cats">
        <button class="sh-nav__catbtn" type="button" data-catmenu-toggle aria-expanded="false" aria-controls="sh-catmenu">
          <?= sh_icon('menu', 17) ?> Categories <?= sh_icon('chevron-down', 15) ?>
        </button>
        <ul class="sh-catmenu" id="sh-catmenu" hidden>
          <?php foreach ($shCategories as $c): ?>
            <li><a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>">
              <?= sh_icon($c['icon'] ?: 'grid', 16) ?><span><?= e($c['name']) ?></span>
              <em><?= (int)$c['product_count'] ?></em>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <ul class="sh-nav__links">
        <li><a href="<?= e(sh_url('index.php')) ?>">Home</a></li>
        <li><a href="<?= e(sh_url('products.php?flash=1')) ?>" class="sh-nav__hot"><?= sh_icon('zap', 15) ?> Flash Sale</a></li>
        <li><a href="<?= e(sh_url('products.php?sort=popular')) ?>">Best Sellers</a></li>
        <li><a href="<?= e(sh_url('products.php?sort=newest')) ?>">New Arrivals</a></li>
        <li><a href="<?= e(sh_url('products.php?discount=20')) ?>">Offers</a></li>
        <?php foreach (array_slice($shCategories, 0, 4) as $c): ?>
          <li class="sh-nav__extra"><a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>"><?= e($c['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
</header>

<!-- Mobile drawer -->
<div class="sh-drawer" id="sh-drawer" hidden>
  <div class="sh-drawer__panel" role="dialog" aria-label="Menu" aria-modal="true">
    <div class="sh-drawer__head">
      <span><?= e($shSiteName) ?></span>
      <button type="button" data-drawer-close aria-label="Close menu"><?= sh_icon('x', 20) ?></button>
    </div>
    <div class="sh-drawer__body">
      <p class="sh-drawer__title">Shop by category</p>
      <ul class="sh-drawer__list">
        <?php foreach ($shCategories as $c): ?>
          <li><a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>">
            <?= sh_icon($c['icon'] ?: 'grid', 17) ?><span><?= e($c['name']) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <p class="sh-drawer__title">Quick links</p>
      <ul class="sh-drawer__list">
        <li><a href="<?= e(sh_url('products.php?flash=1')) ?>"><?= sh_icon('zap', 17) ?><span>Flash Sale</span></a></li>
        <li><a href="<?= e(sh_url('products.php?sort=newest')) ?>"><?= sh_icon('trending-up', 17) ?><span>New Arrivals</span></a></li>
        <li><a href="<?= e(sh_url('orders.php')) ?>"><?= sh_icon('package', 17) ?><span>My Orders</span></a></li>
        <li><a href="<?= e(sh_url('wishlist.php')) ?>"><?= sh_icon('heart', 17) ?><span>Wishlist</span></a></li>
        <li><a href="<?= e(sh_url('track.php')) ?>"><?= sh_icon('map-pin', 17) ?><span>Track Order</span></a></li>
        <li><a href="<?= e(sh_url('support.php')) ?>"><?= sh_icon('headphones', 17) ?><span>Customer Support</span></a></li>
        <?php if ($shUser): ?>
          <li><a href="<?= e(sh_url('account.php')) ?>"><?= sh_icon('user', 17) ?><span>My Account</span></a></li>
          <li><a href="<?= e(sh_url('logout.php')) ?>"><?= sh_icon('log-out', 17) ?><span>Sign out</span></a></li>
        <?php else: ?>
          <li><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('log-in', 17) ?><span>Login</span></a></li>
          <li><a href="<?= e(sh_url('register.php')) ?>"><?= sh_icon('user-plus', 17) ?><span>Sign Up</span></a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <button class="sh-drawer__scrim" type="button" data-drawer-close aria-label="Close menu"></button>
</div>

<?php if ($shFlash): ?>
<div class="sh-wrap sh-flashmsgs">
  <?php foreach ($shFlash as $f): ?>
    <div class="sh-alert sh-alert--<?= e($f['type']) ?>">
      <?= sh_icon($f['type'] === 'success' ? 'check-circle' : ($f['type'] === 'error' ? 'x-circle' : 'info'), 17) ?>
      <span><?= e($f['message']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<main id="sh-main" class="sh-main">
