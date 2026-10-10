<?php
/**
 * Customer Verification: courier delivery history (per phone, via configurable
 * courier verification providers), order IP capture and approximate IP
 * geolocation. Admin-only risk/verification aid — never a judgement.
 *
 * Tables (created lazily, non-destructive):
 *   verify_providers      configurable courier history APIs
 *   verify_courier_cache  cached per phone + provider results
 *   verify_ip_cache       cached approximate IP information
 *   verify_orders         per-order manual verification status
 * Columns added to orders: order_ip, order_ip_version.
 */

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------
function sh_verify_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        if (sh_setting('verify_schema_v', '') === '2') { return; } // already migrated
        $pdo = sh_db();
        // CREATE/ALTER/DROP implicitly commit in MySQL/MariaDB. A lazy schema
        // upgrade must never interrupt an order/payment transaction.
        if ($pdo->inTransaction()) {
            sh_log_line('verify-schema', 'Deferred verification schema migration because a transaction is active.');
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS verify_providers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(80) NOT NULL,
            code VARCHAR(40) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            base_url VARCHAR(255) NOT NULL DEFAULT '',
            endpoint VARCHAR(255) NOT NULL DEFAULT '',
            http_method VARCHAR(8) NOT NULL DEFAULT 'GET',
            auth_type VARCHAR(20) NOT NULL DEFAULT 'header',
            auth_name VARCHAR(80) NOT NULL DEFAULT 'Api-Key',
            api_key TEXT NULL,
            api_secret TEXT NULL,
            secret_name VARCHAR(80) NOT NULL DEFAULT '',
            extra_headers TEXT NULL,
            phone_param VARCHAR(60) NOT NULL DEFAULT 'phone',
            phone_format VARCHAR(10) NOT NULL DEFAULT 'local',
            body_type VARCHAR(10) NOT NULL DEFAULT 'query',
            map_total VARCHAR(120) NOT NULL DEFAULT '',
            map_delivered VARCHAR(120) NOT NULL DEFAULT '',
            map_returned VARCHAR(120) NOT NULL DEFAULT '',
            map_cancelled VARCHAR(120) NOT NULL DEFAULT '',
            map_list VARCHAR(120) NOT NULL DEFAULT '',
            map_list_status VARCHAR(60) NOT NULL DEFAULT 'status',
            status_delivered TEXT NULL,
            status_returned TEXT NULL,
            status_cancelled TEXT NULL,
            timeout_sec TINYINT UNSIGNED NOT NULL DEFAULT 8,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_vp_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS verify_courier_cache (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(20) NOT NULL,
            provider_id INT UNSIGNED NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'error',
            total INT UNSIGNED NOT NULL DEFAULT 0,
            delivered INT UNSIGNED NOT NULL DEFAULT 0,
            returned INT UNSIGNED NOT NULL DEFAULT 0,
            cancelled INT UNSIGNED NOT NULL DEFAULT 0,
            success_rate DECIMAL(5,1) DEFAULT NULL,
            error VARCHAR(255) DEFAULT NULL,
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_vcc (phone, provider_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS verify_ip_cache (
            ip VARCHAR(45) NOT NULL,
            ip_version TINYINT UNSIGNED NOT NULL DEFAULT 4,
            status VARCHAR(12) NOT NULL DEFAULT 'error',
            country VARCHAR(80) DEFAULT NULL,
            country_code VARCHAR(4) DEFAULT NULL,
            region VARCHAR(120) DEFAULT NULL,
            city VARCHAR(120) DEFAULT NULL,
            error VARCHAR(255) DEFAULT NULL,
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS verify_orders (
            order_id INT UNSIGNED NOT NULL,
            manual_status VARCHAR(12) NOT NULL DEFAULT '',
            manual_note VARCHAR(255) DEFAULT NULL,
            manual_admin_id INT UNSIGNED DEFAULT NULL,
            manual_at DATETIME DEFAULT NULL,
            PRIMARY KEY (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['order_ip' => 'VARCHAR(45) DEFAULT NULL', 'order_ip_version' => 'TINYINT UNSIGNED DEFAULT NULL'] as $col => $def) {
            $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['orders', $col], 0);
            if ($n === 0) { $pdo->exec("ALTER TABLE orders ADD COLUMN `$col` $def"); }
        }
        // v2: network details (ISP / organisation / ASN / timezone) are no longer stored.
        foreach (['isp', 'org', 'asn', 'timezone'] as $col) {
            $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['verify_ip_cache', $col], 0);
            if ($n > 0) { $pdo->exec("ALTER TABLE verify_ip_cache DROP COLUMN `$col`"); }
        }
        sh_setting_save('verify_schema_v', '2');
    } catch (Throwable $e) {
        sh_log_exception($e, 'verify-schema');
    }
}

// ---------------------------------------------------------------------------
// Secrets (encrypted at rest with a key derived from the DB credentials)
// ---------------------------------------------------------------------------
function sh_verify_secret_key(): string
{
    $cfg = function_exists('sh_db_config') ? (sh_db_config() ?? []) : [];
    return hash('sha256', 'shophaat-verify|' . ($cfg['name'] ?? '') . '|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''), true);
}
function sh_verify_encrypt(string $plain): string
{
    if ($plain === '') { return ''; }
    if (!function_exists('openssl_encrypt')) { return 'plain:' . $plain; }
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', sh_verify_secret_key(), OPENSSL_RAW_DATA, $iv);
    return $ct === false ? 'plain:' . $plain : 'enc:' . base64_encode($iv . $ct);
}
function sh_verify_decrypt(?string $stored): string
{
    $stored = trim((string)$stored);
    if ($stored === '') { return ''; }
    if (str_starts_with($stored, 'plain:')) { return substr($stored, 6); }
    if (!str_starts_with($stored, 'enc:')) { return $stored; }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) <= 16 || !function_exists('openssl_decrypt')) { return ''; }
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', sh_verify_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}
function sh_verify_mask(string $secret): string
{
    $n = strlen($secret);
    if ($n === 0) { return ''; }
    return $n <= 6 ? str_repeat('•', $n) : substr($secret, 0, 3) . str_repeat('•', 6) . substr($secret, -3);
}

// ---------------------------------------------------------------------------
// Client IP (trusted-proxy aware)
// ---------------------------------------------------------------------------
/**
 * Determine the real client IP. Modes (setting verify_proxy_mode):
 *   auto       – Cloudflare header when present, otherwise REMOTE_ADDR (default)
 *   none       – REMOTE_ADDR only
 *   cloudflare – CF-Connecting-IP only when REMOTE_ADDR is a trusted proxy
 *                (or always, when no proxy list is configured)
 *   xff        – X-Forwarded-For, only when REMOTE_ADDR is a listed trusted proxy;
 *                the right-most address not belonging to a trusted proxy wins.
 */
function sh_verify_client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $mode = 'auto';
    $trusted = [];
    try {
        $mode = (string)sh_setting('verify_proxy_mode', 'auto');
        $trusted = preg_split('/[\s,]+/', (string)sh_setting('verify_trusted_proxies', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } catch (Throwable $e) { /* settings not available yet */ }

    $isTrusted = static function (string $ip) use ($trusted): bool {
        foreach ($trusted as $t) { if (sh_verify_ip_in_cidr($ip, $t)) { return true; } }
        return false;
    };
    $valid = static fn(string $ip): bool => (bool)filter_var($ip, FILTER_VALIDATE_IP);

    if ($mode === 'cloudflare' || $mode === 'auto') {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && $valid($cf) && (!$trusted || $isTrusted($remote))) { return $cf; }
    }
    if ($mode === 'xff' && $trusted && $isTrusted($remote)) {
        $chain = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = $chain[$i];
            if ($ip !== '' && $valid($ip) && !$isTrusted($ip)) { return $ip; }
        }
    }
    return $valid($remote) ? $remote : '0.0.0.0';
}

