<?php
/**
 * Product catalogue extensions: gallery, brand/catalog type and structured
 * colour options.
 *
 * The existing `products.image` column is kept as the primary-image snapshot so
 * every old query, order line, notification and card remains compatible. The
 * relational `product_images` rows are the source of truth for up to five
 * images. The legacy `products.product_type` ENUM('physical','digital') keeps
 * its original fulfilment meaning; the new `catalog_type_id` is the shopper-
 * facing classification such as Handbag, T-Shirt or Watch.
 */
declare(strict_types=1);

const SH_PRODUCT_MAX_IMAGES = 5;
const SH_PRODUCT_MAX_IMAGE_BYTES = 3145728; // 3 MB
const SH_PRODUCT_CATALOG_SCHEMA_VERSION = 2;

/** Additive, idempotent MySQL/MariaDB migration for existing installations. */
function sh_product_catalog_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    try {
        if ((int)sh_setting('product_catalog_schema_v', '0') >= SH_PRODUCT_CATALOG_SCHEMA_VERSION) { return; }
        $pdo = sh_db();
        // MySQL DDL commits implicitly. Let a later call in this same request
        // perform the migration once the checkout transaction has finished.
        if ($pdo->inTransaction()) { $done = false; return; }

        $tableExists = static function (string $table) use ($pdo): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        };
        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        };
        $indexExists = static function (string $table, string $index) use ($pdo): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $st->execute([$table, $index]);
            return (int)$st->fetchColumn() > 0;
        };
        $constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = ?
                   AND constraint_name = ? AND constraint_type = \'FOREIGN KEY\''
            );
            $st->execute([$table, $constraint]);
            return (int)$st->fetchColumn() > 0;
        };

        if (!$tableExists('product_images')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT UNSIGNED NOT NULL,
                image VARCHAR(190) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pimages_product (product_id),
                CONSTRAINT fk_pimages_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$tableExists('product_types')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ptypes_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$tableExists('product_colours')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_colours (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL,
                hex CHAR(7) NOT NULL DEFAULT '#888888',
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pcolours_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!$tableExists('product_colour_selections')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS product_colour_selections (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT UNSIGNED NOT NULL,
                colour_id INT UNSIGNED DEFAULT NULL,
                custom_name VARCHAR(120) DEFAULT NULL,
                custom_hex CHAR(7) DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pcsel_product (product_id),
                KEY idx_pcsel_colour (colour_id),
                CONSTRAINT fk_pcsel_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                CONSTRAINT fk_pcsel_colour FOREIGN KEY (colour_id) REFERENCES product_colours (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$columnExists('products', 'catalog_type_id')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN catalog_type_id INT UNSIGNED DEFAULT NULL AFTER brand_id');
        }
        if (!$indexExists('products', 'idx_products_catalog_type')) {
            try { $pdo->exec('ALTER TABLE products ADD KEY idx_products_catalog_type (catalog_type_id)'); }
            catch (Throwable $e) { sh_product_catalog_log('Could not add catalog type index: ' . $e->getMessage()); }
        }
        // A foreign key is a hardening guarantee, not a requirement for the
        // read path. Older imports may contain orphan IDs, so a failed add is
        // logged and the application still safely resolves labels. Check the
        // actual constraint name as well as a possible auto-created index:
        // canonical schema can already have the FK without that index name.
        if (!$constraintExists('products', 'fk_products_catalog_type')
            && !$indexExists('products', 'fk_products_catalog_type')) {
            try {
                $pdo->exec('ALTER TABLE products ADD CONSTRAINT fk_products_catalog_type
                    FOREIGN KEY (catalog_type_id) REFERENCES product_types (id) ON DELETE SET NULL');
            } catch (Throwable $e) {
                sh_product_catalog_log('Catalog type foreign key not added: ' . $e->getMessage());
            }
        }

        // Cart lines become variant-aware so a selected colour survives into
        // order_items.product_variant. Existing rows use an empty variant.
        if (!$columnExists('cart_items', 'variant')) {
            $pdo->exec("ALTER TABLE cart_items ADD COLUMN variant VARCHAR(190) NOT NULL DEFAULT '' AFTER product_id");
        }
        if ($indexExists('cart_items', 'uq_cart_product')) {
            // InnoDB uses the old unique key's leading cart_id column to index
            // fk_citems_cart. Create an explicit plain index first; otherwise
            // MySQL 1553 refuses to drop the unique key and the old product-only
            // constraint would still reject a second colour line.
            if (!$indexExists('cart_items', 'idx_citems_cart')) {
                try { $pdo->exec('ALTER TABLE cart_items ADD KEY idx_citems_cart (cart_id)'); }
                catch (Throwable $e) { sh_product_catalog_log('Could not add cart FK support index: ' . $e->getMessage()); }
            }
            if ($indexExists('cart_items', 'idx_citems_cart')) {
                try { $pdo->exec('ALTER TABLE cart_items DROP INDEX uq_cart_product'); }
                catch (Throwable $e) { sh_product_catalog_log('Could not replace cart unique key: ' . $e->getMessage()); }
            } else {
                sh_product_catalog_log('Could not replace cart unique key: no supporting cart_id index');
            }
        }
        if (!$indexExists('cart_items', 'uq_cart_product_variant')) {
            try {
                $pdo->exec('ALTER TABLE cart_items ADD UNIQUE KEY uq_cart_product_variant (cart_id, product_id, variant)');
            } catch (Throwable $e) {
                sh_product_catalog_log('Could not add variant unique key: ' . $e->getMessage());
            }
        }

        // Back-fill every legacy one-image product into the relational gallery.
        // This is deliberately additive: no existing products.image is removed.
        $pdo->exec("INSERT INTO product_images (product_id, image, sort_order)
            SELECT p.id, p.image, 0 FROM products p
            WHERE p.image IS NOT NULL AND p.image <> ''
              AND NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id)");

        sh_product_catalog_seed($pdo);
        if (function_exists('sh_setting_save')) {
            sh_setting_save('product_catalog_schema_v', (string)SH_PRODUCT_CATALOG_SCHEMA_VERSION);
        }
    } catch (Throwable $e) {
        sh_product_catalog_log('Schema migration failed: ' . $e->getMessage());
    }
}

function sh_product_catalog_log(string $message): void
{
    if (function_exists('sh_log_line')) { sh_log_line('product-catalog', $message); }
}

/** Seed only when the dictionaries are empty; never overwrite admin edits. */
function sh_product_catalog_seed(PDO $pdo): void
{
    $types = [
        'Handbag', 'Shoulder Bag', 'T-Shirt', 'Shoes', 'Watch',
        'Accessories', 'Electronics', 'Home & Living',
    ];
    $st = $pdo->query('SELECT COUNT(*) FROM product_types');
    if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO product_types (name, slug, status) VALUES (?, ?, 1)');
        foreach ($types as $name) {
            $slug = function_exists('sh_slug') ? sh_slug($name) : strtolower(str_replace(' ', '-', $name));
            $ins->execute([$name, $slug]);
        }
    }
    $colours = [
        ['Black', '#111827'], ['White', '#f8fafc'], ['Red', '#dc2626'],
        ['Blue', '#2563eb'], ['Navy', '#1e3a8a'], ['Green', '#16a34a'],
        ['Yellow', '#facc15'], ['Pink', '#ec4899'], ['Purple', '#7c3aed'],
        ['Brown', '#92400e'], ['Grey', '#6b7280'], ['Beige', '#d6c3a5'],
        ['Orange', '#f97316'], ['Silver', '#c0c4cc'], ['Gold', '#d4af37'],
    ];
    $st = $pdo->query('SELECT COUNT(*) FROM product_colours');
    if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO product_colours (name, slug, hex, status) VALUES (?, ?, ?, 1)');
        foreach ($colours as [$name, $hex]) {
            $slug = function_exists('sh_slug') ? sh_slug($name) : strtolower($name);
            $ins->execute([$name, $slug, $hex]);
        }
    }
}

