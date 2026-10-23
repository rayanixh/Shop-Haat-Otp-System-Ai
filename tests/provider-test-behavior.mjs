/**
 * AI provider "Test connection" behaviour harness.
 *
 * Drives the REAL card markup from admin/ai/providers.php and the REAL
 * assets/js/app.js inside jsdom, with a scripted fetch() standing in for
 * api/ai.php, and asserts the rule the administrator sees:
 *
 *   Test connection ALWAYS finishes: the button returns to its original
 *   label and enabled state, and the real outcome (connected, token limit,
 *   rejected key, server error, network failure, timeout) is rendered in
 *   the card. It can never stay on "Testing…".
 *
 * This is a test-only harness: nothing in it ships to the store and the site
 * still runs on plain PHP with no Node.js requirement.
 *
 *   node tests/provider-test-behavior.mjs
 *
 * Environment:
 *   SH_JSDOM_PATH         path to a jsdom installation (default: require("jsdom"))
 *   SH_PT2_REQUIRE_JSDOM  "1" fails instead of skipping when jsdom is missing
 *
 * @package ShopHaat\Tests
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
	const msg = 'jsdom is not installed, so the provider-test behaviour harness did not run.';
	if (process.env.SH_PT2_REQUIRE_JSDOM === '1') {
		console.error('::error title=Provider test harness::' + msg);
		process.exit(1);
	}
	console.log('SKIP  ' + msg);
	console.log('      install it with:  npm i --no-save jsdom@26.1.0');
	process.exit(0);
}

let passed = 0;
const failures = [];

function check(name, condition, detail) {
	if (condition) {
		passed += 1;
		console.log('  ok  ' + name);
	} else {
		failures.push(name + (detail ? ' — ' + detail : ''));
		console.log('FAIL  ' + name + (detail ? ' — ' + detail : ''));
	}
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** One provider card, byte-for-byte the shape admin/ai/providers.php renders. */
function cardHtml(id, name, testCode, note) {
	const tone = testCode === 'connected' ? 'ok' : (testCode === 'token_limit' ? 'warn' : 'muted');
	const badge = tone === 'ok' ? 'sh-badge--ok' : (tone === 'warn' ? 'sh-badge--warn' : 'sh-badge--muted');
	return `
  <article class="sh-aiprov" id="provider-${id}" data-ai-providers data-ai-card="${id}">
    <header class="sh-aiprov__head">
      <span class="sh-aiprov__mark sh-aiprov__mark--${tone}" data-driver="gemini"></span>
      <div class="sh-aiprov__id">
        <h2 class="sh-aiprov__name">${name}</h2>
        <p class="sh-aiprov__type">Google Gemini</p>
      </div>
      <div class="sh-aiprov__flags"><span class="sh-statuspill sh-statuspill--on">Enabled</span></div>
    </header>
    <dl class="sh-aiprov__meta">
      <div class="sh-aiprov__meta-item sh-aiprov__meta-item--wide"><dt>Base URL</dt><dd>https://generativelanguage.googleapis.com/v1beta</dd></div>
      <div class="sh-aiprov__meta-item"><dt>Text model</dt><dd>gemini-2.5-flash</dd></div>
      <div class="sh-aiprov__meta-item"><dt>Image model</dt><dd>—</dd></div>
      <div class="sh-aiprov__meta-item"><dt>Models</dt><dd>12 catalogued</dd></div>
      <div class="sh-aiprov__meta-item"><dt>Catalogue synced</dt><dd>never</dd></div>
    </dl>
    <div class="sh-aiprov__test" data-ai-test-state="${testCode}">
      <div class="sh-aiprov__testhead">
        <span class="sh-badge ${badge}" data-ai-test-chip>${testCode === 'token_limit' ? 'Connected — token limit' : (testCode === 'connected' ? 'Connected' : 'Not tested')}</span>
        <span class="sh-aiprov__testtime" data-ai-test-time>${testCode ? '2 h ago' : 'No test recorded'}</span>
      </div>
      <p class="sh-aiprov__note sh-break" data-ai-test-note${note ? '' : ' hidden'}>${note || ''}</p>
      <p class="sh-aiprov__next sh-break" data-ai-test-next hidden></p>
      <p class="sh-aiprov__live" data-ai-test-result="${id}" role="status" aria-live="polite" hidden></p>
    </div>
    <footer class="sh-aiprov__actions">
      <button class="sh-btn sh-btn--sm" type="button" data-ai-test data-provider="${id}">
        <svg></svg> <span data-ai-test-label>Test connection</span>
      </button>
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="#">Edit</a>
    </footer>
  </article>`;
}

