<?php
/**
 * Continue with Google — step 1. Builds a signed OAuth request and sends the
 * customer to Google. Refuses to start when the admin has the feature OFF.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_safe_redirect(sh_get('redirect'), '');

if (sh_user() !== null) { sh_redirect($redirect !== '' ? $redirect : 'account.php'); }

if (!sh_google_enabled()) {
    sh_flash('error', 'Google Login is currently disabled.');
    sh_redirect('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''));
}
if (!sh_google_is_https() && !sh_google_is_localhost()) {
    sh_log_line('google-auth', 'Refused to start OAuth over plain HTTP.');
    sh_flash('error', 'Unable to sign in with Google. Please try again.');
    sh_redirect('login.php');
}

header('Cache-Control: no-store');
header('Location: ' . sh_google_begin($redirect));
exit;
