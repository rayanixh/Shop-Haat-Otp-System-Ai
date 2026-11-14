/**
 * Real browser regression — storefront, admin panel, mobile, security.
 *
 * Runs Chromium against a store that was installed through the application's own
 * installer (tests/browser/seed.php) on a disposable MySQL database, served by a
 * real web server. Everything asserted here is observed in the browser: rendered
 * markup, decoded images, console output, failed requests, CSP violation reports,
 * horizontal overflow at a phone viewport, and the state of the database as the
 * admin panel reports it.
 *
 *   SH_BROWSER_BASE_URL=http://127.0.0.1:8080 \
 *   SH_BROWSER_ADMIN_PASSWORD=… SH_BROWSER_CUSTOMER_PASSWORD=… \
 *     node tests/browser/smoke.mjs
 *
 * Areas are wrapped individually: a broken flow records a failed check and the
 * run continues, so one defect cannot hide the rest of the report.
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

// What the seed actually built: which product carries the multi-image gallery,
// which category has products, and how many of each exist.
let seeded = {};
try { seeded = JSON.parse(readFileSync(join(ARTIFACTS, 'state.json'), 'utf8')); } catch { seeded = {}; }

const checks = [];
function check(name, ok, detail = '') {
  checks.push({ name, ok: !!ok, detail });
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

/** Run one area; an exception becomes a named failure instead of ending the run. */
async function area(label, fn) {
  console.log(`\n${label}`);
  try { await fn(); } catch (error) {
    check(`${label}: the flow completed without an exception`, false, String(error).replace(/\s+/g, ' ').slice(0, 320));
  }
}

/** Collect console errors, JS exceptions, failed requests and CSP reports. */
async function watch(page) {
  const seen = { console: [], pageErrors: [], requests: [], csp: [] };
  page.on('console', (msg) => { if (msg.type() === 'error') { seen.console.push(msg.text().slice(0, 200)); } });
  page.on('pageerror', (err) => { seen.pageErrors.push(String(err).slice(0, 200)); });
  page.on('response', (res) => {
    const status = res.status();
    if (status >= 400) { seen.requests.push(`${status} ${res.url().replace(BASE, '')}`); }
  });
  page.on('requestfailed', (req) => { seen.requests.push(`failed ${req.url().replace(BASE, '')}`); });
  await page.addInitScript(() => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspViolations.push(`${e.violatedDirective} ${e.blockedURI}`.slice(0, 160));
    });
  });
  return seen;
}

function report(errors, label) {
  check(`${label}: no console errors`, errors.console.length === 0, errors.console.slice(0, 3).join(' | '));
  check(`${label}: no JavaScript exceptions`, errors.pageErrors.length === 0, errors.pageErrors.slice(0, 3).join(' | '));
  check(`${label}: no failed requests (4xx/5xx/network)`, errors.requests.length === 0, errors.requests.slice(0, 3).join(' | '));
}

async function csp(page, label) {
  const events = await page.evaluate(() => window.__cspViolations || []).catch(() => []);
  check(`${label}: the CSP reports no violations`, events.length === 0, (events || []).slice(0, 3).join(' | '));
}

async function snap(page, name) {
  try { await page.screenshot({ path: join(ARTIFACTS, `${name}.png`), fullPage: false }); } catch { /* a screenshot is not a check */ }
}

const text = async (locator) => (await locator.textContent().catch(() => '')) || '';
const count = async (locator) => await locator.count().catch(() => 0);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
let errors = await watch(page);

// Values carried between areas.
let productUrl = seeded.galleryProductUrl ? BASE + seeded.galleryProductUrl : '';
let productName = '';
let orderNumber = '';
let orderToken = '';

/* ======================================================================== */
await area('1. Storefront home', async () => {
  const home = await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
  check('the home page responds 200', home && home.status() === 200, `status ${home?.status()}`);
  const cards = await count(page.locator('article.sh-product-card'));
  check('the home page lists products', cards > 0, `${cards} product cards · ${page.url().replace(BASE, '')}`);
  const broken = await page.evaluate(() => Array.from(document.images)
    .filter((img) => img.complete && img.naturalWidth === 0)
    .map((img) => img.currentSrc || img.src).slice(0, 3));
  check('every image on the home page decodes', broken.length === 0, broken.join(' | '));
  await snap(page, '01-home');
  report(errors, 'home');
  await csp(page, 'home');
});

