<?php
/**
 * Cart / wishlist AJAX endpoint. Always returns JSON.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-cart-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}

require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/cart.php';

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_json(['success' => false, 'error' => 'POST required.'], 405);
}
sh_csrf_require();

$action = sh_post('action');
$productId = sh_int($_POST['product_id'] ?? 0);
$quantity = sh_int($_POST['quantity'] ?? 1, 1);

try {
    switch ($action) {
        case 'add':
            if ($productId <= 0) { sh_json(['success' => false, 'error' => 'Invalid product.'], 400); }
            $r = sh_cart_add($productId, $quantity);
            sh_json($r['ok']
                ? ['success' => true, 'count' => $r['count'], 'message' => 'Added to your cart.']
                : ['success' => false, 'error' => $r['error']]);
            break;

        case 'update':
            if ($productId <= 0) { sh_json(['success' => false, 'error' => 'Invalid product.'], 400); }
            $r = sh_cart_update($productId, $quantity);
            sh_json($r['ok']
                ? ['success' => true, 'count' => $r['count'], 'message' => 'Cart updated.']
                : ['success' => false, 'error' => $r['error']]);
            break;

        case 'remove':
            if ($productId <= 0) { sh_json(['success' => false, 'error' => 'Invalid product.'], 400); }
            $r = sh_cart_remove($productId);
            sh_json(['success' => true, 'count' => $r['count'], 'message' => 'Item removed.']);
            break;

        case 'clear':
            sh_cart_clear();
            sh_json(['success' => true, 'count' => 0, 'message' => 'Cart cleared.']);
            break;

        case 'count':
            sh_json(['success' => true, 'count' => sh_cart_count()]);
            break;

        case 'wishlist':
            $u = sh_user();
            if ($u === null) {
                sh_json(['success' => false, 'auth_required' => true, 'error' => 'Please sign in to use your wishlist.'], 401);
            }
            if ($productId <= 0) { sh_json(['success' => false, 'error' => 'Invalid product.'], 400); }
            $exists = sh_one('SELECT id FROM wishlist WHERE user_id = ? AND product_id = ?', [(int)$u['id'], $productId]);
            if ($exists) {
                sh_query('DELETE FROM wishlist WHERE id = ?', [(int)$exists['id']]);
                sh_json(['success' => true, 'in_wishlist' => false, 'message' => 'Removed from your wishlist.']);
            }
            $p = sh_one('SELECT id FROM products WHERE id = ? AND status = 1', [$productId]);
            if ($p === null) { sh_json(['success' => false, 'error' => 'Product not available.'], 404); }
            sh_insert('wishlist', ['user_id' => (int)$u['id'], 'product_id' => $productId]);
            sh_json(['success' => true, 'in_wishlist' => true, 'message' => 'Saved to your wishlist.']);
            break;

        case 'coupon':
            $code = sh_post('code');
            $summary = sh_cart_summary(null);
            $res = sh_coupon_evaluate($code, $summary['subtotal']);
            if (!$res['valid']) { sh_json(['success' => false, 'error' => $res['error']]); }
            $_SESSION['coupon_code'] = strtoupper($code);
            sh_json(['success' => true, 'message' => 'Coupon applied.', 'discount' => sh_money($res['discount'])]);
            break;

        case 'coupon_remove':
            unset($_SESSION['coupon_code']);
            sh_json(['success' => true, 'message' => 'Coupon removed.']);
            break;

        default:
            sh_json(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    sh_log_exception($e, 'api-cart');
    sh_json(['success' => false, 'error' => 'The request could not be completed.'], 500);
}
