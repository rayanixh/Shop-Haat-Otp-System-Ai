<?php
/**
 * Admin roles & permissions.
 *
 * - Roles live in `admin_roles` (JSON permission list); admins reference them via
 *   `admins.role_id` (added additively). The legacy `admins.role` ENUM is kept:
 *   'superadmin' always has every permission, and a 'manager' without a role
 *   keeps the access it had before this feature (everything except the
 *   superadmin-only modules).
 * - Every protected page/action calls sh_require_perm(); the sidebar merely
 *   hides what the admin cannot open.
 */
declare(strict_types=1);

require_once SH_ROOT . '/includes/admin-auth.php';

/** Permission catalogue: group => [key => label]. */
function sh_perms_catalog(): array
{
    return [
        'Dashboard' => ['dashboard.view' => 'View dashboard'],
        'Orders' => ['orders.view' => 'View orders', 'orders.details' => 'View order details', 'orders.update_status' => 'Update order status', 'orders.cancel' => 'Cancel / return orders'],
        'Payments' => ['payments.view' => 'View payments', 'payments.verify' => 'Verify payments', 'payments.reject' => 'Reject payments', 'payments.refund' => 'Mark refunds (where supported)'],
        'Products' => ['products.view' => 'View products', 'products.create' => 'Create products', 'products.edit' => 'Edit products', 'products.delete' => 'Delete products', 'products.stock' => 'Manage stock / inventory', 'catalog.manage' => 'Manage categories, brands, coupons & digital codes'],
        'Customers' => ['customers.view' => 'View customers', 'customers.details' => 'View customer details', 'customers.orders' => 'View customer order history', 'customers.manage' => 'Block / reactivate customers'],
        'Customer Verification' => ['verification.courier' => 'View courier history', 'verification.ip' => 'View IP address', 'verification.location' => 'View approximate location'],
        'Reports' => ['reports.view' => 'View reports', 'reports.export' => 'Export reports (CSV)'],
        'Settings' => ['settings.view' => 'View settings', 'settings.edit' => 'Edit settings', 'storefront.manage' => 'Homepage images, theme & page transition', 'integrations.manage' => 'Payment methods, couriers, messaging & notifications'],
        'System' => ['system.health' => 'View system health', 'system.backups' => 'Manage database backups', 'system.audit' => 'View audit logs & settings history', 'system.logs' => 'View error / security logs', 'system.notifications' => 'Admin notifications inbox', 'ai.use' => 'AI tools'],
        'Admin Management' => ['admins.manage' => 'Manage admin accounts', 'roles.manage' => 'Manage roles & permissions'],
    ];
}

/** Permissions that can never be granted to a non-superadmin role. */
function sh_perms_superadmin_only(): array
{
    return ['admins.manage', 'roles.manage', 'system.backups'];
}

function sh_perms_all(): array
{
    $out = [];
    foreach (sh_perms_catalog() as $perms) { $out = array_merge($out, array_keys($perms)); }
    return $out;
}

function sh_perms_default_roles(): array
{
    return [
        ['slug' => 'super_admin', 'name' => 'Super Admin', 'description' => 'Full access to every module, including admins, roles and backups.', 'is_system' => 1, 'permissions' => sh_perms_all()],
        ['slug' => 'order_manager', 'name' => 'Order Manager', 'description' => 'Handles orders, shipping and customer verification.', 'is_system' => 0,
            'permissions' => ['dashboard.view', 'orders.view', 'orders.details', 'orders.update_status', 'orders.cancel', 'customers.view', 'customers.details', 'customers.orders', 'verification.courier', 'verification.ip', 'verification.location', 'reports.view', 'system.notifications']],
        ['slug' => 'payment_manager', 'name' => 'Payment Manager', 'description' => 'Verifies and rejects payments.', 'is_system' => 0,
            'permissions' => ['dashboard.view', 'orders.view', 'orders.details', 'payments.view', 'payments.verify', 'payments.reject', 'payments.refund', 'customers.view', 'customers.details', 'reports.view', 'system.notifications']],
        ['slug' => 'product_manager', 'name' => 'Product Manager', 'description' => 'Manages the catalogue and inventory.', 'is_system' => 0,
            'permissions' => ['dashboard.view', 'products.view', 'products.create', 'products.edit', 'products.delete', 'products.stock', 'catalog.manage', 'reports.view', 'system.notifications', 'ai.use']],
        ['slug' => 'support_staff', 'name' => 'Support Staff', 'description' => 'Read-only access to orders, payments and customers.', 'is_system' => 0,
            'permissions' => ['dashboard.view', 'orders.view', 'orders.details', 'payments.view', 'customers.view', 'customers.details', 'customers.orders', 'system.notifications']],
    ];
}

