<?php
/**
 * ShopHaat SEO engine.
 *
 * One place that decides what every public page tells search engines: the
 * title, meta description, canonical URL, robots directive, social cards,
 * JSON-LD structured data, the XML sitemap and robots.txt.
 *
 * Pages stay in charge of their own content. A page sets the variables it
 * always did ($pageTitle, $pageDescription, $pageCanonical, $structuredData),
 * optionally identifies itself with a page key, and this module turns all of
 * that into a single, non-duplicated set of <head> tags.
 *
 * Design rules, in order of importance:
 *   1. Never invent data. Prices, stock, ratings and dates come from real rows
 *      or are omitted. No fake lastmod, changefreq or priority values.
 *   2. Never duplicate a tag. This module is the only writer of title,
 *      description, canonical, robots, og:* and twitter:* tags.
 *   3. Never break a page. If anything here fails, the page still renders with
 *      the values it supplied, and the failure is logged.
 *   4. A non-indexable page must never look indexable, and a private page must
 *      never reach the sitemap or the index.
 */

declare(strict_types=1);

require_once SH_ROOT . '/includes/db.php';

// ===========================================================================
// Page keys
// ===========================================================================

/**
 * The pages whose metadata an owner can override from the admin panel.
 *
 * 'path' is used by the sitemap; an empty path means the page is listed by a
 * dedicated rule there (home, products, product, category) rather than by a
 * static path. 'indexable' false means the page is deliberately kept out of the
 * sitemap even when the override row exists.
 */
function sh_seo_pages_catalog(): array
{
    return [
        'home'     => ['label' => 'Homepage',        'path' => '',                    'indexable' => true],
        'products' => ['label' => 'All products',    'path' => 'products.php',        'indexable' => true],
        'about'    => ['label' => 'About Us',        'path' => 'page.php?p=about',    'indexable' => true],
        'terms'    => ['label' => 'Terms',           'path' => 'page.php?p=terms',    'indexable' => true],
        'privacy'  => ['label' => 'Privacy Policy',  'path' => 'page.php?p=privacy',  'indexable' => true],
        'returns'  => ['label' => 'Returns & Refunds', 'path' => 'page.php?p=returns', 'indexable' => true],
        'shipping' => ['label' => 'Shipping Info',   'path' => 'page.php?p=shipping', 'indexable' => true],
        'help'     => ['label' => 'Help / FAQ',      'path' => 'help.php',            'indexable' => true],
        'support'  => ['label' => 'Support / Contact', 'path' => 'support.php',       'indexable' => true],
        // Kept in the catalogue so an owner can set a title, but never in the
        // sitemap: a page that only exists for a signed-in shopper or a
        // one-time action must not be offered for indexing.
        'track'    => ['label' => 'Track order',     'path' => '',                    'indexable' => false],
        'account'  => ['label' => 'My account',      'path' => '',                    'indexable' => false],
        'cart'     => ['label' => 'Cart',            'path' => '',                    'indexable' => false],
    ];
}

/** Pages that carry per-entity SEO columns on their own database row. */
function sh_seo_entity_pages(): array
{
    return ['product', 'category'];
}

// ===========================================================================
// Settings
// ===========================================================================

/**
 * Every SEO setting with its default.
 *
 * Stored in the existing `settings` table, so no new table is needed and the
 * admin Settings History page records changes for free.
 */
function sh_seo_setting_defaults(): array
{
    return [
        'seo_enabled'              => '1',

        // Titles
        'seo_title_suffix'         => '',          // empty = " | {site name}" from General Settings
        'seo_default_description'  => '',          // empty = site_description from General Settings
        'seo_default_keywords'     => '',

        // Identity / canonical
        'seo_canonical_base'       => '',          // empty = site_url from General Settings
        'seo_default_image'        => '',
        'seo_default_robots'       => 'index,follow',
        'seo_lang'                 => 'en',
        'seo_locale'               => 'en_US',

        // Social
        'seo_og_enabled'           => '1',
        'seo_twitter_enabled'      => '1',
        'seo_twitter_site'         => '',          // e.g. @shophaat
        'seo_org_name'             => '',
        'seo_org_logo'             => '',
        'seo_org_email'            => '',
        'seo_org_phone'            => '',
        'seo_social_facebook'      => '',
        'seo_social_instagram'     => '',
        'seo_social_youtube'       => '',
        'seo_social_tiktok'        => '',
        'seo_social_linkedin'      => '',

        // Search-engine verification
        'seo_verify_google'        => '',
        'seo_verify_bing'          => '',
        'seo_verify_pinterest'     => '',

        // Sitemap
        'seo_sitemap_enabled'      => '1',
        'seo_sitemap_products'     => '2000',      // rows per sitemap file
        'seo_sitemap_cache_min'    => '30',        // minutes

        // Analytics / tracking (optional, owner-supplied)
        'seo_ga_measurement_id'    => '',
        'seo_indexnow_key'         => '',
    ];
}

/** The SEO setting list actually in use, defaults filled in. */
function sh_seo_settings(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    foreach (sh_seo_setting_defaults() as $k => $default) {
        $out[$k] = (string)sh_setting($k, $default);
    }
    return $cache = $out;
}

/** Read one SEO setting. */
function sh_seo_get(string $key, string $fallback = ''): string
{
    $all = sh_seo_settings();
    $v = array_key_exists($key, $all) ? (string)$all[$key] : '';
    return $v !== '' ? $v : $fallback;
}

/** Save one SEO setting, refreshing the cached copy. */
function sh_seo_save(string $key, string $value): void
{
    sh_setting_save($key, $value);
    sh_seo_settings();
    // A settings change can alter every URL in the sitemap: drop the cache.
    sh_seo_cache_clear();
}

/** Is the SEO layer switched on? When off, the previous behaviour is kept. */
function sh_seo_enabled(): bool
{
    return sh_seo_get('seo_enabled', '1') === '1';
}

// ===========================================================================
// URLs
// ===========================================================================

/**
 * The site's canonical origin, without a trailing slash.
 *
 * Prefers the SEO Manager field, then the General Settings site URL, then the
 * live request host. Only http(s) values are accepted, and a value that is not
 * a public host (localhost, a private IP) is refused so a half-configured store
 * never publishes "https://localhost" as its canonical domain.
 */
function sh_seo_base(): string
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $configured = trim(sh_seo_get('seo_canonical_base'));
    if ($configured !== '') {
        $candidate = rtrim($configured, '/');
        if (sh_seo_valid_absolute_url($candidate)) { return $cache = $candidate; }
    }
    $public = sh_public_site_url();
    if ($public !== '') { return $cache = rtrim($public, '/'); }

    return $cache = rtrim(sh_site_url(), '/');
}

/** Absolute URL for a site-relative path. Already-absolute input is returned unchanged. */
function sh_seo_abs(string $path): string
{
    $path = trim($path);
    if ($path === '') { return sh_seo_base() . '/'; }
    if (preg_match('~^https?://~i', $path) === 1) { return $path; }
    return sh_seo_base() . '/' . ltrim($path, '/');
}

/**
 * True only for an absolute, credential-free http(s) URL.
 *
 * Used for admin-entered URLs (canonical overrides, social profiles, images).
 * A relative path is handled by the caller: it is not absolute, but it is safe.
 */
