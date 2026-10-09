<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
if (sh_admin() !== null) { sh_redirect('admin/dashboard.php'); }

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $email = sh_post('email');
    $password = (string)($_POST['password'] ?? '');

    if (sh_login_throttled('admin_login', 5, 900)) {
        $error = 'Too many failed sign-in attempts. Please wait 15 minutes before trying again.';
        sh_log_line('security', 'Admin login throttled for ' . $email);
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } else {
        try {
            $a = sh_one('SELECT id, password_hash, status FROM admins WHERE email = ? LIMIT 1', [$email]);
            $hash = $a['password_hash'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000';
            if ($a !== null && password_verify($password, $hash) && $a['status'] === 'active') {
                sh_login_reset('admin_login');
                sh_admin_login((int)$a['id']);
                sh_log_line('security', 'Admin signed in: ' . $email);
                $r = sh_post('redirect', sh_get('redirect'));
                sh_redirect($r !== '' && strpos($r, '//') === false ? ltrim($r, '/') : 'admin/dashboard.php');
            }
            sh_login_fail('admin_login');
            sh_log_line('security', 'Failed admin login for ' . $email);
            $error = 'Invalid email address or password.';
        } catch (Throwable $e) {
            sh_log_exception($e, 'admin-login');
            $error = 'Sign in is temporarily unavailable. Please try again shortly.';
        }
    }
}
$token = sh_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin Sign In — <?= e(sh_setting('site_name', 'ShopHaat')) ?></title>
<link rel="stylesheet" href="<?= e(sh_asset('assets/css/app.css')) ?>">
<style>
.sh-adminlogin { min-height: 100vh; display: grid; place-items: center; background: #151b2b; padding: 20px; }
.sh-adminlogin__box { background: #fff; border-radius: 11px; width: min(410px, 100%); padding: 30px; box-shadow: 0 12px 40px rgba(0,0,0,.35); }
.sh-adminlogin__brand { display: flex; align-items: center; gap: 10px; justify-content: center; font-weight: 800; font-size: 19px; margin-bottom: 5px; }
.sh-adminlogin__sub { text-align: center; font-size: 13px; color: var(--sh-muted); margin-bottom: 20px; }
</style>
</head>
<body>
<div class="sh-adminlogin">
  <div class="sh-adminlogin__box">
    <div class="sh-adminlogin__brand"><span class="sh-brand__mark">SH</span> Admin Panel</div>
    <p class="sh-adminlogin__sub"><?= e(sh_setting('site_name', 'ShopHaat')) ?> store management</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <input type="hidden" name="redirect" value="<?= e(sh_get('redirect')) ?>">
      <div class="sh-field">
        <label class="sh-field__label" for="ad-email">Email address</label>
        <input class="sh-input" id="ad-email" type="email" name="email" value="<?= e($email) ?>" required autocomplete="username">
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="ad-pass">Password</label>
        <input class="sh-input" id="ad-pass" type="password" name="password" required autocomplete="current-password">
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('lock', 16) ?> Sign in</button>
    </form>
    <p style="text-align:center;margin-top:16px;font-size:12.5px;color:var(--sh-muted)">
      <a href="<?= e(sh_url('index.php')) ?>">Back to store</a>
    </p>
  </div>
</div>
</body>
</html>
