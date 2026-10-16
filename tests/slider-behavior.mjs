/**
 * Homepage slider behaviour harness.
 *
 * Drives the REAL slider markup (tests/slider-fixtures.php, which calls the
 * shipped sh_slider_markup()), the REAL stylesheet (assets/css/app.css) and the
 * REAL script (assets/js/app.js) inside jsdom, and asserts the behaviour a
 * browser would see: a stacked crossfade with no blank frame, autoplay on the
 * configured interval, a pause while the tab is hidden or the carousel is
 * scrolled out of view, reduced-motion handling, adjacent-image warming, a
 * missing image degrading to the plate, and pagination that never appears for a
 * single slide.
 *
 * Timers are intercepted, so the assertions are deterministic and nothing waits
 * for real seconds to pass.
 *
 * This is a test-only harness: nothing in it ships to the store and the site
 * still runs on plain PHP with no Node.js requirement.
 *
 *   node tests/slider-behavior.mjs
 *
 * Environment:
 *   SH_PHP_CMD           PHP command used to render the fixtures (default: php)
 *   SH_SLIDER_REQUIRE_JSDOM  "1" fails instead of skipping when jsdom is missing
 *
 * @package ShopHaat\Tests
 */

import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const require_ = createRequire(import.meta.url);

let JSDOM = null;
let jsdomModule = null;
try {
	const path = process.env.SH_JSDOM_PATH;
	jsdomModule = path ? require_(path) : require_('jsdom');
	({ JSDOM } = jsdomModule);
} catch {
	JSDOM = null;
	jsdomModule = null;
}
if (!JSDOM) {
	const msg = 'jsdom is not installed, so the slider behaviour harness did not run.';
	if (process.env.SH_SLIDER_REQUIRE_JSDOM === '1') {
		console.error('::error title=Slider harness::' + msg);
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
process.on('uncaughtException', (err) => {
	failures.push(current + ' → uncaught error in the page script :: ' + (err && err.stack));
	console.log('  FAIL  the harness stopped on an unexpected error');
	console.log('        ' + (err && err.stack));
});
/** Run one scenario; an unexpected throw is reported instead of ending the run. */
function scenario(name, fn) {
	group(name);
	try {
		fn();
	} catch (err) {
		failures.push(name + ' → threw :: ' + (err && err.stack));
		console.log('  FAIL  the scenario threw');
		console.log('        ' + (err && err.stack));
	}
}

// ---------------------------------------------------------------------------
// Fixtures rendered by PHP, exactly as the storefront renders them
// ---------------------------------------------------------------------------
function renderFixtures() {
	const phpCmd = (process.env.SH_PHP_CMD || 'php').split(' ');
	const res = spawnSync(phpCmd[0], [...phpCmd.slice(1), join(HERE, 'slider-fixtures.php')], {
		encoding: 'utf8',
		env: { ...process.env, SH_ROOT: ROOT },
	});
	if (res.status !== 0) {
		throw new Error('the fixture renderer failed: ' + (res.stderr || res.stdout));
	}
	try {
		return JSON.parse(res.stdout);
	} catch (e) {
		throw new Error('the fixture renderer did not print JSON: ' + e.message + '\n' + String(res.stdout).slice(0, 400));
	}
}

const CSS = readFileSync(join(ROOT, 'assets/css/app.css'), 'utf8');
const JS = readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8');

/**
 * Build a page holding one rendered variant. Timer APIs are replaced before the
 * page script runs so the harness can decide when a tick happens.
 */
function load(fixtures, variant, opts = {}) {
	const html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>' + CSS + '</style></head>'
		+ '<body><main>' + fixtures[variant].markup + '</main>'
		+ '<script>' + JS + '<\/script></body></html>';

	const timers = { intervals: [], timeouts: [], cleared: [] };
	const images = [];
	const errors = [];
	const virtualConsole = new jsdomModule.VirtualConsole();
	virtualConsole.on('jsdomError', (err) => {
		if (/Not implemented/i.test(String(err && err.message))) { return; }
		errors.push(String(err && err.message));
	});

	const dom = new JSDOM(html, {
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		url: 'https://shop.example/index.php',
		virtualConsole,
		beforeParse(window) {
			window.matchMedia = (query) => ({
				matches: !!(opts.reduce && /prefers-reduced-motion/.test(query)),
				media: query,
				addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {},
			});
			window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) });
			// Deterministic timers: nothing advances unless the harness says so.
			window.setInterval = (fn, ms) => {
				const id = { fn, ms, kind: 'interval' };
				timers.intervals.push(id);
				return id;
			};
			window.clearInterval = (id) => {
				timers.cleared.push(id);
				timers.intervals = timers.intervals.filter((x) => x !== id);
			};
			window.setTimeout = (fn, ms) => {
				const id = { fn, ms, kind: 'timeout' };
				timers.timeouts.push(id);
				return id;
			};
			window.clearTimeout = (id) => {
				timers.cleared.push(id);
				timers.timeouts = timers.timeouts.filter((x) => x !== id);
			};
			// Record (and fake) the warming fetches the script starts.
			const RealImage = window.Image;
			window.Image = function ImageStub() {
				const img = new RealImage();
				images.push(img);
				return img;
			};
			// jsdom has no IntersectionObserver: stand in for it so the script's
			// off-screen pause is observable.
			window.__ioCallbacks = [];
			window.IntersectionObserver = function IntersectionObserverStub(cb) {
				window.__ioCallbacks.push(cb);
				this.observe = () => {};
				this.disconnect = () => {};
				this.unobserve = () => {};
			};
			if (opts.hidden) {
				Object.defineProperty(window.document, 'hidden', { value: true, configurable: true });
				Object.defineProperty(window.document, 'visibilityState', { value: 'hidden', configurable: true });
			}
		},
	});

	const win = dom.window;
	const doc = win.document;
	const carousel = doc.querySelector('[data-carousel]');
	const slides = Array.from(doc.querySelectorAll('.sh-slide'));

	return {
		dom, win, doc, carousel, slides, timers, images, errors,
		dots: Array.from(doc.querySelectorAll('.sh-carousel__dot')),
		active: () => slides.filter((s) => s.classList.contains('sh-slide--on')),
		runTimers: (kind = 'interval') => {
			const list = kind === 'timeout' ? timers.timeouts : timers.intervals;
			const pending = list.slice();
			pending.forEach((t) => {
				// A fired timer is gone: running it twice would re-apply a stale
				// slide change that the page script has already moved past.
				timers.timeouts = timers.timeouts.filter((x) => x !== t);
				timers.intervals = timers.intervals.filter((x) => x !== t);
				t.fn();
			});
			return pending.length;
		},
		visibility: (hidden) => {
			Object.defineProperty(doc, 'hidden', { value: hidden, configurable: true });
			doc.dispatchEvent(new win.Event('visibilitychange'));
		},
		intersect: (ratio) => {
			win.__ioCallbacks.forEach((cb) => cb([{ intersectionRatio: ratio, isIntersecting: ratio > 0 }]));
		},
		close: () => win.close(),
	};
}