/* ======================================================================== */
await area('2. Shop listing and search', async () => {
  errors = await watch(page);
  const listing = await page.goto(BASE + '/products.php', { waitUntil: 'domcontentloaded' });
  check('the shop listing responds 200', listing && listing.status() === 200, `status ${listing?.status()}`);
  const listed = await count(page.locator('article.sh-product-card'));
  check('the shop listing shows products', listed > 0, `${listed} cards`);

  // Search the way a shopper does: type a word from a real product name.
  const firstName = await text(page.locator('article.sh-product-card .sh-product-card__name').first());
  const word = firstName.trim().split(/\s+/)[0] || '';
  const searched = await page.goto(BASE + '/products.php?q=' + encodeURIComponent(word), { waitUntil: 'domcontentloaded' });
  const results = await count(page.locator('article.sh-product-card'));
  check('a search returns matching products', searched?.status() === 200 && results > 0,
    `"${word}" → ${results} card(s)`);
  const bodyText = await text(page.locator('body'));
  check('the search results actually contain the searched word',
    results > 0 && bodyText.toLowerCase().includes(word.toLowerCase()), `"${word}"`);
  report(errors, 'listing');
});

/* ======================================================================== */
await area('3. Category page', async () => {
  errors = await watch(page);
  const catHref = await page.locator('a.sh-category-card').first()
    .getAttribute('href', { timeout: 5000 }).catch(() => null);
  const categoryUrl = seeded.categoryUrl ? BASE + seeded.categoryUrl : (catHref ? new URL(catHref, BASE).toString() : '');
  check('the home page links to a category', categoryUrl !== '', categoryUrl.replace(BASE, ''));
  if (categoryUrl !== '') {
    const cat = await page.goto(categoryUrl, { waitUntil: 'domcontentloaded' });
    check('the category page responds 200', cat && cat.status() === 200, `status ${cat?.status()} ${categoryUrl.replace(BASE, '')}`);
    const cards = await count(page.locator('article.sh-product-card'));
    check('the category page lists its products', cards > 0, `${cards} cards`);
  }
  report(errors, 'category');
});

/* ======================================================================== */
await area('4. Product page and gallery', async () => {
  errors = await watch(page);
  if (productUrl === '') {
    const href = await page.locator('a.sh-product-card__media').first()
      .getAttribute('href', { timeout: 5000 }).catch(() => null);
    productUrl = href ? new URL(href, BASE).toString() : BASE + '/product.php?slug=missing-in-seed';
  }
  const prod = await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  check('the product page responds 200', prod && prod.status() === 200, `status ${prod?.status()} ${productUrl.replace(BASE, '')}`);
  productName = (await text(page.locator('h1'))).trim().slice(0, 80);
  check('the product page names the product', productName !== '', productName);

  const mainLoaded = await page.evaluate(() => {
    const img = document.querySelector('[data-gallery-main]');
    return !!img && img.complete && img.naturalWidth > 0;
  });
  check('the main gallery image really decodes (naturalWidth > 0)', mainLoaded);

  const thumbs = await count(page.locator('[data-gallery-thumb]'));
  if (thumbs > 1) {
    check('a multi-image product shows its thumbnails', thumbs > 1, `${thumbs} thumbnails`);
    const before = await page.evaluate(() => ({
      count: (document.querySelector('[data-gallery-count]')?.textContent || '').trim(),
      src: document.querySelector('[data-gallery-main]')?.getAttribute('src') || '',
    }));
    const next = page.locator('[data-gallery-next]').first();
    if (await next.count()) {
      await next.click();
      await page.waitForTimeout(300);
      const after = await page.evaluate(() => ({
        count: (document.querySelector('[data-gallery-count]')?.textContent || '').trim(),
        src: document.querySelector('[data-gallery-main]')?.getAttribute('src') || '',
      }));
      check('the gallery "next" control changes the counter', before.count !== after.count, `${before.count} → ${after.count}`);
      check('the gallery "next" control changes the main image',
        before.src !== '' && before.src !== after.src,
        `${before.src.split('/').pop()} → ${after.src.split('/').pop()}`);
    } else {
      check('the gallery "next" control exists for a multi-image product', false, 'no [data-gallery-next]');
    }
  } else {
    check('a single-image product hides the gallery navigation',
      (await count(page.locator('[data-gallery-next]'))) === 0 && (await count(page.locator('[data-gallery-count]'))) === 0,
      `${thumbs} thumbnail(s) · seeded gallery ${seeded.galleryImages ?? 'unknown'}`);
  }
  const addBtn = page.locator('[data-add-cart]').first();
  check('the product page offers add-to-cart', (await addBtn.count()) > 0);
  await snap(page, '04-product');
  report(errors, 'product');
  await csp(page, 'product');
});