/** Active brands, types and common colours for admin selects. */
function sh_product_brands_all(bool $includeInactive = false): array
{
    return sh_all('SELECT id, name, status FROM brands' . ($includeInactive ? '' : ' WHERE status = 1') . ' ORDER BY name ASC');
}
function sh_product_types_all(bool $includeInactive = false): array
{
    return sh_all('SELECT id, name, status FROM product_types' . ($includeInactive ? '' : ' WHERE status = 1') . ' ORDER BY name ASC');
}
function sh_product_colours_all(bool $includeInactive = false): array
{
    return sh_all('SELECT id, name, slug, hex, status FROM product_colours' . ($includeInactive ? '' : ' WHERE status = 1') . ' ORDER BY name ASC');
}

/** Resolve optional display labels without emitting empty sections. */
function sh_product_brand_name($id): string
{
    $id = (int)$id;
    if ($id <= 0) { return ''; }
    return (string)sh_val('SELECT name FROM brands WHERE id = ? AND status = 1 LIMIT 1', [$id], '');
}
function sh_product_catalog_type_name($id): string
{
    $id = (int)$id;
    if ($id <= 0) { return ''; }
    return (string)sh_val('SELECT name FROM product_types WHERE id = ? AND status = 1 LIMIT 1', [$id], '');
}

