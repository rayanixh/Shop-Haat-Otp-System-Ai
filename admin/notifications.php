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
// Safe additive migration for idempotent initial-order summary and image deliveries.
sh_notification_log_schema_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('form') === 'matrix') {
        $on = $_POST['on'] ?? [];
        $on = is_array($on) ? $on : [];
        try {
            foreach (SH_EVENTS as $event => $_label) {
                foreach (SH_CHANNELS as $channel) {
                    $enabled = isset($on[$event][$channel]) ? 1 : 0;
                    $exists = sh_one('SELECT id FROM notifications WHERE event = ? AND channel = ? LIMIT 1', [$event, $channel]);
                    if ($exists) { sh_query('UPDATE notifications SET enabled = ? WHERE id = ?', [$enabled, (int)$exists['id']]); }
                    else { sh_insert('notifications', ['event' => $event, 'channel' => $channel, 'enabled' => $enabled]); }
                }
            }
            sh_log_line('admin', 'Notification matrix updated');
            sh_flash('success', 'Notification preferences saved.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'notif-matrix');
            sh_flash('error', 'The preferences could not be saved.');
        }
        sh_redirect('admin/notifications.php');
    }
    if (sh_post('form') === 'clear_log') {
        sh_query('DELETE FROM notification_logs WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        sh_flash('success', 'Log entries older than 30 days were removed.');
        sh_redirect('admin/notifications.php');
    }
    if (sh_post('form') === 'retry_order_notification') {
        $logId = sh_int($_POST['log_id'] ?? 0);
        $retry = sh_retry_order_notification($logId);
        sh_log_line('admin', 'Order notification retry requested for notification log ' . $logId);
        if (!empty($retry['ok'])) {
            $detail = trim((string)($retry['error'] ?? ''));
            sh_flash('success', $detail === ''
                ? 'The failed notification was retried.'
                : 'The provider accepted the retry. Note: ' . $detail);
        } else {
            sh_flash('error', (string)($retry['error'] ?? 'The notification could not be retried.'));
        }
        sh_redirect('admin/notifications.php?status=failed');
    }
}

$matrix = [];
foreach (sh_all('SELECT event, channel, enabled FROM notifications') as $r) {
    $matrix[$r['event']][$r['channel']] = (int)$r['enabled'] === 1;
}

$channelState = [
    'telegram'  => sh_setting('telegram_enabled', '0') === '1' && (string)sh_setting('telegram_bot_token', '') !== '' && (string)sh_setting('telegram_chat_id', '') !== '',
    'whatsapp'  => sh_setting('whatsapp_enabled', '0') === '1' && (string)sh_setting('whatsapp_token', '') !== '',
    'messenger' => sh_setting('messenger_enabled', '0') === '1' && (string)sh_setting('messenger_token', '') !== '',
    'email'     => sh_setting('email_enabled', '0') === '1' && (string)sh_setting('admin_notify_email', '') !== '',
];
$channelLabels = [
    'telegram' => 'Telegram',
    'whatsapp' => 'WhatsApp',
    'messenger' => 'Messenger',
    'email' => 'Email',
];
$channelPage = ['telegram' => 'telegram.php', 'whatsapp' => 'whatsapp.php', 'messenger' => 'messenger.php', 'email' => 'email.php'];

$fChannel = sh_get('channel');
$fStatus = sh_get('status');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 30;
$where = ['1=1']; $args = [];
if (in_array($fChannel, SH_CHANNELS, true)) { $where[] = 'channel = ?'; $args[] = $fChannel; }
if (in_array($fStatus, ['sent', 'failed', 'skipped'], true)) { $where[] = 'status = ?'; $args[] = $fStatus; }
$whereSql = implode(' AND ', $where);
$logTotal = (int)sh_val("SELECT COUNT(*) FROM notification_logs WHERE $whereSql", $args, 0);
$logPages = max(1, (int)ceil($logTotal / $per));
$page = min($page, $logPages);
$logs = sh_all(
    "SELECT l.*, o.order_number FROM notification_logs l
     LEFT JOIN orders o ON o.id = l.order_id
     WHERE $whereSql ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);

$adminPage = 'notifications';
$adminTitle = 'Notifications';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel sh-notif-status">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('bell', 17) ?> Channel status</h2>
  </div>
  <div class="sh-panel__body sh-chanrow">
    <?php foreach ($channelState as $ch => $ready): ?>
      <a class="sh-chancard" href="<?= e(sh_url('admin/' . $channelPage[$ch])) ?>">
        <span class="sh-chancard__head">
          <?= sh_icon($ch === 'email' ? 'mail' : ($ch === 'telegram' ? 'send' : 'message'), 16) ?>
          <span class="sh-chancard__name"><?= e($channelLabels[$ch] ?? ucfirst($ch)) ?></span>
        </span>
        <span class="sh-statuspill <?= $ready ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
          <?= $ready ? 'Active' : 'Not configured' ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="sh-panel sh-mx-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> Event and channel matrix</h2>
  </div>
  <div class="sh-panel__body sh-mx-panel__body">
    <p class="sh-mx__note">Choose which channels receive each notification.</p>
    <form class="sh-mx-form" method="post">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="matrix">
      <div class="sh-mx" role="table" aria-label="Notification event and channel preferences">
        <div class="sh-mx__head" role="row">
          <span class="sh-mx__h" role="columnheader">Event</span>
          <?php foreach (SH_CHANNELS as $ch): ?>
            <span class="sh-mx__h" role="columnheader"><?= e($channelLabels[$ch] ?? ucfirst($ch)) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="sh-mx__body" role="rowgroup">
          <?php foreach (SH_EVENTS as $event => $eventName): ?>
            <div class="sh-mx__row" role="row">
              <span class="sh-mx__event" role="rowheader"><?= e($eventName) ?></span>
              <?php foreach (SH_CHANNELS as $ch): ?>
                <div class="sh-mx__ch" role="cell">
                  <span class="sh-mx__name" aria-hidden="true"><?= e($channelLabels[$ch] ?? ucfirst($ch)) ?></span>
                  <label class="sh-toggle sh-mx__toggle">
                    <input type="checkbox" name="on[<?= e($event) ?>][<?= e($ch) ?>]" value="1"
                           <?= !empty($matrix[$event][$ch]) ? 'checked' : '' ?>>
                    <span class="sh-toggle__track" aria-hidden="true"></span>
                    <span class="sh-sr-only"><?= e($eventName . ' — ' . ($channelLabels[$ch] ?? ucfirst($ch))) ?></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="sh-mx__save">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save preferences</button>
      </div>
    </form>
  </div>
</div>

<div class="sh-panel sh-notif-log" id="log">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Delivery log (<?= number_format($logTotal) ?>)</h2>
    <div class="sh-panel__actions">
      <form method="post" data-confirm="Delete log entries older than 30 days?">
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="clear_log">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Prune old entries</button>
      </form>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-notif-log__filters" method="get">
      <div class="sh-field"><label class="sh-field__label" for="f-ch">Channel</label>
        <select class="sh-select" id="f-ch" name="channel">
          <option value="">All</option>
          <?php foreach (SH_CHANNELS as $ch): ?>
            <option value="<?= e($ch) ?>" <?= $fChannel === $ch ? 'selected' : '' ?>><?= e($channelLabels[$ch] ?? ucfirst($ch)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="f-st">Status</label>
        <select class="sh-select" id="f-st" name="status">
          <option value="">All</option>
          <?php foreach (['sent', 'failed', 'skipped'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-notif-log__actions">
        <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
        <?php if ($fChannel !== '' || $fStatus !== ''): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/notifications.php')) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table sh-notif-log__table">
      <thead><tr><th>Order</th><th>Event</th><th>Channel</th><th>Status</th><th>Detail</th><th>Time</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$logs): ?><tr class="sh-table--empty"><td colspan="7">No log entries.</td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td data-label="Order"><?php if ($l['order_number']): ?>
            <a href="<?= e(sh_url('admin/orders.php?id=' . (int)$l['order_id'])) ?>"><?= e($l['order_number']) ?></a>
          <?php else: ?><span class="sh-table__meta">—</span><?php endif; ?></td>
          <td data-label="Event"><?= e(str_replace('_', ' ', $l['event'])) ?></td>
          <td data-label="Channel"><?= e($channelLabels[$l['channel']] ?? ucfirst($l['channel'])) ?></td>
          <td data-label="Status"><span class="sh-badge <?= $l['status'] === 'sent' ? 'sh-badge--ok' : ($l['status'] === 'failed' ? 'sh-badge--bad' : '') ?>"><?= e($l['status']) ?></span></td>
          <td data-label="Detail" class="sh-table__meta" style="max-width:360px">
            <?= e((string)($l['error_message'] ?? '')) ?>
            <?php if ((int)($l['attempts'] ?? 0) > 1): ?><div>Attempts: <?= (int)$l['attempts'] ?></div><?php endif; ?>
          </td>
          <td data-label="Time" class="sh-table__meta"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
          <td data-label="Action" style="text-align:right">
            <?php
              $retryableEvents = ['order_created', 'payment_submitted', 'order_created_image', 'payment_submitted_image'];
              $retryable = $l['status'] === 'failed'
                && in_array($l['channel'], ['telegram', 'whatsapp', 'messenger'], true)
                && in_array($l['event'], $retryableEvents, true)
                && !empty($l['idempotency_key']);
            ?>
            <?php if ($retryable): ?>
              <form method="post" style="display:inline" data-confirm="Retry this failed <?= e(str_replace('_', ' ', $l['event'])) ?> delivery?">
                <?= sh_csrf_field() ?><input type="hidden" name="form" value="retry_order_notification"><input type="hidden" name="log_id" value="<?= (int)$l['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('refresh', 13) ?> Retry<?= str_ends_with((string)$l['event'], '_image') ? ' image' : '' ?></button>
              </form>
            <?php else: ?><span class="sh-table__meta">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($logPages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($logTotal, $per, $page, sh_url('admin/notifications.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
