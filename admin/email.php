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
if (!in_array($selectedTab, ['smtp', 'firebase', 'brevo', 'none'], true)) {
    $selectedTab = '';
}
if ($selectedTab === '') {
    // Default to the transport that is actually selected, otherwise show the selector.
    if (sh_mail_selected_transport() === 'brevo_api') {
        $selectedTab = 'brevo';
    } elseif (sh_setting('smtp_enabled', '0') === '1' || (string)sh_setting('smtp_host', '') !== '') {
        $selectedTab = 'smtp';
    }
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
            'transport'         => 'smtp',
            'host'              => $host,
            'port'              => $port,
            'encryption'        => in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls',
            'username'          => $user,
            'password'          => $pass,
            'timeout'           => $timeout,
        ];
        $connTestResult = sh_mail_test_connection($checkTransport);
        sh_setting_save('smtp_last_tested_at', date('Y-m-d H:i:s'));
        sh_setting_save('smtp_last_test_ok', $connTestResult['ok'] ? '1' : '0');
        sh_log_line('admin', 'SMTP connection tested to ' . ($connTestResult['host'] ?? $host) . ':' . ($connTestResult['port'] ?? $port) . ' — ' . ($connTestResult['ok'] ? 'success' : 'failed') . ' — step ' . (string)($connTestResult['step'] ?? '?'));
    } elseif ($form === 'test_brevo_api') {
        $selectedTab = 'brevo';
        $connTestResult = sh_mail_test_connection(['transport' => 'brevo_api', 'timeout' => sh_email_admin_int('smtp_timeout', 20, 5, 60)]);
        sh_setting_save('brevo_api_last_tested_at', date('Y-m-d H:i:s'));
        sh_setting_save('brevo_api_last_test_ok', !empty($connTestResult['ok']) ? '1' : '0');
        sh_log_line('admin', 'Brevo API connection tested — ' . (!empty($connTestResult['ok']) ? 'success' : 'failed') . ' — step ' . (string)($connTestResult['step'] ?? '?'));
    } else if ($form === 'brevo_api_settings') {
        $selectedTab = 'brevo';
        $apiKey = trim((string)($_POST['brevo_api_key'] ?? ''));
        // null means "keep the stored key". Nothing is written until every
        // field on this form has validated, so a bad sender address cannot
        // leave a half-saved configuration behind.
        $newApiKey = null;
        if (!empty($_POST['clear_brevo_key'])) {
            $newApiKey = '';
        } elseif ($apiKey !== '' && !str_contains($apiKey, '•')) {
            if (str_starts_with($apiKey, 'xsmtpsib-')) {
                $errors['brevo_api_key'] = 'That is a Brevo SMTP key. The HTTPS API needs a v3 API key (it starts with xkeysib-). The two credentials stay separate.';
            } elseif (preg_match('~^[A-Za-z0-9_.-]{20,200}$~', $apiKey) !== 1) {
                $errors['brevo_api_key'] = 'The API key contains unexpected characters. Paste the v3 API key exactly as Brevo shows it.';
            } else {
                $newApiKey = sh_verify_encrypt($apiKey);
                if (!str_starts_with($apiKey, 'xkeysib-')) {
                    sh_flash('warning', 'The key was saved, but it does not start with xkeysib-. Only Brevo can confirm whether it is a v3 API key; run the diagnostics after saving.');
                }
            }
        }
        $apiFrom = trim((string)($_POST['smtp_from_email'] ?? ''));
        if ($apiFrom !== '' && !sh_valid_email($apiFrom)) {
            $errors['smtp_from_email'] = 'The sender email address is not a valid email address.';
        }
        $apiReply = trim((string)($_POST['smtp_reply_to'] ?? ''));
        if ($apiReply !== '' && !sh_valid_email($apiReply)) {
            $errors['smtp_reply_to'] = 'The reply-to address is not a valid email address.';
        }
        if (!$errors) {
            if ($newApiKey !== null) {
                sh_setting_save('brevo_api_key', $newApiKey);
            }
            sh_setting_save('brevo_api_enabled', !empty($_POST['brevo_api_enabled']) ? '1' : '0');
            sh_setting_save('smtp_from_email', $apiFrom);
            sh_setting_save('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
            sh_setting_save('smtp_reply_to', $apiReply);
            sh_setting_save('smtp_timeout', (string)sh_email_admin_int('smtp_timeout', 20, 5, 60));
            sh_log_line('admin', 'Brevo API settings updated');
            sh_flash('success', 'Brevo API settings saved. The delivery transport was not changed automatically.');
            sh_redirect('admin/email.php?tab=brevo');
        }
    } elseif ($form === 'use_transport') {
        $which = (string)($_POST['transport'] ?? '');
        if ($which === 'brevo_api') {
            if (sh_setting('brevo_api_enabled', '0') !== '1') {
                sh_flash('error', 'Enable the Brevo API transport before selecting it for delivery.');
            } elseif (!sh_brevo_api_key_set()) {
                sh_flash('error', 'Store a Brevo v3 API key before selecting the API transport.');
            } else {
                sh_setting_save('mail_transport', 'brevo_api');
                sh_setting_save('email_delivery_provider', 'smtp');
                sh_log_line('admin', 'Outgoing mail transport switched to Brevo HTTPS API');
                sh_flash('success', 'Brevo HTTPS API is now the delivery transport. Send a test email to confirm it before relying on it.');
            }
        } elseif ($which === 'smtp') {
            if ((string)sh_setting('smtp_host', '') === '') {
                sh_flash('error', 'Configure an SMTP host before selecting the SMTP transport.');
            } else {
                sh_setting_save('mail_transport', 'smtp');
                sh_setting_save('email_delivery_provider', 'smtp');
                sh_log_line('admin', 'Outgoing mail transport switched to SMTP');
                sh_flash('success', 'SMTP is now the delivery transport. Run the connection test and send a test email.');
            }
        }
        sh_redirect('admin/email.php?tab=' . ($which === 'brevo_api' ? 'brevo' : 'smtp'));
    } elseif ($form === 'test_mail') {
        $selectedTab = sh_mail_selected_transport() === 'brevo_api' ? 'brevo' : 'smtp';
        // Transmit one real test message through the configured transport.
        $to = trim((string)($_POST['test_recipient'] ?? ''));
        if ($to === '' || !sh_valid_email($to)) {
            $errors['test_recipient'] = 'Enter a valid address to send the test message to.';
        } else {
            $transport = sh_mail_transport();
            $testResult = sh_mail_send_test($to, $transport, ['admin_id' => (int)(sh_admin()['id'] ?? 0)]);
            $viaApi = (string)($testResult['transport'] ?? '') === 'brevo_api';
            if ($viaApi) {
                sh_setting_save('brevo_api_last_test_ok', !empty($testResult['ok']) ? '1' : '0');
                sh_setting_save('brevo_api_last_tested_at', date('Y-m-d H:i:s'));
                // Persist only non-secret outcome data so the Transactional
                // Email row still shows the real result after a page refresh.
                sh_setting_save('brevo_api_last_send_at', date('Y-m-d H:i:s'));
                sh_setting_save('brevo_api_last_send_status', (string)(int)($testResult['http_status'] ?? 0));
                sh_setting_save('brevo_api_last_send_message_id', (string)($testResult['message_id'] ?? ''));
                sh_setting_save('brevo_api_last_send_error', empty($testResult['ok'])
                    ? mb_substr((string)($testResult['error'] ?? ''), 0, 300)
                    : '');
                sh_setting_save('brevo_api_last_send_fields', implode(', ', array_slice((array)($testResult['fields'] ?? []), 0, 3)));
                if (!empty($testResult['ok'])) {
                    // Only an accepted real send sets this; a connection test or
                    // a read-only diagnostic can never claim delivery works.
                    sh_setting_save('brevo_api_verified_at', date('Y-m-d H:i:s'));
                    if (empty($testResult['message_id'])) {
                        sh_setting_save('brevo_api_verified_note', 'accepted without a messageId');
                    } else {
                        sh_setting_save('brevo_api_verified_note', 'messageId ' . (string)$testResult['message_id']);
                    }
                } elseif (in_array((string)($testResult['category'] ?? ''), ['auth', 'permission', 'credential', 'billing', 'not_configured'], true)) {
                    // A refusal invalidates the earlier proof; a temporary
                    // network or rate-limit failure does not.
                    sh_setting_save('brevo_api_verified_at', '');
                    sh_setting_save('brevo_api_verified_note', '');
                }
            } else {
                sh_setting_save('smtp_last_tested_at', date('Y-m-d H:i:s'));
                sh_setting_save('smtp_last_test_ok', !empty($testResult['ok']) ? '1' : '0');
            }
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
            // Certificate verification is not optional in this application.
            sh_setting_save('smtp_allow_self_signed', '0');
            sh_setting_save('email_delivery_provider', !empty($_POST['set_primary_provider']) ? 'smtp' : (string)sh_setting('email_delivery_provider', 'smtp'));

            sh_log_line('admin', 'Email/SMTP settings updated');
            sh_flash('success', 'SMTP settings saved successfully.' . (!empty($noticePort) ? ' ' . $noticePort : ''));
            sh_redirect('admin/email.php?tab=smtp');
        }
    }
}

