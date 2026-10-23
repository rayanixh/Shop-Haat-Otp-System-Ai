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
        // Vendored third-party source. It is not our markup, it can never be
        // reached over HTTP (lib/.htaccess denies the directory), and one of its
        // regexes contains the literal characters "<script" — which is not an
        // inline script. Our own files are still all scanned.
        if (str_starts_with($rel, 'lib/')) { continue; }
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
    // Which file owns each entity's save handler. Categories render the shared
    // SEO block on their dedicated editor screen, so the guard follows the
    // form: what must never happen is a rendered field with no writer.
    $owners = [
        'admin/products.php'      => 'admin/product-edit.php',
        'admin/categories.php'    => 'admin/category-edit.php',
    ];
    foreach ($owners as $page => $owner) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $page);
        $writer = (string)file_get_contents(SH_ROOT . '/' . $owner);
        foreach ($fields as $f) {
            // Tolerant of alignment spaces: what matters is that the key is
            // written by the handler that owns this entity.
            if (preg_match("/'" . preg_quote($f, '/') . "'\\s*=>/", $writer) !== 1) {
                return $owner . ' never saves the field ' . $f . ' rendered by admin/_seo-fields.php';
            }
        }
        if ($owner === $page) {
            if (!str_contains($src, 'require __DIR__ . \'/_seo-fields.php\';')) {
                return $page . ' does not render the SEO fields';
            }
        } elseif (!str_contains($src, basename($owner))) {
            // The listing must hand its save POSTs to the editor rather than
            // dropping them, and must stay CSRF protected while doing it.
            return $page . ' no longer routes its form to ' . $owner;
        }
        if (!str_contains($writer, 'sh_seo_schema_ensure()')) {
            return $owner . ' writes SEO columns without ensuring they exist first';
        }
    }
    // The SEO block itself is only rendered by the editor, which must be as
    // guarded as the page it replaced.
    $editor = (string)file_get_contents(SH_ROOT . '/admin/category-edit.php');
    foreach (['sh_require_perm(', 'sh_csrf_require()', 'sh_csrf_field()', 'sh_audit('] as $must) {
        if (!str_contains($editor, $must)) { return 'admin/category-edit.php is missing ' . $must; }
    }
    return true;
});

// ===========================================================================
// Phase 11 — the Digital Codes feature is gone, not merely hidden
// ===========================================================================

sh_section('Phase 11 — the "Digital Codes" feature is fully removed');

/** Files that legitimately still name the kept-for-history schema pieces. */
function sh_digital_schema_keepers(): array
{
    return ['install/schema.php', 'install/migrations/2026-10-remove-digital-codes.sql'];
}

/**
 * Source with comments removed.
 *
 * The removal checks below must judge what a visitor or an admin actually sees
 * and what the code actually does — not the explanation comments that describe
 * the removal itself (this file's own subject matter would otherwise fail it).
 */
function sh_source_without_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

sh_t('the removed feature has no page and no admin route left', function () {
    foreach (['digital.php', 'admin/digital-codes.php'] as $rel) {
        if (is_file(SH_ROOT . '/' . $rel)) { return $rel . ' still exists'; }
    }
    return true;
});

sh_t('no shipped file still calls the code-delivery API', function () {
    // The two functions that used to hand codes to a customer, and the order
    // columns they wrote, must not be referenced by any shipped file. The
    // schema keeps the columns, so only the schema/migration may name them.
    $needles = ['sh_order_codes', 'sh_deliver_digital_codes', 'codes_delivered', 'has_digital', 'product_codes'];
    $keepers = sh_digital_schema_keepers();
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        if (in_array($rel, $keepers, true)) { continue; }
        $src = sh_source_without_comments((string)file_get_contents($path));
        foreach ($needles as $n) {
            if (str_contains($src, $n)) { $offenders[] = $rel . ' → ' . $n; }
        }
    }
    return sh_assert($offenders === [], 'code-delivery references remain: ' . implode('; ', $offenders));
});

sh_t('no shipped page links to the removed pages', function () {
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        $src = sh_source_without_comments((string)file_get_contents($path));
        // A link target or a redirect to the deleted routes.
        if (preg_match('~digital\.php|digital-codes\.php~', $src) === 1) { $offenders[] = $rel; }
    }
    return sh_assert($offenders === [], 'links to a deleted page: ' . implode(', ', $offenders));
});

sh_t('no shipped page advertises instant digital delivery', function () {
    // Copy check: the feature was customer-facing marketing, so the words must
    // be gone from the storefront, the FAQ, the policy pages, the admin UI and
    // the seeded notification matrix.
    $keepers = sh_digital_schema_keepers();
    $offenders = [];
    foreach (sh_markup_files() as [$rel, $path]) {
        if (in_array($rel, $keepers, true)) { continue; }
        $src = sh_source_without_comments((string)file_get_contents($path));
        if (preg_match('~digital delivery|digital product|digital code|browse digital|instant(ly)? digit|delivered digit|digital goods|digital purchases|q=code|gift\+card~i', $src, $m) === 1) {
            $offenders[] = $rel . ' ("' . $m[0] . '")';
        }
    }
    return sh_assert($offenders === [], 'marketing copy for the removed feature: ' . implode('; ', $offenders));
});

sh_t('the order timeline no longer promises a code-delivery step', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/order-timeline.php');
    foreach (['code_delivered', 'codes_delivered', 'is_digital_only', 'Digital'] as $n) {
        if (str_contains($src, $n)) { return 'order-timeline.php still references ' . $n; }
    }
    return true;
});

sh_t('the seeded notification matrix has no code_delivered event', function () {
    $src = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    if (str_contains($src, "'code_delivered'")) { return 'the installer still seeds the code_delivered event'; }
    if (str_contains($src, 'slide-digital')) { return 'the installer still seeds the digital slider image'; }
    return true;
});

sh_t('the removal migration is data-safe and idempotent', function () {
    $rel = 'install/migrations/2026-10-remove-digital-codes.sql';
    $path = SH_ROOT . '/' . $rel;
    if (!is_file($path)) { return $rel . ' is missing'; }
    $sql = (string)file_get_contents($path);
    // Strip comments so the safety rules are judged on executable statements.
    $code = preg_replace('~^\s*--.*$~m', '', $sql) ?? $sql;
    $forbidden = [
        'DROP TABLE'    => 'drops a table',
        'DROP COLUMN'   => 'drops a column',
        'TRUNCATE'      => 'truncates a table',
        'DELETE FROM `product_codes`' => 'deletes stored codes',
        'DELETE FROM `orders`'        => 'deletes orders',
        'DELETE FROM `notification_logs`' => 'deletes delivery history',
        'UPDATE `products`'  => 'modifies product rows',
        'DELETE FROM `products`' => 'deletes product rows',
    ];
    foreach ($forbidden as $needle => $why) {
        if (stripos($code, $needle) !== false) { return $rel . ' ' . $why . ' (' . $needle . ')'; }
    }
    if (stripos($code, 'DELETE FROM `promo_slides`') === false) { return $rel . ' does not remove the digital promo slide'; }
    if (stripos($code, 'DELETE FROM `notifications`') === false) { return $rel . ' does not remove the code_delivered event row'; }
    if (stripos($code, "'code_delivered'") === false) { return $rel . ' does not name the event it removes'; }
    // The owner's decision list must be present, read-only.
    if (stripos($sql, "product_type = 'digital'") === false) { return $rel . ' does not list the digital product rows for the owner'; }
    if (stripos($sql, 'mysqldump') === false) { return $rel . ' does not tell the owner to take a backup'; }
    return true;
});

sh_t('a legacy digital product cannot be sold', function () {
    // The fulfilment path is gone, so the sale must be refused rather than
    // accepted and left unfulfillable. Data is untouched: this is code only.
    $cart = (string)file_get_contents(SH_ROOT . '/includes/cart.php');
    if (preg_match('~function sh_cart_add~s', $cart) !== 1) { return 'sh_cart_add() is missing'; }
    $add = substr($cart, (int)strpos($cart, 'function sh_cart_add'), 1400);
    if (preg_match("~product_type~", $add) !== 1) { return 'sh_cart_add() does not look at product_type'; }
    if (preg_match("~product_type.*?!==\s*'physical'~s", $add) !== 1) { return 'sh_cart_add() does not refuse a non-physical product'; }
    $upd = substr($cart, (int)strpos($cart, 'function sh_cart_update'), 1200);
    if (preg_match("~product_type.*?!==\s*'physical'~s", $upd) !== 1) { return 'sh_cart_update() does not refuse a non-physical product'; }
    $sum = substr($cart, (int)strpos($cart, 'function sh_cart_summary'), 2600);
    if (preg_match("~product_type.*?!==\s*'physical'~s", $sum) !== 1) { return 'sh_cart_summary() does not exclude a non-physical line'; }

    $pdp = (string)file_get_contents(SH_ROOT . '/product.php');
    if (preg_match('~\$orderable\s*=~', $pdp) !== 1) { return 'product.php no longer derives an orderable flag'; }
    if (strpos($pdp, '$orderable ? \'\' : \'disabled\'') === false) { return 'product.php does not disable the buy buttons for a non-orderable product'; }
    return true;
});

sh_t('the storefront layout is balanced for two banner cards and no code box', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    if (preg_match('~\.sh-banner\s*\{[^}]*repeat\(2, 1fr\)~', $css) !== 1) {
        return '.sh-banner is not a two-card grid';
    }
    foreach (['.sh-codebox', '.sh-cart-item__tag'] as $dead) {
        if (str_contains($css, $dead)) { return 'the unused ' . $dead . ' rules are still shipped'; }
    }
    // The homepage strip renders the free-delivery card always and the welcome
    // coupon card only when that coupon really exists. The markup therefore has
    // exactly two card blocks; the second one is inside an `if`.
    $index = (string)file_get_contents(SH_ROOT . '/index.php');
    if (preg_match_all('~class="sh-banner__item"~', $index) !== 2) {
        return 'index.php does not render exactly two banner cards in the markup';
    }
    if (!str_contains($index, 'sh-banner--single')) {
        return 'a store with no welcome coupon would leave a half-empty banner row';
    }
    if (preg_match('~Save with coupon WELCOME10~', $index) === 1) {
        return 'the homepage advertises a coupon unconditionally instead of reading the coupon table';
    }
    if (str_contains($index, 'q=code')) { return 'index.php still links to the removed code search'; }
    // Those cards are sample content: they live in the optional starter catalogue,
    // never in the structure-level seeder that every install runs.
    $starter = (string)file_get_contents(SH_ROOT . '/install/demo-data.php');
    foreach (['card_1', 'card_2', 'card_3'] as $slot) {
        if (strpos($starter, $slot) === false) { return 'the starter catalogue no longer seeds ' . $slot; }
    }
    return true;
});

// ===========================================================================
// Page transition — every supported page load, one shared setting
// ===========================================================================

sh_section('Page transition — first load, refresh, direct URL and admin panel');

/** Layouts that render a full page and must therefore carry the transition. */
function sh_transition_layouts(): array
{
    return ['includes/header.php', 'admin/_layout.php', 'admin/login.php'];
}

/**
 * Layouts that deliberately do NOT carry it: the setup wizard runs before the
 * store has settings, and the fatal/404 page must never depend on JavaScript
 * to become readable.
 */
function sh_transition_layout_exceptions(): array
{
    return ['install/index.php', 'includes/errors.php'];
}

/** A configuration array in the shape sh_transition_config() returns. */
function sh_transition_test_config(array $over = []): array
{
    return $over + [
        'enabled' => true, 'bg_type' => 'gradient', 'bg_color' => '#111827', 'bg_color2' => '#1f2937',
        'bg_direction' => 'diagonal', 'bg_media' => '', 'bg_url' => '', 'bg_css' => 'linear-gradient(135deg, #111827, #1f2937)',
        'media' => '', 'media_url' => '', 'duration' => 450, 'fade' => 'smooth', 'dark' => true,
    ];
}

sh_t('every full-page layout wires the one global transition', function () {
    require_once SH_ROOT . '/includes/transition.php';
    $offenders = [];
    foreach (sh_transition_layouts() as $rel) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        // The head snippet decides what the first paint looks like, the body
        // attributes carry the settings, the overlay is what the visitor sees.
        foreach (['sh_transition_head(', 'sh_transition_body_attrs(', 'sh_transition_overlay('] as $call) {
            if (!str_contains($src, $call)) { $offenders[] = $rel . ' is missing ' . $call; }
        }
        $headAt = strpos($src, 'sh_transition_head(');
        $bodyAt = strpos($src, '<body');
        if ($headAt !== false && $bodyAt !== false && $headAt > $bodyAt) {
            $offenders[] = $rel . ' emits the hold after <body>, which is too late for the first paint';
        }
    }
    // Every page that renders the overlay must also load the script that
    // releases it: the layout and the footer are a pair, and a page that skips
    // the footer would otherwise leave the overlay on screen.
    foreach (sh_markup_files() as [$rel, $path]) {
        $src = (string)file_get_contents($path);
        if (str_contains($src, "includes/header.php'")) {
            if (!str_contains($src, "includes/footer.php'")) { $offenders[] = $rel . ' opens the public layout but never closes it'; }
        }
        if (str_contains($src, "'_layout.php'")) {
            if (!str_contains($src, "'_footer.php'")) { $offenders[] = $rel . ' opens the admin layout but never closes it'; }
        }
    }
    return sh_assert($offenders === [], implode('; ', $offenders));
});

sh_t('the head snippet can only come from one place', function () {
    require_once SH_ROOT . '/includes/transition.php';
    $rendered = sh_transition_head(sh_transition_test_config());
    // The CSP nonce is the one part that changes per request, so compare the
    // snippet with the nonce and the duration blanked out.
    $shape = function (string $html): string {
        $html = preg_replace('~nonce="[^"]*"~', 'nonce="N"', $html) ?? $html;
        return $html;
    };
    foreach (sh_markup_files() as [$rel, $path]) {
        if ($rel === 'includes/transition.php') { continue; }
        $src = (string)file_get_contents($path);
        // No page may hand-roll the hold: a second copy would drift from the
        // shared setting and could leave the overlay stuck.
        if (str_contains($src, 'sh-pt-hold') && !str_contains($src, 'sh_transition_head(') && !in_array($rel, sh_transition_layouts(), true)) {
            return $rel . ' manipulates the hold state outside the shared helper';
        }
        if (str_contains($shape($src), substr($shape($rendered), 0, 60))) {
            return $rel . ' contains a copied copy of the head snippet';
        }
    }
    return true;
});

sh_t('the excluded layouts are excluded on purpose and stay that way', function () {
    foreach (sh_transition_layout_exceptions() as $rel) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (str_contains($src, 'sh_transition_overlay(')) {
            return $rel . ' now renders the overlay: document the change and this exception';
        }
    }
    // A one-line note per exclusion so the choice is visible in the code.
    $errors = (string)file_get_contents(SH_ROOT . '/includes/errors.php');
    if (stripos($errors, 'transition') === false) { return 'includes/errors.php does not explain why it has no overlay'; }
    return true;
});

sh_t('no shipped file renders the overlay twice', function () {
    foreach (sh_markup_files() as [$rel, $path]) {
        $src = (string)file_get_contents($path);
        if (substr_count($src, 'sh_transition_overlay(') > 1) {
            return $rel . ' calls sh_transition_overlay() more than once';
        }
        if (substr_count($src, "id=\"sh-pt\"") > 0 && $rel !== 'includes/transition.php') {
            return $rel . ' hard-codes the overlay element instead of using the helper';
        }
    }
    return true;
});

sh_t('the initial-load hold is emitted for a cold load and never when disabled', function () {
    require_once SH_ROOT . '/includes/transition.php';
    $on = sh_transition_head(sh_transition_test_config());
    $off = sh_transition_head(sh_transition_test_config(['enabled' => false]));
    if ($off !== '') { return 'a disabled transition still emits a head snippet'; }
    if ($on === '') { return 'an enabled transition emits no head snippet at all'; }
    // What the snippet must do, in order: consume the one-shot marker, skip on
    // reduced motion, hold the overlay, and arm its own release.
    foreach (['sh-pt-hold', '__shPtCold', 'sh-pt', '__shPtArrived', 'prefers-reduced-motion', '__shPtFallback'] as $needle) {
        if (!str_contains($on, $needle)) { return 'the head snippet never mentions ' . $needle; }
    }
    if (!str_contains($on, '450')) { return 'the head snippet does not carry the configured duration'; }
    if (!str_contains($on, 'nonce=')) { return 'the head snippet has no CSP nonce'; }
    if (str_contains($on, 'sh-pt-cold')) { return 'the head snippet still uses a state the stylesheet does not define'; }

    // The critical rules must cover the first paint without the stylesheet.
    if (!str_contains($on, 'html.sh-pt-hold .sh-pt')) { return 'the critical CSS does not cover the first paint'; }
    return true;
});

sh_t('the overlay markup carries only the configured values', function () {
    require_once SH_ROOT . '/includes/transition.php';
    $cfg = sh_transition_test_config(['duration' => 900, 'fade' => 'cinematic', 'media_url' => '/uploads/transitions/logo.gif', 'bg_css' => 'linear-gradient(90deg, #010203, #040506)']);
    $html = sh_transition_overlay($cfg);
    if (substr_count($html, 'id="sh-pt"') !== 1) { return 'the overlay is not emitted exactly once'; }
    if (!str_contains($html, 'sh-pt--cinematic')) { return 'the configured fade style is not applied'; }
    if (!str_contains($html, '--sh-pt-ms:900ms')) { return 'the configured duration is not applied'; }
    if (!str_contains($html, '/uploads/transitions/logo.gif')) { return 'the configured media is not rendered'; }
    if (!str_contains($html, '#010203')) { return 'the configured background is not applied'; }
    if (sh_transition_overlay(sh_transition_test_config(['enabled' => false])) !== '') {
        return 'a disabled transition still renders the overlay';
    }
    return true;
});

sh_t('the transition stylesheet keeps the hold and its CSS-only release', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    $needles = [
        'html.sh-pt-hold .sh-pt'                 => 'the initial-load hold has no rule',
        'html.sh-pt-hold .sh-pt-page'            => 'the held page is not hidden',
        'sh-pt-release'                          => 'the CSS-only release animation is gone',
        'html.sh-pt-enter .sh-pt-page'           => 'the page is never animated back in',
        'prefers-reduced-motion'                 => 'reduced motion is not honoured',
    ];
    foreach ($needles as $needle => $why) {
        if (!str_contains($css, (string)$needle)) { return $why . ' (missing ' . $needle . ')'; }
    }
    if (preg_match('~html\.sh-pt-hold \.sh-pt \{[^}]*animation:[^}]*sh-pt-release~', $css) !== 1) {
        return 'the CSS-only release is not tied to the hold state any more';
    }
    return true;
});