function sh_seo_valid_absolute_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_contains($url, "\n") || str_contains($url, "\r")) { return false; }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) { return false; }
    $parts = parse_url($url);
    if (!is_array($parts)) { return false; }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') { return false; }
    // user:pass@ in a URL is either a mistake or an attempt at obscuring the host.
    if (!empty($parts['user']) || !empty($parts['pass'])) { return false; }
    return trim((string)($parts['host'] ?? '')) !== '';
}

/**
 * Clean an admin-entered URL field.
 *
 * Returns '' for anything that could become an injection or a link to a
 * scripting scheme. Accepts an absolute http(s) URL or a root-relative path.
 */
function sh_seo_clean_url(string $url): string
{
    $url = trim($url);
    if ($url === '') { return ''; }
    // Control characters can hide a second value inside a header or a tag.
    if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) { return ''; }
    // A URL is never allowed to carry whitespace or quotes. Every output path
    // escapes its values anyway, so this is defence in depth: a value that
    // needed escaping to be safe is not a valid address to publish.
    if (preg_match('/[\s<>"\x27]/', $url) === 1) { return ''; }
    if (preg_match('~^\s*(javascript|data|vbscript|file|ftp):~i', $url) === 1) { return ''; }
    // Protocol-relative "//host" inherits the scheme and is not a site path.
    if (str_starts_with($url, '//') || str_starts_with($url, '\\\\')) { return ''; }
    if (str_starts_with($url, '/')) {
        // A root-relative path: no traversal, no backslashes, no spaces.
        if (str_contains($url, '..') || str_contains($url, '\\') || preg_match('/\s/', $url) === 1) { return ''; }
        return $url;
    }
    return sh_seo_valid_absolute_url($url) ? $url : '';
}

/**
 * Canonical URL for an entity path, stripped of tracking noise.
 *
 * Query parameters are kept only when they identify the page (slug, p, page).
 * Sorting, filter and campaign parameters create duplicate URLs for one page,
 * so they are dropped here and the page is told to point at the clean form.
 */
function sh_seo_canonical_url(string $path, array $keep = []): string
{
    if (preg_match('~^https?://~i', $path) === 1) { return $path; }
    $keep = $keep !== [] ? $keep : ['slug', 'p', 'page', 'tab'];

    $parts = parse_url($path);
    if ($parts === false) { return sh_seo_abs($path); }
    $pathOnly = (string)($parts['path'] ?? '');
    $query = [];
    if (isset($parts['query'])) {
        parse_str((string)$parts['query'], $parsed);
        foreach ($keep as $k) {
            if (isset($parsed[$k]) && is_scalar($parsed[$k]) && (string)$parsed[$k] !== '') {
                $query[$k] = (string)$parsed[$k];
            }
        }
    }
    $url = sh_seo_abs($pathOnly);
    if ($query !== []) { $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
    return $url;
}

/** Absolute URL for an uploaded image, or '' when there is nothing usable. */
function sh_seo_image_url(string $file): string
{
    $file = trim($file);
    if ($file === '') { return ''; }
    if (preg_match('~^https?://~i', $file) === 1) { return sh_seo_valid_absolute_url($file) ? $file : ''; }
    if (str_starts_with($file, 'data:')) { return ''; }
    return sh_seo_abs($file);
}

// ===========================================================================
// Schema
// ===========================================================================

/** Is this column present on the table? Cached by sh_table_has_column(). */
function sh_seo_has_column(string $table, string $column): bool
{
    return sh_table_has_column($table, $column);
}

/**
 * Add the SEO columns this module needs.
 *
 * Idempotent and versioned, following the same pattern as the other lazy
 * migrations in this project. Products already carry meta_title and
 * meta_description from the original schema; they are reused, never duplicated.
 * The AI module also adds meta_keywords to categories and products, so this
 * routine creates it only when it is missing and both modules agree on one
 * column rather than two competing ones.
 */
function sh_seo_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    try {
        // Version 2 widens the two SEO text columns: they are editorial copy,
        // not fixed-width identifiers, and an administrator may legitimately
        // write more than the old 190/300 characters. The ALTERs are additive
        // and never truncate existing data.
        if (sh_setting('seo_schema_v', '') === '2') { return; }
        $pdo = sh_db();
        // DDL implicitly commits in MySQL/MariaDB, so never run it inside a
        // transaction that a checkout or payment flow depends on.
        if ($pdo->inTransaction()) {
            sh_log_line('seo-schema', 'Deferred SEO schema migration because a transaction is active.');
            return;
        }

        $columns = [
            'products' => [
                'meta_title'           => 'TEXT NULL',
                'meta_description'     => 'TEXT NULL',
                'meta_keywords'        => 'VARCHAR(300) DEFAULT NULL',
                'seo_focus_keyword'    => 'VARCHAR(120) DEFAULT NULL',
                'seo_related_phrases'  => 'VARCHAR(500) DEFAULT NULL',
                'seo_og_image'         => 'VARCHAR(190) DEFAULT NULL',
                'seo_canonical'        => 'VARCHAR(255) DEFAULT NULL',
                'seo_noindex'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
            'categories' => [
                'meta_title'           => 'TEXT NULL',
                'meta_description'     => 'TEXT NULL',
                'meta_keywords'        => 'VARCHAR(300) DEFAULT NULL',
                'seo_focus_keyword'    => 'VARCHAR(120) DEFAULT NULL',
                'seo_related_phrases'  => 'VARCHAR(500) DEFAULT NULL',
                'seo_og_image'         => 'VARCHAR(190) DEFAULT NULL',
                'seo_canonical'        => 'VARCHAR(255) DEFAULT NULL',
                'seo_noindex'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
            'blog_posts' => [
                'seo_focus_keyword'    => 'VARCHAR(120) DEFAULT NULL',
                'seo_related_phrases'  => 'VARCHAR(500) DEFAULT NULL',
                'seo_og_image'         => 'VARCHAR(190) DEFAULT NULL',
                'seo_canonical'        => 'VARCHAR(255) DEFAULT NULL',
                'seo_noindex'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            ],
        ];

        // ALTER TABLE ... ADD COLUMN needs the identifier inline; every name
        // below is a literal from this array, never input.
        foreach ($columns as $table => $defs) {
            if (!sh_table_exists($table)) { continue; }
            foreach ($defs as $col => $ddl) {
                if (sh_table_has_column($table, $col)) { continue; }
                try {
                    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $ddl);
                } catch (Throwable $e) {
                    // A concurrent request may have added it first — not fatal.
                    sh_log_exception($e, 'seo-schema');
                }
            }
        }

        // Overrides for pages that have no database row of their own.
        $pdo->exec("CREATE TABLE IF NOT EXISTS seo_pages (
            page_key VARCHAR(40) NOT NULL,
            meta_title TEXT NULL,
            meta_description TEXT NULL,
            meta_keywords VARCHAR(300) DEFAULT NULL,
            seo_og_image VARCHAR(190) DEFAULT NULL,
            seo_canonical VARCHAR(255) DEFAULT NULL,
            seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (page_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // An install created by an older release still has VARCHAR(190/300)
        // here. Widen those columns in place; the values are preserved.
        sh_seo_widen_text_columns();
        sh_setting_save('seo_schema_v', '2');
    } catch (Throwable $e) {
        // The SEO layer must never be the reason a storefront page fails.
        sh_log_exception($e, 'seo-schema');
    }
}

/**
 * Widen meta_title / meta_description to TEXT where an older schema used a
 * fixed VARCHAR. Safe to call repeatedly: each column is inspected first and
 * skipped when it is already a text type. Returns the number of columns changed.
 */
function sh_seo_widen_text_columns(): int
{
    $targets = [
        'products'   => ['meta_title', 'meta_description'],
        'categories' => ['meta_title', 'meta_description'],
        'seo_pages'  => ['meta_title', 'meta_description'],
        'blog_posts' => ['meta_title', 'meta_description'],
    ];
    $changed = 0;
    foreach ($targets as $table => $columns) {
        if (!sh_table_exists($table)) { continue; }
        foreach ($columns as $column) {
            if (!sh_table_has_column($table, $column)) { continue; }
            $type = strtolower((string)sh_val(
                'SELECT DATA_TYPE FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column], ''
            ));
            if (in_array($type, ['tinytext', 'text', 'mediumtext', 'longtext'], true)) { continue; }
            try {
                // Identifiers are literals from the list above, never input.
                sh_query('ALTER TABLE `' . $table . '` MODIFY `' . $column . '` TEXT NULL');
                $changed++;
            } catch (Throwable $e) {
                // Never let a widening failure break a page view.
                sh_log_exception($e, 'seo-widen-text');
            }
        }
    }
    return $changed;
}

/** An override row for a page key, or an empty array. */
function sh_seo_page_row(string $key): array
{
    static $cache = [];
    if ($key === '') { return []; }
    if (array_key_exists($key, $cache)) { return $cache[$key]; }
    try {
        $row = sh_one('SELECT * FROM seo_pages WHERE page_key = ? LIMIT 1', [$key]);
        return $cache[$key] = $row ?? [];
    } catch (Throwable $e) {
        return $cache[$key] = [];
    }
}

// ===========================================================================
// Text helpers
// ===========================================================================

/**
 * Shorten a string to $max characters without cutting a word in half.
 *
 * The length is a soft limit: a single very long word is still hard-truncated
 * so the result can never blow past a search engine's display limit.
 */
function sh_seo_clip(string $text, int $max, string $ellipsis = '…'): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || $max <= 0) { return ''; }
    if (mb_strlen($text) <= $max) { return $text; }

    $slice = mb_substr($text, 0, $max + 1);
    $cut = mb_strrpos($slice, ' ');
    $body = ($cut !== false && $cut > (int)($max * 0.5)) ? mb_substr($slice, 0, $cut) : mb_substr($text, 0, $max);
    return rtrim($body, " \t\n\r\0\x0B.,;:-") . $ellipsis;
}

/** Strip markup and entities so the text can go inside a meta tag. */
function sh_seo_plain(?string $text): string
{
    $t = strip_tags((string)$text);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return trim($t);
}

/** Normalise a comma-separated keyword field into a clean, deduplicated list. */
function sh_seo_keyword_list(string $raw): array
{
    $parts = preg_split('/[,\n]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', $p) ?? $p);
        if ($p === '' || mb_strlen($p) > 60) { continue; }
        $key = mb_strtolower($p);
        if (!isset($out[$key])) { $out[$key] = $p; }
    }
    return array_values($out);
}

