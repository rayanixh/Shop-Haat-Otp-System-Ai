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
$canReply = sh_admin_can('support.reply');
$convId = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$conv = sh_support_conversation($convId);

$adminNames = [];
try { foreach (sh_all('SELECT id, name FROM admins') as $a) { $adminNames[(int)$a['id']] = (string)$a['name']; } } catch (Throwable $e) {}

/** Admin-side display shape (includes internal notes; never sent to customers). */
$shape = static function (array $m) use ($adminNames): array {
    $facts = [];
    if (!empty($m['facts_json'])) { $d = json_decode((string)$m['facts_json'], true); if (is_array($d)) { $facts = $d; } }
    $who = match ((string)$m['sender']) {
        'customer' => 'Customer',
        'admin'    => $adminNames[(int)($m['admin_id'] ?? 0)] ?? 'Agent',
        'ai'       => 'AI assistant',
        default    => (($facts['kind'] ?? '') === 'assistant' ? 'Support (automatic, no AI)' : 'System'),
    };
    $meta = '';
    if ((string)$m['sender'] === 'ai') {
        $meta = trim((string)($facts['provider'] ?? '') . ' · ' . (string)($facts['model'] ?? '') . (!empty($facts['ms']) ? ' · ' . (int)$facts['ms'] . 'ms' : '') . (!empty($facts['fallback']) ? ' · fallback provider' : ''));
    } elseif ((string)$m['sender'] === 'system' && ($facts['kind'] ?? '') === 'assistant') {
        $meta = 'Deterministic reply — no model used' . (!empty($facts['intent']) ? ' · intent: ' . (string)$facts['intent'] : '');
    }
    return [
        'id' => (int)$m['id'], 'sender' => (string)$m['sender'],
        'visibility' => (string)$m['visibility'], 'who' => $who, 'meta' => $meta,
        'body' => (string)$m['body'], 'at' => (string)$m['created_at'],
    ];
};

/* --- Poll endpoint (read-only JSON) ----------------------------------- */
if (($_GET['format'] ?? '') === 'json') {
    if ($conv === null) { http_response_code(404); sh_json(['ok' => false, 'error' => 'Conversation not found.']); }
    $after = max(0, (int)($_GET['after_id'] ?? 0));
    $msgs = array_map($shape, sh_support_messages($convId, $after, true, 100));
    if ($msgs !== []) { sh_support_mark_read($convId, 'admin'); }
    sh_json(['ok' => true, 'messages' => $msgs, 'state' => [
        'status' => (string)$conv['status'], 'ai_paused' => !empty($conv['ai_paused']),
        'assigned_admin_id' => (int)($conv['assigned_admin_id'] ?? 0),
    ]]);
}