$smtpEnabled = sh_setting('smtp_enabled', '0') === '1';
$emailEnabled = sh_setting('email_enabled', '0') === '1';
$selectedTransport = sh_mail_selected_transport();
$brevoApiEnabled = sh_setting('brevo_api_enabled', '0') === '1';
$brevoKeySet = sh_brevo_api_key_set();
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
$brevoLastTestedAt = (string)sh_setting('brevo_api_last_tested_at', '');
$brevoLastTestOk = (string)sh_setting('brevo_api_last_test_ok', '0') === '1';
// Only a real accepted send sets this; configuration alone is not "ready".
$brevoVerifiedAt = (string)sh_setting('brevo_api_verified_at', '');
$brevoVerified = $brevoVerifiedAt !== '';

// Detailed Brevo API state: 'ready' (delivering), 'configured' (enabled and
// keyed but SMTP is still the active transport), 'disabled' (key but switch
// off), 'incomplete' (no key).
$brevoState = 'incomplete';
if ($brevoKeySet) {
    if (!$brevoApiEnabled) {
        $brevoState = 'disabled';
    } elseif ($selectedTransport === 'brevo_api') {
        $brevoState = 'ready';
    } else {
        $brevoState = 'configured';
    }
}

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
  <?php
    $isApiTest = (string)($connTestResult['transport'] ?? '') === 'brevo_api';
    $st = (string)($connTestResult['step'] ?? '');
    $isOk = !empty($connTestResult['ok']);
    $apiChecks = is_array($connTestResult['checks'] ?? null) ? (array)$connTestResult['checks'] : [];
    $apiVerdict = (string)($connTestResult['verdict'] ?? '');
    $apiAcctCheck = is_array($apiChecks['account'] ?? null) ? $apiChecks['account'] : null;
    $apiTlsCheck = is_array($apiChecks['tls'] ?? null) ? $apiChecks['tls'] : null;

    // A 403 on the account resource is neither a success nor an authentication
    // failure: the key authenticated but that resource is out of scope. It is
    // shown as an informational amber result, never as "the API is broken".
    $apiAcctDenied = $isApiTest && $apiVerdict === 'account_denied';
    $apiOk = $isApiTest && ($apiVerdict === 'reachable' || $apiVerdict === 'account_denied');

    // Test C is the real POST /v3/smtp/email, which is only run by the
    // administrator's Send Test Email action. Its persisted outcome is shown
    // here so the row survives a refresh without ever sending on page load.
    $lastSendAt = (string)sh_setting('brevo_api_last_send_at', '');
    $lastSendStatus = (int)sh_setting('brevo_api_last_send_status', 0);
    $lastSendMessageId = (string)sh_setting('brevo_api_last_send_message_id', '');
    $lastSendError = (string)sh_setting('brevo_api_last_send_error', '');
    $lastSendFields = (string)sh_setting('brevo_api_last_send_fields', '');
    $lastSendOk = $lastSendAt !== '' && $lastSendStatus >= 200 && $lastSendStatus < 300;
    $txCheck = [
        'key' => 'transactional',
        'label' => 'Test C — Transactional email (real POST /v3/smtp/email)',
        'status' => $lastSendAt === '' ? 'not_tested' : ($lastSendOk ? 'passed' : 'failed'),
        'http_status' => $lastSendStatus > 0 ? $lastSendStatus : 0,
        'endpoint' => 'POST /v3/smtp/email',
        'detail' => $lastSendAt === ''
            ? 'Not run. This check sends a real message, so it only runs when you press Send Test Email.'
            : 'Last attempt ' . $lastSendAt . '. ' . ($lastSendOk
                ? 'Brevo accepted the request (HTTP ' . $lastSendStatus . '). Acceptance is not inbox delivery — confirm it in the recipient mailbox or Brevo → Transactional → Logs / Events.'
                : 'Brevo refused or the request failed: ' . ($lastSendError !== '' ? $lastSendError : 'no detail was recorded.')),
        'message_id' => $lastSendMessageId,
        'fields' => $lastSendFields,
        'action' => $lastSendAt === ''
            ? 'Use Send Test Email below with a mailbox you control. Brevo must answer 201 with a messageId.'
            : '',
    ];

    $pending = ['config', 'library', 'environment'];
    $stateOf = static function (?array $check): string {
        if ($check === null) { return 'pending'; }
        return match ((string)($check['status'] ?? 'pending')) {
            'passed' => 'passed',
            'failed', 'invalid_key', 'unreachable' => 'failed',
            'permission_denied', 'payment_required', 'rate_limited', 'not_configured' => 'warn',
            'skipped' => 'skipped',
            default => 'pending',
        };
    };
    if ($isApiTest) {
        $dnsSt = empty($connTestResult['resolved_ips']) && in_array($st, ['dns'], true) ? 'failed' : 'passed';
        $tcpSt = $apiTlsCheck === null ? 'pending' : $stateOf($apiTlsCheck);
        $ehloSt = $apiTlsCheck === null ? 'pending' : $stateOf($apiTlsCheck);
        $tlsSt = $apiTlsCheck === null ? 'pending' : $stateOf($apiTlsCheck);
        $authSt = $apiAcctCheck === null ? 'pending' : $stateOf($apiAcctCheck);
        $sendSt = $lastSendAt === '' ? 'pending' : ($lastSendOk ? 'passed' : 'failed');
    } else {
        $dnsSt = in_array($st, $pending, true) ? 'pending' : ($st === 'dns' ? 'failed' : 'passed');
        $tcpSt = in_array($st, array_merge($pending, ['dns']), true) ? 'pending' : ($st === 'connect' ? 'failed' : 'passed');
        $ehloSt = in_array($st, array_merge($pending, ['dns', 'connect']), true) ? 'pending'
            : (in_array($st, ['ehlo', 'ehlo_tls'], true) ? 'failed' : 'passed');
        $tlsSt = in_array($st, array_merge($pending, ['dns', 'connect']), true) ? 'pending'
            : (in_array($st, ['starttls', 'tls_hostname', 'tls_cert', 'tls_handshake', 'tls'], true) ? 'failed' : 'passed');
        $authSt = in_array($st, array_merge($pending, ['dns', 'connect', 'ehlo', 'starttls', 'tls_hostname', 'tls_cert', 'tls_handshake', 'tls', 'ehlo_tls']), true) ? 'pending'
            : ($st === 'auth' ? 'failed' : ($isOk ? 'passed' : 'skipped'));
    }
    $cert = is_array($connTestResult['certificate'] ?? null) ? $connTestResult['certificate'] : null;
    $dot = static function (string $state): string {
        return match ($state) {
            'passed' => '#16a34a',
            'warn' => '#d97706',
            'failed' => '#dc2626',
            default => '#94a3b8',
        };
    };
    $label = static function (string $state, string $passed, string $failed, string $pendingText = 'Pending', string $warnText = 'Limited'): string {
        return match ($state) {
            'passed' => $passed,
            'failed' => $failed,
            'warn' => $warnText,
            'skipped' => 'Not reached',
            default => $pendingText,
        };
    };
  ?>
  <div class="sh-alert <?= $isApiTest ? ($apiOk ? ($apiVerdict === 'account_denied' ? 'sh-alert--warning' : 'sh-alert--success') : 'sh-alert--error') : ($isOk ? 'sh-alert--success' : 'sh-alert--error') ?>" style="margin-bottom:16px">
    <?= sh_icon($isApiTest ? ($apiOk ? ($apiVerdict === 'account_denied' ? 'alert' : 'check-circle') : 'x-circle') : ($isOk ? 'check-circle' : 'x-circle'), 18) ?>
    <div style="flex:1">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <strong><?php
          if ($isApiTest) {
              if ($apiVerdict === 'reachable') { echo 'Brevo HTTPS API reachable — credential accepted for account access'; }
              elseif ($apiVerdict === 'account_denied') { echo 'Brevo HTTPS API reachable — account access denied (informational)'; }
              elseif ($apiVerdict === 'invalid_credentials') { echo 'Brevo API key was not accepted'; }
              elseif ($apiVerdict === 'unreachable') { echo 'Brevo HTTPS API is not reachable'; }
              elseif ($apiVerdict === 'incomplete') { echo 'Brevo API credential is not usable'; }
              else { echo 'Brevo API diagnostic reported a provider or platform problem'; }
          } else {
              echo $isOk ? 'SMTP Connection Succeeded' : 'SMTP Connection Failed';
          }
        ?></strong>
        <span class="sh-chip <?= $isApiTest ? ($apiOk ? ($apiVerdict === 'account_denied' ? 'sh-chip--warning' : 'sh-chip--success') : 'sh-chip--danger') : ($isOk ? 'sh-chip--success' : 'sh-chip--danger') ?>">
          <?= $isApiTest ? 'Verdict: ' . e($apiVerdict !== '' ? $apiVerdict : $st) : 'Failed/succeeded stage: ' . e($st) ?>
        </span>
      </div>
      <div style="margin-top:4px">
        <?= e((string)(($isApiTest ? $apiOk : $isOk) ? ($connTestResult['message'] ?? '') : ($connTestResult['error'] ?? ''))) ?>
      </div>
      <?php if ($isApiTest && !$apiOk && !empty($connTestResult['error'])): ?>
        <div style="margin-top:4px;font-size:13px"><?= e((string)$connTestResult['error']) ?></div>
      <?php endif; ?>
      <?php if (!empty($connTestResult['cause'])): ?>
        <div style="margin-top:6px;font-size:13px;color:var(--sh-heading)">
          <strong>Confirmed cause:</strong> <?= e((string)$connTestResult['cause']) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($connTestResult['action'])): ?>
        <div style="margin-top:4px;font-size:13px;color:var(--sh-heading)">
          <strong>Recommended action:</strong> <?= e((string)$connTestResult['action']) ?>
        </div>
      <?php endif; ?>
      <div style="margin-top:8px;font-size:12px;opacity:0.85;display:flex;gap:12px;flex-wrap:wrap">
        <span>Target: <code><?= e((string)($connTestResult['host'] ?? '')) ?>:<?= e((string)($connTestResult['port'] ?? '')) ?></code></span>
        <span>Mode: <code><?= e((string)($connTestResult['encryption'] ?? '')) ?></code></span>
        <?php
          $dnsIps = array_filter((array)($connTestResult['resolved_ips'] ?? []), 'is_string');
          $peerShown = trim((string)($cert['peer_ip'] ?? ''));
          if ($peerShown === '') { $peerShown = trim((string)($connTestResult['peer_ip_resolved'] ?? '')); }
        ?>
        <span>DNS: <code><?= $dnsIps !== [] ? e(implode(', ', $dnsIps)) : 'no address resolved' ?></code></span>
        <span>Remote endpoint: <code><?= e($peerShown !== '' ? $peerShown : 'not connected') ?>:<?= (int)($connTestResult['port'] ?? 0) ?></code></span>
        <span>Credential: <code>••••••••</code> (masked)</span>
      </div>

      <!-- Stage-by-stage results breakdown -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid rgba(0,0,0,0.08)">
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($dnsSt) ?>;margin-right:4px"></span>
          <strong>DNS:</strong> <?= e($label($dnsSt, 'Resolved', 'Failed')) ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($tcpSt) ?>;margin-right:4px"></span>
          <strong>Socket:</strong> <?= e($label($tcpSt, 'Connected', 'Refused/Timeout')) ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($ehloSt) ?>;margin-right:4px"></span>
          <strong><?= $isApiTest ? 'HTTPS:' : 'EHLO:' ?></strong> <?= e($label($ehloSt, $isApiTest ? 'Reached' : 'Greeted', 'Failed')) ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($tlsSt) ?>;margin-right:4px"></span>
          <strong>TLS:</strong> <?= e($label($tlsSt, 'Verified', 'Failed')) ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($authSt) ?>;margin-right:4px"></span>
          <strong><?= $isApiTest ? 'Account API:' : 'Auth:' ?></strong> <?= e($isApiTest ? $label($authSt, 'Accessible', 'Rejected', 'Pending', (string)($apiAcctCheck['status'] ?? '') === 'permission_denied' ? 'Denied (informational)' : 'Limited') : $label($authSt, 'Verified', 'Failed')) ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $isApiTest ? $dot($sendSt) : '#94a3b8' ?>;margin-right:4px"></span>
          <strong>Transactional email:</strong>
          <?php if (!$isApiTest): ?>
            <em>Use Send Test Email</em>
          <?php elseif ($lastSendAt === ''): ?>
            <em>Not tested</em>
          <?php elseif ($lastSendOk): ?>
            Accepted (HTTP <?= (int)$lastSendStatus ?>)
          <?php else: ?>
            Failed (HTTP <?= (int)$lastSendStatus ?>)
          <?php endif; ?>
        </div>
        <div style="font-size:11.5px">
          <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#94a3b8;margin-right:4px"></span>
          <strong>Delivery:</strong>
          <em><?= $lastSendOk ? 'Unconfirmed — confirm in the inbox or Brevo logs' : 'No accepted send yet' ?></em>
        </div>
      </div>

      <?php if ($isApiTest): ?>
        <div style="margin-top:12px;display:grid;gap:8px">
          <?php foreach ([['A', $apiTlsCheck], ['B', $apiAcctCheck], ['C', $txCheck]] as $row): ?>
            <?php
              $check = $row[1];
              if (!is_array($check)) { continue; }
              $cSt = ($check['status'] ?? '') === 'not_tested' ? 'pending' : $stateOf($check);
              $http = (int)($check['http_status'] ?? 0);
              $prov = is_array($check['provider'] ?? null) ? $check['provider'] : null;
            ?>
            <div style="border:1px solid var(--sh-line);border-radius:6px;padding:9px 11px;background:var(--sh-bg-2);font-size:12.5px">
              <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $dot($cSt) ?>"></span>
                <strong><?= e((string)($check['label'] ?? $row[0])) ?></strong>
                <?php if (!empty($check['endpoint'])): ?><code style="font-size:11.5px"><?= e((string)$check['endpoint']) ?></code><?php endif; ?>
                <span class="sh-chip sh-chip--<?= $cSt === 'passed' ? 'success' : ($cSt === 'warn' ? 'warning' : ($cSt === 'failed' ? 'danger' : 'neutral')) ?>">
                  <?= $http > 0 ? 'HTTP ' . $http . ' · ' : '' ?><?= e((string)($check['status'] ?? 'pending')) ?>
                </span>
              </div>
              <?php if (!empty($check['detail'])): ?><div style="margin-top:4px"><?= e((string)$check['detail']) ?></div><?php endif; ?>
              <?php if (!empty($check['message_id'])): ?>
                <div style="margin-top:4px"><strong>Message ID:</strong> <code><?= e((string)$check['message_id']) ?></code></div>
              <?php endif; ?>
              <?php if (!empty($check['fields'])): ?>
                <div style="margin-top:4px"><strong>Field Brevo objected to:</strong> <code><?= e((string)$check['fields']) ?></code></div>
              <?php endif; ?>
              <?php if ($prov !== null && !empty($prov['detail'])): ?>
                <div style="margin-top:4px;font-size:11.5px;color:var(--sh-muted)">Brevo said: <code><?= e((string)$prov['detail']) ?></code></div>
              <?php endif; ?>
              <?php if (!empty($check['action'])): ?>
                <div style="margin-top:4px;font-size:11.5px"><strong>What to do:</strong> <?= e((string)$check['action']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <div style="font-size:11.5px;color:var(--sh-muted)">
            Test A and Test B never send mail. Test C is the documented transactional endpoint and only runs from
            the explicit <em>Send Test Email</em> action below. Brevo documents 401 as an unknown or missing key and
            403 as a key that authenticated but is not permitted for that resource — the two are reported separately,
            and a 403 on the account resource is not treated as a broken integration. Acceptance (201) is not inbox
            delivery; confirm that in the recipient's mailbox or Brevo's transaction log.
            <a href="https://developers.brevo.com/docs/api-key-authentication" target="_blank" rel="noopener">API key authentication</a> ·
            <a href="https://developers.brevo.com/reference/sendtransacemail" target="_blank" rel="noopener">Send a transactional email</a> ·
            <a href="https://help.brevo.com/hc/en-us/articles/209467485-Create-and-manage-your-API-keys" target="_blank" rel="noopener">Create and manage API keys</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($cert !== null): ?>
        <div style="margin-top:14px;padding:12px;border:1px solid var(--sh-line);border-radius:8px;background:var(--sh-bg-2)">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">
            <strong style="font-size:13px"><?= sh_icon('shield', 14) ?> Presented TLS certificate</strong>
            <span class="sh-badge sh-badge--neutral">Read-only inspection — no credentials or message sent</span>
          </div>
          <?php if (!empty($cert['ok'])): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;font-size:12px">
              <div><strong>Subject:</strong> <code><?= e((string)($cert['subject'] ?? '')) ?></code></div>
              <div><strong>Issuer:</strong> <code><?= e((string)($cert['issuer'] ?? '')) ?></code></div>
              <div><strong>Valid from:</strong> <?= e((string)($cert['valid_from'] ?? '')) ?></div>
              <div><strong>Valid to:</strong> <?= e((string)($cert['valid_to'] ?? '')) ?></div>
              <div><strong>Peer endpoint:</strong> <code><?= e(trim((string)($cert['peer_ip'] ?? '')) !== '' ? (string)$cert['peer_ip'] : ($peerShown !== '' ? $peerShown : 'not captured')) ?>:<?= (int)($cert['port'] ?? ($connTestResult['port'] ?? 0)) ?></code></div>
              <div><strong>SNI / expected name:</strong> <code><?= e((string)($cert['host'] ?? '')) ?></code></div>
              <div style="grid-column:1/-1"><strong>Subject Alternative Names:</strong>
                <?php if (!empty($cert['sans'])): ?>
                  <?php foreach ((array)$cert['sans'] as $san): ?><code style="margin-right:6px"><?= e((string)$san) ?></code><?php endforeach; ?>
                <?php else: ?><em>none</em><?php endif; ?>
              </div>
              <div><strong>Hostname validation:</strong>
                <?php if (($cert['hostname_valid'] ?? null) === true): ?><span class="sh-chip sh-chip--success">Match</span>
                <?php elseif (($cert['hostname_valid'] ?? null) === false): ?><span class="sh-chip sh-chip--danger">Mismatch</span>
                <?php else: ?><span class="sh-chip sh-chip--neutral">Not checked</span><?php endif; ?>
              </div>
              <div><strong>Chain validation:</strong>
                <?php if (($cert['chain_valid'] ?? null) === true): ?><span class="sh-chip sh-chip--success">Trusted</span>
                <?php elseif (($cert['chain_valid'] ?? null) === false): ?><span class="sh-chip sh-chip--danger">Not trusted</span>
                <?php else: ?><span class="sh-chip sh-chip--neutral">Not checked</span><?php endif; ?>
              </div>
              <div><strong>TLS:</strong> <code><?= e((string)($cert['tls_protocol'] ?? '')) ?> <?= e((string)($cert['cipher'] ?? '')) ?></code></div>
              <div style="grid-column:1/-1"><strong>SHA-256 fingerprint:</strong> <code style="font-size:11px"><?= e((string)($cert['fingerprint_sha256'] ?? '')) ?></code></div>
            </div>
            <?php if (($cert['hostname_valid'] ?? null) === false): ?>
              <div style="margin-top:10px;padding:10px;border-radius:6px;background:#fef2f2;color:#991b1b;font-size:12.5px">
                <strong>This is a certificate hostname mismatch, not a generic TLS or cryptographic failure.</strong>
                The certificate is valid for the SANs above but not for <code><?= e((string)($cert['host'] ?? '')) ?></code>.
              </div>
              <?php if (!empty($connTestResult['alternate_host'])): ?>
                <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                  <?= sh_csrf_field() ?>
                  <input type="hidden" name="form" value="test_smtp_connection">
                  <input type="hidden" name="smtp_host" value="<?= e((string)$connTestResult['alternate_host']) ?>">
                  <input type="hidden" name="smtp_port" value="<?= (int)($connTestResult['port'] ?? 587) ?>">
                  <input type="hidden" name="smtp_encryption" value="<?= e(strtolower((string)($connTestResult['encryption'] ?? 'tls'))) ?>">
                  <input type="hidden" name="smtp_username" value="<?= e((string)sh_setting('smtp_username', '')) ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">
                    <?= sh_icon('zap', 13) ?> Test <?= e((string)$connTestResult['alternate_host']) ?> with full validation
                  </button>
                  <span class="sh-table__meta">Does not save or switch the hostname. Confirm this legacy hostname with Brevo support first.</span>
                </form>
              <?php endif; ?>
              <div style="margin-top:8px">
                <a class="sh-btn sh-btn--sm" href="?tab=brevo"><?= sh_icon('mail', 13) ?> Open the Brevo HTTPS API transport</a>
                <span class="sh-table__meta">Recommended supported route; uses a v3 API key over HTTPS with a valid certificate.</span>
              </div>
            <?php endif; ?>
            <?php if (($cert['chain_valid'] ?? null) === false && !empty($cert['chain_error'])): ?>
              <div style="margin-top:8px;font-size:12px;color:#991b1b"><strong>Chain detail:</strong> <?= e((string)$cert['chain_error']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div style="font-size:12px;color:#991b1b">
              The certificate could not be read: <?= e((string)($cert['error'] ?? 'unknown reason')) ?>
            </div>
            <?php if (!empty($cert['openssl_errors'])): ?>
              <pre style="max-height:120px;overflow:auto;font-size:11px;margin-top:6px"><?= e(implode("\n", (array)$cert['openssl_errors'])) ?></pre>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!empty($connTestResult['transcript'])): ?>
    <details style="margin-bottom:16px">
      <summary class="sh-panel__note" style="cursor:pointer;font-weight:600">View <?= $isApiTest ? 'HTTPS API' : 'SMTP handshake' ?> transcript (redacted)</summary>
      <pre style="max-height:220px;overflow:auto;font-size:11.5px;line-height:1.5;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;margin-top:6px"><?= e($connTestResult['transcript']) ?></pre>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php if ($testResult !== null): ?>
  <div class="sh-alert <?= !empty($testResult['ok']) ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin-bottom:16px">
    <?= sh_icon(!empty($testResult['ok']) ? 'check-circle' : 'x-circle', 18) ?>
    <div style="flex:1">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <strong><?= !empty($testResult['ok'])
          ? ((string)($testResult['transport'] ?? '') === 'brevo_api'
              ? 'Brevo accepted the test email (HTTP ' . (int)($testResult['http_status'] ?? 0) . ', messageId ' . (!empty($testResult['message_id']) ? 'returned' : 'not returned') . ')'
              : 'Test message accepted by the mail server')
          : 'Test email was not accepted' ?></strong>
        <span class="sh-chip <?= !empty($testResult['ok']) ? 'sh-chip--success' : 'sh-chip--danger' ?>">
          Transport: <?= e((string)($testResult['transport'] ?? 'unknown')) ?><?= !empty($testResult['http_status']) ? ', HTTP ' . (int)$testResult['http_status'] : '' ?><?= !empty($testResult['category']) ? ', Category: ' . e((string)$testResult['category']) : '' ?>
        </span>
      </div>
      <div style="margin-top:4px">
        <?= e((string)($testResult['ok'] ? ($testResult['response'] ?? 'Accepted.') : ($testResult['error'] ?? ''))) ?>
      </div>
      <?php if (!empty($testResult['message_id'])): ?><div style="margin-top:4px" class="sh-table__meta">Message ID: <code><?= e((string)$testResult['message_id']) ?></code></div><?php endif; ?>
      <?php if ((string)($testResult['transport'] ?? '') === 'brevo_api' && !empty($testResult['ok'])): ?>
        <div style="margin-top:4px" class="sh-table__meta">
          <strong>Delivery:</strong> pending / unconfirmed — acceptance is not inbox delivery. Confirm it in the
          recipient mailbox or in Brevo → Transactional → Logs / Events.
        </div>
      <?php endif; ?>
      <?php if (!empty($testResult['fields'])): ?>
        <div style="margin-top:4px" class="sh-table__meta"><strong>Field Brevo objected to:</strong> <code><?= e(implode(', ', array_slice((array)$testResult['fields'], 0, 3))) ?></code></div>
      <?php endif; ?>
      <?php if (!empty($testResult['cause'])): ?>
        <div style="margin-top:6px;font-size:13px;color:var(--sh-heading)"><strong>Confirmed cause:</strong> <?= e((string)$testResult['cause']) ?></div>
      <?php endif; ?>
      <?php if (!empty($testResult['action'])): ?>
        <div style="margin-top:4px;font-size:13px;color:var(--sh-heading)"><strong>Recommended action:</strong> <?= e((string)$testResult['action']) ?></div>
      <?php endif; ?>
      <?php if (is_array($testResult['request'] ?? null)): ?>
        <div style="margin-top:8px;font-size:12px;opacity:0.85;display:flex;gap:12px;flex-wrap:wrap">
          <span>Request: <code><?= e((string)($testResult['request']['method'] ?? '')) ?> <?= e((string)($testResult['request']['endpoint'] ?? '')) ?><?= e((string)($testResult['request']['path'] ?? '')) ?></code></span>
          <span>HTTP status: <code><?= (int)($testResult['http_status'] ?? 0) ?></code></span>
          <span>Credential: <code><?= e((string)($testResult['request']['credential_field'] ?? 'none')) ?><?= !empty($testResult['request']['credential_sent']) ? ' (sent, masked)' : ' (not sent)' ?></code></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($testResult['retry_after'])): ?>
        <div style="margin-top:4px;font-size:12px">Brevo asked to retry after <?= (int)$testResult['retry_after'] ?> second(s). No automatic retry was attempted.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!empty($testResult['transcript'])): ?>
    <details style="margin-bottom:16px">
      <summary class="sh-panel__note" style="cursor:pointer;font-weight:600">View <?= (string)($testResult['transport'] ?? '') === 'brevo_api' ? 'HTTPS API response' : 'SMTP handshake' ?> transcript (redacted)</summary>
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

      <!-- Option C: Brevo HTTPS API -->
      <a href="?tab=brevo"
         id="btn-select-brevo"
         class="sh-setup-option <?= $selectedTab === 'brevo' ? 'sh-setup-option--active' : '' ?>"
         role="tab"
         aria-selected="<?= $selectedTab === 'brevo' ? 'true' : 'false' ?>"
         aria-controls="panel-brevo"
         tabindex="0">
        <div class="sh-setup-option__top">
          <span class="sh-setup-option__title">
            <span class="sh-setup-option__radio"></span>
            <?= sh_icon('send', 16) ?> Option C: Brevo HTTPS API
          </span>
          <?php if ($selectedTransport === 'brevo_api' && $brevoVerified): ?>
            <span class="sh-badge sh-badge--success">Active &middot; verified</span>
          <?php elseif ($selectedTransport === 'brevo_api'): ?>
            <span class="sh-badge sh-badge--warning">Active &middot; unverified</span>
          <?php elseif ($brevoState === 'configured'): ?>
            <span class="sh-badge sh-badge--info">Configured</span>
          <?php elseif ($brevoState === 'disabled'): ?>
            <span class="sh-badge sh-badge--neutral">Disabled</span>
          <?php else: ?>
            <span class="sh-badge sh-badge--warning">Incomplete</span>
          <?php endif; ?>
        </div>
        <p class="sh-setup-option__desc">
          Sends transactional email through Brevo's official HTTPS API. This is the supported route when a regional
          SMTP relay presents a certificate that does not cover <code>smtp-relay.brevo.com</code>. It uses a v3 API
          key, not the SMTP key, and never disables TLS verification.
        </p>
        <div class="sh-setup-option__foot">
          <span style="color:var(--sh-muted)">
            <?= $brevoKeySet ? 'API key set' : 'API key missing' ?>
            <?= $selectedTransport === 'brevo_api' ? ($brevoVerified ? ' · verified send' : ' · awaiting verified send') : '' ?>
          </span>
          <span style="font-weight:700;color:var(--sh-brand)">
            <?= $selectedTab === 'brevo' ? 'Selected &bull;' : 'Configure &rarr;' ?>
          </span>
        </div>
      </a>
    </div>

    <!-- Empty State / Selection Prompt -->
    <div id="no-method-panel" class="sh-alert sh-alert--info" style="display:<?= $selectedTab === '' ? 'flex' : 'none' ?>;margin:0 0 16px">
      <?= sh_icon('info', 16) ?>
      <div>
        <strong>Choose a setup method to configure its settings.</strong> Select <strong>Custom SMTP Server</strong>, <strong>Firebase Integration</strong>, or the <strong>Brevo HTTPS API</strong> above.
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
          <div class="sh-alert <?= $smtpState === 'ready' ? 'sh-alert--success' : 'sh-alert--warning' ?>" style="margin-bottom:16px">
            <?= sh_icon($smtpState === 'ready' ? 'check-circle' : 'alert', 16) ?>
            <div>
              <?php if ($selectedTransport === 'brevo_api'): ?>
                <strong>Brevo HTTPS API is the active delivery transport.</strong> The SMTP settings below are stored but
                are not used for delivery until you explicitly switch back with “Use SMTP relay for delivery”.
              <?php elseif ($smtpState === 'ready'): ?>
                SMTP transport is configured: <strong><?= e($host) ?>:<?= (int)sh_setting('smtp_port', '587') ?></strong>
                (<?= e(strtoupper((string)sh_setting('smtp_encryption', 'tls'))) ?>).
              <?php else: ?>
                <strong>Configuration Required:</strong> Missing: <?= e(implode(', ', array_filter([
                    $host === '' ? 'SMTP host' : '',
                    $hasPass ? '' : 'SMTP password',
                    $smtpEnabled ? '' : 'Enable Custom SMTP Delivery',
                ]))) ?>.
                The store will clearly display when email is not configured instead of pretending codes were delivered.
              <?php endif; ?>
              <br><span class="sh-table__meta">Library: <?= e((string)$mailStatus['library']) ?> · <?= $hasPass ? 'password stored' : 'no password stored' ?> · TLS validation: always on</span>
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

            <div class="sh-alert sh-alert--info" style="margin-bottom:16px">
              <?= sh_icon('shield', 16) ?>
              <div>
                <strong>TLS verification is always enforced.</strong>
                ShopHaat validates the certificate chain, the hostname and SNI for every SMTP delivery, and refuses to
                authenticate before the encrypted channel is confirmed. The old “accept self-signed certificate”
                option has been retired. If a provider presents the wrong hostname, fix the endpoint or use the
                Brevo HTTPS API transport — do not disable verification.
              </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save SMTP Settings</button>
              <?php if ($selectedTransport === 'smtp'): ?>
                <span class="sh-chip sh-chip--success">SMTP is the active delivery transport</span>
              <?php endif; ?>
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
                  <button class="sh-btn sh-btn--sm" type="submit" <?= ($mailStatus['ready'] && $selectedTransport === 'smtp') ? '' : 'disabled' ?> style="flex:none">
                    <?= sh_icon('send', 13) ?> Send Test Email
                  </button>
                </div>
              </form>
            </div>
          </div>

          <form method="post" id="use-smtp-transport" style="margin-top:14px">
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="use_transport">
            <input type="hidden" name="transport" value="smtp">
            <p class="sh-panel__note" style="margin:0 0 8px">
              Selecting a transport is an explicit change. Saving SMTP settings alone never switches an active Brevo API transport.
            </p>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" <?= $host === '' ? 'disabled' : '' ?>>
              <?= sh_icon('check-circle', 14) ?> Use SMTP relay for delivery
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- ====================================================================
         PANEL C: BREVO HTTPS TRANSACTIONAL API
         ==================================================================== -->
    <div id="panel-brevo" role="tabpanel" aria-labelledby="btn-select-brevo" style="display:<?= $selectedTab === 'brevo' ? 'block' : 'none' ?>">
      <div class="sh-panel" style="border:1px solid var(--sh-line);box-shadow:none;margin-bottom:0">
        <div class="sh-panel__head" style="background:var(--sh-bg-2)">
          <h3 class="sh-panel__title"><?= sh_icon('mail', 16) ?> Brevo HTTPS API Transport</h3>
          <div class="sh-panel__actions">
            <a href="<?= e(sh_url('admin/guides/smtp')) ?>#brevo-api" class="sh-btn sh-btn--sm" style="background:#0f766e;color:#fff;border-color:#0f766e">
              <?= sh_icon('book-open', 14) ?> Brevo setup guide &rarr;
            </a>
          </div>
        </div>
        <div class="sh-panel__body">
          <div class="sh-alert sh-alert--info" style="margin-bottom:16px">
            <?= sh_icon('info', 16) ?>
            <div>
              <strong>A supported route around the Brevo SMTP certificate mismatch.</strong>
              Brevo documents <code>smtp-relay.brevo.com</code>, but some of its regional relays present a
              Sendinblue-era certificate whose Subject Alternative Names do not include that hostname. The official
              transactional API at <code>https://api.brevo.com/v3/smtp/email</code> uses a valid
              <code>*.brevo.com</code> certificate and a separate v3 API key. Choosing it here is explicit: SMTP
              settings and credentials are left untouched, and no automatic fallback is performed.
            </div>
          </div>

          <div class="sh-alert <?= ($selectedTransport === 'brevo_api' && $brevoVerified) ? 'sh-alert--success' : 'sh-alert--warning' ?>" style="margin-bottom:16px">
            <?= sh_icon(($selectedTransport === 'brevo_api' && $brevoVerified) ? 'check-circle' : 'alert', 16) ?>
            <div>
              <strong>Current delivery transport:</strong> <?= $selectedTransport === 'brevo_api' ? 'Brevo HTTPS API' : 'SMTP relay' ?>.
              API key: <?= $brevoKeySet ? 'stored (masked)' : 'not set' ?>;
              API transport switch: <?= $brevoApiEnabled ? 'enabled' : 'disabled' ?>.
              <?php if ($brevoLastTestedAt !== ''): ?>
                <br><span class="sh-table__meta">Last diagnostics run: <?= e($brevoLastTestedAt) ?> — <?= $brevoLastTestOk ? 'HTTPS and account access OK (email not yet proven)' : 'not verified' ?>.</span>
              <?php endif; ?>
              <br><span class="sh-table__meta">
                Last accepted test send: <?= $brevoVerified ? e($brevoVerifiedAt) : 'none yet' ?>.
                <?php if ($selectedTransport === 'brevo_api' && !$brevoVerified): ?>
                  Configured but unverified: run “Send Test Email” to prove Brevo accepts a message from this key.
                <?php endif; ?>
              </span>
              <?php if ($brevoState === 'incomplete'): ?>
                <br><span class="sh-table__meta">Add a v3 API key to finish the configuration.</span>
              <?php elseif ($brevoState === 'configured'): ?>
                <br><span class="sh-table__meta">Enabled and keyed. Use “Use Brevo HTTPS API for delivery” below to make it the active transport.</span>
              <?php endif; ?>
            </div>
          </div>

          <form method="post" novalidate>
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="brevo_api_settings">

            <label class="sh-toggle" style="margin-bottom:14px">
              <input type="checkbox" name="brevo_api_enabled" value="1" <?= $brevoApiEnabled ? 'checked' : '' ?>>
              <span class="sh-toggle__track"></span>
              <span style="font-weight:700">Enable the Brevo HTTPS API transport</span>
            </label>

            <div class="sh-field">
              <label class="sh-field__label" for="brevo-key">Brevo v3 API Key</label>
              <input class="sh-input" id="brevo-key" type="password" name="brevo_api_key" autocomplete="new-password" spellcheck="false"
                     placeholder="<?= $brevoKeySet ? 'A key is saved — leave blank to keep it' : 'xkeysib-…' ?>">
              <span class="sh-field__hint">
                Brevo → <strong>SMTP &amp; API → API Keys &amp; MCP</strong> → create a v3 key (starts with <code>xkeysib-</code>).
                This is <strong>not</strong> the SMTP key (<code>xsmtpsib-</code>) and <strong>not</strong> the account password.
                Stored encrypted server-side; never rendered, logged or sent to browser JavaScript.
                Docs:
                <a href="https://developers.brevo.com/docs/api-key-authentication" target="_blank" rel="noopener">API key authentication</a>,
                <a href="https://developers.brevo.com/reference/sendtransacemail" target="_blank" rel="noopener">send a transactional email</a>,
                <a href="https://help.brevo.com/hc/en-us/articles/209467485-Create-and-manage-your-API-keys" target="_blank" rel="noopener">create and manage API keys</a>.
              </span>
              <?php if ($brevoKeySet): ?>
                <label class="sh-check" style="margin-top:6px">
                  <input type="checkbox" name="clear_brevo_key" value="1">
                  <span>Remove the stored Brevo API key</span>
                </label>
              <?php endif; ?>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="brevo-from-name">Sender Name</label>
                <input class="sh-input" id="brevo-from-name" name="smtp_from_name" value="<?= e((string)sh_setting('smtp_from_name', 'ShopHaat')) ?>">
                <span class="sh-field__hint">Shared sender identity with the SMTP transport.</span>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="brevo-from-mail">Sender Email Address</label>
                <input class="sh-input" id="brevo-from-mail" type="email" name="smtp_from_email" value="<?= e((string)sh_setting('smtp_from_email', '')) ?>" placeholder="noreply@yourdomain.com">
                <span class="sh-field__hint">Must be a verified sender or on an authenticated domain in Brevo.</span>
              </div>
            </div>

            <div class="sh-grid2">
              <div class="sh-field">
                <label class="sh-field__label" for="brevo-reply">Reply-To (optional)</label>
                <input class="sh-input" id="brevo-reply" type="email" name="smtp_reply_to" value="<?= e((string)sh_setting('smtp_reply_to', '')) ?>" placeholder="support@yourdomain.com">
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="brevo-timeout">HTTPS Timeout (seconds)</label>
                <input class="sh-input" id="brevo-timeout" type="number" min="5" max="60" name="smtp_timeout" value="<?= (int)sh_setting('smtp_timeout', '20') ?>">
              </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px">
              <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Brevo API Settings</button>
              <span class="sh-table__meta">Saving does not change the active transport.</span>
            </div>
          </form>

          <form method="post" style="margin-bottom:18px">
            <?= sh_csrf_field() ?>
            <input type="hidden" name="form" value="use_transport">
            <input type="hidden" name="transport" value="brevo_api">
            <button class="sh-btn sh-btn--sm" type="submit" <?= ($brevoKeySet && $brevoApiEnabled) ? '' : 'disabled' ?>>
              <?= sh_icon('check-circle', 14) ?> Use Brevo HTTPS API for delivery
            </button>
            <?php if (!$brevoKeySet || !$brevoApiEnabled): ?>
              <span class="sh-table__meta">Save a v3 API key and enable the switch first.</span>
            <?php elseif ($selectedTransport === 'brevo_api'): ?>
              <span class="sh-chip sh-chip--success">Active delivery transport</span>
            <?php endif; ?>
          </form>

          <hr style="border:0;border-top:1px solid var(--sh-line);margin:20px 0">

          <h4 style="margin:0 0 12px;font-size:14px;color:var(--sh-heading)"><?= sh_icon('zap', 15) ?> Brevo API Diagnostics</h4>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:14px">
              <h5 style="margin:0 0 6px;font-size:13px;color:var(--sh-heading)">1. Connection and account tests (no email sent)</h5>
              <p style="font-size:12px;color:var(--sh-muted);margin:0 0 8px">
                Two checks run here. Transactional email is proven separately by a real send, because that is the
                only documented operation that can accept a message:
              </p>
              <ol style="font-size:12px;color:var(--sh-muted);margin:0 0 10px;padding-left:18px;line-height:1.5">
                <li><strong>A — HTTPS/TLS</strong> to <code>api.brevo.com</code> (no credential sent).</li>
                <li><strong>B — account access</strong>: <code>GET /v3/account</code>, informational only. A 403 here means this key may not read account details; it does not by itself block email.</li>
                <li><strong>C — transactional email</strong>: a real <code>POST /v3/smtp/email</code>, run only by <em>Send Test Email</em> below. It is never sent by this button.</li>
              </ol>
              <form method="post" novalidate>
                <?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="test_brevo_api">
                <input type="hidden" name="smtp_timeout" value="<?= (int)sh_setting('smtp_timeout', '20') ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">
                  <?= sh_icon('zap', 14) ?> Run Brevo API Diagnostics (A/B/C)
                </button>
                <?php if (!$brevoKeySet): ?>
                  <span class="sh-table__meta">No key stored: only Test A will run.</span>
                <?php endif; ?>
              </form>
            </div>

            <div style="background:var(--sh-bg-2);border:1px solid var(--sh-line);border-radius:8px;padding:14px">
              <h5 style="margin:0 0 6px;font-size:13px;color:var(--sh-heading)">2. Send a real test email</h5>
              <p style="font-size:12px;color:var(--sh-muted);margin:0 0 10px">
                Sends one real message through <strong><?= $selectedTransport === 'brevo_api' ? 'the Brevo HTTPS API (POST /v3/smtp/email)' : 'SMTP' ?></strong>
                — the saved transport, not the tab you are on. The sender must be a registered, verified address in the
                Brevo account, and the recipient must be a mailbox you control.
                Only a 201 answer with a messageId is treated as accepted; delivery is still unconfirmed until you see it.
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
                <?php if (!$mailStatus['ready']): ?>
                  <span class="sh-table__meta" style="display:block;margin-top:6px">
                    Not available yet. Missing: <?= e(implode(', ', (array)($mailStatus['missing'] ?? []))) ?>
                  </span>
                <?php elseif ($selectedTransport !== 'brevo_api'): ?>
                  <span class="sh-table__meta" style="display:block;margin-top:6px">
                    This sends through SMTP. Select <em>Use Brevo HTTPS API for delivery</em> above to test the API.
                  </span>
                <?php elseif ((string)sh_setting('brevo_api_verified_at', '') === ''): ?>
                  <span class="sh-table__meta" style="display:block;margin-top:6px">
                    The API has not been verified by a real send yet.
                  </span>
                <?php else: ?>
                  <span class="sh-table__meta" style="display:block;margin-top:6px">
                    Last accepted test send: <?= e((string)sh_setting('brevo_api_verified_at', '')) ?>.
                  </span>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <div class="sh-alert sh-alert--info" style="margin-top:16px">
            <?= sh_icon('shield', 16) ?>
            <div>
              HTTPS requests always verify the certificate chain and the <code>api.brevo.com</code> hostname. API
              credentials and SMTP credentials are stored separately, are never sent to the other service and are never
              exposed to the browser. An SMTP key pasted into the API field is refused before any request is made.
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
  var tabs = {
    smtp: { btn: document.getElementById('btn-select-smtp'), panel: document.getElementById('panel-smtp') },
    firebase: { btn: document.getElementById('btn-select-firebase'), panel: document.getElementById('panel-firebase') },
    brevo: { btn: document.getElementById('btn-select-brevo'), panel: document.getElementById('panel-brevo') }
  };
  var panelNone = document.getElementById('no-method-panel');

  function activateTab(name, updateUrl) {
    Object.keys(tabs).forEach(function(key) {
      var t = tabs[key];
      if (!t) { return; }
      var active = key === name;
      if (t.btn) {
        t.btn.classList.toggle('sh-setup-option--active', active);
        t.btn.setAttribute('aria-selected', active ? 'true' : 'false');
      }
      if (t.panel) { t.panel.style.display = active ? 'block' : 'none'; }
    });
    if (panelNone) { panelNone.style.display = 'none'; }
    if (updateUrl && window.history && history.replaceState) {
      history.replaceState(null, '', '?tab=' + encodeURIComponent(name));
    }
  }

  Object.keys(tabs).forEach(function(name) {
    var btn = tabs[name] && tabs[name].btn;
    if (!btn) { return; }
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      activateTab(name, true);
    });
    btn.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activateTab(name, true);
      }
    });
  });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
