<?php
/**
 * AI Auto Work — "Generate Full Product" persistence.
 *
 * Generation and saving are two different jobs, and this file owns the second
 * one. The draft endpoint only produces JSON; nothing reaches the database until
 * sh_ai_save_full_product() is called with the reviewed values. That function
 * is deliberately all-or-nothing:
 *
 *   1. every value is validated again on the server (the browser is never
 *      trusted, and the AI's answer never is);
 *   2. the product row, its tags and its gallery are written inside ONE
 *      transaction, and the row is read back before the transaction commits;
 *   3. the draft token makes a retry idempotent, so a double-click or a retried
 *      request returns the product that already exists instead of a twin;
 *   4. only after the commit does it invalidate the SEO/sitemap cache and return
 *      the real product id, slug and storefront URL.
 *
 * It reuses the existing tables (products, product_images, product_tags) and the
 * existing upload pipeline (sh_upload_image / sh_ai_store_image). No new table
 * and no new storage location is introduced.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/seo.php';

/**
 * Convert model-written HTML into the plain text the storefront prints.
 *
 * product.php renders the description with nl2br(e($description)), so saved
 * "<p>…</p>" markup would be shown to shoppers as literal tags. Paragraph and
 * list structure is therefore converted to newlines and bullets at save time,
 * while the text itself is preserved.
 */
function sh_ai_plain_text(string $html, int $max = 8000): string
{
    $s = $html;
    $s = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $s) ?? $s;
    $s = preg_replace('~</\s*(?:p|div|h[1-6]|blockquote)\s*>~i', "\n\n", $s) ?? $s;
    $s = preg_replace('~<\s*li[^>]*>~i', "\n• ", $s) ?? $s;
    $s = preg_replace('~</\s*(?:ul|ol)\s*>~i', "\n", $s) ?? $s;
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\n[ \t]+/u', "\n", $s) ?? $s;
    $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
    $s = trim($s);
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

/**
 * Split a free-form list (array or newline/comma text) into clean lines.
 *
 * @return array<int,string>
 */
function sh_ai_line_list($value, int $maxItems, int $maxLen): array
{
    if (is_string($value)) {
        $value = preg_split('/[\r\n]+/', $value) ?: [];
    }
    if (!is_array($value)) { return []; }
    $out = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) { continue; }
        $line = sh_ai_plain_text((string)$item, $maxLen);
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = trim($line, " \t\n\r\0\x0B-•");
        if ($line === '') { continue; }
        $out[] = mb_substr($line, 0, $maxLen);
        if (count($out) >= $maxItems) { break; }
    }
    return array_values(array_unique($out));
}

/**
 * A slug that follows the store's own URL rules and is unique right now.
 *
 * The manual product form builds the slug with sh_slug() and rejects a duplicate
 * instead of silently reusing it. A generated draft cannot ask the administrator
 * to resolve a collision, so the same rule is applied with a numeric suffix.
 */
