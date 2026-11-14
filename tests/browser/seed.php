<?php
/**
 * Seed a disposable database for the browser regression job.
 *
 * This uses the APPLICATION'S OWN installer path — sh_schema_sql() followed by
 * sh_install_create_store() with demo data on — so the store the browser sees is
 * the same store an operator gets from a real install: the 50 tables, the
 * settings the storefront needs, the starter catalogue with generated
 * placeholder images, the manual bKash/Nagad/Rocket methods and Cash on
 * Delivery, and one administrator account.
 *
 * It then adds two ordinary customer accounts for the flows that need to sign in.
 *
 * Refuses to run against anything that is not named like a test database, because
 * it drops every table first.
 *
 *   SH_BROWSER_SEED=1 php tests/browser/seed.php
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = (string)(getenv('SH_BROWSER_HOST') ?: '127.0.0.1:8080');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_SCHEME'] = 'http';

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
// sh_install_create_store() calls sh_install_demo_data() when demo data is
// requested; the installer page always loads the demo module next to the
// schema module, so seeding has to do the same.
require_once SH_ROOT . '/install/demo-data.php';

$say = static function (string $status, string $msg): void { printf("  %-6s %s\n", $status, $msg); };

// The installer writes this when it finishes; without it every page redirects to
// /install/. The browser job needs the store, not the installer.
if (!is_file(SH_LOCK_FILE)) { @file_put_contents(SH_LOCK_FILE, 'seeded by tests/browser/seed.php'); }

$pdo = sh_db();
$dbName = (string)(sh_db_config()['name'] ?? '');
if (preg_match('~(test|staging|scratch|ci|sandbox)~i', $dbName) !== 1) {
    fwrite(STDERR, "REFUSED: \"{$dbName}\" is not named like a test database; this script drops every table.\n");
    exit(2);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string)$t) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$errors = [];
foreach (sh_schema_sql() as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}
$tables = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
$say($errors === [] ? 'PASS' : 'FAIL', 'schema created: ' . $tables . ' tables' . ($errors === [] ? '' : ' — ' . substr($errors[0], 0, 160)));
if ($errors !== []) { exit(1); }

// The installer throws when it cannot finish. Uncaught, the application's own
// error page is printed instead and the process still looks finished, which is
// exactly how an empty storefront was mistaken for a rendering bug.
try {
    $report = sh_install_create_store($pdo, [
        'site_name'      => 'Shop Haat Test Store',
        'admin_email'    => 'admin@example.test',
        'admin_password' => 'AdminTest!2026',
        'demo_data'      => '1',
    ], '', 'http://' . (string)(getenv('SH_BROWSER_HOST') ?: '127.0.0.1:8080'));
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: the installer refused to finish: " . $e->getMessage()
        . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
}

$admin = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$products = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE status = 1')->fetchColumn();
$categories = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$methods = (int)$pdo->query('SELECT COUNT(*) FROM payment_methods WHERE status = 1')->fetchColumn();
// The gates that decide whether a sign-in survives to the next request. Printing
// them means a checkout that bounces to login is explained by the store's own
// configuration rather than guessed at.
$gateKeys = ['auth_mode', 'email_verify_required_login', 'email_verification_required', 'email_verifier', 'guest_checkout'];
$gate = [];
foreach ($pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $row) {
    if (in_array((string)$row['setting_key'], $gateKeys, true)) {
        $gate[(string)$row['setting_key']] = (string)$row['setting_value'];
    }
}
$say('PASS', 'sign-in gates: ' . ($gate === [] ? '(none set)' : json_encode($gate)));
$say($admin === 1 ? 'PASS' : 'FAIL', 'administrator account: ' . $admin);
$say($products > 0 ? 'PASS' : 'FAIL', 'storefront products: ' . $products);
$say($categories > 0 ? 'PASS' : 'FAIL', 'categories: ' . $categories);
$say($methods > 0 ? 'PASS' : 'FAIL', 'active payment methods: ' . $methods);

// A real multi-image gallery. product.php only renders its previous/next
// controls when a product actually has more than one picture, so the gallery
// assertions need a product that has some — written through product_images, the
// same table the admin form writes.
$first = $pdo->query('SELECT id, name, slug, image FROM products ORDER BY id ASC LIMIT 1')->fetch();
$galleryImages = 0;
if (is_array($first)) {
    $insertImage = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)');
    foreach ([' — side view' => 1, ' — in use' => 2] as $suffix => $order) {
        $file = sh_demo_placeholder((string)$first['name'] . $suffix, '#dbe4f0', '#8fa6c4');
        if ($file !== '') { $insertImage->execute([(int)$first['id'], $file, $order]); }
    }
    $galleryImages = (int)$pdo->query(
        'SELECT COUNT(*) FROM product_images WHERE product_id = ' . (int)$first['id']
    )->fetchColumn();
    $say($galleryImages >= 2 ? 'PASS' : 'FAIL', 'extra gallery images on "' . $first['name'] . '": ' . $galleryImages);
}

$customer = static function (PDO $pdo, string $name, string $email, string $phone) use ($say): void {
    $st = $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, status, email_verified, email_verified_at)
                         VALUES (?,?,?,?, 'active', 1, NOW())");
    $st->execute([$name, $email, $phone, password_hash('Customer!2026', PASSWORD_DEFAULT)]);
    $say('PASS', 'customer seeded: ' . $email);
};
$customer($pdo, 'Test Customer', 'customer@example.test', '01700000001');
$customer($pdo, 'Second Customer', 'customer2@example.test', '01700000002');

// A verified phone is not required for the password flow, but a missing merchant
// number makes the manual methods unusable at checkout; give bKash one so the
// payment-submission step is reachable.
$pdo->exec("UPDATE payment_methods SET account_number = '01711111111' WHERE code = 'bkash'");

// The browser run needs to know which page has the gallery it should exercise.
$state = [
    'products'           => $products,
    'categories'         => $categories,
    'galleryImages'      => $galleryImages,
    'galleryProductUrl'  => is_array($first) ? '/product.php?slug=' . urlencode((string)$first['slug']) : '',
];
@mkdir(__DIR__ . '/artifacts', 0777, true);
@file_put_contents(__DIR__ . '/artifacts/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\nSeed complete: http://" . (string)(getenv('SH_BROWSER_HOST') ?: '127.0.0.1:8080') . "\n";
echo "admin@example.test / AdminTest!2026 · customer@example.test / Customer!2026\n";
echo json_encode($state + ['admin' => 'admin@example.test', 'customer' => 'customer@example.test',
    'password' => 'Customer!2026', 'admin_password' => 'AdminTest!2026']) . "\n";
// A seed that produced no catalogue cannot support a browser run: say so here
// rather than letting eight browser checks fail for one cause.
exit($products > 0 && $admin === 1 && $methods > 0 && $galleryImages >= 2 ? 0 : 1);