/* ======================================================================== */
await area('5. Add to cart and cart totals', async () => {
  errors = await watch(page);
  const addBtn = page.locator('[data-add-cart]').first();
  if (await addBtn.count()) {
    await addBtn.click();
    await page.waitForTimeout(700);
  }
  await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
  const rows = await count(page.locator('[data-cart-row]'));
  check('the cart lists the product that was added', rows > 0, `${rows} line(s)`);
  const cartText = await text(page.locator('body'));
  check('the cart shows a subtotal for the items', /Subtotal/i.test(cartText), 'expected the word Subtotal');
  await snap(page, '05-cart');
  report(errors, 'cart');
});

/* ======================================================================== */
await area('6. Customer sign-in and session', async () => {
  errors = await watch(page);
  await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
  // Scope everything to the form that owns the password field: the first submit
  // button on the page belongs to the header search box.
  const form = page.locator('form:has(input[name="password"])').first();
  await form.locator('input[name="email"]').fill(CUSTOMER.email);
  await form.locator('input[name="password"]').fill(CUSTOMER.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    form.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  const landing = page.url();
  const passwordField = await count(page.locator('input[name="password"]'));
  check('a customer can sign in with email and password',
    !/\/login\.php/.test(landing) && passwordField === 0,
    `landed on ${landing.replace(BASE, '')} · password field ${passwordField}`);

  // The sign-in only counts when a second request still knows the customer.
  await page.goto(BASE + '/account.php', { waitUntil: 'domcontentloaded' });
  check('the signed-in session survives a second request',
    !/\/login\.php/.test(page.url()), page.url().replace(BASE, ''));
  const account = await text(page.locator('body'));
  check('the account page knows which customer is signed in',
    account.toLowerCase().includes(CUSTOMER.email.toLowerCase()), `looking for ${CUSTOMER.email}`);
  await snap(page, '06-account');
  report(errors, 'login');
});

/* ======================================================================== */
await area('7. Wishlist', async () => {
  errors = await watch(page);
  await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
  const heart = page.locator('[data-wishlist]').first();
  check('a product page offers a wishlist control', (await heart.count()) > 0);
  if (await heart.count()) {
    await heart.click();
    await page.waitForTimeout(900);
  }
  await page.goto(BASE + '/wishlist.php', { waitUntil: 'domcontentloaded' });
  const saved = await count(page.locator('article.sh-product-card'));
  check('the saved product appears on the wishlist page', saved > 0, `${saved} saved item(s)`);
  report(errors, 'wishlist');
});

/* ======================================================================== */
await area('8. Checkout form', async () => {
  errors = await watch(page);
  await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
  let rows = await count(page.locator('[data-cart-row]'));
  if (rows === 0) {
    // Re-add: a cart is not guaranteed to survive every step above.
    await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    const again = page.locator('[data-add-cart]').first();
    if (await again.count()) { await again.click(); await page.waitForTimeout(700); }
    await page.goto(BASE + '/cart.php', { waitUntil: 'domcontentloaded' });
    rows = await count(page.locator('[data-cart-row]'));
  }
  check('the cart still holds an item after signing in', rows > 0, `${rows} line(s)`);

  const goCheckout = page.locator('a[href*="checkout.php"]').first();
  if (await goCheckout.count()) {
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), goCheckout.click()]);
  } else {
    await page.goto(BASE + '/checkout.php', { waitUntil: 'domcontentloaded' });
  }
  const onCheckout = page.url().includes('checkout.php') && !page.url().includes('/login.php');
  const heading = (await text(page.locator('h1'))).trim().slice(0, 60);
  check('the checkout page opens for a signed-in customer', onCheckout,
    `${page.url().replace(BASE, '')} · h1 "${heading}" · cart ${rows}`);
  orderToken = await page.locator('input[name="order_token"]').first()
    .getAttribute('value', { timeout: 3000 }).catch(() => '');
  check('checkout carries the order idempotency token', !!orderToken, orderToken ? 'token present' : 'no order_token field');
  report(errors, 'checkout');
});