function sh_ai_unique_product_slug(string $title, string $name = '', int $ignoreId = 0): string
{
    $base = sh_slug($title !== '' ? $title : $name);
    if ($base === '') { $base = 'product'; }
    $base = mb_substr($base, 0, 180);
    $slug = $base;
    $n = 2;
    while (sh_one('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $ignoreId]) !== null) {
        $slug = $base . '-' . $n++;
        if ($n > 60) {
            $slug = $base . '-' . bin2hex(random_bytes(3));
            break;
        }
    }
    return mb_substr($slug, 0, 210);
}

/** Is an image-generation provider/model actually configured and usable? */
function sh_ai_image_generation_ready(): array
{
    if (!sh_ai_installed()) {
        return ['ok' => false, 'error' => 'AI Auto Work is not installed yet.'];
    }
    $cfg = sh_ai_config();
    if (empty($cfg['has_key'])) {
        return ['ok' => false, 'error' => 'No AI provider has an API key yet.'];
    }
    $route = sh_ai_route('product_image');
    if (($route['provider'] ?? null) === null) {
        return ['ok' => false, 'error' => 'No AI provider is configured for image generation.'];
    }
    if ((string)$route['model'] === '') {
        return ['ok' => false, 'error' => 'No image model is selected. Choose one under AI Auto Work → Models.'];
    }
    if (!sh_ai_model_supports((int)$route['provider']['id'], (string)$route['model'], 'image')) {
        return ['ok' => false,
                'error' => 'The configured model cannot generate images. Pick an image-capable model in AI Auto Work → Models, or upload a product photo instead.'];
    }
    return ['ok' => true, 'provider' => (string)$route['provider']['name'], 'model' => (string)$route['model']];
}

/**
 * Generate one product photo and store it through the normal upload pipeline.
 *
 * The bytes only ever land in uploads/products with an unpredictable name, the
 * same folder every manually uploaded product image uses, so the storefront and
 * the admin editor resolve it with sh_product_image() and nothing depends on an
 * external URL that could expire.
 *
 * @return array{ok:bool,file?:string,url?:string,error?:string,prompt?:string}
 */
function sh_ai_generate_product_image(array $draft, ?int $adminId = null): array
{
    $ready = sh_ai_image_generation_ready();
    if (empty($ready['ok'])) {
        return ['ok' => false, 'error' => (string)$ready['error']];
    }

    $name = trim((string)($draft['title'] ?? $draft['name'] ?? 'product'));
    $category = trim((string)($draft['category_name'] ?? ''));
    $short = trim((string)($draft['short_description'] ?? ''));

    // A prompt from the reviewed draft, not from the provider's imagination
    // about a brand: no logos, packaging text or claims are requested.
    $prompt = 'Professional e-commerce product photograph of ' . $name
        . ($category !== '' ? ' (category: ' . $category . ')' : '') . '. '
        . ($short !== '' ? $short . ' ' : '')
        . 'Single product, centred, clean seamless white background, soft studio lighting, '
        . 'realistic materials and colours, sharp focus, commercial catalogue style. '
        . 'No text, no logos, no packaging labels, no watermark, no people, no hands.';

    $res = sh_ai_image('product_image', $prompt, [
        'admin_id'       => $adminId,
        'type'           => 'product_full_image',
        'reference_type' => 'product',
        'reference_id'   => null,
    ]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string)($res['error'] ?? 'The image generation request failed.'), 'prompt' => $prompt];
    }

    $file = sh_ai_store_image((string)($res['binary'] ?? ''));
    if ($file === null) {
        return ['ok' => false,
                'error' => 'The generated image could not be saved to uploads/products. Check the folder permissions.',
                'prompt' => $prompt];
    }

    return [
        'ok'     => true,
        'file'   => $file,
        'url'    => sh_url('uploads/products/' . rawurlencode($file)),
        'prompt' => $prompt,
        'via'    => sh_ai_via($res),
    ];
}

/**
 * Validate and normalise the values posted back by the review form.
 *
 * @return array{ok:bool,error?:string,fields?:array<string,string>,data?:array<string,mixed>}
 */
