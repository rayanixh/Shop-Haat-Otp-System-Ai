<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/notifications.php';
require_once SH_ROOT . '/includes/ai-support.php';

sh_session_start();
$supportGuestToken = sh_support_guest_token();
$pageStyles = ['assets/css/support.css'];
$pageScripts = ['assets/js/support.js'];
$sent = false;
$errors = [];
$user = null;
if (function_exists('sh_user')) { $user = sh_user(); }

$form = ['name' => $user['name'] ?? '', 'email' => $user['email'] ?? '', 'subject' => '', 'message' => ''];
foreach ($form as $k => $v) { if (isset($_POST[$k])) { $form[$k] = trim((string)$_POST[$k]); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $v = new ShValidator($_POST);
    $v->required('name', 'Your name')->maxLen('name', 110, 'Your name')
      ->required('email', 'Email address')->email('email', 'Email address')
      ->required('subject', 'Subject')->maxLen('subject', 150, 'Subject')
      ->required('message', 'Message')->minLen('message', 10, 'Message')->maxLen('message', 2000, 'Message');
    $errors = $v->errors();
    if (!$errors) {
        $to = trim(sh_support_setting('contact_email', ''));
        if ($to === '') { $to = trim((string)sh_setting('contact_email', '')); }
        $body = "SUPPORT REQUEST\n" . str_repeat('-', 28) . "\n"
            . 'From: ' . $form['name'] . ' <' . $form['email'] . ">\n"
            . 'Subject: ' . $form['subject'] . "\n\n" . $form['message'];
        $res = $to !== '' ? sh_mail_send($to, 'Support: ' . $form['subject'], $body) : ['skipped' => true];
        // Report honestly: if mail is not configured we say so rather than faking success.
        if (!empty($res['ok'])) {
            $sent = true;
            sh_notify_log(null, 'email', 'support_request', 'sent', $to, null);
        } else {
            sh_notify_log(null, 'email', 'support_request', 'failed', $to, $res['error'] ?? 'Email not configured.');
            $supportPhone = trim(sh_support_setting('contact_phone', ''));
            if ($supportPhone === '') { $supportPhone = trim((string)sh_setting('contact_phone', '')); }
            $errors['general'] = 'We could not send your message automatically. Please email us directly at '
                . ($to !== '' ? $to : 'our contact address')
                . ($supportPhone !== '' ? ' or call ' . $supportPhone : '') . '.';
        }
    }
}

// SEO: overridable from the SEO Manager, with a canonical and breadcrumb.
$pageSeoKey = 'support';
$pageTitle = 'Customer Support';
$pageDescription = 'Contact the ' . sh_setting('site_name', 'ShopHaat')
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
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-chat-delete hidden>Delete resolved conversation</button>
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

  <div class="sh-cartlayout">
    <div class="sh-section">
      <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('headphones', 18) ?> Contact Support</h2></div>
      <?php if ($sent): ?>
        <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?>
          <span>Thank you — the store’s configured email system accepted your message. This page does not promise a response time.</span></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
          <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="sp-name">Your name <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="sp-name" name="name" required maxlength="110" value="<?= e($form['name']) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="sp-email">Email address <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="sp-email" type="email" name="email" required value="<?= e($form['email']) ?>"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="sp-subject">Subject <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="sp-subject" name="subject" required maxlength="150" value="<?= e($form['subject']) ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="sp-msg">Message <span class="sh-field__req">*</span></label>
          <textarea class="sh-textarea" id="sp-msg" name="message" required minlength="10" maxlength="2000"><?= e($form['message']) ?></textarea></div>
        <button class="sh-btn" type="submit"><?= sh_icon('send', 15) ?> Send message</button>
      </form>
    </div>
    <aside class="sh-summary">
      <h2 class="sh-summary__title">Other ways to reach us</h2>
      <?php $supportPhone = trim(sh_support_setting('contact_phone', ''));
      if ($supportPhone === '') { $supportPhone = trim((string)sh_setting('contact_phone', '')); }
      $supportEmail = trim(sh_support_setting('contact_email', ''));
      if ($supportEmail === '') { $supportEmail = trim((string)sh_setting('contact_email', '')); }
      $supportContacts = array_filter([
          'phone' => $supportPhone,
          'mail' => $supportEmail,
          'map-pin' => trim((string)sh_setting('contact_address', '')),
      ], static fn(string $value): bool => $value !== ''); ?>
      <?php if ($supportContacts): ?><ul class="sh-support-contact-list">
        <?php foreach ($supportContacts as $icon => $value): ?><li><?= sh_icon($icon, 14) ?><span><?= e($value) ?></span></li><?php endforeach; ?>
      </ul><?php else: ?><p class="sh-support-muted">The store has not published phone, email or address details here.</p><?php endif; ?>
      <?php $supportHours = trim(sh_support_setting('hours', '')); ?>
      <?php if ($supportHours !== ''): ?><p class="sh-support-muted"><strong>Support hours:</strong> <?= e($supportHours) ?></p>
      <?php else: ?><p class="sh-support-muted">No support hours are configured. Chat does not indicate live staff availability or promise a response time.</p><?php endif; ?>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