function sh_product_catalog_has_column(string $table, string $column): bool
{
    return function_exists('sh_table_has_column') && sh_table_has_column($table, $column);
}

function sh_product_clean_hex($value): string
{
    $hex = strtoupper(trim((string)$value));
    if ($hex !== '' && $hex[0] !== '#') { $hex = '#' . $hex; }
    return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : '#888888';
}

/** @return array{brand:string,type:string,colours:array} */
function sh_product_catalog_display(int $productId, array $product = []): array
{
    if (array_key_exists('brand_name', $product)) {
        $brand = trim((string)$product['brand_name']);
    } else {
        $brandId = $product['brand_id'] ?? sh_val('SELECT brand_id FROM products WHERE id = ?', [$productId], 0);
        $brand = sh_product_brand_name($brandId);
    }
    if (array_key_exists('catalog_type_name', $product)) {
        $type = trim((string)$product['catalog_type_name']);
    } else {
        $typeId = $product['catalog_type_id'] ?? 0;
        if (!array_key_exists('catalog_type_id', $product) && sh_product_catalog_has_column('products', 'catalog_type_id')) {
            $typeId = sh_val('SELECT catalog_type_id FROM products WHERE id = ?', [$productId], 0);
        }
        $type = sh_product_catalog_type_name($typeId);
    }
    return [
        'brand' => $brand,
        'type' => $type,
        'colours' => sh_product_colour_options($productId),
    ];
}

/** Slug-based create helpers used for the inline admin "add new" fields. */
function sh_product_catalog_unique_slug(string $table, string $name): string
{
    $base = function_exists('sh_slug') ? sh_slug($name) : strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? ''));
    if ($base === '') { $base = 'item'; }
    $slug = mb_substr($base, 0, 140);
    $suffix = 2;
    // Fixed SQL per allowlisted dictionary table; no request value reaches SQL.
    $existsSql = $table === 'brands'
        ? 'SELECT COUNT(*) FROM brands WHERE slug = ?'
        : 'SELECT COUNT(*) FROM product_types WHERE slug = ?';
    while ((int)sh_val($existsSql, [$slug], 0) > 0) {
        $slug = mb_substr($base, 0, 134) . '-' . $suffix++;
    }
    return $slug;
}
function sh_product_catalog_create_brand(string $name): int
{
    $name = trim(mb_substr($name, 0, 120));
    if ($name === '') { return 0; }
    return (int)sh_insert('brands', [
        'name' => $name,
        'slug' => sh_product_catalog_unique_slug('brands', $name),
        'status' => 1,
    ]);
}
function sh_product_catalog_create_type(string $name): int
{
    $name = trim(mb_substr($name, 0, 120));
    if ($name === '') { return 0; }
    return (int)sh_insert('product_types', [
        'name' => $name,
        'slug' => sh_product_catalog_unique_slug('product_types', $name),
        'status' => 1,
    ]);
}

/** Structured colour options, including per-product custom colours. */
function sh_product_colour_options(int $productId): array
{
    $rows = sh_all(
        'SELECT s.id, s.colour_id, s.custom_name, s.custom_hex, s.sort_order,
                COALESCE(c.name, s.custom_name) AS name,
                COALESCE(c.hex, s.custom_hex, "#888888") AS hex
         FROM product_colour_selections s
         LEFT JOIN product_colours c ON c.id = s.colour_id
         WHERE s.product_id = ? ORDER BY s.sort_order, s.id',
        [$productId]
    );
    return array_values(array_filter(array_map(static function (array $r): array {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') { return []; }
        return [
            'id' => (int)$r['id'],
            'name' => $name,
            'hex' => sh_product_clean_hex($r['hex'] ?? ''),
            'value' => 'Colour: ' . $name,
        ];
    }, $rows)));
}

