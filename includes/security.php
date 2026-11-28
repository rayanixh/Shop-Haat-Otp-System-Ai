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
    // blob: is needed for the admin's "preview the image I just picked" path:
    // a file the browser holds locally, before any upload has happened. It is
    // still same-origin content, never a remote fetch.
    $imgExtra = "'self' blob: data: https:";
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
 * denied to the web by its own .htaccess.
 *
 * What happens when the counter cannot be written (read-only or full disk, a
 * broken logs/ directory, an unwritable NFS mount) is a per-endpoint decision,
 * not a global one:
 *
 *   $failClosed = false (default) — flood protection (CSP report collector,
 *   email webhook). Telemetry must never be dropped because a counter file
 *   could not be opened.
 *
 *   $failClosed = true — brute-force protection of a secret (installer unlock
 *   token, backup cron token). If the limiter cannot count, the request is
 *   refused rather than letting an attacker hammer the secret at unlimited
 *   rate by breaking the counter store first. This cannot lock the owner out:
 *   these endpoints only consult the limiter for a *wrong* secret, so the
 *   correct token always works.
 *
 * Every refusal is logged so the operator can see why the endpoint went quiet.
 */
/** Create or read the persistent bounded-cleanup queue state. Caller holds its lock. */
function sh_rate_limit_cleanup_state(string $queueDir): ?array
{
    $path = $queueDir . '/state.json';
    if (!is_file($path)) { return ['head' => 1, 'tail' => 1]; }
    if (is_link($path)) { return null; }
    $raw = @file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || !isset($state['head'], $state['tail'])
        || !is_numeric($state['head']) || !is_numeric($state['tail'])) { return null; }
    $head = (int)$state['head']; $tail = (int)$state['tail'];
    if ($head < 1 || $tail < $head || $tail > PHP_INT_MAX - 1024) { return null; }
    return ['head' => $head, 'tail' => $tail];
}

/** Atomically persist a small state file. Caller holds the queue lock. */
function sh_rate_limit_cleanup_state_write(string $queueDir, array $state): bool
{
    $temp = $queueDir . '/.state.tmp';
    if (is_link($temp)) { return false; }
    $fh = @fopen($temp, 'c+');
    if ($fh === false) { return false; }
    $encoded = json_encode(['head' => (int)$state['head'], 'tail' => (int)$state['tail']]);
    try {
        if (!is_string($encoded) || !@chmod($temp, 0600) || !ftruncate($fh, 0) || !rewind($fh)) { return false; }
        $offset = 0; $length = strlen($encoded);
        while ($offset < $length) {
            $written = fwrite($fh, substr($encoded, $offset));
            if ($written === false || $written === 0) { return false; }
            $offset += $written;
        }
        if (!fflush($fh)) { return false; }
    } finally { fclose($fh); }
    return @rename($temp, $queueDir . '/state.json');
}

/** Enqueue one opaque counter filename while caller holds the persistent queue lock. */
function sh_rate_limit_cleanup_enqueue_locked(string $queueDir, array &$state, string $entry): bool
{
    if (preg_match('/^[a-z0-9_.-]+-[a-f0-9]{64}\.txt$/i', $entry) !== 1 || $state['tail'] >= PHP_INT_MAX - 1024) { return false; }
    $sequence = (int)$state['tail'];
    $jobPath = $queueDir . '/job-' . sprintf('%020d', $sequence) . '.txt';
    $temp = $queueDir . '/.job.tmp';
    if (is_link($temp)) { return false; }
    $tempHandle = @fopen($temp, 'c+');
    if ($tempHandle === false) { return false; }
    $payload = $entry . "\n";
    $ok = false;
    try {
        if (!@chmod($temp, 0600) || !ftruncate($tempHandle, 0) || !rewind($tempHandle)) { return false; }
        $written = fwrite($tempHandle, $payload);
        if ($written !== strlen($payload) || !fflush($tempHandle)) { return false; }
        $ok = true;
    } finally { fclose($tempHandle); }
    if (!$ok || !@rename($temp, $jobPath)) { return false; }
    $state['tail'] = $sequence + 1;
    return sh_rate_limit_cleanup_state_write($queueDir, $state);
}

