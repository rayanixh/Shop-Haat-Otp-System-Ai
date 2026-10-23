/**
 * Support chat widget behaviour harness.
 *
 * Drives the REAL assets/js/support-chat.js inside jsdom against fixture
 * markup mirroring includes/support-widget.php (kept honest by source
 * checks below) with a scripted fetch() standing in for api/support.php,
 * and asserts the customer-visible rules:
 *
 *   - opening the panel reveals it once (a single start call) in the page
 *     flow — absolutely positioned at the scroll offset, never a scroll box;
 *   - every request carries the CSRF token and an idempotent client_msg_id,
 *     and a failed send offers Retry reusing that same id;
 *   - message bodies render as TEXT (an XSS payload stays inert) and only
 *     same-origin store links become anchors;
 *   - AI-generated, automatic and human replies are labelled differently —
 *     nothing deterministic is ever shown as "AI assistant";
 *   - handoff, polling (with dedupe), a new conversation and Escape all
 *     behave, and a rate-limit answer is explained instead of failing hard.
 *
 * Test-only: the store itself still runs on plain PHP with no Node.js.
 *
 *   node tests/support-chat-behavior.mjs
 *
 * Environment:
 *   SH_JSDOM_PATH         path to a jsdom installation (default: require("jsdom"))
 *   SH_SC_REQUIRE_JSDOM   "1" fails instead of skipping when jsdom is missing
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
	const msg = 'jsdom is not installed, so the support-chat behaviour harness did not run.';
	if (process.env.SH_SC_REQUIRE_JSDOM === '1') {
		console.error('::error title=Support chat harness::' + msg);
		process.exit(1);
	}
	console.log('SKIP  ' + msg);
	console.log('      install it with:  npm i --no-save jsdom@26.1.0');
	process.exit(0);
}

let passed = 0;
const failures = [];
function check(name, condition, detail) {
	if (condition) { passed += 1; console.log('  ok  ' + name); }
	else { failures.push(name + (detail ? ' — ' + detail : '')); console.log('FAIL  ' + name + (detail ? ' — ' + detail : '')); }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* Fixture fidelity: every hook the script needs must exist in the shipped
 * markup, and the panel must stay in page flow (no inner scroll box). */
const widgetPhp = readFileSync(join(ROOT, 'includes/support-widget.php'), 'utf8');
const appCss = readFileSync(join(ROOT, 'assets/css/app.css'), 'utf8');
for (const id of ['sh-schat-fab', 'sh-schat-sheet', 'sh-schat-messages', 'sh-schat-suggest', 'sh-schat-form',
	'sh-schat-input', 'sh-schat-send', 'sh-schat-status', 'sh-schat-sub', 'sh-schat-badge',
	'sh-schat-handoff', 'sh-schat-new', 'sh-schat-close']) {
	check('shipped widget markup has #' + id, widgetPhp.includes('id="' + id + '"'));
}
check('chat panel is absolutely positioned in page flow (not fixed/scrollboxed)',
	/\.sh-schat-sheet\s*\{[^}]*position:\s*absolute/.test(appCss));
check('no inner scroll region exists in the stylesheet', !/overflow(-x|-y)?\s*:\s*(auto|scroll)/.test(appCss));

const PAGE = `<!doctype html><html><head><title>t</title></head><body>
<button type="button" class="sh-schat-fab" id="sh-schat-fab" aria-expanded="false" aria-controls="sh-schat-sheet" aria-label="Open customer support chat">
  <span class="sh-schat-fab-label">Support</span><span class="sh-schat-badge" id="sh-schat-badge" hidden>0</span>
</button>
<section class="sh-schat-sheet" id="sh-schat-sheet" role="dialog" aria-labelledby="sh-schat-title" data-config='{"endpoint":"/api/support.php","max":2000}' hidden>
  <header class="sh-schat-head"><div class="sh-schat-headmain">
    <h2 id="sh-schat-title">Customer Support</h2>
    <p class="sh-schat-sub" id="sh-schat-sub">Replies use live store data.</p></div>
    <div class="sh-schat-headactions">
      <button type="button" class="sh-schat-iconbtn" id="sh-schat-new" aria-label="Start a new conversation">+</button>
      <button type="button" class="sh-schat-iconbtn" id="sh-schat-close" aria-label="Close chat">×</button>
    </div></header>
  <div class="sh-schat-messages" id="sh-schat-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation messages"></div>
  <div class="sh-schat-suggest" id="sh-schat-suggest" aria-label="Quick questions"></div>
  <form class="sh-schat-form" id="sh-schat-form">
    <label class="sh-sr-only" for="sh-schat-input">Type your message</label>
    <textarea class="sh-schat-input" id="sh-schat-input" rows="2" maxlength="2000"></textarea>
    <button type="submit" class="sh-btn sh-btn-primary sh-schat-send" id="sh-schat-send">Send</button>
  </form>
  <p class="sh-schat-status" id="sh-schat-status" role="status"></p>
  <div class="sh-schat-foot"><button type="button" class="sh-schat-handoff" id="sh-schat-handoff">Talk to a human</button></div>
</section></body></html>`;

