<?php
/**
 * Continue with Google — step 2 (the Redirect URI registered in Google Cloud).
 * Verifies state, exchanges the code, validates the ID token, then links or
 * creates the customer account and signs them in. Cart is merged by sh_login_user().
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$back = static function (string $msg, string $redirect = ''): void {
    sh_flash('error', $msg);
    sh_redirect('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''));
};

if (!sh_google_enabled()) {
    unset($_SESSION['sh_google_oauth']);
    $back('Google Login is currently disabled.');
}
if (sh_user() !== null) { sh_redirect('account.php'); }

try {
    // Remember where the customer wanted to go (cart / checkout) before the
    // single-use OAuth context is consumed below.
    $redirect = sh_safe_redirect((string)($_SESSION['sh_google_oauth']['redirect'] ?? ''), '');
    $res = sh_google_complete($_GET);
    if (!$res['ok']) { $back($res['error'], $redirect); }

    $acct = sh_google_resolve_user($res['profile']);
    if (!$acct['ok']) { $back($acct['error'], $redirect); }

    $uid = (int)$acct['user_id'];
    $target = sh_safe_redirect($redirect, 'account.php');
    sh_login_user($uid); // regenerates the session id + merges the guest cart
    sh_security_log('login_success', $uid, ['via' => 'google']);
    sh_flash('success', !empty($acct['created'])
        ? 'Your account has been created. Welcome to ' . sh_setting('site_name', 'ShopHaat') . '.'
        : 'Welcome back.');
    sh_redirect($target);
} catch (Throwable $e) {
    sh_log_exception($e, 'google-auth');
    $back('Unable to sign in with Google. Please try again.');
}
