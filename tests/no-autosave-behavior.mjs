/**
 * No-autosave behaviour harness.
 *
 * Drives the REAL admin script (assets/js/app.js) and the REAL SEO preview
 * script (assets/js/seo.js) inside jsdom against fixtures that mirror the
 * shipped admin markup, and asserts the rule the administrator sees:
 *
 *   Typing, pasting, changing a field, blurring or waiting NEVER writes
 *   anything. Only an explicit Save / Create / Update submission does. The
 *   unsaved-changes guard warns before leaving — and nothing more.
 *
 * It also proves the SEO preview shows long copy in full (SEO copy has no
 * character limit anywhere) instead of clipping or counting it.
 *
 * This is a test-only harness: nothing in it ships to the store and the site
 * still runs on plain PHP with no Node.js requirement.
 *
 *   node tests/no-autosave-behavior.mjs
 *
 * Environment:
 *   SH_JSDOM_PATH         path to a jsdom installation (default: require("jsdom"))
 *   SH_NA_REQUIRE_JSDOM   "1" fails instead of skipping when jsdom is missing
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

// ---------------------------------------------------------------------------
// jsdom is a development-only dependency (see .github/workflows). Without it
// the harness reports that it did not run instead of pretending to pass.
// ---------------------------------------------------------------------------
let JSDOM = null;
try {
	const path = process.env.SH_JSDOM_PATH;
	({ JSDOM } = path ? require_(path) : require_('jsdom'));
} catch {
	JSDOM = null;
}
if (!JSDOM) {
	const msg = 'jsdom is not installed, so the no-autosave behaviour harness did not run.';
	if (process.env.SH_NA_REQUIRE_JSDOM === '1') {
		console.error('::error title=No-autosave harness::' + msg);
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

// ---------------------------------------------------------------------------
// Part 1: the unsaved-changes guard in assets/js/app.js
// ---------------------------------------------------------------------------

const ADMIN_HTML = `<!DOCTYPE html>
<html><head><title>Edit product</title></head><body>
<div class="sh-admin">
  <form id="edit" method="post" action="/admin/product-edit.php">
    <input type="hidden" name="id" value="1">
    <label>Product name <input id="edit-name" type="text" name="name" value=""></label>
    <div class="sh-formbar">
      <button type="submit" name="form" value="save">Save changes</button>
      <a href="/admin/products.php">Cancel</a>
    </div>
  </form>

  <form id="filter" method="get" action="/admin/products.php">
    <select id="filter-sel" name="status" data-filter-auto>
      <option value="">All</option><option value="1">Live</option>
    </select>
    <input id="filter-q" type="search" name="q" value="">
  </form>

  <form id="actiononly" method="post" action="/admin/products.php">
    <input type="hidden" name="do" value="toggle">
    <button type="submit">Toggle</button>
  </form>

  <form id="pickerform" method="post" action="/admin/seo.php">
    <select id="picker" data-seo-page-picker>
      <option value="home">Home</option><option value="shop">Shop</option>
    </select>
    <input id="picker-name" type="text" name="name" value="">
    <button type="submit">Save</button>
  </form>

  <form id="optout" method="post" action="/admin/settings.php" data-no-dirty-guard>
    <input id="optout-name" type="text" name="name" value="">
    <button type="submit">Save</button>
  </form>
</div>

<form id="explicit" method="post" action="/admin/ai/products.php" data-dirty-guard>
  <input id="explicit-name" type="text" name="name" value="">
  <button type="submit">Publish product</button>
</form>
</body></html>`;

const STOREFRONT_HTML = `<!DOCTYPE html>
<html><head><title>Checkout</title></head><body>
<form id="plain" method="post" action="/checkout.php">
  <input id="plain-name" type="text" name="name" value="">
  <button type="submit">Place order</button>
</form>
<form id="explicit2" method="post" action="/account.php" data-dirty-guard>
  <input id="explicit2-name" type="text" name="name" value="">
  <button type="submit">Save</button>
</form>
</body></html>`;

function makePage(html, url) {
	const dom = new JSDOM(html, { url, runScripts: 'outside-only' });
	const { window } = dom;
	const fetches = [];
	window.SH_BASE = '/';
	window.SH_CSRF = 'test-token';
	window.confirm = () => true;
	window.fetch = (target, opts = {}) => {
		fetches.push({ url: String(target), method: (opts.method || 'GET').toUpperCase() });
		return Promise.resolve({
			ok: true,
			headers: { get: () => 'application/json' },
			json: () => Promise.resolve({}),
			text: () => Promise.resolve(''),
		});
	};
	// jsdom has no IntersectionObserver; app.js tolerates its absence, but a
	// stub keeps the harness quiet and closer to a real browser.
	window.IntersectionObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
	const src = readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8');
	window.eval(src);
	return { dom, window, fetches };
}

function type(window, el, value) {
	el.value = value;
	el.dispatchEvent(new window.Event('input', { bubbles: true }));
}

function warnedOnLeave(window) {
	const ev = new window.Event('beforeunload', { cancelable: true });
	window.dispatchEvent(ev);
	return ev.defaultPrevented === true;
}

function submitForm(window, form) {
	const ev = new window.Event('submit', { bubbles: true, cancelable: true });
	form.dispatchEvent(ev);
	return ev;
}

async function testGuard() {
	console.log('\nUnsaved-changes guard (assets/js/app.js)');
	const { window, fetches } = makePage(ADMIN_HTML, 'http://localhost/admin/product-edit.php');
	const doc = window.document;
	const edit = doc.getElementById('edit');
	const editName = doc.getElementById('edit-name');

	check('a fresh page does not warn on leave', !warnedOnLeave(window));

	const note = edit.querySelector('[data-dirty-note]');
	check('an admin edit form gets the unsaved-changes pill', !!note);
	check('the pill starts hidden', !!note && note.hidden === true);

	// Typing and pasting must only change the field — never the database.
	type(window, editName, 'Stainless');
	check('typing shows the unsaved-changes pill', !!note && note.hidden === false);
	check('typing warns before leaving', warnedOnLeave(window));

	const longText = 'A very long pasted product description. '.repeat(40);
	editName.dispatchEvent(new window.Event('paste', { bubbles: true }));
	type(window, editName, longText);
	check('pasting keeps the full text in the field', editName.value === longText);
	editName.dispatchEvent(new window.Event('blur', { bubbles: true }));
	editName.dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(1100); // long enough for any debounce to have fired
	check(
		'paste + blur + change + waiting performs no write',
		fetches.every((f) => f.method === 'GET'),
		JSON.stringify(fetches)
	);
	check('the form is still marked unsaved afterwards', !!note && note.hidden === false);

	// An uncancelled submission is the save: the guard stands down.
	submitForm(window, edit);
	check('submitting clears the pill', !!note && note.hidden === true);
	check('submitting clears the leave warning', !warnedOnLeave(window));

	// A cancelled submission (confirm dialog refused) is not a save.
	type(window, editName, 'Edited again');
	edit.setAttribute('data-confirm', 'Save these changes?');
	window.confirm = () => false;
	submitForm(window, edit);
	check('a cancelled submission stays unsaved', !!note && note.hidden === false);
	edit.removeAttribute('data-confirm');
	window.confirm = () => true;

	// Script-saved forms clear the guard only by announcing a real save.
	edit.dispatchEvent(new window.Event('sh:form-clean', { bubbles: true }));
	check('sh:form-clean stands the guard down', !!note && note.hidden === true);
	check('no write happened at any point in the edit cycle',
		fetches.every((f) => f.method === 'GET'), JSON.stringify(fetches));

	// GET filter forms are read-only navigation, never guarded.
	const filter = doc.getElementById('filter');
	check('a GET filter form gets no pill', !filter.querySelector('[data-dirty-note]'));
	type(window, doc.getElementById('filter-q'), 'kettle');
	check('typing in a GET filter never warns', !warnedOnLeave(window));

	// Hidden-only one-click action forms are not edit forms.
	const action = doc.getElementById('actiononly');
	check('a hidden-only action form gets no pill', !action.querySelector('[data-dirty-note]'));

	// A picker that only navigates must not mark its form dirty by itself…
	const pickerForm = doc.getElementById('pickerform');
	const picker = doc.getElementById('picker');
	picker.value = 'shop';
	picker.dispatchEvent(new window.Event('change', { bubbles: true }));
	const pickerNote = pickerForm.querySelector('[data-dirty-note]');
	check('changing only a navigation picker stays clean',
		!!pickerNote && pickerNote.hidden === true && !warnedOnLeave(window));
	// …but a real edit in the same form is guarded.
	type(window, doc.getElementById('picker-name'), 'Typed');
	check('a real edit next to a picker is guarded',
		!!pickerNote && pickerNote.hidden === false && warnedOnLeave(window));
	submitForm(window, pickerForm);

	// Explicit opt-out.
	type(window, doc.getElementById('optout-name'), 'x');
	check('data-no-dirty-guard opts a form out',
		!doc.getElementById('optout').querySelector('[data-dirty-note]') && !warnedOnLeave(window));

	// Explicit opt-in works anywhere, e.g. the script-handled AI review.
	const explicit = doc.getElementById('explicit');
	type(window, doc.getElementById('explicit-name'), 'Draft');
	const explicitNote = explicit.querySelector('[data-dirty-note]');
	check('data-dirty-guard arms a form explicitly',
		!!explicitNote && explicitNote.hidden === false && warnedOnLeave(window));
	submitForm(window, explicit);
	check('the explicit form also clears on submit', !warnedOnLeave(window));

	// Storefront: no .sh-admin, so generic coverage must not arm.
	const front = makePage(STOREFRONT_HTML, 'http://localhost/checkout.php');
	const fdoc = front.window.document;
	type(front.window, fdoc.getElementById('plain-name'), 'Shopper');
	check('a storefront POST form is not guarded by the admin sweep',
		!fdoc.getElementById('plain').querySelector('[data-dirty-note]')
			&& !warnedOnLeave(front.window));
	type(front.window, fdoc.getElementById('explicit2-name'), 'Shopper');
	check('data-dirty-guard still works on the storefront', warnedOnLeave(front.window));
}

// ---------------------------------------------------------------------------
// Part 2: the SEO preview in assets/js/seo.js
// ---------------------------------------------------------------------------

const SEO_HTML = `<!DOCTYPE html>
<html><head><title>SEO</title></head><body>
<div class="sh-panel" data-seo-block="1" data-seo-brand="ShopHaat" data-seo-suffix=" | ShopHaat">
  <input type="text" name="seo_meta_title" data-seo-field="title" placeholder="Auto title" value="">
  <output data-seo-check="focus"></output>
  <textarea name="seo_meta_description" data-seo-field="description" placeholder="Auto description"></textarea>
  <input type="text" data-seo-field="focus" value="">
  <input type="text" data-seo-field="canonical" data-seo-auto="/product/kettle" value="">
  <input type="text" data-seo-field="image" value="">
  <input type="checkbox" data-seo-field="noindex">
  <span data-seo-preview="host"></span>
  <span data-seo-preview="url"></span>
  <span data-seo-preview="title"></span>
  <p data-seo-preview="description"></p>
  <span data-seo-preview="og-title"></span>
  <p data-seo-preview="og-description"></p>
  <img data-seo-preview="image" alt="">
  <span data-seo-preview="image-fallback">No image</span>
  <span data-seo-preview="noindex-note" hidden>Hidden</span>
</div>
</body></html>`;

async function testSeoPreview() {
	console.log('\nSEO preview (assets/js/seo.js)');
	const dom = new JSDOM(SEO_HTML, { url: 'http://localhost/admin/product-edit.php', runScripts: 'outside-only' });
	const { window } = dom;
	window.SH_BASE = '/';
	window.eval(readFileSync(join(ROOT, 'assets/js/seo.js'), 'utf8'));
	// seo.js defers to DOMContentLoaded when it is loaded during parsing.
	if (window.document.readyState !== 'complete') {
		await new Promise((resolve) => window.addEventListener('load', resolve));
	}

	const doc = window.document;
	const title = doc.querySelector('[data-seo-field="title"]');
	const desc = doc.querySelector('[data-seo-field="description"]');
	const prevTitle = doc.querySelector('[data-seo-preview="title"]');
	const prevDesc = doc.querySelector('[data-seo-preview="description"]');
	const prevOgDesc = doc.querySelector('[data-seo-preview="og-description"]');

	check('the title field carries no maxlength', title.maxLength === -1);
	check('the description field carries no maxlength', desc.maxLength === -1);
	check('empty fields preview the automatic values',
		prevTitle.textContent === 'Auto title' && prevDesc.textContent === 'Auto description');

	const longTitle = 'Stainless Steel Electric Kettle 1.8L | ShopHaat Kitchen Collection With Free Delivery Across Bangladesh';
	const longDesc = ('A 1.8 litre stainless steel kettle with a cool-touch handle, rapid boil element '
		+ 'and automatic shut-off, delivered anywhere in Bangladesh with cash on delivery. ').repeat(2).trim();
	type(window, title, longTitle);
	type(window, desc, longDesc);

	check('a title past 60 characters is previewed in full',
		prevTitle.textContent === longTitle, 'got: ' + prevTitle.textContent);
	check('a description past 160 characters is previewed in full',
		prevDesc.textContent === longDesc, 'got: ' + prevDesc.textContent);
	check('the social preview is also unshortened', prevOgDesc.textContent === longDesc);
	check('no character counter is rendered anywhere',
		!doc.querySelector('[data-seo-count]') && !/of 60|of 160|characters used/i.test(doc.body.textContent));
}

async function main() {
	await testGuard();
	await testSeoPreview();

	console.log(`\n${passed} passed, ${failures.length} failed`);
	if (failures.length > 0) {
		console.log('NO-AUTOSAVE BEHAVIOUR: FAILED');
		process.exit(1);
	}
	console.log('NO-AUTOSAVE BEHAVIOUR: ALL CHECKS PASSED');
}

main().catch((err) => {
	console.error('::error title=No-autosave harness::' + (err && err.message ? err.message : String(err)));
	console.error(err && err.stack ? err.stack : err);
	process.exit(1);
});
