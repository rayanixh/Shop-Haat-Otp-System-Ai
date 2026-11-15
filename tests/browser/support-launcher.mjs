/**
 * Real browser verification of the ShopHaat support launcher.
 *
 * Everything asserted here is observed in Chromium against a real PHP web
 * server and a real MySQL database seeded with tests/browser/seed.php:
 *
 *   admin     the two external channels are configured in the EXISTING support
 *             settings page, a plain-http Facebook link is rejected, and the
 *             saved values are normalised (a typed number becomes wa.me)
 *   desktop   the launcher is a 56px circle with no "Support" text, the menu
 *             slides up with three options, Facebook and WhatsApp really open
 *             the configured destinations in a new tab, Escape / outside click
 *             close it, and Live Chat still opens the untouched chat panel
 *             (including a real reply and a real Talk-to-a-human escalation)
 *   mobile    320 / 360 / 375 / 390 / 412 / 430 px and 1280 px desktop: the
 *             circle never stretches, nothing overflows sideways, the open menu
 *             stays inside the viewport and clears the launcher, labels stay
 *             readable, and there are no console errors
 *   keyboard  Enter opens, arrows move, Escape closes and refocuses the launcher
 *   reduced   prefers-reduced-motion still opens and closes the menu
 *   fallback  an unconfigured store shows the unavailable state while Live
 *             Chat keeps working
 *
 *   SH_SUPPORT_BASE_URL=http://127.0.0.1:8080 node tests/browser/support-launcher.mjs
 *
 * Screenshots: SH_SUPPORT_SHOT_DIR (default tests/browser/artifacts/support-launcher)
 * Exit code 0 only when every check passed.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = (process.env.SH_SUPPORT_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const SHOTS = process.env.SH_SUPPORT_SHOT_DIR || join(HERE, 'artifacts', 'support-launcher');
mkdirSync(SHOTS, { recursive: true });

const ADMIN_EMAIL = process.env.SH_SUPPORT_ADMIN_EMAIL || 'admin@example.test';
const ADMIN_PASSWORD = process.env.SH_SUPPORT_ADMIN_PASSWORD || 'AdminTest!2026';

const FB_URL = 'https://m.me/ShopHaatSupport';
const WA_NUMBER = '+8801712345678';
const WA_TEXT = 'Hi ShopHaat, I need help with my order.';
const WA_NORMALISED = 'https://wa.me/8801712345678';

const MOBILE = [320, 360, 375, 390, 412, 430];
const DESKTOP = 1280;

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

/**
 * Console/exception/network watcher.
 *
 * The store ships a Report-Only CSP in this environment, so Chromium logs
 * "[Report Only] Refused to evaluate a string as JavaScript" whenever the
 * harness itself uses an in-page predicate. Those are recorded separately and
 * reported with their source, and the run asserts that none of them comes from
 * the launcher's own scripts — a real widget error would still fail.
 */
async function watch(page) {
  const seen = { console: [], reportOnly: [], pageErrors: [], requests: [], page };
  await page.addInitScript(() => {
    window.__shCspReports = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__shCspReports.push({
        directive: String(event.violatedDirective || ''),
        blocked: String(event.blockedURI || ''),
        sample: String(event.sample || '').slice(0, 120),
        source: `${String(event.sourceFile || '').split('/').pop()}:${event.lineNumber || 0}`,
      });
    });
  });
  page.on('console', (msg) => {
    if (msg.type() !== 'error') { return; }
    const text = msg.text().slice(0, 220);
    if (/\[Report Only\]/.test(text)) { seen.reportOnly.push(text); return; }
    seen.console.push(text);
  });
  page.on('pageerror', (err) => { seen.pageErrors.push(String(err).slice(0, 200)); });
  page.on('response', (res) => {
    if (res.status() >= 400 && !/favicon/.test(res.url())) { seen.requests.push(`${res.status()} ${res.url().replace(BASE, '')}`); }
  });
  page.on('requestfailed', (req) => {
    const failure = req.failure();
    if (failure && /ERR_ABORTED/i.test(failure.errorText)) { return; }
    if (!req.url().startsWith(BASE)) { return; }
    seen.requests.push(`failed ${req.url().replace(BASE, '')}`);
  });
  return seen;
}