function sh_verify_ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($cidr, '/') === false) { return $ip === $cidr; }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip); $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) { return false; }
    $bits = (int)$bits; $len = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $len) { return false; }
    $full = intdiv($bits, 8); $rem = $bits % 8;
    if ($full > 0 && substr($ipBin, 0, $full) !== substr($subBin, 0, $full)) { return false; }
    if ($rem === 0) { return true; }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$full]) & $mask) === (ord($subBin[$full]) & $mask);
}

function sh_verify_ip_version(string $ip): int
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { return 6; }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 0;
}

function sh_verify_ip_is_public(string $ip): bool
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// ---------------------------------------------------------------------------
// Courier verification providers
// ---------------------------------------------------------------------------
function sh_verify_providers(bool $enabledOnly = false): array
{
    sh_verify_schema_ensure();
    try {
        return sh_all('SELECT * FROM verify_providers' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY name');
    } catch (Throwable $e) { return []; }
}
function sh_verify_provider(int $id): ?array
{
    sh_verify_schema_ensure();
    return $id > 0 ? sh_one('SELECT * FROM verify_providers WHERE id = ?', [$id]) : null;
}

/** Form presets: a starting point only; the admin can edit every field. */
function sh_verify_provider_presets(): array
{
    return [
        'generic' => ['name' => '', 'base_url' => '', 'endpoint' => '', 'http_method' => 'GET', 'auth_type' => 'header', 'auth_name' => 'Api-Key',
            'phone_param' => 'phone', 'phone_format' => 'local', 'body_type' => 'query',
            'map_total' => 'data.total', 'map_delivered' => 'data.delivered', 'map_returned' => 'data.returned', 'map_cancelled' => 'data.cancelled',
            'map_list' => '', 'map_list_status' => 'status',
            'status_delivered' => 'delivered, delivered successfully, received, completed, success',
            'status_returned' => 'returned, return, returned to merchant, delivery failed, customer refused, failed',
            'status_cancelled' => 'cancelled, canceled'],
        'list' => ['name' => '', 'base_url' => '', 'endpoint' => '', 'http_method' => 'GET', 'auth_type' => 'bearer', 'auth_name' => 'Authorization',
            'phone_param' => 'phone', 'phone_format' => 'local', 'body_type' => 'query',
            'map_total' => '', 'map_delivered' => '', 'map_returned' => '', 'map_cancelled' => '',
            'map_list' => 'data', 'map_list_status' => 'status',
            'status_delivered' => 'delivered, delivered successfully, received, completed',
            'status_returned' => 'returned, return, returned to merchant, delivery failed, customer refused',
            'status_cancelled' => 'cancelled, canceled'],
    ];
}

/** Phone in the format a provider expects. */
function sh_verify_phone_format(string $canonical, string $format): string
{
    if (!preg_match('/^880(1[3-9]\d{8})$/', $canonical, $m)) { return $canonical; }
    switch ($format) {
        case 'intl': return '880' . $m[1];
        case 'plus': return '+880' . $m[1];
        default:     return '0' . $m[1];
    }
}

/** Read a dotted path ("data.summary.total" / "data.0.count") out of a decoded response. */
function sh_verify_pluck(array $data, string $path)
{
    $path = trim($path);
    if ($path === '') { return null; }
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; continue; }
        return null;
    }
    return $cur;
}