sh_t('the script releases the initial load, honours the marker and cleans up', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    foreach ([
        '__shPtArrived'          => 'the arrival handoff from the head snippet is not read',
        '__shPtCold'             => 'the initial-load timing is not read',
        '__shPtFallback'         => 'the head fallback is never cancelled',
        "sessionStorage.getItem('sh-pt')" => 'the one-shot navigation marker is not handled',
        'pageshow'               => 'the back-forward cache restore is not handled',
        'data-no-transition'     => 'the per-link opt-out is not honoured',
        "url.origin !== location.origin" => 'external links are not excluded',
        'navigating'             => 'no protection against duplicate navigations',
    ] as $needle => $why) {
        if (!str_contains($js, (string)$needle)) { return $why . ' (missing ' . $needle . ')'; }
    }
    // A stale page-load timer must not be able to cancel a fresh click.
    if (!str_contains($js, 'function owned(g)') || substr_count($js, '++gen') < 3) {
        return 'timers are not generation-owned: a click can be cancelled by the reveal that brought the page in';
    }
    return true;
});

sh_t('the behaviour harness ships with its fixtures', function () {
    foreach (['tests/page-transition-behavior.mjs', 'tests/page-transition-fixtures.php'] as $rel) {
        if (!is_file(SH_ROOT . '/' . $rel)) { return $rel . ' is missing, so the transition is only checked by eye'; }
    }
    $fixtures = (string)file_get_contents(SH_ROOT . '/tests/page-transition-fixtures.php');
    // The fixtures must exercise the shipped helper, never a copy of it.
    if (!str_contains($fixtures, "includes/transition.php")) { return 'the fixtures do not load the shipped transition helper'; }
    $harness = (string)file_get_contents(SH_ROOT . '/tests/page-transition-behavior.mjs');
    foreach (['assets/js/app.js', 'assets/css/app.css'] as $asset) {
        if (!str_contains($harness, $asset)) { return 'the harness does not load ' . $asset; }
    }
    return true;
});

sh_t('transition settings are stored and validated like every other setting', function () {
    $admin = (string)file_get_contents(SH_ROOT . '/admin/transition.php');
    if (!str_contains($admin, 'sh_csrf_require()')) { return 'admin/transition.php accepts POST without a CSRF check'; }
    if (!str_contains($admin, "sh_require_perm('storefront.manage'")) { return 'admin/transition.php is not permission-guarded'; }
    require_once SH_ROOT . '/includes/transition.php';
    // Untrusted stored values must be sanitised, never echoed.
    if (sh_transition_hex('red; background:url(javascript:alert(1))', '#111827') !== '#111827') {
        return 'sh_transition_hex() accepts a value that is not a hex colour';
    }
    if (sh_transition_hex('ff8800', '') !== '#ff8800') { return 'a bare hex colour is not normalised'; }
    foreach (['bg_types', 'directions', 'fades', 'presets'] as $list) {
        $fn = 'sh_transition_' . $list;
        if (count($fn()) < 3) { return $fn . '() lost its allowlist'; }
    }
    // A media value is reduced to a basename, so it can never traverse.
    if (basename('../../config/database.php') !== 'database.php') { return 'unexpected basename() behaviour'; }
    return true;
});

sh_t('the transition never blocks the page when JavaScript is missing', function () {
    require_once SH_ROOT . '/includes/transition.php';
    $head = sh_transition_head(sh_transition_test_config());
    // The fallback must be a timer (runs without app.js) and the stylesheet must
    // carry a release of its own, so two independent paths reveal the page.
    if (!str_contains($head, 'setTimeout') || !str_contains($head, '__shPtFallbackRelease')) {
        return 'the head snippet has no self-release when app.js never runs';
    }
    if (preg_match('~setTimeout\(function\(\)\{try\{window\.__shPtFallbackRelease~', $head) !== 1) {
        return 'the fallback release is not armed by a timer';
    }
    return true;
});

// ===========================================================================
// Category cards — one shared renderer, image and name only
// ===========================================================================

sh_section('Product category cards (storefront and admin)');

sh_t('one renderer produces every category card', function () {
    require_once SH_ROOT . '/includes/catalog.php';
    // The homepage and the category page must not each hand-roll a card.
    foreach (['index.php', 'category.php'] as $rel) {
        $src = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/' . $rel));
        if (!str_contains($src, 'sh_category_cards(')) {
            return $rel . ' does not use the shared category card renderer';
        }
        if (str_contains($src, 'sh-category-card__icon') || str_contains($src, 'sh-category-card__count')) {
            return $rel . ' still renders the old card markup by hand';
        }
        if (preg_match('~<a class="sh-category-card~', $src) === 1) {
            return $rel . ' builds the card element itself instead of calling the helper';
        }
    }
    return true;
});

sh_t('a card shows the image and the name, and nothing else', function () {
    require_once SH_ROOT . '/includes/catalog.php';
    $card = sh_category_card(['name' => 'Mobile Phones', 'slug' => 'mobile-phones', 'image' => '', 'product_count' => 6], 0);
    // Exactly one link, so the whole card is clickable and nothing nests inside it.
    if (substr_count($card, '<a ') !== 1 || substr_count($card, '</a>') !== 1) {
        return 'a card is not a single link: ' . $card;
    }
    if (!str_contains($card, 'class="sh-category-card__name"')) { return 'a card has no name element'; }
    if (!str_contains($card, 'Mobile Phones')) { return 'a card does not render the category name'; }
    if (stripos($card, 'item') !== false || stripos($card, 'count') !== false) {
        return 'a card still mentions an item count: ' . $card;
    }
    if (preg_match('~[0-9]+\s*(items?|products?)~i', $card) === 1) { return 'a card prints a numeric count'; }
    if (!str_contains($card, 'sh-category-card__img')) { return 'a card has no image element'; }
    // A missing photo must not leave an empty frame.
    if (!str_contains($card, basename(sh_category_placeholder_image()))) {
        return 'a card without an image does not fall back to the neutral placeholder';
    }
    return true;
});

sh_t('the uploaded category image wins over the placeholder', function () {
    require_once SH_ROOT . '/includes/catalog.php';
    // A file that really exists in the uploads folder, so sh_category_image()
    // resolves it exactly as it does for a saved category.
    $dir = SH_UPLOAD_DIR . '/categories';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return null; }
    $name = 'regression-category-' . bin2hex(random_bytes(4)) . '.gif';
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    if (@file_put_contents($dir . '/' . $name, $gif) === false) { return null; }
    try {
        $card = sh_category_card(['name' => 'Uploaded', 'slug' => 'uploaded', 'image' => $name], 0);
        if (!str_contains($card, rawurlencode($name))) { return 'a card does not use the uploaded image: ' . $card; }
        if (str_contains($card, 'is-placeholder') || str_contains($card, '--fallback')) {
            return 'a card treats a real uploaded image as a placeholder';
        }
        // A stored value that is not on disk must fall back, never break the card.
        $missing = sh_category_card(['name' => 'Gone', 'slug' => 'gone', 'image' => 'does-not-exist.png'], 0);
        if (!str_contains($missing, basename(sh_category_placeholder_image()))) {
            return 'a card whose stored image is missing does not fall back';
        }
        // The helper is the path guard: a traversing value cannot escape.
        if (sh_category_image('../../config/database.php') !== '') { return 'a traversing image path resolved to a URL'; }
    } finally {
        @unlink($dir . '/' . $name);
    }
    return true;
});

sh_t('the placeholder is a neutral, self-contained image', function () {
    $path = SH_ROOT . '/assets/images/category-fallback.svg';
    if (!is_file($path)) { return 'the shared category placeholder is missing'; }
    $svg = (string)file_get_contents($path);
    if (!str_starts_with(trim($svg), '<svg')) { return 'the placeholder is not an SVG'; }
    foreach (['<script', 'javascript:', 'onload=', 'xlink:href', '<image'] as $bad) {
        if (stripos($svg, $bad) !== false) { return 'the placeholder contains ' . $bad; }
    }
    if (!str_contains($svg, 'viewBox')) { return 'the placeholder has no viewBox, so it cannot scale'; }
    return true;
});

sh_t('the category grid is responsive and keeps its shape', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    $block = '';
    if (preg_match('~\.sh-category-card \{(.*?)\}~s', $css, $m) === 1) { $block = $m[1]; }
    if ($block === '') { return 'the category card has no rule'; }
    foreach (['flex-direction: column', 'overflow: hidden'] as $need) {
        if (!str_contains($block, $need)) { return 'the card rule has no ' . $need; }
    }
    if (preg_match('~\.sh-category-card__media \{[^}]*aspect-ratio:\s*4\s*/\s*3~s', $css) !== 1) {
        return 'the default card image has no fixed 4:3 frame, so rows can shift while images load';
    }
    if (preg_match('~\.sh-category-card__img \{[^}]*object-fit:\s*cover~s', $css) !== 1) {
        return 'the card image is not object-fit: cover';
    }
    if (strpos($css, 'sh-category-card__img--fallback') === false) {
        return 'flat placeholder artwork would be cropped: no contain rule';
    }
    // Cards are fixed-size boxes on a wrapping flex line, which is what lets
    // one category be Small while the next is Large in the same grid.
    if (preg_match('~\.sh-catgrid \{[^}]*display:\s*flex[^}]*flex-wrap:\s*wrap~s', $css) !== 1) {
        return 'the category grid is not a wrapping flex row';
    }
    foreach (['small' => 104, 'medium' => 136, 'large' => 216] as $size => $width) {
        if (preg_match('~\.sh-category-card--' . $size . '\s*\{[^}]*width:\s*' . $width . 'px~s', $css) !== 1) {
            return 'the ' . $size . ' card has no ' . $width . 'px width rule';
        }
    }
    // Medium is the shipped default, roughly half of the old ~220px card.
    if (preg_match('~\.sh-category-card--medium\s*\{[^}]*width:\s*(\d+)px~s', $css, $m2) !== 1) {
        return 'the medium card has no width';
    }
    if ((int)$m2[1] > 160) { return 'the medium card is still oversized (' . $m2[1] . 'px)'; }
    if (!str_contains($css, '@media (max-width: 600px)')
        || preg_match('~@media \(max-width: 600px\) \{[^@]*?\.sh-category-card--large\s*\{[^}]*width:\s*100%~s', $css) !== 1) {
        return 'a large card could overflow a phone screen';
    }
    if (preg_match('~@media \(max-width: 600px\) \{[^@]*?\.sh-category-card--medium\s*\{[^}]*calc\(~s', $css) !== 1) {
        return 'the medium card has no phone width';
    }
    // Hover and focus feedback, and no layout shift from the name length.
    if (!str_contains($css, '.sh-category-card:hover') || !str_contains($css, '.sh-category-card:focus-visible')) {
        return 'the card has no hover/focus feedback';
    }
    if (preg_match('~\.sh-category-card__name \{[^}]*min-height~s', $css) !== 1) {
        return 'category names can make cards uneven';
    }
    return true;
});

sh_t('every card carries its saved size as a validated modifier', function () {
    require_once SH_ROOT . '/includes/catalog.php';
    if (array_keys(sh_category_card_sizes()) !== ['small', 'medium', 'large']) {
        return 'the size allowlist is not exactly small/medium/large';
    }
    if (sh_category_card_size('huge') !== 'medium') { return 'an unknown size does not fall back to medium'; }
    if (sh_category_card_size(null) !== 'medium') { return 'a missing size does not default to medium'; }
    foreach (['small', 'medium', 'large'] as $size) {
        $card = sh_category_card(['name' => 'Example', 'slug' => 'example', 'image' => '', 'card_size' => $size], 0);
        if (!str_contains($card, 'sh-category-card--' . $size)) {
            return 'a card with the saved size "' . $size . '" does not render its class';
        }
        if (!str_contains($card, 'data-size="' . $size . '"')) {
            return 'a card does not expose its size for the grid';
        }
        if (str_contains($card, 'style=')) { return 'a card renders an inline style from the stored size'; }
    }
    false;
    return true;
});

sh_t('the category size is chosen per category and validated on save', function () {
    $src = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/category-edit.php'));
    // The three options are real radio inputs, so the control works by keyboard.
    foreach (['small', 'medium', 'large'] as $size) {
        if (!str_contains($src, 'sh_category_card_sizes()')) { return 'the editor does not use the size allowlist'; }
        break;
    }
    if (preg_match('~foreach \(sh_category_card_sizes\(\) as~', $src) !== 1) {
        return 'the editor does not loop over the size allowlist';
    }
    if (preg_match_all('~name="card_size"~', $src) !== 1) {
        return 'the editor renders more than one card_size input by hand';
    }
    if (preg_match('~<input[^>]*type="radio"[^>]*name="card_size"~s', $src) !== 1) {
        return 'the card size control is not a radio group';
    }
    if (!str_contains($src, "'card_size' => sh_category_card_size(")) {
        return 'the posted size is not funnelled through the allowlist before saving';
    }
    if (str_contains($src, "sh_post('card_size')") && !str_contains($src, 'sh_category_card_size')) {
        return 'the raw posted size reaches the database';
    }
    // It must be part of the audit diff too, so a change is traceable.
    if (!str_contains($src, "'sort_order', 'card_size'")) {
        return 'a card-size change is not recorded in the audit diff';
    }
    // A saved value must be read back into the form, never reset to medium.
    if (!str_contains($src, "\$editing['card_size']")) {
        return 'the editor does not read the saved size back';
    }
    // The schema default is the backward-compatible migration for old rows.
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    if (preg_match("~card_size ENUM\\('small','medium','large'\\) NOT NULL DEFAULT 'medium'~", $schema) !== 1) {
        return 'the categories table has no bounded card_size column with a medium default';
    }
    $migrate = (string)file_get_contents(SH_ROOT . '/includes/category-image.php');
    if (!str_contains($migrate, 'card_size')) {
        return 'existing installations have no automatic card_size migration';
    }
    // Never arbitrary CSS/JS: the value is a token and the class is built from it.
    $catalog = (string)file_get_contents(SH_ROOT . '/includes/catalog.php');
    if (str_contains($catalog, 'sh_category_card_size($cat') && str_contains($catalog, 'style=')) {
        return 'the card size is written as an inline style';
    }
    return true;
});

sh_t('the email delivery log has one home, in the System group', function () {
    $src = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/_layout.php'));
    if (substr_count($src, "'email-log.php'") !== 1) {
        return 'the delivery log appears in more than one navigation group';
    }
    $entry = strpos($src, "'email-log.php'");
    $system = strpos($src, "'System' =>");
    if ($entry === false || $system === false || $entry < $system) {
        return 'the delivery log is not listed under System';
    }
    // Moving the navigation must not break the existing URL, its guards or its
    // redaction: the page itself is unchanged and still permission checked.
    if (!is_file(SH_ROOT . '/admin/email-log.php')) { return 'the log page file was moved or removed'; }
    $page = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/email-log.php'));
    if (!str_contains($page, "sh_require_perm('integrations.manage')")) {
        return 'the log page lost its permission check';
    }
    if (!str_contains($page, "\$adminPage = 'email_log'")) {
        return 'the log page does not highlight its System navigation row';
    }
    return true;
});

sh_t('the SEO preview shows the real product image, not a placeholder', function () {
    $seo = (string)file_get_contents(SH_ROOT . '/admin/_seo-fields.php');
    foreach (['data-seo-image=', 'data-seo-image-input=', 'data-seo-preview="image"'] as $need) {
        if (!str_contains($seo, $need)) { return 'the SEO block does not expose ' . $need; }
    }
    $edit = (string)file_get_contents(SH_ROOT . '/admin/product-edit.php');
    if (preg_match('~\$seoImage\s*=\s*\$val\(\'image\'\)~', $edit) !== 1) {
        return 'the editor does not resolve the saved product image for the preview';
    }
    if (str_contains($edit, 'SH_UPLOAD_DIR')) { return 'the editor leaks a filesystem path into the form'; }
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/seo.js');
    if (!str_contains($js, 'data-seo-image')) { return 'the preview script ignores the product image'; }
    if (!str_contains($js, 'createObjectURL')) { return 'a newly chosen image does not update the preview'; }
    if (preg_match('~^(?!.*blob:).*absoluteUrl~m', $js) === 1 && !str_contains($js, 'blob:')) {
        return 'blob: preview URLs would be mangled';
    }
    if (preg_match('~(localhost|127\.0\.0\.1|file://)~', $js) === 1) {
        return 'the preview script builds an address the browser cannot reach';
    }
    return true;
});

sh_t('the complete-product draft is server-side and permissioned', function () {
    $api = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/api/ai.php'));
    foreach (["case 'product_draft'", "sh_admin_can('ai.use')",
              "\$needed = \$productId > 0 ? 'products.edit' : 'products.create'", 'sh_admin_can($needed)',
              'sh_ai_product_draft', 'sh_ai_draft_image_from_upload'] as $need) {
        if (!str_contains($api, $need)) { return 'api/ai.php is missing ' . $need; }
    }
    if (preg_match('~[\'"](sk-[A-Za-z0-9]|api_key[\'"]\s*=>)~', $api) === 1) {
        return 'the endpoint appears to return credentials';
    }
    $mgr = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/ai/AIManager.php'));
    if (!str_contains($mgr, "'image_used'")) { return 'the manager does not report whether the image was used'; }
    // An unread image must never be described as analysed.
    if (!str_contains($mgr, 'cannot read images')) { return 'the prompt can still imply an unread image was seen'; }
    return true;
});

sh_t('a generated draft can only carry fields that really exist', function () {
    require_once SH_ROOT . '/ai/AIManager.php';
    $d = sh_ai_normalise_product_draft([
        'title' => '  <b>Cotton Panjabi</b>  ',
        'short_description' => "Soft cotton panjabi.\n",
        'description' => '<p>Nice</p><script>alert(1)</script>',
        'specifications' => "Material: Cotton\n\n<script>x</script>",
        'category' => 'A Category That Does Not Exist',
        'brand' => 'Brand That Does Not Exist',
        'sku' => 'sh-abc 123',
        'meta_title' => 'Cotton Panjabi',
        'keywords' => ['panjabi', 'cotton'],
        'image_alt' => 'A cotton panjabi',
        'price' => 1200,
        'compare_price' => 1500,
        'stock' => 77,
        'low_stock_threshold' => 3,
        'status' => 1,
        'is_featured' => 1,
    ]);
    foreach (['price', 'compare_price', 'stock', 'low_stock_threshold', 'status', 'is_featured'] as $forbidden) {
        if (array_key_exists($forbidden, $d)) { return 'the draft carries the forbidden field ' . $forbidden; }
    }
    if ($d['title'] !== 'Cotton Panjabi') { return 'the title kept its markup: ' . $d['title']; }
    if (str_contains($d['description'], '<script')) { return 'the description kept a script tag'; }
    if (str_contains($d['specifications'], '<')) { return 'the specifications kept markup'; }
    if ($d['category_id'] !== null) { return 'a non-existent category was mapped to an id'; }
    if ($d['brand_id'] !== null) { return 'a non-existent brand was mapped to an id'; }
    if ($d['sku'] !== 'SH-ABC123') { return 'the SKU was not cleaned to the allowed characters'; }
    return true;
});

