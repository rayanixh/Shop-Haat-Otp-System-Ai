<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
sh_session_start();
sh_redirect(sh_admin() !== null ? 'admin/dashboard.php' : 'admin/login.php');
