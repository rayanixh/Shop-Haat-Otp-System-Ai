<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/mail.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

$errors = [];
$testResult = null;
sh_email_schema_ensure();

/** @return array<string,int> clamped numeric input */
function sh_email_admin_int(string $key, int $default, int $min, int $max): int
{
    $n = sh_int($_POST[$key] ?? $default);
    return max($min, min($max, $n));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = (string)($_POST['form'] ?? 'smtp_settings');

    if ($form === 'test_mail') {
        // One real message, and the transport's own answer reported back. Never
        // "sent" unless the server accepted it.
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
    } elseif ($form === 'verification_settings') {
        // Authentication email switches.
        sh_setting_save('email_verify_enabled', !empty($_POST['email_verify_enabled']) ? '1' : '0');
        sh_setting_save('email_verification_required', !empty($_POST['email_verification_required']) ? '1' : '0');
        sh_setting_save('email_verify_required_login', !empty($_POST['email_verify_required_login']) ? '1' : '0');
        sh_setting_save('email_forgot_enabled', !empty($_POST['email_forgot_enabled']) ? '1' : '0');
        sh_setting_save('email_notify_password_change', !empty($_POST['email_notify_password_change']) ? '1' : '0');
        sh_setting_save('email_otp_on_password_change', !empty($_POST['email_otp_on_password_change']) ? '1' : '0');

        // Challenge policy, clamped to the same ranges the readers enforce.
        sh_setting_save('email_otp_expiry_minutes', (string)sh_email_admin_int('email_otp_expiry_minutes', 10, 5, 60));
        sh_setting_save('email_otp_max_attempts', (string)sh_email_admin_int('email_otp_max_attempts', 5, 3, 20));
        sh_setting_save('email_otp_max_resends', (string)sh_email_admin_int('email_otp_max_resends', 5, 1, 20));
        sh_setting_save('email_otp_resend_cooldown', (string)sh_email_admin_int('email_otp_resend_cooldown', 60, 30, 900));
        sh_setting_save('email_otp_email_limit', (string)sh_email_admin_int('email_otp_email_limit', 3, 1, 50));
        sh_setting_save('email_otp_ip_limit', (string)sh_email_admin_int('email_otp_ip_limit', 10, 1, 200));

        sh_log_line('admin', 'Email verification settings updated');
        sh_flash('success', 'Verification settings saved.');
        sh_redirect('admin/email.php');
    } elseif ($form === 'dkim_settings') {
        // Separate form, so saving the DKIM key never rewrites the toggles above.
        sh_setting_save('dkim_enabled', !empty($_POST['dkim_enabled']) ? '1' : '0');
        sh_setting_save('dkim_domain', trim((string)($_POST['dkim_domain'] ?? '')));
        sh_setting_save('dkim_selector', trim((string)($_POST['dkim_selector'] ?? '')));
        sh_setting_save('dkim_private_key_path', trim((string)($_POST['dkim_private_key_path'] ?? '')));
        $dkim = sh_mail_dkim_status();
        sh_log_line('admin', 'DKIM settings updated — ' . ($dkim['ready'] ? 'ready' : 'not usable: ' . $dkim['reason']));
        sh_flash($dkim['ready'] ? 'success' : 'warning', $dkim['ready']
            ? 'DKIM signing is configured. Outgoing mail is signed with selector "' . (string)sh_setting('dkim_selector', '') . '".'
            : 'DKIM settings saved, but signing is not active: ' . $dkim['reason']);
        sh_redirect('admin/email.php');
    } else {
        // SMTP transport settings.
        $pass = (string)($_POST['smtp_password'] ?? '');
        if ($pass !== '') { sh_setting_save('smtp_password', $pass); }
        if (!empty($_POST['clear_password'])) { sh_setting_save('smtp_password', ''); }

        $fromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
        if ($fromEmail !== '' && !sh_valid_email($fromEmail)) {
            $errors['smtp_from_email'] = 'The "from" address is not a valid email address.';
        }
        $adminEmail = trim((string)($_POST['admin_notify_email'] ?? ''));
        if ($adminEmail !== '' && !sh_valid_email($adminEmail)) {
            $errors['admin_notify_email'] = 'The admin notification address is not a valid email address.';
        }
        $port = sh_int($_POST['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) { $errors['smtp_port'] = 'The SMTP port must be between 1 and 65535.'; }

        if (!$errors) {
            sh_setting_save('smtp_enabled', !empty($_POST['smtp_enabled']) ? '1' : '0');
            sh_setting_save('smtp_host', trim((string)($_POST['smtp_host'] ?? '')));
            sh_setting_save('smtp_port', (string)$port);
            $enc = (string)($_POST['smtp_encryption'] ?? 'tls');
            sh_setting_save('smtp_encryption', in_array($enc, ['tls', 'ssl', 'none'], true) ? $enc : 'tls');
            sh_setting_save('smtp_username', trim((string)($_POST['smtp_username'] ?? '')));
            sh_setting_save('smtp_from_email', $fromEmail);
            sh_setting_save('smtp_from_name', trim((string)($_POST['smtp_from_name'] ?? '')));
            sh_setting_save('admin_notify_email', $adminEmail);
            sh_setting_save('email_enabled', !empty($_POST['email_enabled']) ? '1' : '0');
            sh_setting_save('smtp_allow_self_signed', !empty($_POST['smtp_allow_self_signed']) ? '1' : '0');
            sh_log_line('admin', 'Email/SMTP settings updated');
            sh_flash('success', 'Email settings saved.');
            sh_redirect('admin/email.php');
        }
    }
}

$smtpEnabled = sh_setting('smtp_enabled', '0') === '1';
$emailEnabled = sh_setting('email_enabled', '0') === '1';
$host = (string)sh_setting('smtp_host', '');
$hasPass = (string)sh_setting('smtp_password', '') !== '';
$adminEmail = (string)sh_setting('admin_notify_email', '');
$configured = $adminEmail !== '' && (!$smtpEnabled || ($host !== '' && $hasPass));
$recent = sh_all("SELECT * FROM notification_logs WHERE channel = 'email' ORDER BY id DESC LIMIT 12");
$mailStatus = sh_mail_service_status();
$mailStats = sh_email_log_stats(7);
$verifier = sh_email_verifier();
$grandfathered = (int)sh_val('SELECT COUNT(*) FROM users WHERE email_verified = 1', [], 0);
$unverified = (int)sh_val("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND email <> '' AND email NOT LIKE '%@user.shophaat.local'", [], 0);

$adminPage = 'email';
$adminTitle = 'Email / SMTP';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-alert <?= $configured ? 'sh-alert--success' : 'sh-alert--warning' ?>">
  <?= sh_icon($configured ? 'check-circle' : 'alert', 17) ?>
  <span><?php if ($configured): ?>
    Email is configured. Outgoing mail uses <?= $smtpEnabled ? 'SMTP (' . e($host) . ')' : "the server's built-in mail function" ?>.
  <?php else: ?>
    <strong>Not fully configured.</strong> Set an admin notification address, and SMTP credentials if you enable SMTP.
    Until then email notifications are logged as skipped or failed — never as sent.
  <?php endif; ?></span>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-msggrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('mail', 17) ?> Mail delivery</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="smtp_settings">
        <label class="sh-toggle" style="margin-bottom:12px">
          <input type="checkbox" name="email_enabled" value="1" <?= $emailEnabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Send order notifications by email</span>
        </label>
        <div class="sh-field">
          <label class="sh-field__label" for="em-admin">Admin notification address</label>
          <input class="sh-input" id="em-admin" type="email" name="admin_notify_email" value="<?= e($adminEmail) ?>" placeholder="orders@yourstore.com">
          <span class="sh-field__hint">New orders and payment submissions are sent here.</span>
        </div>

        <hr style="border:0;border-top:1px solid var(--sh-line);margin:16px 0">
        <label class="sh-toggle" style="margin-bottom:12px">
          <input type="checkbox" name="smtp_enabled" value="1" <?= $smtpEnabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Use an SMTP server (recommended)</span>
        </label>
        <p class="sh-panel__note" style="margin-bottom:13px">
          With SMTP disabled the site falls back to PHP's mail() function, which many shared hosts rate-limit or mark as spam.
        </p>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="em-host">SMTP host</label>
            <input class="sh-input" id="em-host" name="smtp_host" value="<?= e($host) ?>" placeholder="smtp.yourhost.com"></div>
          <div class="sh-field"><label class="sh-field__label" for="em-port">Port</label>
            <input class="sh-input" id="em-port" name="smtp_port" type="number" value="<?= e((string)sh_setting('smtp_port', '587')) ?>"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="em-enc">Encryption</label>
            <select class="sh-select" id="em-enc" name="smtp_encryption">
              <?php $enc = (string)sh_setting('smtp_encryption', 'tls');
              foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= $enc === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="em-user">SMTP username</label>
            <input class="sh-input" id="em-user" name="smtp_username" value="<?= e((string)sh_setting('smtp_username', '')) ?>" autocomplete="off"></div>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="em-pass">SMTP password</label>
          <input class="sh-input" id="em-pass" type="password" name="smtp_password" autocomplete="new-password"
                 placeholder="<?= $hasPass ? 'A password is saved — leave blank to keep it' : 'Mailbox password' ?>">
          <span class="sh-field__hint">Stored server-side and never rendered back into this page.</span>
        </div>
        <?php if ($hasPass): ?>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_password" value="1"><span>Remove the stored SMTP password</span></label>
        <?php endif; ?>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="em-fe">From address</label>
            <input class="sh-input" id="em-fe" type="email" name="smtp_from_email" value="<?= e((string)sh_setting('smtp_from_email', '')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="em-fn">From name</label>
            <input class="sh-input" id="em-fn" name="smtp_from_name" value="<?= e((string)sh_setting('smtp_from_name', (string)sh_setting('site_name', ''))) ?>"></div>
        </div>
        <label class="sh-toggle" style="margin-bottom:12px">
          <input type="checkbox" name="smtp_allow_self_signed" value="1" <?= sh_setting('smtp_allow_self_signed', '0') === '1' ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Accept a self-signed SMTP certificate</span>
        </label>
        <p class="sh-panel__note" style="margin:0 0 13px 26px">
          Leave this off unless the host's mail server genuinely uses a private certificate — it disables
          certificate validation for the SMTP connection.
        </p>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save email settings</button>
      </form>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Send test email</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:10px">
        Sends one real message and reports what the mail server actually answered. A submission is not
        proof of inbox delivery — that depends on SPF, DKIM and DMARC, which the provider reports separately.
      </p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="test_mail">
        <div class="sh-field">
          <label class="sh-field__label" for="em-test">Send to</label>
          <input class="sh-input" id="em-test" type="email" name="test_recipient"
                 value="<?= e((string)($_POST['test_recipient'] ?? $adminEmail)) ?>" required>
          <?php if (!empty($errors['test_recipient'])): ?><p class="sh-field__hint" style="color:#b42318"><?= e($errors['test_recipient']) ?></p><?php endif; ?>
        </div>
        <button class="sh-btn sh-btn--block" type="submit"<?= $mailStatus['ready'] ? '' : ' disabled' ?>>
          <?= sh_icon('send', 15) ?> Send test email
        </button>
      </form>

      <?php if ($testResult !== null): ?>
        <div class="sh-alert <?= !empty($testResult['ok']) ? 'sh-alert--success' : 'sh-alert--error' ?>" style="margin-top:12px">
          <?= sh_icon(!empty($testResult['ok']) ? 'check-circle' : 'x-circle', 16) ?>
          <div>
            <strong><?= !empty($testResult['ok']) ? 'Accepted by the mail server' : 'Not sent' ?></strong>
            (<?= e((string)$testResult['transport']) ?><?= !empty($testResult['category']) ? ', ' . e((string)$testResult['category']) : '' ?>)<br>
            <?= e((string)($testResult['ok'] ? (string)$testResult['response'] : (string)($testResult['error'] ?? ''))) ?>
            <?php if (!empty($testResult['message_id'])): ?><br><span class="sh-table__meta">Message-ID: <?= e((string)$testResult['message_id']) ?></span><?php endif; ?>
          </div>
        </div>
        <?php if (!empty($testResult['transcript'])): ?>
          <details style="margin-top:10px">
            <summary class="sh-panel__note" style="cursor:pointer">SMTP transcript (redacted)</summary>
            <pre style="max-height:240px;overflow:auto;font-size:11.5px;line-height:1.5;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px"><?= e((string)$testResult['transcript']) ?></pre>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> Authentication email</h2>
    <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/email-log.php')) ?>"><?= sh_icon('list', 14) ?> Delivery log</a></div>
  <div class="sh-panel__body">
    <div class="sh-alert <?= $mailStatus['ready'] ? 'sh-alert--success' : 'sh-alert--warning' ?>" style="margin-bottom:14px">
      <?= sh_icon($mailStatus['ready'] ? 'check-circle' : 'alert', 16) ?>
      <div>
        <?php if ($mailStatus['ready']): ?>
          Email sending is configured over <strong><?= e((string)$mailStatus['driver']) ?></strong>
          (<?= e((string)$mailStatus['host']) ?>:<?= (int)$mailStatus['port'] ?>, <?= e((string)$mailStatus['encryption']) ?>).
        <?php else: ?>
          <strong>Email is not ready.</strong> Missing: <?= e(implode(', ', (array)$mailStatus['missing'])) ?>.
          Verification and reset codes cannot be delivered until this is complete, and the store will say so
          instead of pretending a message was sent.
        <?php endif; ?>
        <br><span class="sh-table__meta">Mail library: <?= e((string)$mailStatus['library']) ?>
          <?= $mailStatus['password_set'] ? ' · password stored' : ' · no password stored' ?></span>
      </div>
    </div>

    <p class="sh-panel__note">
      Verifier in use: <strong><?= e($verifier) ?></strong>
      (smtp_otp = this store emails the codes, firebase = the Firebase verification email, none = neither is configured).
      Verified accounts: <strong><?= (int)$grandfathered ?></strong> · unverified: <strong><?= (int)$unverified ?></strong>.
      Accounts that existed before this system was installed were grandfathered once so nobody was locked out.
    </p>

    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="verification_settings">
      <label class="sh-toggle" style="margin-bottom:10px">
        <input type="checkbox" name="email_verify_enabled" value="1" <?= sh_setting('email_verify_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Email a 6-digit code when a customer registers</span>
      </label>
      <p class="sh-panel__note" style="margin:0 0 12px 26px">
        The account is only marked verified after the code is entered. A refused message is reported as a failure.
      </p>
      <label class="sh-toggle" style="margin-bottom:10px">
        <input type="checkbox" name="email_forgot_enabled" value="1" <?= sh_setting('email_forgot_enabled', '1') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Password recovery by emailed code</span>
      </label>
      <label class="sh-toggle" style="margin-bottom:10px">
        <input type="checkbox" name="email_notify_password_change" value="1" <?= sh_setting('email_notify_password_change', '1') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Email a security notice after a password change</span>
      </label>
      <label class="sh-toggle" style="margin-bottom:10px">
        <input type="checkbox" name="email_otp_on_password_change" value="1" <?= sh_setting('email_otp_on_password_change', '0') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Require an emailed code before a password change</span>
      </label>
      <hr style="border:0;border-top:1px solid var(--sh-line);margin:14px 0">
      <label class="sh-toggle" style="margin-bottom:10px">
        <input type="checkbox" name="email_verification_required" value="1" <?= sh_setting('email_verification_required', '0') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Require a verified email before checkout</span>
      </label>
      <label class="sh-toggle" style="margin-bottom:12px">
        <input type="checkbox" name="email_verify_required_login" value="1" <?= sh_setting('email_verify_required_login', '0') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Require a verified email before sign-in</span>
      </label>
      <p class="sh-panel__note" style="margin:0 0 14px 26px">
        Both gates are off by default and never apply to phone-only accounts. Switching them on without working
        mail delivery would stop customers, so the store refuses to complete verification it cannot deliver.
      </p>
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="em-exp">Code lifetime (minutes)</label>
          <input class="sh-input" id="em-exp" type="number" min="5" max="60" name="email_otp_expiry_minutes" value="<?= (int)sh_email_otp_expiry_minutes() ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="em-att">Attempts per code</label>
          <input class="sh-input" id="em-att" type="number" min="3" max="20" name="email_otp_max_attempts" value="<?= (int)sh_email_otp_max_attempts() ?>"></div>
      </div>
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="em-cool">Resend cooldown (seconds)</label>
          <input class="sh-input" id="em-cool" type="number" min="30" max="900" name="email_otp_resend_cooldown" value="<?= (int)sh_email_otp_resend_cooldown() ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="em-resends">Resends per address</label>
          <input class="sh-input" id="em-resends" type="number" min="1" max="20" name="email_otp_max_resends" value="<?= (int)sh_email_otp_max_resends() ?>"></div>
      </div>
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="em-elim">Codes per address / hour</label>
          <input class="sh-input" id="em-elim" type="number" min="1" max="50" name="email_otp_email_limit" value="<?= (int)sh_setting('email_otp_email_limit', '3') ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="em-ilim">Codes per connection / hour</label>
          <input class="sh-input" id="em-ilim" type="number" min="1" max="200" name="email_otp_ip_limit" value="<?= (int)sh_setting('email_otp_ip_limit', '10') ?>"></div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save verification settings</button>
    </form>

    <div class="sh-tablewrap" style="margin-top:16px">
      <table class="sh-table">
        <thead><tr><th>Last 7 days</th><th>Accepted</th><th>Failed</th><th>Skipped</th><th>Delivered</th><th>Bounced</th><th>Complaints</th></tr></thead>
        <tbody><tr>
          <td><?= (int)$mailStats['total'] ?></td>
          <td><?= (int)$mailStats['accepted'] ?></td>
          <td><?= (int)$mailStats['failed'] ?></td>
          <td><?= (int)$mailStats['skipped'] ?></td>
          <td><?= (int)$mailStats['delivered'] ?></td>
          <td><?= (int)$mailStats['bounced'] ?></td>
          <td><?= (int)$mailStats['complaint'] ?></td>
        </tr></tbody>
      </table>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> DKIM signing</h2></div>
  <div class="sh-panel__body">
    <div class="sh-alert <?= $mailStatus['dkim']['ready'] ? 'sh-alert--success' : 'sh-alert--info' ?>" style="margin-bottom:14px">
      <?= sh_icon($mailStatus['dkim']['ready'] ? 'check-circle' : 'info', 16) ?>
      <span><?= e((string)$mailStatus['dkim']['reason']) ?></span>
    </div>
    <form method="post" novalidate>
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="dkim_settings">
      <label class="sh-toggle" style="margin-bottom:12px">
        <input type="checkbox" name="dkim_enabled" value="1" <?= sh_setting('dkim_enabled', '0') === '1' ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span><span>Sign outgoing mail with DKIM</span>
      </label>
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="em-dkimdom">DKIM domain</label>
          <input class="sh-input" id="em-dkimdom" name="dkim_domain" value="<?= e((string)sh_setting('dkim_domain', '')) ?>" placeholder="yourdomain.com"></div>
        <div class="sh-field"><label class="sh-field__label" for="em-dkimsel">DKIM selector</label>
          <input class="sh-input" id="em-dkimsel" name="dkim_selector" value="<?= e((string)sh_setting('dkim_selector', '')) ?>" placeholder="brevo"></div>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="em-dkimkey">Private key file (absolute path)</label>
        <input class="sh-input" id="em-dkimkey" name="dkim_private_key_path" value="<?= e((string)sh_setting('dkim_private_key_path', '')) ?>" placeholder="/home/account/dkim/private.key">
        <p class="sh-field__hint">
          The key comes from your email provider — it cannot be generated here, and a made-up key or DNS value
          would break signing. Keep the file outside the public uploads folder; that path is refused.
        </p>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save DKIM settings</button>
    </form>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Recent email deliveries</h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Event</th><th>Recipient</th><th>Status</th><th>Detail</th><th>Time</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr class="sh-table--empty"><td colspan="5">Nothing attempted yet.</td></tr>
      <?php else: foreach ($recent as $l): ?>
        <tr><td><?= e(str_replace('_', ' ', $l['event'])) ?></td>
          <td class="sh-table__meta"><?= e((string)($l['recipient'] ?? '')) ?></td>
          <td><span class="sh-badge <?= $l['status'] === 'sent' ? 'sh-badge--ok' : ($l['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e($l['status']) ?></span></td>
          <td class="sh-table__meta" style="max-width:340px"><?= e((string)($l['error_message'] ?? '')) ?></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>@media (max-width: 1000px){.sh-msggrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
