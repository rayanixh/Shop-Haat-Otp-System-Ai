<?php
/**
 * Live checkout totals (coupon / delivery zone) recalculated server-side.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-checkout-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/cart.php';

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sh_json(['success' => false, 'error' => 'POST required.'], 405); }
sh_csrf_require();

try {
    $zone = sh_post('delivery_zone', 'inside') === 'outside' ? 'outside' : 'inside';
    $coupon = sh_post('coupon_code');
    if ($coupon === '' && !empty($_SESSION['coupon_code'])) { $coupon = (string)$_SESSION['coupon_code']; }

    $s = sh_cart_summary($coupon !== '' ? $coupon : null, $zone);
    sh_json([
        'success'  => true,
        'subtotal' => sh_money($s['subtotal']),
        'discount' => sh_money($s['discount']),
        'delivery' => $s['delivery'] > 0 ? sh_money($s['delivery']) : 'Free',
        'total'    => sh_money($s['total']),
        'count'    => $s['count'],
        'coupon_applied' => $s['coupon'] !== null,
        'issues'   => $s['issues'],
    ]);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-checkout');
    sh_json(['success' => false, 'error' => 'Could not recalculate your order.'], 500);
}