/** Persist brand, catalog type and structured colour selections. */
function sh_product_catalog_save_relations(
    int $productId,
    $brandId,
    $catalogTypeId,
    array $colourIds,
    array $customNames,
    array $customHexes
): int {
    if ($productId <= 0) { return 0; }
    $brandId = (int)$brandId;
    $catalogTypeId = (int)$catalogTypeId;
    if ($brandId <= 0 || sh_product_brand_name($brandId) === '') { $brandId = 0; }
    if ($catalogTypeId <= 0 || sh_product_catalog_type_name($catalogTypeId) === '') { $catalogTypeId = 0; }
    if (sh_product_catalog_has_column('products', 'catalog_type_id')) {
        sh_query('UPDATE products SET brand_id = ?, catalog_type_id = ? WHERE id = ?', [
            $brandId > 0 ? $brandId : null,
            $catalogTypeId > 0 ? $catalogTypeId : null,
            $productId,
        ]);
    } else {
        sh_query('UPDATE products SET brand_id = ? WHERE id = ?', [$brandId > 0 ? $brandId : null, $productId]);
    }

    sh_query('DELETE FROM product_colour_selections WHERE product_id = ?', [$productId]);
    $sort = 0;
    $seen = [];
    foreach ($colourIds as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0 || isset($seen[$cid])) { continue; }
        $name = (string)sh_val('SELECT name FROM product_colours WHERE id = ? AND status = 1', [$cid], '');
        if ($name === '') { continue; }
        $seen[$cid] = true;
        sh_insert('product_colour_selections', [
            'product_id' => $productId,
            'colour_id' => $cid,
            'custom_name' => null,
            'custom_hex' => null,
            'sort_order' => $sort++,
        ]);
    }
    foreach ($customNames as $i => $name) {
        $name = trim(mb_substr((string)$name, 0, 120));
        if ($name === '') { continue; }
        sh_insert('product_colour_selections', [
            'product_id' => $productId,
            'colour_id' => null,
            'custom_name' => $name,
            'custom_hex' => sh_product_clean_hex($customHexes[$i] ?? '#888888'),
            'sort_order' => $sort++,
        ]);
    }
    return $sort;
}

/** Saved or just-posted colour selections for the admin form. */
function sh_product_colour_form_state(int $productId, array $post = []): array
{
    $selected = [];
    $custom = [];
    if (isset($post['colour_ids']) && is_array($post['colour_ids'])) {
        foreach ($post['colour_ids'] as $id) { $selected[(int)$id] = true; }
    } elseif ($productId > 0) {
        $rows = sh_all('SELECT colour_id FROM product_colour_selections WHERE product_id = ? AND colour_id IS NOT NULL', [$productId]);
        foreach ($rows as $r) { $selected[(int)$r['colour_id']] = true; }
    }
    if (isset($post['custom_colour_name']) && is_array($post['custom_colour_name'])) {
        foreach ($post['custom_colour_name'] as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') { continue; }
            $custom[] = ['name' => mb_substr($name, 0, 120), 'hex' => sh_product_clean_hex($post['custom_colour_hex'][$i] ?? '#888888')];
        }
    } elseif ($productId > 0) {
        $rows = sh_all('SELECT custom_name, custom_hex FROM product_colour_selections WHERE product_id = ? AND colour_id IS NULL AND custom_name IS NOT NULL ORDER BY sort_order, id', [$productId]);
        foreach ($rows as $r) {
            $name = trim((string)$r['custom_name']);
            if ($name !== '') { $custom[] = ['name' => $name, 'hex' => sh_product_clean_hex($r['custom_hex'] ?? '')]; }
        }
    }
    return ['selected' => $selected, 'custom' => $custom];
}

/** Validate a customer's preferred colour against the product's real options. */
function sh_product_colour_variant(int $productId, string $variant): array
{
    $variant = trim(mb_substr($variant, 0, 190));
    $options = sh_product_colour_options($productId);
    if ($variant === '') {
        return ['ok' => true, 'variant' => $options !== [] ? (string)$options[0]['value'] : ''];
    }
    foreach ($options as $option) {
        if (hash_equals((string)$option['value'], $variant)) {
            return ['ok' => true, 'variant' => $variant];
        }
    }
    return ['ok' => false, 'variant' => '', 'error' => 'Please choose an available colour.'];
}

