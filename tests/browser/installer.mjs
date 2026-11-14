/**
 * Real browser verification of the ShopHaat installation wizard.
 *
 * Everything asserted here is observed in Chromium against a real PHP web
 * server and a real MySQL database:
 *
 *   fresh              the requirements screen, the refresh action, the phone
 *                      viewport matrix, and a complete installation driven
 *                      through the redesigned form
 *   repair             the restricted repair screen on an installed store
 *   requirements-fail  the blocked screen, produced by making config/, uploads/
 *                      and logs/ genuinely unwritable rather than by faking a
 *                      status in the markup
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

const OPTIONAL_NAMES = ['mbstring', 'DOM / libxml', 'cURL', 'OpenSSL', 'GD', 'fileinfo', 'intl'];
const REQUIRED_NAMES = [
  'PHP version', 'PDO extension', 'pdo_mysql driver', 'JSON extension',
  '/config directory', '/uploads directory', '/logs directory',
];
const STEP_LABELS = [
  'Server requirements', 'Database configuration', 'Store configuration', 'Installation complete',
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
  const seen = { console: [], pageErrors: [], requests: [], csp: [] };
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

/** Every element must stay inside the viewport: no sideways scroll, no clipping. */
async function overflowScan(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const bad = [];
    for (const el of document.querySelectorAll('body *')) {
      if (el.classList.contains('sprite')) { continue; }
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) { continue; }
      if (rect.right > vw + 1 || rect.left < -1) {
        const cls = typeof el.className === 'string' ? el.className.split(' ')[0] : '';
        bad.push(`${el.tagName.toLowerCase()}${cls ? '.' + cls : ''} [${rect.left.toFixed(0)}–${rect.right.toFixed(0)}] vw=${vw}`);
      }
    }
    return { docScroll: document.documentElement.scrollWidth, bodyScroll: document.body.scrollWidth, innerWidth: vw, bad: bad.slice(0, 6), total: bad.length };
  });
}

