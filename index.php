<?php
/**
 * Homepage — product-dense marketplace layout.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/payment.php';

/*
 * SEO. The title is left empty on purpose: the SEO engine then builds the
 * homepage title from the store name and tagline, and the owner can override it
 * from the SEO Manager without touching this file.
 */
$pageSeoKey      = 'home';
$pageTitle       = '';
$pageCanonical   = '/';
$pageDescription = (string)sh_setting('site_description', '');
$pageBreadcrumbs = [['name' => (string)sh_setting('site_name', 'ShopHaat'), 'url' => '']];

$cats        = sh_categories();
$flash       = sh_products_flash(12);
$flashEnds   = sh_flash_ends_at();
$featured    = sh_products_featured(12);
$best        = sh_products_best_sellers(12);
$newest      = sh_products_new(12);
$shWishlist  = sh_wishlist_ids();

// Category showcase rows (only categories that actually have products)
$showcase = [];
foreach ($cats as $c) {
    if ((int)$c['product_count'] < 2) { continue; }
    $items = sh_products_by_category((int)$c['id'], 6);
    if (count($items) >= 2) { $showcase[] = ['cat' => $c, 'items' => $items]; }
    if (count($showcase) >= 3) { break; }
}
$more = sh_product_search(['sort' => 'popular', 'per_page' => 18, 'page' => 1]);
$shSlides = sh_promo_items('slider');
$shCards  = sh_promo_items('card');

// The coupon strip is only real if that coupon exists on this store. A fresh
// install has no coupons, so it must not advertise one that would be rejected
// at checkout.
$shIntroCoupon = null;
try {
    $shIntroCoupon = sh_one(
        "SELECT code, type, value FROM coupons
         WHERE code = 'WELCOME10' AND status = 1
           AND (expires_at IS NULL OR expires_at > NOW())
           AND (usage_limit = 0 OR used_count < usage_limit) LIMIT 1"
    );
} catch (Throwable $e) {
    $shIntroCoupon = null;
}
if ($shIntroCoupon !== null) {
    // Only advertise the offer on products, where a coupon box exists; with an
    // empty catalogue the link would lead to a page that cannot use it.
    $shIntroCoupon = (bool)$more['items'] ? $shIntroCoupon : null;
}

// True only when the owner has published something. An empty store then shows a
// short, calm empty state instead of a page of empty sections.
$shHasContent = (bool)($cats || $flash || $featured || $best || $newest || $more['items'] || $shSlides || $shCards);

require_once SH_ROOT . '/includes/header.php';
?>

<div class="sh-wrap">

  <!-- Marketplace promotional area -->
  <?php if ($cats || $shSlides || $shCards): ?>
  <section class="sh-promo" aria-label="Promotions">
    <nav class="sh-promo__panel" aria-label="Category shortcuts">
      <?php foreach (array_slice($cats, 0, 10) as $c): ?>
        <a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>">
          <?= sh_icon($c['icon'] ?: 'grid', 16) ?><span><?= e($c['name']) ?></span><?= sh_icon('chevron-right', 14) ?>
        </a>
      <?php endforeach; ?>
    </nav>

<?php
    // The hero slider markup (and its no-CTA, stacked-crossfade rules) lives in
    // includes/functions.php so the storefront and the behaviour harness render
    // exactly the same string.
    echo sh_slider_markup($shSlides);