async function report(errors, label) {
  const csp = await errors.page.evaluate(() => window.__shCspReports || []).catch(() => []);
  const fromLauncher = csp.filter((c) => /support-chat\.js/.test(c.source) || /sh-schat/.test(c.sample) || /sh-schat/.test(c.blocked));
  check(`${label}: no console errors`, errors.console.length === 0, errors.console.slice(0, 3).join(' | '));
  check(`${label}: no JavaScript exceptions`, errors.pageErrors.length === 0, errors.pageErrors.slice(0, 3).join(' | '));
  check(`${label}: no failed requests (4xx/5xx/network)`, errors.requests.length === 0, errors.requests.slice(0, 3).join(' | '));
  check(`${label}: no CSP violation comes from the support launcher`, fromLauncher.length === 0,
    JSON.stringify(fromLauncher.slice(0, 2)));
  if (csp.length) {
    console.log(`  note  ${label}: ${csp.length} Report-Only CSP notice(s), first: ` + JSON.stringify(csp[0]));
  }
}

/** Wait for the store's page-transition overlay to finish before measuring. */
async function settle(page) {
  await page.waitForFunction(() => {
    const doc = document.documentElement;
    if (doc.classList.contains('sh-pt-hold') || doc.classList.contains('sh-pt-enter')) { return false; }
    const overlay = document.getElementById('sh-pt');
    if (!overlay) { return true; }
    return !overlay.classList.contains('is-on') && !overlay.classList.contains('is-hold');
  }, null, { timeout: 15000 }).catch(() => null);
  await page.waitForTimeout(120);
}

async function snap(page, name, fullPage = false) {
  try { await page.screenshot({ path: join(SHOTS, `${name}.png`), fullPage }); } catch { /* a screenshot is not a check */ }
}

const url = (path) => BASE + path;

/** Launcher geometry as the browser computed it. */
const fabGeometry = (page) => page.evaluate(() => {
  const fab = document.querySelector('#sh-schat-fab');
  if (!fab) { return null; }
  const r = fab.getBoundingClientRect();
  const s = getComputedStyle(fab);
  return {
    width: r.width, height: r.height, top: r.top, right: r.right, bottom: r.bottom, left: r.left,
    radius: s.borderRadius, background: s.backgroundColor, position: s.position,
    text: fab.textContent.replace(/[0-9]/g, '').trim(),
    icons: fab.querySelectorAll('svg').length,
    label: fab.getAttribute('aria-label') || '',
    expanded: fab.getAttribute('aria-expanded'),
    vw: window.innerWidth, vh: window.innerHeight,
    docScroll: document.documentElement.scrollWidth,
  };
});

const menuGeometry = (page) => page.evaluate(() => {
  const menu = document.querySelector('#sh-schat-menu');
  const fab = document.querySelector('#sh-schat-fab');
  if (!menu || !fab) { return null; }
  const r = menu.getBoundingClientRect();
  const f = fab.getBoundingClientRect();
  const s = getComputedStyle(menu);
  return {
    top: r.top, right: r.right, bottom: r.bottom, left: r.left, width: r.width, height: r.height,
    fabTop: f.top, open: menu.classList.contains('is-open'), hidden: menu.hidden,
    opacity: s.opacity, transition: s.transitionProperty, visibility: s.visibility,
    expanded: fab.getAttribute('aria-expanded'),
    items: [...menu.querySelectorAll('[role="menuitem"]')].map((el) => ({
      text: (el.querySelector('strong') || el).textContent.trim(),
      tag: el.tagName.toLowerCase(),
      href: el.getAttribute('href'),
      target: el.getAttribute('target'),
      rel: el.getAttribute('rel') || '',
      disabled: el.getAttribute('aria-disabled') === 'true',
      icon: !!el.querySelector('.sh-schat-opt__icon svg'),
      fontSize: parseFloat(getComputedStyle(el.querySelector('strong') || el).fontSize || '0'),
    })),
    vw: window.innerWidth, vh: window.innerHeight,
    docScroll: document.documentElement.scrollWidth,
  };
});