async function phoneMatrix(browser, label, pathFor = () => '/install/') {
  for (const width of MOBILE) {
    const page = await browser.newPage({ viewport: { width, height: 720 } });
    await page.goto(url(pathFor()), { waitUntil: 'load' });
    const scan = await overflowScan(page);
    check(`${label} @${width}px: the page does not scroll sideways`, scan.docScroll <= scan.innerWidth + 1 && scan.bodyScroll <= scan.innerWidth + 1,
      `scrollWidth ${scan.docScroll}/${scan.bodyScroll}, viewport ${scan.innerWidth}`);
    check(`${label} @${width}px: no element overflows the viewport`, scan.total === 0, scan.bad.join(' | '));
    const tap = await page.evaluate(() => {
      const targets = [...document.querySelectorAll('.btn, .radio, .input')].map((el) => {
        const r = el.getBoundingClientRect();
        return { tag: el.tagName.toLowerCase(), cls: (el.className || '').toString().split(' ')[0], h: Math.round(r.height) };
      });
      return targets.filter((t) => t.h > 0 && t.h < 40);
    });
    check(`${label} @${width}px: every control is a comfortable touch target (>= 40px tall)`, tap.length === 0,
      tap.slice(0, 4).map((t) => `${t.tag}.${t.cls}=${t.h}px`).join(' | '));
    const squeezed = await page.evaluate(() => {
      const bad = [];
      for (const el of document.querySelectorAll('.opt__name, .req__name, .step__label, .badge, .chip')) {
        const rect = el.getBoundingClientRect();
        if (rect.width > 0 && rect.width < 48) {
          bad.push(`${(el.className || '').toString().split(' ')[0]}="${el.textContent.trim().slice(0, 14)}" ${Math.round(rect.width)}px`);
        }
      }
      return bad.slice(0, 5);
    });
    check(`${label} @${width}px: no label is squeezed into a sliver`, squeezed.length === 0, squeezed.join(' | '));
    const optCols = await page.$eval('.opt-grid', (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length);
    check(`${label} @${width}px: the optional extensions are a single column`, optCols === 1, `${optCols} column(s)`);
    await snap(page, `installer-${label}-${width}px`);
    await page.close();
  }
}

// ---------------------------------------------------------------------------
// Phase: fresh install
// ---------------------------------------------------------------------------
async function phaseFresh(browser) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = watch(page);

  await area('1. Server requirements show what this server really reports', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the installer responds with HTTP 200', res && res.status() === 200, `status ${res && res.status()}`);

    const h1 = (await page.textContent('h1') || '').trim();
    check('the page presents the installation title', h1 === 'Install your store', h1);

    const steps = await page.$$eval('.steps .step', (els) => els.map((el) => {
      // The screen-reader state suffix is part of the accessible name but not
      // part of the visible step label.
      const label = el.querySelector('.step__label').cloneNode(true);
      label.querySelectorAll('.sr-only').forEach((node) => node.remove());
      return { label: label.textContent.trim(), cls: el.className, current: el.getAttribute('aria-current') };
    }));
    check('the step indicator lists the four real steps', JSON.stringify(steps.map((s) => s.label)) === JSON.stringify(STEP_LABELS),
      steps.map((s) => s.label).join(' > '));
    check('step 1 is complete because every required check passed', /step--complete/.test(steps[0].cls), steps[0].cls);
    check('step 2 is the current step', steps[1].current === 'step' && /step--current/.test(steps[1].cls), `${steps[1].cls} aria-current=${steps[1].current}`);
    check('steps 3 and 4 are still pending', /step--pending/.test(steps[2].cls) && /step--pending/.test(steps[3].cls));

    const progress = await page.$eval('.progress', (el) => ({
      now: el.getAttribute('aria-valuenow'),
      width: el.querySelector('.progress__fill').style.width,
    }));
    check('the progress bar reports the state that was really reached (1 of 4 = 25%)',
      progress.now === '25' && progress.width === '25%', `aria-valuenow=${progress.now} width=${progress.width}`);
    const meta = (await page.textContent('.progress__meta') || '').replace(/\s+/g, ' ');
    check('the progress line names the current step', /Step 2 of 4 . Database configuration/.test(meta), meta.slice(0, 80));

    const rows = await page.$$eval('.req-list .req', (els) => els.map((el) => {
      // The threshold that sits inside the name cell ("8.1 or newer") is not
      // part of the name itself.
      const name = el.querySelector('.req__name').cloneNode(true);
      name.querySelectorAll('.req__need').forEach((node) => node.remove());
      return {
        name: name.textContent.trim(),
        value: el.querySelector('.req__value').textContent.trim(),
        badge: el.querySelector('.req__badge').textContent.trim(),
        badgeCls: el.querySelector('.req__badge').className,
      };
    }));
    check('all seven required checks are listed', rows.length === 7, rows.map((r) => r.name).join(', '));
    check('the seven required checks are the documented ones', JSON.stringify(rows.map((r) => r.name)) === JSON.stringify(REQUIRED_NAMES),
      rows.map((r) => r.name).join(' | '));
    check('every required row shows a detected value', rows.every((r) => r.value.length > 0), rows.map((r) => `${r.name}=${r.value}`).join(', '));
    check('every required row is reported as passed (this host meets the requirements)', rows.every((r) => r.badge === 'Passed'),
      rows.filter((r) => r.badge !== 'Passed').map((r) => `${r.name}:${r.badge}`).join(', '));
    if (EXPECT_PHP) {
      check('the PHP row shows the version the server actually runs, not a hardcoded string',
        rows[0].value === EXPECT_PHP, `${rows[0].value} vs expected ${EXPECT_PHP}`);
    } else {
      check('the PHP row shows a real version string', /^\d+\.\d+\.\d+/.test(rows[0].value), rows[0].value);
    }

    const verdict = (await page.textContent('.verdict') || '').trim();
    check('the overall verdict says the server is ready', verdict === 'Ready to install', verdict);

    const metrics = await page.$$eval('.summary .metric', (els) => els.map((el) => ({
      label: el.querySelector('.metric__label').textContent.trim(),
      value: el.querySelector('.metric__value').textContent.trim(),
    })));
    const byLabel = Object.fromEntries(metrics.map((m) => [m.label, m.value]));
    check('the summary counts the required passes from the rendered rows', byLabel['Required passed'] === '7/7', JSON.stringify(byLabel));
    check('the summary reports no failed required check', byLabel['Required failed'] === '0', byLabel['Required failed']);
    check('the summary reports overall readiness', byLabel['Overall readiness'] === 'Ready', byLabel['Overall readiness']);
    check('the summary counts the optional extensions from the rendered grid',
      /^\d\/7$/.test(byLabel['Optional available'] || ''), byLabel['Optional available']);
  });

  await area('2. Optional extensions are separate, complete and never blocking', async () => {
    const opts = await page.$$eval('.opt-grid .opt', (els) => els.map((el) => ({
      name: el.querySelector('.opt__name').textContent.trim(),
      purpose: (el.querySelector('.opt__purpose') || {}).textContent?.trim() || '',
      chip: (el.querySelector('.chip') || {}).textContent?.trim() || '',
    })));
    check('all seven optional extensions are listed', opts.length === 7, opts.map((o) => o.name).join(', '));
    check('the optional extension names match the shipped fallbacks', JSON.stringify(opts.map((o) => o.name)) === JSON.stringify(OPTIONAL_NAMES),
      opts.map((o) => o.name).join(' | '));
    check('every optional extension explains its purpose', opts.every((o) => o.purpose.length > 6), opts.map((o) => `${o.name}:${o.purpose}`).join(', '));
    check('every optional extension carries an availability chip', opts.every((o) => o.chip === 'Available' || o.chip === 'Fallback'),
      opts.map((o) => `${o.name}:${o.chip}`).join(', '));
    const heading = (await page.textContent('#optional-title') || '').trim();
    check('the section is labelled as optional', heading === 'Optional extensions', heading);
    const cols = await page.$eval('.opt-grid', (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length);
    check('the optional grid is two columns on desktop', cols === 2, `${cols} column(s)`);
    const usingFallback = opts.filter((o) => o.chip === 'Fallback').length;
    const errorBadges = await page.$$eval('.opt-grid .badge--bad, .opt-grid .metric--bad', (els) => els.length);
    check('no optional extension is presented as an error', errorBadges === 0,
      `${opts.length - usingFallback} available, ${usingFallback} using a documented fallback, ${errorBadges} error badge(s)`);
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
    const inlineStyles = await page.$$eval('[style]', (els) => els.map((el) => el.tagName.toLowerCase()));
    check('only the dynamic progress width is an inline style', inlineStyles.length === 1 && inlineStyles[0] === 'div',
      `${inlineStyles.length}: ${inlineStyles.slice(0, 5).join(', ')}`);
    const css = await page.$eval('style', (el) => el.textContent || '');
    check('the stylesheet never hides content with overflow:hidden', !/overflow\s*:\s*hidden/i.test(css),
      (css.match(/overflow\s*:\s*hidden[^;]*/i) || [''])[0]);
    const html = await page.content();
    check('no server path leaks into the page', !/\/home\/[a-z]|\/var\/www|C:\\\\inetpub/.test(html),
      (html.match(/\/home\/[a-z]|\/var\/www/g) || []).slice(0, 2).join(', '));
  });

  await area('4. The action area offers a real refresh and a real continue', async () => {
    const refresh = page.locator('.actionbar__buttons form button');
    check('the action area has a Refresh requirements button', await refresh.count() === 1);
    const cont = page.locator('button[form="install-form"]');
    check('the action area has the primary Continue button', await cont.count() === 1);
    check('Continue is enabled because every required check passed', await cont.isEnabled());
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), refresh.click()]);
    const after = (await page.textContent('.panel__sub') || '').match(/Checked on this server at ([^.]+)\./);
    check('Refresh requirements re-runs the checks on the server', !!after && after[1].trim().length > 0, `checked at ${after ? after[1].trim() : 'unknown'}`);
    check('the refreshed page still reports a ready server', (await page.textContent('.verdict') || '').trim() === 'Ready to install');
    await snap(page, 'installer-desktop-1280px');
    report(errors, 'the requirements screen');
  });

  await area('5. Phone viewports 320–430px', async () => {
    await phoneMatrix(browser, 'requirements');
  });

  await area('6. A complete installation runs through the redesigned form', async () => {
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
      page.click('button[form="install-form"]'),
    ]);
    const done = await page.waitForSelector('.panel--done', { timeout: 180000 }).catch(() => null);
    if (!done) {
      const failure = (await page.textContent('.callout--bad').catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 300);
      check('the installation completed through the redesigned form', false, failure || 'no completion panel appeared');
      await snap(page, 'installer-install-failed');
      return;
    }
    check('the installation completed through the redesigned form', true);

    const h1 = (await page.textContent('h1') || '').trim();
    check('the completion screen is titled Installation complete', h1 === 'Installation complete', h1);
    const steps = await page.$$eval('.steps .step', (els) => els.map((el) => el.className));
    check('all four steps are shown as complete', steps.length === 4 && steps.every((c) => /step--complete/.test(c)), steps.join(' | '));
    const progress = await page.$eval('.progress', (el) => el.getAttribute('aria-valuenow'));
    check('the progress bar is at 100% and not before', progress === '100', `aria-valuenow=${progress}`);
    const notice = (await page.textContent('.callout--ok').catch(() => '') || '').trim();
    check('the completion notice is rendered (it used to be computed and never shown)',
      /Installation completed successfully/.test(notice), notice.slice(0, 90));
    const warn = (await page.textContent('.done__notice') || '').replace(/\s+/g, ' ');
    check('the completion screen still tells the owner to delete /install', /Delete the \/install folder/.test(warn), warn.slice(0, 80));
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

    // The completion screen at phone widths: the same document, re-laid out.
    for (const width of [320, 430]) {
      await page.setViewportSize({ width, height: 720 });
      await page.waitForTimeout(120);
      const scan = await overflowScan(page);
      check(`the completion screen @${width}px: no sideways scroll`, scan.docScroll <= scan.innerWidth + 1, `${scan.docScroll}/${scan.innerWidth}`);
      check(`the completion screen @${width}px: no element overflows`, scan.total === 0, scan.bad.join(' | '));
      await snap(page, `installer-done-${width}px`);
    }
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  report(errors, 'the whole fresh-install session');
  await page.close();
}

