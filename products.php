<?php
/**
 * Product listing with filters, sorting and pagination.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/catalog.php';
// The SEO helpers are used while this page builds its metadata, before
// includes/header.php is loaded, so the engine must be required here.
require_once SH_ROOT . '/includes/seo.php';

$q          = sh_get('q');
$catSlug    = sh_get('category');
$sort       = sh_get('sort', 'popular');
$minPrice   = sh_get('min_price');
$maxPrice   = sh_get('max_price');
$rating     = sh_get('rating');
$discount   = sh_get('discount');
$inStock    = sh_get('in_stock') === '1';
$flashOnly  = sh_get('flash') === '1';
$featured   = sh_get('featured') === '1';
$page       = max(1, sh_int($_GET['page'] ?? 1, 1));

$activeCat = $catSlug !== '' ? sh_category_by_slug($catSlug) : null;

$result = sh_product_search([
    'q'           => $q,
    'category_id' => $activeCat['id'] ?? null,
    'min_price'   => $minPrice,
    'max_price'   => $maxPrice,
    'rating'      => $rating,
    'discount'    => $discount,
    'in_stock'    => $inStock,
    'flash'       => $flashOnly,
    'featured'    => $featured,
    'sort'        => $sort,
    'page'        => $page,
]);

$heading = 'All Products';
if ($q !== '')          { $heading = 'Search results for "' . $q . '"'; }
elseif ($activeCat)     { $heading = $activeCat['name']; }
elseif ($flashOnly)     { $heading = 'Flash Sale'; }
elseif ($featured)      { $heading = 'Featured Products'; }
elseif ($discount !== ''){ $heading = 'Offers — ' . (int)$discount . '% off or more'; }
elseif ($sort === 'newest')  { $heading = 'New Arrivals'; }
elseif ($sort === 'popular') { $heading = 'Best Sellers'; }

/*
 * SEO for a listing page.
 *
 * Three different kinds of page share this route and must not compete for the
 * same index entry:
 *   - the plain catalogue (and category listings): indexable, with a canonical
 *     that strips sort and filter parameters so one listing has one URL;
 *   - an internal search result (?q=...): never indexable, because it is
 *     infinite and duplicates the catalogue;
 *   - page 2 and beyond: indexable content, canonical still points at the
 *     unfiltered page-1 URL, which is what the paginated view really is.
 */
$isSearchView = $q !== '';
$hasFilters = $isSearchView
    || $minPrice !== '' || $maxPrice !== '' || $rating !== '' || $discount !== ''
    || $inStock || $flashOnly || $featured || $sort !== 'popular';

$pageTitle       = $heading;
$pageDescription = 'Browse ' . $result['total'] . ' products'
    . ($activeCat ? ' in ' . $activeCat['name'] : '')
    . ' at ' . sh_setting('site_name', 'ShopHaat') . ' — cash on delivery, mobile banking and nationwide shipping.';

if ($activeCat) {
    // A filtered category view still describes the category, so the category's
    // own SEO fields apply to it.
    $pageSeoEntity = $activeCat;
    $pageSeoKey = '';
    $pageCanonical = 'category.php?slug=' . rawurlencode((string)$activeCat['slug']);
    $pageImage = sh_seo_category_image($activeCat);
} else {
    $pageSeoKey = 'products';
    $pageCanonical = 'products.php';
}

// A filtered or searched view is the same catalogue sliced differently: point it
// at the clean URL and keep it out of the index rather than letting thousands of
// near-identical URLs compete with the pages that should rank.
if ($isSearchView) {
    $pageRobots = 'noindex,follow';
    $pageCanonical = 'products.php';
} elseif ($hasFilters && $page === 1) {
    $pageRobots = 'noindex,follow';
}

$pageBreadcrumbs = [['name' => 'Home', 'url' => 'index.php']];
if ($activeCat) {
    $pageBreadcrumbs[] = ['name' => 'Products', 'url' => 'products.php'];
    $pageBreadcrumbs[] = ['name' => (string)$activeCat['name'], 'url' => ''];
} else {
    $pageBreadcrumbs[] = ['name' => (string)$heading, 'url' => ''];
}

$shWishlist = sh_wishlist_ids();

// Preserve current filters when building URLs
$baseParams = $_GET;
unset($baseParams['page']);
$baseUrl = sh_url('products.php') . ($baseParams ? '?' . http_build_query($baseParams) : '');