/* Scripted api/support.php ------------------------------------------------ */
const requests = [];
const server = {
	nextId: 1, msgs: [], clientIds: new Map(), failNext: null, nextReply: null,
	suggestions: ['Where is my order?', 'Delivery charge koto?'],
	push(kind, body, ai) {
		const m = { id: this.nextId++, kind, ai: !!ai, body, at: '2026-08-15 10:0' + (this.nextId % 10) + ':00' };
		this.msgs.push(m); return m;
	},
	resetConv() { this.msgs = []; this.clientIds.clear(); },
};
server.push('assistant', 'Welcome to ShopHaat!', false);
function convPublic(human) { return { id: 42, status: human ? 'pending' : 'open', ai_active: !human, human: !!human, lang: 'en' }; }
function json(data, ok = true, status = 200) { return Promise.resolve({ ok, status, json: () => Promise.resolve(data) }); }
function fakeFetch(url, init) {
	const p = new URLSearchParams(init.body.toString());
	const action = p.get('action');
	requests.push({ action, p });
	if (server.failNext === 'network') { server.failNext = null; return Promise.reject(new Error('offline')); }
	if (server.failNext === 'rate') { server.failNext = null; return json({ ok: false, error: 'rate_limited' }, false, 429); }
	const after = parseInt(p.get('after_id') || '0', 10) || 0;
	const fresh = () => server.msgs.filter((m) => m.id > after);
	switch (action) {
		case 'start': return json({ ok: true, conversation: convPublic(false), messages: server.msgs.slice(), suggestions: server.suggestions, max_input: 2000 });
		case 'new': server.resetConv(); server.push('assistant', 'New conversation — welcome!', false);
			return json({ ok: true, conversation: convPublic(false), messages: server.msgs.slice(), suggestions: server.suggestions });
		case 'poll': return json({ ok: true, conversation: convPublic(server.human), messages: fresh(), suggestions: server.suggestions });
		case 'handoff': server.human = true; server.push('notice', 'Your request has been passed to our support team.', false);
			return json({ ok: true, conversation: convPublic(true), messages: fresh(), suggestions: [] });
		case 'send': {
			const cmid = p.get('client_msg_id');
			if (server.clientIds.has(cmid)) {
				return json({ ok: true, duplicate: true, conversation: convPublic(server.human), messages: fresh(), suggestions: [] });
			}
			server.clientIds.set(cmid, true);
			server.push('customer', p.get('message'), false);
			const r = server.nextReply || { body: 'Checked: Trail Runner Shoe — ৳1,250 · in stock\n/product.php?slug=trail-runner', ai: false };
			server.nextReply = null;
			server.push('assistant', r.body, r.ai);
			return json({ ok: true, conversation: convPublic(server.human), messages: fresh(), suggestions: [] });
		}
		default: return json({ ok: false, error: 'bad' }, false, 400);
	}
}

const dom = new JSDOM(PAGE, { url: 'https://shop.example.bd/', runScripts: 'outside-only' });
const { window } = dom;
// jsdom windows report hidden/prerender; a real foreground tab does not, and
// the widget rightly only polls on visibilitychange when the tab is visible.
Object.defineProperty(window.document, 'hidden', { get: () => false, configurable: true });
Object.defineProperty(window.document, 'visibilityState', { get: () => 'visible', configurable: true });
window.SH_CSRF = 'csrf-test-token';
window.fetch = fakeFetch;
window.eval(readFileSync(join(ROOT, 'assets/js/support-chat.js'), 'utf8'));
const $ = (s) => window.document.querySelector(s);
const $$ = (s) => Array.from(window.document.querySelectorAll(s));
const reqs = (a) => requests.filter((r) => r.action === a);

/* 1. Open → exactly one start, panel in flow, welcome + suggestions ------- */
check('sheet starts hidden', $('#sh-schat-sheet').hidden === true);
$('#sh-schat-fab').click();
await sleep(30);
const sheet = $('#sh-schat-sheet');
check('opening reveals the panel', sheet.hidden === false);
check('panel is pinned to the current scroll offset (page flow)', sheet.style.top === '10px', sheet.style.top);
check('fab reports expanded state', $('#sh-schat-fab').getAttribute('aria-expanded') === 'true');
check('start called once with the CSRF token', reqs('start').length === 1 && reqs('start')[0].p.get('csrf_token') === 'csrf-test-token');
check('welcome message renders', $('#sh-schat-messages').textContent.includes('Welcome to ShopHaat!'));
check('quick suggestions render', $$('#sh-schat-suggest .sh-schat-chip').length === 2);

/* 2. Suggestion click sends it, reply renders with safe link --------------- */
$$('#sh-schat-suggest .sh-schat-chip')[0].click();
await sleep(30);
check('suggestion is sent as a message', reqs('send').length >= 1 && reqs('send').at(-1).p.get('message') === 'Where is my order?');
check('send carries a client_msg_id and conversation id',
	(reqs('send').at(-1).p.get('client_msg_id') || '').length >= 8 && reqs('send').at(-1).p.get('conversation_id') === '42');
