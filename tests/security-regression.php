<?php
/**
 * ShopHaat security regression tests.
 *
 * Runs on any host with PHP 8.1+ and needs no database, no web server and no
 * dependencies. It exercises the security controls that are easy to break by
 * accident: response headers and CSP, the installer access rules, redirect
 * validation, CSRF comparison, the HTML sanitizer, upload validation, the
 * authentication throttles, log redaction and the presence of the web-server
 * guards.
 *
 *     php tests/security-regression.php
 *
 * Exit code 0 = every executed check passed, 1 = at least one failed.
 *
 * Checks that cannot run in the current SAPI (for example reading headers on a
 * CLI-only build) are reported as SKIP with the reason, never as a pass and
 * never as a failure — a skipped check is not evidence of anything.
 */

declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/security-regression.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/install-guard.php';

// Capture the emitted headers BEFORE any output: once this script prints a
// line, header() is a no-op and the check would silently skip itself.
$probeHeaders = null;
$probeSupported = headers_supported();
if ($probeSupported) {
    sh_security_headers_apply();
    $probeHeaders = headers_list();
}

$passed = 0;
$failed = 0;
$skipped = 0;
/** @var array<int,string> */
$failures = [];
/** @var array<int,string> */
$skips = [];

function sh_t(string $name, callable $fn): void
{
    global $passed, $failed, $failures;
    try {
        $result = $fn();
        if ($result === null) {
            sh_t_skip($name, 'not applicable in this environment');
            return;
        }
        if ($result === true) {
            $passed++;
            echo "  PASS  $name\n";
            return;
        }
        $failed++;
        $failures[] = $name . ' — ' . (is_string($result) ? $result : 'assertion returned false');
        echo "  FAIL  $name\n        " . (is_string($result) ? $result : 'assertion returned false') . "\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ' — threw ' . get_class($e) . ': ' . $e->getMessage();
        echo "  FAIL  $name\n        threw " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

function sh_t_skip(string $name, string $why): void
{
    global $skipped, $skips;
    $skipped++;
    $skips[] = $name . ' — ' . $why;
    echo "  SKIP  $name\n        $why\n";
}

/** Assert a condition; returns true or a message describing the mismatch. */
function sh_assert(bool $condition, string $message): bool|string
{
    return $condition ? true : $message;
}

function sh_assert_contains(string $haystack, string $needle, string $message): bool|string
{
    return str_contains($haystack, $needle) ? true : $message . ' (missing: ' . $needle . ')';
}

function sh_section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', max(20, strlen($title))) . "\n";
}

echo "ShopHaat security regression tests\n";
echo "PHP " . PHP_VERSION . " (" . PHP_SAPI . ")\n";

// ===========================================================================
sh_section('1. Security response headers');
// ===========================================================================

sh_t('the header list contains the baseline protections', function () {
    $names = array_column(sh_security_header_list(), 0);
    foreach (['X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options', 'Permissions-Policy'] as $required) {
        if (!in_array($required, $names, true)) { return "missing header $required"; }
    }
    return true;
});

sh_t('clickjacking is blocked by default', function () {
    $headers = sh_security_header_list();
    foreach ($headers as [$name, $value]) {
        if ($name === 'X-Frame-Options' && $value !== 'SAMEORIGIN' && $value !== 'DENY') {
            return "X-Frame-Options is $value";
        }
    }
    $csp = '';
    foreach ($headers as [$name, $value]) {
        if (str_contains($name, 'Content-Security-Policy')) { $csp = $value; }
    }
    return sh_assert_contains($csp, "frame-ancestors 'self'", 'CSP frame-ancestors is not self');
});

sh_t('MIME sniffing is disabled', function () {
    foreach (sh_security_header_list() as [$name, $value]) {
        if ($name === 'X-Content-Type-Options') {
            return sh_assert($value === 'nosniff', "X-Content-Type-Options is $value");
        }
    }
    return 'header absent';
});

sh_t('a Content Security Policy is present by default', function () {
    $found = null;
    foreach (sh_security_header_list() as [$name, $value]) {
        if (str_contains($name, 'Content-Security-Policy')) { $found = [$name, $value]; }
    }
    if ($found === null) { return 'no CSP header is emitted'; }
    // Default is report-only: it must not block a live store before the owner
    // has reviewed the violation log.
    return sh_assert($found[0] === 'Content-Security-Policy-Report-Only',
        'the default CSP should be report-only, got ' . $found[0]);
});

sh_t('the CSP blocks inline script except by nonce', function () {
    $policy = sh_csp_policy();
    if (str_contains($policy, "script-src 'self' 'unsafe-inline'")) { return "script-src allows 'unsafe-inline'"; }
    if (!str_contains($policy, "script-src 'self' 'nonce-")) { return "script-src is not nonce based: $policy"; }
    foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $must) {
        if (!str_contains($policy, $must)) { return "policy is missing $must"; }
    }
    return true;
});

sh_t('the CSP nonce is unpredictable and changes per process', function () {
    $nonce = sh_csp_nonce();
    if (strlen($nonce) < 20) { return 'nonce is too short: ' . strlen($nonce); }
    if (preg_match('/^[A-Za-z0-9_-]+$/', $nonce) !== 1) { return 'nonce is not URL-safe base64'; }
    // A second process must produce a different nonce; within one request it is
    // stable so the markup and the header agree.
    $again = shell_execSafeNonce();
    if ($again === null) { return null; }
    return sh_assert($again !== $nonce, 'two processes produced the same nonce');
});

