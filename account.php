<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
$user = sh_require_login();
$errors = [];
$fbOn = sh_fb_enabled() && sh_fb_applies($user);

// Resend the Firebase verification email (rate-limited).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'resend_verification') {
    sh_csrf_require();
    $r = $fbOn ? sh_fb_send_verification($user) : ['ok' => false, 'error' => 'Email verification is not available.'];
    if (!empty($r['ok'])) { sh_flash('success', !empty($r['already']) ? 'Your email is already verified.' : 'Verification email sent. Please check your inbox (and spam folder).'); }
    else { sh_flash('error', (string)($r['error'] ?? 'The verification email could not be sent.')); }
    sh_redirect('account.php#account-verification');
}
// Re-check with Firebase when the customer comes back from the link or asks explicitly.
if ($fbOn && empty($user['email_verified']) && (isset($_GET['verify']) || empty($user['email_verify_checked_at']) || time() - strtotime((string)$user['email_verify_checked_at']) > 600)) {
    $sync = sh_fb_sync_verified($user);
    if (!empty($sync['verified'])) { $user['email_verified'] = 1; if (isset($_GET['verify'])) { sh_flash('success', 'Your email address is now verified.'); sh_redirect('account.php#account-verification'); } }
    elseif (isset($_GET['verify'])) { sh_flash('error', $sync['ok'] ? 'Your email is not verified yet. Open the link in the verification email first.' : (string)($sync['error'] ?? 'Verification could not be checked right now.')); sh_redirect('account.php#account-verification'); }
}

// Phone-only accounts carry a synthetic email; never surface it in the form.
$form = [
    'name'  => $user['name'],
    'email' => sh_is_synthetic_email((string)$user['email']) ? '' : (string)$user['email'],
    'phone' => (string)$user['phone'],
];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $v = new ShValidator($_POST);
    $v->required('name', 'Full name')->maxLen('name', 110, 'Full name')
      ->email('email', 'Email address')
      ->required('phone', 'Phone number')->phone('phone', 'Phone number');
    $errors = $v->errors();

    if (!$errors) {
        try {
            $email = trim($form['email']);
            if ($email !== '') {
                $dupeEmail = sh_one('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, (int)$user['id']]);
                if ($dupeEmail) {
                    $errors['email'] = 'That email address is already used by another account.';
                }
            }

            $newPhone = sh_phone_normalize($form['phone']);
            $oldPhone = sh_phone_normalize((string)$user['phone']);
            $phoneChanged = $newPhone !== '' && $newPhone !== $oldPhone;
            if ($newPhone === '') {
                $errors['phone'] = 'Please enter a valid mobile number.';
            } elseif ($phoneChanged) {
                $dupe = sh_find_user_by_phone($newPhone);
                if ($dupe !== null && (int)$dupe['id'] !== (int)$user['id']) {
                    $errors['phone'] = 'This mobile number is already used by another account.';
                }
            }

            if (!$errors) {
                $upd = [
                    'name'  => $form['name'],
                    'phone' => $newPhone,
                ];
                if ($email !== '' || !sh_is_synthetic_email((string)$user['email'])) {
                    // Keep a real email; only overwrite when the customer typed one.
                    if ($email !== '') {
                        $upd['email'] = $email;
                        // A different address must be verified again.
                        if (strcasecmp($email, (string)$user['email']) !== 0) { $upd += sh_fb_reset_for_email_change(); }
                    }
                }
                if ($phoneChanged) {
                    // The new number is unverified until they sign in with it.
                    $upd['phone_verified'] = 0;
                    $upd['phone_verified_at'] = null;
                    $upd['phone_verification_method'] = null;
                }
                sh_update('users', $upd, 'id = ?', [(int)$user['id']]);
                sh_security_log('profile_updated', (int)$user['id']);
                sh_flash('success', 'Your profile has been updated.');
                sh_redirect('account.php');
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'profile');
            $errors['general'] = 'Your profile could not be saved.';
        }
    }
}

