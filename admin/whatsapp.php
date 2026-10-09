<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/whatsapp.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'generate_verify') {
        sh_setting_save('whatsapp_verify_token', sh_wa_generate_verify_token());
        sh_log_line('admin', 'WhatsApp webhook verify token regenerated');
        sh_flash('success', 'A new verify token was generated. Paste it into Meta and re-verify the webhook.');
        sh_redirect('admin/whatsapp.php');
    }

    if ($form === 'test_connection') {
        $testResult = sh_wa_test_connection();
        sh_flash($testResult['ok'] ? 'success' : 'error', $testResult['ok']
            ? 'Connected to Meta as ' . ($testResult['name'] ?: 'your business') . ' (' . ($testResult['number'] ?: 'number hidden') . ').'
            : 'Meta rejected the request: ' . $testResult['error']);
        sh_redirect('admin/whatsapp.php');
    }

    if ($form === 'clear_log') {
        try {
            sh_query('DELETE FROM whatsapp_messages WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
            sh_query('DELETE FROM whatsapp_message_statuses WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
            sh_flash('success', 'Webhook records older than 30 days were removed.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'wa-prune');
            sh_flash('error', 'Those records could not be removed.');
        }
        sh_redirect('admin/whatsapp.php');
    }

    // --- default: save settings ---
    // Secrets are encrypted at rest and never rendered back into the page.
    $tok = trim((string)($_POST['whatsapp_token'] ?? ''));
    if ($tok !== '') { sh_setting_save('whatsapp_token', sh_wa_encrypt($tok)); }
    if (!empty($_POST['clear_token'])) { sh_setting_save('whatsapp_token', ''); }

    $sec = trim((string)($_POST['whatsapp_app_secret'] ?? ''));
    if ($sec !== '') { sh_setting_save('whatsapp_app_secret', sh_wa_encrypt($sec)); }
    if (!empty($_POST['clear_secret'])) { sh_setting_save('whatsapp_app_secret', ''); }

    $verify = trim((string)($_POST['whatsapp_verify_token'] ?? ''));
    if ($verify !== '') { sh_setting_save('whatsapp_verify_token', $verify); }

    $ver = trim((string)($_POST['whatsapp_api_version'] ?? ''));
    if ($ver !== '' && !preg_match('/^v\d+\.\d+$/', $ver)) {
        sh_flash('error', 'API version should look like v21.0.');
        sh_redirect('admin/whatsapp.php');
    }
    sh_setting_save('whatsapp_api_version', $ver !== '' ? $ver : SH_WA_DEFAULT_API_VERSION);

    sh_setting_save('whatsapp_phone_id', trim((string)($_POST['whatsapp_phone_id'] ?? '')));
    sh_setting_save('whatsapp_business_id', trim((string)($_POST['whatsapp_business_id'] ?? '')));
    sh_setting_save('whatsapp_recipient', trim((string)($_POST['whatsapp_recipient'] ?? '')));
    sh_setting_save('whatsapp_enabled', !empty($_POST['whatsapp_enabled']) ? '1' : '0');
    sh_setting_save('whatsapp_webhook_debug', !empty($_POST['whatsapp_webhook_debug']) ? '1' : '0');

    sh_log_line('admin', 'WhatsApp settings updated');
    sh_flash('success', 'WhatsApp settings saved.');
    sh_redirect('admin/whatsapp.php');
}

$enabled = sh_setting('whatsapp_enabled', '0') === '1';
$phoneId = (string)sh_setting('whatsapp_phone_id', '');
$recipient = (string)sh_setting('whatsapp_recipient', '');
$hasToken = (string)sh_setting('whatsapp_token', '') !== '';
$configured = $hasToken && $phoneId !== '' && $recipient !== '';
$recent = sh_all("SELECT * FROM notification_logs WHERE channel = 'whatsapp' ORDER BY id DESC LIMIT 12");

$waCfg = sh_wa_config();
$hasSecret = (string)sh_setting('whatsapp_app_secret', '') !== '';
$lastHook = (string)sh_setting('whatsapp_last_webhook', '');
$lastErr  = (string)sh_setting('whatsapp_last_error', '');
$health   = sh_wa_health();
$healthOk = true;
foreach ($health as $h) { if (!$h['ok']) { $healthOk = false; } }

$waInbox = []; $waCount = 0;
try {
    $waInbox = sh_all('SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 10');
    $waCount = (int)sh_val('SELECT COUNT(*) FROM whatsapp_messages', [], 0);
} catch (Throwable $e) { /* tables may not exist yet; health check reports it */ }

$adminPage = 'whatsapp';
$adminTitle = 'WhatsApp Notifications';
require __DIR__ . '/_layout.php';
?>
<div class="sh-alert <?= $configured ? 'sh-alert--success' : 'sh-alert--warning' ?>">
  <?= sh_icon($configured ? 'check-circle' : 'alert', 17) ?>
  <span><?php if ($configured): ?>
    WhatsApp Cloud API is configured<?= $enabled ? ' and enabled' : ' but currently disabled' ?>.
  <?php else: ?>
    <strong>Not configured.</strong> WhatsApp messages are skipped and recorded as skipped in the log.
    Orders, payments and every other part of the store continue to work normally.
  <?php endif; ?></span>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-msggrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> WhatsApp Cloud API</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:13px">
        Uses the official Meta WhatsApp Cloud API. Create an app at developers.facebook.com, add the WhatsApp product,
        then copy the phone number ID and a permanent access token.
      </p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="whatsapp_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Send order notifications to WhatsApp</span>
        </label>
        <div class="sh-field">
          <label class="sh-field__label" for="wa-pid">Phone number ID</label>
          <input class="sh-input" id="wa-pid" name="whatsapp_phone_id" value="<?= e($phoneId) ?>" placeholder="123456789012345">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="wa-tok">Permanent access token</label>
          <input class="sh-input" id="wa-tok" type="password" name="whatsapp_token" autocomplete="new-password"
                 placeholder="<?= $hasToken ? 'A token is saved — leave blank to keep it' : 'EAAG...' ?>">
          <span class="sh-field__hint">Stored server-side only and never rendered back into the page.</span>
        </div>
        <?php if ($hasToken): ?>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_token" value="1"><span>Remove the stored token</span></label>
        <?php endif; ?>
        <div class="sh-field">
          <label class="sh-field__label" for="wa-to">Notification recipient</label>
          <input class="sh-input" id="wa-to" name="whatsapp_recipient" value="<?= e($recipient) ?>" placeholder="8801XXXXXXXXX">
          <span class="sh-field__hint">Full international number without a plus sign.</span>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="wa-baid">Business account ID</label>
            <input class="sh-input" id="wa-baid" name="whatsapp_business_id" value="<?= e($waCfg['business_id']) ?>" placeholder="Optional">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="wa-ver">Graph API version</label>
            <input class="sh-input" id="wa-ver" name="whatsapp_api_version" value="<?= e($waCfg['api_version']) ?>" placeholder="<?= e(SH_WA_DEFAULT_API_VERSION) ?>">
          </div>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="wa-verify">Webhook verify token</label>
          <input class="sh-input" id="wa-verify" name="whatsapp_verify_token" value="<?= e($waCfg['verify_token']) ?>"
                 placeholder="Any secret string you also type into Meta">
          <span class="sh-field__hint">This is <strong>not</strong> the access token. You invent it here and paste the same value into Meta.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="wa-secret">App secret <span class="sh-field__hint" style="font-weight:400">(optional, enables signature checks)</span></label>
          <input class="sh-input" id="wa-secret" type="password" name="whatsapp_app_secret" autocomplete="new-password"
                 placeholder="<?= $hasSecret ? 'A secret is saved — leave blank to keep it' : 'From Meta App Settings → Basic' ?>">
          <span class="sh-field__hint">When set, every incoming webhook is verified against Meta's X-Hub-Signature-256 header.</span>
        </div>
        <?php if ($hasSecret): ?>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_secret" value="1"><span>Remove the stored app secret</span></label>
        <?php endif; ?>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="whatsapp_webhook_debug" value="1" <?= $waCfg['debug'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Webhook debug logging (turn off in production)</span>
        </label>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save WhatsApp settings</button>
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
        <button class="sh-btn sh-btn--block" type="button" data-test-channel="whatsapp" <?= $configured ? '' : 'disabled' ?>>
          <?= sh_icon('send', 15) ?> Send test message</button>
        <p id="test-result" style="margin-top:10px;font-size:12.7px"></p>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Webhook (receive customer messages)</h2>
    <div class="sh-panel__actions">
      <form method="post"><?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="test_connection">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('zap', 14) ?> Test configuration</button>
      </form>
    </div>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:12px">
      Paste these two values into <strong>Meta → WhatsApp → Configuration → Webhook</strong>, then click Verify and Save.
      Subscribe to the <code>messages</code> field to receive both incoming messages and delivery statuses.
    </p>

    <div class="sh-field">
      <label class="sh-field__label" for="wa-url">Callback URL</label>
      <input class="sh-input" id="wa-url" value="<?= e(sh_wa_webhook_url()) ?>" readonly onclick="this.select()">
    </div>
    <div class="sh-field">
      <label class="sh-field__label" for="wa-vt">Verify token</label>
      <input class="sh-input" id="wa-vt" value="<?= e($waCfg['verify_token']) ?>" readonly onclick="this.select()"
             placeholder="Not set — save one above or generate it">
    </div>
    <form method="post" style="margin-bottom:14px"
          data-confirm="Generate a new verify token? You must re-verify the webhook in Meta afterwards.">
      <?= sh_csrf_field() ?><input type="hidden" name="form" value="generate_verify">
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('key', 14) ?> Generate a secure token</button>
    </form>

    <div class="sh-grid2">
      <div>
        <p class="sh-field__label">Last webhook received</p>
        <p class="sh-panel__note"><?= $lastHook !== '' ? e($lastHook) : 'Nothing received yet.' ?></p>
      </div>
      <div>
        <p class="sh-field__label">Last webhook error</p>
        <p class="sh-panel__note" style="<?= $lastErr !== '' ? 'color:#c62828' : '' ?>">
          <?= $lastErr !== '' ? e($lastErr) : 'None.' ?></p>
      </div>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon($healthOk ? 'check-circle' : 'alert', 17) ?> Health check</h2>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($health as $h): ?>
          <tr>
            <td><?= e($h['label']) ?></td>
            <td><span class="sh-badge <?= $h['ok'] ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= $h['ok'] ? 'OK' : 'Check' ?></span></td>
            <td class="sh-table__meta"><?= e($h['value']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> Incoming messages (<?= number_format($waCount) ?>)</h2>
    <div class="sh-panel__actions">
      <form method="post" data-confirm="Delete webhook records older than 30 days?">
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="clear_log">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Prune old records</button>
      </form>
    </div>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>From</th><th>Type</th><th>Message</th><th>Status</th><th>Received</th></tr></thead>
      <tbody>
      <?php if (!$waInbox): ?>
        <tr class="sh-table--empty"><td colspan="5">No customer messages received yet.</td></tr>
      <?php else: foreach ($waInbox as $m): ?>
        <tr>
          <td class="sh-table__name"><?= e((string)($m['contact_name'] ?: 'Unknown')) ?>
            <div class="sh-table__meta"><?= e((string)$m['phone_number']) ?></div></td>
          <td><?= e((string)$m['message_type']) ?></td>
          <td class="sh-table__meta" style="max-width:360px"><?= e(sh_excerpt((string)$m['message_text'], 90)) ?></td>
          <td><span class="sh-badge <?= $m['status'] === 'read' ? 'sh-badge--ok' : ($m['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e((string)$m['status']) ?></span></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime((string)$m['created_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Recent WhatsApp deliveries</h2></div>
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