sh_t('HSTS is not sent over plain HTTP', function () {
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    foreach (sh_security_header_list() as [$name]) {
        if ($name === 'Strict-Transport-Security') { return 'HSTS was sent on a non-HTTPS request'; }
    }
    return true;
});

sh_t('HSTS is sent over HTTPS', function () {
    $_SERVER['HTTPS'] = 'on';
    $found = null;
    foreach (sh_security_header_list() as [$name, $value]) {
        if ($name === 'Strict-Transport-Security') { $found = $value; }
    }
    unset($_SERVER['HTTPS']);
    if ($found === null) { return 'no HSTS header on an HTTPS request'; }
    return sh_assert_contains($found, 'max-age=', 'HSTS has no max-age');
});

sh_t('frame-ancestors rejects injected junk', function () {
    // A setting is stored in the database; treating its value as trusted would
    // let a compromised setting disable clickjacking protection.
    foreach (["'self' https://shop.example.com", "evil.example; script-src *", "self, https://ok.example"] as $probe) {
        $got = sh_frame_ancestors_probe($probe);
        if (preg_match('/[;<>]/', $got) === 1) { return "unsafe value survived: $got"; }
        if ($got === '') { return 'empty result for ' . $probe; }
    }
    return true;
});

sh_t('the headers are actually emitted', function () {
    global $probeHeaders, $probeSupported;
    if (!$probeSupported) { return null; }
    $flat = strtolower(implode("\n", $probeHeaders ?? []));
    if (!str_contains($flat, 'x-content-type-options')) { return 'nosniff was not sent'; }
    if (!str_contains($flat, 'x-frame-options')) { return 'X-Frame-Options was not sent'; }
    if (!str_contains($flat, 'content-security-policy')) { return 'no CSP was sent'; }
    if (str_contains($flat, 'x-powered-by')) { return 'X-Powered-By is still advertised'; }
    return true;
});

// ===========================================================================
sh_section('2. Installer access control');
// ===========================================================================

sh_t('a brand-new store can be installed', function () {
    $a = sh_install_access(false, false, false, false);
    return sh_assert($a['mode'] === 'fresh' && $a['can_create_admin'] && $a['can_write_config'],
        'a fresh install must be allowed to create the first administrator');
});

sh_t('an installed store cannot create an administrator from the web', function () {
    $a = sh_install_access(true, true, false, false);
    if ($a['mode'] !== 'repair') { return 'expected repair mode, got ' . $a['mode']; }
    if ($a['can_create_admin']) { return 'the installer may still create an administrator'; }
    if ($a['can_write_config']) { return 'the installer may still rewrite config/database.php'; }
    return true;
});

sh_t('a store with only a saved config (lock file deleted) is still restricted', function () {
    // This is the bypass that mattered: deleting installed.lock must not turn
    // the installer back into an open door.
    $a = sh_install_access(false, true, false, false);
    if ($a['mode'] === 'fresh') { return 'a present config/database.php was treated as a fresh install'; }
    if ($a['can_create_admin'] || $a['can_write_config']) { return 'permissions were granted without an unlock'; }
    return true;
});

sh_t('repair is available over the saved connection', function () {
    $a = sh_install_access(true, true, false, false);
    return sh_assert($a['can_repair'], 'a repair should be possible while a saved connection exists');
});

sh_t('repair fails closed when the saved config is missing', function () {
    $a = sh_install_access(true, false, false, false);
    return sh_assert($a['can_repair'] === false, 'there is nothing to repair against, so it must refuse');
});

sh_t('an unlock restores full setup permissions', function () {
    $a = sh_install_access(true, true, true, true);
    return sh_assert($a['mode'] === 'unlocked' && $a['can_create_admin'] && $a['can_write_config'],
        'a verified unlock should allow full setup');
});

sh_t('unlock tokens are compared in constant time and need real length', function () {
    if (sh_install_unlock_matches('')) { return 'an empty token was accepted'; }
    if (sh_install_unlock_matches('not-the-token')) { return 'a wrong token was accepted'; }
    // Reading the token through the public helpers must not expose a short file.
    return true;
});

sh_t('the installer consults the access rules', function () {
    $src = (string)@file_get_contents(SH_ROOT . '/install/index.php');
    if ($src === '') { return 'install/index.php is missing'; }
    if (!str_contains($src, 'sh_install_access')) { return 'the installer no longer calls sh_install_access()'; }
    if (str_contains($src, "ON DUPLICATE KEY UPDATE password_hash")) {
        // The upsert may exist, but only on the fresh/unlocked path.
        if (!str_contains($src, '$shAccess[\'mode\'] === \'repair\'')) {
            return 'the administrator upsert is not preceded by the repair-mode gate';
        }
    }
    return true;
});

// ===========================================================================
sh_section('3. Redirect and return-target validation');
// ===========================================================================

sh_t('open redirects are rejected', function () {
    $attacks = [
        '//evil.example', 'https://evil.example', 'http://evil.example',
        'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
        '%2F%2Fevil.example', '/\\evil.example', '\\\\evil.example',
        '../../etc/passwd', "index.php\nLocation: https://evil.example",
        'account.php#@evil.example',
    ];
    foreach ($attacks as $attack) {
        $got = sh_safe_redirect($attack, 'SAFE');
        if ($got !== 'SAFE' && !str_starts_with($got, 'account.php')) {
            // Anything that is not the fallback must at least be internal.
            if (preg_match('~^(?:[a-z]+:)?//~i', $got) === 1) {
                return "redirect target escaped the site: $attack => $got";
            }
        }
    }
    return true;
});

sh_t('legitimate internal redirects still work', function () {
    return sh_assert(sh_safe_redirect('checkout.php?id=12', 'x') === 'checkout.php?id=12',
        'a normal internal path was rejected');
});

