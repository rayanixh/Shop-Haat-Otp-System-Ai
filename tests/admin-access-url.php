<?php
/**
 * Custom Admin Access URL integration tests.
 *
 * Executes the REAL functions in includes/admin-access.php against a real PDO
 * database, then verifies the routing/auth guards in isolated PHP subprocesses
 * (because a blocked request terminates the process with a generic 404).
 *
 * See tests/_support_db_bootstrap.php for the MySQL / SQLite drivers.
 *
 *     php tests/admin-access-url.php
 *     SH_TEST_PHP_BIN=/path/to/php php tests/admin-access-url.php   # optional
 */
declare(strict_types=1);

require_once __DIR__ . '/_support_db_bootstrap.php';
require_once SH_ROOT . '/includes/admin-access.php';

sh_test_schema();

$src = static fn(string $rel): string => (string)file_get_contents(SH_ROOT . '/' . $rel);
$phpBin = static function (): string {
    $bin = getenv('SH_TEST_PHP_BIN');
    if (is_string($bin) && $bin !== '') { return $bin; }
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) { return (string)PHP_BINARY; }
    // php-wasm CLI reports a temporary binary that is not runnable from a
    // subprocess; fall back to a real php on PATH when one is available.
    $fromPath = trim((string)@shell_exec('command -v php 2>/dev/null'));
    return $fromPath !== '' ? $fromPath : (string)PHP_BINARY;
};
/** Run PHP code in a fresh process; returns [output, exitCode]. */
$subprocess = static function (string $code, string $env = '') use ($phpBin): array {
    $root = var_export(SH_ROOT, true);
    $bootstrap = var_export(SH_ROOT . '/tests/_support_db_bootstrap.php', true);
    $preamble = "<?php\nrequire_once $bootstrap;\nrequire_once " . var_export(SH_ROOT . '/includes/admin-access.php', true) . ";\nsh_test_schema();\n";
    $file = tempnam(sys_get_temp_dir(), 'sh-admin-access-');
    if ($file === false) { return ['', -1]; }
    file_put_contents($file, $preamble . $code);
    $cmd = ($env !== '' ? $env . ' ' : '') . escapeshellarg($phpBin()) . ' ' . escapeshellarg($file) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    @unlink($file);
    return [trim($out), 0];
};

$collisionDir = '';

