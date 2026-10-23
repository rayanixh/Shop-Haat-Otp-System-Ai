<?php
/**
 * AI Auto Work — real MySQL persistence test for "Generate Full Product".
 *
 * This is the proof the interface cannot give: it calls the same function the
 * save endpoint calls, against a real MySQL/MariaDB schema, and then reads the
 * rows back through the application's own data layer.
 *
 * It covers:
 *   1. every generated field is stored in its real column (including the plain
 *      text description, specifications, SEO columns and alt text) — and that
 *      the retired brand/features inputs are ignored rather than stored;
 *   2. the category relationship, the tags and the gallery rows are written;
 *   3. the same draft token can never create a second product;
 *   4. a failed INSERT is rolled back completely (no product, no tags, no
 *      gallery) and the retry with the same token then succeeds;
 *   5. validation refuses to create anything without a price or a category;
 *   6. the saved product is visible to the storefront queries and the category
 *      card uses the category's own Small/Medium/Large size.
 *
 * Run with a test-only config/database.php that points at a disposable MySQL /
 * MariaDB database. The script drops and recreates the tables it needs and
 * leaves the database empty again, so it can run before reporting-integration.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/product-ai-save.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/seo.php';
require_once SH_ROOT . '/includes/category-image.php';
require_once SH_ROOT . '/includes/product-schema.php';
require_once SH_ROOT . '/ai/AIManager.php';
require_once SH_ROOT . '/ai/schema.php';

/** @param mixed $expected @param mixed $actual */
function ai_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

function ai_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function ai_step(string $name): void
{
    echo '::group::' . $name . "\n";
}

