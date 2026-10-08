<?php
/**
 * Admin — Notification Channels.
 *
 * Event & channel matrix (per-event, per-channel switches), channel readiness
 * status and the delivery log. Uses the existing notification engine tables and
 * helpers; this page only renders and saves the switch preferences.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
$admin = sh_require_admin();
sh_require_perm('integrations.manage');

const SH_CHANNEL_LABELS = [
    'telegram'  => 'Telegram',
    'whatsapp'  => 'WhatsApp',
    'messenger' => 'Messenger',
    'email'     => 'Email',
];
const SH_CHANNEL_ICONS = [
    'telegram'  => 'message',
    'whatsapp'  => 'message',
    'messenger' => 'message',
    'email'     => 'mail',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'save') {
        $old = sh_notification_matrix();
        $posted = $_POST['m'] ?? [];
        $posted = is_array($posted) ? $posted : [];
        $new = [];
        try {
            foreach (SH_CHANNELS as $channel) {
                foreach (array_keys(SH_EVENTS) as $event) {
                    $on = isset($posted[$channel][$event]) ? 1 : 0;
                    sh_query(
                        'INSERT INTO notifications (channel, event, enabled) VALUES (?, ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                        [$channel, $event, $on]
                    );
                    $new[$channel][$event] = $on === 1;
                }
            }
            $oldA = [];
            $newA = [];
            foreach (SH_CHANNELS as $channel) {
                foreach (SH_EVENTS as $event => $label) {
                    $key = $channel . '.' . $event;
                    $oldA[$key] = !empty($old[$channel][$event]) ? 'on' : 'off';
                    $newA[$key] = !empty($new[$channel][$event]) ? 'on' : 'off';
                }
            }
            if ($oldA !== $newA) {
                sh_audit('settings_changed', 'settings', null, 'notification_matrix', $oldA, $newA);
            }
            sh_flash('success', 'Notification preferences saved.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'notify-save');
            sh_flash('error', 'The preferences could not be saved. Please try again.');
        }
        sh_redirect('admin/notifications.php');
    }

    if ($form === 'prune') {
        $days = in_array((int)($_POST['days'] ?? 30), [7, 30, 90], true) ? (int)$_POST['days'] : 30;
        try {
            $n = sh_query('DELETE FROM notification_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days])->rowCount();
            sh_audit('notification_logs_pruned', 'settings', null, 'Entries older than ' . $days . ' days', null, ['deleted' => $n]);
            sh_flash('success', number_format($n) . ' log entries older than ' . $days . ' days were deleted.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'notify-prune');
            sh_flash('error', 'The old entries could not be deleted.');
        }
        sh_redirect('admin/notifications.php');
    }
}

// ---- Matrix state -----------------------------------------------------------
$matrix = sh_notification_matrix();

// ---- Channel readiness (mirrors the checks in the send functions) -----------
$ready = [
    'telegram'  => (string)sh_setting('telegram_enabled', '0') === '1'
        && trim((string)sh_setting('telegram_bot_token', '')) !== ''
        && trim((string)sh_setting('telegram_chat_id', '')) !== '',
    'whatsapp'  => (string)sh_setting('whatsapp_enabled', '0') === '1'
        && trim((string)sh_setting('whatsapp_phone_id', '')) !== ''
        && trim((string)sh_setting('whatsapp_token', '')) !== ''
        && trim((string)sh_setting('whatsapp_recipient', '')) !== '',
    'messenger' => (string)sh_setting('messenger_enabled', '0') === '1'
        && trim((string)sh_setting('messenger_token', '')) !== ''
        && trim((string)sh_setting('messenger_recipient', '')) !== '',
    'email'     => (function (): bool {
        $to = trim((string)sh_setting('admin_notify_email', (string)sh_setting('contact_email', '')));
        return $to !== '' && sh_valid_email($to);
    })(),
];

// ---- Delivery log -----------------------------------------------------------
$fChannel = sh_get('channel');
$fStatus = sh_get('status');
if (!in_array($fChannel, SH_CHANNELS, true)) { $fChannel = ''; }
if (!in_array($fStatus, ['sent', 'failed', 'skipped'], true)) { $fStatus = ''; }

$logWhere = ['1=1'];
$logArgs = [];
if ($fChannel !== '') { $logWhere[] = 'channel = ?'; $logArgs[] = $fChannel; }
if ($fStatus !== '') { $logWhere[] = 'status = ?'; $logArgs[] = $fStatus; }
$logWhereSql = implode(' AND ', $logWhere);

$logTotal = (int)sh_val('SELECT COUNT(*) FROM notification_logs', [], 0);
$logs = sh_all(
    "SELECT * FROM notification_logs WHERE $logWhereSql ORDER BY id DESC LIMIT 100",
    $logArgs
);

$adminPage = 'notifications';
$adminTitle = 'Notification Channels';
require __DIR__ . '/_layout.php';
?>
<form method="post" class="sh-panel sh-mx-panel" id="notification-matrix">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('bell', 17) ?> Event &amp; channel matrix</h2>
  </div>
  <div class="sh-panel__body">
    <p class="sh-mx__note">Choose which channels receive each notification.</p>

    <div class="sh-mx">
      <div class="sh-mx__head" aria-hidden="true">
        <div class="sh-mx__h sh-mx__h--event">Event</div>
        <?php foreach (SH_CHANNELS as $channel): ?>
          <div class="sh-mx__h"><?= e(SH_CHANNEL_LABELS[$channel]) ?></div>
        <?php endforeach; ?>
      </div>

      <?php foreach (SH_EVENTS as $event => $eventLabel): ?>
        <div class="sh-mx__row">
          <div class="sh-mx__event"><?= e($eventLabel) ?></div>
          <?php foreach (SH_CHANNELS as $channel):
            $on = !empty($matrix[$channel][$event]);
            $fid = 'mx-' . $channel . '-' . $event;
          ?>
            <div class="sh-mx__ch">
              <span class="sh-mx__name"><?= e(SH_CHANNEL_LABELS[$channel]) ?></span>
              <label class="sh-toggle sh-mx__toggle" for="<?= e($fid) ?>">
                <input type="checkbox" id="<?= e($fid) ?>" name="m[<?= e($channel) ?>][<?= e($event) ?>]" value="1" <?= $on ? 'checked' : '' ?>>
                <span class="sh-toggle__track"></span>
                <span class="sh-sr-only"><?= e($eventLabel . ' via ' . SH_CHANNEL_LABELS[$channel]) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="sh-mx__save">
      <button class="sh-btn" type="submit">Save preferences</button>
    </div>
  </div>
</form>

<div class="sh-panel sh-chan-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title">Channel status</h2>
  </div>
  <div class="sh-panel__body">
    <div class="sh-chanrow">
      <?php foreach (SH_CHANNELS as $channel): ?>
        <div class="sh-chancard">
          <div class="sh-chancard__head">
            <?= sh_icon(SH_CHANNEL_ICONS[$channel], 15) ?>
            <span class="sh-chancard__name"><?= e(SH_CHANNEL_LABELS[$channel]) ?></span>
          </div>
          <span class="sh-statuspill <?= $ready[$channel] ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $ready[$channel] ? 'Configured' : 'Not configured' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="sh-panel" id="delivery-log">
  <div class="sh-panel__head sh-panel__head--wrap">
    <h2 class="sh-panel__title">Delivery log (<?= number_format($logTotal) ?>)</h2>
    <form method="post" class="sh-inline-form sh-log-prune" data-confirm="Delete delivery log entries older than the selected period? This cannot be undone.">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="prune">
      <select class="sh-select sh-select--sm" name="days" aria-label="Age of entries to prune">
        <option value="7">older than 7 days</option>
        <option value="30" selected>older than 30 days</option>
        <option value="90">older than 90 days</option>
      </select>
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Prune old entries</button>
    </form>
  </div>
  <div class="sh-panel__body">
    <form class="sh-filterbar sh-log-filter" method="get">
      <div class="sh-field">
        <label class="sh-field__label" for="f-channel">Channel</label>
        <select class="sh-select" id="f-channel" name="channel">
          <option value="">All</option>
          <?php foreach (SH_CHANNELS as $channel): ?>
            <option value="<?= e($channel) ?>" <?= $fChannel === $channel ? 'selected' : '' ?>><?= e(SH_CHANNEL_LABELS[$channel]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="f-status">Status</label>
        <select class="sh-select" id="f-status" name="status">
          <option value="">All</option>
          <?php foreach (['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped'] as $v => $l): ?>
            <option value="<?= e($v) ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="sh-btn sh-btn--ghost" type="submit"><?= sh_icon('search', 14) ?> Filter</button>
    </form>
  </div>

  <div class="sh-table-wrap sh-log-wrap">
    <table class="sh-table sh-log-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>Channel</th>
          <th>Event</th>
          <th>Recipient</th>
          <th>Status</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr class="sh-table--empty"><td colspan="6">No log entries.</td></tr>
      <?php else: foreach ($logs as $r):
        $label = SH_EVENTS[$r['event']] ?? ucwords(str_replace('_', ' ', (string)$r['event']));
        $statusCls = $r['status'] === 'sent' ? 'sh-badge--ok' : ($r['status'] === 'failed' ? 'sh-badge--bad' : 'sh-badge--muted');
      ?>
        <tr>
          <td class="sh-table__meta" data-label="Time"><?= e(date('d M Y H:i', strtotime((string)$r['created_at']))) ?></td>
          <td data-label="Channel"><?= e(SH_CHANNEL_LABELS[$r['channel']] ?? ucfirst((string)$r['channel'])) ?></td>
          <td data-label="Event"><?= e($label) ?></td>
          <td class="sh-table__meta" data-label="Recipient"><?= e((string)($r['recipient'] ?? '')) ?: '—' ?></td>
          <td data-label="Status"><span class="sh-badge <?= $statusCls ?>"><?= e(ucfirst((string)$r['status'])) ?></span></td>
          <td class="sh-table__meta" data-label="Details"><?= e((string)($r['error_message'] ?? '')) ?: '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