// ===========================================================================
// Metadata resolution
// ===========================================================================

/**
 * Build the complete metadata set for the current page.
 *
 * @param array $ctx {
 *   key             string  page key for a seo_pages override
 *   fallback_title  string  title the page would use on its own
 *   fallback_desc   string  description the page would use on its own
 *   canonical       string  path or absolute URL the page considers canonical
 *   image           string  relative or absolute social image
 *   type            string  og:type ('website', 'product', 'article')
 *   robots          string  robots directive for this page
 *   keywords        string  extra keywords from real page data
 *   entity          array   product/category row carrying SEO columns
 *   breadcrumbs     array   list of ['name' => ..., 'url' => ...]
 *   schema          array   extra JSON-LD blocks from the page
 *   paginated       bool    true when this is page 2+ of a listing
 * }
 */
function sh_seo_meta(array $ctx = []): array
{
    $siteName = (string)sh_setting('site_name', 'ShopHaat');
    $tagline  = (string)sh_setting('site_tagline', '');

    $key      = (string)($ctx['key'] ?? '');
    $entity   = is_array($ctx['entity'] ?? null) ? $ctx['entity'] : [];
    $pageRow  = $key !== '' ? sh_seo_page_row($key) : [];

    // ---- title ----------------------------------------------------------
    // The candidate is taken from the most specific source available and the
    // suffix rule is applied once, at the end, so a custom title and a
    // generated one follow exactly the same rule instead of drifting apart.
    $rawTitle = trim((string)($ctx['fallback_title'] ?? ''));
    $entityTitle = trim(sh_seo_plain((string)($entity['meta_title'] ?? '')));
    if ($entityTitle === '' && $entity !== []) {
        $entityTitle = trim(sh_seo_plain((string)($entity['name'] ?? '')));
    }
    $overrideTitle = trim((string)($pageRow['meta_title'] ?? ''));
    $titleCandidate = $overrideTitle !== '' ? $overrideTitle : ($entityTitle !== '' ? $entityTitle : $rawTitle);

    $title = $titleCandidate;
    $titleSource = $overrideTitle !== '' ? 'override' : ($entityTitle !== '' ? 'entity' : ($rawTitle !== '' ? 'page' : 'none'));

    // ---- description ----------------------------------------------------
    $desc = trim((string)($ctx['fallback_desc'] ?? ''));
    if ($desc === '') { $desc = sh_seo_get('seo_default_description', (string)sh_setting('site_description', '')); }

    // ---- robots ---------------------------------------------------------
    $robots = trim((string)($ctx['robots'] ?? ''));
    if ($robots === '') { $robots = sh_seo_get('seo_default_robots', 'index,follow'); }

    // ---- image ----------------------------------------------------------
    $image = sh_seo_image_url((string)($ctx['image'] ?? ''));
    if ($image === '') { $image = sh_seo_image_url(sh_seo_get('seo_default_image')); }
    if ($image === '') { $image = sh_seo_image_url((string)sh_setting('site_logo', '')); }

    // ---- canonical ------------------------------------------------------
    $canonical = sh_seo_canonical_url((string)($ctx['canonical'] ?? ''));

    // ---- keywords (editorial only) --------------------------------------
    $keywords = sh_seo_keyword_list((string)($ctx['keywords'] ?? ''));
    if ($keywords === []) {
        $keywords = sh_seo_keyword_list(sh_seo_get('seo_default_keywords'));
    }

    $out = [
        'title'       => $title,
        'description' => $desc,
        'keywords'    => $keywords,
        'robots'      => $robots,
        'canonical'   => $canonical,
        'image'       => $image,
        'type'        => in_array((string)($ctx['type'] ?? ''), ['website', 'product', 'article'], true)
            ? (string)$ctx['type'] : 'website',
        'locale'      => sh_seo_get('seo_locale', 'en_US'),
        'lang'        => sh_seo_get('seo_lang', 'en'),
        'site_name'   => $siteName,
        'page_key'    => $key,
        'title_source' => $titleSource,
    ];

    // ---- the page's own override row wins over generated text ------------
    if ($pageRow !== []) {
        if (trim((string)($pageRow['meta_description'] ?? '')) !== '') {
            $out['description'] = trim((string)$pageRow['meta_description']);
        }
        if (trim((string)($pageRow['meta_keywords'] ?? '')) !== '') {
            $out['keywords'] = sh_seo_keyword_list((string)$pageRow['meta_keywords']);
        }
        if (trim((string)($pageRow['seo_og_image'] ?? '')) !== '') {
            $out['image'] = sh_seo_image_url((string)$pageRow['seo_og_image']);
        }
        $overrideCanonical = sh_seo_clean_url((string)($pageRow['seo_canonical'] ?? ''));
        if ($overrideCanonical !== '') {
            $out['canonical'] = sh_seo_canonical_url($overrideCanonical);
        }
        if ((int)($pageRow['seo_noindex'] ?? 0) === 1) {
            $out['robots'] = 'noindex,follow';
        }
    }

    // ---- the entity's own columns win over everything --------------------
    if ($entity !== []) {
        $eDesc = sh_seo_plain((string)($entity['meta_description'] ?? ''));
        if ($eDesc !== '') { $out['description'] = $eDesc; }

        $eKeywords = sh_seo_keyword_list((string)($entity['meta_keywords'] ?? ''));
        if ($eKeywords === [] && isset($entity['seo_focus_keyword'])) {
            $eKeywords = sh_seo_keyword_list((string)$entity['seo_focus_keyword']);
        }
        if (isset($entity['seo_related_phrases'])) {
            $eKeywords = array_merge($eKeywords, sh_seo_keyword_list((string)$entity['seo_related_phrases']));
        }
        if ($eKeywords !== []) {
            $seen = [];
            foreach ($eKeywords as $k) { $seen[mb_strtolower($k)] = $k; }
            $out['keywords'] = array_values($seen);
        }

        $eImage = sh_seo_image_url((string)($entity['seo_og_image'] ?? ''));
        if ($eImage !== '') { $out['image'] = $eImage; }

        $eCanonical = sh_seo_clean_url((string)($entity['seo_canonical'] ?? ''));
        if ($eCanonical !== '') { $out['canonical'] = sh_seo_canonical_url($eCanonical); }

        if ((int)($entity['seo_noindex'] ?? 0) === 1) { $out['robots'] = 'noindex,follow'; }
    }

    // ---- final shaping ---------------------------------------------------
    // Suffix: skipped for the auto-generated fallback (which already names the
    // store) and whenever the title already mentions the store or carries its
    // own separator, so "Store | Shoes" never becomes "Store | Shoes | Store".
    $suffixConfigured = sh_seo_get('seo_title_suffix');
    $suffix = $suffixConfigured !== '' ? $suffixConfigured : (' | ' . $siteName);
    if ($out['title'] === '') {
        $out['title'] = $siteName . ($tagline !== '' ? ' — ' . $tagline : '');
    } elseif (empty($ctx['title_exact'])
        && !str_contains($out['title'], '|')
        && !str_contains(mb_strtolower($out['title']), mb_strtolower($siteName))) {
        $out['title'] .= $suffix;
    }
    // 70 characters is the point past which a search result truncates almost
    // everything; the suffix is allowed to be trimmed off in preference to the
    // page's own words.
    $out['title']       = sh_seo_clip($out['title'], 70, '');
    $out['description'] = sh_seo_clip(sh_seo_plain($out['description']), 158);
    $out['robots']      = sh_seo_normalise_robots($out['robots']);

    // A paginated listing keeps its own canonical but must not look like a
    // duplicate of page 1 to a crawler, so only page 1 is offered for indexing
    // through the sitemap; the directive here stays index,follow because the
    // content is unique, and the canonical points at the parameterless URL.
    if (!empty($ctx['paginated'])) {
        $out['robots'] = str_contains($out['robots'], 'noindex') ? $out['robots'] : 'index,follow';
    }

    // ---- structured data -------------------------------------------------
    $out['schema'] = sh_seo_schema_graph($ctx, $out);

    return $out;
}

