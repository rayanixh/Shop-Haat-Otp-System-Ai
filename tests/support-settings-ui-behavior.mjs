#!/usr/bin/env node
/**
 * jsdom behaviour tests for the restored compact Support Settings UI. It uses
 * the shipped accordion pattern, opens only the requested section, keeps
 * provider-specific SMS fields hidden and disabled, and never creates an
 * internal scrolling box.
 *
 *   NODE_PATH=/home/user/node_modules node tests/support-settings-ui-behavior.mjs
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (p) => readFileSync(join(root, p), 'utf8');
const script = read('assets/js/support-settings.js');
const css = read('assets/css/support-admin.css');
const page = read('admin/support-settings.php');

const html = `<!doctype html><html><body><div class="sh-support-settings" data-support-settings>
  <details class="sh-guide-accordion" data-section="policy"><summary>Policy</summary><div class="sh-guide-accordion__content"><textarea></textarea></div></details>
  <details class="sh-guide-accordion" id="ai" data-section="ai"><summary>AI</summary><div class="sh-guide-accordion__content"><textarea></textarea></div></details>
  <details class="sh-guide-accordion" id="human-support-sms" data-section="human-support-sms"><summary>SMS</summary><div class="sh-guide-accordion__content">
    <select data-sms-provider-select><option value="offline">offline</option><option value="store">store</option><option value="textbee" selected>textbee</option><option value="generic_http">generic_http</option></select>
    <div data-sms-provider-panel="offline" hidden><input name="offline_note" disabled></div>
    <div data-sms-provider-panel="store" hidden><input name="store_note" disabled></div>
    <div data-sms-provider-panel="textbee" hidden><input name="textbee_api_key" disabled><textarea name="textbee_body" disabled></textarea></div>
    <div data-sms-provider-panel="generic_http" hidden><input name="api_url" disabled><textarea name="api_body" disabled></textarea></div>
  </div></details>
  <details class="sh-guide-accordion" data-section="faqs"><summary>FAQs</summary><div class="sh-guide-accordion__content">Saved FAQs</div></details>
  <details class="sh-guide-accordion" data-section="faq-edit"><summary>Add FAQ</summary><div class="sh-guide-accordion__content">Editor</div></details>
</div></body></html>`;

const dom = new JSDOM(html, {
  url: 'https://shop.example.com/admin/support-settings.php#human-support-sms',
  runScripts: 'outside-only',
});
const { window } = dom;
window.eval(script);

const doc = window.document;
const rootEl = doc.querySelector('[data-support-settings]');
const select = doc.querySelector('[data-sms-provider-select]');
const panels = Array.from(doc.querySelectorAll('[data-sms-provider-panel]'));
const panel = (name) => doc.querySelector(`[data-sms-provider-panel="${name}"]`);
const fields = (name) => Array.from(panel(name).querySelectorAll('input, select, textarea, button'));

let passed = 0;
let failed = 0;
const ok = (condition, name) => {
  if (condition) { passed += 1; console.log('  ok  ' + name); }
  else { failed += 1; console.log('FAIL  ' + name); }
};

ok(doc.querySelector('#human-support-sms').open, 'URL hash opens only the requested SMS accordion');
ok(!doc.querySelector('[data-section="policy"]').open && !doc.querySelector('[data-section="faqs"]').open,
  'other settings and FAQ fields are not expanded simultaneously');

window.location.hash = '#faqs';
window.dispatchEvent(new window.Event('hashchange'));
ok(doc.querySelector('[data-section="faqs"]').open, 'hash changes open the matching existing accordion');

ok(panel('textbee').hidden === false && fields('textbee').every((el) => !el.disabled),
  'selected TextBee fields are visible and submitted');
for (const name of ['offline', 'store', 'generic_http']) {
  ok(panel(name).hidden === true && fields(name).every((el) => el.disabled),
    'unselected ' + name + ' fields stay hidden and disabled');
}
ok(panel('generic_http').querySelector('textarea').style.height === '',
  'hidden textareas are not resized or scrolled');

select.value = 'generic_http';
select.dispatchEvent(new window.Event('change', { bubbles: true }));
ok(panel('generic_http').hidden === false && fields('generic_http').every((el) => !el.disabled),
  'switching provider reveals only that provider’s fields');
ok(panel('textbee').hidden === true && fields('textbee').every((el) => el.disabled),
  'switching away hides and disables the previous provider’s credentials');

select.value = 'offline';
select.dispatchEvent(new window.Event('change', { bubbles: true }));
ok(panel('offline').hidden === false && panel('textbee').hidden === true
  && panel('generic_http').hidden === true && panel('store').hidden === true,
  'offline mode shows only its short note, never provider credential fields');

ok(page.includes('<details class="sh-guide-accordion" id="human-support-sms"')
  && !page.includes('<section class="sh-panel" id="human-support-sms"'),
  'SMS uses the existing compact accordion instead of a separate expanded panel');
ok(page.includes('data-section="policy"') && page.includes('data-section="ai"')
  && page.includes('data-section="faqs"') && page.includes('data-section="faq-edit"'),
  'policy, AI, FAQ list and FAQ editor keep their sections but collapse by default');
ok(page.includes('data-sms-provider-panel="textbee"') && page.includes('data-sms-provider-panel="generic_http"')
  && script.includes('field.disabled = !active'),
  'provider panels are hidden in markup and enforced by the shipped script');
ok(!/overflow\s*:\s*(auto|scroll)/i.test(css) && !/max-height\s*:[^;]*;\s*overflow/i.test(css),
  'Support Settings CSS has no internal scrolling boxes');
ok(!css.includes('.sh-support-settings__alert.is-good') && !css.includes(':has(> form)'),
  'no new SMS alert design-system classes remain in the restored stylesheet');
ok(!page.includes("sh_icon('check',") && !page.includes("sh_icon('activity',"),
  'existing known icon names are used instead of unsupported fallbacks');
ok(!page.includes("value=\"<?= e($sms['textbee_api_key']) ?>\"")
  && !page.includes("value=\"<?= e($sms['api_key']) ?>\"")
  && !page.includes("value=\"<?= e($sms['api_body']) ?>\"")
  && page.includes('type="password" value=""'),
  'provider secrets and saved body templates are never echoed into the page');

console.log(`\nCompact Support Settings UI: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