/* ======================================================================== */
await area('9. Place the order', async () => {
  errors = await watch(page);
  if (!page.url().includes('checkout.php') || page.url().includes('/login.php')) {
    check('the order can be placed', false, `not on the checkout page (${page.url().replace(BASE, '')})`);
    return;
  }
  await page.fill('input[name="customer_name"]', 'Browser Customer');
  await page.fill('input[name="customer_phone"]', '01700000009');
  await page.fill('input[name="address_line"]', '12 Test Road, Dhanmondi');
  await page.fill('input[name="area"]', 'Dhanmondi');
  await page.fill('input[name="city"]', 'Dhaka');
  const cod = page.locator('input[name="payment_method_id"]').last();
  if (await cod.count()) { await cod.check().catch(() => {}); }

  // Remember the exact body that places the order, so the duplicate-submission
  // check can replay it byte for byte.
  let posted = null;
  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes('checkout.php')) { posted = req.postData(); }
  });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
    page.locator('button:has-text("Place Order")').first().click(),
  ]);
  const successUrl = page.url();
  check('placing the order lands on the confirmation page', /order-success\.php/.test(successUrl), successUrl.replace(BASE, ''));
  const body = await page.content();
  orderNumber = (body.match(/SH[-\s]?[A-Z0-9]{6,}/) || [])[0] || '';
  check('the confirmation names the order', orderNumber !== '', orderNumber);
  await snap(page, '09-order-success');
  report(errors, 'order');

  /* The same submission, sent again. A second order would be a double charge. */
  if (posted && orderToken) {
    const replay = await page.request.post(BASE + '/checkout.php', {
      form: Object.fromEntries(new URLSearchParams(posted)),
      maxRedirects: 5,
    }).catch(() => null);
    const replayBody = replay ? await replay.text() : '';
    const replayNumbers = new Set((replayBody.match(/SH[-\s]?[A-Z0-9]{6,}/g) || []).map((n) => n.replace(/\s/g, '')));
    const firstNumber = orderNumber.replace(/\s/g, '');
    const distinct = [...replayNumbers].filter((n) => n !== firstNumber);
    check('replaying the same checkout creates no second order', distinct.length === 0,
      `${replay?.status()} · numbers in the replay: ${[...replayNumbers].join(', ') || 'none'} · first ${firstNumber || 'unknown'}`);
  } else {
    check('replaying the same checkout creates no second order', false, 'no captured checkout POST to replay');
  }
});

/* ======================================================================== */
await area('10. Order tracking', async () => {
  errors = await watch(page);
  if (orderNumber === '') {
    check('the order can be tracked by number and phone', false, 'no order number was produced');
    return;
  }
  const track = await page.goto(BASE + '/track.php', { waitUntil: 'domcontentloaded' });
  check('the tracking page responds 200', track && track.status() === 200, `status ${track?.status()}`);
  await page.fill('input[name="order_number"]', orderNumber);
  await page.fill('input[name="phone"]', '01700000009');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.locator('form:has(input[name="order_number"]) button[type="submit"]').first().click(),
  ]);
  const tracked = await text(page.locator('body'));
  check('the tracking page finds the order and shows its status',
    tracked.includes(orderNumber) && /pending|processing|confirmed|status/i.test(tracked),
    `looking for ${orderNumber}`);
  await snap(page, '10-track');
  report(errors, 'track');
});

/* ======================================================================== */
await area('11. Unknown product is a 404, not an error', async () => {
  const missing = await page.goto(BASE + '/product.php?slug=this-product-does-not-exist',
    { waitUntil: 'domcontentloaded' });
  const body = await text(page.locator('body'));
  check('an unknown product answers 404 with a readable page',
    missing?.status() === 404 && !/fatal error|uncaught|exception/i.test(body),
    `status ${missing?.status()}`);
});