/** Keep only directives we understand, so a typo cannot produce nonsense. */
function sh_seo_normalise_robots(string $raw): string
{
    $raw = mb_strtolower(trim($raw));
    if ($raw === '') { return 'index,follow'; }
    $indexing = null;
    $following = null;
    $extras = [];
    foreach (preg_split('/[,\s]+/', $raw) ?: [] as $token) {
        $token = trim($token);
        if ($token === '') { continue; }
        if ($token === 'index' || $token === 'noindex') { $indexing = $token; continue; }
        if ($token === 'follow' || $token === 'nofollow') { $following = $token; continue; }
        if (in_array($token, ['noarchive', 'nosnippet', 'noimageindex', 'notranslate'], true)) { $extras[] = $token; }
    }
    $parts = [$indexing ?? 'index', $following ?? 'follow'];
    return implode(',', array_merge($parts, array_unique($extras)));
}

/** True when the resolved directive asks search engines not to index the page. */
function sh_seo_is_noindex(array $meta): bool
{
    return str_contains((string)($meta['robots'] ?? ''), 'noindex');
}

/**
 * Assemble the JSON-LD graph: a single block, so no page can emit two
 * contradictory copies of the same entity.
 *
 * Only real data is used. An Offer appears only when the price is usable, and
 * AggregateRating only when the store actually holds approved reviews for the
 * product — a product with no reviews gets no rating at all rather than a
 * fabricated one.
 */
function sh_seo_schema_graph(array $ctx, array $meta): array
{
    $base = sh_seo_base();
    $siteName = (string)$meta['site_name'];
    $graph = [];

    // WebSite — every page may carry it; SearchAction points at the real route.
    $graph[] = [
        '@type' => 'WebSite',
        '@id'   => $base . '/#website',
        'url'   => $base . '/',
        'name'  => $siteName,
        'inLanguage' => (string)$meta['lang'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base . '/products.php?q={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];

    // Organization / OnlineStore — identity, logo and verified contact points.
    $orgName = sh_seo_get('seo_org_name', $siteName);
    $org = [
        '@type' => 'OnlineStore',
        '@id'   => $base . '/#organization',
        'name'  => $orgName,
        'url'   => $base . '/',
    ];
    $logo = sh_seo_image_url(sh_seo_get('seo_org_logo'));
    if ($logo === '') { $logo = sh_seo_image_url((string)sh_setting('site_logo', '')); }
    if ($logo !== '') { $org['logo'] = $logo; $org['image'] = $logo; }

    $email = sh_seo_get('seo_org_email');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $org['email'] = $email;
    }
    $phone = sh_seo_get('seo_org_phone');
    if ($phone !== '') { $org['telephone'] = $phone; }

    // sameAs lists only profiles the owner typed in, never guessed handles.
    $sameAs = [];
    foreach (['seo_social_facebook', 'seo_social_instagram', 'seo_social_youtube',
              'seo_social_tiktok', 'seo_social_linkedin'] as $k) {
        $v = sh_seo_valid_absolute_url(sh_seo_get($k)) ? sh_seo_get($k) : '';
        if ($v !== '') { $sameAs[] = $v; }
    }
    if ($sameAs !== []) { $org['sameAs'] = array_values(array_unique($sameAs)); }
    $graph[] = $org;

    // Breadcrumbs from the links the page actually rendered.
    $crumbs = is_array($ctx['breadcrumbs'] ?? null) ? $ctx['breadcrumbs'] : [];
    if ($crumbs !== []) {
        $items = [];
        $pos = 1;
        foreach ($crumbs as $c) {
            $name = sh_seo_plain((string)($c['name'] ?? ''));
            $url = (string)($c['url'] ?? '');
            if ($name === '') { continue; }
            $entry = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $name];
            if ($url !== '') { $entry['item'] = sh_seo_abs($url); }
            $items[] = $entry;
        }
        if ($items !== []) {
            $graph[] = [
                '@type' => 'BreadcrumbList',
                '@id'   => ($meta['canonical'] !== '' ? $meta['canonical'] : $base . '/') . '#breadcrumb',
                'itemListElement' => $items,
            ];
        }
    }

    // Anything the page built itself (the Product block on a product page).
    $extra = is_array($ctx['schema'] ?? null) ? $ctx['schema'] : [];
    if ($extra === []) { return $graph; }
    // A single node is common; a list is allowed too.
    if (array_is_list($extra)) {
        foreach ($extra as $node) { if (is_array($node) && $node !== []) { $graph[] = $node; } }
    } else {
        $graph[] = $extra;
    }
    return $graph;
}