function sh_perms_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        if (sh_setting('admin_roles_schema_v', '') === '1') { return; }
        $pdo = sh_db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_roles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            name VARCHAR(80) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            permissions TEXT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_admin_roles_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $col = sh_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role_id'");
        if ($col === null) { $pdo->exec('ALTER TABLE admins ADD COLUMN role_id INT UNSIGNED DEFAULT NULL AFTER role, ADD INDEX idx_admins_role (role_id)'); }
        foreach (sh_perms_default_roles() as $r) {
            $exists = sh_one('SELECT id FROM admin_roles WHERE slug = ?', [$r['slug']]);
            if ($exists === null) {
                sh_insert('admin_roles', ['slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'], 'is_system' => $r['is_system'], 'permissions' => json_encode($r['permissions'])]);
            }
        }
        // Existing superadmins get the system role so the UI shows it; access is governed by the ENUM anyway.
        $sa = sh_one("SELECT id FROM admin_roles WHERE slug = 'super_admin'");
        if ($sa) { sh_query("UPDATE admins SET role_id = ? WHERE role = 'superadmin' AND role_id IS NULL", [(int)$sa['id']]); }
        sh_setting_save('admin_roles_schema_v', '1');
    } catch (Throwable $e) { sh_log_exception($e, 'admin-roles-schema'); }
}

function sh_roles_all(): array
{
    sh_perms_schema_ensure();
    try { return sh_all('SELECT r.*, (SELECT COUNT(*) FROM admins a WHERE a.role_id = r.id) AS admin_count FROM admin_roles r ORDER BY is_system DESC, name ASC'); } catch (Throwable $e) { return []; }
}

function sh_role_permissions(?array $role): array
{
    if ($role === null) { return []; }
    $p = json_decode((string)($role['permissions'] ?? '[]'), true);
    return is_array($p) ? array_values(array_intersect($p, sh_perms_all())) : [];
}

/** Resolved permission list for the signed-in admin (cached per request). */
function sh_admin_permissions(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $a = sh_admin();
    if ($a === null) { return $cache = []; }
    if (($a['role'] ?? '') === 'superadmin') { return $cache = sh_perms_all(); }
    sh_perms_schema_ensure();
    $roleId = (int)($a['role_id'] ?? 0);
    if ($roleId > 0) {
        try { $role = sh_one('SELECT * FROM admin_roles WHERE id = ?', [$roleId]); } catch (Throwable $e) { $role = null; }
        if ($role !== null) {
            if ($role['slug'] === 'super_admin') { return $cache = array_values(array_diff(sh_perms_all(), sh_perms_superadmin_only())); }
            return $cache = array_values(array_diff(sh_role_permissions($role), sh_perms_superadmin_only()));
        }
    }
    // Legacy manager without an assigned role: keep previous behaviour (everything but the owner-only modules).
    return $cache = array_values(array_diff(sh_perms_all(), sh_perms_superadmin_only()));
}

function sh_admin_can(string ...$perms): bool
{
    $have = sh_admin_permissions();
    foreach ($perms as $p) { if (in_array($p, $have, true)) { return true; } }
    return false;
}

/** Render the professional Access Denied page (HTTP 403) and stop. */
function sh_admin_deny(string $message = ''): void
{
    if (sh_wants_json()) { sh_json(['success' => false, 'error' => 'You do not have permission to perform this action.'], 403); }
    http_response_code(403);
    $adminPage = '';
    $adminTitle = 'Access Denied';
    $admin = sh_admin();
    require SH_ROOT . '/admin/_layout.php';
    echo '<div class="sh-panel"><div class="sh-panel__body"><div class="sh-denied">'
        . sh_icon('lock', 30)
        . '<h2>Access Denied</h2>'
        . '<p>' . e($message !== '' ? $message : 'Your admin role does not include permission to open this page or perform this action. Please contact the store owner if you believe this is a mistake.') . '</p>'
        . '<a class="sh-btn sh-btn--sm" href="' . e(sh_url('admin/dashboard.php')) . '">Back to dashboard</a>'
        . '</div></div></div>';
    require SH_ROOT . '/admin/_footer.php';
    exit;
}

/** Require any one of the given permissions (page or action level). */
function sh_require_perm(string ...$perms): array
{
    $a = sh_require_admin();
    if (!sh_admin_can(...$perms)) { sh_admin_deny(); }
    return $a;
}

/** Form-level helper: returns true when denied (after flashing), to use inside POST handlers that redirect. */
function sh_perm_denied_flash(string ...$perms): bool
{
    if (sh_admin_can(...$perms)) { return false; }
    sh_flash('error', 'Access denied: your role does not allow this action.');
    return true;
}