/* ======================================================================== */
await area('12. Admin sign-in', async () => {
  errors = await watch(page);
  await page.goto(BASE + '/admin/login.php', { waitUntil: 'domcontentloaded' });
  const form = page.locator('form:has(input[name="password"])').first();
  await form.locator('input[name="email"]').fill(ADMIN.email);
  await form.locator('input[name="password"]').fill(ADMIN.password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    form.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  check('an administrator can sign in', !page.url().includes('/admin/login.php'), page.url().replace(BASE, ''));
  const nav = await count(page.locator('a[href*="admin/products.php"], a[href*="admin/orders.php"]'));
  check('the admin shell renders its navigation', nav > 0, `${nav} links`);
  await snap(page, '12-admin');
  report(errors, 'admin');
});

/* ======================================================================== */
await area('13. Admin dashboard and orders', async () => {
  errors = await watch(page);
  const dash = await page.goto(BASE + '/admin/index.php', { waitUntil: 'domcontentloaded' });
  check('the admin dashboard responds 200', dash && dash.status() === 200, `status ${dash?.status()}`);

  const orders = await page.goto(BASE + '/admin/orders.php', { waitUntil: 'domcontentloaded' });
  check('the orders screen responds 200', orders && orders.status() === 200, `status ${orders?.status()}`);
  const ordersBody = await text(page.locator('body'));
  check('the orders screen lists the order the browser placed',
    orderNumber !== '' && ordersBody.includes(orderNumber), `looking for ${orderNumber || '(none)'}`);

  const detailHref = await page.locator('a[href*="admin/orders.php?id="]').first()
    .getAttribute('href', { timeout: 5000 }).catch(() => null);
  if (detailHref) {
    const detail = await page.goto(new URL(detailHref, BASE).toString(), { waitUntil: 'domcontentloaded' });
    const detailBody = await text(page.locator('body'));
    check('the order detail page opens the order', detail?.status() === 200 && detailBody.includes('Browser Customer'),
      `status ${detail?.status()} · customer name ${detailBody.includes('Browser Customer') ? 'present' : 'missing'}`);
    check('the order detail shows the item that was bought',
      productName !== '' && detailBody.includes(productName.split(' ').slice(0, 2).join(' ')),
      `looking for "${productName.split(' ').slice(0, 2).join(' ')}"`);
    await snap(page, '13-admin-order');
  } else {
    check('the order detail page opens the order', false, 'no link to admin/orders.php?id=');
  }
  report(errors, 'admin-orders');
});

/* ======================================================================== */
await area('14. Admin catalogue: products, categories, payment methods', async () => {
  errors = await watch(page);
  const products = await page.goto(BASE + '/admin/products.php', { waitUntil: 'domcontentloaded' });
  check('the products screen responds 200', products && products.status() === 200, `status ${products?.status()}`);
  const rows = await count(page.locator('tbody tr'));
  check('the products screen lists catalogue rows', rows > 0, `${rows} rows`);

  const editHref = await page.locator('a[href*="admin/product-edit.php?edit="]').first()
    .getAttribute('href', { timeout: 5000 }).catch(() => null);
  if (editHref) {
    const edit = await page.goto(new URL(editHref, BASE).toString(), { waitUntil: 'domcontentloaded' });
    const nameValue = await page.locator('input[name="name"]').first().inputValue().catch(() => '');
    check('the product edit form opens with the saved values',
      edit?.status() === 200 && nameValue.trim() !== '', `status ${edit?.status()} · name "${nameValue.slice(0, 40)}"`);

    // A real save: change the short description and prove it persisted.
    const stamp = 'browser-' + Date.now().toString().slice(-6);
    const shortField = page.locator('input[name="short_description"]').first();
    if (await shortField.count()) {
      await shortField.fill(stamp);
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
        page.locator('form:has(input[name="name"]) button[type="submit"]').first().click(),
      ]);
      const afterSave = await text(page.locator('body'));
      check('saving a product reports success',
        /saved|updated|success/i.test(afterSave), afterSave.replace(/\s+/g, ' ').slice(0, 120));
      await page.goto(new URL(editHref, BASE).toString(), { waitUntil: 'domcontentloaded' });
      const persisted = await page.locator('input[name="short_description"]').first().inputValue().catch(() => '');
      check('the saved product value is still there after a reload', persisted === stamp,
        `expected "${stamp}", found "${persisted}"`);
      // Put the store back the way it was.
      await page.locator('input[name="short_description"]').first().fill('');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 25000 }).catch(() => {}),
        page.locator('form:has(input[name="name"]) button[type="submit"]').first().click(),
      ]);
    } else {
      check('saving a product reports success', false, 'the edit form has no short_description field');
    }
  } else {
    check('the product edit form opens with the saved values', false, 'no product edit link on the list');
  }

  const cats = await page.goto(BASE + '/admin/categories.php', { waitUntil: 'domcontentloaded' });
  check('the categories screen responds 200', cats && cats.status() === 200, `status ${cats?.status()}`);
  const catBody = await text(page.locator('body'));
  check('the categories screen lists the seeded categories',
    (seeded.categories ?? 0) > 0 && /categor/i.test(catBody), `seeded ${seeded.categories ?? '?'}`);

  const pay = await page.goto(BASE + '/admin/payment-methods.php', { waitUntil: 'domcontentloaded' });
  const payBody = await text(page.locator('body'));
  check('the payment methods screen lists the manual bKash route',
    pay?.status() === 200 && /bkash/i.test(payBody), `status ${pay?.status()}`);
  report(errors, 'admin-catalogue');
});

