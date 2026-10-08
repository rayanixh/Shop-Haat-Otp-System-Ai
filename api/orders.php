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

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sh_json(['success' => false, 'error' => 'POST required.'], 405); }
sh_csrf_require();

try {
    $action = sh_post('action', 'status');
    if ($action === 'track') {
        $number = strtoupper(sh_post('order_number'));
        $phone  = sh_post('phone');
        if ($number === '' || $phone === '') {
            sh_json(['success' => false, 'error' => 'Enter both the order number and the phone number used at checkout.']);
        }
        // Requires BOTH values — prevents order enumeration.
        $o = sh_one('SELECT id, order_number, status, payment_status, total, created_at, has_digital
                     FROM orders WHERE order_number = ? AND customer_phone = ? LIMIT 1', [$number, $phone]);
        if ($o === null) {
            sh_json(['success' => false, 'error' => 'No order matched those details.']);
        }
        sh_json([
            'success' => true,
            'order' => [
                'number' => $o['order_number'],
                'status' => sh_status_label($o['status']),
                'status_key' => $o['status'],
                'payment' => sh_status_label($o['payment_status']),
                'total' => sh_money($o['total']),
                'placed' => date('d M Y, h:i A', strtotime($o['created_at'])),
            ],
        ]);
    }

    $orderId = sh_int($_POST['order_id'] ?? 0);
    $order = $orderId > 0 ? sh_order_get($orderId) : null;
    if ($order === null || !sh_order_can_view($order)) {
        sh_json(['success' => false, 'error' => 'Order not found.'], 404);
    }
    sh_json([
        'success' => true,
        'status' => sh_status_label($order['status']),
        'status_key' => $order['status'],
        'payment_status' => sh_status_label($order['payment_status']),
        'codes_delivered' => (int)$order['codes_delivered'] === 1,
    ]);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-orders');
    sh_json(['success' => false, 'error' => 'Could not load the order.'], 500);
}
