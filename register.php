<?php
/**
 * Customer registration. The active authentication mode decides the form:
 * email + password, or phone number + OTP (never both).
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$redirect = sh_get('redirect');
if (sh_user() !== null) { sh_redirect(sh_safe_redirect($redirect, 'account.php')); }

$mode = sh_auth_mode();

$errors = [];
$form = ['name' => '', 'email' => '', 'phone' => ''];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

// OTP modal state (phone mode only, populated after a successful "Create account").
$otpSent = false;
$otpPhone = '';
$otpMasked = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $redirect = sh_post('redirect', $redirect);

    if ($mode === 'email_password') {
        $v = new ShValidator($_POST);
        $v->required('name', 'Full name')->minLen('name', 2, 'Full name')->maxLen('name', 110, 'Full name')
          ->required('email', 'Email address')->email('email', 'Email address')
          ->required('password', 'Password')->password('password')
          ->matches('password2', 'password', 'Password confirmation');
        if (empty($_POST['terms'])) { $v->custom('terms', false, 'You must accept the terms and conditions.'); }
        $errors = $v->errors();

        if (!$errors) {
            try {
                $exists = sh_one('SELECT id FROM users WHERE email = ? LIMIT 1', [$form['email']]);
                if ($exists) {
                    $errors['email'] = 'An account with this email address already exists.';
                } else {
                    $target = sh_safe_redirect($redirect, 'account.php');
                    $uid = sh_insert('users', [
                        'name'          => $form['name'],
                        'email'         => $form['email'],
                        'password_hash' => password_hash((string)$_POST['password'], PASSWORD_DEFAULT),
                        'status'        => 'active',
                    ]);
                    sh_login_user($uid);
                    sh_security_log('account_created', $uid, ['email' => mb_substr($form['email'], 0, 3) . '***']);
                    try { require_once SH_ROOT . '/includes/admin-tools.php'; sh_admin_notify('new_customer', 'New customer registered', (string)$form['name'], 'admin/customers.php?id=' . $uid); } catch (Throwable $e) {}
                    $welcome = 'Your account has been created. Welcome to ' . sh_setting('site_name', 'ShopHaat') . '.';
                    try {
                        require_once SH_ROOT . '/includes/firebase.php';
                        if (sh_fb_enabled()) {
                            $sent = sh_fb_send_verification(['id' => $uid, 'email' => $form['email'], 'email_verified' => 0]);
                            $welcome .= !empty($sent['ok'])
                                ? ' We sent a verification link to ' . $form['email'] . ' — please check your inbox.'
                                : ' You can verify your email address from your profile.';
                        }
                    } catch (Throwable $e) { sh_log_exception($e, 'register-verify'); }
                    sh_flash('success', $welcome);
                    sh_redirect($target);
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'register');
                $errors['general'] = 'Your account could not be created. Please try again.';
            }
        }
    } else {
        // Phone + OTP mode
        $v = new ShValidator($_POST);
        $v->required('name', 'Full name')->minLen('name', 2, 'Full name')->maxLen('name', 110, 'Full name')
          ->required('phone', 'Phone number')->phone('phone', 'Phone number');
        if (empty($_POST['terms'])) { $v->custom('terms', false, 'You must accept the terms and conditions.'); }
        $errors = $v->errors();

        if (!$errors) {
            $phone = sh_phone_normalize($form['phone']);
            if ($phone === '') {
                $errors['phone'] = 'Please enter a valid phone number.';
            } else {
                try {
                    $target = sh_safe_redirect($redirect, 'account.php');
                    $exists = sh_find_user_by_phone($phone);
                    if ($exists !== null) {
                        $errors['phone'] = 'An account already exists with this phone number. Please log in instead.';
                    } else {
                        // Already mid-verification for this number? Re-open the modal
                        // without sending a duplicate SMS.
                        $pend = sh_pending_signup();
                        if ($pend !== null && sh_phone_normalize((string)($pend['phone'] ?? '')) === $phone) {
                            $otpSent = true;
                            $otpPhone = $phone;
                            $otpMasked = sh_phone_mask_login($phone);
                        } else {
                            sh_pending_signup_save($form['name'], $phone, $target);
                            $issue = sh_otp_issue($phone, 'signup', null);
                            if ($issue['ok']) {
                                $otpSent = true;
                                $otpPhone = $phone;
                                $otpMasked = sh_phone_mask_login($phone);
                            } else {
                                sh_pending_signup_clear();
                                $errors['general'] = $issue['error'] ?? 'We couldn\'t send the verification code right now. Please try again later.';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    sh_log_exception($e, 'register');
                    $errors['general'] = 'Your account could not be created. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Create Account';
$pageDescription = 'Create an account to order faster and track your purchases.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-auth">
    <?php if ($mode === 'email_password'): ?>
      <div class="sh-auth__mark"><?= sh_icon('user-plus', 26) ?></div>
      <h1 class="sh-auth__title">Create Account</h1>
      <p class="sh-auth__sub">Create an account to order faster and track your purchases.</p>

      <?php if ($errors): ?>
        <div class="sh-alert sh-alert--error">
          <?= sh_icon('x-circle', 16) ?>
          <div><strong>Please check the following:</strong>
            <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="sh-field">
          <label class="sh-field__label" for="rg-name">Full Name <span class="sh-field__req">*</span></label>
          <input class="sh-input <?= isset($errors['name']) ? 'sh-input--error' : '' ?>" id="rg-name" name="name"
                 value="<?= e($form['name']) ?>" required maxlength="110" autocomplete="name">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="rg-email">Email Address <span class="sh-field__req">*</span></label>
          <input class="sh-input <?= isset($errors['email']) ? 'sh-input--error' : '' ?>" id="rg-email" type="email"
                 name="email" value="<?= e($form['email']) ?>" required autocomplete="email">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="rg-pass">Password <span class="sh-field__req">*</span></label>
          <input class="sh-input <?= isset($errors['password']) ? 'sh-input--error' : '' ?>" id="rg-pass" type="password"
                 name="password" required autocomplete="new-password">
          <p class="sh-field__hint">At least 8 characters, including a letter and a number.</p>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="rg-pass2">Confirm Password <span class="sh-field__req">*</span></label>
          <input class="sh-input <?= isset($errors['password2']) ? 'sh-input--error' : '' ?>" id="rg-pass2" type="password"
                 name="password2" required autocomplete="new-password">
        </div>
        <label class="sh-check" style="margin-bottom:14px">
          <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
          <span>I agree to the <a href="<?= e(sh_url('page.php?p=terms')) ?>" style="color:var(--sh-brand)">terms and conditions</a>
            and <a href="<?= e(sh_url('page.php?p=privacy')) ?>" style="color:var(--sh-brand)">privacy policy</a>.</span>
        </label>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit"><?= sh_icon('user-plus', 16) ?> Create Account</button>
      </form>

      <?= sh_google_button($redirect) ?>

      <p class="sh-auth__foot">Already have an account?
        <a href="<?= e(sh_url('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Login</a>
      </p>
    <?php else: ?>
      <div class="sh-auth__mark"><?= sh_icon('user-plus', 26) ?></div>
      <h1 class="sh-auth__title">Create Account</h1>
      <p class="sh-auth__sub">Just your name and phone number — we will verify it with a one-time code.</p>

      <?php if ($errors): ?>
        <div class="sh-alert sh-alert--error">
          <?= sh_icon('x-circle', 16) ?>
          <div><strong>Please check the following:</strong>
            <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        </div>
      <?php endif; ?>

      <form method="post" novalidate data-signup-phone-form data-no-lock>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="sh-field">
          <label class="sh-field__label" for="rg-name">Full Name <span class="sh-field__req">*</span></label>
          <input class="sh-input <?= isset($errors['name']) ? 'sh-input--error' : '' ?>" id="rg-name" name="name"
                 value="<?= e($form['name']) ?>" required maxlength="110" autocomplete="name">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="rg-phone">Phone Number <span class="sh-field__req">*</span></label>
          <input class="sh-input sh-input--phone <?= isset($errors['phone']) ? 'sh-input--error' : '' ?>" id="rg-phone" name="phone"
                 value="<?= e($form['phone'] !== '' ? sh_phone_display($form['phone']) : '') ?>" required
                 placeholder="01XXXXXXXXX" autocomplete="tel">
        </div>
        <label class="sh-check" style="margin-bottom:14px">
          <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
          <span>I agree to the <a href="<?= e(sh_url('page.php?p=terms')) ?>" style="color:var(--sh-brand)">terms and conditions</a>
            and <a href="<?= e(sh_url('page.php?p=privacy')) ?>" style="color:var(--sh-brand)">privacy policy</a>.</span>
        </label>
        <div class="sh-alert sh-alert--error" data-signup-error hidden><?= sh_icon('x-circle', 16) ?><span></span></div>
        <button class="sh-btn sh-btn--lg sh-btn--block" type="submit" data-signup-connect>
          <?= sh_icon('arrow-right', 16) ?><span data-signup-connect-label>Continue</span>
        </button>
      </form>

      <p class="sh-auth__foot">Already have an account?
        <a href="<?= e(sh_url('login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''))) ?>">Login</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($mode === 'phone_otp'): ?>
  <?php
  $otpPurpose = 'signup';
  $otpVerifyLabel = 'Verify & Create Account';
  $otpAlreadySent = (bool)$otpSent;
  ?>
  <?php if ($otpSent): ?>
    <noscript>
      <div class="sh-wrap"><div class="sh-auth" style="text-align:center">
        <p>A verification code was sent to <strong><?= e($otpMasked) ?></strong>.
          <a href="<?= e(sh_url('otp.php?purpose=signup')) ?>">Enter the code here</a>.</p>
      </div></div>
    </noscript>
  <?php endif; ?>
  <?php require SH_ROOT . '/includes/otp-modal.php'; ?>
<?php endif; ?>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
