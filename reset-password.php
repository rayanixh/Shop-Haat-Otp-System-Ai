<?php
/**
 * Password reset (Email + Password mode only). Validates a single-use token
 * and lets the customer choose a new password.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
if (sh_user() !== null) { sh_redirect('account.php'); }
if (sh_auth_mode() !== 'email_password') { sh_redirect('login.php'); }

$email = trim(sh_get('email'));
$token = trim(sh_get('token'));
$error = '';
$valid = false;
$userId = 0;

if ($email === '' || $token === '') {
    $error = 'This password reset link is invalid.';
} else {
    $user = sh_one('SELECT id, status FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($user === null) {
        $error = 'This password reset link is invalid.';
    } else {
        $row = sh_one(
            'SELECT id, expires_at, used_at FROM password_resets
             WHERE user_id = ? AND token_hash = ? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [(int)$user['id'], hash('sha256', $token)]
        );
        if ($row === null) {
            $error = 'This password reset link is invalid or has already been used.';
        } elseif (strtotime($row['expires_at']) < time()) {
            $error = 'This password reset link has expired. Please request a new one.';
        } else {
            $valid = true;
            $userId = (int)$user['id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    sh_csrf_require();
    $new = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password2'] ?? '');
    $v = new ShValidator($_POST);
    $v->required('password', 'Password')->password('password')
      ->matches('password2', 'password', 'Password confirmation');
    if ($v->fails()) {
        $errors = $v->errors();
    } else {
        try {
            sh_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            sh_db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND token_hash = ?')
                ->execute([$userId, hash('sha256', $token)]);
            sh_security_log('password_reset_completed', $userId, ['email' => mb_substr($email, 0, 3) . '***']);
            sh_flash('success', 'Your password has been reset. You can now sign in.');
            sh_redirect('login.php');
        } catch (Throwable $e) {
            sh_log_exception($e, 'reset-password');
            $errors = ['general' => 'Your password could not be updated. Please try again.'];
        }
    }
}

$pageTitle = 'Reset Password';
$pageDescription = 'Choose a new password for your account.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('lock', 26) ?></div>
    <h1 class="sh-auth__title">Reset Password</h1>

    <?php if (!$valid): ?>
      <p class="sh-auth__sub"><?= e($error) ?></p>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('forgot-password.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Request a new link</a></p>
    <?php else: ?>
      <p class="sh-auth__sub">Choose a new password for <?= e($email) ?>.</p>

      <?php if (!empty($errors)): ?>
        <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
          <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <div class="sh-field">
          <label class="sh-field__label" for="rp-pass">New Password</label>
          <input class="sh-input" id="rp-pass" type="password" name="password" required autocomplete="new-password">
          <p class="sh-field__hint">At least 8 characters, including a letter and a number.</p>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="rp-pass2">Confirm Password</label>
          <input class="sh-input" id="rp-pass2" type="password" name="password2" required autocomplete="new-password">
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('check-circle', 16) ?> Save new password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
