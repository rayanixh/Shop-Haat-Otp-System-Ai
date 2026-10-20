<?php
/**
 * Catalogue queries — categories, product search, filters and listings.
 */
require_once __DIR__ . '/auth.php';

function sh_categories(bool $onlyActive = true): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        $cache = sh_all(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 1) AS product_count
             FROM categories c ' . ($onlyActive ? 'WHERE c.status = 1 ' : '') .
            'ORDER BY c.sort_order ASC, c.name ASC'
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'categories');
        $cache = [];
    }
    return $cache;
}

/**
 * Neutral fallback image for a category with no uploaded photo.
 *
 * One shared asset keeps every category card the same size whether or not the
 * owner has uploaded artwork, and replaces the oversized placeholder icon the
 * cards used to show. It is a plain SVG with no script, so it passes the CSP.
 */
function sh_category_placeholder_image(): string
{
    return sh_url('assets/images/category-fallback.svg');
}

/**
 * The catalogue thumbnail for a category: its own uploaded image when one is
 * present on disk, otherwise the neutral fallback.
 */
function sh_category_thumb(?string $file): string
{
    $own = sh_category_image($file);
    return $own !== '' ? $own : sh_category_placeholder_image();
}

/**
 * The three card sizes a category can be given from the admin editor.
 *
 * The stored value is a short token, never a number, a CSS string or a class
 * name supplied by the browser: the storefront only ever sees one of these
 * three keys, and anything else falls back to "medium".
 *
 * @return array<string,string>
 */
function sh_category_card_sizes(): array
{
    return ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'];
}

/**
 * Normalise a stored or posted card size.
 *
 * This is the ONLY place the value is trusted. Unknown, missing or malformed
 * values become "medium", which is also the column default, so a category that
 * predates the setting renders exactly like a newly created one.
 */
function sh_category_card_size($value): string
{
    $v = strtolower(trim((string)$value));
    return isset(sh_category_card_sizes()[$v]) ? $v : 'medium';
}

/** Human label for a stored size ("Medium"). */
function sh_category_card_size_label($value): string
{
    $sizes = sh_category_card_sizes();
    return $sizes[sh_category_card_size($value)];
}

/**
 * Render one product-category card.
 *
 * Used everywhere a category card appears (the homepage showcase and the
 * category listing pages) so the markup can only exist in one place. The whole
 * card is a single link: no nested controls, no item counts, and the image sits
 * in a fixed 4:3 frame so the grid never shifts while images load.
 *
 * The first few cards load eagerly on purpose: they are above the fold on
 * mobile, and lazy-loading them is what makes a category grid appear to pop in
 * after the rest of the page.
 */
function sh_category_card(array $cat, int $index = 0): string
{
    $name  = trim((string)($cat['name'] ?? ''));
    $slug  = (string)($cat['slug'] ?? '');
    if ($name === '' || $slug === '') { return ''; }
    $href   = sh_url('category.php?slug=' . urlencode($slug));
    $img    = sh_category_thumb($cat['image'] ?? null);
    $hasImg = $img !== sh_category_placeholder_image();

    $size = sh_category_card_size($cat['card_size'] ?? null);

    return '<a class="sh-category-card sh-category-card--' . e($size) . '"'
        . ' data-size="' . e($size) . '" href="' . e($href) . '">'
        . '<span class="sh-category-card__media">'
        . '<img class="sh-category-card__img' . ($hasImg ? '' : ' sh-category-card__img--fallback') . '"'
        . ' src="' . e($img) . '" alt="' . e($name) . '" width="400" height="300"'
        . ($index < 4 ? ' decoding="async"' : ' loading="lazy" decoding="async"')
        . '>'
        . '</span>'
        . '<span class="sh-category-card__name" title="' . e($name) . '">' . e($name) . '</span>'
        . '</a>';
}

/**
 * Small square image for a navigation row.
 *
 * Shows the category's own uploaded image when it has one, and the line icon the
 * rest of the navigation uses when it does not — so a category with a real photo
 * is never represented by a generic icon. The image is lazy-loaded on purpose:
 * the dropdown and the mobile drawer are both hidden until the visitor opens
 * them, so nothing extra is fetched during a page load.
 */