?>

    <?php if ($shCards): ?>
    <div class="sh-promo__side">
      <?php foreach ($shCards as $c): $img = sh_promo_image($c['image']); ?>
        <div class="sh-sidecard">
          <?php if ($img !== ''): ?>
            <img class="sh-sidecard__img" src="<?= e($img) ?>" alt="<?= e($c['title']) ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <span class="sh-sidecard__scrim" aria-hidden="true"
                style="opacity:<?= e(number_format(max(0, min(100, (int)$c['overlay'])) / 100, 2)) ?>"></span>
          <p class="sh-sidecard__title"><?= e($c['title']) ?></p>
          <?php if (trim((string)$c['subtitle']) !== ''): ?>
            <p class="sh-sidecard__text"><?= e($c['subtitle']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)$c['button_text']) !== '' && trim((string)$c['button_url']) !== ''): ?>
            <a class="sh-sidecard__link" href="<?= e(sh_url($c['button_url'])) ?>"><?= e($c['button_text']) ?> <?= sh_icon('chevron-right', 13) ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Popular categories -->
  <?php if ($cats): ?>
  <section class="sh-section" aria-labelledby="sh-cats-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-cats-h"><?= sh_icon('grid', 19) ?> Popular Categories</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php')) ?>">All categories <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-catgrid">
      <?= sh_category_cards(array_slice($cats, 0, 8)) ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Flash sale -->
  <?php if ($flash): ?>
  <section class="sh-section sh-section--flash" aria-labelledby="sh-flash-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-flash-h"><?= sh_icon('zap', 19) ?> Flash Sale</h2>
      <?php if ($flashEnds): ?>
        <div class="sh-countdown" data-countdown="<?= e($flashEnds) ?>">
          <span>Ends in</span>
          <span class="sh-countdown__unit" data-cd-d>00</span>d
          <span class="sh-countdown__unit" data-cd-h>00</span>h
          <span class="sh-countdown__unit" data-cd-m>00</span>m
          <span class="sh-countdown__unit" data-cd-s>00</span>s
        </div>
      <?php endif; ?>
      <a class="sh-section__more" href="<?= e(sh_url('products.php?flash=1')) ?>">View all <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach (array_slice($flash, 0, 6) as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Featured -->
  <?php if ($featured): ?>
  <section class="sh-section" aria-labelledby="sh-feat-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-feat-h"><?= sh_icon('star', 19) ?> Featured Products</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php?featured=1')) ?>">View all <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach (array_slice($featured, 0, 12) as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Best sellers -->
  <?php if ($best): ?>
  <section class="sh-section" aria-labelledby="sh-best-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-best-h"><?= sh_icon('trending-up', 19) ?> Best Sellers</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php?sort=popular')) ?>">View all <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach (array_slice($best, 0, 6) as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Category showcases -->
  <?php foreach ($showcase as $row): ?>
  <section class="sh-section">
    <div class="sh-section__head">
      <h2 class="sh-section__title"><?= sh_icon($row['cat']['icon'] ?: 'grid', 19) ?> <?= e($row['cat']['name']) ?></h2>
      <a class="sh-section__more" href="<?= e(sh_url('category.php?slug=' . urlencode($row['cat']['slug']))) ?>">
        Shop <?= e($row['cat']['name']) ?> <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach ($row['items'] as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endforeach; ?>

  <!-- Promotional banner strip. An empty store has nothing to advertise. -->
  <?php if ($shHasContent): ?>
  <section class="sh-banner<?= $shIntroCoupon === null ? ' sh-banner--single' : '' ?>" aria-label="Offers">
    <div class="sh-banner__item" style="background:linear-gradient(135deg,#1d4ed8,#38bdf8)">
      <p class="sh-banner__title">Free delivery over <?= e(sh_money(sh_setting('free_delivery_over', '3000'))) ?></p>
      <p class="sh-banner__text">On eligible orders inside the city</p>
      <a class="sh-banner__link" href="<?= e(sh_url('products.php')) ?>">Shop now <?= sh_icon('chevron-right', 13) ?></a>
    </div>
    <?php if ($shIntroCoupon !== null): ?>
    <div class="sh-banner__item" style="background:linear-gradient(135deg,#b45309,#fbbf24)">
      <p class="sh-banner__title">Save with coupon <?= e($shIntroCoupon['code']) ?></p>
      <p class="sh-banner__text"><?= $shIntroCoupon['type'] === 'percent'
            ? e(rtrim(rtrim(number_format((float)$shIntroCoupon['value'], 2, '.', ''), '0'), '.')) . '% off'
            : e(sh_money((float)$shIntroCoupon['value'])) . ' off' ?> your order</p>
      <a class="sh-banner__link" href="<?= e(sh_url('products.php')) ?>">Shop now <?= sh_icon('chevron-right', 13) ?></a>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- New arrivals -->
  <?php if ($newest): ?>
  <section class="sh-section" aria-labelledby="sh-new-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-new-h"><?= sh_icon('box', 19) ?> New Arrivals</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php?sort=newest')) ?>">View all <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach (array_slice($newest, 0, 6) as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- More products -->
  <?php if ($more['items']): ?>
  <section class="sh-section" aria-labelledby="sh-more-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-more-h"><?= sh_icon('layout', 19) ?> More Products For You</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php')) ?>">Browse everything <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-product-grid">
      <?php foreach ($more['items'] as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!$shHasContent): ?>
  <!-- Genuinely empty store: a short, factual state instead of empty sections. -->
  <section class="sh-section" aria-labelledby="sh-empty-h">
    <div class="sh-empty">
      <span class="sh-empty__icon"><?= sh_icon('box', 26) ?></span>
      <h2 class="sh-empty__title" id="sh-empty-h">No products yet</h2>
      <p class="sh-empty__text">New items will appear here soon.</p>
    </div>
  </section>
  <?php endif; ?>

  <!-- Trust / service. These are the store's own promises; with no catalogue
       yet there is nothing to promise them for. -->
  <?php if ($shHasContent): ?>
  <section class="sh-trust" aria-label="Our services">
    <div class="sh-trust__item">
      <span class="sh-trust__icon"><?= sh_icon('truck', 20) ?></span>
      <div><p class="sh-trust__title">Nationwide delivery</p><p class="sh-trust__text">1–3 days inside Dhaka</p></div>
    </div>
    <div class="sh-trust__item">
      <span class="sh-trust__icon"><?= sh_icon('shield', 20) ?></span>
      <div><p class="sh-trust__title">Genuine products</p><p class="sh-trust__text">Verified suppliers only</p></div>
    </div>
    <div class="sh-trust__item">
      <span class="sh-trust__icon"><?= sh_icon('rotate', 20) ?></span>
      <div><p class="sh-trust__title">7-day replacement</p><p class="sh-trust__text">On eligible items</p></div>
    </div>
    <div class="sh-trust__item">
      <span class="sh-trust__icon"><?= sh_icon('headphones', 20) ?></span>
      <div><p class="sh-trust__title">Support every day</p><p class="sh-trust__text">Chat, phone and email</p></div>
    </div>
  </section>
  <?php endif; ?>

</div>

<?php require_once SH_ROOT . '/includes/footer.php'; ?>
