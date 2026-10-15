<?php
/**
 * Category page — SEO-friendly entry point that reuses the listing UI.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/catalog.php';

$slug = sh_get('slug');
$category = $slug !== '' ? sh_category_by_slug($slug) : null;

if ($category === null) {
    http_response_code(404);
    $pageTitle = 'Category not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('grid', 26) . '</span>'
        . '<h1 class="sh-empty__title">Category not found</h1>'
        . '<p class="sh-empty__text">The category you requested does not exist or is no longer available.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('products.php')) . '">Browse all products</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

$sort = sh_get('sort', 'popular');
$page = max(1, sh_int($_GET['page'] ?? 1, 1));
$minPrice = sh_get('min_price');
$maxPrice = sh_get('max_price');
$rating   = sh_get('rating');
$inStock  = sh_get('in_stock') === '1';
$brandIds = array_map('intval', (array)($_GET['brand'] ?? []));

$result = sh_product_search([
    'category_id' => (int)$category['id'],
    'sort' => $sort, 'page' => $page,
    'min_price' => $minPrice, 'max_price' => $maxPrice,
    'rating' => $rating, 'in_stock' => $inStock, 'brand_ids' => $brandIds,
]);

/*
 * SEO. Categories gained the same editable columns as products, so the row is
 * handed to the SEO engine; the generated description below is only a fallback
 * for a category the owner has not written one for yet.
 *
 * Sorted and filtered views of this page are the same content, so the canonical
 * always points at the plain slug URL with the browsing parameters stripped.
 */
$pageSeoKey      = 'category';
$pageSeoEntity   = $category;
$pageTitle       = (string)$category['name'];
$pageDescription = sh_seo_plain((string)$category['description']);
if ($pageDescription === '') {
    // Built from the real category name and the store's own delivery promises
    // rather than a generic sentence repeated on every category.
    $pageDescription = 'Shop ' . $category['name'] . ' at ' . sh_setting('site_name', 'ShopHaat')
        . '. ' . (int)($category['product_count'] ?? 0) . ' products with delivery across Bangladesh and secure payment.';
}
$pageCanonical   = 'category.php?slug=' . rawurlencode((string)$category['slug']);
$pageKeywords    = trim((string)($category['seo_focus_keyword'] ?? '') . ',' . (string)($category['meta_keywords'] ?? ''), ',');
// Prefer an image the owner set for sharing, then the category's own image.
$pageImage = sh_seo_category_image($category);
$pageBreadcrumbs = [
    ['name' => 'Home', 'url' => 'index.php'],
    ['name' => (string)$category['name'], 'url' => ''],
];
$shWishlist = sh_wishlist_ids();
$brands = sh_brands();

$baseParams = $_GET;
unset($baseParams['page']);
$baseUrl = sh_url('category.php') . '?' . http_build_query($baseParams);

