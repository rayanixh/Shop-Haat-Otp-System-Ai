/**
 * Shop Haat — real browser regression (Chromium via Playwright).
 *
 * This is a REAL browser against a REAL PHP server and a REAL MySQL database:
 * it loads pages, clicks, types and submits, and it watches what the browser
 * actually reports (console errors, failed requests, CSP violations, horizontal
 * overflow at a phone width). It is not an HTML inspection.
 *
 * What it covers — and this is deliberately the reliable core, not a pretend
 * full sweep:
 *   1. storefront home page renders with products, no console/network errors
 *   2. category page renders its products
 *   3. product page: main gallery image really loads (naturalWidth > 0), the
 *      next/prev controls really change the counter
 *   4. add to cart from the product page, and the cart page shows the line
 *   5. customer sign-in with email + password
 *   6. checkout: fill the delivery form, choose Cash on Delivery, place the
 *      order, land on order-success with an order number
 *   7. admin sign-in with a seeded administrator, dashboard renders
 *   8. admin guard: a signed-out request for /admin/orders.php is redirected to
 *      the login page and never shows order data
 *   9. mobile width (390×844): no horizontal overflow on home/product/cart,
 *      gallery and buttons stay on screen
 *  10. CSP: the page reports zero securitypolicyviolation events while the
 *      policy is in its default report-only mode
 *
 *   SH_BROWSER_BASE_URL=http://127.0.0.1:8080 \
 *   SH_BROWSER_ADMIN_PASSWORD=… SH_BROWSER_CUSTOMER_PASSWORD=… \
 *     node tests/browser/smoke.mjs
 *
 * Exit code 0 only when every check passed.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = (process.env.SH_BROWSER_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ADMIN = { email: 'admin@example.test', password: process.env.SH_BROWSER_ADMIN_PASSWORD || 'AdminTest!2026' };
const CUSTOMER = { email: 'customer@example.test', password: process.env.SH_BROWSER_CUSTOMER_PASSWORD || 'Customer!2026' };
const ARTIFACTS = join(HERE, 'artifacts');
mkdirSync(ARTIFACTS, { recursive: true });

// What the seed actually built. tests/browser/seed.php records the product it
// gave a multi-image gallery, so the gallery assertions describe a known page
// instead of "whichever card happened to be first".
let seeded = {};
try { seeded = JSON.parse(readFileSync(join(ARTIFACTS, 'state.json'), 'utf8')); } catch { seeded = {}; }

const checks = [];
function check(name, ok, detail = '') {
  checks.push({ name, ok: !!ok, detail });
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

/** Attach console/network/CSP listeners; returns the collectors for a page. */
async function watch(page) {
  const errors = { console: [], requests: [], csp: [], pageErrors: [] };
  page.on('console', (m) => { if (m.type() === 'error') { errors.console.push(m.text().slice(0, 300)); } });
  page.on('pageerror', (e) => errors.pageErrors.push(String(e).slice(0, 300)));
  page.on('requestfailed', (r) => {
    if (!/favicon\.ico$/.test(r.url())) { errors.requests.push(`${r.method()} ${r.url()} — ${r.failure()?.errorText}`); }
  });
  page.on('response', (r) => {
    if (r.status() >= 400 && !/favicon\.ico$/.test(r.url())) { errors.requests.push(`${r.status()} ${r.url()}`); }
  });
  await page.addInitScript(() => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspViolations.push(`${e.violatedDirective} ${e.blockedURI}`);
    });
  });
  return errors;
}

/** Save a full-page screenshot for the CI artifact (one per flow). */
async function snap(page, name) {
  try { await page.screenshot({ path: join(ARTIFACTS, `${name}.png`), fullPage: true }); }
  catch { /* a screenshot is a convenience, never a check */ }
}

function report(errors, label) {
  const csp = errors.csp.length;
  const detail = [
    errors.console.length ? `console: ${errors.console.slice(0, 2).join(' | ')}` : '',
    errors.requests.length ? `requests: ${errors.requests.slice(0, 2).join(' | ')}` : '',
    errors.pageErrors.length ? `js: ${errors.pageErrors.slice(0, 2).join(' | ')}` : '',
  ].filter(Boolean).join(' · ');
  check(`${label}: no console errors`, errors.console.length === 0, detail);
  check(`${label}: no JavaScript exceptions`, errors.pageErrors.length === 0, detail);
  check(`${label}: no failed requests (4xx/5xx/network)`, errors.requests.length === 0, detail);
  return csp;
}