function sh_product_image_exists(string $file): bool
{
    $name = basename(trim($file));
    return $name !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1
        && is_file(SH_UPLOAD_DIR . '/products/' . $name);
}
function sh_product_existing_image_url(string $file): string
{
    return sh_product_image_exists($file) && function_exists('sh_product_image') ? sh_product_image($file) : '';
}

/** Normalise a PHP multiple-file array into individual file records. */
function sh_product_files_flat($file): array
{
    if (!is_array($file) || !isset($file['name'])) { return []; }
    if (!is_array($file['name'])) { return [$file]; }
    $out = [];
    foreach ($file['name'] as $key => $name) {
        $out[] = [
            'name' => $name,
            'type' => $file['type'][$key] ?? '',
            'tmp_name' => $file['tmp_name'][$key] ?? '',
            'error' => $file['error'][$key] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][$key] ?? 0,
        ];
    }
    return $out;
}

/** Normalise image_replace[id] files into an id-keyed map. */
function sh_product_files_keyed($file): array
{
    if (!is_array($file) || !isset($file['name']) || !is_array($file['name'])) { return []; }
    $out = [];
    foreach ($file['name'] as $id => $name) {
        $out[(int)$id] = [
            'name' => $name,
            'type' => $file['type'][$id] ?? '',
            'tmp_name' => $file['tmp_name'][$id] ?? '',
            'error' => $file['error'][$id] ?? UPLOAD_ERR_NO_FILE,
            'size' => $file['size'][$id] ?? 0,
        ];
    }
    return $out;
}

function sh_product_upload_image(array $file): array
{
    // Test hook lets the PHP-WASM/SQLite harness exercise real validation
    // without a web server's is_uploaded_file() context. Production always
    // uses the shipped MIME/size/dimension validator.
    if (function_exists('sh_test_product_upload_image')) {
        return sh_test_product_upload_image($file);
    }
    $limit = function_exists('sh_server_upload_limit') ? (int)sh_server_upload_limit() : 0;
    $max = SH_PRODUCT_MAX_IMAGE_BYTES;
    if ($limit > 0) { $max = min($max, $limit); }
    return sh_upload_image($file, 'products', $max);
}

function sh_product_delete_image_file(string $file): void
{
    $name = basename(trim($file));
    if ($name === '' || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) { return; }
    $path = SH_UPLOAD_DIR . '/products/' . $name;
    if (!is_file($path)) { return; }
    if (function_exists('unlink') && !@unlink($path)) {
        sh_product_catalog_log('Could not delete unused product image ' . $name);
    }
}

/** Delete an orphaned file only when neither gallery nor legacy snapshot uses it. */
function sh_product_delete_unreferenced_image(string $file): void
{
    $name = basename(trim($file));
    if ($name === '') { return; }
    $gallery = (int)sh_val('SELECT COUNT(*) FROM product_images WHERE image = ?', [$name], 0);
    $legacy = (int)sh_val('SELECT COUNT(*) FROM products WHERE image = ?', [$name], 0);
    if ($gallery === 0 && $legacy === 0) { sh_product_delete_image_file($name); }
}

/** Count-only validation so a form with other errors never leaves orphan files. */
function sh_product_gallery_submission_errors(array $existingRows, array $post, array $files, bool $requireAtLeastOne = true): array
{
    $remove = isset($post['image_remove']) && is_array($post['image_remove'])
        ? array_map('intval', array_keys($post['image_remove'])) : [];
    $kept = 0;
    foreach ($existingRows as $row) {
        if (!in_array((int)($row['id'] ?? 0), $remove, true)) { $kept++; }
    }
    $newFiles = array_values(array_filter(
        sh_product_files_flat($files['images'] ?? []),
        static fn(array $f): bool => trim((string)($f['name'] ?? '')) !== ''
    ));
    $count = $kept + count($newFiles);
    if ($count > SH_PRODUCT_MAX_IMAGES) {
        return ['A product can have at most ' . SH_PRODUCT_MAX_IMAGES . ' images. Remove an existing image or upload fewer new files.'];
    }
    if ($requireAtLeastOne && $count < 1) { return ['Upload at least one product image.']; }
    return [];
}

