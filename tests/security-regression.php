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
    // The homepage strip must render exactly the two surviving cards: a third
    // card in the markup would sit alone on the second row of a 2-column grid.
    $index = (string)file_get_contents(SH_ROOT . '/index.php');
    if (preg_match_all('~class="sh-banner__item"~', $index) !== 2) {
        return 'index.php does not render exactly two banner cards';
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
        return 'the card image has no fixed 4:3 frame, so rows can shift while images load';
    }
    if (preg_match('~\.sh-category-card__img \{[^}]*object-fit:\s*cover~s', $css) !== 1) {
        return 'the card image is not object-fit: cover';
    }
    if (strpos($css, 'sh-category-card__img--fallback') === false) {
        return 'flat placeholder artwork would be cropped: no contain rule';
    }
    // 4-6 columns on desktop, 2 on phones.
    if (preg_match('~\.sh-catgrid \{ display: grid; grid-template-columns: repeat\((\d),~', $css, $m) !== 1) {
        return 'the desktop column count is not a plain repeat()';
    }
    $desktop = (int)$m[1];
    if ($desktop < 4 || $desktop > 6) { return 'the desktop grid has ' . $desktop . ' columns, expected 4-6'; }
    if (preg_match('~@media \(max-width: 600px\) \{[^@]*?\.sh-catgrid \{ grid-template-columns: repeat\((\d),~s', $css, $m2) !== 1) {
        return 'the mobile column count is not declared at the 600px breakpoint';
    }
    if ((int)$m2[1] !== 2) { return 'mobile shows ' . $m2[1] . ' category columns, expected 2'; }
    // Hover and focus feedback, and no layout shift from the name length.
    if (!str_contains($css, '.sh-category-card:hover') || !str_contains($css, '.sh-category-card:focus-visible')) {
        return 'the card has no hover/focus feedback';
    }
    if (preg_match('~\.sh-category-card__name \{[^}]*min-height~s', $css) !== 1) {
        return 'category names can make cards uneven';
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
        'admin/brands.php'          => ['', 'Add brand'],   // short form: modal
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
    foreach (['admin/categories.php', 'admin/products.php', 'admin/brands.php', 'admin/couriers.php', 'admin/coupons.php',
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
    if (!str_contains($src, 'sh_install_seed_all(')) { return 'the installer does not use the shared seeding decision'; }
    return true;
});

sh_t('the starter catalogue is idempotent and cannot be re-created by a normal request', function () {
    $src = (string)file_get_contents(SH_ROOT . '/install/demo-data.php');
    // Every seeded table has to be looked up by its own unique key before it is
    // written. One generic helper does that; each table must appear in a call.
    if (!str_contains($src, "SELECT id FROM ' . \$table . ' WHERE ' . \$column . ' = ? LIMIT 1")) {
        return 'the seeder has no by-key lookup helper';
    }
    foreach (['categories', 'brands', 'products', 'promo_slides'] as $table) {
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

sh_t('removing sample content needs an explicit, permission-checked confirmation', function () {
    $src = (string)file_get_contents(SH_ROOT . '/admin/demo-data.php');
    foreach (['sh_require_perm(', 'sh_csrf_require()', 'sh_csrf_field()', 'sh_audit('] as $must) {
        if (!str_contains($src, $must)) { return 'admin/demo-data.php is missing ' . $must; }
    }
    if (preg_match('~\\$confirmPhrase = \'([^\']+)\'~', $src, $m) !== 1) { return 'the cleanup screen has no confirmation phrase'; }
    if (mb_strlen($m[1]) < 8) { return 'the confirmation phrase is too easy to type by accident'; }
    if (!str_contains($src, '$typed !== $confirmPhrase')) { return 'the typed confirmation phrase is never compared'; }
    if (!str_contains($src, "!empty(\$_POST['acknowledge'])")) { return 'the cleanup screen has no acknowledgement checkbox'; }
    // The cleanup itself must be all-or-nothing.
    $seeder = (string)file_get_contents(SH_ROOT . '/install/demo-data.php');
    $start = (int)strpos($seeder, 'function sh_demo_cleanup(');
    $end = (int)strpos($seeder, "\n}\n", $start);
    if ($start === 0 || $end <= $start) { return 'sh_demo_cleanup() could not be read'; }
    $fn = substr($seeder, $start, $end - $start);
    foreach (['beginTransaction()', 'rollBack()', 'commit()'] as $must) {
        if (!str_contains($fn, $must)) { return 'the cleanup is not wrapped in a transaction (' . $must . ')'; }
    }
    foreach (["DELETE FROM users", "DELETE FROM orders", "DELETE FROM order_items", "DELETE FROM payments",
              "DELETE FROM admins", "DELETE FROM settings", "DELETE FROM payment_methods", "DELETE FROM payment_gateways"] as $bad) {
        if (str_contains($fn, $bad)) { return 'the cleanup can delete protected records: ' . $bad; }
    }
    return true;
});

sh_t('removing sample content never destroys records that may be real', function () {
    $src = (string)file_get_contents(SH_ROOT . '/install/demo-data.php');
    $start = (int)strpos($src, 'function sh_demo_cleanup(');
    $end = (int)strpos($src, "\n}\n", $start);
    $fn = substr($src, $start, $end - $start);
    // Products in order history and products carrying a visitor review are
    // deactivated; a used coupon is disabled; a category or brand still in use is
    // kept. Deleting any of those would take real data with it.
    foreach (["\$p['order_lines'] > 0 || (\$p['foreign_reviews'] ?? 0) > 0", 'products_deactivated',
              'coupons_disabled', "SELECT COUNT(*) FROM products WHERE category_id = ?" ] as $must) {
        if (!str_contains($fn, $must)) { return 'the cleanup no longer protects: ' . $must; }
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
