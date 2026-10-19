<?php
/**
 * Page-transition test fixtures.
 *
 * Renders the REAL page-transition markup (includes/transition.php is required
 * as-is, never copied) so the JavaScript behaviour harness can drive the same
 * strings a browser receives. Only the surrounding application services are
 * stubbed — settings lookups, the URL helper, the escaper and the CSP nonce —
 * because this script runs without a database.
 *
 * Usage:
 *   php tests/page-transition-fixtures.php            → JSON map of every variant
 *   php tests/page-transition-fixtures.php head       → the default <head> snippet
 *
 * The JSON shape (one entry per variant): { html, head, overlay, body_attrs }
 * where `html` is a complete page that mirrors the tag order of
 * includes/header.php and admin/_layout.php.
 *
 * @package ShopHaat\Tests
 */

declare(strict_types=1);

$root = getenv('SH_ROOT') ?: dirname(__DIR__);
define('SH_ROOT', $root);
$uploadDir = getenv('SH_UPLOAD_DIR') ?: sys_get_temp_dir() . '/shophaat-transition-fixtures';
define('SH_UPLOAD_DIR', $uploadDir);

/** Settings the stubbed sh_setting() returns; replaced per variant. */
$GLOBALS['sh_fixture_settings'] = [];

function sh_setting(string $key, string $default = ''): string
{
    $v = $GLOBALS['sh_fixture_settings'][$key] ?? null;
    return $v === null ? $default : (string)$v;
}
function sh_url(string $path = ''): string { return '/' . ltrim($path, '/'); }
function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function sh_csp_nonce_attr(): string { return ' nonce="fixture-nonce"'; }

require_once SH_ROOT . '/includes/transition.php';

/**
 * A deterministic 1x1 GIF so `transition_media` / `transition_bg_media` resolve
 * to a real file the way an uploaded GIF would.
 */
function sh_fixture_media(string $file): string
{
    $dir = SH_UPLOAD_DIR . '/transitions';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The fixture upload directory could not be created: ' . $dir);
    }
    $path = $dir . '/' . basename($file);
    if (!is_file($path)) {
        // "GIF89a" + a 1x1 transparent image.
        file_put_contents($path, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    }
    return $path;
}

/** A complete page in the exact tag order of the shipped layouts. */
function sh_fixture_page(array $cfg, bool $admin = false): string
{
    $title = $admin ? 'Admin fixture' : 'Storefront fixture';
    $inner = $admin
        ? "<div class=\"sh-admin\">\n  <aside class=\"sh-admin-sidebar\">nav</aside>\n  <div class=\"sh-admin-main\" id=\"sh-main\">\n"
            . "    <h1>Admin fixture page</h1>\n    <p id=\"sh-fixture-body\">admin content loaded</p>\n"
            . sh_fixture_links() . "\n  </div>\n</div>"
        : "<main class=\"sh-main\" id=\"sh-main\">\n"
            . "  <h1>Fixture page</h1>\n  <p id=\"sh-fixture-body\">page content loaded</p>\n"
            . sh_fixture_links() . "\n</main>";

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . e($title) . "</title>\n"
        . "<link rel=\"stylesheet\" href=\"/assets/css/app.css\">\n"
        . "<script nonce=\"fixture-nonce\">window.SH_BASE = '/'; window.SH_CSRF = 'fixture-token';</script>\n"
        . sh_transition_head($cfg) . "\n"
        . "</head>\n<body" . sh_transition_body_attrs($cfg) . ">\n"
        . sh_transition_overlay($cfg) . "\n"
        . $inner . "\n"
        // Both shipped footers render this container immediately before app.js.
        . "<div class=\"sh-toasts\" id=\"sh-toasts\" aria-live=\"polite\" aria-atomic=\"true\"></div>\n"
        . "<script src=\"/assets/js/app.js\" defer></script>\n"
        . "</body>\n</html>\n";
}

