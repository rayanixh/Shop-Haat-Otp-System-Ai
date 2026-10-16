<?php
/**
 * Email verification.
 *
 * This page is the target of both verification mechanisms and had been missing
 * entirely: includes/firebase.php has always pointed Firebase's verification
 * email at /verify-email.php, so customers who clicked that link landed on a
 * 404 and their address was never marked verified.
 *
 * It now serves:
 *   1. an incoming Firebase link (?mode=verifyEmail&oobCode=…) — the code is
 *      validated server-side against Firebase, never trusted from the browser;
 *   2. the store's own SMTP verification code — six digits, emailed by
 *      includes/mail.php, stored only as a bcrypt hash, 10-minute expiry,
 *      attempt limit, resend cooldown, single use;
 *   3. the fresh-signup and login-gate states.
 *
 * Nothing here ever claims an email was sent when the transport refused it, and
 * the account is only marked verified after a code really validated.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/mail.php';

sh_email_schema_ensure();
sh_email_challenge_cleanup();
sh_session_start();

/** Local render helper — keeps every status message in one place. */
function sh_verify_alert(string $kind, string $message): string
{
    $icon = $kind === 'success' ? 'check-circle' : ($kind === 'error' ? 'x-circle' : 'alert');
    $class = $kind === 'success' ? 'sh-alert--success' : ($kind === 'error' ? 'sh-alert--error' : 'sh-alert--warning');
    return '<div class="sh-alert ' . $class . '">' . sh_icon($icon, 16) . '<span>' . e($message) . '</span></div>';
}

$user = sh_user();
$pending = sh_email_verify_pending();

// ---------------------------------------------------------------------------
// 1. A verification link that points straight at this site
// ---------------------------------------------------------------------------
$oob = trim((string)($_GET['oobCode'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));
if ($oob !== '') {
    if (!function_exists('sh_fb_apply_oob_code')) {
        require_once SH_ROOT . '/includes/firebase.php';
    }
    $applied = sh_fb_apply_oob_code($oob);
    if (!empty($applied['ok'])) {
        sh_flash('success', 'Your email address is verified. Thank you.');
        sh_redirect($pending !== null ? (string)($pending['return'] ?? 'account.php') : 'account.php');
    }
    sh_flash('error', 'That verification link could not be used: ' . (string)($applied['error'] ?? 'the code is invalid or has expired.'));
    sh_redirect('verify-email.php');
}

// ---------------------------------------------------------------------------
// 2. Work out which address is being verified
// ---------------------------------------------------------------------------
$email = '';
$uid = 0;
$context = 'account';
$returnTo = 'account.php';
$verified = false;

if ($user !== null) {
    $email = trim((string)($user['email'] ?? ''));
    $uid = (int)$user['id'];
    $verified = !empty($user['email_verified']);
    $context = 'account';
} elseif ($pending !== null) {
    $email = trim((string)($pending['email'] ?? ''));
    $uid = (int)($pending['user_id'] ?? 0);
    $context = (string)($pending['context'] ?? 'login');
    $returnTo = (string)($pending['return'] ?? 'account.php');
    $row = sh_one('SELECT id, email, email_verified FROM users WHERE id = ? LIMIT 1', [$uid]);
    $verified = $row !== null && !empty($row['email_verified']);
    if ($row !== null) { $verified = !empty($row['email_verified']); }
}

$verifier = sh_email_verifier();
$canSend = sh_email_verify_enabled() && $email !== '' && sh_valid_email($email) && !$verified;
$errors = [];
$notice = '';
$noticeKind = 'info';
$sent = false;

// ---------------------------------------------------------------------------
// 3. POST: send a new code, or verify the submitted one
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $action = (string)sh_post('action');

    if ($email === '' || !sh_valid_email($email)) {
        $errors[] = 'There is no email address to verify. Please sign in or register again.';
    } elseif ($verified) {
        $notice = 'This email address is already verified.';
        $noticeKind = 'success';
    } elseif ($verifier === 'firebase' && !sh_email_verify_enabled() && $action === 'send') {
        // No SMTP configured, but Firebase is: use the provider that is set up
        // rather than pretending this store can send its own mail.
        $target = sh_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
        $sentFirebase = $target !== null ? sh_fb_send_verification($target) : ['ok' => false, 'error' => 'Account not found.'];
        if (!empty($sentFirebase['ok'])) {
            $notice = 'A verification email was sent to ' . sh_email_mask($email) . '.';
            $noticeKind = 'success';
            $sent = true;
        } else {
            $errors[] = 'The verification email was not sent: ' . (string)($sentFirebase['error'] ?? 'the provider refused the request.');
        }
    } elseif ($action === 'send') {
        $result = sh_email_send_verification_code($email, $uid ?: null);
        if (!empty($result['ok'])) {
            $notice = 'A 6-digit code was sent to ' . sh_email_mask($email) . '. It expires in ' . sh_email_otp_expiry_minutes() . ' minutes.';
            $noticeKind = 'success';
            $sent = true;
            if (function_exists('sh_security_log')) { sh_security_log('email_verification_sent', $uid, ['email' => sh_email_mask($email)]); }
        } else {
            // The transport refused it. Say so, and say why in plain words.
            $errors[] = 'The code could not be emailed: ' . (string)($result['error'] ?? 'the mail server refused the message.')
                . ' No code is pending, so nothing will accept a guess — try again once the mail settings are corrected.';
            if (function_exists('sh_security_log')) { sh_security_log('email_verification_send_failed', $uid, ['email' => sh_email_mask($email), 'state' => (string)($result['state'] ?? 'failed')]); }
        }
    } elseif ($action === 'verify') {
        $code = (string)sh_post('code');
        $check = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_SIGNUP, $code, $uid ?: null);
        if (!empty($check['ok'])) {
            if ($uid <= 0) {
                $row = sh_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
                $uid = (int)($row['id'] ?? 0);
            }
            if ($uid > 0) {
                sh_email_mark_verified($uid, 'smtp_otp');
                sh_email_verify_pending_clear();
                if ($context === 'login') {
                    // The password was already checked before the gate paused the
                    // sign-in; completing the code finishes that same sign-in.
                    sh_login_user($uid);
                }
                sh_flash('success', 'Your email address is verified. Thank you.');
                sh_redirect($returnTo !== '' ? $returnTo : 'account.php');
            }
            $errors[] = 'The code was correct but the account could not be updated. Please contact support.';
        } else {
            $errors[] = (string)($check['error'] ?? 'That code could not be verified.');
            if (function_exists('sh_security_log')) {
                sh_security_log('email_verification_failed', $uid, ['email' => sh_email_mask($email), 'state' => (string)($check['state'] ?? 'invalid')]);
            }
        }
    }
}

