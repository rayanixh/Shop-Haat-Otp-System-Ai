<?php
/**
 * Admin-only: runs (or refreshes) courier history + IP lookups for one order
 * and returns the rendered Customer Verification card. POST + CSRF.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/verification.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
if (!sh_admin_can('verification.courier', 'verification.ip', 'verification.location')) { http_response_code(403); echo '<p class="sh-cv__muted">Access denied: your role cannot view customer verification.</p>'; exit; }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !sh_csrf_valid()) { http_response_code(419); echo '<p class="sh-cv__muted">Session expired. Refresh the page.</p>'; exit; }

$order = sh_order_get(sh_int($_POST['id'] ?? 0));
if ($order === null) { http_response_code(404); echo '<p class="sh-cv__muted">Order not found.</p>'; exit; }
$what = sh_post('refresh'); // '' (fill missing), 'courier', 'ip', 'all'
$vb = sh_verify_order_bundle($order, true, in_array($what, ['courier', 'all'], true), in_array($what, ['ip', 'all'], true));
if (!empty($vb['courier']['configured']) && !empty($vb['courier']['checked'])) {
    require_once SH_ROOT . '/includes/admin-tools.php';
    $cv = $vb['courier'];
    $ok = $cv['ok_count'] > 0;
    sh_admin_notify('courier_check', ($ok ? 'Courier history checked · ' : 'Courier history unavailable · ') . $order['order_number'],
        $ok ? ($cv['delivered'] . ' delivered, ' . $cv['returned'] . ' returned · ' . $cv['risk']['label']) : 'All configured providers failed.',
        'admin/orders.php?id=' . (int)$order['id'] . '#customer-verification', 'courier-' . (int)$order['id']);
}
if ($what !== '') { sh_log_line('admin', 'Customer verification refreshed (' . $what . ') for ' . $order['order_number'] . ' by ' . ($admin['email'] ?? '')); }
require __DIR__ . '/_verification-card.php';