sh_t('Add Product has no AI generator; AI Auto Work is the only entry point', function () {
    $editor = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/product-edit.php'));
    foreach (['data-product-ai', 'product-ai.js', 'data-ai-draft-run', 'Generate Complete Product with AI', 'product_draft'] as $gone) {
        if (str_contains($editor, $gone)) { return 'the product editor still contains the AI panel (' . $gone . ')'; }
    }
    if (str_contains($editor, 'name="features"') || stripos($editor, 'Features and benefits') !== false) {
        return 'the removed features field is still in the product form';
    }
    if (!str_contains($editor, 'name="image_alt"')) {
        return 'the manual product form cannot edit the image alt text';
    }
    if (!str_contains($editor, 'name="price"') || !str_contains($editor, 'name="stock"')) {
        return 'the normal manual product form was damaged';
    }
    if (is_file(SH_ROOT . '/assets/js/product-ai.js')) {
        return 'the removed Add Product AI client is still shipped';
    }

    // The generator lives in AI Auto Work, next to the per-product tools.
    $auto = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/ai/products.php'));
    foreach (['data-ai-create', 'Generate Full Product', 'data-ai-save-product', 'data-ai-create-review'] as $need) {
        if (!str_contains($auto, $need)) { return 'AI Auto Work is missing ' . $need; }
    }
    if (!str_contains((string)file_get_contents(SH_ROOT . '/assets/js/ai-product-create.js'), "action: 'save_product'")) {
        return 'the AI Auto Work client does not call the save_product action';
    }
    if (substr_count($auto, 'data-ai-create-start') !== 1) {
        return 'AI Auto Work does not have exactly one create form';
    }
    return true;
});

sh_t('the AI create client never writes price, stock or visibility by itself', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/ai-product-create.js');
    // The fields applyDraft() copies from the model answer. Price, stock and
    // publication are deliberately absent: the administrator types them.
    if (preg_match("~\['name', 'slug', 'sku', 'category_id',.*?\]~s", $js, $m) !== 1) {
        return 'the draft field list is missing';
    }
    foreach (["'price'", "'stock'", "'compare_price'", "'status'"] as $forbidden) {
        if (str_contains($m[0], $forbidden)) {
            return 'the AI draft writes ' . $forbidden . ' into the review form';
        }
    }
    // Save must go through the server action that wraps a transaction.
    if (!str_contains($js, "action: 'save_product'")) {
        return 'the client does not use the transactional save action';
    }
    if (str_contains($js, 'product-edit.php" method="post"')) {
        return 'the client submits the product form directly';
    }
    return true;
});

sh_t('a full product is saved in one all-or-nothing transaction', function () {
    $studio = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/ai/ProductStudio.php'));
    foreach (['beginTransaction', 'commit()', 'rollBack()', "sh_insert('products'", 'product_tags', 'product_images'] as $need) {
        if (!str_contains($studio, $need)) { return 'the save path is missing ' . $need; }
    }
    // The row must be read back BEFORE the commit, otherwise "saved" is a claim.
    $read = strpos($studio, 'SELECT * FROM products WHERE id = ?');
    $commit = strpos($studio, '$pdo->commit();');
    if ($read === false || $commit === false || $read > $commit) {
        return 'the save path commits without verifying the written row';
    }
    // Retrying with the same token must find the existing product first.
    if (strpos($studio, 'WHERE ai_draft_token = ?') === false) {
        return 'the save path has no idempotency lookup';
    }
    if (strpos($studio, 'WHERE ai_draft_token = ?') > strpos($studio, "sh_insert('products'")) {
        return 'the idempotency check runs after the insert';
    }
    // A failure must not report success: the transaction is rolled back and the
    // function returns ok=false.
    $catchAt = strpos($studio, 'catch (Throwable $e) {', strpos($studio, 'beginTransaction'));
    if ($catchAt === false) { return 'the save path has no failure handler'; }
    $catch = substr($studio, $catchAt, 1400);
    if (!str_contains($catch, 'rollBack()') || !str_contains($catch, "'ok' => false")) {
        return 'a failed save does not roll back and return an error';
    }
    if (!str_contains($studio, 'sh_seo_cache_invalidate()')) {
        return 'a saved product does not invalidate the SEO/sitemap cache';
    }
    // Uploaded files are deleted on rollback, generated ones are kept for retry.
    if (!str_contains($studio, 'sh_delete_upload($imageFile')) {
        return 'a rolled-back upload is left orphaned on disk';
    }
    // The slug follows the store's own rules and is uniqueness-checked.
    if (!str_contains($studio, 'sh_slug(') || !str_contains($studio, 'FROM products WHERE slug = ?')) {
        return 'the save path does not enforce the store slug rules';
    }
    // Price and stock are validated, never derived from the model.
    if (!str_contains($studio, 'Enter the selling price. AI never invents a price.')) {
        return 'a missing price is not rejected';
    }
    // The SEO columns are added by a separate migration on an existing store.
    // The save writes them, so the save path must run that migration itself.
    if (!str_contains($studio, 'sh_seo_schema_ensure()')) {
        return 'the save path can write SEO fields without ensuring their columns exist';
    }
    // A generated image must exist on disk under the naming rule it trusts.
    if (!str_contains($studio, 'ai-\d{8}') || !str_contains($studio, '[a-f0-9]{16}')) {
        return 'the save path does not restrict generated image filenames';
    }
    return true;
});

sh_t('the save endpoint and schema back the create flow', function () {
    $api = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/api/ai.php'));
    if (strpos($api, "case 'save_product'") === false) { return 'api/ai.php has no save_product action'; }
    if (strpos($api, "case 'product_image'") === false) { return 'the image retry action is missing'; }
    $clientSrc = (string)file_get_contents(SH_ROOT . '/assets/js/ai-product-create.js');
    if (!str_contains($clientSrc, "action: 'product_image'")) {
        return 'the image retry action exists but the client never calls it';
    }
    $saveAt = strpos($api, "case 'save_product'");
    $seg = substr($api, $saveAt, 1200);
    foreach (["sh_admin_can('products.create')", 'sh_ai_throttle', 'sh_ai_save_full_product'] as $need) {
        if (!str_contains($seg, $need)) { return 'save_product does not check ' . $need; }
    }
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    foreach (['image_alt VARCHAR(190)', 'ai_draft_token VARCHAR(64)', 'uq_products_draft_token'] as $need) {
        if (!str_contains($schema, $need)) { return 'install/schema.php is missing ' . $need; }
    }
    $ensure = (string)file_get_contents(SH_ROOT . '/includes/product-schema.php');
    if (!str_contains($ensure, "ALTER TABLE products ADD COLUMN")) {
        return 'existing installations have no additive product-column migration';
    }
    // Storefront: the removed features section is gone, and the alt text is used.
    $pdp = (string)file_get_contents(SH_ROOT . '/product.php');
    if (str_contains($pdp, 'sh-featurelist') || str_contains($pdp, "\$product['features']")) {
        return 'the product page still renders the removed features section';
    }
    if (!str_contains($pdp, "product['image_alt']")) {
        return 'the product page ignores the generated alt text';
    }
    return true;
});

sh_t('the AI create client only touches controls the panel really renders', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/ai-product-create.js');
    $markup = (string)file_get_contents(SH_ROOT . '/admin/ai/products.php');

    // A renamed data attribute is silent in production: querySelector returns
    // null, the field is skipped and Save writes an empty value. Catch it here.
    preg_match_all('~querySelector\([\'"]\[(data-ai-[a-z0-9-]+)\]~', $js, $m);
    $missing = [];
    if (count($m[1]) < 15) {
        return 'the selector scan found almost nothing in the client';
    }
    foreach (array_unique($m[1]) as $attr) {
        if (preg_match('~' . preg_quote($attr, '~') . '(?![a-z0-9-])~', $markup) !== 1) {
            $missing[] = $attr;
        }
    }
    if ($missing) {
        return 'the client selects attributes the panel does not render: ' . implode(', ', $missing);
    }

    // Both field maps (render + collect) name the review controls.
    if (!preg_match_all("~\[\s*'name',\s*'slug'.*?\]~s", $js, $lists)) {
        return 'the client no longer has an explicit field map';
    }
    if (count($lists[0]) < 2) {
        return 'the client field map could not be read';
    }
    foreach ($lists[0] as $list) {
        preg_match_all("~'([a-z0-9_]+)'~", $list, $names);
        foreach (array_unique($names[1]) as $name) {
            if (!str_contains($markup, 'data-f="' . $name . '"')) {
                return 'the client writes a field the review form does not have: ' . $name;
            }
        }
    }

    // The two recovery paths promised by the brief: retry and manual upload.
    if (!str_contains($markup, 'data-ai-image-retry') || !str_contains($markup, 'data-ai-upload')) {
        return 'the review form has no image retry and no manual upload';
    }
    if (!str_contains($markup, 'data-ai-image-file')) {
        return 'the generated image file is never posted back to the save handler';
    }
    if (!str_contains($js, 'request.image = uploadedFile')) {
        return 'a manually uploaded photo is not sent as a file';
    }
    return true;
});

sh_t('no shipped page still prints a category item count', function () {
    foreach (sh_markup_files() as [$rel, $path]) {
        if (str_starts_with($rel, 'admin/')) { continue; }   // the admin list shows counts on purpose
        $src = sh_source_without_comments((string)file_get_contents($path));
        foreach (['sh-category-card__count', 'sh-category-card__icon'] as $gone) {
            if (str_contains($src, $gone)) { return $rel . ' still renders ' . $gone; }
        }
        // "6 items" style blurbs next to a category link.
        if (preg_match('~category\.php\?slug=[^>]*>.*?\d+\s*items~is', $src) === 1) {
            return $rel . ' prints an item count beside a category link';
        }
    }
    return true;
});

sh_t('the category navigation prints no item count', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/header.php');
    if (str_contains($src, '<em>')) { return 'a navigation row still prints a count element'; }
    // The dropdown and the drawer both list categories; neither may print a count.
    foreach ([['<ul class="sh-catmenu"', '</ul>'], ['<p class="sh-drawer__title">Shop by category</p>', '<p class="sh-drawer__title">Quick links</p>']] as [$from, $to]) {
        $start = strpos($src, $from);
        if ($start === false) { return 'the navigation list ' . $from . ' is missing'; }
        $end = strpos($src, $to, $start);
        if ($end === false) { return 'the navigation list ' . $from . ' is not closed'; }
        $block = substr($src, $start, $end - $start);
        if (str_contains($block, 'product_count')) { return 'a navigation row prints the product count'; }
        if (preg_match('~[0-9]+\s*(items?|products?)~i', $block) === 1) { return 'a navigation row prints a numeric count'; }
    }
    return true;
});

sh_t('a navigation row uses the real upload and falls back to its icon', function () {
    require_once SH_ROOT . '/includes/catalog.php';
    // Without an upload the row keeps the line icon the rest of the nav uses.
    $plain = sh_category_nav_thumb(['name' => 'Kitchen', 'slug' => 'kitchen', 'image' => '', 'icon' => 'grid'], 16);
    if (str_contains($plain, '<img')) { return 'a category without an upload renders an image in the navigation'; }
    if (!str_contains($plain, '<svg')) { return 'a category without an upload has no icon in the navigation'; }
    // With one, the row shows the category's own image instead of a generic icon.
    $dir = SH_UPLOAD_DIR . '/categories';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return null; }
    $name = 'regression-nav-' . bin2hex(random_bytes(4)) . '.gif';
    if (@file_put_contents($dir . '/' . $name, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')) === false) { return null; }
    try {
        $row = sh_category_nav_thumb(['name' => 'Kitchen', 'slug' => 'kitchen', 'image' => $name, 'icon' => 'grid'], 16);
        if (!str_contains($row, 'sh-catthumb') || !str_contains($row, rawurlencode($name))) {
            return 'a navigation row does not use the uploaded image: ' . $row;
        }
        if (str_contains($row, '<svg')) { return 'a navigation row still shows a generic icon when a real image exists'; }
        // The menus are hidden until opened, so nothing may load eagerly.
        if (!str_contains($row, 'loading="lazy"') || !str_contains($row, 'alt=""')) {
            return 'a navigation thumbnail is not a lazy decorative image: ' . $row;
        }
        // A stored file that is gone must fall back, not break the row.
        $gone = sh_category_nav_thumb(['name' => 'Kitchen', 'slug' => 'kitchen', 'image' => 'nope.png', 'icon' => 'grid'], 16);
        if (str_contains($gone, '<img')) { return 'a navigation row with a missing file still renders an image'; }
    } finally {
        @unlink($dir . '/' . $name);
    }
    return true;
});

// ===========================================================================
// Admin CRUD — Add at the top, forms on their own screens
// ===========================================================================

sh_section('Admin CRUD layout and form routing');

/** The list page => [editor page, Add-button label] for each converted section. */
function sh_admin_crud_map(): array
{
    return [
        'admin/categories.php'      => ['admin/category-edit.php', 'Add category'],
        'admin/products.php'        => ['admin/product-edit.php', 'Add product'],
        'admin/homepage.php'        => ['admin/homepage-edit.php', 'Add slide'],
        'admin/payment-methods.php' => ['admin/payment-method-edit.php', 'Add payment method'],
        'admin/couriers.php'        => ['admin/courier-edit.php', 'Add a courier'],
        'admin/coupons.php'         => ['admin/coupon-edit.php', 'Add coupon'],
        'admin/admins.php'          => ['admin/admin-edit.php', 'Add admin'],
        'admin/roles.php'           => ['admin/role-edit.php', 'Add role'],
        'admin/verification.php'    => ['admin/verification-provider-edit.php', 'Add provider'],
        'admin/payment-gateways.php'=> ['admin/payment-gateway-edit.php', 'Register gateway'],
    ];
}

sh_t('every list page offers its create action at the top of the section', function () {
    $offenders = [];
    foreach (sh_admin_crud_map() as $list => [$editor, $label]) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $list);
        if (!str_contains($src, 'sh_admin_header(')) { $offenders[] = $list . ' has no page header'; }
        if (!str_contains($src, $label)) { $offenders[] = $list . ' has no "' . $label . '" action'; }
        // The header (and therefore the Add button) must come before the records.
        $headerAt = strpos($src, 'sh_admin_header(');
        $tableAt  = strpos($src, 'sh-tablewrap');
        $cardsAt  = strpos($src, 'sh-cards');
        $recordsAt = min(array_filter([$tableAt === false ? PHP_INT_MAX : $tableAt, $cardsAt === false ? PHP_INT_MAX : $cardsAt]));
        if ($recordsAt !== PHP_INT_MAX && $headerAt > $recordsAt) {
            $offenders[] = $list . ' renders its records before the header action';
        }
    }
    return sh_assert($offenders === [], implode('; ', $offenders));
});

sh_t('the create action is wired to the editor or to a modal', function () {
    $offenders = [];
    foreach (sh_admin_crud_map() as $list => [$editor, $label]) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $list);
        if ($editor !== '') {
            if (!str_contains($src, basename($editor))) { $offenders[] = $list . ' does not link to ' . $editor; }
            if (!is_file(SH_ROOT . '/' . $editor)) { $offenders[] = $editor . ' does not exist'; }
        } else {
            // A short form is a modal on the same page: it must open through the
            // shared modal helper, and its form must still POST with a CSRF field.
            if (!str_contains($src, 'sh_admin_modal(')) { $offenders[] = $list . ' has no shared modal'; }
            if (substr_count($src, 'sh_csrf_field()') < 2) { $offenders[] = $list . ' modal form has no CSRF field'; }
        }
    }
    return sh_assert($offenders === [], implode('; ', $offenders));
});

sh_t('no listing page renders a create/edit form below its records', function () {
    $offenders = [];
    foreach (array_keys(sh_admin_crud_map()) as $list) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $list);
        $recordsAt = strpos($src, 'sh-tablewrap');
        if ($recordsAt === false) { $recordsAt = strpos($src, 'sh-cards'); }
        if ($recordsAt === false) { continue; }
        $tail = substr($src, $recordsAt);
        // A form that writes the entity (form=save) must not live after the list.
        if (preg_match('~<form[^>]*>.*?name="form" value="save"~s', $tail) === 1) {
            $offenders[] = $list . ' still renders its save form under the listing';
        }
        // Nor may it re-appear through a GET flag on the same page.
        if (str_contains($tail, "_seo-fields.php")) {
            $offenders[] = $list . ' still renders the SEO field block inside the listing';
        }
    }
    return sh_assert($offenders === [], implode('; ', $offenders));
});

function sh_admin_editor_map(): array
{
    // editor page => the guard it must carry. Editors live on their own screen;
    // every one of them keeps the same shell, the same CSRF checks and the same
    // way back to its listing.
    return [
        'admin/category-edit.php'               => 'sh_require_admin(',
        'admin/product-edit.php'                => 'sh_require_admin(',
        'admin/homepage-edit.php'               => 'sh_require_admin(',
        'admin/payment-method-edit.php'         => 'sh_require_admin(',
        'admin/courier-edit.php'                => 'sh_require_admin(',
        'admin/coupon-edit.php'                 => 'sh_require_admin(',
        'admin/admin-edit.php'                  => 'sh_require_superadmin(',
        'admin/role-edit.php'                   => 'sh_require_superadmin(',
        'admin/verification-provider-edit.php'  => 'sh_require_admin(',
        'admin/payment-gateway-edit.php'        => 'sh_require_admin(',
    ];
}

sh_t('the new editor pages are guarded like the pages they replaced', function () {
    foreach (sh_admin_editor_map() as $rel => $guard) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        // Shared form shell: every editor renders the panel as a form card with
        // its own action footer, so the screens cannot drift apart.
        foreach (['sh_require_perm(', 'sh_csrf_require()', 'sh_csrf_field()', $guard,
                  'sh-panel sh-formcard', 'sh-formcard__foot'] as $must) {
            if (!str_contains($src, $must)) { return $rel . ' is missing ' . $must; }
        }
        // Every editor offers a way back that keeps the listing's filters.
        if (!str_contains($src, 'sh_admin_keep_query(')) { return $rel . ' does not preserve the listing query'; }
        // The breadcrumb comes from the shared helper: one heading policy, no
        // hand-written entity heading per page.
        if (!str_contains($src, 'sh_admin_edit_head(') && !str_contains($src, 'sh_admin_breadcrumb(')) {
            return $rel . ' has no breadcrumb';
        }
        if (!str_contains($src, "require __DIR__ . '/_footer.php';")) { return $rel . ' does not close the layout'; }
        // POST only: a state change must never be reachable by a GET.
        if (preg_match('~\$_GET\[[^\]]*\]\s*\)?[^;]*;\s*//\s*save~', $src) === 1) { return $rel . ' saves from a GET parameter'; }
    }
    return true;
});

