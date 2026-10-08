<?php
/**
 * Forgot password (Email + Password mode only). Reuses the existing mailer
 * (config/mail.php) — no separate email system is created.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/config/mail.php';

sh_session_start();
if (sh_user() !== null) { sh_redirect('account.php'); }
if (sh_auth_mode() !== 'email_password') { sh_redirect('login.php'); }

$error = '';
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $email = trim(sh_post('email'));

    if (sh_login_throttled('forgot_password')) {
        $error = 'Too many requests. Please wait a few minutes and try again.';
    } elseif ($email === '' || !sh_valid_email($email)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            $u = sh_one('SELECT id, email, status FROM users WHERE email = ? LIMIT 1', [$email]);
            if ($u !== null && $u['status'] === 'active') {
                $uid = (int)$u['id'];

                // Rate limit reset requests: max 3 per account per hour.
                $recent = (int)sh_val(
                    'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?',
                    [$uid, date('Y-m-d H:i:s', time() - 3600)], 0);
                if ($recent >= 3) {
                    $error = 'Too many reset requests. Please try again later.';
                } else {
                    $token = bin2hex(random_bytes(32));
                    sh_db()->prepare(
                        'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address)
                         VALUES (?, ?, ?, ?)'
                    )->execute([
                        $uid,
                        hash('sha256', $token),
                        date('Y-m-d H:i:s', time() + 3600),
                        mb_substr(sh_client_ip(), 0, 45),
                    ]);

                    $resetUrl = rtrim(sh_site_url(), '/') . sh_url('reset-password.php?email=' . rawurlencode($u['email']) . '&token=' . $token);
                    $siteName = (string)sh_setting('site_name', 'ShopHaat');
                    $body = "Hello,\n\nWe received a request to reset the password for your $siteName account.\n\n"
                        . "To choose a new password, open the link below (it expires in 1 hour):\n\n$resetUrl\n\n"
                        . "If you did not request this, you can safely ignore this email.\n\n— $siteName";
                    sh_mail_send($u['email'], 'Reset your ' . $siteName . ' password', $body);

                    sh_security_log('password_reset_requested', $uid, ['email' => mb_substr($email, 0, 3) . '***']);
                    sh_login_reset('forgot_password');
                    $sent = true;
                }
            } else {
                // Do not reveal whether the email exists; still consume the attempt.
                sh_login_fail('forgot_password');
                $sent = true;
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'forgot-password');
            $error = 'The password reset could not be processed. Please try again.';
        }
    }
}

$pageTitle = 'Forgot Password';
$pageDescription = 'Reset your account password.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('key', 26) ?></div>
    <h1 class="sh-auth__title">Forgot Password</h1>
    <p class="sh-auth__sub">Enter your email address and we will send you a secure reset link.</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?>
        <span>If an account exists for that email, a password reset link has been sent. Please check your inbox.</span></div>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a></p>
    <?php else: ?>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <div class="sh-field">
          <label class="sh-field__label" for="fp-email">Email Address</label>
          <input class="sh-input" id="fp-email" type="email" name="email" value="<?= e($email) ?>" required
                 autocomplete="email" placeholder="Enter your email">
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('send', 16) ?> Send reset link</button>
      </form>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a></p>
    <?php endif; ?>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
