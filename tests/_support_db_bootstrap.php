<?php
/**
 * Shared test bootstrap for the support state-machine and Admin Access URL
 * integration suites.
 *
 * It loads the REAL application service modules (includes/ai-support.php,
 * includes/admin-access.php) and executes them against a real PDO database.
 *
 *   * With SH_TEST_MYSQL_DSN (+ SH_TEST_MYSQL_USER / _PASS) it connects to a
 *     real MySQL/MariaDB server and runs the shipped MySQL DDL unmodified.
 *     Example:
 *       SH_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=shophaat_test;charset=utf8mb4' \
 *       SH_TEST_MYSQL_USER=test SH_TEST_MYSQL_PASS=test \
 *       php tests/human-support-handoff.php
 *
 *   * Without those variables it uses an in-memory SQLite database. A small,
 *     explicit translation layer maps the handful of MySQL constructs the
 *     module uses (FOR UPDATE, ON DUPLICATE KEY UPDATE, UTC_TIMESTAMP(), etc.)
 *     onto SQLite. This exercises the same PHP code and real SQL statements;
 *     it is NOT a claim of MySQL-engine parity. CI with MySQL must be used for
 *     that (see .github/workflows).
 *
 * No web server, provider credential, Node.js or Composer dependency is used.
 */
declare(strict_types=1);

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }
require_once SH_ROOT . '/ai/support-schema.php';
if (!isset($_SESSION)) { $_SESSION = []; }
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/tests/human-support-handoff.php';
$_SERVER['SCRIPT_NAME'] = '/tests/human-support-handoff.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_ORIGIN']);

/** PDO subclass that translates MySQL-only SQL when running on SQLite. */
class ShTestPDO extends PDO
{
    public bool $isMySql;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        parent::__construct($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->isMySql = str_starts_with($dsn, 'mysql:');
        if (!$this->isMySql) {
            // PHP 8.5 deprecates PDO::sqliteCreateFunction in favour of Pdo\Sqlite;
            // both work, and the notice would only add noise to the test output.
            @$this->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
            @$this->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
            @$this->sqliteCreateFunction('IF', static fn($c, $a, $b) => $c ? $a : $b, 3);
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->translate($statement));
    }

