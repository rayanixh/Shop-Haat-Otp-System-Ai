<?php
/**
 * Dynamic robots.txt.
 *
 * Reached as /robots.txt through the rewrite rules in the root .htaccess, with a
 * query-string fallback (robots.php) for hosts where rewriting is unavailable.
 *
 * This file is public and world-readable, so it contains nothing sensitive: no
 * admin paths that are not already guessable, no tokens, no table names. The
 * paths it discourages crawling are already protected by authentication —
 * robots.txt is a crawling hint, never an access control.
 *
 * It always answers as text/plain. A crawler that receives an HTML error page
 * where robots.txt should be reads it as a broken site, so every failure path
 * here falls back to the permissive default instead of an error document.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once SH_ROOT . '/includes/seo.php';

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
}

/** The permissive default: never block a crawler because of our own failure. */
function sh_seo_robots_default(): string
{
    return "# robots.txt\n"
        . "# The store could not describe itself just now, so nothing is disallowed.\n"
        . "# This is the safe answer: a blocked crawler cannot be un-blocked later.\n"
        . "User-agent: *\n"
        . "Allow: /\n";
}

// Before installation there is nothing to describe, and the store's canonical
// domain is unknown. Serve the permissive default rather than a wrong one.
if (!sh_is_locked() || sh_db_state()['status'] !== 'ok') {
    echo sh_seo_robots_default();
    exit;
}

try {
    $cached = sh_seo_cache_read('seo-robots', 3600);
    if ($cached !== null) {
        echo $cached;
        exit;
    }

    $body = sh_seo_robots_txt();
    // A body that lost its sitemap line is not worth caching.
    if (trim($body) === '') {
        echo sh_seo_robots_default();
        exit;
    }
    sh_seo_cache_write('seo-robots', $body);
    echo $body;
} catch (Throwable $e) {
    sh_log_exception($e, 'robots');
    echo sh_seo_robots_default();
}