/** Register a counter once in the persistent queue. Must run after its shard lock is released. */
function sh_rate_limit_cleanup_enqueue(string $dir, string $entry): bool
{
    $queueDir = rtrim($dir, '/') . '/.cleanup-queue';
    $indexDir = $queueDir . '/index';
    $lockDir = rtrim($dir, '/') . '/.locks';
    if ((!is_dir($queueDir) && !@mkdir($queueDir, 0700)) || (!is_dir($indexDir) && !@mkdir($indexDir, 0700))) { return false; }
    if (is_link($queueDir) || is_link($indexDir) || !@chmod($queueDir, 0700) || !@chmod($indexDir, 0700)) { return false; }
    $queueMode = @fileperms($queueDir); $indexMode = @fileperms($indexDir);
    if ($queueMode === false || (($queueMode & 0700) !== 0700) || (($queueMode & 0077) !== 0)
        || $indexMode === false || (($indexMode & 0700) !== 0700) || (($indexMode & 0077) !== 0)) { return false; }
    $marker = $indexDir . '/' . hash('sha256', $entry) . '.idx';
    if (is_link($marker)) { return false; }
    if (is_file($marker)) { return true; }
    $queueLockPath = $lockDir . '/cleanup-queue.lck';
    if (is_link($queueLockPath)) { return false; }
    $queueLock = @fopen($queueLockPath, 'c+');
    if ($queueLock === false) { return false; }
    try {
        if (!@chmod($queueLockPath, 0600)) { return false; }
        $mode = @fileperms($queueLockPath);
        if ($mode === false || (($mode & 0600) !== 0600) || (($mode & 0077) !== 0) || !flock($queueLock, LOCK_EX)) { return false; }
        if (is_file($marker)) { return true; }
        $state = sh_rate_limit_cleanup_state($queueDir);
        if ($state === null || !sh_rate_limit_cleanup_enqueue_locked($queueDir, $state, $entry)) { return false; }
        $markerHandle = @fopen($marker, 'x');
        if ($markerHandle === false) { return is_file($marker); }
        try {
            if (!@chmod($marker, 0600) || fwrite($markerHandle, "1\n") !== 2 || !fflush($markerHandle)) { @unlink($marker); return false; }
        } finally { fclose($markerHandle); }
        return true;
    } finally { fclose($queueLock); }
}

/**
 * Process a bounded number of persistent cleanup-queue entries.
 *
 * Counter writers and cleanup share each stable bucket/hash-prefix lock. The
 * queue itself has one private lock and a durable head/tail cursor, so every
 * invocation resumes at the prior position without rescanning the directory.
 * No directory enumeration is used; each inspected job is addressed directly
 * by sequence number. Active counters are re-queued, expired ones are removed.
 *
 * @param int|null $inspected Optional test/diagnostic output: jobs examined.
 */
