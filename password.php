<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$user = sh_require_login();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            sh_flash('success', 'Your password has been changed.');
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
        <form method="post" novalidate>
          <?= sh_csrf_field() ?>
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
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
