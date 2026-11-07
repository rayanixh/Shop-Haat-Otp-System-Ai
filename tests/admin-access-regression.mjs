#!/usr/bin/env node
/**
 * Source-level regression checks for Custom Admin Access. These assertions do
 * not replace live Apache/PHP/MySQL testing; they guard the security invariants
 * that must remain present when this shared-hosting feature is changed.
 *
 *   node tests/admin-access-regression.mjs
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (p) => readFileSync(join(root, p), 'utf8');
const access = read('includes/admin-access.php');
const dispatcher = read('admin/custom-route.php');
const security = read('admin/security.php');
const auth = read('includes/admin-auth.php');
const csrf = read('includes/csrf.php');
const config = read('config/config.php');
const htaccess = read('.htaccess');
const router = read('router.php');
const adminAccessJs = read('assets/js/admin-access.js');
const login = read('admin/login.php');
const logout = read('admin/logout.php');
const layout = read('admin/_layout.php');
const seo = read('includes/seo.php');
const aiApi = read('api/ai.php');
const productStudio = read('ai/ProductStudio.php');
const saveStart = access.indexOf('function sh_admin_access_save_pending');
const saveEnd = access.indexOf('\nfunction ', saveStart + 10);
const savePending = access.slice(saveStart, saveEnd === -1 ? undefined : saveEnd);
const aiSaveStart = aiApi.indexOf("case 'save_product':");
const aiSaveEnd = aiApi.indexOf("case 'save':", aiSaveStart);
const aiSaveCode = aiApi.slice(aiSaveStart, aiSaveEnd);

const cases = [
  ['normalized route is one flexible 3–80 character URL-safe segment',
    access.includes('strlen($path) < 3') && access.includes('strlen($path) > 80')
    && !access.includes('strlen($path) < 32') && !access.includes('strlen($path) > 64')
    && access.includes('strtolower($path)') && access.includes("preg_match('/^[a-z0-9]")
    && access.includes("str_contains($path, '/'")],
  ['encoded paths, traversal, query/fragment, external URL and unsafe delimiters are rejected',
    /str_contains\(\$path,\s*'\\\\'\)/.test(access) && ["str_contains($path, '%')", "str_contains($path, '?')", "str_contains($path, '#')", "str_contains($path, ':')", "str_contains($path, '.')"].every((part) => access.includes(part))],
  ['candidate checks reserved names, root PHP pages, filesystem and storefront slug collisions before saving',
    access.includes('sh_admin_access_reserved_paths()') && access.includes("is_file($root . DIRECTORY_SEPARATOR . $path . '.php')")
    && access.includes('file_exists($candidate)') && access.includes('DirectoryIterator($root)')
    && access.includes("['products', 'categories', 'brands', 'blog_posts', 'pages', 'custom_pages']") && access.includes("LOWER(slug) = ?")],
  ['random suggestion is optional only; the admin chooses a short or long path with a live complete URL preview',
    access.includes('bin2hex(random_bytes(24))') && security.includes("$form === 'admin_access_suggest'")
    && security.includes('Suggest random path') && security.includes('It has not been saved')
    && !security.includes("if ($adminAccessInput === '') {\n    try { $adminAccessInput = sh_admin_access_suggest(); }")
    && security.includes('data-admin-access-form') && security.includes('data-admin-access-preview')
    && security.includes('minlength="3"') && !security.includes('minlength="32"')
    && adminAccessJs.includes('setCustomValidity') && adminAccessJs.includes('const fullUrl = origin + state.path')
    && htaccess.includes('{3,80}') && router.includes('{3,80}')],
  ['saving a proposal only changes pending verification metadata, not the active route',
    savePending.includes('SH_ADMIN_ACCESS_PENDING_KEY => $path') && savePending.includes("SH_ADMIN_ACCESS_VERIFIED_KEY => ''") && !savePending.includes('SH_ADMIN_ACCESS_ACTIVE_KEY =>')],
  ['verification timestamps are timezone-independent (the deployed activation bug)',
    access.includes("strtotime($raw . ' UTC')") && access.includes('sh_admin_access_verified_at_write()')
    && access.includes('sh_admin_access_verified_at_parse($verifiedAt)')
    && !/VERIFIED_AT_KEY\s*=>\s*gmdate\(/.test(access)],
  ['activation is one atomic transaction with read-back, compensating rollback and reason codes',
    access.includes('function sh_admin_access_activate_verified(')
    && access.includes('$pdo->beginTransaction()') && access.includes('FOR UPDATE')
    && access.includes('database_readback_failed') && access.includes('$restored = sh_admin_access_write($previous);')
    && access.includes('sh_admin_access_activation_message(')
    && security.includes('sh_admin_access_request_path()')
    && security.includes('sh_security_log(\'admin_access_activation_failed\'')],
  ['a pending path requires a same-session ticket and shows a verification screen; a GET never verifies or activates',
    dispatcher.includes('sh_admin_access_request_path()') && dispatcher.includes('SH_ADMIN_ACCESS_VERIFY_SESSION_KEY')
    && dispatcher.includes("define('SH_ADMIN_ACCESS_VERIFY_SCREEN', true)")
    && !dispatcher.includes('sh_admin_access_record_verification(') && !dispatcher.includes('sh_admin_access_apply_pending(')
    && security.includes("!\$adminAccessVerifyScreen") && security.includes("'admin_access_verify_activate'")
    && !/verify_admin_url\s*=\s*1/i.test(dispatcher + security)],
  ['apply requires a fresh matching verification, revalidates, and atomically activates the route',
    access.includes('hash_equals($pending, $verified)') && access.includes('$verifiedAt < time() - 3600') && access.includes('sh_admin_access_validate_path($pending)') && access.includes('SH_ADMIN_ACCESS_ENABLED_KEY => \'1\'') && access.includes('SH_ADMIN_ACCESS_ACTIVE_KEY => $pending')],
  ['dispatcher only includes fixed PHP targets resolved under the admin directory',
    access.includes('realpath(SH_ROOT . \'/admin/\' . $route)') && access.includes('$adminRoot . DIRECTORY_SEPARATOR') && access.includes("str_starts_with($part, '_')") && dispatcher.includes('require $target;')],
  ['AI product editor link is returned only by authenticated CSRF-protected admin save and mapped for internal routing',
    aiApi.indexOf('$admin = sh_require_admin();') >= 0 && aiApi.indexOf('$admin = sh_require_admin();') < aiApi.indexOf("'admin_url'     =>") && aiApi.includes('sh_csrf_require();') && aiSaveCode.indexOf("sh_admin_can('products.create')") < aiSaveCode.indexOf("'admin_url'     =>") && productStudio.includes("sh_url('admin/product-edit.php?edit='") && access.includes("!defined('SH_ADMIN_ROUTE_CONTEXT') || !sh_admin_access_enabled()")],
  ['applied mode blocks legacy/direct admin PHP and the old /admin.php alias',
    access.includes('function sh_admin_access_guard_request()') && access.includes('sh_admin_access_not_found();') && htaccess.includes('RewriteRule ^admin\\.php$ - [F,L,NC]') && htaccess.includes('admin/custom-route.php') && router.includes('admin/custom-route.php') && auth.includes('sh_admin_access_guard_request()')],
  ['auth remains independent; admin POSTs require CSRF, login regenerates sessions, cookies are hardened, and public sitemap output omits the secret',
    auth.includes('sh_csrf_require();') && csrf.includes("defined('SH_ADMIN_ROUTE_CONTEXT')") && login.includes('sh_auth_throttle_blocked') && auth.includes('session_regenerate_id(true)') && logout.includes('sh_csrf_require();') && logout.includes("!== 'POST'") && layout.includes('sh_csrf_field()') && layout.includes("if (!defined('SH_ADMIN_PAGE_AUTHORIZED'))") && !layout.includes('!defined(\'SH_ADMIN_ROUTE_CONTEXT\')') && config.includes("'httponly' => true") && config.includes("'samesite' => 'Lax'") && config.includes("'secure'   => sh_request_is_https()") && access.includes("!defined('SH_ADMIN_ROUTE_CONTEXT') || !sh_admin_access_enabled()") && security.includes('Suggest random path') && security.includes('data-admin-access-preview') && !security.includes('minlength="32"') && security.includes('Save proposed route') && security.includes('Saved — verification required') && security.includes('Verify and activate new Admin URL') && security.includes('New Admin URL is active') && security.includes('Open Admin Panel') && security.includes('Copy full URL') && security.includes('Disable and clear custom route') && security.includes('cPanel recovery') && !seo.includes('admin_access_secret_path')],
];

let failed = 0;
for (const [name, ok] of cases) {
  console.log(`${ok ? '  ok' : 'FAIL'}  ${name}`);
  if (!ok) failed += 1;
}
console.log(`\n${cases.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