$pageTitle = 'Verify Email';
$pageDescription = 'Confirm your email address.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <div class="sh-auth__mark"><?= sh_icon('mail', 26) ?></div>
    <h1 class="sh-auth__title">Verify your email</h1>

    <?php if ($email === ''): ?>
      <p class="sh-auth__sub">There is no verification in progress for this browser. Sign in to verify the email address on your account.</p>
      <?php if ($user === null): ?>
        <p class="sh-auth__foot"><a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a></p>
      <?php endif; ?>
    <?php elseif ($verified): ?>
      <p class="sh-auth__sub"><?= e(sh_email_mask($email)) ?> is already verified on this account.</p>
      <?= sh_verify_alert('success', 'Nothing further is needed.') ?>
      <p class="sh-auth__foot"><a href="<?= e(sh_url($returnTo)) ?>"><?= sh_icon('check-circle', 14) ?> Continue</a></p>
    <?php else: ?>
      <p class="sh-auth__sub">
        We sent a <?= (int)sh_email_otp_code_length() ?>-digit code to <strong><?= e(sh_email_mask($email)) ?></strong>.
        Enter it below to confirm the address.
      </p>

      <?php foreach ($errors as $er): ?><?= sh_verify_alert('error', $er) ?><?php endforeach; ?>
      <?php if ($notice !== ''): ?><?= sh_verify_alert($noticeKind, $notice) ?><?php endif; ?>

      <?php if ($verifier === 'none'): ?>
        <?= sh_verify_alert('warning', 'This store cannot send email yet, so verification codes cannot be delivered. An administrator has to configure SMTP in Admin → Email & SMTP. Until then the address stays unverified — no verification is faked.') ?>
      <?php elseif ($verifier === 'firebase' && !sh_email_verify_enabled()): ?>
        <?= sh_verify_alert('warning', 'This store verifies addresses through Firebase. Use the button below to have Firebase send the verification email.') ?>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="sh-field">
          <label class="sh-field__label" for="ve-code">Verification code</label>
          <input class="sh-input" id="ve-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9]*" maxlength="<?= (int)sh_email_otp_code_length() ?>" required
                 style="letter-spacing:6px;font-size:20px;text-align:center" placeholder="<?= str_repeat('0', (int)sh_email_otp_code_length()) ?>">
          <p class="sh-field__hint">The code expires <?= (int)sh_email_otp_expiry_minutes() ?> minutes after it is sent.</p>
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('check-circle', 16) ?> Verify email</button>
      </form>

      <form method="post" novalidate style="margin-top:10px">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <button class="sh-btn sh-btn--ghost sh-btn--block" type="submit"<?= $canSend || $verifier === 'firebase' ? '' : ' disabled' ?>>
          <?= sh_icon('send', 15) ?> Send a new code
        </button>
        <p class="sh-field__hint" style="text-align:center;margin-top:8px">
          One code every <?= (int)sh_email_otp_resend_cooldown() ?> seconds.
        </p>
      </form>

      <p class="sh-auth__foot">
        <?php if ($user === null): ?>
          <a href="<?= e(sh_url('login.php')) ?>"><?= sh_icon('chevron-left', 14) ?> Back to login</a>
        <?php else: ?>
          <a href="<?= e(sh_url('account.php')) ?>"><?= sh_icon('user', 14) ?> Go to your account</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
