#!/usr/bin/env node
/**
 * Fixed-height Customer Support popup composer regression harness.
 *
 * Loads the REAL assets/js/app.js and assets/js/support-chat.js in jsdom and
 * types one-word, sentence, 5-line, 20-line, long-paragraph, Bengali,
 * Banglish and English drafts. The global textarea grower must skip this
 * composer at its source: no inline height, no inline overflowY:hidden and no
 * per-keystroke resize. CSS source checks cover the stable dimensions, the
 * textarea-only scrollbar and the non-stretching Send button.
 *
 *   node tests/support-chat-composer-behavior.mjs
 *
 * Environment:
 *   SH_JSDOM_PATH             path to a jsdom installation (default: require("jsdom"))
 *   SH_COMPOSER_REQUIRE_JSDOM "1" fails instead of skipping when jsdom is missing
 */
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const require_ = createRequire(import.meta.url);
let JSDOM = null;
try {
  const path = process.env.SH_JSDOM_PATH;
  ({ JSDOM } = path ? require_(path) : require_('jsdom'));
} catch {
  JSDOM = null;
}
if (!JSDOM) {
  const msg = 'jsdom is not installed, so the composer sizing harness did not run.';
  if (process.env.SH_COMPOSER_REQUIRE_JSDOM === '1') {
    console.error('::error title=Support chat composer::' + msg);
    process.exit(1);
  }
  console.log('SKIP  ' + msg);
  console.log('      install it with:  npm i --no-save jsdom@26.1.0');
  process.exit(0);
}

const read = (p) => readFileSync(join(ROOT, p), 'utf8');
const widget = read('includes/support-widget.php');
const css = read('assets/css/app.css');
const appJs = read('assets/js/app.js');
const chatJs = read('assets/js/support-chat.js');
const supportJs = read('assets/js/support.js');
const supportCss = read('assets/css/support.css');
let passed = 0;
const failures = [];
const ok = (name, condition, detail = '') => {
  if (condition) { passed += 1; console.log('  ok  ' + name); }
  else { failures.push(name + (detail ? ' — ' + detail : '')); console.log('FAIL  ' + name + (detail ? ' — ' + detail : '')); }
};

/* 1. Source-level root cause: the global grower is disabled for this composer. */
ok('popup textarea opts out of global auto-grow', /class="sh-schat-input"[\s\S]*?data-sh-no-autogrow/.test(widget));
const growFn = (appJs.match(/function growTextarea\(el\)\s*\{[\s\S]*?\n  \}/) || [''])[0];
ok('app.js grower checks the explicit opt-out before touching inline styles',
  growFn.includes("hasAttribute('data-sh-no-autogrow')")
  && growFn.includes("classList.contains('sh-schat-input')")
  && growFn.indexOf('data-sh-no-autogrow') < growFn.indexOf('style.overflowY')
  && growFn.indexOf('data-sh-no-autogrow') < growFn.indexOf('style.height'));
ok('ordinary textarea growth remains available for other fields',
  appJs.includes('window.shGrowTextarea = growTextarea') && appJs.includes('growAllTextareas()'));
