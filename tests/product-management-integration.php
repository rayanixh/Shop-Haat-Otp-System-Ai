<?php
/**
 * SHOP HAAT — product management & image gallery integration suite.
 *
 * The 16 acceptance cases are enumerated in .github/workflows/reporting-integration.yml.
 * This suite drives the REAL application helpers and SQL against a PDO SQLite
 * database with a small MySQL-DDL translation layer. It proves SQL-backed
 * create/edit/replace/remove/reorder, migration, dictionary, cart, guest-merge,
 * order-snapshot, notification and rollback logic. It is NOT a claim of live
 * MySQL/MariaDB, deployed-server, real-browser or production parity; the CI
 * MySQL job separately exercises the shipped DDL.
 *
 * P01 additive migration and legacy product/URL/fulfilment compatibility
 * P02 fresh-install schema, unique variant key and idempotent dictionary seeds
 * P03 create a product with 1–5 ordered images, primary snapshot follows first
 * P04 reject zero images for new products and more than five; no orphan files
 * P05 secure 3 MB / MIME / dimension validation and no partial gallery upload
 * P06 edit gallery: replace, remove, reorder, add, cleanup and reopen
 * P07 brand/type/colour CRUD, hide and usage-protected delete
 * P08 structured common and custom colours persist and reopen in the editor
 * P09 server validates colour and defaults to the first available option
 * P10 distinct colour cart lines, shared stock, update/remove/clear
 * P11 guest-cart merge preserves variants and keeps legacy schema working
 * P12 checkout/order snapshot and customer/admin/retry display contain colour
 * P13 notification caption and order text include the saved colour snapshot
 * P14 all-or-nothing product save: rollback and deferred old-file deletion
 * P15 AI Auto Work, SEO, product-card and legacy one-image compatibility
 * P16 no autoplay/timers/cropping; admin scripts and SEO preview are wired
 */
declare(strict_types=1);

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }
$fixtureRoot = sys_get_temp_dir() . '/shop-haat-product-db-' . getmypid();
if (!defined('SH_UPLOAD_DIR')) { define('SH_UPLOAD_DIR', $fixtureRoot); }
@mkdir(SH_UPLOAD_DIR . '/products', 0775, true);

$_SERVER['SCRIPT_NAME'] = '/tests/product-management-integration.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];

$GLOBALS['product_test_log'] = [];
$GLOBALS['product_test_upload_no'] = 0;
$GLOBALS['product_test_scenario'] = ['legacy_cart' => false, 'user' => null];

const SH_EVENTS = ['order_created' => 'New Order'];

class ShProductTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->translate($statement));
    }

    private function translate(string $sql): string
    {
        $sql = preg_replace('/^\s*INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql) ?? $sql;
        if (preg_match('~information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?~i', $sql) === 1) {
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
        }
        if (preg_match('~information_schema\.columns\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+column_name\s*=\s*\?~i', $sql) === 1) {
            return 'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?';
        }
        if (preg_match('~information_schema\.statistics\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+index_name\s*=\s*\?~i', $sql) === 1) {
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?";
        }
        if (preg_match('~information_schema\.table_constraints\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?\s+AND\s+constraint_name\s*=\s*\?~i', $sql) === 1) {
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?";
        }
        if (preg_match('~CREATE TABLE IF NOT EXISTS\s+(\w+)~i', $sql, $m) === 1) {
            $table = strtolower($m[1]);
            $sqlite = [
                'product_images' => 'CREATE TABLE IF NOT EXISTS product_images (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, image TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
                'product_types' => 'CREATE TABLE IF NOT EXISTS product_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, status INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
                'product_colours' => 'CREATE TABLE IF NOT EXISTS product_colours (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, hex TEXT NOT NULL DEFAULT "#888888", status INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)',
                'product_colour_selections' => 'CREATE TABLE IF NOT EXISTS product_colour_selections (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, colour_id INTEGER, custom_name TEXT, custom_hex TEXT, sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
            ];
            if (isset($sqlite[$table])) { return $sqlite[$table]; }
        }
        if (preg_match('~ALTER TABLE products ADD COLUMN catalog_type_id~i', $sql) === 1) {
            return 'ALTER TABLE products ADD COLUMN catalog_type_id INTEGER DEFAULT NULL';
        }
        if (preg_match('~ALTER TABLE cart_items ADD COLUMN variant~i', $sql) === 1) {
            return "ALTER TABLE cart_items ADD COLUMN variant TEXT NOT NULL DEFAULT ''";
        }
        if (preg_match('~ALTER TABLE cart_items DROP INDEX\s+(\w+)~i', $sql, $m) === 1) {
            return 'DROP INDEX IF EXISTS ' . $m[1];
        }
        if (preg_match('~ALTER TABLE cart_items ADD UNIQUE KEY\s+(\w+)\s+\(([^)]+)\)~i', $sql, $m) === 1) {
            return 'CREATE UNIQUE INDEX IF NOT EXISTS ' . $m[1] . ' ON cart_items (' . $m[2] . ')';
        }
        if (preg_match('~ALTER TABLE\s+\w+\s+ADD\s+(KEY|UNIQUE KEY|CONSTRAINT)~i', $sql) === 1) {
            return 'SELECT 0';
        }
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $sql = str_replace(
                'ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ?)',
                'ON CONFLICT(cart_id, product_id, variant) DO UPDATE SET quantity = MIN(quantity + excluded.quantity, ?)',
                $sql
            );
            $sql = str_replace(
                'ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
                'ON CONFLICT(cart_id, product_id, variant) DO UPDATE SET quantity = excluded.quantity',
                $sql
            );
        }
        return preg_replace('/\s+FOR UPDATE\b/i', '', $sql) ?? $sql;
    }
}

