<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/ai-support.php';

sh_session_start();
// The customer support page is the conversation only. The former secondary
// "Contact Support" section (heading, explanatory copy, email form and its
// "Send message" button) was removed; admin inbox and customer-to-agent chat
// continue to use the same conversation below.
sh_support_guest_token();
$pageStyles = ['assets/css/support.css'];
$pageScripts = ['assets/js/support.js'];

// SEO: overridable from the SEO Manager, with a canonical and breadcrumb.
$pageSeoKey = 'support';
$pageTitle = 'Customer Support';
$pageDescription = 'Chat with the ' . sh_setting('site_name', 'ShopHaat')
    . ' support team about an order, a payment, a delivery or a return.';
$pageCanonical = 'support.php';
$pageBreadcrumbs = [['name' => 'Home', 'url' => 'index.php'], ['name' => 'Customer Support', 'url' => '']];
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Customer Support</span></nav>

  <section class="sh-support-chat" data-support-chat
           data-api="<?= e(sh_url('api/support.php')) ?>"
           data-csrf="<?= e(sh_csrf_token()) ?>"
           aria-labelledby="sh-support-chat-title">
    <div class="sh-support-chat__heading">
      <div>
        <p class="sh-support-chat__eyebrow">Customer Support</p>
        <h1 id="sh-support-chat-title">Chat with ShopHaat Support</h1>
      </div>
    </div>

    <div class="sh-support-chat__alert" data-chat-error role="alert" hidden></div>
    <div class="sh-support-chat__empty" data-chat-empty hidden>
      <p class="sh-support-chat__unavailable" data-chat-unavailable hidden>Chat is temporarily unavailable.</p>
      <button class="sh-btn" type="button" data-chat-open><?= sh_icon('message', 16) ?> Start a chat</button>
    </div>

    <div class="sh-support-chat__history" data-chat-history hidden>
      <div class="sh-support-chat__thread-head">
        <div><strong data-thread-title>Conversation</strong><span data-thread-state></span></div>
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-chat-new hidden>Start a new conversation</button>
      </div>
      <button class="sh-btn sh-btn--sm sh-btn--ghost sh-support-chat__older" type="button" data-load-older hidden>Load older messages</button>
      <ol class="sh-support-chat__messages" data-chat-messages aria-live="polite" aria-relevant="additions text"></ol>
      <div class="sh-support-chat__actions" data-chat-actions>
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-handoff>Request human support</button>
      </div>
      <form class="sh-support-chat__compose" data-chat-form>
        <label class="sh-support-chat__message-label" for="sh-support-message">Your message</label>
        <textarea class="sh-textarea" id="sh-support-message" data-chat-input rows="2" maxlength="2000" placeholder="Write a message…" required></textarea>
        <div class="sh-support-chat__compose-foot">
          <span class="sh-support-chat__counter" data-char-count>0 / 2,000</span>
          <button class="sh-btn" type="submit" data-chat-send><?= sh_icon('send', 15) ?> Send</button>
        </div>
      </form>
      <div class="sh-support-chat__disabled" data-chat-disabled hidden></div>
    </div>
  </section>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
