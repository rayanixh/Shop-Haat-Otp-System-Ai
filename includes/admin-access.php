<?php
/**
 * Custom admin URL routing.
 *
 * The URL is only a routing layer; every admin route still enforces the
 * ordinary admin session, role permission, CSRF and rate-limit checks. The
 * pending route is not activated until the dispatcher has seen it in the
 * same authenticated session and an owner explicitly applies it.
 */
declare(strict_types=1);

const SH_ADMIN_ACCESS_ENABLED_KEY = 'admin_access_enabled';
const SH_ADMIN_ACCESS_ACTIVE_KEY = 'admin_access_secret_path';
const SH_ADMIN_ACCESS_PENDING_KEY = 'admin_access_pending_secret';
const SH_ADMIN_ACCESS_VERIFIED_KEY = 'admin_access_verified_secret';
const SH_ADMIN_ACCESS_VERIFIED_AT_KEY = 'admin_access_verified_at';
const SH_ADMIN_ACCESS_VERIFIED_BY_KEY = 'admin_access_verified_by';
const SH_ADMIN_ACCESS_VERIFY_SESSION_KEY = 'sh_admin_access_pending_verification';

/** Accept exactly one normalized, URL-safe path segment with high entropy. */
function sh_admin_access_normalize_path($value): ?string
{
    if (!is_string($value)) { return null; }
    $path = trim($value);
    if ($path === '' || strlen($path) > 130) { return null; }
    if (str_starts_with($path, '/')) { $path = substr($path, 1); }
    if (str_ends_with($path, '/')) { $path = substr($path, 0, -1); }
    if ($path === '' || str_contains($path, '/') || str_contains($path, '\\')
        || str_contains($path, '%') || str_contains($path, '?') || str_contains($path, '#')
        || str_contains($path, ':') || str_contains($path, '.') || preg_match('/\s/u', $path)) {
        return null;
    }
    $path = strtolower($path);
    if (strlen($path) < 32 || strlen($path) > 64
        || preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/D', $path) !== 1) {
        return null;
    }
    // Never let a custom secret shadow a conventional route or a familiar admin alias.
    $reserved = [
        'admin', 'adminphp', 'admin-login', 'admin-login-php', 'login', 'logout',
        'api', 'assets', 'uploads', 'install', 'support', 'cart', 'checkout',
        'account', 'orders', 'products', 'product', 'category', 'categories',
        'robots', 'sitemap', 'health', 'status', 'security', 'cron', 'config',
        'includes', 'lib', 'logs', 'tests', 'tools', 'docs', 'auth', 'installer',
        'index', 'track', 'help', 'page', 'payment', 'password', 'otp', 'register',
        'verify-email', 'forgot-password', 'reset-password', 'telegram-webhook',
    ];
    if (in_array($path, $reserved, true)) { return null; }
    // Reject routes that share a prefix with a known application/endpoint
    // namespace even when a long random suffix is attached. This keeps the
    // custom secret away from customer routes, API routes, static assets and
    // the SEO/robots rewrite rules in the root .htaccess.
    foreach (['admin-', 'api-', 'assets-', 'uploads-', 'install-', 'config-',
              'includes-', 'sitemap-', 'robots-', 'cron-', 'auth-', 'lib-'] as $prefix) {
        if (str_starts_with($path, $prefix)) { return null; }
    }
    return $path;
}

/**
 * Timezone-independent timestamp helpers for the one-hour verification marker.
 *
 * Root cause of the deployed activation failure: the marker used to be WRITTEN
 * with gmdate() (UTC wall-clock text) but READ with strtotime(), which parses
 * that text in PHP's local timezone. On a UTC+N server (for example
 * Asia/Dhaka, UTC+6) the marker looked N hours old, so the one-hour freshness
 * check rejected it and activation always failed. On a UTC server both agree,
 * which is why the CI/SQLite suites passed while cPanel failed.
 *
 * New values are plain Unix timestamps (timezone-independent). Legacy datetime
 * strings are still accepted by forcing UTC interpretation.
 */
function sh_admin_access_verified_at_parse(string $raw): int|false
{
    $raw = trim($raw);
    if ($raw === '') { return false; }
    if (preg_match('/^[0-9]{9,12}$/D', $raw) === 1) { return (int)$raw; }
    $raw = preg_replace('/\s+UTC$/i', '', $raw) ?? $raw;
    $time = strtotime($raw . ' UTC');
    return $time === false ? false : $time;
}

