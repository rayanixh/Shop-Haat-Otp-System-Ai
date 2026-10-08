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
        'SELECT ' . SH_PRODUCT_SELECT . " FROM products p WHERE $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset",
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