/** Drop every table this test knows about, leaving an empty database. */
function ai_drop_all(PDO $pdo): void
{
    $extra = ['product_tags', 'ai_generations', 'ai_providers', 'ai_models', 'ai_settings',
              'ai_prompt_templates', 'seo_pages', 'blog_posts'];
    $tables = array_values(array_unique(array_merge(sh_schema_tables(), $extra)));
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$pdo = sh_db();
$createdProductIds = [];
$tempFiles = [];

try {
    ai_step('Fresh schema');
    ai_drop_all($pdo);
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_schema_seed($pdo, ['site_name' => 'ShopHaat AI Product Test', 'admin_email' => 'ai-test@example.test']);
    sh_seed_essential_starters($pdo);
    sh_ai_migrate();
    ai_assert(sh_table_exists('product_tags'), 'product_tags was not created by the AI migration');
    // The "Features and benefits" section was removed: a fresh schema must not
    // create the column, and the save below must not need it.
    ai_assert(!sh_table_has_column('products', 'features'), 'a fresh schema still creates the retired features column');
    ai_assert(sh_table_has_column('products', 'image_alt'), 'products.image_alt is missing from the schema');
    ai_assert(sh_table_has_column('products', 'ai_draft_token'), 'products.ai_draft_token is missing');
    ai_assert(sh_table_has_column('categories', 'card_size'), 'categories.card_size is missing');

    // One image generated the way sh_ai_store_image() names its files.
    $dir = SH_UPLOAD_DIR . '/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create uploads/products');
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $image = 'ai-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.png';
    file_put_contents($dir . '/' . $image, $png);
    $tempFiles[] = $dir . '/' . $image;
    $galleryImage = 'ai-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.png';
    file_put_contents($dir . '/' . $galleryImage, $png);
    $tempFiles[] = $dir . '/' . $galleryImage;

    $catSmall = (int)sh_insert('categories', [
        'name' => 'Kitchen Appliances', 'slug' => 'kitchen-appliances', 'icon' => 'zap',
        'card_size' => 'small', 'status' => 1, 'sort_order' => 1,
    ]);
    $catLarge = (int)sh_insert('categories', [
        'name' => 'Home & Living', 'slug' => 'home-living', 'icon' => 'home',
        'card_size' => 'large', 'status' => 1, 'sort_order' => 2,
    ]);
    // ---------------------------------------------- 0. what the model may set
    ai_step('A model answer keeps only fields the product form really has');
    $model = [
        'title'             => 'Stainless Steel Electric Kettle 1.8L',
        'short_description' => 'A 1.8 litre kettle for everyday tea and coffee.',
        'description'       => '<p>Boils water for tea, coffee and cooking.</p><p>Cool-touch handle.</p>',
        'specifications'    => ["Capacity: 1.8 L", 'Power: 1500 W'],
        'features'          => ['Boils up to 1.8 litres', 'Cool-touch handle'],
        'tags'              => ['kettle', 'kitchen appliance'],
        'meta_title'        => 'Stainless Steel Electric Kettle 1.8L',
        'meta_description'  => 'A 1.8 litre stainless steel electric kettle.',
        'focus_keyword'     => 'electric kettle',
        'related_phrases'   => ['steel kettle', '1.8 litre kettle'],
        'keywords'          => ['kettle', 'electric kettle'],
        'image_alt'         => 'Stainless steel electric kettle on a kitchen counter',
        'category'          => 'Kitchen Appliances',
        'brand'             => 'HaatHome',
        'sku'               => 'KET-18L',
        // Fabricated business data the model must never be able to set:
        'price' => '999', 'compare_price' => '1999', 'stock' => '500',
        'status' => 1, 'low_stock_threshold' => 99,
        'certifications' => ['ISO 9001'], 'warranty' => '5 years',
        'ingredients' => ['steel'], 'dimensions' => '10x10x10',
    ];
    $norm = sh_ai_normalise_product_draft($model);
    // 'brand' and 'features' above are retired fields: a model (or a stale
    // client) still sending them must not resurrect either one.
    foreach (['price', 'compare_price', 'stock', 'status', 'low_stock_threshold',
              'certifications', 'warranty', 'ingredients', 'dimensions',
              'brand', 'brand_id', 'brand_name', 'features'] as $forbidden) {
        ai_assert(!array_key_exists($forbidden, $norm),
            'the draft exposes a field the model must not control: ' . $forbidden);
    }
    ai_assert_same($catSmall, (int)$norm['category_id'], 'an exact category name was not matched to the real row');
    ai_assert_same('KET-18L', (string)$norm['sku'], 'the generated SKU was not cleaned');
    ai_assert_same(2, count($norm['tags']), 'the generated tags were lost');
    ai_assert(str_contains((string)$norm['description'], 'Boils water'), 'the generated description was lost');
    ai_assert(!str_contains((string)$norm['description'], '<p>'), 'the generated description kept HTML');
    ai_assert(str_contains((string)$norm['specifications'], 'Capacity: 1.8 L'), 'the generated specifications were lost');
    ai_assert(str_contains((string)$norm['image_alt'], 'kitchen counter'), 'the generated alt text was lost');

    // A category the model invented stays a visible suggestion, never an id.
    $invented = sh_ai_normalise_product_draft(['title' => 'Catamaran', 'category' => 'Luxury Yachts']);
    ai_assert_same(null, $invented['category_id'], 'an invented category was turned into a real category id');
    ai_assert_same('Luxury Yachts', (string)$invented['category_name'], 'the invented category is not shown as missing');

    // ---------------------------------------------------------------- 1. save
    ai_step('Save a complete draft with one call');
    $token = bin2hex(random_bytes(16));
    $draft = [
        'token'             => $token,
        'name'              => 'Stainless Steel Electric Kettle 1.8L',
        'slug'              => 'stainless-steel-electric-kettle-1-8l',
        'sku'               => 'KET-18L',
        'category_id'       => $catSmall,
        // Retired input: must be ignored, not validated and not stored.
        'brand_id'          => 999999,
        'price'             => '2490.00',
        'compare_price'     => '2990',
        'stock'             => '12',
        'status'            => 1,
        'short_description' => 'A 1.8 litre stainless steel kettle for everyday tea and coffee.',
        'description'       => "Boils water for tea, coffee and cooking.\n\nA cool-touch handle and a concealed element keep daily use simple.",
        'specifications'    => "Capacity: 1.8 L\nMaterial: Stainless steel\nPower: 1500 W",
        'features'          => "Boils up to 1.8 litres in one fill\nCool-touch handle for safe pouring",
        'tags'              => ['kettle', 'kitchen appliance', 'electric kettle'],
        'meta_title'        => 'Stainless Steel Electric Kettle 1.8L',
        'meta_description'  => 'A 1.8 litre stainless steel electric kettle with a cool-touch handle.',
        'focus_keyword'     => 'electric kettle',
        'related_phrases'   => 'steel kettle, 1.8 litre kettle',
        'keywords'          => 'kettle, electric kettle, kitchen',
        'image_alt'         => 'Stainless steel electric kettle on a kitchen counter',
        'image_file'        => $image,
        'gallery_files'     => [$galleryImage],
    ];

    $res = sh_ai_save_full_product($draft, 1);
    ai_assert(!empty($res['ok']), 'the save failed: ' . (string)($res['error'] ?? 'unknown'));
    $id = (int)$res['id'];
    $createdProductIds[] = $id;
    ai_assert($id > 0, 'the save returned no product id');
    ai_assert(empty($res['already_saved']), 'the first save was reported as a duplicate');
    echo "created product #{$id}\n";

    // Once a product exists, the draft flags the same SKU instead of pretending.
    $dupe = sh_ai_normalise_product_draft(['title' => 'Another kettle', 'sku' => 'ket-18l']);
    ai_assert_same(false, (bool)$dupe['sku_unique'], 'a duplicate SKU was not flagged after a product exists');

    // ------------------------------------------------ 2. read the real rows
    ai_step('Verify every saved column through the application data layer');
    $row = sh_one('SELECT * FROM products WHERE id = ? LIMIT 1', [$id]);
    ai_assert($row !== null, 'the product row does not exist after a successful save');
    ai_assert_same('Stainless Steel Electric Kettle 1.8L', (string)$row['name'], 'name was not stored');
    ai_assert_same('stainless-steel-electric-kettle-1-8l', (string)$row['slug'], 'slug was not stored');
    ai_assert_same('KET-18L', (string)$row['sku'], 'sku was not stored');
    ai_assert_same($catSmall, (int)$row['category_id'], 'category relationship was not stored');
    ai_assert(empty($row['brand_id']), 'a brand was stored even though product management has no brand field');
    ai_assert_same('2490.00', number_format((float)$row['price'], 2, '.', ''), 'price was not stored');
    ai_assert_same('2990.00', number_format((float)$row['compare_price'], 2, '.', ''), 'compare-at price was not stored');
    ai_assert_same(12, (int)$row['stock'], 'stock was not stored');
    ai_assert_same(1, (int)$row['status'], 'publication status was not stored');
    ai_assert_same($image, (string)$row['image'], 'the featured image was not attached');
    ai_assert_same($token, (string)$row['ai_draft_token'], 'the draft token was not stored');
    ai_assert(str_contains((string)$row['description'], 'Boils water'), 'the description was not stored');
    ai_assert(!str_contains((string)$row['description'], '<p'), 'the description kept HTML tags');
    ai_assert(str_contains((string)$row['specifications'], 'Capacity: 1.8 L'), 'specifications were not stored');
    ai_assert(empty($row['features'] ?? null), 'features text was stored even though the section was removed');
    ai_assert_same('Stainless Steel Electric Kettle 1.8L', (string)$row['meta_title'], 'SEO title was not stored');
    ai_assert(str_contains((string)$row['meta_description'], 'cool-touch handle'), 'SEO description was not stored');
    ai_assert_same('electric kettle', (string)$row['seo_focus_keyword'], 'focus keyword was not stored');
    ai_assert(str_contains((string)$row['seo_related_phrases'], '1.8 litre'), 'related phrases were not stored');
    ai_assert(str_contains((string)$row['meta_keywords'], 'kitchen'), 'SEO keywords were not stored');
    ai_assert(str_contains((string)$row['image_alt'], 'Stainless steel electric kettle'), 'image alt text was not stored');

    $tags = array_column(sh_all('SELECT tag FROM product_tags WHERE product_id = ? ORDER BY tag', [$id]), 'tag');
    ai_assert_same(3, count($tags), 'the three tags were not written to product_tags');
    ai_assert(in_array('electric kettle', $tags, true), 'a tag was lost: ' . implode(', ', $tags));

    $gallery = array_column(sh_all('SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order', [$id]), 'image');
    ai_assert_same([$galleryImage], $gallery, 'the gallery image was not written to product_images');
    ai_assert(is_file(SH_UPLOAD_DIR . '/products/' . $galleryImage), 'the gallery file is not on disk');
    ai_assert(is_file(SH_UPLOAD_DIR . '/products/' . $image), 'the featured image is not on disk');

    // ------------------------------------------------------- 3. storefront
    ai_step('The product is visible to the storefront and the admin list');
    $bySlug = sh_product_by_slug('stainless-steel-electric-kettle-1-8l');
    ai_assert($bySlug !== null, 'sh_product_by_slug() cannot find the saved product');
    ai_assert_same($id, (int)$bySlug['id'], 'the storefront resolves the wrong product');

    $inCategory = array_column(sh_products_by_category($catSmall, 50), 'id');
    ai_assert(in_array($id, $inCategory, true), 'the product is missing from its category listing');

    $adminList = sh_all('SELECT id FROM products WHERE name LIKE ?', ['%Electric Kettle%']);
    ai_assert(in_array($id, array_map('intval', array_column($adminList, 'id')), true),
        'the product is missing from the admin listing query');

    // ------------------------------------------------- 4. category sizes
    ai_step('Small / Medium / Large are stored and rendered per category');
    $catRow = sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$catSmall]);
    ai_assert_same('small', (string)$catRow['card_size'], 'the category size was not stored');
    $card = sh_category_card($catRow, 0);
    ai_assert(str_contains($card, 'sh-category-card--small'), 'the storefront card ignores the saved small size');

    sh_update('categories', ['card_size' => 'large'], 'id = ?', [$catLarge]);
    $catRow2 = sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$catLarge]);
    ai_assert(str_contains(sh_category_card($catRow2, 0), 'sh-category-card--large'), 'the large size is not rendered');

    sh_update('categories', ['card_size' => 'medium'], 'id = ?', [$catLarge]);
    $catRow2 = sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$catLarge]);
    ai_assert(str_contains(sh_category_card($catRow2, 0), 'sh-category-card--medium'), 'the medium size is not rendered');

    // An unknown/missing value must fall back to medium, never break the card.
    ai_assert(str_contains(sh_category_card(['name' => 'Legacy', 'slug' => 'legacy', 'image' => ''], 0),
        'sh-category-card--medium'), 'a category without a stored size is not medium');

    $homeCats = sh_category_cards(sh_categories());
    ai_assert(str_contains($homeCats, 'sh-category-card--small'), 'the homepage grid does not mix saved sizes');

    // --------------------------------------------- 5. duplicate protection
    ai_step('A retried save cannot create a second product');
    $again = sh_ai_save_full_product($draft, 1);
    ai_assert(!empty($again['ok']), 'the idempotent retry failed: ' . (string)($again['error'] ?? ''));
    ai_assert(!empty($again['already_saved']), 'the retry did not report the existing product');
    ai_assert_same($id, (int)$again['id'], 'the retry returned a different product');
    ai_assert_same(1, (int)sh_val('SELECT COUNT(*) FROM products WHERE ai_draft_token = ?', [$token], 0),
        'the retry created a duplicate product');

    // Same name is allowed (different slug); the same slug is not.
    $twin = $draft;
    $twin['token'] = bin2hex(random_bytes(16));
    $twin['slug'] = 'stainless-steel-electric-kettle-1-8l';
    $twin['sku'] = 'KET-18L-2';
    $twinRes = sh_ai_save_full_product($twin, 1);
    ai_assert(empty($twinRes['ok']), 'a duplicate slug was accepted');
    ai_assert(isset($twinRes['fields']['slug']), 'the duplicate slug was not reported on the slug field');

    // ------------------------------------------------------- 6. validation
    ai_step('Validation refuses incomplete business data');
    $before = (int)sh_val('SELECT COUNT(*) FROM products', [], 0);

    $noPrice = $draft;
    $noPrice['token'] = bin2hex(random_bytes(16));
    $noPrice['slug'] = 'no-price-product';
    $noPrice['price'] = '';
    $r = sh_ai_save_full_product($noPrice, 1);
    ai_assert(empty($r['ok']) && isset($r['fields']['price']), 'a missing price was accepted');

    $noCat = $draft;
    $noCat['token'] = bin2hex(random_bytes(16));
    $noCat['slug'] = 'no-category-product';
    $noCat['category_id'] = 0;
    $r = sh_ai_save_full_product($noCat, 1);
    ai_assert(empty($r['ok']) && isset($r['fields']['category_id']), 'a missing category was accepted');

    $badStock = $draft;
    $badStock['token'] = bin2hex(random_bytes(16));
    $badStock['slug'] = 'bad-stock-product';
    $badStock['stock'] = '-4';
    $r = sh_ai_save_full_product($badStock, 1);
    ai_assert(empty($r['ok']) && isset($r['fields']['stock']), 'a negative stock value was accepted');

    ai_assert_same($before, (int)sh_val('SELECT COUNT(*) FROM products', [], 0),
        'a rejected save still created a product');

    // ---------------------------------------------------- 7. real rollback
    ai_step('A database failure rolls the whole save back');
    // Force the second write of the transaction (the gallery INSERT) to fail by
    // taking its table away for the duration of one save. The product row is
    // inserted first, so this proves the rollback really covers it. A trigger is
    // deliberately not used: managed MySQL users are often denied TRIGGER.
    $failToken = bin2hex(random_bytes(16));
    $fail = $draft;
    $fail['token'] = $failToken;
    $fail['name'] = 'Rollback Only Kettle';
    $fail['slug'] = 'rollback-only-kettle';
    $fail['sku'] = 'ROLLBACK-1';
    $galleryBefore = (int)sh_val('SELECT COUNT(*) FROM product_images', [], 0);

    $pdo->exec('DROP TABLE IF EXISTS product_images_rollback_hold');
    $pdo->exec('RENAME TABLE product_images TO product_images_rollback_hold');
    try {
        $r = sh_ai_save_full_product($fail, 1);
    } finally {
        $pdo->exec('RENAME TABLE product_images_rollback_hold TO product_images');
    }
    ai_assert(empty($r['ok']), 'a database failure was reported as a successful save');
    ai_assert(str_contains((string)$r['error'], 'not saved'), 'the failure message claims success');
    ai_assert(!$pdo->inTransaction(), 'a failed save left the connection inside a transaction');
    ai_assert_same(0, (int)sh_val('SELECT COUNT(*) FROM products WHERE ai_draft_token = ?', [$failToken], 0),
        'a failed save left a product row behind');
    ai_assert_same(0, (int)sh_val('SELECT COUNT(*) FROM products WHERE slug = ?', ['rollback-only-kettle'], 0),
        'a failed save left the product slug behind');
    ai_assert_same($galleryBefore, (int)sh_val('SELECT COUNT(*) FROM product_images', [], 0),
        'a failed save left a gallery row behind');

    // The same token retries cleanly once the database is healthy again.
    $retry = sh_ai_save_full_product($fail, 1);
    ai_assert(!empty($retry['ok']), 'the retry after a rollback failed: ' . (string)($retry['error'] ?? ''));
    $retryId = (int)$retry['id'];
    $createdProductIds[] = $retryId;
    ai_assert_same(1, (int)sh_val('SELECT COUNT(*) FROM products WHERE ai_draft_token = ?', [$failToken], 0),
        'the retry created more than one product');
    ai_assert_same($galleryBefore + 1, (int)sh_val('SELECT COUNT(*) FROM product_images', [], 0),
        'the retried save did not attach its gallery');

    // ------------------------------------------------------ 8. hidden rows
    ai_step('A hidden product is saved but not listed');
    $hidden = $draft;
    $hidden['token'] = bin2hex(random_bytes(16));
    $hidden['name'] = 'Hidden Test Kettle';
    $hidden['slug'] = 'hidden-test-kettle';
    $hidden['sku'] = 'HIDDEN-1';
    $hidden['status'] = 0;
    $hiddenRes = sh_ai_save_full_product($hidden, 1);
    ai_assert(!empty($hiddenRes['ok']), 'a hidden draft could not be saved: ' . (string)($hiddenRes['error'] ?? ''));
    $hiddenId = (int)$hiddenRes['id'];
    $createdProductIds[] = $hiddenId;
    ai_assert_same(0, (int)sh_val('SELECT status FROM products WHERE id = ?', [$hiddenId], 1), 'the hidden status was not stored');
    ai_assert(sh_product_by_slug('hidden-test-kettle') === null, 'a hidden product is visible on the storefront');

    echo "::notice title=AI product save::created #{$id}, tags=" . count($tags)
        . ', gallery=' . count($gallery) . ", rollback=verified\n";
    echo "AI PRODUCT SAVE: ALL CHECKS PASSED\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "::error title=AI product save test::" . $e->getMessage() . "\n";
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    foreach ($tempFiles as $f) { if (is_file($f)) { @unlink($f); } }
    try {
        ai_drop_all($pdo);
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup: ' . $e->getMessage() . "\n");
    }
}
