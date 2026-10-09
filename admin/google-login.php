<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_admin();

$errors = [];
$isOwner = sh_admin_is_superadmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if (!$isOwner) {
        sh_flash('error', 'Only the store owner can change Google Login settings.');
        sh_redirect('admin/google-login.php');
    }

    if ($form === 'google_login') {
        $cid = trim((string)($_POST['google_client_id'] ?? ''));
        $sec = trim((string)($_POST['google_client_secret'] ?? ''));
        $on  = !empty($_POST['google_login_enabled']);
        $clear = !empty($_POST['google_clear_secret']);
        if ($cid !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $cid)) {
            $errors['google_client_id'] = 'The Google Client ID contains unexpected characters.';
        }
        if ($on && ($cid === '' || ($sec === '' && (!sh_google_has_secret() || $clear)))) {
            $errors['google_login_enabled'] = 'Enter the Google Client ID and Client Secret before turning Google Login on.';
        }
        if (!$errors) {
            sh_setting_save('google_client_id', $cid);
            // Encrypted at rest and never rendered back; blank keeps the stored one.
            if ($clear) { sh_setting_save('google_client_secret', ''); }
            if ($sec !== '') { sh_setting_save('google_client_secret', sh_google_encrypt($sec)); }
            sh_setting_save('google_login_enabled', $on ? '1' : '0');
            sh_security_log('google_login_settings_changed', null, ['enabled' => $on ? 1 : 0]);
            sh_log_line('admin', 'Google Login settings updated by ' . $admin['email'] . ' (enabled=' . ($on ? '1' : '0') . ')');
            sh_flash('success', $on ? 'Google Login is ON. “Continue with Google” is now shown on the login page.' : 'Settings saved. Google Login is OFF.');
            sh_redirect('admin/google-login.php');
        }
    }

    if ($form === 'google_test') {
        $_SESSION['sh_google_test'] = sh_google_test_connection();
        sh_redirect('admin/google-login.php');
    }
}

$s        = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);
$gEnabled = $s('google_login_enabled', '0') === '1';
$gLive    = sh_google_enabled();
$gTest    = $_SESSION['sh_google_test'] ?? null;
unset($_SESSION['sh_google_test']);
$gCallback = sh_google_callback_url();
$gOrigin   = preg_replace('#^(https?://[^/]+).*$#', '$1', sh_site_url());
$httpsOk   = sh_google_is_https() || sh_google_is_localhost();