    private function translate(string $sql): string
    {
        if ($this->isMySql) { return $sql; }
        // information_schema probes: map to SQLite catalog equivalents with the
        // same bound parameters so prepared statements keep their shape.
        if (preg_match('~information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?~i', $sql) === 1) {
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
        }
        if (preg_match('~information_schema\.COLUMNS\s+WHERE\s+TABLE_SCHEMA\s*=\s*DATABASE\(\)\s+AND\s+TABLE_NAME\s*=\s*\?\s+AND\s+COLUMN_NAME\s*=\s*\?~i', $sql) === 1) {
            return 'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?';
        }
        $sql = preg_replace('/\s+FOR UPDATE\b/i', '', $sql) ?? $sql;
        $sql = preg_replace('/\bINSERT IGNORE\b/i', 'INSERT OR IGNORE', $sql) ?? $sql;
        $sql = preg_replace('/\bON DUPLICATE KEY UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $sql) ?? $sql;
        $sql = preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', $sql) ?? $sql;
        $sql = preg_replace('/\bAS CHAR\b/i', 'AS TEXT', $sql) ?? $sql;
        return $sql;
    }
}

$GLOBALS['sh_test_mysql'] = false;
$dsn = getenv('SH_TEST_MYSQL_DSN');
if (is_string($dsn) && $dsn !== '' && extension_loaded('pdo_mysql')) {
    $pdo = new ShTestPDO($dsn, (string)getenv('SH_TEST_MYSQL_USER'), (string)getenv('SH_TEST_MYSQL_PASS'));
    $GLOBALS['sh_test_mysql'] = true;
} elseif (extension_loaded('pdo_sqlite')) {
    $pdo = new ShTestPDO('sqlite::memory:');
    $pdo->exec('PRAGMA foreign_keys = OFF');
} else {
    fwrite(STDERR, "This suite needs pdo_sqlite (or pdo_mysql + SH_TEST_MYSQL_DSN).\n");
    exit(2);
}
$GLOBALS['sh_test_pdo'] = $pdo;
$GLOBALS['sh_test_tables'] = [];
$GLOBALS['sh_test_ai_calls'] = 0;
$GLOBALS['sh_test_admin_super'] = true;

function sh_db(): PDO { return $GLOBALS['sh_test_pdo']; }
function sh_db_config(): array { return ['host' => 'localhost', 'name' => 'shophaat_test', 'user' => 'test', 'pass' => 'test']; }
function sh_query(string $sql, array $params = []): PDOStatement
{
    $st = sh_db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function sh_all(string $sql, array $params = []): array { return sh_query($sql, $params)->fetchAll(); }
function sh_one(string $sql, array $params = []): ?array
{
    $row = sh_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}
function sh_val(string $sql, array $params = [], $default = null)
{
    $v = sh_query($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
function sh_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    sh_query('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')', array_values($data));
    return (int)sh_db()->lastInsertId();
}
function sh_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) { $set[] = "`$c` = ?"; }
    return sh_query('UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where,
        array_merge(array_values($data), $whereParams))->rowCount();
}
function sh_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    $cache = [];
    foreach (sh_all('SELECT setting_key, setting_value FROM settings') as $r) {
        $cache[$r['setting_key']] = $r['setting_value'];
    }
    return $cache;
}
function sh_setting(string $key, $default = '')
{
    $s = sh_settings();
    return array_key_exists($key, $s) && $s[$key] !== null ? $s[$key] : $default;
}
function sh_setting_save(string $key, string $value): void
{
    sh_query('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    sh_settings(true);
}
function sh_table_exists(string $table): bool
{
    return (bool)($GLOBALS['sh_test_tables'][$table] ?? false);
}
function sh_log_line(string $channel, string $message): void {}
function sh_log_exception(Throwable $e, string $channel): void {}
function sh_session_start(): void {}
function sh_request_is_https(): bool { return false; }
function sh_base_url(): string { return ''; }
function sh_url(string $path = ''): string { return '/' . ltrim($path, '/'); }
function sh_redirect(string $path): void { throw new RuntimeException('redirect: ' . $path); }
function sh_json(array $payload, int $status = 200): void { throw new RuntimeException('json: ' . json_encode($payload)); }
function sh_csrf_require(): void {}
function sh_csrf_valid(?string $token = null): bool { return true; }
function sh_wants_json(): bool { return false; }
// admin-auth.php defines this itself; the auth subprocess loads that real file.
if (getenv('SH_TEST_ADMIN_AUTH_MODE') !== '1') {
    function sh_admin_is_superadmin(): bool { return (bool)$GLOBALS['sh_test_admin_super']; }
}
function sh_perms_superadmin_only(): array { return []; }
function sh_ai_installed(): bool { $GLOBALS['sh_test_ai_calls']++; return false; }
function sh_security_log(string $event, $userId = null, array $meta = []): void {}
function sh_audit(string $event, string $kind = 'setting', $id = null, string $label = '', $old = null, $new = null): void {}
function sh_site_cookie_options(int $expires = 0): array { return ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']; }

/** Create the schema in whichever engine is in use. */
function sh_test_schema(): void
{
    $pdo = sh_db();
    $mysql = $pdo->isMySql;
    $ai = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $big = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $ddl = [
        "CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(190) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NULL)$engine",
        "CREATE TABLE IF NOT EXISTS admins (id $ai, name VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', role VARCHAR(40) NOT NULL DEFAULT 'support_agent', role_id INT UNSIGNED DEFAULT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', password_hash VARCHAR(255) NOT NULL DEFAULT '')$engine",
        "CREATE TABLE IF NOT EXISTS users (id $ai, name VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL DEFAULT '', phone VARCHAR(30) NOT NULL DEFAULT '')$engine",
        "CREATE TABLE IF NOT EXISTS categories (id $ai, name VARCHAR(160) NOT NULL DEFAULT '')$engine",
        "CREATE TABLE IF NOT EXISTS products (id $ai, name VARCHAR(190) NOT NULL DEFAULT '', slug VARCHAR(190) NOT NULL DEFAULT '', short_description TEXT NULL, description TEXT NULL, specifications TEXT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, compare_price DECIMAL(12,2) NOT NULL DEFAULT 0, stock INT NOT NULL DEFAULT 0, product_type VARCHAR(40) NOT NULL DEFAULT 'physical', image VARCHAR(255) NOT NULL DEFAULT '', category_id INT UNSIGNED DEFAULT NULL, status TINYINT(1) NOT NULL DEFAULT 1, is_featured TINYINT(1) NOT NULL DEFAULT 0, sold_count INT NOT NULL DEFAULT 0)$engine",
        "CREATE TABLE IF NOT EXISTS orders (id $big, order_number VARCHAR(40) NOT NULL DEFAULT '', user_id INT UNSIGNED DEFAULT NULL, status VARCHAR(40) NOT NULL DEFAULT 'processing', payment_status VARCHAR(40) NOT NULL DEFAULT 'pending', total DECIMAL(12,2) NOT NULL DEFAULT 0, payment_method_name VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS order_items (id $big, order_id BIGINT UNSIGNED NOT NULL DEFAULT 0, product_name VARCHAR(190) NOT NULL DEFAULT '', quantity INT NOT NULL DEFAULT 1, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0)$engine",
    ];
    foreach ($ddl as $sql) { $pdo->exec($sql); }

    if ($mysql) {
        // Production DDL, unmodified, from the shipped schema module.
        foreach (sh_ai_support_schema_sql() as $sql) { $pdo->exec($sql); }
        return;
    }
    // SQLite equivalents of the six support tables (same columns the module uses).
    foreach ([
        "CREATE TABLE support_conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER DEFAULT NULL,
            guest_token_hash TEXT DEFAULT NULL, customer_name TEXT NOT NULL DEFAULT '',
            customer_email TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '',
            subject TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'open',
            assigned_admin_id INTEGER DEFAULT NULL, ai_paused INTEGER NOT NULL DEFAULT 0,
            unread_for_admin INTEGER NOT NULL DEFAULT 0, unread_for_customer INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT 'en', last_product_id INTEGER DEFAULT NULL,
            last_order_number TEXT DEFAULT NULL, context_summary TEXT DEFAULT NULL,
            unread_admin INTEGER NOT NULL DEFAULT 0, unread_customer INTEGER NOT NULL DEFAULT 0,
            last_message_at TEXT DEFAULT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        "CREATE TABLE support_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL, reply_to_message_id INTEGER DEFAULT NULL,
            client_message_id TEXT DEFAULT NULL, sender_admin_id INTEGER DEFAULT NULL,
            sender TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'manual', body TEXT NOT NULL,
            extra_json TEXT DEFAULT NULL, reply_status TEXT NOT NULL DEFAULT 'none',
            reply_started_at TEXT DEFAULT NULL, is_internal INTEGER NOT NULL DEFAULT 0,
            visibility TEXT NOT NULL DEFAULT 'public', lang TEXT NOT NULL DEFAULT '',
            client_msg_id TEXT DEFAULT NULL, facts_json TEXT DEFAULT NULL,
            admin_id INTEGER DEFAULT NULL, created_at TEXT NOT NULL,
            UNIQUE (conversation_id, client_message_id))",
        "CREATE TABLE support_handoff_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL,
            event_key TEXT DEFAULT NULL UNIQUE, legacy_event_id INTEGER DEFAULT NULL UNIQUE,
            actor_type TEXT NOT NULL, actor_admin_id INTEGER DEFAULT NULL,
            event TEXT NOT NULL, reason TEXT DEFAULT NULL, created_at TEXT NOT NULL)",
        "CREATE TABLE support_faqs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, seed_key TEXT DEFAULT NULL UNIQUE,
            title TEXT NOT NULL DEFAULT '', question_en TEXT NOT NULL DEFAULT '',
            question_bn TEXT DEFAULT NULL, question_banglish TEXT DEFAULT NULL,
            answer_en TEXT NOT NULL DEFAULT '', answer_bn TEXT DEFAULT NULL,
            answer_banglish TEXT DEFAULT NULL, keywords TEXT DEFAULT NULL,
            status INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER DEFAULT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        "CREATE TABLE support_ai_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL,
            user_id INTEGER DEFAULT NULL, guest_token_hash TEXT DEFAULT NULL,
            provider_id INTEGER DEFAULT NULL, model TEXT DEFAULT NULL, outcome TEXT NOT NULL,
            prompt_tokens INTEGER NOT NULL DEFAULT 0, completion_tokens INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER NOT NULL DEFAULT 0, diagnostic_code TEXT DEFAULT NULL,
            created_at TEXT NOT NULL)",
        "CREATE TABLE support_rate_limits (
            bucket_key TEXT NOT NULL PRIMARY KEY, hits INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT DEFAULT NULL, updated_at TEXT DEFAULT NULL)",
    ] as $sql) { $pdo->exec($sql); }
}

function sh_test_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** Minimal assertion runner shared by both suites. */
function sh_test_run(string $title, callable $fn): array
{
    $passed = 0; $failed = 0; $failures = [];
    $ok = static function (bool $cond, string $msg) use (&$passed, &$failed, &$failures): void {
        if ($cond) { $passed++; echo "  PASS  $msg\n"; return; }
        $failed++; $failures[] = $msg; echo "  FAIL  $msg\n";
    };
    $eq = static function ($expected, $actual, string $msg) use ($ok): void {
        $ok($expected === $actual, $msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    };
    echo $title . "\n" . str_repeat('-', min(72, strlen($title))) . "\n";
    $fn($ok, $eq);
    echo "\n$title: passed $passed, failed $failed\n";
    if ($failures) { echo "Failures:\n - " . implode("\n - ", $failures) . "\n"; }
    return [$passed, $failed];
}