/**
 * Validate and upload a complete desired gallery without mutating the DB.
 * The `image_row[]` sequence carries the admin's manual order; it is an
 * allowlist of server-side tokens, never a path or an uploaded filename.
 * @return array{ok:bool,errors:array,rows:array,primary:string,uploaded:array}
 */
function sh_product_gallery_plan(array $existingRows, array $post, array $files, bool $requireAtLeastOne = true): array
{
    $remove = isset($post['image_remove']) && is_array($post['image_remove'])
        ? array_map('intval', array_keys($post['image_remove'])) : [];
    $replaceFiles = sh_product_files_keyed($files['image_replace'] ?? []);
    $newFiles = array_values(array_filter(
        sh_product_files_flat($files['images'] ?? []),
        static fn(array $f): bool => trim((string)($f['name'] ?? '')) !== ''
    ));
    $errors = sh_product_gallery_submission_errors($existingRows, $post, $files, $requireAtLeastOne);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'rows' => [], 'primary' => '', 'uploaded' => []];
    }

    $kept = [];
    foreach ($existingRows as $row) {
        $id = (int)($row['id'] ?? 0);
        if (in_array($id, $remove, true)) { continue; }
        $kept['e:' . $id] = [
            'id' => $id,
            'image' => basename((string)($row['image'] ?? $row['file'] ?? '')),
            'old_file' => '',
        ];
    }
    $new = [];
    foreach ($newFiles as $i => $file) {
        $new['n:' . $i] = $file;
    }

    $sequence = [];
    if (isset($post['image_row']) && is_array($post['image_row'])) {
        foreach ($post['image_row'] as $token) {
            $token = (string)$token;
            if (isset($kept[$token]) || isset($new[$token])) { $sequence[] = $token; }
        }
    }
    // A legacy form or a browser without the enhancement still gets a stable
    // order: saved images first, then new uploads in their natural order.
    foreach (array_keys($kept) as $token) {
        if (!in_array($token, $sequence, true)) { $sequence[] = $token; }
    }
    foreach (array_keys($new) as $token) {
        if (!in_array($token, $sequence, true)) { $sequence[] = $token; }
    }
    $sequence = array_slice($sequence, 0, SH_PRODUCT_MAX_IMAGES);

    $uploaded = [];
    $rows = [];
    foreach ($sequence as $sort => $token) {
        if (isset($kept[$token])) {
            $item = $kept[$token];
            $id = (int)$item['id'];
            $file = (string)$item['image'];
            $oldFile = '';
            if (isset($replaceFiles[$id]) && trim((string)$replaceFiles[$id]['name']) !== '') {
                $up = sh_product_upload_image($replaceFiles[$id]);
                if (empty($up['ok'])) {
                    $errors[] = $up['error'] ?? 'A replacement image could not be uploaded.';
                    continue;
                }
                $uploaded[] = (string)$up['file'];
                $oldFile = $file;
                $file = (string)$up['file'];
            }
            $rows[] = [
                'id' => $id,
                'image' => $file,
                'sort' => $sort,
                'old_file' => $oldFile,
            ];
        } elseif (isset($new[$token])) {
            $up = sh_product_upload_image($new[$token]);
            if (empty($up['ok'])) {
                $errors[] = $up['error'] ?? 'An image could not be uploaded.';
                continue;
            }
            $uploaded[] = (string)$up['file'];
            $rows[] = [
                'id' => 0,
                'image' => (string)$up['file'],
                'sort' => $sort,
                'old_file' => '',
            ];
        }
    }
    if ($errors || ($requireAtLeastOne && !$rows) || count($rows) > SH_PRODUCT_MAX_IMAGES) {
        foreach ($uploaded as $file) { sh_product_delete_image_file($file); }
        return [
            'ok' => false,
            'errors' => $errors ?: ['Upload at least one valid product image.'],
            'rows' => [], 'primary' => '', 'uploaded' => [],
        ];
    }
    foreach ($rows as $i => &$row) { $row['sort'] = $i; }
    unset($row);
    return [
        'ok' => true,
        'errors' => [],
        'rows' => $rows,
        'primary' => $rows !== [] ? (string)$rows[0]['image'] : '',
        'uploaded' => $uploaded,
    ];
}

