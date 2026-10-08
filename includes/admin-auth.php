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
    static $admin = null;
    static $done = false;
    if ($done) { return $admin; }
    $done = true;
    sh_session_start();
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) { return null; }
    $last = (int)($_SESSION['admin_seen'] ?? 0);
    if ($last > 0 && (time() - $last) > SH_ADMIN_IDLE_TIMEOUT) {
        sh_admin_logout();
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
    sh_query('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$adminId]);
}

function sh_admin_logout(): void
{
    sh_session_start();
    unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
    session_regenerate_id(true);
}

/** Call at the top of every admin page except login.php. */
function sh_require_admin(): array
{
    $a = sh_admin();
    if ($a === null) {
        if (sh_wants_json()) {
            sh_json(['success' => false, 'error' => 'Admin authentication required.'], 401);
        }
        sh_redirect('admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
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