/* --- Actions ------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    sh_require_perm('support.reply');
    if ($conv === null) { sh_flash('error', 'Conversation not found.'); sh_redirect('admin/support.php'); }
    $act = (string)($_POST['act'] ?? '');
    switch ($act) {
        case 'reply': {
            $text = trim((string)($_POST['message'] ?? ''));
            $internal = !empty($_POST['internal']);
            if ($text === '') { sh_flash('error', 'Write a message first.'); break; }
            if (!$internal && empty($conv['ai_paused'])) {
                // First human reply takes the conversation over so the AI can
                // never talk over the agent (transactional pause + notice).
                sh_support_takeover($convId, $adminId);
            }
            sh_support_admin_reply($convId, $adminId, $text, $internal);
            sh_flash('success', $internal ? 'Internal note saved (customer cannot see it).' : 'Reply sent.');
            break;
        }
        case 'takeover':
            sh_support_takeover($convId, $adminId);
            sh_flash('success', 'You took over this conversation. The AI assistant is paused here.');
            break;
        case 'resume_ai':
            sh_support_resume_ai($convId, $adminId);
            sh_flash('success', 'AI assistant resumed for this conversation.');
            break;
        case 'assign': {
            $to = (int)($_POST['assigned_admin_id'] ?? 0);
            if ($to > 0 && !isset($adminNames[$to])) { sh_flash('error', 'Unknown admin.'); break; }
            sh_support_assign($convId, $adminId, $to);
            sh_flash('success', $to > 0 ? 'Assigned to ' . $adminNames[$to] . '.' : 'Conversation unassigned.');
            break;
        }
        case 'status': {
            $st = (string)($_POST['status'] ?? '');
            sh_support_set_status($convId, $adminId, $st);
            sh_flash('success', 'Status updated.');
            break;
        }
    }
    sh_redirect('admin/support-conversation.php?id=' . $convId);
}

if ($conv === null) { sh_flash('error', 'Conversation not found.'); sh_redirect('admin/support.php'); }
sh_support_mark_read($convId, 'admin');

$messages = array_map($shape, sh_support_messages($convId, 0, true, 500));
$events = [];
try { $events = sh_all('SELECT * FROM support_events WHERE conversation_id = ? ORDER BY id DESC LIMIT 30', [$convId]); } catch (Throwable $e) {}
$admins = [];
try { $admins = sh_all("SELECT id, name FROM admins WHERE status = 'active' ORDER BY name ASC"); } catch (Throwable $e) {}
$lastProduct = !empty($conv['last_product_id']) ? sh_support_tool_product((int)$conv['last_product_id']) : null;
$lastOrderId = 0;
if (!empty($conv['last_order_number'])) {
    try { $lastOrderId = (int)sh_val('SELECT id FROM orders WHERE order_number = ?', [$conv['last_order_number']], 0); } catch (Throwable $e) {}
}
$lastMsgId = $messages !== [] ? (int)end($messages)['id'] : 0;

$adminPage = 'support';
$adminTitle = 'Support Conversation #' . $convId;
$adminScripts = ['assets/js/support-admin.js'];
require __DIR__ . '/_layout.php';
?>
<style>
.sh-supgrid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start}
.sh-supthread{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.sh-supmsg{max-width:85%;border:1px solid var(--sh-line-2);background:var(--sh-bg-2);border-radius:12px;padding:9px 12px}
.sh-supmsg.is-customer{align-self:flex-end;background:var(--sh-brand-soft);border-color:#f6d9cd}
.sh-supmsg.is-admin{align-self:flex-start;background:var(--sh-accent-soft);border-color:#d5e1f2}
.sh-supmsg.is-ai{align-self:flex-start}
.sh-supmsg.is-internal{align-self:flex-start;background:#fff8e1;border:1px dashed #d9b13b}
.sh-supmsg.is-system{align-self:center;background:#fff;border-style:dashed;color:var(--sh-ink-2);font-size:12.5px;text-align:center}
.sh-supmeta{font-size:11.5px;color:var(--sh-muted);margin-bottom:3px}
.sh-supbody{font-size:14px;line-height:1.55;overflow-wrap:anywhere;white-space:pre-wrap}
@media (max-width:1000px){.sh-supgrid{grid-template-columns:minmax(0,1fr)}}
</style>

<p style="margin:0 0 10px"><a href="support.php">← Back to Support Inbox</a></p>

<div class="sh-supgrid">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> Conversation #<?= (int)$conv['id'] ?></h2>
      <span id="sh-sup-state">
        <?php if (!empty($conv['ai_paused'])): ?><span class="sh-badge sh-badge--warn">Human handling · AI paused</span>
        <?php else: ?><span class="sh-badge sh-badge--ok">AI active</span><?php endif; ?>
        <span class="sh-badge"><?= e(ucfirst((string)$conv['status'])) ?></span>
      </span>
    </div>
    <div class="sh-panel__body">
      <div class="sh-supthread" id="sh-sup-thread" data-conv="<?= (int)$conv['id'] ?>" data-after="<?= $lastMsgId ?>" aria-live="polite">
        <?php foreach ($messages as $m): ?>
          <div class="sh-supmsg is-<?= e($m['visibility'] === 'internal' ? 'internal' : $m['sender']) ?>">
            <div class="sh-supmeta"><strong><?= e($m['who']) ?></strong><?php if ($m['visibility'] === 'internal'): ?> · Internal note (customer cannot see)<?php endif; ?> · <?= e(date('d M, h:i A', strtotime($m['at']) ?: time())) ?><?php if ($m['meta'] !== ''): ?><br><?= e($m['meta']) ?><?php endif; ?></div>
            <div class="sh-supbody"><?= e($m['body']) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if ($messages === []): ?><p class="sh-panel__note">No messages yet.</p><?php endif; ?>
      </div>
      <p class="sh-panel__note" id="sh-sup-pollnote">This page checks for new customer messages every few seconds while open.</p>

      <?php if ($canReply): ?>
      <form method="post" class="sh-form">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="act" value="reply">
        <input type="hidden" name="id" value="<?= (int)$conv['id'] ?>">
        <div class="sh-field">
          <label for="sup-message">Reply to customer</label>
          <textarea class="sh-textarea" id="sup-message" name="message" rows="3" maxlength="4000" placeholder="Write your reply…"></textarea>
          <p class="sh-field__hint"><?php if (empty($conv['ai_paused'])): ?>Sending a public reply automatically takes over from the AI (pauses it here) so you never double-reply.<?php else: ?>You are handling this conversation; the AI stays paused until you resume it.<?php endif; ?></p>
        </div>
        <label class="sh-check"><input type="checkbox" name="internal" value="1"> Internal note (not visible to the customer)</label>
        <div style="margin-top:10px"><button class="sh-btn sh-btn-primary" type="submit"><?= sh_icon('send', 15) ?> Send</button></div>
      </form>
      <?php else: ?>
      <p class="sh-panel__note">You can view this conversation. The <code>support.reply</code> permission is required to reply or change it.</p>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="sh-panel">
      <div class="sh-panel__head"><h3 class="sh-panel__title"><?= sh_icon('user', 16) ?> Customer</h3></div>
      <div class="sh-panel__body">
        <p style="margin:0 0 6px"><strong><?= e($conv['customer_name'] !== '' ? $conv['customer_name'] : 'Guest visitor') ?></strong></p>
        <?php if ($conv['customer_email'] !== ''): ?><div class="sh-table__meta"><?= e($conv['customer_email']) ?></div><?php endif; ?>
        <?php if ($conv['customer_phone'] !== ''): ?><div class="sh-table__meta"><?= e($conv['customer_phone']) ?></div><?php endif; ?>
        <div class="sh-table__meta"><?= !empty($conv['user_id']) ? 'Registered account #' . (int)$conv['user_id'] : 'Guest (browser session)' ?> · Language: <?= e($conv['lang']) ?></div>
        <div class="sh-table__meta">Started <?= e(date('d M Y, h:i A', strtotime((string)$conv['created_at']) ?: time())) ?></div>
        <?php if ($lastProduct !== null): ?>
          <p style="margin:10px 0 4px"><strong>Last discussed product</strong><br>
          <a href="product-edit.php?id=<?= (int)$lastProduct['id'] ?>"><?= e($lastProduct['name']) ?></a>
          <span class="sh-table__meta"><?= e($lastProduct['price_fmt']) ?> · <?= e($lastProduct['availability']) ?></span></p>
        <?php endif; ?>
        <?php if ($lastOrderId > 0): ?>
          <p style="margin:6px 0 0"><strong>Last discussed order</strong><br>
          <a href="orders.php?id=<?= $lastOrderId ?>"><?= e($conv['last_order_number']) ?></a></p>
        <?php elseif (!empty($conv['last_order_number'])): ?>
          <p style="margin:6px 0 0"><strong>Order mentioned:</strong> <?= e($conv['last_order_number']) ?> <span class="sh-table__meta">(not matched/verified)</span></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canReply): ?>
    <div class="sh-panel">
      <div class="sh-panel__head"><h3 class="sh-panel__title"><?= sh_icon('settings', 16) ?> Conversation controls</h3></div>
      <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:12px">
        <?php if (empty($conv['ai_paused'])): ?>
        <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="act" value="takeover"><input type="hidden" name="id" value="<?= (int)$conv['id'] ?>">
          <button class="sh-btn sh-btn-primary" type="submit"><?= sh_icon('user', 15) ?> Take over from AI</button>
          <p class="sh-field__hint">Pauses the AI in this chat and assigns it to you. The customer is told an agent joined.</p>
        </form>
        <?php else: ?>
        <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="act" value="resume_ai"><input type="hidden" name="id" value="<?= (int)$conv['id'] ?>">
          <button class="sh-btn sh-btn-soft" type="submit"><?= sh_icon('refresh', 15) ?> Resume AI assistant</button>
          <p class="sh-field__hint">Hands this chat back to the assistant. The customer is notified.</p>
        </form>
        <?php endif; ?>

        <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="act" value="assign"><input type="hidden" name="id" value="<?= (int)$conv['id'] ?>">
          <div class="sh-field"><label for="sup-assign">Assigned to</label>
            <select class="sh-input" id="sup-assign" name="assigned_admin_id">
              <option value="0" <?= empty($conv['assigned_admin_id']) ? 'selected' : '' ?>>Unassigned</option>
              <?php foreach ($admins as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)($conv['assigned_admin_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button class="sh-btn sh-btn-soft" type="submit">Save assignment</button>
        </form>

        <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= (int)$conv['id'] ?>">
          <div class="sh-field"><label for="sup-status">Status</label>
            <select class="sh-input" id="sup-status" name="status">
              <?php foreach (['open' => 'Open', 'pending' => 'Pending (waiting)', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $conv['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="sh-btn sh-btn-soft" type="submit">Save status</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="sh-panel">
      <div class="sh-panel__head"><h3 class="sh-panel__title"><?= sh_icon('clock', 16) ?> Activity</h3></div>
      <div class="sh-panel__body">
        <?php if ($events === []): ?><p class="sh-panel__note">No recorded events.</p><?php else: ?>
        <ul style="margin:0;padding-left:18px;font-size:13px">
          <?php foreach ($events as $ev): ?>
            <li style="margin:4px 0"><strong><?= e($ev['event']) ?></strong>
              <?php if (!empty($ev['admin_id'])): ?> · <?= e($adminNames[(int)$ev['admin_id']] ?? 'Admin') ?><?php endif; ?>
              <?php if ($ev['detail']): ?><br><span class="sh-table__meta"><?= e($ev['detail']) ?></span><?php endif; ?>
              <br><span class="sh-table__meta"><?= e(date('d M, h:i A', strtotime((string)$ev['created_at']) ?: time())) ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