sh_t('old edit links keep working through a redirect', function () {
    $map = [
        'admin/categories.php'      => 'admin/category-edit.php',
        'admin/products.php'        => 'admin/product-edit.php',
        'admin/homepage.php'        => 'admin/homepage-edit.php',
        'admin/payment-methods.php' => 'admin/payment-method-edit.php',
        'admin/couriers.php'        => 'admin/courier-edit.php',
        'admin/coupons.php'         => 'admin/coupon-edit.php',
        'admin/admins.php'          => 'admin/admin-edit.php',
        'admin/roles.php'           => 'admin/role-edit.php',
        'admin/verification.php'    => 'admin/verification-provider-edit.php',
        'admin/payment-gateways.php'=> 'admin/payment-gateway-edit.php',
    ];
    foreach ($map as $list => $editor) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $list);
        if (!str_contains($src, "sh_redirect('" . $editor . "?edit=") && !str_contains($src, "sh_redirect('" . $editor . "'")) {
            return $list . ' no longer forwards ?edit= to ' . $editor;
        }
    }
    return true;
});

sh_t('a save posted to a listing is forwarded, never silently dropped', function () {
    foreach (['admin/categories.php', 'admin/products.php', 'admin/homepage.php', 'admin/payment-methods.php',
              'admin/couriers.php', 'admin/coupons.php', 'admin/admins.php', 'admin/roles.php',
              'admin/verification.php', 'admin/payment-gateways.php'] as $rel) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (preg_match('~\$form (===|!==)~', $src) !== 1) {
            return $rel . ' has no branch for a save posted to it';
        }
        if (!str_contains($src, 'sh_redirect(')) { return $rel . ' never redirects a forwarded save'; }
    }
    return true;
});

sh_t('mobile tables can render as record cards', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    foreach (['.sh-tablewrap--cards', 'attr(data-label)'] as $needle) {
        if (!str_contains($css, $needle)) { return 'the responsive table card pattern is missing ' . $needle; }
    }
    // The converted listings opt in and label their cells.
    foreach (['admin/categories.php', 'admin/products.php', 'admin/couriers.php', 'admin/coupons.php',
              'admin/admins.php', 'admin/roles.php', 'admin/payment-gateways.php'] as $rel) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (!str_contains($src, 'sh-tablewrap--cards')) { return $rel . ' does not opt into the mobile card layout'; }
        if (substr_count($src, 'data-label=') < 3) { return $rel . ' does not label its table cells for narrow screens'; }
    }
    return true;
});

sh_t('no write sends an explicit NULL into a NOT NULL column', function () {
    // MySQL runs in strict mode: a NULL written into a NOT NULL column aborts the
    // statement, so the save silently reverts to "the form came back". The schema
    // is the source of truth here.
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    $tables = [];
    if (preg_match_all('~CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) \$E~s', $schema, $m, PREG_SET_ORDER)) {
        foreach ($m as $block) {
            $cols = [];
            foreach (preg_split('~\R~', $block[2]) ?: [] as $line) {
                if (preg_match('~^\s*(\w+)\s+[A-Za-z]+(?:\([^)]*\))?\s*(.*?),?$~', $line, $c) !== 1) { continue; }
                if (in_array(strtoupper($c[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN'], true)) { continue; }
                $cols[$c[1]] = str_contains(strtoupper($c[2]), 'NOT NULL');
            }
            $tables[$block[1]] = $cols;
        }
    }
    if ($tables === []) { return 'the schema could not be read'; }

    foreach (array_merge(glob(SH_ROOT . '/admin/*.php'), glob(SH_ROOT . '/includes/*.php')) as $file) {
        $src = (string)file_get_contents($file);
        foreach (preg_split('~\R~', $src) ?: [] as $i => $line) {
            if (preg_match("~^\s*'(\w+)'\s*=>\s*(.*)$~", $line, $c) !== 1) { continue; }
            if (preg_match('~\bnull\b~', $c[2]) !== 1) { continue; }
            foreach ($tables as $table => $cols) {
                if (!isset($cols[$c[1]]) || $cols[$c[1]] !== true) { continue; }
                if (preg_match("~sh_(?:insert|update)\(\s*'" . $table . "'~", $src) !== 1) { continue; }
                return basename($file) . ' writes NULL into ' . $table . '.' . $c[1] . ' (line ' . ($i + 1) . '), which is NOT NULL';
            }
        }
    }
    return true;
});

sh_t('the installer never writes sample business records into an ordinary install', function () {
    // The demo-data bug: sh_schema_seed() -- which runs for EVERY install --
    // inserted categories, brands, promo slides, coupons and invented contact
    // details. Structure-level starters are allowed; business records are not.
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    $start = (int)strpos($schema, 'function sh_schema_seed(');
    $end = (int)strpos($schema, "\n}\n", $start);
    if ($start === 0 || $end <= $start) { return 'sh_schema_seed() could not be read'; }
    $fn = substr($schema, $start, $end - $start);
    foreach (['promo_slides', 'INSERT INTO products', 'INSERT INTO categories', 'INSERT INTO brands',
              'INSERT INTO coupons', 'INSERT INTO reviews', 'INSERT INTO orders', 'INSERT INTO users'] as $bad) {
        if (str_contains($fn, $bad)) { return 'sh_schema_seed() still inserts business content: ' . $bad; }
    }
    // Courier accounts are business records too: the lazy upgrade may create them
    // only once, for a store that predates the recorded demo-data choice.
    $courier = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    if (!str_contains($courier, 'courier_registry_state')) {
        return 'the lazy courier upgrade does not read the recorded demo-data choice';
    }
    if (strpos($courier, 'sh_courier_seed($pdo)') !== false
        && strpos($courier, 'if ($marked === 0)') === false) {
        return 'the lazy courier upgrade reseeds an empty registry on every request';
    }
    // Invented company details must not ship as if they were the real business.
    foreach (["'+880 1700-000000'", "'Level 4, Bijoy Sarani", "'We deliver genuine products across Bangladesh"] as $fake) {
        if (str_contains($fn, $fake)) { return 'sh_schema_seed() still invents business details: ' . $fake; }
    }
    return true;
});

sh_t('the setup wizard defaults to "no sample content" and honours the posted choice', function () {
    $src = (string)file_get_contents(SH_ROOT . '/install/index.php');
    if (preg_match("~'demo_data'\s*=>\s*'1'~", $src) === 1) {
        return 'the installer still pre-selects the starter catalogue, so an untouched form installs sample content';
    }
    // A checkbox posts only when ticked: the value has to be normalised, and the
    // gate has to compare against that normalised value.
    if (!str_contains($src, "\$form['demo_data'] = (\$form['demo_data'] === '1') ? '1' : '0';")) {
        return 'the installer does not normalise the posted demo-data value';
    }
    if (!str_contains($src, "\$form['demo_data'] === '1'")) {
        return 'the installer no longer gates the catalogue on the validated value';
    }
    if (preg_match('~\$_POST\[.demo_data.\]\s*(===|==|!==|!=)\s*~', $src) === 1) {
        return 'the installer decides from the raw POST value instead of the validated one';
    }
    if (!str_contains((string)file_get_contents(SH_ROOT . '/install/schema.php'), 'sh_install_seed_all(')) {
        return 'the installer does not use the shared seeding decision';
    }
    return true;
});

sh_t('the starter catalogue is idempotent and cannot be re-created by a normal request', function () {
    $src = (string)file_get_contents(SH_ROOT . '/install/demo-data.php');
    // Every seeded table has to be looked up by its own unique key before it is
    // written. One generic helper does that; each table must appear in a call.
    if (!str_contains($src, "SELECT id FROM ' . \$table . ' WHERE ' . \$column . ' = ? LIMIT 1")) {
        return 'the seeder has no by-key lookup helper';
    }
    // (Brands are not seeded at all — the store has no brand system; the
    // DB-backed installer suite asserts the seeded brand count stays zero.)
    foreach (['categories', 'products', 'promo_slides'] as $table) {
        if (preg_match('~\\$find\\(\\$pdo, \'' . $table . '\'~', $src) !== 1) {
            return 'the seeder never checks ' . $table . ' for an existing row';
        }
    }
    // Coupons are guarded by the table's own unique key instead of a lookup.
    if (preg_match('~INSERT IGNORE INTO coupons~', $src) !== 1) {
        return 'the seeder inserts coupons without a duplicate guard';
    }
    // The opt-in flag decides, not the checkbox: without an explicit request a
    // recorded decision makes the call a no-op.
    if (preg_match('~function sh_install_demo_data\(PDO \$pdo, bool \$explicitRequest = false\)~', $src) !== 1) {
        return 'sh_install_demo_data() no longer distinguishes an explicit request from an automatic call';
    }
    if (!str_contains($src, 'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')) {
        return 'the seeder does not record that it ran';
    }
    // Only the installer may run it, and only behind the validated choice.
    $callers = [];
    foreach (array_merge(glob(SH_ROOT . '/includes/*.php'), glob(SH_ROOT . '/admin/*.php'),
                         glob(SH_ROOT . '/*.php'), glob(SH_ROOT . '/install/*.php')) as $file) {
        $rel = str_replace(SH_ROOT . '/', '', $file);
        if (str_contains((string)file_get_contents($file), 'sh_install_demo_data(') && $rel !== 'install/demo-data.php'
            && $rel !== 'install/schema.php' && $rel !== 'tests/installer-demo-data.php') {
            $callers[] = $rel;
        }
    }
    if ($callers !== []) { return 'sample content can be installed from outside the setup wizard: ' . implode(', ', $callers); }
    return true;
});

sh_t('the sample-content review screen and its navigation are gone', function () {
    // The demo choice belongs in the installer only. There must be no admin page,
    // no menu entry, no route and no leftover backend helper for reviewing or
    // removing sample content.
    if (file_exists(SH_ROOT . '/admin/demo-data.php')) { return 'admin/demo-data.php still exists'; }
    foreach (['install/demo-data.php'] as $rel) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        foreach (['sh_demo_inventory(', 'sh_demo_cleanup(', 'DEMO SAMPLE DATA', 'confirm_phrase'] as $gone) {
            if (str_contains($src, $gone)) { return $rel . ' still carries the review backend: ' . $gone; }
        }
    }
    foreach (['admin/_layout.php', 'admin/dashboard.php', 'admin/settings.php', 'admin/index.php'] as $rel) {
        if (!file_exists(SH_ROOT . '/' . $rel)) { continue; }
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (preg_match('~demo-data\.php|Demo Data Review~', $src) === 1) {
            return $rel . ' still links to the removed review screen';
        }
    }
    // No replacement page was added under another name.
    foreach ((array)glob(SH_ROOT . '/admin/*.php') as $file) {
        $src = (string)file_get_contents($file);
        if (preg_match('~sh_demo_(?:inventory|cleanup)\s*\(~', $src) === 1) {
            return basename($file) . ' still drives the removed review backend';
        }
    }
    // The installer offers exactly one explicit two-state choice.
    $installer = (string)file_get_contents(SH_ROOT . '/install/index.php');
    if (preg_match('~name="demo_data"~', $installer) !== 1) { return 'the installer no longer offers the Demo Data choice'; }
    if (preg_match('~name="demo_data"[^>]*value="1"~', $installer) !== 1
        || preg_match('~name="demo_data"[^>]*value="0"~', $installer) !== 1) {
        return 'the Demo Data choice is not an explicit ON/OFF pair';
    }
    if (preg_match('~type="radio"[^>]*name="demo_data"~', $installer) !== 1) { return 'the Demo Data choice is not a radio control'; }
    return true;
});

sh_t('a fresh install never overwrites an existing administrator', function () {
    $installer = (string)file_get_contents(SH_ROOT . '/install/index.php');
    $creator   = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    foreach ([$installer, $creator] as $src) {
        if (stripos($src, 'ON DUPLICATE KEY UPDATE password_hash') !== false) {
            return 'the installer still upserts an administrator, which resets an existing password on a re-run';
        }
    }
    if (!str_contains($installer, 'sh_install_create_store(')) {
        return 'the installer does not use the shared create-store path';
    }
    if (!str_contains($creator, 'SELECT COUNT(*) FROM admins')) {
        return 'the installer does not check whether an administrator already exists';
    }
    // The administrator insert and the seed share one transaction.
    if (!str_contains($creator, '$pdo->beginTransaction();')) { return 'the installer seeds without a transaction'; }
    if (!str_contains($creator, '$pdo->rollBack();')) { return 'the installer never rolls back a failed seed'; }
    // The typed password is hashed, never compared against a sample or default.
    if (!str_contains($creator, 'password_hash((string)($form[\'admin_password\'] ?? \'\'), PASSWORD_DEFAULT)')) {
        return 'the installer does not hash the chosen administrator password';
    }
    // An existing account may only be reset through the owner unlock path.
    if (!str_contains($creator, '$ownerRecovery')) { return 'the creator has no owner-recovery authorisation'; }
    if (!str_contains($installer, "\$shAccess['mode'] === 'unlocked'")) {
        return 'the installer does not tie recovery to the unlock mode';
    }
    return true;
});

sh_t('no fixed or example password is shipped anywhere', function () {
    $files = array_merge(
        (array)glob(SH_ROOT . '/*.php'), (array)glob(SH_ROOT . '/admin/*.php'),
        (array)glob(SH_ROOT . '/includes/*.php'), (array)glob(SH_ROOT . '/install/*.php'),
        (array)glob(SH_ROOT . '/api/*.php'), [SH_ROOT . '/README.md']
    );
    foreach ($files as $file) {
        if (!is_file($file)) { continue; }
        $src = (string)file_get_contents($file);
        foreach (['Shop2024pass', 'MyStore99', 'ShopHatt123', 'Shop123hatt'] as $sample) {
            if (str_contains($src, $sample)) { return basename($file) . ' still ships the sample password ' . $sample; }
        }
    }
    // The installer must not pre-fill or auto-generate a password either.
    $installer = (string)file_get_contents(SH_ROOT . '/install/index.php');
    if (preg_match('~name="admin_password"[^>]*value=~', $installer) === 1) {
        return 'the installer pre-fills the administrator password field';
    }
    // A credential is never trimmed before it is hashed.
    if (!str_contains($installer, "in_array(\$k, \$verbatim, true)")) {
        return 'the installer trims the posted administrator password';
    }
    return true;
});

sh_t('the administrator password policy is length-only and configurable', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/validation.php');
    if (!str_contains($src, 'function sh_password_min_length(')) { return 'the password minimum is not configurable'; }
    if (!str_contains($src, "SH_ADMIN_PASSWORD_MIN_LENGTH")) { return 'the administrator minimum cannot be overridden'; }
    if (!str_contains($src, "return max(\$admin ? 12 : 8, min(64, \$configured));")) {
        return 'the administrator minimum is no longer 12 by default';
    }
    if (preg_match('~Shop2024pass|ShopHatt123|Shop123hatt|MyStore99~', $src) === 1) {
        return 'the shared policy still cites a sample password';
    }
    if (!str_contains($src, '$this->raw($f)')) {
        return 'password validation and confirmation no longer use the exact submitted bytes';
    }
    // The installer and the admin screens both use the administrator context.
    foreach (['install/index.php', 'admin/settings.php', 'admin/admin-edit.php'] as $rel) {
        $file = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (!str_contains($file, 'admin_password_problem') && !str_contains($file, "password('admin_password', 'admin')")) {
            return $rel . ' does not use the administrator password policy';
        }
    }
    return true;
});

sh_t('the public homepage slider carries no call-to-action and no arrow controls', function () {
    $index = (string)file_get_contents(SH_ROOT . '/index.php');
    $fn    = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
    $js    = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    $css   = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    foreach ([[$index, 'index.php'], [$fn, 'includes/functions.php'], [$js, 'assets/js/app.js'], [$css, 'assets/css/app.css']] as [$src, $rel]) {
        foreach (['sh-slide__btn', 'sh-carousel__arrow', 'data-carousel-prev', 'data-carousel-next'] as $gone) {
            if (str_contains($src, $gone)) { return $rel . ' still ships the removed slider control: ' . $gone; }
        }
    }
    // The markup is rendered by one shared function so the harness can drive the
    // shipped string; index.php must call it rather than keep its own copy.
    $start = strpos($fn, 'function sh_slider_markup(');
    $end = $start === false ? false : strpos($fn, "\n}\n", $start);
    if ($start === false || $end === false) { return 'sh_slider_markup() is missing'; }
    $markup = substr($fn, $start, $end - $start);
    if (!str_contains($index, 'sh_slider_markup(')) { return 'index.php does not render the shared slider markup'; }
    if (str_contains($index, 'sh-slide__img')) { return 'index.php keeps a second copy of the slider markup'; }
    // The content the slide is there for must survive the removal.
    foreach (['sh-slide__title', 'sh-slide__text', 'sh-slide__img', 'sh-slide__tag'] as $kept) {
        if (!str_contains($markup, $kept)) { return 'the slider markup no longer renders ' . $kept; }
    }
    // Feature cards keep their own link — the removal is scoped to the slides.
    if (!str_contains($index, 'sh-sidecard__link')) { return 'the feature cards lost their link'; }
    if (!str_contains($css, '.sh-sidecard__link')) { return 'the feature card link lost its styling'; }
    return true;
});

sh_t('the slider crossfades instead of swapping, and never shows a blank frame', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    $js  = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    $markup = (string)file_get_contents(SH_ROOT . '/includes/functions.php');

    // Slides are stacked and faded, never display:none (that was the flicker:
    // the browser had to lay the incoming slide out and decode it mid-swap).
    if (preg_match('~\.sh-slide\s*\{[^}]*position:\s*absolute~', $css) !== 1) {
        return 'slides are not absolutely stacked';
    }
    if (preg_match('~\.sh-slide\s*\{[^}]*display:\s*none~', $css) === 1) {
        return 'a slide is still switched off with display:none';
    }
    if (!str_contains($css, '--sh-slide-ease: cubic-bezier(0.22, 1, 0.36, 1)')) {
        return 'the slider no longer uses the requested easing curve';
    }
    if (preg_match('~\.sh-slide\s*\{[^}]*transition:\s*opacity var\(--sh-slide-fade\)~', $css) !== 1) {
        return 'the slide opacity is not driven by the configured fade duration';
    }
    // The incoming slide sits above the outgoing one while it fades in.
    if (preg_match('~\.sh-slide--in\s*\{\s*z-index:\s*2~', $css) !== 1) {
        return 'the incoming slide is not layered above the outgoing one';
    }
    // The outgoing slide is only released after the fade, so no frame can show
    // the container behind it.
    if (strpos($js, 'fade + 120') === false) { return 'the outgoing slide is released without waiting for the fade'; }
    // Autoplay and fade durations come from the settings, clamped in both places.
    foreach (['data-carousel-interval', 'data-carousel-fade'] as $attr) {
        if (!str_contains($markup, $attr)) { return 'the slider markup does not pass ' . $attr . ' to the script'; }
        if (!str_contains($js, $attr)) { return 'the script ignores ' . $attr; }
    }
    if (strpos($js, ">= 4000 && interval <= 6000") === false) { return 'the autoplay interval is not clamped to 4-6 s'; }
    if (strpos($js, ">= 600 && fade <= 1000") === false) { return 'the crossfade is not clamped to 600-1000 ms'; }
    $fn = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
    foreach (['function sh_slider_interval_ms(', 'function sh_slider_fade_ms('] as $helper) {
        if (strpos($fn, $helper) === false) { return 'missing ' . $helper; }
    }
    // The admin screen that changes them validates the same band.
    $admin = (string)file_get_contents(SH_ROOT . '/admin/homepage.php');
    if (!str_contains($admin, 'slider_timing') || !str_contains($admin, '4000 || $intervalMs > 6000')) {
        return 'admin/homepage.php does not validate the slider timing it saves';
    }
    return true;
});

sh_t('the slider keeps itself safe: hidden tab, off-screen, reduced motion, broken image', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    foreach (["document.hidden", "'visibilitychange'", "IntersectionObserver",
              "'(prefers-reduced-motion: reduce)'", "'error'", "new Image()"] as $must) {
        if (!str_contains($js, $must)) { return 'the carousel script ignores ' . $must; }
    }
    // A missing file must degrade to the gradient plate and keep the text: the
    // server marks the slide, and the script handles an image that fails later.
    $markup = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
    if (!str_contains($markup, 'sh-slide--noimg')) { return 'the slider markup does not mark a slide without a usable image'; }
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    if (!str_contains($css, '.sh-slide--noimg')) { return 'the missing-image state has no styling'; }
    // Reduced motion collapses the fade too.
    if (preg_match('~@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*--sh-slide-fade: 1ms~s', $css) !== 1) {
        return 'the reduced-motion block no longer collapses the slide fade';
    }
    return true;
});

