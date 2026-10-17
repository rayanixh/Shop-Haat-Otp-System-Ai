<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');
sh_redirect('admin/email.php');