async function openMenu(page) {
  await page.click('#sh-schat-fab');
  await page.waitForFunction(() => document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 10000 });
  // Let the slide/fade transition finish so the geometry and opacity checks
  // describe the settled state (reduced motion settles immediately).
  await page.waitForFunction(() => {
    const menu = document.querySelector('#sh-schat-menu');
    return !!menu && getComputedStyle(menu).opacity === '1';
  }, null, { timeout: 5000 });
  await page.waitForTimeout(120);
}

/* ---------------------------------------------------------------------------
 * Phase 1 — the administrator configures the two external channels
 * ------------------------------------------------------------------------ */
async function phaseAdmin(browser) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = await watch(page);

  await area('1. The channels are configured in the existing support settings page', async () => {
    await page.goto(url('/admin/login.php'), { waitUntil: 'load' });
    await page.fill('#ad-email', ADMIN_EMAIL);
    await page.fill('#ad-pass', ADMIN_PASSWORD);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      page.click('form button[type="submit"]'),
    ]);
    check('the administrator is signed in', /dashboard/i.test(page.url()), page.url().replace(BASE, ''));

    await page.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    const fields = await page.evaluate(() => ({
      facebook: !!document.querySelector('#support-facebook'),
      whatsapp: !!document.querySelector('#support-whatsapp'),
      message: !!document.querySelector('#support-whatsapp-message'),
      inExistingForm: !!document.querySelector('#support-facebook')?.closest('form')?.querySelector('input[name="form"][value="save_settings"]'),
      existingSections: document.querySelectorAll('#settings, #ai').length,
    }));
    check('the existing settings page hosts the Facebook, WhatsApp and pre-filled message fields',
      fields.facebook && fields.whatsapp && fields.message);
    check('the channel fields belong to the existing save_settings form (no second settings page)',
      fields.inExistingForm && fields.existingSections === 2);

    // A non-https link must be refused, not silently stored.
    await page.fill('#support-facebook', 'http://m.me/ShopHaatSupport');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      page.click('button:has-text("Save support settings")'),
    ]);
    const badText = (await page.textContent('.sh-alert--error').catch(() => '') || '').replace(/\s+/g, ' ');
    check('a plain-http Facebook link is rejected with an explanation', /https/i.test(badText), badText.slice(0, 140));

    await page.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    await page.fill('#support-facebook', FB_URL);
    await page.fill('#support-whatsapp', WA_NUMBER);
    await page.fill('#support-whatsapp-message', WA_TEXT);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      page.click('button:has-text("Save support settings")'),
    ]);

    await page.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    const saved = await page.evaluate(() => ({
      facebook: document.querySelector('#support-facebook')?.value || '',
      whatsapp: document.querySelector('#support-whatsapp')?.value || '',
      message: document.querySelector('#support-whatsapp-message')?.value || '',
      chips: [...document.querySelectorAll('.sh-support-settings__state')].map((el) => el.textContent.trim()),
    }));
    // A typed number is accepted and normalised to its wa.me URL — that is what
    // the storefront links to, so a stray space or dash can never break it.
    check('the accepted values are stored and shown again',
      saved.facebook === FB_URL && saved.whatsapp === WA_NORMALISED && saved.message === WA_TEXT,
      `${saved.facebook} | ${saved.whatsapp} | ${saved.message.slice(0, 40)}`);
    check('the page reports both channels as available', saved.chips.some((c) => /Facebook: available/.test(c)) && saved.chips.some((c) => /WhatsApp: available/.test(c)),
      saved.chips.join(' / ').slice(0, 160));
    check('the page states that live chat stays available', saved.chips.some((c) => /Live chat: always available/.test(c)));
    await snap(page, 'support-launcher-admin-desktop-1280');
    await report(errors, 'the admin settings session');
  });

  await context.close();
}

