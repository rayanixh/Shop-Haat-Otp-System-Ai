<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
sh_session_start();

// Accept GET so the sidebar link works, but log the actor first.
$a = sh_admin();
if ($a !== null) { sh_log_line('security', 'Admin signed out: ' . ($a['email'] ?? '')); }
sh_admin_logout();
sh_redirect('admin/login.php');