$pdo = new ShProductTestPDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE brands (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, status INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE)');
$pdo->exec('CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER, brand_id INTEGER, catalog_type_id INTEGER,
    name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, image TEXT, price REAL DEFAULT 0,
    compare_price REAL DEFAULT 0, stock INTEGER DEFAULT 0, status INTEGER DEFAULT 1,
    product_type TEXT DEFAULT "physical", sku TEXT, description TEXT, specifications TEXT,
    short_description TEXT, rating REAL DEFAULT 0, review_count INTEGER DEFAULT 0,
    sold_count INTEGER DEFAULT 0, image_alt TEXT
)');
$pdo->exec('CREATE TABLE cart (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, session_token TEXT)');
$pdo->exec('CREATE TABLE cart_items (id INTEGER PRIMARY KEY AUTOINCREMENT, cart_id INTEGER NOT NULL, product_id INTEGER NOT NULL, variant TEXT NOT NULL DEFAULT "", quantity INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE UNIQUE INDEX uq_cart_product_variant ON cart_items (cart_id, product_id, variant)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
$pdo->exec('CREATE TABLE reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, rating REAL, status TEXT)');
$pdo->exec('CREATE TABLE payment_methods (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, type TEXT, description TEXT, instructions TEXT, account_number TEXT, account_type TEXT, sort_order INTEGER, status INTEGER)');
$pdo->exec('CREATE TABLE payment_gateways (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, driver TEXT, description TEXT, mode TEXT, status INTEGER, sort_order INTEGER)');

function sh_db(): PDO { return $GLOBALS['product_test_pdo']; }
$GLOBALS['product_test_pdo'] = $pdo;
function sh_query(string $sql, array $params = []): PDOStatement
{
    $st = sh_db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function sh_all(string $sql, array $params = []): array { return sh_query($sql, $params)->fetchAll(); }
function sh_one(string $sql, array $params = []): ?array
{
    $row = sh_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}
function sh_val(string $sql, array $params = [], $default = null)
{
    $v = sh_query($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
function sh_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    sh_query('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES ($ph)", array_values($data));
    return (int)sh_db()->lastInsertId();
}
function sh_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) { $set[] = "`$c` = ?"; }
    return sh_query('UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where, array_merge(array_values($data), $whereParams))->rowCount();
}
function sh_setting(string $key, string $default = ''): string
{
    return (string)sh_val('SELECT setting_value FROM settings WHERE setting_key = ?', [$key], $default);
}
function sh_setting_save(string $key, string $value): void
{
    sh_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value', [$key, $value]);
}
function sh_table_has_column(string $table, string $column): bool
{
    return (int)sh_val('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?', [$table, $column], 0) > 0;
}
function sh_slug(string $text): string
{
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'item';
}
function sh_url(string $path = ''): string { return '/' . ltrim($path, '/'); }
function sh_product_image(?string $file, string $size = 'md'): string
{
    return '/uploads/products/' . rawurlencode(basename((string)$file));
}
function sh_log_line(string $channel, string $message): void { $GLOBALS['product_test_log'][] = [$channel, $message]; }
function sh_log_exception(Throwable $e, string $channel): void { $GLOBALS['product_test_log'][] = [$channel, $e->getMessage()]; }
function sh_audit(string $action, string $module, ?int $targetId = null, string $label = '', $old = null, $new = null): void {}
function sh_order_item_variant(string $category, string $sku): string
{
    return implode(' · ', array_values(array_filter([trim($category), trim($sku) !== '' ? 'SKU ' . trim($sku) : ''])));
}
function sh_money($amount): string { return 'BDT ' . number_format((float)$amount, 2); }
function sh_status_label(string $status): string { return ucfirst(str_replace('_', ' ', $status)); }
function sh_cart_id(bool $create = true): int { return 1; }
function sh_user(): ?array { return $GLOBALS['product_test_scenario']['user'] ?? null; }
function sh_session_start(): void {}
function sh_cart_has_variant_column(): bool
{
    return empty($GLOBALS['product_test_scenario']['legacy_cart']);
}

/** Production-like MIME/size validation for the PHP-WASM/SQLite harness. */
function sh_test_product_upload_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { return ['ok' => false, 'error' => 'Upload failed.']; }
    if ((int)($file['size'] ?? 0) > SH_PRODUCT_MAX_IMAGE_BYTES) { return ['ok' => false, 'error' => 'File is larger than 3 MB.']; }
    $type = strtolower((string)($file['type'] ?? ''));
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$type])) { return ['ok' => false, 'error' => 'Unsupported image type.']; }
    $name = 'test-' . (++$GLOBALS['product_test_upload_no']) . '.' . $allowed[$type];
    file_put_contents(SH_UPLOAD_DIR . '/products/' . $name, 'png-fixture');
    return ['ok' => true, 'file' => $name];
}

require_once SH_ROOT . '/includes/product-catalog.php';
require_once SH_ROOT . '/install/schema.php';

