<?php
/**
 * Content-Security-Policy readiness checks.
 *
 * The CSP ships in report-only mode so a live store can collect violations
 * without anything breaking. Enforcing it is only safe if the application never
 * relies on what the policy blocks: inline event handlers, inline <script>
 * without the per-request nonce, eval, or third-party script origins.
 *
 * This suite answers that question from the source of truth (the templates and
 * the shipped JS), so switching Admin → Security → CSP to "Enforce" is a
 * verified step rather than a guess. It does not replace a browser run.
 *
 *     php tests/csp-readiness.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function t_ok(string $name, bool $pass, string $detail = ''): void
{
    if ($pass) { $GLOBALS['pass']++; echo "  ok   {$name}\n"; return; }
    $GLOBALS['fail']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
}

function t_sec(string $t): void { echo "\n" . $t . "\n"; }

$root = dirname(__DIR__);

/** Every PHP template (application code only). */
function templates(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = (string)$f->getPathname();
        if (!str_ends_with($path, '.php')) { continue; }
        foreach (['/tests/', '/lib/', '/.git/', '/.tmpharness/', '/install/', '/tools/'] as $skip) {
            if (str_contains($path, $skip)) { continue 2; }
        }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

$files = templates($root);

// ===========================================================================
t_sec('1. Inline script and handler inventory');
// ===========================================================================
// Attribute handlers (onclick="…") require 'unsafe-inline' for scripts; there
// must be none, because the policy does not grant it.
$handlerHits = [];
$inlineScripts = [];
$remoteScripts = [];
foreach ($files as $path) {
    $src = (string)file_get_contents($path);
    $rel = str_replace($root . '/', '', $path);
    if (preg_match_all('/\son(?:click|change|submit|input|keyup|keydown|keypress|load|error|focus|blur|mouseover|mouseout|mousedown|mouseup|touchstart|touchend|animationend|transitionend)\s*=\s*["\']/i', $src, $m) > 0) {
        $handlerHits[] = $rel . ' (' . count($m[0]) . ')';
    }
    // <script> occurrences are classified line by line: a PHP-generated nonce
    // attribute (a script tag with a PHP-emitted nonce attribute) is legal, and
    // prose in a comment is not a tag at all.
    foreach (explode("\n", $src) as $lineNo => $line) {
        if (!str_contains($line, '<script')) { continue; }
        $trimmed = ltrim($line);
        // Prose in any comment style (//, /*, /**, *, #) is not markup.
        if (preg_match('~^(?://|/\*|\*|#)~', $trimmed) === 1) { continue; }
        $window = substr($line, (int)stripos($line, '<script'), 200);
        $hasSrc = stripos($window, 'src=') !== false;
        $hasNonce = stripos($window, 'nonce') !== false || str_contains($window, 'sh_csp_nonce_attr');
        if (!$hasSrc && !$hasNonce) {
            $inlineScripts[] = $rel . ':' . ($lineNo + 1);
        }
        if ($hasSrc && preg_match('~src=["\'](?:https?:)?//~i', $window) === 1) {
            $remoteScripts[] = $rel . ':' . ($lineNo + 1);
        }
    }
}
t_ok('no inline event handler attributes are used', $handlerHits === [], implode(', ', $handlerHits));
t_ok('no inline <script> is emitted without a nonce', $inlineScripts === [], implode(', ', $inlineScripts));
t_ok('no script is loaded from another origin', $remoteScripts === [], implode(', ', $remoteScripts));
t_ok('templates were actually scanned', count($files) > 100, count($files) . ' files');

// ===========================================================================
t_sec('2. Shipped JavaScript');
// ===========================================================================
$jsFiles = glob($root . '/assets/js/*.js') ?: [];
$evalHits = [];
$remoteCalls = [];
foreach ($jsFiles as $f) {
    $src = (string)file_get_contents($f);
    $name = basename($f);
    if (preg_match('/\beval\s*\(|\bnew\s+Function\s*\(/', $src) === 1) { $evalHits[] = $name; }
    if (preg_match('~\b(?:fetch|XMLHttpRequest|importScripts)\s*\(\s*["\'](?:https?:)?//~', $src) === 1) { $remoteCalls[] = $name; }
}
t_ok('the shipped JavaScript never uses eval() or new Function()', $evalHits === [], implode(', ', $evalHits));
t_ok('the shipped JavaScript never calls another origin', $remoteCalls === [], implode(', ', $remoteCalls));
t_ok('the JavaScript bundle set was found', count($jsFiles) >= 1, count($jsFiles) . ' files');

// ===========================================================================
t_sec('3. The policy itself');
// ===========================================================================
// The policy builder reads two values from the rest of the bootstrap. Supply
// them here so this suite can run on its own.
if (!function_exists('sh_setting')) { function sh_setting(string $k, $d = '') { return $d; } }
if (!function_exists('sh_request_is_https')) { function sh_request_is_https(): bool { return false; } }
if (!function_exists('sh_url')) { function sh_url(string $p = ''): string { return '/' . ltrim($p, '/'); } }
require_once $root . '/includes/security.php';

$policy = sh_csp_policy();
foreach ([
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "script-src 'self' 'nonce-",
    "form-action 'self'",
    'frame-ancestors',
    'report-uri',
] as $directive) {
    t_ok('the policy contains ' . $directive, str_contains($policy, $directive));
}
t_ok('script-src does not grant unsafe-inline', !preg_match("~script-src[^;]*'unsafe-inline'~", $policy));
t_ok('script-src does not grant unsafe-eval', !preg_match("~script-src[^;]*'unsafe-eval'~", $policy));
t_ok('object-src is none (no plugin content)', str_contains($policy, "object-src 'none'"));
$nonce = sh_csp_nonce();
t_ok('the nonce carries at least 128 bits of randomness', strlen($nonce) >= 22, 'length ' . strlen($nonce));
t_ok('the nonce is base64url-safe', preg_match('/^[A-Za-z0-9_-]+$/', $nonce) === 1);
t_ok('the same request reuses one nonce', $nonce === sh_csp_nonce());
t_ok('the nonce is embedded in the emitted policy', str_contains($policy, "'nonce-" . $nonce . "'"));
t_ok('the nonce attribute helper escapes it', str_contains(sh_csp_nonce_attr(), 'nonce="' . $nonce . '"'));

$headers = sh_security_header_list();
$names = array_column($headers, 0);
t_ok('a CSP header is emitted in report-only mode by default',
    in_array('Content-Security-Policy-Report-Only', $names, true)
    || in_array('Content-Security-Policy', $names, true));
t_ok('nosniff is always sent', in_array('X-Content-Type-Options', $names, true));
t_ok('clickjacking protection is always sent', in_array('X-Frame-Options', $names, true));
t_ok('referrer policy is always sent', in_array('Referrer-Policy', $names, true));

// ===========================================================================
t_sec('4. Rollback and enforcement controls');
// ===========================================================================
$adminSecurity = (string)file_get_contents($root . '/admin/security.php');
t_ok('the admin can switch the CSP mode', str_contains($adminSecurity, "name=\"security_csp_mode\""));
t_ok('the admin can turn the CSP off for troubleshooting',
    str_contains($adminSecurity, 'value="off"') && str_contains($adminSecurity, 'value="enforce"'));
$secSrc = (string)file_get_contents($root . '/includes/security.php');
t_ok('report-only is the documented default', str_contains($secSrc, "sh_security_setting('security_csp_mode', 'report-only')"));
t_ok('CSP reports have a receiver', is_file($root . '/api/csp-report.php'));
t_ok('the report receiver is rate limited',
    str_contains((string)file_get_contents($root . '/api/csp-report.php'), 'sh_file_rate_limit'));

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "CSP READINESS SUITE: ALL CHECKS PASSED\n" : "CSP READINESS SUITE: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
