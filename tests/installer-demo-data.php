<?php
/**
 * Installer test.
 *
 * Proves against a real MySQL/MariaDB schema, through the same functions the
 * setup form calls (sh_install_create_store() and sh_install_seed_all()), that:
 *
 *   1. Demo data OFF creates the database structure, the payment routes, the
 *      essential settings and the administrator account — and nothing else. No
 *      products, categories, brands, customers, orders, reviews, coupons,
 *      slides, promotions or courier accounts.
 *   2. Demo data ON adds only the bundled starter catalogue, exactly once, and a
 *      later page view, login, upgrade or repeated run cannot duplicate it.
 *   3. A repeated install cannot create a second administrator or overwrite an
 *      existing password, and neither choice ever deletes real business records.
 *   4. The administrator password is accepted on the real policy, hashed with
 *      password_hash(), verified with the exact bytes typed (a trailing space is
 *      not silently dropped), and the confirmation field compares exact bytes.
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
require_once SH_ROOT . '/includes/courier.php';

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
    $tables = ['products', 'categories', 'brands', 'coupons', 'promo_slides', 'reviews',
               'orders', 'order_items', 'users', 'couriers'];
    $total = 0;
    foreach ($tables as $t) { $total += installer_count($t); }
    return $total;
}

/**
 * Everything the store does on its own after setup: an upgrade re-runs the DDL,
 * the automatic migrations insert their own settings, and a page view boots the
 * app. None of it may insert business content.
 */
function installer_simulate_runtime(PDO $pdo): void
{
    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_schema_seed($pdo, ['site_name' => 'Runtime Probe', 'admin_email' => 'probe@example.test']);
    sh_seed_essential_starters($pdo);
    sh_otp_seed_settings($pdo);
    sh_courier_schema_ensure();
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('google_login_enabled','0')");
    // A stray call from anywhere in the application must be a no-op too.
    sh_install_demo_data($pdo);
}

/**
 * Install exactly the way install/index.php does.
 *
 * @param array<string,string> $overrides
 */
function installer_install(PDO $pdo, array $overrides = [], bool $ownerRecovery = false): array
{
    $form = array_merge([
        'site_name'      => 'ShopHaat Installer Test',
        'admin_email'    => 'owner@example.test',
        'admin_password' => 'Passphrase-Only-42',
        'demo_data'      => '0',
    ], $overrides);
    return sh_install_create_store($pdo, $form, '', 'http://localhost', $ownerRecovery);
}