function sh_file_rate_limit_cleanup(string $dir, int $budget = 24, ?int &$inspected = null): int
{
    $inspected = 0;
    $budget = max(1, min(32, $budget));
    $dir = rtrim($dir, '/');
    $lockDir = $dir . '/.locks';
    $queueDir = $dir . '/.cleanup-queue';
    $indexDir = $queueDir . '/index';
    if (!is_dir($dir) || !is_dir($lockDir) || !is_dir($queueDir) || !is_dir($indexDir)
        || is_link($lockDir) || is_link($queueDir) || is_link($indexDir)) { return 0; }
    foreach ([$lockDir, $queueDir, $indexDir] as $privateDir) {
        $mode = @fileperms($privateDir);
        if ($mode === false || (($mode & 0700) !== 0700) || (($mode & 0077) !== 0)) { return 0; }
    }
    $queueLockPath = $lockDir . '/cleanup-queue.lck';
    if (is_link($queueLockPath)) { return 0; }
    $queueLock = @fopen($queueLockPath, 'c+');
    if ($queueLock === false) { return 0; }
    $removed = 0;
    $now = time();
    try {
        if (!@chmod($queueLockPath, 0600)) { return 0; }
        $lockMode = @fileperms($queueLockPath);
        if ($lockMode === false || (($lockMode & 0600) !== 0600) || (($lockMode & 0077) !== 0)
            || !flock($queueLock, LOCK_EX | LOCK_NB)) { return 0; }
        $state = sh_rate_limit_cleanup_state($queueDir);
        if ($state === null) { return 0; }
        // Re-queued active counters are deferred to the next invocation so one
        // hot active entry cannot consume the whole budget repeatedly.
        $availableAtStart = min($budget, (int)$state['tail'] - (int)$state['head']);

        while ($inspected < $availableAtStart && $state['head'] < $state['tail']) {
            $sequence = (int)$state['head'];
            $jobPath = $queueDir . '/job-' . sprintf('%020d', $sequence) . '.txt';
            $inspected++;
            $rawEntry = !is_link($jobPath) && is_file($jobPath) ? @file_get_contents($jobPath) : false;
            $entry = is_string($rawEntry) ? trim($rawEntry) : '';
            if (preg_match('/^([a-z0-9_.-]+)-([a-f0-9]{64})\.txt$/i', $entry, $m) !== 1) {
                @unlink($jobPath);
                $state['head'] = $sequence + 1;
                if (!sh_rate_limit_cleanup_state_write($queueDir, $state)) { break; }
                continue;
            }
            $bucket = (string)$m[1]; $keyHash = strtolower((string)$m[2]);
            $counterPath = $dir . '/' . $entry;
            $marker = $indexDir . '/' . hash('sha256', $entry) . '.idx';
            $shardLockPath = $lockDir . '/' . $bucket . '-' . substr($keyHash, 0, 2) . '.lck';
            if (is_link($shardLockPath)) { break; }
            $shardLock = @fopen($shardLockPath, 'c+');
            if ($shardLock === false) { break; }
            $active = false; $counterMissing = false; $counterExpired = false;
            try {
                if (!@chmod($shardLockPath, 0600)) { break; }
                $mode = @fileperms($shardLockPath);
                if ($mode === false || (($mode & 0600) !== 0600) || (($mode & 0077) !== 0)
                    || !flock($shardLock, LOCK_EX | LOCK_NB)) { break; }
                if (is_link($counterPath) || !is_file($counterPath)) {
                    $counterMissing = true;
                } else {
                    $counter = @fopen($counterPath, 'r');
                    if ($counter === false) { break; }
                    try { $raw = stream_get_contents($counter); }
                    finally { fclose($counter); }
                    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                    $valid = is_array($data) && isset($data['t'], $data['n']) && is_numeric($data['t']) && is_numeric($data['n']);
                    if (!$valid) {
                        $mtime = @filemtime($counterPath);
                        $active = $mtime === false || ($now - $mtime) <= 3600;
                    } else {
                        $legacyWindow = match ($bucket) {
                            'public-order-track-ip' => 600,
                            'public-order-track-target' => 3600,
                            'public-order-track-pair' => 900,
                            'csp', 'email_webhook' => 60,
                            'install-unlock', 'backup-cron-token' => 900,
                            default => 3600,
                        };
                        $window = isset($data['w']) && is_numeric($data['w']) ? max(1, (int)$data['w']) : $legacyWindow;
                        $active = ($now - (int)$data['t']) <= $window;
                    }
                    if (!$active) {
                        $counterExpired = @unlink($counterPath);
                        if (!$counterExpired) { break; }
                        $removed++;
                    }
                }
                if ($counterMissing || $counterExpired) { @unlink($marker); }
            } finally { fclose($shardLock); }

            if ($active && !sh_rate_limit_cleanup_enqueue_locked($queueDir, $state, $entry)) { break; }
            @unlink($jobPath);
            $state['head'] = $sequence + 1;
            if (!sh_rate_limit_cleanup_state_write($queueDir, $state)) { break; }
        }
    } catch (Throwable $e) {
        // Retention is maintenance only. The counter operation below continues
        // to enforce the endpoint's configured fail-open/fail-closed behavior.
        return $removed;
    } finally { fclose($queueLock); }
    return $removed;
}

/**
 * File-backed rate limiter used by endpoints that must remain throttled even
 * when the database is unavailable. Returns true when the caller is within
 * $max events per $window seconds.
 *
 * Counter filenames contain only a fixed bucket label and a SHA-256 key hash;
 * raw IPs, phones and order references are never stored in filenames or logs.
 * A finite per-bucket set of stable shard locks serializes updates and cleanup.
 */
