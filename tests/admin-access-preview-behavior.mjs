#!/usr/bin/env node
/**
 * jsdom behaviour tests for the custom Admin URL live preview. The server
 * remains authoritative; this suite proves the browser shows the complete
 * lowercase URL immediately, validates before submit and keeps the optional
 * random-suggestion button usable.
 *
 *   NODE_PATH=/home/user/node_modules node tests/admin-access-preview-behavior.mjs
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const source = readFileSync(join(root, 'assets/js/admin-access.js'), 'utf8');
const html = `<!doctype html><html><body>
<form method="post" data-admin-access-form
  data-admin-access-origin="https://shop.example.com/shop/"
  data-admin-access-reserved='["admin","api","index","support","login"]'>
  <input id="path" data-admin-access-path value="">
  <div data-admin-access-preview>Enter a path to preview the complete URL.</div>
  <button type="button" data-copy="" data-admin-access-preview-copy disabled>Copy full URL</button>
  <p data-admin-access-hint>The final URL is shown here before you save.</p>
  <button type="submit" name="form" value="admin_access_save">Save Proposed Route</button>
  <button type="submit" name="form" value="admin_access_suggest" formnovalidate>Suggest random path</button>
</form></body></html>`;

const dom = new JSDOM(html, { url: 'https://shop.example.com/admin/security.php', runScripts: 'outside-only' });
const { window } = dom;
window.HTMLInputElement.prototype.reportValidity = function reportValidity() { return !this.validationMessage; };
window.eval(source);

const form = window.document.querySelector('[data-admin-access-form]');
const input = window.document.querySelector('[data-admin-access-path]');
const preview = window.document.querySelector('[data-admin-access-preview]');
const copy = window.document.querySelector('[data-admin-access-preview-copy]');
const hint = window.document.querySelector('[data-admin-access-hint]');
const suggestion = window.document.querySelector('[value="admin_access_suggest"]');

const setValue = (value) => {
  input.value = value;
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
};
const submit = (submitter) => {
  const event = new window.Event('submit', { bubbles: true, cancelable: true });
  if (submitter) Object.defineProperty(event, 'submitter', { value: submitter, configurable: true });
  form.dispatchEvent(event);
  return event.defaultPrevented;
};

let passed = 0;
let failed = 0;
const ok = (condition, name) => {
  if (condition) { passed += 1; console.log('  ok  ' + name); }
  else { failed += 1; console.log('FAIL  ' + name); }
};

ok(preview.textContent.includes('Enter a path'), 'empty input shows a prompt instead of a fabricated URL');
ok(copy.disabled && copy.getAttribute('data-copy') === '', 'Copy full URL is disabled until a valid URL exists');

setValue('My-Admin');
ok(preview.textContent === 'https://shop.example.com/shop/my-admin', 'uppercase input immediately previews the complete lowercase URL');
ok(copy.getAttribute('data-copy') === 'https://shop.example.com/shop/my-admin' && !copy.disabled, 'Copy full URL receives the complete proposed URL');
ok(input.value === 'My-Admin', 'the typed value is not rewritten; only the resulting URL is normalized');
ok(hint.textContent.includes('lowercase'), 'the admin is told why the final URL differs');
ok(input.validationMessage === '' && submit() === false, 'a valid short path passes frontend validation');

for (const path of ['/rayan-admin', 'control-panel', 'secret-dashboard', 'myshop-control', 'admin-access-2026', 'a'.repeat(80)]) {
  setValue(path);
  const expected = 'https://shop.example.com/shop/' + path.replace(/^\//, '').toLowerCase();
  ok(preview.textContent === expected && input.validationMessage === '', 'accepted path previews complete URL: ' + path);
}

for (const [path, label] of [
  ['https://example.com/my-admin', 'protocol and domain'],
  ['example.com/my-admin', 'bare domain'],
  ['my-admin?x=1', 'query'],
  ['my-admin#top', 'fragment'],
  ['%2e%2e%2fmy-admin', 'encoded traversal'],
  ['my-admin/extra', 'multiple segments'],
  ['../my-admin', 'parent traversal'],
  ['my-admin.php', 'dot / extension'],
  ['my-admin:8080', 'port / colon'],
  ['admin', 'reserved route'],
  ['ab', 'too short'],
  ['a'.repeat(81), 'too long'],
  ['-my-admin', 'leading hyphen'],
  ['my-admin-', 'trailing hyphen'],
  ['my admin', 'whitespace'],
]) {
  setValue(path);
  ok(input.validationMessage !== '' && copy.disabled && submit() === true,
    'frontend rejects ' + label + ' and blocks submission');
}

setValue('https://example.com/my-admin');
ok(submit(suggestion) === false, 'optional random-suggestion button bypasses path validation without saving a route');

ok(!source.includes('/admin/'), 'preview script contains no hardcoded legacy admin URL');
ok(!readFileSync(join(root, 'admin/security.php'), 'utf8').includes('minlength="32"'),
  'the 32-character minimum is not present in the shipped settings UI');

console.log(`\nAdmin URL live preview: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