function sh_verify_status_set(?string $csv): array
{
    $out = [];
    foreach (preg_split('/[,\n]+/', (string)$csv) ?: [] as $s) {
        $s = strtolower(trim($s));
        if ($s !== '') { $out[$s] = true; }
    }
    return $out;
}

/** Perform one provider request for one canonical phone. Returns a cache-shaped row. */
function sh_verify_provider_query(array $p, string $canonical): array
{
    $row = ['status' => 'error', 'total' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'success_rate' => null, 'error' => null];
    $base = rtrim((string)$p['base_url'], '/');
    $endpoint = (string)$p['endpoint'];
    if ($base === '' && !preg_match('~^https?://~i', $endpoint)) { $row['error'] = 'Provider URL is not configured.'; return $row; }
    $phone = sh_verify_phone_format($canonical, (string)$p['phone_format']);
    $url = preg_match('~^https?://~i', $endpoint) ? $endpoint : $base . '/' . ltrim($endpoint, '/');
    $url = str_replace(['{phone}', '%7Bphone%7D'], rawurlencode($phone), $url);
    $inUrl = strpos((string)$p['endpoint'], '{phone}') !== false;

    $headers = ['Accept' => 'application/json'];
    $extra = json_decode((string)($p['extra_headers'] ?? ''), true);
    if (is_array($extra)) { foreach ($extra as $k => $v) { if (is_scalar($v)) { $headers[(string)$k] = (string)$v; } } }
    $key = sh_verify_decrypt($p['api_key'] ?? '');
    $secret = sh_verify_decrypt($p['api_secret'] ?? '');
    $params = [];
    switch ((string)$p['auth_type']) {
        case 'bearer': if ($key !== '') { $headers['Authorization'] = 'Bearer ' . $key; } break;
        case 'basic':  if ($key !== '') { $headers['Authorization'] = 'Basic ' . base64_encode($key . ':' . $secret); } break;
        case 'query':  if ($key !== '') { $params[(string)($p['auth_name'] ?: 'api_key')] = $key; } break;
        case 'none':   break;
        default:       if ($key !== '') { $headers[(string)($p['auth_name'] ?: 'Api-Key')] = $key; }
                       if ($secret !== '' && (string)$p['secret_name'] !== '') { $headers[(string)$p['secret_name']] = $secret; }
    }
    if (!$inUrl) { $params[(string)($p['phone_param'] ?: 'phone')] = $phone; }

    $method = strtoupper((string)$p['http_method']) === 'POST' ? 'POST' : 'GET';
    $json = null;
    if ($method === 'GET' || (string)$p['body_type'] === 'query') {
        if ($params) { $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params); }
    } elseif ((string)$p['body_type'] === 'form') {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $json = null;
        $http = sh_verify_http($method, $url, $headers, http_build_query($params), (int)$p['timeout_sec']);
        return sh_verify_parse_response($p, $http, $row);
    } else {
        $json = $params;
    }
    $http = sh_verify_http($method, $url, $headers, $json === null ? null : json_encode($json), (int)$p['timeout_sec'], $json !== null);
    return sh_verify_parse_response($p, $http, $row);
}