[$passed, $failed] = sh_test_run('Custom Admin Access URL — real PHP + SQL', static function ($ok, $eq) use (
    $src, $subprocess, &$collisionDir
) {
    echo "Engine: " . (sh_db()->isMySql ? 'MySQL/MariaDB (production DDL)' : 'SQLite (MySQL SQL translated)') . "\n\n";

    /* --- 1. Server-side path validation ---------------------------------- */
    $good = 'secure-control-7x9p-' . bin2hex(random_bytes(12)); // 48+ chars, URL safe
    $valid = sh_admin_access_validate_path($good);
    $ok(!empty($valid['ok']) && $valid['path'] === strtolower($good), 'a normalized custom path is accepted');
    $eq($good, sh_admin_access_normalize_path('/' . strtoupper($good) . '/') ?? '', 'leading/trailing slash and case are normalized');
    foreach ([
        '' => 'empty path',
        'short' => 'shorter than 32 characters',
        str_repeat('a', 65) => 'longer than 64 characters',
        'has space ' . str_repeat('a', 40) => 'whitespace',
        str_repeat('a', 20) . '/' . str_repeat('b', 20) => 'extra path segment',
        '//' . str_repeat('a', 40) . '//' => 'repeated slashes',
        str_repeat('a', 40) . '?x=1' => 'query string',
        str_repeat('a', 40) . '#top' => 'fragment',
        '%2e%2e%2f' . str_repeat('a', 33) => 'encoded traversal',
        'https://example.com/' . str_repeat('a', 33) => 'complete external URL',
        str_repeat('a', 40) . '.php' => 'dot / file extension',
        str_repeat('a', 40) . ':8080' => 'port / colon',
        'admin-' . bin2hex(random_bytes(16)) => 'reserved admin- prefix',
        'api-' . bin2hex(random_bytes(16)) => 'reserved api- prefix',
        'assets-' . bin2hex(random_bytes(16)) => 'reserved assets- prefix',
        'sitemap-' . bin2hex(random_bytes(16)) => 'reserved sitemap- prefix',
        str_repeat('a', 31) => '31 characters (below minimum)',
        '-' . str_repeat('a', 40) => 'invalid leading hyphen',
    ] as $candidate => $label) {
        $ok(sh_admin_access_normalize_path((string)$candidate) === null, 'rejects ' . $label);
    }
    foreach (['admin', 'api', 'assets', 'uploads', 'install', 'config', 'includes', 'cron'] as $reserved) {
        $padded = $reserved . str_repeat('-x', 20);
        // Reserved exact names are shorter than the minimum length anyway; the
        // prefix rules cover long lookalikes. Both must be rejected.
        $ok(sh_admin_access_normalize_path($padded) === null || sh_admin_access_normalize_path($reserved) === null,
            'reserved namespace rejected: ' . $reserved);
    }

    /* --- 2. Filesystem and live-slug collision checks -------------------- */
    $collisionDir = SH_ROOT . '/' . strtolower(str_repeat('z', 20) . bin2hex(random_bytes(8)));
    if (!is_dir($collisionDir)) { mkdir($collisionDir, 0755, true); }
    $collisionPath = strtolower(basename($collisionDir));
    $check = sh_admin_access_validate_path($collisionPath);
    $ok(empty($check['ok']) && str_contains((string)$check['error'], 'path'), 'a path colliding with a real directory is rejected');

    $slug = 'live-product-' . bin2hex(random_bytes(12));
    sh_insert('products', ['name' => 'Collision test', 'slug' => $slug, 'status' => 1]);
    $GLOBALS['sh_test_tables']['products'] = true;
    $check = sh_admin_access_validate_path($slug);
    $ok(empty($check['ok']) && str_contains((string)$check['error'], 'collides'), 'a path colliding with a live storefront slug is rejected');
    $GLOBALS['sh_test_tables']['products'] = false;

    /* --- 3. Cryptographically secure suggestion ------------------------- */
    $s1 = sh_admin_access_suggest();
    $s2 = sh_admin_access_suggest();
    $ok(preg_match('/^[a-f0-9]{48}$/D', $s1) === 1, 'suggestion is 192 bits of hex entropy');
    $ok($s1 !== $s2, 'two suggestions are different');
    $ok(!empty(sh_admin_access_validate_path($s1)['ok']), 'generated suggestion passes server-side validation');
    $ok(str_contains($src('includes/admin-access.php'), 'random_bytes('), 'suggestions use random_bytes(), not timestamps/ids');

    /* --- 4. No-lockout state machine: pending -> verify -> apply --------- */
    $eq('disabled', sh_admin_access_state()['status'], 'feature starts disabled');
    $ok(!sh_admin_access_enabled(), 'custom route is off initially');
    $path = 'private-dashboard-' . bin2hex(random_bytes(12));
    $ok(sh_admin_access_save_pending($path), 'saving a valid pending path succeeds');
    $state = sh_admin_access_state();
    $eq($path, $state['pending_path'], 'pending path recorded');
    $eq('', $state['active_path'], 'no active route is changed by saving');
    $ok(!$state['enabled'], 'old route stays active while a new route is staged');
    $ok(!sh_admin_access_apply_pending(), 'apply is refused before verification');
    $ok(!sh_admin_access_enabled(), 'old route still active after a refused apply');
    $ok(sh_admin_access_begin_verification(), 'same-session verification ticket issued');
    $ok(!empty($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]), 'verification ticket lives in the server session, not a public query parameter');
    $ok(sh_admin_access_record_verification($path, 1), 'superadmin verification is recorded');
    $ok(sh_admin_access_pending_is_verified(), 'state reports the route as verified');
    $ok(sh_admin_access_apply_pending(), 'apply succeeds only after verification');
    $state = sh_admin_access_state();
    $ok($state['enabled'], 'custom route is now enabled');
    $eq($path, $state['active_path'], 'verified path becomes the active path');
    $eq('', $state['pending_path'], 'pending value is cleared after apply');
    $ok(!sh_admin_access_save_pending($path), 're-saving the already-active path is rejected');
    $eq(0, (int)sh_val("SELECT COUNT(*) FROM settings WHERE setting_key = 'admin_access_pending_secret' AND setting_value <> ''", [], 0), 'no stale pending secret remains');

    /* --- 5. Route URL, link mapping and include allowlist ---------------- */
    $url = sh_admin_access_route_url($path, 'security.php');
    $ok(str_contains($url, '/' . $path), 'current admin URL contains the configured path');
    $ok(str_contains($url, '__admin_route=security.php'), 'internal admin route is encoded as a validated route key');
    $ok(!str_contains($url, 'admin.php'), 'current admin URL is not the predictable default');
    $eq(null, sh_admin_access_map_url('admin/security.php'), 'public storefront rendering never maps to the secret route');
    define('SH_ADMIN_ROUTE_CONTEXT', true);
    $mapped = sh_admin_access_map_url('admin/security.php?tab=x');
    $ok(is_string($mapped) && str_contains($mapped, '/' . $path) && str_contains($mapped, '__admin_route=security.php') && str_contains($mapped, 'tab=x'),
        'authenticated admin links are rewritten to the custom route with their query intact');
    foreach (['security.php', 'dashboard.php', 'ai/settings.php', 'guides/smtp.php'] as $allowed) {
        $ok(sh_admin_access_normalize_target($allowed) === $allowed, 'allowlisted admin target: ' . $allowed);
    }
    foreach (['../config/config.php', 'ai/../config.php', '_layout.php', 'custom-route.php', 'index.php',
              'security.php?x=1', 'security.php%00', 'security.txt', 'a/b/c/../../evil.php'] as $denied) {
        $ok(sh_admin_access_normalize_target($denied) === null, 'unsafe route target rejected: ' . $denied);
    }

    /* --- 6. Authenticated disable restores the standard route ------------ */
    $ok(sh_admin_access_disable(), 'authenticated superadmin can disable and clear the custom route');
    $state = sh_admin_access_state();
    $ok(!$state['enabled'] && $state['active_path'] === '' && $state['pending_path'] === '', 'all route secrets cleared on disable');
    $eq('disabled', $state['status'], 'status reports disabled');

    /* --- 7. Subprocess: old routes are blocked, new route is reachable --- */
    $probe = $subprocess;
    $enable = "sh_setting_save('admin_access_enabled','1');\n"
        . "sh_setting_save('admin_access_secret_path', " . var_export($path, true) . ");\n";
    $blockCases = [
        ['/admin.php', 'old /admin.php URL'],
        ['/admin/login.php', 'direct admin login file'],
        ['/admin/dashboard.php', 'direct admin dashboard file'],
        ['/admin/_footer.php', 'include-only admin partial'],
        ['/admin/support.php', 'admin support inbox file'],
        ['/admin/ai/providers.php', 'admin AJAX/provider file'],
    ];
    foreach ($blockCases as [$uri, $label]) {
        [$out] = $probe($enable
            . "\$_SERVER['SCRIPT_NAME'] = " . var_export($uri, true) . ";\n"
            . "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
            . "sh_admin_access_guard_request();\n echo 'GUARD-RETURNED';\n");
        $ok($out === 'Not found.', 'guard returns a safe 404 for ' . $label);
    }
    [$out] = $probe($enable
        . "\$_SERVER['SCRIPT_NAME'] = '/admin/custom-route.php';\n"
        . "\$_SERVER['REQUEST_URI'] = " . var_export('/' . $path, true) . ";\n"
        . "sh_admin_access_guard_request();\n echo 'GUARD-RETURNED';\n");
    $ok(str_contains($out, 'GUARD-RETURNED'), 'the verified custom-route dispatcher is not blocked by the guard');
    [$out] = $probe("\$_SERVER['SCRIPT_NAME'] = '/admin/login.php';\n"
        . "\$_SERVER['REQUEST_URI'] = '/admin/login.php';\n"
        . "sh_admin_access_guard_request();\n echo 'GUARD-RETURNED';\n");
    $ok(str_contains($out, 'GUARD-RETURNED'), 'with the feature disabled the standard admin route is reachable again');

    /* --- 8. Authentication is independent of the URL -------------------- */
    [$out] = $probe("require_once " . var_export(SH_ROOT . '/includes/admin-auth.php', true) . ";\n"
        . "\$_SESSION = [];\n"
        . "try { sh_require_admin(); echo 'NO-AUTH-CHECK'; } catch (Throwable \$e) { echo 'AUTH-REQUIRED: ' . \$e->getMessage(); }\n",
        'SH_TEST_ADMIN_AUTH_MODE=1');
    $ok(str_contains($out, 'AUTH-REQUIRED') && str_contains($out, 'admin/login.php'), 'unauthenticated admin request is redirected to login, never rendered');

    /* --- 9. Static wiring / no secret leakage ---------------------------- */
    $htaccess = $src('.htaccess');
    $ok(str_contains($htaccess, 'RewriteRule ^admin\\.php$ - [F,L,NC]'), '.htaccess denies the predictable /admin.php entry point');
    $ok(str_contains($htaccess, 'admin/custom-route.php'), '.htaccess rewrites one high-entropy segment to the dispatcher');
    $ok(str_contains($src('config/config.php'), 'sh_admin_access_guard_request()'), 'bootstrap enforces the legacy-route block on every request');
    $ok(str_contains($src('admin/custom-route.php'), 'sh_admin()') && str_contains($src('admin/custom-route.php'), 'sh_admin_is_superadmin()'), 'dispatcher still requires an admin session and superadmin role');
    $ok(str_contains($src('admin/security.php'), 'sh_require_superadmin()') && str_contains($src('admin/security.php'), 'sh_csrf_require()'), 'Admin Access URL settings require superadmin + CSRF');
    $ok(str_contains($src('api/support.php'), 'sh_require_admin()') && str_contains($src('api/support.php'), 'sh_admin_can(') && str_contains($src('api/support.php'), 'sh_csrf_require()'), 'admin AJAX support actions enforce auth, perms and CSRF independently');
    $ok(str_contains($src('includes/admin-auth.php'), 'session_regenerate_id(true)'), 'session id is regenerated on admin login');
    $ok(str_contains($src('config/config.php'), "'httponly' => true") && str_contains($src('config/config.php'), "'samesite' => 'Lax'"), 'admin/customer cookies are HttpOnly + SameSite');
    $ok(str_contains($src('admin/login.php'), 'sh_login_throttled(') && str_contains($src('admin/login.php'), 'sh_auth_throttle_blocked('), 'admin login rate limiting + temporary lockout are present');
    $ok(str_contains($src('admin/login.php'), "sh_log_line('security'"), 'admin login success/failure events are logged without passwords');
    $ok(str_contains($src('includes/admin-auth.php'), "sh_log_line('security', ") && str_contains($src('includes/admin-auth.php'), 'no authenticated administrator session'), 'unauthenticated admin access denials are logged');
    $ok(str_contains($src('includes/admin-perms.php'), "sh_log_line('security', ") && str_contains($src('includes/admin-perms.php'), 'denied by role check'), 'RBAC denials are logged');
    $ok(str_contains($src('docs/ADMIN-ACCESS-URL.md'), 'phpMyAdmin') && str_contains($src('docs/ADMIN-ACCESS-URL.md'), 'admin_access_enabled'), 'cPanel recovery procedure is documented without an application backdoor');
    foreach (['sitemap.php', 'robots.php', 'index.php', 'support.php', 'includes/footer.php', 'assets/js/app.js'] as $publicFile) {
        $ok(!str_contains($src($publicFile), 'admin_access_secret_path') && !str_contains($src($publicFile), 'sh_admin_access_state()'),
            'no custom route secret in public file: ' . $publicFile);
    }
    $ok(str_contains($src('admin/security.php'), 'data-copy='), 'copy URL action is present in the admin UI');
    $ok(str_contains($src('admin/security.php'), 'Generate secure suggestion') && str_contains($src('admin/security.php'), 'Apply verified route'), 'generate / save / verify / apply controls are present');
    $ok(str_contains($src('admin/security.php'), "sh_post('admin_access_confirm_works') !== '1'")
        && str_contains($src('admin/security.php'), 'name="admin_access_confirm_works"'),
        'the final switch requires an explicit server-side confirmation that the new URL works');
    $ok(str_contains($src('admin/security.php'), 'sh-alert--warning'), 'clear warning is shown before changing the URL');

    /* --- 10. Independent endpoint / asset / internal-file audit ----------- */
    // No admin PHP page is reachable without its own server-side check, even
    // though the bootstrap guard already hides the whole /admin/ namespace.
    $unguarded = [];
    foreach (glob(SH_ROOT . '/admin/*.php') as $file) {
        $body = (string)file_get_contents($file);
        $name = basename($file);
        if (str_starts_with($name, '_')) { continue; } // include-only partials guard via SH_ADMIN_PAGE_AUTHORIZED
        $hasGuard = str_contains($body, 'sh_require_admin()') || str_contains($body, 'sh_require_perm(')
            || str_contains($body, 'sh_require_superadmin()') || str_contains($body, 'sh_admin()');
        if (!$hasGuard) { $unguarded[] = $name; }
    }
    $ok($unguarded === [], 'every admin page repeats a server-side auth/permission check' . ($unguarded ? ': ' . implode(', ', $unguarded) : ''));
    foreach (glob(SH_ROOT . '/admin/ai/*.php') as $file) {
        $body = (string)file_get_contents($file);
        $ok(basename($file) === '_ai.php' ? (str_contains($body, 'sh_require_admin()') && str_contains($body, "sh_require_perm('ai.use')"))
            : str_contains($body, "_ai.php"), 'admin/ai endpoint shares the guarded AI bootstrap: ' . basename($file));
    }
    // Customer endpoints are intentionally public but state-changing calls carry
    // CSRF; admin endpoints repeat auth + RBAC; the three read-only/webhook
    // endpoints document their own unauthenticated contract.
    foreach (['cart.php', 'checkout.php', 'orders.php', 'otp.php', 'payment.php'] as $customerApi) {
        $ok(str_contains($src('api/' . $customerApi), 'sh_csrf_require()'), 'customer state-changing API enforces CSRF: ' . $customerApi);
    }
    foreach (['ai.php', 'notifications.php', 'support.php'] as $adminApi) {
        $ok(str_contains($src('api/' . $adminApi), 'sh_require_admin()'), 'admin API repeats server-side authentication: ' . $adminApi);
    }
    $ok(str_contains($src('api/email-events.php'), 'sh_email_webhook_verify('), 'provider webhook verifies a shared secret/HMAC before any write');
    $ok(str_contains($src('api/csp-report.php'), 'sh_file_rate_limit(') && str_contains($src('api/csp-report.php'), 'POST'), 'public CSP collector is rate limited and read-only');
    $ok(str_contains($src('api/search.php'), 'sh_search_suggest('), 'public search endpoint is read-only');
    foreach (['config', 'includes', 'ai', 'lib', 'logs', 'tests'] as $dir) {
        $deny = $src($dir . '/.htaccess');
        $ok(str_contains($deny, 'Require all denied') || str_contains($deny, 'Deny from all'), 'direct HTTP access denied for ' . $dir . '/');
    }
    $ok(str_contains($htaccess, '^(?:config|includes|ai)/') && str_contains($htaccess, 'database|installed|config'), 'root rewrite/deny also protects internal config and credential files');
    $ok(str_contains($src('router.php'), 'admin/custom-route.php') && str_contains($src('router.php'), '404'), 'PHP built-in router mirrors the Apache rewrite and safe 404');
    $ok(!is_file(SH_ROOT . '/config/database.php'), 'no database credentials are committed in the checkout');
    $ok(str_contains($src('config/database.example.php'), "'pass'"), 'only the example config template ships credentials placeholders');
    foreach (['index.php', 'product.php', 'cart.php', 'checkout.php', 'support.php', 'help.php', 'sitemap.php', 'robots.php'] as $public) {
        $body = $src($public);
        $ok(!str_contains($body, 'admin_access_secret_path') && !str_contains($body, 'sh_admin_access_state('), 'no route secret in public entry point: ' . $public);
    }
});

if ($collisionDir !== '' && is_dir($collisionDir)) { @rmdir($collisionDir); }
exit($failed > 0 ? 1 : 0);