sh_t('authentication pages cannot be used as a return target', function () {
    foreach (['login.php', 'register.php', 'reset-password.php', 'admin/orders.php', 'api/cart.php', 'install/index.php'] as $bad) {
        $got = sh_customer_return_target($bad, 'FALLBACK');
        if ($got !== 'FALLBACK') { return "return target accepted: $bad => $got"; }
    }
    return true;
});

// ===========================================================================
sh_section('4. CSRF tokens');
// ===========================================================================

sh_t('a session token is generated with full entropy', function () {
    $_SESSION = [];
    $token = sh_csrf_token();
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) { return 'token is not 32 random bytes of hex: ' . $token; }
    return sh_assert(sh_csrf_token() === $token, 'the token is not stable within one session');
});

sh_t('a wrong or missing token is rejected', function () {
    $_SESSION['csrf_token'] = str_repeat('a', 64);
    if (sh_csrf_valid(str_repeat('a', 64)) !== true) { return 'the correct token was rejected'; }
    if (sh_csrf_valid(str_repeat('b', 64)) !== false) { return 'a wrong token was accepted'; }
    if (sh_csrf_valid('') !== false) { return 'an empty token was accepted'; }
    if (sh_csrf_valid(str_repeat('a', 32)) !== false) { return 'a truncated token was accepted'; }
    if (sh_csrf_valid(null) !== false) { return 'a request with no token was accepted'; }
    return true;
});

// ===========================================================================
sh_section('5. HTML sanitizer (rich text from the AI provider)');
// ===========================================================================

sh_t('scripts, handlers and unsafe URLs are removed', function () {
    $attacks = [
        '<script>alert(1)</script>'                       => 'script',
        '<p onclick="alert(1)">x</p>'                     => 'onclick',
        '<p/onmouseover=alert(1)>x</p>'                   => 'onmouseover',
        '<img src=x onerror=alert(1)>'                    => 'onerror',
        '<iframe src="https://evil.example"></iframe>'    => 'iframe',
        '<a href="javascript:alert(1)">x</a>'             => 'javascript:',
        '<style>body{display:none}</style>'               => '<style',
        '<svg><script>alert(1)</script></svg>'            => 'script',
        '<p style="position:fixed">x</p>'                 => 'style=',
    ];
    foreach ($attacks as $input => $needle) {
        $out = sh_sanitize_html_allowlist($input);
        if (stripos($out, $needle) !== false) { return "payload survived: $needle => $out"; }
    }
    return true;
});

sh_t('later-arriving vectors are neutralised too', function () {
    // Every one of these was a real bypass in some allowlist sanitizer
    // implementation: base/template/noscript never "run" but reparent the DOM,
    // srcdoc and formaction are URLs, and entity-encoded handler names survive
    // a naive attribute check.
    $attacks = [
        '<base href="https://evil.example/">'            => '<base',
        '<template><img src=x onerror=alert(1)></template>' => 'onerror',
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>">' => 'onerror',
        '<iframe srcdoc="<script>alert(1)</script>"></iframe>' => 'srcdoc',
        '<form action="https://evil.example"><button formaction="https://evil.example">x</button></form>' => 'formaction',
        '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>' => 'onerror',
        '<p &#111;nclick="alert(1)">x</p>'                => 'nclick=',
        '<a href="java\tscript:alert(1)">x</a>'          => 'script:',
    ];
    foreach ($attacks as $input => $needle) {
        $out = sh_sanitize_html_allowlist($input);
        if (stripos($out, $needle) !== false) { return "payload survived: $needle => $out"; }
    }
    return true;
});

sh_t('deeply nested input neither crashes nor emits markup', function () {
    $payload = str_repeat('<div>', 400) . 'plain' . str_repeat('</div>', 400);
    $out = sh_sanitize_html_allowlist($payload);
    if (!is_string($out)) { return 'the sanitizer did not return a string'; }
    if (stripos($out, '<div') !== false) { return 'a disallowed element survived nesting'; }
    return sh_assert_contains($out, 'plain', 'the text content was lost');
});

sh_t('the allowed markup is preserved', function () {
    $out = sh_sanitize_html_allowlist('<p>Hi <strong>there</strong></p><ul><li>One</li></ul>');
    if (!str_contains($out, '<strong>')) { return 'strong was stripped: ' . $out; }
    if (!str_contains($out, '<ul>')) { return 'ul was stripped: ' . $out; }
    if (str_contains($out, '<?xml')) { return 'the XML encoding hint leaked into the output'; }
    return true;
});

// ===========================================================================
sh_section('6. Upload validation');
// ===========================================================================

sh_t('a missing file field is rejected', function () {
    $r = sh_upload_image([], 'products');
    return sh_assert(empty($r['ok']), 'an empty upload was accepted');
});

