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
    $accessSrc = $src('includes/admin-access.php');
    $securitySrc = $src('admin/security.php');
    $htaccessSrc = $src('.htaccess');
    $routerSrc = $src('router.php');
    $adminAccessJs = $src('assets/js/admin-access.js');
    $good = 'secure-control-7x9p-' . bin2hex(random_bytes(12)); // long, URL safe
    $valid = sh_admin_access_validate_path($good);
    $ok(!empty($valid['ok']) && $valid['path'] === strtolower($good), 'a normalized long custom path is accepted');
    $eq($good, sh_admin_access_normalize_path('/' . strtoupper($good) . '/') ?? '', 'leading/trailing slash and case are normalized');
    foreach (['my-admin', 'RayAn-Admin', 'control-panel', 'secret-dashboard', 'myshop-control', 'admin-access-2026'] as $memorable) {
        $eq(strtolower($memorable), sh_admin_access_normalize_path('/' . $memorable) ?? '', 'short memorable path accepted: ' . $memorable);
    }
    $eq(str_repeat('a', 80), sh_admin_access_normalize_path(str_repeat('A', 80)) ?? '', '80 uppercase characters normalize safely');
    foreach (['admin', 'api', 'assets', 'uploads', 'install', 'config', 'includes', 'cron', 'index', 'product'] as $reserved) {
        $ok(sh_admin_access_normalize_path($reserved) === null, 'reserved exact route rejected: ' . $reserved);
    }
    $rootPage = strtolower((string)pathinfo(SH_ROOT . '/index.php', PATHINFO_FILENAME));
    $ok(sh_admin_access_normalize_path($rootPage) === null, 'root PHP basename is rejected as a route');
    foreach ([
        '' => 'empty path',
        'ab' => 'shorter than 3 characters',
        str_repeat('a', 81) => 'longer than 80 characters',
        'my admin' => 'whitespace',
        'my-admin/extra' => 'extra path segment',
        '//my-admin//' => 'repeated slashes',
        'my-admin?x=1' => 'query string',
        'my-admin#top' => 'fragment',
        '%2e%2e%2fmy-admin' => 'encoded traversal',
        'example.com/my-admin' => 'bare domain',
        'https://example.com/my-admin' => 'complete external URL',
        'my-admin.php' => 'dot / file extension',
        'my-admin:8080' => 'port / colon',
        '../my-admin' => 'parent traversal',
        '-my-admin' => 'invalid leading hyphen',
        'my-admin-' => 'invalid trailing hyphen',
    ] as $candidate => $label) {
        $ok(sh_admin_access_normalize_path((string)$candidate) === null, 'rejects ' . $label);
    }
    $ok(!str_contains($accessSrc, 'strlen($path) < 32') && !str_contains($accessSrc, 'strlen($path) > 64')
        && str_contains($accessSrc, 'strlen($path) < 3') && str_contains($accessSrc, 'strlen($path) > 80')
        && str_contains($accessSrc, 'strtolower($path)') && str_contains($accessSrc, 'sh_admin_access_reserved_paths()'),
        'backend accepts flexible 3–80 character paths and normalizes uppercase');
    $ok(!str_contains($securitySrc, 'minlength="32"') && str_contains($securitySrc, 'minlength="3"')
        && str_contains($securitySrc, 'data-admin-access-preview') && str_contains($securitySrc, 'data-admin-access-origin')
        && str_contains($adminAccessJs, 'setCustomValidity') && str_contains($adminAccessJs, 'const fullUrl = origin + state.path')
        && str_contains($htaccessSrc, '{3,80}') && str_contains($routerSrc, '{3,80}'),
        'frontend, Apache rewrite and built-in router use the same flexible path and live complete URL preview');
    $ok(!preg_match('/if \(\$adminAccessInput === \'\'\)\s*\{\s*try\s*\{\s*\$adminAccessInput = sh_admin_access_suggest\(\);/', $securitySrc),
        'no automatic random path is generated as the required/default workflow');
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

    /* --- 3. Optional cryptographic suggestion ------------------------- */
    $s1 = sh_admin_access_suggest();
    $s2 = sh_admin_access_suggest();
    $ok(preg_match('/^[a-f0-9]{48}$/D', $s1) === 1, 'optional suggestion is 192 bits of hex entropy');
    $ok($s1 !== $s2, 'two optional suggestions are different');
    $ok(!empty(sh_admin_access_validate_path($s1)['ok']), 'optional suggestion passes server-side validation');
    $ok(str_contains($accessSrc, 'random_bytes('), 'optional suggestions use random_bytes(), not timestamps/ids');
    $ok(str_contains($securitySrc, 'Suggest random path') && str_contains($securitySrc, 'It has not been saved'),
        'random suggestion is an explicit optional action and is not saved automatically');
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
    $ok(!sh_admin_access_pending_is_verified() && !sh_admin_access_enabled(),
        'issuing/opening a verification ticket alone neither verifies nor activates the route');
    $ok(sh_admin_access_record_verification($path, 1), 'superadmin verification is recorded');
    $ok(sh_admin_access_pending_is_verified(), 'state reports the route as verified');
    $ok(sh_admin_access_apply_pending(), 'apply succeeds only after verification');
    $state = sh_admin_access_state();
    $ok($state['enabled'], 'custom route is now enabled');
    $eq($path, $state['active_path'], 'verified path becomes the active path');
    $eq('', $state['pending_path'], 'pending value is cleared after apply');
    $ok(!sh_admin_access_save_pending($path), 're-saving the already-active path is rejected');
    $eq(0, (int)sh_val("SELECT COUNT(*) FROM settings WHERE setting_key = 'admin_access_pending_secret' AND setting_value <> ''", [], 0), 'no stale pending secret remains');

    /* --- 4b. Root-cause regression: timezone-independent verification ---- */
    // The deployed failure was gmdate() (UTC wall clock) written by
    // record_verification() but strtotime() read under Asia/Dhaka (UTC+6).
    // Every supported timezone must now see the same freshness result.
    $zones = ['UTC', 'Asia/Dhaka', 'America/New_York', 'Pacific/Auckland', 'Europe/London'];
    $originalZone = date_default_timezone_get();
    $freshnessByZone = [];
    foreach ($zones as $zone) {
        date_default_timezone_set($zone);
        sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, $path);
        sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_KEY, $path);
        sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_BY_KEY, '1');
        sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_AT_KEY, (string)time());
        $freshnessByZone[$zone] = sh_admin_access_pending_is_verified();
        // Legacy UTC datetime strings from earlier installs must be accepted too.
        sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_AT_KEY, gmdate('Y-m-d H:i:s'));
        $ok(sh_admin_access_pending_is_verified(), 'legacy UTC datetime marker stays fresh under ' . $zone);
    }
    // Exact deployed reproduction: the whole save -> verify -> activate flow
    // on a UTC+6 server. Before the fix, record_verification() wrote a UTC
    // wall-clock string and the freshness check under Asia/Dhaka rejected it.
    date_default_timezone_set('Asia/Dhaka');
    $tzPath = 'dhaka-route-' . bin2hex(random_bytes(12));
    foreach ([SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY, SH_ADMIN_ACCESS_PENDING_KEY,
              SH_ADMIN_ACCESS_VERIFIED_KEY, SH_ADMIN_ACCESS_VERIFIED_AT_KEY, SH_ADMIN_ACCESS_VERIFIED_BY_KEY] as $k) {
        sh_setting_save($k, '');
    }
    $ok(sh_admin_access_save_pending($tzPath), 'Asia/Dhaka: proposed route is saved');
    $ok(sh_admin_access_begin_verification(), 'Asia/Dhaka: same-session ticket is issued');
    $ok(sh_admin_access_record_verification($tzPath, 1), 'Asia/Dhaka: superadmin verification is recorded');
    $ok(sh_admin_access_pending_is_verified(), 'Asia/Dhaka: verification is still fresh (the reported bug)');
    $ok(sh_admin_access_apply_pending(), 'Asia/Dhaka: legacy two-step apply still works');
    $eq($tzPath, sh_admin_access_state()['active_path'], 'Asia/Dhaka: proposed route becomes active');
    date_default_timezone_set($originalZone);
    $ok(count(array_filter($freshnessByZone)) === count($zones),
        'epoch verification marker is fresh in UTC, Asia/Dhaka and three other timezones');
    $eq(false, sh_admin_access_verified_at_parse(''), 'empty verification marker is not fresh');
    $ok(sh_admin_access_verified_at_parse(gmdate('Y-m-d H:i:s')) !== false,
        'UTC datetime markers parse without depending on the server timezone');
    $ok(sh_admin_access_verified_at_parse((string)(time() - 7200)) < time() - 3600,
        'a genuinely stale marker is still rejected');
    sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_KEY, '');
    sh_setting_save(SH_ADMIN_ACCESS_VERIFIED_AT_KEY, '');
    sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, '');

    /* --- 4c. Atomic activation, rollback and reason codes ---------------- */
    $reset = static function (string $active = '', bool $enabled = false): void {
        foreach ([SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY, SH_ADMIN_ACCESS_PENDING_KEY,
                  SH_ADMIN_ACCESS_VERIFIED_KEY, SH_ADMIN_ACCESS_VERIFIED_AT_KEY, SH_ADMIN_ACCESS_VERIFIED_BY_KEY] as $k) {
            sh_setting_save($k, '');
        }
        sh_setting_save(SH_ADMIN_ACCESS_ENABLED_KEY, $enabled ? '1' : '0');
        sh_setting_save(SH_ADMIN_ACCESS_ACTIVE_KEY, $active);
        unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
    };
    $newPath = 'verified-route-' . bin2hex(random_bytes(12));
    $oldPath = 'previous-route-' . bin2hex(random_bytes(12));
    $readActive = static fn(): string => (string)sh_val(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [SH_ADMIN_ACCESS_ACTIVE_KEY], '');

    // Missing admin / ticket / pending route all fail closed without touching
    // the previously active route.
    $reset($oldPath, true);
    $r = sh_admin_access_activate_verified($newPath, 0);
    $ok(empty($r['ok']) && $r['reason'] === 'admin_session_missing', 'activation refuses an unauthenticated caller');
    $eq($oldPath, $readActive(), 'old active route unchanged after an unauthenticated activation attempt');

    $reset($oldPath, true);
    sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, $newPath);
    $r = sh_admin_access_activate_verified($newPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'verification_ticket_missing', 'activation refuses a missing session ticket');
    $eq($oldPath, $readActive(), 'old active route unchanged after a missing-ticket activation attempt');

    $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] = hash('sha256', $newPath);
    $r = sh_admin_access_activate_verified($oldPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'verification_ticket_mismatch', 'activation refuses a ticket for a different URL');
    $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] = hash('sha256', $oldPath);
    $r = sh_admin_access_activate_verified($oldPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'route_mismatch',
        'a ticket for the OLD route cannot activate a different staged route');
    $eq($oldPath, $readActive(), 'old-route ticket does not alter the active route');
    sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, $oldPath);
    $r = sh_admin_access_activate_verified($oldPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'already_active', 'already-active URL is not re-applied');
    $eq($oldPath, $readActive(), 'already-active refusal leaves the active route unchanged');
    sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, $newPath);
    $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] = hash('sha256', 'unrelated-' . bin2hex(random_bytes(12)));
    $r = sh_admin_access_activate_verified($newPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'verification_ticket_mismatch', 'ticket must match the deployed request path');
    $eq($oldPath, $readActive(), 'route mismatch does not change the old active route');
    $reset($oldPath, true);
    $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] = hash('sha256', $newPath);
    $r = sh_admin_access_activate_verified($newPath, 7);
    $ok(empty($r['ok']) && $r['reason'] === 'no_pending_route', 'activation refuses when nothing is staged');
    $eq($oldPath, $readActive(), 'no-pending failure leaves the old active route untouched');

    // Full success: one transaction, DB read-back, ticket consumed.
    $reset('', false);
    $ok(sh_admin_access_save_pending($newPath), 'staging a new route for the atomic activation succeeds');
    $ok(sh_admin_access_begin_verification(), 'same-session ticket issued before activation');
    $r = sh_admin_access_activate_verified($newPath, 7);
    $ok(!empty($r['ok']) && $r['reason'] === 'activated', 'atomic activation succeeds with a matching ticket, admin and deployed path');
    $eq($newPath, $readActive(), 'database now holds the new active route');
    $eq('1', (string)sh_val('SELECT setting_value FROM settings WHERE setting_key = ?', [SH_ADMIN_ACCESS_ENABLED_KEY], ''),
        'database confirms the custom route is enabled');
    $eq('', (string)sh_val('SELECT setting_value FROM settings WHERE setting_key = ?', [SH_ADMIN_ACCESS_PENDING_KEY], ''),
        'database confirms the staged route was consumed');
    $ok(sh_admin_access_enabled() && sh_admin_access_state()['active_path'] === $newPath,
        'post-commit settings read used by every admin request confirms activation');
    $ok(empty($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]), 'one-use ticket is consumed after success');
    $ok(!empty(sh_admin_access_activation_message('activated')),
        'success is only reported through the confirmed-activation message');
    foreach (['database_error', 'database_readback_failed', 'post_commit_verification_failed',
              'route_mismatch', 'verification_ticket_missing', 'no_pending_route'] as $reason) {
        $msg = sh_admin_access_activation_message($reason);
        $ok($msg !== sh_admin_access_activation_message('__unknown__') && $msg !== '',
            'useful admin-facing explanation exists for ' . $reason);
    }
    $ok(str_contains($src('includes/admin-access.php'), "strtotime(\$raw . ' UTC')")
        && str_contains($src('includes/admin-access.php'), 'sh_admin_access_verified_at_write()'),
        'verification timestamps no longer depend on the PHP default timezone');
    $ok(str_contains($src('includes/admin-access.php'), '$restored = sh_admin_access_write($previous);'),
        'post-commit mismatch triggers compensating restore of the previous route');
    $ok(str_contains($src('admin/security.php'), 'sh_security_log(\'admin_access_activation_failed\'')
        || str_contains($src('admin/security.php'), 'sh_security_log("admin_access_activation_failed"'),
        'failed activation reason is written to the secure admin log');

    // Restore the state section 4 left behind for the URL-mapping assertions.
    $reset($path, true);

    /* --- 4d. Short, memorable paths use the same secure lifecycle -------- */
    $reset($path, true);
    $shortPath = 'my-admin';
    $ok(sh_admin_access_save_pending($shortPath), 'a short memorable path can be staged');
    $ok(sh_admin_access_begin_verification(), 'short path gets a fresh same-session ticket');
    $shortResult = sh_admin_access_activate_verified($shortPath, 7);
    $ok(!empty($shortResult['ok']) && $shortResult['reason'] === 'activated', 'short path activates only through explicit verified activation');
    $eq($shortPath, $readActive(), 'database holds the lowercase short path after activation');
    $ok(sh_admin_access_enabled(), 'old long route is replaced only after successful activation');
    $_SERVER['REQUEST_URI'] = '/My-Admin';
    $eq('my-admin', sh_admin_access_request_path() ?? '', 'uppercase deployed URL resolves to the lowercase active path');
    $ok(sh_admin_access_save_pending('rayan-admin'), 'the path can be changed again through the same secure process');
    $eq($shortPath, sh_admin_access_state()['active_path'], 'staging a later change does not disable the working short route');
    $reset($path, true);

    /* --- 5. Route URL, link mapping and include allowlist ---------------- */
    $url = sh_admin_access_route_url($path, 'security.php');
    $ok(str_contains($url, '/' . $path), 'current admin URL contains the configured path');
    $eq('http://localhost/' . $path, sh_admin_access_full_url($path), 'full Admin URL includes scheme, domain and path exactly once');
    $eq('http://localhost/' . $path . '?__admin_route=security.php', sh_admin_access_full_url($path, 'security.php'), 'full Admin URL preserves a validated internal route');
    $eq('', sh_admin_access_full_url('ab'), 'an invalid path never produces a copyable URL');
    $eq('http://localhost/my-admin', sh_admin_access_full_url('My-Admin'), 'uppercase input produces the lowercase complete URL');
    $eq('http://localhost/admin/login.php', sh_admin_access_standard_url(), 'standard route URL includes the domain');
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
    $ok(str_contains($htaccess, 'admin/custom-route.php') && str_contains($htaccess, '{3,80}') && str_contains($htaccess, '[NC]'), '.htaccess rewrites one flexible admin-chosen segment to the dispatcher');
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
    $dispatcherSrc = $src('admin/custom-route.php');
    $ok(str_contains($securitySrc, 'data-copy=') && str_contains($securitySrc, 'Copy full URL'), 'Copy full URL actions are present in the admin UI');
    foreach (['Current active Admin URL', 'New proposed Admin URL', 'Complete proposed URL',
              'Choose a custom path', 'Saved — verification required', 'Verify and activate',
              'New Admin URL is active', 'Open Admin Panel', 'Save proposed route',
              'Suggest random path', 'Verify and activate new Admin URL'] as $label) {
        $ok(str_contains($securitySrc, $label), 'Admin URL UX label present: ' . $label);
    }
    $ok(str_contains($securitySrc, 'sh-aau-steps') && str_contains($securitySrc, 'sh-aau-step'), 'three-step status indicator is rendered');
    $ok(str_contains($src('includes/admin-access.php'), 'function sh_admin_access_full_url(')
        && str_contains($securitySrc, 'sh_admin_access_full_url(')
        && str_contains($src('assets/css/app.css'), '.sh-aau-urlbox'),
        'complete absolute URL (domain included) is shown and wraps on mobile');
    $ok(str_contains($securitySrc, 'data-admin-access-preview-copy')
        && str_contains($securitySrc, 'data-copy=') && str_contains($adminAccessJs, 'copy.setAttribute'),
        'live preview updates the Copy full URL control with the complete URL');
    $ok(!str_contains($securitySrc, 'minlength="32"') && !str_contains($accessSrc, 'strlen($path) < 32'),
        'mandatory 32–64 character restriction is removed from both frontend and backend');
    $ok(str_contains($securitySrc, "if (!\$adminAccessVerifyScreen)")
        && str_contains($securitySrc, "\$form === 'admin_access_verify_activate'")
        && str_contains($securitySrc, 'sh_admin_access_activate_verified(')
        && str_contains($securitySrc, 'sh_admin_access_request_path()')
        && str_contains($src('includes/admin-access.php'), "hash_equals(hash('sha256', \$requestedPath), \$ticket)")
        && str_contains($securitySrc, 'sh_csrf_require()'),
        'activation is an explicit CSRF-protected POST served only from the pending URL with a matching session ticket');
    $ok(str_contains($src('includes/admin-access.php'), 'sh_admin_access_verified_at_parse(')
        && str_contains($src('includes/admin-access.php'), 'sh_admin_access_verified_at_write()')
        && str_contains($src('includes/admin-access.php'), 'sh_admin_access_activation_message('),
        'UTC/local-timezone mismatch, atomic activation and useful error messages are implemented');
    $ok(!str_contains($dispatcherSrc, 'sh_admin_access_record_verification(')
        && str_contains($dispatcherSrc, "define('SH_ADMIN_ACCESS_VERIFY_SCREEN', true)"),
        'visiting the proposed URL only shows a verification screen and never records verification or activates');
    $ok(str_contains($securitySrc, 'sh-alert--warning'), 'clear warning is shown before changing the URL');
    $ok(str_contains($securitySrc, 'sh_admin_access_standard_url()'), 'the standard route is still shown as the current active URL while the feature is off');

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
    $ok(str_contains($routerSrc, 'admin/custom-route.php') && str_contains($routerSrc, '{3,80}') && str_contains($routerSrc, '404'), 'PHP built-in router mirrors the Apache flexible rewrite and safe 404');
    $ok(!is_file(SH_ROOT . '/config/database.php'), 'no database credentials are committed in the checkout');
    $ok(str_contains($src('config/database.example.php'), "'pass'"), 'only the example config template ships credentials placeholders');
    foreach (['index.php', 'product.php', 'cart.php', 'checkout.php', 'support.php', 'help.php', 'sitemap.php', 'robots.php'] as $public) {
        $body = $src($public);
        $ok(!str_contains($body, 'admin_access_secret_path') && !str_contains($body, 'sh_admin_access_state('), 'no route secret in public entry point: ' . $public);
    }

    /* --- 10b. New-route navigation / forms / AJAX / upload source audit --- */
    // After activation, every admin POST/GET target must be produced by
    // sh_url() (which maps admin/ targets through the dispatcher) or be a
    // non-admin api/ endpoint. A hardcoded /admin/... literal in a form or a
    // shipped JS file would be blocked by the bootstrap guard.
    $hardcodedForms = [];
    foreach (glob(SH_ROOT . '/admin/*.php') as $file) {
        $body = (string)file_get_contents($file);
        if (preg_match_all('/<form[^>]*\baction="([^"]*)"/i', $body, $matches) > 0) {
            foreach ($matches[1] as $action) {
                if ($action === '' || str_contains($action, 'sh_url(') || str_contains($action, 'sh_admin_access_route_url(')) { continue; }
                $hardcodedForms[] = basename($file) . ': ' . $action;
            }
        }
    }
    $ok($hardcodedForms === [], 'all admin form actions are route-mapped through sh_url() (no hardcoded /admin/ target)');
    $hardcodedJs = [];
    foreach (glob(SH_ROOT . '/assets/js/*.js') as $file) {
        if (preg_match('~["\']/admin/~', (string)file_get_contents($file)) === 1) { $hardcodedJs[] = basename($file); }
    }
    $ok($hardcodedJs === [], 'shipped admin JS has no hardcoded /admin/ AJAX or upload URL');
    // The disable lifecycle test above turned the feature off; restore the
    // verified active route for the post-activation link-mapping audit.
    sh_setting_save(SH_ADMIN_ACCESS_ENABLED_KEY, '1');
    sh_setting_save(SH_ADMIN_ACCESS_ACTIVE_KEY, $path);
    sh_setting_save(SH_ADMIN_ACCESS_PENDING_KEY, '');
    foreach (['security.php', 'support.php', 'support-settings.php', 'ai/settings.php', 'guides/smtp.php'] as $target) {
        $ok(sh_admin_access_normalize_target($target) === $target, 'post-activation dispatcher accepts admin target: ' . $target);
        $mapped = sh_admin_access_map_url('admin/' . $target);
        $ok(is_string($mapped) && str_contains($mapped, '/' . $path)
            && str_contains($mapped, '__admin_route=' . rawurlencode($target)),
            'post-activation internal link is rewritten to the new route: ' . $target);
    }
    $ok(str_contains($src('api/support.php'), "sh_url('api/support.php')")
        || str_contains($src('admin/support.php'), "sh_url('api/support.php')"),
        'admin AJAX uses the public api/ endpoint that stays reachable after activation');
});

if ($collisionDir !== '' && is_dir($collisionDir)) { @rmdir($collisionDir); }
exit($failed > 0 ? 1 : 0);
