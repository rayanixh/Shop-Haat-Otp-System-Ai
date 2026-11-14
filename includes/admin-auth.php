<?php
/**
 * Admin authentication. Separate session key from customers, with
 * brute-force throttling and an idle timeout.
 */

// Login throttling helpers are shared with the customer login and live in auth.php.
require_once __DIR__ . '/auth.php';

const SH_ADMIN_IDLE_TIMEOUT = 7200; // 2 hours

function sh_admin(): ?array
{
    // A configured custom URL blocks every legacy /admin/*.php entry point.
    // The dispatcher defines SH_ADMIN_ROUTE_CONTEXT only after matching the
    // active or pending secret path; the secret itself is never auth.
    if (function_exists('sh_admin_access_guard_request')) { sh_admin_access_guard_request(); }
    static $admin = null;
    static $done = false;
    if ($done) { return $admin; }
    $done = true;
    sh_session_start();
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) { return null; }
    $last = (int)($_SESSION['admin_seen'] ?? 0);
    if ($last > 0 && (time() - $last) > SH_ADMIN_IDLE_TIMEOUT) {
        sh_admin_logout(true);
        return null;
    }
    $_SESSION['admin_seen'] = time();
    try {
        try {
            $a = sh_one('SELECT id, name, email, role, role_id, status FROM admins WHERE id = ? LIMIT 1', [$id]);
        } catch (Throwable $e) {
            // role_id is added lazily by the roles module on first admin page load.
            $a = sh_one('SELECT id, name, email, role, NULL AS role_id, status FROM admins WHERE id = ? LIMIT 1', [$id]);
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'admin-auth');
        return null;
    }
    if ($a === null || $a['status'] !== 'active') {
        unset($_SESSION['admin_id']);
        return null;
    }
    $admin = $a;
    return $admin;
}

function sh_admin_login(int $adminId): void
{
    sh_session_start();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_seen'] = time();
    unset($_SESSION['csrf_token'], $_SESSION['sh_admin_expired']);
    sh_query('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$adminId]);
}

function sh_admin_logout(bool $expired = false): void
{
    sh_session_start();
    unset($_SESSION['admin_id'], $_SESSION['admin_seen'], $_SESSION['csrf_token']);
    if (defined('SH_ADMIN_ACCESS_VERIFY_SESSION_KEY')) { unset($_SESSION[SH_ADMIN_ACCESS_VERIFY_SESSION_KEY]); }
    if ($expired) { $_SESSION['sh_admin_expired'] = 1; }
    else { unset($_SESSION['sh_admin_expired']); }
    session_regenerate_id(true);
}

/** Call at the top of every admin page except login.php. */
function sh_require_admin(): array
{
    $a = sh_admin();
    if ($a !== null && !defined('SH_ADMIN_PAGE_AUTHORIZED')) { define('SH_ADMIN_PAGE_AUTHORIZED', true); }
    if ($a !== null && function_exists('sh_admin_access_enabled') && sh_admin_access_enabled()
        && preg_match('~(?:^|/)api(?:/|$)~i', (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '')) === 1
        && !defined('SH_ADMIN_ROUTE_CONTEXT')) {
        // Authenticated admin APIs may return internal admin links, but only
        // after the ordinary session check has succeeded.
        define('SH_ADMIN_ROUTE_CONTEXT', true);
    }
    if ($a === null) {
        // Security-relevant denial, with no credentials, tokens or secret route.
        if (function_exists('sh_log_line')) {
            sh_log_line('security', !empty($_SESSION['sh_admin_expired'])
                ? 'Admin access denied: expired session.'
                : 'Admin access denied: no authenticated administrator session.');
        }
        $redirect = (string)($_SERVER['REQUEST_URI'] ?? 'admin/dashboard.php');
        $loginUrl = 'admin/login.php?redirect=' . urlencode($redirect);
        $expired = !empty($_SESSION['sh_admin_expired']);
        if (sh_wants_json()) {
            // Never disclose the custom route to unauthenticated public API
            // callers. Admin pages can reload their already-open secret URL;
            // route-dispatched AJAX can receive the current custom login URL.
            $jsonLoginUrl = sh_url($loginUrl);
            if (function_exists('sh_admin_access_enabled') && sh_admin_access_enabled()
                && !defined('SH_ADMIN_ROUTE_CONTEXT')) {
                $jsonLoginUrl = null;
            }
            sh_json([
                'success' => false,
                'error' => $expired ? 'Your admin session has expired. Please sign in again.' : 'Admin authentication required.',
                'auth_required' => true,
                'session_expired' => $expired,
                'admin_required' => true,
                'login_url' => $jsonLoginUrl,
            ], 401);
        }
        if ($expired) {
            unset($_SESSION['sh_admin_expired']);
            sh_flash('info', 'Your admin session has expired. Please sign in again.');
        }
        sh_redirect($loginUrl);
    }
    // Every admin POST mutates state or performs a protected action. Enforce
    // CSRF centrally so a route cannot accidentally omit its page-level check.
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        && function_exists('sh_csrf_require')) {
        sh_csrf_require();
    }
    return $a;
}

function sh_admin_id(): int
{
    $a = sh_admin();
    return $a ? (int)$a['id'] : 0;
}

/** Credential/rules changes and security logs are restricted to the owner. */
function sh_admin_is_superadmin(): bool
{
    $a = sh_admin();
    return $a !== null && ($a['role'] ?? '') === 'superadmin';
}

/**
 * Hard gate for security-sensitive admin pages. Sends non-owners away with a
 * clear message instead of rendering the page.
 */
function sh_require_superadmin(): array
{
    $a = sh_require_admin();
    if (($a['role'] ?? '') !== 'superadmin') {
        require_once SH_ROOT . '/includes/admin-perms.php';
        sh_admin_deny('Only the store owner (Super Admin) can open this page.');
    }
    return $a;
}