function sh_category_nav_thumb(array $cat, int $size = 20): string
{
    $image = sh_category_image($cat['image'] ?? null);
    if ($image !== '') {
        return '<img class="sh-catthumb" src="' . e($image) . '" alt=""'
            . ' width="' . (int)$size . '" height="' . (int)$size . '" loading="lazy" decoding="async">';
    }
    return sh_icon((string)($cat['icon'] ?? '') !== '' ? (string)$cat['icon'] : 'grid', $size);
}

/**
 * Category cards for a list of category rows (the homepage showcase uses this).
 *
 * @param array<int,array<string,mixed>> $cats
 */
function sh_category_cards(array $cats, int $limit = 0): string
{
    if ($limit > 0) { $cats = array_slice($cats, 0, $limit); }
    $html = '';
    $i = 0;
    foreach ($cats as $c) {
        $card = sh_category_card($c, $i);
        if ($card === '') { continue; }
        $html .= $card;
        $i++;
    }
    return $html;
}

/**
 * Child categories of $parentId, ordered the way the admin panel lists them.
 *
 * Returns [] when the category has no children, so callers can skip the whole
 * section instead of rendering an empty grid.
 *
 * @return array<int,array<string,mixed>>
 */
function sh_category_children(int $parentId, bool $onlyActive = true): array
{
    if ($parentId <= 0) { return []; }
    try {
        return sh_all(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 1) AS product_count
             FROM categories c
             WHERE c.parent_id = ? ' . ($onlyActive ? 'AND c.status = 1 ' : '') .
            'ORDER BY c.sort_order ASC, c.name ASC',
            [$parentId]
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'category-children');
        return [];
    }
}

function sh_category_by_slug(string $slug): ?array
{
    return sh_one('SELECT * FROM categories WHERE slug = ? AND status = 1 LIMIT 1', [$slug]);
}

function sh_product_by_slug(string $slug): ?array
{
    return sh_one(
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN brands b ON b.id = p.brand_id
         WHERE p.slug = ? AND p.status = 1 LIMIT 1',
        [$slug]
    );
}

const SH_PRODUCT_SELECT = 'p.id, p.name, p.slug, p.price, p.compare_price, p.image, p.stock,
    p.rating, p.review_count, p.sold_count, p.product_type, p.is_flash_sale, p.flash_sale_ends_at,
    p.category_id, p.brand_id';

function sh_products_featured(int $limit = 12): array
{
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 AND p.is_featured = 1 ORDER BY p.sold_count DESC, p.id DESC LIMIT ' . (int)$limit);
}

function sh_products_flash(int $limit = 12): array
{
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 AND p.is_flash_sale = 1 AND p.compare_price > p.price
        AND (p.flash_sale_ends_at IS NULL OR p.flash_sale_ends_at > NOW())
        ORDER BY (p.compare_price - p.price) / p.compare_price DESC LIMIT ' . (int)$limit);
}

