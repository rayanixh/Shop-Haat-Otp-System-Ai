/**
 * Real browser verification of the ShopHaat installation page.
 *
 * The page keeps its original design. What is verified here, in Chromium
 * against a real PHP web server and a real MySQL database, is that the
 * original layout still holds at every phone width the brief names and that
 * nothing overflows, overlaps, is clipped or is hidden:
 *
 *   fresh              the original requirements + form screen, the phone
 *                      viewport matrix, and a complete installation driven
 *                      through the original form
 *   repair             the original restricted repair card and the owner
 *                      unlock card on an installed store
 *   requirements-fail  the same screen with genuinely unwritable folders
 *
 *   SH_INSTALL_BASE_URL=http://127.0.0.1:8080 \
 *   SH_INSTALL_DB_PASS=… SH_INSTALL_EXPECT_PHP=8.2.29 \
 *     node tests/browser/installer.mjs
 *
 * Phase select: SH_INSTALL_PHASE=fresh|repair|requirements-fail
 * Screenshots: SH_INSTALL_SHOT_DIR (default tests/browser/artifacts/installer)
 *
 * Exit code 0 only when every check in the selected phase passed.
 */
import { chromium } from 'playwright';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..', '..');
const BASE = (process.env.SH_INSTALL_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const PHASE = process.env.SH_INSTALL_PHASE || 'fresh';
const SHOTS = process.env.SH_INSTALL_SHOT_DIR || join(HERE, 'artifacts', 'installer');
mkdirSync(SHOTS, { recursive: true });

const DB = {
  host: process.env.SH_INSTALL_DB_HOST || '127.0.0.1',
  name: process.env.SH_INSTALL_DB_NAME || 'shophaat_installer',
  user: process.env.SH_INSTALL_DB_USER || 'root',
  pass: process.env.SH_INSTALL_DB_PASS || 'root_test_password',
};
const SITE_NAME = process.env.SH_INSTALL_SITE_NAME || 'Installer Test Store';
const ADMIN_EMAIL = process.env.SH_INSTALL_ADMIN_EMAIL || 'owner@example.test';
const ADMIN_PASSWORD = process.env.SH_INSTALL_ADMIN_PASSWORD || 'InstallerTest!2026';
const EXPECT_PHP = (process.env.SH_INSTALL_EXPECT_PHP || '').trim();

// Every viewport the brief names. 320 is the narrowest phone still in use.
const MOBILE = [320, 360, 375, 390, 412, 430];
const DESKTOP = 1280;

// The original page, verbatim: seven required checks and seven optional ones.
const REQUIRED_NAMES = [
  'PHP 8.1 or newer', 'PDO extension', 'pdo_mysql driver', 'JSON extension',
  '/config writable', '/uploads writable', '/logs writable',
];
// Classes that only ever existed in the discarded redesign. Their absence is a
// check of its own: the page must stay the one it always was.
const REDESIGN_ONLY = [
  '.steps', '.step', '.progress', '.progress__fill', '.summary', '.metric',
  '.verdict', '.actionbar', '.panel', '.panel--done', '.opt-grid', '.opt',
  '.req-list', '.req__name', '.req__value', '.req__badge', '.chip', '.badge',
  '.callout', '.callout--bad', '.callout--ok', '.done__notice', '.sr-only',
];

const checks = [];
function check(name, ok, detail = '') {
  checks.push({ name, ok: !!ok, detail });
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

async function area(label, fn) {
  console.log(`\n${label}`);
  try { await fn(); } catch (error) {
    check(`${label}: the flow completed without an exception`, false, String(error).replace(/\s+/g, ' ').slice(0, 320));
  }
}

function watch(page) {
  const seen = { console: [], pageErrors: [], requests: [] };
  page.on('console', (msg) => { if (msg.type() === 'error') { seen.console.push(msg.text().slice(0, 200)); } });
  page.on('pageerror', (err) => { seen.pageErrors.push(String(err).slice(0, 200)); });
  page.on('response', (res) => {
    if (res.status() >= 400) { seen.requests.push(`${res.status()} ${res.url().replace(BASE, '')}`); }
  });
  page.on('requestfailed', (req) => {
    const failure = req.failure();
    if (failure && /ERR_ABORTED/i.test(failure.errorText)) { return; }
    seen.requests.push(`failed ${req.url().replace(BASE, '')}`);
  });
  return seen;
}

function report(errors, label) {
  check(`${label}: no console errors`, errors.console.length === 0, errors.console.slice(0, 3).join(' | '));
  check(`${label}: no JavaScript exceptions`, errors.pageErrors.length === 0, errors.pageErrors.slice(0, 3).join(' | '));
  check(`${label}: no failed requests (4xx/5xx/network)`, errors.requests.length === 0, errors.requests.slice(0, 3).join(' | '));
}

async function snap(page, name, fullPage = true) {
  try { await page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage }); } catch { /* a screenshot is not a check */ }
}

