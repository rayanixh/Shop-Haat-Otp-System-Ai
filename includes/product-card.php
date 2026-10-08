<?php
/**
 * Compact marketplace product card.
 * Expects $p (product row). Optional $shWishlist array of product ids.
 */
$p = $p ?? null;
if (!is_array($p)) { return; }
$wishIds = $shWishlist ?? [];
$off = sh_discount_percent($p['price'], $p['compare_price']);
$stock = (int)$p['stock'];
$inWish = in_array((int)$p['id'], $wishIds, true);
$link = sh_url('product.php?slug=' . urlencode($p['slug']));
$sold = (int)($p['sold_count'] ?? 0);
?>
<article class="sh-product-card">
  <a class="sh-product-card__media" href="<?= e($link) ?>" tabindex="-1" aria-hidden="true">
    <img class="sh-product-card__img" src="<?= e(sh_product_image($p['image'])) ?>"
         alt="<?= e($p['name']) ?>" loading="lazy" width="220" height="220">
    <?php if ($off > 0): ?><span class="sh-product-card__badge">-<?= $off ?>%</span><?php endif; ?>
  </a>
  <button class="sh-product-card__wish" type="button" data-wishlist="<?= (int)$p['id'] ?>"
          aria-pressed="<?= $inWish ? 'true' : 'false' ?>" aria-label="Add to wishlist">
    <?= sh_icon('heart', 15) ?>
  </button>
  <div class="sh-product-card__body">
    <h3 class="sh-product-card__name"><a href="<?= e($link) ?>"><?= e($p['name']) ?></a></h3>
    <div class="sh-product-card__meta">
      <?= sh_stars((float)$p['rating'], (int)$p['review_count']) ?>
      <?php if ($sold > 0): ?><span><?= $sold >= 1000 ? round($sold / 1000, 1) . 'k' : $sold ?> sold</span><?php endif; ?>
    </div>
    <div class="sh-product-card__price">
      <span class="sh-product-card__now"><?= e(sh_money($p['price'])) ?></span>
      <?php if ($off > 0): ?>
        <span class="sh-product-card__was"><?= e(sh_money($p['compare_price'])) ?></span>
        <span class="sh-product-card__off">-<?= $off ?>%</span>
      <?php endif; ?>
    </div>
    <?php if ($stock <= 0): ?>
      <p class="sh-product-card__stock sh-product-card__stock--out">Out of stock</p>
    <?php elseif ($stock <= 10): ?>
      <p class="sh-product-card__stock sh-product-card__stock--low">Only <?= $stock ?> left</p>
      <div class="sh-product-card__bar"><i style="width:<?= max(8, min(100, $stock * 10)) ?>%"></i></div>
    <?php else: ?>
      <p class="sh-product-card__stock">In stock</p>
    <?php endif; ?>
    <button class="sh-product-card__cart" type="button" data-add-cart="<?= (int)$p['id'] ?>" <?= $stock <= 0 ? 'disabled' : '' ?>>
      <?= sh_icon('shopping-cart', 15) ?> <?= $stock <= 0 ? 'Unavailable' : 'Add to Cart' ?>
    </button>
  </div>
</article>