$adminPage = 'google_login';
$adminTitle = 'Google Login';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-cards">
  <!-- Status -->
  <section class="sh-panel sh-cards__full">
    <div class="sh-panel__body sh-status">
      <div class="sh-status__item">
        <span class="sh-status__label">Google Login</span>
        <span class="sh-statuspill <?= $gLive ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
          <?= $gLive ? 'ON' : ($gEnabled ? 'Incomplete' : 'OFF') ?></span>
      </div>
      <div class="sh-status__item">
        <span class="sh-status__label">Client ID</span>
        <span class="sh-status__value"><?= $s('google_client_id') !== '' ? 'Set' : 'Not set' ?></span>
      </div>
      <div class="sh-status__item">
        <span class="sh-status__label">Client Secret</span>
        <span class="sh-status__value"><?= sh_google_has_secret() ? 'Stored (encrypted)' : 'Not set' ?></span>
      </div>
      <div class="sh-status__item">
        <span class="sh-status__label">HTTPS</span>
        <span class="sh-statuspill <?= $httpsOk ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= sh_google_is_https() ? 'Secure' : (sh_google_is_localhost() ? 'Localhost' : 'Required') ?></span>
      </div>
    </div>
  </section>

  <!-- Settings -->
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('log-in', 17) ?> OAuth settings</h2></div>
    <div class="sh-panel__body">
      <?php if ($isOwner): ?>
      <form method="post" novalidate autocomplete="off">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="google_login">
        <label class="sh-toggle" style="margin:2px 0 18px">
          <input type="checkbox" name="google_login_enabled" value="1" <?= $gEnabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span>Enable Google Login</span>
        </label>
        <div class="sh-field">
          <label class="sh-field__label" for="gl-cid">Google Client ID</label>
          <input class="sh-input <?= isset($errors['google_client_id']) ? 'sh-input--error' : '' ?>" id="gl-cid" name="google_client_id"
                 value="<?= e($s('google_client_id')) ?>" placeholder="1234567890-abc123.apps.googleusercontent.com" spellcheck="false">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="gl-sec">Google Client Secret</label>
          <div class="sh-secret">
            <input class="sh-input" id="gl-sec" type="password" name="google_client_secret" value="" autocomplete="new-password"
                   placeholder="<?= sh_google_has_secret() ? 'Stored — leave blank to keep' : 'GOCSPX-…' ?>">
            <button class="sh-secret__btn" type="button" data-reveal="gl-sec" aria-label="Show or hide"><?= sh_icon('eye', 15) ?></button>
          </div>
          <span class="sh-field__hint">Encrypted at rest. Used server-side only and never sent to the browser.</span>
          <?php if (sh_google_has_secret()): ?>
            <label class="sh-check" style="margin-top:6px"><input type="checkbox" name="google_clear_secret" value="1"><span>Remove the stored secret</span></label>
          <?php endif; ?>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="gl-cb">Authorized Redirect URI</label>
          <div class="sh-copyrow">
            <input class="sh-input sh-input--mono" id="gl-cb" value="<?= e($gCallback) ?>" readonly onclick="this.select()">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-copy="<?= e($gCallback) ?>"><?= sh_icon('copy', 14) ?> Copy</button>
          </div>
          <span class="sh-field__hint">Detected from this site's address. Add it exactly as shown in Google Cloud Console.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="gl-or">Authorized JavaScript origin</label>
          <div class="sh-copyrow">
            <input class="sh-input sh-input--mono" id="gl-or" value="<?= e($gOrigin) ?>" readonly onclick="this.select()">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-copy="<?= e($gOrigin) ?>"><?= sh_icon('copy', 14) ?> Copy</button>
          </div>
        </div>
        <?php if (!$httpsOk): ?>
          <p class="sh-field__error" style="margin-bottom:12px">This site is not served over HTTPS. Google only accepts https:// redirect URIs, so enable SSL before turning this on.</p>
        <?php endif; ?>
        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
          <button class="sh-btn sh-btn--ghost" type="submit" name="form" value="google_test" formnovalidate><?= sh_icon('shield', 15) ?> Test connection</button>
        </div>
      </form>
      <?php else: ?>
        <p class="sh-panel__note">Only the store owner can change Google Login settings.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Test result / setup -->
  <div class="sh-cards__col">
    <?php if (is_array($gTest)): ?>
      <section class="sh-panel">
        <div class="sh-panel__head">
          <h2 class="sh-panel__title"><?= sh_icon($gTest['ok'] ? 'check-circle' : 'x-circle', 17) ?> Connection status</h2>
          <span class="sh-statuspill <?= $gTest['ok'] ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>" style="margin-left:auto"><?= $gTest['ok'] ? 'Passed' : 'Problems found' ?></span>
        </div>
        <ul class="sh-checklist">
          <?php foreach ($gTest['checks'] as $c): ?>
            <li class="<?= $c['ok'] ? 'is-ok' : 'is-bad' ?>">
              <?= sh_icon($c['ok'] ? 'check-circle' : 'x-circle', 15) ?>
              <div><span><?= e($c['label']) ?></span><small><?= e($c['value']) ?></small></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <section class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('external', 17) ?> Google Cloud Console setup</h2></div>
      <ol class="sh-steps-list">
        <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">console.cloud.google.com</a> and create or select a project.</li>
        <li><strong>OAuth consent screen</strong>: choose External, add app name and support email, scopes <code>openid</code>, <code>email</code>, <code>profile</code>. Publish, or add test users.</li>
        <li><strong>Credentials → Create credentials → OAuth client ID</strong>, application type <strong>Web application</strong>.</li>
        <li>Authorized JavaScript origins: <code><?= e($gOrigin) ?></code></li>
        <li>Authorized redirect URIs: <code><?= e($gCallback) ?></code></li>
        <li>Paste the Client ID and Client Secret here, switch the toggle on, save, then run <strong>Test connection</strong>.</li>
      </ol>
    </section>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
