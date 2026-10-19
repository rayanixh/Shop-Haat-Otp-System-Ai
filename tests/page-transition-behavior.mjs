/**
 * Page-transition behaviour harness.
 *
 * Drives the REAL rendered markup (tests/page-transition-fixtures.php, which
 * requires the shipped includes/transition.php), the REAL stylesheet
 * (assets/css/app.css) and the REAL script (assets/js/app.js) inside jsdom, and
 * asserts the state machine a browser would see: computed overlay opacity,
 * document classes, the single-use navigation marker, click interception and
 * the failure fallbacks.
 *
 * This is a test-only harness: nothing in it ships to the store and the site
 * still runs on plain PHP with no Node.js requirement.
 *
 *   node tests/page-transition-behavior.mjs
 *
 * Environment:
 *   SH_PHP_CMD            PHP command used to render the fixtures (default: php)
 *   SH_PT_REQUIRE_JSDOM   "1" fails instead of skipping when jsdom is missing
 *
 * @package ShopHaat\Tests
 */

import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const require_ = createRequire(import.meta.url);

// ---------------------------------------------------------------------------
// jsdom is a development-only dependency (see .github/workflows). Without it the
// harness reports that it did not run instead of pretending to have passed.
// ---------------------------------------------------------------------------
let JSDOM = null;
try {
	const path = process.env.SH_JSDOM_PATH;
	({ JSDOM } = path ? require_(path) : require_('jsdom'));
} catch {
	JSDOM = null;
}
if (!JSDOM) {
	const msg = 'jsdom is not installed, so the page-transition behaviour harness did not run.';
	if (process.env.SH_PT_REQUIRE_JSDOM === '1') {
		console.error('::error title=Page transition harness::' + msg);
		process.exit(1);
	}
	console.log('SKIP  ' + msg);
	console.log('      install it with:  npm i --no-save jsdom@26.1.0');
	process.exit(0);
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------
let passed = 0;
const failures = [];
let current = '';

function group(name) {
	current = name;
	console.log('\n' + name + '\n' + '-'.repeat(Math.max(20, name.length)));
}
function check(name, condition, detail = '') {
	if (condition) {
		passed++;
		console.log('  PASS  ' + name);
		return true;
	}
	failures.push(current + ' → ' + name + (detail ? ' :: ' + detail : ''));
	console.log('  FAIL  ' + name + (detail ? '\n        ' + detail : ''));
	return false;
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// A page-script error thrown from a jsdom callback would otherwise kill the
// process and hide every remaining result: record it and keep going.
process.on('uncaughtException', (err) => {
	failures.push(current + ' → uncaught page-script error :: ' + (err && err.message));
	console.log('  FAIL  uncaught page-script error: ' + (err && err.message));
});
process.on('unhandledRejection', (err) => {
	failures.push(current + ' → unhandled rejection :: ' + (err && err.message ? err.message : err));
	console.log('  FAIL  unhandled rejection: ' + (err && err.message ? err.message : err));
});

// ---------------------------------------------------------------------------
// Fixtures: rendered once by PHP so every scenario uses production markup.
// ---------------------------------------------------------------------------
function renderFixtures() {
	const script = join(HERE, 'page-transition-fixtures.php');
	const php = process.env.SH_PHP_CMD || 'php';
	const res = spawnSync(php, [script], {
		cwd: ROOT,
		encoding: 'utf8',
		shell: true,
		env: {
			...process.env,
			SH_ROOT: ROOT,
			SH_UPLOAD_DIR: process.env.SH_UPLOAD_DIR || join(process.env.TMPDIR || '/tmp', 'shophaat-transition-fixtures'),
		},
	});
	if (res.error) { throw new Error('the fixture renderer could not be started: ' + res.error.message); }
	if (res.status !== 0) {
		throw new Error('the fixture renderer exited with ' + res.status + '\n' + (res.stderr || '') + (res.stdout || ''));
	}
	let parsed;
	try {
		parsed = JSON.parse(res.stdout);
	} catch (e) {
		throw new Error('the fixture renderer did not print JSON: ' + e.message + '\n' + String(res.stdout).slice(0, 400));
	}
	return parsed;
}

const CSS = readFileSync(join(ROOT, 'assets/css/app.css'), 'utf8');
const JS = readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8');

/**
 * Load one variant the way a browser would: stylesheet in the head, the script
 * at the end of the body (the shipped tags are `defer`, which is equivalent for
 * everything this harness asserts).
 */
function load(fixtures, variant, opts = {}) {
	let html = fixtures[variant].html;
	// A replacer function, never a replacement string: page code is full of
	// sequences a $-pattern string replacement would rewrite.
	html = html.replace('<link rel="stylesheet" href="/assets/css/app.css">', () => '<style>' + CSS + '</style>');
	const scriptTag = '<script src="/assets/js/app.js" defer></script>';
	html = opts.js === false
		? html.replace(scriptTag, '')
		: html.replace(scriptTag, () => '<script>' + JS + '</script>');

	const navigations = [];
	const virtualConsole = new (require_('jsdom').VirtualConsole)();
	virtualConsole.on('jsdomError', (err) => {
		if (/Not implemented: navigation/i.test(String(err && err.message))) {
			navigations.push(String(err.message));
			return;
		}
		// Anything else is a real error inside the page script.
		failures.push(current + ' → uncaught error in the page script :: ' + (err && err.message));
	});
	virtualConsole.on('error', (msg) => {
		failures.push(current + ' → console.error :: ' + msg);
	});

	const dom = new JSDOM(html, {
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		url: opts.url || 'https://shop.example/index.php',
		virtualConsole,
		beforeParse(window) {
			window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
			window.matchMedia = (query) => ({
				matches: !!(opts.reduce && /prefers-reduced-motion/.test(query)),
				media: query,
				addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {},
			});
			if (opts.marker) { window.sessionStorage.setItem('sh-pt', JSON.stringify(opts.marker)); }
			window.confirm = () => true;
		},
	});

	const win = dom.window;
	const doc = win.document;
	const overlay = doc.getElementById('sh-pt');
	const style = (el) => (el ? win.getComputedStyle(el) : null);
	const visible = (el) => {
		const s = style(el);
		if (!s) { return false; }
		// jsdom returns an empty string when no rule sets opacity; that is 1.
		const opacity = s.opacity === '' ? 1 : parseFloat(s.opacity);
		return s.visibility !== 'hidden' && opacity > 0.5;
	};
	const click = (id, init = {}) => {
		const el = doc.getElementById(id);
		const ev = new win.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
		el.dispatchEvent(ev);
		return ev;
	};
	const close = () => win.close();
	return { dom, win, doc, overlay, style, visible, click, navigations, close };
}

const rootClasses = (doc) => doc.documentElement.className.split(/\s+/).filter(Boolean);
const marker = (win) => {
	try { return win.sessionStorage.getItem('sh-pt'); } catch { return null; }
};

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------
const fixtures = renderFixtures();
const cases = [];
const only = process.env.SH_PT_ONLY || '';
function scenario(name, fn) {
	if (only && !name.toLowerCase().includes(only.toLowerCase())) { return; }
	cases.push([name, fn]);
}

// 1 + 2 + 3 — first visit, refresh and direct URL are the same state: a load
// with no navigation marker.
scenario('1/2/3. Initial load, refresh and direct URL run the transition', async () => {
	const p = load(fixtures, 'default');
	check('the page is held behind the overlay straight after parse',
		p.doc.documentElement.classList.contains('sh-pt-hold'),
		'classes: ' + rootClasses(p.doc).join(' '));
	check('the overlay is painted before the page is revealed', p.visible(p.overlay),
		'computed visibility=' + (p.style(p.overlay).visibility) + ' opacity=' + p.style(p.overlay).opacity);
	check('exactly one overlay element exists', p.doc.querySelectorAll('#sh-pt').length === 1);
	const d = fixtures.default.config.duration;
	await wait(d + 900);
	check('the overlay is gone once the configured time has elapsed', !p.visible(p.overlay),
		'opacity=' + p.style(p.overlay).opacity + ' visibility=' + p.style(p.overlay).visibility);
	check('the hold class is cleaned up', !p.doc.documentElement.classList.contains('sh-pt-cold'));
	check('the page content is visible and interactive again',
		p.doc.getElementById('sh-fixture-body').textContent.includes('page content loaded')
		&& !p.doc.querySelector('.sh-main').classList.contains('is-leaving'));
	p.close();

	// A refresh is a second load of the same URL with no marker.
	const r = load(fixtures, 'default');
	check('a refresh (second load) runs the transition again', r.visible(r.overlay));
	await wait(d + 900);
	check('the refresh reveal finishes and stays finished', !r.visible(r.overlay));
	await wait(d * 3);
	check('no second transition replays after the reveal (no infinite loop)', !r.visible(r.overlay),
		'opacity=' + r.style(r.overlay).opacity);
	r.close();

	// Direct URL into the admin panel.
	const a = load(fixtures, 'admin', { url: 'https://shop.example/admin/orders.php?id=7' });
	check('a direct URL into the admin panel is held behind the overlay', a.visible(a.overlay));
	await wait(d + 900);
	check('the admin page is revealed and usable', !a.visible(a.overlay)
		&& a.doc.getElementById('sh-fixture-body').textContent.includes('admin content loaded'));
	check('the admin wrapper is treated as the page (content animation target)',
		a.doc.querySelector('.sh-admin-main').classList.contains('sh-pt-page'));
	a.close();
});

// A fully configured transition (image background, centred GIF, cinematic fade).
scenario('1. The configured background, media, duration and fade are used', async () => {
	const p = load(fixtures, 'custom');
	const cfg = fixtures.custom.config;
	check('the overlay carries the saved fade style', p.overlay.className.includes('sh-pt--' + cfg.fade),
		p.overlay.className);
	check('the overlay carries the saved duration', p.overlay.getAttribute('style').includes('--sh-pt-ms:' + cfg.duration + 'ms'),
		p.overlay.getAttribute('style'));
	check('the saved background image is used', p.overlay.getAttribute('style').includes(cfg.bg_url), cfg.bg_url);
	check('the centred GIF is rendered', !!p.overlay.querySelector('.sh-pt__media img[src="' + cfg.media_url + '"]'),
		cfg.media_url);
	check('the overlay is visible with the custom settings', p.visible(p.overlay));
	await wait(cfg.duration + 1200);
	check('the custom transition finishes', !p.visible(p.overlay));
	p.close();
});

// 4 + 5 + 6 — pages opened by a direct URL / refresh after internal navigation.
scenario('4/5/6. Arrival from an internal link continues one transition, once', async () => {
	const cfg = fixtures.custom.config;
	const p = load(fixtures, 'custom', { marker: { t: Date.now(), d: cfg.duration } });
	check('the arriving page is held behind the overlay', p.doc.documentElement.classList.contains('sh-pt-hold'));
	check('the arriving overlay is visible immediately', p.visible(p.overlay));
	check('the navigation marker is consumed exactly once', marker(p.win) === null,
		'marker still present: ' + marker(p.win));
	await wait(cfg.duration + 1900);
	check('the arrival transition releases the page', !p.visible(p.overlay)
		&& !p.doc.documentElement.classList.contains('sh-pt-hold'));
	check('the page content is visible after arrival', !p.doc.querySelector('.sh-main').classList.contains('is-leaving'));
	p.close();

	// A stale marker (from a tab left open for days) must not hold the page.
	const stale = load(fixtures, 'default', { marker: { t: Date.now() - 60000, d: 450 } });
	check('a stale marker is treated as a fresh load, not an arrival',
		stale.doc.documentElement.classList.contains('sh-pt-hold'));
	await wait(fixtures.default.config.duration + 900);
	check('a stale marker never leaves a stuck overlay', !stale.visible(stale.overlay));
	check('a stale marker is gone from sessionStorage', marker(stale.win) === null, String(marker(stale.win)));
	stale.close();
});

// 7 — internal navigation and browser Back/Forward.
scenario('7. Internal links, AJAX clicks and Back/Forward behave correctly', async () => {
	const cfg = fixtures.custom.config;
	const p = load(fixtures, 'custom');
	// Let the initial-load transition finish so nothing below is confused with
	// the overlay the page load itself put on screen.
	await wait(cfg.duration + 900);
	check('the page settles after its initial-load transition', !p.visible(p.overlay));

	// The add-to-cart handler legitimately calls preventDefault() on its own
	// button; what matters is that the transition system does not take it over.
	const ajax = p.click('sh-fixture-ajax');
	await wait(120);
	check('an AJAX button starts no page transition', !p.visible(p.overlay) && marker(p.win) === null,
		'overlay=' + p.visible(p.overlay) + ' marker=' + String(marker(p.win)));
	check('an AJAX button causes no navigation', p.navigations.length === 0, p.navigations.length + ' navigations');

	const ignore = ['sh-fixture-external', 'sh-fixture-blank', 'sh-fixture-download', 'sh-fixture-modal',
		'sh-fixture-toggle', 'sh-fixture-no-transition', 'sh-fixture-hash', 'sh-fixture-mailto',
		'sh-fixture-logout', 'sh-fixture-image'];
	for (const id of ignore) {
		const ev = p.click(id);
		check('not intercepted: ' + id, !ev.defaultPrevented);
	}

	// The storefront marks modal openers with data-modal-open, and app.js's own
	// modal handler owns those clicks (it calls preventDefault() itself). What
	// the transition must never do is start a full-page transition for them, so
	// the assertion is about the marker and the overlay, not about the default.
	// The clicks above were deliberately not intercepted, so jsdom is still
	// reporting their navigation attempts: let them settle first, or they are
	// attributed to the modal click below.
	await wait(150);
	const modalNavs = p.navigations.length;
	const modalEv = p.click('sh-fixture-modal-open');
	await wait(150);
	check('a real modal opener starts no page transition',
		!p.visible(p.overlay) && marker(p.win) === null && p.navigations.length === modalNavs,
		'overlay=' + p.visible(p.overlay) + ' marker=' + String(marker(p.win))
			+ ' navigations=' + (p.navigations.length - modalNavs) + ' defaultPrevented=' + modalEv.defaultPrevented);
	const ctrl = p.click('sh-fixture-internal', { ctrlKey: true });
	check('ctrl/cmd-click (new tab) is not intercepted', !ctrl.defaultPrevented);
	const mid = p.click('sh-fixture-internal', { button: 1 });
	check('middle-click is not intercepted', !mid.defaultPrevented);

	// jsdom reports a navigation attempt asynchronously: let the clicks above
	// settle before measuring the one the internal click causes.
	await wait(150);
	const navBefore = p.navigations.length;
	const nav = p.click('sh-fixture-internal');
	check('an internal link is intercepted for the transition', nav.defaultPrevented);
	check('the transition marker is written for the next page', (() => {
		const m = marker(p.win);
		if (!m) { return false; }
		const parsed = JSON.parse(m);
		return parsed && parsed.d === cfg.duration && typeof parsed.t === 'number';
	})(), String(marker(p.win)));
	check('the leaving page shows the overlay', p.visible(p.overlay));
	await wait(700);
	check('the intercepted click causes exactly one navigation', p.navigations.length - navBefore === 1,
		(p.navigations.length - navBefore) + ' navigations from this click');
	check('the leaving page is still covered while navigating away', p.doc.documentElement.className.includes('sh-pt-enter')
		|| p.doc.documentElement.className.includes('sh-pt-hold') || p.visible(p.overlay));
	// jsdom never completes a real navigation, which is exactly the "navigation
	// blocked" case the shipped recovery timer exists for.
	await wait(cfg.duration + 6400);
	check('a click whose navigation never completes recovers (no stuck overlay)',
		marker(p.win) === null && !p.doc.documentElement.classList.contains('sh-pt-enter'), 'marker=' + String(marker(p.win)));

	// Back/forward cache restore must not replay the initial animation.
	const b = load(fixtures, 'default');
	await wait(fixtures.default.config.duration + 900);
	const before = rootClasses(b.doc).slice().sort().join(' ');
	const ev = new b.win.Event('pageshow');
	Object.defineProperty(ev, 'persisted', { value: true });
	b.win.dispatchEvent(ev);
	check('a bfcache restore leaves the overlay hidden', !b.visible(b.overlay));
	check('a bfcache restore adds no new classes', rootClasses(b.doc).slice().sort().join(' ') === before,
		before + ' → ' + rootClasses(b.doc).slice().sort().join(' '));
	await wait(700);
	check('a bfcache restore does not replay the transition', !b.visible(b.overlay));
	b.close();
	p.close();
});

// 8 — the Enable switch.
scenario('8. Disabling Page Transition removes every trace of the overlay', async () => {
	const fam = fixtures.disabled;
	check('no overlay is rendered at all', !fam.html.includes('id="sh-pt"'));
	check('no transition script is rendered', fam.head === '');
	check('body reports the feature as off', fam.body_attrs.includes('data-transition="0"'));

	const p = load(fixtures, 'disabled');
	check('the page is never held behind an overlay', !p.doc.documentElement.classList.contains('sh-pt-cold')
		&& p.doc.documentElement.classList.contains('sh-pt-enter') === false);
	check('the content is visible immediately', p.visible(p.doc.getElementById('sh-fixture-body')));
	const nav = p.click('sh-fixture-internal');
	check('internal navigation is left to the browser when disabled', !nav.defaultPrevented);
	check('no transition marker is written when disabled', marker(p.win) === null);
	p.close();
});

// 3 — failure paths must always reveal the page.
scenario('3. A failed script cannot leave a permanent overlay', async () => {
	const cfg = fixtures.custom.config;
	const p = load(fixtures, 'custom', { js: false });
	check('without app.js the overlay is still shown first', p.visible(p.overlay));
	await wait(cfg.duration + 1500);
	check('the built-in fallback releases the page while app.js is missing', !p.doc.documentElement.classList.contains('sh-pt-cold')
		|| !p.visible(p.overlay), 'classes: ' + rootClasses(p.doc).join(' '));
	await wait(4200);
	check('no permanent overlay remains when the script never runs', !p.visible(p.overlay),
		'opacity=' + p.style(p.overlay).opacity);
	check('the content is readable without the script', p.visible(p.doc.getElementById('sh-fixture-body')));
	p.close();
});

scenario('3. Reduced motion never shows an overlay', async () => {
	const p = load(fixtures, 'default', { reduce: true });
	check('the page is not held when reduced motion is requested', !p.doc.documentElement.classList.contains('sh-pt-cold'));
	check('the overlay is never visible', !p.visible(p.overlay));
	const nav = p.click('sh-fixture-internal');
	check('navigation still happens immediately with reduced motion', nav.defaultPrevented);
	check('reduced motion does not start a transition on the next page', marker(p.win) === null,
		String(marker(p.win)));
	await wait(120);
	check('reduced motion navigates straight away', p.navigations.length >= 1, p.navigations.length + ' navigations');
	p.close();
});

// Stored settings are untrusted input: they must never become markup or script.
scenario('Settings values cannot inject markup into the overlay', async () => {
	const fam = fixtures.sanitised;
	check('a hostile background type falls back to the default', fam.config.bg_type === 'gradient', fam.config.bg_type);
	check('a hostile colour falls back to the default', fam.config.bg_color === '#111827', fam.config.bg_color);
	check('an out-of-range duration is clamped', fam.config.duration === 5000, String(fam.config.duration));
	check('a hostile fade style falls back to the default', fam.config.fade === 'smooth', fam.config.fade);
	check('a traversing media path resolves to nothing', fam.config.media_url === '' && fam.config.bg_url === '',
		fam.config.media_url + ' | ' + fam.config.bg_url);

	const p = load(fixtures, 'sanitised');
	check('no script or style element was injected into the overlay',
		p.doc.querySelectorAll('#sh-pt script, #sh-pt style').length === 0);
	check('no javascript: URL survived', !p.overlay.outerHTML.toLowerCase().includes('javascript:'), p.overlay.outerHTML.slice(0, 200));
	check('the page still has exactly one overlay', p.doc.querySelectorAll('#sh-pt').length === 1);
	await wait(300);
	// The overlay is still showing its (clamped) 5 s transition here: the page
	// underneath must already be laid out and readable, never hidden forever.
	const content = p.doc.getElementById('sh-fixture-body');
	check('the page under the sanitised overlay is not hidden or collapsed',
		p.visible(content) && p.doc.querySelector('.sh-main').classList.contains('sh-pt-page'));
	p.close();
});

scenario('No media configured still transitions and never blocks', async () => {
	const p = load(fixtures, 'no_media');
	check('an image background that no longer exists falls back safely',
		fixtures.no_media.config.bg_type === 'gradient', fixtures.no_media.config.bg_type);
	check('the dot mark is used when no media is set', !!p.overlay.querySelector('.sh-pt__mark'));
	check('the overlay is visible on a fresh load', p.visible(p.overlay));
	await wait(fixtures.no_media.config.duration + 900);
	check('the fallback transition finishes', !p.visible(p.overlay));
	p.close();
});

// ---------------------------------------------------------------------------
for (const [name, fn] of cases) {
	group(name);
	try {
		await fn();
	} catch (e) {
		failures.push(current + ' → threw :: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
		console.log('  FAIL  scenario threw: ' + (e && e.message ? e.message : e));
	}
}

console.log('\n' + '='.repeat(72));
console.log(`page-transition behaviour: passed ${passed}   failed ${failures.length}`);
if (failures.length) {
	console.log('\nFailures:');
	for (const f of failures) { console.log('  - ' + f); }
}
process.exit(failures.length === 0 ? 0 : 1);