function sh_admin_access_verified_at_write(): string
{
    return (string)time();
}

/** One source of truth for the route state; never sent to storefront responses. */
function sh_admin_access_state(): array
{
    $active = sh_admin_access_normalize_path((string)sh_setting(SH_ADMIN_ACCESS_ACTIVE_KEY, '')) ?? '';
    $pending = sh_admin_access_normalize_path((string)sh_setting(SH_ADMIN_ACCESS_PENDING_KEY, '')) ?? '';
    $verified = sh_admin_access_normalize_path((string)sh_setting(SH_ADMIN_ACCESS_VERIFIED_KEY, '')) ?? '';
    $enabled = (string)sh_setting(SH_ADMIN_ACCESS_ENABLED_KEY, '0') === '1' && $active !== '';
    $verifiedAt = (string)sh_setting(SH_ADMIN_ACCESS_VERIFIED_AT_KEY, '');
    $verifiedTime = sh_admin_access_verified_at_parse($verifiedAt);
    $verifiedFresh = $pending !== '' && $verified !== '' && hash_equals($pending, $verified)
        && $verifiedTime !== false && $verifiedTime >= time() - 3600;
    return [
        'enabled' => $enabled,
        'active_path' => $active,
        'pending_path' => $pending,
        'verified' => $verifiedFresh,
        'verified_at' => $verifiedAt,
        'verified_by' => (int)sh_setting(SH_ADMIN_ACCESS_VERIFIED_BY_KEY, '0'),
        'status' => $enabled ? ($pending !== '' ? ($verifiedFresh ? 'enabled_with_verified_change' : 'enabled_with_pending_change') : 'enabled')
            : ($pending !== '' ? ($verifiedFresh ? 'verified_pending_apply' : 'pending_verification') : 'disabled'),
    ];
}

function sh_admin_access_enabled(): bool
{
    $state = sh_admin_access_state();
    return $state['enabled'] && $state['active_path'] !== '';
}

function sh_admin_access_current_path(): string
{
    $state = sh_admin_access_state();
    return $state['enabled'] ? $state['active_path'] : '';
}

/** Generate 192 bits of entropy, hex-encoded to an Apache-safe path segment. */
function sh_admin_access_suggest(): string
{
    return bin2hex(random_bytes(24));
}

/**
 * Check a candidate against filesystem and known dynamic slugs, in addition to
 * strict syntax validation. Any lookup error rejects the candidate safely.
 */
function sh_admin_access_validate_path(string $input): array
{
    $path = sh_admin_access_normalize_path($input);
    if ($path === null) { return ['ok' => false, 'path' => '', 'error' => 'Use one lowercase URL-safe path segment containing 32–64 letters, numbers, hyphens or underscores. Do not include a domain, query, fragment, encoding or extra path segments.']; }

    $root = realpath(SH_ROOT);
    if ($root === false) { return ['ok' => false, 'path' => '', 'error' => 'The application root could not be checked.']; }
    $candidate = $root . DIRECTORY_SEPARATOR . $path;
    if (file_exists($candidate) || is_link($candidate)) {
        return ['ok' => false, 'path' => '', 'error' => 'That path already exists in the application. Choose a different route.'];
    }
    try {
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) { continue; }
            if (strtolower($entry->getFilename()) === $path) {
                return ['ok' => false, 'path' => '', 'error' => 'That path collides with an existing application file or directory.'];
            }
        }
        foreach (['products', 'categories', 'brands', 'blog_posts', 'pages', 'custom_pages'] as $table) {
            if (!sh_table_exists($table)) { continue; }
            $hasSlug = (int)sh_val(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'slug'], 0
            ) > 0;
            if (!$hasSlug) { continue; }
            $hit = sh_one('SELECT 1 AS hit FROM `' . str_replace('`', '', $table) . '` WHERE LOWER(slug) = ? LIMIT 1', [$path]);
            if ($hit !== null) {
                return ['ok' => false, 'path' => '', 'error' => 'That path collides with an existing storefront slug. Choose a different route.'];
            }
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'admin-access-collision-check');
        return ['ok' => false, 'path' => '', 'error' => 'The path could not be checked for collisions. Please try again.'];
    }
    return ['ok' => true, 'path' => $path, 'error' => ''];
}

