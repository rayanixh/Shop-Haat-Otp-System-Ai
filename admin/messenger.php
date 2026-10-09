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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $tok = trim((string)($_POST['messenger_token'] ?? ''));
    if ($tok !== '') { sh_setting_save('messenger_token', $tok); }
    if (!empty($_POST['clear_token'])) { sh_setting_save('messenger_token', ''); }
    sh_setting_save('messenger_recipient', trim((string)($_POST['messenger_recipient'] ?? '')));
    sh_setting_save('messenger_enabled', !empty($_POST['messenger_enabled']) ? '1' : '0');
    sh_log_line('admin', 'Messenger settings updated');
    sh_flash('success', 'Messenger settings saved.');
    sh_redirect('admin/messenger.php');
}

$enabled = sh_setting('messenger_enabled', '0') === '1';
$recipient = (string)sh_setting('messenger_recipient', '');
$hasToken = (string)sh_setting('messenger_token', '') !== '';
$configured = $hasToken && $recipient !== '';
$recent = sh_all("SELECT * FROM notification_logs WHERE channel = 'messenger' ORDER BY id DESC LIMIT 12");

$adminPage = 'messenger';
$adminTitle = 'Messenger Notifications';
require __DIR__ . '/_layout.php';
?>
<div class="sh-alert <?= $configured ? 'sh-alert--success' : 'sh-alert--warning' ?>">
  <?= sh_icon($configured ? 'check-circle' : 'alert', 17) ?>
  <span><?php if ($configured): ?>
    Facebook Messenger is configured<?= $enabled ? ' and enabled' : ' but currently disabled' ?>.
  <?php else: ?>
    <strong>Not configured.</strong> Messenger notifications are skipped and logged as skipped. Nothing else on the store is affected.
  <?php endif; ?></span>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-msggrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> Messenger Send API</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:13px">
        Requires a Facebook Page, a Page access token with the pages_messaging permission, and the recipient PSID.
        Meta only allows messages to people who have already messaged your Page.
      </p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="messenger_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Send order notifications to Messenger</span>
        </label>
        <div class="sh-field">
          <label class="sh-field__label" for="ms-tok">Page access token</label>
          <input class="sh-input" id="ms-tok" type="password" name="messenger_token" autocomplete="new-password"
                 placeholder="<?= $hasToken ? 'A token is saved — leave blank to keep it' : 'EAAG...' ?>">
          <span class="sh-field__hint">Stored server-side only; never exposed to the browser.</span>
        </div>
        <?php if ($hasToken): ?>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_token" value="1"><span>Remove the stored token</span></label>
        <?php endif; ?>
        <div class="sh-field">
          <label class="sh-field__label" for="ms-to">Recipient PSID</label>
          <input class="sh-input" id="ms-to" name="messenger_recipient" value="<?= e($recipient) ?>" placeholder="1234567890123456">
        </div>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Messenger settings</button>
      </form>
    </div>
  </div>
  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Send test message</h2></div>
    <div class="sh-panel__body">
      <?php if (!$configured): ?>
          <p class="sh-panel__note" style="margin-bottom:10px">
            Save the credentials above first — the test button turns on once this channel is configured.</p>
        <?php else: ?>
          <p class="sh-panel__note" style="margin-bottom:10px">Sends a real message and reports the provider's actual response.</p>
        <?php endif; ?>
        <button class="sh-btn sh-btn--block" type="button" data-test-channel="messenger" <?= $configured ? '' : 'disabled' ?>>
          <?= sh_icon('send', 15) ?> Send test message</button>
        <p id="test-result" style="margin-top:10px;font-size:12.7px"></p>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Recent Messenger deliveries</h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Event</th><th>Status</th><th>Detail</th><th>Time</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr class="sh-table--empty"><td colspan="4">Nothing attempted yet.</td></tr>
      <?php else: foreach ($recent as $l): ?>
        <tr><td><?= e(str_replace('_', ' ', $l['event'])) ?></td>
          <td><span class="sh-badge <?= $l['status'] === 'sent' ? 'sh-badge--ok' : ($l['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e($l['status']) ?></span></td>
          <td class="sh-table__meta" style="max-width:420px"><?= e((string)($l['error_message'] ?? '')) ?></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>@media (max-width: 1000px){.sh-msggrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