const browser = await chromium.launch();
try {
  /* ------------------------------------------------------------------ */
  console.log('\n1. Storefront home');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  let errors = await watch(page);
  const home = await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
  check('the home page responds 200', home && home.status() === 200, `status ${home?.status()}`);
  const cards = await page.locator('article.sh-product-card').count();
  check('the home page lists products', cards > 0,
    `${cards} product cards · url ${page.url().replace(BASE, '')} · title "${(await page.title()).slice(0, 90)}"`);
  const brokenImages = await page.evaluate(() => Array.from(document.images)
    .filter((img) => img.complete && img.naturalWidth === 0)
    .map((img) => img.currentSrc || img.src).slice(0, 3));
  check('no broken images on the home page', brokenImages.length === 0, brokenImages.join(' | '));
  await snap(page, '01-home');
  const homeCsp = report(errors, 'home');
  const cspEvents = await page.evaluate(() => window.__cspViolations || []);
  check('home: the CSP reports no violations', cspEvents.length === 0 && homeCsp === 0,
    (cspEvents || []).slice(0, 3).join(' | '));

  /* ------------------------------------------------------------------ */
  console.log('\n2. Category page');
  errors = await watch(page);
  // An empty catalogue must not abort the run: the later areas then fail with a
  // readable reason instead of a 30 s locator timeout.
  const catHref = await page.locator('a.sh-product-card__media').first()
    .getAttribute('href', { timeout: 5000 }).catch(() => null);
  if (!catHref) { check('a product card links to a product page', false, 'the home page had no product cards'); }
  const productUrl = seeded.galleryProductUrl
    ? new URL(seeded.galleryProductUrl, BASE).toString()
    : (catHref ? new URL(catHref, BASE).toString() : BASE + '/product.php?slug=missing-in-seed');
  const cat = await page.goto(BASE + '/products.php', { waitUntil: 'domcontentloaded' });
  check('the shop listing responds 200', cat && cat.status() === 200, `status ${cat?.status()}`);
  const listed = await page.locator('article.sh-product-card').count();
  check('the shop listing shows products', listed > 0, `${listed} cards`);
  report(errors, 'listing');

  /* ------------------------------------------------------------------ */
  console.log('\n3. Product page and gallery');
  errors = await watch(page);
  const prod = await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  check('the product page responds 200', prod && prod.status() === 200, `status ${prod?.status()} ${productUrl.replace(BASE, '')}`);
  const mainLoaded = await page.evaluate(() => {
    const img = document.querySelector('[data-gallery-main]');
    return !!img && img.complete && img.naturalWidth > 0;
  });
  check('the main gallery image really loads (naturalWidth > 0)', mainLoaded);
  const thumbs = await page.locator('[data-gallery-thumb]').count();
  const nextBtn = page.locator('[data-gallery-next]').first();
  if (thumbs > 1) {
    // A real multi-image gallery: the control must exist, and pressing it must
    // change both the counter and the image the shopper is looking at.
    check('a multi-image product shows its thumbnails', thumbs > 1, `${thumbs} thumbnails`);
    const before = await page.evaluate(() => ({
      count: (document.querySelector('[data-gallery-count]')?.textContent || '').trim(),
      src: document.querySelector('[data-gallery-main]')?.getAttribute('src') || '',
    }));
    if (await nextBtn.count()) {
      await nextBtn.click();
      await page.waitForTimeout(300);
      const after = await page.evaluate(() => ({
        count: (document.querySelector('[data-gallery-count]')?.textContent || '').trim(),
        src: document.querySelector('[data-gallery-main]')?.getAttribute('src') || '',
      }));
      check('the gallery "next" control changes the counter', before.count !== after.count, `${before.count} → ${after.count}`);
      check('the gallery "next" control changes the main image', before.src !== '' && before.src !== after.src,
        `${before.src.split('/').pop()} → ${after.src.split('/').pop()}`);
    } else {
      check('the gallery "next" control exists for a multi-image product', false, 'no [data-gallery-next]');
    }
  } else {
    // One image, no navigation: the app hides the controls on purpose, so their
    // absence is the correct behaviour and worth asserting.
    check('a single-image product hides the gallery navigation',
      (await page.locator('[data-gallery-next]').count()) === 0 && (await page.locator('[data-gallery-count]').count()) === 0,
      `${thumbs} thumbnail(s), seeded gallery ${seeded.galleryImages ?? 'unknown'}`);
  }
  await snap(page, '03-product');
  report(errors, 'product');

  /* ------------------------------------------------------------------ */
  console.log('\n4. Add to cart → cart page');
  errors = await watch(page);
  const addBtn = page.locator('[data-add-cart]').first();
  check('an add-to-cart control exists', (await addBtn.count()) > 0);
  if (await addBtn.count()) {
    await addBtn.click();
    await page.waitForTimeout(600);
  }
  await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
  const rows = await page.locator('[data-cart-row]').count();
  check('the cart page shows the item that was added', rows > 0, `${rows} line(s)`);
  await snap(page, '04-cart');
  report(errors, 'cart');

  /* ------------------------------------------------------------------ */
  console.log('\n5. Customer sign-in');
  errors = await watch(page);
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  // Scope everything to the form that owns the password field. The first submit
  // button on the page belongs to the header search box, and clicking that
  // "signs in" to a products page with no session at all.
  const loginForm = page.locator('form:has(input[name="password"])').first();
  await loginForm.locator('input[name="email"]').fill(CUSTOMER.email);
  await loginForm.locator('input[name="password"]').fill(CUSTOMER.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    loginForm.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  const afterLogin = page.url();
  const passwordField = await page.locator('input[name="password"]').count();
  check('a customer can sign in with email and password',
    !/\/login\.php/.test(afterLogin) && passwordField === 0,
    `landed on ${afterLogin.replace(BASE, '')} · password field ${passwordField}`);
  // The sign-in only counts when a second request still knows the customer: a
  // landing page that is not the login form can still be a verification gate
  // that created no session at all.
  await page.goto(BASE + '/account.php', { waitUntil: 'domcontentloaded' });
  const accountUrl = page.url();
  check('the signed-in session survives a second request',
    !/\/login\.php/.test(accountUrl), accountUrl.replace(BASE, ''));
  report(errors, 'login');

  /* ------------------------------------------------------------------ */
  console.log('\n6. Checkout → order placed');
  errors = await watch(page);
  // Say the preconditions out loud: a signed-in session and a non-empty cart.
  // Without them a checkout page cannot render its form, and a bare locator
  // timeout would hide which of the two was missing.
  await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
  let cartRows = await page.locator('[data-cart-row]').count();
  if (cartRows === 0) {
    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    const addAgain = page.locator('[data-add-cart]').first();
    if (await addAgain.count()) { await addAgain.click(); await page.waitForTimeout(700); }
    await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
    cartRows = await page.locator('[data-cart-row]').count();
  }
  check('the cart still holds the item after signing in', cartRows > 0, `${cartRows} line(s) at ${page.url().replace(BASE, '')}`);

  const goCheckout = page.locator('a:has-text("Checkout"), a[href*="checkout.php"]').first();
  if (await goCheckout.count()) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), goCheckout.click()]);
  } else {
    await page.goto(BASE + '/checkout.php', { waitUntil: 'domcontentloaded' });
  }
  const onCheckout = page.url().includes('checkout.php') && !page.url().includes('/login.php');
  const heading = (await page.locator('h1').first().textContent().catch(() => '')) || '';
  check('the checkout page opens for a signed-in customer', onCheckout,
    `${page.url().replace(BASE, '')} · h1 "${heading.trim().slice(0, 60)}" · cart ${cartRows}`);
  const hasToken = await page.locator('input[name="order_token"]').count();
  check('checkout carries the order idempotency token', hasToken > 0,
    `${page.url().replace(BASE, '')} · ${hasToken} token field(s)`);
  if (onCheckout && hasToken > 0) {
    await page.fill('input[name="customer_name"]', 'Browser Customer');
    await page.fill('input[name="customer_phone"]', '01700000009');
    await page.fill('input[name="address_line"]', '12 Test Road, Dhanmondi');
    await page.fill('input[name="area"]', 'Dhanmondi');
    await page.fill('input[name="city"]', 'Dhaka');
    const cod = page.locator('input[name="payment_method_id"]').last();
    if (await cod.count()) { await cod.check().catch(() => {}); }
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
      page.locator('button:has-text("Place Order")').first().click(),
    ]);
    const successUrl = page.url();
    check('placing the order lands on the confirmation page', /order-success\.php/.test(successUrl), successUrl.replace(BASE, ''));
    const body = (await page.content()).slice(0, 60000);
    const orderNumber = (body.match(/SH[-\s]?[A-Z0-9]{6,}/) || [])[0] || '';
    check('the confirmation names the order', orderNumber !== '', orderNumber);
  } else {
    check('placing the order lands on the confirmation page', false,
      'checkout was not reachable with the seeded customer session');
  }
  await snap(page, '06-checkout');
  report(errors, 'checkout');

  /* ------------------------------------------------------------------ */
  console.log('\n7. Admin sign-in');
  const adminCtx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const admin = await adminCtx.newPage();
  const adminErrors = await watch(admin);
  await admin.goto(BASE + '/admin/login.php', { waitUntil: 'domcontentloaded' });
  const adminForm = admin.locator('form:has(input[name="password"])').first();
  await adminForm.locator('input[name="email"]').fill(ADMIN.email);
  await adminForm.locator('input[name="password"]').fill(ADMIN.password);
  await Promise.all([
    admin.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    adminForm.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  const adminUrl = admin.url();
  check('an administrator can sign in', !adminUrl.includes('/admin/login.php'), adminUrl.replace(BASE, ''));
  const adminHasNav = await admin.locator('a[href*="admin/products.php"], a[href*="admin/orders.php"]').count();
  check('the admin shell renders its navigation', adminHasNav > 0, `${adminHasNav} links`);
  await snap(admin, '07-admin');
  report(adminErrors, 'admin');

  /* ------------------------------------------------------------------ */
  console.log('\n8. Admin guard without a session');
  const anonCtx = await browser.newContext();
  const anon = await anonCtx.newPage();
  await watch(anon);
  const guarded = await anon.goto(BASE + '/admin/orders.php', { waitUntil: 'domcontentloaded' });
  const anonBody = (await anon.content()).slice(0, 20000);
  check('a signed-out /admin/orders.php request does not show order data',
    !/Order\s*#|order_number|sh-table__row/i.test(anonBody),
    anon.url().replace(BASE, ''));
  check('it is answered with a login page or a redirect',
    /login\.php/.test(anon.url()) || (guarded && guarded.status() === 403), `status ${guarded?.status()}`);
  await anonCtx.close();

  /* ------------------------------------------------------------------ */
  console.log('\n9. Mobile width (390×844)');
  const mobileCtx = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
    deviceScaleFactor: 2,
  });
  const mobile = await mobileCtx.newPage();
  await watch(mobile);
  for (const [label, url] of [['home', '/index.php'], ['product', new URL(productUrl).pathname], ['cart', '/cart.php']]) {
    await mobile.goto(BASE + url, { waitUntil: 'domcontentloaded' });
    const overflow = await mobile.evaluate(() => ({
      scroll: document.documentElement.scrollWidth,
      client: document.documentElement.clientWidth,
    }));
    check(`mobile / ${label}: no horizontal overflow`,
      overflow.scroll <= overflow.client + 1, `scrollWidth ${overflow.scroll} vs ${overflow.client}`);
  }
  await mobile.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
  const navVisible = await mobile.evaluate(() => {
    const el = document.querySelector('header');
    if (!el) { return false; }
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  });
  check('mobile: the header is visible and sized', navVisible);
  const offscreen = await mobile.evaluate(() => Array.from(document.querySelectorAll('button, a.sh-btn'))
    .filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.right > document.documentElement.clientWidth + 1; })
    .length);
  check('mobile: no button or main call-to-action runs off screen', offscreen === 0, `${offscreen} off screen`);
  await mobileCtx.close();

  await ctx.close();
  await adminCtx.close();
} catch (error) {
  check('the browser run completed without an unexpected exception', false, String(error).slice(0, 400));
  console.error(error);
} finally {
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
console.log(`\nBrowser regression: ${checks.length - failed.length} passed, ${failed.length} failed`);
if (failed.length) {
  console.log('Failures:');
  for (const f of failed) { console.log(` - ${f.name}${f.detail ? ` (${f.detail})` : ''}`); }
}
console.log(`\nBase URL: ${BASE} · Chromium ${browser.version?.() || ''}`);
process.exit(failed.length ? 1 : 0);
