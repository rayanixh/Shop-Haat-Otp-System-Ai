<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/ai-support.php';

sh_session_start();
$admin = sh_require_admin();
sh_perms_schema_ensure();
sh_require_perm('support.manage');
if (!sh_support_schema_ensure()) {
    http_response_code(503);
    $adminTitle = 'Support Inbox';
    $adminPage = 'support';
    require __DIR__ . '/_layout.php';
    echo '<div class="sh-panel"><div class="sh-panel__body"><div class="sh-alert sh-alert--error"><span>Support tables are not available. Back up the database, then run <code>install/migrations/2026-10-ai-customer-support.sql</code> in phpMyAdmin.</span></div></div></div>';
    require __DIR__ . '/_footer.php';
    exit;
}
sh_support_retention_prune();

$adminPage = 'support';
$adminTitle = 'Customer Support Inbox';
$adminStyles = ['assets/css/support-admin.css'];
$adminScripts = ['assets/js/support-admin.js'];
$selectedId = max(0, sh_int($_GET['id'] ?? 0));
$statusFilter = trim(sh_get('status'));
$queryFilter = sh_get('q');
$csrf = sh_csrf_token();
$apiUrl = sh_url('api/support.php');
$initialStats = sh_support_usage_stats();
require __DIR__ . '/_layout.php';
?>
<div class="sh-support-admin" data-support-inbox
     data-api="<?= e($apiUrl) ?>"
     data-csrf="<?= e($csrf) ?>"
     data-selected-id="<?= $selectedId ?>"
     data-admin-id="<?= (int)$admin['id'] ?>"
     data-superadmin="<?= sh_admin_is_superadmin() ? '1' : '0' ?>"
     data-status="<?= e($statusFilter) ?>"
     data-query="<?= e($queryFilter) ?>">
  <div class="sh-support-admin__intro">
    <div>
      <p class="sh-support-admin__eyebrow">Private customer conversations</p>
      <h2>Support Inbox</h2>
      <p>Assign and reply to customer threads, add internal-only notes, pause or resume AI, and resolve conversations. Messages and customer details are visible only to administrators with the support permission.</p>
    </div>
    <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/support-settings.php')) ?>"><?= sh_icon('settings', 14) ?> Settings &amp; FAQs</a>
  </div>

  <div class="sh-support-admin__stats" data-support-stats>
    <div><strong data-stat-conversations><?= (int)$initialStats['conversations'] ?></strong><span>Conversations</span></div>
    <div><strong data-stat-unread><?= (int)$initialStats['unread'] ?></strong><span>Unread for support</span></div>
    <div><strong data-stat-ai><?= e((string)($initialStats['today']['succeeded'] ?? 0)) ?></strong><span>AI replies today</span></div>
    <div><strong data-stat-fallback><?= e((string)($initialStats['today']['fallback'] ?? 0)) ?></strong><span>Messages not sent to model today</span></div>
  </div>

  <form class="sh-support-admin__filters" data-support-filter method="get" action="<?= e(sh_url('admin/support.php')) ?>">
    <label class="sh-field"><span class="sh-field__label">Status</span>
      <select class="sh-select" name="status" data-filter-status>
        <option value="">All conversations</option>
        <?php foreach (['open' => 'Open', 'pending' => 'Pending human support', 'assigned' => 'Assigned', 'resolved' => 'Resolved'] as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="sh-field sh-support-admin__search"><span class="sh-field__label">Search</span>
      <input class="sh-input" name="q" type="search" maxlength="100" value="<?= e($queryFilter) ?>" placeholder="Customer, email or conversation ID" data-filter-query>
    </label>
    <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Find</button>
  </form>

  <div class="sh-support-admin__error" data-admin-error role="alert" hidden></div>
  <div class="sh-support-admin__layout">
    <section class="sh-panel sh-support-admin__list-panel" aria-label="Conversation list">
      <div class="sh-panel__head"><h3>Conversations</h3><span data-list-count></span></div>
      <div class="sh-panel__body">
        <div class="sh-support-admin__list" data-conversation-list aria-live="polite"></div>
        <p class="sh-support-admin__empty" data-list-empty hidden>No conversations match these filters.</p>
        <div class="sh-support-admin__pagination" data-list-pagination hidden>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-page-prev>Previous</button>
          <span data-page-label></span>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-page-next>Next</button>
        </div>
      </div>
    </section>

    <section class="sh-panel sh-support-admin__thread-panel" aria-label="Selected conversation">
      <div class="sh-panel__body sh-support-admin__thread-empty" data-thread-empty>
        <h3>Select a conversation</h3>
        <p>Customer messages, verified context and saved history appear here. This page uses regular document scrolling; load older messages in bounded batches.</p>
      </div>
      <div class="sh-support-admin__thread" data-thread hidden>
        <div class="sh-support-admin__thread-head">
          <div class="sh-support-admin__thread-meta">
            <h3 data-thread-subject>Conversation</h3>
            <p data-customer-details></p>
            <p data-thread-status></p>
          </div>
          <div class="sh-support-admin__thread-actions">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-ai-action></button>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-resolve-action></button>
          </div>
        </div>

        <div class="sh-support-admin__assignment">
          <label class="sh-field"><span class="sh-field__label">Assign to support agent</span>
            <select class="sh-select" data-agent-select><option value="">Unassigned</option></select>
          </label>
          <button class="sh-btn sh-btn--sm" type="button" data-assign>Assign</button>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-unassign>Unassign</button>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-takeover>Take over</button>
        </div>

        <div class="sh-support-admin__events" data-handoff-events hidden></div>
        <button class="sh-btn sh-btn--sm sh-btn--ghost sh-support-admin__older" type="button" data-thread-older hidden>Load older messages</button>
        <ol class="sh-support-admin__messages" data-thread-messages aria-live="polite" aria-relevant="additions text"></ol>

        <form class="sh-support-admin__reply" data-reply-form>
          <label class="sh-field"><span class="sh-field__label">Message type</span>
            <select class="sh-select" data-reply-kind>
              <option value="public">Reply to customer</option>
              <option value="internal">Internal note (not sent to customer)</option>
            </select>
          </label>
          <label class="sh-field"><span class="sh-field__label" data-reply-label>Reply</span>
            <textarea class="sh-textarea" rows="3" maxlength="2000" required data-reply-input></textarea>
          </label>
          <div class="sh-support-admin__reply-foot">
            <span data-reply-count>0 / 2,000</span>
            <button class="sh-btn" type="submit" data-reply-send><?= sh_icon('send', 14) ?> Send reply</button>
          </div>
        </form>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