function sh_ai_full_product_input(array $in, ?int $adminId = null): array
{
    $fields = [];
    $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($in['name'] ?? ''))) ?? '');
    if ($name === '') { $fields['name'] = 'Enter the product name.'; }
    elseif (mb_strlen($name) > 190) { $fields['name'] = 'The product name must be 190 characters or fewer.'; }

    $priceRaw = trim((string)($in['price'] ?? ''));
    if ($priceRaw === '') {
        $fields['price'] = 'Enter the selling price. AI never invents a price.';
    } elseif (!is_numeric($priceRaw) || (float)$priceRaw < 0) {
        $fields['price'] = 'The selling price must be a number of 0 or more.';
    }

    $compareRaw = trim((string)($in['compare_price'] ?? ''));
    if ($compareRaw !== '' && (!is_numeric($compareRaw) || (float)$compareRaw < 0)) {
        $fields['compare_price'] = 'The compare-at price must be a number of 0 or more.';
    }

    $stockRaw = trim((string)($in['stock'] ?? '0'));
    $stock = 0;
    if ($stockRaw === '') { $stock = 0; }
    elseif (!ctype_digit($stockRaw)) { $fields['stock'] = 'Stock must be a whole number of 0 or more.'; }
    else { $stock = (int)$stockRaw; }

    $categoryId = (int)($in['category_id'] ?? 0);
    $category = $categoryId > 0 ? sh_one('SELECT id, name FROM categories WHERE id = ? LIMIT 1', [$categoryId]) : null;
    if ($categoryId <= 0) {
        $fields['category_id'] = 'Choose an existing category. AI never invents one.';
    } elseif ($category === null) {
        $fields['category_id'] = 'That category no longer exists. Choose another one.';
    }

    $brandId = (int)($in['brand_id'] ?? 0);
    if ($brandId > 0 && sh_one('SELECT id FROM brands WHERE id = ? LIMIT 1', [$brandId]) === null) {
        $fields['brand_id'] = 'That brand no longer exists.';
    }

    $title = trim((string)($in['slug'] ?? '')) !== '' ? (string)$in['slug'] : $name;
    $slug = sh_slug($title);
    if ($slug === '') { $fields['slug'] = 'Could not build a URL slug from that name.'; }
    elseif (sh_one('SELECT id FROM products WHERE slug = ? LIMIT 1', [$slug]) !== null) {
        $fields['slug'] = 'Another product already uses the URL slug "' . $slug . '".';
    }

    $sku = strtoupper(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($in['sku'] ?? '')) ?? '');
    $sku = mb_substr($sku, 0, 60);
    if ($sku !== '' && sh_one('SELECT id FROM products WHERE sku = ? LIMIT 1', [$sku]) !== null) {
        $fields['sku'] = 'Another product already uses the SKU "' . $sku . '".';
    }

    if ($fields) {
        return ['ok' => false, 'error' => 'Please fix the highlighted fields.', 'fields' => $fields];
    }

    $description = sh_ai_plain_text((string)($in['description'] ?? ''), 8000);
    $short = sh_ai_plain_text((string)($in['short_description'] ?? ''), 300);
    $specs = sh_ai_line_list($in['specifications'] ?? [], 30, 200);
    $features = sh_ai_line_list($in['features'] ?? [], 15, 240);
    $tags = sh_ai_line_list($in['tags'] ?? [], 15, 60);

    $token = strtolower(trim((string)($in['token'] ?? '')));

    return [
        'ok' => true,
        'data' => [
            'ai_draft_token'    => $token !== '' ? $token : null,
            'name'              => $name,
            'slug'              => $slug,
            'sku'               => $sku !== '' ? $sku : null,
            'category_id'       => (int)$category['id'],
            'brand_id'          => $brandId > 0 ? $brandId : null,
            'short_description' => $short !== '' ? $short : null,
            'description'       => $description !== '' ? $description : null,
            'specifications'    => $specs ? implode("\n", $specs) : null,
            'features'          => $features ? implode("\n", $features) : null,
            'image_alt'         => mb_substr(trim(sh_ai_plain_text((string)($in['image_alt'] ?? ''), 190)), 0, 190) ?: null,
            'price'             => (float)$priceRaw,
            'compare_price'     => $compareRaw !== '' ? (float)$compareRaw : 0.0,
            'stock'             => $stock,
            'low_stock_threshold' => max(0, (int)($in['low_stock_threshold'] ?? 5)),
            'status'            => !empty($in['status']) ? 1 : 0,
            'meta_title'        => mb_substr(trim(sh_ai_plain_text((string)($in['meta_title'] ?? ''), 190)), 0, 190) ?: null,
            'meta_description'  => mb_substr(trim(sh_ai_plain_text((string)($in['meta_description'] ?? ''), 300)), 0, 300) ?: null,
            'meta_keywords'     => mb_substr(trim(sh_ai_plain_text((string)($in['keywords'] ?? ''), 300)), 0, 300) ?: null,
            'seo_focus_keyword' => mb_substr(trim(sh_ai_plain_text((string)($in['focus_keyword'] ?? ''), 120)), 0, 120) ?: null,
            'seo_related_phrases' => mb_substr(trim(sh_ai_plain_text((string)($in['related_phrases'] ?? ''), 500)), 0, 500) ?: null,
            'seo_noindex'       => 0,
            '_tags'             => $tags,
            '_gallery'          => sh_ai_line_list($in['gallery'] ?? [], 6, 190),
        ],
    ];
}

/**
 * Create one product from a reviewed AI draft.
 *
 * @param array $in    the reviewed values (see sh_ai_full_product_input)
 * @param int|null $adminId
 * @param array|null $files upload array; defaults to $_FILES
 * @return array{ok:bool,error?:string,fields?:array<string,string>,id?:int,slug?:string,url?:string,admin_url?:string,already_saved?:bool,verify?:array<string,mixed>}
 */