ok('widget client contains no composer scrollHeight or inline-height resizing',
  !/(?:input|form|sendBtn)\.scrollHeight|(?:input|form|sendBtn)\.style\.height|(?:input|form|sendBtn)\.style\[['"]height['"]\]|style\.height\s*=|resize\s*\(/.test(chatJs));
ok('widget client clears stale inline composer dimensions on init',
  chatJs.includes("input.style.removeProperty('height')")
  && chatJs.includes("input.style.removeProperty('overflow-y')"));
ok('dedicated support client no longer uses scrollHeight or inline heights for its composer',
  !/(?:chatInput|input)\.scrollHeight|style\.height\s*=|style\[['"]height['"]\]\s*=/.test(supportJs)
  && supportJs.includes("chatInput.style.removeProperty('height')"));
ok('dedicated support page textarea opts out of global auto-grow', /data-chat-input[\s\S]*?data-sh-no-autogrow/.test(read('support.php')));

/* 2. CSS owns one fixed compact composer row, with text scrolling inside. */
const formRule = (css.match(/\.sh-schat-form\s*\{[^}]*\}/) || [''])[0];
const inputRule = (css.match(/\.sh-schat-input\s*\{[^}]*\}/) || [''])[0];
const sendRule = (css.match(/\.sh-schat-send\s*\{[^}]*\}/) || [''])[0];
ok('composer is a non-wrapping flex row aligned to the bottom',
  /display:\s*flex/.test(formRule) && /align-items:\s*flex-end/.test(formRule)
  && /flex-wrap:\s*nowrap/.test(formRule) && !/align-items:\s*stretch/.test(formRule));
ok('textarea has fixed height, max-height, internal vertical scroll and no resize handle',
  /height:\s*44px/.test(inputRule) && /max-height:\s*44px/.test(inputRule)
  && /overflow-y:\s*auto/.test(inputRule) && /resize:\s*none/.test(inputRule)
  && /field-sizing:\s*fixed/.test(inputRule) && /overflow-x:\s*hidden/.test(inputRule));
ok('long words wrap inside the textarea instead of widening the composer',
  /overflow-wrap:\s*anywhere/.test(inputRule) && /min-width:\s*0/.test(inputRule));
ok('Send button has fixed dimensions and never stretches',
  /flex-shrink:\s*0/.test(sendRule) && /align-self:\s*flex-end/.test(sendRule)
  && /height:\s*44px/.test(sendRule) && /max-height:\s*44px/.test(sendRule)
  && !/align-self:\s*stretch/.test(sendRule));
ok('Send icon and label remain centered',
  /display:\s*inline-flex/.test(sendRule) && /align-items:\s*center/.test(sendRule)
  && /justify-content:\s*center/.test(sendRule) && /\.sh-schat-send svg\s*\{[^}]*flex:\s*none/.test(css));
ok('Talk to a human stays below the composer in shipped markup',
  widget.indexOf('</form>') < widget.indexOf('sh-schat-handoff')
  && widget.indexOf('sh-schat-status') < widget.indexOf('sh-schat-handoff'));
const supportComposerRule = (supportCss.match(/\.sh-support-chat__compose textarea\s*\{[^}]*\}/) || [''])[0];
ok('dedicated support composer also has fixed height and internal scroll',
  /height:\s*70px/.test(supportComposerRule) && /max-height:\s*70px/.test(supportComposerRule)
  && /overflow-y:\s*auto/.test(supportComposerRule) && /resize:\s*none/.test(supportComposerRule)
  && /field-sizing:\s*fixed/.test(supportComposerRule));

/* 3. Runtime: the real scripts must not resize the composer on typing/focus. */
const PAGE = `<!doctype html><html><head><title>t</title></head><body>
<button type="button" class="sh-schat-fab" id="sh-schat-fab" aria-expanded="false" aria-controls="sh-schat-sheet"></button>
<section class="sh-schat-sheet" id="sh-schat-sheet" role="dialog" aria-labelledby="sh-schat-title" data-human="0" data-config='{"endpoint":"/api/support.php","max":2000}' hidden>
  <header class="sh-schat-head"><div class="sh-schat-headmain"><h2 id="sh-schat-title">Customer Support</h2></div>
    <div class="sh-schat-headactions"><button type="button" class="sh-schat-iconbtn" id="sh-schat-new">+</button><button type="button" class="sh-schat-iconbtn" id="sh-schat-close">×</button></div></header>
  <div class="sh-schat-messages" id="sh-schat-messages" role="log"></div>
  <div class="sh-schat-suggest" id="sh-schat-suggest"></div>
  <form class="sh-schat-form" id="sh-schat-form">
    <label class="sh-sr-only" for="sh-schat-input">Type your message</label>
    <textarea class="sh-schat-input" id="sh-schat-input" rows="2" maxlength="2000" data-sh-no-autogrow></textarea>
    <button type="submit" class="sh-btn sh-btn-primary sh-schat-send" id="sh-schat-send">Send</button>
  </form>
  <p class="sh-schat-status" id="sh-schat-status" role="status"></p>
  <div class="sh-schat-foot"><button type="button" class="sh-schat-handoff" id="sh-schat-handoff">Talk to a human</button></div>
</section>
<textarea id="ordinary-field" rows="2"></textarea>
</body></html>`;
const dom = new JSDOM(PAGE, { url: 'https://shop.example.bd/', runScripts: 'outside-only' });
const { window } = dom;
window.SH_CSRF = 'csrf-composer-test';
window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, conversation: { id: 1, status: 'open', human: false }, messages: [], suggestions: [] }) });
window.eval(appJs);
window.eval(chatJs);
const doc = window.document;
const input = doc.getElementById('sh-schat-input');
const send = doc.getElementById('sh-schat-send');
const form = doc.getElementById('sh-schat-form');

const longParagraph = 'I need a detailed update about my parcel and the delivery address, payment reference, courier tracking number, replacement options and expected resolution time. '.repeat(14).slice(0, 1900);
const cases = [
  ['one word', 'Hello'],
  ['short sentence', 'I need help with my order.'],
  ['5 lines', 'Line one\nLine two\nLine three\nLine four\nLine five'],
  ['20 lines', Array.from({ length: 20 }, (_, i) => 'Long draft line ' + (i + 1) + ' with enough words to wrap inside the composer').join('\n')],
  ['very long paragraph', longParagraph],
  ['Bengali', 'আমি আমার অর্ডারের ডেলিভারি ঠিকানা পরিবর্তন করতে চাই এবং কুরিয়ার ট্র্যাকিং নম্বর ও পেমেন্ট রেফারেন্স নিশ্চিত করতে চাই।'],
  ['Banglish', 'Amar order-er delivery address change korte chai, courier tracking number ar payment reference confirm korte chai.'],
  ['English', 'Please confirm the stock, price, delivery charge and expected arrival date before I place the order.'],
];

ok('global grower function is registered by app.js', typeof window.shGrowTextarea === 'function' && typeof window.shGrowTextareas === 'function');
for (const [name, text] of cases) {
  input.value = text;
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
  input.dispatchEvent(new window.FocusEvent('focusin', { bubbles: true }));
  window.shGrowTextarea(input);
  window.shGrowTextareas();
  ok(name + ': no inline height or hidden overflow is written',
    input.style.height === '' && input.style.overflowY !== 'hidden' && !/height\s*:/.test(input.getAttribute('style') || ''),
    'height=' + input.style.height + ' overflowY=' + input.style.overflowY);
  ok(name + ': typed text and send button remain unchanged',
    input.value === text && send.style.height === '' && send.style.width === '' && form.style.height === '');
}
ok('the opt-out attribute survives runtime initialization', input.hasAttribute('data-sh-no-autogrow'));
window.close();

console.log('\n' + passed + ' passed, ' + failures.length + ' failed');
if (failures.length) { failures.forEach((f) => console.log('  - ' + f)); }
console.log(failures.length ? 'SUPPORT CHAT COMPOSER: FAILED' : 'SUPPORT CHAT COMPOSER: ALL CHECKS PASSED');
process.exit(failures.length ? 1 : 0);