/* ---------------------------------------------------------------------------
 * Phase 2 — desktop storefront: circle, menu, real destinations, live chat
 * ------------------------------------------------------------------------ */
async function phaseDesktop(browser) {
  const context = await browser.newContext({ viewport: { width: DESKTOP, height: 900 } });
  // The external destinations are stubbed so the test proves the navigation
  // really happens without depending on Facebook or WhatsApp being reachable.
  await context.route('https://m.me/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Messenger stub</title>' }));
  await context.route('https://wa.me/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>WhatsApp stub</title>' }));
  const page = await context.newPage();
  const errors = await watch(page);

  await area('2. The launcher is a compact circle on desktop', async () => {
    await page.goto(url('/index.php'), { waitUntil: 'load' });
    await settle(page);
    const fab = await fabGeometry(page);
    check('the launcher exists on the storefront', !!fab);
    if (!fab) { return; }
    check('the launcher is a perfect circle (equal width and height)', Math.abs(fab.width - fab.height) < 0.6, `${fab.width}x${fab.height}`);
    check('the launcher is compact (48–60px)', fab.width >= 48 && fab.width <= 60, `${fab.width}px`);
    check('the launcher is round (border-radius 50%)', fab.radius === '50%', fab.radius);
    check('the launcher shows a chat icon only — no "Support" text', fab.text === '' && fab.icons >= 1, JSON.stringify(fab.text));
    check('the launcher has an accessible name', /support/i.test(fab.label), fab.label);
    check('the launcher is fixed and bottom-right anchored',
      fab.position === 'fixed' && fab.vw - fab.right >= 8 && fab.vh - fab.bottom >= 8,
      `right gap ${(fab.vw - fab.right).toFixed(1)} bottom gap ${(fab.vh - fab.bottom).toFixed(1)}`);
    check('the unread badge starts hidden', await page.locator('#sh-schat-badge').isHidden());
    check('the launcher is collapsed before any interaction', fab.expanded === 'false');
    check('the launcher menu starts hidden', await page.locator('#sh-schat-menu').isHidden());
    check('the chat panel starts closed', await page.locator('#sh-schat-sheet').isHidden());
    check('exactly one launcher exists on the page', await page.locator('#sh-schat-fab').count() === 1);
    check('the brand colour is used for the circle', /rgb\(/.test(fab.background), fab.background);
    await snap(page, 'support-launcher-desktop-1280-closed');
  });

  await area('3. The menu slides up with exactly three options', async () => {
    await openMenu(page);
    const menu = await menuGeometry(page);
    check('the menu opens on launcher click', menu.open === true && menu.hidden === false && menu.visibility === 'visible');
    check('the launcher reports the open menu', menu.expanded === 'true');
    check('the menu animates (opacity + transform transition)', /opacity/.test(menu.transition) && /transform/.test(menu.transition), menu.transition);
    check('the menu is fully visible once open', menu.opacity === '1', menu.opacity);
    check('the menu sits directly above the launcher', menu.bottom <= menu.fabTop + 1, `menu bottom ${menu.bottom.toFixed(1)} / launcher top ${menu.fabTop.toFixed(1)}`);
    check('exactly three options are offered', menu.items.length === 3, String(menu.items.length));
    check('the options are labelled Facebook, WhatsApp and Live Chat',
      menu.items.map((i) => i.text).join(',') === 'Facebook,WhatsApp,Live Chat', menu.items.map((i) => i.text).join(','));
    check('every option has a brand icon', menu.items.every((i) => i.icon));
    check('the labels stay readable', menu.items.every((i) => i.fontSize >= 12), menu.items.map((i) => i.fontSize).join('/'));
    check('the menu stays inside the viewport',
      menu.top >= 0 && menu.left >= 0 && menu.right <= menu.vw && menu.bottom <= menu.vh,
      `top ${menu.top.toFixed(0)} right ${menu.right.toFixed(0)} / vw ${menu.vw}`);
    check('opening the menu does not open the chat', await page.locator('#sh-schat-sheet').isHidden());
    await snap(page, 'support-launcher-desktop-1280-open');
  });

  await area('4. Facebook and WhatsApp really navigate to the configured destinations', async () => {
    const menu = await menuGeometry(page);
    const [facebook, whatsapp, live] = menu.items;
    check('Facebook points at the configured Messenger link', facebook.tag === 'a' && facebook.href === FB_URL, String(facebook.href));
    check('WhatsApp points at the configured number with the pre-filled message',
      whatsapp.tag === 'a' && whatsapp.href === `${WA_NORMALISED}?text=${encodeURIComponent(WA_TEXT)}`, String(whatsapp.href));
    check('external options open in a new tab with noopener', [facebook, whatsapp].every((i) => i.target === '_blank' && /noopener/.test(i.rel) && /noreferrer/.test(i.rel)));
    check('Live Chat is a real button, not a link', live.tag === 'button' && !live.href);

    const fbPopup = page.waitForEvent('popup', { timeout: 15000 }).catch(() => null);
    await page.click('#sh-schat-opt-facebook');
    const fbPage = await fbPopup;
    check('clicking Facebook opens the Messenger destination', !!fbPage && fbPage.url().startsWith('https://m.me/'), fbPage ? fbPage.url() : 'no popup');
    if (fbPage) { await fbPage.close(); }

    const waPopup = page.waitForEvent('popup', { timeout: 15000 }).catch(() => null);
    await page.click('#sh-schat-opt-whatsapp');
    const waPage = await waPopup;
    check('clicking WhatsApp opens the wa.me destination', !!waPage && waPage.url().startsWith('https://wa.me/8801712345678'), waPage ? waPage.url() : 'no popup');
    if (waPage) { await waPage.close(); }
  });

  await area('5. The menu closes on selection, Escape and an outside click', async () => {
    await page.waitForFunction(() => document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 }).catch(() => null);
    if (!(await page.locator('#sh-schat-menu').evaluate((el) => el.classList.contains('is-open')))) { await openMenu(page); }
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
    const afterEscape = await fabGeometry(page);
    check('Escape closes the menu', afterEscape.expanded === 'false');
    check('Escape returns focus to the launcher', await page.evaluate(() => document.activeElement?.id === 'sh-schat-fab'));
    check('the shop page is still visible after closing the menu', await page.locator('#sh-schat-sheet').isHidden());

    await openMenu(page);
    await page.mouse.click(20, 200);
    await page.waitForFunction(() => !document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
    check('an outside click closes the menu without opening the chat',
      (await fabGeometry(page)).expanded === 'false' && await page.locator('#sh-schat-sheet').isHidden());
  });

  await area('6. Live Chat opens the existing chat and still answers', async () => {
    const apiLog = [];
    page.on('response', async (res) => {
      if (!res.url().includes('/api/support.php')) { return; }
      let body = '';
      try { body = (await res.text()).slice(0, 300); } catch { /* body unavailable */ }
      apiLog.push(`${res.status()} ${body.replace(/\s+/g, ' ')}`);
    });
    await openMenu(page);
    const startCall = page.waitForResponse(
      (res) => res.url().includes('/api/support.php') && res.request().method() === 'POST',
      { timeout: 20000 },
    ).catch(() => null);
    await page.click('#sh-schat-opt-chat');
    await page.waitForFunction(() => document.querySelector('#sh-schat-sheet')?.hidden === false, null, { timeout: 10000 });
    const startResponse = await startCall;
    check('the existing chat start call succeeds', !!startResponse && startResponse.status() === 200,
      apiLog.join(' | ').slice(0, 300));
    check('Live Chat opens the existing panel', await page.locator('#sh-schat-sheet').isVisible());
    check('the menu folds away when the chat opens',
      !(await page.locator('#sh-schat-menu').evaluate((el) => el.classList.contains('is-open')))
      && (await fabGeometry(page)).expanded === 'false');
    const chrome = await page.evaluate(() => ({
      title: document.querySelector('#sh-schat-title')?.textContent.trim() || '',
      newBtn: !!document.querySelector('#sh-schat-new'),
      closeBtn: !!document.querySelector('#sh-schat-close'),
      handoff: !!document.querySelector('#sh-schat-handoff'),
      handoffText: document.querySelector('#sh-schat-handoff')?.textContent.trim() || '',
      input: !!document.querySelector('#sh-schat-input'),
      send: !!document.querySelector('#sh-schat-send'),
    }));
    check('the existing chat header, new-conversation and close controls are intact',
      /Customer Support/.test(chrome.title) && chrome.newBtn && chrome.closeBtn, chrome.title);
    check('Talk to a human is still offered', chrome.handoff && /Talk to a human/.test(chrome.handoffText), chrome.handoffText);
    check('the composer is still there', chrome.input && chrome.send);

    await page.fill('#sh-schat-input', 'Do you deliver to Dhaka?');
    await page.click('#sh-schat-send');
    await page.waitForFunction(() => {
      const bubbles = document.querySelectorAll('#sh-schat-messages .sh-schat-msg');
      return bubbles.length >= 2;
    }, null, { timeout: 30000 }).catch(() => null);
    const thread = (await page.textContent('#sh-schat-messages').catch(() => '') || '').replace(/\s+/g, ' ');
    check('the customer message and a reply both render',
      (await page.locator('#sh-schat-messages .sh-schat-msg').count()) >= 2,
      thread.slice(0, 160) + ' :: ' + apiLog.slice(-2).join(' | ').slice(0, 200));
    check('the existing chat answers the customer after the launcher change',
      /Dhaka/i.test(thread) && thread.length > 60, thread.slice(0, 140));
    check('no message is shown as failed', await page.locator('#sh-schat-messages .is-failed').count() === 0);
    await snap(page, 'support-launcher-desktop-1280-chat');

    await page.click('#sh-schat-handoff');
    await page.waitForFunction(() => document.querySelector('#sh-schat-sheet')?.getAttribute('data-human') === '1', null, { timeout: 20000 }).catch(() => null);
    const human = await page.evaluate(() => ({
      human: document.querySelector('#sh-schat-sheet')?.getAttribute('data-human'),
      notice: document.querySelector('.sh-schat-notice')?.textContent.trim() || '',
      handoffHidden: document.querySelector('#sh-schat-handoff')?.hidden,
    }));
    check('Talk to a human still escalates the same conversation',
      human.human === '1' && human.handoffHidden === true && human.notice.length > 0, human.notice.slice(0, 120));
    await snap(page, 'support-launcher-desktop-1280-handoff');
    await report(errors, 'the desktop storefront session');
  });

  // The chat opened above must not leak into the other viewports.
  await page.evaluate(() => {
    document.querySelector('#sh-schat-sheet')?.setAttribute('hidden', 'hidden');
  }).catch(() => null);
  await context.close();
}

