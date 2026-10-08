<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
sh_logout_user();
sh_flash('info', 'You have been signed out.');
sh_redirect('index.php');
