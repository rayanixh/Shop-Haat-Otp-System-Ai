/*
 * Browser-level regression check that the centralized support policy remains
 * consistent across protected customer journeys on a configured storefront.
 *
 * Run with the project's normal seeded PHP + MySQL browser environment:
 *   SH_SUPPORT_BASE_URL=http://127.0.0.1:8080 node tests/browser/support-visibility-pages.mjs
 */
import { chromium } from 'playwright';

const base = (process.env.SH_SUPPORT_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const blockedPages = [
  ['/cart.php', 'Cart'],
  ['/orders.php', 'Orders'],
  ['/order-details.php?id=1', 'Order details'],
  ['/track.php', 'Order tracking'],
  ['/account.php', 'Profile/account'],
  ['/addresses.php', 'Address book'],
  ['/login.php', 'Login'],
  ['/register.php', 'Signup'],
  ['/forgot-password.php', 'Password reset request'],
  ['/reset-password.php', 'Password reset'],
  ['/verify-email.php', 'Email verification'],
  ['/otp.php', 'OTP verification'],
];

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
let passed = 0;
let failed = 0;
try {
  for (const [route, label] of blockedPages) {
    let response = null;
    try { response = await page.goto(base + route, { waitUntil: 'load', timeout: 30000 }); }
    catch (error) { console.log(`FAIL ${label}: navigation failed — ${String(error).slice(0, 180)}`); failed++; continue; }
    const result = await page.evaluate(() => ({
      launcher: !!document.querySelector('#sh-schat-fab'),
      menu: !!document.querySelector('#sh-schat-menu'),
      popup: !!document.querySelector('#sh-schat-sheet'),
      supportLink: [...document.querySelectorAll('a[href]')].some((a) => /(?:^|\/)support\.php(?:$|[?#])/.test(a.getAttribute('href') || '')),
      messenger: !!document.querySelector('a[href*="m.me/"],a[href*="messenger.com/"]'),
      whatsapp: !!document.querySelector('a[href*="wa.me/"],a[href*="whatsapp.com/"]'),
    }));
    const ok = !!response && response.status() < 400
      && !result.launcher && !result.menu && !result.popup && !result.supportLink
      && !result.messenger && !result.whatsapp;
    console.log(`${ok ? 'PASS' : 'FAIL'} ${label} (${response ? response.status() : 'no response'}) — ${JSON.stringify(result)}`);
    if (ok) { passed++; } else { failed++; }
  }
} finally {
  await browser.close();
}
console.log(`\nRestricted-page support visibility: ${passed} passed, ${failed} failed`);
if (failed) { process.exitCode = 1; }
