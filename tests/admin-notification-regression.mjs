#!/usr/bin/env node
/** Source-level checks for CSRF-safe admin notification navigation. */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const layout = readFileSync(join(root, 'admin/_layout.php'), 'utf8');
const inbox = readFileSync(join(root, 'admin/inbox.php'), 'utf8');
const cases = [
  ['notification dropdown opens items with a CSRF-protected POST form',
    layout.includes('name="form" value="open"') && layout.includes('name="id" value="<?= (int)$n[\'id\'] ?>"') && layout.includes('<?= sh_csrf_field() ?>') && layout.includes('class="sh-admin-bell__item" type="submit"')],
  ['inbox Open controls also use POST and include a CSRF token',
    inbox.includes('name="form" value="open"') && inbox.includes('class="sh-btn sh-btn--sm" type="submit">Open</button>')],
  ['read-state mutation occurs only inside the POST handler',
    inbox.indexOf("if ($_SERVER['REQUEST_METHOD'] === 'POST')") < inbox.indexOf("UPDATE admin_notifications SET is_read = 1, read_at = NOW() WHERE id = ?") && inbox.includes("$form === 'open'")],
  ['legacy GET open links are read-only and no longer mark notifications read',
    inbox.includes('// Legacy GET links remain navigational only; reading a notification is a POST.') && inbox.slice(inbox.indexOf('$open = sh_int($_GET[\'open\']'), inbox.indexOf('$filter = sh_get')).includes('sh_admin_notification_redirect($url)') && !inbox.slice(inbox.indexOf('$open = sh_int($_GET[\'open\']'), inbox.indexOf('$filter = sh_get')).includes('UPDATE admin_notifications')],
  ['notification destinations reject external URLs and unsafe paths before redirect',
    inbox.includes('function sh_admin_notification_redirect(string $raw)') && inbox.includes("isset($parts['scheme'])") && inbox.includes("sh_safe_redirect($target, '')") && inbox.includes("preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $fragment)")],
];

let failed = 0;
for (const [name, ok] of cases) {
  console.log(`${ok ? '  ok' : 'FAIL'}  ${name}`);
  if (!ok) failed += 1;
}
console.log(`\n${cases.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
