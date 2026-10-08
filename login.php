<?php
/**
 * Customer sign-in. The active authentication mode (email + password, or
 * phone + OTP) is chosen by the admin from Settings → Authentication; only one
 * mode is ever shown here.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect(sh_safe_redirect($redirect, 'account.php')); }

$mode = sh_auth_mode();

// A guest who clicked "Proceed to Checkout" is sent here with ?redirect=checkout.php.
// Show a clear message; the cart is untouched and they return to checkout after login.
$checkoutNotice = sh_safe_redirect($redirect, '') !== ''
    && str_starts_with(sh_safe_redirect($redirect, ''), 'checkout.php');

$error = '';
$email = '';
$phone = '';

// OTP modal state (phone mode only, populated after a successful "Continue").
$otpSent = false;
$otpPhone = '';
$otpMasked = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $redirect = sh_post('redirect', $redirect);

    if ($mode === 'email_password') {
        $email = trim(sh_post('email'));
        $password = (string)($_POST['password'] ?? '');

        if (sh_login_throttled('user_login')) {
            $error = 'Too many failed attempts. Please wait a few minutes and try again.';
        } elseif ($email === '' || $password === '') {
            $error = 'Enter your email address and password.';
        } else {
            try {
                $u = sh_one('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1', [$email]);
                // Constant-ish work factor whether or not the user exists.
                $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt0000000000000000000000000000000';
                if ($u !== null && password_verify($password, $hash) && $u['status'] === 'active') {
                    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                        sh_query('UPDATE users SET password_hash = ? WHERE id = ?',
                            [password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
                    }
                    sh_login_reset('user_login');
                    $uid = (int)$u['id'];
                    $target = sh_safe_redirect($redirect, 'account.php');
                    sh_login_user($uid);
                    sh_security_log('login_success', $uid, ['email' => mb_substr($email, 0, 3) . '***']);
                    sh_flash('success', 'Welcome back.');
                    sh_redirect($target);
                }
                if ($u !== null && $u['status'] !== 'active') {
                    sh_security_log('login_failed', (int)$u['id'], ['email' => mb_substr($email, 0, 3) . '***', 'reason' => 'blocked']);
                    $error = 'This account has been blocked. Please contact customer support.';
                } else {
                    sh_login_fail('user_login');
                    sh_security_log('login_failed', null, ['email' => mb_substr($email, 0, 3) . '***']);
                    $error = 'Invalid email or password.';
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'login');
                $error = 'Sign in is temporarily unavailable. Please try again shortly.';
            }
        }
    } else {
        // Phone + OTP mode
        $phone = sh_post('phone');

        if (sh_login_throttled('user_login')) {
            $error = 'Too many failed attempts. Please wait a few minutes and try again.';
        } elseif ($phone === '') {
            $error = 'Enter your mobile number to continue.';
        } else {
            $phone = sh_phone_normalize($phone);
            if ($phone === '') {
                $error = 'Please enter a valid phone number.';
            } else {
                try {
                    $u = sh_find_user_by_phone($phone);
                    if ($u === null) {
                        sh_login_fail('user_login');
                        sh_security_log('login_failed', null, ['phone' => sh_phone_mask($phone), 'reason' => 'unknown']);
                        $error = 'No account was found with this phone number. Please sign up instead.';
                    } elseif ($u['status'] !== 'active') {
                        sh_security_log('login_failed', (int)$u['id'], ['phone' => sh_phone_mask($phone), 'reason' => 'blocked']);
                        $error = 'This account has been blocked. Please contact customer support.';
                    } else {
                        $target = sh_safe_redirect($redirect, 'account.php');
                        $uid = (int)$u['id'];
                        $canonical = sh_phone_normalize((string)$u['phone']);

                        // Already mid-verification for this account? Re-open the modal
                        // without sending a duplicate SMS.
                        $pend = sh_pending_login();
                        if ($pend !== null && sh_phone_normalize((string)($pend['phone'] ?? '')) === $canonical) {
                            sh_login_reset('user_login');
                            $otpSent = true;
                            $otpPhone = $canonical;
                            $otpMasked = sh_phone_mask_login($canonical);
                        } else {
                            sh_pending_login_save($uid, $canonical, $target);
                            $issue = sh_otp_issue($canonical, 'login', $uid);
                            if ($issue['ok']) {
                                sh_login_reset('user_login');
                                $otpSent = true;
                                $otpPhone = $canonical;
                                $otpMasked = sh_phone_mask_login($canonical);
                            } else {
                                sh_pending_login_clear();
                                $error = $issue['error'] ?? 'We couldn\'t send the verification code right now. Please try again later.';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    sh_log_exception($e, 'login');
                    $error = 'Sign in is temporarily unavailable. Please try again shortly.';
                }
            }
        }
    }
}

$pageTitle = 'Sign In';
$pageDescription = 'Sign in to your account to track orders and manage your profile.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <?php if ($checkoutNotice): ?>
      <div class="sh-alert sh-alert--info" style="margin-bottom:14px">
        <?= sh_icon('lock', 16) ?><span>Please login to continue to checkout.</span>
      </div>
    <?php endif; ?>
    <?php if ($mode === 'email_password'): ?>
      <div class="sh-auth__mark"><?= sh_icon('mail', 26) ?></div>
      <h1 class="sh-auth__title">Login</h1>
      <p class="sh-auth__sub">Access your orders, wishlist and digital purchases.</p>

      <?php if ($error): ?>
        <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="sh-field">
          <label class="sh-field__label" for="lg-email">Email Address</label>
          <input class="sh-input" id="lg-email" type="email" name="email" value="<?= e($email) ?>" required
                 autocomplete="email" placeholder="Enter your email">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="lg-pass">Password</label>
          <input class="sh-input" id="lg-pass" type="password" name="password" required
                 autocomplete="current-password" placeholder="Enter your password">
        </div>
        <div style="text-align:right;margin:-4px 0 12px">
          <a href="<?= e(sh_url('forgot-password.php')) ?>" style="font-size:13px;color:var(--sh-brand);font-weight:700">Forgot Password?</a>
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('log-in', 16) ?> Login</button>
      </form>

      <?= sh_google_button($redirect) ?>

      <p class="sh-auth__foot">New to <?= e(sh_setting('site_name', 'ShopHaat')) ?>?
        <a href="<?= e(sh_url('register.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Create Account</a>
      </p>
    <?php else: ?>
      <div class="sh-auth__mark"><?= sh_icon('smartphone', 26) ?></div>
      <h1 class="sh-auth__title">Login</h1>
      <p class="sh-auth__sub">Enter your phone number. We will send you a one-time code to verify it.</p>

      <?php if ($error): ?>
        <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($error) ?></span></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="sh-field">
          <label class="sh-field__label" for="lg-phone">Phone Number</label>
          <input class="sh-input sh-input--phone" id="lg-phone" type="tel" inputmode="tel" name="phone"
                 value="<?= e($phone !== '' ? sh_phone_display($phone) : '') ?>" required
                 placeholder="Enter phone number" autocomplete="tel">
        </div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('arrow-right', 16) ?> Continue</button>
      </form>

      <p class="sh-auth__foot">New to <?= e(sh_setting('site_name', 'ShopHaat')) ?>?
        <a href="<?= e(sh_url('register.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Sign up</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($otpSent): ?>
  <?php
  $otpPurpose = 'login';
  $otpVerifyLabel = 'Verify & Login';
  ?>
  <noscript>
    <div class="sh-wrap"><div class="sh-auth" style="text-align:center">
      <p>A verification code was sent to <strong><?= e($otpMasked) ?></strong>.
        <a href="<?= e(sh_url('otp.php?purpose=login')) ?>">Enter the code here</a>.</p>
    </div></div>
  </noscript>
  <?php require SH_ROOT . '/includes/otp-modal.php'; ?>
<?php endif; ?>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
