/**
 * SHOP HAAT — rendered / PHP-template / JS behaviour suite.
 *
 * The 16 acceptance cases are enumerated in .github/workflows/reporting-integration.yml.
 * This suite asks tests/product-management-render.php to render the REAL
 * product.php, cart.php and admin/product-edit.php templates, then drives the
 * shipped CSS and scripts inside jsdom. It is fixture evidence, not a real
 * browser/device or live MySQL/back-end submission test.
 *
 *   SH_TEST_PHP_BIN=/path/to/php SH_JSDOM_PATH=/path/to/jsdom \
 *     node tests/product-management-behavior.mjs
 */
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const require_ = createRequire(import.meta.url);
let jsdom;
try {
  jsdom = process.env.SH_JSDOM_PATH ? require_(process.env.SH_JSDOM_PATH) : require_('jsdom');
} catch (error) {
  if (process.env.SH_PM_REQUIRE_JSDOM === '1') {
    console.error('FAIL  jsdom is required for the product-management rendered suite: ' + error.message);
    process.exit(1);
  }
  console.log('SKIP  jsdom is not installed; set SH_JSDOM_PATH to run this test.');
  process.exit(0);
}
const { JSDOM, VirtualConsole } = jsdom;

const phpBin = process.env.SH_TEST_PHP_BIN || 'php';
const fixtures = JSON.parse(execFileSync(phpBin, [join(ROOT, 'tests/product-management-render.php')], {
  encoding: 'utf8',
  maxBuffer: 32 * 1024 * 1024,
}));

const appJs = readFileSync(join(ROOT, 'assets/js/app.js'), 'utf8');
const productFormJs = readFileSync(join(ROOT, 'assets/js/product-form.js'), 'utf8');
const seoJs = readFileSync(join(ROOT, 'assets/js/seo.js'), 'utf8');
const css = readFileSync(join(ROOT, 'assets/css/app.css'), 'utf8');
const src = (path) => readFileSync(join(ROOT, path), 'utf8');

const caseLabels = {
  P01: 'Additive migration and legacy product/URL compatibility',
  P02: 'Fresh-install schema and dictionary starters',
  P03: 'Create 1–5 images with primary snapshot',
  P04: 'Reject zero and more than five images',
  P05: 'Secure 3 MB / MIME / dimension validation',
  P06: 'Replace, remove, reorder, add and previews',
  P07: 'Brand/type/colour management UI and guards',
  P08: 'Structured common and custom colours',
  P09: 'Colour selection through PDP JS and cart API',
  P10: 'Cart update/remove by colour variant',
  P11: 'Guest-cart merge preserves variants',
  P12: 'Checkout/order displays keep the colour',
  P13: 'Notifications keep the colour snapshot',
  P14: 'All-or-nothing save and deferred cleanup',
  P15: 'AI/SEO/card/legacy compatibility and conditional tabs',
  P16: 'Manual gallery, no timers/cropping, SEO preview wiring',
};
const caseIds = Object.keys(caseLabels);
let checks = 0;
const failures = [];
const seenCases = new Set();

function caseStart(id) {
  assert.ok(caseLabels[id], 'known case ' + id);
  seenCases.add(id);
  console.log(`CASE ${id}: ${caseLabels[id]}`);
}
async function check(id, name, fn) {
  seenCases.add(id);
  try {
    await fn();
    checks += 1;
    console.log(`  ok  ${name}`);
  } catch (error) {
    failures.push(`[${id}] ${name}: ${error.message}`);
    console.error(`FAIL  [${id}] ${name}: ${error.message}`);
  }
}