function sh_verify_http(string $method, string $url, array $headers, ?string $body, int $timeout, bool $jsonBody = false): array
{
    $timeout = max(3, min(20, $timeout ?: 8));
    $hdr = [];
    if ($jsonBody) { $headers['Content-Type'] = 'application/json'; }
    foreach ($headers as $k => $v) { $hdr[] = $k . ': ' . $v; }
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $hdr), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
        set_error_handler(static fn(): bool => true);
        try { $raw = file_get_contents($url, false, $ctx); } finally { restore_error_handler(); }
        if ($raw === false) { return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'Connection failed']; }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) { if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; } }
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { return ['ok' => false, 'status' => 0, 'body' => [], 'error' => $err ?: 'Connection failed']; }
    }
    $decoded = json_decode((string)$raw, true);
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'error' => null];
}

function sh_verify_parse_response(array $p, array $http, array $row): array
{
    if (!$http['ok']) {
        $msg = (string)(($http['body']['message'] ?? '') ?: ($http['error'] ?? ''));
        $row['error'] = $msg !== '' ? mb_substr($msg, 0, 200) : ('HTTP ' . (int)$http['status']);
        if ((int)$http['status'] === 404) { $row['status'] = 'empty'; $row['error'] = null; }
        return $row;
    }
    $body = $http['body'];
    if (!$body) { $row['error'] = 'Empty or non-JSON response.'; return $row; }

    $delivered = $returned = $cancelled = $total = null;
    if ((string)$p['map_list'] !== '') {
        $list = sh_verify_pluck($body, (string)$p['map_list']);
        if (!is_array($list)) { $row['status'] = 'empty'; return $row; }
        $d = sh_verify_status_set($p['status_delivered']); $r = sh_verify_status_set($p['status_returned']); $c = sh_verify_status_set($p['status_cancelled']);
        $delivered = $returned = $cancelled = 0; $total = 0;
        foreach ($list as $item) {
            if (!is_array($item)) { continue; }
            $total++;
            $st = strtolower(trim((string)(sh_verify_pluck($item, (string)($p['map_list_status'] ?: 'status')) ?? '')));
            if (isset($d[$st])) { $delivered++; } elseif (isset($r[$st])) { $returned++; } elseif (isset($c[$st])) { $cancelled++; }
        }
    } else {
        $num = static function ($v): ?int {
            if (is_numeric($v)) { return (int)$v; }
            if (is_array($v)) { return count($v); }
            return null;
        };
        $delivered = $num(sh_verify_pluck($body, (string)$p['map_delivered']));
        $returned  = $num(sh_verify_pluck($body, (string)$p['map_returned']));
        $cancelled = $num(sh_verify_pluck($body, (string)$p['map_cancelled']));
        $total     = $num(sh_verify_pluck($body, (string)$p['map_total']));
        if ($delivered === null && $returned === null && $total === null) {
            $row['error'] = 'Response did not contain the mapped fields.';
            return $row;
        }
    }
    $delivered = (int)$delivered; $returned = (int)$returned; $cancelled = (int)$cancelled;
    $total = $total === null ? $delivered + $returned + $cancelled : (int)$total;
    $row['status'] = ($total + $delivered + $returned) > 0 ? 'ok' : 'empty';
    $row['total'] = $total; $row['delivered'] = $delivered; $row['returned'] = $returned; $row['cancelled'] = $cancelled;
    $row['success_rate'] = ($delivered + $returned) > 0 ? round($delivered / ($delivered + $returned) * 100, 1) : null;
    return $row;
}