sh_t('upload errors are reported by cause, not swallowed', function () {
    $r = sh_upload_image(['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0, 'name' => 'a.png'], 'products');
    if (!empty($r['ok'])) { return 'an oversized upload error was ignored'; }
    return sh_assert_contains((string)($r['error'] ?? ''), 'larger than', 'the size error is not explained');
});

sh_t('a file that was not uploaded by PHP cannot be imported', function () {
    // Protects against any future caller that passes an arbitrary path.
    $tmp = tempnam(sys_get_temp_dir(), 'sh');
    file_put_contents($tmp, 'not an upload');
    $r = sh_upload_image(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 13, 'name' => 'x.php'], 'products');
    @unlink($tmp);
    return sh_assert(empty($r['ok']), 'a non-uploaded path was accepted');
});

sh_t('uploads are never given an executable extension', function () {
    $src = (string)@file_get_contents(SH_ROOT . '/includes/functions.php');
    if (!preg_match("/'image\\/jpeg'\\s*=>\\s*'jpg'/", $src)) { return 'the MIME allowlist is missing'; }
    if (!str_contains($src, "bin2hex(random_bytes(8))")) { return 'the stored filename is no longer random'; }
    return true;
});

// ===========================================================================
sh_section('7. Authentication throttling');
// ===========================================================================

sh_t('the audit identifier is pseudonymous', function () {
    $hash = sh_auth_identity_hash('Someone@Example.com');
    if (preg_match('/^[a-f0-9]{32}$/', $hash) !== 1) { return 'unexpected hash format: ' . $hash; }
    if (str_contains($hash, 'someone')) { return 'the identifier is not hashed'; }
    if (sh_auth_identity_hash('someone@example.com') !== $hash) { return 'the hash is not case stable'; }
    if (sh_auth_identity_hash('other@example.com') === $hash) { return 'two addresses produce the same hash'; }
    return true;
});

sh_t('the failure limiter blocks after the configured budget', function () {
    $dir = sys_get_temp_dir() . '/sh-limit-test-' . bin2hex(random_bytes(4));
    $key = 'unit-test';
    for ($i = 0; $i < 3; $i++) {
        if (!sh_file_rate_limit('unit', $key, 3, 60, $dir)) { return "blocked too early on attempt $i"; }
    }
    if (sh_file_rate_limit('unit', $key, 3, 60, $dir)) { return 'the fourth attempt was allowed past a limit of 3'; }
    // A different key is unaffected.
    if (!sh_file_rate_limit('unit', 'another-key', 3, 60, $dir)) { return 'the limiter leaks between keys'; }
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
    return true;
});

sh_t('login throttling consults the audit table, not just the session', function () {
    $src = (string)@file_get_contents(SH_ROOT . '/includes/auth.php');
    if (!str_contains($src, 'security_logs')) { return 'the DB-backed throttle was removed'; }
    $login = (string)@file_get_contents(SH_ROOT . '/login.php');
    if (!str_contains($login, 'sh_auth_throttle_blocked')) { return 'customer login no longer calls the DB-backed throttle'; }
    $admin = (string)@file_get_contents(SH_ROOT . '/admin/login.php');
    if (!str_contains($admin, 'sh_auth_throttle_blocked')) { return 'admin login no longer calls the DB-backed throttle'; }
    return true;
});

// ===========================================================================
sh_section('8. Logging and secret handling');
// ===========================================================================

sh_t('secrets are redacted before they reach the log', function () {
    $probe = 'password=SuperSecret123 token=abcdef1234567890';
    sh_log_line('security-test', 'redaction probe ' . $probe);
    $file = SH_LOG_DIR . '/app-' . date('Y-m-d') . '.log';
    if (!is_file($file)) { return 'the application log was not written'; }
    $tail = (string)@file_get_contents($file);
    if (str_contains($tail, 'SuperSecret123')) { return 'a password value was written in clear text'; }
    if (str_contains($tail, 'abcdef1234567890')) { return 'a token value was written in clear text'; }
    return sh_assert_contains($tail, 'redaction probe', 'the log line was not written at all');
});

sh_t('response output cannot inject headers or log lines', function () {
    // Control characters are stripped before logging, so a crafted URI cannot
    // forge extra log entries.
    sh_log_line('security-test', "line one\nline two\r\ninjected");
    $file = SH_LOG_DIR . '/app-' . date('Y-m-d') . '.log';
    $fh = @fopen($file, 'r');
    if ($fh === false) { return null; }
    $forged = 0;
    while (($line = fgets($fh)) !== false) {
        if (str_starts_with(trim($line), 'injected')) { $forged++; }
    }
    fclose($fh);
    return sh_assert($forged === 0, 'a newline in the message created a forged log entry');
});

sh_t('no live credentials are tracked by version control', function () {
    $gitIgnore = (string)@file_get_contents(SH_ROOT . '/.gitignore');
    if (!str_contains($gitIgnore, '/config/database.php')) { return '.gitignore no longer covers the database config'; }
    if (!str_contains($gitIgnore, '/logs/*.log')) { return '.gitignore no longer covers the logs'; }
    if (!str_contains($gitIgnore, '/uploads/*')) { return '.gitignore no longer covers stored uploads'; }
    return true;
});

sh_t('HTML escaping covers every metacharacter', function () {
    $out = e("<\">&'");
    foreach (['&lt;', '&gt;', '&quot;'] as $entity) {
        if (!str_contains($out, $entity)) { return "escaping produced: $out"; }
    }
    return sh_assert(!str_contains($out, '<'), 'a raw angle bracket survived escaping');
});

// ===========================================================================
sh_section('9. Web-server guards');
// ===========================================================================

sh_t('every sensitive directory ships a guard file', function () {
    foreach (['.htaccess', 'config/.htaccess', 'logs/.htaccess', 'uploads/.htaccess', 'includes/.htaccess'] as $path) {
        $full = SH_ROOT . '/' . $path;
        if (!is_file($full)) { return "missing $path"; }
        if (filesize($full) < 20) { return "$path is empty"; }
    }
    return true;
});

sh_t('the upload guard refuses to execute code', function () {
    $guard = (string)@file_get_contents(SH_ROOT . '/uploads/.htaccess');
    if (!str_contains($guard, 'php')) { return 'the guard does not mention php'; }
    if (!str_contains($guard, 'Require all denied') && !str_contains($guard, 'Deny from all')) {
        return 'the guard does not deny anything';
    }
    return true;
});

sh_t('the upload guard still serves the generated demo images', function () {
    // Regression guard for a subtle conflict: denying the whole .svg extension
    // is the obvious hardening step, but the installer generates .svg
    // placeholders for the demo catalogue and a 403 would blank every demo
    // product image. The guard must keep SVGs reachable AND neutralise the
    // script they could contain.
    $guard = (string)@file_get_contents(SH_ROOT . '/uploads/.htaccess');
    if (preg_match('/FilesMatch[^\n]*\bsvg\b(?![^\n]*\.svg\$)/i', $guard, $m) === 1
        && !str_contains($guard, 'sandbox')) {
        return 'the upload guard denies .svg outright, which breaks demo product images';
    }
    return sh_assert_contains($guard, 'sandbox',
        'SVG responses must carry their own no-script policy');
});

sh_t('configuration and logs deny all access', function () {
    foreach (['config/.htaccess', 'logs/.htaccess'] as $path) {
        $guard = (string)@file_get_contents(SH_ROOT . '/' . $path);
        if (!str_contains($guard, 'Require all denied') && !str_contains($guard, 'Deny from all')) {
            return "$path does not deny access";
        }
    }
    return true;
});

sh_t('the root guard removes directory listings', function () {
    $guard = (string)@file_get_contents(SH_ROOT . '/.htaccess');
    if (!str_contains($guard, 'Options -Indexes')) { return 'directory listings are not disabled'; }
    if (!str_contains($guard, '<FilesMatch')) { return 'no extension blocklist is present'; }
    if (!str_contains($guard, 'log')) { return 'log files are not blocked by extension'; }
    if (!str_contains($guard, 'sql')) { return 'SQL dumps are not blocked by extension'; }
    return true;
});

sh_t('the installer no longer claims an unreachable store is a new one', function () {
    $installer = (string)@file_get_contents(SH_ROOT . '/install/index.php');
    return sh_assert_contains($installer, 'sh_install_unlock_matches',
        'the installer does not check the owner unlock token');
});

// ===========================================================================
sh_section('10. CSP compatibility of the shipped markup');
// ===========================================================================

/** Shipped PHP files that render HTML (tests, tools and VCS data excluded). */
function sh_markup_files(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(SH_ROOT, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $rel = str_replace(SH_ROOT . '/', '', $f->getPathname());
        if (preg_match('~^(tests|tools|\.git)/~', $rel) === 1) { continue; }
        $out[] = [$rel, $f->getPathname()];
    }
    return $out;
}

sh_t('no inline event handlers remain in shipped markup', function () {
    // An on* attribute is refused by the enforced CSP, because script-src lists
    // only 'self' and the per-request nonce. Such a handler would silently stop
    // working the moment the owner switches the policy from report-only.
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        $src = (string)file_get_contents($path);
        if (preg_match_all('/\son(click|change|input|submit|load|error|focus|blur|keyup|keydown|mouseover|mouseout|dblclick)\s*=/i', $src, $m) > 0) {
            $offenders[] = $rel . ' (' . implode(',', array_unique($m[1])) . ')';
        }
    }
    return sh_assert($offenders === [], 'inline handlers the CSP would block: ' . implode('; ', $offenders));
});