$checks = 0;
$failures = [];
$caseIds = [];
$currentCase = '';
function product_case(string $id, string $label): void
{
    global $caseIds, $currentCase;
    $caseIds[$id] = $label;
    $currentCase = $id;
    echo 'CASE ' . $id . ': ' . $label . "\n";
}
function product_ok(bool $condition, string $message): void
{
    global $checks, $failures, $currentCase;
    $checks++;
    if (!$condition) { $failures[] = '[' . $currentCase . '] ' . $message; }
}
function product_same($expected, $actual, string $message): void
{
    product_ok($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function fake_files(int $count, string $type = 'image/png'): array
{
    $files = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    for ($i = 0; $i < $count; $i++) {
        $files['name'][] = 'file-' . $i . '.png';
        $files['type'][] = $type;
        $files['tmp_name'][] = '/tmp/fake-' . $i;
        $files['error'][] = UPLOAD_ERR_OK;
        $files['size'][] = 1024;
    }
    return ['images' => $files];
}
function sequence_new(int $count): array
{
    $tokens = [];
    for ($i = 0; $i < $count; $i++) { $tokens[] = 'n:' . $i; }
    return ['image_row' => $tokens];
}
/** Include one real top-level function verbatim from its shipped source file. */
function include_shipped_function(string $relativePath, string $name): void
{
    static $included = [];
    $key = $relativePath . ':' . $name;
    if (isset($included[$key])) { return; }
    $source = file_get_contents(SH_ROOT . '/' . $relativePath);
    $pattern = '/^function\s+' . preg_quote($name, '/') . '\s*\([^)]*\).*?^\}/ms';
    if (!is_string($source) || preg_match($pattern, $source, $m) !== 1) {
        throw new RuntimeException('Could not extract ' . $name . ' from ' . $relativePath);
    }
    $path = sys_get_temp_dir() . '/shop-haat-fn-' . getmypid() . '-' . md5($key) . '.php';
    file_put_contents($path, "<?php\ndeclare(strict_types=1);\n" . $m[0] . "\n");
    include $path;
    @unlink($path);
    $included[$key] = true;
}
function source(string $relativePath): string
{
    $text = file_get_contents(SH_ROOT . '/' . $relativePath);
    return is_string($text) ? $text : '';
}

try {
    // P01 ---------------------------------------------------------------
    product_case('P01', 'Additive migration preserves legacy one-image products, URLs and the fulfilment ENUM');
    $legacyFile = 'legacy-one.png';
    file_put_contents(SH_UPLOAD_DIR . '/products/' . $legacyFile, 'legacy');
    $legacyId = sh_insert('products', [
        'name' => 'Legacy Bag', 'slug' => 'legacy-bag', 'image' => $legacyFile,
        'status' => 1, 'stock' => 5, 'price' => 100, 'compare_price' => 140,
        'product_type' => 'physical',
    ]);
    sh_product_catalog_schema_ensure();
    product_ok(sh_table_has_column('products', 'catalog_type_id'), 'migration added products.catalog_type_id');
    product_ok(sh_table_has_column('cart_items', 'variant'), 'migration added cart_items.variant');
    product_ok((int)sh_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='product_images'") === 1, 'migration created product_images');
    product_ok((int)sh_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='product_colour_selections'") === 1, 'migration created colour selections');
    product_same($legacyFile, (string)sh_val('SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order LIMIT 1', [$legacyId]), 'legacy one-image product was back-filled without duplication');
    product_same($legacyFile, (string)sh_val('SELECT image FROM products WHERE id = ?', [$legacyId]), 'legacy products.image snapshot was not overwritten');
    product_same('physical', (string)sh_val('SELECT product_type FROM products WHERE id = ?', [$legacyId]), 'fulfilment ENUM value stayed physical');
    product_ok(str_contains(source('install/schema.php'), "product_type ENUM('physical','digital')"), 'merchandising type did not replace the fulfilment ENUM');
    product_ok(str_contains(source('product.php'), "product.php?slug='"), 'existing slug product URLs were preserved');
    product_same('2', sh_setting('product_catalog_schema_v', '0'), 'migration version was persisted');
    product_ok(!str_contains(implode(' ', array_column($GLOBALS['product_test_log'], 1)), 'Schema migration failed'), 'migration did not fail');
    sh_product_catalog_schema_ensure();
    product_same(1, (int)sh_val('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$legacyId]), 'second migration call did not duplicate the legacy image');

    // P02 ---------------------------------------------------------------
    product_case('P02', 'Fresh-install schema adds tables, variant key and idempotent dictionary starters');
    $tables = sh_schema_tables();
    foreach (['product_types', 'product_colours', 'product_colour_selections'] as $t) {
        product_ok(in_array($t, $tables, true), 'installer table list includes ' . $t);
    }
    $ddl = implode("\n", sh_schema_sql());
    foreach (['CREATE TABLE IF NOT EXISTS product_types', 'CREATE TABLE IF NOT EXISTS product_colours',
              'CREATE TABLE IF NOT EXISTS product_colour_selections', 'catalog_type_id INT UNSIGNED',
              'variant VARCHAR(190)', 'uq_cart_product_variant (cart_id, product_id, variant)',
              'fk_products_catalog_type'] as $needle) {
        product_ok(str_contains($ddl, $needle), 'fresh-install DDL contains ' . $needle);
    }
    sh_seed_essential_starters($pdo);
    sh_seed_essential_starters($pdo);
    product_same(8, (int)sh_val('SELECT COUNT(*) FROM product_types'), 'fresh-install seed produced exactly 8 types');
    product_same(15, (int)sh_val('SELECT COUNT(*) FROM product_colours'), 'fresh-install seed produced exactly 15 colours');
    product_same(4, (int)sh_val('SELECT COUNT(*) FROM payment_methods'), 'essential payment routes still seed');
    product_same(3, (int)sh_val('SELECT COUNT(*) FROM payment_gateways'), 'essential gateway definitions still seed');
    product_same(0, (int)sh_val('SELECT COUNT(*) FROM product_colour_selections'), 'structure seeds do not assign colours to products');

    // P03 ---------------------------------------------------------------
    product_case('P03', 'Admin create saves 1–5 ordered images and keeps products.image on the primary');
    $createdIds = [];
    for ($count = 1; $count <= SH_PRODUCT_MAX_IMAGES; $count++) {
        $pid = sh_insert('products', ['name' => 'Product ' . $count, 'slug' => 'product-' . $count, 'status' => 1, 'stock' => 10, 'price' => 100]);
        $createdIds[$count] = $pid;
        $plan = sh_product_gallery_plan([], sequence_new($count), fake_files($count));
        product_ok($plan['ok'], "create with {$count} image(s) passed server validation");
        product_same($count, count($plan['rows']), "create with {$count} image(s) produced {$count} ordered rows");
        product_same($count, count($plan['uploaded']), "create with {$count} image(s) uploaded exactly {$count} files");
        sh_product_gallery_commit($pid, $plan, []);
        $rows = sh_product_gallery_rows($pid, (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid]), false);
        product_same($count, count($rows), "reopening a {$count}-image product shows every saved image");
        product_same($plan['primary'], (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid]), "primary image is first for {$count} images");
        product_same(range(0, $count - 1), array_column($rows, 'sort_order'), "gallery order is gap-free for {$count} images");
    }

    // P04 ---------------------------------------------------------------
    product_case('P04', 'Zero-image new products and six-image submissions are rejected without orphan uploads');
    product_same(['Upload at least one product image.'], sh_product_gallery_submission_errors([], [], [], true), 'a new product requires at least one image');
    product_same([], sh_product_gallery_submission_errors([], [], [], false), 'a legacy no-photo product may still be edited');
    $six = sh_product_gallery_plan([], sequence_new(6), fake_files(6));
    product_ok(!$six['ok'], 'a six-image submission is rejected server-side');
    product_ok(str_contains(implode(' ', $six['errors']), 'at most 5'), 'six-image rejection explains the maximum');
    product_same([], $six['uploaded'], 'rejected six-image submission left no orphan uploads');
    product_same(['A product can have at most 5 images. Remove an existing image or upload fewer new files.'],
        sh_product_gallery_submission_errors([], sequence_new(6), fake_files(6)), 'count-only validation rejects over-capacity before upload');

    // P05 ---------------------------------------------------------------
    product_case('P05', 'Server-side 3 MB / MIME / dimension validation rejects bad files atomically');
    $badType = sh_product_upload_image(['name' => 'bad.svg', 'type' => 'image/svg+xml', 'size' => 100, 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/bad']);
    product_ok(empty($badType['ok']), 'server-side validation rejects an unsupported MIME type');
    $tooLarge = sh_product_upload_image(['name' => 'large.png', 'type' => 'image/png', 'size' => SH_PRODUCT_MAX_IMAGE_BYTES + 1, 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/large']);
    product_ok(empty($tooLarge['ok']), 'server-side validation rejects a file above 3 MB');
    $mixed = fake_files(2);
    $mixed['images']['type'][1] = 'image/svg+xml';
    $mixedPlan = sh_product_gallery_plan([], sequence_new(2), $mixed);
    product_ok(!$mixedPlan['ok'], 'one invalid image rejects the whole gallery submission');
    product_same([], $mixedPlan['uploaded'], 'invalid mixed submission leaves no partially uploaded files');
    $uploadSource = source('includes/functions.php');
    product_ok(str_contains($uploadSource, 'getimagesize($file[\'tmp_name\'])'), 'production upload validator reads real image dimensions');
    product_ok(str_contains($uploadSource, '$file[\'size\'] > $maxBytes'), 'production upload validator enforces the byte limit');
    product_ok(str_contains($uploadSource, '$info[0] > $maxDim || $info[1] > $maxDim'), 'production upload validator enforces maximum dimensions');
    product_ok(str_contains($uploadSource, 'is_uploaded_file($file[\'tmp_name\'])'), 'production upload validator rejects a spoofed upload source');
    product_ok(str_contains(source('includes/product-catalog.php'), 'SH_PRODUCT_MAX_IMAGE_BYTES'), 'product gallery passes the 3 MB limit to the validator');

    // P06 ---------------------------------------------------------------
    product_case('P06', 'Edit replaces, removes, reorders and adds images; old files are cleaned up');
    $pid = $createdIds[3];
    $existing = sh_product_gallery_rows($pid, (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid]), true);
    $firstId = (int)$existing[0]['id'];
    $lastId = (int)$existing[2]['id'];
    $replace = ['image_replace' => [
        'name' => [$firstId => 'replacement.png'],
        'type' => [$firstId => 'image/png'],
        'tmp_name' => [$firstId => '/tmp/replace'],
        'error' => [$firstId => UPLOAD_ERR_OK],
        'size' => [$firstId => 1024],
    ]];
    $editFiles = $replace;
    $editFiles['images'] = [
        'name' => ['new-edit.png'], 'type' => ['image/png'], 'tmp_name' => ['/tmp/new'],
        'error' => [UPLOAD_ERR_OK], 'size' => [1024],
    ];
    $editPost = [
        'image_row' => ['e:' . $lastId, 'e:' . (int)$existing[1]['id'], 'e:' . $firstId, 'n:0'],
        'image_remove' => [(int)$existing[1]['id'] => '1'],
    ];
    $editPlan = sh_product_gallery_plan($existing, $editPost, $editFiles);
    product_ok($editPlan['ok'], 'edit with replace/remove/add/reorder passed validation');
    product_same(3, count($editPlan['rows']), 'edit kept exactly three images after one removal and one addition');
    $replaced = array_values(array_filter($editPlan['rows'], static fn(array $r): bool => (int)$r['id'] === $firstId));
    product_ok($replaced !== [] && $replaced[0]['image'] !== $existing[0]['file'], 'existing image was replaced with a new file');
    product_same($lastId, (int)$editPlan['rows'][0]['id'], 'manual order made the last saved image primary');
    $oldFiles = sh_product_gallery_commit($pid, $editPlan, $existing, false);
    product_ok(in_array($existing[0]['file'], $oldFiles, true), 'deferred cleanup returns the replaced old file');
    product_ok(in_array($existing[1]['file'], $oldFiles, true), 'deferred cleanup returns the removed old file');
    foreach ($oldFiles as $file) { sh_product_delete_unreferenced_image($file); }
    $afterEdit = sh_product_gallery_rows($pid, (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid]), false);
    product_same(3, count($afterEdit), 'edited gallery persisted after reopen');
    product_same($editPlan['primary'], (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid]), 'products.image followed the new primary image');
    product_ok(!is_file(SH_UPLOAD_DIR . '/products/' . $existing[1]['file']), 'removed image file was cleaned up when unreferenced');

    // P07 ---------------------------------------------------------------
    product_case('P07', 'Brand/type/colour dictionaries support create, edit, hide and usage-protected delete');
    foreach (['sh_attributes_config', 'sh_attributes_rows', 'sh_attributes_find', 'sh_attributes_slug',
              'sh_attributes_save', 'sh_attributes_usage_count', 'sh_attributes_toggle', 'sh_attributes_delete'] as $fn) {
        include_shipped_function('admin/product-attributes.php', $fn);
    }
    product_same('brands', sh_attributes_config('brands')['table'], 'attribute config uses a fixed allowlist');
    $newBrand = sh_attributes_save('brands', 0, 'Test Brand', '', 1);
    product_ok($newBrand['ok'], 'a brand can be created');
    $brandId = (int)sh_val('SELECT id FROM brands WHERE name = ?', ['Test Brand'], 0);
    sh_attributes_toggle('brands', $brandId);
    product_same(0, (int)sh_val('SELECT status FROM brands WHERE id = ?', [$brandId]), 'a dictionary row can be hidden');
    sh_attributes_toggle('brands', $brandId);
    product_same(1, (int)sh_val('SELECT status FROM brands WHERE id = ?', [$brandId]), 'a hidden dictionary row can be shown again');
    sh_update('products', ['brand_id' => $brandId], 'id = ?', [$pid]);
    product_ok(sh_attributes_usage_count('brands', $brandId) > 0, 'usage count detects a brand used by a product');
    product_ok(!sh_attributes_delete('brands', $brandId)['ok'], 'a used brand cannot be deleted');
    sh_update('products', ['brand_id' => null], 'id = ?', [$pid]);
    product_ok(sh_attributes_delete('brands', $brandId)['ok'], 'an unused brand can be deleted');
    $newType = sh_attributes_save('types', 0, 'Test Type', '', 1);
    product_ok($newType['ok'], 'a merchandising type can be created');
    $newColour = sh_attributes_save('colours', 0, 'Test Colour', '#112233', 1);
    product_ok($newColour['ok'], 'a common colour with a hex swatch can be created');
    product_same('#112233', (string)sh_val('SELECT hex FROM product_colours WHERE name = ?', ['Test Colour'], ''), 'colour hex was normalised and stored');

    // P08 ---------------------------------------------------------------
    product_case('P08', 'Structured common and custom colours persist, resolve and reopen in the admin form');
    $brandA = sh_product_catalog_create_brand('Atlas Goods');
    $brandB = sh_product_catalog_create_brand('Nova Label');
    $typeA = sh_product_catalog_create_type('Handbag');
    $typeB = sh_product_catalog_create_type('Shoulder Bag');
    $blackId = (int)sh_val('SELECT id FROM product_colours WHERE slug = ?', ['black'], 0);
    $redId = (int)sh_val('SELECT id FROM product_colours WHERE slug = ?', ['red'], 0);
    sh_product_catalog_save_relations($pid, $brandA, $typeA, [$blackId, $redId], ['Forest Green'], ['#228B22']);
    $display = sh_product_catalog_display($pid, sh_one('SELECT * FROM products WHERE id = ?', [$pid]) ?? []);
    product_same('Atlas Goods', $display['brand'], 'brand was stored as a relationship and resolves to its label');
    product_same('Handbag', $display['type'], 'catalog product type is separate from the fulfilment ENUM');
    product_same(['Black', 'Red', 'Forest Green'], array_column($display['colours'], 'name'), 'common and custom colours are structured selections');
    product_same('#228B22', $display['colours'][2]['hex'], 'custom colour hex was normalised and stored');
    $formState = sh_product_colour_form_state($pid);
    product_ok(isset($formState['selected'][$blackId], $formState['selected'][$redId]), 'admin form reopens with saved common colours selected');
    product_same('Forest Green', $formState['custom'][0]['name'], 'admin form reopens with the saved custom colour');
    sh_product_catalog_save_relations($pid, $brandB, $typeB, [$redId], [], []);
    $changed = sh_product_catalog_display($pid, sh_one('SELECT * FROM products WHERE id = ?', [$pid]) ?? []);
    product_same('Nova Label', $changed['brand'], 'brand can be changed after publishing');
    product_same('Shoulder Bag', $changed['type'], 'product type can be changed after publishing');
    product_same(['Red'], array_column($changed['colours'], 'name'), 'removing common and custom colours persists');
    $postedState = sh_product_colour_form_state($pid, ['colour_ids' => [$blackId], 'custom_colour_name' => ['Sky'], 'custom_colour_hex' => ['#87CEEB']]);
    product_ok(isset($postedState['selected'][$blackId]), 'posted common colour survives a validation error');
    product_same('Sky', $postedState['custom'][0]['name'], 'posted custom colour survives a validation error');

    // P09 ---------------------------------------------------------------
    product_case('P09', 'Cart API validates a customer colour and defaults to the first available option');
    sh_product_catalog_save_relations($pid, $brandB, $typeB, [$redId, $blackId], [], []);
    $defaultChoice = sh_product_colour_variant($pid, '');
    product_same('Colour: Red', $defaultChoice['variant'], 'a cart with no explicit colour defaults to the first available option');
    product_ok(!sh_product_colour_variant($pid, 'Colour: Not Available')['ok'], 'a forged colour is rejected server-side');
    product_ok(sh_product_colour_variant($pid, 'Colour: Black')['ok'], 'a real colour is accepted server-side');
    $cartSource = source('includes/cart.php');
    $cartSource = str_replace("require_once __DIR__ . '/auth.php';", '', $cartSource);
    $cartSource = str_replace("require_once __DIR__ . '/product-schema.php';\nsh_product_schema_ensure();", '', $cartSource);
    $cartPath = $fixtureRoot . '/cart-under-test.php';
    file_put_contents($cartPath, $cartSource);
    include $cartPath;
    sh_query('INSERT INTO cart (id, session_token) VALUES (1, ?)', ['test-token']);
    sh_query('INSERT INTO categories (id, name, slug) VALUES (1, ?, ?)', ['Bags', 'bags']);
    sh_query('UPDATE products SET category_id = 1, sku = ? WHERE id = ?', ['BAG-1', $pid]);

    // P10 ---------------------------------------------------------------
    product_case('P10', 'Distinct colour lines share stock and update/remove/clear by variant');
    $firstAdd = sh_cart_add($pid, 1, '');
    product_ok($firstAdd['ok'], 'cart accepted the default available colour');
    $blackAdd = sh_cart_add($pid, 1, 'Colour: Black');
    product_ok($blackAdd['ok'], 'cart accepted an explicit available colour');
    product_ok(!sh_cart_add($pid, 1, 'Colour: Forged')['ok'], 'cart API rejects a forged colour variant');
    $summary = sh_cart_summary();
    product_same(2, count($summary['items']), 'two colour variants produce two distinct cart lines');
    product_same('Colour: Black', $summary['items'][0]['colour'], 'cart row exposes the selected colour');
    product_ok(str_contains($summary['items'][0]['variant'], 'Colour: Black'), 'cart variant text includes the selected colour');
    product_ok(sh_cart_update($pid, 2, 'Colour: Black')['ok'], 'cart quantity can be updated per colour');
    product_same(2, (int)sh_val('SELECT quantity FROM cart_items WHERE product_id = ? AND variant = ?', [$pid, 'Colour: Black'], 0), 'update touched only the chosen colour line');
    product_ok(sh_cart_remove($pid, 'Colour: Black')['ok'], 'cart line can be removed per colour');
    product_same(1, count(sh_cart_summary()['items']), 'removing one colour keeps the other cart line');
    product_ok(sh_cart_clear()['ok'], 'the original cart-clear API still exists');
    product_same(0, (int)sh_val('SELECT COUNT(*) FROM cart_items WHERE cart_id = 1'), 'cart clear removed every colour line');
    product_ok(sh_cart_add($pid, 1, 'Colour: Red')['ok'], 'cart can be rebuilt after clear');

    // P11 ---------------------------------------------------------------
    product_case('P11', 'Guest-cart merge preserves colour variants and keeps a pre-migration schema working');
    include_shipped_function('includes/auth.php', 'sh_merge_guest_cart');
    $userId = (int)sh_insert('users', ['name' => 'Test Customer', 'email' => 'customer@example.test']);
    sh_query('UPDATE cart SET user_id = ?, session_token = NULL WHERE id = 1', [$userId]);
    $guestId = (int)sh_insert('cart', ['session_token' => str_repeat('a', 64)]);
    sh_query('INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)', [$guestId, $pid, 'Colour: Black', 2]);
    $_SESSION['cart_token'] = str_repeat('a', 64);
    sh_merge_guest_cart($userId);
    product_same(2, (int)sh_val('SELECT quantity FROM cart_items WHERE cart_id = 1 AND product_id = ? AND variant = ?', [$pid, 'Colour: Black'], 0), 'guest colour line merged into the owning cart');
    product_same(0, (int)sh_val('SELECT COUNT(*) FROM cart WHERE id = ?', [$guestId]), 'merged guest cart was removed');
    $legacyGuestId = (int)sh_insert('cart', ['session_token' => str_repeat('b', 64)]);
    sh_query('INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)', [$legacyGuestId, $pid, '', 1]);
    $_SESSION['cart_token'] = str_repeat('b', 64);
    $GLOBALS['product_test_scenario']['legacy_cart'] = true;
    sh_merge_guest_cart($userId);
    product_same(0, (int)sh_val('SELECT COUNT(*) FROM cart WHERE id = ?', [$legacyGuestId]), 'legacy guest merge also removed its guest cart');
    product_ok((int)sh_val('SELECT COUNT(*) FROM cart_items WHERE cart_id = 1 AND product_id = ?', [$pid], 0) >= 1, 'legacy schema merge preserved a product-only cart line instead of failing login');
    $GLOBALS['product_test_scenario']['legacy_cart'] = false;

    // P12 ---------------------------------------------------------------
    product_case('P12', 'Checkout/order snapshot and customer/admin/retry displays keep the selected colour');
    include_shipped_function('includes/payment.php', 'sh_order_item_variant_text');
    include_shipped_function('includes/payment.php', 'sh_order_item_colour_text');
    $snapshot = [
        'product_id' => $pid,
        'product_name' => 'Colour Product',
        'product_variant' => 'Bags · SKU BAG-1 · Colour: Black',
        'quantity' => 2,
        'unit_price' => 100,
        'line_total' => 200,
    ];
    product_same('Black', sh_order_item_colour_text($snapshot), 'saved order snapshot extracts exactly the selected colour');
    product_same('', sh_order_item_colour_text(['product_variant' => 'Bags · SKU BAG-1']), 'legacy order snapshot without a colour is not mislabelled');
    product_same('Bags · SKU BAG-1 · Colour: Black', sh_order_item_variant_text($snapshot), 'admin/notification variant text keeps the full saved snapshot');
    $paymentSource = source('includes/payment.php');
    product_ok(str_contains($paymentSource, "'product_variant' => (\$it['variant'] ?? '')"), 'checkout writes the cart variant into order_items.product_variant');
    foreach (['cart.php', 'checkout.php', 'order-success.php', 'order-details.php', 'orders.php'] as $page) {
        product_ok(str_contains(source($page), 'Colour') || str_contains(source($page), "it['colour']") || str_contains(source($page), 'sh_order_item_colour_text'), $page . ' displays the selected colour');
    }
    product_ok(str_contains(source('admin/orders.php'), 'sh_order_item_variant_text'), 'admin order detail displays the saved variant');
    product_ok(str_contains(source('admin/reports.php'), 'product_variant'), 'admin reports still read the saved variant');

    // P13 ---------------------------------------------------------------
    product_case('P13', 'Notification image caption and order text include the saved colour snapshot');
    include_shipped_function('includes/notifications.php', 'sh_order_media_caption');
    include_shipped_function('includes/notifications.php', 'sh_notify_message');
    $caption = sh_order_media_caption(['order_number' => 'SH-TEST-1'], $snapshot, 1, 1);
    product_ok(str_contains($caption, 'Colour: Black'), 'order media caption includes the colour snapshot');
    $message = sh_notify_message('order_created', ['order_number' => 'SH-TEST-1', 'items' => [$snapshot], 'status' => 'processing', 'payment_status' => 'unpaid']);
    $messageText = is_array($message) ? implode("\n", array_map('strval', $message)) : (string)$message;
    product_ok(str_contains($messageText, 'Colour: Black'), 'order notification text includes the colour snapshot');

    // P14 ---------------------------------------------------------------
    product_case('P14', 'A failed product save rolls back DB changes and never deletes old files early');
    $rollbackPid = $createdIds[2];
    $beforeRows = sh_product_gallery_rows($rollbackPid, (string)sh_val('SELECT image FROM products WHERE id = ?', [$rollbackPid]), false);
    $beforePrimary = (string)sh_val('SELECT image FROM products WHERE id = ?', [$rollbackPid]);
    $rollbackFile = 'rollback-only.png';
    file_put_contents(SH_UPLOAD_DIR . '/products/' . $rollbackFile, 'x');
    $badPlan = [
        'rows' => [['id' => 0, 'image' => $rollbackFile, 'sort' => 0, 'old_file' => '']],
        'primary' => $rollbackFile,
        'uploaded' => [$rollbackFile],
    ];
    $pdo->beginTransaction();
    try {
        $deferred = sh_product_gallery_commit($rollbackPid, $badPlan, $beforeRows, false);
        sh_query('UPDATE products SET image = ? WHERE id = ?', [$rollbackFile, $rollbackPid]);
        throw new RuntimeException('Simulated failure after partial gallery write.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        product_ok($deferred !== [], 'deferred cleanup listed old files before the forced failure');
        product_same($beforePrimary, (string)sh_val('SELECT image FROM products WHERE id = ?', [$rollbackPid]), 'rollback restored the original primary snapshot');
        product_same(count($beforeRows), (int)sh_val('SELECT COUNT(*) FROM product_images WHERE product_id = ?', [$rollbackPid], 0), 'rollback restored the original gallery rows');
        foreach ($beforeRows as $row) {
            product_ok(is_file(SH_UPLOAD_DIR . '/products/' . $row['file']), 'rollback left referenced old photo ' . $row['file'] . ' on disk');
        }
    }
    @unlink(SH_UPLOAD_DIR . '/products/' . $rollbackFile);
    product_ok(str_contains(source('admin/product-edit.php'), 'beginTransaction()'), 'admin save starts a DB transaction');
    product_ok(str_contains(source('admin/product-edit.php'), 'rollBack()'), 'admin save rolls back on failure');
    product_ok(str_contains(source('admin/product-edit.php'), 'sh_product_gallery_commit($id, $plan, $savedGallery, false)'), 'admin save defers old-file deletion until after commit');
    product_ok(str_contains(source('admin/product-edit.php'), '$cleanupUploads()'), 'admin save removes new uploads on failure');

    // P15 ---------------------------------------------------------------
    product_case('P15', 'AI Auto Work, SEO, product cards and legacy one-image products remain compatible');
    $legacyRows = sh_product_gallery_rows($legacyId, $legacyFile, false);
    product_same(1, count($legacyRows), 'legacy one-image product still resolves one real gallery image');
    product_same('/uploads/products/' . $legacyFile, $legacyRows[0]['url'], 'legacy image retains its original URL');
    $legacyPrimaryInGallery = sh_product_gallery_rows(
        (int)sh_val('SELECT id FROM products WHERE slug = ?', ['product-1'], 0),
        (string)sh_val('SELECT image FROM products WHERE slug = ?', ['product-1'], ''),
        false
    );
    product_same($legacyPrimaryInGallery[0]['file'], (string)sh_val('SELECT image FROM products WHERE slug = ?', ['product-1'], ''), 'the old single-image snapshot remains the gallery primary');
    $aiSource = source('ai/ProductStudio.php');
    product_ok(str_contains($aiSource, 'INSERT INTO product_images') && str_contains($aiSource, "\$data['image'] = \$imageFile;"), 'AI Auto Work still writes its gallery and products.image primary');
    product_ok(str_contains(source('product.php'), 'sh_seo_product_schema($product, $shProductMeta, $galleryImages)'), 'SEO structured data still receives every gallery image');
    product_ok(str_contains(source('includes/product-card.php'), 'sh_product_image($p[\'image\'])'), 'category/product cards still use the primary snapshot');
    product_ok(str_contains(source('includes/catalog.php'), 'product-by-slug-compat'), 'product URLs retain a pre-migration query fallback');
    $noImageId = sh_insert('products', ['name' => 'No Photo', 'slug' => 'no-photo', 'status' => 1, 'stock' => 1, 'price' => 10]);
    $noImagePlan = sh_product_gallery_plan([], [], [], false);
    product_ok($noImagePlan['ok'], 'an existing product with no image can still be edited');
    $emptyFiles = sh_product_gallery_commit($noImageId, $noImagePlan, []);
    product_same([], $emptyFiles, 'an empty legacy gallery commit deletes no files');
    product_same(null, sh_val('SELECT image FROM products WHERE id = ?', [$noImageId], ''), 'no-image legacy product was not given a placeholder');
    product_ok(str_contains(source('install/schema.php'), 'uq_products_draft_token'), 'AI draft-token idempotency index was preserved');

    // P16 ---------------------------------------------------------------
    product_case('P16', 'No autoplay/timers/cropping; admin gallery, colour and SEO preview scripts are wired');
    $appJs = source('assets/js/app.js');
    $galleryMatch = [];
    preg_match('~Product gallery \(manual only.*?/\* ---------- Customer preferred colour~s', $appJs, $galleryMatch);
    $galleryJs = $galleryMatch[0] ?? '';
    product_ok($galleryJs !== '', 'manual customer-gallery code block was found');
    product_ok(!str_contains($galleryJs, 'setInterval') && !str_contains($galleryJs, 'setTimeout') && !str_contains($galleryJs, 'autoplay'), 'customer gallery has no timer or autoplay');
    product_ok(str_contains($galleryJs, 'touchend') && str_contains($galleryJs, 'pointerup') && str_contains($galleryJs, 'data-gallery-next'), 'manual swipe and prev/next controls are present');
    $css = source('assets/css/app.css');
    product_ok(str_contains($css, '.sh-gallery__main img { display: block; width: 100%; height: 100%; max-width: 100%; max-height: 100%; object-fit: contain;'), 'PDP gallery image is contained, never cropped or stretched');
    product_ok(str_contains($css, '.sh-pg__preview img { width: 100%; height: 100%; object-fit: contain;'), 'admin gallery preview is contained');
    product_ok(str_contains($css, '.sh-product-card__img { width: 100%; height: 100%; object-fit: contain;'), 'product card preview is contained');
    product_ok(str_contains(source('admin/product-edit.php'), "\$adminScripts = ['product_form' => 'assets/js/product-form.js']"), 'product editor registers its compact gallery script');
    product_ok(str_contains(source('admin/_footer.php'), '($adminScripts ?? [])'), 'admin footer loads registered scripts');
    product_ok(str_contains(source('admin/_seo-fields.php'), "\$adminScripts['seo'] = 'assets/js/seo.js'"), 'SEO partial still registers its preview script');
    product_ok(str_contains(source('assets/js/seo.js'), 'sh-product-image-picked') && str_contains(source('assets/js/product-form.js'), 'sh-product-image-picked'), 'primary-image preview event is wired in both scripts');
    product_ok(!str_contains(source('assets/js/product-form.js'), 'setInterval') && !str_contains(source('assets/js/product-form.js'), 'autoplay'), 'admin gallery editor has no timer or autoplay');

    echo 'Product management integration: ' . $checks . ' checks, ' . count($failures) . ' failures, ' . count($caseIds) . " cases\n";
    foreach ($failures as $failure) { echo 'FAIL: ' . $failure . "\n"; }
    exit($failures === [] && count($caseIds) === 16 ? 0 : 1);
} catch (Throwable $e) {
    echo 'Product management integration ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