/** Atomically update key/value settings without writing the secret into audit values. */
function sh_admin_access_write(array $values): bool
{
    $allowed = [
        SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY, SH_ADMIN_ACCESS_PENDING_KEY,
        SH_ADMIN_ACCESS_VERIFIED_KEY, SH_ADMIN_ACCESS_VERIFIED_AT_KEY, SH_ADMIN_ACCESS_VERIFIED_BY_KEY,
    ];
    foreach ($values as $key => $value) {
        if (!in_array((string)$key, $allowed, true) || !is_scalar($value)) { return false; }
    }
    $pdo = null;
    try {
        $pdo = sh_db();
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        $st = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach ($values as $key => $value) { $st->execute([(string)$key, (string)$value]); }
        if ($started) { $pdo->commit(); }
        sh_settings(true);
        return true;
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'admin-access-settings-write');
        return false;
    }
}

function sh_admin_access_save_pending(string $path): bool
{
    $pdo = null;
    try {
        $pdo = sh_db();
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?) FOR UPDATE');
        $st->execute([SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY]);
        $current = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $current[(string)$row['setting_key']] = (string)$row['setting_value']; }
        $active = sh_admin_access_normalize_path($current[SH_ADMIN_ACCESS_ACTIVE_KEY] ?? '') ?? '';
        $enabled = ($current[SH_ADMIN_ACCESS_ENABLED_KEY] ?? '0') === '1';
        if ($enabled && $active !== '' && hash_equals($active, $path)) { $pdo->rollBack(); return false; }
        if (!sh_admin_access_write([
            SH_ADMIN_ACCESS_PENDING_KEY => $path,
            SH_ADMIN_ACCESS_VERIFIED_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_AT_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_BY_KEY => '0',
        ])) { $pdo->rollBack(); return false; }
        $pdo->commit();
        sh_settings(true);
        unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
        return true;
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'admin-access-pending-save');
        return false;
    }
}

/**
 * Human-readable, non-technical explanation for an activation result. Detailed
 * causes go to the admin-only error log, never to the browser (and never with
 * route secrets, tokens, credentials or raw SQL).
 */
function sh_admin_access_activation_message(string $reason): string
{
    return match ($reason) {
        'activated' => 'New Admin URL is active. The old Admin URL is now disabled.',
        'route_not_recognized' => 'This page was not opened through the proposed Admin URL. Open the full proposed URL in this browser, then activate it.',
        'admin_session_missing' => 'Your admin session was not recognised. Sign in again as a superadmin and repeat the verification.',
        'verification_ticket_missing' => 'This verification link has expired or was opened in a different browser session. Save the proposed URL again and reopen it.',
        'verification_ticket_mismatch' => 'This verification ticket does not match the proposed Admin URL. Save the proposed URL again and reopen it.',
        'no_pending_route' => 'There is no staged Admin URL waiting for activation. Save a new proposed URL first.',
        'route_mismatch' => 'The staged Admin URL changed. Return to Admin Access, save the route again and reopen the full URL.',
        'already_active' => 'This Admin URL is already active; no change was needed.',
        'path_validation_failed' => 'The proposed Admin URL is not a valid, available one-segment path. Save a different URL.',
        'database_readback_failed' => 'The database did not confirm the new Admin URL, so the old URL was kept. Check the admin error log and database permissions.',
        'post_commit_verification_failed' => 'Activation could not be confirmed after saving, so the previous Admin URL was restored. Check the admin error log.',
        'database_error' => 'The database rejected the activation and the old Admin URL was left unchanged. Check the admin error log and MySQL/MariaDB permissions.',
        default => 'New Admin URL could not be activated. Your current Admin URL is unchanged. Check the admin error log for details.',
    };
}

/**
 * Atomically activate the staged Admin URL.
 *
 * Replaces the old two-transaction "record verification, then apply" sequence:
 * that split could leave the verification marker in place while activation
 * failed for an unrelated reason, with no useful diagnostics. This function
 * keeps ALL writes in one transaction, verifies them by reading back from the
 * database, and only then reports success. On any failure the transaction is
 * rolled back (old route untouched); if the post-commit settings read does not
 * confirm the switch, the exact previous values are restored (compensating
 * rollback) so the old route keeps working.
 *
 * $requestedPath MUST be the real route segment the request arrived on, taken
 * from sh_admin_access_request_path() by the dispatcher. The DB pending value
 * and the deployed request path must agree, so a success message can never be
 * shown for a URL that is not actually serving admin pages.
 *
 * @return array{ok:bool,reason:string,path:string}
 */
