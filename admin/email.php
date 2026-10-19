<?php
/**
 * Admin — Email & Verification Settings.
 *
 * Professional control center for customer email verification, custom SMTP delivery,
 * Firebase authentication synchronization, DKIM signing, and deliverability diagnostics.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/mail.php';
require_once SH_ROOT . '/includes/firebase.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

$errors = [];
$testResult = null;
$connTestResult = null;
$fbTestResult = null;

sh_email_schema_ensure();
sh_fb_schema_ensure();

/** @return int clamped numeric input */
function sh_email_admin_int(string $key, int $default, int $min, int $max): int
{
    $n = sh_int($_POST[$key] ?? $default);
    return max($min, min($max, $n));
}

// Active setup method selection: 'smtp', 'firebase', or '' (none selected)
$selectedTab = (string)($_GET['tab'] ?? '');
if (!in_array($selectedTab, ['smtp', 'firebase', 'none'], true)) {
    // Default to SMTP if already configured or enabled, otherwise show selector
    $selectedTab = (sh_setting('smtp_enabled', '0') === '1' || (string)sh_setting('smtp_host', '') !== '') ? 'smtp' : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = (string)($_POST['form'] ?? 'smtp_settings');

    if ($form === 'test_smtp_connection') {
        $selectedTab = 'smtp';
        // Socket and TLS/AUTH handshake verification without sending a message.
        $transport = sh_mail_transport();
        $host = trim((string)($_POST['smtp_host'] ?? $transport['host']));
        $port = sh_int($_POST['smtp_port'] ?? $transport['port']);
        $enc  = (string)($_POST['smtp_encryption'] ?? $transport['encryption']);
        $user = trim((string)($_POST['smtp_username'] ?? $transport['username']));
        $pass = (string)($_POST['smtp_password'] ?? '');
        if ($pass === '') { $pass = (string)$transport['password']; }
        $timeout = sh_email_admin_int('smtp_timeout', 20, 5, 60);

        $checkTransport = [
            'host'              => $host,
            'port'              => $port,
            'encryption'        => in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls',
            'username'          => $user,
            'password'          => $pass,
            'timeout'           => $timeout,
            'allow_self_signed' => !empty($_POST['smtp_allow_self_signed']) || !empty($transport['allow_self_signed']),
        ];
        $connTestResult = sh_mail_test_connection($checkTransport);
        sh_setting_save('smtp_last_tested_at', date('Y-m-d H:i:s'));
        sh_setting_save('smtp_last_test_ok', $connTestResult['ok'] ? '1' : '0');
        sh_log_line('admin', 'SMTP connection tested to ' . ($connTestResult['host'] ?? $host) . ':' . ($connTestResult['port'] ?? $port) . ' — ' . ($connTestResult['ok'] ? 'success' : 'failed'));
    } elseif ($form === 'test_mail') {
        $selectedTab = 'smtp';
        // Transmit one real test message through the configured transport.
        $to = trim((string)($_POST['test_recipient'] ?? ''));
        if ($to === '' || !sh_valid_email($to)) {
            $errors['test_recipient'] = 'Enter a valid address to send the test message to.';
        } else {
            $transport = sh_mail_transport();
            $transport['host'] = trim((string)sh_setting('smtp_host', ''));
            $transport['driver'] = ((string)sh_setting('smtp_enabled', '0') === '1' && $transport['host'] !== '') ? 'smtp' : 'mail';
            $testResult = sh_mail_send_test($to, $transport, ['admin_id' => (int)(sh_admin()['id'] ?? 0)]);
            sh_log_line('admin', 'Test email attempted to ' . sh_email_mask($to) . ' via ' . (string)$testResult['transport']
                . ' — ' . (!empty($testResult['ok']) ? 'accepted' : 'failed'));
        }
    } elseif ($form === 'test_firebase') {
        $selectedTab = 'firebase';
        // Harmless Firebase connectivity check.
        $r = sh_fb_call('signInWithPassword', [
            'email' => 'connectivity-check@example.com',
            'password' => bin2hex(random_bytes(8)),
            'returnSecureToken' => true
        ]);
        $code = strtoupper((string)($r['error'] ?? ''));
        if ($r['ok'] || str_starts_with($code, 'EMAIL_NOT_FOUND') || str_starts_with($code, 'INVALID_LOGIN_CREDENTIALS') || str_starts_with($code, 'INVALID_PASSWORD')) {
            $fbTestResult = ['ok' => true, 'msg' => 'Connected to Firebase Authentication. Email/Password sign-in is operational.'];
            sh_setting_save('firebase_last_test_ok', '1');
            sh_setting_save('firebase_last_tested_at', date('Y-m-d H:i:s'));
        } elseif (str_starts_with($code, 'OPERATION_NOT_ALLOWED')) {
            $fbTestResult = ['ok' => false, 'msg' => 'Connected, but Email/Password sign-in is disabled in Firebase Console → Authentication → Sign-in method.'];
            sh_setting_save('firebase_last_test_ok', '0');
            sh_setting_save('firebase_last_tested_at', date('Y-m-d H:i:s'));
        } else {
            $fbTestResult = ['ok' => false, 'msg' => 'Could not reach Firebase: ' . sh_fb_error_text($code)];
            sh_setting_save('firebase_last_test_ok', '0');
            sh_setting_save('firebase_last_tested_at', date('Y-m-d H:i:s'));
        }
    } elseif ($form === 'verification_settings') {
        // Verification Policies
        sh_setting_save('email_verify_enabled', !empty($_POST['email_verify_enabled']) ? '1' : '0');
        sh_setting_save('email_verify_method', 'otp_code');
        sh_setting_save('email_verification_required', !empty($_POST['email_verification_required']) ? '1' : '0');
        sh_setting_save('email_verify_required_login', !empty($_POST['email_verify_required_login']) ? '1' : '0');
        sh_setting_save('email_forgot_enabled', !empty($_POST['email_forgot_enabled']) ? '1' : '0');
        sh_setting_save('email_notify_password_change', !empty($_POST['email_notify_password_change']) ? '1' : '0');
        sh_setting_save('email_otp_on_password_change', !empty($_POST['email_otp_on_password_change']) ? '1' : '0');

        sh_setting_save('email_otp_expiry_minutes', (string)sh_email_admin_int('email_otp_expiry_minutes', 10, 5, 60));
        sh_setting_save('email_otp_max_attempts', (string)sh_email_admin_int('email_otp_max_attempts', 5, 3, 20));
        sh_setting_save('email_otp_max_resends', (string)sh_email_admin_int('email_otp_max_resends', 5, 1, 20));
        sh_setting_save('email_otp_resend_cooldown', (string)sh_email_admin_int('email_otp_resend_cooldown', 60, 30, 900));
        sh_setting_save('email_otp_email_limit', (string)sh_email_admin_int('email_otp_email_limit', 3, 1, 50));
        sh_setting_save('email_otp_ip_limit', (string)sh_email_admin_int('email_otp_ip_limit', 10, 1, 200));

        sh_log_line('admin', 'Email verification settings updated');
        sh_flash('success', 'Email verification settings saved successfully.');
        sh_redirect('admin/email.php?tab=' . urlencode($selectedTab) . '#verification');
    } elseif ($form === 'firebase_settings') {
        $selectedTab = 'firebase';
        $key = trim((string)sh_post('firebase_api_key'));
        if ($key !== '' && !str_contains($key, '•')) {
            if (!preg_match('/^[A-Za-z0-9_\-]{20,80}$/', $key)) {
                $errors['firebase_api_key'] = 'That does not look like a valid Firebase Web API key.';
            } else {
                sh_setting_save('firebase_api_key', sh_verify_encrypt($key));
            }
        }
        if (!empty($_POST['clear_firebase_key'])) {
            sh_setting_save('firebase_api_key', '');
        }

        if (!$errors) {
            sh_setting_save('firebase_project_id', mb_substr(trim((string)sh_post('firebase_project_id')), 0, 80));
            sh_setting_save('firebase_app_id', mb_substr(trim((string)sh_post('firebase_app_id')), 0, 100));
            sh_setting_save('firebase_auth_domain', mb_substr(trim((string)sh_post('firebase_auth_domain')), 0, 120));
            sh_setting_save('firebase_enabled', !empty($_POST['firebase_enabled']) && sh_fb_api_key() !== '' ? '1' : '0');
            sh_setting_save('email_delivery_provider', !empty($_POST['set_primary_provider']) ? 'firebase' : (string)sh_setting('email_delivery_provider', 'smtp'));

            sh_log_line('admin', 'Firebase settings updated');
            sh_flash('success', 'Firebase settings saved successfully.');
            sh_redirect('admin/email.php?tab=firebase');
        }
    } elseif ($form === 'dkim_settings') {
        // DKIM Signing Settings
        sh_setting_save('dkim_enabled', !empty($_POST['dkim_enabled']) ? '1' : '0');
        sh_setting_save('dkim_domain', trim((string)($_POST['dkim_domain'] ?? '')));
        sh_setting_save('dkim_selector', trim((string)($_POST['dkim_selector'] ?? '')));
        sh_setting_save('dkim_private_key_path', trim((string)($_POST['dkim_private_key_path'] ?? '')));
        $dkim = sh_mail_dkim_status();
        sh_log_line('admin', 'DKIM settings updated — ' . ($dkim['ready'] ? 'ready' : 'not usable: ' . $dkim['reason']));
        sh_flash($dkim['ready'] ? 'success' : 'warning', $dkim['ready']
            ? 'DKIM signing is configured. Outgoing mail is signed with selector "' . (string)sh_setting('dkim_selector', '') . '".'
            : 'DKIM settings saved, but signing is not active: ' . $dkim['reason']);
        sh_redirect('admin/email.php?tab=' . urlencode($selectedTab) . '#dkim');
    } else {
        $selectedTab = 'smtp';
        // Custom SMTP Transport Settings
        $pass = (string)($_POST['smtp_password'] ?? '');
        if ($pass !== '') { sh_setting_save('smtp_password', $pass); }
        if (!empty($_POST['clear_password'])) { sh_setting_save('smtp_password', ''); }

        $fromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
        if ($fromEmail !== '' && !sh_valid_email($fromEmail)) {
            $errors['smtp_from_email'] = 'The "from" address is not a valid email address.';
        }
        $replyTo = trim((string)($_POST['smtp_reply_to'] ?? ''));
        if ($replyTo !== '' && !sh_valid_email($replyTo)) {
            $errors['smtp_reply_to'] = 'The reply-to address is not a valid email address.';
        }
        $adminEmail = trim((string)($_POST['admin_notify_email'] ?? ''));
        if ($adminEmail !== '' && !sh_valid_email($adminEmail)) {
            $errors['admin_notify_email'] = 'The admin notification address is not a valid email address.';
        }
        $port = sh_int($_POST['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) { $errors['smtp_port'] = 'The SMTP port must be between 1 and 65535.'; }

        $enc = (string)($_POST['smtp_encryption'] ?? 'tls');
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) { $enc = 'tls'; }

        $rawHost = trim((string)($_POST['smtp_host'] ?? ''));
        $parsedHost = sh_mail_parse_host($rawHost, $port);
        $cleanHost = $parsedHost['host'];
        if ($rawHost !== '' && $cleanHost === '') {
            $errors['smtp_host'] = 'The SMTP host is invalid.';
        }
        if (!empty($parsedHost['port']) && empty($_POST['smtp_port'])) {
            $port = $parsedHost['port'];
        }

        // Port & Encryption compatibility check
        $noticePort = '';
        if ($enc === 'none') {
            $noticePort = 'Plain SMTP sends passwords and email contents unencrypted across the network. Use STARTTLS or SSL/TLS in production.';
        } elseif ($port === 465 && $enc !== 'ssl') {
            $noticePort = 'Port 465 is designed for SSL/TLS (Implicit TLS). Using STARTTLS on port 465 will usually fail.';
        } elseif ($port === 587 && $enc === 'ssl') {
            $noticePort = 'Port 587 is designed for STARTTLS. Using Implicit SSL/TLS on port 587 will usually fail.';
        }

        if (!$errors) {
            sh_setting_save('smtp_enabled', !empty($_POST['smtp_enabled']) ? '1' : '0');
            sh_setting_save('smtp_host', $cleanHost);
            sh_setting_save('smtp_port', (string)$port);
            sh_setting_save('smtp_encryption', $enc);
            sh_setting_save('smtp_username', trim((string)($_POST['smtp_username'] ?? '')));
            sh_setting_save('smtp_from_email', $fromEmail);
            sh_setting_save('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
            sh_setting_save('smtp_reply_to', $replyTo);
            sh_setting_save('smtp_timeout', (string)sh_email_admin_int('smtp_timeout', 20, 5, 60));
            sh_setting_save('admin_notify_email', $adminEmail);
            sh_setting_save('email_enabled', !empty($_POST['email_enabled']) ? '1' : '0');
            sh_setting_save('smtp_allow_self_signed', !empty($_POST['smtp_allow_self_signed']) ? '1' : '0');
            sh_setting_save('email_delivery_provider', !empty($_POST['set_primary_provider']) ? 'smtp' : (string)sh_setting('email_delivery_provider', 'smtp'));

            sh_log_line('admin', 'Email/SMTP settings updated');
            sh_flash('success', 'SMTP settings saved successfully.' . (!empty($noticePort) ? ' ' . $noticePort : ''));
            sh_redirect('admin/email.php?tab=smtp');
        }
    }
}

$smtpEnabled = sh_setting('smtp_enabled', '0') === '1';
$emailEnabled = sh_setting('email_enabled', '0') === '1';
$host = (string)sh_setting('smtp_host', '');
$hasPass = (string)sh_setting('smtp_password', '') !== '';
$adminEmail = (string)sh_setting('admin_notify_email', '');
$primaryProvider = (string)sh_setting('email_delivery_provider', 'smtp');
$fbEnabled = sh_fb_enabled();
$fbKey = sh_fb_api_key();
$fbKeyMask = sh_verify_mask($fbKey);

$mailStatus = sh_mail_service_status();
$mailStats = sh_email_log_stats(7);
$verifier = sh_email_verifier();
$verifyEnabled = sh_setting('email_verify_enabled', '1') === '1';
$checkoutGate = sh_setting('email_verification_required', '0') === '1';
$loginGate = sh_setting('email_verify_required_login', '0') === '1';

// Customer accounts counts
$verifiedCount = (int)sh_val("SELECT COUNT(*) FROM users WHERE email_verified = 1 AND email <> '' AND email NOT LIKE '%@user.shophaat.local'", [], 0);
$unverifiedCount = (int)sh_val("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND email <> '' AND email NOT LIKE '%@user.shophaat.local'", [], 0);
$totalCustomers = $verifiedCount + $unverifiedCount;

// Delivery & connection metrics from real persistence
$totalDelivered = (int)sh_val("SELECT COUNT(*) FROM email_log WHERE status IN ('accepted', 'delivered')", [], 0);
$lastTestedAt = (string)sh_setting('smtp_last_tested_at', '');
$lastTestOk = (string)sh_setting('smtp_last_test_ok', '0') === '1';

// Detailed SMTP configuration state: 'ready', 'incomplete', 'disabled'
$smtpConfigured = ($host !== '' && $hasPass);
$smtpState = 'incomplete';
if (!$smtpConfigured) {
    $smtpState = 'incomplete';
} elseif (!$smtpEnabled) {
    $smtpState = 'disabled';
} elseif ($mailStatus['ready']) {
    $smtpState = 'ready';
}

// Detailed Firebase configuration state
$fbConfigured = ($fbKey !== '');
$fbState = 'incomplete';
if (!$fbConfigured) {
    $fbState = 'incomplete';
} elseif (!$fbEnabled) {
    $fbState = 'disabled';
} else {
    $fbState = 'ready';
}

$adminPage = 'email';
$adminTitle = 'Email & Verification Settings';
require __DIR__ . '/_layout.php';
?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error" style="margin-bottom:16px">
    <?= sh_icon('x-circle', 17) ?>
    <div>
      <strong>Please correct the following:</strong>
      <ul style="margin:4px 0 0 16px;padding:0">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<?php if ($connTestResult !== null): ?>
  <div class="sh-alert <?= $connTestResult['ok'] ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin-bottom:16px">
    <?= sh_icon($connTestResult['ok'] ? 'check-circle' : 'x-circle', 18) ?>
    <div style="flex:1">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <strong><?= $connTestResult['ok'] ? 'SMTP Connection Succeeded' : 'SMTP Connection Failed' ?></strong>
        <span class="sh-chip <?= $connTestResult['ok'] ? 'sh-chip--success' : 'sh-chip--danger' ?>">
          Stage: <?= e($connTestResult['step']) ?>
        </span>
      </div>
      <div style="margin-top:4px">
        <?= e((string)($connTestResult['ok'] ? ($connTestResult['message'] ?? '') : ($connTestResult['error'] ?? ''))) ?>
      </div>
      <?php if (!empty($connTestResult['cause'])): ?>
        <div style="margin-top:6px;font-size:13px;color:var(--sh-heading)">
          <strong>Likely cause:</strong> <?= e($connTestResult['cause']) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($connTestResult['action'])): ?>
        <div style="margin-top:4px;font-size:13px;color:var(--sh-heading)">
          <strong>Recommended action:</strong> <?= e($connTestResult['action']) ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:8px;font-size:12px;opacity:0.85;display:flex;gap:12px;flex-wrap:wrap">
        <span>Target: <code><?= e((string)($connTestResult['host'] ?? '')) ?>:<?= e((string)($connTestResult['port'] ?? '')) ?></code></span>
        <span>Mode: <code><?= e((string)($connTestResult['encryption'] ?? '')) ?></code></span>
        <span>Password: <code>••••••••</code> (masked)</span>
      </div>

      <!-- Stage-by-stage results breakdown -->
      <?php
        $st = $connTestResult['step'];
        $isOk = $connTestResult['ok'];
        $dnsSt = ($st === 'dns') ? 'failed' : 'passed';
        $tcpSt = ($st === 'dns') ? 'skipped' : (($st === 'connect') ? 'failed' : 'passed');
        $ehloSt = in_array($st, ['dns', 'connect'], true) ? 'skipped' : (($st === 'ehlo') ? 'failed' : 'passed');
        $tlsSt = in_array($st, ['dns', 'connect', 'ehlo'], true) ? 'skipped' : (in_array($st, ['starttls', 'tls_cert', 'tls_handshake'], true) ? 'failed' : 'passed');
        $authSt = in_array($st, ['dns', 'connect', 'ehlo', 'starttls', 'tls_cert', 'tls_handshake'], true) ? 'skipped' : (($st === 'auth') ? 'failed' : ($isOk ? 'passed' : 'skipped'));
      ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid rgba(0,0,0,0.08)">
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dnsSt === 'passed' ? '#16a34a' : '#dc2626' ?>;margin-right:4px"></span>
          <strong>DNS:</strong> <?= $dnsSt === 'passed' ? 'Resolved' : 'Failed' ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $tcpSt === 'passed' ? '#16a34a' : ($tcpSt === 'failed' ? '#dc2626' : '#94a3b8') ?>;margin-right:4px"></span>
          <strong>Socket:</strong> <?= $tcpSt === 'passed' ? 'Connected' : ($tcpSt === 'failed' ? 'Refused/Timeout' : 'Pending') ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $ehloSt === 'passed' ? '#16a34a' : ($ehloSt === 'failed' ? '#dc2626' : '#94a3b8') ?>;margin-right:4px"></span>
          <strong>EHLO:</strong> <?= $ehloSt === 'passed' ? 'Greeted' : ($ehloSt === 'failed' ? 'Failed' : 'Pending') ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $tlsSt === 'passed' ? '#16a34a' : ($tlsSt === 'failed' ? '#dc2626' : '#94a3b8') ?>;margin-right:4px"></span>
          <strong>TLS:</strong> <?= $tlsSt === 'passed' ? 'Verified' : ($tlsSt === 'failed' ? 'Failed' : 'Pending') ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $authSt === 'passed' ? '#16a34a' : ($authSt === 'failed' ? '#dc2626' : '#94a3b8') ?>;margin-right:4px"></span>
          <strong>Auth:</strong> <?= $authSt === 'passed' ? 'Verified' : ($authSt === 'failed' ? 'Failed' : 'Pending') ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#94a3b8;margin-right:4px"></span>
          <strong>Delivery:</strong> <em>Not tested</em>
        </div>
      </div>
    </div>
  </div>
  <?php if (!empty($connTestResult['transcript'])): ?>
    <details style="margin-bottom:16px">
      <summary class="sh-panel__note" style="cursor:pointer;font-weight:600">View Connection Diagnostic Transcript (Redacted)</summary>
      <pre style="max-height:220px;overflow:auto;font-size:11.5px;line-height:1.5;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;margin-top:6px"><?= e($connTestResult['transcript']) ?></pre>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if ($testResult !== null): ?>
  <div class="sh-alert <?= !empty($testResult['ok']) ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin-bottom:16px">
    <?= sh_icon(!empty($testResult['ok']) ? 'check-circle' : 'x-circle', 18) ?>
    <div style="flex:1">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <strong><?= !empty($testResult['ok']) ? 'Test Message Accepted by Mail Server' : 'Test Email Delivery Failed' ?></strong>
        <span class="sh-chip <?= !empty($testResult['ok']) ? 'sh-chip--success' : 'sh-chip--danger' ?>">
          Transport: <?= e((string)($testResult['transport'] ?? 'unknown')) ?><?= !empty($testResult['category']) ? ', Category: ' . e((string)$testResult['category']) : '' ?>
        </span>
      </div>
      <div style="margin-top:4px">
        <?= e((string)($testResult['ok'] ? ($testResult['response'] ?? 'Accepted.') : ($testResult['error'] ?? ''))) ?>
      </div>
      <?php if (!empty($testResult['message_id'])): ?><div style="margin-top:4px" class="sh-table__meta">Message-ID: <?= e((string)$testResult['message_id']) ?></div><?php endif; ?>
    </div>
  </div>
  <?php if (!empty($testResult['transcript'])): ?>
    <details style="margin-bottom:16px">
      <summary class="sh-panel__note" style="cursor:pointer;font-weight:600">View SMTP Handshake Transcript (Redacted)</summary>
      <pre style="max-height:220px;overflow:auto;font-size:11.5px;line-height:1.5;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;margin-top:6px"><?= e((string)$testResult['transcript']) ?></pre>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if ($fbTestResult !== null): ?>
  <div class="sh-alert <?= $fbTestResult['ok'] ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin-bottom:16px">
    <?= sh_icon($fbTestResult['ok'] ? 'check-circle' : 'x-circle', 18) ?>
    <div><?= e($fbTestResult['msg']) ?></div>
  </div>
<?php endif; ?>

<!-- Redesigned Compact Settings Summary -->
<div class="sh-summary-bar">
  <!-- Card 1: Email Verification Policy -->
  <div class="sh-summary-chip">
    <div class="sh-summary-chip__lbl">
      <span>Signup Verification</span>
      <span class="sh-badge <?= $verifyEnabled ? 'sh-badge--success' : 'sh-badge--neutral' ?>" style="font-size:10.5px">
        <?= $verifyEnabled ? 'Active' : 'Disabled' ?>
      </span>
    </div>
    <div class="sh-summary-chip__val">
      <?= sh_icon('shield', 15) ?>
      <span>6-Digit Numeric OTP</span>
    </div>
    <div class="sh-summary-chip__meta">
      Expires: <?= (int)sh_email_otp_expiry_minutes() ?>m · Cooldown: <?= (int)sh_email_otp_resend_cooldown() ?>s
    </div>
  </div>

  <!-- Card 2: SMTP Delivery Status -->
  <div class="sh-summary-chip">
    <div class="sh-summary-chip__lbl">
      <span>SMTP Delivery</span>
      <?php if ($smtpState === 'ready'): ?>
        <span class="sh-badge sh-badge--success" style="font-size:10.5px">Ready</span>
      <?php elseif ($smtpState === 'disabled'): ?>
        <span class="sh-badge sh-badge--neutral" style="font-size:10.5px">Disabled</span>
      <?php else: ?>
        <span class="sh-badge sh-badge--warning" style="font-size:10.5px">Incomplete</span>
      <?php endif; ?>
    </div>
    <div class="sh-summary-chip__val">
      <?= sh_icon($smtpConfigured ? 'check-circle' : 'alert', 15) ?>
      <span><?= $smtpConfigured ? 'Configured' : 'Missing Config' ?></span>
    </div>
    <div class="sh-summary-chip__meta">
      <?= $hasPass ? 'password stored' : 'no password stored' ?> · <?= $totalDelivered ?> sent
    </div>
  </div>

  <!-- Card 3: Security Access Gates -->
  <div class="sh-summary-chip">
    <div class="sh-summary-chip__lbl">
      <span>Security Gates</span>
      <span class="sh-badge <?= ($checkoutGate || $loginGate) ? 'sh-badge--info' : 'sh-badge--neutral' ?>" style="font-size:10.5px">
        <?= ($checkoutGate || $loginGate) ? 'Enforced' : 'Optional' ?>
      </span>
    </div>
    <div class="sh-summary-chip__val">
      <?= sh_icon('lock', 15) ?>
      <span><?= $checkoutGate ? 'Checkout Gated' : 'Checkout Open' ?></span>
    </div>
    <div class="sh-summary-chip__meta">
      Sign-in Gate: <?= $loginGate ? 'Enforced' : 'Optional' ?>
    </div>
  </div>

  <!-- Card 4: Customer Accounts Audit -->
  <div class="sh-summary-chip">
    <div class="sh-summary-chip__lbl">
      <span>Customer Accounts</span>
      <span class="sh-badge sh-badge--info" style="font-size:10.5px">
        <?= $verifiedCount ?> / <?= $totalCustomers ?>
      </span>
    </div>
    <div class="sh-summary-chip__val">
      <?= sh_icon('users', 15) ?>
      <span><?= $verifiedCount ?> Verified</span>
    </div>
    <div class="sh-summary-chip__meta">
      <?= $unverifiedCount ?> unverified account(s)
    </div>
  </div>
</div>

<!-- ========================================================================
     SETUP METHOD SELECTION INTERFACE
     ======================================================================== -->
<section class="sh-panel" style="margin-bottom:22px">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('layers', 17) ?> Outgoing Setup Method Selection</h2>
    <span class="sh-badge sh-badge--info">Interactive Configurator</span>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:16px">
      Choose a setup method to configure its parameters. Selecting a method reveals only its dedicated settings panel and documentation guide, keeping the interface clean and focused.
    </p>

    <!-- The Two Selection Cards -->
    <div class="sh-setup-grid" role="tablist" aria-label="Email Delivery Setup Options">
      <!-- Option A: Custom SMTP Server -->
      <a href="?tab=smtp"
         id="btn-select-smtp"
         class="sh-setup-option <?= $selectedTab === 'smtp' ? 'sh-setup-option--active' : '' ?>"
         role="tab"
         aria-selected="<?= $selectedTab === 'smtp' ? 'true' : 'false' ?>"
         aria-controls="panel-smtp"
         tabindex="0">
        <div class="sh-setup-option__top">
          <span class="sh-setup-option__title">
            <span class="sh-setup-option__radio"></span>
            <?= sh_icon('mail', 16) ?> Option A: Custom SMTP Server
          </span>
          <?php if ($smtpState === 'ready'): ?>
            <span class="sh-badge sh-badge--success">Ready</span>
          <?php elseif ($smtpState === 'disabled'): ?>
            <span class="sh-badge sh-badge--neutral">Disabled</span>
          <?php else: ?>
            <span class="sh-badge sh-badge--warning">Incomplete</span>
          <?php endif; ?>
        </div>
        <p class="sh-setup-option__desc">
          Sends transactional emails and automatic 6-digit numeric verification OTPs through your custom SMTP host or transactional email service (Google Workspace, Brevo, SendGrid, Amazon SES, or cPanel).
        </p>
        <div class="sh-setup-option__foot">
          <span style="color:var(--sh-muted)">
            <?= $host !== '' ? 'Host: ' . e($host) : 'Host not set' ?>
          </span>
          <span style="font-weight:700;color:var(--sh-brand)">
            <?= $selectedTab === 'smtp' ? 'Selected &bull;' : 'Configure &rarr;' ?>
          </span>
        </div>
      </a>

      <!-- Option B: Firebase Integration -->
      <a href="?tab=firebase"
         id="btn-select-firebase"
         class="sh-setup-option <?= $selectedTab === 'firebase' ? 'sh-setup-option--active' : '' ?>"
         role="tab"
         aria-selected="<?= $selectedTab === 'firebase' ? 'true' : 'false' ?>"
         aria-controls="panel-firebase"
         tabindex="0">
        <div class="sh-setup-option__top">
          <span class="sh-setup-option__title">
            <span class="sh-setup-option__radio"></span>
            <?= sh_icon('settings', 16) ?> Option B: Firebase Integration
          </span>
          <?php if ($fbState === 'ready'): ?>
            <span class="sh-badge sh-badge--success">Active</span>
          <?php elseif ($fbState === 'disabled'): ?>
            <span class="sh-badge sh-badge--neutral">Disabled</span>
          <?php else: ?>
            <span class="sh-badge sh-badge--warning">Incomplete</span>
          <?php endif; ?>
        </div>
        <p class="sh-setup-option__desc">
          Synchronizes customer accounts with Google Firebase Authentication. Used for Firebase identity management, Google sign-in, and Firebase hosted action links.
        </p>
        <div class="sh-setup-option__foot">
          <span style="color:var(--sh-muted)">
            <?= $fbKey !== '' ? 'API Key set' : 'API Key missing' ?>
          </span>
          <span style="font-weight:700;color:var(--sh-brand)">
            <?= $selectedTab === 'firebase' ? 'Selected &bull;' : 'Configure &rarr;' ?>
          </span>
        </div>
      </a>
    </div>

    <!-- Empty State / Selection Prompt -->
    <div id="no-method-panel" class="sh-alert sh-alert--info" style="display:<?= $selectedTab === '' ? 'flex' : 'none' ?>;margin:0 0 16px">
      <?= sh_icon('info', 16) ?>
      <div>
        <strong>Choose a setup method to configure its settings.</strong> Select either <strong>Custom SMTP Server</strong> or <strong>Firebase Integration</strong> above.
      </div>
    </div>

    <!-- ====================================================================
         PANEL A: CUSTOM SMTP SERVER CONFIGURATION
         ==================================================================== -->
    <div id="panel-smtp" role="tabpanel" aria-labelledby="btn-select-smtp" style="display:<?= $selectedTab === 'smtp' ? 'block' : 'none' ?>">
      <div class="sh-panel" style="border:1px solid var(--sh-line);box-shadow:none;margin-bottom:0">
        <div class="sh-panel__head" style="background:var(--sh-bg-2)">
          <h3 class="sh-panel__title"><?= sh_icon('mail', 16) ?> Custom SMTP Server Configuration</h3>
          <div class="sh-panel__actions">
            <a href="<?= e(sh_url('admin/guides/smtp')) ?>" class="sh-btn sh-btn--sm" style="background:#0f766e;color:#fff;border-color:#0f766e">
              <?= sh_icon('book-open', 14) ?> Open SMTP Setup Guide &rarr;
            </a>
          </div>
        </div>
        <div class="sh-panel__body">
          <div class="sh-alert <?= $mailStatus['ready'] ? 'sh-alert--success' : 'sh-alert--warning' ?>" style="margin-bottom:16px">
            <?= sh_icon($mailStatus['ready'] ? 'check-circle' : 'alert', 16) ?>
            <div>
              <?php if ($mailStatus['ready']): ?>
                SMTP transport is configured: <strong><?= e((string)$mailStatus['host']) ?>:<?= (int)$mailStatus['port'] ?></strong>
                (<?= e(strtoupper((string)$mailStatus['encryption'])) ?>).
              <?php else: ?>
                <strong>Configuration Required:</strong> Missing: <?= e(implode(', ', (array)$mailStatus['missing'])) ?>.
                The store will clearly display when email is not configured instead of pretending codes were delivered.
              <?php endif; ?>
              <br><span class="sh-table__meta">Library: <?= e((string)$mailStatus['library']) ?> · <?= $mailStatus['password_set'] ? 'password stored' : 'no password stored' ?></span>
            </div>
          </div>

          <form method="post" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="smtp_settings">
            <input type="hidden" name="set_primary_provider" value="1">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
              <label class="sh-toggle">
                <input type="checkbox" name="smtp_enabled" value="1" <?= $smtpEnabled ? 'checked' : '' ?>>
                <span class="sh-toggle__track"></span>
                <span style="font-weight:700">Enable Custom SMTP Delivery</span>
              </label>
              <label class="sh-toggle">
                <input type="checkbox" name="email_enabled" value="1" <?= $emailEnabled ? 'checked' : '' ?>>
                <span class="sh-toggle__track"></span>
                <span>Send order notifications by email</span>
              </label>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="em-host">SMTP Host</label>
                <input class="sh-input" id="em-host" name="smtp_host" value="<?= e($host) ?>" placeholder="smtp.yourprovider.com">
                <span class="sh-field__hint">Hostname or IP address (e.g. <code>smtp.gmail.com</code> or <code>smtp-relay.brevo.com</code>).</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="em-port">SMTP Port</label>
                <input class="sh-input" id="em-port" name="smtp_port" type="number" value="<?= e((string)sh_setting('smtp_port', '587')) ?>">
                <span class="sh-field__hint">Standard ports: <code>587</code> (STARTTLS), <code>465</code> (SSL/TLS), <code>25</code> (unencrypted).</span>
              </div>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="em-enc">Encryption</label>
                <select class="sh-select" id="em-enc" name="smtp_encryption">
                  <?php $enc = (string)sh_setting('smtp_encryption', 'tls');
                  foreach (['tls' => 'STARTTLS (recommended, port 587)', 'ssl' => 'SSL/TLS (secure SMTPS, port 465)', 'none' => 'None (unencrypted, port 25)'] as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $enc === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="sh-field__hint">Ensure port and encryption match your provider's requirements.</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="em-user">SMTP Username</label>
                <input class="sh-input" id="em-user" name="smtp_username" value="<?= e((string)sh_setting('smtp_username', '')) ?>" autocomplete="off" placeholder="user@yourdomain.com">
                <span class="sh-field__hint">Your authenticated mailbox address or provider API key username.</span>
              </div>
            </div>

            <div class="sh-field">
              <label class="sh-field__label" for="em-pass">SMTP Password</label>
              <input class="sh-input" id="em-pass" type="password" name="smtp_password" autocomplete="new-password"
                     placeholder="<?= $hasPass ? 'A password is saved — leave blank to keep it' : 'Enter mailbox password or app password' ?>">
              <span class="sh-field__hint">Securely stored and masked. Never exposed in HTML or public APIs.</span>
              <?php if ($hasPass): ?>
                <label class="sh-check" style="margin-top:6px">
                  <input type="checkbox" name="clear_password" value="1">
                  <span>Remove stored SMTP password</span>
                </label>
              <?php endif; ?>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="em-fn">Sender Name</label>
                <input class="sh-input" id="em-fn" name="smtp_from_name" value="<?= e((string)sh_setting('smtp_from_name', (string)sh_setting('site_name', 'ShopHaat'))) ?>">
                <span class="sh-field__hint">Name appearing in customer inboxes (e.g. <code>ShopHaat</code>).</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="em-fe">Sender Email Address</label>
                <input class="sh-input" id="em-fe" type="email" name="smtp_from_email" value="<?= e((string)sh_setting('smtp_from_email', '')) ?>" placeholder="noreply@yourdomain.com">
                <span class="sh-field__hint">Must be an authorized or verified sender address.</span>
              </div>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="em-rt">Reply-To Email Address (Optional)</label>
                <input class="sh-input" id="em-rt" type="email" name="smtp_reply_to" value="<?= e((string)sh_setting('smtp_reply_to', '')) ?>" placeholder="support@yourdomain.com">
                <span class="sh-field__hint">Where customer replies should be delivered.</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="em-timeout">Connection Timeout (seconds)</label>
                <input class="sh-input" id="em-timeout" type="number" min="5" max="60" name="smtp_timeout" value="<?= (int)sh_setting('smtp_timeout', '20') ?>">
                <span class="sh-field__hint">Network timeout (default: 20s, range 5-60s).</span>
              </div>
            </div>

            <div class="sh-field">
              <label class="sh-field__label" for="em-admin">Admin Notification Email</label>
              <input class="sh-input" id="em-admin" type="email" name="admin_notify_email" value="<?= e($adminEmail) ?>" placeholder="orders@yourstore.com">
              <span class="sh-field__hint">Receives new order notifications and critical administrative alerts.</span>
            </div>

            <label class="sh-toggle" style="margin-bottom:16px">
              <input type="checkbox" name="smtp_allow_self_signed" value="1" <?= sh_setting('smtp_allow_self_signed', '0') === '1' ? 'checked' : '' ?>>
              <span class="sh-toggle__track"></span>
              <span>Accept self-signed certificate (internal testing only)</span>
            </label>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save SMTP Settings</button>
            </div>
          </form>

          <hr style="border:0;border-top:1px solid var(--sh-line);margin:20px 0">

          <!-- SMTP Diagnostics & Actions -->
          <h4 style="margin:0 0 12px;font-size:14px;color:var(--sh-heading)"><?= sh_icon('zap', 15) ?> SMTP Diagnostics &amp; Testing Actions</h4>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <!-- Action 1: Run SMTP Connection Test -->
            <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:14px">
              <h5 style="margin:0 0 6px;font-size:13px;color:var(--sh-heading)">Action: Run SMTP Connection Test</h5>
              <p style="font-size:12px;color:var(--sh-muted);margin:0 0 10px">
                Tests network reachability, TLS handshake, and authentication credentials without sending a message.
              </p>
              <form method="post" novalidate>
                <?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="test_smtp_connection">
                <input type="hidden" name="smtp_host" value="<?= e($host) ?>">
                <input type="hidden" name="smtp_port" value="<?= e((string)sh_setting('smtp_port', '587')) ?>">
                <input type="hidden" name="smtp_encryption" value="<?= e((string)sh_setting('smtp_encryption', 'tls')) ?>">
                <input type="hidden" name="smtp_username" value="<?= e((string)sh_setting('smtp_username', '')) ?>">
                <input type="hidden" name="smtp_allow_self_signed" value="<?= sh_setting('smtp_allow_self_signed', '0') === '1' ? '1' : '0' ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" <?= $host === '' ? 'disabled' : '' ?>>
                  <?= sh_icon('zap', 14) ?> Run SMTP Connection Test
                </button>
              </form>
            </div>

            <!-- Action 2: Send Test Email -->
            <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:14px">
              <h5 style="margin:0 0 6px;font-size:13px;color:var(--sh-heading)">Action: Send Test Email</h5>
              <p style="font-size:12px;color:var(--sh-muted);margin:0 0 10px">
                Sends an actual test message and returns the exact server response.
              </p>
              <form method="post" novalidate>
                <?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="test_mail">
                <div style="display:flex;gap:8px">
                  <input class="sh-input" type="email" name="test_recipient" placeholder="test@yourinbox.com"
                         value="<?= e((string)($_POST['test_recipient'] ?? $adminEmail)) ?>" required style="height:36px;font-size:13px">
                  <button class="sh-btn sh-btn--sm" type="submit" <?= $mailStatus['ready'] ? '' : 'disabled' ?> style="flex:none">
                    <?= sh_icon('send', 13) ?> Send Test Email
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ====================================================================
         PANEL B: FIREBASE INTEGRATION CONFIGURATION
         ==================================================================== -->
    <div id="panel-firebase" role="tabpanel" aria-labelledby="btn-select-firebase" style="display:<?= $selectedTab === 'firebase' ? 'block' : 'none' ?>">
      <div class="sh-panel" style="border:1px solid var(--sh-line);box-shadow:none;margin-bottom:0">
        <div class="sh-panel__head" style="background:var(--sh-bg-2)">
          <h3 class="sh-panel__title"><?= sh_icon('settings', 16) ?> Firebase Integration Configuration</h3>
          <div class="sh-panel__actions">
            <a href="<?= e(sh_url('admin/guides/firebase')) ?>" class="sh-btn sh-btn--sm" style="background:#2563eb;color:#fff;border-color:#2563eb">
              <?= sh_icon('book-open', 14) ?> Open Firebase Setup Guide &rarr;
            </a>
          </div>
        </div>
        <div class="sh-panel__body">
          <div class="sh-alert sh-alert--info" style="margin-bottom:16px">
            <?= sh_icon('info', 16) ?>
            <div>
              <strong>Architecture Note:</strong> Firebase standard email verification operates through a <strong>verification link</strong>,
              not a 6-digit numeric OTP code. If Firebase is chosen as the identity provider without custom SMTP, email verification
              will use Firebase's link verification. For automatic 6-digit verification codes, configure Custom SMTP (Option A).
            </div>
          </div>

          <form method="post" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="firebase_settings">
            <input type="hidden" name="set_primary_provider" value="1">

            <label class="sh-toggle" style="margin-bottom:14px">
              <input type="checkbox" name="firebase_enabled" value="1" <?= $fbEnabled ? 'checked' : '' ?>>
              <span class="sh-toggle__track"></span>
              <span style="font-weight:700">Enable Firebase Integration</span>
            </label>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="fb-key">Firebase Web API Key</label>
                <input class="sh-input" id="fb-key" name="firebase_api_key" autocomplete="off" spellcheck="false"
                       value="<?= e($fbKeyMask) ?>" placeholder="AIzaSy…">
                <span class="sh-field__hint">Firebase Console &rarr; Project Settings &rarr; General &rarr; Web API Key. Stored encrypted.</span>
                <?php if ($fbKeyMask !== ''): ?>
                  <label class="sh-check" style="margin-top:6px">
                    <input type="checkbox" name="clear_firebase_key" value="1">
                    <span>Remove stored Firebase Web API Key</span>
                  </label>
                <?php endif; ?>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="fb-proj">Firebase Project ID</label>
                <input class="sh-input" id="fb-proj" name="firebase_project_id" value="<?= e((string)sh_setting('firebase_project_id', '')) ?>" placeholder="my-shop-12345">
                <span class="sh-field__hint">Unique project identifier from Firebase Console.</span>
              </div>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="fb-app">Firebase App ID (Optional)</label>
                <input class="sh-input" id="fb-app" name="firebase_app_id" value="<?= e((string)sh_setting('firebase_app_id', '')) ?>" placeholder="1:1234567890:web:abcdef">
                <span class="sh-field__hint">Web App identifier from Firebase General settings.</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="fb-domain">Auth Domain (Optional)</label>
                <input class="sh-input" id="fb-domain" name="firebase_auth_domain" value="<?= e((string)sh_setting('firebase_auth_domain', '')) ?>" placeholder="my-shop-12345.firebaseapp.com">
                <span class="sh-field__hint">Default auth domain provided by Firebase.</span>
              </div>
            </div>

            <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">
              <p style="margin:0 0 6px"><strong>Authorized Domain:</strong> Add <code><?= e(parse_url(sh_site_url(), PHP_URL_HOST) ?: '') ?></code> to Firebase Console &rarr; Authentication &rarr; Settings &rarr; Authorized domains.</p>
              <p style="margin:0"><strong>Continue Landing URL:</strong> <code><?= e(sh_fb_continue_url()) ?></code></p>
            </div>

            <div style="display:flex;gap:10px;align-items:center">
              <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Firebase Settings</button>
            </div>
          </form>

          <form method="post" style="margin-top:12px" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="test_firebase">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" <?= $fbKeyMask === '' ? 'disabled' : '' ?>>
              <?= sh_icon('zap', 14) ?> Test Firebase Connection
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ========================================================================
     SUBSECTION A: EMAIL VERIFICATION POLICIES & GATES
     ======================================================================== -->
<section class="sh-panel" id="verification" style="margin-bottom:22px">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('shield', 18) ?> Email Verification Policies &amp; Access Gates</h2>
    <span class="sh-badge <?= $verifyEnabled ? 'sh-badge--success' : 'sh-badge--warning' ?>">
      <?= $verifyEnabled ? 'Verification Active' : 'Verification Disabled' ?>
    </span>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:16px">
      Configure verification rules, challenge lifetimes, and access gates for customer accounts.
      Verification codes are 6-digit cryptographically secure numeric tokens, hashed with bcrypt and delivered via email.
    </p>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="verification_settings">

      <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:16px;margin-bottom:18px">
        <label class="sh-toggle" style="margin-bottom:8px">
          <input type="checkbox" name="email_verify_enabled" value="1" <?= $verifyEnabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span style="font-weight:700">Enable signup email verification</span>
        </label>
        <p class="sh-field__hint" style="margin:0 0 14px 28px">
          When active, new registrants are issued an automatic 6-digit numeric verification code and redirected to the verification screen.
        </p>

        <div class="sh-field" style="margin-left:28px">
          <label class="sh-field__label">Verification Method</label>
          <div style="display:flex;gap:12px;align-items:center">
            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13.5px">
              <input type="radio" name="email_verify_method" value="otp_code" checked>
              Six-digit email code (numeric OTP)
            </label>
          </div>
          <span class="sh-field__hint">
            Cryptographically secure 6-digit numeric code sent to the customer's email and verified on the protected endpoint.
          </span>
        </div>
      </div>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="em-exp">Code Expiration Time (minutes)</label>
          <input class="sh-input" id="em-exp" type="number" min="5" max="60" name="email_otp_expiry_minutes"
                 value="<?= (int)sh_email_otp_expiry_minutes() ?>">
          <span class="sh-field__hint">How long a verification code remains valid (default: 10 minutes).</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="em-cool">Resend Cooldown (seconds)</label>
          <input class="sh-input" id="em-cool" type="number" min="30" max="900" name="email_otp_resend_cooldown"
                 value="<?= (int)sh_email_otp_resend_cooldown() ?>">
          <span class="sh-field__hint">Wait time required between code requests (default: 60 seconds). Shows live countdown on storefront.</span>
        </div>
      </div>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="em-att">Maximum Incorrect Attempts</label>
          <input class="sh-input" id="em-att" type="number" min="3" max="20" name="email_otp_max_attempts"
                 value="<?= (int)sh_email_otp_max_attempts() ?>">
          <span class="sh-field__hint">Attempts allowed before a code is revoked (default: 5 attempts).</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="em-resends">Maximum Resends Per Code</label>
          <input class="sh-input" id="em-resends" type="number" min="1" max="20" name="email_otp_max_resends"
                 value="<?= (int)sh_email_otp_max_resends() ?>">
          <span class="sh-field__hint">Maximum fresh codes issued for a single verification session.</span>
        </div>
      </div>

      <hr style="border:0;border-top:1px solid var(--sh-line);margin:18px 0">

      <h4 style="margin:0 0 10px;font-size:14px;color:var(--sh-heading)">Protected Feature Access Gates</h4>
      <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:14px;margin-bottom:18px">
        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="email_verification_required" value="1" <?= $checkoutGate ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span>Require verified email before accessing <strong>checkout</strong></span>
        </label>
        <label class="sh-toggle" style="margin-bottom:8px">
          <input type="checkbox" name="email_verify_required_login" value="1" <?= $loginGate ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span>Require verified email before customer <strong>sign-in</strong></span>
        </label>
        <p class="sh-field__hint" style="margin:0">
          If enabled without functioning email delivery, unverified customers would be blocked. Ensure SMTP is verified and working before enforcing.
        </p>
      </div>

      <h4 style="margin:0 0 10px;font-size:14px;color:var(--sh-heading)">Additional Security Options</h4>
      <div class="sh-grid2" style="margin-bottom:16px">
        <label class="sh-check">
          <input type="checkbox" name="email_forgot_enabled" value="1" <?= sh_setting('email_forgot_enabled', '1') === '1' ? 'checked' : '' ?>>
          <span>Password recovery by emailed 6-digit code</span>
        </label>
        <label class="sh-check">
          <input type="checkbox" name="email_notify_password_change" value="1" <?= sh_setting('email_notify_password_change', '1') === '1' ? 'checked' : '' ?>>
          <span>Email security notice after password change</span>
        </label>
      </div>

      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Verification Settings</button>
    </form>
  </div>
</section>

<!-- ========================================================================
     SUBSECTION C: DKIM SIGNING & DELIVERY LOG
     ======================================================================== -->
<section class="sh-panel" id="dkim" style="margin-bottom:22px">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('shield', 18) ?> DKIM Signing &amp; Delivery Log</h2>
    <div class="sh-panel__actions">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/email-log.php')) ?>"><?= sh_icon('list', 14) ?> View Delivery Log</a>
    </div>
  </div>
  <div class="sh-panel__body">
    <div class="sh-alert <?= $mailStatus['dkim']['ready'] ? 'sh-alert--success' : 'sh-alert--info' ?>" style="margin-bottom:16px">
      <?= sh_icon($mailStatus['dkim']['ready'] ? 'check-circle' : 'info', 16) ?>
      <div><?= e((string)$mailStatus['dkim']['reason']) ?></div>
    </div>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="dkim_settings">
      <label class="sh-toggle" style="margin-bottom:12px">
        <input type="checkbox" name="dkim_enabled" value="1" <?= sh_setting('dkim_enabled', '0') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span>Sign outgoing email with DKIM</span>
      </label>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="em-dkimdom">DKIM Domain</label>
          <input class="sh-input" id="em-dkimdom" name="dkim_domain" value="<?= e((string)sh_setting('dkim_domain', '')) ?>" placeholder="yourdomain.com">
          <span class="sh-field__hint">Your sending domain name.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="em-dkimsel">DKIM Selector</label>
          <input class="sh-input" id="em-dkimsel" name="dkim_selector" value="<?= e((string)sh_setting('dkim_selector', '')) ?>" placeholder="default or mail">
          <span class="sh-field__hint">DNS selector identifying the public key.</span>
        </div>
      </div>

      <div class="sh-field">
        <label class="sh-field__label" for="em-dkimkey">Private Key File Path (Absolute Server Path)</label>
        <input class="sh-input" id="em-dkimkey" name="dkim_private_key_path" value="<?= e((string)sh_setting('dkim_private_key_path', '')) ?>" placeholder="/home/username/dkim/private.key">
        <span class="sh-field__hint">Must reside outside public document root for security.</span>
      </div>

      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save DKIM Settings</button>
    </form>

    <div class="sh-tablewrap" style="margin-top:20px">
      <table class="sh-table">
        <thead>
          <tr>
            <th>Last 7 Days</th>
            <th>Accepted</th>
            <th>Failed</th>
            <th>Skipped</th>
            <th>Delivered</th>
            <th>Bounced</th>
            <th>Complaints</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong><?= (int)$mailStats['total'] ?></strong></td>
            <td><span style="color:#0f766e;font-weight:700"><?= (int)$mailStats['accepted'] ?></span></td>
            <td><span style="color:#b42318;font-weight:700"><?= (int)$mailStats['failed'] ?></span></td>
            <td><?= (int)$mailStats['skipped'] ?></td>
            <td><span style="color:#0f766e"><?= (int)$mailStats['delivered'] ?></span></td>
            <td><span style="color:#b42318"><?= (int)$mailStats['bounced'] ?></span></td>
            <td><?= (int)$mailStats['complaint'] ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- Client-side Tab Switcher Enhancer (Seamless Switching & Keyboard Access) -->
<script<?= sh_csp_nonce_attr() ?>>
(function() {
  var btnSmtp = document.getElementById('btn-select-smtp');
  var btnFb = document.getElementById('btn-select-firebase');
  var panelSmtp = document.getElementById('panel-smtp');
  var panelFb = document.getElementById('panel-firebase');
  var panelNone = document.getElementById('no-method-panel');

  function activateTab(tab, updateUrl) {
    if (tab === 'smtp') {
      if (btnSmtp) {
        btnSmtp.classList.add('sh-setup-option--active');
        btnSmtp.setAttribute('aria-selected', 'true');
      }
      if (btnFb) {
        btnFb.classList.remove('sh-setup-option--active');
        btnFb.setAttribute('aria-selected', 'false');
      }
      if (panelSmtp) panelSmtp.style.display = 'block';
      if (panelFb) panelFb.style.display = 'none';
      if (panelNone) panelNone.style.display = 'none';
      if (updateUrl && window.history && history.replaceState) {
        history.replaceState(null, '', '?tab=smtp');
      }
    } else if (tab === 'firebase') {
      if (btnFb) {
        btnFb.classList.add('sh-setup-option--active');
        btnFb.setAttribute('aria-selected', 'true');
      }
      if (btnSmtp) {
        btnSmtp.classList.remove('sh-setup-option--active');
        btnSmtp.setAttribute('aria-selected', 'false');
      }
      if (panelFb) panelFb.style.display = 'block';
      if (panelSmtp) panelSmtp.style.display = 'none';
      if (panelNone) panelNone.style.display = 'none';
      if (updateUrl && window.history && history.replaceState) {
        history.replaceState(null, '', '?tab=firebase');
      }
    }
  }

  if (btnSmtp) {
    btnSmtp.addEventListener('click', function(e) {
      e.preventDefault();
      activateTab('smtp', true);
    });
    btnSmtp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activateTab('smtp', true);
      }
    });
  }

  if (btnFb) {
    btnFb.addEventListener('click', function(e) {
      e.preventDefault();
      activateTab('firebase', true);
    });
    btnFb.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activateTab('firebase', true);
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
