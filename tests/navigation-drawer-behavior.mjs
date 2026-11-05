/**
 * Regression test for the storefront and admin navigation drawer lifecycle.
 * Runs the shipped assets/js/app.js with jsdom and checks the shipped CSS and
 * PHP template hooks without requiring PHP, a database, or a browser binary.
 *
 *   SH_JSDOM_PATH=/path/to/jsdom node tests/navigation-drawer-behavior.mjs
 */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const require_ = createRequire(import.meta.url);
let JSDOM;
try {
  ({ JSDOM } = process.env.SH_JSDOM_PATH
    ? require_(process.env.SH_JSDOM_PATH)
    : require_('jsdom'));
} catch {
  console.log('SKIP  jsdom is not installed; set SH_JSDOM_PATH to run this test.');
  process.exit(0);
}

const appJs = readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8');
const css = readFileSync(join(ROOT, 'assets/css/app.css'), 'utf8');
const storefrontTemplate = readFileSync(join(ROOT, 'includes/header.php'), 'utf8');
const adminTemplate = readFileSync(join(ROOT, 'admin/_layout.php'), 'utf8');
const productsTemplate = readFileSync(join(ROOT, 'products.php'), 'utf8');
const categoryTemplate = readFileSync(join(ROOT, 'category.php'), 'utf8');
let passed = 0;
function check(name, fn) {
  try {
    fn();
    passed += 1;
    console.log(`  ok  ${name}`);
  } catch (error) {
    console.error(`FAIL  ${name}: ${error.message}`);
    throw error;
  }
}

function makePage(markup, url) {
  const dom = new JSDOM(markup, { url, runScripts: 'outside-only', pretendToBeVisual: true });
  const { window } = dom;
  let x = 23;
  let y = 480;
  const scrollCalls = [];
  Object.defineProperty(window, 'scrollX', { configurable: true, get: () => x });
  Object.defineProperty(window, 'scrollY', { configurable: true, get: () => y });
  window.scrollTo = (nextX, nextY) => {
    x = Number(nextX);
    y = Number(nextY);
    scrollCalls.push([x, y]);
  };
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390, writable: true });
  window.SH_BASE = '/';
  window.SH_CSRF = 'test-token';
  window.confirm = () => true;
  window.fetch = () => Promise.resolve({ ok: true, text: () => Promise.resolve('{}') });
  window.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  window.eval(appJs);
  return { dom, window, scrollCalls, getPosition: () => [x, y] };
}

function storefrontPage() {
  return makePage(`<!doctype html><html style="overflow-y: scroll; color: rebeccapurple">
    <head><title>Store</title></head><body style="margin: 4px; scroll-behavior: smooth">
      <button type="button" data-drawer-open aria-expanded="false" aria-label="Open menu">Open</button>
      <main id="store-page"><a href="/products.php">Page content</a></main>
      <div class="sh-drawer" id="sh-drawer" hidden>
        <div class="sh-drawer__panel" role="dialog" aria-label="Menu" aria-modal="true">
          <div class="sh-drawer__head"><span>ShopHaat</span><button type="button" data-drawer-close aria-label="Close menu">Close</button></div>
          <div class="sh-drawer__body" tabindex="0" aria-label="Menu links">
            <a href="/category.php?slug=one">Category one</a>
            <a href="/products.php?sort=newest">New arrivals</a>
            <a href="/orders.php">My orders</a>
          </div>
        </div>
        <button class="sh-drawer__scrim" type="button" data-drawer-close aria-label="Close menu"></button>
      </div>
    </body></html>`, 'https://shop.example.test/');
}

function filterPage() {
  return makePage(`<!doctype html><html style="overflow-y: auto; color: seagreen">
    <head><title>Products</title></head><body style="margin: 3px; scroll-behavior: smooth">
      <main class="sh-listing">
        <aside class="sh-filters" id="sh-filters" aria-label="Product filters">
          <form>
            <div class="sh-filters__head"><span>Filters</span><button type="button" data-filters-close aria-label="Close filters">Close</button></div>
            <div class="sh-filters__scroll" tabindex="0" aria-label="Filter options">
              <a href="/category.php?slug=one">Category one</a>
              <a href="/category.php?slug=two">Category two</a>
            </div>
            <div class="sh-filters__actions"><button type="submit">Apply filters</button></div>
          </form>
        </aside>
        <button class="sh-filters__scrim" type="button" data-filters-close hidden aria-label="Close filters"></button>
        <div class="sh-mobile-tools"><button type="button" data-filters-open aria-expanded="false" aria-label="Open filters">Filter</button></div>
      </main>
    </body></html>`, 'https://shop.example.test/products.php');
}