function installer_admin_hash(string $email): string
{
    return (string)sh_val('SELECT password_hash FROM admins WHERE email = ? LIMIT 1', [$email], '');
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
    $offPassword = 'Passphrase-Only-42';
    $report = installer_install($pdo);
    installer_assert($report['demo'] === false, 'A demo-OFF install must report that no catalogue was installed');
    installer_assert_same(0, installer_business_row_total(), 'A demo-OFF install must not create any business record');
    installer_assert_same(0, installer_count('couriers'), 'A demo-OFF install must not register courier accounts');

    installer_assert_same('ShopHaat Installer Test', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'site_name'", [], ''), 'Essential settings must still be written');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'contact_phone'", [], 'x'), 'A demo-OFF install must not invent a contact phone number');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'contact_address'", [], 'x'), 'A demo-OFF install must not invent an office address');
    installer_assert_same('', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'footer_about'", [], 'x'), 'A demo-OFF install must not invent footer marketing copy');
    installer_assert_same('none', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'The declined catalogue must be recorded');
    installer_assert_same('none', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'courier_registry_state'", [], ''), 'The empty courier registry must be recorded');

    // The order-taking routes are structure, not sample content.
    installer_assert_same(4, installer_count('payment_methods'), 'The four built-in payment routes must exist');
    installer_assert_same(0, installer_count('payment_methods', "code IN ('bkash','nagad','rocket') AND account_number IS NOT NULL AND account_number <> ''"), 'Manual payment routes must ship without a made-up merchant number');
    installer_assert_same(1, installer_count('payment_methods', "code = 'cod' AND status = 1"), 'Cash on delivery must be usable on a fresh install');
    installer_assert_same(3, installer_count('payment_gateways'), 'The gateway definitions must exist');
    installer_assert_same(0, installer_count('payment_gateways', 'status = 1'), 'Gateway definitions must stay disabled until credentials are supplied');

    // The administrator: exactly one row, stored only as a hash.
    installer_assert_same(1, installer_count('admins'), 'A fresh install must create exactly one administrator');
    $offHash = installer_admin_hash('owner@example.test');
    installer_assert($offHash !== '' && $offHash !== $offPassword, 'The administrator password must never be stored in plain text');
    installer_assert(str_starts_with($offHash, '$2y$'), 'The administrator password must be a password_hash() bcrypt hash');
    installer_assert(password_verify($offPassword, $offHash), 'The chosen administrator password must verify');

    // -----------------------------------------------------------------------
    // 2. Demo data must not come back, and a repeated install must not reset
    //    the administrator.
    // -----------------------------------------------------------------------
    installer_phase('2 no re-insert');
    $secondInstallRefused = false;
    try {
        installer_install($pdo, ['admin_email' => 'intruder@example.test', 'admin_password' => 'Second-Install-Pass-1', 'demo_data' => '1']);
    } catch (Throwable $e) {
        $secondInstallRefused = true;
    }
    installer_assert($secondInstallRefused, 'A second install must be refused while an administrator exists');
    installer_assert_same(1, installer_count('admins'), 'A repeated install must not create a second administrator');
    installer_assert(password_verify($offPassword, installer_admin_hash('owner@example.test')), 'A repeated install must not overwrite the existing password');

    sh_install_seed_all($pdo, $opts, false);
    sh_install_seed_all($pdo, $opts, false);
    installer_simulate_runtime($pdo);
    installer_simulate_runtime($pdo);
    installer_assert_same(0, installer_business_row_total(), 'Demo content must never be inserted by a refresh, login, migration or repeated setup run');
    installer_assert_same('none', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'A later run must not overwrite the recorded decision');

    // 2b. The owner unlock is the one authorised recovery: it resets the one
    //     matching account and changes nothing else.
    installer_phase('2b owner recovery');
    $recoveryPassword = 'Owner-Reset-Password-9';
    $recovered = installer_install($pdo, ['admin_password' => $recoveryPassword, 'demo_data' => '1'], true);
    installer_assert(!empty($recovered['recovered']), 'The owner unlock path must report a recovery');
    installer_assert_same(1, installer_count('admins'), 'Owner recovery must not create a second administrator');
    installer_assert(password_verify($recoveryPassword, installer_admin_hash('owner@example.test')), 'Owner recovery must set the new password');
    installer_assert_same(0, installer_business_row_total(), 'Owner recovery must not seed sample content into a live store');
    $wrongEmailRefused = false;
    try {
        installer_install($pdo, ['admin_email' => 'nobody@example.test', 'admin_password' => 'Another-Password-77'], true);
    } catch (Throwable $e) {
        $wrongEmailRefused = true;
    }
    installer_assert($wrongEmailRefused, 'Owner recovery must refuse an address no administrator uses');

    // -----------------------------------------------------------------------
    // 3. Fresh install with demo data ON
    // -----------------------------------------------------------------------
    installer_phase('3 demo ON');
    installer_reset($pdo);
    $onPassword = 'CorrectHorse 2024 ';
    $report = installer_install($pdo, ['admin_password' => $onPassword, 'demo_data' => '1']);
    installer_assert($report['demo'] === true, 'A demo-ON install must report the catalogue');
    $onHash = installer_admin_hash('owner@example.test');
    installer_assert(password_verify($onPassword, $onHash), 'The exact password typed (including a trailing space) must verify');
    installer_assert(!password_verify(rtrim($onPassword), $onHash), 'A silently trimmed password must not verify');

    $seeded = [
        'products'   => installer_count('products'),
        'categories' => installer_count('categories'),
        'brands'     => installer_count('brands'),
        'coupons'    => installer_count('coupons'),
        'reviews'    => installer_count('reviews'),
        'slider'     => installer_count('promo_slides', "placement = 'slider'"),
        'cards'      => installer_count('promo_slides', "placement = 'card'"),
    ];
    foreach (['products', 'categories', 'coupons', 'reviews', 'slider', 'cards'] as $k) {
        installer_assert($seeded[$k] > 0, 'A demo-ON install must create ' . $k);
    }
    // The store has no brand system: the demo catalogue must not seed brands.
    installer_assert_same(0, $seeded['brands'], 'A demo-ON install still seeds the retired brands');
    installer_assert_same(2, $seeded['slider'], 'Exactly the two starter slides are installed');
    installer_assert_same(3, $seeded['cards'], 'Exactly the three starter feature cards are installed');
    installer_assert_same('installed', (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'demo_data_state'", [], ''), 'The completed catalogue must be recorded');
    installer_assert_same(3, installer_count('payment_methods', "code IN ('bkash','nagad','rocket') AND account_number <> ''"), 'A demo store gets demo wallet numbers for its manual routes');
    installer_assert_same(7, installer_count('couriers'), 'A demo store gets the pre-registered courier accounts');
    installer_assert_same(0, installer_count('couriers', 'status = 1'), 'Courier accounts must start disabled until real credentials are entered');

    // Re-runs (including an explicit one) must not duplicate a single row.
    sh_install_demo_data($pdo, true);
    sh_install_demo_data($pdo, false);
    sh_install_seed_all($pdo, $opts, true);
    installer_simulate_runtime($pdo);
    $after = [
        'products'   => installer_count('products'),
        'categories' => installer_count('categories'),
        'brands'     => installer_count('brands'),
        'coupons'    => installer_count('coupons'),
        'reviews'    => installer_count('reviews'),
        'promos'     => installer_count('promo_slides'),
        'couriers'   => installer_count('couriers'),
    ];
    installer_assert_same($seeded['products'], $after['products'], 'A repeated run must not duplicate products');
    installer_assert_same($seeded['categories'], $after['categories'], 'A repeated run must not duplicate categories');
    installer_assert_same($seeded['brands'], $after['brands'], 'A repeated run must not create brands');
    installer_assert_same($seeded['coupons'], $after['coupons'], 'A repeated run must not duplicate coupons');
    installer_assert_same($seeded['reviews'], $after['reviews'], 'A repeated run must not duplicate reviews');
    installer_assert_same($seeded['slider'] + $seeded['cards'], $after['promos'], 'A repeated run must not duplicate slides');
    installer_assert_same(7, $after['couriers'], 'A repeated run must not duplicate courier accounts');

    // -----------------------------------------------------------------------
    // 4. Existing real records are never deleted, whichever choice is replayed
    // -----------------------------------------------------------------------
    installer_phase('4 real records');
    $realCategory = sh_insert('categories', ['name' => 'Owner Category', 'slug' => 'owner-category', 'status' => 1]);
    $realBrand    = sh_insert('brands', ['name' => 'Owner Brand', 'slug' => 'owner-brand', 'status' => 1]);
    $realProduct  = sh_insert('products', [
        'category_id' => $realCategory, 'brand_id' => $realBrand, 'name' => 'Owner Real Product',
        'slug' => 'owner-real-product', 'sku' => 'OWN-1', 'price' => 1500.00, 'stock' => 5,
        'product_type' => 'physical', 'status' => 1,
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
        'order_id' => $orderId, 'product_id' => $realProduct, 'product_name' => 'Owner Real Product',
        'unit_price' => 100.00, 'quantity' => 1, 'line_total' => 100.00, 'product_type' => 'physical',
    ]);
    $visitorReview = sh_insert('reviews', [
        'product_id' => $realProduct, 'user_id' => $userId, 'author_name' => 'Real Visitor',
        'rating' => 4, 'title' => 'My own words', 'body' => 'Written by a real customer after delivery.',
        'status' => 'approved',
    ]);

    $productsBefore = installer_count('products');
    $couponsBefore  = installer_count('coupons');
    $slidesBefore   = installer_count('promo_slides');
    $couriersBefore = installer_count('couriers');
    $adminsBefore   = installer_count('admins');

    // The paths an existing store actually runs: the seed decision replayed with
    // demo OFF, and the app booting.
    sh_install_seed_all($pdo, $opts, false);
    sh_install_seed_all($pdo, $opts, false);
    installer_simulate_runtime($pdo);

    installer_assert_same($productsBefore, installer_count('products'), 'A later run must not add or delete products');
    installer_assert_same($couponsBefore, installer_count('coupons'), 'A later run must not add or delete coupons');
    installer_assert_same($slidesBefore, installer_count('promo_slides'), 'A later run must not add or delete slides');
    installer_assert_same($couriersBefore, installer_count('couriers'), 'A later run must not add or delete courier accounts');
    installer_assert_same($adminsBefore, installer_count('admins'), 'A later run must not add or delete administrators');
    installer_assert_same(1, installer_count('products', 'id = ?', [$realProduct]), 'A real product must survive a later run');
    installer_assert_same(1, installer_count('categories', 'id = ?', [$realCategory]), 'A real category must survive a later run');
    installer_assert_same(1, installer_count('brands', 'id = ?', [$realBrand]), 'A real brand must survive a later run');
    installer_assert_same(1, installer_count('users', 'id = ?', [$userId]), 'A real customer must survive a later run');
    installer_assert_same(1, installer_count('orders', 'id = ?', [$orderId]), 'A real order must survive a later run');
    installer_assert_same(1, installer_count('order_items', 'order_id = ?', [$orderId]), 'Order lines must survive a later run');
    installer_assert_same(1, installer_count('reviews', 'id = ?', [$visitorReview]), 'A real customer review must survive a later run');

    // -----------------------------------------------------------------------
    // 5. Administrator password policy and exact-bytes handling
    // -----------------------------------------------------------------------
    installer_phase('5 admin password');
    installer_assert(sh_admin_password_problem('vL9#qPz!2mW@') === null, 'A 12-character password with no brand words must be accepted');
    installer_assert(sh_admin_password_problem('two words here ok') === null, 'A passphrase with spaces must be accepted');
    installer_assert(sh_admin_password_problem('এটি একটি পাসওয়ার্ড 2024') === null, 'A non-Latin password must be accepted');
    installer_assert(sh_admin_password_problem('  ') !== null, 'A whitespace-only password must be refused');
    installer_assert(sh_admin_password_problem(str_repeat('a', 201)) !== null, 'A password over 200 characters must be refused');

    $short = sh_admin_password_problem('shortpass12');
    installer_assert($short !== null && str_contains($short, '12'), 'An 11-character password must be refused and name the real minimum');
    installer_assert(sh_admin_password_problem('') !== null, 'An empty password must be refused');
    foreach (['Shop2024pass', 'ShopHatt123', 'Shop123hatt'] as $branded) {
        $problem = sh_admin_password_problem($branded);
        installer_assert($problem === null || !str_contains($problem, $branded), 'The policy must never name or require a fixed password');
    }

    // The confirmation compares the exact bytes, and a mismatch is a clear error.
    $v = new ShValidator(['admin_password' => 'vL9#qPz!2mW@', 'admin_password2' => 'vL9#qPz!2mW@ ']);
    $v->password('admin_password', 'admin')->matchesRaw('admin_password2', 'admin_password', 'Password confirmation');
    installer_assert(isset($v->errors()['admin_password2']), 'A confirmation that differs by one space must be refused');
    $v = new ShValidator(['admin_password' => 'vL9#qPz!2mW@', 'admin_password2' => 'vL9#qPz!2mW@']);
    $v->password('admin_password', 'admin')->matchesRaw('admin_password2', 'admin_password', 'Password confirmation');
    installer_assert($v->errors() === [], 'A matching confirmation must pass');

    // One administrator per address at the database level too.
    $duplicateRefused = false;
    try {
        sh_query('INSERT INTO admins (name, email, password_hash, role, status) VALUES (?,?,?,?,?)',
            ['Duplicate', 'owner@example.test', password_hash('another password', PASSWORD_DEFAULT), 'superadmin', 'active']);
    } catch (Throwable $e) {
        $duplicateRefused = true;
    }
    installer_assert($duplicateRefused, 'A second administrator with the same address must be refused by the database');

    // -----------------------------------------------------------------------
    // 6. Leave the disposable database exactly as it was found
    // -----------------------------------------------------------------------
    installer_phase('6 cleanup');
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
