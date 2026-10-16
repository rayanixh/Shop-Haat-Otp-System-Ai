<?php
/**
 * Installer seeding test.
 *
 * Proves, against a real MySQL/MariaDB schema, the two data-safety promises the
 * setup wizard makes:
 *
 *   1. Demo data OFF  -> the store gets database structure and essential system
 *      settings only: no products, categories, brands, customers, orders,
 *      reviews, coupons, slides, promotions or notifications of sample content.
 *   2. Demo data ON   -> the starter catalogue appears exactly once, and a later
 *      page view, login, upgrade or repeated run cannot duplicate or resurrect it.
 *
 * It also drives the admin "Demo Data Review" cleanup path and proves that an
 * existing database keeps its real products, orders, reviews, coupons and
 * payment records.
 *
 * Run with a test-only config/database.php that points at a disposable MySQL /
 * MariaDB database. The test drops every table it needs at start and leaves the
 * database empty again, so it can run before tests/reporting-integration.php.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/installer-demo-data.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/install/demo-data.php';

function installer_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

function installer_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

/**
 * Mark the section being exercised. The runner exposes these as annotations, so
 * a failure says which phase it happened in without downloading the step log.
 */
$GLOBALS['installer_phase'] = 'start';
function installer_phase(string $name): void
{
    $GLOBALS['installer_phase'] = $name;
    echo '::notice title=Installer seeding test::' . $name . "\n";
}

function installer_count(string $table, string $where = '', array $params = []): int
{
    $sql = 'SELECT COUNT(*) FROM `' . $table . '`' . ($where !== '' ? ' WHERE ' . $where : '');
    return (int)sh_val($sql, $params, 0);
}