const fixtures = renderFixtures();

// ---------------------------------------------------------------------------
// 1. Markup contract
// ---------------------------------------------------------------------------
scenario('Rendered markup: no call-to-action, stacked slides, minimal pagination', () => {
	check('the fixture renderer produced markup', fixtures['two-slides'].markup.length > 0);
	check('no slide button is rendered', !fixtures['two-slides'].markup.includes('sh-slide__btn'));
	check('no arrow control is rendered',
		!fixtures['two-slides'].markup.includes('sh-carousel__arrow')
		&& !fixtures['two-slides'].markup.includes('data-carousel-prev')
		&& !fixtures['two-slides'].markup.includes('data-carousel-next'));
	check('the slide heading and text survive',
		fixtures['two-slides'].markup.includes('Fixture Slide One')
		&& fixtures['two-slides'].markup.includes('First slide description.'));
	check('both slides are in the DOM with their images',
		(fixtures['two-slides'].markup.match(/class="sh-slide__img"/g) || []).length === 2);
	check('exactly two pagination buttons are rendered',
		(fixtures['two-slides'].markup.match(/data-slide-to="/g) || []).length === 2);
	check('a single slide renders no pagination',
		!fixtures['one-slide'].markup.includes('sh-carousel__dots'));
	check('a slide with no usable image is marked and keeps its text',
		fixtures['missing-image'].markup.includes('sh-slide--noimg')
		&& fixtures['missing-image'].markup.includes('Text must survive a missing file.')
		&& (fixtures['missing-image'].markup.match(/class="sh-slide__img"/g) || []).length === 1);
	check('no slides renders nothing at all', fixtures['no-slides'].markup === '');
});

// ---------------------------------------------------------------------------
// 2. Crossfade
// ---------------------------------------------------------------------------
scenario('Crossfade: the incoming slide fades in over the outgoing one', () => {
	const page = load(fixtures, 'two-slides');
	const [a, b] = page.slides;
	check('the first slide starts active and layered', a.classList.contains('sh-slide--on'));
	check('the other slide starts inert and hidden from screen readers',
		!b.classList.contains('sh-slide--on') && b.getAttribute('aria-hidden') === 'true');
	check('the fade duration comes from the markup, not a hard-coded value',
		page.carousel.style.getPropertyValue('--sh-slide-fade') === '700ms'
		|| /--sh-slide-fade:700ms/.test(page.carousel.getAttribute('style') || ''));

	// Advance one autoplay tick.
	check('autoplay is scheduled', page.timers.intervals.length === 1);
	const interval = page.timers.intervals[0];
	check('autoplay uses the configured interval', interval && interval.ms === 5200, 'got ' + (interval && interval.ms));
	page.runTimers('interval');
	check('the incoming slide fades in on top', b.classList.contains('sh-slide--in') && b.classList.contains('sh-slide--on'));
	check('the outgoing slide is still fully painted while the fade runs',
		a.classList.contains('sh-slide--on') && !a.classList.contains('sh-slide--in'));
	check('screen readers follow the change',
		b.getAttribute('aria-hidden') === null && a.getAttribute('aria-hidden') !== null);

	// The fade settle timer releases the old slide.
	const settle = page.timers.timeouts.find((t) => t.ms >= 820 && t.ms <= 1000);
	check('the outgoing slide is released only after the fade has finished', !!settle);
	page.runTimers('timeout');
	check('the settle timer waits for the whole fade',
		!!settle && settle.ms >= 700 && settle.ms <= 1000, settle ? String(settle.ms) : 'none');
	check('after settling exactly one slide is active', page.active().length === 1 && page.active()[0] === b);
	check('the fade helper class is removed', !b.classList.contains('sh-slide--in'));
	check('the active dot follows the slide',
		page.dots[1].classList.contains('sh-carousel__dot--on') && !page.dots[0].classList.contains('sh-carousel__dot--on'));
	check('the script reported no page errors', page.errors.length === 0, page.errors.join(' | '));
	page.close();
});

// ---------------------------------------------------------------------------
// 3. Autoplay pauses
// ---------------------------------------------------------------------------
scenario('Autoplay pauses: hidden tab, off-screen, hover/focus, and never under reduced motion', () => {
	const page = load(fixtures, 'two-slides');
	check('autoplay starts while the carousel is on screen and the tab is visible', page.timers.intervals.length === 1);
	page.visibility(true);
	check('a hidden tab stops the rotation', page.timers.intervals.length === 0);
	page.visibility(false);
	check('returning to the tab restarts it', page.timers.intervals.length === 1);
	page.intersect(0.05);
	check('scrolling the carousel out of view stops it', page.timers.intervals.length === 0);
	page.intersect(1);
	check('scrolling back restarts it', page.timers.intervals.length === 1);

	page.carousel.dispatchEvent(new page.win.MouseEvent('mouseenter'));
	check('hovering pauses the rotation', page.timers.intervals.length === 0);
	page.carousel.dispatchEvent(new page.win.MouseEvent('mouseleave'));
	check('leaving the pointer restarts it', page.timers.intervals.length === 1);
	page.close();

	const hidden = load(fixtures, 'two-slides', { hidden: true });
	check('a page that loads in a background tab does not start rotating', hidden.timers.intervals.length === 0);
	hidden.close();

	const reduced = load(fixtures, 'two-slides', { reduce: true });
	check('prefers-reduced-motion disables autoplay entirely', reduced.timers.intervals.length === 0);
	// A manual dot click must still work, with an instant cut.
	reduced.dots[1].dispatchEvent(new reduced.win.MouseEvent('click', { bubbles: true }));
	check('reduced motion still allows manual navigation',
		reduced.active().length === 1 && reduced.active()[0] === reduced.slides[1]);
	check('reduced motion leaves the outgoing slide released (no waiting fade)',
		!reduced.slides[0].classList.contains('sh-slide--on') && !reduced.slides[1].classList.contains('sh-slide--in'));
	reduced.close();
});

// ---------------------------------------------------------------------------
// 4. Preparation and failure handling
// ---------------------------------------------------------------------------
scenario('Adjacent image warm-up and a missing image', () => {
	const page = load(fixtures, 'two-slides');
	const warmed = page.images.map((img) => img.getAttribute('src')).filter(Boolean);
	check('the next slide image is warmed in advance', warmed.some((src) => src.includes('slide-grocery')));
	check('the current slide image is not warmed twice', warmed.filter((s) => s.includes('slide-grocery')).length === 1);
	page.close();

	const broken = load(fixtures, 'two-slides');
	const img = broken.slides[0].querySelector('.sh-slide__img');
	img.dispatchEvent(new broken.win.Event('error'));
	check('a broken image marks the slide so the plate stays visible',
		broken.slides[0].classList.contains('sh-slide--noimg'));
	check('the broken source is dropped', !img.getAttribute('src'));
	check('the heading stays on the slide',
		broken.slides[0].querySelector('.sh-slide__title').textContent.includes('Fixture Slide One'));
	broken.close();
});

// ---------------------------------------------------------------------------
// 5. Swipe and pagination
// ---------------------------------------------------------------------------
scenario('Touch swipe and pagination buttons', () => {
	const page = load(fixtures, 'two-slides');
	const touch = (type, x) => {
		const ev = new page.win.Event(type, { bubbles: true });
		ev.touches = [{ clientX: x }];
		ev.changedTouches = [{ clientX: x }];
		page.carousel.dispatchEvent(ev);
	};
	touch('touchstart', 300);
	touch('touchend', 200);
	check('a left swipe advances to the next slide',
		page.slides[1].classList.contains('sh-slide--on') && page.slides[1].classList.contains('sh-slide--in'));
	check('the swiped-out slide keeps painting until the fade settles',
		page.slides[0].classList.contains('sh-slide--on'));
	page.runTimers('timeout');
	check('exactly one slide is active once the fade settles',
		page.active().length === 1 && page.active()[0] === page.slides[1]);
	touch('touchstart', 200);
	touch('touchend', 300);
	page.runTimers('timeout');
	check('a right swipe goes back',
		page.active().length === 1 && page.active()[0] === page.slides[0]);
	check('the pagination buttons stay usable',
		page.dots.length === 2
		&& page.dots[0].getAttribute('aria-label').includes('Fixture Slide One')
		&& page.dots[1].getAttribute('aria-label').includes('Fixture Slide Two'));
	page.close();
});

// ---------------------------------------------------------------------------
console.log('\n' + '='.repeat(72));
console.log('passed ' + passed + '   failed ' + failures.length);
if (failures.length) {
	console.log('\nFailures:');
	failures.forEach((f) => console.log('  - ' + f));
	process.exit(1);
}
console.log('Slider behaviour passed');