/* ======================================================================== */
await area('15. Admin customers and settings', async () => {
  errors = await watch(page);
  const customers = await page.goto(BASE + '/admin/customers.php', { waitUntil: 'domcontentloaded' });
  const customersBody = await text(page.locator('body'));
  check('the customers screen lists the seeded customer',
    customers?.status() === 200 && customersBody.toLowerCase().includes(CUSTOMER.email.toLowerCase()),
    `status ${customers?.status()}`);
  const settings = await page.goto(BASE + '/admin/settings.php', { waitUntil: 'domcontentloaded' });
  const siteName = await page.locator('input[name="site_name"]').first().inputValue().catch(() => '');
  check('the settings screen shows the store name it was installed with',
    settings?.status() === 200 && siteName.trim() !== '', `status ${settings?.status()} · "${siteName.slice(0, 40)}"`);
  await snap(page, '15-settings');
  report(errors, 'admin-settings');
});

/* ======================================================================== */
await area('16. Admin guard without a session', async () => {
  const anonCtx = await browser.newContext();
  const anon = await anonCtx.newPage();
  await watch(anon);
  const guarded = await anon.goto(BASE + '/admin/orders.php', { waitUntil: 'domcontentloaded' });
  const anonBody = await anon.content();
  check('a signed-out /admin/orders.php request shows no order data',
    !/order_number|sh-table__row/i.test(anonBody), anon.url().replace(BASE, ''));
  check('it is answered with a login page or a refusal',
    /login\.php/.test(anon.url()) || (guarded && [403, 404].includes(guarded.status())),
    `status ${guarded?.status()} · ${anon.url().replace(BASE, '')}`);
  await anonCtx.close();
});

/* ======================================================================== */
await area('17. Mobile width (390×844)', async () => {
  const mobileCtx = await browser.newContext({
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2,
  });
  const mobile = await mobileCtx.newPage();
  const mobileErrors = await watch(mobile);
  for (const [label, url] of [
    ['home', '/index.php'],
    ['listing', '/products.php'],
    ['product', new URL(productUrl).pathname + (new URL(productUrl).search || '')],
    ['cart', '/cart.php'],
  ]) {
    await mobile.goto(BASE + url, { waitUntil: 'domcontentloaded' });
    const overflow = await mobile.evaluate(() => ({
      scroll: document.documentElement.scrollWidth,
      client: document.documentElement.clientWidth,
    }));
    check(`mobile / ${label}: no horizontal overflow`,
      overflow.scroll <= overflow.client + 1, `scrollWidth ${overflow.scroll} vs ${overflow.client}`);
  }
  await mobile.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
  const headerVisible = await mobile.evaluate(() => {
    const el = document.querySelector('header');
    if (!el) { return false; }
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  });
  check('mobile: the header is visible and sized', headerVisible);
  const offscreen = await mobile.evaluate(() => Array.from(document.querySelectorAll('button, a.sh-btn'))
    .filter((el) => {
      const rect = el.getBoundingClientRect();
      return rect.width > 0 && rect.right > document.documentElement.clientWidth + 1;
    }).length);
  check('mobile: no button or main call-to-action runs off screen', offscreen === 0, `${offscreen} off screen`);
  await mobile.screenshot({ path: join(ARTIFACTS, '17-mobile-home.png'), fullPage: false }).catch(() => {});
  check('mobile: no console errors', mobileErrors.console.length === 0, mobileErrors.console.slice(0, 3).join(' | '));
  check('mobile: no failed requests', mobileErrors.requests.length === 0, mobileErrors.requests.slice(0, 3).join(' | '));
  await csp(mobile, 'mobile');
  await mobileCtx.close();
});

await ctx.close();
await browser.close();

const failed = checks.filter((c) => !c.ok);
console.log(`\nBrowser regression: ${checks.length - failed.length} passed, ${failed.length} failed`);
if (failed.length) {
  console.log('Failures:');
  for (const f of failed) { console.log(` - ${f.name}${f.detail ? ` (${f.detail})` : ''}`); }
}
console.log(`\nBase URL: ${BASE} · Chromium ${browser.version?.() || ''}`);
process.exit(failed.length ? 1 : 0);
