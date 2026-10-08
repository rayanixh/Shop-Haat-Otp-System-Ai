<?php
/**
 * Admin business tooling shared by the dashboard, orders, payments, products,
 * customers and the new audit-log / notifications / stock / backup pages.
 *
 * Tables (created lazily, never dropped):
 *   admin_audit_logs      who changed what (old → new) and when
 *   admin_notifications   in-panel alerts with read state
 *   stock_adjustments     manual + automatic stock movements
 *   db_backups            backup history (files live in /storage/backups, denied to the web)
 * Migrations: orders.status ENUM gains shipped / delivered / returned.
 */

function sh_admin_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        if (sh_setting('admin_tools_schema_v', '') === '1') { return; }
        $pdo = sh_db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT UNSIGNED DEFAULT NULL,
            admin_email VARCHAR(190) DEFAULT NULL,
            action VARCHAR(60) NOT NULL,
            module VARCHAR(40) NOT NULL,
            target_id INT UNSIGNED DEFAULT NULL,
            target_label VARCHAR(190) DEFAULT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_audit_created (created_at), KEY idx_audit_target (module, target_id), KEY idx_audit_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body VARCHAR(500) DEFAULT NULL,
            url VARCHAR(255) DEFAULT NULL,
            dedupe_key VARCHAR(120) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_an_read (is_read, created_at), KEY idx_an_dedupe (dedupe_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_adjustments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id INT UNSIGNED NOT NULL,
            previous_stock INT NOT NULL,
            new_stock INT NOT NULL,
            difference INT NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            admin_id INT UNSIGNED DEFAULT NULL,
            admin_email VARCHAR(190) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_sa_product (product_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS db_backups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name VARCHAR(190) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tables_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'completed',
            error VARCHAR(255) DEFAULT NULL,
            created_by VARCHAR(190) DEFAULT NULL,
            source VARCHAR(12) NOT NULL DEFAULT 'manual',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_backup_file (file_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Extend the order status list (ALTER ... MODIFY keeps all existing rows).
        $col = sh_one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status'");
        $type = (string)($col['COLUMN_TYPE'] ?? '');
        if ($type !== '' && stripos($type, "'returned'") === false) {
            $pdo->exec("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','awaiting_payment','payment_submitted','payment_verified','payment_rejected','processing','shipped','delivered','completed','cancelled','returned') NOT NULL DEFAULT 'pending'");
        }
        // Search helpers (ignored if they already exist).
        foreach (['orders' => ['idx_orders_phone', 'customer_phone'], 'payments' => ['idx_payments_trx', 'transaction_id']] as $t => [$idx, $c]) {
            $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $idx], 0);
            if ($n === 0) { $pdo->exec("ALTER TABLE `$t` ADD INDEX `$idx` (`$c`)"); }
        }
        sh_setting_save('admin_tools_schema_v', '1');
    } catch (Throwable $e) {
        sh_log_exception($e, 'admin-tools-schema');
    }
}

// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------
function sh_audit(string $action, string $module, ?int $targetId = null, string $targetLabel = '', $old = null, $new = null): void
{
    sh_admin_schema_ensure();
    try {
        $admin = function_exists('sh_admin') ? sh_admin() : null;
        $fmt = static function ($v): ?string {
            if ($v === null) { return null; }
            if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
            return mb_substr((string)$v, 0, 2000);
        };
        sh_insert('admin_audit_logs', [
            'admin_id'     => $admin['id'] ?? null,
            'admin_email'  => $admin['email'] ?? 'system',
            'action'       => mb_substr($action, 0, 60),
            'module'       => mb_substr($module, 0, 40),
            'target_id'    => $targetId,
            'target_label' => mb_substr($targetLabel, 0, 190) ?: null,
            'old_value'    => $fmt($old),
            'new_value'    => $fmt($new),
            'ip_address'   => function_exists('sh_verify_client_ip') ? sh_verify_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
        ]);
    } catch (Throwable $e) { sh_log_exception($e, 'audit'); }
}