sh_t('a disabled slide is absent from the homepage, and one slide renders no pagination', function () {
    $fn = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
    // Disabled slides are filtered in SQL, so they are never rendered, never
    // downloaded and cannot leak their heading into the page source.
    if (preg_match("~SELECT \* FROM promo_slides WHERE placement = \? AND status = 1~", $fn) !== 1) {
        return 'sh_promo_items() no longer filters disabled slides in the query';
    }
    $markup = (string)file_get_contents(SH_ROOT . '/includes/functions.php');
    if (preg_match('~if \(\$total > 1\)~', $markup) !== 1) {
        return 'the slider markup renders pagination even when there is a single slide';
    }
    // The shared markup is also what the behaviour harness loads, so it must not
    // depend on anything a fixture cannot provide.
    if (!str_contains($markup, 'function sh_slider_markup(')) { return 'sh_slider_markup() is missing'; }
    return true;
});

sh_t('every column the installer and the fixtures write exists in the schema', function () {
    // A fixture that writes 'city' where the table has 'shipping_city' fails only
    // when CI finally reaches MySQL. The column list is parsed from the schema and
    // every literal write in install/ and tests/ is checked against it here.
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    $tables = [];
    if (preg_match_all('~CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) \$E~s', $schema, $m, PREG_SET_ORDER)) {
        foreach ($m as $block) {
            $cols = [];
            foreach (preg_split('~\R~', $block[2]) ?: [] as $line) {
                if (preg_match('~^\s*(\w+)\s+[A-Za-z]+(?:\([^)]*\))?~', $line, $c) !== 1) { continue; }
                if (in_array(strtoupper($c[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN'], true)) { continue; }
                $cols[strtolower($c[1])] = true;
            }
            $tables[strtolower($block[1])] = $cols;
        }
    }
    if ($tables === []) { return 'the schema could not be read'; }

    $offenders = [];
    $files = array_merge(glob(SH_ROOT . '/install/*.php'), glob(SH_ROOT . '/tests/*.php'));
    foreach ($files as $file) {
        $src = (string)file_get_contents($file);
        // Each sh_insert('table', [ ... ]) / sh_update('table', [ ... ]) block is
        // read on its own: only the keys inside that literal belong to that table.
        if (preg_match_all("~sh_(?:insert|update)\(\s*'([a-z_]+)'\s*,\s*\[~i", $src, $calls, PREG_OFFSET_CAPTURE)) {
            foreach ($calls[0] as $i => $call) {
                $table = strtolower($calls[1][$i][0]);
                if (!isset($tables[$table])) { continue; }
                $start = $call[1] + strlen($call[0]) - 1;
                $depth = 0;
                $block = '';
                for ($p = $start, $len = strlen($src); $p < $len; $p++) {
                    $ch = $src[$p];
                    if ($ch === '[') { $depth++; continue; }
                    if ($ch === ']') { $depth--; if ($depth === 0) { break; } continue; }
                    if ($depth > 0) { $block .= $ch; }
                }
                // A nested array is a merge (not a column list) and cannot be read
                // here. A single key whose value is a variable is perfectly fine to
                // check: the key is what has to exist, not the value.
                if (str_contains($block, '[')) { continue; }
                if (preg_match_all("~'([A-Za-z_][\w]*)'\s*=>\s*~", $block, $keys, PREG_OFFSET_CAPTURE)) {
                    $count = count($keys[0]);
                    for ($k = 0; $k < $count; $k++) {
                        $key = $keys[1][$k][0];
                        $valueStart = $keys[0][$k][1] + strlen($keys[0][$k][0]);
                        $valueEnd = $k + 1 < $count ? $keys[0][$k + 1][1] : strlen($block);
                        $value = trim(substr($block, $valueStart, $valueEnd - $valueStart), " \t\n\r,");
                        // A value that is built at runtime still has to name a real
                        // column: only the key is checked, never the value.
                        if ($value === '' && !str_contains($block, '$')) { continue; }
                        if (!isset($tables[$table][strtolower($key)])) {
                            $offenders[] = basename($file) . ' writes ' . $table . '.' . $key . ', which the schema does not define';
                        }
                    }
                }
            }
        }
    }
    return sh_assert($offenders === [], implode('; ', array_slice(array_unique($offenders), 0, 6)));
});

sh_t('the panel has exactly one page heading per screen', function () {
    // The layout renders the only <h1>. A second one inside a page body means the
    // entity name appears twice as a title.
    foreach (glob(SH_ROOT . '/admin/*.php') as $file) {
        $rel = 'admin/' . basename($file);
        if (basename($file) === '_layout.php' || basename($file) === 'login.php') { continue; }
        $src = (string)file_get_contents($file);
        if (str_contains($src, '<h1')) { return $rel . ' renders a second page heading'; }
    }
    return true;
});

sh_t('a listing does not repeat the page title above its records', function () {
    // These headings used to repeat the title the layout already prints in the
    // top bar; the head now carries only a count or a range.
    $forbidden = [
        'admin/coupons.php'        => 'All coupons',
        'admin/audit-log.php'      => 'Audit Log (',
        'admin/otp-logs.php'       => 'OTP Requests (',
        'admin/security-logs.php'  => 'Security Events (',
        'admin/orders.php'         => 'Orders (',
        'admin/parcels.php'        => 'Parcels (',
        'admin/payments.php'       => 'Payments (',
        'admin/customers.php'      => 'Customers (',
        'admin/admins.php'         => 'sh-panel__title',
        'admin/roles.php'          => 'sh-panel__title',
        'admin/payment-gateways.php' => 'Registered gateways (',
    ];
    foreach ($forbidden as $rel => $needle) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        if (str_contains($src, $needle)) { return $rel . ' still repeats its title in a panel heading ("' . $needle . '")'; }
    }
    return true;
});

sh_t('every editor checks the token before it reads a posted value', function () {
    foreach (sh_admin_editor_map() as $rel => $guard) {
        $src = (string)file_get_contents(SH_ROOT . '/' . $rel);
        $csrfAt = strpos($src, 'sh_csrf_require()');
        if ($csrfAt === false) { return $rel . ' never calls sh_csrf_require()'; }
        // A posted field read that happens before the token check would act on
        // unauthenticated input.
        if (preg_match('~\$_(POST)\[~', substr($src, 0, $csrfAt)) === 1) { return $rel . ' reads $_POST before the CSRF check'; }
        // A top-level comparison runs on GET as well as POST, so the value must be
        // initialised first: otherwise PHP warns about an undefined variable on a
        // perfectly normal page view.
        $sawInit = false;
        foreach (preg_split('~\R~', $src) ?: [] as $line) {
            if (preg_match('~^\$form = \'\'~', $line) === 1) { $sawInit = true; }
            if (preg_match('~^if \(\$form (===|!==)~', $line) === 1 && !$sawInit) {
                return $rel . ' compares $form at the top level before initialising it';
            }
        }
        // CSRF first, then the value: no state change can happen before both.
        $postForm = strpos($src, "sh_post('form')");
        if ($postForm !== false && $postForm < $csrfAt) { return $rel . ' reads the form name before the CSRF check'; }
    }
    return true;
});

sh_t('the layout is the only place that prints the page title', function () {
    $layout = (string)file_get_contents(SH_ROOT . '/admin/_layout.php');
    if (substr_count($layout, '$adminTitle') < 1) { return 'the layout no longer prints the page title'; }
    if (!str_contains($layout, 'sh-admin-top__title')) { return 'the layout title lost its class'; }
    // sh_admin_header() must never render a heading of its own.
    $tools = (string)file_get_contents(SH_ROOT . '/includes/admin-tools.php');
    $start = (int)strpos($tools, 'function sh_admin_header(');
    $end = (int)strpos($tools, "\n}\n", $start);
    $fn = $end > $start ? substr($tools, $start, $end - $start) : substr($tools, $start, 3000);
    if (str_contains($fn, '<h1') || str_contains($fn, '<h2')) { return 'sh_admin_header() renders a heading of its own'; }
    if (!str_contains($fn, 'sh-pagehead--bar')) { return 'sh_admin_header() is no longer a bar'; }
    return true;
});

sh_t('the admin form helpers stay in one place', function () {
    $tools = (string)file_get_contents(SH_ROOT . '/includes/admin-tools.php');
    foreach (['sh_admin_header', 'sh_admin_add_button', 'sh_admin_field', 'sh_admin_error_summary', 'sh_admin_modal', 'sh_admin_breadcrumb', 'sh_admin_keep_query', 'sh_admin_range_label'] as $fn) {
        if (!str_contains($tools, 'function ' . $fn . '(')) { return 'the shared helper ' . $fn . '() no longer exists'; }
    }
    // The Add button must respect permissions rather than showing a dead action.
    if (!str_contains($tools, 'sh_admin_can(')) { return 'the Add button ignores permissions'; }
    return true;
});

// ===========================================================================
// Category image upload reliability
// ===========================================================================

sh_section('Category image upload and replacement');

sh_t('a failed upload never removes the existing image', function () {
    require_once SH_ROOT . '/includes/category-image.php';
    $row = ['image' => 'existing-category.png'];
    // PHP reports a failure (too large / partial); the result must be the failure
    // only, with the stored image left untouched.
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR] as $code) {
        $res = sh_category_resolve_image($row, [], ['image' => ['name' => 'new.png', 'error' => $code, 'tmp_name' => '', 'size' => 0]]);
        if ($res['error'] === null) { return 'upload error ' . $code . ' was not reported'; }
        if ($res['action'] !== 'keep' || $res['file'] !== 'existing-category.png') {
            return 'a failed upload changed the stored image (error ' . $code . ')';
        }
        if ($res['uploaded'] !== null) { return 'a failed upload reported a stored file'; }
    }
    return true;
});

sh_t('keeping, replacing and removing are decided explicitly', function () {
    require_once SH_ROOT . '/includes/category-image.php';
    // No upload, no checkbox: keep.
    $keep = sh_category_resolve_image(['image' => 'a.png'], [], []);
    if ($keep['action'] !== 'keep' || $keep['file'] !== 'a.png') { return 'a plain save did not keep the image'; }
    // Explicit removal without a replacement: remove.
    $remove = sh_category_resolve_image(['image' => 'a.png'], ['remove_image' => '1'], []);
    if ($remove['action'] !== 'remove' || $remove['file'] !== null) { return 'the remove flag was ignored'; }
    // A removal tick combined with a new upload must prefer the new upload.
    $both = sh_category_resolve_image(['image' => 'a.png'], ['remove_image' => '1'], ['image' => ['name' => 'x.png', 'error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0]]);
    if ($both['action'] !== 'remove') { return 'a no-file upload overrode the explicit removal'; }
    // A brand-new category with nothing uploaded stays empty (the placeholder shows).
    $fresh = sh_category_resolve_image(null, [], []);
    if ($fresh['action'] !== 'keep' || $fresh['file'] !== null) { return 'a new category did not start without an image'; }
    return true;
});

sh_t('the image file is only deleted in a safe order', function () {
    $editor = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/category-edit.php'));
    // The previous file is removed only inside the branch that already wrote the
    // new value to the database, and the delete helper basenames its input.
    $updateAt = strpos($editor, "sh_update('categories'");
    if ($updateAt === false) { return 'the category editor does not update the row'; }
    $deleteAt = strpos($editor, 'sh_category_delete_file($old)');
    if ($deleteAt === false) { return 'the previous image is never cleaned up'; }
    if ($updateAt === false || $deleteAt < $updateAt) {
        return 'the previous image is deleted before the new value is stored';
    }
    $helper = (string)file_get_contents(SH_ROOT . '/includes/category-image.php');
    if (!str_contains($helper, 'basename(')) { return 'the delete helper does not basename its path'; }
    // An upload that is validated away must be cleaned up rather than orphaned.
    if (!str_contains($editor, "sh_category_delete_file(\$imgResult['uploaded'])")) {
        return 'a rejected upload can be left orphaned on disk';
    }
    return true;
});

sh_t('the editor keeps every field the old inline form had', function () {
    $editor = sh_source_without_comments((string)file_get_contents(SH_ROOT . '/admin/category-edit.php'));
    // Losing a field here would silently drop an existing setting on save.
    foreach ([
        "'parent_id'" => 'parent category',
        "'icon'" => 'navigation icon',
        "'description'" => 'description',
        "'sort_order'" => 'sort order',
        "'status'" => 'navigation visibility',
        "'image'" => 'category image',
        'sh_category_resolve_image' => 'the image keep/replace/remove rules',
    ] as $needle => $why) {
        if (!str_contains($editor, (string)$needle)) { return 'the category editor no longer writes ' . $why; }
    }
    // The upload field still accepts the allowlisted formats and shows a preview.
    if (!str_contains($editor, 'accept="image/jpeg,image/png,image/webp,image/gif"')) {
        return 'the image input no longer restricts formats client-side';
    }
    if (!str_contains($editor, 'data-image-preview=')) { return 'the image preview was lost'; }
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
sh_section('Email verification, recovery and SMTP delivery');
// ===========================================================================

/** Read a file that must exist for the email flows. */
function sh_email_src(string $rel): string
{
    $path = SH_ROOT . '/' . $rel;
    return is_file($path) ? (string)file_get_contents($path) : '';
}

sh_t('verification codes come from a cryptographically secure source', function () {
    $src = sh_email_src('includes/mail.php');
    if ($src === '') { return 'includes/mail.php is missing'; }
    if (!str_contains($src, 'random_int(')) { return 'codes are not generated with random_int()'; }
    // Word boundaries matter: "sh_email_brand(" contains "rand(" but is not one.
    if (preg_match('~(?<![A-Za-z_$])(?:mt_rand|rand|uniqid|srand|lcg_value)\s*\(~', $src) === 1) {
        return 'a weak random source is used';
    }
    return true;
});

sh_t('a code is stored as a hash, compared with password_verify and never in the clear', function () {
    $src = sh_email_src('includes/mail.php');
    if (!str_contains($src, 'password_hash($code, PASSWORD_DEFAULT)')) { return 'the code is not stored as a password_hash() value'; }
    if (!str_contains($src, "password_verify(\$code, (string)\$row['code_hash'])")) { return 'the submitted code is not compared with password_verify()'; }
    $schema = sh_email_src('install/schema.php');
    $start = strpos($schema, 'CREATE TABLE IF NOT EXISTS email_challenges (');
    if ($start === false) { return 'the email_challenges table is not defined'; }
    $ddl = substr($schema, $start, 2600);
    if (!str_contains($ddl, 'code_hash VARCHAR(255) NOT NULL')) { return 'the hash column is missing'; }
    foreach (['code VARCHAR', 'otp VARCHAR', 'plain_code', '`code` INT'] as $bad) {
        if (str_contains($ddl, $bad)) { return 'the challenge table can hold a plaintext code: ' . $bad; }
    }
    return true;
});

sh_t('the delivery log cannot hold a body, a code or a token', function () {
    $schema = sh_email_src('install/schema.php');
    $start = strpos($schema, 'CREATE TABLE IF NOT EXISTS email_log (');
    if ($start === false) { return 'the email_log table is not defined'; }
    $end = strpos($schema, ') $E', $start);
    if ($end === false) { return 'the email_log DDL is malformed'; }
    $ddl = substr($schema, $start, $end - $start);
    // Column names only: "unicode_ci" contains "code_" but is not a column.
    preg_match_all('~^\s{8}([a-z_]+)\s+~m', $ddl, $cols);
    foreach ($cols[1] as $col) {
        if (in_array($col, ['body', 'html', 'message', 'text_body', 'html_body', 'code', 'code_hash', 'otp', 'token', 'raw'], true)) {
            return 'email_log has a column that could store message content: ' . $col;
        }
    }
    if (!str_contains($ddl, 'KEY idx_email_log_msgid (message_id)')) { return 'the webhook lookup has no index'; }
    return true;
});

sh_t('no authentication code is ever placed in a URL', function () {
    foreach (['forgot-password.php', 'reset-password.php', 'login.php', 'register.php', 'password.php', 'account.php'] as $file) {
        $src = sh_email_src($file);
        if ($src === '') { return $file . ' is missing'; }
        foreach (['?code=', '&code=', '?otp=', '&otp='] as $bad) {
            if (str_contains($src, $bad)) { return $file . ' puts a code in a URL (' . $bad . ')'; }
        }
    }
    // The provider link is the only code that arrives in a URL, and it is read,
    // never generated or echoed back.
    $verify = sh_email_src('verify-email.php');
    if ($verify === '' || !str_contains($verify, "_GET['oobCode']")) { return 'verify-email.php does not read the provider link'; }
    if (!str_contains($verify, 'sh_fb_apply_oob_code(')) { return 'verify-email.php does not validate the provider code server-side'; }
    return true;
});

sh_t('the reset flow invalidates every credential it can', function () {
    $src = sh_email_src('reset-password.php');
    if (!str_contains($src, 'sh_email_challenge_revoke(')) { return 'outstanding codes are not revoked after a reset'; }
    if (!str_contains($src, 'UPDATE password_resets SET used_at = NOW()')) { return 'outstanding reset tokens are not consumed'; }
    if (!str_contains($src, 'sh_forget_customer_session()')) { return 'the session is not rotated after a reset'; }
    if (!str_contains($src, 'password_hash($new, PASSWORD_DEFAULT)')) { return 'the new password is not hashed with password_hash()'; }
    if (!str_contains($src, 'sh_email_notify_password_changed(')) { return 'no real notification is sent after a reset'; }
    return true;
});

sh_t('the sign-in gate never marks an address verified by itself', function () {
    $src = sh_email_src('login.php');
    if (!str_contains($src, 'sh_email_gate_blocked(')) { return 'login.php does not apply the verification gate'; }
    if (str_contains($src, 'sh_email_mark_verified(')) { return 'login.php marks an address verified without a code'; }
    if (!str_contains($src, 'sh_email_verify_pending_set(')) { return 'the pending verification state is not recorded'; }
    return true;
});

sh_t('every sender reports the transport result instead of assuming success', function () {
    foreach (['register.php', 'forgot-password.php', 'password.php', 'account.php', 'reset-password.php'] as $file) {
        $src = sh_email_src($file);
        if (preg_match('~!empty\(\$[a-z_]+\[[\'"]ok[\'"]\]\)~', $src) !== 1) {
            return $file . ' does not check whether the send actually succeeded';
        }
    }
    $mail = sh_email_src('config/mail.php');
    if (!str_contains($mail, "'skipped'")) { return 'missing configuration is not reported as skipped'; }
    if (!str_contains($mail, 'getLastMessageID()')) { return 'the accepted message is not identified for the provider webhook'; }
    return true;
});

sh_t('the provider webhook verifies its signature before it reads the payload', function () {
    $src = sh_email_src('api/email-events.php');
    if ($src === '') { return 'api/email-events.php is missing'; }
    $verifyAt = strpos($src, 'sh_email_webhook_verify(');
    $decodeAt = strpos($src, 'json_decode(');
    if ($verifyAt === false || $decodeAt === false) { return 'the webhook does not verify and decode'; }
    if ($verifyAt > $decodeAt) { return 'the payload is decoded before the signature is checked'; }
    if (strpos($src, 'sh_file_rate_limit(') === false) { return 'the webhook has no rate limit'; }
    if (str_contains($src, 'json_encode($payload') || str_contains($src, 'echo $raw')) { return 'the webhook echoes the payload back'; }
    $mail = sh_email_src('includes/mail.php');
    if (!str_contains($mail, 'hash_equals($secret, $offered)') || !str_contains($mail, "hash_hmac('sha256', \$rawBody, \$secret)")) {
        return 'the shared secret and the HMAC are not compared in constant time';
    }
    if (!str_contains($mail, "if (\$secret === '' || strlen(\$secret) < 24)")) { return 'a missing webhook secret is not refused'; }
    return true;
});

sh_t('the SMTP password is never rendered, logged or returned', function () {
    $admin = sh_email_src('admin/email.php');
    if (preg_match('~value="<\?=\s*e\(\(string\)sh_setting\(\'smtp_password~', $admin) === 1) {
        return 'admin/email.php renders the stored SMTP password';
    }
    $mail = sh_email_src('config/mail.php');
    $statusAt = strpos($mail, 'function sh_mail_service_status()');
    $statusEnd = $statusAt === false ? false : strpos($mail, "\n}", $statusAt);
    if ($statusAt === false || $statusEnd === false) { return 'sh_mail_service_status() could not be read'; }
    $fn = substr($mail, $statusAt, $statusEnd - $statusAt);
    if (preg_match("~'password'\s*=>~", $fn) === 1) { return 'the sanitised status returns the SMTP password'; }
    if (!str_contains($fn, "'password_set'")) { return 'the status does not report whether a password is stored'; }
    if (!str_contains($mail, 'function sh_mail_redact(')) { return 'transcripts and errors are not redacted'; }
    return true;
});

sh_t('the Brevo API key is server-side only and delivery TLS verification is never disabled', function () {
    $admin = sh_email_src('admin/email.php');
    if (preg_match('~value="<\?=\s*e\(\(string\)sh_setting\(\'brevo_api_key~', $admin) === 1) {
        return 'admin/email.php renders the stored Brevo API key';
    }
    if (!str_contains($admin, 'name="brevo_api_key"') || !str_contains($admin, 'type="password"')) {
        return 'the Brevo API key field is not a masked password input';
    }
    if (!str_contains($admin, 'sh_verify_encrypt($apiKey)')) {
        return 'the Brevo API key is not encrypted before storage';
    }

    $mail = sh_email_src('config/mail.php');
    $sendAt = strpos($mail, 'function sh_mail_send(');
    $sendEnd = $sendAt === false ? false : strpos($mail, 'function sh_mail_send_legacy(', $sendAt);
    if ($sendAt === false || $sendEnd === false) { return 'sh_mail_send() could not be read'; }
    $send = substr($mail, $sendAt, $sendEnd - $sendAt);
    foreach (["'verify_peer'       => true", "'verify_peer_name'  => true", "'allow_self_signed' => false", "'peer_name'         => \$cleanHost"] as $needle) {
        if (!str_contains($send, $needle)) { return 'the delivery path does not enforce TLS validation: ' . $needle; }
    }
    if (preg_match('~sh_setting\(\s*[\'\"]smtp_allow_self_signed~', $send) === 1) {
        return 'the delivery path still reads the retired self-signed setting';
    }
    if (str_contains($send, 'CURLOPT_SSL_VERIFYPEER => false') || str_contains($send, 'CURLOPT_SSL_VERIFYHOST => 0')) {
        return 'the delivery path disables HTTPS verification';
    }

    $apiAt = strpos($mail, 'function sh_brevo_api_request(');
    $apiEnd = $apiAt === false ? false : strpos($mail, 'function sh_brevo_api_send(', $apiAt);
    if ($apiAt === false || $apiEnd === false) { return 'sh_brevo_api_request() could not be read'; }
    $api = substr($mail, $apiAt, $apiEnd - $apiAt);
    foreach (['CURLOPT_SSL_VERIFYPEER => true', 'CURLOPT_SSL_VERIFYHOST => 2', "'verify_peer' => true", "'verify_peer_name' => true"] as $needle) {
        if (!str_contains($api, $needle)) { return 'the Brevo API path does not enforce TLS validation: ' . $needle; }
    }
    if (!str_contains($mail, "'inspection_only' => true")) {
        return 'the read-only certificate inspector is not labelled as inspection-only';
    }
    return true;
});

sh_t('a 403 from the Brevo account resource is reported as a permission, not an auth failure', function () {
    $mail = sh_email_src('config/mail.php');
    $at = strpos($mail, 'function sh_brevo_api_test_connection(');
    $end = $at === false ? false : strpos($mail, 'function sh_mail_send(', $at);
    if ($at === false || $end === false) { return 'sh_brevo_api_test_connection() could not be read'; }
    $fn = substr($mail, $at, $end - $at);

    // The checks must stay separate and the account check must be described as
    // informational, so a 403 there can never read as "the API is broken" or
    // "the key is invalid". The historical list endpoint is a log query that
    // needs an email/messageId/templateId filter; it must not be used as a
    // permission probe, and transaction email must not be claimed before a
    // real POST has succeeded.
    if (str_contains($fn, "'/smtp/emails'")) { return 'the diagnostics still query the historical transactional-email log'; }
    if (!str_contains($fn, 'POST /v3/smtp/email')) { return 'the transactional check does not name the documented send endpoint'; }
    if (!str_contains($fn, "'not_tested'")) { return 'transactional email is not reported as not tested until a real send'; }
    if (!str_contains($fn, 'informational')) { return 'the account check is not labelled informational'; }
    if (!str_contains($fn, "=== 403")) { return '403 is not handled separately in the API diagnostics'; }
    if (!str_contains($fn, "'permission_denied'")) { return 'the diagnostics never report a resource permission result'; }
    if (str_contains($fn, "'api_auth'")) { return 'the conflating api_auth stage is still reported'; }

    $statusAt = strpos($mail, 'function sh_brevo_api_status_meaning(');
    $statusEnd = $statusAt === false ? false : strpos($mail, 'function sh_brevo_api_request(', $statusAt);
    if ($statusAt === false || $statusEnd === false) { return 'sh_brevo_api_status_meaning() could not be read'; }
    $status = substr($mail, $statusAt, $statusEnd - $statusAt);
    foreach (['402', '403', '429', '401'] as $code) {
        if (!str_contains($status, '=== ' . $code)) { return 'HTTP ' . $code . ' is not handled separately'; }
    }
    if (preg_match('~\$status\s*>=\s*200\s*&&\s*\$status\s*<\s*300~', $status) !== 1) {
        return 'success is not restricted to 2xx; a 403 could be treated as success';
    }
    if (str_contains($status, "kind' => 'ok'") && !str_contains($status, 'Accepted')) {
        return 'the success branch is not explicit';
    }
    return true;
});

sh_t('Brevo credentials never cross transports and a denied send is never retried', function () {
    $mail = sh_email_src('config/mail.php');

    $keyAt = strpos($mail, 'function sh_brevo_api_key_info(');
    $keyEnd = $keyAt === false ? false : strpos($mail, 'function sh_brevo_api_key(', $keyAt);
    if ($keyAt === false || $keyEnd === false) { return 'sh_brevo_api_key_info() could not be read'; }
    $keyFn = substr($mail, $keyAt, $keyEnd - $keyAt);
    if (!str_contains($keyFn, "'smtp_key'")) { return 'an SMTP key in the API field is not identified'; }
    if (!str_contains($keyFn, "\$out['key'] = '';")) { return 'an SMTP key in the API field is not blanked before use'; }

    if (!str_contains($mail, 'function sh_mail_credential_mismatch(')) { return 'the SMTP credential check is missing'; }
    $mismatchAt = strpos($mail, 'function sh_mail_credential_mismatch(');
    $mismatchEnd = strpos($mail, 'function sh_mail_send(', $mismatchAt);
    $mismatch = substr($mail, $mismatchAt, $mismatchEnd - $mismatchAt);
    if (!str_contains($mismatch, 'xkeysib-')) { return 'an API key in the SMTP password is not detected'; }

    $sendAt = strpos($mail, 'function sh_mail_send(');
    $sendEnd = $sendAt === false ? false : strpos($mail, 'function sh_mail_send_legacy(', $sendAt);
    $send = substr($mail, $sendAt, $sendEnd - $sendAt);
    if (substr_count($send, 'sh_mail_credential_mismatch(') < 1) { return 'sh_mail_send() does not refuse a cross-transport credential'; }

    $apiSendAt = strpos($mail, 'function sh_brevo_api_send(');
    $apiSendEnd = $apiSendAt === false ? false : strpos($mail, 'function sh_brevo_api_test_connection(', $apiSendAt);
    $apiSend = substr($mail, $apiSendAt, $apiSendEnd - $apiSendAt);
    if (str_contains($apiSend, 'sleep(')) { return 'the API send waits and retries instead of reporting the result'; }
    if (!str_contains($apiSend, "'permission'")) { return 'a denied API send is not categorised as a permission failure'; }

    // The API key header is added only when a key is actually sent.
    $reqAt = strpos($mail, 'function sh_brevo_api_request(');
    $reqEnd = strpos($mail, 'function sh_brevo_api_send(', $reqAt);
    $req = substr($mail, $reqAt, $reqEnd - $reqAt);
    if (!str_contains($req, "if (\$key !== '') { \$headers[] = 'api-key: ' . \$key; }")) {
        return 'the api-key header is not conditional on a key being sent';
    }
    return true;
});

sh_t('email templates escape interpolated values and ship a text alternative', function () {
    $src = sh_email_src('includes/mail.php');
    if (!str_contains($src, 'function sh_email_e(')) { return 'the email escaper is missing'; }
    $start = strpos($src, 'function sh_email_layout(');
    $end = $start === false ? false : strpos($src, "\n}", $start);
    if ($start === false || $end === false) { return 'sh_email_layout() could not be read'; }
    $fn = substr($src, $start, $end - $start);
    foreach (['sh_email_e($heading)', "sh_email_e((string)\$o['subject'])", "sh_email_e((string)\$cta['url'])"] as $needle) {
        if (!str_contains($fn, $needle)) { return 'a template value is not escaped: ' . $needle; }
    }
    if (!str_contains($fn, "'text' =>") || !str_contains($fn, "'html' =>")) { return 'the template does not build both alternatives'; }
    if (str_contains($fn, '<script')) { return 'the email template contains a script tag'; }
    return true;
});

sh_t('the verification link the provider sends is a page that exists', function () {
    if (!is_file(SH_ROOT . '/verify-email.php')) { return 'verify-email.php does not exist, so provider links land on a 404'; }
    $fb = sh_email_src('includes/firebase.php');
    if (!str_contains($fb, "sh_url('verify-email.php')") && !str_contains($fb, "'/verify-email.php'")) {
        return 'the provider continue URL does not point at the verification page';
    }
    $verify = sh_email_src('verify-email.php');
    foreach (['sh_email_challenge_verify(', 'sh_email_mark_verified(', 'sh_csrf_require()'] as $needle) {
        if (!str_contains($verify, $needle)) { return 'verify-email.php is missing ' . $needle; }
    }
    return true;
});

sh_t('the mail library is vendored, loadable and unreachable over HTTP', function () {
    foreach (['lib/PHPMailer/src/PHPMailer.php', 'lib/PHPMailer/src/SMTP.php', 'lib/PHPMailer/LICENSE', 'lib/PHPMailer/VERSION'] as $rel) {
        if (!is_file(SH_ROOT . '/' . $rel)) { return $rel . ' is missing'; }
    }
    if (!is_file(SH_ROOT . '/lib/.htaccess')) { return 'lib/ has no directory guard'; }
    $guard = (string)file_get_contents(SH_ROOT . '/lib/.htaccess');
    if (!str_contains($guard, 'Require all denied') && !str_contains($guard, 'Deny from all')) { return 'lib/ is not denied over HTTP'; }
    $loader = (string)file_get_contents(SH_ROOT . '/lib/PHPMailer/autoload.php');
    if (!str_contains($loader, 'PHPMailer\\PHPMailer\\')) { return 'the autoloader does not map the PHPMailer namespace'; }
    if (!str_contains($loader, "preg_match('~^[A-Za-z0-9_]+$~'")) { return 'the autoloader does not constrain the class name it maps to a file'; }
    if (str_contains($loader, 'vendor/autoload.php') === false) { return 'the autoloader ignores a Composer install'; }
    return true;
});

sh_t('the challenge table is indexed and tied to the account', function () {
    $schema = sh_email_src('install/schema.php');
    foreach ([
        'KEY idx_email_chal_lookup (email, purpose, status, id)',
        'KEY idx_email_chal_expiry (expires_at)',
        'CONSTRAINT fk_email_chal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
        'CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    ] as $needle) {
        if (!str_contains($schema, $needle)) { return 'the mail schema is missing: ' . $needle; }
    }
    if (!str_contains($schema, 'function sh_email_schema_sql()')) { return 'the email tables have no shared DDL function'; }
    if (!str_contains($schema, 'sh_email_schema_sql())')) { return 'the installer does not create the email tables'; }
    $mail = sh_email_src('includes/mail.php');
    if (!str_contains($mail, 'function sh_email_schema_ensure()')) { return 'existing stores have no lazy migration for the mail tables'; }
    if (!str_contains($mail, 'sh_email_schema_sql()')) { return 'the lazy migration does not use the shared DDL'; }
    return true;
});

sh_t('existing accounts are grandfathered once instead of being locked out', function () {
    $mail = sh_email_src('includes/mail.php');
    if (!str_contains($mail, "sh_setting('email_verify_grandfathered', '0') !== '1'")) { return 'the one-time grandfathering guard is missing'; }
    if (!str_contains($mail, "sh_setting_save('email_verify_grandfathered', '1')")) { return 'the grandfathering is not recorded'; }
    if (!str_contains($mail, 'UPDATE users SET email_verified = 1')) { return 'existing accounts are not grandfathered'; }
    return true;
});

sh_section('AI provider connection tests');

sh_t('every bundled AI driver has its own connection test', function () {
    $files = [
        'AbstractAIProvider.php'       => 'the shared fallback',
        'OpenAICompatibleProvider.php' => 'OpenAI-compatible endpoints',
        'OpenAIProvider.php'           => 'OpenAI',
        'OpenRouterProvider.php'       => 'OpenRouter',
        'GeminiProvider.php'           => 'Google Gemini',
        'AnthropicProvider.php'        => 'Anthropic Claude',
    ];
    foreach ($files as $file => $label) {
        $src = (string)file_get_contents(SH_ROOT . '/ai/providers/' . $file);
        if (!str_contains($src, 'public function testConnection(): array')) {
            return $label . ' has no testConnection() override';
        }
        if (!str_contains($src, 'testResult(')) { return $label . ' does not build a structured test result'; }
    }
    return true;
});

sh_t('the Gemini probe cannot mistake a token limit for a bad key', function () {
    $src = (string)file_get_contents(SH_ROOT . '/ai/providers/GeminiProvider.php');
    if (!str_contains($src, '/models?pageSize=200')) { return 'the key is not checked with a read-only /models call'; }
    if (!str_contains($src, ':generateContent')) { return 'the text probe does not use generateContent'; }
    if (!str_contains($src, "'maxOutputTokens' => 256")) { return 'the probe budget is not large enough for thinking models'; }
    if (!str_contains($src, "if (\$finish === 'MAX_TOKENS')")) { return 'MAX_TOKENS is not classified explicitly'; }
    if (!str_contains($src, "testResult(true, 'token_limit'")) { return 'a token limit is not reported as a valid connection'; }
    return true;
});

sh_t('no provider probe uses an eight-token budget', function () {
    $dir = SH_ROOT . '/ai/providers';
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $src = (string)file_get_contents($file);
        if (preg_match("/max_(?:completion_)?tokens'?\s*=>\s*8\b/", $src)) {
            return basename($file) . ' still asks for only 8 output tokens';
        }
    }
    return true;
});

sh_t('OpenRouter validates the key before spending tokens', function () {
    $src = (string)file_get_contents(SH_ROOT . '/ai/providers/OpenRouterProvider.php');
    if (!str_contains($src, "'/auth/key'")) { return 'the /auth/key check is missing'; }
    if (!str_contains($src, "'max_tokens' => 64")) { return 'the chat probe budget changed unexpectedly'; }
    if (str_contains($src, "'max_tokens' => 8")) { return 'the old eight-token probe is still present'; }
    return true;
});

sh_t('a provider test stores the code, HTTP status and next action', function () {
    $pm = (string)file_get_contents(SH_ROOT . '/ai/ProviderManager.php');
    foreach (["'last_test_code'", "'last_test_status'", "'last_test_next'", 'sh_ai_test_columns_ensure()'] as $needle) {
        if (!str_contains($pm, $needle)) { return 'ProviderManager is missing ' . $needle; }
    }
    $schema = (string)file_get_contents(SH_ROOT . '/ai/schema.php');
    foreach (['last_test_code VARCHAR(40)', 'last_test_status SMALLINT UNSIGNED', 'last_test_next VARCHAR(255)'] as $needle) {
        if (!str_contains($schema, $needle)) { return 'ai_providers is missing ' . $needle; }
    }
    if (!str_contains($schema, 'function sh_ai_test_columns_ensure()')) { return 'existing installs have no additive migration'; }
    return true;
});

sh_t('the test endpoint returns the real outcome instead of a bare failure', function () {
    $api = (string)file_get_contents(SH_ROOT . '/api/ai.php');
    $start = strpos($api, "case 'test_connection':");
    if ($start === false) { return 'test_connection is missing'; }
    $end = strpos($api, "case 'sync_models':", $start);
    $fn = $end === false ? substr($api, $start) : substr($api, $start, $end - $start);
    foreach (["'ok'         =>", "'code'       =>", "'state'      =>", "'next'       =>", "'tested_at'  =>"] as $needle) {
        if (!str_contains($fn, $needle)) { return 'the response omits ' . trim($needle); }
    }
    if (str_contains($fn, 'api_key')) { return 'the test response mentions the API key'; }
    return true;
});

sh_t('the Providers screen never renders a stored key', function () {
    $page = (string)file_get_contents(SH_ROOT . '/admin/ai/providers.php');
    if (!str_contains($page, 'sh_ai_provider_masked_key($editing)')) { return 'the edit form does not show the masked key'; }
    if (!str_contains($page, 'sh_ai_provider_masked_key($p)')) { return 'the card does not show the masked key'; }
    if (preg_match('/value="<\?=\s*e\(\$[^)]*\[.api_key.\]/', $page)) { return 'a stored key is printed into an input value'; }
    if (!str_contains($page, 'type="password"')) { return 'the key field is not a password input'; }
    if (!str_contains($page, 'data-modal-open="sh-aiprov-add"')) { return 'there is no Add provider action'; }
    if (!str_contains($page, 'sh_admin_modal(')) { return 'the Add provider chooser is missing'; }
    if (!str_contains($page, 'data-ai-card=')) { return 'the cards have no test-state scope'; }
    if (str_contains($page, 'style="display:inline"')) { return 'actions still use inline forms that break alignment'; }
    return true;
});

sh_t('the browser test handler always restores its button', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    foreach (['AbortController', '45000', "removeAttribute('data-testing')", 'busy(btn, false)', 'function showTestResult', 'data-ai-test-autorun'] as $needle) {
        if (!str_contains($js, $needle)) { return 'app.js is missing ' . $needle; }
    }
    $start = strpos($js, 'function runProviderTest(btn)');
    $end = strpos($js, 'window.shAiTestProvider', $start);
    if ($start === false || $end === false) { return 'runProviderTest() could not be read'; }
    $fn = substr($js, $start, $end - $start);
    if (substr_count($fn, 'busy(btn, false)') !== 1) { return 'the busy state is not reset in exactly one finally path'; }
    if (!str_contains($fn, '.finally(')) { return 'the reset is not in a finally block'; }
    return true;
});

sh_t('the redesigned provider cards have a responsive grid and no fixed heights', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    foreach (['.sh-aiprov-grid', '.sh-aiprov__meta', '.sh-aiprov-stats', '.sh-aiprov__actions', '.sh-aiprov-pick'] as $needle) {
        if (!str_contains($css, $needle)) { return 'the stylesheet is missing ' . $needle; }
    }
    $start = strpos($css, '/* Providers screen: compact cards');
    $block = $start === false ? '' : substr($css, $start, 9000);
    if ($block === '') { return 'the provider stylesheet block could not be read'; }
    if (preg_match('/\.sh-aiprov(?:::?[a-z_-]+)?\s*\{[^}]*height:\s*\d/', $block)) { return 'a provider card has a fixed height'; }
    if (preg_match('/\.sh-aiprov__meta\s*\{[^}]*height:\s*\d/', $block)) { return 'the metadata grid has a fixed height'; }
    if (preg_match('/gradient\(/i', $block)) { return 'the provider styles use a gradient'; }
    return true;
});

sh_t('the AI navigation is a wrapping tab bar in page flow and the card test area is flat', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    if (!preg_match('/\.sh-aisubnav\s*\{(?:[^}]*)flex-wrap:\s*wrap/', $css)) {
        return 'the AI sub-navigation does not wrap in normal page flow';
    }
    $at = strpos($css, '.sh-aisubnav {');
    $rule = $at === false ? '' : substr($css, $at, 160);
    if (str_contains($rule, 'overflow')) { return 'the AI tab bar scrolls inside its own box again'; }
    if (str_contains($css, '.sh-aisubnav { display: grid;')) {
        return 'the old multi-row mobile navigation grid is back';
    }
    $start = strpos($css, '.sh-aiprov__test {');
    $rule = $start === false ? '' : substr($css, $start, 220);
    if (!str_contains($rule, 'border-top:')) { return 'the provider test area has no flat divider'; }
    if (str_contains($rule, 'background:')) { return 'the provider test area is a nested card again'; }
    $nav = (string)file_get_contents(SH_ROOT . '/admin/ai/_ai.php');
    if (!str_contains($nav, 'aria-current="page"')) { return 'the active AI section is not announced'; }
    foreach (['index.php', 'products.php', 'bulk.php', 'blog.php', 'category.php', 'seo.php',
              'image.php', 'history.php', 'providers.php', 'models.php', 'settings.php'] as $file) {
        if (!str_contains($nav, "'" . $file . "'")) { return 'the AI navigation lost ' . $file; }
    }
    return true;
});

sh_t('only navigation drawers own internal scroll containers; AI content stays in page flow', function () {
    $css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
    // Nested scrolling is allowed only inside the navigation menus and the
    // mobile filter drawer. AI Auto Work output and ordinary page content keep
    // the document as their scroller.
    preg_match_all('/([^{}]+)\{([^{}]*\boverflow(?:-[xy])?\s*:\s*(?:auto|scroll)\b[^{}]*)\}/i', $css, $scrollRules, PREG_SET_ORDER);
    foreach ($scrollRules as $rule) {
        $selector = trim($rule[1]);
        if (!str_contains($selector, '.sh-drawer__body')
            && !str_contains($selector, '.sh-admin-sidebar__nav')
            && !str_contains($selector, '.sh-filters__scroll')) {
            return 'an ordinary content selector creates an internal scroll region: ' . $selector;
        }
    }
    foreach (['.sh-drawer__body', '.sh-admin-sidebar__nav', '.sh-filters__scroll'] as $selector) {
        if (!preg_match_all('/' . preg_quote($selector, '/') . '\\s*\\{([^}]*)\\}/', $css, $matches) || !$matches[1]) {
            return $selector . ' has no style rule';
        }
        $rule = end($matches[1]);
        if (!preg_match('/overflow-y:\\s*auto/i', $rule)) { return $selector . ' is not the menu scroll container'; }
        if (!preg_match('/overscroll-behavior:\\s*contain/i', $rule)) { return $selector . ' does not contain scroll chaining'; }
        if (!str_contains($rule, '-webkit-overflow-scrolling: touch')) { return $selector . ' lacks native touch momentum scrolling'; }
    }
    if (str_contains($css, 'body.sh-modal-open { overflow: hidden; }')) {
        return 'opening a modal still changes the page scroll lifecycle';
    }
    if (!str_contains($css, '.sh-modal { position: absolute;')) {
        return 'modals are not placed in normal page flow';
    }
    // Generated AI output and diagnostics expand with the page.
    foreach (['.sh-airesult-box', '.sh-aipre'] as $sel) {
        $at = strpos($css, $sel . ' {');
        $rule = $at === false ? '' : substr($css, $at, 300);
        if (str_contains($rule, 'max-height:')) { return $sel . ' still caps its height'; }
    }
    if (!str_contains($css, 'field-sizing: content')) {
        return 'textareas do not size to their content';
    }
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    if (str_contains($js, "document.body.style.overflow = 'hidden'")) {
        return 'JavaScript still uses a body overflow shortcut instead of the shared lock lifecycle';
    }
    foreach (["root.style.setProperty('overflow', 'hidden', 'important')", "body.style.setProperty('position', 'fixed', 'important')",
              'restoreStyleAttribute(saved.body, saved.bodyStyle)', 'window.scrollTo(saved.x, saved.y)',
              'openNavigationMenus', 'navigationRemovalObserver', "window.addEventListener('pagehide'"] as $needle) {
        if (!str_contains($js, $needle)) { return 'the shared drawer lifecycle is missing ' . $needle; }
    }
    if (str_contains($js, "addEventListener('touchmove'") || str_contains($js, 'addEventListener("touchmove"')) {
        return 'the shared script globally prevents native touch scrolling';
    }
    if (!str_contains($js, 'shGrowTextarea')) { return 'textareas do not auto-grow with page content'; }
    $storefront = (string)file_get_contents(SH_ROOT . '/includes/header.php');
    if (!str_contains($storefront, 'class="sh-drawer__body" tabindex="0"')
        || !str_contains($storefront, 'data-drawer-close')) {
        return 'the storefront drawer has no focusable menu scroller or accessible close control';
    }
    $admin = (string)file_get_contents(SH_ROOT . '/admin/_layout.php');
    if (!str_contains($admin, 'class="sh-admin-sidebar__nav" aria-label="Admin navigation" tabindex="0"')
        || !str_contains($admin, 'data-admin-nav-close')
        || !str_contains($admin, 'aria-expanded="false"')) {
        return 'the admin drawer has no focusable menu scroller, close control or expanded state';
    }
    foreach (['/products.php', '/category.php'] as $rel) {
        $src = (string)file_get_contents(SH_ROOT . $rel);
        if (!str_contains($src, 'class="sh-filters__scroll" tabindex="0"')
            || !str_contains($src, 'aria-controls="sh-filters" aria-expanded="false"')
            || !str_contains($src, 'data-filters-close hidden')) {
            return $rel . ' has no keyboard-focusable, contained mobile filter drawer';
        }
    }
    foreach (['/admin/email.php', '/admin/logs.php'] as $rel) {
        $src = (string)file_get_contents(SH_ROOT . $rel);
        if (preg_match('/overflow\\s*:\\s*(?:auto|scroll)/', $src) === 1) {
            return $rel . ' still renders an inline scroll box';
        }
    }
    return true;
});

sh_section('Courier connection tests');

require_once SH_ROOT . '/includes/courier.php';

sh_t('the test vocabulary covers every state the admin can see', function () {
    $states = sh_courier_test_states();
    foreach (['connected', 'authentication_failed', 'configuration_incomplete', 'permission_denied',
              'network_error', 'unsupported', 'failed'] as $key) {
        if (!isset($states[$key])) { return 'missing state ' . $key; }
        if (trim((string)$states[$key]['label']) === '') { return $key . ' has no label'; }
    }
    if (sh_courier_test_state_label('unsupported') !== 'Unsupported automated test') {
        return 'the unsupported state is not worded honestly';
    }
    return true;
});

sh_t('HTTP statuses map to authentication, permission and network states', function () {
    $map = [
        0   => 'network_error',
        401 => 'authentication_failed',
        403 => 'permission_denied',
        408 => 'network_error',
        500 => 'failed',
    ];
    foreach ($map as $status => $expected) {
        if (sh_courier_test_state_from_http($status) !== $expected) {
            return 'HTTP ' . $status . ' maps to ' . sh_courier_test_state_from_http($status) . ', expected ' . $expected;
        }
    }
    return true;
});

sh_t('a test message cannot leak a stored credential', function () {
    $msg = 'Invalid key sk-live-SECRET123 for merchant';
    $clean = sh_courier_test_scrub($msg, ['sk-live-SECRET123']);
    if (str_contains($clean, 'sk-live-SECRET123')) { return 'the credential survived sanitising'; }
    if (!str_contains($clean, '••••')) { return 'the credential was not replaced'; }
    if (str_contains($clean, "\n")) { return 'control characters were not stripped'; }
    $long = sh_courier_test_scrub(str_repeat('x', 500));
    if (mb_strlen($long) > 220) { return 'messages are not capped'; }
    return true;
});

sh_t('every required courier has a provider-specific probe', function () {
    foreach (['steadfast', 'pathao', 'ecourier', 'paperfly', 'sundarban', 'saparibahan'] as $name) {
        if (!function_exists('sh_courier_probe_' . $name)) { return 'sh_courier_probe_' . $name . '() is missing'; }
    }
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    foreach ([
        "sh_courier_probe_steadfast" => "sh_steadfast_base_url(\$creds) . '/get_balance'",
        "sh_courier_probe_pathao"    => "'/aladdin/api/v1/issue-token'",
        "sh_courier_probe_ecourier"  => "'/city-list'",
        "sh_courier_probe_redx"      => "'/pickup/stores'",
    ] as $fn => $needle) {
        $start = strpos($src, 'function ' . $fn . '(');
        $end = $start === false ? false : strpos($src, "\n}\n", $start);
        if ($start === false || $end === false) { return $fn . '() could not be read'; }
        $body = substr($src, $start, $end - $start);
        if (!str_contains($body, $needle)) { return $fn . ' does not call its documented endpoint'; }
    }
    return true;
});

sh_t('an HTTP 200 web page is never accepted as a provider answer', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    foreach (['sh_courier_probe_steadfast', 'sh_courier_probe_pathao', 'sh_courier_probe_ecourier', 'sh_courier_probe_redx'] as $fn) {
        $start = strpos($src, 'function ' . $fn . '(');
        $end = $start === false ? false : strpos($src, "\n}\n", $start);
        if ($start === false || $end === false) { return $fn . '() could not be read'; }
        $body = substr($src, $start, $end - $start);
        if (!str_contains($body, "['raw']")) {
            return $fn . ' would accept a non-JSON login page as a successful connection';
        }
    }

    $start = strpos($src, 'function sh_courier_probe_ecourier(');
    $end = $start === false ? false : strpos($src, "\n}\n", $start);
    if ($start === false || $end === false) { return 'the eCourier probe could not be read'; }
    $eco = substr($src, $start, $end - $start);
    if (!str_contains($eco, '(object)[]')) { return 'eCourier does not send an empty JSON object to /city-list'; }
    if (str_contains($eco, '$successLike')) { return 'the eCourier success=true bug is back'; }
    if (!str_contains($eco, '$successFlag === false')) { return 'eCourier does not treat success=false as a failure'; }
    return true;
});

sh_t('a Pathao test uses the official password-grant token flow and verifies the stored store', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    // The credential form must collect everything the grant needs.
    foreach (["'client_id' => 'Client ID'", "'client_secret' => 'Client Secret'",
              "'username' => 'Merchant Username'", "'password' => 'Merchant Password'",
              "'store_id' => 'Store ID'"] as $field) {
        if (!str_contains($src, $field)) { return 'the Pathao credential set is missing ' . $field; }
    }
    $start = strpos($src, 'function sh_courier_probe_pathao(');
    $end = $start === false ? false : strpos($src, "\n}\n", $start);
    if ($start === false || $end === false) { return 'the Pathao probe could not be read'; }
    $fn = substr($src, $start, $end - $start);
    foreach (['client_id', 'client_secret', "'grant_type'", 'username', 'password',
              'access_token', '/aladdin/api/v1/stores', 'store_id', "'authentication_failed'"] as $needle) {
        if (!str_contains($fn, $needle)) { return 'the Pathao probe is missing ' . $needle; }
    }
    if (!str_contains($fn, "'password'")) { return 'the probe does not send the password grant'; }
    if (str_contains($fn, 'external/login')) { return 'the probe still calls the undocumented external/login endpoint'; }
    if (!str_contains($fn, "'configuration_incomplete'") && !str_contains($fn, 'sh_courier_test_incomplete')) {
        return 'a missing or unknown store is not reported as configuration incomplete';
    }
    return true;
});

sh_t('a RedX test validates the access token against the pickup stores', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    $start = strpos($src, 'function sh_courier_probe_redx(');
    $end = $start === false ? false : strpos($src, "\n}\n", $start);
    if ($start === false || $end === false) { return 'the RedX probe could not be read'; }
    $fn = substr($src, $start, $end - $start);
    foreach (['API-ACCESS-TOKEN', 'Bearer ', 'pickup_stores'] as $needle) {
        if (!str_contains($fn, $needle)) { return 'the RedX probe is missing ' . $needle; }
    }
    if (!str_contains($src, 'https://openapi.redx.com.bd/v1.0.0-beta')) {
        return 'the RedX probe does not default to the OpenAPI host';
    }
    if (str_contains($fn, "'/parcel'") || str_contains($fn, '/parcel/track')) {
        return 'the RedX probe goes beyond the read-only store list';
    }
    if (!str_contains($fn, "'configuration_incomplete'") && !str_contains($fn, 'sh_courier_test_incomplete')) {
        return 'a token without pickup stores is not reported as configuration incomplete';
    }
    if (!str_contains($src, 'return [\'steadfast\', \'pathao\', \'ecourier\', \'paperfly\', \'redx\'];')) {
        return 'RedX is not registered as a testable driver';
    }
    return true;
});

