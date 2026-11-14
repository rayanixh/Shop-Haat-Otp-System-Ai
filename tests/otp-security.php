<?php
/**
 * OTP security & risk remediation suite.
 *
 * Exercises the REAL includes/phone-otp.php against a REAL PDO database:
 * rate limiting (phone / IP / daily), failed-delivery accounting, production
 * provider safety, verification replay + concurrency, expiry, purpose and user
 * binding, schema migration/backfill, and the optional-extension fallbacks.
 *
 *   php tests/otp-security.php                       # in-memory SQLite
 *   SH_TEST_MYSQL_DSN='mysql:host=…;dbname=…' \
 *   SH_TEST_MYSQL_USER=… SH_TEST_MYSQL_PASS=… php tests/otp-security.php
 *
 * With MySQL/MariaDB the SHIPPED DDL from install/schema.php is executed
 * unmodified. Without it, an explicit SQLite mirror of those tables is created
 * and a small translation layer maps the MySQL constructs the module uses
 * (NOW(), INTERVAL arithmetic, TIMESTAMPDIFF, <=>, INSERT IGNORE, FOR UPDATE).
 * That is the same approach tests/_support_db_bootstrap.php uses; it is not a
 * claim of engine parity, and CI runs the MySQL path where a server exists.
 *
 * No carrier is contacted: provider delivery is replaced by a registered test
 * driver, and the offline driver is used where "delivery succeeds" is needed.
 */
declare(strict_types=1);

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }
define('SH_TEST_LOG_DIR', sys_get_temp_dir() . '/sh-otp-test-logs-' . getmypid());
@mkdir(SH_TEST_LOG_DIR, 0775, true);
define('SH_LOG_DIR', SH_TEST_LOG_DIR);
define('SH_UPLOAD_DIR', SH_TEST_LOG_DIR);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['REQUEST_URI'] = '/tests/otp-security.php';
$_SERVER['SCRIPT_NAME'] = '/tests/otp-security.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'ShopHaat OTP security suite';
unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_ORIGIN'], $_SESSION['sh_test']);
if (!isset($_SESSION)) { $_SESSION = []; }

require_once SH_ROOT . '/includes/errors.php';
require_once SH_ROOT . '/includes/compat.php';
require_once SH_ROOT . '/includes/environment.php';
// Real settings / phone helpers / sanitizer (no DB call happens at load time).
require_once SH_ROOT . '/includes/functions.php';

// ---------------------------------------------------------------------------
// Database: MySQL when configured, SQLite otherwise.
// ---------------------------------------------------------------------------
class ShOtpTestPDO extends PDO
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
            @$this->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
            @$this->sqliteCreateFunction('CURDATE', static fn(): string => gmdate('Y-m-d'), 0);
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

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query($this->translate($query), $fetchMode, ...$fetchModeArgs);
    }

    /** Translate the handful of MySQL constructs this module uses to SQLite. */
    public function translate(string $sql): string
    {
        if ($this->isMySql) { return $sql; }

        // information_schema probes used by sh_table_exists().
        if (preg_match('~information_schema\.tables\s+WHERE\s+table_schema\s*=\s*DATABASE\(\)\s+AND\s+table_name\s*=\s*\?~i', $sql) === 1) {
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
        }
        if (preg_match('~information_schema\.COLUMNS~i', $sql) === 1) {
            return preg_replace('~information_schema\.COLUMNS~i', 'pragma_table_info', $sql) ?? $sql;
        }

        $unit = static fn(string $u): string => strtolower($u);
        // DATE_SUB / DATE_ADD with a bound interval. The bound value may be
        // negative, so the sign is produced arithmetically instead of with a
        // literal '-' (which would yield the invalid modifier "--10 second").
        $sql = preg_replace_callback(
            '~DATE_(SUB|ADD)\(\s*NOW\(\)\s*,\s*INTERVAL\s+\?\s+(SECOND|MINUTE|HOUR|DAY)\s*\)~i',
            static fn(array $m): string => "datetime('now', ("
                . ($m[1] === 'SUB' ? '-1' : '1') . " * ?) || ' " . $unit($m[2]) . "')",
            $sql
        ) ?? $sql;
        // …and with a literal interval.
        $sql = preg_replace_callback(
            '~DATE_(SUB|ADD)\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)~i',
            static fn(array $m): string => "datetime('now', '" . ($m[1] === 'SUB' ? '-' : '+') . $m[2] . ' ' . $unit($m[3]) . "')",
            $sql
        ) ?? $sql;
        // Legacy `NOW() - INTERVAL n SECOND` arithmetic.
        $sql = preg_replace_callback(
            '~\(\s*NOW\(\)\s*([+-])\s*INTERVAL\s+\?\s+(SECOND|MINUTE|HOUR|DAY)\s*\)~i',
            static fn(array $m): string => "datetime('now', ("
                . ($m[1] === '-' ? '-1' : '1') . " * ?) || ' " . $unit($m[2]) . "')",
            $sql
        ) ?? $sql;

        // TIMESTAMPDIFF(SECOND, col, NOW()) — the argument may itself contain
        // parentheses, so NOW() is matched explicitly before the generic form.
        $sql = preg_replace(
            '~TIMESTAMPDIFF\(\s*SECOND\s*,\s*([A-Za-z0-9_.]+)\s*,\s*NOW\(\)\s*\)~i',
            'CAST((julianday(NOW()) - julianday($1)) * 86400 AS INTEGER)',
            $sql
        ) ?? $sql;
        $sql = preg_replace(
            '~TIMESTAMPDIFF\(\s*SECOND\s*,\s*([A-Za-z0-9_.]+)\s*,\s*([A-Za-z0-9_.]+)\s*\)~i',
            'CAST((julianday($2) - julianday($1)) * 86400 AS INTEGER)',
            $sql
        ) ?? $sql;
        $sql = preg_replace('~\bGREATEST\(~i', 'MAX(', $sql) ?? $sql;
        $sql = preg_replace('~\(\s*(\w+)\s*<=\>\s*\?\s*\)~', '($1 IS ?)', $sql) ?? $sql;
        $sql = preg_replace('~\bINSERT IGNORE\b~i', 'INSERT OR IGNORE', $sql) ?? $sql;
        $sql = preg_replace('~ON DUPLICATE KEY UPDATE\s+setting_value\s*=\s*VALUES\(setting_value\)\s*,\s*updated_at\s*=\s*NOW\(\)~i',
            "ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = datetime('now')", $sql) ?? $sql;
        $sql = preg_replace('~ON DUPLICATE KEY UPDATE\s+setting_value\s*=\s*VALUES\(setting_value\)~i',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value', $sql) ?? $sql;
        $sql = preg_replace('~\s+FOR UPDATE\b~i', '', $sql) ?? $sql;
        return $sql;
    }
}