/**
 * The product's own image as an absolute URL, or '' when it has none.
 *
 * Deliberately not sh_product_image(): that helper falls back to a placeholder
 * graphic, which would put the same stock image in every social card. An empty
 * result here lets the configured default image take over instead.
 */
function sh_seo_product_image(array $product): string
{
    $file = basename((string)($product['image'] ?? ''));
    if ($file === '' || !is_file(SH_UPLOAD_DIR . '/products/' . $file)) { return ''; }
    return sh_seo_abs('uploads/products/' . rawurlencode($file));
}

/**
 * A category's own image as an absolute URL, or '' so the default applies.
 *
 * Accepts an absolute URL in the sharing-image field (an owner may host the
 * image elsewhere) and otherwise resolves the stored upload the same way the
 * category page itself does.
 */
function sh_seo_category_image(array $category): string
{
    $custom = sh_seo_clean_url((string)($category['seo_og_image'] ?? ''));
    if ($custom !== '') {
        $abs = sh_seo_image_url($custom);
        if ($abs !== '') { return $abs; }
    }
    $file = basename((string)($category['image'] ?? ''));
    if ($file === '' || !is_file(SH_UPLOAD_DIR . '/categories/' . $file)) { return ''; }
    return sh_seo_abs('uploads/categories/' . rawurlencode($file));
}

/**
 * Product JSON-LD built from the row the page already loaded.
 *
 * @param array $product products row (plus brand_name when joined)
 * @param array $meta    the resolved metadata, for the canonical URL
 * @param array $images  optional extra image URLs
 */
function sh_seo_product_schema(array $product, array $meta, array $images = []): array
{
    $currency = (string)sh_setting('currency_code', 'BDT');
    $url = $meta['canonical'] !== '' ? $meta['canonical'] : sh_seo_abs('product.php?slug=' . rawurlencode((string)($product['slug'] ?? '')));
    $name = sh_seo_plain((string)($product['name'] ?? ''));
    $desc = sh_seo_plain((string)($product['meta_description'] ?? ''));
    if ($desc === '') {
        $desc = sh_seo_clip(sh_seo_plain((string)($product['short_description'] ?? '') ?: (string)($product['description'] ?? '')), 300);
    }

    $node = [
        '@type' => 'Product',
        '@id'   => $url . '#product',
        'name'  => $name,
        'url'   => $url,
    ];
    if ($desc !== '') { $node['description'] = $desc; }

    $sku = trim((string)($product['sku'] ?? ''));
    $node['sku'] = $sku !== '' ? $sku : ('SH-' . (int)($product['id'] ?? 0));

    // Images: the configured social image first, then the gallery, all absolute
    // and only when the values came from real upload fields.
    $imageList = [];
    if (($meta['image'] ?? '') !== '') { $imageList[] = (string)$meta['image']; }
    foreach ($images as $img) {
        $abs = sh_seo_image_url((string)$img);
        if ($abs !== '') { $imageList[] = $abs; }
    }
    if ($imageList !== []) { $node['image'] = array_values(array_unique($imageList)); }

    if (trim((string)($product['brand_name'] ?? '')) !== '') {
        $node['brand'] = ['@type' => 'Brand', 'name' => sh_seo_plain((string)$product['brand_name'])];
    }

    // An Offer is only truthful when the store has a real price for the item.
    $price = (float)($product['price'] ?? 0);
    if ($price > 0) {
        $stock = (int)($product['stock'] ?? 0);
        $node['offers'] = [
            '@type' => 'Offer',
            'url'   => $url,
            'price' => number_format($price, 2, '.', ''),
            'priceCurrency' => $currency,
            'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            // The store's real policy, from the same settings the pages show.
            'seller' => ['@id' => sh_seo_base() . '/#organization'],
        ];
    }

    // AggregateRating only from reviews the store really holds and shows.
    $reviewCount = (int)($product['review_count'] ?? 0);
    $rating = (float)($product['rating'] ?? 0);
    if ($reviewCount > 0 && $rating > 0) {
        $node['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($rating, 1, '.', ''),
            'reviewCount' => $reviewCount,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }
    return $node;
}

// ===========================================================================
// Rendering
// ===========================================================================

/**
 * All <head> tags this module owns, as an HTML string.
 *
 * Rendered in one call so a page cannot accidentally emit a second title or a
 * second description: there is exactly one writer for each of these tags.
 */
function sh_seo_head_html(array $meta): string
{
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $out = [];

    $out[] = '<title>' . $e((string)$meta['title']) . '</title>';
    if ((string)$meta['description'] !== '') {
        $out[] = '<meta name="description" content="' . $e((string)$meta['description']) . '">';
    }
    // Keywords are for internal organisation and legacy engines only; Google
    // ignores them and no ranking depends on them.
    if (($meta['keywords'] ?? []) !== []) {
        $out[] = '<meta name="keywords" content="' . $e(implode(', ', (array)$meta['keywords'])) . '">';
    }
    $out[] = '<meta name="robots" content="' . $e((string)$meta['robots']) . '">';
    if ((string)$meta['canonical'] !== '') {
        $out[] = '<link rel="canonical" href="' . $e((string)$meta['canonical']) . '">';
    }
    $out[] = '<meta name="generator" content="ShopHaat">';

    if (sh_seo_get('seo_og_enabled', '1') === '1') {
        $out[] = '<meta property="og:type" content="' . $e((string)$meta['type']) . '">';
        $out[] = '<meta property="og:site_name" content="' . $e((string)$meta['site_name']) . '">';
        $out[] = '<meta property="og:title" content="' . $e((string)$meta['title']) . '">';
        if ((string)$meta['description'] !== '') {
            $out[] = '<meta property="og:description" content="' . $e((string)$meta['description']) . '">';
        }
        if ((string)$meta['canonical'] !== '') {
            $out[] = '<meta property="og:url" content="' . $e((string)$meta['canonical']) . '">';
        }
        if ((string)$meta['image'] !== '') {
            $out[] = '<meta property="og:image" content="' . $e((string)$meta['image']) . '">';
            $out[] = '<meta property="og:image:alt" content="' . $e((string)$meta['title']) . '">';
        }
        $out[] = '<meta property="og:locale" content="' . $e((string)$meta['locale']) . '">';
    }

    if (sh_seo_get('seo_twitter_enabled', '1') === '1') {
        $card = (string)$meta['image'] !== '' ? 'summary_large_image' : 'summary';
        $out[] = '<meta name="twitter:card" content="' . $e($card) . '">';
        $out[] = '<meta name="twitter:title" content="' . $e((string)$meta['title']) . '">';
        if ((string)$meta['description'] !== '') {
            $out[] = '<meta name="twitter:description" content="' . $e((string)$meta['description']) . '">';
        }
        if ((string)$meta['image'] !== '') {
            $out[] = '<meta name="twitter:image" content="' . $e((string)$meta['image']) . '">';
            $out[] = '<meta name="twitter:image:alt" content="' . $e((string)$meta['title']) . '">';
        }
        $site = sh_seo_get('seo_twitter_site');
        if ($site !== '' && preg_match('/^@?[A-Za-z0-9_]{1,15}$/', $site) === 1) {
            $out[] = '<meta name="twitter:site" content="' . $e(str_starts_with($site, '@') ? $site : '@' . $site) . '">';
        }
    }

    // Verification values are emitted only when the owner saved one, and are
    // validated on save so a broken value cannot inject an attribute.
    foreach ([
        'seo_verify_google'    => 'google-site-verification',
        'seo_verify_bing'      => 'msvalidate.01',
        'seo_verify_pinterest' => 'p:domain_verify',
    ] as $key => $tagName) {
        $v = trim(sh_seo_get($key));
        if ($v === '' || preg_match('/^[A-Za-z0-9_\-.:]{10,200}$/', $v) !== 1) { continue; }
        $out[] = '<meta name="' . $tagName . '" content="' . $e($v) . '">';
    }

    // One consolidated JSON-LD block per page.
    $graph = (array)($meta['schema'] ?? []);
    if ($graph !== []) {
        $payload = count($graph) === 1
            ? array_merge(['@context' => 'https://schema.org'], $graph[0])
            : ['@context' => 'https://schema.org', '@graph' => array_values($graph)];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== '') {
            // A closing tag inside the payload would end the script early; the
            // escaping below is required for JSON-LD embedded in HTML.
            $json = str_replace(['<', '>', '&'], ['\u003C', '\u003E', '\u0026'], $json);
            // A data block is not executed, but it still carries the nonce so
            // every inline <script> in the markup follows one rule.
            $out[] = '<script type="application/ld+json"' . sh_csp_nonce_attr() . '>' . $json . '</script>';
        }
    }

    return implode("\n", $out);
}