const PAGE = `<!DOCTYPE html><html><head><title>AI Providers</title></head><body>
<div class="sh-admin"><div class="sh-aiprov-grid">
${cardHtml(7, 'Google Gemini', 'token_limit', 'Connected to Google Gemini. gemini-2.5-flash reached the output-token limit before replying, which means the key is valid.')}
</div></div>
</body></html>`;

function makePage(fetchImpl) {
	const dom = new JSDOM(PAGE, { url: 'http://localhost/admin/ai/providers.php', runScripts: 'outside-only' });
	const { window } = dom;
	window.SH_BASE = '/';
	window.SH_CSRF = 'test-token';
	window.confirm = () => true;
	window.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
	// Accelerate the 45s abort timer: run it after 60ms instead, so the
	// harness can prove the timeout path without waiting in real time.
	const realSetTimeout = window.setTimeout.bind(window);
	window.setTimeout = (fn, delay, ...rest) => realSetTimeout(fn, delay === 45000 ? 60 : delay, ...rest);
	window.fetch = fetchImpl;
	window.eval(readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8'));
	return window;
}

function jsonResponse(payload, status = 200) {
	return Promise.resolve({
		ok: status >= 200 && status < 300,
		status,
		headers: { get: () => 'application/json' },
		text: () => Promise.resolve(JSON.stringify(payload)),
		json: () => Promise.resolve(payload),
	});
}

function buttonIdle(btn) {
	return !btn.disabled
		&& btn.getAttribute('data-testing') === null
		&& btn.textContent.includes('Test connection')
		&& !btn.textContent.includes('Testing');
}

async function scenario(name, fetchImpl, expect) {
	const window = makePage(fetchImpl);
	const doc = window.document;
	const btn = doc.querySelector('[data-ai-test]');
	const card = doc.querySelector('[data-ai-card]');
	btn.click();
	// While running, the button must say so and be disabled.
	const busySeen = btn.disabled && btn.textContent.includes('Testing');
	await sleep(400);
	check(`${name}: button shows progress then resets`, busySeen && buttonIdle(btn),
		`disabled=${btn.disabled} label="${btn.textContent.trim()}"`);
	const chip = card.querySelector('[data-ai-test-chip]');
	check(`${name}: chip shows "${expect.state}"`, chip.textContent === expect.state, 'got: ' + chip.textContent);
	const note = card.querySelector('[data-ai-test-note]');
	check(`${name}: real message is rendered`, note.textContent.includes(expect.messagePart), 'got: ' + note.textContent);
	const stateEl = card.querySelector('[data-ai-test-state]');
	check(`${name}: card records code ${expect.code}`,
		stateEl.getAttribute('data-ai-test-state') === expect.code,
		'got: ' + stateEl.getAttribute('data-ai-test-state'));
	// A second click must work too (no one-shot breakage).
	btn.click();
	await sleep(400);
	check(`${name}: a second test also resets`, buttonIdle(btn), `label="${btn.textContent.trim()}"`);
	window.close();
}

async function main() {
	console.log('\nAI provider connection test (assets/js/app.js)');

	await scenario('connected', () => jsonResponse({
		success: true, ok: true, code: 'connected', state: 'Connected', tone: 'ok', status: 200,
		message: 'Connected to Google Gemini. gemini-2.5-flash replied: ready', next: '',
	}), { state: 'Connected', messagePart: 'replied: ready', code: 'connected' });

	await scenario('token limit', () => jsonResponse({
		success: true, ok: true, code: 'token_limit', state: 'Connected — token limit', tone: 'warn', status: 200,
		message: 'Connected to Google Gemini. gemini-2.5-flash reached the output-token limit before replying, which means the key is valid.',
		next: 'Raise the token budget for generations, or choose a model with less thinking overhead.',
	}), { state: 'Connected — token limit', messagePart: 'key is valid', code: 'token_limit' });

	await scenario('rejected key', () => jsonResponse({
		success: true, ok: false, code: 'auth_failed', state: 'Authentication failed', tone: 'bad', status: 401,
		message: 'The API key was rejected by Google Gemini.', next: 'Paste a current key, save it, then test again.',
	}), { state: 'Authentication failed', messagePart: 'rejected', code: 'auth_failed' });

	await scenario('rate limited', () => jsonResponse({
		success: true, ok: false, code: 'rate_limited', state: 'Rate limited', tone: 'warn', status: 429,
		message: 'Google Gemini rate-limited the test request.', next: 'Wait a minute and run the test again.',
	}), { state: 'Rate limited', messagePart: 'rate-limited', code: 'rate_limited' });

	await scenario('server error page', () => Promise.resolve({
		ok: false, status: 500,
		headers: { get: () => 'text/html' },
		text: () => Promise.resolve('<html><body>Internal Server Error</body></html>'),
		json: () => Promise.reject(new Error('not json')),
	}), { state: 'Network error', messagePart: 'unexpected response', code: 'network_error' });

	await scenario('network failure', () => Promise.reject(new TypeError('Failed to fetch')),
		{ state: 'Network error', messagePart: 'Failed to fetch', code: 'network_error' });

	// The request never answers: the abort timer must end the test cleanly.
	await scenario('timeout', (url, init) => new Promise((resolve, reject) => {
		init.signal.addEventListener('abort', () => {
			const err = new Error('The operation was aborted');
			err.name = 'AbortError';
			reject(err);
		});
	}), { state: 'Timed out', messagePart: 'did not answer within 45 seconds', code: 'timeout' });

	// Double-click while a test is running must not run two tests at once.
	{
		let calls = 0;
		const window = makePage(() => {
			calls += 1;
			return sleep(150).then(() => jsonResponse({
				success: true, ok: true, code: 'connected', state: 'Connected', tone: 'ok', status: 200,
				message: 'Connected.', next: '',
			}));
		});
		const btn = window.document.querySelector('[data-ai-test]');
		btn.click(); btn.click(); btn.click();
		await sleep(400);
		check('triple-click runs exactly one test', calls === 1, 'fetch calls: ' + calls);
		check('triple-click ends idle', buttonIdle(btn));
		window.close();
	}

	// A page frozen in the back/forward cache mid-test must not come back
	// with the button disabled on "Testing…".
	{
		const window = makePage((url, init) => new Promise((resolve, reject) => {
			init.signal.addEventListener('abort', () => {
				const err = new Error('aborted'); err.name = 'AbortError'; reject(err);
			});
		}));
		const btn = window.document.querySelector('[data-ai-test]');
		btn.click();
		await sleep(20);
		const frozen = btn.disabled && btn.textContent.includes('Testing');
		const ev = new window.Event('pageshow');
		ev.persisted = true;
		window.dispatchEvent(ev);
		check('bfcache restore stands a frozen button down', frozen && buttonIdle(btn),
			`disabled=${btn.disabled} label="${btn.textContent.trim()}"`);
		window.close();
	}

	console.log(`\n${passed} passed, ${failures.length} failed`);
	if (failures.length > 0) {
		console.log('PROVIDER TEST BEHAVIOUR: FAILED');
		process.exit(1);
	}
	console.log('PROVIDER TEST BEHAVIOUR: ALL CHECKS PASSED');
}

main().catch((err) => {
	console.error('::error title=Provider test harness::' + (err && err.message ? err.message : String(err)));
	console.error(err && err.stack ? err.stack : err);
	process.exit(1);
});
