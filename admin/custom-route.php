<?php
/** Custom admin URL dispatcher; direct /admin/custom-route.php is never a route. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$requestedPath = sh_admin_access_request_path();
if ($requestedPath === null) { sh_admin_access_not_found(); }

$state = sh_admin_access_state();
$isActive = $state['enabled'] && $state['active_path'] !== ''
    && hash_equals($state['active_path'], $requestedPath);
$isPending = $state['pending_path'] !== '' && hash_equals($state['pending_path'], $requestedPath);

if (!$isActive && !$isPending) { sh_admin_access_not_found(); }

if ($isPending && !$isActive) {
    $ticket = $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] ?? '';
    $expectedTicket = hash('sha256', $requestedPath);
    if (!is_string($ticket) || $ticket === '' || !hash_equals($expectedTicket, $ticket)) {
        sh_admin_access_not_found();
    }
}

// The route secret alone never authorizes the panel. This context merely allows
// the dispatcher to include a fixed, validated PHP route; every target still
// executes its existing authentication and role guards.
define('SH_ADMIN_ROUTE_CONTEXT', true);

if ($isPending && !$isActive) {
    unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
    $admin = sh_admin();
    if ($admin === null) {
        sh_flash('info', 'Your admin session is no longer active. Sign in through the current admin route and verify again.');
        sh_redirect('admin/login.php');
    }
    if (!sh_admin_is_superadmin()) { sh_admin_access_not_found(); }
    if (!sh_admin_access_record_verification($requestedPath, (int)$admin['id'])) {
        sh_log_line('security', 'Pending admin access route verification failed.');
        sh_render_error_page(503, 'Route verification failed', 'The new admin route could not be verified. Your existing admin route remains active. Please return to Admin Access and try again.');
    }
    sh_admin_access_mark_verified_audit($requestedPath);
    sh_flash('success', 'The proposed admin route is reachable from this authenticated browser. The old route is still active until you explicitly apply the change.');

    if ($state['enabled'] && $state['active_path'] !== '') {
        header('Location: ' . sh_admin_access_route_url($state['active_path'], 'security.php'));
        exit;
    }
    sh_redirect('admin/security.php');
}

$route = $_GET['__admin_route'] ?? 'dashboard.php';
if (!is_string($route)) { sh_admin_access_not_found(); }
$route = sh_admin_access_normalize_target($route);
if ($route === null) { sh_admin_access_not_found(); }

$target = realpath(SH_ROOT . '/admin/' . $route);
$adminRoot = realpath(SH_ROOT . '/admin');
if ($target === false || $adminRoot === false || !str_starts_with($target, $adminRoot . DIRECTORY_SEPARATOR)) {
    sh_admin_access_not_found();
}
require $target;
