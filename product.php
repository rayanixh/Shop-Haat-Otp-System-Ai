<?php
/**
 * Product detail page with gallery, tabs, reviews and related products.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/catalog.php';

$slug = sh_get('slug');
$product = $slug !== '' ? sh_product_by_slug($slug) : null;

if ($product === null) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('box', 26) . '</span>'
        . '<h1 class="sh-empty__title">Product not found</h1>'
        . '<p class="sh-empty__text">This product may have been removed or is no longer available.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('products.php')) . '">Continue shopping</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

$pid = (int)$product['id'];

// Review submission
$reviewError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && sh_post('form') === 'review') {
    sh_csrf_require();
    $u = sh_user();
    if ($u === null) {
        sh_flash('error', 'Please sign in to write a review.');
        sh_redirect('login.php?redirect=' . urlencode('product.php?slug=' . $slug));
    }
    $rating = max(1, min(5, sh_int($_POST['rating'] ?? 5, 5)));
    $title = mb_substr(sh_post('title'), 0, 150);
    $body = mb_substr(sh_post('body'), 0, 2000);
    if (mb_strlen($body) < 8) {
        $reviewError = 'Please write at least a few words about the product.';
    } else {
        try {
            $already = sh_one('SELECT id FROM reviews WHERE product_id = ? AND user_id = ? LIMIT 1', [$pid, (int)$u['id']]);
            if ($already) {
                $reviewError = 'You have already reviewed this product.';
            } else {
                sh_insert('reviews', [
                    'product_id' => $pid, 'user_id' => (int)$u['id'], 'author_name' => $u['name'],
                    'rating' => $rating, 'title' => $title, 'body' => $body, 'status' => 'approved',
                ]);
                sh_query('UPDATE products SET
                    rating = COALESCE((SELECT ROUND(AVG(r.rating),2) FROM reviews r WHERE r.product_id = ? AND r.status = \'approved\'),0),
                    review_count = (SELECT COUNT(*) FROM reviews r WHERE r.product_id = ? AND r.status = \'approved\')
                    WHERE id = ?', [$pid, $pid, $pid]);
                sh_flash('success', 'Thank you — your review has been published.');
                sh_redirect('product.php?slug=' . urlencode($slug));
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'review');
            $reviewError = 'Your review could not be saved. Please try again.';
        }
    }
}

try { sh_query('UPDATE products SET view_count = view_count + 1 WHERE id = ?', [$pid]); }
catch (Throwable $e) { sh_log_exception($e, 'view-count'); }

$images  = sh_all('SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$pid]);
$gallery = array_values(array_filter(array_merge([$product['image']], array_column($images, 'image'))));
if (!$gallery) { $gallery = [null]; }
$reviews = sh_all('SELECT * FROM reviews WHERE product_id = ? AND status = \'approved\' ORDER BY created_at DESC LIMIT 20', [$pid]);
$related = sh_products_related($pid, $product['category_id'] ? (int)$product['category_id'] : null, 6);
$shWishlist = sh_wishlist_ids();

$off = sh_discount_percent($product['price'], $product['compare_price']);
$stock = (int)$product['stock'];
$isDigital = $product['product_type'] === 'digital';

$pageTitle = $product['meta_title'] ?: $product['name'];
$pageDescription = $product['meta_description'] ?: sh_excerpt($product['short_description'] ?: $product['description'], 158);
$pageCanonical = rtrim(sh_site_url(), '/') . '/product.php?slug=' . urlencode($product['slug']);

$structuredData = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['name'],
    'description' => sh_excerpt($product['short_description'] ?: $product['description'], 300),
    'sku' => $product['sku'] ?: ('SH-' . $pid),
    'image' => [rtrim(sh_site_url(), '/') . '/' . ltrim(str_replace(sh_base_url() . '/', '', sh_product_image($product['image'])), '/')],
    'offers' => [
        '@type' => 'Offer',
        'price' => number_format((float)$product['price'], 2, '.', ''),
        'priceCurrency' => (string)sh_setting('currency_code', 'BDT'),
        'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'url' => $pageCanonical,
    ],
];
if ($product['brand_name']) {
    $structuredData['brand'] = ['@type' => 'Brand', 'name' => $product['brand_name']];
}
if ((int)$product['review_count'] > 0 && (float)$product['rating'] > 0) {
    $structuredData['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => number_format((float)$product['rating'], 1),
        'reviewCount' => (int)$product['review_count'],
    ];
}

require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?>
    <?php if ($product['category_slug']): ?>
      <a href="<?= e(sh_url('category.php?slug=' . urlencode($product['category_slug']))) ?>"><?= e($product['category_name']) ?></a>
      <?= sh_icon('chevron-right', 13) ?>
    <?php endif; ?>
    <span aria-current="page"><?= e(sh_excerpt($product['name'], 60)) ?></span>
  </nav>

  <div class="sh-pdp">
    <!-- Gallery -->
    <div data-gallery>
      <div class="sh-gallery__main">
        <img data-gallery-main src="<?= e(sh_product_image($gallery[0])) ?>" alt="<?= e($product['name']) ?>" width="460" height="460">
      </div>
      <?php if (count($gallery) > 1): ?>
        <div class="sh-gallery__thumbs">
          <?php foreach ($gallery as $i => $g): ?>
            <button class="sh-gallery__thumb <?= $i === 0 ? 'sh-gallery__thumb--on' : '' ?>" type="button"
                    data-gallery-thumb="<?= e(sh_product_image($g)) ?>" aria-label="View image <?= $i + 1 ?>">
              <img src="<?= e(sh_product_image($g)) ?>" alt="" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Buy box -->
    <div>
      <h1 class="sh-pdp__title"><?= e($product['name']) ?></h1>
      <div class="sh-pdp__meta">
        <?= sh_stars((float)$product['rating'], (int)$product['review_count']) ?>
        <span><?= (int)$product['review_count'] ?> review<?= (int)$product['review_count'] === 1 ? '' : 's' ?></span>
        <span><?= (int)$product['sold_count'] ?> sold</span>
        <?php if ($product['brand_name']): ?><span>Brand: <strong><?= e($product['brand_name']) ?></strong></span><?php endif; ?>
      </div>

      <div class="sh-pdp__price">
        <span class="sh-pdp__now"><?= e(sh_money($product['price'])) ?></span>
        <?php if ($off > 0): ?>
          <span class="sh-pdp__was"><?= e(sh_money($product['compare_price'])) ?></span>
          <span class="sh-pdp__off">Save <?= $off ?>%</span>
        <?php endif; ?>
      </div>

      <dl class="sh-pdp__rows">
        <div class="sh-pdp__row"><dt>Availability</dt><dd>
          <?php if ($stock <= 0): ?><span class="sh-badge sh-badge--bad"><?= sh_icon('x-circle', 13) ?> Out of stock</span>
          <?php elseif ($stock <= 10): ?><span class="sh-badge sh-badge--warn"><?= sh_icon('clock', 13) ?> Only <?= $stock ?> left</span>
          <?php else: ?><span class="sh-badge sh-badge--ok"><?= sh_icon('check-circle', 13) ?> In stock</span><?php endif; ?>
        </dd></div>
        <div class="sh-pdp__row"><dt>Product type</dt><dd><?= $isDigital ? 'Digital — instant code delivery' : 'Physical — shipped to your address' ?></dd></div>
        <div class="sh-pdp__row"><dt>Delivery</dt><dd>
          <?= $isDigital
            ? 'Delivered to your account immediately after payment verification'
            : 'Inside city ' . e(sh_money(sh_setting('delivery_fee_inside', '60'))) . ' · Outside city ' . e(sh_money(sh_setting('delivery_fee_outside', '120'))) ?>
        </dd></div>
        <?php if ($product['sku']): ?>
          <div class="sh-pdp__row"><dt>SKU</dt><dd><?= e($product['sku']) ?></dd></div>
        <?php endif; ?>
      </dl>

      <?php if ($stock > 0): ?>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span style="font-size:13px;font-weight:700;color:var(--sh-ink-2)">Quantity</span>
          <div class="sh-qty">
            <button type="button" data-qty-step="-1" aria-label="Decrease quantity"><?= sh_icon('minus', 15) ?></button>
            <input id="sh-pdp-qty" type="number" value="1" min="1" max="<?= min(20, $stock) ?>" aria-label="Quantity">
            <button type="button" data-qty-step="1" aria-label="Increase quantity"><?= sh_icon('plus', 15) ?></button>
          </div>
          <span style="font-size:12.5px;color:var(--sh-muted)"><?= $stock ?> available</span>
        </div>
      <?php endif; ?>

      <div class="sh-pdp__buy">
        <button class="sh-btn sh-btn--lg" type="button" data-add-cart="<?= $pid ?>" data-qty-source="sh-pdp-qty" <?= $stock <= 0 ? 'disabled' : '' ?>>
          <?= sh_icon('shopping-cart', 17) ?> Add to Cart
        </button>
        <button class="sh-btn sh-btn--lg sh-btn--dark" type="button" data-add-cart="<?= $pid ?>" data-qty-source="sh-pdp-qty" data-then-checkout <?= $stock <= 0 ? 'disabled' : '' ?>>
          <?= sh_icon('zap', 17) ?> Buy Now
        </button>
        <button class="sh-btn sh-btn--lg sh-btn--ghost" type="button" data-wishlist="<?= $pid ?>"
                aria-pressed="<?= in_array($pid, $shWishlist, true) ? 'true' : 'false' ?>">
          <?= sh_icon('heart', 17) ?> Wishlist
        </button>
      </div>

      <div class="sh-secure-note">
        <?= sh_icon('shield', 16) ?>
        <span>Secure checkout with bKash, Nagad, Rocket and Cash on Delivery. Your payment details are never stored on our servers.</span>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="sh-pdp__tabs" data-tabs>
    <div class="sh-tabs__head" role="tablist">
      <button class="sh-tabs__btn sh-tabs__btn--on" type="button" data-tab="desc">Description</button>
      <button class="sh-tabs__btn" type="button" data-tab="specs">Specifications</button>
      <button class="sh-tabs__btn" type="button" data-tab="reviews">Reviews (<?= (int)$product['review_count'] ?>)</button>
    </div>
    <div class="sh-tabs__panel" data-tab-panel="desc">
      <?= nl2br(e($product['description'] ?: $product['short_description'] ?: 'No description available.')) ?>
    </div>
    <div class="sh-tabs__panel" data-tab-panel="specs" hidden>
      <?php $specs = array_filter(array_map('trim', explode("\n", (string)$product['specifications']))); ?>
      <?php if ($specs): ?>
        <table class="sh-spectable"><tbody>
          <?php foreach ($specs as $line): $parts = explode(':', $line, 2); ?>
            <tr>
              <td><?= e(trim($parts[0])) ?></td>
              <td><?= e(trim($parts[1] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php else: ?>
        <p>No specifications have been provided for this product.</p>
      <?php endif; ?>
    </div>
    <div class="sh-tabs__panel" data-tab-panel="reviews" hidden>
      <?php if ($reviewError): ?>
        <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($reviewError) ?></span></div>
      <?php endif; ?>
      <?php if (!$reviews): ?>
        <p style="color:var(--sh-muted)">No reviews yet. Be the first to share your experience.</p>
      <?php else: ?>
        <?php foreach ($reviews as $rv): ?>
          <div class="sh-review">
            <div class="sh-review__head">
              <span class="sh-review__author"><?= e($rv['author_name']) ?></span>
              <?= sh_stars((float)$rv['rating']) ?>
              <span class="sh-review__date"><?= e(date('d M Y', strtotime($rv['created_at']))) ?></span>
            </div>
            <?php if ($rv['title']): ?><p class="sh-review__title"><?= e($rv['title']) ?></p><?php endif; ?>
            <p class="sh-review__body"><?= nl2br(e($rv['body'])) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--sh-line)">
        <h3 style="font-size:14.5px;font-weight:800;margin-bottom:12px">Write a review</h3>
        <?php if (sh_user() === null): ?>
          <p style="font-size:13.5px;color:var(--sh-muted)">
            Please <a href="<?= e(sh_url('login.php?redirect=' . urlencode('product.php?slug=' . $slug))) ?>" style="color:var(--sh-brand);font-weight:700">sign in</a> to write a review.
          </p>
        <?php else: ?>
          <form method="post">
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="review">
            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="rv-rating">Your rating</label>
                <select class="sh-select" id="rv-rating" name="rating">
                  <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?= $i ?>"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="rv-title">Review title</label>
                <input class="sh-input" id="rv-title" name="title" maxlength="150" placeholder="Summarise your experience">
              </div>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="rv-body">Your review <span class="sh-field__req">*</span></label>
              <textarea class="sh-textarea" id="rv-body" name="body" required minlength="8" maxlength="2000"
                        placeholder="What did you like or dislike about this product?"></textarea>
            </div>
            <button class="sh-btn" type="submit"><?= sh_icon('send', 15) ?> Submit review</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($related): ?>
    <section class="sh-section" style="margin-top:14px">
      <div class="sh-section__head">
        <h2 class="sh-section__title"><?= sh_icon('layout', 19) ?> Related Products</h2>
      </div>
      <div class="sh-product-grid">
        <?php foreach ($related as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