function sh_file_rate_limit(string $bucket, string $key, int $max, int $window, string $dir = '', bool $failClosed = false): bool
{
    $fallback = !$failClosed;
    $refused = static function (string $why) use ($bucket, $failClosed, $fallback): bool {
        if ($failClosed && function_exists('sh_log_line')) {
            sh_log_line('security', 'Rate limiter for "' . $bucket . '" is unavailable (' . $why . '); denying the request.');
        }
        return $fallback;
    };

    $bucket = preg_replace('/[^a-z0-9_.-]+/i', '-', $bucket) ?: 'general';
    $dir = $dir !== '' ? rtrim($dir, '/') : (defined('SH_LOG_DIR') ? SH_LOG_DIR . '/limits' : '');
    if ($dir === '') { return $refused('no storage directory'); }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return $refused('cannot create the storage directory'); }
    if (!@chmod($dir, 0700)) { return $refused('cannot restrict the storage directory permissions'); }
    $dirMode = @fileperms($dir);
    if ($dirMode === false || (($dirMode & 0700) !== 0700) || (($dirMode & 0077) !== 0) || !is_writable($dir)) {
        return $refused('storage directory permissions are not private and writable');
    }

    $lockDir = $dir . '/.locks';
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0700) && !is_dir($lockDir)) { return $refused('cannot create lock storage'); }
    if (!@chmod($lockDir, 0700)) { return $refused('cannot restrict lock storage permissions'); }
    $lockDirMode = @fileperms($lockDir);
    if ($lockDirMode === false || (($lockDirMode & 0700) !== 0700) || (($lockDirMode & 0077) !== 0) || !is_writable($lockDir)) {
        return $refused('lock storage permissions are not private and writable');
    }

    // Bounded cleanup resumes from a persistent sequence cursor. It inspects no
    // more than 24 jobs by default (hard cap 32), never walks a counter folder.
    sh_file_rate_limit_cleanup($dir, 24);

    $keyHash = hash('sha256', $key);
    $file = $dir . '/' . $bucket . '-' . $keyHash . '.txt';
    $lockPath = $lockDir . '/' . $bucket . '-' . substr($keyHash, 0, 2) . '.lck';
    if (is_link($lockPath)) { return $refused('invalid lock path'); }
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) { return $refused('cannot open the rate-limit lock'); }
    $updated = false; $allowed = false;
    try {
        if (!@chmod($lockPath, 0600)) { return $refused('cannot restrict lock file permissions'); }
        $lockMode = @fileperms($lockPath);
        if ($lockMode === false || (($lockMode & 0600) !== 0600) || (($lockMode & 0077) !== 0)) {
            return $refused('rate-limit lock permissions are not private');
        }
        if (!flock($lock, LOCK_EX)) { return $refused('cannot lock the counter shard'); }
        if (is_link($file)) { return $refused('invalid counter path'); }
        $fh = @fopen($file, 'c+');
        if ($fh === false) { return $refused('cannot open the counter file'); }
        try {
            if (!@chmod($file, 0600)) { return $refused('cannot restrict the counter file permissions'); }
            $fileMode = @fileperms($file);
            if ($fileMode === false || (($fileMode & 0600) !== 0600) || (($fileMode & 0077) !== 0)) {
                return $refused('counter file permissions are not private');
            }
            if (!rewind($fh)) { return $refused('cannot read the counter file'); }
            $raw = stream_get_contents($fh);
            if (!is_string($raw)) { return $refused('cannot read the counter file'); }
            $data = json_decode($raw, true);
            if ($raw === '') {
                $data = ['t' => time(), 'n' => 0, 'w' => $window];
            } elseif (!is_array($data) || !isset($data['t'], $data['n']) || !is_numeric($data['t']) || !is_numeric($data['n'])) {
                $data = ['t' => time(), 'n' => 0, 'w' => $window];
            } elseif ((time() - (int)$data['t']) > $window) {
                $data = ['t' => time(), 'n' => 0, 'w' => $window];
            }
            $data['w'] = $window;
            $data['n'] = (int)$data['n'] + 1;
            $encoded = json_encode($data);
            if (!is_string($encoded) || !ftruncate($fh, 0) || !rewind($fh)) {
                return $refused('cannot prepare the counter update');
            }
            $offset = 0; $length = strlen($encoded);
            while ($offset < $length) {
                $written = fwrite($fh, substr($encoded, $offset));
                if ($written === false || $written === 0) { return $refused('cannot update the counter file'); }
                $offset += $written;
            }
            if (!fflush($fh)) { return $refused('cannot flush the counter update'); }
            $allowed = $data['n'] <= $max;
            $updated = true;
        } finally { fclose($fh); }
    } catch (Throwable $e) {
        return $refused('counter error: ' . $e->getMessage());
    } finally { fclose($lock); }

    // Queue registration happens only after releasing the shard lock, preserving
    // a single lock order with cleanup (queue lock then shard lock).
    if (!$updated) { return $refused('counter update did not complete'); }
    if (!sh_rate_limit_cleanup_enqueue($dir, basename($file))) {
        return $refused('cannot register counter for bounded cleanup');
    }
    return $allowed;
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