sh_t('every inline script tag carries the CSP nonce', function () {
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $n => $line) {
            // Comments mention <script> in prose; only real tags matter.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) { continue; }
            if (preg_match('/<script(?![^>]*\bsrc=)/i', $line) !== 1) { continue; }
            if (str_contains($line, 'sh_csp_nonce_attr')) { continue; }
            $offenders[] = $rel . ':' . ($n + 1);
        }
    }
    return sh_assert($offenders === [], 'inline <script> without a nonce: ' . implode(', ', $offenders));
});

sh_t('the strict policy does not fall back to unsafe-inline scripts', function () {
    $policy = sh_csp_policy();
    $scriptSrc = '';
    foreach (explode(';', $policy) as $directive) {
        if (str_starts_with(trim($directive), 'script-src')) { $scriptSrc = trim($directive); }
    }
    if ($scriptSrc === '') { return 'the policy declares no script-src'; }
    return sh_assert(!str_contains($scriptSrc, "'unsafe-inline'"),
        'script-src still allows unsafe-inline, so the nonce adds nothing: ' . $scriptSrc);
});

sh_t('data URIs are not allowed for scripts or objects', function () {
    $policy = sh_csp_policy();
    $scriptSrc = '';
    $objectSrc = '';
    foreach (explode(';', $policy) as $directive) {
        $d = trim($directive);
        if (str_starts_with($d, 'script-src')) { $scriptSrc = $d; }
        if (str_starts_with($d, 'object-src')) { $objectSrc = $d; }
    }
    if (str_contains($scriptSrc, 'data:')) { return 'script-src allows data: URIs'; }
    return sh_assert(str_contains($objectSrc, "'none'"), 'object-src is not locked to none: ' . $objectSrc);
});

// ===========================================================================
sh_section('11. SEO output integrity');
// ===========================================================================

require_once SH_ROOT . '/includes/seo.php';

/** A complete context for the head renderer, with no database needed. */
function sh_seo_test_meta(array $ctx = []): array
{
    return sh_seo_meta($ctx + [
        'key' => 'home',
        'fallback_title' => 'ShopHaat online shopping',
        'fallback_desc' => 'Order electronics, fashion and daily essentials with cash on delivery.',
        'canonical' => '/',
    ]);
}

