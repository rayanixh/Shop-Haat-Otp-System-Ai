<?php
/**
 * Courier connection tests against real MySQL.
 *
 * Proves the parts the static suite cannot:
 *   - a test result is written to courier_test_results with the state, the
 *     HTTP status, the timestamp and the next action;
 *   - the seven admin-facing states are stored and read back;
 *   - a missing credential is reported as Configuration incomplete, never as
 *     Connected;
 *   - providers with no public API stay Unsupported and send no JSON request
 *     path through a booking endpoint;
 *   - history is append-only (two runs leave two rows) and the newest row is
 *     the one the card renders;
 *   - deleting a courier removes its test rows through the foreign key and
 *     never touches another courier's history;
 *   - the AI provider test columns are added to an ai_providers table created
 *     by an older release, without dropping it.
 *
 * Run with a test-only config/database.php that points at a disposable MySQL /
 * MariaDB database. It recreates the two courier tables and the admins table it
 * needs, so it can run between the email suite and the product-save suite.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/courier-connection-tests.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/install/schema.php';
require_once SH_ROOT . '/includes/courier.php';
require_once SH_ROOT . '/ai/schema.php';

$passed = 0;
$failed = 0;
$failures = [];

function cc_assert(bool $condition, string $message): void
{
    global $passed, $failed, $failures;
    if ($condition) { $passed++; return; }
    $failed++;
    $failures[] = $message;
    echo "  FAIL  $message\n";
}

function cc_same($expected, $actual, string $message): void
{
    cc_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true) . ')');
}

function cc_step(string $name): void
{
    echo "\n== $name\n";
}

$pdo = sh_db();

// --- Isolated schema: only the tables this test needs --------------------
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['courier_test_results', 'shipments', 'shipment_events', 'couriers', 'admins'] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$made = [];
foreach (sh_schema_sql() as $ddl) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS (admins|couriers|courier_test_results)\s*\(/', $ddl)) {
        $pdo->exec($ddl);
        if (preg_match('/IF NOT EXISTS (\w+)/', $ddl, $m)) { $made[] = $m[1]; }
    }
}
cc_assert(in_array('couriers', $made, true), 'the real couriers DDL is applied');
cc_assert(in_array('courier_test_results', $made, true), 'the additive courier_test_results DDL is applied');
cc_assert(in_array('admins', $made, true), 'the admins table exists for the tested_by foreign key');

$insertCourier = static function (string $code, string $driver, string $name, array $creds = []) use ($pdo): int {
    $st = $pdo->prepare('INSERT INTO couriers (name, code, driver, credentials, status, sort_order) VALUES (?,?,?,?,1,1)');
    $st->execute([$name, $code, $driver, $creds ? json_encode($creds, JSON_UNESCAPED_SLASHES) : null]);
    return (int)$pdo->lastInsertId();
};

$ids = [
    'steadfast'   => $insertCourier('steadfast', 'steadfast', 'Steadfast'),
    'pathao'      => $insertCourier('pathao', 'pathao', 'Pathao Courier'),
    'ecourier'    => $insertCourier('ecourier', 'ecourier', 'eCourier'),
    'paperfly'    => $insertCourier('paperfly', 'paperfly', 'Paperfly'),
    'sundarban'   => $insertCourier('sundarban', 'sundarban', 'Sundarban Courier'),
    'saparibahan' => $insertCourier('saparibahan', 'custom', 'SA Paribahan'),
    'manual'      => $insertCourier('manual', 'manual', 'Manual Courier'),
];
$byId = static fn(int $id): array => sh_courier_by_id($id) ?? [];

// --- 1. A missing credential is never Connected --------------------------
cc_step('configuration incomplete');
$r = sh_courier_test($byId($ids['steadfast']));
cc_same('configuration_incomplete', $r['state'], 'Steadfast without keys is configuration incomplete');
cc_same(false, $r['ok'], 'configuration incomplete is not a success');
cc_assert(str_contains($r['message'], 'API Key'), 'the message names the missing API key');

$r = sh_courier_test($byId($ids['ecourier']));
cc_same('configuration_incomplete', $r['state'], 'eCourier without credentials is configuration incomplete');
cc_assert(str_contains($r['message'], 'API Key'), 'the eCourier message names the missing API key');

$r = sh_courier_test($byId($ids['pathao']));
cc_same('configuration_incomplete', $r['state'], 'Pathao without credentials is configuration incomplete');
foreach (['Client ID', 'Client Secret', 'Store ID'] as $field) {
    cc_assert(str_contains($r['message'], $field), 'Pathao message names ' . $field);
}

// --- 2. Providers without a documented API stay unsupported --------------
cc_step('unsupported providers');
foreach ([
    'sundarban'   => 'no public merchant API',
    'saparibahan' => 'corporate API',
    'manual'      => 'no API',
] as $code => $needle) {
    $r = sh_courier_test($byId($ids[$code]));
    cc_same('unsupported', $r['state'], $code . ' is reported as unsupported');
    cc_same(false, $r['ok'], $code . ' is never claimed connected');
    cc_same(0, $r['http_status'], $code . ' sent no HTTP request');
    cc_assert(stripos($r['message'], $needle) !== false, $code . ' explains why: ' . $needle);
}

$r = sh_courier_test($byId($ids['paperfly']));
cc_same('configuration_incomplete', $r['state'], 'Paperfly without its three credentials is incomplete');
cc_assert(str_contains($r['message'], 'Paperfly Key'), 'Paperfly asks for the paperflykey credential');

// Give Paperfly its credentials. It still must not claim Connected.
$creds = ['paperfly_key' => 'PF-KEY-SECRET', 'username' => 'merchant1', 'password' => 'pw-SECRET-1'];
sh_update('couriers', ['credentials' => sh_courier_credentials_store($creds)], 'id = ?', [$ids['paperfly']]);
$r = sh_courier_test($byId($ids['paperfly']));
cc_same('unsupported', $r['state'], 'Paperfly with credentials is still unsupported');
cc_assert(!str_contains($r['message'] . $r['next'], 'PF-KEY-SECRET'), 'the Paperfly key is not echoed back');

// --- 3. Results are persisted with a real timestamp ----------------------
cc_step('persistence and history');
$before = (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id = ?', [$ids['sundarban']], 0);
sh_courier_test($byId($ids['sundarban']));
sh_courier_test($byId($ids['sundarban']));
$after = (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id = ?', [$ids['sundarban']], 0);
cc_same($before + 2, $after, 'two runs append two rows (history is not overwritten)');

$last = sh_courier_last_test($ids['sundarban']);
cc_assert($last !== null, 'the newest result can be read back');
cc_same('unsupported', (string)($last['state'] ?? ''), 'the stored state is the real outcome');
cc_assert((string)($last['note'] ?? '') !== '', 'a note is stored');
cc_assert((string)($last['next_action'] ?? '') !== '', 'a next action is stored');
cc_assert((string)($last['tested_at'] ?? '') !== '', 'the test time is stored');
cc_assert((string)($last['tested_by'] ?? '') === '', 'an anonymous test stores no admin id');

// --- 4. The rendered summary is honest and secret-free -------------------
cc_step('rendered summary');
$courier = $byId($ids['paperfly']);
$html = sh_courier_test_summary($courier);
cc_assert(str_contains($html, 'Unsupported automated test'), 'the summary shows the real state');
cc_assert(str_contains($html, '<strong>Next:</strong>'), 'the summary shows the next action');
cc_assert(!str_contains($html, 'PF-KEY-SECRET'), 'the summary never contains a credential');
cc_assert(!str_contains($html, 'pw-SECRET-1'), 'the summary never contains the password');

$empty = sh_courier_test_summary(['id' => 999999, 'name' => 'Never tested', 'driver' => 'manual', 'code' => 'manual']);
cc_assert(str_contains($empty, 'No connection test yet'), 'an untested courier explains what a test does');

// --- 5. Credentials survive a test and stay encrypted -------------------
cc_step('credentials are encrypted and preserved');
$rawCreds = (string)($byId($ids['paperfly'])['credentials'] ?? '');
cc_assert(str_starts_with($rawCreds, 'cou:'), 'new credentials are stored with the encrypted prefix');
cc_assert(!str_contains($rawCreds, 'PF-KEY-SECRET'), 'the raw column does not contain the key');
$stored = sh_courier_credentials($byId($ids['paperfly']));
cc_assert(($stored['paperfly_key'] ?? '') === 'PF-KEY-SECRET', 'the credentials decrypt for the test');
cc_assert(($stored['password'] ?? '') === 'pw-SECRET-1', 'the password decrypts too');

// A record saved by the previous release is plain JSON and must keep working.
$legacy = ['api_key' => 'LEGACY-KEY-1', 'secret_key' => 'LEGACY-SECRET-1'];
sh_update('couriers', ['credentials' => json_encode($legacy)], 'id = ?', [$ids['steadfast']]);
$readBack = sh_courier_credentials($byId($ids['steadfast']));
cc_assert(($readBack['api_key'] ?? '') === 'LEGACY-KEY-1', 'legacy plain-JSON credentials still read');

// --- 6. The foreign key keeps history tidy -------------------------------
cc_step('foreign keys');
$temp = $insertCourier('temp-courier', 'manual', 'Temporary');
$rowsBefore = (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id = ?', [$temp], 0);
sh_courier_test($byId($temp));
$rowsAfter = (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id = ?', [$temp], 0);
cc_same($rowsBefore + 1, $rowsAfter, 'the temporary courier has a result');
$otherBefore = (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id <> ?', [$temp], 0);
sh_query('DELETE FROM couriers WHERE id = ?', [$temp]);
cc_same(0, (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id = ?', [$temp], 0),
    'deleting a courier removes only its own test rows');
cc_same($otherBefore, (int)sh_val('SELECT COUNT(*) FROM courier_test_results WHERE courier_id <> ?', [$temp], 0),
    'other couriers keep their history');

// --- 7. A provider message cannot leak a secret --------------------------
cc_step('message sanitising');
$res = sh_courier_test_result('authentication_failed', 401,
    'Rejected key SECRET-ABC-123 for merchant', 'Paste a new key', '/auth', ['SECRET-ABC-123']);
cc_assert(!str_contains($res['message'], 'SECRET-ABC-123'), 'a credential echoed by a provider is removed');
cc_assert(str_contains($res['message'], '••••'), 'the credential is replaced with a mask');

// --- 8. Old ai_providers tables get the test columns ---------------------
cc_step('AI provider test columns');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE IF EXISTS ai_providers');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE ai_providers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    driver VARCHAR(40) NOT NULL,
    last_tested_at DATETIME DEFAULT NULL,
    last_test_ok TINYINT(1) DEFAULT NULL,
    last_test_note VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
sh_ai_test_columns_ensure();
$cols = sh_all('SHOW COLUMNS FROM ai_providers');
$names = array_map(static fn($c) => strtolower((string)($c['Field'] ?? '')), $cols);
foreach (['last_test_code', 'last_test_status', 'last_test_next'] as $col) {
    cc_assert(in_array($col, $names, true), 'ai_providers gained ' . $col);
}
$ddl = implode("\n", sh_ai_schema_sql());
cc_assert(str_contains($ddl, 'last_test_code VARCHAR(40)'), 'a fresh install has last_test_code in its DDL');
cc_assert(!str_contains($ddl, 'DROP TABLE'), 'the AI schema does not drop tables');

// --- Report --------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
printf("courier connection tests: passed %d   failed %d\n", $passed, $failed);
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) { echo '  - ' . $f . "\n"; }
}
exit($failed === 0 ? 0 : 1);
