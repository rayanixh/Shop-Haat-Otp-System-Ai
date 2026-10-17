<?php
/**
 * Dynamic XML sitemap.
 *
 * Reached as /sitemap.xml (and /sitemap-1.xml, /sitemap-2.xml … for a large
 * catalogue) through the rewrite rules in the root .htaccess. A query-string
 * fallback (sitemap.php, sitemap.php?file=2) exists so the sitemap still works
 * on a host where rewriting is unavailable.
 *
 * Everything here is generated from real database rows. Nothing is invented: a
 * URL appears only when a published row backs it, and a <lastmod> appears only
 * when the row carries a trustworthy timestamp.
 *
 * Cost control: the entry list is built once and the finished XML for each
 * chunk is cached on disk, so a crawler walking a large sitemap index never
 * triggers a catalogue query. Saving a product or category clears the cache
 * immediately (see sh_seo_cache_invalidate()).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once SH_ROOT . '/includes/seo.php';

// sh_seo_sitemap_rebuild() (which builds and caches every chunk) lives in the
// SEO engine so the admin panel and the tests use the same code path.

/** The valid, empty document used whenever there is nothing to publish. */
function sh_seo_sitemap_empty_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . "\n";
}

/** Send the response as XML and stop. */
function sh_seo_sitemap_send(string $body, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/xml; charset=UTF-8');
        // Deliberately no X-Robots-Tag here. Google Search Console has been
        // observed treating a noindex header on a sitemap as a reason to hold
        // the file back, and the only benefit would be keeping the raw XML out
        // of search results. Discovery of the listed pages matters more.
        header('Cache-Control: public, max-age=600');
    }
    echo $body;
    exit;
}

// ---------------------------------------------------------------------------
// Guards. Each one answers with the correct status instead of a broken page.
// ---------------------------------------------------------------------------

// The store must be installed before it can describe itself. Without a working
// database there is nothing truthful to publish, so the sitemap is withheld
// (503) rather than served empty: an empty sitemap tells a crawler to forget
// every URL it already knows.
if (!sh_is_locked() || sh_db_state()['status'] !== 'ok') {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/xml; charset=UTF-8');
        header('Retry-After: 3600');
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<!-- The store is not installed or its database is unreachable. Try again later. -->' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>' . "\n";
    exit;
}

if (sh_seo_get('seo_sitemap_enabled', '1') !== '1') {
    // The owner switched the sitemap off: answer honestly instead of serving a
    // stale file, so a crawler sees the feature is disabled.
    sh_seo_sitemap_send(sh_seo_sitemap_empty_xml(), 404);
}

// file=0 is the entry point; file=N is chunk N of an index.
$file = max(0, sh_int($_GET['file'] ?? 0, 0));
$perFile = max(100, min(45000, sh_int(sh_seo_get('seo_sitemap_products', '2000'), 2000)));
$tti = max(5, min(1440, sh_int(sh_seo_get('seo_sitemap_cache_min', '30'), 30))) * 60;

try {
    // The store is installed, so the SEO columns should exist; make sure.
    sh_seo_schema_ensure();

    $cachedCount = sh_seo_cache_read('seo-sitemap-count', $tti);
    $count = $cachedCount === null ? sh_seo_sitemap_rebuild($perFile) : max(0, (int)$cachedCount);

    // Nothing indexable yet: a valid, empty document is the honest answer.
    if ($count === 0) {
        sh_seo_sitemap_send(sh_seo_sitemap_empty_xml());
    }

    if ($file > 0) {
        if ($file > $count) {
            // A URL that was never published: 404 rather than an empty 200, so
            // a crawler drops it instead of treating it as the current sitemap.
            sh_seo_sitemap_send(sh_seo_sitemap_empty_xml(), 404);
        }
        $body = sh_seo_cache_read('seo-sitemap-' . $file, $tti);
        if ($body === null) {
            // The chunk expired before the count did: rebuild the whole set
            // rather than querying only part of the catalogue.
            $count = sh_seo_sitemap_rebuild($perFile);
            $body = $file <= $count ? sh_seo_cache_read('seo-sitemap-' . $file, $tti) : null;
            if ($body === null) { sh_seo_sitemap_send(sh_seo_sitemap_empty_xml(), 404); }
        }
        sh_seo_sitemap_send($body);
    }

    if ($count > 1) {
        sh_seo_sitemap_send(sh_seo_sitemap_index_xml($count));
    }

    $body = sh_seo_cache_read('seo-sitemap-1', $tti);
    if ($body === null) {
        sh_seo_sitemap_rebuild($perFile);
        $body = sh_seo_cache_read('seo-sitemap-1', $tti);
    }
    sh_seo_sitemap_send($body ?? sh_seo_sitemap_empty_xml());
} catch (Throwable $e) {
    sh_log_exception($e, 'sitemap');
    // A broken sitemap must not look like a working one: the 500 tells the
    // crawler to keep the copy it already has, and the body stays valid XML so
    // nothing that reads it blindly sees an HTML error page.
    if (!headers_sent()) { header('Retry-After: 3600'); }
    sh_seo_sitemap_send(sh_seo_sitemap_empty_xml(), 500);
}