require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?>
    <a href="<?= e(sh_url('products.php')) ?>">Products</a> <?= sh_icon('chevron-right', 13) ?>
    <span aria-current="page"><?= e($category['name']) ?></span>
  </nav>

  <div class="sh-listing">
    <aside class="sh-filters" id="sh-filters">
      <form method="get" action="<?= e(sh_url('category.php')) ?>">
        <input type="hidden" name="slug" value="<?= e($category['slug']) ?>">
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <div class="sh-filters__head">
          <?= sh_icon('sliders', 17) ?> <span style="flex:1">Filters</span>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-filters-close><?= sh_icon('x', 15) ?></button>
        </div>
        <div class="sh-filters__group">
          <p class="sh-filters__title">Other categories</p>
          <?php foreach ($shCategories as $c): ?>
            <label class="sh-filters__opt">
              <input type="radio" name="slug" value="<?= e($c['slug']) ?>" <?= $c['slug'] === $category['slug'] ? 'checked' : '' ?>>
              <span><?= e($c['name']) ?> (<?= (int)$c['product_count'] ?>)</span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="sh-filters__group">
          <p class="sh-filters__title">Price range</p>
          <div class="sh-filters__price">
            <input type="number" name="min_price" min="0" placeholder="Min" value="<?= e($minPrice) ?>" aria-label="Minimum price">
            <span>–</span>
            <input type="number" name="max_price" min="0" placeholder="Max" value="<?= e($maxPrice) ?>" aria-label="Maximum price">
          </div>
        </div>
        <?php if ($brands): ?>
        <div class="sh-filters__group">
          <p class="sh-filters__title">Brand</p>
          <?php foreach ($brands as $b): ?>
            <label class="sh-filters__opt">
              <input type="checkbox" name="brand[]" value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $brandIds, true) ? 'checked' : '' ?>>
              <span><?= e($b['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="sh-filters__group">
          <p class="sh-filters__title">Customer rating</p>
          <?php foreach ([4 => '4 stars & up', 3 => '3 stars & up'] as $rv => $rl): ?>
            <label class="sh-filters__opt">
              <input type="radio" name="rating" value="<?= $rv ?>" <?= (string)$rating === (string)$rv ? 'checked' : '' ?>><span><?= e($rl) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="sh-filters__opt"><input type="radio" name="rating" value="" <?= $rating === '' ? 'checked' : '' ?>><span>Any rating</span></label>
        </div>
        <div class="sh-filters__group">
          <p class="sh-filters__title">Availability</p>
          <label class="sh-filters__opt"><input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>><span>In stock only</span></label>
        </div>
        <div class="sh-filters__actions">
          <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('filter', 15) ?> Apply filters</button>
          <a class="sh-btn sh-btn--ghost sh-btn--block" href="<?= e(sh_url('category.php?slug=' . urlencode($category['slug']))) ?>">Reset</a>
        </div>
      </form>
    </aside>

    <div>
      <div class="sh-mobile-tools">
        <button type="button" data-filters-open><?= sh_icon('sliders', 16) ?> Filter</button>
        <select data-sort-select aria-label="Sort products">
          <option value="popular"    <?= $sort === 'popular' ? 'selected' : '' ?>>Sort: Popular</option>
          <option value="newest"     <?= $sort === 'newest' ? 'selected' : '' ?>>Sort: Newest</option>
          <option value="price_asc"  <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
          <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
          <option value="rating"     <?= $sort === 'rating' ? 'selected' : '' ?>>Top Rated</option>
        </select>
      </div>

      <div class="sh-toolbar">
        <h1 style="font-size:17px;font-weight:800"><?= e($category['name']) ?></h1>
        <span class="sh-toolbar__count"><b><?= (int)$result['total'] ?></b> product<?= $result['total'] === 1 ? '' : 's' ?></span>
        <div class="sh-toolbar__right">
          <label for="sh-sort2" style="font-size:13px;color:var(--sh-muted)">Sort by</label>
          <select class="sh-toolbar__select" id="sh-sort2" data-sort-select>
            <option value="popular"    <?= $sort === 'popular' ? 'selected' : '' ?>>Popularity</option>
            <option value="newest"     <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="price_asc"  <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
            <option value="rating"     <?= $sort === 'rating' ? 'selected' : '' ?>>Customer rating</option>
          </select>
        </div>
      </div>

      <?php if ($category['description']): ?>
        <p style="font-size:13.5px;color:var(--sh-muted);margin-bottom:12px"><?= e($category['description']) ?></p>
      <?php endif; ?>

      <?php if (!$result['items']): ?>
        <div class="sh-empty">
          <span class="sh-empty__icon"><?= sh_icon('box', 26) ?></span>
          <h2 class="sh-empty__title">No products in this category yet</h2>
          <p class="sh-empty__text">Please check back soon, or explore our other categories.</p>
          <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Browse all products</a>
        </div>
      <?php else: ?>
        <div class="sh-product-grid sh-product-grid--5">
          <?php foreach ($result['items'] as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
        </div>
        <?= sh_paginate((int)$result['total'], (int)$result['per_page'], (int)$result['page'], $baseUrl) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