const url = (path) => BASE + path;

/**
 * Geometry of the page as the browser really laid it out.
 *
 *  - nothing sticks out of the viewport and the page has no sideways scroll;
 *  - every element inside a card stays inside that card's content box (so a
 *    long requirement name cannot push the card, the status or the page);
 *  - a requirement's name and its status never overlap each other;
 *  - no element is clipped by its own box;
 *  - the stylesheet never hides overflow.
 */
async function layoutScan(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const out = { scroll: { doc: document.documentElement.scrollWidth, body: document.body.scrollWidth, vw },
      outsideViewport: [], outsideCard: [], overlaps: [], clipped: [] };

    for (const el of document.querySelectorAll('body *')) {
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) { continue; }
      if (rect.right > vw + 1 || rect.left < -1) {
        const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
        out.outsideViewport.push(`${el.tagName.toLowerCase()}${cls ? '.' + cls : ''} [${rect.left.toFixed(0)}–${rect.right.toFixed(0)}] vw=${vw}`);
      }
    }

    for (const card of document.querySelectorAll('.card')) {
      const style = getComputedStyle(card);
      const box = card.getBoundingClientRect();
      const left = box.left + parseFloat(style.paddingLeft || '0');
      const right = box.right - parseFloat(style.paddingRight || '0');
      const title = (card.querySelector('h2') || {}).textContent || '(card)';
      for (const el of card.querySelectorAll('*')) {
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) { continue; }
        if (rect.left < left - 1 || rect.right > right + 1) {
          const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
          out.outsideCard.push(`${title.trim()}>${el.tagName.toLowerCase()}${cls ? '.' + cls : ''} [${rect.left.toFixed(0)}–${rect.right.toFixed(0)}] card=[${left.toFixed(0)}–${right.toFixed(0)}]`);
        }
      }
    }

    for (const row of document.querySelectorAll('.req li')) {
      const cells = [...row.children].map((el) => el.getBoundingClientRect());
      if (cells.length < 2) { continue; }
      const [a, b] = cells;
      const overlap = !(a.right <= b.left + 1 || b.right <= a.left + 1 || a.bottom <= b.top + 1 || b.bottom <= a.top + 1);
      if (overlap) { out.overlaps.push(`"${row.textContent.trim().slice(0, 40)}"`); }
    }

    for (const el of document.querySelectorAll('.card, .card *')) {
      // Form controls scroll their own content natively; that is not page layout.
      if (['INPUT', 'SELECT', 'TEXTAREA', 'svg', 'SVG'].includes(el.tagName)) { continue; }
      const style = getComputedStyle(el);
      const clippedX = /hidden|clip|auto|scroll/.test(style.overflowX) && el.scrollWidth > el.clientWidth + 1;
      const clippedY = /hidden|clip|auto|scroll/.test(style.overflowY) && el.scrollHeight > el.clientHeight + 1;
      if (clippedX || clippedY) {
        const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
        out.clipped.push(`${el.tagName.toLowerCase()}${cls ? '.' + cls : ''} ${el.scrollWidth}>${el.clientWidth}`);
      }
    }

    for (const key of Object.keys(out)) {
      if (Array.isArray(out[key])) { out[key] = [...new Set(out[key])].slice(0, 6); }
    }
    return out;
  });
}

async function assertLayout(page, label) {
  const scan = await layoutScan(page);
  check(`${label}: the page does not scroll sideways`,
    scan.scroll.doc <= scan.scroll.vw + 1 && scan.scroll.body <= scan.scroll.vw + 1,
    `scrollWidth ${scan.scroll.doc}/${scan.scroll.body}, viewport ${scan.scroll.vw}`);
  check(`${label}: nothing sticks out of the viewport`, scan.outsideViewport.length === 0, scan.outsideViewport.join(' | '));
  check(`${label}: every element stays inside its card`, scan.outsideCard.length === 0, scan.outsideCard.join(' | '));
  check(`${label}: no requirement name overlaps its status`, scan.overlaps.length === 0, scan.overlaps.join(' | '));
  check(`${label}: no content is clipped by its box`, scan.clipped.length === 0, scan.clipped.join(' | '));
  return scan;
}