function sh_admin_access_activate_verified(string $requestedPath, ?int $adminId = null): array
{
    $requestedPath = sh_admin_access_normalize_path($requestedPath) ?? '';
    $adminId = (int)($adminId ?? 0);
    $fingerprint = $requestedPath !== '' ? substr(hash('sha256', $requestedPath), 0, 12) : 'none';

    $fail = static function (string $reason, string $detail = '') use ($fingerprint): array {
        sh_log_line('security', 'Admin URL activation refused: ' . $reason
            . ($detail !== '' ? ' (' . $detail . ')' : '')
            . ' fingerprint=' . $fingerprint . '. The previously active route was left unchanged.');
        return ['ok' => false, 'reason' => $reason, 'path' => ''];
    };

    if ($requestedPath === '') { return $fail('route_not_recognized', 'request path did not resolve to a normalised route segment'); }
    if ($adminId <= 0) { return $fail('admin_session_missing'); }

    $ticket = $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] ?? '';
    if (!is_string($ticket) || $ticket === '') { return $fail('verification_ticket_missing'); }
    if (!hash_equals(hash('sha256', $requestedPath), $ticket)) { return $fail('verification_ticket_mismatch'); }

    $keys = [
        SH_ADMIN_ACCESS_ENABLED_KEY,
        SH_ADMIN_ACCESS_ACTIVE_KEY,
        SH_ADMIN_ACCESS_PENDING_KEY,
        SH_ADMIN_ACCESS_VERIFIED_KEY,
        SH_ADMIN_ACCESS_VERIFIED_AT_KEY,
        SH_ADMIN_ACCESS_VERIFIED_BY_KEY,
    ];

    $pdo = null;
    $previous = [];
    try {
        $pdo = sh_db();
        $pdo->beginTransaction();
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $read = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($marks) FOR UPDATE");
        $read->execute($keys);
        $current = [];
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        foreach ($keys as $key) { $previous[$key] = $current[$key] ?? ''; }

        $pending = sh_admin_access_normalize_path($previous[SH_ADMIN_ACCESS_PENDING_KEY]) ?? '';
        $active = sh_admin_access_normalize_path($previous[SH_ADMIN_ACCESS_ACTIVE_KEY]) ?? '';
        $enabled = $previous[SH_ADMIN_ACCESS_ENABLED_KEY] === '1';

        if ($pending === '') { $pdo->rollBack(); return $fail('no_pending_route'); }
        if (!hash_equals($pending, $requestedPath)) { $pdo->rollBack(); return $fail('route_mismatch', 'staged and requested route differ'); }
        if ($enabled && $active !== '' && hash_equals($active, $pending)) { $pdo->rollBack(); return $fail('already_active'); }

        // Full filesystem/storefront-slug collision validation already ran when
        // the route was staged (sh_admin_access_save_pending). Activation repeats
        // only the cheap syntax check, so a product created in the meantime can
        // never leave the store with no working admin route.
        $syntax = sh_admin_access_normalize_path($pending);
        if ($syntax === null || !hash_equals($syntax, $pending)) {
            $pdo->rollBack();
            return $fail('path_validation_failed');
        }

        // One statement per key, all inside the SAME transaction. No helper that
        // could start a nested transaction or roll back the caller's work.
        $write = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
        $next = [
            SH_ADMIN_ACCESS_ENABLED_KEY => '1',
            SH_ADMIN_ACCESS_ACTIVE_KEY => $pending,
            SH_ADMIN_ACCESS_PENDING_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_AT_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_BY_KEY => '0',
        ];
        foreach ($next as $key => $value) { $write->execute([$key, $value]); }

        // Read the values back from inside the transaction before committing.
        $verify = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)');
        $verify->execute([SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY]);
        $confirmed = [];
        foreach ($verify->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $confirmed[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        if (($confirmed[SH_ADMIN_ACCESS_ENABLED_KEY] ?? '') !== '1'
            || !hash_equals((string)($confirmed[SH_ADMIN_ACCESS_ACTIVE_KEY] ?? ''), $pending)) {
            $pdo->rollBack();
            return $fail('database_readback_failed');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $ignored) { /* leave old route intact */ }
        }
        sh_log_exception($e, 'admin-access-activation');
        return $fail('database_error', get_class($e));
    }

    // Post-commit confirmation from the settings cache actually used by every
    // admin request. If it does not agree, restore the exact previous values.
    sh_settings(true);
    $state = sh_admin_access_state();
    if (!$state['enabled'] || $state['active_path'] === ''
        || !hash_equals($state['active_path'], $requestedPath)
        || $state['pending_path'] !== '') {
        $restored = sh_admin_access_write($previous);
        sh_settings(true);
        return $fail('post_commit_verification_failed', $restored ? 'previous values restored' : 'RESTORE FAILED');
    }

    unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
    sh_log_line('security', 'Admin URL activation confirmed by database read-back; fingerprint=' . $fingerprint . '.');
    return ['ok' => true, 'reason' => 'activated', 'path' => $pending];
}