/** Empty the disposable database, then build the canonical schema. */
function installer_reset(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (sh_schema_tables() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
}

/** The rows that must never appear unless the operator asked for the catalogue. */
function installer_business_row_total(): int
{
    $tables = ['products', 'categories', 'brands', 'coupons', 'promo_slides', 'reviews', 'orders', 'order_items', 'users'];
    $total = 0;
    foreach ($tables as $t) { $total += installer_count($t); }
    return $total;
}

/**
 * Everything the store does on its own after setup: an upgrade re-runs the DDL,
 * the automatic migrations insert their own settings, the courier and OTP
 * registries ensure their rows, and a page view boots the app.
 */
function installer_simulate_runtime(PDO $pdo): void
{
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_schema_seed($pdo, ['site_name' => 'Runtime Probe', 'admin_email' => 'probe@example.test']);
    sh_seed_essential_starters($pdo);
    sh_courier_seed($pdo);
    sh_otp_seed_settings($pdo);
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('google_login_enabled','0')");
    // A stray call from anywhere in the application must be a no-op too.
    sh_install_demo_data($pdo);
}

$opts = ['site_name' => 'ShopHaat Installer Test', 'site_url' => 'http://localhost', 'admin_email' => 'owner@example.test'];

try {
    $pdo = sh_db();
    foreach (sh_schema_tables() as $table) {
        $exists = (int)sh_val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table], 0);
        if ($exists > 0) {
            installer_assert_same(0, installer_count($table), 'Installer test requires an empty database (' . $table . ')');
        }
    }

    // -----------------------------------------------------------------------
    // 1. Fresh install with demo data OFF
    // -----------------------------------------------------------------------
    installer_phase('1 demo OFF');
    installer_reset($pdo);
    $report = sh_install_seed_all($pdo, $opts, false);
    installer_assert($report['demo'] === false, 'A demo-OFF install must report that no catalogue was installed');
    installer_assert_same(0, installer_business_row_total(), 'A demo-OFF install must not create any business record');

    installer_assert_same('ShopHaat Installer Test', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'site_name'", [], ''), 'Essential settings must still be written');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'contact_phone'", [], 'x'), 'A demo-OFF install must not invent a contact phone number');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'contact_address'", [], 'x'), 'A demo-OFF install must not invent an office address');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'footer_about'", [], 'x'), 'A demo-OFF install must not invent footer marketing copy');
    installer_assert_same('none', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'The declined catalogue must be recorded');

    // The order-taking routes are structure, not sample content.
    installer_assert_same(4, installer_count('payment_methods'), 'The four built-in payment routes must exist');
    installer_assert_same(0, installer_count('payment_methods', "code IN ('bkash','nagad','rocket') AND account_number IS NOT NULL AND account_number <> ''"), 'Manual payment routes must ship without a made-up merchant number');
    installer_assert_same(1, installer_count('payment_methods', "code = 'cod' AND status = 1"), 'Cash on delivery must be usable on a fresh install');
    installer_assert_same(3, installer_count('payment_gateways'), 'The gateway definitions must exist');
    installer_assert_same(0, installer_count('payment_gateways', 'status = 1'), 'Gateway definitions must stay disabled until credentials are supplied');

    // -----------------------------------------------------------------------
    // 2. Demo data must not come back: re-run, refresh, login, upgrade
    // -----------------------------------------------------------------------
    installer_phase('2 no re-insert');
    sh_install_seed_all($pdo, $opts, false);
    sh_install_seed_all($pdo, $opts, false);
    installer_simulate_runtime($pdo);
    installer_simulate_runtime($pdo);
    installer_assert_same(0, installer_business_row_total(), 'Demo content must never be inserted by a refresh, login, migration or repeated setup run');
    installer_assert_same('none', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'A later run must not overwrite the recorded decision');

    // -----------------------------------------------------------------------
    // 3. Fresh install with demo data ON
    // -----------------------------------------------------------------------
    installer_phase('3 demo ON');
    installer_reset($pdo);
    $report = sh_install_seed_all($pdo, $opts, true);
    installer_assert($report['demo'] === true, 'A demo-ON install must report the catalogue');
    $seeded = [
        'products'   => installer_count('products'),
        'categories' => installer_count('categories'),
        'brands'     => installer_count('brands'),
        'coupons'    => installer_count('coupons'),
        'reviews'    => installer_count('reviews'),
        'slider'     => installer_count('promo_slides', "placement = 'slider'"),
        'cards'      => installer_count('promo_slides', "placement = 'card'"),
    ];
    foreach (['products', 'categories', 'brands', 'coupons', 'reviews', 'slider', 'cards'] as $k) {
        installer_assert($seeded[$k] > 0, 'A demo-ON install must create ' . $k);
    }
    installer_assert_same(2, $seeded['slider'], 'Exactly the two starter slides are installed');
    installer_assert_same(3, $seeded['cards'], 'Exactly the three starter feature cards are installed');
    installer_assert_same('installed', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'The completed catalogue must be recorded');
    installer_assert_same(3, installer_count('payment_methods', "code IN ('bkash','nagad','rocket') AND account_number <> ''"), 'A demo store gets demo wallet numbers for its manual routes');

    // Re-runs (including an explicit one) must not duplicate a single row.
    sh_install_demo_data($pdo, true);
    sh_install_demo_data($pdo, false);
    installer_simulate_runtime($pdo);
    $after = [
        'products'   => installer_count('products'),
        'categories' => installer_count('categories'),
        'brands'     => installer_count('brands'),
        'coupons'    => installer_count('coupons'),
        'reviews'    => installer_count('reviews'),
        'promos'     => installer_count('promo_slides'),
    ];
    installer_assert_same($seeded['products'], $after['products'], 'A repeated run must not duplicate products');
    installer_assert_same($seeded['categories'], $after['categories'], 'A repeated run must not duplicate categories');
    installer_assert_same($seeded['brands'], $after['brands'], 'A repeated run must not duplicate brands');
    installer_assert_same($seeded['coupons'], $after['coupons'], 'A repeated run must not duplicate coupons');
    installer_assert_same($seeded['reviews'], $after['reviews'], 'A repeated run must not duplicate reviews');
    installer_assert_same($seeded['slider'] + $seeded['cards'], $after['promos'], 'A repeated run must not duplicate slides');

    // -----------------------------------------------------------------------
    // 4. Real business records must survive the cleanup untouched
    // -----------------------------------------------------------------------
    installer_phase('4 real records');
    $realCategory = sh_insert('categories', ['name' => 'Owner Category', 'slug' => 'owner-category', 'status' => 1]);
    $realBrand    = sh_insert('brands', ['name' => 'Owner Brand', 'slug' => 'owner-brand', 'status' => 1]);
    $realProduct  = sh_insert('products', [
        'category_id' => $realCategory, 'brand_id' => $realBrand, 'name' => 'Owner Real Product',
        'slug' => 'owner-real-product', 'sku' => 'OWN-1', 'price' => 1500.00, 'stock' => 5,
        'product_type' => 'physical', 'status' => 1,
    ]);
    $sampleProductIds = array_column(sh_demo_inventory($pdo)['products'], 'id');
    installer_assert(count($sampleProductIds) === $seeded['products'], 'The review screen must recognise every seeded product');
    $orderedSample = (int)$sampleProductIds[0];

    // A visitor review on a sample product: it must never be destroyed.
    $visitorReview = sh_insert('reviews', [
        'product_id' => $orderedSample, 'user_id' => null, 'author_name' => 'Real Visitor',
        'rating' => 4, 'title' => 'My own words', 'body' => 'Written by a real customer after delivery.',
        'status' => 'approved',
    ]);

    $userId = sh_insert('users', [
        'name' => 'Real Customer', 'email' => 'real@example.test', 'phone' => '01700000007',
        'password_hash' => password_hash('not-used-by-test', PASSWORD_DEFAULT), 'status' => 'active',
    ]);
    $orderId = sh_insert('orders', [
        'order_number' => 'SH-REAL-1', 'user_id' => $userId, 'customer_name' => 'Real Customer',
        'customer_phone' => '01700000007', 'customer_email' => 'real@example.test',
        'shipping_address' => '1 Real Street', 'shipping_city' => 'Dhaka', 'coupon_code' => 'WELCOME10',
        'subtotal' => 100.00, 'discount' => 0.00, 'delivery_fee' => 60.00, 'total' => 160.00,
        'status' => 'pending', 'payment_status' => 'unpaid',
    ]);
    sh_insert('order_items', [
        'order_id' => $orderId, 'product_id' => $orderedSample, 'product_name' => 'Sample Product Sold',
        'unit_price' => 100.00, 'quantity' => 1, 'line_total' => 100.00, 'product_type' => 'physical',
    ]);

    $inv = sh_demo_inventory($pdo);
    installer_assert_same(1, count(array_filter($inv['products'], static fn($p) => (int)$p['id'] === $orderedSample && $p['order_lines'] === 1)), 'The review screen must flag a sample product that appears in an order');
    installer_assert_same(1, count(array_filter($inv['products'], static fn($p) => (int)$p['id'] === $orderedSample && ($p['foreign_reviews'] ?? 0) === 1)), 'The review screen must flag a real review left on a sample product');

    // -----------------------------------------------------------------------
    // 5. Cleanup
    // -----------------------------------------------------------------------
    installer_phase('5 cleanup');
    $result = sh_demo_cleanup($pdo);

    installer_assert($result['products_deleted'] > 0, 'Sample products without order history must be deleted');
    installer_assert($result['products_deactivated'] >= 1, 'A sample product in an order must be deactivated, not deleted');
    installer_assert_same($seeded['slider'] + $seeded['cards'], $result['slides_deleted'], 'Every installed slide and card must be removed');
    installer_assert($result['coupons_deleted'] >= 1, 'Unused sample coupons must be deleted');
    installer_assert($result['coupons_disabled'] >= 1, 'A sample coupon used on an order must be disabled, not deleted');
    installer_assert_same('cleaned', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'The cleanup must record itself');

    // Real records: still there, still complete.
    installer_assert_same(1, installer_count('products', 'id = ?', [$realProduct]), 'A real product must survive the cleanup');
    installer_assert_same(1, installer_count('categories', 'id = ?', [$realCategory]), 'A real category must survive the cleanup');
    installer_assert_same(1, installer_count('brands', 'id = ?', [$realBrand]), 'A real brand must survive the cleanup');
    installer_assert_same(1, installer_count('users', 'id = ?', [$userId]), 'A real customer must survive the cleanup');
    installer_assert_same(1, installer_count('orders', 'id = ?', [$orderId]), 'A real order must survive the cleanup');
    installer_assert_same(1, installer_count('order_items', 'order_id = ?', [$orderId]), 'Order lines must survive the cleanup');
    installer_assert_same(1, installer_count('reviews', 'id = ?', [$visitorReview]), 'A real customer review must survive the cleanup');
    installer_assert_same(0, installer_count('products', 'id = ? AND status = 1', [$orderedSample]), 'A sample product kept for its order history or its visitor review must be deactivated');
    installer_assert_same(2, installer_count('products'), 'Only the real product and the one kept sample product may remain');
    // Everything that is not still listed as sample content must have been
    // removed: the two counts have to add up to the seeded total.
    $remainingSamples = count(sh_demo_inventory($pdo)['products']);
    installer_assert_same(1, $remainingSamples, 'exactly one sample product must be kept for its order history and visitor review');
    installer_assert_same(
        (int)$seeded['products'] - $remainingSamples,
        $result['products_deleted'] + $result['products_deactivated'],
        'Every other sample product must have been removed'
    );
    installer_assert_same(0, installer_count('promo_slides'), 'No sample slide or card may survive the cleanup');
    installer_assert_same(2, installer_count('categories'), 'Only the owner category and the kept sample category may remain');
    installer_assert_same(1, installer_count('payment_methods', "code = 'cod' AND status = 1"), 'The cleanup must not touch payment routes');

    // -----------------------------------------------------------------------
    // 6. After the cleanup, nothing puts sample content back
    // -----------------------------------------------------------------------
    installer_phase('6 no restore');
    $before = installer_count('products');
    sh_install_demo_data($pdo);
    sh_install_seed_all($pdo, $opts, false);
    installer_simulate_runtime($pdo);
    installer_assert_same($before, installer_count('products'), 'Sample content must not be restored after it was removed');
    installer_assert_same(0, installer_count('promo_slides'), 'Removed slides must not come back');

    // -----------------------------------------------------------------------
    // 7. Leave the disposable database exactly as it was found
    // -----------------------------------------------------------------------
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (sh_schema_tables() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    echo "Installer demo-data test passed\n";
} catch (Throwable $e) {
    // The runner exposes these as annotations: the message has to be on one line
    // and % escaped, because the workflow command parser treats it as an escape.
    $detail = str_replace(["\r", "\n", '%'], [' ', ' | ', '%25'], $e->getMessage());
    $detail = str_replace(['"', '::'], ["'", ':'], $detail);
    echo '::error title=Installer seeding test (' . ($GLOBALS['installer_phase'] ?? 'unknown phase') . ')::' . $detail . "\n";
    fwrite(STDERR, 'Installer demo-data test failed: ' . $e->getMessage() . "\n");
    exit(1);
}
