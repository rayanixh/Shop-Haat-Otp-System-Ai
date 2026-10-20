<?php
/**
 * Admin — live preview of the storefront category grid.
 *
 * Loaded in an iframe by Settings → Category Display. It renders the REAL
 * category cards (the same helper the homepage uses) inside the REAL storefront
 * stylesheet, so the preview cannot drift from the shipped layout: changing the
 * scale only changes the same CSS variable the storefront reads.
 *
 * This is a fragment of the storefront, not a second implementation, and it is
 * never indexable.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('settings.view');

/** A scale from the query string, or the saved one. Constrained, never free text. */
$scaleRaw = trim((string)($_GET['scale'] ?? ''));
$scale = preg_match('/^\d{1,3}$/', $scaleRaw) === 1 ? (int)$scaleRaw : sh_category_card_scale();
if ($scale < 40 || $scale > 100) { $scale = sh_category_card_scale(); }

$rows = [];
try {
    $rows = sh_all(
        'SELECT id, name, slug, image FROM categories WHERE status = 1 ORDER BY sort_order ASC, name ASC LIMIT 24'
    );
} catch (Throwable $e) {
    $rows = [];
}
$isSample = false;
if ($rows === []) {
    // Nothing to show yet. Sample names make the sizing visible without
    // pretending they are real categories.
    $isSample = true;
    $rows = [
        ['name' => 'Mobile Phones', 'slug' => 'sample-1', 'image' => ''],
        ['name' => 'Electronics',   'slug' => 'sample-2', 'image' => ''],
        ['name' => 'Home & Kitchen','slug' => 'sample-3', 'image' => ''],
        ['name' => 'Fashion',       'slug' => 'sample-4', 'image' => ''],
        ['name' => 'Beauty',        'slug' => 'sample-5', 'image' => ''],
        ['name' => 'Grocery',       'slug' => 'sample-6', 'image' => ''],
        ['name' => 'Sports',        'slug' => 'sample-7', 'image' => ''],
        ['name' => 'Books',         'slug' => 'sample-8', 'image' => ''],
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<base target="_blank">
<title>Category grid preview</title>
<link rel="stylesheet" href="<?= e(sh_asset('assets/css/app.css')) ?>">
<?= sh_category_display_style_tag($scale) ?>
<style>
  /* Only the frame's own chrome: the grid itself is untouched storefront CSS. */
  html, body { margin: 0; padding: 0; background: var(--sh-bg-2); }
  .sh-catpreview { padding: 14px; }
  .sh-catpreview__note { font-size: 11.5px; color: var(--sh-muted); margin: 0 0 10px; }
  <?php if ($isSample): ?>
  /* Sample names only exist to show the size; they must not act as links. */
  .sh-catpreview .sh-catgrid { pointer-events: none; }
  <?php endif; ?>
</style>
</head>
<body>
<div class="sh-catpreview">
  <p class="sh-catpreview__note">
    <?= $isSample ? 'Sample names — you have no visible categories yet.' : 'Your live categories, at the size saved by this setting.' ?>
    Card width: <strong><?= (int)sh_category_card_min_width($scale) ?>px minimum</strong> ·
    <strong><?= (int)$scale ?>%</strong>
  </p>
  <div class="sh-catgrid">
    <?= sh_category_cards($rows) ?>
  </div>
</div>
</body>
</html>