$stats = ['orders' => 0, 'pending' => 0, 'completed' => 0, 'spent' => 0];
try {
    $row = sh_one('SELECT COUNT(*) AS c,
        SUM(status IN (\'pending\',\'awaiting_payment\',\'payment_submitted\',\'processing\')) AS p,
        SUM(status = \'completed\') AS d,
        COALESCE(SUM(CASE WHEN payment_status = \'verified\' OR status = \'completed\' THEN total ELSE 0 END),0) AS s
        FROM orders WHERE user_id = ?', [(int)$user['id']]);
    $stats = ['orders' => (int)$row['c'], 'pending' => (int)$row['p'], 'completed' => (int)$row['d'], 'spent' => (float)$row['s']];
} catch (Throwable $e) { sh_log_exception($e, 'account-stats'); }

$accountPage = 'account';
$pageTitle = 'My Profile';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">My Account</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-stats">
        <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('package', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['orders'] ?></p><p class="sh-stat__label">Total orders</p></div></div>
        <div class="sh-stat sh-stat--warn"><span class="sh-stat__icon"><?= sh_icon('clock', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['pending'] ?></p><p class="sh-stat__label">In progress</p></div></div>
        <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('check-circle', 19) ?></span>
          <div><p class="sh-stat__value"><?= $stats['completed'] ?></p><p class="sh-stat__label">Completed</p></div></div>
        <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('dollar', 19) ?></span>
          <div><p class="sh-stat__value" style="font-size:16px"><?= e(sh_money($stats['spent'])) ?></p><p class="sh-stat__label">Total spent</p></div></div>
      </div>

      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('user', 18) ?> Profile Information</h1></div>
        <?php if ($errors): ?>
          <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
            <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <?= sh_csrf_field() ?>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="ac-name">Full name</label>
              <input class="sh-input" id="ac-name" name="name" value="<?= e($form['name']) ?>" required maxlength="110">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="ac-phone">Mobile number</label>
              <input class="sh-input" id="ac-phone" name="phone" value="<?= e($form['phone'] !== '' ? sh_phone_display($form['phone']) : '') ?>" required>
              <?php if (!empty($user['phone_verified'])): ?>
                <p class="sh-field__hint"><span class="sh-verify-badge sh-verify-badge--ok"><?= sh_icon('check-circle', 13) ?> Verified</span></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ac-email">Email address <span style="color:var(--sh-muted);font-weight:400">(optional)</span></label>
            <input class="sh-input" id="ac-email" type="email" name="email" value="<?= e($form['email']) ?>">
            <p class="sh-field__hint">Only used for order updates and digital delivery receipts.</p>
          </div>
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save changes</button>
        </form>
        <p style="font-size:12.5px;color:var(--sh-muted);margin-top:12px">
          Member since <?= e(date('d M Y', strtotime($user['created_at']))) ?>.
        </p>
      </div>

      <?php if ($fbOn): $emailOk = !empty($user['email_verified']); ?>
      <div class="sh-section" id="account-verification">
        <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('shield', 18) ?> Account Verification</h2></div>
        <div class="sh-ver">
          <div class="sh-ver__row">
            <div class="sh-ver__info">
              <span class="sh-ver__label">Email</span>
              <span class="sh-ver__value"><?= e((string)$user['email']) ?></span>
            </div>
            <?php if ($emailOk): ?>
              <span class="sh-verify-badge sh-verify-badge--ok"><?= sh_icon('check-circle', 13) ?> Email Verified</span>
            <?php else: ?>
              <span class="sh-verify-badge sh-verify-badge--no"><?= sh_icon('alert', 13) ?> Email Not Verified</span>
            <?php endif; ?>
          </div>
          <?php if (!$emailOk): ?>
            <p class="sh-ver__hint">Verify your email to secure your account. We send the link through Google Firebase — open it from your inbox, then return here.</p>
            <div class="sh-ver__actions">
              <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="resend_verification">
                <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('send', 14) ?> Resend Verification Email</button></form>
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('account.php?verify=1')) ?>#account-verification"><?= sh_icon('refresh', 14) ?> I've verified — check again</a>
            </div>
          <?php else: ?>
            <p class="sh-ver__hint">Verified<?= !empty($user['email_verified_at']) ? ' on ' . e(date('d M Y', strtotime((string)$user['email_verified_at']))) : '' ?>.</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