// ===========================================================================
// Sitemap
// ===========================================================================

/**
 * Every indexable public URL, in a stable order.
 *
 * Excluded on purpose: the cart, checkout, payment, sign-in and sign-up pages,
 * the account area, orders, order tracking, the installer, the admin panel,
 * private API routes, filtered and internal search results, and anything the
 * owner marked noindex. Each rule says why, so a wrong inclusion is visible.
 *
 * @return array<int,array{loc:string,lastmod:string,source:string}>
 */
function sh_seo_sitemap_entries(): array
{
    $entries = [];
    $add = static function (string $loc, string $lastmod, string $source) use (&$entries): void {
        $loc = trim($loc);
        if ($loc === '') { return; }
        $entries[mb_strtolower($loc)] = ['loc' => $loc, 'lastmod' => $lastmod, 'source' => $source];
    };

    // ---- homepage --------------------------------------------------------
    if (!sh_seo_page_is_noindex('home')) {
        // No lastmod: the homepage has no single honest modification date.
        $add(sh_seo_base() . '/', '', 'home');
    }

    // ---- static informational pages -------------------------------------
    foreach (sh_seo_pages_catalog() as $key => $info) {
        if (empty($info['indexable']) || (string)$info['path'] === '') { continue; }
        if (sh_seo_page_is_noindex($key)) { continue; }
        $row = sh_seo_page_row($key);
        $lastmod = (string)($row['updated_at'] ?? '');
        // A page whose text is generated by the application has no per-page
        // timestamp, so it is submitted without one rather than with a fake one.
        $add(sh_seo_canonical_url((string)$info['path']), $lastmod, 'page:' . $key);
    }

    // ---- product and category listings ----------------------------------
    if (!sh_seo_page_is_noindex('products')) {
        $row = sh_seo_page_row('products');
        $add(sh_seo_canonical_url('products.php'), (string)($row['updated_at'] ?? ''), 'page:products');
    }

    // ---- categories ------------------------------------------------------
    try {
        $hasNoindex = sh_seo_has_column('categories', 'seo_noindex');
        $sql = 'SELECT slug, updated_at' . ($hasNoindex ? ', seo_noindex' : '') . '
                FROM categories WHERE status = 1 ORDER BY sort_order ASC, id ASC';
        foreach (sh_all($sql) as $c) {
            if ($hasNoindex && (int)$c['seo_noindex'] === 1) { continue; }
            $slug = (string)$c['slug'];
            if ($slug === '') { continue; }
            $add(sh_seo_abs('category.php?slug=' . rawurlencode($slug)), (string)$c['updated_at'], 'category');
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'seo-sitemap-categories');
    }

    // ---- published products ---------------------------------------------
    try {
        $cols = ['slug', 'updated_at', 'id'];
        if (sh_seo_has_column('products', 'seo_noindex')) { $cols[] = 'seo_noindex'; }
        $rows = sh_all('SELECT ' . implode(',', $cols) . ' FROM products WHERE status = 1 ORDER BY id ASC');
        $hasNoindex = in_array('seo_noindex', $cols, true);
        foreach ($rows as $p) {
            if ($hasNoindex && (int)($p['seo_noindex'] ?? 0) === 1) { continue; }
            $slug = (string)$p['slug'];
            if ($slug === '') { continue; }
            $add(sh_seo_abs('product.php?slug=' . rawurlencode($slug)), (string)$p['updated_at'], 'product');
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'seo-sitemap-products');
    }

    // ---- published blog posts, when the store actually has any ----------
    try {
        if (sh_table_exists('blog_posts')) {
            $hasNoindex = sh_seo_has_column('blog_posts', 'seo_noindex');
            $sql = "SELECT slug, updated_at, published_at FROM blog_posts WHERE status = 'published'";
            if ($hasNoindex) { $sql .= ' AND (seo_noindex = 0 OR seo_noindex IS NULL)'; }
            $sql .= ' ORDER BY published_at DESC, id DESC';
            foreach (sh_all($sql) as $b) {
                $slug = (string)$b['slug'];
                if ($slug === '') { continue; }
                // Real publication time is an honest lastmod; created_at is not.
                $lastmod = (string)($b['updated_at'] ?? '') !== '' ? (string)$b['updated_at'] : (string)($b['published_at'] ?? '');
                $add(sh_seo_abs('blog.php?slug=' . rawurlencode($slug)), $lastmod, 'post');
            }
        }
    } catch (Throwable $e) {
        // No blog module installed is normal, not an error worth escalating.
        sh_log_exception($e, 'seo-sitemap-posts');
    }

    return array_values($entries);
}

/**
 * Is this page key (or the page's entity) deliberately marked noindex?
 *
 * The sitemap and the page itself must agree: offering a noindex URL for
 * crawling is a contradiction search engines report as an error.
 */
function sh_seo_page_is_noindex(string $key): bool
{
    $row = sh_seo_page_row($key);
    return (int)($row['seo_noindex'] ?? 0) === 1;
}

/** XML text escaping, including the characters only XML cares about. */
function sh_seo_xml_escape(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $text);
}

/**
 * A valid <lastmod> value, or '' when the timestamp cannot be trusted.
 *
 * A zero date, a future date or an unparseable string is dropped: publishing a
 * wrong modification date is worse than publishing none.
 */
function sh_seo_lastmod(string $datetime): string
{
    $datetime = trim($datetime);
    if ($datetime === '' || str_starts_with($datetime, '0000-00-00')) { return ''; }
    $ts = strtotime($datetime);
    if ($ts === false) { return ''; }
    // Allow a small clock skew, refuse anything further in the future.
    if ($ts > time() + 3600) { return ''; }
    if ($ts < 946684800) { return ''; } // before 2000 — certainly a placeholder
    return gmdate('Y-m-d\TH:i:s+00:00', $ts);
}

