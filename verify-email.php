<?php
/**
 * Email verification.
 *
 * Serves:
 *   1. An incoming provider link (?mode=verifyEmail&oobCode=…) — validated
 *      server-side against Firebase, never trusted blindly from the browser.
 *   2. The store's own SMTP verification code — six digits, emailed by
 *      includes/mail.php, stored only as a bcrypt hash, expiry,
 *      attempt limit, resend cooldown, single use.
 *   3. Fresh-signup, login-gate and protected customer route verification states.
 *
 * The two delivery methods (Firebase verification link vs Custom SMTP OTP) are
 * strictly separated: the UI and backend logic match the active provider.
 * The account is only marked verified after a code or link really validates.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/mail.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_email_schema_ensure();
sh_email_challenge_cleanup();
sh_session_start();

/**
 * Calculate remaining server-enforced cooldown in seconds for this user/email.
 */
function sh_verify_cooldown_remaining(string $email, string $verifier, ?array $user): int
{
    if ($email === '') { return 0; }
    if ($verifier === 'firebase') {
        $last = (string)($user['email_verify_sent_at'] ?? '');
        if ($last !== '') {
            $wait = 90 - (time() - (int)strtotime($last));
            return max(0, $wait);
        }
        return 0;
    }
    try {
        $last = sh_one(
            'SELECT last_sent_at FROM email_challenges
             WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
            [mb_strtolower(trim($email)), SH_EMAIL_PURPOSE_SIGNUP]
        );
        if ($last && !empty($last['last_sent_at'])) {
            $cd = function_exists('sh_email_otp_resend_cooldown') ? sh_email_otp_resend_cooldown() : 60;
            $wait = $cd - (time() - (int)strtotime((string)$last['last_sent_at']));
            return max(0, $wait);
        }
    } catch (Throwable $e) {}
    return 0;
}