function sh_flash_ends_at(): ?string
{
    $v = sh_val('SELECT MAX(flash_sale_ends_at) FROM products
        WHERE status = 1 AND is_flash_sale = 1 AND flash_sale_ends_at > NOW()');
    return $v ? (string)$v : null;
}

function sh_products_best_sellers(int $limit = 12): array
{
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 ORDER BY p.sold_count DESC, p.rating DESC LIMIT ' . (int)$limit);
}

function sh_products_new(int $limit = 12): array
{
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 ORDER BY p.created_at DESC, p.id DESC LIMIT ' . (int)$limit);
}

function sh_products_by_category(int $categoryId, int $limit = 12): array
{
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 AND p.category_id = ? ORDER BY p.sold_count DESC LIMIT ' . (int)$limit, [$categoryId]);
}

function sh_products_related(int $productId, ?int $categoryId, int $limit = 6): array
{
    if ($categoryId) {
        $rows = sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
            WHERE p.status = 1 AND p.category_id = ? AND p.id <> ?
            ORDER BY p.sold_count DESC LIMIT ' . (int)$limit, [$categoryId, $productId]);
        if ($rows) { return $rows; }
    }
    return sh_all('SELECT ' . SH_PRODUCT_SELECT . ' FROM products p
        WHERE p.status = 1 AND p.id <> ? ORDER BY p.sold_count DESC LIMIT ' . (int)$limit, [$productId]);
}

function sh_brands(): array
{
    try {
        return sh_all('SELECT id, name, slug FROM brands WHERE status = 1 ORDER BY name');
    } catch (Throwable $e) {
        sh_log_exception($e, 'brands');
        return [];
    }
}

/**
 * Filtered, sorted, paginated product search used by products.php and category.php.
 * @return array{items:array,total:int,page:int,per_page:int}
 */
function sh_product_search(array $f): array
{
    $where = ['p.status = 1'];
    $params = [];

    if (!empty($f['q'])) {
        $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.sku LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    if (!empty($f['category_id'])) {
        $where[] = 'p.category_id = ?';
        $params[] = (int)$f['category_id'];
    }
    if (!empty($f['brand_ids']) && is_array($f['brand_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $f['brand_ids'])));
        if ($ids) {
            $where[] = 'p.brand_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
    }
    if (isset($f['min_price']) && $f['min_price'] !== '' && is_numeric($f['min_price'])) {
        $where[] = 'p.price >= ?';
        $params[] = (float)$f['min_price'];
    }
    if (isset($f['max_price']) && $f['max_price'] !== '' && is_numeric($f['max_price'])) {
        $where[] = 'p.price <= ?';
        $params[] = (float)$f['max_price'];
    }
    if (!empty($f['rating'])) {
        $where[] = 'p.rating >= ?';
        $params[] = (float)$f['rating'];
    }
    if (!empty($f['in_stock'])) {
        $where[] = 'p.stock > 0';
    }
    if (!empty($f['discount'])) {
        $where[] = 'p.compare_price > p.price AND ((p.compare_price - p.price) / p.compare_price) * 100 >= ?';
        $params[] = (float)$f['discount'];
    }
    if (!empty($f['flash'])) {
        $where[] = 'p.is_flash_sale = 1 AND (p.flash_sale_ends_at IS NULL OR p.flash_sale_ends_at > NOW())';
    }
    if (!empty($f['featured'])) { $where[] = 'p.is_featured = 1'; }

    $sortMap = [
        'newest'     => 'p.created_at DESC, p.id DESC',
        'popular'    => 'p.sold_count DESC, p.rating DESC',
        'price_asc'  => 'p.price ASC, p.id DESC',
        'price_desc' => 'p.price DESC, p.id DESC',
        'rating'     => 'p.rating DESC, p.review_count DESC',
        'discount'   => '((p.compare_price - p.price) / GREATEST(p.compare_price,1)) DESC',
    ];
    $order = $sortMap[$f['sort'] ?? 'popular'] ?? $sortMap['popular'];

    $whereSql = implode(' AND ', $where);
    $total = (int)sh_val("SELECT COUNT(*) FROM products p WHERE $whereSql", $params, 0);

    $perPage = max(4, min(60, (int)($f['per_page'] ?? (int)sh_setting('products_per_page', '24'))));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, (int)($f['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;

    $items = $total === 0 ? [] : sh_all(
        'SELECT ' . SH_PRODUCT_SELECT . " FROM products p WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset", // @security-scan-ignore sql_interpolation_unproven — $order is a value of the literal $sortMap allowlist; $perPage is clamped to 4..60; $offset is derived from it
        $params
    );

    return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
}

/** Live search suggestions for the header. */
function sh_search_suggest(string $q, int $limit = 8): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) { return []; }
    $like = '%' . $q . '%';
    return sh_all(
        'SELECT p.id, p.name, p.slug, p.price, p.compare_price, p.image
         FROM products p WHERE p.status = 1 AND p.name LIKE ?
         ORDER BY p.sold_count DESC LIMIT ' . (int)$limit,
        [$like]
    );
}

function sh_wishlist_ids(): array
{
    $uid = sh_user_id();
    if ($uid <= 0) { return []; }
    try {
        return array_map('intval', array_column(sh_all('SELECT product_id FROM wishlist WHERE user_id = ?', [$uid]), 'product_id'));
    } catch (Throwable $e) {
        sh_log_exception($e, 'wishlist');
        return [];
    }
}