// ---------------------------------------------------------------------------
// Courier history: cache + aggregation
// ---------------------------------------------------------------------------
function sh_verify_courier_cache_ttl(): int
{
    return max(1, (int)sh_setting('verify_courier_ttl_hours', '24')) * 3600;
}

/** Cached rows for a canonical phone, keyed by provider id. */
function sh_verify_courier_cached(string $canonical): array
{
    sh_verify_schema_ensure();
    if ($canonical === '') { return []; }
    $out = [];
    try {
        foreach (sh_all('SELECT * FROM verify_courier_cache WHERE phone = ?', [$canonical]) as $r) { $out[(int)$r['provider_id']] = $r; }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * Query every enabled provider for a phone (respecting the cache unless
 * $force). Each provider is independent: one failure never hides another.
 */
function sh_verify_courier_check(string $phone, bool $force = false): array
{
    $canonical = sh_phone_normalize($phone);
    $providers = sh_verify_providers(true);
    if ($canonical === '' || !$providers) { return sh_verify_courier_summary($canonical, $providers); }
    $cached = sh_verify_courier_cached($canonical);
    $ttl = sh_verify_courier_cache_ttl();
    foreach ($providers as $p) {
        $c = $cached[(int)$p['id']] ?? null;
        $fresh = $c && (time() - strtotime((string)$c['checked_at'])) < $ttl && $c['status'] !== 'error';
        if ($fresh && !$force) { continue; }
        $r = sh_verify_provider_query($p, $canonical);
        try {
            sh_query('INSERT INTO verify_courier_cache (phone, provider_id, status, total, delivered, returned, cancelled, success_rate, error, checked_at)
                      VALUES (?,?,?,?,?,?,?,?,?,NOW())
                      ON DUPLICATE KEY UPDATE status=VALUES(status), total=VALUES(total), delivered=VALUES(delivered), returned=VALUES(returned),
                      cancelled=VALUES(cancelled), success_rate=VALUES(success_rate), error=VALUES(error), checked_at=NOW()',
                [$canonical, (int)$p['id'], $r['status'], $r['total'], $r['delivered'], $r['returned'], $r['cancelled'], $r['success_rate'], $r['error']]);
        } catch (Throwable $e) { sh_log_exception($e, 'verify-cache'); }
    }
    return sh_verify_courier_summary($canonical, $providers);
}

/** Aggregate cached provider rows into one summary (no API calls). */
function sh_verify_courier_summary(string $canonical, ?array $providers = null): array
{
    $providers = $providers ?? sh_verify_providers(true);
    $cached = $canonical !== '' ? sh_verify_courier_cached($canonical) : [];
    $sum = ['phone' => $canonical, 'configured' => (bool)$providers, 'checked' => false, 'pending' => false,
        'total' => 0, 'delivered' => 0, 'returned' => 0, 'cancelled' => 0, 'success_rate' => null,
        'providers' => [], 'ok_count' => 0, 'error_count' => 0, 'last_checked' => null];
    foreach ($providers as $p) {
        $c = $cached[(int)$p['id']] ?? null;
        $entry = ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'status' => $c['status'] ?? 'pending',
            'total' => (int)($c['total'] ?? 0), 'delivered' => (int)($c['delivered'] ?? 0), 'returned' => (int)($c['returned'] ?? 0),
            'cancelled' => (int)($c['cancelled'] ?? 0), 'error' => $c['error'] ?? null, 'checked_at' => $c['checked_at'] ?? null];
        if ($c === null) { $sum['pending'] = true; }
        else {
            $sum['checked'] = true;
            if ($sum['last_checked'] === null || $c['checked_at'] > $sum['last_checked']) { $sum['last_checked'] = $c['checked_at']; }
            if ($c['status'] === 'ok') {
                $sum['ok_count']++;
                $sum['total'] += (int)$c['total']; $sum['delivered'] += (int)$c['delivered'];
                $sum['returned'] += (int)$c['returned']; $sum['cancelled'] += (int)$c['cancelled'];
            } elseif ($c['status'] === 'error') { $sum['error_count']++; }
            else { $sum['ok_count']++; } // "empty" is a valid answer: no records at that courier
        }
        $sum['providers'][] = $entry;
    }
    $den = $sum['delivered'] + $sum['returned'];
    $sum['success_rate'] = $den > 0 ? round($sum['delivered'] / $den * 100, 1) : null;
    $sum['risk'] = sh_verify_risk($sum['delivered'], $sum['returned'], $sum['checked'] && $sum['ok_count'] > 0);
    return $sum;
}

/** Risk level from delivered/returned counts. Needs >= 3 decided parcels. */
function sh_verify_risk(int $delivered, int $returned, bool $hasData): array
{
    $den = $delivered + $returned;
    if (!$hasData || $den < 3) { return ['key' => 'insufficient', 'label' => 'Insufficient data', 'class' => 'sh-badge--muted']; }
    $rate = $delivered / $den * 100;
    if ($rate >= 90) { return ['key' => 'low', 'label' => 'Low risk', 'class' => 'sh-badge--ok']; }
    if ($rate >= 75) { return ['key' => 'medium', 'label' => 'Medium risk', 'class' => 'sh-badge--warn']; }
    if ($rate >= 50) { return ['key' => 'high', 'label' => 'High risk', 'class' => 'sh-badge--bad']; }
    return ['key' => 'very_high', 'label' => 'Very high risk', 'class' => 'sh-badge--bad'];
}

/** Cheap risk pills for a list of orders (one query, cache only). */
function sh_verify_risk_for_orders(array $orders): array
{
    sh_verify_schema_ensure();
    $out = [];
    $phones = [];
    foreach ($orders as $o) {
        $c = sh_phone_normalize((string)($o['customer_phone'] ?? ''));
        if ($c !== '') { $phones[$c] = true; }
    }
    if (!$phones || !sh_verify_providers(true)) { return $out; }
    try {
        $in = implode(',', array_fill(0, count($phones), '?'));
        $rows = sh_all("SELECT phone, status, delivered, returned FROM verify_courier_cache WHERE phone IN ($in)", array_keys($phones));
    } catch (Throwable $e) { return $out; }
    $agg = [];
    foreach ($rows as $r) {
        $a = &$agg[$r['phone']]; $a = $a ?? ['d' => 0, 'r' => 0, 'ok' => false];
        if ($r['status'] !== 'error') { $a['ok'] = true; }
        if ($r['status'] === 'ok') { $a['d'] += (int)$r['delivered']; $a['r'] += (int)$r['returned']; }
        unset($a);
    }
    foreach ($orders as $o) {
        $c = sh_phone_normalize((string)($o['customer_phone'] ?? ''));
        if ($c === '' || !isset($agg[$c])) { continue; }
        $out[(int)$o['id']] = sh_verify_risk($agg[$c]['d'], $agg[$c]['r'], $agg[$c]['ok']);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// IP geolocation (server-side, cached)
// ---------------------------------------------------------------------------
function sh_verify_ip_providers(): array
{
    return [
        'ipwhois' => ['name' => 'ipwho.is (free, no key)', 'key' => false],
        'ipapi'   => ['name' => 'ip-api.com (free tier, no key)', 'key' => false],
        'ipinfo'  => ['name' => 'ipinfo.io (token)', 'key' => true],
        'ipapico' => ['name' => 'ipapi.co (optional key)', 'key' => true],
        'none'    => ['name' => 'Disabled', 'key' => false],
    ];
}

function sh_verify_ip_cached(string $ip): ?array
{
    sh_verify_schema_ensure();
    if ($ip === '') { return null; }
    try { return sh_one('SELECT * FROM verify_ip_cache WHERE ip = ?', [$ip]); } catch (Throwable $e) { return null; }
}

function sh_verify_ip_lookup(string $ip, bool $force = false): ?array
{
    if ($ip === '' || sh_verify_ip_version($ip) === 0) { return null; }
    $cached = sh_verify_ip_cached($ip);
    $ttl = max(1, (int)sh_setting('verify_ip_ttl_days', '30')) * 86400;
    if ($cached && !$force && $cached['status'] !== 'error' && (time() - strtotime((string)$cached['checked_at'])) < $ttl) { return $cached; }

    $provider = (string)sh_setting('verify_ip_provider', 'ipwhois');
    $row = ['ip' => $ip, 'ip_version' => sh_verify_ip_version($ip), 'status' => 'error', 'country' => null, 'country_code' => null,
        'region' => null, 'city' => null, 'error' => null];
    if ($provider === 'none') { $row['status'] = 'disabled'; }
    elseif (!sh_verify_ip_is_public($ip)) { $row['status'] = 'private'; $row['error'] = 'Private or local network address.'; }
    else {
        $key = sh_verify_decrypt((string)sh_setting('verify_ip_key', ''));
        $r = sh_verify_ip_fetch($provider, $ip, $key);
        $row = array_merge($row, $r);
    }
    try {
        sh_query('INSERT INTO verify_ip_cache (ip, ip_version, status, country, country_code, region, city, error, checked_at)
                  VALUES (?,?,?,?,?,?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE ip_version=VALUES(ip_version), status=VALUES(status), country=VALUES(country), country_code=VALUES(country_code),
                  region=VALUES(region), city=VALUES(city), error=VALUES(error), checked_at=NOW()',
            [$ip, $row['ip_version'], $row['status'], $row['country'], $row['country_code'], $row['region'], $row['city'], $row['error']]);
    } catch (Throwable $e) { sh_log_exception($e, 'verify-ip-cache'); }
    return sh_verify_ip_cached($ip) ?? $row;
}

function sh_verify_ip_fetch(string $provider, string $ip, string $key): array
{
    $s = static fn($v): ?string => (is_scalar($v) && (string)$v !== '') ? mb_substr((string)$v, 0, 150) : null;
    $out = ['status' => 'error', 'error' => null];
    switch ($provider) {
        case 'ipapi':
            $h = sh_verify_http('GET', 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,message,country,countryCode,regionName,city', [], null, 6);
            $b = $h['body'];
            if (!$h['ok'] || ($b['status'] ?? '') !== 'success') { $out['error'] = $s($b['message'] ?? $h['error']) ?? 'Lookup failed'; return $out; }
            return ['status' => 'ok', 'country' => $s($b['country'] ?? null), 'country_code' => $s($b['countryCode'] ?? null), 'region' => $s($b['regionName'] ?? null), 'city' => $s($b['city'] ?? null)];
        case 'ipinfo':
            $url = 'https://ipinfo.io/' . rawurlencode($ip) . '/json' . ($key !== '' ? '?token=' . rawurlencode($key) : '');
            $h = sh_verify_http('GET', $url, [], null, 6);
            $b = $h['body'];
            if (!$h['ok'] || empty($b) || isset($b['error'])) { $out['error'] = $s($b['error']['message'] ?? $h['error']) ?? 'Lookup failed'; return $out; }
            return ['status' => 'ok', 'country' => $s($b['country'] ?? null), 'country_code' => $s($b['country'] ?? null), 'region' => $s($b['region'] ?? null), 'city' => $s($b['city'] ?? null)];
        case 'ipapico':
            $url = 'https://ipapi.co/' . rawurlencode($ip) . '/json/' . ($key !== '' ? '?key=' . rawurlencode($key) : '');
            $h = sh_verify_http('GET', $url, ['User-Agent' => 'ShopHaat/1.0'], null, 6);
            $b = $h['body'];
            if (!$h['ok'] || !empty($b['error'])) { $out['error'] = $s($b['reason'] ?? $h['error']) ?? 'Lookup failed'; return $out; }
            return ['status' => 'ok', 'country' => $s($b['country_name'] ?? null), 'country_code' => $s($b['country_code'] ?? null), 'region' => $s($b['region'] ?? null), 'city' => $s($b['city'] ?? null)];
        default: // ipwhois
            $h = sh_verify_http('GET', 'https://ipwho.is/' . rawurlencode($ip), [], null, 6);
            $b = $h['body'];
            if (!$h['ok'] || empty($b['success'])) { $out['error'] = $s($b['message'] ?? $h['error']) ?? 'Lookup failed'; return $out; }
            return ['status' => 'ok', 'country' => $s($b['country'] ?? null), 'country_code' => $s($b['country_code'] ?? null), 'region' => $s($b['region'] ?? null), 'city' => $s($b['city'] ?? null)];
    }
}

function sh_verify_ip_location_text(?array $geo): string
{
    if (!$geo || ($geo['status'] ?? '') !== 'ok') { return ''; }
    $parts = array_values(array_filter([$geo['city'] ?? null, $geo['region'] ?? null, $geo['country'] ?? null], static fn($v) => $v !== null && $v !== ''));
    // Avoid "Dhaka, Dhaka, Bangladesh".
    if (count($parts) >= 2 && strcasecmp((string)$parts[0], (string)$parts[1]) === 0) { array_splice($parts, 1, 1); }
    return implode(', ', $parts);
}

// ---------------------------------------------------------------------------
// Order helpers
// ---------------------------------------------------------------------------
/**
 * Values to store with a new order. Schema preparation happens before the
 * checkout transaction; MySQL/MariaDB schema DDL must never run after that
 * transaction starts because DDL performs an implicit commit.
 */
function sh_verify_order_ip_fields(): array
{
    sh_verify_schema_ensure();
    // Verification is optional. If an older/locked-down installation cannot
    // add these columns, do not break or partially commit a customer order.
    if (!sh_table_has_column('orders', 'order_ip') || !sh_table_has_column('orders', 'order_ip_version')) {
        return [];
    }
    $ip = sh_verify_client_ip();
    $v = sh_verify_ip_version($ip);
    return $v === 0 ? [] : ['order_ip' => $ip, 'order_ip_version' => $v];
}

/** Previous order IPs for the same customer (phone variants or user id), excluding this order. */
function sh_verify_previous_ips(array $order, int $limit = 6): array
{
    sh_verify_schema_ensure();
    $variants = sh_phone_variants((string)$order['customer_phone']);
    $where = []; $params = [];
    if ($variants) { $where[] = 'customer_phone IN (' . implode(',', array_fill(0, count($variants), '?')) . ')'; $params = $variants; }
    if (!empty($order['user_id'])) { $where[] = 'user_id = ?'; $params[] = (int)$order['user_id']; }
    if (!$where) { return []; }
    $params[] = (int)$order['id'];
    try {
        $rows = sh_all('SELECT order_number, order_ip, created_at FROM orders WHERE (' . implode(' OR ', $where) . ') AND id <> ? AND order_ip IS NOT NULL
                        ORDER BY created_at DESC LIMIT ' . (int)$limit, $params);
    } catch (Throwable $e) { return []; }
    return $rows;
}

function sh_verify_manual(int $orderId): ?array
{
    sh_verify_schema_ensure();
    try { return sh_one('SELECT * FROM verify_orders WHERE order_id = ?', [$orderId]); } catch (Throwable $e) { return null; }
}
function sh_verify_manual_set(int $orderId, string $status, int $adminId, string $note = ''): void
{
    sh_verify_schema_ensure();
    if (!in_array($status, ['', 'verified', 'unable'], true)) { return; }
    sh_query('INSERT INTO verify_orders (order_id, manual_status, manual_note, manual_admin_id, manual_at) VALUES (?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE manual_status=VALUES(manual_status), manual_note=VALUES(manual_note), manual_admin_id=VALUES(manual_admin_id), manual_at=NOW()',
        [$orderId, $status, mb_substr($note, 0, 255) ?: null, $adminId]);
}

/** Everything the admin "Customer Verification" card needs (cache only unless $live). */
function sh_verify_order_bundle(array $order, bool $live = false, bool $forceCourier = false, bool $forceIp = false): array
{
    sh_verify_schema_ensure();
    $phone = (string)$order['customer_phone'];
    $courier = $live ? sh_verify_courier_check($phone, $forceCourier) : sh_verify_courier_summary(sh_phone_normalize($phone));
    $ip = (string)($order['order_ip'] ?? '');
    $geo = null;
    if ($ip !== '') {
        $geo = $live ? sh_verify_ip_lookup($ip, $forceIp) : sh_verify_ip_cached($ip);
    }
    return ['courier' => $courier, 'ip' => $ip, 'ip_version' => (int)($order['order_ip_version'] ?? 0) ?: sh_verify_ip_version($ip), 'geo' => $geo,
        'ip_configured' => sh_setting('verify_ip_provider', 'ipwhois') !== 'none',
        'previous_ips' => sh_verify_previous_ips($order), 'manual' => sh_verify_manual((int)$order['id'])];
}
