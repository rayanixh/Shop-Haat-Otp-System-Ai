<?php
/**
 * Security response headers and Content Security Policy.
 *
 * Loaded very early by config/config.php so every response — storefront, admin,
 * API and installer — carries the same baseline protections. Nothing here may
 * depend on a working database: a store with a broken connection still needs
 * clickjacking and sniffing protection.
 *
 * Design notes
 * ------------
 * - The CSP ships in REPORT-ONLY mode by default (setting `security_csp_mode`).
 *   Report-only never blocks a legitimate page, so it can be enabled on a live
 *   store first; violations are written to the application log by
 *   api/csp-report.php. Switch to `enforce` once the log is quiet.
 * - Inline <script> blocks carry a per-request nonce, and inline event handlers
 *   were replaced with data attributes handled by assets/js/app.js, so script
 *   execution can be restricted to our own files plus that nonce.
 * - Inline `style="…"` attributes are used throughout the existing templates
 *   (hundreds of them), so `style-src 'unsafe-inline'` remains. Styles are a
 *   far smaller risk than scripts and rewriting every template would be a pure
 *   redesign, which is out of scope.
 */

/** Per-request CSP nonce. Never reused across requests. */
function sh_csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
    return $nonce;
}

/** `nonce="…"` attribute for an inline <script> tag. */
function sh_csp_nonce_attr(): string
{
    return ' nonce="' . htmlspecialchars(sh_csp_nonce(), ENT_QUOTES, 'UTF-8') . '"';
}

/**
 * Read a security setting without ever failing on a missing database or a
 * store that has not been installed yet.
 */
function sh_security_setting(string $key, string $default): string
{
    try {
        if (!function_exists('sh_setting')) { return $default; }
        $v = sh_setting($key, $default);
        return is_string($v) && $v !== '' ? $v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * The CSP policy for this request.
 *
 * Kept as a pure function of the settings so it can be asserted by tests
 * without a database.
 */
function sh_csp_policy(): string
{
    $imgExtra = "'self' data: https:";
    $directives = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "script-src 'self' 'nonce-" . sh_csp_nonce() . "'",
        "style-src 'self' 'unsafe-inline'",
        "img-src $imgExtra",
        "font-src 'self' data:",
        "media-src 'self'",
        "connect-src 'self'",
        // Forms only ever post back to this application. Payment gateways are
        // reached by a server-issued redirect, which form-action does not govern.
        "form-action 'self'",
        "frame-ancestors " . sh_frame_ancestors(),
        "report-uri " . sh_csp_report_url(),
    ];
    return implode('; ', $directives);
}

/** Anti-clickjacking ancestor list, configurable for owners who embed the store. */
function sh_frame_ancestors(): string
{
    $raw = sh_security_setting('security_frame_ancestors', 'self');
    if ($raw === '' || $raw === "'none'") { return "'none'"; }
    // Accept only origin-ish tokens; anything else falls back to the safe default.
    $parts = preg_split('/[\s,]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === 'self' || $p === "'self'") { $out[] = "'self'"; continue; }
        if (preg_match('~^https?://[a-z0-9._:-]+$~i', $p) === 1) { $out[] = $p; }
    }
    $out = array_values(array_unique($out));
    return $out === [] ? "'self'" : implode(' ', $out);
}

function sh_csp_report_url(): string
{
    // A relative path is resolved by the browser against the document, but CSP
    // requires a URL; sh_url() supplies the correct sub-folder aware value.
    $path = function_exists('sh_url') ? sh_url('api/csp-report.php') : '/api/csp-report.php';
    return $path !== '' ? $path : '/api/csp-report.php';
}

/**
 * Every security header for this request.
 *
 * Returned as a list rather than emitted inline so the policy can be inspected
 * by the automated regression tests; sh_security_headers_apply() sends it.
 *
 * @return array<int, array{0:string,1:string}>
 */
function sh_security_header_list(): array
{
    $headers = [
        ['X-Content-Type-Options', 'nosniff'],
        ['Referrer-Policy', 'strict-origin-when-cross-origin'],
        ['X-Frame-Options', sh_frame_ancestors() === "'none'" ? 'DENY' : 'SAMEORIGIN'],
        // The store never needs these device permissions.
        ['Permissions-Policy', 'geolocation=(), microphone=(), camera=(), usb=(), magnetometer=(), gyroscope=()'],
        ['Cross-Origin-Opener-Policy', 'same-origin'],
        // Cache-Control for authenticated pages is set by the pages themselves;
        // these two suppress MIME-based feature detection in old browsers.
        ['X-Permitted-Cross-Domain-Policies', 'none'],
    ];

    $mode = strtolower(sh_security_setting('security_csp_mode', 'report-only'));
    if ($mode === 'enforce') {
        $headers[] = ['Content-Security-Policy', sh_csp_policy()];
    } elseif ($mode === 'report-only') {
        $headers[] = ['Content-Security-Policy-Report-Only', sh_csp_policy()];
    }
    // 'off' emits no CSP at all (used only for a documented troubleshooting step).

    // HSTS only over HTTPS, and only when the owner has not disabled it. Without
    // includeSubDomains/preload a mistake here cannot lock other hostnames out.
    if (sh_request_is_https() && sh_security_setting('security_hsts', 'on') === 'on') {
        $headers[] = ['Strict-Transport-Security', 'max-age=15552000'];
    }

    return $headers;
}

/**
 * File-backed request limiter.
 *
 * Used for endpoints that must stay rate limited even when the database is
 * unavailable (CSP reports, installer unlock attempts). Returns true when the
 * caller is still within $max events per $window seconds.
 *
 * The counter file is written with LOCK_EX and lives under logs/, which is
 * denied to the web by its own .htaccess. Failure to open the file fails OPEN
 * deliberately: a broken log directory must not lock the owner out of repair.
 */
function sh_file_rate_limit(string $bucket, string $key, int $max, int $window, string $dir = ''): bool
{
    $bucket = preg_replace('/[^a-z0-9_.-]+/i', '-', $bucket) ?: 'general';
    $dir = $dir !== '' ? rtrim($dir, '/') : (defined('SH_LOG_DIR') ? SH_LOG_DIR . '/limits' : '');
    if ($dir === '') { return true; }
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) { return true; }

    $file = $dir . '/' . $bucket . '-' . hash('sha256', $key) . '.txt';
    $now = time();
    $fh = @fopen($file, 'c+');
    if ($fh === false) { return true; }
    try {
        if (!flock($fh, LOCK_EX)) { return true; }
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data) || !isset($data['t'], $data['n']) || ($now - (int)$data['t']) > $window) {
            $data = ['t' => $now, 'n' => 0];
        }
        $data['n'] = (int)$data['n'] + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)json_encode($data));
        fflush($fh);
        $allowed = $data['n'] <= $max;
        flock($fh, LOCK_UN);
        return $allowed;
    } catch (Throwable $e) {
        return true;
    } finally {
        fclose($fh);
    }
}

/**
 * Send the headers above. Safe to call more than once per request and safe when
 * output has already started (for example from an error page).
 */
function sh_security_headers_apply(): void
{
    static $applied = false;
    if ($applied || headers_sent()) { return; }
    $applied = true;
    foreach (sh_security_header_list() as [$name, $value]) {
        header($name . ': ' . $value);
    }
    // Do not advertise the PHP version.
    header_remove('X-Powered-By');
}
