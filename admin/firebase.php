<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
$admin = sh_require_admin();
sh_fb_schema_ensure();
$errors = [];
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form === 'save') {
        $key = trim(sh_post('firebase_api_key'));
        if ($key !== '' && strpos($key, '•') === false) {
            if (!preg_match('/^[A-Za-z0-9_\-]{20,80}$/', $key)) { $errors[] = 'That does not look like a Firebase Web API key.'; }
            else { sh_setting_save('firebase_api_key', sh_verify_encrypt($key)); }
        }
        if (!empty($_POST['clear_key'])) { sh_setting_save('firebase_api_key', ''); }
        if (!$errors) {
            sh_setting_save('firebase_project_id', mb_substr(trim(sh_post('firebase_project_id')), 0, 80));
            sh_setting_save('firebase_enabled', !empty($_POST['firebase_enabled']) && sh_fb_api_key() !== '' ? '1' : '0');
            sh_setting_save('email_verification_required', !empty($_POST['email_verification_required']) ? '1' : '0');
            sh_log_line('admin', 'Email verification (Firebase) settings updated by ' . $admin['email']);
            sh_flash('success', 'Email verification settings saved.');
            sh_redirect('admin/firebase.php');
        }
    }
    if ($form === 'test') {
        // Harmless connectivity check: an intentionally bad credential must come
        // back as a Firebase error (not a transport error / invalid key).
        $r = sh_fb_call('signInWithPassword', ['email' => 'connectivity-check@example.com', 'password' => bin2hex(random_bytes(8)), 'returnSecureToken' => true]);
        $code = strtoupper((string)($r['error'] ?? ''));
        if ($r['ok'] || str_starts_with($code, 'EMAIL_NOT_FOUND') || str_starts_with($code, 'INVALID_LOGIN_CREDENTIALS') || str_starts_with($code, 'INVALID_PASSWORD')) {
            $test = ['ok' => true, 'msg' => 'Connected to Firebase Authentication. Email/password sign-in is enabled.'];
        } elseif (str_starts_with($code, 'OPERATION_NOT_ALLOWED')) {
            $test = ['ok' => false, 'msg' => 'Connected, but Email/Password sign-in is disabled in Firebase → Authentication → Sign-in method.'];
        } else {
            $test = ['ok' => false, 'msg' => 'Could not reach Firebase: ' . sh_fb_error_text($code)];
        }
    }
}

$keyMask = sh_verify_mask(sh_fb_api_key());
$stats = ['total' => 0, 'verified' => 0];
try {
    $row = sh_one("SELECT COUNT(*) c, SUM(email_verified = 1) v FROM users WHERE email NOT LIKE '%@user.shophaat.local' AND email <> ''");
    $stats = ['total' => (int)($row['c'] ?? 0), 'verified' => (int)($row['v'] ?? 0)];
} catch (Throwable $e) {}

$adminPage = 'firebase';
$adminTitle = 'Email Verification';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?><div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?><div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<?php if ($test): ?><div class="sh-alert <?= $test['ok'] ? 'sh-alert--success' : 'sh-alert--error' ?>"><?= sh_icon($test['ok'] ? 'check-circle' : 'x-circle', 17) ?><div><?= e($test['msg']) ?></div></div><?php endif; ?>

<div class="sh-cards">
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('mail', 17) ?> Email verification (Google Firebase)</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="save">
        <label class="sh-toggle" style="margin-bottom:14px"><input type="checkbox" name="firebase_enabled" value="1" <?= sh_setting('firebase_enabled', '0') === '1' ? 'checked' : '' ?>><span class="sh-toggle__track"></span><span>Enable email verification for customer accounts</span></label>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="fb-key">Firebase Web API key</label>
            <input class="sh-input" id="fb-key" name="firebase_api_key" autocomplete="off" spellcheck="false" value="<?= e($keyMask) ?>" placeholder="AIza…">
            <span class="sh-field__hint">Firebase console → Project settings → General → Web API Key. Used server-side only; it is stored encrypted and never sent to the browser.</span>
            <?php if ($keyMask !== ''): ?><label class="sh-toggle" style="margin-top:6px"><input type="checkbox" name="clear_key" value="1"><span class="sh-toggle__track"></span><span>Remove stored key</span></label><?php endif; ?>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="fb-proj">Firebase project ID (optional, for reference)</label>
            <input class="sh-input" id="fb-proj" name="firebase_project_id" value="<?= e((string)sh_setting('firebase_project_id', '')) ?>" placeholder="my-shop-12345">
          </div>
        </div>
        <label class="sh-toggle" style="margin:4px 0 14px"><input type="checkbox" name="email_verification_required" value="1" <?= sh_setting('email_verification_required', '0') === '1' ? 'checked' : '' ?>><span class="sh-toggle__track"></span><span>Require a verified email before checkout (email/password mode only)</span></label>
        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
        </div>
      </form>
      <form method="post" style="margin-top:10px"><?= sh_csrf_field() ?><input type="hidden" name="form" value="test">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" <?= $keyMask === '' ? 'disabled' : '' ?>><?= sh_icon('zap', 13) ?> Test connection</button></form>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('settings', 17) ?> Firebase project setup</h2></div>
    <div class="sh-panel__body" style="font-size:13.2px;line-height:1.8">
      <dl class="sh-cv__kv">
        <div><dt>Sign-in method</dt><dd>Enable <strong>Email/Password</strong> in Firebase → Authentication → Sign-in method.</dd></div>
        <div><dt>Authorized domain</dt><dd>Add <code><?= e(parse_url(sh_site_url(), PHP_URL_HOST) ?: '') ?></code> under Authentication → Settings → Authorized domains.</dd></div>
        <div><dt>Continue URL</dt><dd><code><?= e(sh_fb_continue_url()) ?></code> — customers return here after Firebase's verification page.</dd></div>
        <div><dt>Custom action URL</dt><dd>Optional: set the email template action URL to <code><?= e(sh_fb_continue_url()) ?></code> so the link opens on your site directly.</dd></div>
        <div><dt>Status</dt><dd><?= $stats['verified'] ?> of <?= $stats['total'] ?> email accounts verified.</dd></div>
      </dl>
      <p class="sh-panel__note" style="margin-top:10px">Customers who sign in with Google are marked verified automatically because Google has already confirmed the address. Phone-OTP accounts without an email are not affected.</p>
    </div>
  </section>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
