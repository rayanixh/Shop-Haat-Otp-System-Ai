<?php
/**
 * ShopHaat - Core bootstrap
 * Loaded by every public/admin page. Must never emit a blank page.
 */

if (defined('SH_BOOTSTRAPPED')) { return; }
define('SH_BOOTSTRAPPED', true);

define('SH_ROOT', dirname(__DIR__));
define('SH_CONFIG_DIR', SH_ROOT . '/config');
define('SH_LOG_DIR', SH_ROOT . '/logs');
define('SH_UPLOAD_DIR', SH_ROOT . '/uploads');
define('SH_DB_CONFIG_FILE', SH_CONFIG_DIR . '/database.php');
define('SH_LOCK_FILE', SH_CONFIG_DIR . '/installed.lock');

// Production posture: never print raw errors to the browser.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!is_dir(SH_LOG_DIR) && !mkdir(SH_LOG_DIR, 0755, true) && !is_dir(SH_LOG_DIR)) {
    error_log('ShopHaat: could not create log directory ' . SH_LOG_DIR);
}
ini_set('error_log', SH_LOG_DIR . '/php-error.log');

require_once SH_ROOT . '/includes/errors.php';
sh_register_error_handlers();

// ---------------------------------------------------------------------------
// Base URL detection (works in subfolders on shared hosting)
// ---------------------------------------------------------------------------
function sh_base_url(): string
{
    static $base = null;
    if ($base !== null) { return $base; }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    // Pages inside /admin, /api or /install resolve up to the project root.
    // Trailing segments are stripped repeatedly so nested folders such as
    // /admin/ai also resolve correctly rather than becoming the base URL.
    $known = ['admin', 'api', 'install', 'ai', 'auth', 'google'];
    $parts = array_values(array_filter(explode('/', $dir), static fn($p) => $p !== ''));
    while ($parts !== [] && in_array(end($parts), $known, true)) {
        array_pop($parts);
    }
    $dir = $parts === [] ? '' : '/' . implode('/', $parts);
    $dir = rtrim($dir, '/');
    $base = ($dir === '' || $dir === '.') ? '' : $dir;
    return $base;
}

function sh_url(string $path = ''): string
{
    return sh_base_url() . '/' . ltrim($path, '/');
}

function sh_site_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host . sh_base_url();
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------
function sh_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    if (headers_sent()) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Keep the server-side session data alive at least as long as the 24-hour
    // customer login session, so sign-in is not cut short by the host's PHP
    // session garbage collector.
    @ini_set('session.gc_maxlifetime', '86400');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => sh_base_url() . '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('SHSESSID');
    session_start();
}

// ---------------------------------------------------------------------------
// Installation state
// ---------------------------------------------------------------------------
function sh_db_config(): ?array
{
    static $cfg = null;
    static $loaded = false;
    if ($loaded) { return $cfg; }
    $loaded = true;
    if (!is_file(SH_DB_CONFIG_FILE)) { return null; }
    $data = require SH_DB_CONFIG_FILE;
    if (!is_array($data)) { return null; }
    foreach (['host', 'name', 'user'] as $k) {
        if (!array_key_exists($k, $data) || $data[$k] === '') { return null; }
    }
    $data += ['pass' => '', 'port' => 3306, 'charset' => 'utf8mb4'];
    $cfg = $data;
    return $cfg;
}

function sh_is_locked(): bool
{
    return is_file(SH_LOCK_FILE);
}

/**
 * Guard used by every front-end/admin page.
 * Sends the visitor to the installer or to a clean error page as needed.
 */
/**
 * Classifies why the database is unreachable, so the caller can react correctly.
 *
 * Returns one of:
 *   'ok'          connected and the schema is present
 *   'no-config'   config/database.php is missing or incomplete  -> install
 *   'no-server'   the MySQL server itself cannot be reached     -> retry page
 *   'no-database' the server is up but the named database is gone/denied -> install
 *   'no-tables'   the database exists but our tables are missing -> repair
 */
function sh_db_state(): array
{
    static $state = null;
    if ($state !== null) { return $state; }

    $cfg = sh_db_config();
    if ($cfg === null) {
        return $state = ['status' => 'no-config', 'error' => 'Database configuration missing.'];
    }
    if (!extension_loaded('pdo_mysql')) {
        return $state = ['status' => 'no-server', 'error' => 'The pdo_mysql PHP extension is not enabled.'];
    }

    // First try the configured database directly.
    try {
        $pdo = sh_db();
        return $state = ['status' => sh_tables_installed($pdo) ? 'ok' : 'no-tables', 'error' => null];
    } catch (Throwable $e) {
        $first = $e;
    }

    // It failed. Determine whether the SERVER is down or just the DATABASE is absent
    // by connecting without selecting a schema.
    try {
        sh_db_connect($cfg['host'], null, $cfg['user'], $cfg['pass'], (int)$cfg['port'], $cfg['charset']);
        // Server reachable => the specific database is missing or access is denied.
        sh_log_line('database', 'Database "' . $cfg['name'] . '" unavailable: ' . $first->getMessage());
        return $state = ['status' => 'no-database', 'error' => $first->getMessage()];
    } catch (Throwable $e2) {
        sh_log_line('database', 'Database server unreachable: ' . $e2->getMessage());
        return $state = ['status' => 'no-server', 'error' => $e2->getMessage()];
    }
}

/**
 * Gate used by every public page.
 *
 * Missing configuration, a missing database or missing tables all send the visitor
 * to the installer so the store can be set up or repaired. A fully installed store
 * never reaches the installer. Only a genuinely unreachable database server produces
 * an error page, because that is the one case an installer cannot fix.
 */
function sh_require_installed(): void
{
    $state = sh_db_state();

    switch ($state['status']) {
        case 'ok':
            // Self-healing migration for features added after the original install
            // (phone verification columns + tables). No-op once stamped.
            sh_otp_schema_ensure();
            return;

        case 'no-config':
            header('Location: ' . sh_url('install/index.php'));
            exit;

        case 'no-database':
            // The database was dropped or credentials no longer grant access:
            // let the operator recreate it through the installer.
            header('Location: ' . sh_url('install/index.php?step=repair&reason=database'));
            exit;

        case 'no-tables':
            header('Location: ' . sh_url('install/index.php?step=repair&reason=tables'));
            exit;

        case 'no-server':
        default:
            sh_render_error_page(
                503,
                'Database unavailable',
                'The store cannot reach its database server right now. '
                . 'If you have just moved hosting or changed credentials, run the installer to reconfigure the connection.',
                sh_url('install/index.php?step=repair&reason=server')
            );
    }
}

function sh_tables_installed(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        $cfg = sh_db_config();
        $need = ['users', 'admins', 'products', 'orders', 'settings', 'payment_methods'];
        $in = implode(',', array_fill(0, count($need), '?'));
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = ? AND table_name IN ($in)"
        );
        $st->execute(array_merge([$cfg['name']], $need));
        $ok = ((int)$st->fetchColumn() === count($need));
    } catch (Throwable $e) {
        sh_log_exception($e, 'table-check');
        $ok = false;
    }
    return $ok;
}

require_once SH_ROOT . '/includes/db.php';
require_once SH_ROOT . '/includes/functions.php';
require_once SH_ROOT . '/includes/csrf.php';
require_once SH_ROOT . '/includes/validation.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/phone-otp.php';
require_once SH_ROOT . '/includes/google-auth.php';
require_once SH_ROOT . '/includes/theme.php';
