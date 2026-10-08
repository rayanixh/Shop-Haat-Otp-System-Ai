<?php
/**
 * Homepage — product-dense marketplace layout.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/payment.php';

$pageTitle = '';
$pageCanonical = rtrim(sh_site_url(), '/') . '/';
$pageDescription = (string)sh_setting('site_description', '');

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

require_once SH_ROOT . '/includes/header.php';
?>

<div class="sh-wrap">

  <!-- Marketplace promotional area -->
  <section class="sh-promo" aria-label="Promotions">
    <nav class="sh-promo__panel" aria-label="Category shortcuts">
      <?php foreach (array_slice($cats, 0, 10) as $c): ?>
        <a href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>">
          <?= sh_icon($c['icon'] ?: 'grid', 16) ?><span><?= e($c['name']) ?></span><?= sh_icon('chevron-right', 14) ?>
        </a>
      <?php endforeach; ?>
    </nav>

<?php $shSlides = sh_promo_items('slider'); ?>
    <?php if ($shSlides): ?>
    <div class="sh-carousel" data-carousel>
      <?php foreach ($shSlides as $i => $sl): $img = sh_promo_image($sl['image']); ?>
        <div class="sh-slide <?= $i === 0 ? 'sh-slide--on' : '' ?>">
          <?php if ($img !== ''): ?>
            <img class="sh-slide__img" src="<?= e($img) ?>" alt="<?= e($sl['title']) ?>"
                 <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
          <?php endif; ?>
          <span class="sh-slide__scrim" aria-hidden="true"
                style="opacity:<?= e(number_format(max(0, min(100, (int)$sl['overlay'])) / 100, 2)) ?>"></span>
          <div class="sh-slide__inner">
            <?php if (trim((string)$sl['tag']) !== ''): ?>
              <span class="sh-slide__tag"><?= e($sl['tag']) ?></span>
            <?php endif; ?>
            <h2 class="sh-slide__title"><?= e($sl['title']) ?></h2>
            <?php if (trim((string)$sl['subtitle']) !== ''): ?>
              <p class="sh-slide__text"><?= e($sl['subtitle']) ?></p>
            <?php endif; ?>
            <?php if (trim((string)$sl['button_text']) !== '' && trim((string)$sl['button_url']) !== ''): ?>
              <a class="sh-slide__btn" href="<?= e(sh_url($sl['button_url'])) ?>"><?= e($sl['button_text']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (count($shSlides) > 1): ?>
        <button class="sh-carousel__arrow sh-carousel__arrow--prev" type="button" data-carousel-prev aria-label="Previous slide"><?= sh_icon('chevron-left', 18) ?></button>
        <button class="sh-carousel__arrow sh-carousel__arrow--next" type="button" data-carousel-next aria-label="Next slide"><?= sh_icon('chevron-right', 18) ?></button>
        <div class="sh-carousel__dots">
          <?php foreach ($shSlides as $i => $sl): ?>
            <button class="sh-carousel__dot <?= $i === 0 ? 'sh-carousel__dot--on' : '' ?>" type="button"
                    aria-label="Slide <?= (int)($i + 1) ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

<?php $shCards = sh_promo_items('card'); ?>
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

  <!-- Popular categories -->
  <?php if ($cats): ?>
  <section class="sh-section" aria-labelledby="sh-cats-h">
    <div class="sh-section__head">
      <h2 class="sh-section__title" id="sh-cats-h"><?= sh_icon('grid', 19) ?> Popular Categories</h2>
      <a class="sh-section__more" href="<?= e(sh_url('products.php')) ?>">All categories <?= sh_icon('chevron-right', 14) ?></a>
    </div>
    <div class="sh-catgrid">
      <?php foreach (array_slice($cats, 0, 8) as $c): ?>
        <a class="sh-category-card" href="<?= e(sh_url('category.php?slug=' . urlencode($c['slug']))) ?>">
          <?php $catImg = sh_category_image($c['image'] ?? null); ?>
          <span class="sh-category-card__icon<?= $catImg !== '' ? ' sh-category-card__icon--photo' : '' ?>">
            <?php if ($catImg !== ''): ?>
              <img src="<?= e($catImg) ?>" alt="<?= e($c['name']) ?>" loading="lazy" decoding="async">
            <?php else: ?>
              <?= sh_icon('image', 20) ?>
            <?php endif; ?>
          </span>
          <span class="sh-category-card__name"><?= e($c['name']) ?></span>
          <span class="sh-category-card__count"><?= (int)$c['product_count'] ?> items</span>
        </a>
      <?php endforeach; ?>
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

  <!-- Promotional banner strip -->
  <section class="sh-banner" aria-label="Offers">
    <div class="sh-banner__item" style="background:linear-gradient(135deg,#1d4ed8,#38bdf8)">
      <p class="sh-banner__title">Free delivery over <?= e(sh_money(sh_setting('free_delivery_over', '3000'))) ?></p>
      <p class="sh-banner__text">On eligible orders inside the city</p>
      <a class="sh-banner__link" href="<?= e(sh_url('products.php')) ?>">Shop now <?= sh_icon('chevron-right', 13) ?></a>
    </div>
    <div class="sh-banner__item" style="background:linear-gradient(135deg,#b45309,#fbbf24)">
      <p class="sh-banner__title">Save with coupon WELCOME10</p>
      <p class="sh-banner__text">10% off your first qualifying order</p>
      <a class="sh-banner__link" href="<?= e(sh_url('products.php?discount=10')) ?>">Grab the deal <?= sh_icon('chevron-right', 13) ?></a>
    </div>
    <div class="sh-banner__item" style="background:linear-gradient(135deg,#0f766e,#34d399)">
      <p class="sh-banner__title">Instant digital delivery</p>
      <p class="sh-banner__text">Codes issued right after payment approval</p>
      <a class="sh-banner__link" href="<?= e(sh_url('products.php?q=' . urlencode('code'))) ?>">See products <?= sh_icon('chevron-right', 13) ?></a>
    </div>
  </section>

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

  <!-- Trust / service -->
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

</div>

<?php require_once SH_ROOT . '/includes/footer.php'; ?>
