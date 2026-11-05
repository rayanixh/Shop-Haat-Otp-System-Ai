<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
sh_session_start();
$admin = sh_require_admin();

// Logout mutates the session, so it is POST-only and CSRF protected.
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('POST required.');
}
sh_csrf_require();
sh_log_line('security', 'Admin signed out: ' . ($admin['email'] ?? ''));
sh_admin_logout();
sh_redirect('admin/login.php');
