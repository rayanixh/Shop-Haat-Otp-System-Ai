<?php
/**
 * Rendered markup fixtures for tests/product-management-behavior.mjs.
 *
 * Copies the REAL product.php, cart.php and admin/product-edit.php templates
 * into a temporary fixture tree, then stubs only the application services
 * around them (DB, auth, SEO values, settings). The markup under test is never
 * copied or rewritten. Output: one JSON object of HTML fragments.
 *
 * Usage:
 *   php tests/product-management-render.php
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$repoRoot = dirname(__DIR__);
$fixtureRoot = sys_get_temp_dir() . '/shop-haat-product-render-' . getmypid();
@mkdir($fixtureRoot . '/config', 0775, true);
@mkdir($fixtureRoot . '/includes', 0775, true);
@mkdir($fixtureRoot . '/admin', 0775, true);

$config = <<<'PHP'
<?php
declare(strict_types=1);
if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }
if (!defined('SH_PRODUCT_MAX_IMAGES')) { define('SH_PRODUCT_MAX_IMAGES', 5); }
if (!defined('SH_PRODUCT_MAX_IMAGE_BYTES')) { define('SH_PRODUCT_MAX_IMAGE_BYTES', 3145728); }
if (!defined('SH_UPLOAD_DIR')) { define('SH_UPLOAD_DIR', sys_get_temp_dir()); }
$GLOBALS['fixture_mode'] = 'pdp';
$GLOBALS['fixture_structured'] = [];

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sh_url(string $path = ''): string { return '/' . ltrim($path, '/'); }
function sh_asset(string $path): string { return '/' . ltrim($path, '/'); }
function sh_icon(string $name, int $size = 16): string { return '<svg class="sh-icon" aria-hidden="true" width="' . $size . '" height="' . $size . '"></svg>'; }
function sh_get(string $key, string $default = ''): string { return isset($_GET[$key]) ? (string)$_GET[$key] : $default; }
function sh_post(string $key, string $default = ''): string { return isset($_POST[$key]) ? (string)$_POST[$key] : $default; }
function sh_int($value, int $default = 0): int { return is_numeric($value) ? (int)$value : $default; }
function sh_slug(string $text): string { return strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-')) ?: 'item'; }
function sh_setting(string $key, string $default = ''): string
{
    $map = ['site_name' => 'Fixture Shop', 'delivery_fee_inside' => '60', 'delivery_fee_outside' => '120'];
    return (string)($map[$key] ?? $default);
}
function sh_seo_get(string $key): string { return ''; }
function sh_seo_base(): string { return 'https://shop.example.test'; }
function sh_seo_plain(string $text): string { return trim(strip_tags($text)); }
function sh_seo_clip(string $text, int $limit, string $suffix = '…'): string
{
    return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, max(1, $limit - 1)) . $suffix;
}
function sh_seo_clean_url(string $url): string { return trim($url); }
function sh_seo_image_url(string $url): string
{
    if ($url === '') { return ''; }
    return str_starts_with($url, 'http') || str_starts_with($url, 'blob:') ? $url : 'https://shop.example.test/' . ltrim($url, '/');
}
function sh_seo_product_image(array $row): string
{
    $file = trim((string)($row['image'] ?? ''));
    return $file !== '' ? 'https://shop.example.test/uploads/products/' . rawurlencode(basename($file)) : '';
}
function sh_seo_meta(array $args): array { return ['title' => 'Fixture', 'description' => 'Fixture description']; }
function sh_seo_product_schema($product, $meta, $images): string
{
    $GLOBALS['fixture_structured'] = array_values((array)$images);
    return '<script type="application/ld+json" id="fixture-ld">' . json_encode(['image' => $images], JSON_UNESCAPED_SLASHES) . '</script>';
}
function sh_money($amount): string { return 'BDT ' . number_format((float)$amount, 2); }
function sh_discount_percent($price, $compare): int
{
    $price = (float)$price; $compare = (float)$compare;
    return ($compare > $price && $price > 0) ? (int)round(100 - ($price * 100 / $compare)) : 0;
}
function sh_excerpt(string $text, int $limit): string { return sh_seo_clip($text, $limit, ''); }
function sh_stars($rating, $count = 0): string { return '<span class="sh-stars" data-rating="' . e($rating) . '">★★★★★</span>'; }
function sh_product_image(?string $file, string $size = 'md'): string
{
    return '/uploads/products/' . rawurlencode(basename((string)$file));
}
function sh_require_installed(): void {}
function sh_session_start(): void {}
function sh_require_admin(): void {}
function sh_require_perm(string $perm): void {}
function sh_perm_denied_flash(string $perm): bool { return false; }
function sh_csrf_require(): void {}
function sh_csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-token">'; }
function sh_flash(string $type, string $message): void {}
function sh_redirect(string $url): void {}
function sh_log_exception(Throwable $e, string $channel): void {}
function sh_query(string $sql, array $params = []) { return null; }
function sh_insert(string $table, array $data): int { return 1; }
function sh_update(string $table, array $data, string $where, array $params = []): int { return 1; }
function sh_audit(string $action, string $module, ?int $id = null, string $label = '', $old = null, $new = null): void {}
function sh_db() { return null; }
function sh_admin_schema_ensure(): void {}
function sh_seo_schema_ensure(): void {}
function sh_product_schema_ensure(): void {}
function sh_seo_cache_invalidate(): void {}
function sh_admin_keep_query(array $ignore = []): string { return ''; }
function sh_admin_edit_head(array $crumbs, string $subtitle = '', string $actions = ''): string
{
    return '<div class="sh-admin-edit-head">Product editor fixture</div>';
}
function sh_stock_status(array $row): array { return ['class' => 'sh-badge--ok', 'label' => 'In stock']; }
function sh_stock_adjust(int $id, int $stock, string $reason, string $source): array { return ['ok' => true]; }
function sh_stock_check_alert(int $id): void {}
function sh_bytes_label(int $bytes): string { return '3 MB'; }
function sh_server_upload_limit(): int { return 3145728; }
function sh_product_clean_hex($hex): string
{
    $hex = strtoupper(trim((string)$hex));
    if ($hex !== '' && $hex[0] !== '#') { $hex = '#' . $hex; }
    return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : '#888888';
}
function sh_product_brands_all(bool $includeInactive = false): array
{
    return [['id' => 1, 'name' => 'Atlas Goods', 'status' => 1], ['id' => 2, 'name' => 'Nova Label', 'status' => 1]];
}
function sh_product_types_all(bool $includeInactive = false): array
{
    return [['id' => 1, 'name' => 'Handbag', 'status' => 1], ['id' => 2, 'name' => 'Shoulder Bag', 'status' => 1]];
}
function sh_product_colours_all(bool $includeInactive = false): array
{
    return [
        ['id' => 1, 'name' => 'Black', 'slug' => 'black', 'hex' => '#111827', 'status' => 1],
        ['id' => 2, 'name' => 'Red', 'slug' => 'red', 'hex' => '#DC2626', 'status' => 1],
    ];
}
function sh_product_colour_form_state(int $productId, array $post = []): array
{
    return $GLOBALS['fixture_mode'] === 'adminEdit'
        ? ['selected' => [1 => true], 'custom' => [['name' => 'Forest Green', 'hex' => '#228B22']]]
        : ['selected' => [], 'custom' => [['name' => '', 'hex' => '#888888']]];
}
function sh_product_gallery_rows(int $productId, ?string $legacyImage = null, bool $backfill = false): array
{
    $mode = $GLOBALS['fixture_mode'];
    if (!in_array($mode, ['pdp', 'adminEdit'], true)) { return []; }
    $rows = [];
    for ($i = 1; $i <= 3; $i++) {
        $file = 'image-' . $i . '.png';
        $rows[] = ['id' => $i, 'file' => $file, 'image' => $file, 'url' => '/uploads/products/' . $file, 'sort_order' => $i - 1];
    }
    return $rows;
}
function sh_product_catalog_display(int $productId, array $product = []): array
{
    if (in_array($GLOBALS['fixture_mode'], ['pdp', 'adminEdit'], true)) {
        return [
            'brand' => 'Atlas Goods',
            'type' => 'Handbag',
            'colours' => [
                ['id' => 1, 'name' => 'Black', 'hex' => '#111827', 'value' => 'Colour: Black'],
                ['id' => 2, 'name' => 'Red', 'hex' => '#DC2626', 'value' => 'Colour: Red'],
                ['id' => 3, 'name' => 'Forest Green', 'hex' => '#228B22', 'value' => 'Colour: Forest Green'],
            ],
        ];
    }
    return ['brand' => '', 'type' => '', 'colours' => []];
}
function sh_product_by_slug(string $slug): ?array
{
    $empty = $slug === 'empty-product';
    return [
        'id' => 42, 'category_id' => 7, 'brand_id' => 1, 'catalog_type_id' => 1,
        'name' => $empty ? 'Empty fixture product' : 'Fixture Handbag',
        'slug' => $slug, 'sku' => 'BAG-42', 'image' => $empty ? '' : 'image-1.png',
        'image_alt' => $empty ? '' : 'Fixture handbag, front view',
        'price' => 1200, 'compare_price' => 1500, 'stock' => 8, 'status' => 1,
        'product_type' => 'physical', 'rating' => $empty ? 0 : 4.5,
        'review_count' => $empty ? 0 : 2, 'sold_count' => $empty ? 0 : 10,
        'short_description' => $empty ? '' : 'A structured handbag for everyday use.',
        'description' => $empty ? '' : "Spacious main compartment.\nAdjustable strap.",
        'specifications' => $empty ? '' : "Material: Vegan leather\nWeight: 650 g",
        'category_name' => 'Bags', 'category_slug' => 'bags',
        'brand_name' => $empty ? '' : 'Atlas Goods',
        'catalog_type_name' => $empty ? '' : 'Handbag',
        'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '',
        'seo_focus_keyword' => '', 'seo_related_phrases' => '', 'seo_og_image' => '',
        'seo_canonical' => '', 'seo_noindex' => 0,
        'low_stock_threshold' => 5, 'is_featured' => 0, 'is_flash_sale' => 0,
        'flash_sale_ends_at' => '', 'status' => 1,
    ];
}
function sh_products_related($productId, $categoryId, int $limit): array { return []; }
function sh_wishlist_ids(): array { return []; }
function sh_user(): ?array { return null; }
function sh_one(string $sql, array $params = []): ?array
{
    if (stripos($sql, 'FROM products WHERE id = ?') !== false && $GLOBALS['fixture_mode'] === 'adminEdit') {
        return sh_product_by_slug('fixture-handbag');
    }
    return null;
}
function sh_all(string $sql, array $params = []): array
{
    if (stripos($sql, 'FROM categories') !== false) {
        return [['id' => 7, 'name' => 'Bags'], ['id' => 8, 'name' => 'Accessories']];
    }
    if (stripos($sql, 'FROM reviews') !== false && $GLOBALS['fixture_mode'] === 'pdp') {
        return [['id' => 1, 'author_name' => 'Fixture Customer', 'rating' => 5, 'title' => 'Great bag', 'body' => 'Very useful.', 'created_at' => '2026-01-01 10:00:00']];
    }
    return [];
}
function sh_product_gallery_submission_errors(array $rows, array $post, array $files, bool $required = true): array { return []; }
function sh_product_gallery_plan(array $rows, array $post, array $files, bool $required = true): array
{
    return ['ok' => true, 'errors' => [], 'rows' => [], 'primary' => '', 'uploaded' => []];
}
function sh_product_gallery_commit(int $id, array $plan, array $rows, bool $cleanup = true): array { return []; }
function sh_product_catalog_save_relations(int $id, $brand, $type, array $colours, array $names, array $hexes): int { return 0; }
function sh_product_catalog_create_brand(string $name): int { return 0; }
function sh_product_catalog_create_type(string $name): int { return 0; }
function sh_product_delete_image_file(string $file): void {}
function sh_product_delete_unreferenced_image(string $file): void {}
function sh_cart_summary(?string $coupon = null, string $zone = 'inside'): array
{
    return [
        'items' => [[
            'item_id' => 9, 'product_id' => 42, 'name' => 'Fixture Handbag', 'slug' => 'fixture-handbag',
            'image' => 'image-1.png', 'product_type' => 'physical',
            'variant' => 'Bags · SKU BAG-42 · Colour: Black', 'colour' => 'Colour: Black',
            'unit_price' => 1200, 'compare_price' => 1500, 'quantity' => 1, 'stock' => 8,
            'line_total' => 1200,
        ]],
        'subtotal' => 1200.0, 'discount' => 0.0, 'delivery' => 60.0, 'total' => 1260.0,
        'count' => 1, 'savings' => 300.0, 'coupon' => null, 'issues' => [], 'has_physical' => true,
    ];
}
function sh_coupon_evaluate(string $code, float $subtotal): array
{
    return ['valid' => false, 'error' => 'Coupon is not valid.', 'discount' => 0.0];
}
PHP;

file_put_contents($fixtureRoot . '/config/config.php', $config);
foreach (['catalog.php', 'seo.php', 'product-schema.php', 'admin-auth.php', 'admin-perms.php', 'admin-tools.php', 'header.php', 'footer.php', 'product-card.php', 'cart.php'] as $stub) {
    file_put_contents($fixtureRoot . '/includes/' . $stub, "<?php\n");
}
file_put_contents($fixtureRoot . '/admin/_layout.php', <<<'PHP'
<?php if (!defined('SH_ADMIN_PAGE_AUTHORIZED')) { define('SH_ADMIN_PAGE_AUTHORIZED', true); } ?>
<div class="sh-admin-body">
PHP
);
file_put_contents($fixtureRoot . '/admin/_footer.php', <<<'PHP'
<?php if (!defined('SH_ADMIN_PAGE_AUTHORIZED')) { http_response_code(404); exit; } ?>
</div>
PHP
);
foreach (['product.php', 'cart.php'] as $page) {
    copy($repoRoot . '/' . $page, $fixtureRoot . '/' . $page);
}
copy($repoRoot . '/admin/product-edit.php', $fixtureRoot . '/admin/product-edit.php');
copy($repoRoot . '/admin/_seo-fields.php', $fixtureRoot . '/admin/_seo-fields.php');

function render_fixture(string $fixtureRoot, string $page, string $mode, array $get): string
{
    $GLOBALS['fixture_mode'] = $mode;
    $GLOBALS['fixture_structured'] = [];
    $_GET = $get;
    $_POST = [];
    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . $page;
    ob_start();
    include $fixtureRoot . '/' . $page;
    $html = (string)ob_get_clean();
    if ($page === 'product.php') {
        $html .= '<script type="application/ld+json" id="fixture-ld">'
            . json_encode(['image' => $GLOBALS['fixture_structured']], JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
    return $html;
}

$out = [
    'cases' => ['P01', 'P02', 'P03', 'P04', 'P05', 'P06', 'P07', 'P08', 'P09', 'P10', 'P11', 'P12', 'P13', 'P14', 'P15', 'P16'],
    'pdp' => render_fixture($fixtureRoot, 'product.php', 'pdp', ['slug' => 'fixture-handbag']),
    'pdpEmpty' => render_fixture($fixtureRoot, 'product.php', 'pdpEmpty', ['slug' => 'empty-product']),
    'adminNew' => render_fixture($fixtureRoot, 'admin/product-edit.php', 'adminNew', ['new' => '1']),
    'adminEdit' => render_fixture($fixtureRoot, 'admin/product-edit.php', 'adminEdit', ['edit' => '42']),
    'cart' => render_fixture($fixtureRoot, 'cart.php', 'cart', []),
];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