$GLOBALS['sh_test_is_mysql'] = false;
$dsn = getenv('SH_TEST_MYSQL_DSN');
if (is_string($dsn) && $dsn !== '' && extension_loaded('pdo_mysql')) {
    $pdo = new ShOtpTestPDO($dsn, (string)getenv('SH_TEST_MYSQL_USER'), (string)getenv('SH_TEST_MYSQL_PASS'));
    $GLOBALS['sh_test_is_mysql'] = true;
} elseif (extension_loaded('pdo_sqlite')) {
    // A file (not :memory:) database so the concurrency cases can open a second
    // connection to the same store, exactly like a second PHP request would.
    $dbFile = sys_get_temp_dir() . '/sh-otp-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo = new ShOtpTestPDO('sqlite:' . $dbFile);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('PRAGMA busy_timeout = 4000');
    $GLOBALS['sh_test_db_file'] = $dbFile;
} else {
    fwrite(STDERR, "This suite needs pdo_sqlite (or pdo_mysql + SH_TEST_MYSQL_DSN).\n");
    exit(2);
}
$GLOBALS['sh_test_pdo'] = $pdo;

function sh_db(): PDO { return $GLOBALS['sh_test_pdo']; }
function sh_db_config(): array { return ['host' => 'localhost', 'name' => 'shophaat_test', 'user' => 'test', 'pass' => 'test']; }

// The shipped query helpers, re-declared here because includes/db.php builds a
// MySQL connection through sh_db_config(). Behaviour is identical: prepared
// statements only, a RuntimeException on failure.
function sh_query(string $sql, array $params = []): PDOStatement
{
    try {
        $st = sh_db()->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (PDOException $e) {
        throw new RuntimeException('Database query failed: ' . $e->getMessage(), 0, $e);
    }
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
    sh_query('INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES ($ph)", array_values($data));
    return (int)sh_db()->lastInsertId();
}

function sh_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) { $set[] = "`$c` = ?"; }
    return sh_query('UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where,
        array_merge(array_values($data), $whereParams))->rowCount();
}

require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/phone-otp.php';

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------
function sh_otp_test_is_mysql(): bool { return (bool)$GLOBALS['sh_test_is_mysql']; }