// ---------------------------------------------------------------------------
// Phase: blocked requirements (real unwritable directories)
// ---------------------------------------------------------------------------
async function phaseRequirementsFail(browser) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = watch(page);

  await area('1. Genuinely failed checks block the installation', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the installer still responds with HTTP 200 when checks fail', res && res.status() === 200, `status ${res && res.status()}`);
    const verdict = (await page.textContent('.verdict') || '').trim();
    check('the verdict asks for action', verdict === 'Action required', verdict);

    const rows = await page.$$eval('.req-list .req', (els) => els.map((el) => ({
      name: el.querySelector('.req__name').textContent.trim(),
      value: el.querySelector('.req__value').textContent.trim(),
      badge: el.querySelector('.req__badge').textContent.trim(),
    })));
    const failed = rows.filter((r) => r.badge === 'Failed');
    check('the unwritable directories are reported as failed', failed.length >= 3, failed.map((r) => `${r.name}=${r.value}`).join(', '));
    check('a failed row states what was detected', failed.every((r) => /Not writable/.test(r.value)), failed.map((r) => r.value).join(', '));

    const metrics = await page.$$eval('.summary .metric', (els) => Object.fromEntries(els.map((el) => [
      el.querySelector('.metric__label').textContent.trim(), el.querySelector('.metric__value').textContent.trim(),
    ])));
    check('the summary counts the failures', metrics['Required failed'] === String(failed.length), JSON.stringify(metrics));
    check('overall readiness is blocked', metrics['Overall readiness'] === 'Blocked', metrics['Overall readiness']);
    const cont = page.locator('button[form="install-form"]');
    check('Continue is disabled while a required check fails', await cont.isDisabled());

    const progress = await page.$eval('.progress', (el) => el.getAttribute('aria-valuenow'));
    check('progress stays at 0% when step 1 has not passed', progress === '0', `aria-valuenow=${progress}`);
    const steps = await page.$$eval('.steps .step', (els) => els.map((el) => ({ cls: el.className, current: el.getAttribute('aria-current') })));
    check('step 1 is marked as the current step', steps[0].current === 'step' && /step--current/.test(steps[0].cls), steps[0].cls);

    const fixes = await page.textContent('.callout--bad') || '';
    check('the failed checks are explained in plain language', failed.every((r) => fixes.includes(r.name)), fixes.replace(/\s+/g, ' ').slice(0, 120));
    check('the fix text names no server path', !/\/home\/[a-z]|\/var\/www/.test(fixes));
    await snap(page, 'installer-blocked-desktop-1280px');
    report(errors, 'the blocked screen');
  });

  await area('2. The blocked screen is usable at 320–430px', async () => {
    await phoneMatrix(browser, 'blocked');
  });

  await page.close();
}