/** The click targets the behaviour harness asserts on. */
function sh_fixture_links(): string
{
    return implode("\n", [
        '  <a id="sh-fixture-internal" href="/cart.php">Internal link</a>',
        '  <a id="sh-fixture-internal-query" href="/products.php?sort=newest">Internal link with query</a>',
        '  <a id="sh-fixture-external" href="https://example.com/page">External link</a>',
        '  <a id="sh-fixture-blank" href="/help.php" target="_blank">New tab link</a>',
        '  <a id="sh-fixture-download" href="/track.php" download>Download link</a>',
        '  <a id="sh-fixture-modal" href="/product.php?slug=x" data-modal="1">Modal link</a>',
        '  <a id="sh-fixture-modal-open" href="/product.php?slug=x" data-modal-open="sh-demo-modal">Modal opener (the attribute the storefront uses)</a>',
        '  <a id="sh-fixture-toggle" href="/product.php?slug=x" data-toggle="x">Toggle link</a>',
        '  <a id="sh-fixture-no-transition" href="/help.php" data-no-transition>Opt-out link</a>',
        '  <a id="sh-fixture-hash" href="#sh-main">Hash link</a>',
        '  <a id="sh-fixture-mailto" href="mailto:shop@example.com">Mail link</a>',
        '  <a id="sh-fixture-logout" href="/logout.php">Logout link</a>',
        '  <a id="sh-fixture-image" href="/assets/images/placeholder.svg">Image link</a>',
        '  <button id="sh-fixture-ajax" type="button" data-add-cart="3">Add to cart (AJAX)</button>',
    ]) . "\n";
}

/** Build one variant under a given settings map. */
function sh_fixture_variant(array $settings, bool $admin = false): array
{
    $GLOBALS['sh_fixture_settings'] = $settings;
    $cfg = sh_transition_config();
    return [
        'config' => $cfg,
        'html' => sh_fixture_page($cfg, $admin),
        'head' => sh_transition_head($cfg),
        'overlay' => sh_transition_overlay($cfg),
        'body_attrs' => sh_transition_body_attrs($cfg),
    ];
}

$mediaFile = 'fixture-media.gif';
$bgFile = 'fixture-bg.gif';
sh_fixture_media($mediaFile);
sh_fixture_media($bgFile);

$variants = [
    // Defaults: enabled, 450 ms, smooth, gradient, no media.
    'default' => sh_fixture_variant([]),
    // The same defaults rendered through the admin layout wrapper.
    'admin' => sh_fixture_variant([], true),
    // Disabled in settings: nothing may be emitted at all.
    'disabled' => sh_fixture_variant(['transition_enabled' => '0']),
    // A fully configured transition: image background, centred GIF, custom
    // duration and the cinematic fade.
    'custom' => sh_fixture_variant([
        'transition_enabled' => '1',
        'transition_bg_type' => 'image',
        'transition_bg_color' => '#101828',
        'transition_bg_color2' => '#1d2939',
        'transition_bg_media' => $bgFile,
        'transition_media' => $mediaFile,
        'transition_bg_direction' => 'right',
        'transition_duration' => '150',
        'transition_fade' => 'cinematic',
    ]),
    // Untrusted / malformed stored values must fall back, never break the page.
    'sanitised' => sh_fixture_variant([
        'transition_enabled' => '1',
        'transition_bg_type' => '<script>alert(1)</script>',
        'transition_bg_color' => 'red; background:url(javascript:alert(1))',
        'transition_bg_media' => '../../config/database.php',
        'transition_media' => '../../evil.gif',
        'transition_fade' => '";}<script>',
        'transition_duration' => '999999',
    ]),
    'no_media' => sh_fixture_variant([
        'transition_enabled' => '1',
        'transition_bg_type' => 'image',
        'transition_bg_media' => 'missing-file.gif',
        'transition_media' => '',
        'transition_duration' => '300',
        'transition_fade' => 'soft',
    ]),
];

$mode = $argv[1] ?? '';
if ($mode === 'head') { echo $variants['default']['head']; exit(0); }
if ($mode === 'overlay') { echo $variants['default']['overlay']; exit(0); }

echo json_encode($variants, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
