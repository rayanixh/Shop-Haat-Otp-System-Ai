<?php
/**
 * Search suggestions (GET, read-only).
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'Not installed.'], 503);
}
try {
    sh_db();
    require_once SH_ROOT . '/includes/catalog.php';
    $q = sh_get('q');
    $items = [];
    foreach (sh_search_suggest($q, 8) as $p) {
        $items[] = [
            'name'  => $p['name'],
            'url'   => sh_url('product.php?slug=' . urlencode($p['slug'])),
            'image' => sh_product_image($p['image']),
            'price' => sh_money($p['price']),
        ];
    }
    sh_json(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-search');
    sh_json(['success' => false, 'error' => 'Search is unavailable.'], 500);
}