// ---------------------------------------------------------------------------
// Phase: repair mode on an installed store
// ---------------------------------------------------------------------------
async function phaseRepair(browser) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = watch(page);

  await area('1. An installed store serves the restricted repair screen', async () => {
    const res = await page.goto(url('/install/'), { waitUntil: 'load' });
    check('the repair screen responds with HTTP 200', res && res.status() === 200, `status ${res && res.status()}`);
    const h1 = (await page.textContent('h1') || '').trim();
    check('the screen is titled Repair this store', h1 === 'Repair this store', h1);
    const chip = (await page.textContent('.chip--mode') || '').trim();
    check('the header names repair mode', /Repair mode/.test(chip), chip);
    check('the restricted panel is present', (await page.textContent('#repair-title') || '').includes('already installed'));
    check('the unlock panel is still offered to the owner', await page.locator('#unlock-form').count() === 1);
    const steps = await page.$$eval('.steps .step', (els) => els.map((el) => el.className));
    check('store configuration is shown as not part of a repair', /step--skipped/.test(steps[2]), steps[2]);
    // A repair works over the saved connection: the page must not offer a way
    // to enter different database credentials, and it must not echo the stored
    // password back into the markup.
    const credentialFields = await page.$$eval('#repair-form input', (els) => els
      .map((el) => el.getAttribute('name') || '')
      .filter((name) => ['db_host', 'db_name', 'db_user', 'db_pass'].includes(name)));
    check('the repair form accepts no database credentials', credentialFields.length === 0, credentialFields.join(', '));
    const repairHtml = await page.content();
    check('the saved database password is never written into the page',
      !/not-a-real-password|root_test_password/.test(repairHtml));
    const primary = page.locator('button[form="repair-form"]');
    check('the primary action repairs the database instead of installing', (await primary.textContent() || '').includes('Repair database tables'));
    check('the repair action is enabled', await primary.isEnabled());
    await snap(page, 'installer-repair-desktop-1280px');
  });

  await area('2. The repair runs over the saved connection', async () => {
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }).catch(() => null),
      page.click('button[form="repair-form"]'),
    ]);
    const done = await page.waitForSelector('.panel--done', { timeout: 60000 }).catch(() => null);
    const bad = (await page.textContent('.callout--bad').catch(() => '') || '').replace(/\s+/g, ' ').slice(0, 200);
    check('the repair completed', !!done, done ? '' : bad);
    if (done) {
      const notice = (await page.textContent('.callout--ok').catch(() => '') || '').replace(/\s+/g, ' ');
      check('the repair reports what it did', /Database repaired against/.test(notice), notice.slice(0, 100));
      check('the repair says the administrator was left untouched', /administrator account was left untouched/.test(notice), notice.slice(0, 140));
      const store = await page.request.get(url('/index.php'));
      check('the store still loads after the repair', store.status() === 200, `status ${store.status()}`);
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
