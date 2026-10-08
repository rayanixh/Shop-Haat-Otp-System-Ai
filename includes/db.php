<?php
/**
 * PDO connection + query helpers. All queries use prepared statements.
 */

function sh_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $cfg = sh_db_config();
    if ($cfg === null) {
        throw new RuntimeException('Database configuration missing.');
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The pdo_mysql PHP extension is not enabled on this server.');
    }
    $pdo = sh_db_connect($cfg['host'], $cfg['name'], $cfg['user'], $cfg['pass'], (int)$cfg['port'], $cfg['charset']);
    return $pdo;
}

/** Raw connector, also used by the installer to test credentials. */
function sh_db_connect(string $host, ?string $dbname, string $user, string $pass, int $port = 3306, string $charset = 'utf8mb4'): PDO
{
    // cPanel users often paste "localhost:3306" or a socket path.
    if (strpos($host, ':') !== false) {
        [$h, $p] = explode(':', $host, 2);
        $host = $h;
        if (ctype_digit($p)) { $port = (int)$p; }
    }
    $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    if ($dbname !== null && $dbname !== '') { $dsn .= ";dbname={$dbname}"; }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
}

/** Execute a prepared statement with safe logging on failure. */
function sh_query(string $sql, array $params = []): PDOStatement
{
    try {
        $st = sh_db()->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (PDOException $e) {
        sh_log_line('sql', sprintf(
            'Query failed [%s]: %s | sql=%s',
            $e->getCode(),
            $e->getMessage(),
            preg_replace('/\s+/', ' ', $sql)
        ));
        throw new RuntimeException('Database query failed.', 0, $e);
    }
}

function sh_all(string $sql, array $params = []): array
{
    return sh_query($sql, $params)->fetchAll();
}

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
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES ($ph)";
    sh_query($sql, array_values($data));
    return (int)sh_db()->lastInsertId();
}

function sh_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) { $set[] = "`$c` = ?"; }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where;
    return sh_query($sql, array_merge(array_values($data), $whereParams))->rowCount();
}