/** Commit a validated plan and keep products.image in sync with the first row. */
/**
 * Commit a validated plan. When called inside a DB transaction, pass
 * $cleanupFiles=false and delete the returned old files only AFTER commit:
 * a rolled-back save must never leave referenced photos deleted.
 *
 * @return array<int,string> unreferenced old filenames, unique and path-safe
 */
function sh_product_gallery_commit(int $productId, array $plan, array $existingRows, bool $cleanupFiles = true): array
{
    if ($productId <= 0) { return []; }
    if (empty($plan['rows'])) {
        $orphans = [];
        foreach ($existingRows as $row) {
            $file = basename((string)($row['image'] ?? $row['file'] ?? ''));
            if ($file !== '') { $orphans[] = $file; }
        }
        sh_query('DELETE FROM product_images WHERE product_id = ?', [$productId]);
        sh_query('UPDATE products SET image = NULL WHERE id = ?', [$productId]);
        $orphans = array_values(array_unique($orphans));
        if ($cleanupFiles) {
            foreach ($orphans as $file) { sh_product_delete_unreferenced_image($file); }
        }
        return $orphans;
    }
    $finalFiles = array_map(static fn(array $r): string => (string)$r['image'], $plan['rows']);
    $removedFiles = [];
    foreach ($existingRows as $row) {
        $file = basename((string)($row['image'] ?? $row['file'] ?? ''));
        if ($file !== '' && !in_array($file, $finalFiles, true)) { $removedFiles[] = $file; }
    }
    foreach ($plan['rows'] as $row) {
        if (($row['old_file'] ?? '') !== '') { $removedFiles[] = (string)$row['old_file']; }
    }

    // A complete, ordered replacement is simpler and safer than trying to
    // reconcile stale row IDs. The legacy products.image snapshot is then set
    // to the first (primary) image, so every existing card/order path works.
    sh_query('DELETE FROM product_images WHERE product_id = ?', [$productId]);
    foreach ($plan['rows'] as $row) {
        sh_insert('product_images', [
            'product_id' => $productId,
            'image' => (string)$row['image'],
            'sort_order' => (int)$row['sort'],
        ]);
    }
    sh_query('UPDATE products SET image = ? WHERE id = ?', [(string)$plan['primary'], $productId]);
    $removedFiles = array_values(array_unique($removedFiles));
    if ($cleanupFiles) {
        foreach ($removedFiles as $file) { sh_product_delete_unreferenced_image($file); }
    }
    return $removedFiles;
}

/** Existing relational rows; legacy one-image products get a virtual row. */
function sh_product_gallery_rows(int $productId, ?string $legacyImage = null, bool $backfill = false): array
{
    if ($productId <= 0) { return []; }
    $rows = sh_all(
        'SELECT id, image, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT ' . SH_PRODUCT_MAX_IMAGES,
        [$productId]
    );
    // Keep the legacy filename even if its file is missing: an old product
    // must remain editable, while the storefront filters out empty URLs.
    $legacy = $legacyImage !== null ? basename($legacyImage) : '';
    $existingFiles = array_map(static fn(array $r): string => basename((string)$r['image']), $rows);
    if ($legacy !== '' && !in_array($legacy, $existingFiles, true)
        && ($rows === [] || sh_product_image_exists($legacy))) {
        // AI drafts and older imports may have gallery rows but no row for the
        // primary snapshot. Keep that real primary first and never exceed five.
        array_unshift($rows, ['id' => 0, 'image' => $legacy, 'sort_order' => -1]);
        $rows = array_slice($rows, 0, SH_PRODUCT_MAX_IMAGES);
        if ($backfill) {
            sh_insert('product_images', ['product_id' => $productId, 'image' => $legacy, 'sort_order' => -1]);
            $rows = sh_all(
                'SELECT id, image, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT ' . SH_PRODUCT_MAX_IMAGES,
                [$productId]
            );
        }
    } elseif (!$rows && $legacy !== '') {
        $rows = [['id' => 0, 'image' => $legacy, 'sort_order' => 0]];
    }
    return array_map(static function (array $r): array {
        $file = basename((string)$r['image']);
        return [
            'id' => (int)$r['id'],
            'file' => $file,
            'image' => $file,
            'url' => sh_product_existing_image_url($file),
            'sort_order' => (int)$r['sort_order'],
        ];
    }, $rows);
}