/** Start a CSRF-protected same-session visit to the pending route. */
function sh_admin_access_begin_verification(): bool
{
    $state = sh_admin_access_state();
    if ($state['pending_path'] === '') { return false; }
    sh_session_start();
    $_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY] = hash('sha256', $state['pending_path']);
    return true;
}

function sh_admin_access_pending_is_verified(): bool
{
    return (bool)sh_admin_access_state()['verified'];
}

function sh_admin_access_apply_pending(): bool
{
    $pdo = null;
    try {
        $pdo = sh_db();
        $pdo->beginTransaction();
        $keys = [SH_ADMIN_ACCESS_ENABLED_KEY, SH_ADMIN_ACCESS_ACTIVE_KEY, SH_ADMIN_ACCESS_PENDING_KEY,
            SH_ADMIN_ACCESS_VERIFIED_KEY, SH_ADMIN_ACCESS_VERIFIED_AT_KEY];
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($marks) FOR UPDATE");
        $st->execute($keys);
        $current = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $current[(string)$row['setting_key']] = (string)$row['setting_value']; }
        $active = sh_admin_access_normalize_path($current[SH_ADMIN_ACCESS_ACTIVE_KEY] ?? '') ?? '';
        $pending = sh_admin_access_normalize_path($current[SH_ADMIN_ACCESS_PENDING_KEY] ?? '') ?? '';
        $verified = sh_admin_access_normalize_path($current[SH_ADMIN_ACCESS_VERIFIED_KEY] ?? '') ?? '';
        $verifiedAt = isset($current[SH_ADMIN_ACCESS_VERIFIED_AT_KEY])
            ? sh_admin_access_verified_at_parse((string)$current[SH_ADMIN_ACCESS_VERIFIED_AT_KEY]) : false;
        $enabled = ($current[SH_ADMIN_ACCESS_ENABLED_KEY] ?? '0') === '1';
        if ($pending === '' || $verified === '' || !hash_equals($pending, $verified)
            || $verifiedAt === false || $verifiedAt < time() - 3600
            || ($enabled && $active !== '' && hash_equals($active, $pending))) {
            $pdo->rollBack();
            return false;
        }
        $check = sh_admin_access_validate_path($pending);
        if (empty($check['ok']) || !hash_equals($pending, (string)$check['path'])) {
            $pdo->rollBack();
            return false;
        }
        if (!sh_admin_access_write([
            SH_ADMIN_ACCESS_ENABLED_KEY => '1',
            SH_ADMIN_ACCESS_ACTIVE_KEY => $pending,
            SH_ADMIN_ACCESS_PENDING_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_AT_KEY => '',
            SH_ADMIN_ACCESS_VERIFIED_BY_KEY => '0',
        ])) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        sh_settings(true);
        unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
        return true;
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'admin-access-apply');
        return false;
    }
}

function sh_admin_access_disable(): bool
{
    unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]);
    return sh_admin_access_write([
        SH_ADMIN_ACCESS_ENABLED_KEY => '0',
        SH_ADMIN_ACCESS_ACTIVE_KEY => '',
        SH_ADMIN_ACCESS_PENDING_KEY => '',
        SH_ADMIN_ACCESS_VERIFIED_KEY => '',
        SH_ADMIN_ACCESS_VERIFIED_AT_KEY => '',
        SH_ADMIN_ACCESS_VERIFIED_BY_KEY => '0',
    ]);
}