/**
 * Every status belongs to its own row: it starts on the same horizontal line
 * as the label it describes, sits at the right edge of that row and is
 * right-aligned. A wrapped label must never push the status underneath it.
 */
async function assertStatusRows(page, label) {
  const rows = await page.evaluate(() => [...document.querySelectorAll('.req li')].map((li) => {
    const name = li.children[0].getBoundingClientRect();
    const status = li.children[1].getBoundingClientRect();
    const row = li.getBoundingClientRect();
    return {
      text: li.children[0].textContent.trim().slice(0, 44),
      value: li.children[1].textContent.trim().slice(0, 24),
      nameTop: Math.round(name.top), statusTop: Math.round(status.top),
      statusLeft: status.left, nameRight: name.right,
      statusRight: status.right, rowRight: row.right,
      align: getComputedStyle(li.children[1]).textAlign,
    };
  }));
  check(`${label}: every list row has a label and a status cell`, rows.length === 14, `${rows.length} row(s)`);
  const wrapped = rows.filter((r) => Math.abs(r.statusTop - r.nameTop) > 2);
  check(`${label}: every status starts on the same line as its label`, wrapped.length === 0,
    wrapped.map((r) => `${r.text}… [label ${r.nameTop} vs status ${r.statusTop}]`).join(' | '));
  const beside = rows.filter((r) => r.statusLeft < r.nameRight - 1);
  check(`${label}: no status overlaps or undercuts its label`, beside.length === 0,
    beside.map((r) => r.text).join(' | '));
  const notRight = rows.filter((r) => Math.abs(r.statusRight - r.rowRight) > 1.5);
  check(`${label}: every status is right-aligned in its row`, notRight.length === 0,
    notRight.map((r) => `${r.value} [${Math.round(r.statusRight)} vs ${Math.round(r.rowRight)}]`).join(' | '));
  check(`${label}: the status cells are text-aligned right`, rows.every((r) => r.align === 'right'),
    [...new Set(rows.map((r) => r.align))].join(', '));
  return rows;
}

async function phoneMatrix(browser, label, pathFor = () => '/install/', expectHeading = null) {
  for (const width of MOBILE) {
    const page = await browser.newPage({ viewport: { width, height: 760 } });
    await page.goto(url(pathFor()), { waitUntil: 'load' });
    await assertLayout(page, `${label} @${width}px`);
    if (expectHeading) {
      const h1 = (await page.textContent('h1') || '').trim();
      check(`${label} @${width}px: the heading is unchanged`, h1 === expectHeading, h1);
    }
    await assertStatusRows(page, `${label} @${width}px`);
    // A button may never be wider than the card it sits in, and it must stay a
    // comfortable target.
    const buttons = await page.evaluate(() => [...document.querySelectorAll('.btn, .actions a')].map((el) => {
      const rect = el.getBoundingClientRect();
      const parent = el.parentElement.getBoundingClientRect();
      return { text: el.textContent.trim().slice(0, 24), w: Math.round(rect.width), pw: Math.round(parent.width), h: Math.round(rect.height), left: rect.left, right: rect.right, pleft: parent.left, pright: parent.right };
    }));
    const overflowing = buttons.filter((b) => b.w > b.pw + 1 || b.left < b.pleft - 1 || b.right > b.pright + 1);
    check(`${label} @${width}px: no button exceeds its container`, overflowing.length === 0,
      overflowing.map((b) => `${b.text}=${b.w}px in ${b.pw}px`).join(' | '));
    const small = buttons.filter((b) => b.h > 0 && b.h < 40);
    check(`${label} @${width}px: every control is a comfortable touch target (>= 40px tall)`, small.length === 0,
      small.map((b) => `${b.text}=${b.h}px`).join(' | '));
    await snap(page, `installer-${label}-${width}px`);
    await page.close();
  }
}

