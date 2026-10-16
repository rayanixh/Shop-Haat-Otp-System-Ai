<?php
/**
 * Homepage slider fixtures.
 *
 * Renders the REAL slider markup through includes/functions.php (sh_slider_markup()
 * is called as-is, never copied) so tests/slider-behavior.mjs can drive exactly
 * the string the storefront ships. Only the application services around it are
 * stubbed — settings lookups, the URL helper, the escaper and the upload dir —
 * because this script runs without a database.
 *
 * Usage:
 *   php tests/slider-fixtures.php            → JSON map of every variant
 *   php tests/slider-fixtures.php markup     → the two-slide page fragment
 *
 * @package ShopHaat\Tests
 */

declare(strict_types=1);

$root = getenv('SH_ROOT') ?: dirname(__DIR__);
define('SH_ROOT', $root);
$uploadDir = getenv('SH_UPLOAD_DIR') ?: sys_get_temp_dir() . '/shophaat-slider-fixtures';
define('SH_UPLOAD_DIR', $uploadDir);

/** Settings the real sh_setting() reads; the DB row shape is stubbed below. */
$GLOBALS['sh_fixture_settings'] = ['slider_interval_ms' => '5200', 'slider_fade_ms' => '700'];

/*
 * includes/functions.php is loaded as-is: e(), sh_setting(), sh_promo_image(),
 * sh_slider_interval_ms() and sh_slider_markup() are the shipped implementations.
 * Only the database-backed lookups they sit on are stubbed, because this script
 * has no database.
 */
function sh_all(string $sql, array $params = []): array
{
    if (stripos($sql, 'FROM settings') === false) { return []; }
    $rows = [];
    foreach ($GLOBALS['sh_fixture_settings'] as $k => $v) {
        $rows[] = ['setting_key' => $k, 'setting_value' => (string)$v];
    }
    return $rows;
}
function sh_log_line(string $channel, string $message): void {}
function sh_log_exception(Throwable $exception, string $context = 'exception'): void {}
function sh_url(string $path = ''): string { return '/' . ltrim($path, '/'); }

require_once SH_ROOT . '/includes/functions.php';

/**
 * A slide row shaped like the promo_slides table, so sh_slider_markup() reads
 * the same keys it does in production.
 *
 * @return array<string,mixed>
 */
function sh_fixture_slide(int $i, string $title, string $subtitle, string $tag, string $image, int $overlay = 70): array
{
    return [
        'id' => $i + 1,
        'placement' => 'slider',
        'tag' => $tag,
        'title' => $title,
        'subtitle' => $subtitle,
        'button_text' => null,
        'button_url' => null,
        'image' => $image,
        'overlay' => $overlay,
        'sort_order' => $i + 1,
        'status' => 1,
    ];
}

/** Bundled artwork that really exists, so sh_promo_image() resolves it. */
$bundled = static function (string $file): string {
    return is_file(SH_ROOT . '/assets/images/' . $file) ? $file : ('promo/' . basename($file));
};

$variants = [
    'two-slides' => [
        sh_fixture_slide(0, 'Fixture Slide One', 'First slide description.', 'Fixture Badge', $bundled('promo/slide-flash.jpg')),
        sh_fixture_slide(1, 'Fixture Slide Two', 'Second slide description.', 'Second Badge', $bundled('promo/slide-grocery.jpg')),
    ],
    'one-slide' => [
        sh_fixture_slide(0, 'Only Slide', 'Single slide description.', 'Solo', $bundled('promo/slide-flash.jpg')),
    ],
    // A row whose file is not on disk: sh_promo_image() returns '' and the slide
    // must fall back to its plate while keeping the heading and text.
    'missing-image' => [
        sh_fixture_slide(0, 'Fixture Slide One', 'First slide description.', 'Fixture Badge', $bundled('promo/slide-flash.jpg')),
        sh_fixture_slide(1, 'Slide With No File', 'Text must survive a missing file.', 'Second Badge', 'promo/does-not-exist-fixture.jpg'),
    ],
    'no-slides' => [],
];

$out = [];
foreach ($variants as $name => $slides) {
    $out[$name] = ['markup' => sh_slider_markup($slides), 'slides' => count($slides)];
}

$arg = $argv[1] ?? '';
if ($arg === 'markup') {
    echo $out['two-slides']['markup'];
    exit(0);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
