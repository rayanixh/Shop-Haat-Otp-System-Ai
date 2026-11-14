#!/usr/bin/env node
/**
 * Static UI/UX audit regression checks. These cover the source-level root
 * causes that the browser layout audit exercised: no masked page overflow,
 * bounded flex/grid tracks, long dynamic text wrapping, the removed Support
 * subtitle, the repaired wishlist/placeholder routes and the concise admin
 * guide copy — without requiring MySQL, a live provider or a real browser.
 *
 *   node tests/ui-ux-audit.mjs
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (p) => readFileSync(join(root, p), 'utf8');
let passed = 0;
let failed = 0;
const ok = (condition, label) => {
  if (condition) {
    passed += 1;
    console.log('  ok  ' + label);
  } else {
    failed += 1;
    console.log('  FAIL  ' + label);
  }
};

const widget = read('includes/support-widget.php');
const chat = read('assets/js/support-chat.js');
const appCss = read('assets/css/app.css');
const supportCss = read('assets/css/support.css');
const adminCss = read('assets/css/support-admin.css');
const email = read('admin/email.php');
const supportSettings = read('admin/support-settings.php');
const wishlist = read('wishlist.php');
const placeholder = read('assets/images/placeholder.svg');
const allCss = appCss + '\n' + supportCss + '\n' + adminCss;

// 1. Customer Support subtitle is gone; no replacement explanatory sentence.
ok(!/Replies use live store data|never guessed|live store data/i.test(widget), 'support popup has no removed subtitle or replacement explanation');
ok(!/sh-schat-sub/.test(widget + chat + allCss), 'no subtitle element, selector or JS reference remains');
ok(/data-human="0"/.test(widget), 'human mode uses a non-visible sheet state');
ok(/id="sh-schat-title"/.test(widget), 'Customer Support title is preserved');
ok(/sh-schat-close/.test(widget) && /sh-schat-new/.test(widget), 'close and new-conversation buttons remain');
ok(/sh-schat-messages/.test(widget), 'message area remains');
ok(/sh-schat-form/.test(widget) && /sh-schat-input/.test(widget) && /sh-schat-send/.test(widget), 'input and Send button remain');
ok(/sh-schat-handoff/.test(widget) && /Talk to a human/.test(widget), 'Talk to a human button remains');
ok(/action/.test(chat) && /send/.test(chat) && /human/.test(chat), 'AI send and human handoff logic remain');
ok(/conversation/.test(chat) && /renderAll\(data\.messages\)/.test(chat), 'conversation message history rendering remains');
ok(/sh-schat-new/.test(chat) && /Escape/.test(chat), 'close/new conversation handling remains');

// 2. Root-cause CSS: no page-level hidden overflow, bounded tracks and wrapping.
const bodyRule = (appCss.match(/\bbody\s*\{[^}]*\}/) || [''])[0];
ok(!!bodyRule && !/overflow-x\s*:\s*hidden/i.test(bodyRule), 'body does not mask horizontal scrolling with overflow-x:hidden');
ok(/box-sizing\s*:\s*border-box/.test(appCss), 'global border-box sizing remains');
ok(/min-width\s*:\s*0/.test(appCss), 'flex/grid children can shrink below min-content');
ok(/max-width\s*:\s*100%/.test(appCss), 'dynamic content is bounded to its container');
ok(/overflow-wrap\s*:\s*anywhere/.test(appCss), 'long product names, URLs and prices wrap');
ok(/minmax\(0,\s*1fr\)/.test(appCss), 'responsive grid tracks use minmax(0, 1fr)');
ok(/table-layout\s*:\s*fixed/.test(appCss), 'customer tables distribute columns inside their own width');
ok(/env\(safe-area-inset-bottom/.test(appCss), 'bottom-anchored UI respects safe-area insets');
ok(!/\.sh-schat-sub/.test(allCss), 'no orphaned subtitle CSS remains');

// 3. Repaired concrete routes/assets.
ok(existsSync(join(root, 'wishlist.php')), 'wishlist.php exists for header/footer/account links');
ok(existsSync(join(root, 'assets/images/placeholder.svg')), 'placeholder.svg exists for product fallbacks');
ok(/<svg[\s>]/.test(placeholder) && /viewBox=/.test(placeholder), 'placeholder is a scalable SVG');
ok(/FROM wishlist w/.test(wishlist) && /JOIN products p/.test(wishlist), 'wishlist page uses the existing wishlist/products tables');
ok(!/CREATE TABLE|ALTER TABLE|INSERT INTO|MIGRATION/i.test(wishlist), 'wishlist page adds no schema, migration or SQL data');
ok(/sh_require_login/.test(wishlist), 'wishlist is authentication-protected');
ok(/account-nav\.php/.test(wishlist), 'wishlist keeps the existing account navigation');
ok(/sh-product-grid/.test(wishlist) && /product-card\.php/.test(wishlist), 'wishlist reuses existing product cards');
ok(/sh-empty/.test(wishlist), 'wishlist has an empty state');
ok(/sh_url\('admin\/guides\/smtp\.php'\)/.test(email), 'SMTP guide link resolves to the existing PHP file');
ok(/sh_url\('admin\/guides\/firebase\.php'\)/.test(email), 'Firebase guide link resolves to the existing PHP file');
ok(!/sh_url\('admin\/guides\/(smtp|firebase)'\)/.test(email), 'no extensionless admin guide links remain');

// 4. Judgment-based guide cleanup: redundant copy removed, essentials retained.
const removed = [
  'Keep contact and policy answers aligned with the live store',
  'AI never invents a price',
  'AI never invents stock',
  'Stored in the existing product_tags table',
  'Images are validated with',
  'Choose the API you want to connect',
  'Safe to re-run after an update',
  'That is a good sign',
  'One global setting: applies to every customer-facing page',
  'No character limit. Search engines may shorten',
  'Tracking always reflects the saved order and payment records',
  'Changing the zone updates the total after you place the order form submission',
  'The only required input. Everything else is generated from it',
  'Optional. Human support keeps working without SMS',
  'Turning this off does not delete saved conversations',
];
const guideFiles = [
  'admin/security.php', 'admin/support-settings.php', 'admin/ai/products.php',
  'admin/ai/image.php', 'admin/ai/providers.php', 'admin/ai/settings.php',
  'admin/logs.php', 'admin/transition.php', 'admin/seo.php',
  'admin/_seo-fields.php', 'track.php', 'checkout.php',
].map(read).join('\n');
for (const text of removed) ok(!guideFiles.includes(text), 'removed unnecessary guide text: ' + text.slice(0, 52));
ok(/3–80 letters, numbers, hyphens or underscores/.test(guideFiles), 'custom Admin URL validation constraint remains concise');
ok(/The old route is disabled after successful activation/.test(guideFiles), 'custom Admin URL activation warning remains');
ok(/Keys are encrypted at rest and never sent to the browser/.test(guideFiles), 'AI key security warning remains');
ok(/Do not put customer data, passwords, API keys/.test(supportSettings), 'FAQ data-privacy warning remains');
ok(/Success means the provider accepted the request, not handset delivery/.test(supportSettings), 'SMS acceptance-vs-delivery warning remains');
ok(/sh-field__label/.test(guideFiles) && /sh-field__error/.test(guideFiles), 'field labels and validation errors remain');

// 5. Professional admin refinement: compact courier panel, bounded provider
// grid, wrapping AI subnav and the original provider form/action contract.
const verification = read('admin/verification.php');
const providers = read('admin/ai/providers.php');
const aiShared = read('admin/ai/_ai.php');
const actionBlock = providers.match(/<div class="sh-actions sh-aiprov-actions">([\s\S]*?)<\/div>/);
ok(/<section class="sh-panel sh-panel--compact" id="sh-verify-providers">/.test(verification), 'courier panel uses the compact modifier');
ok(/<div class="sh-empty sh-empty--compact">/.test(verification), 'courier empty state is compact');
ok(/<h3 class="sh-empty__title">No providers yet<\/h3>/.test(verification), 'courier empty state has a concise title');
ok(/Add a courier phone-history API to show delivered and returned counts on orders\./.test(verification), 'courier empty state keeps one short explanation');
ok(/<div class="sh-vp-grid">/.test(verification), 'courier provider cards use the bounded grid');
ok(/\.sh-vp-grid \{[^}]*minmax\(min\(100%, 300px\), 1fr\)/.test(appCss), 'courier grid columns are bounded by the viewport');
ok(/\.sh-admin-body \.sh-panel__head > \.sh-panel__title \{ flex: 1 1 auto; min-width: 0; \}/.test(appCss), 'shared panel title no longer forces a 160px flex basis');
ok(/^#sh-verify-providers\.sh-panel--compact \.sh-cv__head \{[\s\S]*?display: grid/m.test(appCss), 'courier head is a content-based grid');
ok(/^\.sh-panel--compact \{ min-height: 0; height: auto; \}/m.test(appCss), 'compact panel uses auto height, not a fixed card height');
ok(/^\.sh-empty--compact \{[\s\S]*?display: flex; flex-direction: row/m.test(appCss), 'compact empty state is a horizontal icon-and-copy row');
ok(/^\.sh-empty--compact \.sh-empty__icon \{[\s\S]*?width: 34px; height: 34px/m.test(appCss), 'compact empty icon is 34px');
ok(/^\.sh-aisubnav \{ display: grid; grid-template-columns: repeat\(auto-fit, minmax\(min\(100%, 148px\), 1fr\)\)/m.test(appCss), 'AI subnav wraps in a bounded grid');
ok(/^\.sh-aisubnav__link \{[\s\S]*?min-height: 40px/m.test(appCss), 'AI subnav links are at least 40px tall');
ok(/@media \(max-width: 620px\) \{[\s\S]*?\.sh-aisubnav__link \{ min-height: 42px/.test(appCss), 'mobile AI subnav links are at least 42px tall');
ok(!/single[\s-]+scrollable[\s-]+row/i.test(aiShared), 'stale single-scrollable-row subnav comment is gone');
ok((providers.match(/<div class="sh-aiprov-formsec">/g) || []).length === 4, 'provider form keeps four sections');
ok((providers.match(/<h3 class="sh-aiprov-formsec__title">/g) || []).length === 4, 'provider form sections use real headings');
ok(actionBlock !== null && (actionBlock[1].match(/<(?:button|a)\b/g) || []).length === 3, 'provider form keeps exactly three actions');
ok(/<button class="sh-btn sh-btn--primary" type="submit">[\s\S]*?Save provider<\/button>/.test(providers), 'Save provider is the single primary submit');
ok(/<button class="sh-btn sh-btn--ghost" type="submit" name="save_action" value="test"/.test(providers), 'Save & test keeps its original submit name/value');
ok(/<a class="sh-btn sh-btn--ghost" href="<\?= e\(sh_url\('admin\/ai\/providers\.php'\)\) \?>">Cancel<\/a>/.test(providers), 'Cancel keeps its original link');
ok(/\$wantsTest = sh_post\('save_action'\) === 'test';/.test(providers), 'POST handler still reads save_action=test');
ok(/sh_ai_provider_save\(\[/.test(providers), 'provider save path remains intact');
ok(/^\.sh-admin-body \.sh-aiprov-actions \{/m.test(appCss), 'provider actions are scoped with admin-body specificity');
ok(/^\.sh-admin-body \.sh-aiprov-actions \.sh-btn--primary \{ background: var\(--sh-brand\)/m.test(appCss), 'primary action styling is scoped to provider actions');
ok(/@media \(max-width: 420px\) \{[\s\S]*?\.sh-admin-body \.sh-aiprov-actions \{ display: grid; grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/.test(appCss), 'mobile actions use a specificity-safe two-column grid');
ok((appCss.match(/^\.sh-aiprov-actions \{/gm) || []).length === 0, 'no low-specificity action selector can be overridden by global sh-actions rules');

console.log(`\n${passed} passed, ${failed} failed`);
if (failed) {
  console.log('UI/UX STATIC AUDIT: FAILED');
  process.exitCode = 1;
} else {
  console.log('UI/UX STATIC AUDIT: ALL CHECKS PASSED');
}
