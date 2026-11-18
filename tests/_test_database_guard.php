<?php
/** Shared, destructive-test-only database preflight. Never used by the website. */
declare(strict_types=1);

// Application requests intentionally render a generic HTML error page for an
// uncaught exception. A test process must instead fail visibly and non-zero;
// keep this handler test-local so production error handling is unchanged.
set_exception_handler(static function (Throwable $e): void {
    $location = basename($e->getFile()) . ':' . $e->getLine();
    fwrite(STDERR, 'FAIL uncaught test exception (' . get_class($e) . ') at ' . $location . "\n");
    exit(1);
});

if (!function_exists('sh_test_require_disposable_mysql')) {
    /**
     * Connect and prove the active database is the named disposable MySQL target
     * before a suite mutates fixtures. Refusal/blocking exits non-zero so CI can
     * never turn missing config or an Error 500 into a green test run.
     */
    function sh_test_require_disposable_mysql(string $suite): PDO
    {
        $blocked = static function (string $reason) use ($suite): never {
            fwrite(STDERR, "BLOCKED {$suite}: {$reason}\n");
            exit(2);
        };
        $refused = static function (string $reason) use ($suite): never {
            fwrite(STDERR, "BLOCKED {$suite}: destructive safety refusal: {$reason}\n");
            exit(3);
        };

        if (!function_exists('sh_db_config') || !function_exists('sh_db')) {
            $blocked('application database bootstrap is not loaded');
        }
        $cfg = sh_db_config();
        if (!is_array($cfg) || trim((string)($cfg['name'] ?? '')) === '') {
            $blocked('database configuration is missing or incomplete');
        }
        $name = trim((string)$cfg['name']);
        if (preg_match('/(?:^|[_-])(?:test|testing|ci|scratch|disposable)(?:$|[_-])/i', $name) !== 1
            || preg_match('/(?:^|[_-])(?:prod|production|live|master|main)(?:$|[_-])/i', $name) === 1) {
            $refused('configured database name is not unambiguously disposable');
        }
        if (getenv('SH_TEST_ALLOW_DESTRUCTIVE') !== '1') {
            $refused('set SH_TEST_ALLOW_DESTRUCTIVE=1 only for a disposable test database');
        }
        if (!extension_loaded('pdo_mysql')) {
            $blocked('pdo_mysql is unavailable; this suite requires real MySQL/MariaDB');
        }

        try {
            $pdo = sh_db();
            $actualName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable $e) {
            // Do not print exception messages: PDO can include connection details.
            $blocked('connection preflight failed; no database mutations were started');
        }
        if ($actualName === '' || !hash_equals($name, $actualName)) {
            $refused('connected database name does not match the configured disposable database');
        }
        $engine = stripos($version, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        echo "PASS database preflight {$suite}: {$engine} " . strtok($version, ' ') . " on disposable database {$actualName}\n";
        return $pdo;
    }
}
