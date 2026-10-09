<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/telegram.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();

    if (sh_post('form') === 'set_webhook') {
        if (sh_tg_webhook_secret() === '') {
            sh_setting_save('telegram_webhook_secret', bin2hex(random_bytes(16)));
            sh_settings(true);
        }
        $r = sh_tg_set_webhook();
        sh_flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
            ? 'Webhook registered with Telegram. Button presses will now reach your site.'
            : 'Telegram refused the webhook: ' . (string)($r['error'] ?? 'unknown error'));
        sh_redirect('admin/telegram.php');
    }
    if (sh_post('form') === 'delete_webhook') {
        $r = sh_tg_delete_webhook();
        sh_flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
            ? 'Webhook removed. Inline buttons will stop working until you register it again.'
            : 'Could not remove the webhook: ' . (string)($r['error'] ?? 'unknown error'));
        sh_redirect('admin/telegram.php');
    }
    $token = trim((string)($_POST['telegram_bot_token'] ?? ''));
    // Empty submission keeps the stored token — the field is never pre-filled with it.
    if ($token !== '') {
        // Be permissive: accept the documented shape (digits, colon, secret) without
        // hard-coding a secret length Telegram has never guaranteed.
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            $errors['telegram_bot_token'] = 'That does not look like a Telegram bot token. '
                . 'It should be your numeric bot ID, a colon, then the secret — for example 123456789:AAHxyz... '
                . 'Copy the whole token from @BotFather without spaces.';
        } else {
            sh_setting_save('telegram_bot_token', $token);
        }
    }
    if (!empty($_POST['clear_token'])) { sh_setting_save('telegram_bot_token', ''); }
    sh_setting_save('telegram_chat_id', trim((string)($_POST['telegram_chat_id'] ?? '')));
    sh_setting_save('telegram_enabled', !empty($_POST['telegram_enabled']) ? '1' : '0');

    // Admin control panel settings.
    $ids = trim((string)($_POST['telegram_admin_ids'] ?? ''));
    if ($ids !== '' && !preg_match('/^[\d,\s;-]+$/', $ids)) {
        $errors['telegram_admin_ids'] = 'Admin IDs must be numeric Telegram user IDs separated by commas.';
    } else {
        sh_setting_save('telegram_admin_ids', $ids);
    }
    sh_setting_save('telegram_debug', !empty($_POST['telegram_debug']) ? '1' : '0');
    if (!$errors) {
        sh_log_line('admin', 'Telegram settings updated');
        sh_flash('success', 'Telegram settings saved.');
        sh_redirect('admin/telegram.php');
    }
}

$enabled = sh_setting('telegram_enabled', '0') === '1';
$chatId = (string)sh_setting('telegram_chat_id', '');
$token = (string)sh_setting('telegram_bot_token', '');
$hasToken = $token !== '';
$configured = $hasToken && $chatId !== '';

$recent = sh_all("SELECT * FROM notification_logs WHERE channel = 'telegram' ORDER BY id DESC LIMIT 12");

$tgAdminIds = (string)sh_setting('telegram_admin_ids', '');
$tgDebug    = (string)sh_setting('telegram_debug', '0') === '1';
$tgHealth   = sh_tg_health();
$tgHealthOk = true;
foreach ($tgHealth as $h) { if (!$h['ok']) { $tgHealthOk = false; } }
$tgHookInfo = null;
if ($hasToken) {
    $info = sh_tg_webhook_info();
    if (!empty($info['ok']) && is_array($info['result'] ?? null)) { $tgHookInfo = $info['result']; }
}
$tgLog = [];
try { $tgLog = sh_all('SELECT * FROM telegram_admin_log ORDER BY id DESC LIMIT 12'); } catch (Throwable $e) {}

