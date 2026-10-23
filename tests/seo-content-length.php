<?php
/**
 * SEO copy length — real MySQL/MariaDB proof.
 *
 * The admin editors used to cap the SEO title at 190 characters and the meta
 * description at 300, both in the form and in the save handler, and the columns
 * themselves were VARCHAR(190)/VARCHAR(300). This test proves the replacement:
 *
 *   1. a legacy install with those VARCHAR columns keeps every stored value
 *      when the upgrade runs;
 *   2. the columns become TEXT, so a longer value is stored intact;
 *   3. writing a title longer than 60 characters and a description longer than
 *      160 characters through the application's own data layer reads back
 *      byte-for-byte (products, categories and seo_pages);
 *   4. repeating the migration changes nothing;
 *   5. a fresh install creates products.meta_title / meta_description as TEXT.
 *
 * No provider, no browser and no network access is needed.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/seo-content-length.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/seo.php';

$passed = 0;
$failed = 0;
$failures = [];

function seo_assert(bool $condition, string $message): void
{
    global $passed, $failed, $failures;
    if ($condition) { $passed++; return; }
    $failed++;
    $failures[] = $message;
    echo "  FAIL  $message\n";
}

function seo_same($expected, $actual, string $message): void
{
    seo_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ')');
}

function seo_type(PDO $pdo, string $table, string $column): string
{
    return strtolower((string)sh_val(
        'SELECT DATA_TYPE FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$table, $column], ''
    ));
}

function seo_step(string $name): void
{
    echo "\n== $name\n";
}

$pdo = sh_db();

// --- A clean catalogue schema --------------------------------------------
seo_step('simulated install');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['products', 'product_images', 'product_tags', 'categories', 'brands', 'seo_pages'] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

foreach (sh_schema_sql() as $ddl) {
    // Every statement is CREATE TABLE IF NOT EXISTS; running the full schema
    // keeps the foreign keys of products -> categories/brands intact.
    $pdo->exec($ddl);
}

// The SEO module owns the SEO columns and the seo_pages table.
sh_setting_save('seo_schema_v', '1');
sh_seo_schema_ensure();
seo_same('2', (string)sh_setting('seo_schema_v', ''), 'the SEO schema reports version 2 after the upgrade');
seo_assert(sh_table_exists('seo_pages'), 'the SEO override table exists');
if (!sh_table_exists('seo_pages')) {
    echo "FAIL  seo_pages could not be created, so the migration cannot be tested\n";
    exit(1);
}

// Turn the columns back into the previous release's fixed widths.
$pdo->exec('ALTER TABLE products MODIFY meta_title VARCHAR(190) NULL, MODIFY meta_description VARCHAR(300) NULL');
$pdo->exec('ALTER TABLE categories MODIFY meta_title VARCHAR(190) NULL, MODIFY meta_description VARCHAR(300) NULL');
$pdo->exec('ALTER TABLE seo_pages MODIFY meta_title VARCHAR(190) NULL, MODIFY meta_description VARCHAR(300) NULL');
seo_assert(seo_type($pdo, 'products', 'meta_title') === 'varchar', 'the simulated install starts with VARCHAR');

$catId = (int)sh_insert('categories', ['name' => 'Kettles', 'slug' => 'kettles', 'status' => 1]);
$productId = (int)sh_insert('products', [
    'category_id' => $catId, 'name' => 'Legacy Kettle', 'slug' => 'legacy-kettle',
    'price' => 100, 'compare_price' => 0, 'stock' => 1,
]);
sh_query('UPDATE products SET meta_title = ?, meta_description = ? WHERE id = ?',
    [str_repeat('C', 190), str_repeat('D', 300), $productId]);
sh_query('UPDATE categories SET meta_title = ?, meta_description = ? WHERE id = ?',
    [str_repeat('A', 190), str_repeat('B', 300), $catId]);
sh_query('INSERT INTO seo_pages (page_key, meta_title, meta_description) VALUES (?,?,?)',
    ['catalogue', str_repeat('E', 190), str_repeat('F', 300)]);

// --- Upgrade --------------------------------------------------------------
seo_step('widening migration');
$changed = sh_seo_widen_text_columns();
seo_assert($changed >= 6, 'the migration widened products/categories/seo_pages (changed ' . $changed . ')');

foreach ([
    'products'   => ['meta_title', 'meta_description'],
    'categories' => ['meta_title', 'meta_description'],
    'seo_pages'  => ['meta_title', 'meta_description'],
] as $table => $columns) {
    foreach ($columns as $column) {
        seo_same('text', seo_type($pdo, $table, $column), $table . '.' . $column . ' is TEXT after the upgrade');
    }
}

$legacyProduct = sh_one('SELECT meta_title, meta_description FROM products WHERE id = ?', [$productId]);
seo_assert($legacyProduct !== null, 'the legacy product row is readable');
seo_same(190, mb_strlen((string)$legacyProduct['meta_title']), 'the legacy product title was preserved');
seo_same(300, mb_strlen((string)$legacyProduct['meta_description']), 'the legacy product description was preserved');
$legacyCat = sh_one('SELECT meta_title FROM categories WHERE id = ?', [$catId]);
seo_same(190, mb_strlen((string)$legacyCat['meta_title']), 'the legacy category title was preserved');
$legacyPage = sh_one('SELECT meta_description FROM seo_pages WHERE page_key = ?', ['catalogue']);
seo_same(300, mb_strlen((string)$legacyPage['meta_description']), 'the legacy page description was preserved');

// Running it again changes nothing: the columns are already text.
seo_same(0, sh_seo_widen_text_columns(), 'a repeated migration is a no-op');

// --- What the admin form writes now --------------------------------------
seo_step('long values survive the data layer');
$longTitle = 'Stainless Steel Electric Kettle 1.8L — ShopHaat Kitchen Collection';
$longDesc  = str_repeat('A 1.8 litre stainless steel kettle with a cool-touch handle and a concealed element. ', 6);
$longDesc  = mb_substr($longDesc, 0, 420);
seo_assert(mb_strlen($longTitle) > 60, 'the title used for the test is longer than 60 characters');
seo_assert(mb_strlen($longDesc) > 160, 'the description used for the test is longer than 160 characters');

sh_query('UPDATE products SET meta_title = ?, meta_description = ? WHERE id = ?', [$longTitle, $longDesc, $productId]);
sh_query('UPDATE categories SET meta_title = ?, meta_description = ? WHERE id = ?', [$longTitle, $longDesc, $catId]);
sh_query('UPDATE seo_pages SET meta_title = ?, meta_description = ? WHERE page_key = ?', [$longTitle, $longDesc, 'catalogue']);

$storedProduct = sh_one('SELECT meta_title, meta_description FROM products WHERE id = ?', [$productId]);
seo_same($longTitle, (string)$storedProduct['meta_title'], 'the product SEO title is stored without truncation');
seo_same($longDesc, (string)$storedProduct['meta_description'], 'the product meta description is stored without truncation');
$storedCat = sh_one('SELECT meta_title, meta_description FROM categories WHERE id = ?', [$catId]);
seo_same($longTitle, (string)$storedCat['meta_title'], 'the category SEO title is stored without truncation');
seo_same($longDesc, (string)$storedCat['meta_description'], 'the category meta description is stored without truncation');
$storedPage = sh_one('SELECT meta_title, meta_description FROM seo_pages WHERE page_key = ?', ['catalogue']);
seo_same($longTitle, (string)$storedPage['meta_title'], 'the page SEO title is stored without truncation');
seo_same($longDesc, (string)$storedPage['meta_description'], 'the page meta description is stored without truncation');

// --- A fresh install already uses TEXT -----------------------------------
seo_step('fresh install DDL');
$productDdl = '';
foreach (sh_schema_sql() as $ddl) {
    if (str_contains($ddl, 'CREATE TABLE IF NOT EXISTS products (')) { $productDdl = $ddl; break; }
}
seo_assert(str_contains($productDdl, 'meta_title TEXT NULL'), 'a new products table stores meta_title as TEXT');
seo_assert(str_contains($productDdl, 'meta_description TEXT NULL'), 'a new products table stores meta_description as TEXT');
seo_assert(!str_contains($productDdl, 'meta_title VARCHAR'), 'a new products table no longer caps meta_title');

// --- Report --------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("SEO content length: passed %d   failed %d\n", $passed, $failed);
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo '  - ' . $f . "\n"; }
}
exit($failed === 0 ? 0 : 1);