/* ---------------------------------------------------------------------------
 * Phase 3 — phone widths
 * ------------------------------------------------------------------------ */
async function phaseMobile(browser) {
  for (const width of MOBILE) {
    await area(`${width}px phone`, async () => {
      const context = await browser.newContext({ viewport: { width, height: 720 } });
      const page = await context.newPage();
      const errors = await watch(page);
      await page.goto(url('/index.php'), { waitUntil: 'load' });
      await settle(page);

      const fab = await fabGeometry(page);
      check(`${width}px: the launcher is still a circle`, !!fab && Math.abs(fab.width - fab.height) < 0.6 && fab.width >= 48, fab ? `${fab.width}x${fab.height}` : 'missing');
      check(`${width}px: the launcher keeps a safe right margin`, fab.vw - fab.right >= 10, `${(fab.vw - fab.right).toFixed(1)}px`);
      check(`${width}px: the launcher keeps a safe bottom margin`, fab.vh - fab.bottom >= 10, `${(fab.vh - fab.bottom).toFixed(1)}px`);
      check(`${width}px: no page-wide horizontal scroll`, fab.docScroll <= fab.vw + 1, `${fab.docScroll} / ${fab.vw}`);
      check(`${width}px: the launcher shows no "Support" text`, fab.text === '', JSON.stringify(fab.text));
      await snap(page, `support-launcher-${width}px-closed`);

      await openMenu(page);
      const menu = await menuGeometry(page);
      check(`${width}px: the open menu stays inside the viewport`,
        menu.top >= 0 && menu.left >= 0 && menu.right <= menu.vw && menu.bottom <= menu.vh,
        `top ${menu.top.toFixed(0)} left ${menu.left.toFixed(0)} right ${menu.right.toFixed(0)} bottom ${menu.bottom.toFixed(0)}`);
      check(`${width}px: the menu clears the launcher`, menu.bottom <= menu.fabTop + 1);
      check(`${width}px: the menu never widens the page`, menu.docScroll <= menu.vw + 1, `${menu.docScroll} / ${menu.vw}`);
      check(`${width}px: all three options are present and readable`,
        menu.items.length === 3 && menu.items.every((i) => i.fontSize >= 12), menu.items.map((i) => i.fontSize).join('/'));
      await snap(page, `support-launcher-${width}px-open`);

      await page.keyboard.press('Escape');
      await page.waitForFunction(() => !document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
      check(`${width}px: Escape closes the menu`, (await fabGeometry(page)).expanded === 'false');
      check(`${width}px: the closed menu leaves no overflow`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
      await report(errors, `${width}px`);
      await context.close();
    });
  }
}

/* ---------------------------------------------------------------------------
 * Phase 4 — keyboard and reduced motion
 * ------------------------------------------------------------------------ */
async function phaseKeyboard(browser) {
  await area('Keyboard control', async () => {
    const context = await browser.newContext({ viewport: { width: 390, height: 720 } });
    const page = await context.newPage();
    const errors = await watch(page);
    await page.goto(url('/index.php'), { waitUntil: 'load' });
    await settle(page);

    await page.focus('#sh-schat-fab');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      const s = el ? getComputedStyle(el) : null;
      return { id: el?.id, outline: s ? `${s.outlineStyle} ${s.outlineWidth}` : '' };
    });
    check('the launcher is reachable by keyboard', focused.id === 'sh-schat-fab', focused.id);
    check('the focused launcher shows a visible focus ring', /solid|auto/.test(focused.outline) && !/0px/.test(focused.outline), focused.outline);

    await page.keyboard.press('Enter');
    await page.waitForFunction(() => document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
    check('Enter opens the menu', (await fabGeometry(page)).expanded === 'true');
    check('focus moves into the menu', await page.evaluate(() => document.activeElement?.id === 'sh-schat-opt-facebook'));

    await page.keyboard.press('ArrowDown');
    check('ArrowDown moves to the next option', await page.evaluate(() => document.activeElement?.id === 'sh-schat-opt-whatsapp'));
    await page.keyboard.press('ArrowUp');
    check('ArrowUp moves back', await page.evaluate(() => document.activeElement?.id === 'sh-schat-opt-facebook'));
    await page.keyboard.press('End');
    check('End jumps to the last option', await page.evaluate(() => document.activeElement?.id === 'sh-schat-opt-chat'));
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
    check('Escape closes and refocuses the launcher', await page.evaluate(() => document.activeElement?.id === 'sh-schat-fab'));
    await report(errors, 'the keyboard session');
    await context.close();
  });

  await area('Reduced motion', async () => {
    const context = await browser.newContext({ viewport: { width: 390, height: 720 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    await page.goto(url('/index.php'), { waitUntil: 'load' });
    await settle(page);
    await openMenu(page);
    const menu = await menuGeometry(page);
    check('reduced motion: the menu still opens', menu.open && menu.visibility === 'visible' && menu.opacity === '1');
    check('reduced motion: the slide/fade transition is removed', menu.transition === 'none' || !/transform/.test(menu.transition), menu.transition);
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#sh-schat-menu')?.classList.contains('is-open'), null, { timeout: 5000 });
    check('reduced motion: the menu still closes', (await fabGeometry(page)).expanded === 'false');
    await snap(page, 'support-launcher-reduced-motion-390px');
    await context.close();
  });
}

/* ---------------------------------------------------------------------------
 * Phase 5 — an unconfigured store: unavailable state, Live Chat intact
 * ------------------------------------------------------------------------ */
async function phaseUnconfigured(browser) {
  const adminContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await adminContext.newPage();

  await area('Unconfigured channels degrade honestly, Live Chat keeps working', async () => {
    await admin.goto(url('/admin/login.php'), { waitUntil: 'load' });
    await admin.fill('#ad-email', ADMIN_EMAIL);
    await admin.fill('#ad-pass', ADMIN_PASSWORD);
    await Promise.all([
      admin.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      admin.click('form button[type="submit"]'),
    ]);
    await admin.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    await admin.fill('#support-facebook', '');
    await admin.fill('#support-whatsapp', '');
    await admin.fill('#support-whatsapp-message', '');
    await Promise.all([
      admin.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      admin.click('button:has-text("Save support settings")'),
    ]);
    const states = await admin.evaluate(() => [...document.querySelectorAll('.sh-support-settings__state')].map((el) => el.textContent.trim()));
    check('clearing both links is accepted', states.some((s) => /Facebook: not configured/.test(s)) && states.some((s) => /WhatsApp: not configured/.test(s)), states.join(' / ').slice(0, 160));

    const context = await browser.newContext({ viewport: { width: 390, height: 720 } });
    const page = await context.newPage();
    const errors = await watch(page);
    await page.goto(url('/index.php'), { waitUntil: 'load' });
    await settle(page);
    await openMenu(page);
    const menu = await menuGeometry(page);
    check('an unconfigured store still shows three options', menu.items.length === 3);
    check('the unconfigured options are marked unavailable',
      menu.items[0].disabled && menu.items[1].disabled && !menu.items[0].href && !menu.items[1].href);
    check('Live Chat stays enabled', !menu.items[2].disabled);
    await snap(page, 'support-launcher-unconfigured-390px-open');
    await page.click('#sh-schat-opt-chat');
    await page.waitForFunction(() => document.querySelector('#sh-schat-sheet')?.hidden === false, null, { timeout: 10000 });
    check('Live Chat opens even when nothing else is configured', await page.locator('#sh-schat-sheet').isVisible());
    await report(errors, 'the unconfigured storefront');
    await context.close();
  });

  // Leave the store configured so a re-run starts from the same state.
  await area('Restore the configuration for the next run', async () => {
    await admin.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    await admin.fill('#support-facebook', FB_URL);
    await admin.fill('#support-whatsapp', WA_NUMBER);
    await admin.fill('#support-whatsapp-message', WA_TEXT);
    await Promise.all([
      admin.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => null),
      admin.click('button:has-text("Save support settings")'),
    ]);
    await admin.goto(url('/admin/support-settings.php'), { waitUntil: 'load' });
    check('the store is left configured', (await admin.inputValue('#support-facebook')) === FB_URL);
  });

  await adminContext.close();
}

/* ------------------------------------------------------------------------- */
const browser = await chromium.launch();
console.log(`ShopHaat support launcher — real browser run against ${BASE}`);
try {
  await phaseAdmin(browser);
  await phaseDesktop(browser);
  await phaseMobile(browser);
  await phaseKeyboard(browser);
  await phaseUnconfigured(browser);
} finally {
  await browser.close();
}

const failed = checks.filter((c) => !c.ok);
console.log(`\nSupport launcher UI: ${checks.length - failed.length} passed, ${failed.length} failed`);
if (failed.length) {
  console.log('Failures:');
  for (const f of failed) { console.log(`  - ${f.name}${f.detail ? ' — ' + f.detail : ''}`); }
}
console.log(`Screenshots: ${SHOTS}`);
process.exit(failed.length ? 1 : 0);