/** Split entries into chunks of at most $perFile URLs, keeping the sitemap limit. */
function sh_seo_sitemap_chunks(array $entries, int $perFile): array
{
    $perFile = max(100, min(45000, $perFile));   // well inside the 50,000 limit
    return array_chunk($entries, $perFile);
}

/**
 * One <urlset> document.
 *
 * @param array $entries rows from sh_seo_sitemap_entries()
 */
function sh_seo_sitemap_xml(array $entries): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $seen = [];
    foreach ($entries as $e) {
        $loc = (string)($e['loc'] ?? '');
        if ($loc === '' || isset($seen[$loc])) { continue; }
        $seen[$loc] = true;
        $xml .= "  <url>\n    <loc>" . sh_seo_xml_escape($loc) . "</loc>\n";
        $lastmod = sh_seo_lastmod((string)($e['lastmod'] ?? ''));
        // No changefreq and no priority: both are ignored by Google and would
        // only be invented values here.
        if ($lastmod !== '') { $xml .= '    <lastmod>' . $lastmod . "</lastmod>\n"; }
        $xml .= "  </url>\n";
    }
    return $xml . '</urlset>' . "\n";
}

/** A <sitemapindex> document for a store with more URLs than one file allows. */
function sh_seo_sitemap_index_xml(int $files): string
{
    $base = sh_seo_base();
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    for ($i = 1; $i <= $files; $i++) {
        $xml .= "  <sitemap>\n    <loc>" . sh_seo_xml_escape($base . '/sitemap-' . $i . '.xml') . "</loc>\n  </sitemap>\n";
    }
    return $xml . '</sitemapindex>' . "\n";
}

/**
 * Build every sitemap chunk, cache it, and return the number of chunks.
 *
 * Runs only when the cached count has expired or was cleared, so a crawler
 * walking a large sitemap index never triggers a catalogue query. Chunks left
 * over from an earlier, larger catalogue are removed first, so a stale
 * /sitemap-7.xml cannot keep listing products that no longer exist.
 */
function sh_seo_sitemap_rebuild(int $perFile): int
{
    $entries = sh_seo_sitemap_entries();
    $chunks = sh_seo_sitemap_chunks($entries, $perFile);
    $count = count($chunks);
    foreach (glob(sh_seo_cache_dir() . '/seo-sitemap-[0-9]*') ?: [] as $f) {
        if (is_file($f)) { @unlink($f); }
    }
    for ($i = 0; $i < $count; $i++) {
        sh_seo_cache_write('seo-sitemap-' . ($i + 1), sh_seo_sitemap_xml($chunks[$i]));
    }
    sh_seo_cache_write('seo-sitemap-count', (string)$count);
    return $count;
}


// ===========================================================================
// Cache
// ===========================================================================

/** Directory for generated SEO artifacts. Inside logs/, which denies HTTP access. */
function sh_seo_cache_dir(): string
{
    $dir = SH_LOG_DIR . '/seo';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    return $dir;
}

/** Cached sitemap body, or null when there is none or it is stale. */
function sh_seo_cache_read(string $name, int $maxAgeSeconds): ?string
{
    $file = sh_seo_cache_dir() . '/' . preg_replace('/[^a-z0-9._-]/i', '', $name);
    if (!is_file($file)) { return null; }
    $age = time() - (int)filemtime($file);
    if ($age < 0 || $age > $maxAgeSeconds) { return null; }
    $data = @file_get_contents($file);
    return is_string($data) && $data !== '' ? $data : null;
}

/** Write a cache file atomically so a reader never sees a half-written sitemap. */
function sh_seo_cache_write(string $name, string $data): void
{
    $dir = sh_seo_cache_dir();
    $file = $dir . '/' . preg_replace('/[^a-z0-9._-]/i', '', $name);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data) === false) { return; }
    if (!@rename($tmp, $file)) { @unlink($tmp); }
}