function providerLogoPage() {
  return makePage(`<!doctype html><html><head></head><body>
    <span class="sh-aiprov__mark" data-driver="openai">
      <img src="/assets/images/ai-providers/openai.svg" alt="OpenAI logo" data-ai-provider-logo>
      <span class="sh-aiprov__mark-fallback" role="img" aria-label="OpenAI" hidden>OpenAI</span>
    </span>
  </body></html>`, 'https://shop.example.test/admin/ai/providers.php');
}

function adminPage() {
  return makePage(`<!doctype html><html style="overflow-x: clip; color: navy">
    <head><title>Admin</title></head><body style="margin: 2px; scroll-behavior: smooth">
      <div class="sh-admin">
        <aside class="sh-admin-sidebar" id="sh-admin-sidebar">
          <div class="sh-admin-sidebar__head">
            <a class="sh-admin-sidebar__brand" href="/admin/dashboard.php">ShopHaat Admin</a>
            <button type="button" data-admin-nav-close aria-label="Close admin navigation">Close</button>
          </div>
          <nav class="sh-admin-sidebar__nav" aria-label="Admin navigation" tabindex="0">
            <a href="/admin/products.php">Products</a>
            <a href="/admin/orders.php">Orders</a>
            <a href="/admin/settings.php">Settings</a>
          </nav>
        </aside>
        <button class="sh-admin-scrim" type="button" hidden aria-label="Close admin navigation"></button>
        <div class="sh-admin-main"><header><button type="button" data-admin-burger aria-expanded="false" aria-label="Open admin navigation">Menu</button></header><main>Content</main></div>
      </div>
    </body></html>`, 'https://shop.example.test/admin/dashboard.php');
}

function assertReleased(page, bodyStyle, rootStyle, expectedPosition) {
  const { window, scrollCalls, getPosition } = page;
  assert.equal(window.document.body.getAttribute('style'), bodyStyle, 'body inline styles must be restored byte-for-byte');
  assert.equal(window.document.documentElement.getAttribute('style'), rootStyle, 'root inline styles must be restored byte-for-byte');
  assert.deepEqual(getPosition(), expectedPosition, 'page scroll position must be restored');
  assert.deepEqual(scrollCalls.at(-1), expectedPosition, 'scrollTo must restore both axes');
}