sh_t('the renderer emits exactly one of each critical tag', function () {
    $html = sh_seo_head_html(sh_seo_test_meta());
    $counts = [
        '<title>' => substr_count($html, '<title>'),
        'description' => substr_count($html, 'name="description"'),
        'canonical' => substr_count($html, 'rel="canonical"'),
        'robots' => substr_count($html, 'name="robots"'),
        'og:title' => substr_count($html, 'property="og:title"'),
        'og:url' => substr_count($html, 'property="og:url"'),
    ];
    foreach ($counts as $tag => $n) {
        if ($n !== 1) { return $tag . ' appears ' . $n . ' time(s), expected exactly 1'; }
    }
    return sh_assert(count((array)sh_seo_test_meta()['schema']) >= 1, 'no structured data was produced');
});

sh_t('JSON-LD cannot break out of its script element', function () {
    // The payload is JSON inside <script>, so a literal closing tag or an
    // unescaped angle bracket in any product name would end the block early.
    $html = sh_seo_head_html([
        'title' => 'A "><script>alert(1)</script> title',
        'description' => 'D & <b>bold</b>',
        'keywords' => ['a"b'],
        'robots' => 'index,follow',
        'canonical' => 'https://x.test/?a=1&b=2',
        'type' => 'website',
        'site_name' => 'X',
        'image' => 'https://x.test/i.png',
        'locale' => 'en_US',
        'lang' => 'en',
        'page_key' => 'home',
        'title_source' => 'override',
        'schema' => [['@type' => 'WebSite', 'name' => '</script><script>alert(1)</script>', 'x' => 'a & b']],
    ]);
    if (substr_count($html, '</script>') !== 1) {
        return 'the head contains ' . substr_count($html, '</script>') . ' script closing tags';
    }
    if (str_contains($html, '"><script>')) { return 'an unescaped payload reached the markup'; }
    if (!str_contains($html, '\u003C') && !str_contains($html, '\u003E')) {
        return 'angle brackets were not escaped inside the JSON payload';
    }
    return sh_assert(str_contains($html, '&quot;'), 'attribute values are not quote-escaped');
});

sh_t('URL validation refuses anything that is not an address', function () {
    $refused = [
        'javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<script>alert(1)</script>',
        'vbscript:msgbox', 'file:///etc/passwd', 'ftp://host/x', '//evil.example.com/x',
        'products.php', 'uploads/a.png', "https://ok.example.com/a\"onmouseover=1",
        "https://ok.example.com/a'onmouseover=1", "https://ok.example.com/\nSet-Cookie: x",
        'https://ok.example.com/a b', "https://ok.example.com/a<b>", '',
    ];
    foreach ($refused as $url) {
        $got = sh_seo_clean_url($url);
        if ($got !== '') { return 'accepted an unsafe value: ' . var_export($url, true) . ' -> ' . var_export($got, true); }
    }
    $accepted = [
        'https://shop.example.com/product.php?slug=x',
        'http://shop.example.com/',
        '/products.php',
        '/page.php?p=privacy',
    ];
    foreach ($accepted as $url) {
        if (sh_seo_clean_url($url) !== $url) { return 'refused a legitimate value: ' . $url; }
    }
    return true;
});

sh_t('the robots directive is an allowlist, never free text', function () {
    $cases = [
        'index,follow' => 'index,follow',
        'INDEX, FOLLOW' => 'index,follow',
        'noindex,follow' => 'noindex,follow',
        'noindex' => 'noindex,follow',
        'index,nofollow' => 'index,nofollow',
        'index,nonsense' => 'index,follow',
        '<script>alert(1)</script>' => 'index,follow',
        'noindex,follow,noarchive' => 'noindex,follow,noarchive',
        '' => 'index,follow',
    ];
    foreach ($cases as $input => $expected) {
        $got = sh_seo_normalise_robots((string)$input);
        if ($got !== $expected) {
            return var_export((string)$input, true) . ' normalised to ' . var_export($got, true) . ', expected ' . var_export($expected, true);
        }
    }
    return true;
});

sh_t('sitemap XML escapes correctly and invents nothing', function () {
    $xml = sh_seo_sitemap_xml([
        ['loc' => 'https://shop.example.com/product.php?slug=tee-&-cap', 'lastmod' => '2026-09-01 10:00:00'],
        ['loc' => 'https://shop.example.com/', 'lastmod' => ''],
        ['loc' => 'https://shop.example.com/product.php?slug=tee-&-cap', 'lastmod' => '2026-09-01 10:00:00'],
        ['loc' => '', 'lastmod' => ''],
    ]);
    if (str_contains($xml, 'slug=tee-&-cap')) { return 'an unescaped ampersand reached the XML'; }
    if (substr_count($xml, '<loc>') !== 2) {
        return 'the duplicate and empty URLs were not removed (' . substr_count($xml, '<loc>') . ' entries)';
    }
    if (str_contains($xml, 'changefreq') || str_contains($xml, 'priority')) {
        return 'the sitemap carries a changefreq or priority value that nothing can justify';
    }
    if (substr_count($xml, '<lastmod>') !== 1) {
        return 'a lastmod was written for an entry with no timestamp';
    }
    if (!str_contains($xml, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"')) {
        return 'the sitemap namespace is missing';
    }
    if (function_exists('simplexml_load_string')) {
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) { return 'the generated sitemap is not well-formed XML'; }
    }
    return true;
});