function makeDom(fragment, { admin = false } = {}) {
  const html = `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>${fragment}</body></html>`;
  const errors = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', (e) => errors.push(e.message || String(e)));
  const dom = new JSDOM(html, {
    url: 'https://shop.example.test/admin/product-edit.php',
    runScripts: 'outside-only',
    pretendToBeVisual: true,
    virtualConsole,
  });
  const w = dom.window;
  const d = w.document;
  Object.defineProperty(d, 'readyState', { configurable: true, get: () => 'loading' });
  w.SH_BASE = '/';
  w.SH_CSRF = 'test-token';
  w.confirm = () => true;
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
  if (!w.MutationObserver) { w.MutationObserver = class { observe() {} disconnect() {} }; }
  w.requestAnimationFrame = (fn) => { fn(0); return 1; };
  w.cancelAnimationFrame = () => {};
  w.scrollTo = () => {};
  if (w.HTMLElement && w.HTMLElement.prototype) { w.HTMLElement.prototype.scrollIntoView = () => {}; }
  try { Object.defineProperty(w.location, 'reload', { configurable: true, value: () => {} }); } catch { /* jsdom variant */ }
  const fetchCalls = [];
  w.fetch = (url, init) => {
    fetchCalls.push({ url, body: init && init.body });
    return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(JSON.stringify({ success: true, count: 1 })) });
  };
  let blobNo = 0;
  w.URL.createObjectURL = (file) => `blob:${(file && file.name) || 'picked'}-${++blobNo}`;
  w.URL.revokeObjectURL = () => {};
  delete w.DataTransfer;
  const timers = { intervals: 0, timeouts: 0 };
  w.setInterval = () => { timers.intervals += 1; return 0; };
  w.clearInterval = () => {};
  w.setTimeout = () => { timers.timeouts += 1; return 0; };
  w.clearTimeout = () => {};
  try {
    w.eval(appJs);
    if (admin) { w.eval(productFormJs); w.eval(seoJs); }
  } catch (e) {
    errors.push('script eval: ' + e.message);
  }
  d.dispatchEvent(new w.Event('DOMContentLoaded', { bubbles: true }));
  timers.intervals = 0;
  timers.timeouts = 0;
  return { dom, window: w, document: d, fetchCalls, timers, errors };
}

function fakeFile(w, name, type = 'image/png', size = 1024) {
  const file = new w.File(['fixture'], name, { type });
  try { Object.defineProperty(file, 'size', { configurable: true, value: size }); } catch { /* jsdom sets length */ }
  return file;
}
function pickFiles(w, picker, files) {
  Object.defineProperty(picker, 'files', { configurable: true, value: files });
  picker.dispatchEvent(new w.Event('change', { bubbles: true }));
}
function activeTiles(d) {
  return [...d.querySelectorAll('[data-pg-tile]')].filter((tile) => !tile.classList.contains('is-removed'));
}
function nextTick() { return new Promise((resolve) => setImmediate(resolve)); }

