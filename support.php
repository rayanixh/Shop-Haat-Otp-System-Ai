<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
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
        $to = (string)sh_setting('contact_email', '');
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
            $errors['general'] = 'We could not send your message automatically. Please email us directly at '
                . ($to !== '' ? $to : 'our contact address') . ' or call ' . sh_setting('contact_phone', '') . '.';
        }
    }
}

$pageTitle = 'Customer Support';
$pageDescription = 'Contact our customer support team.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Customer Support</span></nav>
  <div class="sh-cartlayout">
    <div class="sh-section">
      <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('headphones', 18) ?> Contact Support</h1></div>
      <?php if ($sent): ?>
        <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?>
          <span>Thank you — your message has been sent. Our team usually replies within one working day.</span></div>
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
      <p style="font-size:13.3px;line-height:2;color:var(--sh-ink-2)">
        <?= sh_icon('phone', 14) ?> <?= e(sh_setting('contact_phone', '')) ?><br>
        <?= sh_icon('mail', 14) ?> <?= e(sh_setting('contact_email', '')) ?><br>
        <?= sh_icon('map-pin', 14) ?> <?= e(sh_setting('contact_address', '')) ?>
      </p>
      <p style="font-size:12.5px;color:var(--sh-muted);margin-top:10px">Support hours: Saturday to Thursday, 9:00 AM – 8:00 PM.</p>
    </aside>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
