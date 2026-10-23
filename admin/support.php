<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/support.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('support.view');

sh_support_ensure_schema();
$cfg = sh_support_cfg();
$admin = sh_admin();
$adminId = (int)($admin['id'] ?? 0);

$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'open', 'pending', 'resolved', 'closed'], true)) { $status = ''; }
$assigned = (string)($_GET['assigned'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = ['status' => $status, 'q' => $q];
if ($assigned === 'me') { $filters['assigned'] = $adminId; }
elseif ($assigned === 'unassigned') { $filters['assigned'] = 'unassigned'; }

$box = sh_support_inbox($filters, $page, 20);
$stats = sh_support_stats();

$base = 'support.php?status=' . urlencode($status) . '&assigned=' . urlencode($assigned) . '&q=' . urlencode($q);

$adminPage = 'support';
$adminTitle = 'Support Inbox';
require __DIR__ . '/_layout.php';

$langNames = ['bn' => 'বাংলা', 'banglish' => 'Banglish', 'en' => 'English'];
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> Support Inbox</h2>
    <a class="sh-btn sh-btn-soft" href="support-settings.php"><?= sh_icon('settings', 15) ?> Support Settings</a>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note" style="margin-bottom:12px">
      Open: <strong><?= (int)$stats['open'] ?></strong> · Waiting for a human: <strong><?= (int)$stats['pending'] ?></strong> ·
      Unassigned: <strong><?= (int)$stats['unassigned'] ?></strong> · Unread customer messages: <strong><?= (int)$stats['unread'] ?></strong>
      <?php if (!$cfg['enabled']): ?><br><span class="sh-badge sh-badge--warn">Support chat is disabled in Support Settings — customers cannot open it.</span><?php endif; ?>
    </p>

    <form method="get" class="sh-admin-filters" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <input class="sh-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, phone, order, message…" style="flex:1 1 240px">
      <select class="sh-input" name="status" aria-label="Status">
        <option value="" <?= $status === '' ? 'selected' : '' ?>>Open + pending</option>
        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open (AI active)</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending (needs human)</option>
        <option value="resolved" <?= $status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
        <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Closed</option>
      </select>
      <select class="sh-input" name="assigned" aria-label="Assignment">
        <option value="" <?= $assigned === '' ? 'selected' : '' ?>>All assignees</option>
        <option value="me" <?= $assigned === 'me' ? 'selected' : '' ?>>Assigned to me</option>
        <option value="unassigned" <?= $assigned === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
      </select>
      <button class="sh-btn sh-btn-primary" type="submit"><?= sh_icon('search', 15) ?> Filter</button>
    </form>

    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Customer</th><th>Last message</th><th>State</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if ($box['rows'] === []): ?>
          <tr class="sh-table--empty"><td colspan="5">No conversations found. New customer chats from the storefront widget will appear here.</td></tr>
        <?php else: foreach ($box['rows'] as $c): ?>
          <tr>
            <td>
              <strong><?= e($c['customer_name'] !== '' ? $c['customer_name'] : ('Conversation #' . (int)$c['id'])) ?></strong>
              <?php if ((int)$c['unread_admin'] > 0): ?><span class="sh-badge sh-badge--warn"><?= (int)$c['unread_admin'] ?> new</span><?php endif; ?>
              <div class="sh-table__meta">
                <?= !empty($c['user_id']) ? 'Registered customer' : 'Guest' ?>
                <?php if ($c['customer_email'] !== ''): ?> · <?= e($c['customer_email']) ?><?php endif; ?>
                <?php if ($c['customer_phone'] !== ''): ?> · <?= e($c['customer_phone']) ?><?php endif; ?>
              </div>
              <div class="sh-table__meta"><?= e($langNames[$c['lang']] ?? $c['lang']) ?><?php if ($c['last_order_number'] !== ''): ?> · Order <?= e($c['last_order_number']) ?><?php endif; ?></div>
            </td>
            <td>
              <span class="sh-table__meta"><?= $c['last_sender'] === 'customer' ? 'Customer: ' : ($c['last_sender'] === 'admin' ? 'Agent: ' : 'Support: ') ?></span>
              <?= e(mb_substr(trim((string)($c['last_body'] ?? '')), 0, 140)) ?>
            </td>
            <td>
              <?php if ($c['status'] === 'pending' || !empty($c['ai_paused'])): ?>
                <span class="sh-badge sh-badge--warn">Human</span>
              <?php elseif ($c['status'] === 'resolved'): ?>
                <span class="sh-badge sh-badge--ok">Resolved</span>
              <?php elseif ($c['status'] === 'closed'): ?>
                <span class="sh-badge">Closed</span>
              <?php else: ?>
                <span class="sh-badge sh-badge--ok">AI active</span>
              <?php endif; ?>
              <div class="sh-table__meta"><?= $c['assigned_name'] ? 'Assigned: ' . e($c['assigned_name']) : 'Unassigned' ?></div>
            </td>
            <td><?= e(date('d M, h:i A', strtotime((string)$c['last_message_at']) ?: time())) ?></td>
            <td><a class="sh-btn sh-btn-soft" href="support-conversation.php?id=<?= (int)$c['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?= sh_paginate((int)$box['total'], (int)$box['per'], (int)$box['page'], $base) ?>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