function sh_audit_action_label(string $action): string
{
    $map = ['order_status_changed' => 'Order status changed', 'payment_verified' => 'Payment verified', 'payment_rejected' => 'Payment rejected',
        'product_created' => 'Product created', 'product_updated' => 'Product edited', 'product_deleted' => 'Product deleted',
        'price_changed' => 'Price changed', 'stock_changed' => 'Stock changed', 'customer_blocked' => 'Customer blocked', 'customer_activated' => 'Customer reactivated',
        'customer_phone_verified' => 'Customer phone verified manually', 'customer_phone_unverified' => 'Customer phone verification reset',
        'settings_changed' => 'Settings changed', 'order_note_updated' => 'Order note updated', 'order_manual_verification' => 'Manual customer verification', 'backup_downloaded' => 'Backup downloaded', 'audit_pruned' => 'Audit log pruned', 'settings_restored' => 'Settings restored from history', 'admin_created' => 'Admin account created', 'admin_updated' => 'Admin account updated', 'admin_deleted' => 'Admin account deleted', 'role_created' => 'Role created', 'role_updated' => 'Role permissions updated', 'role_deleted' => 'Role deleted', 'order_refunded' => 'Order marked refunded',
        'backup_created' => 'Backup created', 'backup_deleted' => 'Backup deleted', 'audit_cleared' => 'Audit log pruned'];
    return $map[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** Audit-log entries about one record (for timelines). */
function sh_audit_for(string $module, int $targetId, int $limit = 20): array
{
    sh_admin_schema_ensure();
    try { return sh_all('SELECT * FROM admin_audit_logs WHERE module = ? AND target_id = ? ORDER BY id DESC LIMIT ' . (int)$limit, [$module, $targetId]); }
    catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
// Admin notifications (in-panel; no browser push)
// ---------------------------------------------------------------------------
function sh_admin_notify(string $type, string $title, string $body = '', string $url = '', string $dedupeKey = ''): void
{
    sh_admin_schema_ensure();
    try {
        if ($dedupeKey !== '') {
            $exists = sh_val('SELECT id FROM admin_notifications WHERE dedupe_key = ? AND is_read = 0 LIMIT 1', [$dedupeKey], 0);
            if ($exists) { return; }
        }
        sh_insert('admin_notifications', ['type' => mb_substr($type, 0, 40), 'title' => mb_substr($title, 0, 190), 'body' => mb_substr($body, 0, 500) ?: null,
            'url' => mb_substr($url, 0, 255) ?: null, 'dedupe_key' => $dedupeKey !== '' ? mb_substr($dedupeKey, 0, 120) : null]);
    } catch (Throwable $e) { sh_log_exception($e, 'admin-notify'); }
}
function sh_admin_notifications_unread_count(): int
{
    sh_admin_schema_ensure();
    try { return (int)sh_val('SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0', [], 0); } catch (Throwable $e) { return 0; }
}
function sh_admin_notifications(int $limit = 8, bool $unreadOnly = false): array
{
    sh_admin_schema_ensure();
    try { return sh_all('SELECT * FROM admin_notifications' . ($unreadOnly ? ' WHERE is_read = 0' : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit); } catch (Throwable $e) { return []; }
}
function sh_admin_notification_icon(string $type): string
{
    $map = ['new_order' => 'package', 'payment_pending' => 'credit-card', 'low_stock' => 'alert', 'out_of_stock' => 'alert', 'returned' => 'rotate',
        'payment_failed' => 'x-circle', 'new_customer' => 'users', 'courier_check' => 'truck', 'backup' => 'shield'];
    return $map[$type] ?? 'bell';
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------
/** in | low | out — digital products are tracked by codes, not stock. */
function sh_stock_status(array $p): array
{
    if (($p['product_type'] ?? 'physical') === 'digital') { return ['key' => 'digital', 'label' => 'Digital', 'class' => 'sh-pill--info']; }
    $stock = (int)($p['stock'] ?? 0);
    $th = max(0, (int)($p['low_stock_threshold'] ?? 5));
    if ($stock <= 0) { return ['key' => 'out', 'label' => 'Out of stock', 'class' => 'sh-badge--bad']; }
    if ($stock <= $th) { return ['key' => 'low', 'label' => 'Low stock', 'class' => 'sh-badge--warn']; }
    return ['key' => 'in', 'label' => 'In stock', 'class' => 'sh-badge--ok']; }

/** Record a stock change (and apply it when $apply). Never allows negative stock. */
function sh_stock_adjust(int $productId, int $newStock, string $reason, string $source = 'manual', bool $apply = true): array
{
    sh_admin_schema_ensure();
    $p = sh_one('SELECT id, name, stock, low_stock_threshold, product_type FROM products WHERE id = ?', [$productId]);
    if ($p === null) { return ['ok' => false, 'error' => 'Product not found.']; }
    if ($newStock < 0) { return ['ok' => false, 'error' => 'Stock cannot be negative.']; }
    $prev = (int)$p['stock'];
    if ($newStock === $prev) { return ['ok' => true, 'unchanged' => true]; }
    $admin = function_exists('sh_admin') ? sh_admin() : null;
    try {
        if ($apply) { sh_update('products', ['stock' => $newStock], 'id = ?', [$productId]); }
        sh_insert('stock_adjustments', ['product_id' => $productId, 'previous_stock' => $prev, 'new_stock' => $newStock, 'difference' => $newStock - $prev,
            'reason' => mb_substr(trim($reason), 0, 255) ?: null, 'source' => $source, 'admin_id' => $admin['id'] ?? null, 'admin_email' => $admin['email'] ?? 'system']);
        if ($source === 'manual') { sh_audit('stock_changed', 'product', $productId, (string)$p['name'], $prev, $newStock); }
        sh_stock_check_alert($productId);
    } catch (Throwable $e) { sh_log_exception($e, 'stock-adjust'); return ['ok' => false, 'error' => 'The stock could not be updated.']; }
    return ['ok' => true, 'previous' => $prev, 'new' => $newStock];
}

/** Raise a low/out-of-stock admin notification once per episode. */
function sh_stock_check_alert(int $productId): void
{
    try {
        $p = sh_one('SELECT id, name, stock, low_stock_threshold, product_type FROM products WHERE id = ?', [$productId]);
        if ($p === null || $p['product_type'] === 'digital') { return; }
        $st = sh_stock_status($p);
        $url = 'admin/products.php?edit=' . $productId;
        if ($st['key'] === 'out') { sh_admin_notify('out_of_stock', $p['name'] . ' is out of stock', 'Stock reached 0.', $url, 'stock-out-' . $productId); }
        elseif ($st['key'] === 'low') { sh_admin_notify('low_stock', $p['name'] . ' is running low', 'Only ' . (int)$p['stock'] . ' left (threshold ' . (int)$p['low_stock_threshold'] . ').', $url, 'stock-low-' . $productId); }
    } catch (Throwable $e) {}
}

function sh_low_stock_products(int $limit = 50): array
{
    try {
        return sh_all("SELECT id, name, slug, image, stock, low_stock_threshold, product_type FROM products
                       WHERE product_type = 'physical' AND status = 1 AND stock <= low_stock_threshold ORDER BY stock ASC, name ASC LIMIT " . (int)$limit);
    } catch (Throwable $e) { return []; }
}

// ---------------------------------------------------------------------------
// Sales analytics (real data only)
// ---------------------------------------------------------------------------
function sh_sales_period(string $from, ?string $to = null): array
{
    $to = $to ?? date('Y-m-d H:i:s');
    try {
        $r = sh_one("SELECT COUNT(*) c,
                COALESCE(SUM(CASE WHEN payment_status = 'verified' OR status IN ('completed','delivered') THEN total ELSE 0 END),0) rev,
                SUM(status IN ('completed','delivered')) done, SUM(status = 'cancelled') canc, SUM(status = 'returned') ret
                FROM orders WHERE created_at >= ? AND created_at <= ?", [$from, $to]) ?? [];
        return ['orders' => (int)($r['c'] ?? 0), 'revenue' => (float)($r['rev'] ?? 0), 'completed' => (int)($r['done'] ?? 0), 'cancelled' => (int)($r['canc'] ?? 0), 'returned' => (int)($r['ret'] ?? 0)];
    } catch (Throwable $e) { return ['orders' => 0, 'revenue' => 0.0, 'completed' => 0, 'cancelled' => 0, 'returned' => 0]; }
}

// ---------------------------------------------------------------------------
// Global admin search
// ---------------------------------------------------------------------------
function sh_admin_search(string $q, int $limit = 8): array
{
    $q = trim($q);
    $out = ['orders' => [], 'customers' => [], 'products' => [], 'payments' => []];
    if (mb_strlen($q) < 2) { return $out; }
    $like = '%' . $q . '%';
    $phone = sh_phone_normalize($q);
    $variants = $phone !== '' ? sh_phone_variants($phone) : [];
    try {
        $w = ['o.order_number LIKE ?', 'o.customer_name LIKE ?', 'o.customer_email LIKE ?', 'o.customer_phone LIKE ?']; $a = [$like, $like, $like, $like];
        foreach ($variants as $v) { $w[] = 'o.customer_phone = ?'; $a[] = $v; }
        $w[] = 'EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.transaction_id LIKE ?)'; $a[] = $like;
        $out['orders'] = sh_all('SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.total, o.status, o.payment_status, o.created_at FROM orders o WHERE ' . implode(' OR ', $w) . ' ORDER BY o.id DESC LIMIT ' . (int)$limit, $a);

        $w = ['u.name LIKE ?', 'u.email LIKE ?', 'u.phone LIKE ?']; $a = [$like, $like, $like];
        foreach ($variants as $v) { $w[] = 'u.phone = ?'; $a[] = $v; }
        $out['customers'] = sh_all('SELECT u.id, u.name, u.email, u.phone, u.status, u.created_at FROM users u WHERE ' . implode(' OR ', $w) . ' ORDER BY u.id DESC LIMIT ' . (int)$limit, $a);

        $out['products'] = sh_all('SELECT p.id, p.name, p.sku, p.image, p.price, p.stock, p.product_type, p.status FROM products p WHERE p.name LIKE ? OR p.sku LIKE ? OR p.slug LIKE ? ORDER BY p.id DESC LIMIT ' . (int)$limit, [$like, $like, $like]);

        $out['payments'] = sh_all('SELECT p.id, p.order_id, p.amount, p.transaction_id, p.status, p.method_name, p.created_at, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id
                                   WHERE p.transaction_id LIKE ? OR p.gateway_reference LIKE ? OR p.sender_phone LIKE ? ORDER BY p.id DESC LIMIT ' . (int)$limit, [$like, $like, $like]);
    } catch (Throwable $e) { sh_log_exception($e, 'admin-search'); }
    return $out;
}

// ---------------------------------------------------------------------------
// Duplicate transaction detection
// ---------------------------------------------------------------------------
/** Other payments (different order) that reused the same transaction id. */
function sh_payment_duplicates(string $trx, int $excludePaymentId = 0): array
{
    $trx = trim($trx);
    if ($trx === '') { return []; }
    try {
        return sh_all('SELECT p.id, p.order_id, p.status, p.amount, p.created_at, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id
                       WHERE p.transaction_id = ? AND p.id <> ? ORDER BY p.id ASC', [$trx, $excludePaymentId]);
    } catch (Throwable $e) { return []; }
}
/** Map payment id → duplicate count for a list page (single query). */
function sh_payment_duplicate_map(array $payments): array
{
    $trx = [];
    foreach ($payments as $p) { $t = trim((string)($p['transaction_id'] ?? '')); if ($t !== '') { $trx[$t] = true; } }
    if (!$trx) { return []; }
    try {
        $in = implode(',', array_fill(0, count($trx), '?'));
        $rows = sh_all("SELECT transaction_id, COUNT(DISTINCT order_id) n FROM payments WHERE transaction_id IN ($in) GROUP BY transaction_id HAVING n > 1", array_keys($trx));
    } catch (Throwable $e) { return []; }
    $dupe = [];
    foreach ($rows as $r) { $dupe[$r['transaction_id']] = (int)$r['n']; }
    $out = [];
    foreach ($payments as $p) { $t = trim((string)($p['transaction_id'] ?? '')); if ($t !== '' && isset($dupe[$t])) { $out[(int)$p['id']] = $dupe[$t]; } }
    return $out;
}

// ---------------------------------------------------------------------------
// Database backup (pure PHP, no shell access needed)
// ---------------------------------------------------------------------------
function sh_backup_dir(): string
{
    $dir = SH_ROOT . '/storage/backups';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    // Belt and braces: deny direct web access even though downloads are proxied.
    if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n"); }
    if (!is_file($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php http_response_code(403); exit;"); }
    return $dir;
}

function sh_backup_create(string $createdBy, string $source = 'manual'): array
{
    sh_admin_schema_ensure();
    @set_time_limit(300);
    $dir = sh_backup_dir();
    if (!is_writable($dir)) { return ['ok' => false, 'error' => 'The storage/backups folder is not writable.']; }
    $gz = function_exists('gzopen');
    $name = 'shophaat-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 10) . '.sql' . ($gz ? '.gz' : '');
    $path = $dir . '/' . $name;
    $pdo = sh_db();
    $tables = 0;
    try {
        $fh = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
        if (!$fh) { return ['ok' => false, 'error' => 'Could not create the backup file.']; }
        $w = static function (string $s) use ($fh, $gz): void { $gz ? gzwrite($fh, $s) : fwrite($fh, $s); };
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $w("-- ShopHaat database backup\n-- Database: `$dbName`\n-- Created: " . date('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $list = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
        foreach ($list as $row) {
            $t = (string)$row[0];
            $tables++;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $t) . '`')->fetch(PDO::FETCH_NUM);
            $w("--\n-- Table `$t`\n--\nDROP TABLE IF EXISTS `$t`;\n" . ($create[1] ?? '') . ";\n\n");
            $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $t) . '`');
            $cols = null; $batch = []; $size = 0;
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) { $cols = '`' . implode('`,`', array_map(static fn($c) => str_replace('`', '``', (string)$c), array_keys($r))) . '`'; }
                $vals = [];
                foreach ($r as $v) {
                    if ($v === null) { $vals[] = 'NULL'; }
                    elseif (is_int($v) || is_float($v)) { $vals[] = (string)$v; }
                    else { $vals[] = $pdo->quote((string)$v); }
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line; $size += strlen($line);
                if ($size > 800000 || count($batch) >= 500) { $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; $size = 0; }
            }
            if ($batch) { $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n"); }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
        $gz ? gzclose($fh) : fclose($fh);
    } catch (Throwable $e) {
        if (isset($fh) && $fh) { $gz ? @gzclose($fh) : @fclose($fh); }
        @unlink($path);
        sh_log_exception($e, 'backup');
        try { sh_insert('db_backups', ['file_name' => $name, 'file_size' => 0, 'tables_count' => $tables, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255), 'created_by' => $createdBy, 'source' => $source]); } catch (Throwable $e2) {}
        return ['ok' => false, 'error' => 'The backup failed. The error has been logged.'];
    }
    $size = (int)@filesize($path);
    $id = sh_insert('db_backups', ['file_name' => $name, 'file_size' => $size, 'tables_count' => $tables, 'status' => 'completed', 'created_by' => $createdBy, 'source' => $source]);
    return ['ok' => true, 'id' => $id, 'file' => $name, 'size' => $size, 'tables' => $tables];
}

function sh_backup_list(): array
{
    sh_admin_schema_ensure();
    try { return sh_all('SELECT * FROM db_backups ORDER BY id DESC LIMIT 200'); } catch (Throwable $e) { return []; }
}
function sh_backup_path(array $b): string
{
    $file = basename((string)$b['file_name']);
    return sh_backup_dir() . '/' . $file;
}
/** Run the scheduled backup if due (called from cron.php or lazily from the admin). */
function sh_backup_run_scheduled(): ?array
{
    $freq = (string)sh_setting('backup_schedule', 'off');
    if ($freq === 'off') { return null; }
    $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$freq] ?? 0;
    if ($days === 0) { return null; }
    $last = (string)sh_setting('backup_last_auto', '');
    if ($last !== '' && time() - strtotime($last) < $days * 86400) { return null; }
    sh_setting_save('backup_last_auto', date('Y-m-d H:i:s'));
    $r = sh_backup_create('scheduler', 'auto');
    // Retention
    $keep = max(1, (int)sh_setting('backup_keep', '10'));
    try {
        $old = sh_all('SELECT * FROM db_backups WHERE status = \'completed\' ORDER BY id DESC LIMIT 1000 OFFSET ' . $keep);
        foreach ($old as $b) { @unlink(sh_backup_path($b)); sh_query('DELETE FROM db_backups WHERE id = ?', [(int)$b['id']]); }
    } catch (Throwable $e) {}
    return $r;
}

function sh_bytes_human(int $b): string
{
    if ($b >= 1048576) { return number_format($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024) { return number_format($b / 1024, 0) . ' KB'; }
    return $b . ' B';
}
