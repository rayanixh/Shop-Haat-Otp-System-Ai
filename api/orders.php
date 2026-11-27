<?php
/**
 * Order status lookup for the customer (own orders only).
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-orders-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/order-tracking.php';

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sh_json(['success' => false, 'error' => 'POST required.'], 405); }
$action = sh_post('action', 'status');
// Public tracking is intentionally supported, but shares atomic limits with the
// HTML tracker. Every other action is private and uses the authenticated session.
if ($action !== 'track') {
    $requestedOrderId = sh_int($_POST['order_id'] ?? 0);
    sh_require_authenticated_customer($requestedOrderId > 0 ? 'order-details.php?id=' . $requestedOrderId : 'orders.php');
}
sh_csrf_require();

try {
    if ($action === 'track') {
        header('Cache-Control: no-store, private');
        $number = strtoupper(trim(mb_substr(sh_post('order_number'), 0, 40)));
        $phone = trim(mb_substr(sh_post('phone'), 0, 30));
        if (sh_order_tracking_rate_limited($number, $phone)) {
            header('Retry-After: 600');
            sh_json(['success' => false, 'error' => 'Too many tracking attempts. Please wait and try again.'], 429);
        }
        $variants = sh_phone_variants($phone);
        if (!preg_match('/^[A-Z0-9-]{4,40}$/', $number) || !$variants) {
            sh_json(['success' => false, 'error' => 'No order matched those details.'], 404);
        }
        $holders = implode(',', array_fill(0, count($variants), '?'));
        // Return only fields needed for the public status response; never load
        // customer contact or shipping data for this unauthenticated route.
        $o = sh_one(
            'SELECT order_number, status, payment_status, created_at
             FROM orders WHERE order_number = ? AND customer_phone IN (' . $holders . ') LIMIT 1',
            array_merge([$number], $variants)
        );
        if ($o === null) {
            sh_json(['success' => false, 'error' => 'No order matched those details.'], 404);
        }
        sh_json([
            'success' => true,
            'order' => [
                'number' => $o['order_number'],
                'status' => sh_status_label($o['status']),
                'status_key' => $o['status'],
                'payment' => sh_status_label($o['payment_status']),
                'placed' => date('d M Y, h:i A', strtotime($o['created_at'])),
            ],
        ]);
    }

    $orderId = sh_int($_POST['order_id'] ?? 0);
    $order = $orderId > 0 ? sh_customer_order_get($orderId) : null;
    if ($order === null) {
        sh_json(['success' => false, 'error' => 'Order not found.'], 404);
    }
    sh_json([
        'success' => true,
        'status' => sh_status_label($order['status']),
        'status_key' => $order['status'],
        'payment_status' => sh_status_label($order['payment_status']),
    ]);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-orders');
    sh_json(['success' => false, 'error' => 'Could not load the order.'], 500);
}
