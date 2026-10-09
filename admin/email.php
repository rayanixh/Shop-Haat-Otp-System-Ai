<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
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
        sh_log_line('admin', 'Email/SMTP settings updated');
        sh_flash('success', 'Email settings saved.');
        sh_redirect('admin/email.php');
    }
}

$smtpEnabled = sh_setting('smtp_enabled', '0') === '1';
$emailEnabled = sh_setting('email_enabled', '0') === '1';
$host = (string)sh_setting('smtp_host', '');
$hasPass = (string)sh_setting('smtp_password', '') !== '';
$adminEmail = (string)sh_setting('admin_notify_email', '');
$configured = $adminEmail !== '' && (!$smtpEnabled || ($host !== '' && $hasPass));
$recent = sh_all("SELECT * FROM notification_logs WHERE channel = 'email' ORDER BY id DESC LIMIT 12");

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
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save email settings</button>
      </form>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Send test email</h2></div>
    <div class="sh-panel__body">
      <?php if ($adminEmail === ''): ?>
        <p class="sh-panel__note">Save an admin notification address first.</p>
      <?php else: ?>
        <p class="sh-panel__note" style="margin-bottom:10px">
          Sends a real message to <strong><?= e($adminEmail) ?></strong> and reports the true SMTP result.</p>
        <button class="sh-btn sh-btn--block" type="button" data-test-channel="email"><?= sh_icon('send', 15) ?> Send test email</button>
        <p id="test-result" style="margin-top:10px;font-size:12.7px"></p>
      <?php endif; ?>
    </div>
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
