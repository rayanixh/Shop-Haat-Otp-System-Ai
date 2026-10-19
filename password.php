<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/mail.php';

sh_session_start();
$user = sh_require_login();
sh_email_schema_ensure();
$errors = [];
$notices = [];

// Optional stronger rule: confirm the change with a code emailed to the account
// before the new password is accepted. Off by default (Admin → Email), because
// a store whose owner has not configured SMTP must not be able to lock itself
// out of password changes.
$changeGate = sh_setting('email_otp_on_password_change', '0') === '1'
    && sh_email_can_send()
    && (string)($user['email'] ?? '') !== ''
    && !(function_exists('sh_is_synthetic_email') && sh_is_synthetic_email((string)$user['email']));
$changeVerified = !$changeGate || ((int)($_SESSION['pw_change_verified_at'] ?? 0) > time() - 600);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'send_change_code') {
    sh_csrf_require();
    $send = sh_email_send_change_code((string)$user['email'], (int)$user['id']);
    sh_flash(
        !empty($send['ok']) ? 'success' : 'error',
        !empty($send['ok'])
            ? 'A confirmation code was emailed to ' . sh_email_mask((string)$user['email']) . '.'
            : 'The confirmation code could not be emailed: ' . (string)($send['error'] ?? 'the mail server refused the message.')
    );
    sh_redirect('password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'confirm_change_code') {
    sh_csrf_require();
    $check = sh_email_challenge_verify((string)$user['email'], SH_EMAIL_PURPOSE_CHANGE, (string)sh_post('code'), (int)$user['id']);
    if (!empty($check['ok'])) {
        $_SESSION['pw_change_verified_at'] = time();
        sh_flash('success', 'Code confirmed. Choose your new password below.');
    } else {
        $errors[] = (string)($check['error'] ?? 'That code could not be verified.');
    }
    sh_redirect('password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'change_password' && !$changeVerified) {
    $errors[] = 'Confirm the code we emailed you before changing the password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'change_password' && $changeVerified) {
    sh_csrf_require();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $row = sh_one('SELECT password_hash FROM users WHERE id = ?', [(int)$user['id']]);
    if ($row === null || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (($p = sh_password_problem($new)) !== null) { $errors[] = $p; }
    if ($new !== $confirm) { $errors[] = 'The new passwords do not match.'; }
    if (!$errors) {
        try {
            sh_update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int)$user['id']]);
            // Consume the change-confirmation code and rotate the session so the
            // change cannot be replayed with an old cookie.
            if ($changeGate) {
                sh_email_challenge_revoke((string)$user['email'], SH_EMAIL_PURPOSE_CHANGE);
                unset($_SESSION['pw_change_verified_at']);
            }
            sh_security_log('password_changed', (int)$user['id'], ['email' => sh_email_mask((string)$user['email'])]);

            // Real notification: attempted, and its true result reported. The
            // password is already changed at this point, so a delivery failure is
            // stated plainly and can be re-sent from the admin delivery log.
            $notify = sh_email_notify_password_changed($user, 'account');
            sh_flash('success', 'Your password has been changed.');
            if (empty($notify['ok'])) {
                sh_flash('warning', 'The change was saved, but the security notification email could not be sent'
                    . (!empty($notify['error']) ? ' (' . (string)$notify['error'] . ')' : '')
                    . '. An administrator can re-send it from the email delivery log.');
            }
            sh_redirect('password.php');
        } catch (Throwable $e) {
            sh_log_exception($e, 'password-change');
            $errors[] = 'The password could not be updated.';
        }
    }
}

$accountPage = 'password';
$pageTitle = 'Change Password';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Change Password</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-section" style="max-width:520px">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('lock', 18) ?> Change Password</h1></div>
        <?php if ($errors): ?>
          <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
            <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <?php if (!$changeVerified): ?>
          <p class="sh-auth__sub">For your security, confirm the <?= (int)sh_email_otp_code_length() ?>-digit code we emailed to
            <?= e(sh_email_mask((string)$user['email'])) ?> before choosing a new password.</p>
          <form method="post" novalidate style="margin-bottom:12px">
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="confirm_change_code">
            <div class="sh-field">
              <label class="sh-field__label" for="pw-code">Confirmation code</label>
              <input class="sh-input" id="pw-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                     pattern="[0-9]*" maxlength="<?= (int)sh_email_otp_code_length() ?>" required
                     style="letter-spacing:6px;font-size:20px;text-align:center">
            </div>
            <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Confirm code</button>
          </form>
          <form method="post" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="send_change_code">
            <button class="sh-btn sh-btn--ghost sh-btn--sm" type="submit"><?= sh_icon('send', 14) ?> Send a new code</button>
          </form>
        <?php else: ?>
        <form method="post" novalidate>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="change_password">
          <?php if ($changeGate): ?>
            <p class="sh-field__hint">Code confirmed. Your change is authorised for the next 10 minutes.</p>
          <?php endif; ?>
          <div class="sh-field">
            <label class="sh-field__label" for="pw-cur">Current password</label>
            <input class="sh-input" id="pw-cur" type="password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="pw-new">New password</label>
            <input class="sh-input" id="pw-new" type="password" name="new_password" required autocomplete="new-password">
            <p class="sh-field__hint">At least 8 characters, including a letter and a number.</p>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="pw-conf">Confirm new password</label>
            <input class="sh-input" id="pw-conf" type="password" name="confirm_password" required autocomplete="new-password">
          </div>
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Update password</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