function sh_admin_access_record_verification(string $path, int $adminId): bool
{
    $state = sh_admin_access_state();
    if ($adminId <= 0 || $state['pending_path'] === '' || !hash_equals($state['pending_path'], $path)) { return false; }
    $check = sh_admin_access_validate_path($path);
    if (empty($check['ok']) || !hash_equals($check['path'], $path)) { return false; }
    return sh_admin_access_write([
        SH_ADMIN_ACCESS_VERIFIED_KEY => $path,
        SH_ADMIN_ACCESS_VERIFIED_AT_KEY => sh_admin_access_verified_at_write(),
        SH_ADMIN_ACCESS_VERIFIED_BY_KEY => (string)$adminId,
    ]);
}

/**
 * Request origin (scheme + validated Host header) without the install subfolder.
 * Only ever used while rendering an authenticated admin page, so the secret
 * never reaches storefront HTML/JS. A malformed Host falls back to localhost.
 */
function sh_admin_access_origin(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    // Whitelist a plain host / host:port (IPv6 brackets included). Anything
    // with whitespace, a slash, an @ or another delimiter is Host-header
    // injection material and must never become a copyable admin URL.
    if ($host === '' || strlen($host) > 253 || preg_match('/^[A-Za-z0-9.\[\]:_-]+$/D', $host) !== 1) {
        $host = 'localhost';
    }
    $scheme = function_exists('sh_request_is_https') && sh_request_is_https() ? 'https' : 'http';
    return $scheme . '://' . $host;
}

/**
 * Absolute, browser-ready Admin URL INCLUDING the domain and install subfolder.
 * sh_admin_access_route_url() already contains sh_base_url(), so only the
 * origin is prepended here — never the base path a second time.
 */
function sh_admin_access_full_url(string $secretPath, string $route = ''): string
{
    $normalized = sh_admin_access_normalize_path($secretPath) ?? '';
    if ($normalized === '') { return ''; }
    return sh_admin_access_origin() . sh_admin_access_route_url($normalized, $route);
}

/** Absolute URL of the ordinary /admin/ login route (used when the feature is off). */
function sh_admin_access_standard_url(): string
{
    // Deliberately bypass sh_url(): no secret-route mapping may leak into the
    // "standard route" label while an authenticated admin page is rendering.
    return sh_admin_access_origin() . rtrim(sh_base_url(), '/') . '/admin/login.php';
}

/** Relative URL used by the authenticated Admin Access page and dispatcher. */
function sh_admin_access_route_url(string $secretPath, string $route = ''): string
{
    $secretPath = sh_admin_access_normalize_path($secretPath) ?? '';
    if ($secretPath === '') { return sh_url('admin/login.php'); }
    $url = rtrim(sh_base_url(), '/') . '/' . $secretPath;
    $route = $route !== '' ? sh_admin_access_normalize_target($route) : null;
    if ($route !== null) { $url .= '?__admin_route=' . rawurlencode($route); }
    return $url;
}