/** The discarded redesign is gone: no element, class or stylesheet rule of it. */
async function assertOriginalDesign(page, label) {
  const found = await page.evaluate((selectors) => selectors.filter((sel) => document.querySelector(sel) !== null), REDESIGN_ONLY);
  check(`${label}: the redesigned step/progress/summary markup is not on the page`, found.length === 0, found.join(', '));
  const css = await page.$eval('style', (el) => el.textContent || '');
  check(`${label}: the stylesheet never hides content with overflow:hidden`, !/overflow\s*:\s*hidden/i.test(css),
    (css.match(/overflow\s*:\s*hidden[^;]*/i) || [''])[0]);
  const brand = await page.evaluate(() => {
    const root = getComputedStyle(document.documentElement);
    const card = getComputedStyle(document.querySelector('.card'));
    const wrap = getComputedStyle(document.querySelector('.wrap'));
    return { brand: root.getPropertyValue('--brand').trim(), radius: card.borderRadius, maxWidth: wrap.maxWidth };
  });
  check(`${label}: the original brand colour, card radius and page width are unchanged`,
    brand.brand === '#e8501b' && brand.radius === '10px' && brand.maxWidth === '820px',
    `${brand.brand} / ${brand.radius} / ${brand.maxWidth}`);
}

// ---------------------------------------------------------------------------
// Phase: fresh install
// ---------------------------------------------------------------------------
async function phaseFresh(browser) {
  const page = await browser.newPage({ viewport: { width: DESKTOP, height: 900 } });
  const errors = watch(page);

  await area('1. The original requirements card reports what this server really has', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the installer responds with HTTP 200', res && res.status() === 200, `status ${res && res.status()}`);
    const h1 = (await page.textContent('h1') || '').trim();
    check('the page keeps its original title', h1 === 'Install your store', h1);

    const headings = await page.$$eval('.card h2', (els) => els.map((el) => el.textContent.trim()));
    check('the cards are the original ones, in the original order',
      JSON.stringify(headings) === JSON.stringify(['Server requirements', 'Database connection', 'Website', 'Administrator account']),
      headings.join(' > '));
    const h1s = await page.$$eval('h1', (els) => els.length);
    const h2s = await page.$$eval('.card h2', (els) => els.length);
    check('there is exactly one page heading and no duplicate headings', h1s === 1 && h2s === headings.length, `${h1s} h1, ${h2s} h2`);

    const rows = await page.$$eval('.req', (lists) => lists.map((list) => [...list.querySelectorAll('li')].map((li) => ({
      name: li.children[0].textContent.trim(),
      value: li.children[1].textContent.trim(),
      valueCls: li.children[1].className,
    }))));
    check('the page has the two original lists (required and optional)', rows.length === 2, `${rows.length} list(s)`);
    const required = rows[0];
    check('all seven required checks are listed', required.length === 7, required.map((r) => r.name).join(', '));
    check('the seven required checks are the original ones', JSON.stringify(required.map((r) => r.name)) === JSON.stringify(REQUIRED_NAMES),
      required.map((r) => r.name).join(' | '));
    check('every required row is detected as passed on this host', required.every((r) => r.valueCls === 'ok' && /Enabled|Writable|^\d+\.\d+/.test(r.value)),
      required.map((r) => `${r.name}=${r.value}`).join(', '));
    if (EXPECT_PHP) {
      check('the PHP row shows the version the server actually runs', required[0].value === EXPECT_PHP, `${required[0].value} vs expected ${EXPECT_PHP}`);
    } else {
      check('the PHP row shows a real version string', /^\d+\.\d+\.\d+/.test(required[0].value), required[0].value);
    }
  });

  await area('2. Optional extensions stay separate, complete and never blocking', async () => {
    const optional = await page.$$eval('.req', (lists) => [...lists[1].querySelectorAll('li')].map((li) => ({
      name: li.children[0].textContent.trim(),
      value: li.children[1].textContent.trim(),
      valueCls: li.children[1].className,
    })));
    check('all seven optional extensions are listed', optional.length === 7, optional.map((o) => o.name).join(', '));
    check('every optional row names the extension and its purpose', optional.every((o) => o.name.includes(' — ') && o.name.length > 12),
      optional[0].name);
    check('every optional row says what is used instead when it is missing',
      optional.every((o) => /^(Enabled|Fallback: .+|Not enabled)$/.test(o.value)), optional.map((o) => o.value).join(', '));
    check('no optional extension is presented as an error', optional.every((o) => o.valueCls === 'ok' || o.valueCls === 'warn'),
      optional.filter((o) => o.valueCls !== 'ok' && o.valueCls !== 'warn').map((o) => o.name).join(', '));
    // The optional list explains itself: the explanatory paragraph under the
    // heading was removed on request and must not come back.
    const optionalCard = (await page.textContent('.card') || '').replace(/\s+/g, ' ');
    check('the optional-extensions blurb is gone', !/Recommended, not required|These are recommended/i.test(optionalCard),
      (optionalCard.match(/[^.]*recommended[^.]*\./i) || [''])[0].slice(0, 90));
    const optionalHeadings = await page.$$eval('.card h2', (els) => els.filter((el) => /Optional extensions/i.test(el.textContent)).map((el) => el.textContent.trim()));
    check('the "Optional extensions" heading is removed from the page', optionalHeadings.length === 0, optionalHeadings.join(', '));
  });

  await area('3. The form keeps the existing fields, naming and CSRF protection', async () => {
    const fields = ['db_host', 'db_name', 'db_user', 'db_pass', 'site_name', 'site_logo', 'demo_on', 'demo_off',
      'admin_email', 'admin_password', 'admin_password2'];
    for (const id of fields) {
      const n = await page.locator(`#${id}`).count();
      check(`the form still contains #${id}`, n === 1, `${n} match(es)`);
    }
    const csrf = await page.locator('input[name="csrf_token"]').first().getAttribute('value').catch(() => null);
    check('the CSRF token is present in the form', !!csrf && /^[a-f0-9]{64}$/.test(csrf), csrf ? csrf.slice(0, 12) + '…' : 'missing');
    const unlabelled = await page.$$eval('input:not([type="hidden"])', (els) => els
      .filter((el) => !el.closest('label') && !(el.id && document.querySelector(`label[for="${el.id}"]`)))
      .map((el) => el.id || el.name));
    check('every input is associated with a label', unlabelled.length === 0, unlabelled.join(', '));
    const radios = await page.$$eval('input[name="demo_data"]', (els) => els.map((el) => el.value));
    check('Demo Data is still the explicit ON/OFF pair', JSON.stringify(radios.sort()) === JSON.stringify(['0', '1']), radios.join(', '));
    check('the catalogue is off unless the operator turns it on', !(await page.isChecked('#demo_on')));
    const hints = await page.$$eval('p.hint', (els) => els.map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
    check('helper text stays to one short line per field', hints.every((h) => h.length <= 110), hints.map((h) => h.length).join(', '));
    const html = await page.content();
    check('no server path leaks into the page', !/\/home\/[a-z]|\/var\/www|C:\\\\inetpub/.test(html),
      (html.match(/\/home\/[a-z]|\/var\/www/g) || []).slice(0, 2).join(', '));
  });

  await area('4. The original design is intact on desktop', async () => {
    await assertOriginalDesign(page, 'desktop');
    await assertLayout(page, 'desktop @1280px');
    await assertStatusRows(page, 'desktop @1280px');
    await snap(page, 'installer-desktop-1280px');
    report(errors, 'the requirements screen');
  });

  await area('5. Phone viewports 320–430px', async () => {
    await phoneMatrix(browser, 'requirements', () => '/install/', 'Install your store');
  });

  await area('6. A complete installation runs through the original form', async () => {
    await page.goto(url('/install/'), { waitUntil: 'load' });
    check('no database configuration exists before the install', !existsSync(join(ROOT, 'config', 'database.php')));
    check('the store is not installed before the install', !existsSync(join(ROOT, 'config', 'installed.lock')));

    await page.fill('#db_host', DB.host);
    await page.fill('#db_name', DB.name);
    await page.fill('#db_user', DB.user);
    await page.fill('#db_pass', DB.pass);
    await page.fill('#site_name', SITE_NAME);
    await page.check('#demo_on');
    await page.fill('#admin_email', ADMIN_EMAIL);
    await page.fill('#admin_password', ADMIN_PASSWORD);
    await page.fill('#admin_password2', ADMIN_PASSWORD);

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 180000 }).catch(() => null),
      page.click('button.btn'),
    ]);
    const done = await page.waitForSelector('.card.done', { timeout: 180000 }).catch(() => null);
    if (!done) {
      const failure = (await page.textContent('.alert--bad').catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 300);
      check('the installation completed through the original form', false, failure || 'no completion card appeared');
      await snap(page, 'installer-install-failed');
      return;
    }
    check('the installation completed through the original form', true);

    const h1 = (await page.textContent('h1') || '').trim();
    check('the completion screen is titled Installation complete', h1 === 'Installation complete', h1);
    const doneHeadings = await page.$$eval('.card h2', (els) => els.length);
    check('the completion card carries no second heading', doneHeadings === 0, `${doneHeadings} heading(s)`);
    const doneText = (await page.textContent('.card.done') || '').replace(/\s+/g, ' ');
    check('the completion screen reports the result the server computed', /Installation completed successfully/.test(doneText), doneText.slice(0, 90));
    check('the completion screen still tells the owner to delete /install', /Delete the \/install<\/code>?\s*folder|Delete the \/install folder/.test(doneText), doneText.slice(0, 120));
    check('the completion screen still links to the store and the admin panel',
      (await page.locator('.actions a').count()) === 2);
    await assertLayout(page, 'completion @1280px');
    await snap(page, 'installer-done-desktop-1280px');

    check('the installer wrote config/database.php', existsSync(join(ROOT, 'config', 'database.php')));
    check('the installer wrote config/installed.lock', existsSync(join(ROOT, 'config', 'installed.lock')));
    if (existsSync(join(ROOT, 'config', 'installed.lock'))) {
      const lock = readFileSync(join(ROOT, 'config', 'installed.lock'), 'utf8');
      check('the lock file records the real installation', /"installed_at"/.test(lock), lock.replace(/\s+/g, ' ').slice(0, 90));
    }

    const install = await page.request.get(url('/install/'), { maxRedirects: 0 });
    check('a locked store no longer serves the installer', install.status() >= 300 && install.status() < 400,
      `status ${install.status()} → ${install.headers()['location'] || ''}`);
    const store = await page.request.get(url('/index.php'));
    check('the storefront it just installed loads', store.status() === 200, `status ${store.status()}`);
    check('the storefront shows the configured site name', (await store.text()).includes(SITE_NAME), SITE_NAME);
    const admin = await page.request.get(url('/admin/login.php'));
    check('the admin sign-in page loads', admin.status() === 200, `status ${admin.status()}`);

    for (const width of [320, 430]) {
      await page.setViewportSize({ width, height: 760 });
      await page.waitForTimeout(120);
      await assertLayout(page, `completion @${width}px`);
      await snap(page, `installer-done-${width}px`);
    }
    await page.setViewportSize({ width: DESKTOP, height: 900 });
  });

  report(errors, 'the whole fresh-install session');
  await page.close();
}

