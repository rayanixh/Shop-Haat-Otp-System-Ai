<?php
/**
 * Server-rendered OTP entry — the no-JavaScript fallback for signup/login
 * verification. The primary flow is the in-page modal on login.php/register.php;
 * this page mirrors it as a plain form so authentication still works without JS.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();

if (!sh_otp_enabled()) {
    sh_redirect('index.php');
}

$purpose = sh_get('purpose');
if (!in_array($purpose, ['signup', 'login'], true)) {
    sh_redirect('index.php');
}

$back = $purpose === 'signup' ? 'register.php' : 'login.php';
$phone = '';
$userId = null;
if ($purpose === 'signup') {
    $pend = sh_pending_signup();
    if ($pend === null) { sh_redirect('register.php'); }
    $phone = $pend['phone'];
} else {
    $pend = sh_pending_login();
    if ($pend === null) { sh_redirect('login.php'); }
    $phone = $pend['phone'];
    $userId = (int)$pend['user_id'];
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('action') === 'verify') {
        $code = trim((string)($_POST['code'] ?? ''));
        $res = sh_otp_verify($phone, $purpose, $code, $userId);
        if (!$res['ok']) {
            $error = $res['error'] ?? 'Incorrect code.';
        } else {
            $done = sh_otp_complete($purpose, $phone);
            if (!$done['ok']) {
                $error = $done['error'] ?? 'Verification could not be completed.';
            } else {
                sh_flash('success', $purpose === 'signup' ? 'Your account has been created. Welcome!' : 'Welcome back.');
                sh_redirect($done['redirect'] ?? 'account.php');
            }
        }
    } elseif (sh_post('action') === 'send') {
        $issue = sh_otp_issue($phone, $purpose, $userId);
        if ($issue['ok']) {
            $notice = 'A new code has been sent to your phone.';
        } else {
            $error = $issue['error'] ?? 'We could not send a verification code. Please try again.';
        }
    }
}

$pageTitle = 'Verify Your Phone';
$pageDescription = 'Enter the verification code sent to your phone.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('shield', 26) ?></div>
    <h1 class="sh-auth__title">Verify Your Phone</h1>
    <p class="sh-auth__sub">Enter the code we sent to <strong><?= e(sh_phone_mask_login($phone)) ?></strong>.</p>

    <?php if ($error): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?><span><?= e($notice) ?></span></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <div class="sh-field">
        <label class="sh-field__label" for="otp-code">Verification code</label>
        <input class="sh-input sh-otp__input" id="otp-code" type="text" inputmode="numeric" autocomplete="one-time-code"
               maxlength="<?= (int)sh_otp_length() ?>" placeholder="<?= str_repeat('•', (int)sh_otp_length()) ?>"
               pattern="\d*" autofocus>
        <p class="sh-field__hint">Code expires after <?= (int)ceil(sh_otp_expiry_seconds() / 60) ?> minutes.</p>
      </div>
      <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('check-circle', 16) ?> Verify code</button>
    </form>

    <form method="post" novalidate style="margin-top:10px">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="action" value="send">
      <button class="sh-btn sh-btn--lg sh-btn--block sh-btn--ghost" type="submit"><?= sh_icon('send', 16) ?> Resend code</button>
    </form>

    <p class="sh-auth__foot"><a href="<?= e(sh_url($back)) ?>"><?= sh_icon('chevron-left', 14) ?> Go back</a></p>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