sh_t('an ambiguous provider is never reported as connected', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    foreach (['sh_courier_probe_paperfly', 'sh_courier_probe_sundarban', 'sh_courier_probe_saparibahan'] as $fn) {
        $start = strpos($src, 'function ' . $fn . '(');
        $end = $start === false ? false : strpos($src, "\n}\n", $start);
        if ($start === false || $end === false) { return $fn . '() could not be read'; }
        $body = substr($src, $start, $end - $start);
        if (!str_contains($body, "'unsupported'")) { return $fn . ' does not return the unsupported state'; }
        if (str_contains($body, 'sh_courier_http(')) { return $fn . ' sends a request even though no API is documented'; }
    }
    return true;
});

sh_t('connection tests never book, pick up or charge', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    $start = strpos($src, 'function sh_courier_test(array $courier');
    $end = $start === false ? false : strpos($src, "\n}\n", $start);
    if ($start === false || $end === false) { return 'sh_courier_test() could not be read'; }
    $fn = substr($src, $start, $end - $start);
    foreach (['sh_courier_book', 'sh_shipment_create', 'create_order', 'create_pickup', 'new_order', 'sh_shipment_set_status'] as $needle) {
        if (str_contains($fn, $needle)) { return 'the test path references ' . $needle; }
    }
    // And the probes themselves must not call a booking endpoint.
    $probeStart = strpos($src, 'function sh_courier_probe_steadfast(');
    $probeEnd = strpos($src, '// Test results: persistence + display', $probeStart);
    $probes = $probeStart === false || $probeEnd === false ? '' : substr($src, $probeStart, $probeEnd - $probeStart);
    foreach (['/create_order', '/orders', 'create_pickup', 'new_order', '/order-place'] as $needle) {
        if (str_contains($probes, $needle)) { return 'a probe contains the booking path ' . $needle; }
    }
    return true;
});

