<?php
/**
 * Admin — Firebase Settings Redirect.
 *
 * Firebase integration and verification controls have been unified into the
 * central Email & Verification Settings dashboard (admin/email.php).
 * This endpoint preserves backwards compatibility and prevents 404s.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
sh_require_admin();

// Seamlessly forward to the authoritative Email & Verification Settings page
sh_redirect('admin/email.php?tab=firebase');