// ---------------------------------------------------------------------------
// Phase: blocked requirements (real unwritable directories)
// ---------------------------------------------------------------------------
async function phaseRequirementsFail(browser) {
  const page = await browser.newPage({ viewport: { width: DESKTOP, height: 900 } });
  const errors = watch(page);

  await area('1. Genuinely failed checks block the installation', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the installer still responds with HTTP 200 when checks fail', res && res.status() === 200, `status ${res && res.status()}`);
    const required = await page.$$eval('.req', (lists) => [...lists[0].querySelectorAll('li')].map((li) => ({
      name: li.children[0].textContent.trim(),
      value: li.children[1].textContent.trim(),
      valueCls: li.children[1].className,
    })));
    const failed = required.filter((r) => r.valueCls === 'bad');
    check('the unwritable folders are reported as failed', failed.length >= 3, failed.map((r) => `${r.name}=${r.value}`).join(', '));
    check('a failed row states what was detected', failed.every((r) => /Not writable/.test(r.value)), failed.map((r) => r.value).join(', '));
    const button = page.locator('button.btn');
    check('the install button is disabled while a required check fails', await button.isDisabled());
    const hint = (await page.textContent('h1') || '').trim();
    check('the screen is still the original one', hint === 'Install your store', hint);
    await assertOriginalDesign(page, 'blocked desktop');
    await assertLayout(page, 'blocked @1280px');
    await snap(page, 'installer-blocked-desktop-1280px');
    report(errors, 'the blocked screen');
  });

  await area('2. The blocked screen is usable at 320–430px', async () => {
    await phoneMatrix(browser, 'blocked', () => '/install/', 'Install your store');
  });

  await page.close();
}

