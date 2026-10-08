<?php
/**
 * AI Auto Work — shared bootstrap for every screen in this section.
 * Keeps each page thin and guarantees identical auth/permission handling.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/ai/AIManager.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('ai.use');
$adminId = (int)($admin['id'] ?? 0);

$aiInstalled = sh_ai_installed();
$aiConfig = $aiInstalled ? sh_ai_config() : ['has_key' => false, 'provider' => '', 'provider_id' => 0, 'driver' => '',
    'auto_save' => false, 'auto_publish' => false, 'model' => '', 'image_model' => '', 'language' => 'English',
    'tone' => 'Professional', 'seo_mode' => true, 'temperature' => 0.7, 'max_tokens' => 1200, 'batch_size' => 5,
    'max_retries' => 2, 'fallback_enabled' => false];

/** Standard banner shown when the module is not ready to make calls. */
function sh_ai_banner(bool $installed, array $cfg): void
{
    if (!$installed) {
        echo '<div class="sh-alert sh-alert--warning">' . sh_icon('alert', 17)
           . '<div><strong>AI Auto Work is not installed yet.</strong> '
           . 'Open <a href="' . e(sh_url('admin/ai/settings.php')) . '">AI Settings</a> and run the installer '
           . 'to create the required database tables.</div></div>';
        return;
    }
    if (empty($cfg['has_key'])) {
        echo '<div class="sh-alert sh-alert--warning">' . sh_icon('alert', 17)
           . '<div><strong>No AI provider is ready.</strong> Add a provider and its API key under '
           . '<a href="' . e(sh_url('admin/ai/providers.php')) . '">Providers</a>. '
           . 'Generation buttons stay disabled until then — nothing is faked.</div></div>';
    }
}

/** Sub-navigation, matching the existing admin button styling. */
function sh_ai_subnav(string $current): void
{
    $items = [
        'dashboard' => ['index.php',      'layout',   'AI Dashboard'],
        'product'   => ['products.php',   'box',      'Product AI'],
        'bulk'      => ['bulk.php',       'list',     'Bulk AI Generator'],
        'blog'      => ['blog.php',       'file',     'Blog AI'],
        'category'  => ['category.php',   'grid',     'Category AI'],
        'seo'       => ['seo.php',        'search',   'SEO AI'],
        'image'     => ['image.php',      'image',    'Image AI'],
        'history'   => ['history.php',    'clock',    'AI History'],
        'providers' => ['providers.php',  'cpu',      'Providers'],
        'models'    => ['models.php',     'list',     'Models'],
        'settings'  => ['settings.php',   'settings', 'AI Settings'],
    ];
    echo '<div class="sh-aisubnav">';
    foreach ($items as $key => [$file, $icon, $label]) {
        $on = $key === $current ? ' sh-aisubnav__link--on' : '';
        echo '<a class="sh-aisubnav__link' . $on . '" href="' . e(sh_url('admin/ai/' . $file)) . '">'
           . sh_icon($icon, 14) . '<span>' . e($label) . '</span></a>';
    }
    echo '</div>';
}