$adminPage = 'telegram';
$adminTitle = 'Telegram Notifications';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-alert <?= $configured ? 'sh-alert--success' : 'sh-alert--warning' ?>">
  <?= sh_icon($configured ? 'check-circle' : 'alert', 17) ?>
  <span><?php if ($configured): ?>
    Telegram is configured<?= $enabled ? ' and enabled' : ' but currently disabled' ?>.
  <?php else: ?>
    <strong>Not configured.</strong> Add a bot token and chat ID below. Until then Telegram messages are skipped and logged as
    skipped — the rest of the store keeps working normally.
  <?php endif; ?></span>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-msggrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('send', 17) ?> Bot configuration</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="telegram_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span>
          <span>Send order notifications to Telegram</span>
        </label>

        <div class="sh-field">
          <label class="sh-field__label" for="tg-token">Bot token</label>
          <input class="sh-input" id="tg-token" type="password" name="telegram_bot_token" autocomplete="new-password"
                 placeholder="<?= $hasToken ? 'A token is saved — leave blank to keep it' : '123456789:AAExampleTokenFromBotFather' ?>">
          <span class="sh-field__hint">
            <?php if ($hasToken): ?>
              A bot token is stored. It is never displayed here and never sent to the browser.
            <?php else: ?>
              Create a bot with @BotFather in Telegram and paste the full token.
            <?php endif; ?>
          </span>
        </div>
        <?php if ($hasToken): ?>
          <label class="sh-check" style="margin-bottom:12px">
            <input type="checkbox" name="clear_token" value="1"><span>Remove the stored bot token</span></label>
        <?php endif; ?>

        <div class="sh-field">
          <label class="sh-field__label" for="tg-chat">Chat ID</label>
          <input class="sh-input" id="tg-chat" name="telegram_chat_id" value="<?= e($chatId) ?>" placeholder="-1001234567890">
          <span class="sh-field__hint">Your personal chat ID, or a group/channel ID (starts with -100). Add the bot to the group first.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="tg-admins">Admin Telegram user IDs</label>
          <input class="sh-input" id="tg-admins" name="telegram_admin_ids" value="<?= e($tgAdminIds) ?>"
                 placeholder="123456789, 987654321">
          <span class="sh-field__hint">Numeric user IDs, comma separated. <strong>Only these accounts</strong> can press the
            order buttons. Send <code>/start</code> to <code>@userinfobot</code> in Telegram to find your ID. Usernames are
            never used because they can change.</span>
        </div>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="telegram_debug" value="1" <?= $tgDebug ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Webhook debug logging (turn off in production)</span>
        </label>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save Telegram settings</button>
      </form>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Send test message</h2></div>
      <div class="sh-panel__body">
        <?php if (!$configured): ?>
          <p class="sh-panel__note" style="margin-bottom:10px">
            Save the credentials above first — the test button turns on once this channel is configured.</p>
        <?php else: ?>
          <p class="sh-panel__note" style="margin-bottom:10px">Sends a real message and reports the provider's actual response.</p>
        <?php endif; ?>
        <button class="sh-btn sh-btn--block" type="button" data-test-channel="telegram" <?= $configured ? '' : 'disabled' ?>>
          <?= sh_icon('send', 15) ?> Send test message</button>
        <p id="tg-result" style="margin-top:10px;font-size:12.7px"></p>
      </div>
    </div>
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> Which events send?</h2></div>
      <div class="sh-panel__body">
        <p class="sh-panel__note">Per-event switches live on the
          <a href="<?= e(sh_url('admin/notifications.php')) ?>">Notifications</a> page.</p>
      </div>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> Admin control panel (inline buttons)</h2>
    <div class="sh-panel__actions">
      <?php if ($hasToken): ?>
        <form method="post"><?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="set_webhook">
          <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('zap', 14) ?> Register webhook</button>
        </form>
        <form method="post" data-confirm="Remove the Telegram webhook? Inline buttons will stop working.">
          <?= sh_csrf_field() ?><input type="hidden" name="form" value="delete_webhook">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Remove</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:12px">
      With the webhook registered, every new order and payment arrives in Telegram as a card with real buttons —
      verify or reject a payment, move to processing, deliver digital codes, complete or cancel. Each button performs the
      same database action as the admin panel, including duplicate protection.
    </p>
    <div class="sh-field">
      <label class="sh-field__label" for="tg-url">Webhook URL</label>
      <input class="sh-input" id="tg-url" value="<?= e(sh_tg_webhook_url()) ?>" readonly onclick="this.select()">
    </div>
    <?php if ($tgHookInfo !== null): ?>
      <div class="sh-grid2">
        <div>
          <p class="sh-field__label">Registered URL</p>
          <p class="sh-panel__note"><?= trim((string)($tgHookInfo['url'] ?? '')) !== ''
              ? e((string)$tgHookInfo['url']) : 'Not registered yet.' ?></p>
        </div>
        <div>
          <p class="sh-field__label">Pending updates</p>
          <p class="sh-panel__note"><?= (int)($tgHookInfo['pending_update_count'] ?? 0) ?></p>
        </div>
      </div>
      <?php if (!empty($tgHookInfo['last_error_message'])): ?>
        <p class="sh-panel__note" style="color:#c62828">
          Telegram reported: <?= e((string)$tgHookInfo['last_error_message']) ?></p>
      <?php endif; ?>
    <?php endif; ?>
    <p class="sh-panel__note" style="margin-top:10px">
      Commands for authorised admins: <code>/help</code>, <code>/order 12345</code>,
      <code>/status 12345</code>, <code>/pending</code>, <code>/today</code>.
    </p>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon($tgHealthOk ? 'check-circle' : 'alert', 17) ?> Health check</h2>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($tgHealth as $h): ?>
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
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Telegram admin actions</h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Order</th><th>Admin</th><th>Action</th><th>Change</th><th>Result</th><th>Time</th></tr></thead>
      <tbody>
      <?php if (!$tgLog): ?>
        <tr class="sh-table--empty"><td colspan="6">No Telegram actions recorded yet.</td></tr>
      <?php else: foreach ($tgLog as $l): ?>
        <tr>
          <td><?php if (!empty($l['order_id'])): ?>
            <a href="<?= e(sh_url('admin/orders.php?id=' . (int)$l['order_id'])) ?>">#<?= (int)$l['order_id'] ?></a>
          <?php else: ?><span class="sh-table__meta">—</span><?php endif; ?></td>
          <td class="sh-table__meta"><?= e((string)($l['telegram_name'] ?: $l['telegram_admin_id'])) ?></td>
          <td><?= e(str_replace('_', ' ', (string)$l['action'])) ?></td>
          <td class="sh-table__meta"><?= e((string)($l['previous_status'] ?? '—')) ?> &rarr; <?= e((string)($l['new_status'] ?? '—')) ?></td>
          <td><span class="sh-badge <?= $l['result'] === 'success' ? 'sh-badge--ok' : ($l['result'] === 'denied' || $l['result'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e((string)$l['result']) ?></span>
            <?php if (!empty($l['reason'])): ?><div class="sh-table__meta"><?= e((string)$l['reason']) ?></div><?php endif; ?></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime((string)$l['created_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Recent Telegram deliveries</h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/notifications.php#log')) ?>">Full log</a></div></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Event</th><th>Status</th><th>Detail</th><th>Time</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?><tr class="sh-table--empty"><td colspan="4">No Telegram messages have been attempted yet.</td></tr>
      <?php else: foreach ($recent as $l): ?>
        <tr>
          <td><?= e(str_replace('_', ' ', $l['event'])) ?></td>
          <td><span class="sh-badge <?= $l['status'] === 'sent' ? 'sh-badge--ok' : ($l['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e($l['status']) ?></span></td>
          <td class="sh-table__meta" style="max-width:420px"><?= e((string)($l['error_message'] ?? '')) ?></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<style>@media (max-width: 1000px){.sh-msggrid{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
