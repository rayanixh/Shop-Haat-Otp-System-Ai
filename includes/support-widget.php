<?php
/**
 * Support widget (storefront) — launcher menu + chat panel markup only.
 *
 * Three direct children of <body>: the circular launcher button, the support
 * options menu it opens, and the chat panel. The panel is viewport-fixed and
 * bottom/right anchored by CSS, clears the mobile bottom navigation and safe
 * area, and never follows document scroll; only #sh-schat-messages scrolls
 * internally. The page itself keeps its normal, independent scrolling.
 *
 * The menu is a thin launcher over the existing chat: "Live Chat" calls the same
 * open() path as before, and Facebook / WhatsApp are plain external links to the
 * administrator's configured destinations (see includes/support-links.php).
 * All chat state and replies still come from api/support.php; nothing is faked
 * client-side and no second chat system exists.
 */
if (!function_exists('sh_setting')) { return; }
require_once SH_ROOT . '/includes/support-links.php';
$schatVisibility = isset($shSupportVisibility) && is_array($shSupportVisibility)
    ? $shSupportVisibility
    : sh_support_visibility(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
if (empty($schatVisibility['show_launcher'])) { return; }
$schatChannels = $schatVisibility['channels'];
$schatMax = max(200, min(8000, (int)sh_setting('support_chat_max_input', sh_setting('support_max_input', '2000'))));
$schatAvail = trim((string)sh_setting('support_chat_hours', sh_setting('support_availability', '')));
$schatHandoffEnabled = sh_setting('support_chat_handoff_enabled', sh_setting('support_handoff_enabled', '1')) === '1';
$schatFacebook = $schatChannels['messenger'] ? sh_support_facebook_url() : '';
$schatWhatsapp = $schatChannels['whatsapp'] ? sh_support_whatsapp_href() : '';
$schatCfg = ['endpoint' => sh_url('api/support.php'), 'max' => $schatMax];
// Official brand marks (filled, single colour) — not generic substitutes.
$schatIconMessenger = '<svg class="sh-brandglyph" viewBox="0 0 24 24" width="17" height="17" fill="currentColor" fill-rule="evenodd" aria-hidden="true" focusable="false"><path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.654V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963-3.055-3.26-5.963 3.26L10.732 8.1l3.131 3.26 5.888-3.26z"/></svg>';
$schatIconWhatsapp = '<svg class="sh-brandglyph" viewBox="0 0 24 24" width="17" height="17" fill="currentColor" fill-rule="evenodd" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>';
?>
<button type="button" class="sh-schat-fab" id="sh-schat-fab" aria-expanded="false" aria-haspopup="menu" aria-controls="sh-schat-menu" aria-label="Open support options">
  <?= sh_icon('message', 24) ?>
  <span class="sh-schat-badge" id="sh-schat-badge" hidden>0</span>
</button>

<div class="sh-schat-menu" id="sh-schat-menu" role="menu" aria-label="Support options" aria-hidden="true" hidden>
  <?php if ($schatFacebook !== ''): ?>
    <a class="sh-schat-opt" id="sh-schat-opt-facebook" role="menuitem" href="<?= e($schatFacebook) ?>" target="_blank" rel="noopener noreferrer nofollow" data-channel="facebook">
      <span class="sh-schat-opt__icon sh-schat-opt__icon--messenger" aria-hidden="true"><?= $schatIconMessenger ?></span>
      <span class="sh-schat-opt__body"><strong>Messenger Support</strong><small>Message us on Messenger<span class="sh-sr-only"> — opens in a new tab</span></small></span>
      <?= sh_icon('external', 13, 'sh-schat-opt__go') ?>
    </a>
  <?php endif; ?>
  <?php if ($schatWhatsapp !== ''): ?>
    <a class="sh-schat-opt" id="sh-schat-opt-whatsapp" role="menuitem" href="<?= e($schatWhatsapp) ?>" target="_blank" rel="noopener noreferrer nofollow" data-channel="whatsapp">
      <span class="sh-schat-opt__icon sh-schat-opt__icon--whatsapp" aria-hidden="true"><?= $schatIconWhatsapp ?></span>
      <span class="sh-schat-opt__body"><strong>WhatsApp Support</strong><small>Chat with us on WhatsApp<span class="sh-sr-only"> — opens in a new tab</span></small></span>
      <?= sh_icon('external', 13, 'sh-schat-opt__go') ?>
    </a>
  <?php endif; ?>
  <?php if ($schatChannels['ai']): ?>
    <button type="button" class="sh-schat-opt sh-schat-opt--chat" id="sh-schat-opt-chat" role="menuitem" data-channel="chat">
      <span class="sh-schat-opt__icon sh-schat-opt__icon--chat" aria-hidden="true"><?= sh_icon('message', 17) ?></span>
      <span class="sh-schat-opt__body"><strong>AI Assistant Support</strong><small>Chat with our AI Assistant</small></span>
      <?= sh_icon('chevron-right', 13, 'sh-schat-opt__go') ?>
    </button>
  <?php endif; ?>
</div>

<?php if ($schatChannels['ai']): ?>
<section class="sh-schat-sheet" id="sh-schat-sheet" role="dialog" aria-labelledby="sh-schat-title" data-human="0" data-config="<?= e((string)json_encode($schatCfg)) ?>" hidden>
  <header class="sh-schat-head">
    <div class="sh-schat-headmain">
      <h2 id="sh-schat-title"><?= sh_icon('headphones', 18) ?> Customer Support</h2>
    </div>
    <div class="sh-schat-headactions">
      <button type="button" class="sh-schat-iconbtn" id="sh-schat-new" aria-label="Start a new conversation" title="New conversation"><?= sh_icon('plus', 18) ?></button>
      <button type="button" class="sh-schat-iconbtn" id="sh-schat-close" aria-label="Close chat"><?= sh_icon('x', 18) ?></button>
    </div>
  </header>

  <div class="sh-schat-messages" id="sh-schat-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation messages"></div>

  <div class="sh-schat-suggest" id="sh-schat-suggest" aria-label="Quick questions"></div>

  <form class="sh-schat-form" id="sh-schat-form">
    <label class="sh-sr-only" for="sh-schat-input">Type your message</label>
    <textarea class="sh-schat-input" id="sh-schat-input" rows="2" maxlength="<?= (int)$schatMax ?>" placeholder="Write in বাংলা, Banglish or English…" autocomplete="off" data-sh-no-autogrow></textarea>
    <button type="submit" class="sh-btn sh-btn-primary sh-schat-send" id="sh-schat-send"><?= sh_icon('send', 14) ?> Send</button>
  </form>

  <p class="sh-schat-status" id="sh-schat-status" role="status"></p>

  <div class="sh-schat-foot">
    <button type="button" class="sh-schat-handoff" id="sh-schat-handoff"<?= $schatHandoffEnabled ? '' : ' hidden' ?>><?= sh_icon('user', 14) ?> Talk to a human</button>
    <?php if ($schatAvail !== ''): ?><span class="sh-schat-avail"><?= e($schatAvail) ?></span><?php endif; ?>
  </div>
</section>
<?php endif; ?>