check('deterministic reply is labelled Support, never AI',
	$$('.sh-schat-msg .sh-schat-who').some((w) => w.textContent === 'Support') &&
	!$$('.sh-schat-msg .sh-schat-who').some((w) => w.textContent === 'AI assistant'));
const link = $('#sh-schat-messages a.sh-schat-link');
check('same-origin product URL becomes a link', !!link && link.getAttribute('href') === '/product.php?slug=trail-runner' && link.textContent === 'View product');

/* 3. AI labelling + XSS + foreign link ------------------------------------- */
server.nextReply = { body: 'Sure! <img src=x onerror=alert(1)> see https://evil.example.com/product.php?x=1\nhttps://evil.example.com/product.php?x=1', ai: true };
$('#sh-schat-input').value = 'nike shoe ache?';
$('#sh-schat-form').requestSubmit();
await sleep(30);
check('model reply is labelled AI assistant', $$('.sh-schat-msg .sh-schat-who').some((w) => w.textContent === 'AI assistant'));
check('XSS payload renders as inert text (no elements injected)',
	$('#sh-schat-messages').querySelector('img') === null && $('#sh-schat-messages').textContent.includes('<img src=x onerror=alert(1)>'));
check('foreign-origin URL is NOT turned into a link',
	$$('#sh-schat-messages a').every((a) => !a.href.includes('evil.example.com')));

/* 4. Network failure → Retry reuses the same client_msg_id ----------------- */
server.failNext = 'network';
const sendsBefore = reqs('send').length;
$('#sh-schat-input').value = 'retry me';
$('#sh-schat-form').requestSubmit();
await sleep(30);
const retryBtn = $('.sh-schat-msg.is-failed .sh-schat-retry');
check('failed send keeps the message and offers Retry', !!retryBtn);
check('failure is explained in the status line', $('#sh-schat-status').textContent.includes('Retry'));
retryBtn.click();
await sleep(30);
check('retry reuses the same client_msg_id (server dedupes)',
	reqs('send').length === sendsBefore + 2 &&
	reqs('send').at(-1).p.get('client_msg_id') === reqs('send').at(-2).p.get('client_msg_id'));

/* 5. Rate limit is explained ------------------------------------------------ */
server.failNext = 'rate';
$('#sh-schat-input').value = 'flood';
$('#sh-schat-form').requestSubmit();
await sleep(30);
check('rate limit message asks the customer to wait', $('#sh-schat-status').textContent.includes('wait a moment'));

/* 6. Poll appends agent reply once (dedupe by id) --------------------------- */
server.push('agent', 'Agent here — sorted!', false);
window.document.dispatchEvent(new window.Event('visibilitychange'));
await sleep(30);
check('polled agent reply renders as Support agent',
	$$('.sh-schat-msg .sh-schat-who').some((w) => w.textContent === 'Support agent') &&
	$('#sh-schat-messages').textContent.includes('Agent here — sorted!'));
const countAfterPoll = $$('#sh-schat-messages .sh-schat-msg').length;
window.document.dispatchEvent(new window.Event('visibilitychange'));
await sleep(30);
check('repeated polls never duplicate a message', $$('#sh-schat-messages .sh-schat-msg').length === countAfterPoll);

/* 7. Handoff switches the panel to human mode -------------------------------- */
$('#sh-schat-handoff').click();
await sleep(30);
check('handoff posts to the API', reqs('handoff').length === 1);
check('handoff hides the button and updates the subtitle',
	$('#sh-schat-handoff').hidden === true && $('#sh-schat-sub').textContent.includes('support team will reply'));
check('handoff notice renders as a system line', !!$('.sh-schat-notice'));

/* 8. New conversation resets --------------------------------------------------- */
$('#sh-schat-new').click();
await sleep(30);
check('new conversation clears the old thread',
	$('#sh-schat-messages').textContent.includes('New conversation — welcome!') &&
	!$('#sh-schat-messages').textContent.includes('Agent here — sorted!'));

/* 9. Escape closes; reopening does not re-start ---------------------------------- */
window.document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
check('Escape closes the panel', sheet.hidden === true && $('#sh-schat-fab').getAttribute('aria-expanded') === 'false');
$('#sh-schat-fab').click();
await sleep(20);
check('reopening does not create a second start call', reqs('start').length === 1);
check('Enter submits the message', (() => {
	$('#sh-schat-input').value = 'enter test';
	return true;
})());
$('#sh-schat-input').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
await sleep(30);
check('Enter-to-send actually posted', reqs('send').some((r) => r.p.get('message') === 'enter test'));

window.close();
console.log('\n' + passed + ' passed, ' + failures.length + ' failed');
if (failures.length) { failures.forEach((f) => console.log('  - ' + f)); }
console.log(failures.length ? 'SUPPORT CHAT BEHAVIOUR: FAILED' : 'SUPPORT CHAT BEHAVIOUR: ALL CHECKS PASSED');
process.exit(failures.length ? 1 : 0);