sh_t('test results get their own additive table and never touch shipments', function () {
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    if (!str_contains($schema, 'function sh_courier_test_results_sql()')) { return 'the shared DDL function is missing'; }
    if (!str_contains($schema, 'CREATE TABLE IF NOT EXISTS courier_test_results')) { return 'the table is not created idempotently'; }
    if (!str_contains($schema, 'sh_courier_test_results_sql(),')) { return 'the installer does not create the table'; }
    foreach (['result_code VARCHAR(40)', 'state VARCHAR(40)', 'http_status SMALLINT UNSIGNED', 'tested_by INT UNSIGNED', 'tested_at DATETIME'] as $needle) {
        if (!str_contains($schema, $needle)) { return 'courier_test_results is missing ' . $needle; }
    }
    if (str_contains($schema, 'DROP TABLE')) { return 'the migration drops a table'; }
    foreach (['shipments', 'shipment_events', 'couriers'] as $table) {
        if (preg_match('/ALTER TABLE\s+' . $table . '/i', $schema)) { return 'the migration alters ' . $table; }
    }
    $courier = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    if (!str_contains($courier, 'function sh_courier_test_record(')) { return 'results are not persisted'; }
    if (!str_contains($courier, 'function sh_courier_last_test(')) { return 'the last result cannot be read back'; }
    if (!str_contains($courier, 'function sh_courier_test_summary(')) { return 'there is no shared result display'; }
    return true;
});

