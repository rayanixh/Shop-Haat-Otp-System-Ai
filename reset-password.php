<?php
/**
 * Password reset (Email + Password mode only).
 *
 * Two ways in, both ending at the same server-side checks:
 *
 *   1. Code mode (current): the customer asked for a reset, so the session holds
 *      the pending account. The emailed six-digit code is verified first — it is
 *      single use, expires, is attempt-limited and is scoped to the reset
 *      purpose — and only then is the new password form shown.
 *   2. Legacy token links: a reset link mailed before this system existed still
 *      works, so an inbox full of old links does not break.
 *
 * On success the password is re-hashed with password_hash(), every outstanding
 * code and token for that account is invalidated, the change is logged and a
 * real notification email is attempted. Nothing here emails a password.
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

$pending = $_SESSION['pw_reset'] ?? null;
$pending = is_array($pending) && (int)($pending['user_id'] ?? 0) > 0 && time() - (int)($pending['at'] ?? 0) <= 3600
    ? $pending : null;
if ($pending === null && isset($_SESSION['pw_reset'])) { unset($_SESSION['pw_reset']); }

$legacyEmail = trim(sh_get('email'));
$legacyToken = trim(sh_get('token'));
$legacyUserId = 0;
$mode = 'none';

$errors = [];
$notice = '';

// ---------------------------------------------------------------------------
// Legacy single-use token link
// ---------------------------------------------------------------------------
if ($pending === null && $legacyEmail !== '' && $legacyToken !== '') {
    $mode = 'token';
    $user = sh_one('SELECT id, status FROM users WHERE email = ? LIMIT 1', [$legacyEmail]);
    if ($user === null || (string)$user['status'] !== 'active') {
        $errors[] = 'This password reset link is invalid.';
    } else {
        $row = sh_one(
            'SELECT id, expires_at, used_at FROM password_resets
             WHERE user_id = ? AND token_hash = ? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [(int)$user['id'], hash('sha256', $legacyToken)]
        );
        if ($row === null) {
            $errors[] = 'This password reset link is invalid or has already been used.';
        } elseif (strtotime((string)$row['expires_at']) < time()) {
            $errors[] = 'This password reset link has expired. Please request a new code.';
        } else {
            $legacyUserId = (int)$user['id'];
        }
    }
} elseif ($pending !== null) {
    $mode = 'code';
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------
$action = (string)sh_post('action');
$codeVerified = !empty($_SESSION['pw_reset_verified']) && $mode === 'code';

// A legacy link carries its own proof (the single-use token); the code flow has
// to be verified first, whatever order the customer's browser posts in.
$canSetPassword = $legacyUserId > 0 || ($mode === 'code' && $codeVerified);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'code' && $action === 'resend') {
    $result = sh_email_send_reset_code((string)$pending['email'], (int)$pending['user_id']);
    sh_flash(
        !empty($result['ok']) ? 'success' : 'error',
        !empty($result['ok'])
            ? 'A new reset code was emailed to ' . sh_email_mask((string)$pending['email']) . '.'
            : 'The reset code could not be emailed: ' . (string)($result['error'] ?? 'the mail server refused the message.')
    );
    sh_redirect('reset-password.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'code' && $action === 'code') {
    $check = sh_email_challenge_verify((string)$pending['email'], SH_EMAIL_PURPOSE_RESET, (string)sh_post('code'));
    if (!empty($check['ok'])) {
        $_SESSION['pw_reset_verified'] = time();
        $codeVerified = true;
        sh_security_log('password_reset_code_verified', (int)$pending['user_id'], ['email' => sh_email_mask((string)$pending['email'])]);
        sh_redirect('reset-password.php');
    }
    $errors[] = (string)($check['error'] ?? 'That code could not be verified.');
    sh_security_log('password_reset_code_failed', (int)$pending['user_id'], [
        'email' => sh_email_mask((string)$pending['email']),
        'state' => (string)($check['state'] ?? 'invalid'),
    ]);
    sh_login_fail('forgot_password');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'password' && $canSetPassword) {
    $new = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password2'] ?? '');
    $v = new ShValidator($_POST);
    $v->required('password', 'Password')->password('password')
      ->matches('password2', 'password', 'Password confirmation');
    if ($v->fails()) {
        $errors = array_merge($errors, array_values($v->errors()));
    } else {
        try {
            $targetId = $legacyUserId > 0 ? $legacyUserId : (int)$pending['user_id'];
            $targetUser = sh_one('SELECT id, name, email FROM users WHERE id = ? LIMIT 1', [$targetId]);
            sh_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $targetId]);

            // Everything that could reset this password again is now dead.
            if ($targetUser !== null) {
                sh_email_challenge_revoke((string)$targetUser['email'], SH_EMAIL_PURPOSE_RESET);
                sh_email_challenge_revoke((string)$targetUser['email'], SH_EMAIL_PURPOSE_CHANGE);
            }
            sh_query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$targetId]);

            // Session rotation for the signed-in device, and a fresh CSRF token.
            sh_forget_customer_session();
            unset($_SESSION['pw_reset'], $_SESSION['pw_reset_verified']);

            sh_security_log('password_reset_completed', $targetId, ['email' => sh_email_mask((string)($targetUser['email'] ?? ''))]);

            // Real notification. If the transport refuses it, the password has
            // already changed — say so honestly instead of claiming delivery.
            $notified = false;
            $notifyError = '';
            if ($targetUser !== null) {
                $notice = sh_email_notify_password_changed($targetUser, 'reset');
                $notified = !empty($notice['ok']);
                $notifyError = (string)($notice['error'] ?? '');
            }
            sh_flash('success', 'Your password has been reset. You can now sign in.');
            if (!$notified) {
                sh_flash('warning', 'The password changed, but the confirmation email could not be sent'
                    . ($notifyError !== '' ? ' (' . $notifyError . ')' : '')
                    . '. An administrator can re-send it from the email delivery log.');
            }
            sh_redirect('login.php');
        } catch (Throwable $e) {
            sh_log_exception($e, 'reset-password');
            $errors[] = 'Your password could not be updated. Please try again.';
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

    <?php foreach ($errors as $er): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($er) ?></span></div>
    <?php endforeach; ?>

    <?php if ($mode === 'none'): ?>
      <p class="sh-auth__sub">There is no password reset in progress for this browser, or it has expired.</p>
      <p class="sh-auth__foot"><a href="<?= e(sh_url('forgot-password.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Request a reset code</a></p>
    <?php elseif ($mode === 'code' && !$codeVerified): ?>
      <p class="sh-auth__sub">
        Enter the 6-digit code we emailed to <strong><?= e(sh_email_mask((string)$pending['email'])) ?></strong>.
      </p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="action" value="code">
        <div class="sh-field">
          <label class="sh-field__label" for="rp-code">Reset code</label>
          <input class="sh-input" id="rp-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9]*" maxlength="<?= (int)sh_email_otp_code_length() ?>" required
                 style="letter-spacing:6px;font-size:20px;text-align:center" placeholder="<?= str_repeat('0', (int)sh_email_otp_code_length()) ?>">
          <p class="sh-field__hint">The code expires <?= (int)sh_email_otp_expiry_minutes() ?> minutes after it was sent.</p>
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('check-circle', 16) ?> Verify code</button>
      </form>
      <form method="post" novalidate style="margin-top:10px">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        <button class="sh-btn sh-btn--ghost sh-btn--block" type="submit"><?= sh_icon('send', 15) ?> Send a new code</button>
      </form>
    <?php else: ?>
      <p class="sh-auth__sub">Choose a new password<?= $mode === 'code' ? ' for ' . e(sh_email_mask((string)$pending['email'])) : '' ?>.</p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="action" value="password">
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
