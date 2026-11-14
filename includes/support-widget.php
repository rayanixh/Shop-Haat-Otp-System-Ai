<?php
/**
 * Support chat widget (storefront) — markup only.
 *
 * Both nodes are direct children of <body>. The panel is viewport-fixed and
 * bottom/right anchored by CSS, clears the mobile bottom navigation and safe
 * area, and never follows document scroll; only #sh-schat-messages scrolls
 * internally. The page itself keeps its normal, independent scrolling. All
 * state and replies come from api/support.php; nothing is faked client-side.
 */
if (!function_exists('sh_setting') || sh_setting('support_chat_enabled', sh_setting('support_enabled', '1')) !== '1') { return; }
$schatMax = max(200, min(8000, (int)sh_setting('support_chat_max_input', sh_setting('support_max_input', '2000'))));
$schatAvail = trim((string)sh_setting('support_chat_hours', sh_setting('support_availability', '')));
$schatHandoffEnabled = sh_setting('support_chat_handoff_enabled', sh_setting('support_handoff_enabled', '1')) === '1';
$schatCfg = ['endpoint' => sh_url('api/support.php'), 'max' => $schatMax];
?>
<button type="button" class="sh-schat-fab" id="sh-schat-fab" aria-expanded="false" aria-controls="sh-schat-sheet" aria-label="Open customer support chat">
  <?= sh_icon('message', 20) ?><span class="sh-schat-fab-label">Support</span>
  <span class="sh-schat-badge" id="sh-schat-badge" hidden>0</span>
</button>

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
