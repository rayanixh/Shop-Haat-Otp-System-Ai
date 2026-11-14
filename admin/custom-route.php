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

// This dispatcher does not render admin pages itself: every validated target
// enforces sh_require_admin/sh_require_perm. A pending route only shows a
// verification screen after a same-session ticket, an admin session and the
// superadmin role have been checked; activation needs an explicit CSRF POST.
// The route secret alone never authorizes or changes anything. This context
// merely allows the dispatcher to include a fixed, validated PHP route.
define('SH_ADMIN_ROUTE_CONTEXT', true);

if ($isPending && !$isActive) {
    // Visiting the staged URL NEVER activates or verifies anything. The ticket
    // stays in the session so the explicit POST below can consume it.
    $admin = sh_admin();
    if ($admin === null) {
        sh_flash('info', 'Your admin session is no longer active. Sign in through the current admin route and open the proposed URL again.');
        sh_redirect('admin/login.php');
    }
    if (!sh_admin_is_superadmin()) { sh_admin_access_not_found(); }

    // Only the dispatcher can define this constant. admin/security.php renders
    // a focused verification screen here and handles the CSRF-protected
    // "Verify and activate new Admin URL" POST. Every included target still
    // runs its own sh_require_admin()/sh_require_perm() guards.
    define('SH_ADMIN_ACCESS_VERIFY_SCREEN', true);
    require SH_ROOT . '/admin/security.php';
    exit;
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
