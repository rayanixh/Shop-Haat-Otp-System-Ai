<?php
/**
 * Forgot password (Email + Password mode only).
 *
 * The store emails a six-digit code, not a link: the code is stored only as a
 * bcrypt hash, expires in 10 minutes, allows five attempts, is single-use and
 * cannot be replayed against another purpose. The response is identical whether
 * or not the address exists, so the form cannot be used to enumerate accounts.
 *
 * The email is only reported as sent when the transport accepted the message. A
 * missing SMTP configuration or a refused message is shown as a configuration /
 * delivery failure — never as "check your inbox".
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/mail.php';

sh_session_start();
if (sh_user() !== null) { sh_redirect('account.php'); }
if (sh_auth_mode() !== 'email_password') { sh_redirect('login.php'); }

sh_email_schema_ensure();
sh_email_challenge_cleanup();

$error = '';
$failure = '';
$email = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $email = trim(sh_post('email'));

    // Session limit plus an audit-table limit by IP: six requests per hour per
    // connection. The per-account and per-address limits live in the challenge
    // layer and cannot be reset by clearing cookies.
    if (sh_login_throttled('forgot_password')
        || sh_auth_action_count('password_reset_requested', 3600) >= 6) {
        $error = 'Too many reset requests. Please wait a while and try again.';
    } elseif ($email === '' || !sh_valid_email($email)) {
        $error = 'Enter a valid email address.';
    } elseif (!sh_email_forgot_enabled()) {
        $error = 'Password recovery by email is switched off on this store. Please contact support.';
    } else {
        $status = sh_mail_service_status();
        if (!(bool)$status['ready']) {
            // Refuse before touching the database: there is no way to deliver a
            // code, and pretending otherwise is exactly what must not happen.
            $failure = 'Password recovery by email is not available yet: ' . (
                $status['missing'] === []
                    ? 'the mail settings are incomplete.'
                    : 'missing ' . implode(', ', array_slice((array)$status['missing'], 0, 3)) . '.'
            ) . ' An administrator has to finish Admin → Email & SMTP.';
            sh_log_line('mail', 'Password reset refused: ' . ($status['missing'] === [] ? 'mail settings incomplete' : implode('; ', (array)$status['missing'])));
        } else {
            try {
                $u = sh_one('SELECT id, email, name, status FROM users WHERE email = ? LIMIT 1', [$email]);
                if ($u !== null && $u['status'] === 'active') {
                    $uid = (int)$u['id'];

                    // Per-account cap on top of the challenge layer's per-address cap.
                    $recent = (int)sh_val(
                        'SELECT COUNT(*) FROM email_challenges WHERE user_id = ? AND purpose = ? AND created_at > ?',
                        [$uid, SH_EMAIL_PURPOSE_RESET, date('Y-m-d H:i:s', time() - 3600)],
                        0
                    );
                    if ($recent >= 5) {
                        $failure = 'Too many reset requests for this account. Please try again later.';
                    } else {
                        $result = sh_email_send_reset_code((string)$u['email'], $uid);
                        if (!empty($result['ok'])) {
                            // The pending reset lives in the session, never in a URL:
                            // the code itself is what proves control of the inbox.
                            $_SESSION['pw_reset'] = [
                                'user_id' => $uid,
                                'email'   => (string)$u['email'],
                                'at'      => time(),
                            ];
                            unset($_SESSION['pw_reset_verified']);
                            sh_security_log('password_reset_requested', $uid, ['email' => sh_email_mask((string)$u['email'])]);
                            sh_login_reset('forgot_password');
                            sh_redirect('reset-password.php');
                        }
                        // Honest failure: the code exists but was never delivered, and
                        // the challenge layer already revoked it.
                        $failure = 'The reset code could not be emailed: ' . (string)($result['error'] ?? 'the mail server refused the message.')
                            . ' No code is pending, so nothing can be guessed — try again once mail delivery works.';
                        sh_security_log('password_reset_send_failed', $uid, [
                            'email' => sh_email_mask((string)$u['email']),
                            'state' => (string)($result['state'] ?? 'failed'),
                        ]);
                    }
                } else {
                    // Do not reveal whether the address exists. The response below is
                    // byte-for-byte the same as a delivered code, and the attempt is
                    // still consumed.
                    sh_login_fail('forgot_password');
                    $sent = true;
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'forgot-password');
                $error = 'The password reset could not be processed. Please try again.';
            }
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
    <p class="sh-auth__sub">Enter your email address and we will send a 6-digit code to choose a new password.</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <?php if ($failure !== ''): ?>
      <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?><span><?= e($failure) ?></span></div>
    <?php endif; ?>

    <?php if ($sent): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?>
        <span>If an account exists for that email, a reset code has been sent. Please check your inbox.</span></div>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a></p>
    <?php else: ?>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <div class="sh-field">
          <label class="sh-field__label" for="fp-email">Email Address</label>
          <input class="sh-input" id="fp-email" type="email" name="email" value="<?= e($email) ?>" required
                 autocomplete="email" placeholder="Enter your email">
          <p class="sh-field__hint">The code expires in <?= (int)sh_email_otp_expiry_minutes() ?> minutes and can be used once.</p>
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('send', 16) ?> Send reset code</button>
      </form>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a></p>
    <?php endif; ?>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