/** Drop every cached artifact. Called when SEO settings or content change. */
function sh_seo_cache_clear(): void
{
    $dir = sh_seo_cache_dir();
    foreach (glob($dir . '/seo-*') ?: [] as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}

/** Invalidate the sitemap because content changed. Cheap and safe to over-call. */
function sh_seo_cache_invalidate(): void
{
    sh_seo_cache_clear();
}

/** When the sitemap cache was last written, for the dashboard. */
function sh_seo_cache_time(string $name): ?int
{
    $file = sh_seo_cache_dir() . '/' . preg_replace('/[^a-z0-9._-]/i', '', $name);
    return is_file($file) ? (int)filemtime($file) : null;
}

// ===========================================================================
// robots.txt
// ===========================================================================

/**
 * The robots.txt body.
 *
 * Public file, so it is deliberately boring: it points crawlers at the
 * sitemap, keeps them out of the paths that only make sense with a session or
 * behind a login, and never disallows a private page's *content* as its only
 * protection — authentication does that. Nothing here reveals a secret, and
 * no rule blocks CSS, JavaScript or images, which the crawler needs in order to
 * render a page the way a shopper sees it.
 */
function sh_seo_robots_txt(): string
{
    $base = sh_seo_base();
    $lines = [];

    $lines[] = '# robots.txt for ' . (string)sh_setting('site_name', 'ShopHaat');
    $lines[] = '# Generated from the admin SEO Manager. Public file — no secrets here.';
    $lines[] = '';

    // Every crawler is welcome on the public catalogue.
    $lines[] = 'User-agent: *';
    $lines[] = 'Allow: /';

    // Session-only and transactional paths. Each one either needs a signed-in
    // customer or is a one-time action, so a crawler has nothing to gain and
    // a stored URL could leak a customer's state. Real protection is the
    // authentication on those pages; this only reduces wasted crawling.
    foreach ([
        '/cart.php',
        '/checkout.php',
        '/payment.php',
        '/account.php',
        '/orders.php',
        '/order-details.php',
        '/order-success.php',
        '/track.php',
        '/login.php',
        '/register.php',
        '/logout.php',
        '/password.php',
        '/reset-password.php',
        '/forgot-password.php',
        '/otp.php',
        '/addresses.php',
    ] as $path) {
        $lines[] = 'Disallow: ' . $path;
    }

    // Filtered and sorted catalogue views create near-duplicate URLs. They are
    // allowed to be crawled (the links are legitimate) but not wanted as index
    // entries, so they are not disallowed here — the noindex directive on the
    // page itself is what keeps them out.
    $lines[] = '';
    $lines[] = '# Never worth crawling: the admin panel, installer and internal endpoints.';
    foreach (['/admin/', '/install/', '/api/', '/ai/', '/auth/', '/includes/', '/config/', '/logs/'] as $path) {
        $lines[] = 'Disallow: ' . $path;
    }

    // Internal search results are infinite and low value.
    $lines[] = 'Disallow: /*?q=';
    $lines[] = 'Disallow: /*&q=';

    $lines[] = '';
    $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';

    return implode("\n", $lines) . "\n";
}

// ===========================================================================
// SEO audit (dashboard)
// ===========================================================================

/**
 * Count indexable content and find metadata problems.
 *
 * Runs a handful of aggregate queries — no per-row HTTP requests, no external
 * calls — so it stays usable on shared hosting.
 *
 * @return array{counts:array,issues:array,duplicates:array}
 */
function sh_seo_audit(bool $deep = false): array
{
    $out = [
        'counts' => [
            'products_indexable' => 0, 'products_noindex' => 0, 'products_hidden' => 0,
            'categories_indexable' => 0, 'categories_noindex' => 0,
            'products_missing_title' => 0, 'products_missing_desc' => 0,
            'categories_missing_title' => 0, 'categories_missing_desc' => 0,
            'pages_noindex' => 0, 'posts_indexable' => 0,
        ],
        'issues' => [],
        'duplicates' => ['titles' => [], 'descriptions' => []],
    ];

    try {
        $pNoindex = sh_seo_has_column('products', 'seo_noindex');
        $cNoindex = sh_seo_has_column('categories', 'seo_noindex');

        $out['counts']['products_hidden'] = (int)sh_val('SELECT COUNT(*) FROM products WHERE status = 0', [], 0);
        $where = $pNoindex ? ' WHERE status = 1 AND (seo_noindex = 0 OR seo_noindex IS NULL)' : ' WHERE status = 1';
        $out['counts']['products_indexable'] = (int)sh_val('SELECT COUNT(*) FROM products' . $where, [], 0);
        if ($pNoindex) {
            $out['counts']['products_noindex'] = (int)sh_val('SELECT COUNT(*) FROM products WHERE status = 1 AND seo_noindex = 1', [], 0);
        }

        $cWhere = $cNoindex ? ' WHERE status = 1 AND (seo_noindex = 0 OR seo_noindex IS NULL)' : ' WHERE status = 1';
        $out['counts']['categories_indexable'] = (int)sh_val('SELECT COUNT(*) FROM categories' . $cWhere, [], 0);
        if ($cNoindex) {
            $out['counts']['categories_noindex'] = (int)sh_val('SELECT COUNT(*) FROM categories WHERE status = 1 AND seo_noindex = 1', [], 0);
        }

        // "Missing" means neither a custom value nor usable page data: a
        // product with a name and a description does not need a custom title.
        $out['counts']['products_missing_title'] = (int)sh_val(
            "SELECT COUNT(*) FROM products WHERE status = 1 AND (meta_title IS NULL OR TRIM(meta_title) = '')", [], 0);
        $out['counts']['products_missing_desc'] = (int)sh_val(
            "SELECT COUNT(*) FROM products
             WHERE status = 1
               AND (meta_description IS NULL OR TRIM(meta_description) = '')
               AND (short_description IS NULL OR TRIM(short_description) = '')
               AND (description IS NULL OR TRIM(description) = '')", [], 0);
        $out['counts']['categories_missing_title'] = (int)sh_val(
            "SELECT COUNT(*) FROM categories WHERE status = 1 AND (meta_title IS NULL OR TRIM(meta_title) = '')", [], 0);
        $out['counts']['categories_missing_desc'] = (int)sh_val(
            "SELECT COUNT(*) FROM categories
             WHERE status = 1
               AND (meta_description IS NULL OR TRIM(meta_description) = '')
               AND (description IS NULL OR TRIM(description) = '')", [], 0);

        $out['counts']['pages_noindex'] = (int)sh_val('SELECT COUNT(*) FROM seo_pages WHERE seo_noindex = 1', [], 0);

        if (sh_table_exists('blog_posts')) {
            $out['counts']['posts_indexable'] = (int)sh_val("SELECT COUNT(*) FROM blog_posts WHERE status = 'published'", [], 0);
        }

        // ---- duplicate metadata, computed in SQL (no full table in PHP) ----
        $out['duplicates']['titles'] = sh_all(
            "SELECT meta_title AS value, COUNT(*) AS n FROM products
             WHERE status = 1 AND meta_title IS NOT NULL AND TRIM(meta_title) <> ''
             GROUP BY meta_title HAVING COUNT(*) > 1 ORDER BY n DESC, value ASC LIMIT 25"
        );
        $out['duplicates']['descriptions'] = sh_all(
            "SELECT meta_description AS value, COUNT(*) AS n FROM products
             WHERE status = 1 AND meta_description IS NOT NULL AND TRIM(meta_description) <> ''
             GROUP BY meta_description HAVING COUNT(*) > 1 ORDER BY n DESC, value ASC LIMIT 25"
        );

        // ---- problems worth a warning ------------------------------------
        if ((int)$out['counts']['products_indexable'] === 0) {
            $out['issues'][] = ['level' => 'warning', 'message' => 'No published product is currently indexable, so the sitemap contains no product URLs.'];
        }
        if (sh_seo_base() === '' || !sh_seo_valid_absolute_url(sh_seo_base())) {
            $out['issues'][] = ['level' => 'error', 'message' => 'The canonical domain is not a valid absolute URL. Set it in the SEO Manager before submitting a sitemap.'];
        }
        if (sh_seo_get('seo_canonical_base') === '') {
            $out['issues'][] = ['level' => 'info', 'message' => 'No canonical domain was saved; the General Settings site URL is being used instead.'];
        }
        if (sh_seo_get('seo_default_image') === '' && (string)sh_setting('site_logo', '') === '') {
            $out['issues'][] = ['level' => 'warning', 'message' => 'No default social sharing image is set, so shared links fall back to a text card.'];
        }
        if (sh_seo_get('seo_verify_google') === '') {
            $out['issues'][] = ['level' => 'info', 'message' => 'No Google verification value is saved. Search Console cannot be verified until it is.'];
        }
        if (sh_seo_get('seo_canonical_base') !== '' && !str_starts_with(sh_seo_get('seo_canonical_base'), 'https://')) {
            $out['issues'][] = ['level' => 'warning', 'message' => 'The canonical domain does not use HTTPS. Search Console treats the http and https versions separately.'];
        }

        if ($deep) {
            // Expensive checks are opt-in from the dashboard's Recheck button.
            $brokenCanonical = sh_all(
                "SELECT id, name, seo_canonical FROM products
                 WHERE status = 1 AND seo_canonical IS NOT NULL AND TRIM(seo_canonical) <> ''
                 ORDER BY id DESC LIMIT 200"
            );
            $bad = 0;
            foreach ($brokenCanonical as $row) {
                if (sh_seo_clean_url((string)$row['seo_canonical']) === '') { $bad++; }
            }
            if ($bad > 0) {
                $out['issues'][] = ['level' => 'error',
                    'message' => $bad . ' product(s) have a canonical URL that is not a valid http(s) address or site path. Clear those fields or correct them.'];
            }

            $noImage = (int)sh_val(
                "SELECT COUNT(*) FROM products WHERE status = 1 AND (image IS NULL OR TRIM(image) = '')", [], 0);
            if ($noImage > 0) {
                $out['issues'][] = ['level' => 'info',
                    'message' => $noImage . ' published product(s) have no image, so their social cards and image results will be empty.'];
            }

            $noOg = (int)sh_val(
                "SELECT COUNT(*) FROM categories c WHERE c.status = 1
                   AND (c.seo_og_image IS NULL OR TRIM(c.seo_og_image) = '')
                   AND (c.image IS NULL OR TRIM(c.image) = '')", [], 0);
            if ($noOg > 0) {
                $out['issues'][] = ['level' => 'info',
                    'message' => $noOg . ' category page(s) have no image of their own and will share the site default.'];
            }
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'seo-audit');
        // The detailed error is in the log; this text is shown in the admin UI.
        $out['issues'][] = ['level' => 'error', 'message' => 'The audit could not read the catalogue. The error has been logged.'];
    }

    return $out;
}