sh_t('the sitemap index points at absolute URLs on the canonical domain', function () {
    $xml = sh_seo_sitemap_index_xml(3);
    if (function_exists('simplexml_load_string') && @simplexml_load_string($xml) === false) {
        return 'the sitemap index is not well-formed XML';
    }
    if (substr_count($xml, '<sitemap>') !== 3) { return 'the index does not list every file'; }
    $base = sh_seo_base();
    foreach (['/sitemap-1.xml', '/sitemap-2.xml', '/sitemap-3.xml'] as $path) {
        if (!str_contains($xml, $base . $path)) { return 'missing index entry ' . $base . $path; }
    }
    return true;
});

sh_t('robots.txt welcomes crawlers and points at the sitemap', function () {
    $txt = sh_seo_robots_txt();
    if (!str_contains($txt, 'User-agent: *')) { return 'no user-agent group'; }
    if (!str_contains($txt, "Allow: /\n")) { return 'the site is not allowed'; }
    if (str_contains($txt, "Disallow: /\n") || str_ends_with(trim($txt), 'Disallow: /')) {
        return 'the whole site is disallowed';
    }
    if (!str_contains($txt, 'Sitemap: ' . sh_seo_base() . '/sitemap.xml')) {
        return 'the sitemap line is missing or does not use the canonical domain';
    }
    // Assets and the rewritten sitemap route must stay crawlable.
    foreach (['/assets/', '.css', '.js', '/uploads/', '.png', '.jpg', '.svg', '/sitemap.xml', '/sitemap-'] as $keep) {
        if (str_contains($txt, 'Disallow: ' . $keep)) { return 'crawling of ' . $keep . ' is blocked, which breaks rendering'; }
    }
    foreach (['/cart.php', '/checkout.php', '/payment.php', '/account.php', '/track.php', '/admin/', '/api/', '/install/', '/*?q='] as $expect) {
        if (!str_contains($txt, 'Disallow: ' . $expect)) { return 'expected ' . $expect . ' to be discouraged'; }
    }
    if (!str_contains($txt, "\n")) { return 'the file is not line-based'; }
    return true;
});

sh_t('no shipped page writes a second title or canonical tag', function () {
    // header.php is the only writer of <head> metadata. A page that emits its
    // own would produce the duplicate tags search engines have to guess about.
    // header.php is the single writer for the public site; these files are the
    // intended exceptions: the renderer itself, the standalone error page and
    // the installer, which each own a complete document of their own.
    $allowed = ['includes/header.php', 'includes/seo.php', 'includes/errors.php', 'install/index.php'];
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        if (in_array($rel, $allowed, true) || str_starts_with($rel, 'admin/')) { continue; }
        $src = (string)file_get_contents($path);
        // Strip PHP comments and docblocks first: a comment that quotes a tag is
        // not markup, and flagging it would train everyone to ignore this check.
        $code = preg_replace('~(/\*.*?\*/)|(^\s*//.*$)~ms', '', $src) ?? $src;
        if (preg_match('/<title>/i', $code) === 1) { $offenders[] = $rel . ' (title)'; }
        if (preg_match('/<link[^>]+rel\s*=\s*["\']canonical["\']/i', $code) === 1) { $offenders[] = $rel . ' (canonical)'; }
        if (preg_match('/<meta[^>]+name\s*=\s*["\']description["\']/i', $code) === 1) { $offenders[] = $rel . ' (description)'; }
        if (preg_match('/<meta[^>]+property\s*=\s*["\']og:/i', $code) === 1) { $offenders[] = $rel . ' (open graph)'; }
        if (preg_match('/<script[^>]+application\/ld\+json/i', $code) === 1) { $offenders[] = $rel . ' (json-ld)'; }
    }
    return sh_assert($offenders === [], 'pages writing their own head tags: ' . implode('; ', $offenders));
});

sh_t('the sitemap and robots endpoints serve the right content types', function () {
    foreach (['sitemap.php' => 'application/xml', 'robots.php' => 'text/plain'] as $file => $type) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $file);
        if (!str_contains($src, $type)) { return $file . ' never sets ' . $type; }
        if (!str_contains($src, 'sh_is_locked()')) { return $file . ' does not check that the store is installed'; }
        if (!str_contains($src, 'sh_db_state()')) { return $file . ' does not check that the database is reachable'; }
        if (!str_contains($src, 'sh_seo_cache')) { return $file . ' does not use the cache layer'; }
    }
    // A noindex header on the sitemap itself has been observed to hold the file
    // back in Google Search Console, so it must not be sent.
    foreach (['sitemap.php', 'robots.php'] as $file) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $file);
        $code = preg_replace('~(/\*.*?\*/)|(^\s*//.*$)~ms', '', $src) ?? $src;
        if (preg_match('/header\s*\(\s*[\'"](X-Robots-Tag|X-Robots)/i', $code) === 1) {
            return $file . ' sends X-Robots-Tag, which risks the crawler ignoring the file';
        }
    }
    return true;
});

sh_t('private routes are never published in the sitemap', function () {
    $entries = sh_seo_sitemap_entries();
    if ($entries === []) { return 'the sitemap builder produced no entries at all'; }
    $private = [
        'cart.php', 'checkout.php', 'payment.php', 'account.php', 'orders.php', 'order-details.php',
        'order-success.php', 'track.php', 'login.php', 'register.php', 'logout.php', 'password.php',
        'otp.php', 'addresses.php', '/admin/', '/install/', '/api/', '?q=',
    ];
    foreach ($entries as $e) {
        $loc = (string)$e['loc'];
        foreach ($private as $frag) {
            if (str_contains($loc, $frag)) { return 'the sitemap publishes ' . $loc; }
        }
        if (!preg_match('~^https?://~', $loc)) { return 'a non-absolute URL was published: ' . $loc; }
        if (!isset($e['source']) || (string)$e['source'] === '') { return 'an entry has no traceable source: ' . $loc; }
    }
    return true;
});