async function runStorefront() {
  const page = storefrontPage();
  const { window } = page;
  const doc = window.document;
  const drawer = doc.querySelector('#sh-drawer');
  const panel = doc.querySelector('.sh-drawer__panel');
  const trigger = doc.querySelector('[data-drawer-open]');
  const close = panel.querySelector('[data-drawer-close]');
  const scroller = panel.querySelector('.sh-drawer__body');
  const bodyStyle = doc.body.getAttribute('style');
  const rootStyle = doc.documentElement.getAttribute('style');
  const originalPosition = page.getPosition();

  trigger.click();
  check('storefront drawer opens and freezes the underlying page at its exact offset', () => {
    assert.equal(drawer.hidden, false);
    assert.equal(doc.documentElement.style.getPropertyValue('overflow'), 'hidden');
    assert.equal(doc.body.style.getPropertyValue('position'), 'fixed');
    assert.equal(doc.body.style.getPropertyValue('top'), '-480px');
    assert.equal(doc.body.style.getPropertyValue('left'), '-23px');
    assert.deepEqual(page.getPosition(), originalPosition);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(doc.activeElement, close);
  });
  check('storefront menu keeps its keyboard-scroll surface and working links', () => {
    assert.equal(scroller.getAttribute('tabindex'), '0');
    assert.equal(scroller.querySelector('a[href="/orders.php"]').getAttribute('href'), '/orders.php');
    const lastLink = scroller.querySelector('a:last-of-type');
    lastLink.focus();
    const tab = new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    doc.dispatchEvent(tab);
    assert.equal(tab.defaultPrevented, true);
    assert.equal(doc.activeElement, close, 'Tab wraps within the open navigation dialog');
  });
  doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  check('storefront Escape close restores the original styles, exact position and opener focus', () => {
    assert.equal(drawer.hidden, true);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(doc.activeElement, trigger);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  // Repeated close paths must not leave a reference-counted lock behind.
  trigger.click();
  close.click();
  trigger.click();
  drawer.querySelector('.sh-drawer__scrim').click();
  check('storefront close-button and scrim cycles release the lock repeatedly', () => {
    assert.equal(drawer.hidden, true);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  trigger.click();
  window.dispatchEvent(new window.Event('pagehide'));
  check('pagehide also releases a drawer lock for navigation or bfcache cleanup', () => {
    assert.equal(drawer.hidden, true);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  trigger.click();
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280, writable: true });
  window.dispatchEvent(new window.Event('resize'));
  check('storefront drawer closes when the viewport leaves its mobile breakpoint', () => {
    assert.equal(drawer.hidden, true);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390, writable: true });
  trigger.click();
  drawer.remove();
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  check('removing an open storefront drawer cleans up its scroll lock', () => {
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });
  page.dom.window.close();
}

async function runFilters() {
  const page = filterPage();
  const { window } = page;
  const doc = window.document;
  const panel = doc.querySelector('#sh-filters');
  const trigger = doc.querySelector('[data-filters-open]');
  const close = panel.querySelector('[data-filters-close]');
  const scroller = panel.querySelector('.sh-filters__scroll');
  const scrim = doc.querySelector('.sh-filters__scrim');
  const bodyStyle = doc.body.getAttribute('style');
  const rootStyle = doc.documentElement.getAttribute('style');
  const originalPosition = page.getPosition();

  trigger.click();
  check('product filter drawer uses the shared page lock and accessible modal state', () => {
    assert.equal(panel.classList.contains('sh-filters--open'), true);
    assert.equal(panel.getAttribute('role'), 'dialog');
    assert.equal(panel.getAttribute('aria-modal'), 'true');
    assert.equal(scrim.hidden, false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(doc.activeElement, close);
    assert.equal(doc.documentElement.style.getPropertyValue('overflow'), 'hidden');
    assert.equal(doc.body.style.getPropertyValue('position'), 'fixed');
  });
  check('filter options have their own keyboard-scroll area and route links are preserved', () => {
    assert.equal(scroller.getAttribute('tabindex'), '0');
    assert.equal(scroller.querySelector('a[href="/category.php?slug=two"]').getAttribute('href'), '/category.php?slug=two');
    scroller.focus();
    const pageDown = new window.KeyboardEvent('keydown', { key: 'PageDown', bubbles: true, cancelable: true });
    doc.dispatchEvent(pageDown);
    assert.equal(pageDown.defaultPrevented, false, 'PageDown stays native in the focused filter scroller');
  });
  close.click();
  check('closing filter drawer restores exact original styles, page offset and opener focus', () => {
    assert.equal(panel.classList.contains('sh-filters--open'), false);
    assert.equal(panel.hasAttribute('role'), false);
    assert.equal(scrim.hidden, true);
    assert.equal(doc.activeElement, trigger);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  trigger.click();
  scrim.click();
  check('filter scrim close can be repeated without leaving the page locked', () => {
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });
  trigger.click();
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 950, writable: true });
  window.dispatchEvent(new window.Event('resize'));
  check('filter drawer closes when the listing returns to its desktop layout', () => {
    assert.equal(panel.classList.contains('sh-filters--open'), false);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390, writable: true });
  trigger.click();
  panel.remove();
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  check('removing an open filter drawer cleans up the shared scroll lock', () => {
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });
  page.dom.window.close();
}

async function runAdmin() {
  const page = adminPage();
  const { window } = page;
  const doc = window.document;
  const shell = doc.querySelector('.sh-admin');
  const sidebar = doc.querySelector('.sh-admin-sidebar');
  const burger = doc.querySelector('[data-admin-burger]');
  const scrim = doc.querySelector('.sh-admin-scrim');
  const close = doc.querySelector('[data-admin-nav-close]');
  const nav = doc.querySelector('.sh-admin-sidebar__nav');
  const bodyStyle = doc.body.getAttribute('style');
  const rootStyle = doc.documentElement.getAttribute('style');
  const originalPosition = page.getPosition();

  burger.click();
  check('admin drawer opens, exposes its close control and freezes the page', () => {
    assert.equal(shell.classList.contains('is-nav-open'), true);
    assert.equal(scrim.hidden, false);
    assert.equal(burger.getAttribute('aria-expanded'), 'true');
    assert.equal(doc.activeElement, close);
    assert.equal(doc.documentElement.style.getPropertyValue('overflow'), 'hidden');
    assert.equal(doc.body.style.getPropertyValue('position'), 'fixed');
    assert.equal(doc.body.style.getPropertyValue('top'), '-480px');
  });
  check('admin nav is keyboard-focusable and its route links remain intact', () => {
    assert.equal(nav.getAttribute('tabindex'), '0');
    assert.equal(nav.querySelector('a[href="/admin/orders.php"]').getAttribute('href'), '/admin/orders.php');
    const lastLink = nav.querySelector('a:last-of-type');
    lastLink.focus();
    const tab = new window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    doc.dispatchEvent(tab);
    assert.equal(tab.defaultPrevented, true);
    assert.equal(doc.activeElement, sidebar.querySelector('.sh-admin-sidebar__brand'));
  });
  close.click();
  check('admin close button restores original styles, position and burger focus', () => {
    assert.equal(shell.classList.contains('is-nav-open'), false);
    assert.equal(scrim.hidden, true);
    assert.equal(burger.getAttribute('aria-expanded'), 'false');
    assert.equal(doc.activeElement, burger);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  burger.click();
  scrim.click();
  burger.click();
  doc.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  check('admin scrim and Escape cycles release the shared lock repeatedly', () => {
    assert.equal(shell.classList.contains('is-nav-open'), false);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  burger.click();
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280, writable: true });
  window.dispatchEvent(new window.Event('resize'));
  check('resizing out of the mobile breakpoint closes the admin drawer and unlocks the page', () => {
    assert.equal(shell.classList.contains('is-nav-open'), false);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });

  burger.click();
  sidebar.remove();
  await new Promise((resolve) => window.setTimeout(resolve, 0));
  check('removing an open admin sidebar cleans up its scroll lock', () => {
    assert.equal(shell.classList.contains('is-nav-open'), false);
    assertReleased(page, bodyStyle, rootStyle, originalPosition);
  });
  page.dom.window.close();
}

function runProviderLogoFallback() {
  const page = providerLogoPage();
  const img = page.window.document.querySelector('[data-ai-provider-logo]');
  const fallback = page.window.document.querySelector('.sh-aiprov__mark-fallback');
  img.dispatchEvent(new page.window.Event('error'));
  check('failed local provider logos reveal the honest text fallback instead of a generic image', () => {
    assert.equal(img.hidden, true);
    assert.equal(fallback.hidden, false);
    assert.equal(fallback.textContent, 'OpenAI');
    assert.equal(img.getAttribute('src'), '/assets/images/ai-providers/openai.svg');
  });
  page.dom.window.close();
}

check('storefront, admin and listing templates expose focusable drawers and explicit close controls', () => {
  assert.match(storefrontTemplate, /class="sh-drawer__body" tabindex="0"/);
  assert.match(storefrontTemplate, /data-drawer-open aria-controls="sh-drawer"/);
  assert.match(storefrontTemplate, /data-drawer-close/);
  assert.match(adminTemplate, /class="sh-admin-sidebar__nav" aria-label="Admin navigation" tabindex="0"/);
  assert.match(adminTemplate, /data-admin-nav-close/);
  assert.match(adminTemplate, /aria-controls="sh-admin-sidebar" aria-expanded="false"/);
  for (const template of [productsTemplate, categoryTemplate]) {
    assert.match(template, /class="sh-filters__scroll" tabindex="0" aria-label="Filter options"/);
    assert.match(template, /data-filters-open aria-controls="sh-filters" aria-expanded="false"/);
    assert.match(template, /data-filters-close hidden aria-label="Close filters"/);
  }
});
check('CSS confines overscroll and native touch scrolling to the three actual drawer scrollers', () => {
  const allowedScrollers = ['.sh-drawer__body', '.sh-admin-sidebar__nav', '.sh-filters__scroll'];
  const scrollRules = [...css.matchAll(/([^{}]+)\{([^{}]*\boverflow(?:-[xy])?\s*:\s*(?:auto|scroll)\b[^{}]*)\}/gi)];
  for (const rule of scrollRules) {
    assert.ok(allowedScrollers.some((selector) => rule[1].includes(selector)), `ordinary content must not scroll inside ${rule[1].trim()}`);
  }
  for (const selector of allowedScrollers) {
    const rules = [...css.matchAll(new RegExp(`${selector.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`, 'g'))];
    const rule = rules.at(-1)?.[1] || '';
    assert.match(rule, /overflow-y:\s*auto/);
    assert.match(rule, /overscroll-behavior:\s*contain/);
    assert.match(rule, /-webkit-overflow-scrolling:\s*touch/);
    assert.match(rule, /touch-action:\s*pan-y/);
  }
  assert.match(css, /\.sh-drawer\s*\{[^}]*position:\s*fixed/);
  assert.match(css, /\.sh-admin-sidebar\s*\{[^}]*display:\s*flex/);
});
assert.doesNotMatch(appJs, /addEventListener\(\s*['"]touchmove['"]/);
runProviderLogoFallback();

await runStorefront();
await runFilters();
await runAdmin();
console.log(`\nPASS  ${passed} navigation drawer checks`);