function sh_ai_save_full_product(array $in, ?int $adminId = null, ?array $files = null): array
{
    $files = $files ?? $_FILES;
    if (function_exists('sh_product_schema_ensure')) { sh_product_schema_ensure(); }
    // The SEO columns (meta_keywords, seo_focus_keyword, seo_related_phrases,
    // seo_noindex) are added by the SEO migration on an existing store. Saving
    // writes them, so the save path must own that migration instead of relying
    // on the administrator having opened a page that happens to run it first.
    // Both migrations run outside the transaction and are idempotent.
    if (function_exists('sh_seo_schema_ensure')) { sh_seo_schema_ensure(); }

    $token = strtolower(trim((string)($in['token'] ?? '')));
    if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        return ['ok' => false, 'error' => 'That draft reference is not valid. Generate the product again.'];
    }

    // Idempotency first: a retried request must return the product it already
    // created, not a second one with a "-2" slug.
    if ($token !== '') {
        try {
            $existing = sh_one('SELECT id, name, slug, status FROM products WHERE ai_draft_token = ? LIMIT 1', [$token]);
        } catch (Throwable $e) {
            $existing = null;
        }
        if ($existing !== null) {
            $id = (int)$existing['id'];
            return [
                'ok'            => true,
                'already_saved' => true,
                'id'            => $id,
                'slug'          => (string)$existing['slug'],
                'url'           => sh_url('product.php?slug=' . urlencode((string)$existing['slug'])),
                'admin_url'     => sh_url('admin/product-edit.php?edit=' . $id),
                'verify'        => ['name' => (string)$existing['name'], 'status' => (int)$existing['status']],
                'message'       => 'This draft was already saved. Opening the existing product instead of creating a duplicate.',
            ];
        }
    }

    $validated = sh_ai_full_product_input($in, $adminId);
    if (empty($validated['ok'])) {
        return $validated;
    }
    $data = (array)$validated['data'];
    $tags = (array)$data['_tags'];
    $gallery = (array)$data['_gallery'];
    unset($data['_tags'], $data['_gallery']);

    // --- Featured image ----------------------------------------------------
    // Either the photo generated with the draft (already in uploads/products,
    // named by sh_ai_store_image) or a file the administrator picked here.
    $imageFile = null;
    $uploadedNow = false;
    $galleryFiles = [];

    if (!empty($files['image']['name'])) {
        $up = sh_upload_image((array)$files['image'], 'products');
        if (empty($up['ok'])) {
            return ['ok' => false, 'error' => (string)($up['error'] ?? 'The image could not be uploaded.'), 'fields' => ['image' => (string)($up['error'] ?? 'The image could not be uploaded.')]];
        }
        $imageFile = (string)$up['file'];
        $uploadedNow = true;
    } elseif (trim((string)($in['image_file'] ?? '')) !== '') {
        $candidate = basename(trim((string)$in['image_file']));
        if (preg_match('/^ai-\d{8}-[a-f0-9]{16}\.(?:png|jpg|webp)$/', $candidate) !== 1
            || !is_file(SH_UPLOAD_DIR . '/products/' . $candidate)) {
            return ['ok' => false, 'error' => 'The generated image is no longer on the server. Generate it again or upload a photo.'];
        }
        $imageFile = $candidate;
    }

    // Gallery: only files that came from this draft's generation run (same
    // naming rule), and only ones that really exist on disk.
    foreach ((array)($in['gallery_files'] ?? []) as $g) {
        $candidate = basename((string)$g);
        if (preg_match('/^ai-\d{8}-[a-f0-9]{16}\.(?:png|jpg|webp)$/', $candidate) === 1
            && is_file(SH_UPLOAD_DIR . '/products/' . $candidate)) {
            $galleryFiles[] = $candidate;
        }
        if (count($galleryFiles) >= 4) { break; }
    }
    if ($galleryFiles) { $gallery = $galleryFiles; }
    $gallery = array_values(array_filter($gallery, static function ($f): bool {
        $name = basename((string)$f);
        return preg_match('/^ai-\d{8}-[a-f0-9]{16}\.(?:png|jpg|webp)$/', $name) === 1
            && is_file(SH_UPLOAD_DIR . '/products/' . $name);
    }));
    $gallery = array_map(static fn($f): string => basename((string)$f), array_slice($gallery, 0, 4));

    if ($imageFile !== null) { $data['image'] = $imageFile; }

    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'error' => 'Another operation is already saving. Please try again.'];
    }

    $id = 0;
    try {
        $pdo->beginTransaction();
        $id = sh_insert('products', $data);

        if ($tags && sh_table_exists('product_tags')) {
            $st = $pdo->prepare('INSERT IGNORE INTO product_tags (product_id, tag) VALUES (?,?)');
            foreach ($tags as $tag) {
                $st->execute([$id, mb_substr((string)$tag, 0, 60)]);
            }
        }
        if ($gallery) {
            $st = $pdo->prepare('INSERT INTO product_images (product_id, image, sort_order) VALUES (?,?,?)');
            foreach ($gallery as $i => $file) {
                $st->execute([$id, $file, $i]);
            }
        }

        // Read the row back through the same connection before committing. A
        // save is only reported as successful when the database really holds it.
        $row = sh_one('SELECT * FROM products WHERE id = ? LIMIT 1', [$id]);
        if ($row === null
            || (string)$row['name'] !== (string)$data['name']
            || (string)$row['slug'] !== (string)$data['slug']
            || (int)$row['category_id'] !== (int)$data['category_id']) {
            throw new RuntimeException('The saved product could not be verified after the insert.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Only a file uploaded by THIS request is removed; a generated image is
        // kept so the administrator can retry the save without paying for it again.
        if ($uploadedNow && $imageFile !== null) { sh_delete_upload($imageFile, 'products'); }
        sh_log_exception($e, 'ai-product-save');

        // A concurrent request may have won the token race: answer idempotently.
        if ($token !== '') {
            try {
                $again = sh_one('SELECT id, name, slug, status FROM products WHERE ai_draft_token = ? LIMIT 1', [$token]);
                if ($again !== null) {
                    return ['ok' => true, 'already_saved' => true, 'id' => (int)$again['id'],
                            'slug' => (string)$again['slug'],
                            'url' => sh_url('product.php?slug=' . urlencode((string)$again['slug'])),
                            'admin_url' => sh_url('admin/product-edit.php?edit=' . (int)$again['id']),
                            'verify' => ['name' => (string)$again['name'], 'status' => (int)$again['status']]];
                }
            } catch (Throwable $ignored) { /* fall through to the real error */ }
        }
        // The browser gets a truthful, generic message: the SQL detail stays in
        // logs/app-*.log where an administrator can read it.
        return ['ok' => false,
                'error' => 'The product was not saved, so nothing was created. The database error has been logged.'];
    }

    // --- Post-commit bookkeeping ------------------------------------------
    try {
        if ($id > 0 && (int)$data['stock'] > 0) {
            sh_stock_adjust($id, (int)$data['stock'], 'Initial stock from AI Auto Work', 'initial');
        }
    } catch (Throwable $e) { sh_log_exception($e, 'ai-product-stock'); }
    try {
        sh_audit('product_created', 'product', $id, (string)$data['name'], null,
            ['price' => $data['price'], 'stock' => $data['stock'], 'type' => 'physical', 'source' => 'ai_auto_work']);
    } catch (Throwable $e) { sh_log_exception($e, 'ai-product-audit'); }
    if (function_exists('sh_stock_check_alert')) {
        try { sh_stock_check_alert($id); } catch (Throwable $e) { /* advisory only */ }
    }
    if (function_exists('sh_seo_cache_invalidate')) {
        try { sh_seo_cache_invalidate(); } catch (Throwable $e) { sh_log_exception($e, 'ai-product-cache'); }
    }
    if (function_exists('sh_log_line')) {
        sh_log_line('admin', 'AI Auto Work created product #' . $id . ' ("' . $data['name'] . '")');
    }

    $savedTags = [];
    if ($tags && sh_table_exists('product_tags')) {
        try { $savedTags = array_column(sh_all('SELECT tag FROM product_tags WHERE product_id = ? ORDER BY tag', [$id]), 'tag'); }
        catch (Throwable $e) { $savedTags = []; }
    }
    $savedGallery = [];
    try { $savedGallery = array_column(sh_all('SELECT image FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]), 'image'); }
    catch (Throwable $e) { $savedGallery = []; }

    return [
        'ok'            => true,
        'already_saved' => false,
        'id'            => $id,
        'slug'          => (string)$data['slug'],
        'url'           => sh_url('product.php?slug=' . urlencode((string)$data['slug'])),
        'admin_url'     => sh_url('admin/product-edit.php?edit=' . $id),
        'image'         => $imageFile,
        'image_url'     => $imageFile !== null ? sh_url('uploads/products/' . rawurlencode($imageFile)) : '',
        'tags'          => $savedTags,
        'gallery'       => $savedGallery,
        'verify'        => [
            'name'              => (string)$data['name'],
            'slug'              => (string)$data['slug'],
            'category_id'       => (int)$data['category_id'],
            'price'             => (float)$data['price'],
            'stock'             => (int)$data['stock'],
            'status'            => (int)$data['status'],
            'has_description'   => $data['description'] !== null,
            'has_specifications'=> $data['specifications'] !== null,
            'has_features'      => $data['features'] !== null,
            'image'             => $imageFile,
            'tag_count'         => count($savedTags),
            'gallery_count'     => count($savedGallery),
        ],
        'message'       => 'Product saved. You can open it in the storefront or edit it in the admin panel.',
    ];
}