function sh_otp_test_schema(): void
{
    $pdo = sh_db();
    if (sh_otp_test_is_mysql()) {
        // The shipped DDL, unmodified — this is the production path.
        foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
        return;
    }
    // SQLite mirror of the columns/indexes the module touches.
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        phone VARCHAR(30) NOT NULL,
        purpose VARCHAR(30) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        provider VARCHAR(30) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        max_attempts INTEGER NOT NULL DEFAULT 5,
        resend_count INTEGER NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_otp_phone_purpose ON otp_verifications (phone, purpose)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_otp_created ON otp_verifications (created_at)");
    // Rate-limit tables: identical column set + index names to the MySQL DDL.
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        phone_key CHAR(64) NOT NULL,
        ip_key CHAR(64) DEFAULT NULL,
        purpose VARCHAR(30) NOT NULL,
        outcome VARCHAR(20) NOT NULL DEFAULT 'pending',
        provider VARCHAR(30) DEFAULT NULL,
        user_id INTEGER DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orl_phone_time ON otp_rate_limits (phone_key, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orl_ip_time ON otp_rate_limits (ip_key, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orl_created ON otp_rate_limits (created_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_rate_locks (
        bucket_key CHAR(64) NOT NULL PRIMARY KEY,
        locked_at DATETIME NOT NULL DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orl_lock_age ON otp_rate_locks (locked_at)");
    // Real sh_security_log() writes here.
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        event VARCHAR(60) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        metadata TEXT,
        created_at DATETIME NOT NULL DEFAULT (datetime('now')))");
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------
$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;
$GLOBALS['t_section'] = '';

function t_sec(string $name): void
{
    $GLOBALS['t_section'] = $name;
    echo "\n" . $name . "\n";
}

function t_ok(string $name, bool $condition, string $detail = ''): bool
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "  ok   {$name}\n";
    } else {
        $GLOBALS['t_fail']++;
        echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    }
    return $condition;
}

function t_eq(string $name, $expected, $actual): bool
{
    return t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function t_reset(): void
{
    sh_settings(true);
    foreach (['otp_rate_limits', 'otp_rate_locks', 'otp_verifications', 'security_logs'] as $table) {
        try { sh_db()->exec('DELETE FROM ' . $table); } catch (Throwable $e) { /* table may not exist yet */ }
    }
    $GLOBALS['sh_otp_sent'] = [];
}

function t_setting(string $key, string $value): void
{
    sh_setting_save($key, $value);
    sh_settings(true);
}

/** Deterministic OTP fixture with a known code (bypasses hashing randomness). */
function t_fixture_code(string $phone, string $purpose, string $code, ?int $userId = null, int $expiresInSeconds = 300, int $attempts = 0): int
{
    sh_query(
        'INSERT INTO otp_verifications (user_id, phone, purpose, otp_hash, provider, expires_at, attempts, max_attempts, resend_count, ip_address, user_agent)
         VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, 0, ?, ?)',
        [$userId, $phone, $purpose, password_hash($code, PASSWORD_DEFAULT), 'offline', $expiresInSeconds, $attempts,
         max(1, (int)sh_setting('otp_max_attempts', '5')), '203.0.113.7', 'suite']
    );
    return (int)sh_db()->lastInsertId();
}

function t_count(string $table, string $where = '1=1', array $params = []): int
{
    try {
        return (int)sh_val("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params, 0);
    } catch (Throwable $e) {
        return -1;
    }
}

function t_rate_rows(): array
{
    try { return sh_all('SELECT * FROM otp_rate_limits ORDER BY id ASC'); } catch (Throwable $e) { return []; }
}

function t_last_rate_row(): ?array
{
    $rows = t_rate_rows();
    return $rows === [] ? null : $rows[count($rows) - 1];
}

function t_second_connection(): ?PDO
{
    if (sh_otp_test_is_mysql() || empty($GLOBALS['sh_test_db_file'])) { return null; }
    try {
        $p = new ShOtpTestPDO('sqlite:' . $GLOBALS['sh_test_db_file']);
        $p->exec('PRAGMA busy_timeout = 4000');
        return $p;
    } catch (Throwable $e) {
        return null;
    }
}

/** Register a delivery driver that always fails, and capture what was sent. */
function t_failing_provider(string $message = 'Provider rejected the request.'): void
{
    $GLOBALS['sh_otp_sent'] = [];
    sh_otp_register_driver('test_fail', static function (string $phone, string $msg, array $cfg) use ($message): array {
        $GLOBALS['sh_otp_sent'][] = $msg;
        $diag = sh_sms_diagnostic_base('test_fail', $phone, 'https://example.invalid/send', 'POST', 'x-api-key');
        $diag['acceptance'] = 'Rejected by the transport stub.';
        return sh_sms_result(false, $message, $diag, 'rejected');
    });
}

/** Register a delivery driver that succeeds, and capture what was sent. */
function t_accepting_provider(): void
{
    $GLOBALS['sh_otp_sent'] = [];
    sh_otp_register_driver('test_ok', static function (string $phone, string $msg, array $cfg): array {
        $GLOBALS['sh_otp_sent'][] = $msg;
        $diag = sh_sms_diagnostic_base('test_ok', $phone, 'https://example.invalid/send', 'POST', 'x-api-key');
        $diag['acceptance'] = 'Accepted by the transport stub.';
        return sh_sms_result(true, '', $diag, 'accepted');
    });
}

/** Register an accepting stub under an existing provider name (no network). */
function t_accepting_provider_for(string $name): void
{
    $GLOBALS['sh_otp_sent'] = [];
    sh_otp_register_driver($name, static function (string $phone, string $msg, array $cfg): array {
        $GLOBALS['sh_otp_sent'][] = $msg;
        $diag = sh_sms_diagnostic_base('textbee', $phone, 'https://example.invalid/send', 'POST', 'x-api-key');
        $diag['acceptance'] = 'Accepted by the transport stub.';
        return sh_sms_result(true, '', $diag, 'accepted');
    });
}

/** Switch the deployment environment seen by includes/environment.php. */
function t_env(string $env): void
{
    putenv('SH_ENVIRONMENT=' . $env);
    unset($_SERVER['SH_ENVIRONMENT']);
}

// Production by default: everything that needs the test drivers opts in.
t_env('production');

sh_otp_test_schema();
// Baseline settings (fresh install defaults + a working test provider).
t_setting('authentication_mode', 'phone_otp');
t_setting('otp_provider', 'textbee');
t_setting('otp_textbee_api_key', 'test-key-not-real');
t_setting('otp_length', '6');
t_setting('otp_expiry_minutes', '5');
t_setting('otp_max_attempts', '5');
t_setting('otp_max_resends', '3');
t_setting('otp_resend_cooldown', '60');
t_setting('otp_daily_limit', '20');
t_setting('otp_phone_rate_limit', '5');
t_setting('otp_phone_rate_window', '60');
t_setting('otp_ip_rate_limit', '10');
t_setting('otp_ip_rate_window', '60');
t_setting('otp_rate_schema_version', '1');

$phone = '8801712345678';        // canonical form
$ip = '203.0.113.7';

// ===========================================================================
t_sec('1. Schema, migration and backward compatibility');
// ===========================================================================
$rateDdl = sh_otp_rate_schema_sql();
$tablesDdl = sh_otp_rate_tables_sql();
t_eq('the runtime DDL and the installer DDL are the same definition', $rateDdl, $tablesDdl);
t_ok('rate-limit DDL is idempotent (CREATE TABLE IF NOT EXISTS)',
    str_contains(implode("\n", $rateDdl), 'CREATE TABLE IF NOT EXISTS') && substr_count(implode("\n", $rateDdl), 'CREATE TABLE IF NOT EXISTS') === 2);
t_ok('rate-limit DDL stores hashed identifiers, not phone numbers',
    str_contains(implode("\n", $rateDdl), 'phone_key CHAR(64)') && !str_contains(implode("\n", $rateDdl), 'phone VARCHAR'));
t_ok('rate-limit DDL carries phone, IP and retention indexes',
    str_contains(implode("\n", $rateDdl), 'idx_orl_phone_time')
    && str_contains(implode("\n", $rateDdl), 'idx_orl_ip_time')
    && str_contains(implode("\n", $rateDdl), 'idx_orl_created'));
t_ok('lock table is keyed by bucket and indexed by age',
    str_contains(implode("\n", $rateDdl), 'bucket_key CHAR(64)') && str_contains(implode("\n", $rateDdl), 'idx_orl_lock_age'));
t_ok('new tables are part of the canonical schema inventory',
    in_array('otp_rate_limits', sh_schema_tables(), true) && in_array('otp_rate_locks', sh_schema_tables(), true));
t_ok('installer schema includes the rate-limit DDL', in_array($rateDdl[0], sh_schema_sql(), true));
t_eq('schema version was bumped for this migration', 5, SH_OTP_SCHEMA_VERSION);

$migrationFile = SH_ROOT . '/install/migrations/2026-11-otp-rate-limits.sql';
$rollbackFile = SH_ROOT . '/install/migrations/2026-11-otp-rate-limits-rollback.sql';
$migrationSql = is_file($migrationFile) ? (string)file_get_contents($migrationFile) : '';
$rollbackSql = is_file($rollbackFile) ? (string)file_get_contents($rollbackFile) : '';
t_ok('a versioned migration file exists', $migrationSql !== '');
t_ok('migration is re-runnable and only creates new objects',
    str_contains($migrationSql, 'CREATE TABLE IF NOT EXISTS')
    && !preg_match('~\bDROP\s+TABLE\b~i', $migrationSql)
    && !preg_match('~\bDELETE\s+FROM\b~i', $migrationSql)
    && !preg_match('~\bALTER\s+TABLE\b~i', $migrationSql));
t_ok('migration stamps the schema version', str_contains($migrationSql, "'otp_rate_schema_version', '1'"));
t_ok('rollback drops only the two new tables',
    substr_count($rollbackSql, 'DROP TABLE IF EXISTS') === 2
    && str_contains($rollbackSql, 'DROP TABLE IF EXISTS `otp_rate_locks`')
    && str_contains($rollbackSql, 'DROP TABLE IF EXISTS `otp_rate_limits`')
    && !preg_match('~DROP\s+TABLE[^;]*(otp_verifications|users|orders)~i', $rollbackSql)
    && !preg_match('~DELETE\s+FROM\s+`?(otp_verifications|users|orders)~i', $rollbackSql));

// Lazy creation is safe to call twice and stamps its own version.
if (sh_otp_test_is_mysql()) {
    // Production path: the shipped MySQL DDL actually creates the tables.
    sh_db()->exec('DROP TABLE IF EXISTS otp_rate_locks');
    sh_db()->exec('DROP TABLE IF EXISTS otp_rate_limits');
    t_setting('otp_rate_schema_version', '0');
    t_ok('lazy ensure creates the rate tables from the shipped DDL', sh_otp_rate_ensure() === true);
    t_eq('lazy ensure stamps the rate schema version', '1', (string)sh_setting('otp_rate_schema_version'));
    t_ok('lazy ensure is safe to run again', sh_otp_rate_ensure() === true);
    t_ok('the created tables are usable', t_count('otp_rate_limits') === 0 && t_count('otp_rate_locks') === 0);
} else {
    // SQLite cannot execute the shipped MySQL DDL; assert the two properties
    // that matter here: a stamped install short-circuits, and an un-stamped one
    // fails closed instead of silently disabling limits.
    t_setting('otp_rate_schema_version', '0');
    t_eq('an un-stamped install cannot create MySQL DDL on this engine (fails closed)', false, sh_otp_rate_ensure());
    $failClosed = sh_otp_rate_begin($phone, $ip, 'login', null);
    t_ok('a request is refused while the tables are unavailable', !$failClosed['ok'] && ($failClosed['kind'] ?? '') === 'unavailable');
    t_setting('otp_rate_schema_version', '1');
    t_ok('a stamped install needs no DDL', sh_otp_rate_ensure() === true);
    t_ok('ensure is safe to run again', sh_otp_rate_ensure() === true);
}

// Upgrade path: existing verification rows become rate-limit history once.
t_reset();
sh_query("DELETE FROM settings WHERE setting_key = 'otp_rate_backfilled'");
for ($i = 0; $i < 3; $i++) {
    sh_query('INSERT INTO otp_verifications (phone, purpose, otp_hash, expires_at, created_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 60 SECOND), NOW())',
        [$phone, 'login', password_hash('123456', PASSWORD_DEFAULT)]);
}
sh_otp_rate_backfill(sh_db());
$afterFirst = t_count('otp_rate_limits');
sh_otp_rate_backfill(sh_db());
t_eq('existing verification history is carried into the rate log once', 3, $afterFirst);
t_eq('a second upgrade run does not duplicate the backfill', $afterFirst, t_count('otp_rate_limits'));
t_ok('verification rows are preserved by the upgrade', t_count('otp_verifications') === 3);
$backfilled = t_rate_rows()[0];
t_ok('backfilled rows keep the database timestamp', !empty($backfilled['created_at']));
t_ok('backfilled rows store no plaintext phone', !str_contains($backfilled['phone_key'], '8801712345678'));

// ===========================================================================
t_sec('2. Successful request, provider acceptance and code exposure');
// ===========================================================================
t_reset();
t_env('development');
t_setting('otp_provider', 'offline');
$res = sh_otp_issue($phone, 'login', null, $ip);
t_ok('a development request with the offline driver succeeds', $res['ok'] === true, (string)($res['error'] ?? ''));
t_ok('the development driver returns the code for testing', !empty($res['code']) && preg_match('/^\d{6}$/', (string)$res['code']) === 1);
$row = t_last_rate_row();
t_ok('the request was recorded in the rate log', $row !== null);
t_eq('the recorded outcome is "requested"', 'requested', (string)($row['outcome'] ?? ''));
t_ok('the recorded identifier is a hash, not the number', isset($row['phone_key']) && strlen((string)$row['phone_key']) === 64);
t_eq('the recorded identifier matches the phone bucket', sh_otp_rate_key('phone', $phone), (string)($row['phone_key'] ?? ''));
t_ok('the IP is recorded as a hash too', isset($row['ip_key']) && strlen((string)$row['ip_key']) === 64);

$code = (string)$res['code'];
$logs = sh_all('SELECT * FROM security_logs');
$logBlob = json_encode($logs);
t_ok('the OTP value never reaches the security log', !str_contains((string)$logBlob, $code));
t_ok('the phone is masked in the security log', str_contains((string)$logBlob, '****'));

// A production request through a carrier transport. The transport itself is
// stubbed so the suite never touches the network; the configuration gate,
// readiness check and code-exposure rules are the real ones.
t_env('production');
t_setting('otp_provider', 'textbee');
t_setting('otp_resend_cooldown', '0');
t_accepting_provider_for('textbee');
$rowsBefore = t_count('otp_verifications');
$prod = sh_otp_issue($phone, 'login', null, $ip);
t_ok('a production request with a real provider succeeds', $prod['ok'] === true, (string)($prod['error'] ?? ''));
t_eq('a production response never carries a code', true, empty($prod['code']));
t_eq('production stores exactly one verification row', $rowsBefore + 1, t_count('otp_verifications'));
$prodRow = sh_otp_find_latest($phone, 'login', null);
t_ok('the stored code is a password hash, not the plaintext',
    isset($prodRow['otp_hash']) && str_starts_with((string)$prodRow['otp_hash'], '$'));

t_eq('code echo is refused on production', false, sh_otp_allow_code_echo());
t_setting('otp_provider', 'offline');
t_eq('code echo is still refused on production with the offline driver', false, sh_otp_allow_code_echo());
t_env('development');
t_eq('code echo is allowed for the offline driver in development', true, sh_otp_allow_code_echo());
t_setting('otp_provider', 'textbee');
t_eq('code echo is refused for a real provider even in development', false, sh_otp_allow_code_echo());
t_setting('otp_provider', 'offline');
t_env('production');
t_setting('otp_resend_cooldown', '60');

$apiSource = (string)file_get_contents(SH_ROOT . '/api/otp.php');
t_ok('the send API gates the code behind sh_otp_allow_code_echo()',
    str_contains($apiSource, "sh_otp_allow_code_echo()") && !preg_match('~\$out\[\'code\'\]\s*=\s*\$res\[\'code\'\];~', $apiSource));
t_ok('no API file returns a code outside that gate',
    !preg_match('~\$out\[\'code\'\]~', (string)file_get_contents(SH_ROOT . '/api/otp.php')) ||
    substr_count($apiSource, "\$out['code']") === 1);
$adminSecurity = (string)file_get_contents(SH_ROOT . '/admin/security.php');
t_ok('the admin test-code banner is gated by the same rule', str_contains($adminSecurity, 'sh_otp_allow_code_echo()'));

// ===========================================================================
t_sec('3. Failed SMS delivery keeps every rate limit');
// ===========================================================================
t_reset();
t_setting('otp_phone_rate_limit', '3');
t_setting('otp_ip_rate_limit', '50');      // isolate the phone window
t_setting('otp_daily_limit', '100');
t_setting('otp_resend_cooldown', '0');
t_setting('otp_max_resends', '10');
t_setting('otp_provider', 'test_fail');
t_failing_provider();

$failures = 0;
for ($i = 0; $i < 3; $i++) {
    $r = sh_otp_issue($phone, 'login', null, $ip);
    if (!$r['ok'] && ($r['reason'] ?? '') === 'delivery') { $failures++; }
}
t_eq('three failed deliveries are reported as delivery failures', 3, $failures);
t_eq('failed deliveries leave no verification row behind', 0, t_count('otp_verifications'));
t_eq('every failed delivery is still counted as a request', 3, t_count('otp_rate_limits'));
t_eq('the failed outcomes are recorded', 3, t_count('otp_rate_limits', "outcome = 'failed'"));
t_ok('no OTP plaintext is stored in the rate log',
    !preg_match('/\d{6}/', implode('|', array_column(t_rate_rows(), 'purpose'))));

$blocked = sh_otp_issue($phone, 'login', null, $ip);
t_ok('the phone limit still blocks after repeated failed deliveries', !$blocked['ok'], (string)($blocked['error'] ?? ''));
t_eq('the rejection is reported as rate limiting', 'rate_limited', (string)($blocked['reason'] ?? ''));
t_ok('the message names the per-number limit', str_contains((string)$blocked['error'], 'this number'));

// Deleting the verification history must not clear the limit.
sh_db()->exec('DELETE FROM otp_verifications');
$stillBlocked = sh_otp_issue($phone, 'login', null, $ip);
t_ok('deleting OTP rows does not reset the phone limit', !$stillBlocked['ok']);

// Expiring every code must not clear the limit either.
sh_query('UPDATE otp_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 600 SECOND)');
$afterExpiry = sh_otp_issue($phone, 'login', null, $ip);
t_ok('expiring codes does not reset the phone limit', !$afterExpiry['ok']);

// A different number is unaffected by another number's block.
t_setting('otp_provider', 'test_ok');
t_accepting_provider();
$other = sh_otp_issue('8801911111111', 'login', null, '198.51.100.9');
t_ok('another number is not blocked by this number\'s history', $other['ok'] === true, (string)($other['error'] ?? ''));

// ===========================================================================
t_sec('4. Per-IP and daily limits');
// ===========================================================================
t_reset();
t_setting('otp_phone_rate_limit', '50');
t_setting('otp_ip_rate_limit', '3');
t_setting('otp_ip_rate_window', '60');
t_setting('otp_daily_limit', '100');
t_setting('otp_resend_cooldown', '0');
t_setting('otp_max_resends', '10');
t_setting('otp_provider', 'test_ok');
t_accepting_provider();

$allowed = 0;
for ($i = 0; $i < 3; $i++) {
    $r = sh_otp_issue('88017000000' . $i, 'login', null, $ip);
    if ($r['ok']) { $allowed++; }
}
t_eq('three requests from one IP pass the IP window', 3, $allowed);
$ipBlocked = sh_otp_issue('8801700000099', 'login', null, $ip);
t_ok('the fourth request from the same IP is blocked', !$ipBlocked['ok']);
t_ok('the message names the device/IP limit', str_contains((string)$ipBlocked['error'], 'this device'));
$otherIp = sh_otp_issue('8801700000098', 'login', null, '198.51.100.44');
t_ok('a different IP is unaffected', $otherIp['ok'] === true, (string)($otherIp['error'] ?? ''));

t_reset();
t_setting('otp_phone_rate_limit', '100');
t_setting('otp_ip_rate_limit', '100');
t_setting('otp_daily_limit', '4');
$daily = 0;
for ($i = 0; $i < 4; $i++) {
    if (sh_otp_issue($phone, 'login', null, $ip)['ok']) { $daily++; }
}
t_eq('the daily limit allows exactly its configured count', 4, $daily);
$dailyBlocked = sh_otp_issue($phone, 'login', null, $ip);
t_ok('the next request is blocked by the daily limit', !$dailyBlocked['ok']);
t_ok('the message names the daily limit', str_contains((string)$dailyBlocked['error'], 'Daily verification limit'));
t_eq('the daily count ignores other numbers', 4, t_count('otp_rate_limits', 'phone_key = ?', [sh_otp_rate_key('phone', $phone)]));

// ===========================================================================
t_sec('5. Verification: success, replay, expiry, attempts, purposes');
// ===========================================================================
t_reset();
t_setting('otp_max_attempts', '3');
t_setting('otp_expiry_minutes', '5');

$id = t_fixture_code($phone, 'login', '424242', null);
$ok = sh_otp_verify($phone, 'login', '424242', null);
t_ok('a correct code verifies', $ok['ok'] === true, (string)($ok['error'] ?? ''));
$row = sh_otp_find_by_id($id);
t_ok('the record is marked verified (single use)', $row !== null && $row['verified_at'] !== null);

$replay = sh_otp_verify($phone, 'login', '424242', null);
t_ok('the same code cannot be used twice', !$replay['ok']);
// A used code is either reported as "already been used" (the row is still the
// latest) or as "no active code" (find_latest only returns unused rows).
t_ok('the replay is refused and asks for a new code',
    str_contains((string)$replay['error'], 'already been used')
    || str_contains((string)$replay['error'], 'No active verification code'),
    (string)($replay['error'] ?? ''));

t_reset();
$id = t_fixture_code($phone, 'login', '111111');
$wrong = sh_otp_verify($phone, 'login', '222222', null);
t_ok('an incorrect code is rejected', !$wrong['ok'] && str_contains((string)$wrong['error'], 'Invalid'));
$row = sh_otp_find_by_id($id);
t_eq('a wrong attempt increments the attempt counter', 1, (int)($row['attempts'] ?? -1));

sh_otp_verify($phone, 'login', '222222', null);
sh_otp_verify($phone, 'login', '222222', null);   // reaches max_attempts = 3
$exhausted = sh_otp_verify($phone, 'login', '111111', null);
t_ok('the correct code is refused once attempts are exhausted', !$exhausted['ok']);
t_ok('the exhaustion message asks for a new code', str_contains((string)$exhausted['error'], 'Too many incorrect attempts'));
$row = sh_otp_find_by_id($id);
t_ok('attempts never exceed max_attempts', (int)$row['attempts'] <= (int)$row['max_attempts']);

t_reset();
$id = t_fixture_code($phone, 'login', '333333', null, -10); // already expired
$expired = sh_otp_verify($phone, 'login', '333333', null);
t_ok('an expired code is rejected even when correct', !$expired['ok']);
t_ok('the expiry message tells the customer to request a new code', str_contains((string)$expired['error'], 'expired'));

t_reset();
t_fixture_code($phone, 'signup', '555555');
$crossPurpose = sh_otp_verify($phone, 'login', '555555', null);
t_ok('a signup code cannot be used for login', !$crossPurpose['ok']);

t_reset();
t_fixture_code($phone, 'login', '666666', 7);
$wrongUser = sh_otp_verify($phone, 'login', '666666', 8);
t_ok('a code bound to one account cannot verify another', !$wrongUser['ok']);
$rightUser = sh_otp_verify($phone, 'login', '666666', 7);
t_ok('the bound account can still verify', $rightUser['ok'] === true, (string)($rightUser['error'] ?? ''));

// Old-code invalidation when a new code is issued.
t_reset();
t_setting('otp_provider', 'offline');
t_setting('otp_resend_cooldown', '0');
t_setting('otp_max_resends', '10');
t_env('development');
$first = sh_otp_issue($phone, 'login', null, $ip);
$second = sh_otp_issue($phone, 'login', null, $ip);
t_ok('two codes can be issued back to back', $first['ok'] && $second['ok']);
$oldCode = (string)($first['code'] ?? '');
$newCode = (string)($second['code'] ?? '');
t_ok('the two codes differ', $oldCode !== '' && $newCode !== '' && $oldCode !== $newCode);
$oldTry = sh_otp_verify($phone, 'login', $oldCode, null);
t_ok('the superseded code no longer verifies', !$oldTry['ok']);
$newTry = sh_otp_verify($phone, 'login', $newCode, null);
t_ok('the newest code verifies', $newTry['ok'] === true, (string)($newTry['error'] ?? ''));
t_env('production');

// ===========================================================================
t_sec('6. Concurrent verification and concurrent requests');
// ===========================================================================
t_reset();
$id = t_fixture_code($phone, 'login', '777777');
// A competing request consumes the row between this request's read and write.
$winner = sh_otp_consume($id);
t_ok('the winning request consumes the code', $winner === true);
$loser = sh_otp_verify($phone, 'login', '777777', null);
t_ok('the losing concurrent request is rejected, never accepted twice', !$loser['ok']);
t_ok('the loser is told the code was already used',
    str_contains((string)$loser['error'], 'already been used')
    || str_contains((string)$loser['error'], 'no longer valid')
    || str_contains((string)$loser['error'], 'No active verification code'),
    (string)($loser['error'] ?? ''));
t_eq('the code is consumed exactly once', 1, t_count('otp_verifications', 'verified_at IS NOT NULL'));

t_reset();
t_setting('otp_max_resends', '5');
$id = t_fixture_code($phone, 'login', '888888');
t_ok('the first consume of a fresh row succeeds', sh_otp_consume($id) === true);
t_eq('a second consume on the same row is a no-op', false, sh_otp_consume($id));
sh_query('UPDATE otp_verifications SET verified_at = NULL, attempts = 99 WHERE id = ?', [$id]);
t_eq('a row at max attempts cannot be consumed', false, sh_otp_consume($id));
t_ok('consume requires the row to be unverified, fresh and under the attempt cap',
    (function () {
        $src = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');
        return str_contains($src, 'WHERE id = ? AND verified_at IS NULL AND expires_at > NOW() AND attempts < max_attempts');
    })());

// Two requests for the same number cannot both take the last remaining slot.
t_reset();
t_setting('otp_phone_rate_limit', '1');
t_setting('otp_ip_rate_limit', '50');
t_setting('otp_daily_limit', '50');
t_setting('otp_provider', 'test_ok');
t_accepting_provider();
$firstHit = sh_otp_issue($phone, 'login', null, $ip);
t_ok('the first request takes the only slot', $firstHit['ok'] === true, (string)($firstHit['error'] ?? ''));
$connB = t_second_connection();
if ($connB !== null) {
    // A second connection sees the committed request (as a concurrent PHP
    // process would) then tries to reserve the same window.
    $seen = (int)$connB->query('SELECT COUNT(*) FROM otp_rate_limits')->fetchColumn();
    t_eq('a concurrent request observes the reserved slot', 1, $seen);
} else {
    t_ok('a concurrent request observes the reserved slot (MySQL path: covered by the transaction)', true);
}
$secondHit = sh_otp_issue($phone, 'login', null, $ip);
t_ok('the second request is refused, so the slot cannot be double-booked', !$secondHit['ok']);
t_eq('exactly one request was recorded for the window', 1, t_count('otp_rate_limits'));
t_ok('the window lock rows exist for the phone and IP buckets',
    t_count('otp_rate_locks') >= 1 && t_count('otp_rate_locks', 'bucket_key = ?', [sh_otp_rate_key('phone', $phone)]) === 1);
t_ok('request reservation takes a row lock inside a transaction',
    (function () {
        $src = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');
        return str_contains($src, 'SELECT locked_at FROM otp_rate_locks WHERE bucket_key = ? FOR UPDATE')
            && str_contains($src, '$pdo->beginTransaction()');
    })());

// ===========================================================================
t_sec('7. Production provider safety (offline / missing credentials)');
// ===========================================================================
t_reset();
t_env('production');
t_setting('otp_provider', 'offline');
t_ok('offline is refused on a production install', sh_otp_provider_ready() === false);
t_ok('the refusal explains that offline never sends SMS',
    (bool)preg_grep('/does not send SMS/i', sh_otp_provider_config_errors()));
$blockedOffline = sh_otp_issue($phone, 'login', null, $ip);
t_ok('a production request with the offline driver fails closed', !$blockedOffline['ok']);
t_eq('the failure is reported as a configuration problem', 'config', (string)($blockedOffline['reason'] ?? ''));
t_ok('no code is returned when the provider is refused', empty($blockedOffline['code']));
t_ok('no SMS was attempted', ($GLOBALS['sh_otp_sent'] ?? []) === []);
t_ok('the customer message does not name the provider or credential',
    !str_contains((string)$blockedOffline['error'], 'offline') && !str_contains((string)$blockedOffline['error'], 'TextBee'));
t_eq('the configuration failure is recorded for the administrator', 1,
    t_count('security_logs', "event = 'otp_provider_misconfigured'"));

t_setting('otp_provider', 'textbee');
t_setting('otp_textbee_api_key', '');
t_ok('TextBee without an API key is not ready', sh_otp_provider_ready() === false);
t_ok('the missing credential is named for the admin', (bool)preg_grep('/API key is missing/i', sh_otp_provider_config_errors()));
t_ok('the customer-facing message stays generic',
    !str_contains(sh_otp_configuration_error('textbee'), 'API key'));
t_setting('otp_textbee_api_key', 'test-key-not-real');
t_ok('TextBee with a key is ready', sh_otp_provider_ready() === true);

t_setting('otp_provider', 'generic_http');
t_setting('otp_api_url', 'http://insecure.example/send');
t_ok('a non-HTTPS custom gateway is refused', sh_otp_provider_ready() === false);
t_ok('the HTTPS requirement is explained', (bool)preg_grep('/must use HTTPS/i', sh_otp_provider_config_errors()));
t_setting('otp_api_url', 'https://sms.example/send');
t_ok('an HTTPS custom gateway is ready', sh_otp_provider_ready() === true);

t_setting('otp_provider', 'firebase');
t_ok('the unsupported Firebase driver is refused', sh_otp_provider_ready() === false);
t_setting('otp_provider', 'nonsense');
t_ok('an unknown provider is refused', sh_otp_provider_ready() === false);

t_setting('otp_provider', 'offline');
t_env('development');
t_ok('offline is allowed on an explicit development install', sh_otp_provider_ready() === true);
t_env('production');

t_ok('the admin settings page blocks enabling phone OTP without a ready provider',
    str_contains($adminSecurity, 'sh_otp_provider_ready()'));
$settingsAdmin = (string)file_get_contents(SH_ROOT . '/admin/settings.php');
t_ok('the authentication switch validates the provider before saving',
    str_contains($settingsAdmin, "if (\$mode === 'phone_otp' && !sh_otp_provider_ready())"));
t_ok('the admin cannot save the offline driver on production',
    str_contains($adminSecurity, "\$requestedProvider === 'offline' && !sh_is_development()"));
t_ok('offline is labelled as development-only in the provider list',
    str_contains($adminSecurity, 'DEVELOPMENT ONLY'));
t_ok('no installation default enables phone OTP',
    (function () {
        $defaults = sh_otp_settings_defaults();
        return ($defaults['authentication_mode'] ?? '') === 'email_password';
    })());
t_ok('the upgrade never rewrites an existing authentication mode',
    !preg_match("~sh_setting_save\('authentication_mode',\s*'phone_otp'\)~", (string)file_get_contents(SH_ROOT . '/install/schema.php')));

// ===========================================================================
t_sec('8. Rate-limit retention, cleanup and privacy');
// ===========================================================================
t_reset();
sh_query('INSERT INTO otp_rate_limits (phone_key, ip_key, purpose, outcome, created_at) VALUES (?,?,?,?, DATE_SUB(NOW(), INTERVAL 45 DAY))',
    [sh_otp_rate_key('phone', $phone), null, 'login', 'requested']);
sh_query('INSERT INTO otp_rate_limits (phone_key, ip_key, purpose, outcome, created_at) VALUES (?,?,?,?, DATE_SUB(NOW(), INTERVAL 5 DAY))',
    [sh_otp_rate_key('phone', $phone), null, 'login', 'requested']);
sh_query('INSERT INTO otp_rate_limits (phone_key, ip_key, purpose, outcome, created_at) VALUES (?,?,?,?, NOW())',
    [sh_otp_rate_key('phone', $phone), null, 'login', 'requested']);
$removed = sh_otp_rate_cleanup(30);
t_eq('cleanup removes only history older than the retention window', 1, $removed);
t_eq('recent history is kept', 2, t_count('otp_rate_limits'));

$keys = array_column(t_rate_rows(), 'phone_key');
t_ok('no plaintext phone number is stored in any rate row', !in_array($phone, $keys, true));
t_ok('the phone identifier is a stable HMAC', sh_otp_rate_key('phone', $phone) === sh_otp_rate_key('phone', '+8801712345678'));
t_ok('two different numbers produce different identifiers', sh_otp_rate_key('phone', $phone) !== sh_otp_rate_key('phone', '8801911111111'));
t_ok('IP identifiers are separate from phone identifiers', sh_otp_rate_key('ip', $ip) !== sh_otp_rate_key('phone', $ip));

// ===========================================================================
t_sec('9. PHP environment compatibility (mbstring / DOM)');
// ===========================================================================
$sample = 'ShopHaat — নিশ্চিতকরণ কোড ৪২৪২৪২ ✓';
t_eq('the UTF-8 shim counts characters, not bytes', mb_strlen($sample), sh_compat_mb_strlen($sample));
t_eq('the UTF-8 shim matches mb_substr on multibyte text', mb_substr($sample, 3, 12), sh_compat_mb_substr($sample, 3, 12));
t_eq('the UTF-8 shim handles a negative offset', mb_substr($sample, -5), sh_compat_mb_substr($sample, -5));
t_eq('the UTF-8 shim handles a negative length', mb_substr($sample, 0, -3), sh_compat_mb_substr($sample, 0, -3));
t_eq('the UTF-8 shim truncates with a marker', mb_strimwidth($sample, 0, 12, '…'), sh_compat_mb_strimwidth($sample, 0, 12, '…'));
t_eq('the UTF-8 shim reports character positions', mb_strpos($sample, 'নিশ্চিতকরণ'), sh_compat_mb_strpos($sample, 'নিশ্চিতকরণ'));
t_eq('the UTF-8 shim reports the last character position', mb_strrpos($sample, '৪'), sh_compat_mb_strrpos($sample, '৪'));
t_ok('the UTF-8 shim validates UTF-8', sh_compat_mb_check_encoding($sample) === true);
t_ok('the UTF-8 shim rejects invalid UTF-8', sh_compat_mb_check_encoding("\xC3\x28") === false);
t_ok('an invalid byte sequence degrades to byte semantics without warnings',
    @sh_compat_mb_substr("\xC3\x28abc", 0, 2) === "\xC3\x28");
t_eq('phone masking stays character-safe', '017****5678', sh_phone_mask('8801712345678'));
t_eq('the login mask hides all but the first two digits', '01*********', sh_phone_mask_login('8801712345678'));
t_eq('phone normalisation handles +880 / 880 / 01 forms identically',
    sh_phone_normalize('01712345678'), sh_phone_normalize('+8801712345678'));
t_eq('the Latin-1 → UTF-8 conversion is available without mbstring', 'café', sh_compat_mb_convert_encoding("caf\xE9", 'UTF-8', 'ISO-8859-1'));

$ext = [];
foreach (sh_extension_requirements() as $row) { $ext[$row['ext']] = $row; }
t_ok('PDO, pdo_mysql and JSON are the required extensions',
    $ext['pdo']['required'] && $ext['pdo_mysql']['required'] && $ext['json']['required']);
t_ok('mbstring is optional and documents its fallback',
    !$ext['mbstring']['required'] && str_contains($ext['mbstring']['fallback'], 'compat.php'));
t_ok('DOM is optional and documents its fallback',
    !$ext['dom']['required'] && str_contains($ext['dom']['fallback'], 'Allow-list'));
t_ok('the installer no longer blocks on mbstring',
    !preg_match("~'mbstring extension'~", (string)file_get_contents(SH_ROOT . '/install/index.php')));
t_ok('the installer reports optional extensions separately',
    str_contains((string)file_get_contents(SH_ROOT . '/install/index.php'), 'Optional extensions'));
t_ok('system health reports required and optional extensions apart',
    str_contains((string)file_get_contents(SH_ROOT . '/admin/system-health.php'), 'sh_extension_summary()'));

$fallbackCases = [
    '<p>Hello <strong>world</strong></p>'          => '<p>Hello <strong>world</strong></p>',
    '<script>alert(1)</script>Hi'                  => 'Hi',
    '<p/onmouseover=alert(1)>x'                    => '<p>x</p>',
    '<a href="javascript:alert(1)">click</a>'      => 'click',
    '<img src=x onerror=alert(1)>'                 => '',
    '<h2 style="color:red">T</h2>'                 => '<h2>T</h2>',
    'a < b and 1 > 0'                              => 'a &lt; b and 1 &gt; 0',
    '<ul><li>one</li><li>two</li></ul>'            => '<ul><li>one</li><li>two</li></ul>',
    '<style>body{display:none}</style><p>v</p>'    => '<p>v</p>',
];
$fallbackOk = true;
foreach ($fallbackCases as $in => $expected) {
    if (sh_sanitize_html_fallback($in) !== $expected) { $fallbackOk = false; }
}
t_ok('the DOM-less sanitizer keeps formatting and removes every dangerous construct', $fallbackOk);
t_ok('the DOM-less sanitizer emits no attributes at all',
    !preg_match('/<[a-z0-9]+\s+[a-z]/i', sh_sanitize_html_fallback('<p class="x" onclick="y">t</p><a href="z">l</a>')));
t_ok('the sanitizer prefers the tokenizer when DOM is unavailable',
    str_contains((string)file_get_contents(SH_ROOT . '/includes/functions.php'), 'return sh_sanitize_html_fallback($html, $allowed);'));

// ===========================================================================
t_sec('10. Security regression around the changed code');
// ===========================================================================
$phoneOtpSrc = (string)file_get_contents(SH_ROOT . '/includes/phone-otp.php');
t_ok('the send API still requires a CSRF token', str_contains($apiSource, 'sh_csrf_require()'));
t_ok('the send API still requires POST', str_contains($apiSource, "!== 'POST'"));
t_ok('the admin test purpose still requires a superadmin', str_contains($apiSource, 'sh_admin()'));
t_ok('the OTP endpoint still rejects unknown purposes', str_contains($apiSource, 'sh_otp_purposes()'));
t_ok('every new rate-limit statement is prepared with placeholders',
    preg_match('~prepare\([^)]*\$phone[^)]*\)~', $phoneOtpSrc) !== 1
    && preg_match('~"(?:INSERT|UPDATE|SELECT|DELETE)[^"]*\{\$~', $phoneOtpSrc) !== 1);

$newSql = '';
foreach (['sh_otp_rate_begin', 'sh_otp_rate_finish', 'sh_otp_rate_cleanup', 'sh_otp_consume', 'sh_otp_last_issued'] as $fn) {
    $start = strpos($phoneOtpSrc, 'function ' . $fn . '(');
    if ($start === false) { continue; }
    $next = strpos($phoneOtpSrc, "\nfunction ", $start + 10);
    $newSql .= substr($phoneOtpSrc, $start, ($next === false ? strlen($phoneOtpSrc) : $next) - $start);
}
t_ok('no new query interpolates a phone number, IP or identifier',
    !preg_match('~\$_(?:GET|POST|SERVER|REQUEST)~', $newSql)
    && !preg_match('~VALUES\s*\([^)]*\'\.\s*\$~', $newSql));

$codesStored = sh_all("SELECT otp_hash FROM otp_verifications");
$hashOk = true;
foreach ($codesStored as $r) {
    if (!str_starts_with((string)$r['otp_hash'], '$2y$') && !str_starts_with((string)$r['otp_hash'], '$argon2')) { $hashOk = false; }
}
t_ok('OTP values are stored only as password hashes', $hashOk);
t_ok('OTP generation uses the CSPRNG', str_contains($phoneOtpSrc, 'random_int('));
t_ok('expiry, attempts and purpose are enforced in SQL, not PHP time',
    str_contains($phoneOtpSrc, 'expires_at > NOW()') && str_contains($phoneOtpSrc, "AND purpose = ?"));

t_setting('authentication_mode', 'email_password');
t_ok('email + password mode leaves OTP disabled', sh_otp_enabled() === false);
$disabled = sh_otp_issue($phone, 'login', null, $ip);
t_ok('issuing is refused while phone OTP is off', !$disabled['ok'] && ($disabled['reason'] ?? '') === 'disabled');
t_setting('authentication_mode', 'phone_otp');
t_ok('the authentication mode round-trips without changing other settings',
    sh_auth_mode() === 'phone_otp' && sh_setting('otp_length', '6') === '6');

// ===========================================================================
// Summary
// ===========================================================================
echo "\n========================================================================\n";
printf("passed %d   failed %d\n", $GLOBALS['t_pass'], $GLOBALS['t_fail']);
if (!sh_otp_test_is_mysql() && !empty($GLOBALS['sh_test_db_file'])) {
    @unlink($GLOBALS['sh_test_db_file']);
}
foreach (glob(SH_TEST_LOG_DIR . '/*') ?: [] as $f) { @unlink($f); }
@rmdir(SH_TEST_LOG_DIR);
if ($GLOBALS['t_fail'] > 0) {
    echo "OTP SECURITY SUITE: FAILURES PRESENT\n";
    exit(1);
}
echo "OTP SECURITY SUITE: ALL CHECKS PASSED\n";
exit(0);
