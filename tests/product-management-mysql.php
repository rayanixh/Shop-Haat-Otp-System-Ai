<?php
/**
 * Product-management checks against the disposable CI MySQL database.
 *
 * This is the only product suite that touches real MySQL/InnoDB. It recreates
 * the canonical installer schema, verifies the fresh-install DDL and starters,
 * then removes the additive pieces to reproduce a real pre-upgrade store. It
 * calls the shipped migration and proves P01/P02/P10/P15 against MySQL:
 * additive tables/columns, the dictionary starters, the variant unique key,
 * two colour lines for one product, and the legacy products.image snapshot.
 * It drops every table again so tests/reporting-integration.php still starts
 * from the empty database it requires. Fixture data is not production data.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/product-management-mysql.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/product-schema.php';

$checks = 0;
$failures = [];
$summary = '';
function pmm_ok(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) { $failures[] = $message; }
}
function pmm_same($expected, $actual, string $message): void
{
    pmm_ok($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function pmm_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
function pmm_index(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $st->execute([$table, $index]);
    return (int)$st->fetchColumn() > 0;
}
function pmm_constraint(PDO $pdo, string $table, string $constraint): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE() AND table_name = ?
          AND constraint_name = ? AND constraint_type = 'FOREIGN KEY'");
    $st->execute([$table, $constraint]);
    return (int)$st->fetchColumn() > 0;
}
function pmm_table(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}
function pmm_drop_all(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (sh_schema_tables() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

try {
    $pdo = sh_db();
    pmm_drop_all($pdo);
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_schema_seed($pdo, [
        'site_name' => 'ShopHaat Product MySQL Test',
        'site_url' => 'http://localhost',
        'admin_email' => 'product@example.test',
    ]);
    sh_seed_essential_starters($pdo);

    // -----------------------------------------------------------------------
    // P02: the installer's canonical fresh schema already carries the upgrade.
    // -----------------------------------------------------------------------
    $ddl = implode("\n", sh_schema_sql());
    foreach ([
        'CREATE TABLE IF NOT EXISTS product_images',
        'CREATE TABLE IF NOT EXISTS product_types',
        'CREATE TABLE IF NOT EXISTS product_colours',
        'CREATE TABLE IF NOT EXISTS product_colour_selections',
        'catalog_type_id INT UNSIGNED DEFAULT NULL',
        'variant VARCHAR(190) NOT NULL DEFAULT',
        'UNIQUE KEY uq_cart_product_variant (cart_id, product_id, variant)',
        'KEY idx_products_catalog_type (catalog_type_id)',
        'CONSTRAINT fk_products_catalog_type FOREIGN KEY',
    ] as $needle) {
        pmm_ok(str_contains($ddl, $needle), 'canonical installer DDL contains ' . $needle);
    }
    foreach (['product_images', 'product_types', 'product_colours', 'product_colour_selections'] as $name) {
        pmm_ok(pmm_table($pdo, $name), 'fresh MySQL schema table ' . $name . ' exists');
    }
    pmm_ok(pmm_column($pdo, 'products', 'catalog_type_id'), 'fresh MySQL products.catalog_type_id exists');
    pmm_ok(pmm_column($pdo, 'cart_items', 'variant'), 'fresh MySQL cart_items.variant exists');
    pmm_ok(pmm_index($pdo, 'cart_items', 'uq_cart_product_variant'), 'fresh MySQL variant unique key exists');
    pmm_ok(pmm_constraint($pdo, 'products', 'fk_products_catalog_type'), 'fresh MySQL catalog-type FK exists');
    pmm_same(8, (int)sh_val('SELECT COUNT(*) FROM product_types', [], 0), 'fresh MySQL schema seeds eight product types');
    pmm_same(15, (int)sh_val('SELECT COUNT(*) FROM product_colours', [], 0), 'fresh MySQL schema seeds fifteen colour swatches');
    pmm_same(0, (int)sh_val('SELECT COUNT(*) FROM product_colour_selections', [], 0), 'fresh MySQL schema assigns no product colours');

    // -----------------------------------------------------------------------
    // P01: remove every additive piece, leaving one legacy product and cart row,
    // then run the same upgrade an existing store runs on its next request.
    // -----------------------------------------------------------------------
    $suffix = bin2hex(random_bytes(5));
    $legacyImage = 'pm-primary-' . $suffix . '.png';
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    // Drop the child constraint/column before its dictionary parent so the
    // legacy simulation never leaves an FK pointing at a dropped table.
    $pdo->exec('ALTER TABLE products DROP FOREIGN KEY fk_products_catalog_type');
    $pdo->exec('ALTER TABLE products DROP INDEX idx_products_catalog_type');
    $pdo->exec('ALTER TABLE products DROP COLUMN catalog_type_id');
    $pdo->exec('DROP TABLE IF EXISTS product_colour_selections');
    $pdo->exec('DROP TABLE IF EXISTS product_images');
    $pdo->exec('DROP TABLE IF EXISTS product_types');
    $pdo->exec('DROP TABLE IF EXISTS product_colours');
    $pdo->exec('ALTER TABLE cart_items DROP INDEX uq_cart_product_variant');
    $pdo->exec('ALTER TABLE cart_items DROP COLUMN variant');
    $pdo->exec('ALTER TABLE cart_items ADD UNIQUE KEY uq_cart_product (cart_id, product_id)');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    sh_query("DELETE FROM settings WHERE setting_key IN ('product_schema_v','product_catalog_schema_v')");
    sh_settings(true); // the cached settings read must not see the deleted versions

    $productId = (int)sh_insert('products', [
        'name' => 'PM CI Legacy ' . $suffix,
        'slug' => 'pm-ci-legacy-' . $suffix,
        'image' => $legacyImage,
        'price' => 10,
        'stock' => 5,
        'status' => 1,
        'product_type' => 'physical',
    ]);
    $cartId = (int)sh_insert('cart', ['session_token' => $suffix]);
    sh_query('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)', [$cartId, $productId, 1]);
    pmm_ok(!pmm_table($pdo, 'product_images'), 'legacy simulation removed the gallery table');
    pmm_ok(!pmm_column($pdo, 'cart_items', 'variant'), 'legacy simulation removed cart_items.variant');
    pmm_ok(pmm_index($pdo, 'cart_items', 'uq_cart_product'), 'legacy simulation restored the product-only unique key');

    sh_product_schema_ensure(); // also calls sh_product_catalog_schema_ensure()

    pmm_ok(pmm_column($pdo, 'products', 'catalog_type_id'), 'additive MySQL migration restored products.catalog_type_id');
    pmm_ok(pmm_column($pdo, 'cart_items', 'variant'), 'additive MySQL migration restored cart_items.variant');
    pmm_ok(pmm_index($pdo, 'cart_items', 'uq_cart_product_variant'), 'additive MySQL migration restored the variant unique key');
    pmm_ok(!pmm_index($pdo, 'cart_items', 'uq_cart_product'), 'additive MySQL migration dropped the product-only unique key');
    pmm_ok(pmm_index($pdo, 'products', 'idx_products_catalog_type'), 'additive MySQL migration restored the catalog-type index');
    pmm_ok(pmm_constraint($pdo, 'products', 'fk_products_catalog_type'), 'additive MySQL migration restored the catalog-type FK');
    pmm_same('2', sh_setting('product_catalog_schema_v', '0'), 'catalog migration version was persisted in MySQL');
    foreach (['product_images', 'product_types', 'product_colours', 'product_colour_selections'] as $name) {
        pmm_ok(pmm_table($pdo, $name), 'additive MySQL migration created ' . $name);
    }
    pmm_same(8, (int)sh_val('SELECT COUNT(*) FROM product_types', [], 0), 'additive MySQL migration seeds eight product types');
    pmm_same(15, (int)sh_val('SELECT COUNT(*) FROM product_colours', [], 0), 'additive MySQL migration seeds fifteen colour swatches');
    pmm_same('', (string)sh_val('SELECT variant FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId], 'missing'), 'legacy cart row keeps an empty variant after migration');
    pmm_same($legacyImage, (string)sh_val('SELECT image FROM products WHERE id = ?', [$productId], ''), 'legacy products.image primary snapshot is unchanged');
    pmm_same(1, (int)sh_val('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$productId], 0), 'legacy primary image was back-filled as one gallery row');

    // -----------------------------------------------------------------------
    // P10: two selected colours can share one product in the same MySQL cart.
    // -----------------------------------------------------------------------
    sh_query('INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)', [$cartId, $productId, 'Colour: Black', 1]);
    sh_query('INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)', [$cartId, $productId, 'Colour: Red', 2]);
    pmm_same(3, (int)sh_val('SELECT COUNT(*) FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId], 0), 'MySQL allows one legacy line plus one line per selected colour');
    $duplicateRejected = false;
    try {
        sh_query('INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)', [$cartId, $productId, 'Colour: Black', 3]);
    } catch (Throwable $e) {
        $duplicateRejected = true;
    }
    pmm_ok($duplicateRejected, 'MySQL unique key rejects a duplicate colour line');

    // P15: the relational gallery and the legacy primary snapshot stay coherent.
    sh_query('INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)', [$productId, 'pm-second-' . $suffix . '.png', 1]);
    $rows = sh_product_gallery_rows($productId, $legacyImage, false);
    pmm_same(2, count($rows), 'MySQL gallery returns the back-filled primary plus a second image');
    pmm_same($legacyImage, $rows[0]['file'] ?? '', 'MySQL gallery keeps the legacy primary first');
    pmm_same($legacyImage, (string)sh_val('SELECT image FROM products WHERE id = ?', [$productId], ''), 'products.image still holds the primary snapshot after gallery edits');

    $summary = 'Product management MySQL: ' . $checks . ' checks, ' . count($failures) . ' failures';
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
    $summary = 'Product management MySQL ERROR: ' . $e->getMessage();
} finally {
    // reporting-integration.php requires a genuinely empty disposable database.
    try {
        if (isset($pdo) && $pdo instanceof PDO) { pmm_drop_all($pdo); }
    } catch (Throwable $e) {
        $failures[] = 'cleanup failed: ' . $e->getMessage();
    }
}

if ($summary === '') { $summary = 'Product management MySQL: ' . $checks . ' checks, ' . count($failures) . ' failures'; }
echo $summary . "\n";
foreach ($failures as $failure) { echo 'FAIL: ' . $failure . "\n"; }
exit($failures === [] ? 0 : 1);
