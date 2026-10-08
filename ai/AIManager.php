<?php
/**
 * AI Auto Work — manager.
 *
 * Owns configuration, secret storage, provider selection, generation logging
 * and the task handlers that write results into the EXISTING product, category
 * and blog tables. Nothing here fabricates a result: if the provider fails, the
 * failure is recorded and reported.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/PromptManager.php';
require_once __DIR__ . '/ProviderManager.php';

const SH_AI_MAX_BATCH = 200;

/* ------------------------------------------------------------------ *
 * Settings storage (separate table, secrets encrypted at rest)
 * ------------------------------------------------------------------ */

function sh_ai_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    $cache = [];
    try {
        foreach (sh_all('SELECT setting_key, setting_value FROM ai_settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) { /* not installed yet */ }
    return $cache;
}

function sh_ai_setting(string $key, string $default = ''): string
{
    $s = sh_ai_settings();
    $v = $s[$key] ?? null;
    return ($v === null || $v === '') ? $default : (string)$v;
}

function sh_ai_setting_save(string $key, string $value): void
{
    sh_query('INSERT INTO ai_settings (setting_key, setting_value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    sh_ai_settings(true);
}

/** Encryption key derived from the DB credentials, which live outside the web root. */
function sh_ai_secret_key(): string
{
    $cfg = sh_db_config() ?? [];
    return hash('sha256', 'shophaat-ai|' . ($cfg['name'] ?? '') . '|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''), true);
}

function sh_ai_encrypt(string $plain): string
{
    if ($plain === '') { return ''; }
    if (!function_exists('openssl_encrypt')) { return 'plain:' . $plain; }
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', sh_ai_secret_key(), OPENSSL_RAW_DATA, $iv);
    return $ct === false ? 'plain:' . $plain : 'enc:' . base64_encode($iv . $ct);
}

function sh_ai_decrypt(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') { return ''; }
    if (str_starts_with($stored, 'plain:')) { return substr($stored, 6); }
    if (!str_starts_with($stored, 'enc:')) { return $stored; }
    if (!function_exists('openssl_decrypt')) { return ''; }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) <= 16) { return ''; }
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', sh_ai_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}

/** Read a decrypted secret. Server-side use only — never echo the return value. */
function sh_ai_secret(string $key): string
{
    return sh_ai_decrypt(sh_ai_setting($key, ''));
}

function sh_ai_has_secret(string $key): bool
{
    return trim(sh_ai_setting($key, '')) !== '';
}

/** Masked form for display, e.g. sk-p••••••••3f9a. Never reveals the middle. */
function sh_ai_mask(string $key): string
{
    $v = sh_ai_secret($key);
    if ($v === '') { return ''; }
    $len = strlen($v);
    if ($len <= 10) { return str_repeat('•', max(4, $len)); }
    return substr($v, 0, 4) . str_repeat('•', 10) . substr($v, -4);
}

/** Effective configuration with sane defaults. Contains no secrets. */
function sh_ai_config(): array
{
    $default = sh_ai_default_provider_row();
    return [
        'provider'         => $default ? (string)$default['name'] : '',
        'provider_id'      => $default ? (int)$default['id'] : 0,
        'driver'           => $default ? (string)$default['driver'] : '',
        'model'            => $default ? (string)$default['default_model'] : '',
        'image_model'      => $default ? (string)$default['default_image_model'] : '',
        'temperature'      => (float)sh_ai_setting('temperature', '0.7'),
        'max_tokens'       => (int)sh_ai_setting('max_tokens', '1200'),
        'language'         => sh_ai_setting('language', 'English'),
        'tone'             => sh_ai_setting('tone', 'Professional'),
        'seo_mode'         => sh_ai_setting('seo_mode', '1') === '1',
        'auto_save'        => sh_ai_setting('auto_save', '0') === '1',
        'auto_publish'     => sh_ai_setting('auto_publish', '0') === '1',
        'batch_size'       => max(1, min(25, (int)sh_ai_setting('batch_size', '5'))),
        'max_retries'      => max(0, min(5, (int)sh_ai_setting('max_retries', '2'))),
        'fallback_enabled' => sh_ai_setting('fallback_enabled', '0') === '1',
        // True when at least one enabled provider has a key — the old "has_key" meaning.
        'has_key'          => $default !== null,
    ];
}

/**
 * Upgrade path: an install from the single-provider release has the four
 * original tables but not ai_providers/ai_models. Run the (idempotent)
 * migration once so the admin is never asked to reinstall.
 */
function sh_ai_auto_upgrade(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    if (sh_ai_installed()) { return; }
    if (!sh_ai_table_exists('ai_settings') || !sh_ai_table_exists('ai_generations')) { return; }
    try { sh_ai_migrate(); sh_ai_installed(true); } catch (Throwable $e) { sh_log_exception($e, 'ai-auto-upgrade'); }
}

/** Is the module ready to make a call? */
function sh_ai_ready(): bool
{
    return sh_ai_installed() && sh_ai_default_provider_row() !== null;
}

/* ------------------------------------------------------------------ *
 * Prompt-template wrappers over the provider layer
 * ------------------------------------------------------------------ */

/**
 * Render a template and get plain text back through the routed provider.
 *
 * @return array{ok:bool,text?:string,usage?:array,error?:string,provider?:string,model?:string}
 */
function sh_ai_generate(string $templateKey, array $vars, array $meta = []): array
{
    if (!sh_ai_installed()) {
        return ['ok' => false, 'error' => 'AI Auto Work is not installed yet. Run the installer.'];
    }
    $prompt = sh_ai_render_prompt($templateKey, $vars);
    if ($prompt === null) { return ['ok' => false, 'error' => 'Unknown prompt template: ' . $templateKey]; }
    $meta['type'] = $meta['type'] ?? $templateKey;
    return sh_ai_text($templateKey, $prompt['system'], $prompt['user'], $meta);
}

/**
 * Render a template and get a decoded JSON object back.
 *
 * @return array{ok:bool,data?:array,usage?:array,error?:string,provider?:string,model?:string}
 */
function sh_ai_generate_json(string $templateKey, array $vars, array $meta = []): array
{
    if (!sh_ai_installed()) {
        return ['ok' => false, 'error' => 'AI Auto Work is not installed yet. Run the installer.'];
    }
    $prompt = sh_ai_render_prompt($templateKey, $vars);
    if ($prompt === null) { return ['ok' => false, 'error' => 'Unknown prompt template: ' . $templateKey]; }
    $meta['type'] = $meta['type'] ?? $templateKey;
    return sh_ai_structured($templateKey, $prompt['system'], $prompt['user'], $meta);
}

/** Parse a JSON reply, tolerating code fences and surrounding prose. */
function sh_ai_parse_json(string $text): ?array
{
    return AbstractAIProvider::parseJson($text);
}

/* ------------------------------------------------------------------ *
 * Context builders — feed the model real data from the existing tables
 * ------------------------------------------------------------------ */

function sh_ai_product_context(int $productId): ?array
{
    $p = sh_one(
        'SELECT p.*, c.name AS category_name, b.name AS brand_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN brands b ON b.id = p.brand_id
         WHERE p.id = ? LIMIT 1', [$productId]
    );
    if ($p === null) { return null; }

    $details = [];
    if (trim((string)$p['short_description']) !== '') { $details[] = trim((string)$p['short_description']); }
    if (trim((string)$p['specifications']) !== '')    { $details[] = trim((string)$p['specifications']); }
    if (trim((string)$p['sku']) !== '')               { $details[] = 'SKU ' . $p['sku']; }

    return [
        'row'         => $p,
        'name'        => (string)$p['name'],
        'category'    => (string)($p['category_name'] ?? ''),
        'brand'       => (string)($p['brand_name'] ?? ''),
        'price'       => sh_money($p['price']),
        'type'        => (string)$p['product_type'],
        'description' => trim(strip_tags((string)$p['description'])),
        'specs'       => (string)$p['specifications'],
        'details'     => implode(' | ', $details),
    ];
}

/* ------------------------------------------------------------------ *
 * Task handlers — each returns a preview; saving is a separate step
 * ------------------------------------------------------------------ */

/**
 * Run one AI task against one product/category.
 *
 * @return array{ok:bool,task:string,data?:array,error?:string}
 */
function sh_ai_run_task(string $task, int $refId, ?int $adminId = null, array $extra = []): array
{
    switch ($task) {

        case 'title':
        case 'description':
        case 'short':
        case 'tags':
        case 'seo':
        case 'category':
        case 'image_prompt': {
            $ctx = sh_ai_product_context($refId);
            if ($ctx === null) { return ['ok' => false, 'task' => $task, 'error' => 'Product not found.']; }
            $meta = ['admin_id' => $adminId, 'reference_type' => 'product', 'reference_id' => $refId, 'type' => 'product_' . $task]
                  + sh_ai_route_override($extra);

            if ($task === 'title') {
                $r = sh_ai_generate('product_title', $ctx, $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                return ['ok' => true, 'task' => $task, 'data' => ['title' => mb_substr(trim($r['text'], " \t\n\"'"), 0, 190)], 'via' => sh_ai_via($r)];
            }
            if ($task === 'description') {
                $r = sh_ai_generate('product_description', $ctx, $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                return ['ok' => true, 'task' => $task, 'data' => ['description' => sh_ai_clean_html($r['text'])], 'via' => sh_ai_via($r)];
            }
            if ($task === 'short') {
                $r = sh_ai_generate('product_short', $ctx, $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                return ['ok' => true, 'task' => $task,
                        'data' => ['short_description' => mb_substr(trim(strip_tags($r['text']), " \t\n\"'"), 0, 300)]];
            }
            if ($task === 'tags') {
                $r = sh_ai_generate('product_tags', $ctx, $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                $list = sh_ai_parse_json($r['text']);
                if (!is_array($list)) {
                    // Fall back to comma/newline separated output.
                    $list = preg_split('/[,\n]+/', strip_tags($r['text'])) ?: [];
                }
                $tags = [];
                foreach ($list as $t) {
                    if (!is_scalar($t)) { continue; }
                    $t = trim(preg_replace('/^[\-\*\d\.\s"]+|["\s]+$/', '', (string)$t) ?? '');
                    if ($t === '' || mb_strlen($t) > 60) { continue; }
                    $tags[mb_strtolower($t)] = $t;      // de-duplicate case-insensitively
                }
                $tags = array_values(array_slice($tags, 0, 12));
                if (!$tags) { return ['ok' => false, 'task' => $task, 'error' => 'The AI returned no usable tags.']; }
                return ['ok' => true, 'task' => $task, 'data' => ['tags' => $tags]];
            }
            if ($task === 'seo') {
                $r = sh_ai_generate_json('product_seo', $ctx, $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                return ['ok' => true, 'task' => $task, 'data' => sh_ai_normalise_seo($r['data']), 'via' => sh_ai_via($r)];
            }
            if ($task === 'category') {
                $cats = sh_all('SELECT id, name FROM categories ORDER BY name ASC');
                $names = array_map(static fn($c) => '- ' . $c['name'], $cats);
                $r = sh_ai_generate_json('product_category', $ctx + ['categories' => implode("\n", $names)], $meta);
                if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
                $d = $r['data'];
                $suggested = trim((string)($d['category'] ?? ''));
                $match = null;
                foreach ($cats as $c) {
                    if (mb_strtolower((string)$c['name']) === mb_strtolower($suggested)) { $match = $c; break; }
                }
                return ['ok' => true, 'task' => $task, 'data' => [
                    'suggested'   => $suggested,
                    'category_id' => $match['id'] ?? null,
                    'exists'      => $match !== null,
                    'confidence'  => (int)($d['confidence'] ?? 0),
                    'reason'      => mb_substr((string)($d['reason'] ?? ''), 0, 300),
                ]];
            }
            // image_prompt
            $r = sh_ai_generate('image_prompt', $ctx, $meta);
            if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
            return ['ok' => true, 'task' => $task, 'data' => ['prompt' => trim(strip_tags($r['text']))]];
        }

        case 'category_content': {
            $cat = sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$refId]);
            if ($cat === null) { return ['ok' => false, 'task' => $task, 'error' => 'Category not found.']; }
            $prods = sh_all('SELECT name FROM products WHERE category_id = ? LIMIT 8', [$refId]);
            $r = sh_ai_generate_json('category_content', [
                'name'     => (string)$cat['name'],
                'products' => implode(', ', array_column($prods, 'name')),
                'count'    => (string)(int)sh_val('SELECT COUNT(*) FROM products WHERE category_id = ?', [$refId], 0),
            ], ['admin_id' => $adminId, 'reference_type' => 'category', 'reference_id' => $refId, 'type' => 'category_content']
               + sh_ai_route_override($extra));
            if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
            $d = $r['data'];
            $out = sh_ai_normalise_seo($d);
            $out['description'] = mb_substr(trim(strip_tags((string)($d['description'] ?? ''))), 0, 255);
            return ['ok' => true, 'task' => $task, 'data' => $out];
        }

        case 'blog': {
            $r = sh_ai_generate_json('blog_post', [
                'topic'    => (string)($extra['topic'] ?? ''),
                'audience' => (string)($extra['audience'] ?? 'general shoppers'),
                'keywords' => (string)($extra['keywords'] ?? ''),
                'length'   => (string)((int)($extra['length'] ?? 700)),
            ], ['admin_id' => $adminId, 'reference_type' => 'blog', 'type' => 'blog_post',
                'options' => ['max_tokens' => max(1200, (int)($extra['length'] ?? 700) * 3)]] + sh_ai_route_override($extra));
            if (empty($r['ok'])) { return ['ok' => false, 'task' => $task, 'error' => $r['error']]; }
            $d = $r['data'];
            if (trim((string)($d['content'] ?? '')) === '') {
                return ['ok' => false, 'task' => $task, 'error' => 'The AI response was not a usable blog post.'];
            }
            $out = sh_ai_normalise_seo($d);
            $out['title']   = mb_substr(trim(strip_tags((string)($d['title'] ?? ''))), 0, 190);
            $out['excerpt'] = mb_substr(trim(strip_tags((string)($d['excerpt'] ?? ''))), 0, 500);
            $out['content'] = sh_ai_clean_html((string)$d['content']);
            if ($out['title'] === '') { return ['ok' => false, 'task' => $task, 'error' => 'The AI returned no title.']; }
            return ['ok' => true, 'task' => $task, 'data' => $out, 'via' => sh_ai_via($r)];
        }

        case 'image': {
            $ctx = sh_ai_product_context($refId);
            if ($ctx === null) { return ['ok' => false, 'task' => $task, 'error' => 'Product not found.']; }
            $prompt = trim((string)($extra['prompt'] ?? ''));
            if ($prompt === '') {
                $p = sh_ai_run_task('image_prompt', $refId, $adminId);
                if (empty($p['ok'])) { return $p; }
                $prompt = (string)$p['data']['prompt'];
            }
            $res = sh_ai_image('product_image', $prompt, [
                'admin_id' => $adminId, 'type' => 'product_image',
                'reference_type' => 'product', 'reference_id' => $refId,
            ] + sh_ai_route_override($extra));
            if (empty($res['ok'])) {
                return ['ok' => false, 'task' => $task, 'error' => (string)$res['error'], 'capability' => !empty($res['capability'])];
            }

            $saved = sh_ai_store_image((string)$res['binary']);
            if ($saved === null) { return ['ok' => false, 'task' => $task, 'error' => 'The image could not be saved to disk.']; }
            return ['ok' => true, 'task' => $task, 'data' => ['file' => $saved, 'prompt' => $prompt], 'via' => sh_ai_via($res)];
        }
    }

    return ['ok' => false, 'task' => $task, 'error' => 'Unknown task.'];
}

/** Explicit provider/model override coming from a bulk job or the Image AI picker. */
function sh_ai_route_override(array $extra): array
{
    $o = [];
    if (!empty($extra['provider_id'])) { $o['provider_id'] = (int)$extra['provider_id']; }
    if (!empty($extra['model'])) { $o['model'] = (string)$extra['model']; }
    return $o;
}

/** "Provider · model" attribution for the UI. */
function sh_ai_via(array $r): array
{
    return ['provider' => (string)($r['provider'] ?? ''), 'model' => (string)($r['model'] ?? ''),
            'fallback' => !empty($r['fallback_used'])];
}

/** Trim SEO fields to the column limits and normalise keywords to a string. */
function sh_ai_normalise_seo(array $d): array
{
    $kw = $d['keywords'] ?? [];
    if (is_string($kw)) { $kw = preg_split('/[,\n]+/', $kw) ?: []; }
    $kw = array_values(array_filter(array_map(
        static fn($k) => trim((string)$k, " \t\"'"), is_array($kw) ? $kw : []
    ), static fn($k) => $k !== ''));

    return [
        'meta_title'       => mb_substr(trim(strip_tags((string)($d['meta_title'] ?? ''))), 0, 190),
        'meta_description' => mb_substr(trim(strip_tags((string)($d['meta_description'] ?? ''))), 0, 300),
        'focus_keyword'    => mb_substr(trim(strip_tags((string)($d['focus_keyword'] ?? ''))), 0, 120),
        'keywords'         => mb_substr(implode(', ', array_slice($kw, 0, 12)), 0, 300),
    ];
}

/** Allow only a safe subset of HTML from the model. */
function sh_ai_clean_html(string $html): string
{
    $html = preg_replace('/^```(?:html)?\s*|\s*```$/m', '', trim($html)) ?? $html;
    $clean = strip_tags($html, '<p><br><ul><ol><li><strong><em><h2><h3><h4>');
    // Strip any attribute (blocks onclick=, style=, href=javascript: and friends).
    $clean = preg_replace('/<([a-z0-9]+)\s+[^>]*>/i', '<$1>', $clean) ?? $clean;
    return trim($clean);
}

/** Write generated image bytes into the existing product upload folder. */
function sh_ai_store_image(string $binary): ?string
{
    $dir = SH_UPLOAD_DIR . '/products';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return null; }
    if (!is_writable($dir)) { return null; }

    $tmp = tempnam(sys_get_temp_dir(), 'aiimg');
    if ($tmp === false) { return null; }
    file_put_contents($tmp, $binary);

    // Verify it really is an image before it lands in the public folder.
    set_error_handler(static fn(): bool => true);
    try { $info = getimagesize($tmp); } finally { restore_error_handler(); }
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if ($info === false || !isset($allowed[$info['mime'] ?? ''])) { unlink($tmp); return null; }

    $name = 'ai-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$info['mime']];
    if (!rename($tmp, $dir . '/' . $name)) {
        if (!copy($tmp, $dir . '/' . $name)) { unlink($tmp); return null; }
        unlink($tmp);
    }
    chmod($dir . '/' . $name, 0644);
    return $name;
}

/* ------------------------------------------------------------------ *
 * Persistence — writes into the EXISTING tables
 * ------------------------------------------------------------------ */

/**
 * Apply a generated result to the database.
 *
 * @return array{ok:bool,error?:string}
 */
function sh_ai_apply(string $task, int $refId, array $data, ?int $adminId = null): array
{
    try {
        switch ($task) {
            case 'title':
                $v = trim((string)($data['title'] ?? ''));
                if ($v === '') { return ['ok' => false, 'error' => 'Nothing to save.']; }
                sh_query('UPDATE products SET name = ? WHERE id = ?', [mb_substr($v, 0, 190), $refId]);
                return ['ok' => true];

            case 'description':
                sh_query('UPDATE products SET description = ? WHERE id = ?',
                    [sh_ai_clean_html((string)($data['description'] ?? '')), $refId]);
                return ['ok' => true];

            case 'short':
                sh_query('UPDATE products SET short_description = ? WHERE id = ?',
                    [mb_substr(trim(strip_tags((string)($data['short_description'] ?? ''))), 0, 300), $refId]);
                return ['ok' => true];

            case 'tags': {
                $tags = $data['tags'] ?? [];
                if (!is_array($tags) || !$tags) { return ['ok' => false, 'error' => 'No tags to save.']; }
                $pdo = sh_db();
                $pdo->beginTransaction();
                try {
                    sh_query('DELETE FROM product_tags WHERE product_id = ?', [$refId]);
                    $st = $pdo->prepare('INSERT IGNORE INTO product_tags (product_id, tag) VALUES (?,?)');
                    foreach (array_slice($tags, 0, 20) as $t) {
                        $t = trim((string)$t);
                        if ($t !== '') { $st->execute([$refId, mb_substr($t, 0, 60)]); }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    throw $e;
                }
                return ['ok' => true];
            }

            case 'seo':
                sh_query('UPDATE products SET meta_title = ?, meta_description = ?, meta_keywords = ? WHERE id = ?', [
                    mb_substr((string)($data['meta_title'] ?? ''), 0, 190) ?: null,
                    mb_substr((string)($data['meta_description'] ?? ''), 0, 300) ?: null,
                    mb_substr((string)($data['keywords'] ?? ''), 0, 300) ?: null,
                    $refId,
                ]);
                return ['ok' => true];

            case 'category': {
                $catId = (int)($data['category_id'] ?? 0);
                if ($catId <= 0) { return ['ok' => false, 'error' => 'No existing category was chosen.']; }
                if (sh_one('SELECT id FROM categories WHERE id = ? LIMIT 1', [$catId]) === null) {
                    return ['ok' => false, 'error' => 'That category no longer exists.'];
                }
                sh_query('UPDATE products SET category_id = ? WHERE id = ?', [$catId, $refId]);
                return ['ok' => true];
            }

            case 'image': {
                $file = basename((string)($data['file'] ?? ''));
                if ($file === '' || !is_file(SH_UPLOAD_DIR . '/products/' . $file)) {
                    return ['ok' => false, 'error' => 'The generated image is no longer available.'];
                }
                sh_query('UPDATE products SET image = ? WHERE id = ?', [$file, $refId]);
                return ['ok' => true];
            }

            case 'category_content':
                sh_query('UPDATE categories SET description = ?, meta_title = ?, meta_description = ?, meta_keywords = ?
                          WHERE id = ?', [
                    mb_substr((string)($data['description'] ?? ''), 0, 255) ?: null,
                    mb_substr((string)($data['meta_title'] ?? ''), 0, 190) ?: null,
                    mb_substr((string)($data['meta_description'] ?? ''), 0, 300) ?: null,
                    mb_substr((string)($data['keywords'] ?? ''), 0, 300) ?: null,
                    $refId,
                ]);
                return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Unknown save target.'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-apply');
        return ['ok' => false, 'error' => 'The change could not be saved.'];
    }
}

/** Save a generated blog post. Draft unless auto-publish is explicitly on. */
function sh_ai_save_blog(array $data, ?int $adminId, bool $publish = false): array
{
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') { return ['ok' => false, 'error' => 'The post needs a title.']; }

    $base = sh_slug($title) ?: 'post-' . date('Ymd-His');
    $slug = $base; $n = 2;
    try {
        while (sh_one('SELECT id FROM blog_posts WHERE slug = ? LIMIT 1', [$slug]) !== null) {
            $slug = $base . '-' . $n++;
            if ($n > 50) { $slug = $base . '-' . bin2hex(random_bytes(3)); break; }
        }
        $id = sh_insert('blog_posts', [
            'title' => mb_substr($title, 0, 190),
            'slug' => mb_substr($slug, 0, 210),
            'excerpt' => mb_substr((string)($data['excerpt'] ?? ''), 0, 500) ?: null,
            'content' => sh_ai_clean_html((string)($data['content'] ?? '')),
            'meta_title' => mb_substr((string)($data['meta_title'] ?? ''), 0, 190) ?: null,
            'meta_description' => mb_substr((string)($data['meta_description'] ?? ''), 0, 300) ?: null,
            'meta_keywords' => mb_substr((string)($data['keywords'] ?? ''), 0, 300) ?: null,
            'author_id' => $adminId,
            'source' => 'ai',
            'status' => $publish ? 'published' : 'draft',
            'published_at' => $publish ? date('Y-m-d H:i:s') : null,
        ]);
        return ['ok' => true, 'id' => (int)$id, 'status' => $publish ? 'published' : 'draft'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-save-blog');
        return ['ok' => false, 'error' => 'The post could not be saved.'];
    }
}

/* ------------------------------------------------------------------ *
 * Bulk queue
 * ------------------------------------------------------------------ */

/** Queue jobs. Returns the batch id and how many were queued. */
function sh_ai_queue_batch(array $productIds, array $tasks, ?int $adminId, int $providerId = 0, string $model = ''): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    $valid = ['title', 'description', 'short', 'tags', 'seo', 'image'];
    $tasks = array_values(array_intersect($tasks, $valid));
    if (!$productIds || !$tasks) { return ['ok' => false, 'error' => 'Select at least one product and one task.']; }

    $total = count($productIds) * count($tasks);
    if ($total > SH_AI_MAX_BATCH) {
        return ['ok' => false, 'error' => 'That would queue ' . $total . ' jobs. The maximum is ' . SH_AI_MAX_BATCH . '.'];
    }

    if ($providerId > 0) {
        $prow = sh_ai_provider_row($providerId);
        if ($prow === null || (int)$prow['status'] !== 1 || trim((string)$prow['api_key']) === '') {
            return ['ok' => false, 'error' => 'That AI provider is disabled or has no API key.'];
        }
        if (in_array('image', $tasks, true)) {
            $im = $model !== '' && sh_ai_model_supports($providerId, $model, 'image') ? $model : (string)$prow['default_image_model'];
            if ($im === '' || !sh_ai_model_supports($providerId, $im, 'image')) {
                return ['ok' => false, 'error' => 'This provider/model does not support image generation. Remove the Image task or pick an image-capable provider.'];
            }
        }
        if ($model !== '' && !sh_ai_model_supports($providerId, $model, 'text') && array_diff($tasks, ['image'])) {
            return ['ok' => false, 'error' => 'The model "' . $model . '" cannot produce text for the selected tasks.'];
        }
    }

    $batch = 'b' . date('YmdHis') . bin2hex(random_bytes(3));
    try {
        $st = sh_db()->prepare(
            'INSERT IGNORE INTO ai_queue (batch_id, admin_id, task, reference_type, reference_id, provider_id, model)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($productIds as $pid) {
            foreach ($tasks as $t) {
                // Image jobs use the provider's image model; text jobs use the chosen text model.
                $jobModel = $t === 'image' ? '' : $model;
                $st->execute([$batch, $adminId, $t, 'product', $pid, $providerId > 0 ? $providerId : null, $jobModel !== '' ? $jobModel : null]);
            }
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-queue');
        return ['ok' => false, 'error' => 'The batch could not be queued.'];
    }
    return ['ok' => true, 'batch_id' => $batch, 'total' => $total];
}

/**
 * Process the next few jobs in a batch. Called repeatedly by the browser so a
 * long run never hits max_execution_time and requests stay serialised.
 */
function sh_ai_process_batch(string $batchId, ?int $adminId, int $limit = 0): array
{
    $cfg = sh_ai_config();
    $limit = $limit > 0 ? $limit : $cfg['batch_size'];
    $limit = max(1, min(10, $limit));

    $done = 0;
    for ($i = 0; $i < $limit; $i++) {
        $job = sh_one(
            "SELECT * FROM ai_queue WHERE batch_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1",
            [$batchId]
        );
        if ($job === null) { break; }

        sh_query("UPDATE ai_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?", [(int)$job['id']]);

        $res = sh_ai_run_task((string)$job['task'], (int)$job['reference_id'], $adminId, [
            'provider_id' => (int)($job['provider_id'] ?? 0),
            'model'       => (string)($job['model'] ?? ''),
        ]);

        if (!empty($res['ok'])) {
            // Bulk always writes through: the admin already confirmed the batch.
            $applied = sh_ai_apply((string)$job['task'], (int)$job['reference_id'], (array)$res['data'], $adminId);
            if (!empty($applied['ok'])) {
                sh_query("UPDATE ai_queue SET status = 'completed', result = ?, error_message = NULL WHERE id = ?",
                    [mb_substr(json_encode($res['data'], JSON_UNESCAPED_UNICODE) ?: '', 0, 40000), (int)$job['id']]);
            } else {
                sh_query("UPDATE ai_queue SET status = 'failed', error_message = ? WHERE id = ?",
                    [mb_substr((string)$applied['error'], 0, 500), (int)$job['id']]);
            }
        } else {
            sh_query("UPDATE ai_queue SET status = 'failed', error_message = ? WHERE id = ?",
                [mb_substr((string)$res['error'], 0, 500), (int)$job['id']]);
        }
        $done++;
    }

    return sh_ai_batch_progress($batchId) + ['processed_now' => $done];
}

function sh_ai_batch_progress(string $batchId): array
{
    $row = sh_one(
        "SELECT COUNT(*) AS total,
                SUM(status = 'completed') AS completed,
                SUM(status = 'failed') AS failed,
                SUM(status = 'pending') AS pending,
                SUM(status = 'processing') AS processing
         FROM ai_queue WHERE batch_id = ?", [$batchId]
    ) ?? [];
    $total = (int)($row['total'] ?? 0);
    $completed = (int)($row['completed'] ?? 0);
    $failed = (int)($row['failed'] ?? 0);
    return [
        'batch_id' => $batchId,
        'total' => $total,
        'completed' => $completed,
        'failed' => $failed,
        'pending' => (int)($row['pending'] ?? 0),
        'processing' => (int)($row['processing'] ?? 0),
        'percent' => $total > 0 ? (int)round(($completed + $failed) / $total * 100) : 0,
        'finished' => $total > 0 && ((int)($row['pending'] ?? 0) + (int)($row['processing'] ?? 0)) === 0,
    ];
}

/** Requeue failed jobs, respecting the retry limit. */
function sh_ai_retry_failed(string $batchId): array
{
    $max = sh_ai_config()['max_retries'];
    try {
        sh_query("UPDATE ai_queue SET status = 'pending', error_message = NULL
                  WHERE batch_id = ? AND status = 'failed' AND attempts <= ?", [$batchId, $max]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-retry');
        return ['ok' => false, 'error' => 'Could not requeue those jobs.'];
    }
    return ['ok' => true] + sh_ai_batch_progress($batchId);
}

/* ------------------------------------------------------------------ *
 * Dashboard statistics — real data only
 * ------------------------------------------------------------------ */

function sh_ai_stats(): array
{
    $zero = ['total' => 0, 'product' => 0, 'blog' => 0, 'image' => 0, 'success' => 0, 'failed' => 0, 'tokens' => 0, 'cost' => 0.0, 'fallbacks' => 0];
    if (!sh_ai_installed()) { return $zero; }
    try {
        $r = sh_one(
            "SELECT COUNT(*) AS total,
                    SUM(reference_type = 'product') AS product,
                    SUM(reference_type = 'blog') AS blog,
                    SUM(type = 'product_image') AS image,
                    SUM(status = 'success') AS success,
                    SUM(status = 'failed') AS failed,
                    COALESCE(SUM(tokens_used),0) AS tokens,
                    COALESCE(SUM(cost),0) AS cost,
                    SUM(fallback_used = 1) AS fallbacks
             FROM ai_generations"
        ) ?? [];
        return [
            'total' => (int)($r['total'] ?? 0), 'product' => (int)($r['product'] ?? 0),
            'blog' => (int)($r['blog'] ?? 0), 'image' => (int)($r['image'] ?? 0),
            'success' => (int)($r['success'] ?? 0), 'failed' => (int)($r['failed'] ?? 0),
            'tokens' => (int)($r['tokens'] ?? 0), 'cost' => (float)($r['cost'] ?? 0), 'fallbacks' => (int)($r['fallbacks'] ?? 0),
        ];
    } catch (Throwable $e) {
        return $zero;
    }
}

/** Friendly label for a generation type. */
function sh_ai_type_label(string $type): string
{
    return match ($type) {
        'product_title' => 'Product title',
        'product_description' => 'Product description',
        'product_short' => 'Short description',
        'product_tags' => 'Product tags',
        'product_seo' => 'Product SEO',
        'product_category' => 'Category suggestion',
        'product_image' => 'Product image',
        'category_content' => 'Category content',
        'blog_post' => 'Blog post',
        'image_prompt' => 'Image prompt',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}

/** "2 minutes ago" style helper for the activity feed. */
function sh_ai_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) { return $datetime; }
    $d = max(0, time() - $ts);
    if ($d < 60) { return $d . ' second' . ($d === 1 ? '' : 's') . ' ago'; }
    if ($d < 3600) { $m = (int)floor($d / 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($d < 86400) { $h = (int)floor($d / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $days = (int)floor($d / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

sh_ai_auto_upgrade();
