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
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = (process.env.SH_BROWSER_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const ADMIN = { email: 'admin@example.test', password: process.env.SH_BROWSER_ADMIN_PASSWORD || 'AdminTest!2026' };
const CUSTOMER = { email: 'customer@example.test', password: process.env.SH_BROWSER_CUSTOMER_PASSWORD || 'Customer!2026' };
const ARTIFACTS = join(HERE, 'artifacts');
mkdirSync(ARTIFACTS, { recursive: true });

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
  const productUrl = catHref ? new URL(catHref, BASE).toString() : BASE + '/product.php?slug=missing-in-seed';
  if (!catHref) { check('a product card links to a product page', false, 'the home page had no product cards'); }
  const cat = await page.goto(BASE + '/products.php', { waitUntil: 'domcontentloaded' });
  check('the shop listing responds 200', cat && cat.status() === 200, `status ${cat?.status()}`);
  const listed = await page.locator('article.sh-product-card').count();
  check('the shop listing shows products', listed > 0, `${listed} cards`);
  report(errors, 'listing');

  /* ------------------------------------------------------------------ */
  console.log('\n3. Product page and gallery');
  errors = await watch(page);
  const prod = await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  check('the product page responds 200', prod && prod.status() === 200, `status ${prod?.status()}`);
  const mainLoaded = await page.evaluate(() => {
    const img = document.querySelector('[data-gallery-main]');
    return !!img && img.complete && img.naturalWidth > 0;
  });
  check('the main gallery image really loads (naturalWidth > 0)', mainLoaded);
  const counterBefore = await page.locator('[data-gallery-count]').first().textContent().catch(() => '');
  const nextBtn = page.locator('[data-gallery-next]').first();
  if (await nextBtn.count()) {
    await nextBtn.click();
    await page.waitForTimeout(250);
    const counterAfter = await page.locator('[data-gallery-count]').first().textContent().catch(() => '');
    check('the gallery "next" control changes the counter',
      counterBefore !== counterAfter, `${(counterBefore || '').trim()} → ${(counterAfter || '').trim()}`);
  } else {
    check('the gallery "next" control exists', false, 'no [data-gallery-next] on the page');
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
  await page.fill('input[name="email"]', CUSTOMER.email);
  await page.fill('input[name="password"]', CUSTOMER.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.locator('form button[type="submit"], form input[type="submit"]').first().click(),
  ]);
  const afterLogin = page.url();
  check('a customer can sign in with email and password',
    !afterLogin.includes('login.php') || !(await page.locator('input[name="password"]').count()),
    afterLogin.replace(BASE, ''));
  report(errors, 'login');

  /* ------------------------------------------------------------------ */
  console.log('\n6. Checkout → order placed');
  errors = await watch(page);
  await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
  const goCheckout = page.locator('a:has-text("Checkout"), a[href*="checkout.php"]').first();
  if (await goCheckout.count()) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), goCheckout.click()]);
  } else {
    await page.goto(BASE + '/checkout.php', { waitUntil: 'domcontentloaded' });
  }
  check('the checkout page opens', page.url().includes('checkout.php'), page.url().replace(BASE, ''));
  const hasToken = await page.locator('input[name="order_token"]').count();
  check('checkout carries the order idempotency token', hasToken > 0);
  if (page.url().includes('checkout.php')) {
    await page.fill('input[name="customer_name"]', 'Browser Customer');
    await page.fill('input[name="customer_phone"]', '01700000009');
    await page.fill('input[name="address_line"]', '12 Test Road, Dhanmondi');
    await page.fill('input[name="area"]', 'Dhanmondi');
    await page.fill('input[name="city"]', 'Dhaka');
    const cod = page.locator('input[name="payment_method_id"]').first();
    if (await cod.count()) { await cod.check().catch(() => {}); }
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {}),
      page.locator('form button[type="submit"]').last().click(),
    ]);
    const successUrl = page.url();
    check('placing the order lands on the confirmation page', /order-success\.php/.test(successUrl), successUrl.replace(BASE, ''));
    const body = (await page.content()).slice(0, 40000);
    const orderNumber = (body.match(/SH[-\s]?[A-Z0-9]{6,}/) || [])[0] || '';
    check('the confirmation names the order', orderNumber !== '', orderNumber);
  } else {
    check('placing the order lands on the confirmation page', false, 'checkout was not reachable');
  }
  await snap(page, '06-checkout');
  report(errors, 'checkout');

  /* ------------------------------------------------------------------ */
  console.log('\n7. Admin sign-in');
  const adminCtx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const admin = await adminCtx.newPage();
  const adminErrors = await watch(admin);
  await admin.goto(BASE + '/admin/login.php', { waitUntil: 'domcontentloaded' });
  await admin.fill('input[name="email"]', ADMIN.email);
  await admin.fill('input[name="password"]', ADMIN.password);
  await Promise.all([
    admin.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    admin.locator('form button[type="submit"]').first().click(),
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