try {
  // P01 -------------------------------------------------------------------
  caseStart('P01');
  await check('P01', 'rendered PDP keeps the slug URL contract and legacy primary image', () => {
    assert.match(src('product.php'), /\$pageCanonical\s*=\s*'product\.php\?slug='/);
    assert.match(fixtures.pdp, /\/uploads\/products\/image-1\.png/);
    assert.match(fixtures.pdp, /data-gallery/);
  });
  await check('P01', 'legacy page does not use the retired one-image field', () => {
    assert.doesNotMatch(fixtures.pdp, /name="current_image"/);
    assert.doesNotMatch(fixtures.adminEdit, /name="current_image"/);
  });

  // P02 -------------------------------------------------------------------
  caseStart('P02');
  await check('P02', 'fresh-install DDL and seeds are present', () => {
    const schema = src('install/schema.php');
    assert.match(schema, /CREATE TABLE IF NOT EXISTS product_types/);
    assert.match(schema, /CREATE TABLE IF NOT EXISTS product_colours/);
    assert.match(schema, /CREATE TABLE IF NOT EXISTS product_colour_selections/);
    assert.match(schema, /uq_cart_product_variant \(cart_id, product_id, variant\)/);
    assert.match(schema, /INSERT IGNORE INTO product_types/);
    assert.match(schema, /INSERT IGNORE INTO product_colours/);
  });
  await check('P02', 'fixture enumerates the same 16 cases', () => {
    assert.deepEqual(fixtures.cases, caseIds);
  });

  // P03 -------------------------------------------------------------------
  caseStart('P03');
  await check('P03', 'admin editor renders the 1–5 image contract and ordered tokens', () => {
    assert.match(fixtures.adminNew, /Upload 1–5 real photos/);
    assert.match(fixtures.adminNew, /name="images\[\]"/);
    assert.equal((fixtures.adminEdit.match(/name="image_row\[\]"/g) || []).length, 3);
    assert.match(fixtures.adminEdit, /data-pg-token="e:1"/);
    assert.match(fixtures.adminEdit, /is-primary/);
  });

  // P04 -------------------------------------------------------------------
  caseStart('P04');
  await check('P04', 'empty new-product submit is blocked with a real message', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const form = page.document.querySelector('[data-product-form]');
    const event = new page.window.Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    assert.equal(page.document.querySelector('[data-pg-error]').hidden, false);
    assert.match(page.document.querySelector('[data-pg-error]').textContent, /at least one/i);
  });
  await check('P04', 'sixth image is refused and no sixth tile is created', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const gallery = page.document.querySelector('[data-product-gallery-editor]');
    for (let i = 1; i <= 5; i++) {
      const picker = gallery.querySelector('input[data-pg-input]');
      assert.ok(picker, 'a fresh picker is available for image ' + i);
      pickFiles(page.window, picker, [fakeFile(page.window, `ok-${i}.png`)]);
    }
    assert.equal(activeTiles(page.document).length, 5);
    const picker = gallery.querySelector('input[data-pg-input]');
    pickFiles(page.window, picker, [fakeFile(page.window, 'sixth.png')]);
    assert.equal(activeTiles(page.document).length, 5);
    assert.match(page.document.querySelector('[data-pg-error]').textContent, /at most 5/i);
  });

  // P05 -------------------------------------------------------------------
  caseStart('P05');
  await check('P05', 'client rejects unsupported MIME and over-3-MB files', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const gallery = page.document.querySelector('[data-product-gallery-editor]');
    const picker = gallery.querySelector('input[data-pg-input]');
    pickFiles(page.window, picker, [
      fakeFile(page.window, 'bad.svg', 'image/svg+xml', 500),
      fakeFile(page.window, 'huge.png', 'image/png', 3145729),
    ]);
    assert.equal(activeTiles(page.document).length, 0);
    assert.match(page.document.querySelector('[data-pg-error]').textContent, /2 file\(s\) were not added/i);
    assert.match(page.document.querySelector('[data-pg-error]').textContent, /3 MB/i);
  });
  await check('P05', 'production validator checks bytes, real MIME and dimensions', () => {
    const functions = src('includes/functions.php');
    assert.match(functions, /\$file\['size'\] > \$maxBytes/);
    assert.match(functions, /getimagesize\(\$file\['tmp_name'\]\)/);
    assert.match(functions, /\$info\[0\] > \$maxDim \|\| \$info\[1\] > \$maxDim/);
    assert.match(src('includes/product-catalog.php'), /SH_PRODUCT_MAX_IMAGE_BYTES/);
  });

  // P06 -------------------------------------------------------------------
  caseStart('P06');
  await check('P06', 'new upload creates a preview, fallback input and n:<i> token', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const picker = page.document.querySelector('input[data-pg-input]');
    pickFiles(page.window, picker, [fakeFile(page.window, 'front.png')]);
    const tile = page.document.querySelector('[data-pg-tile="new"]');
    assert.ok(tile);
    assert.match(tile.querySelector('img').getAttribute('src'), /^blob:/);
    assert.ok(tile.querySelector('input[name="images[]"]'));
    assert.equal(tile.querySelector('input[name="image_row[]"]').value, 'n:0');
    assert.match(page.document.querySelector('[data-pg-count]').textContent, /1 \/ 5/);
  });
  await check('P06', 'new tiles reorder through the active list and stay numbered', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const gallery = page.document.querySelector('[data-product-gallery-editor]');
    for (let i = 1; i <= 3; i++) {
      pickFiles(page.window, gallery.querySelector('input[data-pg-input]'), [fakeFile(page.window, `r-${i}.png`)]);
    }
    const first = activeTiles(page.document)[0];
    first.querySelectorAll('.sh-pg__btn')[1].click();
    const tiles = activeTiles(page.document);
    assert.notEqual(tiles[0], first);
    assert.deepEqual(tiles.map((t) => t.querySelector('input[name="image_row[]"]').value), ['n:0', 'n:1', 'n:2']);
    assert.ok(tiles[0].classList.contains('is-primary'));
  });
  await check('P06', 'existing image remove, undo, reorder and replacement preview work', () => {
    const removePage = makeDom(fixtures.adminEdit, { admin: true });
    const first = removePage.document.querySelector('[data-pg-tile="existing"]');
    const removeBtn = first.querySelector('[data-pg-remove-btn]');
    removeBtn.click();
    assert.ok(first.classList.contains('is-removed'));
    assert.equal(first.querySelector('[data-pg-remove]').checked, true);
    assert.equal(first.querySelector('[data-pg-replace]').disabled, true);
    assert.match(removePage.document.querySelector('[data-pg-count]').textContent, /2 \/ 5/);
    removeBtn.click();
    assert.ok(!first.classList.contains('is-removed'));
    assert.match(removePage.document.querySelector('[data-pg-count]').textContent, /3 \/ 5/);

    const orderPage = makeDom(fixtures.adminEdit, { admin: true });
    const tiles = [...orderPage.document.querySelectorAll('[data-pg-tile="existing"]')];
    const second = tiles[1];
    second.querySelector('[data-pg-move="-1"]').click();
    const reordered = [...orderPage.document.querySelectorAll('[data-pg-tile="existing"]')];
    assert.equal(reordered[0], second);

    const replacePage = makeDom(fixtures.adminEdit, { admin: true });
    const primary = replacePage.document.querySelector('[data-pg-tile="existing"]');
    const picked = [];
    replacePage.document.addEventListener('sh-product-image-picked', (ev) => picked.push(ev.detail && ev.detail.file));
    const replace = primary.querySelector('[data-pg-replace]');
    const file = fakeFile(replacePage.window, 'replacement.png');
    Object.defineProperty(replace, 'files', { configurable: true, value: [file] });
    replace.dispatchEvent(new replacePage.window.Event('change', { bubbles: true }));
    assert.match(primary.querySelector('.sh-pg__preview img').getAttribute('src'), /^blob:/);
    assert.ok(picked.some((f) => f === file), 'primary replacement announces its picked file');
  });

  // P07 -------------------------------------------------------------------
  caseStart('P07');
  await check('P07', 'rendered editor exposes brand/type selects, quick-add and manager links', () => {
    assert.match(fixtures.adminNew, /name="brand_id"/);
    assert.match(fixtures.adminNew, /name="catalog_type_id"/);
    assert.match(fixtures.adminNew, /name="new_brand"/);
    assert.match(fixtures.adminNew, /name="new_catalog_type"/);
    assert.match(fixtures.adminNew, /admin\/product-attributes\.php#brands/);
    assert.match(fixtures.adminNew, /admin\/product-attributes\.php#types/);
    assert.match(fixtures.adminNew, /admin\/product-attributes\.php#colours/);
  });
  await check('P07', 'attribute manager has CSRF, permission, hide and usage-protected delete', () => {
    const attributes = src('admin/product-attributes.php');
    assert.match(attributes, /sh_csrf_require\(\)/);
    assert.match(attributes, /sh_require_perm\('catalog\.manage'\)/);
    assert.match(attributes, /function sh_attributes_toggle/);
    assert.match(attributes, /function sh_attributes_usage_count/);
    assert.match(attributes, /Hide it instead/);
    assert.match(src('admin/_layout.php'), /product-attributes\.php/);
  });

  // P08 -------------------------------------------------------------------
  caseStart('P08');
  await check('P08', 'PDP renders structured colours and a selected-colour label', () => {
    const page = makeDom(fixtures.pdp);
    const radios = page.document.querySelectorAll('[data-product-colour]');
    assert.equal(radios.length, 3);
    assert.equal(radios[0].checked, true);
    assert.equal(page.document.querySelector('[data-product-colour-label]').textContent, 'Black');
  });
  await check('P08', 'custom-colour add/remove and common swatch summary work', () => {
    const page = makeDom(fixtures.adminNew, { admin: true });
    const editor = page.document.querySelector('[data-product-colour-editor]');
    const before = editor.querySelectorAll('[data-pc-row]').length;
    editor.querySelector('[data-pc-add]').click();
    const rows = editor.querySelectorAll('[data-pc-row]');
    assert.equal(rows.length, before + 1);
    const name = rows[rows.length - 1].querySelector('[data-pc-name]');
    name.value = 'Ocean Blue';
    name.dispatchEvent(new page.window.Event('input', { bubbles: true }));
    const chip = editor.querySelector('[data-colour-option]');
    chip.checked = true;
    chip.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    assert.match(editor.querySelector('[data-pc-summary]').textContent, /Ocean Blue/);
    assert.match(editor.querySelector('[data-pc-summary]').textContent, new RegExp(chip.getAttribute('data-colour-name')));
    rows[rows.length - 1].querySelector('[data-pc-remove]').click();
    assert.equal(editor.querySelectorAll('[data-pc-row]').length, before);
  });

  // P09 -------------------------------------------------------------------
  caseStart('P09');
  await check('P09', 'colour label follows the radio and Add to Cart posts the variant', async () => {
    const page = makeDom(fixtures.pdp);
    const radios = page.document.querySelectorAll('[data-product-colour]');
    radios[1].checked = true;
    radios[1].dispatchEvent(new page.window.Event('change', { bubbles: true }));
    assert.equal(page.document.querySelector('[data-product-colour-label]').textContent, 'Red');
    const button = [...page.document.querySelectorAll('[data-add-cart]')].find((b) => !b.hasAttribute('data-then-checkout'));
    assert.ok(button);
    button.click();
    await nextTick();
    assert.equal(page.fetchCalls.length, 1);
    assert.equal(page.fetchCalls[0].url, '/api/cart.php');
    assert.equal(page.fetchCalls[0].body.get('action'), 'add');
    assert.equal(page.fetchCalls[0].body.get('variant'), 'Colour: Red');
    assert.equal(page.fetchCalls[0].body.get('csrf_token'), 'test-token');
  });

  // P10 -------------------------------------------------------------------
  caseStart('P10');
  await check('P10', 'cart quantity update and removal send the saved colour variant', async () => {
    const page = makeDom(fixtures.cart);
    const hidden = page.document.querySelector('[data-cart-variant]');
    assert.equal(hidden.value, 'Colour: Black');
    assert.match(page.document.querySelector('[data-cart-row]').textContent, /Colour: Black/);
    page.document.querySelector('[data-cart-update="1"]').click();
    await nextTick();
    assert.equal(page.fetchCalls.length, 1);
    assert.equal(page.fetchCalls[0].body.get('action'), 'update');
    assert.equal(page.fetchCalls[0].body.get('variant'), 'Colour: Black');
    page.document.querySelector('[data-cart-remove]').click();
    await nextTick();
    assert.equal(page.fetchCalls.length, 2);
    assert.equal(page.fetchCalls[1].body.get('action'), 'remove');
    assert.equal(page.fetchCalls[1].body.get('variant'), 'Colour: Black');
  });

  // P11 -------------------------------------------------------------------
  caseStart('P11');
  await check('P11', 'guest merge is variant-aware and keeps a legacy fallback', () => {
    const auth = src('includes/auth.php');
    assert.match(auth, /sh_cart_has_variant_column\(\)/);
    assert.match(auth, /INSERT INTO cart_items \(cart_id, product_id, variant, quantity\)/);
    assert.match(auth, /Legacy schema before the colour migration/);
  });

  // P12 -------------------------------------------------------------------
  caseStart('P12');
  await check('P12', 'cart/checkout/order pages and order snapshot carry the colour', () => {
    assert.match(fixtures.cart, /data-cart-variant value="Colour: Black"/);
    assert.match(src('checkout.php'), /sh_order_item_colour_text/);
    for (const page of ['order-success.php', 'order-details.php', 'orders.php', 'cart.php', 'checkout.php']) {
      assert.match(src(page), /Colour|it\['colour'\]|sh_order_item_colour_text/, page);
    }
    assert.match(src('includes/payment.php'), /'product_variant' => \(\$it\['variant'\] \?\? ''\)/);
    assert.match(src('admin/orders.php'), /sh_order_item_variant_text/);
  });

  // P13 -------------------------------------------------------------------
  caseStart('P13');
  await check('P13', 'notification caption and order text use the saved variant', () => {
    const notifications = src('includes/notifications.php');
    assert.match(notifications, /Package: ' \. \$clip\(\$variant, 140\)/);
    assert.match(notifications, /· Package ' \. \$variant/);
    assert.match(notifications, /sh_order_item_variant_text\(\$item\)/);
  });

  // P14 -------------------------------------------------------------------
  caseStart('P14');
  await check('P14', 'admin save is transactional and defers old-file cleanup', () => {
    const editor = src('admin/product-edit.php');
    assert.match(editor, /beginTransaction\(\)/);
    assert.match(editor, /rollBack\(\)/);
    assert.match(editor, /sh_product_gallery_commit\(\$id, \$plan, \$savedGallery, false\)/);
    assert.match(editor, /\$cleanupUploads\(\)/);
    assert.match(src('includes/product-catalog.php'), /AFTER commit/);
  });

  // P15 -------------------------------------------------------------------
  caseStart('P15');
  await check('P15', 'empty PDP hides gallery, empty tabs and empty metadata', () => {
    const page = makeDom(fixtures.pdpEmpty);
    assert.equal(page.document.querySelector('[data-gallery]'), null);
    assert.equal(page.document.querySelector('[data-tabs]'), null);
    assert.equal(page.document.querySelector('[data-tab-panel]'), null);
    assert.ok(page.document.querySelector('.sh-pdp--no-gallery'));
    assert.equal(page.document.querySelector('.sh-pdp__meta'), null);
  });
  await check('P15', 'SEO structured data receives all three gallery images', () => {
    const page = makeDom(fixtures.pdp);
    const ld = JSON.parse(page.document.querySelector('#fixture-ld').textContent);
    assert.equal(ld.image.length, 3);
    assert.equal(ld.image[0], 'uploads/products/image-1.png');
    assert.match(src('includes/product-card.php'), /sh_product_image\(\$p\['image'\]\)/);
    assert.match(src('ai/ProductStudio.php'), /INSERT INTO product_images/);
  });

  // P16 -------------------------------------------------------------------
  caseStart('P16');
  await check('P16', 'manual gallery navigation, swipe and natural ratio work without timers', () => {
    const page = makeDom(fixtures.pdp);
    const d = page.document;
    const w = page.window;
    const main = d.querySelector('[data-gallery-main]');
    const stage = d.querySelector('[data-gallery-stage]');
    const thumbs = [...d.querySelectorAll('[data-gallery-thumb]')];
    Object.defineProperty(main, 'naturalWidth', { configurable: true, value: 1200 });
    Object.defineProperty(main, 'naturalHeight', { configurable: true, value: 800 });
    Object.defineProperty(main, 'complete', { configurable: true, value: true });
    main.dispatchEvent(new w.Event('load'));
    assert.equal(stage.style.getPropertyValue('--sh-gallery-ratio'), '1.5');
    d.querySelector('[data-gallery-next]').click();
    assert.match(d.querySelector('[data-gallery-count]').textContent, /2 \/ 3/);
    assert.equal(thumbs[1].getAttribute('aria-current'), 'true');
    d.querySelector('[data-gallery-prev]').click();
    assert.match(d.querySelector('[data-gallery-count]').textContent, /1 \/ 3/);
    thumbs[2].click();
    assert.match(d.querySelector('[data-gallery-count]').textContent, /3 \/ 3/);

    const start = new w.Event('touchstart', { bubbles: true });
    Object.defineProperty(start, 'touches', { value: [{ clientX: 200, clientY: 100 }] });
    stage.dispatchEvent(start);
    const end = new w.Event('touchend', { bubbles: true });
    Object.defineProperty(end, 'changedTouches', { value: [{ clientX: 100, clientY: 100 }] });
    stage.dispatchEvent(end);
    assert.match(d.querySelector('[data-gallery-count]').textContent, /1 \/ 3/);

    const down = new w.Event('pointerdown', { bubbles: true });
    Object.defineProperties(down, {
      pointerType: { value: 'mouse' }, clientX: { value: 250 }, clientY: { value: 100 },
    });
    stage.dispatchEvent(down);
    const up = new w.Event('pointerup', { bubbles: true });
    Object.defineProperties(up, {
      pointerType: { value: 'mouse' }, clientX: { value: 100 }, clientY: { value: 100 },
    });
    stage.dispatchEvent(up);
    assert.match(d.querySelector('[data-gallery-count]').textContent, /2 \/ 3/);
    assert.equal(page.timers.intervals, 0);
    assert.equal(page.timers.timeouts, 0);
    assert.deepEqual(page.errors, []);
  });
  await check('P16', 'CSS contains gallery previews and scripts are wired for the SEO preview', () => {
    assert.match(css, /\.sh-gallery__main img \{[^}]*object-fit: contain;/);
    assert.match(css, /\.sh-pg__preview img \{[^}]*object-fit: contain;/);
    assert.match(src('admin/_footer.php'), /\$adminScripts \?\? \[\]/);
    assert.match(src('admin/product-edit.php'), /assets\/js\/product-form\.js/);
    assert.match(src('assets/js/seo.js'), /sh-product-image-picked-request/);
    assert.match(src('assets/js/product-form.js'), /sh-product-image-picked-request/);
    assert.match(src('assets/js/app.js'), /manual only, no automatic rotation/);
  });
  await check('P16', 'primary replacement updates the real SEO image preview', () => {
    const page = makeDom(fixtures.adminEdit, { admin: true });
    const preview = page.document.querySelector('[data-seo-preview="image"]');
    assert.ok(preview);
    const primary = page.document.querySelector('[data-pg-tile="existing"]');
    const replace = primary.querySelector('[data-pg-replace]');
    Object.defineProperty(replace, 'files', { configurable: true, value: [fakeFile(page.window, 'seo-primary.png')] });
    replace.dispatchEvent(new page.window.Event('change', { bubbles: true }));
    assert.match(preview.getAttribute('src') || '', /^blob:/);
    assert.deepEqual(page.errors, []);
  });

  const missing = caseIds.filter((id) => !seenCases.has(id));
  assert.deepEqual(missing, []);
  console.log(`Product management behavior: ${checks} checks, ${failures.length} failures, ${seenCases.size} cases`);
  for (const failure of failures) { console.log('FAIL: ' + failure); }
  process.exit(failures.length === 0 && seenCases.size === 16 ? 0 : 1);
} catch (error) {
  console.error('Product management behavior ERROR: ' + (error && error.stack ? error.stack : error));
  process.exit(1);
}