/** Map trusted internal admin links only while rendering an authenticated admin route. */
function sh_admin_access_map_url(string $path): ?string
{
    if (!defined('SH_ADMIN_ROUTE_CONTEXT') || !sh_admin_access_enabled()) { return null; }
    $parts = parse_url($path);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
    $inner = trim((string)($parts['path'] ?? ''), '/');
    if ($inner === 'admin') { $inner = 'admin/dashboard.php'; }
    if (!str_starts_with($inner, 'admin/')) { return null; }
    $route = sh_admin_access_normalize_target(substr($inner, 6));
    if ($route === null) { return null; }
    $state = sh_admin_access_state();
    if (!$state['enabled'] || $state['active_path'] === '') { return null; }
    $url = rtrim(sh_base_url(), '/') . '/' . $state['active_path'] . '?__admin_route=' . rawurlencode($route);
    $query = (string)($parts['query'] ?? '');
    if ($query !== '') {
        $queryParts = array_values(array_filter(explode('&', $query), static fn($item) => $item !== '' && !preg_match('/^__admin_route(?:=|$)/', $item)));
        if ($queryParts !== []) { $url .= '&' . implode('&', $queryParts); }
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') { $url .= '#' . $parts['fragment']; }
    return $url;
}

/** Validate an internal route key before it is turned into an included file. */
function sh_admin_access_normalize_target(string $route): ?string
{
    if ($route === '' || strlen($route) > 180 || str_contains($route, '\\') || str_contains($route, '%') || str_contains($route, '?') || str_contains($route, '#')) { return null; }
    $route = ltrim($route, '/');
    if ($route === '' || preg_match('~^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.php$~D', $route) !== 1) { return null; }
    foreach (explode('/', $route) as $part) { if ($part === '.' || $part === '..' || str_starts_with($part, '_')) { return null; } }
    if (in_array(strtolower($route), ['custom-route.php', 'index.php'], true)) { return null; }
    $adminRoot = realpath(SH_ROOT . '/admin');
    $file = realpath(SH_ROOT . '/admin/' . $route);
    if ($adminRoot === false || $file === false || !is_file($file)
        || !str_starts_with($file, $adminRoot . DIRECTORY_SEPARATOR)
        || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
        return null;
    }
    return $route;
}

/** Extract the raw, unencoded one-segment route from REQUEST_URI. */
function sh_admin_access_request_path(): ?string
{
    $raw = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if ($raw === '' || str_contains($raw, '%') || str_contains($raw, '\\') || str_contains($raw, "\0")) { return null; }
    $base = rtrim(sh_base_url(), '/');
    if ($base !== '') {
        if (!str_starts_with($raw, $base . '/')) { return null; }
        $relative = substr($raw, strlen($base) + 1);
    } else {
        if (!str_starts_with($raw, '/') || str_starts_with($raw, '//')) { return null; }
        $relative = substr($raw, 1);
    }
    if (str_ends_with($relative, '/')) { $relative = substr($relative, 0, -1); }
    if ($relative === '' || str_contains($relative, '/')) { return null; }
    $normalized = sh_admin_access_normalize_path($relative);
    if ($normalized === null || !hash_equals($normalized, $relative)) { return null; }
    return $normalized;
}

/** Deny old /admin/*.php entry points after a custom URL is applied. */
function sh_admin_access_guard_request(): void
{
    if (!sh_admin_access_enabled() || defined('SH_ADMIN_ROUTE_CONTEXT')) { return; }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // The front controller for a validated custom URL is rewritten to this
    // file. The dispatcher performs the secret-path, session and role checks
    // itself; every target it includes still runs sh_require_admin().
    if (preg_match('~(?:^|/)admin/custom-route\.php$~i', $script) === 1) { return; }
    $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    // Some LiteSpeed/cPanel setups keep SCRIPT_NAME as the original pretty URL.
    // Recognize the configured route itself as the dispatcher entry point too.
    $requested = sh_admin_access_request_path();
    if ($requested !== null) {
        $current = sh_admin_access_state();
        if (($current['enabled'] && $current['active_path'] !== '' && hash_equals($current['active_path'], $requested))
            || ($current['pending_path'] !== '' && hash_equals($current['pending_path'], $requested))) { return; }
    }
    if (preg_match('~(?:^|/)admin(?:/|$)~i', $script) === 1
        || preg_match('~(?:^|/)admin\.php$~i', $requestPath) === 1
        || preg_match('~(?:^|/)admin(?:/|$)~i', $requestPath) === 1) {
        sh_admin_access_not_found();
    }
}

function sh_admin_access_not_found(): void
{
    if (!headers_sent()) {
        http_response_code(404);
        header('Cache-Control: no-store, private');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Not found.';
    exit;
}

function sh_admin_access_mark_verified_audit(string $path): void
{
    $fingerprint = substr(hash('sha256', $path), 0, 12);
    if (function_exists('sh_security_log')) { sh_security_log('admin_access_route_verified', null, ['fingerprint' => $fingerprint]); }
    if (function_exists('sh_audit')) { sh_audit('admin_access_route_verified', 'security', null, 'Admin Access route verified', null, ['fingerprint' => $fingerprint]); }
}

function sh_admin_access_mark_changed_audit(string $status, string $path = ''): void
{
    $meta = ['status' => $status];
    if ($path !== '') { $meta['fingerprint'] = substr(hash('sha256', $path), 0, 12); }
    if (function_exists('sh_security_log')) { sh_security_log('admin_access_route_changed', null, $meta); }
    if (function_exists('sh_audit')) { sh_audit('admin_access_route_changed', 'security', null, 'Admin Access route ' . $status, null, $meta); }
}