require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?>
    <?php if ($activeCat): ?>
      <a href="<?= e(sh_url('products.php')) ?>">Products</a> <?= sh_icon('chevron-right', 13) ?>
      <span aria-current="page"><?= e($activeCat['name']) ?></span>
    <?php else: ?>
      <span aria-current="page"><?= e($heading) ?></span>
    <?php endif; ?>
  </nav>

  <div class="sh-listing">
    <!-- Filter sidebar -->
    <aside class="sh-filters" id="sh-filters">
      <form method="get" action="<?= e(sh_url('products.php')) ?>">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($flashOnly): ?><input type="hidden" name="flash" value="1"><?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">

        <div class="sh-filters__head">
          <?= sh_icon('sliders', 17) ?> <span style="flex:1">Filters</span>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-filters-close><?= sh_icon('x', 15) ?></button>
        </div>

        <div class="sh-filters__group">
          <p class="sh-filters__title">Category</p>
          <label class="sh-filters__opt">
            <input type="radio" name="category" value="" <?= $catSlug === '' ? 'checked' : '' ?>><span>All categories</span>
          </label>
          <?php foreach ($shCategories as $c): ?>
            <label class="sh-filters__opt">
              <input type="radio" name="category" value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'checked' : '' ?>>
              <span><?= e($c['name']) ?> (<?= (int)$c['product_count'] ?>)</span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="sh-filters__group">
          <p class="sh-filters__title">Price range</p>
          <div class="sh-filters__price">
            <input type="number" name="min_price" min="0" step="1" placeholder="Min" value="<?= e($minPrice) ?>" aria-label="Minimum price">
            <span>–</span>
            <input type="number" name="max_price" min="0" step="1" placeholder="Max" value="<?= e($maxPrice) ?>" aria-label="Maximum price">
          </div>
        </div>

        <div class="sh-filters__group">
          <p class="sh-filters__title">Customer rating</p>
          <?php foreach ([4 => '4 stars & up', 3 => '3 stars & up', 2 => '2 stars & up'] as $rv => $rl): ?>
            <label class="sh-filters__opt">
              <input type="radio" name="rating" value="<?= $rv ?>" <?= (string)$rating === (string)$rv ? 'checked' : '' ?>>
              <span><?= e($rl) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="sh-filters__opt">
            <input type="radio" name="rating" value="" <?= $rating === '' ? 'checked' : '' ?>><span>Any rating</span>
          </label>
        </div>

        <div class="sh-filters__group">
          <p class="sh-filters__title">Availability</p>
          <label class="sh-filters__opt">
            <input type="checkbox" name="in_stock" value="1" <?= $inStock ? 'checked' : '' ?>><span>In stock only</span>
          </label>
        </div>

        <div class="sh-filters__group">
          <p class="sh-filters__title">Discount</p>
          <?php foreach ([10 => '10% or more', 20 => '20% or more', 30 => '30% or more'] as $dv => $dl): ?>
            <label class="sh-filters__opt">
              <input type="radio" name="discount" value="<?= $dv ?>" <?= (string)$discount === (string)$dv ? 'checked' : '' ?>>
              <span><?= e($dl) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="sh-filters__opt">
            <input type="radio" name="discount" value="" <?= $discount === '' ? 'checked' : '' ?>><span>Any discount</span>
          </label>
        </div>

        <div class="sh-filters__actions">
          <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('filter', 15) ?> Apply filters</button>
          <a class="sh-btn sh-btn--ghost sh-btn--block" href="<?= e(sh_url('products.php')) ?>">Reset</a>
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
          <option value="discount"   <?= $sort === 'discount' ? 'selected' : '' ?>>Biggest Discount</option>
        </select>
      </div>

      <div class="sh-toolbar">
        <h1 style="font-size:17px;font-weight:800"><?= e($heading) ?></h1>
        <span class="sh-toolbar__count"><b><?= (int)$result['total'] ?></b> product<?= $result['total'] === 1 ? '' : 's' ?> found</span>
        <div class="sh-toolbar__right">
          <label for="sh-sort" style="font-size:13px;color:var(--sh-muted)">Sort by</label>
          <select class="sh-toolbar__select" id="sh-sort" data-sort-select>
            <option value="popular"    <?= $sort === 'popular' ? 'selected' : '' ?>>Popularity</option>
            <option value="newest"     <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="price_asc"  <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
            <option value="rating"     <?= $sort === 'rating' ? 'selected' : '' ?>>Customer rating</option>
            <option value="discount"   <?= $sort === 'discount' ? 'selected' : '' ?>>Biggest discount</option>
          </select>
        </div>
      </div>

      <?php if (!$result['items']): ?>
        <div class="sh-empty">
          <span class="sh-empty__icon"><?= sh_icon($hasFilters ? 'search' : 'box', 26) ?></span>
          <?php if ($hasFilters): ?>
          <h2 class="sh-empty__title">No products found</h2>
          <p class="sh-empty__text">We could not find anything matching those filters. Try widening your price range or clearing some filters.</p>
          <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Browse all products</a>
          <?php else: ?>
          <h2 class="sh-empty__title">No products yet</h2>
          <p class="sh-empty__text">New items will appear here soon.</p>
          <?php endif; ?>
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