// ---------------------------------------------------------------------------
// Phase: repair mode on an installed store
// ---------------------------------------------------------------------------
async function phaseRepair(browser) {
  const page = await browser.newPage({ viewport: { width: DESKTOP, height: 900 } });
  const errors = watch(page);

  await area('1. An installed store serves the original restricted repair card', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the repair screen responds with HTTP 200', res && res.status() === 200, `status ${res && res.status()}`);
    const h1 = (await page.textContent('h1') || '').trim();
    check('the screen is titled Repair this store', h1 === 'Repair this store', h1);
    const headings = await page.$$eval('.card h2', (els) => els.map((el) => el.textContent.trim()));
    check('the repair screen shows the original restricted and unlock cards',
      JSON.stringify(headings) === JSON.stringify([
        'Server requirements',
        'Restricted: this store is already installed', 'Store owner: unlock full setup',
      ]), headings.join(' > '));

    // A repair works over the saved connection: the page must not offer a way
    // to enter different database credentials, and it must not echo the stored
    // password back into the markup.
    const credentialFields = await page.$$eval('input', (els) => els
      .map((el) => el.getAttribute('name') || '')
      .filter((name) => ['db_host', 'db_name', 'db_user', 'db_pass'].includes(name)));
    check('the repair form accepts no database credentials', credentialFields.length === 0, credentialFields.join(', '));
    const html = await page.content();
    check('the saved database password is never written into the page', !/not-a-real-password|root_test_password/.test(html));
    const repair = page.locator('button.btn').first();
    check('the primary action repairs the database instead of installing', (await repair.textContent() || '').includes('Repair database tables'));
    check('the owner unlock form is still offered', await page.locator('#install_unlock').count() === 1);

    await assertOriginalDesign(page, 'repair desktop');
    await assertLayout(page, 'repair @1280px');

    // A wrong unlock text is refused with a plain message and changes nothing.
    await page.fill('#install_unlock', 'definitely-not-the-token');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => null), page.locator('button.btn').last().click()]);
    const refusal = (await page.textContent('.alert--bad').catch(() => '') || '').replace(/\s+/g, ' ');
    check('a wrong unlock text is refused in plain language', /did not match the file on the server/.test(refusal), refusal.slice(0, 120));
    check('the refused unlock leaves the store uninstalled', !existsSync(join(ROOT, 'config', 'installed.lock')));

    await assertLayout(page, 'repair after a refused unlock @1280px');
    await snap(page, 'installer-repair-desktop-1280px');
  });

  await area('2. The repair screen is usable at 320–430px', async () => {
    await phoneMatrix(browser, 'repair', () => '/install/', 'Repair this store');
  });

  await area('3. The repair runs over the saved connection', async () => {
    await page.goto(url('/install/'), { waitUntil: 'load' });
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }).catch(() => null),
      page.locator('button.btn').first().click(),
    ]);
    const done = await page.waitForSelector('.card.done', { timeout: 60000 }).catch(() => null);
    const bad = (await page.textContent('.alert--bad').catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 200);
    check('the repair completed', !!done, done ? '' : bad);
    if (done) {
      const notice = (await page.textContent('.card.done') || '').replace(/\s+/g, ' ');
      check('the repair reports what it did', /Database repaired against/.test(notice), notice.slice(0, 100));
      check('the repair says the administrator was left untouched', /administrator account was left untouched/.test(notice), notice.slice(0, 160));
      const store = await page.request.get(url('/index.php'));
      check('the store still loads after the repair', store.status() === 200, `status ${store.status()}`);
      await assertLayout(page, 'repair completion @1280px');
    }
    await snap(page, 'installer-repair-done-desktop-1280px');
    report(errors, 'the repair session');
  });

  await page.close();
}

// ---------------------------------------------------------------------------
const browser = await chromium.launch();
console.log(`ShopHaat installer UI — phase "${PHASE}" against ${BASE}`);
try {
  if (PHASE === 'fresh') { await phaseFresh(browser); }
  else if (PHASE === 'requirements-fail') { await phaseRequirementsFail(browser); }
  else if (PHASE === 'repair') { await phaseRepair(browser); }
  else { check(`unknown phase "${PHASE}"`, false); }
} finally {
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
console.log(`\nInstaller UI (${PHASE}): ${checks.length - failed.length} passed, ${failed.length} failed`);
if (failed.length) {
  console.log('Failures:');
  for (const f of failed) { console.log(`  - ${f.name}${f.detail ? ' — ' + f.detail : ''}`); }
}
console.log(`Screenshots: ${SHOTS}`);
process.exit(failed.length ? 1 : 0);