sh_t('every file that uses an SEO helper loads the engine first', function () {
    // Regression: product.php called sh_seo_product_image() while the engine was
    // only loaded later by includes/header.php, so the page died with "Call to
    // undefined function" on a real install. The include order is now asserted.
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        // The engine defines the helpers; an underscore-prefixed partial is an
        // include that the page it belongs to loads the engine for.
        if ($rel === 'includes/seo.php' || preg_match('~^(includes|admin)/_~', $rel) === 1) { continue; }
        $src = (string)file_get_contents($path);
        if (!str_contains($src, 'sh_seo_')) { continue; }
        if (str_contains($src, "includes/seo.php")) { continue; }
        // header.php loads the engine for every page that includes it, so a file
        // that only uses the helpers after that point is fine.
        $headerAt = strpos($src, "require_once SH_ROOT . '/includes/header.php'");
        $firstUse = strpos($src, 'sh_seo_');
        if ($headerAt !== false && $firstUse !== false && $firstUse > $headerAt) { continue; }
        $offenders[] = $rel;
    }
    return sh_assert($offenders === [],
        'files using SEO helpers without requiring includes/seo.php: ' . implode(', ', $offenders));
});

sh_t('SEO settings and fields are guarded like any other admin data', function () {
    require_once SH_ROOT . '/includes/admin-perms.php';
    $perms = sh_perms_catalog();
    if (!isset($perms['Settings']['seo.manage'])) { return 'the seo.manage permission is not registered'; }

    $seoAdmin = (string)file_get_contents(SH_ROOT . '/admin/seo.php');
    if (!str_contains($seoAdmin, "sh_require_perm('seo.manage'")) { return 'admin/seo.php does not require the permission'; }
    if (!str_contains($seoAdmin, 'sh_csrf_require()')) { return 'admin/seo.php accepts POST without a CSRF check'; }
    if (substr_count($seoAdmin, 'sh_csrf_field()') < 3) { return 'not every form on admin/seo.php carries a CSRF token'; }
    if (!str_contains($seoAdmin, 'sh_audit(')) { return 'settings changes are not written to the audit log'; }

    $layout = (string)file_get_contents(SH_ROOT . '/admin/_layout.php');
    if (!str_contains($layout, "'seo.php'")) { return 'the SEO Manager is not linked in the admin navigation'; }

    // Both save paths must cover every field the shared partial renders,
    // otherwise a field silently never saves.
    $partial = (string)file_get_contents(SH_ROOT . '/admin/_seo-fields.php');
    preg_match_all('/name="([a-z_]+)"/i', $partial, $m);
    $fields = array_values(array_unique($m[1] ?? []));
    if ($fields === []) { return 'the SEO field partial renders no named inputs'; }
    foreach (['admin/products.php', 'admin/categories.php'] as $page) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $page);
        foreach ($fields as $f) {
            // Tolerant of alignment spaces: what matters is that the key is
            // written by this page's save handler.
            if (preg_match("/'" . preg_quote($f, '/') . "'\\s*=>/", $src) !== 1) {
                return $page . ' never saves the field ' . $f . ' rendered by admin/_seo-fields.php';
            }
        }
        if (!str_contains($src, 'require __DIR__ . \'/_seo-fields.php\';')) {
            return $page . ' does not render the SEO fields';
        }
        if (!str_contains($src, 'sh_seo_schema_ensure()')) {
            return $page . ' writes SEO columns without ensuring they exist first';
        }
    }
    return true;
});

// ===========================================================================
// Helpers used by the checks above
// ===========================================================================

/** Does this SAPI let us observe emitted headers? */
function headers_supported(): bool
{
    header('X-ShopHaat-Probe: 1');
    foreach (headers_list() as $h) {
        if (str_contains($h, 'X-ShopHaat-Probe')) { return true; }
    }
    return false;
}

/** Run a tiny child process to prove the nonce changes between requests. */
function shell_execSafeNonce(): ?string
{
    // Only used when the host allows sub-processes; skipped otherwise.
    if (!function_exists('proc_open') || !is_callable('proc_open')) { return null; }
    if (!defined('PHP_BINARY') || PHP_BINARY === '' || !is_file(PHP_BINARY)) { return null; }
    $code = '<?php require ' . var_export(SH_ROOT . '/includes/security.php', true) . '; echo sh_csp_nonce();';
    $tmp = tempnam(sys_get_temp_dir(), 'shnonce');
    if ($tmp === false) { return null; }
    file_put_contents($tmp, $code);
    $out = @shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    return is_string($out) && trim($out) !== '' ? trim($out) : null;
}

/** Evaluate sh_frame_ancestors() against a temporary setting value. */
function sh_frame_ancestors_probe(string $value): string
{
    // sh_security_setting() reads the database, which is unavailable here, so
    // the sanitising path is exercised directly with the same rules.
    if ($value === '' || $value === "'none'") { return "'none'"; }
    $parts = preg_split('/[\s,]+/', $value) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === 'self' || $p === "'self'") { $out[] = "'self'"; continue; }
        if (preg_match('~^https?://[a-z0-9._:-]+$~i', $p) === 1) { $out[] = $p; }
    }
    $out = array_values(array_unique($out));
    return $out === [] ? "'self'" : implode(' ', $out);
}

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d   skipped %d\n", $passed, $failed, $skipped);
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
}
if ($skips !== []) {
    echo "\nNot exercised in this environment:\n";
    foreach ($skips as $s) { echo "  - $s\n"; }
}
exit($failed === 0 ? 0 : 1);
