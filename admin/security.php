<?php
/**
 * SMS / OTP settings — phone verification master switch, OTP behaviour,
 * rate limiting and provider configuration. Only the provider currently
 * selected is ever rendered, so credentials from other providers are never
 * loaded into the page. Restricted to the superadmin.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_superadmin();

// Only the pending-route dispatcher defines this. A POST on the ordinary
// /admin/security.php URL can therefore never activate a new Admin URL.
$adminAccessVerifyScreen = defined('SH_ADMIN_ACCESS_VERIFY_SCREEN');
$adminAccessActivated = false;

$errors = [];
$notice = '';
$testCode = null;
$adminAccessInput = '';
$adminAccessSuggestion = '';
$adminAccessState = sh_admin_access_state();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if (str_starts_with($form, 'admin_access_')) {
        if ($form === 'admin_access_suggest') {
            try {
                $adminAccessSuggestion = sh_admin_access_suggest();
                $adminAccessInput = $adminAccessSuggestion;
                $notice = 'A new URL suggestion was generated. It is not saved or active yet.';
            } catch (Throwable $e) {
                sh_log_line('security', 'Admin Access path suggestion failed.');
                $errors[] = 'A secure suggestion could not be generated. Please try again.';
            }
        } elseif ($form === 'admin_access_save') {
            $adminAccessInput = (string)($_POST['admin_access_path'] ?? '');
            $candidate = sh_admin_access_validate_path($adminAccessInput);
            if (empty($candidate['ok'])) {
                $errors[] = (string)($candidate['error'] ?? 'That path is not valid.');
            } elseif (!sh_admin_access_save_pending((string)$candidate['path'])) {
                $errors[] = 'That path is already active or could not be saved. Choose a different path and try again.';
            } else {
                // Issue the one-use same-session ticket now, so the superadmin
                // can copy the complete URL and open it in any tab of this browser.
                sh_admin_access_begin_verification();
                sh_admin_access_mark_changed_audit('pending', (string)$candidate['path']);
                sh_flash('success', 'Saved — verification required. Your current Admin URL is still active.');
                sh_redirect('admin/security.php');
            }
        } elseif ($form === 'admin_access_verify_activate') {
            // Visiting the proposed URL is only a GET and changes nothing. The
            // switch requires this explicit CSRF-protected POST, served from
            // the pending route itself, by an authenticated superadmin whose
            // session still holds the one-use verification ticket.
            if (!$adminAccessVerifyScreen) {
                $errors[] = 'Open the complete proposed Admin URL in this browser before you can verify and activate it.';
            } else {
                $pending = (string)sh_admin_access_state()['pending_path'];
                $ticket = $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] ?? '';
                if ($pending === '' || !is_string($ticket) || $ticket === ''
                    || !hash_equals(hash('sha256', $pending), $ticket)) {
                    $errors[] = 'This verification link is no longer valid. Return to Admin Access and save the route again.';
                } elseif (!sh_admin_access_record_verification($pending, (int)($admin['id'] ?? 0))
                          || !sh_admin_access_apply_pending()) {
                    $errors[] = 'New Admin URL could not be activated. Your current Admin URL is unchanged.';
                } else {
                    sh_admin_access_mark_verified_audit($pending);
                    sh_admin_access_mark_changed_audit('enabled', $pending);
                    $adminAccessActivated = true;
                    $notice = 'New Admin URL is active. The old Admin URL is now disabled.';
                }
            }
        } elseif ($form === 'admin_access_disable') {
            $oldPath = (string)(sh_admin_access_state()['active_path'] ?? '');
            if (!sh_admin_access_disable()) {
                $errors[] = 'The custom admin route could not be disabled. Your current route has not been changed.';
            } else {
                sh_admin_access_mark_changed_audit('disabled', $oldPath);
                sh_flash('success', 'The custom admin route is cleared. Standard admin URLs are active again and still require authentication.');
                sh_redirect('admin/security.php');
            }
        }
        $adminAccessState = sh_admin_access_state();
    }

    // Phone Verification master switch. ON = phone + OTP authentication,
    // OFF = email + password authentication. This is the single source of
    // truth (authentication_mode) — never a second independent toggle.
    if ($form === 'phone_verification') {
        $on = sh_post('phone_verification') === '1';
        $mode = $on ? 'phone_otp' : 'email_password';
        if ($mode !== sh_auth_mode()) {
            sh_setting_save('authentication_mode', $mode);
            sh_security_log('authentication_mode_changed', null, ['mode' => $mode]);
            sh_log_line('admin', 'Phone verification ' . ($on ? 'enabled' : 'disabled') . ' by ' . $admin['email']);
            sh_flash('success', $on
                ? 'Phone verification is now ON. Choose and configure your SMS provider below.'
                : 'Phone verification is now OFF. Customers sign in with email and password.');
        }
        sh_redirect('admin/security.php');
    }

    if ($form === 'browser_protection') {
        // Content Security Policy rollout switch. Report-only is the safe first
        // step on a live store: the browser reports violations to
        // api/csp-report.php without blocking anything. Move to "Enforce" only
        // once Admin → Error Logs shows no CSP violations for normal browsing.
        $cspMode = (string)sh_post('security_csp_mode');
        if (!in_array($cspMode, ['off', 'report-only', 'enforce'], true)) { $cspMode = 'report-only'; }
        $frame = trim((string)sh_post('security_frame_ancestors'));
        if ($frame === '') { $frame = 'self'; }
        $hsts = sh_post('security_hsts') === 'off' ? 'off' : 'on';

        $old = [
            'csp'   => sh_setting('security_csp_mode', 'report-only'),
            'frame' => sh_setting('security_frame_ancestors', 'self'),
            'hsts'  => sh_setting('security_hsts', 'on'),
        ];
        sh_setting_save('security_csp_mode', $cspMode);
        sh_setting_save('security_frame_ancestors', mb_substr($frame, 0, 200));
        sh_setting_save('security_hsts', $hsts);
        sh_audit('security_headers_changed', 'setting', null, 'Browser protection policy', $old, [
            'csp' => $cspMode, 'frame_ancestors' => $frame, 'hsts' => $hsts,
        ]);
        sh_security_log('security_headers_changed', null, ['csp' => $cspMode]);
        sh_flash('success', 'Browser protection policy saved. It applies to the next page load.');
        sh_redirect('admin/security.php');
    }

    if ($form === 'general') {
        $num = [
            'otp_length'           => [4, 10],
            'otp_expiry_minutes'   => [1, 60],
            'otp_max_attempts'     => [1, 20],
            'otp_max_resends'      => [1, 10],
            'otp_resend_cooldown'  => [0, 600],
            'otp_daily_limit'      => [1, 1000],
            'otp_phone_rate_limit' => [1, 100],
            'otp_phone_rate_window'=> [60, 3600],
            'otp_ip_rate_limit'    => [1, 1000],
            'otp_ip_rate_window'   => [60, 86400],
        ];
        foreach ($num as $k => [$min, $max]) {
            $v = (int)($_POST[$k] ?? 0);
            if ($v < $min || $v > $max) { $errors[$k] = 'Value must be between ' . $min . ' and ' . $max . '.'; }
        }
        if (!$errors) {
            foreach ($num as $k => $unused) { sh_setting_save($k, (string)(int)$_POST[$k]); }
            sh_security_log('otp_settings_changed', null, ['section' => 'general']);
            sh_flash('success', 'OTP settings saved.');
            sh_redirect('admin/security.php');
        }
    }

    if ($form === 'sms') {
        $keys = ['otp_provider', 'otp_sender_id', 'otp_message',
                 'otp_api_url', 'otp_api_method', 'otp_api_body', 'otp_api_headers',
                 'otp_api_auth', 'otp_api_key', 'otp_api_secret', 'otp_api_token',
                 'otp_api_username', 'otp_api_password', 'otp_phone_param', 'otp_message_param',
                 'otp_success_field', 'otp_success_value',
                 'otp_textbee_api_key', 'otp_textbee_device_id',
                 'otp_firebase_api_key', 'otp_firebase_sender_id'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $_POST)) {
                sh_setting_save($k, trim((string)$_POST[$k]));
            }
        }
        sh_security_log('otp_settings_changed', null, ['section' => 'sms', 'provider' => sh_post('otp_provider')]);
        sh_flash('success', 'SMS provider configuration saved.');
        sh_redirect('admin/security.php');
    }

    if ($form === 'test') {
        $phone = sh_phone_normalize(sh_post('test_phone'));
        if ($phone === '') {
            $errors['test_phone'] = 'Enter a valid mobile number to send the test to.';
        } else {
            $res = sh_otp_issue($phone, 'test', null);
            if ($res['ok']) {
                $notice = 'Test message sent to ' . sh_phone_display($phone) . ' via ' . sh_otp_provider() . '.';
                if (sh_otp_provider() === 'offline' && !empty($res['code'])) {
                    $testCode = $res['code']; // test driver only — never a real SMS
                }
            } else {
                $errors['test_phone'] = $res['error'] ?? 'The test message could not be sent.';
            }
        }
    }
}

$adminAccessState = sh_admin_access_state();
if ($adminAccessInput === '') { $adminAccessInput = $adminAccessState['pending_path'] ?: ''; }
if ($adminAccessInput === '') {
    try { $adminAccessInput = sh_admin_access_suggest(); } catch (Throwable $e) { $adminAccessInput = ''; }
}
$adminAccessEnabled = (bool)$adminAccessState['enabled'];
$adminAccessPending = (string)$adminAccessState['pending_path'];
// Absolute URLs INCLUDING the domain. Rendered only on this authenticated
// superadmin page — never in storefront HTML, JS, sitemap or public APIs.
$adminAccessActiveFull = $adminAccessEnabled ? sh_admin_access_full_url((string)$adminAccessState['active_path']) : '';
$adminAccessCurrentFull = $adminAccessActiveFull !== '' ? $adminAccessActiveFull : sh_admin_access_standard_url();
$adminAccessPendingFull = $adminAccessPending !== '' ? sh_admin_access_full_url($adminAccessPending) : '';
$adminAccessPreviewFull = sh_admin_access_full_url($adminAccessInput);
$adminAccessOpenPanelFull = $adminAccessActiveFull !== '' ? $adminAccessActiveFull : sh_admin_access_standard_url();
$adminAccessStep2 = ($adminAccessEnabled || $adminAccessPending !== '') ? 'is-done' : 'is-current';
$adminAccessStep3 = $adminAccessEnabled ? 'is-done' : ($adminAccessPending !== '' ? 'is-current' : 'is-todo');
// Re-issue the same-session ticket for a superadmin returning to this page
// (e.g. after signing in again). This is session state only, not activation.
if (!$adminAccessVerifyScreen && $adminAccessPending !== '') { sh_admin_access_begin_verification(); }

$s = static fn(string $k, string $fb = ''): string => (string)sh_setting($k, $fb);

// The provider whose setup fields are rendered. The admin can switch it via
// ?provider= so the page never loads another provider's credentials; on a
// normal load (or refresh) it falls back to the saved provider.
$allowedProviders = ['offline', 'textbee', 'firebase', 'generic_http'];
$provider = sh_get('provider');
if (!in_array($provider, $allowedProviders, true)) {
    $provider = $s('otp_provider', 'offline');
}

$phoneVerificationOn = sh_otp_enabled();

// Quick stats for the top of the page (only needed while verification is on).
$stats = ['today' => 0, 'login' => 0, 'signup' => 0, 'verified' => 0, 'failed' => 0, 'expired' => 0, 'rate_limited' => 0];
if ($phoneVerificationOn) {
    try {
        $stats['today']   = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE created_at >= CURDATE()", [], 0);
        $stats['login']   = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'login' AND created_at >= CURDATE()", [], 0);
        $stats['signup']  = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE purpose = 'signup' AND created_at >= CURDATE()", [], 0);
        $stats['verified']= (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE verified_at IS NOT NULL AND verified_at >= CURDATE()", [], 0);
        $stats['failed']  = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_failed' AND created_at >= CURDATE()", [], 0);
        $stats['expired'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_expired' AND created_at >= CURDATE()", [], 0);
        $stats['rate_limited'] = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE event = 'otp_rate_limited' AND created_at >= CURDATE()", [], 0);
    } catch (Throwable $e) { sh_log_exception($e, 'security-stats'); }
}

$adminPage = 'security';
$adminTitle = $adminAccessVerifyScreen
    ? ($adminAccessActivated ? 'New Admin URL is active' : 'Verify new Admin URL')
    : 'Security & Admin Access';
require __DIR__ . '/_layout.php';
if ($adminAccessVerifyScreen):
?>
<!-- Verification screen served ONLY at the staged URL. A GET here never
     changes state; activation is an explicit, CSRF-protected POST below. -->
<section class="sh-panel" id="admin-access-verify">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> <?= $adminAccessActivated ? 'New Admin URL is active' : 'Verify new Admin URL' ?></h2>
  </div>
  <div class="sh-panel__body">
    <?php if ($errors): ?>
      <div class="sh-alert sh-alert--error" style="margin:0 0 14px"><div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
    <?php endif; ?>
    <?php if ($adminAccessActivated): ?>
      <p class="sh-aau-success">New Admin URL is active</p>
      <div class="sh-aau-block">
        <span class="sh-field__label">Current active Admin URL</span>
        <p class="sh-aau-urlbox sh-code-wrap"><?= e($adminAccessCurrentFull) ?></p>
        <div class="sh-aau-actions">
          <button class="sh-btn sh-btn--ghost" type="button" data-copy="<?= e($adminAccessCurrentFull) ?>"><span data-copy-label>Copy full URL</span></button>
          <a class="sh-btn" href="<?= e($adminAccessOpenPanelFull) ?>"><?= sh_icon('external', 14) ?> Open Admin Panel</a>
        </div>
        <p class="sh-field__hint">The old Admin URL is now disabled. Keep this URL private. Admin login, role permissions and CSRF checks still apply.</p>
      </div>
    <?php else: ?>
      <p class="sh-panel__note">You opened the proposed Admin URL while signed in as a Super Admin. Visiting it does not change anything yet.</p>
      <div class="sh-aau-block">
        <span class="sh-field__label">New proposed Admin URL</span>
        <p class="sh-aau-urlbox sh-code-wrap"><?= e($adminAccessPendingFull) ?></p>
        <form method="post">
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="admin_access_verify_activate">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 14) ?> Verify and activate new Admin URL</button>
        </form>
        <p class="sh-field__hint">Your current Admin URL stays active until you press this button. Only an authenticated Super Admin can activate the new route.</p>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php
    require __DIR__ . '/_footer.php';
    return;
endif;
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php if ($notice !== ''): ?>
  <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?><span><?= e($notice) ?></span></div>
<?php endif; ?>
<?php if ($testCode !== null): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('key', 17) ?>
    <span>Test mode code (shown because the provider is “offline”): <strong style="letter-spacing:2px"><?= e($testCode) ?></strong></span></div>
<?php endif; ?>

<!-- Custom Admin URL: route discovery reduction only; the normal admin login and permissions remain required. -->
<section class="sh-panel" id="admin-access">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> Admin Access URL</h2>
    <?php if ($adminAccessEnabled): ?>
      <span class="sh-badge sh-badge--ok">New Admin URL is active</span>
    <?php elseif ($adminAccessPending !== ''): ?>
      <span class="sh-badge sh-badge--warn">Saved — verification required</span>
    <?php else: ?>
      <span class="sh-badge sh-badge--muted">Standard Admin URL active</span>
    <?php endif; ?>
  </div>
  <div class="sh-panel__body">
    <div class="sh-alert sh-alert--warning" style="margin:0 0 14px">
      <?= sh_icon('alert', 17) ?>
      <span>Changing the Admin URL is <strong>not authentication</strong>. A valid admin session, role permission and CSRF check are still required. Keep the URL private. Your current Admin URL stays active until verification and activation succeed.</span>
    </div>

    <ol class="sh-aau-steps" aria-label="Admin URL setup steps">
      <li class="sh-aau-step is-done"><span class="sh-aau-num">1</span><span class="sh-aau-label">Generate or enter a new URL</span></li>
      <li class="sh-aau-step <?= $adminAccessStep2 ?>"><span class="sh-aau-num">2</span><span class="sh-aau-label">Save proposed route</span></li>
      <li class="sh-aau-step <?= $adminAccessStep3 ?>"><span class="sh-aau-num">3</span><span class="sh-aau-label">Verify and activate</span></li>
    </ol>

    <div class="sh-aau-block">
      <span class="sh-field__label">Current active Admin URL</span>
      <p class="sh-aau-urlbox sh-code-wrap"><?= e($adminAccessCurrentFull) ?></p>
      <div class="sh-aau-actions">
        <button class="sh-btn sh-btn--ghost" type="button" data-copy="<?= e($adminAccessCurrentFull) ?>"><span data-copy-label>Copy full URL</span></button>
        <?php if ($adminAccessEnabled): ?><a class="sh-btn" href="<?= e($adminAccessOpenPanelFull) ?>"><?= sh_icon('external', 14) ?> Open Admin Panel</a><?php endif; ?>
      </div>
      <p class="sh-field__hint"><?= $adminAccessEnabled ? 'This route is active. The previous Admin URL is disabled.' : 'The standard admin route is active. It stays usable until a new route is verified and activated.' ?></p>
    </div>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="admin_access_save">

      <div class="sh-aau-block">
        <p class="sh-aau-kicker">Step 1 · Generate or enter a new URL</p>
        <div class="sh-field">
          <label class="sh-field__label" for="admin-access-path">New path</label>
          <span class="sh-aau-origin"><?= e(sh_admin_access_origin() . rtrim(sh_base_url(), '/')) ?>/</span>
          <input class="sh-input sh-input--mono" id="admin-access-path" name="admin_access_path" value="<?= e($adminAccessInput) ?>" minlength="32" maxlength="64" pattern="[a-zA-Z0-9_-]{32,64}" required autocomplete="off" autocapitalize="none" spellcheck="false">
          <p class="sh-field__hint">One URL-safe segment, 32–64 characters. A secure random suggestion is filled in. Domains, extra slashes, spaces, dots, query strings and encoded characters are rejected server-side.</p>
        </div>
        <?php if ($adminAccessPreviewFull !== ''): ?>
          <div style="margin-top:10px">
            <span class="sh-field__label">New proposed Admin URL</span>
            <p class="sh-aau-urlbox sh-code-wrap"><?= e($adminAccessPreviewFull) ?></p>
            <div class="sh-aau-actions">
              <button class="sh-btn sh-btn--ghost" type="button" data-copy="<?= e($adminAccessPreviewFull) ?>"><span data-copy-label>Copy full URL</span></button>
              <button class="sh-btn sh-btn--ghost" type="submit" form="admin-access-suggest-form"><?= sh_icon('refresh', 14) ?> Generate secure suggestion</button>
            </div>
            <p class="sh-field__hint">The complete URL includes your domain. It is <strong>not active yet</strong> — save it in step 2 first.</p>
          </div>
        <?php else: ?>
          <div class="sh-aau-actions" style="margin-top:10px">
            <button class="sh-btn sh-btn--ghost" type="submit" form="admin-access-suggest-form"><?= sh_icon('refresh', 14) ?> Generate secure suggestion</button>
          </div>
        <?php endif; ?>
      </div>

      <div class="sh-aau-block">
        <p class="sh-aau-kicker">Step 2 · Save proposed route</p>
        <?php if ($adminAccessPending !== ''): ?><p class="sh-aau-status">Saved — verification required</p><?php endif; ?>
        <div class="sh-aau-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check', 14) ?> Save proposed route</button>
        </div>
        <p class="sh-field__hint">Saving only stages the URL. Nothing is activated and the current Admin URL keeps working.</p>
      </div>
    </form>
    <form method="post" id="admin-access-suggest-form" hidden>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="admin_access_suggest">
    </form>

    <div class="sh-aau-block">
      <p class="sh-aau-kicker">Step 3 · Verify and activate</p>
      <?php if ($adminAccessEnabled && $adminAccessPending === ''): ?>
        <p class="sh-aau-success">New Admin URL is active</p>
        <p class="sh-field__hint">You can change it again by generating a new proposal above. The old route is disabled; only the URL shown under “Current active Admin URL” works now.</p>
      <?php elseif ($adminAccessPending !== ''): ?>
        <p class="sh-aau-status">Saved — verification required</p>
        <span class="sh-field__label">New proposed Admin URL</span>
        <p class="sh-aau-urlbox sh-code-wrap"><?= e($adminAccessPendingFull) ?></p>
        <ol class="sh-aau-instructions">
          <li>Copy the complete proposed URL above.</li>
          <li>Open it in this browser while signed in as a Super Admin.</li>
          <li>On the verification screen, press <strong>Verify and activate new Admin URL</strong>.</li>
        </ol>
        <div class="sh-aau-actions">
          <button class="sh-btn sh-btn--ghost" type="button" data-copy="<?= e($adminAccessPendingFull) ?>"><span data-copy-label>Copy full URL</span></button>
          <a class="sh-btn" href="<?= e($adminAccessPendingFull) ?>" target="_blank" rel="noopener noreferrer"><?= sh_icon('external', 14) ?> Open proposed Admin URL</a>
        </div>
        <p class="sh-field__hint">Opening the URL alone does not activate anything. Your current Admin URL stays active until you confirm on that screen.<?= $adminAccessEnabled ? ' The currently active custom URL is disabled only after successful activation.' : '' ?></p>
      <?php else: ?>
        <p class="sh-field__hint">Save a proposed route in step 2 first. Then copy its full URL, open it while signed in and confirm activation there.</p>
      <?php endif; ?>
    </div>

    <?php if ($adminAccessEnabled || $adminAccessPending !== ''): ?>
      <form method="post" style="margin-top:12px" data-confirm="Disable and clear the custom admin route? Standard admin URLs will become active again, but authentication and permissions will still be required.">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="admin_access_disable">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('x', 14) ?> Disable and clear custom route</button>
      </form>
    <?php endif; ?>

    <details style="margin-top:16px">
      <summary class="sh-field__label" style="cursor:pointer">cPanel recovery and rewrite requirements</summary>
      <div class="sh-panel__note" style="margin-top:10px">
        <p>ShopHaat uses the root <code>.htaccess</code> rewrite to reach the dispatcher. On cPanel, keep hidden files visible in File Manager and confirm Apache/LiteSpeed rewrite support and that the directory permits the shipped <code>.htaccess</code> rules (typically <code>AllowOverride All</code>). If the proposed URL returns 404, do not activate it; the current route remains active. Restore the shipped <code>.htaccess</code> from your deployment/backup if rewrite rules were changed.</p>
        <p>If the owner has lost the route and cannot sign in, the hosting account owner can use cPanel phpMyAdmin to disable only this routing feature; this is an out-of-band hosting recovery, not an application backdoor. Run these statements against the ShopHaat database:</p>
        <pre class="sh-code-wrap"><code>UPDATE settings SET setting_value = '0' WHERE setting_key = 'admin_access_enabled';
UPDATE settings SET setting_value = '' WHERE setting_key IN ('admin_access_secret_path', 'admin_access_pending_secret', 'admin_access_verified_secret', 'admin_access_verified_at', 'admin_access_verified_by');</code></pre>
        <p>Afterward, use the standard admin URL and sign in normally. No bypass token or unauthenticated recovery URL is created. The full runbook (including the no-lockout verification sequence and the path rules) ships as <code>docs/ADMIN-ACCESS-URL.md</code> in the release archive; documentation is deliberately not served over HTTP.</p>
      </div>
    </details>
  </div>
</section>

<!-- Phone Verification master switch. It has an explicit Save button: changing
     the checkbox alone never writes anything. -->
<form method="post" novalidate data-confirm="Change the phone verification setting? When it is ON, customers sign in with a mobile number and a one-time code.">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="phone_verification">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Phone Verification</h2>
      <div class="sh-panel__actions">
        <label class="sh-toggle">
          <input type="checkbox" name="phone_verification" value="1"
                 <?= $phoneVerificationOn ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span><strong><?= $phoneVerificationOn ? 'ON' : 'OFF' ?></strong></span>
        </label>
      </div>
    </div>
    <div class="sh-panel__body">
      <?php if ($phoneVerificationOn): ?>
        <p class="sh-panel__note">Phone verification is enabled. Customers sign in with a mobile number and a one-time code.</p>
      <?php else: ?>
        <div class="sh-alert sh-alert--info" style="margin:0"><?= sh_icon('info', 17) ?>
          <span>Phone verification is currently disabled.</span></div>
      <?php endif; ?>
      <div class="sh-actions" style="margin-top:12px">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save phone verification</button>
        <span class="sh-field__hint">Nothing changes until you press this button.</span>
      </div>
    </div>
  </div>
</form>

<!-- Browser protection: security headers and Content Security Policy -->
<?php $cspMode = (string)sh_setting('security_csp_mode', 'report-only'); ?>
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="browser_protection">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Browser protection</h2>
      <span class="sh-badge" style="margin-left:auto">
        <?= $cspMode === 'enforce' ? 'CSP: enforcing' : ($cspMode === 'off' ? 'CSP: off' : 'CSP: report only') ?>
      </span>
    </div>
    <div class="sh-panel__body">
      <p class="sh-panel__note">
        These headers tell every browser how to handle this site. They are already active for
        clickjacking, MIME sniffing and referrer leakage. The Content Security Policy is the part that
        needs tuning, so it starts in <strong>report only</strong> mode: violations are recorded but
        nothing is blocked.
      </p>
      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-csp">Content Security Policy</label>
          <select class="sh-select" id="sc-csp" name="security_csp_mode">
            <option value="report-only" <?= $cspMode === 'report-only' ? 'selected' : '' ?>>Report only (recommended first)</option>
            <option value="enforce" <?= $cspMode === 'enforce' ? 'selected' : '' ?>>Enforce (block violations)</option>
            <option value="off" <?= $cspMode === 'off' ? 'selected' : '' ?>>Off (troubleshooting only)</option>
          </select>
          <span class="sh-field__hint">Check Admin → Error Logs for <code>CSP violation</code> lines before enforcing.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-frame">Pages allowed to embed this site</label>
          <input class="sh-input" id="sc-frame" name="security_frame_ancestors"
                 value="<?= e((string)sh_setting('security_frame_ancestors', 'self')) ?>"
                 placeholder="self">
          <span class="sh-field__hint"><code>self</code> blocks clickjacking. Add an origin only if you embed the storefront elsewhere.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-hsts">HTTPS-only (HSTS)</label>
          <select class="sh-select" id="sc-hsts" name="security_hsts">
            <option value="on" <?= sh_setting('security_hsts', 'on') === 'on' ? 'selected' : '' ?>>On when the site is on HTTPS</option>
            <option value="off" <?= sh_setting('security_hsts', 'off') === 'off' ? 'selected' : '' ?>>Off</option>
          </select>
          <span class="sh-field__hint">Tells browsers to keep using HTTPS for six months. Only applies over HTTPS.</span>
        </div>
      </div>
      <button class="sh-btn" type="submit" style="margin-top:12px"><?= sh_icon('check', 15) ?> Save browser protection</button>
    </div>
  </div>
</form>

<?php if ($phoneVerificationOn): ?>
<div class="sh-stats">
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('message', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['today'] ?></p><p class="sh-stat__label">OTPs sent today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('log-in', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['login'] ?></p><p class="sh-stat__label">Login OTPs</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('user-plus', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['signup'] ?></p><p class="sh-stat__label">Signup OTPs</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('check-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['verified'] ?></p><p class="sh-stat__label">Verified today</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('x-circle', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['failed'] ?></p><p class="sh-stat__label">Failed attempts</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('clock', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['expired'] ?></p><p class="sh-stat__label">Expired</p></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('shield', 18) ?></span>
    <div><p class="sh-stat__value"><?= $stats['rate_limited'] ?></p><p class="sh-stat__label">Rate-limited</p></div></div>
</div>

<!-- OTP behaviour -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="general">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> Code behaviour</h2>
    </div>
    <div class="sh-panel__body">
      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-len">Code length</label>
          <input class="sh-input" id="sc-len" type="number" min="4" max="10" name="otp_length" value="<?= e($s('otp_length', '6')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-exp">Validity (minutes)</label>
          <input class="sh-input" id="sc-exp" type="number" min="1" max="60" name="otp_expiry_minutes" value="<?= e($s('otp_expiry_minutes', '5')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-att">Max attempts</label>
          <input class="sh-input" id="sc-att" type="number" min="1" max="20" name="otp_max_attempts" value="<?= e($s('otp_max_attempts', '5')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-res">Max resends</label>
          <input class="sh-input" id="sc-res" type="number" min="1" max="10" name="otp_max_resends" value="<?= e($s('otp_max_resends', '3')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-cd">Resend cooldown (seconds)</label>
          <input class="sh-input" id="sc-cd" type="number" min="0" max="600" name="otp_resend_cooldown" value="<?= e($s('otp_resend_cooldown', '60')) ?>">
        </div>
      </div>

      <p class="sh-panel__note" style="font-weight:700;color:var(--sh-ink);margin-top:16px">Abuse prevention (rate limits)</p>
      <div class="sh-grid3">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-dl">Daily limit per phone</label>
          <input class="sh-input" id="sc-dl" type="number" min="1" max="1000" name="otp_daily_limit" value="<?= e($s('otp_daily_limit', '20')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pl">Per-phone requests</label>
          <input class="sh-input" id="sc-pl" type="number" min="1" max="100" name="otp_phone_rate_limit" value="<?= e($s('otp_phone_rate_limit', '5')) ?>">
          <span class="sh-field__hint">Requests allowed per window.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-pw">Per-phone window (seconds)</label>
          <input class="sh-input" id="sc-pw" type="number" min="60" max="3600" name="otp_phone_rate_window" value="<?= e($s('otp_phone_rate_window', '60')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-ip">Per-IP requests</label>
          <input class="sh-input" id="sc-ip" type="number" min="1" max="1000" name="otp_ip_rate_limit" value="<?= e($s('otp_ip_rate_limit', '10')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-ipw">Per-IP window (seconds)</label>
          <input class="sh-input" id="sc-ipw" type="number" min="60" max="86400" name="otp_ip_rate_window" value="<?= e($s('otp_ip_rate_window', '60')) ?>">
        </div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
    </div>
  </div>
</form>

<!-- SMS provider -->
<form method="post" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="sms">
  <div class="sh-panel" style="margin-top:14px">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> SMS / OTP Provider</h2>
    </div>
    <div class="sh-panel__body">
      <?php if ($provider === 'offline'): ?>
        <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
          <span><strong>No real SMS provider is selected.</strong> Customers cannot receive verification codes while the
            provider is “Offline / test mode”. Choose TextBee, Firebase or a Custom SMS API below and save your credentials.</span></div>
      <?php endif; ?>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="sc-provider">SMS/OTP Provider</label>
          <select class="sh-select" id="sc-provider" name="otp_provider" data-provider-select>
            <option value="offline" <?= $provider === 'offline' ? 'selected' : '' ?>>Offline / test mode (no real SMS)</option>
            <option value="textbee" <?= $provider === 'textbee' ? 'selected' : '' ?>>TextBee</option>
            <option value="firebase" <?= $provider === 'firebase' ? 'selected' : '' ?>>Firebase</option>
            <option value="generic_http" <?= $provider === 'generic_http' ? 'selected' : '' ?>>Custom SMS API</option>
          </select>
          <span class="sh-field__hint">Only the selected provider's fields are loaded. Credentials stay server-side and are never exposed for other providers.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sc-sender">Sender ID</label>
          <input class="sh-input" id="sc-sender" name="otp_sender_id" maxlength="11" value="<?= e($s('otp_sender_id', 'ShopHaat')) ?>">
        </div>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="sc-msg">Message template</label>
        <textarea class="sh-textarea" id="sc-msg" name="otp_message" rows="2" maxlength="320"><?= e($s('otp_message', 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.')) ?></textarea>
        <span class="sh-field__hint">Placeholders: {code}, {minutes}.</span>
      </div>

      <?php if ($provider === 'textbee'): ?>
        <!-- TextBee setup (rendered only when TextBee is selected) -->
        <div class="sh-grid2" style="margin-top:10px" data-provider-fields="textbee">
          <div class="sh-field">
            <label class="sh-field__label" for="sc-tb-key">TextBee API key</label>
            <input class="sh-input" id="sc-tb-key" name="otp_textbee_api_key" autocomplete="off" value="<?= e($s('otp_textbee_api_key')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-tb-dev">TextBee Device ID</label>
            <input class="sh-input" id="sc-tb-dev" name="otp_textbee_device_id" autocomplete="off" value="<?= e($s('otp_textbee_device_id')) ?>">
          </div>
        </div>
      <?php elseif ($provider === 'firebase'): ?>
        <!-- Firebase setup (rendered only when Firebase is selected) -->
        <div class="sh-grid2" style="margin-top:10px" data-provider-fields="firebase">
          <div class="sh-field">
            <label class="sh-field__label" for="sc-fb-key">Firebase web API key</label>
            <input class="sh-input" id="sc-fb-key" name="otp_firebase_api_key" autocomplete="off" value="<?= e($s('otp_firebase_api_key')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-fb-sender">Firebase sender ID (optional)</label>
            <input class="sh-input" id="sc-fb-sender" name="otp_firebase_sender_id" autocomplete="off" value="<?= e($s('otp_firebase_sender_id')) ?>">
          </div>
        </div>
        <p class="sh-panel__note">Firebase Auth issues its own phone code and cannot carry this store's server-generated OTP — for app-level phone auth only. Use TextBee or a Custom SMS API to deliver your codes.</p>
      <?php elseif ($provider === 'generic_http'): ?>
        <!-- Custom SMS API setup (rendered only when it is selected) -->
        <div style="margin-top:10px" data-provider-fields="generic_http">
          <div class="sh-field">
            <label class="sh-field__label" for="sc-url">API URL</label>
            <input class="sh-input" id="sc-url" name="otp_api_url" value="<?= e($s('otp_api_url')) ?>" placeholder="https://api.example.com/send-sms">
          </div>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="sc-method">Request method</label>
              <select class="sh-select" id="sc-method" name="otp_api_method">
                <option value="post_json" <?= $s('otp_api_method') === 'post_json' ? 'selected' : '' ?>>POST (JSON)</option>
                <option value="post_form" <?= $s('otp_api_method') === 'post_form' ? 'selected' : '' ?>>POST (form fields)</option>
                <option value="get" <?= $s('otp_api_method') === 'get' ? 'selected' : '' ?>>GET (query string)</option>
              </select>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-auth">Auth type</label>
              <select class="sh-select" id="sc-auth" name="otp_api_auth">
                <option value="" <?= $s('otp_api_auth') === '' ? 'selected' : '' ?>>None (via body/headers)</option>
                <option value="bearer" <?= $s('otp_api_auth') === 'bearer' ? 'selected' : '' ?>>Bearer token</option>
                <option value="basic" <?= $s('otp_api_auth') === 'basic' ? 'selected' : '' ?>>HTTP Basic</option>
              </select>
            </div>
          </div>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="sc-key">API key</label>
              <input class="sh-input" id="sc-key" name="otp_api_key" autocomplete="off" value="<?= e($s('otp_api_key')) ?>">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-secret">API secret</label>
              <input class="sh-input" id="sc-secret" name="otp_api_secret" type="password" autocomplete="new-password" value="<?= e($s('otp_api_secret')) ?>">
              <span class="sh-field__hint">Optional — only when your gateway uses one. Stored server-side only.</span>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-token">Bearer token</label>
              <input class="sh-input" id="sc-token" name="otp_api_token" type="password" autocomplete="new-password" value="<?= e($s('otp_api_token')) ?>">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-user">Username</label>
              <input class="sh-input" id="sc-user" name="otp_api_username" autocomplete="off" value="<?= e($s('otp_api_username')) ?>">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-pass">Password</label>
              <input class="sh-input" id="sc-pass" name="otp_api_password" type="password" autocomplete="new-password" value="<?= e($s('otp_api_password')) ?>">
            </div>
          </div>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="sc-pp">Phone param name</label>
              <input class="sh-input" id="sc-pp" name="otp_phone_param" value="<?= e($s('otp_phone_param', 'phone')) ?>">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-mp">Message param name</label>
              <input class="sh-input" id="sc-mp" name="otp_message_param" value="<?= e($s('otp_message_param', 'message')) ?>">
            </div>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="sc-body">Request body template (JSON / form / query)</label>
            <textarea class="sh-textarea" id="sc-body" name="otp_api_body" rows="4"
              placeholder='{"phone":"{phone}","message":"{message}","sender":"{sender}","api_key":"{api_key}"}'><?= e($s('otp_api_body')) ?></textarea>
            <span class="sh-field__hint">Optional. If empty, the phone/message param names above are used. Placeholders: {phone}, {message}, {sender}, {api_key}, {api_secret}, {token}, {username}, {password}.</span>
          </div>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="sc-hdr">Headers (JSON, optional)</label>
              <textarea class="sh-textarea" id="sc-hdr" name="otp_api_headers" rows="2"
                placeholder='{"Content-Type":"application/json"}'><?= e($s('otp_api_headers')) ?></textarea>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="sc-sf">Success check (optional)</label>
              <div class="sh-grid2" style="gap:8px">
                <input class="sh-input" id="sc-sf" name="otp_success_field" placeholder="Field, e.g. status" value="<?= e($s('otp_success_field')) ?>">
                <input class="sh-input" name="otp_success_value" placeholder="Value, e.g. OK" value="<?= e($s('otp_success_value')) ?>">
              </div>
              <span class="sh-field__hint">If set, the provider response must contain this field/value or the code is treated as not sent (no fake successes).</span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <button class="sh-btn" type="submit" style="margin-top:12px"><?= sh_icon('check-circle', 15) ?> Save SMS configuration</button>
    </div>
  </div>
</form>

<!-- Test send -->
<div class="sh-panel" style="margin-top:14px">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> Send a test code</h2>
  </div>
  <div class="sh-panel__body">
    <form method="post" novalidate style="max-width:460px">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="test">
      <div class="sh-field">
        <label class="sh-field__label" for="sc-test">Mobile number</label>
        <input class="sh-input <?= isset($errors['test_phone']) ? 'sh-input--error' : '' ?>" id="sc-test"
               name="test_phone" value="<?= e(sh_post('test_phone')) ?>" placeholder="01XXXXXXXXX">
        <?php if (isset($errors['test_phone'])): ?><p class="sh-field__error"><?= e($errors['test_phone']) ?></p><?php endif; ?>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('send', 15) ?> Send test code</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script<?= sh_csp_nonce_attr() ?>>
(function () {
  // Provider dropdown: switching providers reloads the page so only the
  // newly selected provider's fields (and credentials) are ever rendered.
  var sel = document.querySelector('[data-provider-select]');
  if (sel) {
    sel.addEventListener('change', function () {
      var u = new URL(window.location.href);
      u.searchParams.set('provider', sel.value);
      window.location.href = u.toString();
    });
  }
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