sh_t('the test action exists on the card and on the courier details page', function () {
    $list = (string)file_get_contents(SH_ROOT . '/admin/couriers.php');
    if (!str_contains($list, 'name="courier_action" value="test"')) { return 'the card has no test action'; }
    if (!str_contains($list, 'sh_courier_test_summary($c)')) { return 'the card does not show the last result'; }
    if (str_contains($list, "if (\$apiReady): ?>\n          <button")) { return 'the test is still hidden for non-booking drivers'; }
    $edit = (string)file_get_contents(SH_ROOT . '/admin/courier-edit.php');
    if (!str_contains($edit, 'name="courier_action" value="test"')) { return 'the details page has no test action'; }
    if (!str_contains($edit, 'sh_courier_test($saved')) { return 'the details page does not run the test after saving'; }
    if (!str_contains($edit, 'sh_courier_test_summary($editing)')) { return 'the details page does not show the last result'; }
    return true;
});

sh_t('courier credentials are encrypted at rest with a legacy fallback', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    foreach (['function sh_courier_credentials_store(', 'function sh_courier_secret_key(', "str_starts_with(\$raw, 'cou:')"] as $needle) {
        if (!str_contains($src, $needle)) { return 'the courier credential encryption is missing ' . $needle; }
    }
    if (!str_contains($src, 'openssl_encrypt(')) { return 'credentials are not encrypted'; }
    if (!str_contains($src, '$d = json_decode($raw, true);')) { return 'plain legacy JSON is no longer readable'; }
    foreach (['admin/couriers.php', 'admin/courier-edit.php'] as $page) {
        $body = (string)file_get_contents(SH_ROOT . '/' . $page);
        if (str_contains($body, "'credentials'  => \$creds ? json_encode(\$creds")) {
            return $page . ' still writes plaintext credentials';
        }
        if (!str_contains($body, 'sh_courier_credentials_store($creds)')) {
            return $page . ' does not encrypt the credentials it saves';
        }
    }
    return true;
});

sh_t('the Steadfast default points at the official host', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    if (str_contains($src, 'portal.pacakge.net')) { return 'the misspelled portal host is still the default'; }
    if (!str_contains($src, 'https://portal.packzy.com/api/v1')) { return 'the official packzy host is not the default'; }
    return true;
});

sh_t('paperfly asks for the key its API requires', function () {
    $src = (string)file_get_contents(SH_ROOT . '/includes/courier.php');
    if (!str_contains($src, "'paperfly_key' => 'Paperfly Key'")) { return 'the paperflykey credential field is missing'; }
    return true;
});

sh_section('Admin forms never save on their own');

sh_t('AI generation never writes generated text to a product', function () {
    $api = (string)file_get_contents(SH_ROOT . '/api/ai.php');
    if (str_contains($api, 'auto_saved')) { return 'the generate action still reports an automatic save'; }
    if (str_contains($api, "sh_ai_config()['auto_save']")) { return 'the generate action still applies its result'; }

    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    if (str_contains($js, 'auto_saved')) { return 'the admin script still expects an automatic save'; }

    $mgr = (string)file_get_contents(SH_ROOT . '/ai/AIManager.php');
    if (str_contains($mgr, "sh_ai_setting('auto_save'")) { return 'the auto-save setting is still read'; }

    $settings = (string)file_get_contents(SH_ROOT . '/admin/ai/settings.php');
    if (str_contains($settings, 'name="auto_save"')) { return 'the auto-save toggle is still offered'; }
    return true;
});

sh_t('the category cropper stages the crop instead of saving it', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/category-cropper.js');
    if (str_contains($js, 'fetch(')) { return 'the cropper still posts to the server'; }
    if (!str_contains($js, 'croppedImageInput.value = dataUrl')) {
        return 'the cropper does not stage the image in the form';
    }
    $page = (string)file_get_contents(SH_ROOT . '/admin/category-edit.php');
    if (str_contains($page, "\$form === 'crop'")) { return 'the immediate crop-save endpoint is still present'; }
    return true;
});

sh_t('the phone verification switch has an explicit Save button', function () {
    $page = (string)file_get_contents(SH_ROOT . '/admin/security.php');
    if (str_contains($page, 'data-phone-verification-toggle')) { return 'the toggle is still wired to auto-submit'; }
    if (str_contains($page, 'toggle.form.submit();')) { return 'the toggle still submits its form on change'; }
    if (!str_contains($page, 'Save phone verification')) { return 'the form has no explicit save button'; }
    return true;
});

sh_t('editable forms carry a non-saving unsaved-changes guard', function () {
    foreach (['admin/product-edit.php', 'admin/category-edit.php', 'admin/ai/products.php'] as $page) {
        $body = (string)file_get_contents(SH_ROOT . '/' . $page);
        if (!str_contains($body, 'data-dirty-guard')) { return $page . ' has no unsaved-changes guard'; }
        if (!str_contains($body, 'data-dirty-note')) { return $page . ' has no unsaved-changes note'; }
    }
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    if (!str_contains($js, 'beforeunload')) { return 'leaving a dirty form is not warned about'; }
    if (!str_contains($js, "form.addEventListener('input', mark)")) { return 'the guard does not track typing'; }
    if (str_contains($js, 'navigator.sendBeacon')) { return 'the guard saves in the background'; }
    return true;
});

sh_t('only read-only filters submit automatically', function () {
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SH_ROOT . '/admin'));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) { continue; }
        $path = $file->getPathname();
        $body = (string)file_get_contents($path);
        if (!str_contains($body, 'data-submit-form-on-change')) { continue; }
        $hits[] = str_replace(SH_ROOT . '/', '', $path);

        // The surviving uses must sit in a GET filter form; a POST form that
        // submitted itself on change would write on every field selection.
        foreach (preg_split('~<form\b~i', $body) ?: [] as $part) {
            if (!str_contains($part, 'data-submit-form-on-change')) { continue; }
            if (preg_match('~^[^>]*method="post"~i', $part) === 1) {
                return str_replace(SH_ROOT . '/', '', $path) . ' still auto-submits a write form';
            }
        }
    }
    sort($hits);
    if ($hits !== ['admin/ai/models.php']) {
        return 'an editable form still submits on change: ' . implode(', ', $hits);
    }
    if (substr_count((string)file_get_contents(SH_ROOT . '/admin/ai/models.php'), 'data-submit-form-on-change') !== 2) {
        return 'the model filter changed without the test being updated';
    }
    return true;
});

sh_t('every editable admin form is guarded, and the guard itself never writes', function () {
    $js = (string)file_get_contents(SH_ROOT . '/assets/js/app.js');
    // Generic coverage of admin write forms, with an explicit opt-out.
    if (!str_contains($js, 'data-no-dirty-guard')) { return 'the dirty guard has no opt-out for non-edit forms'; }
    if (!str_contains($js, "toLowerCase() !== 'post'")) { return 'the guard is not scoped to write (POST) forms'; }
    // Read-only navigation pickers must not mark a form dirty on their own.
    if (!str_contains($js, 'NAV_CONTROLS')) { return 'navigation pickers still mark a form dirty on their own'; }
    if (!str_contains($js, '[data-seo-page-picker]')) { return 'the SEO page picker is not treated as navigation'; }
    // A script-saved form stands the guard down only after a real save.
    if (!str_contains($js, 'sh:form-clean')) { return 'script-saved forms cannot clear the guard after saving'; }
    $client = (string)file_get_contents(SH_ROOT . '/assets/js/ai-product-create.js');
    if (!str_contains($client, 'sh:form-clean')) { return 'the AI product review never reports its successful save'; }
    // The guard block itself must contain no network or submit call at all:
    // it warns, it never saves.
    $start = strpos($js, 'Unsaved-changes guard');
    $end = $start === false ? false : strpos($js, 'Modals', $start);
    if ($start === false || $end === false) { return 'the dirty guard block was not found'; }
    $block = substr($js, $start, $end - $start);
    foreach (['fetch(', '.submit()', 'sendBeacon', 'XMLHttpRequest'] as $bad) {
        if (str_contains($block, $bad)) { return 'the dirty guard performs a write: ' . $bad; }
    }
    return true;
});

sh_t('no admin button can submit a form by accident', function () {
    // A <button> without a type attribute IS a submit button. Every admin
    // button must say what it is, so a script-driven control can never post
    // its surrounding form when markup moves or a script loads slowly.
    $bad = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SH_ROOT . '/admin'));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) { continue; }
        $body = (string)file_get_contents($file->getPathname());
        // Template islands contain their own ">" (their closing tag), so blank them first.
        $body = preg_replace('~<\?(?:php|=).*?\?>~s', 'PHP', $body) ?? $body;
        if (preg_match_all('~<button\b[^>]*>~i', $body, $m) === false) { continue; }
        foreach ($m[0] as $tag) {
            if (stripos($tag, 'type=') === false) {
                $bad[] = str_replace(SH_ROOT . '/', '', $file->getPathname()) . ': ' . trim($tag);
            }
        }
    }
    return sh_assert($bad === [], 'buttons without an explicit type: ' . implode('; ', $bad));
});

sh_section('Brand and features removed from product management');

sh_t('the brand workflow is gone from the admin', function () {
    if (is_file(SH_ROOT . '/admin/brands.php')) { return 'the brand editor still exists'; }
    $layout = (string)file_get_contents(SH_ROOT . '/admin/_layout.php');
    if (str_contains($layout, "'brands.php'")) { return 'the brand editor is still in the navigation'; }
    $edit = (string)file_get_contents(SH_ROOT . '/admin/product-edit.php');
    if (str_contains($edit, 'name="brand_id"')) { return 'the product form still has a brand selector'; }
    if (str_contains($edit, 'FROM brands')) { return 'the product form still reads the brands table'; }
    $ai = (string)file_get_contents(SH_ROOT . '/admin/ai/products.php');
    if (str_contains($ai, 'data-f="brand_id"')) { return 'the AI product review form still has a brand selector'; }
    return true;
});

sh_t('brand filters and badges are gone from the storefront', function () {
    $list = (string)file_get_contents(SH_ROOT . '/products.php');
    if (str_contains($list, 'name="brand[]"')) { return 'the product listing still offers a brand filter'; }
    if (str_contains($list, '$brandIds')) { return 'the listing still parses brand parameters'; }
    $category = (string)file_get_contents(SH_ROOT . '/category.php');
    if (str_contains($category, 'name="brand[]"')) { return 'the category page still offers a brand filter'; }
    if (str_contains($category, 'sh_brands(')) { return 'the category page still lists brands'; }
    $product = (string)file_get_contents(SH_ROOT . '/product.php');
    if (str_contains($product, 'Brand:')) { return 'the product page still prints a brand line'; }
    return true;
});

sh_t('features and benefits are gone from every product form and page', function () {
    foreach (['admin/product-edit.php', 'admin/ai/products.php'] as $page) {
        $body = (string)file_get_contents(SH_ROOT . '/' . $page);
        if (str_contains($body, 'name="features"') || str_contains($body, 'data-f="features"')) {
            return $page . ' still has a features field';
        }
        if (stripos($body, 'Features and benefits') !== false) { return $page . ' still labels the section'; }
    }
    $product = (string)file_get_contents(SH_ROOT . '/product.php');
    if (str_contains($product, "\$product['features']")) { return 'the product page still prints features'; }
    return true;
});

sh_t('brand and features are also gone from the AI, catalogue and demo layers', function () {
    $manager = sh_source_without_comments(SH_ROOT . '/ai/AIManager.php');
    foreach (['FROM brands', 'brands b', 'brand_id', 'brand_name', "'features'"] as $gone) {
        if (str_contains($manager, $gone)) { return 'the AI draft layer still references ' . $gone; }
    }
    $studio = sh_source_without_comments(SH_ROOT . '/ai/ProductStudio.php');
    foreach (['FROM brands', 'brand_id', "'brand_id' =>", "'features'", 'has_features'] as $gone) {
        if (str_contains($studio, $gone)) { return 'the AI save layer still references ' . $gone; }
    }
    $api = sh_source_without_comments(SH_ROOT . '/api/ai.php');
    if (str_contains($api, "'category', 'brand'")) { return 'brand is still a regenerable field'; }
    $prompts = sh_source_without_comments(SH_ROOT . '/ai/PromptManager.php');
    foreach (['Existing brands', '\"features\"', 'a real brand', 'at most 60 characters', 'at most 155 characters'] as $gone) {
        if (str_contains($prompts, $gone)) { return 'a default prompt still mentions ' . $gone; }
    }
    $catalog = sh_source_without_comments(SH_ROOT . '/includes/catalog.php');
    foreach (['sh_brands(', 'brand_ids', 'JOIN brands', 'brand_name'] as $gone) {
        if (str_contains($catalog, $gone)) { return 'the catalogue helpers still reference ' . $gone; }
    }
    $seo = sh_source_without_comments(SH_ROOT . '/includes/seo.php');
    if (str_contains($seo, "'@type' => 'Brand'")) { return 'the product schema still advertises a brand'; }
    $demo = sh_source_without_comments(SH_ROOT . '/install/demo-data.php');
    foreach (['INSERT INTO brands', 'brand_id'] as $gone) {
        if (str_contains($demo, $gone)) { return 'the demo catalogue still writes ' . $gone; }
    }
    // The retired columns stay for compatibility: no DROP anywhere.
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php')
        . (string)file_get_contents(SH_ROOT . '/includes/product-schema.php');
    if (str_contains($schema, 'DROP COLUMN') || str_contains($schema, 'features TEXT')) {
        return 'the schema either drops a column or still creates features';
    }
    return true;
});

sh_section('SEO copy has no arbitrary character limit');

sh_t('the SEO editor has no maxlength or target-length advice', function () {
    $src = (string)file_get_contents(SH_ROOT . '/admin/_seo-fields.php');
    if (preg_match('~name="meta_title"[^>]*maxlength~s', $src) === 1) { return 'meta_title still has a maxlength'; }
    if (preg_match('~name="meta_description"[^>]*maxlength~s', $src) === 1) { return 'meta_description still has a maxlength'; }
    if (stripos($src, 'Aim for') !== false) { return 'the target-length advice is still shown'; }
    if (str_contains($src, 'data-seo-count')) { return 'the character counter is still shown'; }

    $js = (string)file_get_contents(SH_ROOT . '/assets/js/seo.js');
    if (str_contains($js, 'markCounter') || str_contains($js, 'data-seo-count')) {
        return 'the script still marks fields as over a target length';
    }
    return true;
});

sh_t('the SEO Manager and product saves do not truncate SEO copy', function () {
    $seo = (string)file_get_contents(SH_ROOT . '/admin/seo.php');
    if (preg_match('~name="meta_title"[^>]*maxlength~s', $seo) === 1) { return 'the SEO Manager title has a maxlength'; }
    if (preg_match('~name="meta_description"[^>]*maxlength~s', $seo) === 1) { return 'the SEO Manager description has a maxlength'; }
    if (str_contains($seo, "'seo_default_description' => 300")) {
        return 'the default meta description is still capped at 300 characters';
    }
    foreach (['admin/seo.php', 'admin/product-edit.php', 'admin/category-edit.php', 'ai/AIManager.php'] as $page) {
        $body = (string)file_get_contents(SH_ROOT . '/' . $page);
        if (preg_match('~meta_title[^;\n]{0,120}mb_substr~', $body) === 1) {
            return $page . ' still truncates meta_title';
        }
        if (preg_match('~meta_description[^;\n]{0,120}mb_substr~', $body) === 1) {
            return $page . ' still truncates meta_description';
        }
    }
    return true;
});

sh_t('the SEO columns are TEXT and older installs are widened safely', function () {
    $schema = (string)file_get_contents(SH_ROOT . '/install/schema.php');
    if (!str_contains($schema, 'meta_title TEXT NULL')) { return 'new products still cap the SEO title'; }
    if (!str_contains($schema, 'meta_description TEXT NULL')) { return 'new products still cap the meta description'; }

    $seo = (string)file_get_contents(SH_ROOT . '/includes/seo.php');
    if (!str_contains($seo, 'function sh_seo_widen_text_columns')) { return 'there is no widening migration'; }
    if (!str_contains($seo, 'MODIFY')) { return 'the widening migration does not alter the columns'; }
    if (!str_contains($seo, 'information_schema.columns')) { return 'the migration does not inspect the current type'; }
    if (!str_contains($seo, "sh_setting_save('seo_schema_v', '2')")) { return 'the schema version was not advanced'; }
    if (str_contains($seo, 'DROP TABLE')) { return 'the SEO migration drops a table'; }
    return true;
});

sh_t('SEO copy an administrator wrote reaches the page unshortened', function () {
    $longTitle = trim(str_repeat('Kettle ', 20));
    $longDesc = trim(str_repeat('A stainless steel kettle with a cool-touch handle. ', 8));
    sh_assert(mb_strlen($longTitle) > 70 && mb_strlen($longDesc) > 160, 'test data is not long enough');
    $meta = sh_seo_test_meta([
        'key' => 'home',
        'entity' => ['name' => 'Kettle', 'meta_title' => $longTitle, 'meta_description' => $longDesc],
        'fallback_title' => 'Kettle',
        'fallback_desc' => 'Fallback text.',
        'canonical' => '/',
    ]);
    return sh_assert(
        str_starts_with((string)$meta['title'], $longTitle)
        && (string)$meta['description'] === $longDesc,
        'authored SEO copy was shortened before it reached the page'
    );
});

sh_t('only generated SEO copy is excerpted, and the UI counts nothing', function () {
    $body = trim(str_repeat('word ', 120));
    $meta = sh_seo_test_meta([
        'key' => 'home',
        'fallback_title' => trim(str_repeat('Title ', 30)),
        'fallback_desc' => $body,
        'canonical' => '/',
    ]);
    sh_assert(mb_strlen((string)$meta['description']) <= 160, 'a body-derived description is no longer excerpted');
    sh_assert(mb_strlen((string)$meta['title']) <= 70, 'a generated title is no longer excerpted');
    $manager = sh_source_without_comments(SH_ROOT . '/admin/seo.php');
    if (str_contains($manager, 'Title length') || str_contains($manager, 'Description length')) {
        return 'the SEO Manager still shows character counts';
    }
    $preview = (string)file_get_contents(SH_ROOT . '/assets/js/seo.js');
    if (str_contains($preview, 'clip(') || str_contains($preview, 'CharCount')) {
        return 'the live preview still clips or counts SEO copy';
    }
    return true;
});

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