// ---------------------------------------------------------------------------
// 1. Firebase Action Link callback (?mode=verifyEmail&oobCode=…)
// ---------------------------------------------------------------------------
$oob = trim((string)($_GET['oobCode'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));
if ($oob !== '') {
    if (!function_exists('sh_fb_apply_oob_code')) {
        require_once SH_ROOT . '/includes/firebase.php';
    }
    $applied = sh_fb_apply_oob_code($oob);
    if (!empty($applied['ok'])) {
        $verifiedUid = (int)($applied['user_id'] ?? 0);
        $verifiedEmail = (string)($applied['email'] ?? '');
        if ($verifiedUid > 0) {
            sh_email_mark_verified($verifiedUid, 'firebase_link');
        }
        sh_email_verify_pending_clear();
        $_SESSION['sh_email_just_verified'] = [
            'uid'   => $verifiedUid,
            'email' => $verifiedEmail,
            'at'    => time(),
        ];
        sh_redirect('verify-email.php?verified=1');
    }
    $errorMsg = (string)($applied['error'] ?? 'The verification link is invalid or has expired.');
    sh_flash('error', $errorMsg);
    sh_redirect('verify-email.php');
}

// ---------------------------------------------------------------------------
// 2. Authoritative account & verification state
// ---------------------------------------------------------------------------
$user = sh_user();
$pending = sh_email_verify_pending();

$email = '';
$uid = 0;
$context = 'account';
$returnTo = 'account.php';
$verified = false;
$dbUser = null;

if ($user !== null) {
    $uid = (int)$user['id'];
    $dbUser = sh_one('SELECT id, name, email, email_verified, email_verify_sent_at, email_verify_checked_at FROM users WHERE id = ? LIMIT 1', [$uid]);
    if ($dbUser !== null) {
        $email = trim((string)$dbUser['email']);
        $verified = !empty($dbUser['email_verified']);
    } else {
        $email = trim((string)$user['email']);
        $verified = !empty($user['email_verified']);
    }
    $context = 'account';
    if ($pending !== null && (int)($pending['user_id'] ?? 0) === $uid) {
        $returnTo = (string)($pending['return'] ?? 'account.php');
        $context = (string)($pending['context'] ?? 'account');
    }
} elseif ($pending !== null) {
    $uid = (int)($pending['user_id'] ?? 0);
    $email = trim((string)($pending['email'] ?? ''));
    $returnTo = (string)($pending['return'] ?? 'account.php');
    $context = (string)($pending['context'] ?? 'login');
    if ($uid > 0) {
        $dbUser = sh_one('SELECT id, name, email, email_verified, email_verify_sent_at, email_verify_checked_at FROM users WHERE id = ? LIMIT 1', [$uid]);
        if ($dbUser !== null) {
            $email = trim((string)$dbUser['email']);
            $verified = !empty($dbUser['email_verified']);
        }
    }
}

// Check session flag for recently verified address
$justVerified = false;
if (isset($_GET['verified'])) {
    if (!empty($_SESSION['sh_email_just_verified'])) {
        $justData = $_SESSION['sh_email_just_verified'];
        unset($_SESSION['sh_email_just_verified']);
        if ($email === '') {
            $email = (string)($justData['email'] ?? '');
        }
        $verified = true;
        $justVerified = true;
    } elseif ($verified) {
        $justVerified = true;
    }
}

// Active verification delivery method
$verifier = sh_email_verifier();

// If Firebase is configured and user is unverified, check for external verification sync
if (!$verified && $verifier === 'firebase' && $dbUser !== null && function_exists('sh_fb_enabled') && sh_fb_enabled() && sh_fb_applies($dbUser)) {
    if (isset($_GET['sync']) || isset($_GET['verify']) || empty($dbUser['email_verify_checked_at']) || time() - (int)strtotime((string)$dbUser['email_verify_checked_at']) > 300) {
        $sync = sh_fb_sync_verified($dbUser);
        if (!empty($sync['verified'])) {
            $verified = true;
            $justVerified = true;
            sh_email_verify_pending_clear();
            if (isset($_GET['sync']) || isset($_GET['verify'])) {
                sh_flash('success', 'Your email address is now verified.');
                sh_redirect('verify-email.php?verified=1');
            }
        } elseif (isset($_GET['sync']) || isset($_GET['verify'])) {
            sh_flash('info', 'Your email is not verified yet. Open the link in the verification email first.');
            sh_redirect('verify-email.php');
        }
    }
}

$canSend = sh_email_verify_enabled() && $email !== '' && sh_valid_email($email) && !$verified;
$errors = [];
$notice = '';
$noticeKind = 'info';

// ---------------------------------------------------------------------------
// 3. Pull existing flashes before header to prevent duplicate top banners
// ---------------------------------------------------------------------------
$pulledFlashes = sh_flash_pull();
foreach ($pulledFlashes as $f) {
    if ($f['type'] === 'error') {
        $errors[] = $f['message'];
    } elseif ($f['type'] === 'warning') {
        $notice = $f['message'];
        $noticeKind = 'info';
    } elseif ($f['type'] === 'success') {
        if (str_contains(strtolower($f['message']), 'verified')) {
            $justVerified = true;
        } else {
            $notice = $f['message'];
            $noticeKind = 'success';
        }
    } elseif ($f['type'] === 'info') {
        $notice = $f['message'];
        $noticeKind = 'info';
    }
}

// ---------------------------------------------------------------------------
// 4. POST: verify code or resend code/link
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $action = (string)sh_post('action');

    if ($email === '' || !sh_valid_email($email)) {
        $errors[] = 'There is no email address to verify. Please sign in or register again.';
    } elseif ($verified) {
        $justVerified = true;
    } elseif ($action === 'verify') {
        if ($verifier !== 'smtp_otp') {
            $errors[] = 'Code verification is not supported for this verification method.';
        } else {
            $code = trim((string)sh_post('code'));
            if ($code === '') {
                $errors[] = 'Please enter your 6-digit verification code.';
            } else {
                $check = sh_email_challenge_verify($email, SH_EMAIL_PURPOSE_SIGNUP, $code, $uid ?: null);
                if (!empty($check['ok'])) {
                    if ($uid <= 0) {
                        $row = sh_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
                        $uid = (int)($row['id'] ?? 0);
                    }
                    if ($uid > 0) {
                        sh_email_mark_verified($uid, 'smtp_otp');
                        sh_email_verify_pending_clear();
                        if ($context === 'login' || $user === null) {
                            sh_login_user($uid);
                        }
                        $verified = true;
                        $justVerified = true;
                        $_SESSION['sh_email_just_verified'] = [
                            'uid'   => $uid,
                            'email' => $email,
                            'at'    => time(),
                        ];
                        sh_redirect('verify-email.php?verified=1');
                    } else {
                        $errors[] = 'The code was correct but the account could not be found. Please sign in again.';
                    }
                } else {
                    $errors[] = (string)($check['error'] ?? 'The verification code is invalid or has expired.');
                    if (function_exists('sh_security_log')) {
                        sh_security_log('email_verification_failed', $uid, ['email' => sh_email_mask($email), 'state' => (string)($check['state'] ?? 'invalid')]);
                    }
                }
            }
        }
    } elseif ($action === 'send') {
        if ($verifier === 'firebase') {
            $target = $dbUser ?? ($uid > 0 ? sh_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]) : null);
            if ($target === null && $email !== '') {
                $target = sh_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
            }
            if ($target === null) {
                $errors[] = 'Account could not be found. Please sign in or register again.';
            } else {
                require_once SH_ROOT . '/includes/firebase.php';
                $sentFirebase = sh_fb_send_verification($target);
                if (!empty($sentFirebase['ok'])) {
                    if (!empty($sentFirebase['already'])) {
                        $verified = true;
                        $justVerified = true;
                        sh_redirect('verify-email.php?verified=1');
                    } else {
                        sh_flash('success', 'A verification link was sent to ' . sh_email_mask($email) . '. Please check your inbox.');
                        sh_redirect('verify-email.php');
                    }
                } else {
                    $errors[] = (string)($sentFirebase['error'] ?? 'The verification email could not be sent.');
                }
            }
        } else {
            $result = sh_email_send_verification_code($email, $uid ?: null);
            if (!empty($result['ok'])) {
                sh_flash('success', 'A new 6-digit code was sent to ' . sh_email_mask($email) . '.');
                if (function_exists('sh_security_log')) {
                    sh_security_log('email_verification_sent', $uid, ['email' => sh_email_mask($email), 'via' => 'smtp_otp']);
                }
                sh_redirect('verify-email.php');
            } else {
                $errors[] = 'The code could not be emailed: ' . (string)($result['error'] ?? 'the mail server refused the message.');
                if (function_exists('sh_security_log')) {
                    sh_security_log('email_verification_send_failed', $uid, ['email' => sh_email_mask($email), 'state' => (string)($result['state'] ?? 'failed')]);
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 5. Determine State
// ---------------------------------------------------------------------------
if ($justVerified) {
    $viewState = 'success';
} elseif ($verified) {
    $viewState = 'already_verified';
} elseif ($email === '') {
    $viewState = 'guest';
} else {
    $viewState = 'required';
}

$remainingCooldown = ($viewState === 'required')
    ? sh_verify_cooldown_remaining($email, $verifier, $dbUser)
    : 0;

$rawReturn = ($returnTo !== '' && !str_contains($returnTo, 'verify-email.php')) ? $returnTo : 'account.php';
$returnTarget = sh_customer_return_target($rawReturn, 'account.php');

$pageTitle = ($viewState === 'success' || $viewState === 'already_verified')
    ? 'Email Verified'
    : 'Verify Email';
$pageDescription = 'Confirm your email address on ShopHaat.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-verify-wrap">
  <div class="sh-verify-card">

    <?php if ($viewState === 'guest'): ?>
      <!-- Guest with no verification in progress -->
      <div class="sh-verify-card__badge"><?= sh_icon('mail', 22) ?></div>
      <h1 class="sh-verify-card__title">Verify your email</h1>
      <p class="sh-verify-card__desc">There is no verification in progress for this browser. Sign in to verify the email address on your account.</p>
      <a class="sh-verify-card__btn-primary" href="<?= e(sh_url('login.php')) ?>">
        <?= sh_icon('user', 15) ?> Sign in to your account
      </a>
      <div class="sh-verify-card__footer">
        <a href="<?= e(sh_url('index.php')) ?>">Return to ShopHaat</a>
      </div>

    <?php elseif ($viewState === 'already_verified'): ?>
      <!-- State B: Email already verified -->
      <div class="sh-verify-card__badge sh-verify-card__badge--ok"><?= sh_icon('check-circle', 22) ?></div>
      <h1 class="sh-verify-card__title">Your email is verified</h1>
      <p class="sh-verify-card__desc">Your email address <strong><?= e(sh_email_mask($email)) ?></strong> is already verified on this account.</p>
      <a class="sh-verify-card__btn-primary" href="<?= e(sh_url($returnTarget)) ?>">
        <?= sh_icon('arrow-right', 15) ?> Continue to ShopHaat
      </a>
      <div class="sh-verify-card__footer">
        <?php if ($user !== null): ?>
          <a href="<?= e(sh_url('account.php')) ?>">Go to your account</a>
        <?php else: ?>
          <a href="<?= e(sh_url('login.php')) ?>">Sign in</a>
        <?php endif; ?>
      </div>

    <?php elseif ($viewState === 'success'): ?>
      <!-- State C: Verification successful -->
      <div class="sh-verify-card__badge sh-verify-card__badge--ok"><?= sh_icon('check-circle', 22) ?></div>
      <h1 class="sh-verify-card__title">Verification successful</h1>
      <p class="sh-verify-card__desc">Your email address <strong><?= e(sh_email_mask($email)) ?></strong> has been verified successfully. You can now continue shopping.</p>
      <?php if ($user !== null): ?>
        <a class="sh-verify-card__btn-primary" href="<?= e(sh_url($returnTarget)) ?>">
          <?= sh_icon('arrow-right', 15) ?> Continue to ShopHaat
        </a>
        <div class="sh-verify-card__footer">
          <a href="<?= e(sh_url('account.php')) ?>">Go to your account</a>
        </div>
      <?php else: ?>
        <a class="sh-verify-card__btn-primary" href="<?= e(sh_url('login.php' . ($returnTarget !== 'account.php' ? '?redirect=' . urlencode($returnTarget) : ''))) ?>">
          <?= sh_icon('user', 15) ?> Sign in to your account
        </a>
        <div class="sh-verify-card__footer">
          <a href="<?= e(sh_url('index.php')) ?>">Return to ShopHaat</a>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- State A & D: Verification required / retry -->
      <div class="sh-verify-card__badge"><?= sh_icon('mail', 22) ?></div>
      <h1 class="sh-verify-card__title">Verify your email</h1>

      <?php if ($verifier === 'firebase'): ?>
        <!-- Method A: Firebase Link Flow -->
        <p class="sh-verify-card__desc">
          We sent a verification link to <strong><?= e(sh_email_mask($email)) ?></strong>.
          Please open your email and click the link to confirm your address.
        </p>
      <?php elseif ($verifier === 'smtp_otp'): ?>
        <!-- Method B: Custom SMTP 6-digit OTP Flow -->
        <p class="sh-verify-card__desc">
          We sent a <?= (int)sh_email_otp_code_length() ?>-digit verification code to <strong><?= e(sh_email_mask($email)) ?></strong>.
          Enter it below to confirm your address.
        </p>
      <?php else: ?>
        <p class="sh-verify-card__desc">
          Email verification is currently not available for <strong><?= e(sh_email_mask($email)) ?></strong>.
          An administrator needs to configure email delivery settings.
        </p>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="sh-verify-card__alert sh-verify-card__alert--error">
          <?= sh_icon('x-circle', 16) ?>
          <div><?= e($errors[0]) ?></div>
        </div>
      <?php elseif ($notice !== ''): ?>
        <div class="sh-verify-card__alert sh-verify-card__alert--<?= $noticeKind === 'success' ? 'success' : 'info' ?>">
          <?= sh_icon($noticeKind === 'success' ? 'check-circle' : 'info', 16) ?>
          <div><?= e($notice) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($verifier === 'firebase'): ?>
        <!-- Method A Actions: Status check and resend link -->
        <a class="sh-verify-card__btn-primary" href="<?= e(sh_url('verify-email.php?sync=1')) ?>">
          <?= sh_icon('rotate', 14) ?> Check verification status
        </a>
        <form class="sh-verify-card__resend-form" method="post" novalidate>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="action" value="send">
          <button class="sh-verify-card__resend-btn" id="sh-resend-btn" type="submit"
                  <?= $remainingCooldown > 0 ? 'disabled' : '' ?>
                  data-seconds="<?= (int)$remainingCooldown ?>"
                  data-label="Resend verification email">
            <?= sh_icon('rotate', 13) ?>
            <span id="sh-resend-label">Resend verification email<?= $remainingCooldown > 0 ? ' (' . (int)$remainingCooldown . 's)' : '' ?></span>
          </button>
        </form>
      <?php elseif ($verifier === 'smtp_otp'): ?>
        <!-- Method B Actions: 6-digit OTP form and resend code -->
        <form class="sh-verify-card__form" method="post" novalidate>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="action" value="verify">
          <label class="sh-verify-card__label" for="ve-code">Verification code</label>
          <input class="sh-verify-card__code" id="ve-code" name="code" type="text"
                 inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*"
                 maxlength="<?= (int)sh_email_otp_code_length() ?>" required
                 placeholder="<?= str_repeat('0', (int)sh_email_otp_code_length()) ?>">
          <p class="sh-verify-card__hint">Code expires in <?= (int)sh_email_otp_expiry_minutes() ?> minutes.</p>
          <button class="sh-verify-card__btn-primary" type="submit">
            <?= sh_icon('check-circle', 15) ?> Verify email
          </button>
        </form>

        <form class="sh-verify-card__resend-form" method="post" novalidate>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="action" value="send">
          <button class="sh-verify-card__resend-btn" id="sh-resend-btn" type="submit"
                  <?= ($remainingCooldown > 0 || !$canSend) ? 'disabled' : '' ?>
                  data-seconds="<?= (int)$remainingCooldown ?>"
                  data-label="Resend code">
            <?= sh_icon('rotate', 13) ?>
            <span id="sh-resend-label">Resend code<?= $remainingCooldown > 0 ? ' (' . (int)$remainingCooldown . 's)' : '' ?></span>
          </button>
        </form>
      <?php else: ?>
        <a class="sh-verify-card__btn-primary" href="<?= e(sh_url($returnTarget)) ?>">
          Return to ShopHaat
        </a>
      <?php endif; ?>

      <div class="sh-verify-card__footer">
        <?php if ($user !== null): ?>
          <a href="<?= e(sh_url('logout.php')) ?>">Sign out</a>
          <span class="sh-verify-card__dot">•</span>
          <a href="<?= e(sh_url('index.php')) ?>">Return to store</a>
        <?php elseif ($context === 'signup'): ?>
          <a href="<?= e(sh_url('register.php')) ?>">Register with another email</a>
          <span class="sh-verify-card__dot">•</span>
          <a href="<?= e(sh_url('login.php')) ?>">Back to login</a>
        <?php else: ?>
          <a href="<?= e(sh_url('login.php')) ?>">Back to login</a>
          <span class="sh-verify-card__dot">•</span>
          <a href="<?= e(sh_url('index.php')) ?>">Return to store</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<script<?= sh_csp_nonce_attr() ?>>
(function() {
  var resendBtn = document.getElementById('sh-resend-btn');
  if (resendBtn) {
    var sec = parseInt(resendBtn.getAttribute('data-seconds') || '0', 10);
    var baseLabel = resendBtn.getAttribute('data-label') || 'Resend code';
    var labelSpan = document.getElementById('sh-resend-label');
    if (sec > 0) {
      var timer = setInterval(function() {
        sec--;
        if (sec <= 0) {
          clearInterval(timer);
          resendBtn.disabled = false;
          resendBtn.removeAttribute('disabled');
          if (labelSpan) { labelSpan.textContent = baseLabel; }
        } else {
          if (labelSpan) { labelSpan.textContent = baseLabel + ' (' + sec + 's)'; }
        }
      }, 1000);
    }
  }

  document.querySelectorAll('.sh-verify-card form').forEach(function(f) {
    f.addEventListener('submit', function(e) {
      if (f.getAttribute('data-submitting') === '1') {
        e.preventDefault();
        return false;
      }
      f.setAttribute('data-submitting', '1');
      var b = f.querySelector('button[type="submit"]');
      if (b) {
        setTimeout(function() { b.disabled = true; }, 10);
      }
    });
  });

  var codeIn = document.getElementById('ve-code');
  if (codeIn) {
    codeIn.addEventListener('input', function() {
      var cleaned = this.value.replace(/[^0-9]/g, '');
      if (this.value !== cleaned) {
        this.value = cleaned;
      }
    });
  }
})();
</script>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
