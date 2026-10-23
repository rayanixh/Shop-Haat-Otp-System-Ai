<?php
/**
 * Courier & parcel management (admin-only).
 *
 * A self-contained shipping layer that mirrors the payment-gateway architecture:
 *   - a courier registry with a modular `driver` per courier,
 *   - parcel ("shipment") records linked to orders, each with a status timeline,
 *   - parcel status changes that auto-sync the order status, and
 *   - optional real courier API clients. Only Steadfast ships with a working
 *     server-side client; the other drivers are registered but refuse to act
 *     until their official API is wired in with real credentials — the site
 *     never fabricates a booking or a tracking result.
 *
 * Requires config.php (SH_BOOTSTRAPPED) to be loaded first.
 */

require_once __DIR__ . '/payment.php';   // sh_order_get(), sh_order_notify_payload(), sh_notify()
require_once SH_ROOT . '/install/schema.php';

// ---------------------------------------------------------------------------
// Schema (created lazily on existing installs; the installer does it up front)
// ---------------------------------------------------------------------------
function sh_courier_schema_ensure(): void
{
    try {
        $pdo = sh_db();
        foreach (sh_courier_schema_sql() as $ddl) { $pdo->exec($ddl); }
        // Courier accounts are sample content, so they are only ever created by
        // an install that chose Demo Data = ON (see sh_install_seed_all()).
        // A store installed before this marker existed gets the built-in list
        // once during its upgrade, so an existing admin screen does not suddenly
        // show an empty registry. After that the marker stops this from running
        // again, and a demo-OFF store is never filled by a page view.
        $marked = (int)sh_val("SELECT COUNT(*) FROM settings WHERE setting_key = 'courier_registry_state'", [], 0);
        if ($marked === 0) {
            if ((int)sh_val('SELECT COUNT(*) FROM couriers', [], 0) === 0) {
                sh_courier_seed($pdo);
            }
            sh_courier_state_save($pdo, (int)sh_val('SELECT COUNT(*) FROM couriers', [], 0) > 0 ? 'installed' : 'none');
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'courier-schema');
    }
}

// ---------------------------------------------------------------------------
// Courier registry
// ---------------------------------------------------------------------------
function sh_courier_drivers(): array
{
    return [
        'manual'     => 'Manual (no API)',
        'steadfast'  => 'Steadfast',
        'pathao'     => 'Pathao Courier',
        'redx'       => 'RedX',
        'ecourier'   => 'eCourier',
        'paperfly'   => 'Paperfly',
        'sundarban'  => 'Sundarban Courier',
        'custom'     => 'Custom / other',
    ];
}

/** Drivers with a real server-side API client implemented. */
function sh_courier_api_drivers(): array
{
    return ['steadfast'];
}

/**
 * Drivers that have a provider-specific, read-only connection test.
 *
 * This list is deliberately separate from sh_courier_api_drivers(): a passing
 * connection test must never enable booking for a driver that has no booking
 * client, and it must never be reported as "API ready" for shipments.
 */
function sh_courier_testable_drivers(): array
{
    return ['steadfast', 'pathao', 'ecourier', 'paperfly', 'redx'];
}

/**
 * Built-in providers shown as static cards in Admin → Couriers. Official brand
 * logos are bundled locally in assets/images/couriers (no hot-linking).
 */
function sh_courier_providers(): array
{
    return [
        'steadfast'   => ['name' => 'Steadfast',         'driver' => 'steadfast', 'logo' => 'steadfast.png',   'site' => 'https://steadfast.com.bd',            'tracking' => 'https://steadfast.com.bd/t/{tracking}'],
        'pathao'      => ['name' => 'Pathao Courier',    'driver' => 'pathao',    'logo' => 'pathao.png',      'site' => 'https://merchant.pathao.com',         'tracking' => 'https://merchant.pathao.com/tracking?consignment_id={tracking}'],
        'redx'        => ['name' => 'RedX',              'driver' => 'redx',      'logo' => 'redx.png',        'site' => 'https://redx.com.bd',                 'tracking' => 'https://redx.com.bd/track-parcel/?trackingId={tracking}'],
        'ecourier'    => ['name' => 'eCourier',          'driver' => 'ecourier',  'logo' => 'ecourier.png',    'site' => 'https://ecourier.com.bd',             'tracking' => 'https://ecourier.com.bd/tracking?ecr={tracking}'],
        'paperfly'    => ['name' => 'Paperfly',          'driver' => 'paperfly',  'logo' => 'paperfly.png',    'site' => 'https://paperfly.com.bd',             'tracking' => 'https://paperfly.com.bd/tracking?id={tracking}'],
        'sundarban'   => ['name' => 'Sundarban Courier', 'driver' => 'sundarban', 'logo' => 'sundarban.png',   'site' => 'https://www.sundarbancourierltd.com', 'tracking' => 'https://www.sundarbancourierltd.com/track?cn={tracking}'],
        'saparibahan' => ['name' => 'SA Paribahan',      'driver' => 'custom',    'logo' => 'saparibahan.png', 'site' => 'https://saparibahan.com',             'tracking' => ''],
    ];
}

/** Local official logo URL for a courier row (bundled asset first, then uploaded file). */
function sh_courier_logo_url(array $courier): string
{
    $prov = sh_courier_providers()[$courier['code'] ?? ''] ?? null;
    if ($prov !== null && is_file(SH_ROOT . '/assets/images/couriers/' . $prov['logo'])) {
        return sh_asset('assets/images/couriers/' . $prov['logo']);
    }
    return sh_logo_image($courier['logo'] ?? null);
}

/**
 * Guarantees every built-in provider has a row (disabled by default), so the
 * admin always sees all seven cards even on installs seeded before a provider
 * was added. Existing rows and credentials are never modified.
 */
function sh_courier_providers_ensure(): void
{
    try {
        $st = sh_db()->prepare('INSERT IGNORE INTO couriers (name, code, driver, tracking_url, status, sort_order) VALUES (?,?,?,?,0,?)');
        $i = 1;
        foreach (sh_courier_providers() as $code => $p) {
            $st->execute([$p['name'], $code, $p['driver'], $p['tracking'] !== '' ? $p['tracking'] : null, $i++]);
        }
    } catch (Throwable $e) { sh_log_exception($e, 'courier-providers'); }
}

function sh_couriers(bool $activeOnly = false): array
{
    try {
        return sh_all(
            'SELECT * FROM couriers'
            . ($activeOnly ? ' WHERE status = 1' : '')
            . ' ORDER BY sort_order ASC, id ASC'
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'couriers');
        return [];
    }
}

function sh_courier_by_id(int $id): ?array
{
    return sh_one('SELECT * FROM couriers WHERE id = ? LIMIT 1', [$id]);
}

function sh_courier_by_code(string $code): ?array
{
    return sh_one('SELECT * FROM couriers WHERE code = ? LIMIT 1', [$code]);
}

/**
 * Encryption key for courier credentials. Derived from the database
 * credentials, which live outside the web root and are never committed.
 */
function sh_courier_secret_key(): string
{
    $cfg = sh_db_config() ?? [];
    return hash('sha256', 'shophaat-courier|' . ($cfg['name'] ?? '') . '|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''), true);
}

/**
 * Encrypt the credential JSON for storage. Returns null for an empty set so
 * the column stays NULL instead of an encrypted empty object.
 *
 * Falls back to “plain:” only when OpenSSL is unavailable, which the system
 * health screen already reports. The prefix makes the fallback explicit.
 */
function sh_courier_credentials_store(array $creds): ?string
{
    if (!$creds) { return null; }
    $json = json_encode($creds, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') { return null; }
    if (!function_exists('openssl_encrypt')) { return 'plain:' . $json; }
    $iv = random_bytes(16);
    $ct = openssl_encrypt($json, 'aes-256-cbc', sh_courier_secret_key(), OPENSSL_RAW_DATA, $iv);
    return $ct === false ? 'plain:' . $json : 'cou:' . base64_encode($iv . $ct);
}

/** Read credentials from any stored form: encrypted, legacy plain JSON or plain:. */
function sh_courier_credentials(array $courier): array
{
    $raw = (string)($courier['credentials'] ?? '');
    if ($raw === '') { return []; }
    if (str_starts_with($raw, 'cou:')) {
        $bin = base64_decode(substr($raw, 4), true);
        if ($bin === false || strlen($bin) <= 16 || !function_exists('openssl_decrypt')) { return []; }
        $pt = openssl_decrypt(substr($bin, 16), 'aes-256-cbc', sh_courier_secret_key(), OPENSSL_RAW_DATA, substr($bin, 0, 16));
        $raw = $pt === false ? '' : $pt;
    } elseif (str_starts_with($raw, 'plain:')) {
        $raw = substr($raw, 6);
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Required credential fields per driver (all must be present to be "configured"). */
function sh_courier_fields(string $driver): array
{
    return match ($driver) {
        'steadfast' => ['api_key' => 'API Key', 'secret_key' => 'Secret Key'],
        // Pathao's official token flow is a password grant: the client pair
        // plus the merchant username/password, then a store id (docs:
        // POST /aladdin/api/v1/issue-token, then GET /aladdin/api/v1/stores).
        'pathao'    => ['client_id' => 'Client ID', 'client_secret' => 'Client Secret',
                        'username' => 'Merchant Username', 'password' => 'Merchant Password',
                        'store_id' => 'Store ID'],
        'redx'      => ['api_access_token' => 'API Access Token'],
        'ecourier'  => ['api_key' => 'API Key', 'api_secret' => 'API Secret', 'user_id' => 'User ID'],
        'paperfly'  => ['paperfly_key' => 'Paperfly Key', 'username' => 'Merchant Username', 'password' => 'Merchant Password'],
        'sundarban' => ['api_key' => 'API Key'],
        default     => [],
    };
}

/** Optional credential fields per driver (do not block "configured" status). */
function sh_courier_optional_fields(string $driver): array
{
    return match ($driver) {
        'steadfast' => ['base_url' => 'API base URL (optional)'],
        'pathao'    => ['base_url' => 'API base URL (optional)'],
        'redx'      => ['base_url' => 'API base URL (optional)'],
        'ecourier'  => ['base_url' => 'API base URL (optional)'],
        'paperfly'  => ['base_url' => 'API base URL (optional)'],
        'sundarban' => ['base_url' => 'API base URL (optional)'],
        default     => [],
    };
}

/**
 * Short, provider-specific guidance under the optional base-URL field.
 * Sandbox bases are official hosts, not invented endpoints.
 */
function sh_courier_base_url_hint(string $driver): string
{
    return match ($driver) {
        'steadfast' => 'Leave blank for https://portal.packzy.com/api/v1.',
        'pathao'    => 'Leave blank for live (api-hermes.pathao.com). Sandbox: https://courier-api-sandbox.pathao.com.',
        'ecourier'  => 'Leave blank for live (backoffice.ecourier.com.bd/api). Sandbox: https://staging.ecourier.com.bd/api.',
        'paperfly'  => 'Leave blank for https://api.paperfly.com.bd.',
        'sundarban' => 'No public API host is documented. This value is stored but never called by a test.',
        'redx'      => 'Leave blank for https://openapi.redx.com.bd/v1.0.0-beta.',
        default     => 'Only change this for a sandbox or a private gateway.',
    };
}

function sh_courier_is_configured(array $courier): bool
{
    if (in_array($courier['driver'], ['manual', 'custom'], true)) { return true; }
    $creds = sh_courier_credentials($courier);
    foreach (array_keys(sh_courier_fields($courier['driver'])) as $f) {
        if (trim((string)($creds[$f] ?? '')) === '') { return false; }
    }
    return true;
}

/**
 * Honest status reporting — we never claim a driver is active unless its real
 * API is both implemented and configured.
 */
function sh_courier_status_text(array $courier): string
{
    if (in_array($courier['driver'], ['manual', 'custom'], true)) {
        return 'Manual tracking — no API required.';
    }
    if (!in_array($courier['driver'], sh_courier_api_drivers(), true)) {
        if (in_array($courier['driver'], sh_courier_testable_drivers(), true)) {
            return sh_courier_is_configured($courier)
                ? 'Credentials saved — connection can be tested. Booking is not wired in for this driver.'
                : 'Enter the merchant credentials to run a connection test.';
        }
        return sh_courier_is_configured($courier)
            ? 'Credentials saved. No automated connection test is available for this driver.'
            : 'No automated connection test is available for this driver.';
    }
    return sh_courier_is_configured($courier)
        ? 'API ready — credentials saved.'
        : 'Not configured — enter ' . $courier['name'] . ' merchant credentials.';
}

/**
 * Build the public tracking link for a courier. The stored template may contain
 * a {tracking} placeholder which is replaced with the parcel's tracking number.
 */
function sh_courier_tracking_url(array $courier, string $trackingNumber): string
{
    $tpl = trim((string)($courier['tracking_url'] ?? ''));
    $trackingNumber = trim($trackingNumber);
    if ($tpl === '') { return ''; }
    if (strpos($tpl, '{tracking}') === false) { return $tpl; }
    return str_replace('{tracking}', rawurlencode($trackingNumber), $tpl);
}

// ---------------------------------------------------------------------------
// Parcel (shipment) records
// ---------------------------------------------------------------------------
function sh_shipment_statuses(): array
{
    return [
        'draft'            => 'Draft (not booked)',
        'booked'           => 'Booked (awaiting pickup)',
        'picked_up'        => 'Picked up',
        'in_transit'       => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'failed_attempt'   => 'Delivery attempt failed',
        'returned'         => 'Returned',
        'cancelled'        => 'Cancelled',
    ];
}

function sh_shipment_status_label(string $status): string
{
    $map = [
        'draft'            => 'Draft',
        'booked'           => 'Booked',
        'picked_up'        => 'Picked up',
        'in_transit'       => 'In transit',
        'out_for_delivery' => 'Out for delivery',
        'delivered'        => 'Delivered',
        'failed_attempt'   => 'Attempt failed',
        'returned'         => 'Returned',
        'cancelled'        => 'Cancelled',
    ];
    return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function sh_shipment_status_class(string $status): string
{
    if ($status === 'delivered') { return 'sh-badge--ok'; }
    if (in_array($status, ['returned', 'cancelled', 'failed_attempt'], true)) { return 'sh-badge--bad'; }
    if (in_array($status, ['draft', 'booked', 'picked_up', 'in_transit', 'out_for_delivery'], true)) { return 'sh-badge--warn'; }
    return 'sh-badge--muted';
}

function sh_shipment_get(int $id): ?array
{
    return sh_one('SELECT * FROM shipments WHERE id = ? LIMIT 1', [$id]);
}

function sh_shipment_events(int $shipmentId): array
{
    return sh_all(
        'SELECT se.*, a.name AS admin_name
         FROM shipment_events se
         LEFT JOIN admins a ON a.id = se.admin_id
         WHERE se.shipment_id = ?
         ORDER BY se.id DESC',
        [$shipmentId]
    );
}

/**
 * Create a parcel for an order, snapshotting the recipient from the order.
 * @return array{ok:bool,error?:string,shipment_id?:int,shipment_number?:string}
 */
function sh_shipment_create(array $input, int $adminId): array
{
    $orderId = (int)($input['order_id'] ?? 0);
    $order = sh_order_get($orderId);
    if ($order === null) { return ['ok' => false, 'error' => 'Order not found.']; }
    if (trim((string)($order['shipping_address'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'This order has no shipping address, so it cannot be dispatched as a parcel.'];
    }

    $courierId = (int)($input['courier_id'] ?? 0);
    $courier = $courierId > 0 ? sh_courier_by_id($courierId) : null;
    if ($courierId > 0 && $courier === null) {
        return ['ok' => false, 'error' => 'That courier no longer exists.'];
    }

    // Collect cash on delivery only when the order's payment method is COD.
    $cod = null;
    if (array_key_exists('cod_amount', $input) && trim((string)$input['cod_amount']) !== '') {
        $cod = (float)$input['cod_amount'];
    } else {
        $pmType = $order['payment_method_id']
            ? (string)sh_val('SELECT type FROM payment_methods WHERE id = ?', [(int)$order['payment_method_id']], '')
            : '';
        $cod = $pmType === 'cod' ? (float)$order['total'] : 0.0;
    }

    $addrParts = array_filter([
        trim((string)$order['shipping_address']),
        trim((string)$order['shipping_area']),
        trim(trim((string)$order['shipping_city'] . ' ' . (string)$order['shipping_postcode'])),
    ]);
    $recipientAddress = implode(', ', $addrParts);

    $tracking = trim((string)($input['tracking_number'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $packageType = trim((string)($input['package_type'] ?? ''));

    $data = [
        'shipment_number' => 'TMP' . bin2hex(random_bytes(6)),
        'order_id'        => $orderId,
        'courier_id'      => $courierId > 0 ? $courierId : null,
        'tracking_number' => $tracking !== '' ? $tracking : null,
        'status'          => 'draft',
        'recipient_name'  => $order['customer_name'],
        'recipient_phone' => $order['customer_phone'],
        'recipient_address' => $recipientAddress,
        'package_weight'  => (float)($input['package_weight'] ?? 0),
        'package_type'    => $packageType !== '' ? $packageType : 'Parcel',
        'cod_amount'      => $cod,
        'shipping_cost'   => (float)($input['shipping_cost'] ?? 0),
        'note'            => $note !== '' ? $note : null,
        'created_by'      => $adminId,
    ];

    try {
        $id = sh_insert('shipments', $data);
        $number = 'PCL' . date('ymd') . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        sh_update('shipments', ['shipment_number' => $number], 'id = ?', [$id]);
        sh_insert('shipment_events', [
            'shipment_id' => $id,
            'status'      => 'draft',
            'note'        => 'Parcel created' . ($courier ? ' and assigned to ' . $courier['name'] : ''),
            'admin_id'    => $adminId,
            'source'      => 'admin',
        ]);
        return ['ok' => true, 'shipment_id' => $id, 'shipment_number' => $number];
    } catch (Throwable $e) {
        sh_log_exception($e, 'shipment-create');
        return ['ok' => false, 'error' => 'The parcel could not be created.'];
    }
}

/**
 * Move a parcel to a new status, append a timeline entry, and auto-sync the
 * linked order. All failures are logged and surfaced, never silently swallowed.
 */
function sh_shipment_set_status(int $id, string $status, string $note, int $adminId, string $source = 'admin'): array
{
    if (!array_key_exists($status, sh_shipment_statuses())) {
        return ['ok' => false, 'error' => 'That parcel status is not valid.'];
    }
    $shipment = sh_shipment_get($id);
    if ($shipment === null) { return ['ok' => false, 'error' => 'Parcel not found.']; }
    $note = mb_substr(trim($note), 0, 255);

    try {
        if ($status === 'booked') {
            sh_query('UPDATE shipments SET status = ?, booked_at = COALESCE(booked_at, NOW()), delivered_at = NULL, updated_at = NOW() WHERE id = ?', [$status, $id]);
        } elseif ($status === 'delivered') {
            sh_query('UPDATE shipments SET status = ?, delivered_at = NOW(), updated_at = NOW() WHERE id = ?', [$status, $id]);
        } else {
            sh_update('shipments', ['status' => $status], 'id = ?', [$id]);
        }
        sh_insert('shipment_events', [
            'shipment_id' => $id,
            'status'      => $status,
            'note'        => $note !== '' ? $note : null,
            'admin_id'    => $source === 'admin' ? $adminId : null,
            'source'      => $source,
        ]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'shipment-status');
        return ['ok' => false, 'error' => 'The parcel status could not be updated.'];
    }

    $synced = sh_shipment_sync_order(sh_shipment_get($id));
    sh_log_line('courier', 'Parcel ' . ($shipment['shipment_number'] ?? ('#' . $id)) . ' -> ' . $status . ' by admin ' . $adminId);
    return ['ok' => true, 'synced' => $synced];
}

/**
 * Auto-sync the order status from a parcel status (one source of truth).
 * Delivered -> order completed, Returned -> order cancelled, and any movement
 * into pickup/transit -> order processing. Never downgrades a completed or
 * cancelled order, and never touches payment_status (that stays with Payments).
 */
function sh_shipment_sync_order(array $shipment): array
{
    try {
        $order = sh_order_get((int)$shipment['order_id']);
        if ($order === null) { return ['changed' => false, 'reason' => 'order-missing']; }

        $map = [
            'booked'           => 'processing',
            'picked_up'        => 'processing',
            'in_transit'       => 'processing',
            'out_for_delivery' => 'processing',
            'failed_attempt'   => 'processing',
            'delivered'        => 'completed',
            'returned'         => 'cancelled',
        ];
        $target = $map[$shipment['status']] ?? null;
        if ($target === null) { return ['changed' => false, 'reason' => 'no-mapping']; }

        $current = $order['status'];
        if ($target === 'processing' && in_array($current, ['completed', 'cancelled'], true)) {
            return ['changed' => false, 'reason' => 'no-downgrade'];
        }
        if ($target === 'completed' && $current === 'cancelled') {
            return ['changed' => false, 'reason' => 'no-downgrade'];
        }
        if ($target === 'cancelled' && $current === 'completed') {
            return ['changed' => false, 'reason' => 'no-downgrade'];
        }
        if ($current === $target) { return ['changed' => false, 'reason' => 'already']; }

        sh_update('orders', ['status' => $target], 'id = ?', [$order['id']]);
        $event = $target === 'completed' ? 'order_completed' : ($target === 'processing' ? 'order_processing' : 'order_cancelled');
        sh_notify($event, sh_order_notify_payload(sh_order_get((int)$order['id'])));
        sh_log_line('courier', 'Parcel ' . $shipment['shipment_number'] . ' synced order ' . $order['order_number'] . ' -> ' . $target);
        return ['changed' => true, 'order_status' => $target];
    } catch (Throwable $e) {
        sh_log_exception($e, 'shipment-sync');
        return ['changed' => false, 'reason' => 'error'];
    }
}

// ---------------------------------------------------------------------------
// HTTP helper (curl first, streams as a fallback — no @ suppression)
// ---------------------------------------------------------------------------
function sh_courier_http(string $method, string $url, array $headers = [], array|object|null $json = null, int $timeout = 25): array
{
    $method = strtoupper($method);
    $payload = $json === null ? null : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hdr = [];
    foreach ($headers as $k => $v) { $hdr[] = $k . ': ' . $v; }
    if ($json !== null) { $hdr[] = 'Content-Type: application/json'; }

    $status = 0;
    $body = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Never follow a redirect with credentials or a request body.
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => [], 'error' => 'HTTP request failed: ' . $err];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $hdr),
                'content'       => $payload ?? '',
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents($url, false, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'HTTP request failed (no cURL extension).'];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
        }
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) { $decoded = ['raw' => $body]; }
    $ok = $status >= 200 && $status < 300;
    $error = $ok ? null : sh_courier_api_error(['body' => $decoded, 'status' => $status]);
    return ['ok' => $ok, 'status' => $status, 'body' => $decoded, 'raw' => (string)$body, 'error' => $error];
}

/** Human message from a courier API error body. */
function sh_courier_api_error(array $http): string
{
    $body = $http['body'] ?? [];
    if (is_array($body)) {
        $msg = (string)($body['message'] ?? '');
        if ($msg !== '') { return $msg; }
        $errs = $body['errors'] ?? null;
        if (is_array($errs)) {
            $flat = [];
            array_walk_recursive($errs, static function ($v) use (&$flat) { if (is_scalar($v)) { $flat[] = (string)$v; } });
            if ($flat) { return implode('; ', $flat); }
        }
    }
    $status = (int)($http['status'] ?? 0);
    return $status > 0 ? 'HTTP ' . $status : 'Unknown error';
}

/** Remove anything credential-like before persisting an API payload. */
function sh_courier_scrub(array $payload): array
{
    $bad = ['password', 'secret', 'token', 'api_key', 'api_access_token', 'client_secret', 'signature'];
    $out = [];
    foreach ($payload as $k => $v) {
        if (in_array(strtolower((string)$k), $bad, true)) { continue; }
        if (is_array($v)) { $out[$k] = sh_courier_scrub($v); continue; }
        $out[$k] = is_scalar($v) ? mb_substr((string)$v, 0, 300) : '[object]';
    }
    return $out;
}

// ---------------------------------------------------------------------------
// API actions (book + fetch status)
// ---------------------------------------------------------------------------
/**
 * Book a parcel with its courier. Manual couriers are marked booked locally;
 * a real API driver sends the booking and stores the returned consignment id.
 */
function sh_courier_book(int $shipmentId, int $adminId): array
{
    $shipment = sh_shipment_get($shipmentId);
    if ($shipment === null) { return ['ok' => false, 'error' => 'Parcel not found.']; }
    if (in_array($shipment['status'], ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'returned'], true)) {
        return ['ok' => false, 'error' => 'This parcel is already booked or further along.']; 
    }
    $courier = $shipment['courier_id'] ? sh_courier_by_id((int)$shipment['courier_id']) : null;
    if ($courier === null) { return ['ok' => false, 'error' => 'Assign a courier before booking this parcel.']; }

    $driver = $courier['driver'];

    if (in_array($driver, ['manual', 'custom'], true)) {
        return sh_shipment_set_status($shipmentId, 'booked', 'Marked as handed to ' . $courier['name'], $adminId);
    }
    if (!in_array($driver, sh_courier_api_drivers(), true)) {
        return ['ok' => false, 'error' => $courier['name'] . ' API integration is registered but not yet wired in. No request was sent — enter the tracking number manually instead.'];
    }
    if (!sh_courier_is_configured($courier)) {
        return ['ok' => false, 'error' => $courier['name'] . ' is not configured. Add its merchant credentials on the Couriers page first.'];
    }

    $res = match ($driver) {
        'steadfast' => sh_steadfast_book($courier, $shipment),
        default     => ['ok' => false, 'error' => 'No API client is implemented for this driver.'],
    };
    if (empty($res['ok'])) { return $res; }

    $tracking = (string)($res['tracking_number'] ?? '');
    $payload = $res['payload'] ?? null;
    try {
        sh_update('shipments', [
            'status'          => 'booked',
            'tracking_number' => $tracking !== '' ? $tracking : $shipment['tracking_number'],
            'courier_payload' => $payload,
            'booked_at'       => date('Y-m-d H:i:s'),
        ], 'id = ?', [$shipmentId]);
        sh_insert('shipment_events', [
            'shipment_id' => $shipmentId,
            'status'      => 'booked',
            'note'        => 'Booked with ' . $courier['name'] . ($tracking !== '' ? ' (tracking ' . $tracking . ')' : ''),
            'admin_id'    => $adminId,
            'source'      => 'api',
        ]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'courier-book');
        return ['ok' => false, 'error' => 'The booking succeeded at the courier but could not be saved locally. Please record it manually.'];
    }

    $synced = sh_shipment_sync_order(sh_shipment_get($shipmentId));
    return ['ok' => true, 'tracking_number' => $tracking, 'synced' => $synced];
}

/** Pull the latest tracking status from the courier API and apply it locally. */
function sh_courier_fetch_status(int $shipmentId, int $adminId): array
{
    $shipment = sh_shipment_get($shipmentId);
    if ($shipment === null) { return ['ok' => false, 'error' => 'Parcel not found.']; }
    $courier = $shipment['courier_id'] ? sh_courier_by_id((int)$shipment['courier_id']) : null;
    if ($courier === null) { return ['ok' => false, 'error' => 'No courier is assigned to this parcel.']; }

    $driver = $courier['driver'];
    if (in_array($driver, ['manual', 'custom'], true)) {
        return ['ok' => false, 'error' => 'This courier has no API — update the parcel status manually.'];
    }
    if (!in_array($driver, sh_courier_api_drivers(), true)) {
        return ['ok' => false, 'error' => $courier['name'] . ' API integration is registered but not yet wired in — no request was sent.'];
    }
    if (!sh_courier_is_configured($courier)) {
        return ['ok' => false, 'error' => $courier['name'] . ' is not configured. Add its merchant credentials on the Couriers page first.'];
    }

    $res = match ($driver) {
        'steadfast' => sh_steadfast_status($courier, $shipment),
        default     => ['ok' => false, 'error' => 'No API client is implemented for this driver.'],
    };
    if (empty($res['ok'])) { return $res; }

    $mapped = $res['mapped'] ?? null;
    $raw = (string)($res['raw_status'] ?? '');

    if ($mapped === null) {
        sh_insert('shipment_events', [
            'shipment_id' => $shipmentId,
            'status'      => $shipment['status'],
            'note'        => 'Courier reports status "' . $raw . '" — left unchanged.',
            'source'      => 'api',
        ]);
        return ['ok' => true, 'changed' => false, 'message' => 'Courier reports "' . $raw . '" — parcel left unchanged.'];
    }
    if ($mapped === $shipment['status']) {
        return ['ok' => true, 'changed' => false, 'message' => 'Parcel is already "' . sh_shipment_status_label($mapped) . '".'];
    }

    $set = sh_shipment_set_status($shipmentId, $mapped, 'Courier reports "' . $raw . '"', $adminId, 'api');
    if (empty($set['ok'])) { return $set; }
    return ['ok' => true, 'changed' => true, 'message' => 'Parcel updated to "' . sh_shipment_status_label($mapped) . '".'];
}

// ---------------------------------------------------------------------------
// Steadfast driver (real client)
// ---------------------------------------------------------------------------
function sh_steadfast_base_url(array $creds): string
{
    $base = trim((string)($creds['base_url'] ?? ''));
    return rtrim($base !== '' ? $base : 'https://portal.packzy.com/api/v1', '/');
}

function sh_steadfast_headers(array $creds): array
{
    return [
        'Api-Key'    => (string)($creds['api_key'] ?? ''),
        'Secret-Key' => (string)($creds['secret_key'] ?? ''),
    ];
}

function sh_steadfast_book(array $courier, array $shipment): array
{
    $creds = sh_courier_credentials($courier);
    $http = sh_courier_http('POST', sh_steadfast_base_url($creds) . '/create_order', sh_steadfast_headers($creds), [
        'invoice'           => $shipment['shipment_number'],
        'recipient_name'    => $shipment['recipient_name'],
        'recipient_phone'   => $shipment['recipient_phone'],
        'recipient_address' => $shipment['recipient_address'],
        'cod_amount'        => (float)$shipment['cod_amount'],
        'note'              => $shipment['note'] ?: null,
    ]);
    if (!$http['ok']) {
        return ['ok' => false, 'error' => 'Steadfast rejected the booking: ' . ($http['error'] ?? 'unknown error')];
    }
    $consignment = $http['body']['consignment'] ?? null;
    if (!is_array($consignment)) {
        return ['ok' => false, 'error' => 'Steadfast returned an unexpected response.'];
    }
    $cid = (string)($consignment['consignment_id'] ?? '');
    $track = (string)($consignment['tracking_code'] ?? '');
    if ($cid === '' && $track === '') {
        return ['ok' => false, 'error' => 'Steadfast did not return a consignment id.'];
    }
    return [
        'ok'              => true,
        'tracking_number' => $track !== '' ? $track : $cid,
        'consignment_id'  => $cid,
        'payload'         => json_encode(sh_courier_scrub([
            'consignment_id' => $cid,
            'tracking_code'  => $track,
            'response'       => $http['body'],
        ]), JSON_UNESCAPED_SLASHES),
    ];
}

function sh_steadfast_status(array $courier, array $shipment): array
{
    $creds = sh_courier_credentials($courier);
    $cid = sh_shipment_consignment_id($shipment);
    if ($cid === '') {
        return ['ok' => false, 'error' => 'No consignment id is stored for this parcel. Book it via the API first, or paste the consignment id as the tracking number.'];
    }
    $http = sh_courier_http('GET', sh_steadfast_base_url($creds) . '/status_by_cid/' . rawurlencode($cid), sh_steadfast_headers($creds));
    if (!$http['ok']) {
        return ['ok' => false, 'error' => 'Steadfast status check failed: ' . ($http['error'] ?? 'unknown error')];
    }
    $raw = (string)($http['body']['delivery_status'] ?? '');
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Steadfast returned an unexpected status response.'];
    }
    return ['ok' => true, 'raw_status' => $raw, 'mapped' => sh_steadfast_map_status($raw)];
}

function sh_steadfast_map_status(string $raw): ?string
{
    return match (strtolower($raw)) {
        'delivered'         => 'delivered',
        'returned'          => 'returned',
        'cancelled'         => 'cancelled',
        'partial_delivered' => 'in_transit',
        'in_review', 'pending', 'on_hold', 'hold' => 'booked',
        default             => null,
    };
}

/** Consignment id for Steadfast status lookups, persisted at booking time. */
function sh_shipment_consignment_id(array $shipment): string
{
    $raw = (string)($shipment['courier_payload'] ?? '');
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d) && isset($d['consignment_id']) && (string)$d['consignment_id'] !== '') {
            return (string)$d['consignment_id'];
        }
    }
    // Fall back to the tracking number (numeric consignment ids were the
    // legacy convention; newer records store both fields separately).
    return trim((string)($shipment['tracking_number'] ?? ''));
}

// ---------------------------------------------------------------------------
// Connection tests (read-only; never books, picks up or charges anything)
// ---------------------------------------------------------------------------

/**
 * The only states a courier test can end in. Labels are shown verbatim in the
 * admin; tones map to the existing badge palette.
 * @return array<string,array{label:string,tone:string}>
 */
function sh_courier_test_states(): array
{
    return [
        'connected'                => ['label' => 'Connected',                 'tone' => 'ok'],
        'authentication_failed'    => ['label' => 'Authentication failed',     'tone' => 'bad'],
        'configuration_incomplete' => ['label' => 'Configuration incomplete',  'tone' => 'warn'],
        'permission_denied'        => ['label' => 'Permission denied',         'tone' => 'bad'],
        'network_error'            => ['label' => 'Network error',             'tone' => 'warn'],
        'unsupported'              => ['label' => 'Unsupported automated test','tone' => 'muted'],
        'failed'                   => ['label' => 'Test failed',               'tone' => 'bad'],
    ];
}

function sh_courier_test_state_label(string $state): string
{
    $s = sh_courier_test_states();
    return $s[$state]['label'] ?? 'Test failed';
}

function sh_courier_test_state_tone(string $state): string
{
    $s = sh_courier_test_states();
    return $s[$state]['tone'] ?? 'bad';
}

/** Map an HTTP status to a state. 0 means no response at all. */
function sh_courier_test_state_from_http(int $status): string
{
    return match (true) {
        $status === 0                        => 'network_error',
        $status === 401                      => 'authentication_failed',
        $status === 403                      => 'permission_denied',
        $status === 408, $status === 504     => 'network_error',
        default                              => 'failed',
    };
}

/**
 * Remove control characters, collapse whitespace, cap the length and replace
 * any stored credential that the provider echoed back. Called for every
 * message before it is stored or rendered.
 */
function sh_courier_test_scrub(string $text, array $creds = []): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    foreach ($creds as $value) {
        if (is_scalar($value) && strlen((string)$value) >= 4) {
            $text = str_replace((string)$value, '••••', $text);
        }
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return mb_substr($text, 0, 220);
}

/** \"A\", \"A and B\", \"A, B and C\" for missing-field messages. */
function sh_courier_join_words(array $words): string
{
    $words = array_values(array_filter(array_map('strval', $words), static fn($w) => $w !== ''));
    if (count($words) <= 1) { return $words[0] ?? ''; }
    $last = array_pop($words);
    return implode(', ', $words) . ' and ' . $last;
}

/** Standard result. */
function sh_courier_test_result(string $state, int $httpStatus, string $message, string $next, string $endpoint = '', array $creds = []): array
{
    if (!isset(sh_courier_test_states()[$state])) { $state = 'failed'; }
    return [
        'ok'          => $state === 'connected',
        'state'       => $state,
        'state_label' => sh_courier_test_state_label($state),
        'tone'        => sh_courier_test_state_tone($state),
        'http_status' => $httpStatus,
        'message'     => sh_courier_test_scrub($message, $creds),
        'next'        => sh_courier_test_scrub($next, $creds),
        'endpoint'    => $endpoint,
        'tested_at'   => date('Y-m-d H:i:s'),
    ];
}

function sh_courier_test_incomplete(array $missing, string $what): array
{
    return sh_courier_test_result('configuration_incomplete', 0,
        'Configuration incomplete: ' . sh_courier_join_words($missing) . ' must be saved for ' . $what . '.',
        'Enter the missing merchant credentials on this card, save, then test again.');
}

/** Turn a failed HTTP response into the right state and an actionable message. */
function sh_courier_test_http_failure(array $http, string $endpoint, array $creds, string $what): array
{
    $status = (int)($http['status'] ?? 0);
    $state = sh_courier_test_state_from_http($status);
    $detail = trim((string)($http['error'] ?? ''));
    if ($detail === '') { $detail = trim((string)($http['body']['message'] ?? '')); }

    $message = match ($state) {
        'network_error' => $status === 0
            ? 'No response from ' . $what . '. The host may be unreachable from this server or the base URL may be wrong.'
            : $what . ' did not answer in time (HTTP ' . $status . ').',
        'authentication_failed' => $what . ' rejected these credentials (HTTP 401).',
        'permission_denied' => $what . ' accepted the request but this account has no permission (HTTP 403).',
        default => $status === 404
            ? $what . ' returned HTTP 404 — check the API base URL.'
            : ($status === 429
                ? $what . ' rate-limited the test (HTTP 429).'
                : ($status >= 500
                    ? $what . ' returned a server error (HTTP ' . $status . '). The credentials may still be valid.'
                    : $what . ' rejected the test request (HTTP ' . $status . ').')),
    };
    if ($detail !== '' && !str_contains($message, $detail)) { $message .= ' ' . $detail; }

    $next = match ($state) {
        'network_error'        => 'Check the base URL and that the server can make outbound HTTPS requests.',
        'authentication_failed'=> 'Re-enter the credentials, save, then test again.',
        'permission_denied'    => 'Ask the courier to enable API access for this merchant account.',
        'failed'               => $status === 429 ? 'Wait a minute and test again.' : 'Read the message, correct the credentials or base URL, and retry.',
    };
    return sh_courier_test_result($state, $status, $message, $next, $endpoint, $creds);
}

/** Credentials the driver requires that are still missing (field => label). */
function sh_courier_test_missing(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $out = [];
    foreach (sh_courier_fields((string)$courier['driver']) as $field => $label) {
        if (trim((string)($creds[$field] ?? '')) === '') { $out[$field] = $label; }
    }
    return $out;
}

/* --- Provider-specific probes -------------------------------------------
 * Each one uses that provider's own documented read-only operation. None of
 * them calls a booking, pickup, label or payment endpoint.
 * ---------------------------------------------------------------------- */

function sh_courier_probe_steadfast(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $missing = sh_courier_test_missing($courier);
    if ($missing) { return sh_courier_test_incomplete(array_values($missing), 'Steadfast'); }

    $http = sh_courier_http('GET', sh_steadfast_base_url($creds) . '/get_balance', sh_steadfast_headers($creds), null, 15);
    if (!$http['ok']) { return sh_courier_test_http_failure($http, '/get_balance', $creds, 'Steadfast'); }

    // A wrong base URL can also answer HTTP 200 with a login page. Only a real
    // JSON body from the balance endpoint counts as a successful connection.
    if (isset($http['body']['raw']) || $http['body'] === []) {
        return sh_courier_test_result('failed', (int)$http['status'],
            'Steadfast answered HTTP ' . (int)$http['status'] . ' but not with the balance JSON. The base URL may point at the website instead of the API.',
            'Use https://portal.packzy.com/api/v1 (or your private gateway), save, then test again.', '/get_balance', $creds);
    }
    // The API can answer HTTP 200 with a status field. Never call that connected.
    $bodyStatus = (int)($http['body']['status'] ?? 200);
    if ($bodyStatus >= 400) {
        return sh_courier_test_result(sh_courier_test_state_from_http($bodyStatus), $bodyStatus,
            'Steadfast did not accept the credentials (status ' . $bodyStatus . ').',
            'Re-enter the API key and secret key, save, then test again.', '/get_balance', $creds);
    }
    $bal = $http['body']['current_balance'] ?? null;
    return sh_courier_test_result('connected', (int)$http['status'],
        'Steadfast accepted the API key and secret key.'
            . ($bal !== null ? ' Current balance: ' . sh_money($bal) . '.' : ''),
        'Connection verified. A test never creates a parcel.', '/get_balance', $creds);
}

function sh_pathao_base_url(array $creds): string
{
    $base = trim((string)($creds['base_url'] ?? ''));
    return rtrim($base !== '' ? $base : 'https://api-hermes.pathao.com', '/');
}

function sh_courier_probe_pathao(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $missing = sh_courier_test_missing($courier);
    if ($missing) { return sh_courier_test_incomplete(array_values($missing), 'Pathao'); }

    $base = sh_pathao_base_url($creds);
    // Pathao's official Merchant API issues tokens with a password grant:
    // POST /aladdin/api/v1/issue-token with the client pair and the merchant
    // username/password. The token is used for this test only and discarded;
    // nothing is persisted and no order or pickup can result from it.
    $login = sh_courier_http('POST', $base . '/aladdin/api/v1/issue-token', [], [
        'client_id'     => (string)$creds['client_id'],
        'client_secret' => (string)$creds['client_secret'],
        'grant_type'    => 'password',
        'username'      => (string)$creds['username'],
        'password'      => (string)$creds['password'],
    ], 15);
    if (!$login['ok']) {
        $status = (int)($login['status'] ?? 0);
        // Hermes answers 400 (invalid_grant) or 401 (invalid_client) when any
        // part of the credential set is wrong: an authentication failure, not
        // a generic rejection.
        if ($status === 400 || $status === 401) {
            $detail = trim((string)($login['error'] ?? ''));
            return sh_courier_test_result('authentication_failed', $status,
                'Pathao rejected these credentials (HTTP ' . $status . ').' . ($detail !== '' ? ' ' . $detail : ''),
                'Re-enter the Client ID, Client Secret, merchant username and password from the Pathao merchant panel (API section), save, then test again.',
                '/aladdin/api/v1/issue-token', $creds);
        }
        return sh_courier_test_http_failure($login, '/aladdin/api/v1/issue-token', $creds, 'Pathao');
    }
    $token = trim((string)($login['body']['access_token'] ?? ''));
    if ($token === '') {
        return sh_courier_test_result('failed', (int)$login['status'],
            'Pathao accepted the request but returned no access token.',
            'Check that the credentials belong to the merchant API (not the panel password alone), then retry.',
            '/aladdin/api/v1/issue-token', $creds);
    }

    $stores = sh_courier_http('GET', $base . '/aladdin/api/v1/stores', ['Authorization' => 'Bearer ' . $token], null, 15);
    if (!$stores['ok']) {
        $res = sh_courier_test_http_failure($stores, '/aladdin/api/v1/stores', $creds, 'Pathao');
        if ($res['state'] === 'authentication_failed') {
            $res['message'] = 'Pathao issued a token but rejected it for the stores endpoint (HTTP 401). '
                . 'The token may have expired or the merchant API may be disabled for this account.';
        }
        return $res;
    }

    if (isset($stores['body']['raw'])) {
        return sh_courier_test_result('failed', (int)$stores['status'],
            'Pathao issued a token but the stores endpoint returned a non-JSON answer. Check the API base URL.',
            'Use the live api-hermes.pathao.com host or the official sandbox host, save, then test again.',
            '/aladdin/api/v1/stores', $creds);
    }
    $data = $stores['body']['data'] ?? [];
    $list = is_array($data) && isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    if (!is_array($list)) { $list = []; }
    $storeId = trim((string)$creds['store_id']);
    $match = null;
    foreach ($list as $store) {
        if (!is_array($store)) { continue; }
        if ((string)($store['store_id'] ?? '') === $storeId) { $match = $store; break; }
    }
    if (!$list) {
        return sh_courier_test_result('configuration_incomplete', (int)$stores['status'],
            'Pathao accepted the client credentials but this account has no stores.',
            'Create a store in the Pathao merchant panel, then copy its Store ID here.',
            '/aladdin/api/v1/stores', $creds);
    }
    if ($match === null) {
        return sh_courier_test_result('configuration_incomplete', (int)$stores['status'],
            'Pathao accepted the credentials, but Store ID ' . $storeId . ' is not one of this account\'s stores.',
            'Copy the Store ID from Pathao merchant panel → Stores, save it, then test again.',
            '/aladdin/api/v1/stores', $creds);
    }
    $storeName = trim((string)($match['store_name'] ?? $match['name'] ?? ''));
    return sh_courier_test_result('connected', (int)$stores['status'],
        'Pathao accepted the client credentials and found store ' . ($storeName !== '' ? '"' . $storeName . '" ' : '') . '(' . $storeId . ').',
        'Connection verified. A test never creates an order or a pickup.', '/aladdin/api/v1/stores', $creds);
}

function sh_ecourier_base_url(array $creds): string
{
    $base = trim((string)($creds['base_url'] ?? ''));
    return rtrim($base !== '' ? $base : 'https://backoffice.ecourier.com.bd/api', '/');
}

function sh_courier_probe_ecourier(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $missing = sh_courier_test_missing($courier);
    if ($missing) { return sh_courier_test_incomplete(array_values($missing), 'eCourier'); }

    $http = sh_courier_http('POST', sh_ecourier_base_url($creds) . '/city-list', [
        'API-KEY'      => (string)$creds['api_key'],
        'API-SECRET'   => (string)$creds['api_secret'],
        'USER-ID'      => (string)$creds['user_id'],
        'Content-Type' => 'application/json',
    ], (object)[], 15);

    if ($http['ok']) {
        $body = $http['body'];
        if (isset($body['raw'])) {
            return sh_courier_test_result('failed', (int)$http['status'],
                'eCourier answered HTTP ' . (int)$http['status'] . ' but not with JSON. The base URL may point at the website instead of the API.',
                'Use backoffice.ecourier.com.bd/api or the official staging host, save, then test again.',
                '/city-list', $creds);
        }
        // eCourier answers HTTP 200 with either error=true or success=false for
        // a rejected request. Only an explicit failure flag is a failure: a
        // success=true answer is a pass, not an error.
        $errorFlag = $body['error'] ?? null;
        $successFlag = $body['success'] ?? null;
        $isFailure = $errorFlag === true || $errorFlag === 1 || $errorFlag === '1' || $errorFlag === 'true'
            || $successFlag === false || $successFlag === 0 || $successFlag === '0' || $successFlag === 'false';
        if ($isFailure) {
            $msg = (string)($body['message'] ?? $body['error_message'] ?? 'The city list was not returned.');
            $lower = strtolower($msg);
            $state = (str_contains($lower, 'key') || str_contains($lower, 'secret') || str_contains($lower, 'user'))
                ? 'authentication_failed' : 'failed';
            return sh_courier_test_result($state, 200,
                'eCourier did not accept the request: ' . $msg,
                $state === 'authentication_failed'
                    ? 'Re-enter the API key, API secret and user ID, save, then test again.'
                    : 'Read the message and correct the merchant settings; nothing was booked.',
                '/city-list', $creds);
        }
        // The documented answer is a root JSON array of cities; some accounts
        // wrap it. Count either shape for the success message.
        $cities = $body['cities'] ?? $body['city_list'] ?? (array_is_list($body) ? $body : null);
        $count = is_array($cities) ? count($cities) : null;
        return sh_courier_test_result('connected', (int)$http['status'],
            'eCourier accepted the API key, API secret and user ID'
                . ($count !== null ? ' (' . $count . ' cities returned).' : '.'),
            'Connection verified. A test never places an order.', '/city-list', $creds);
    }

    $res = sh_courier_test_http_failure($http, '/city-list', $creds, 'eCourier');
    if ($res['state'] === 'failed' && (int)($http['status'] ?? 0) === 200) {
        $res['state'] = 'authentication_failed';
        $res['state_label'] = sh_courier_test_state_label('authentication_failed');
        $res['tone'] = sh_courier_test_state_tone('authentication_failed');
        $res['ok'] = false;
        $res['next'] = 'Re-enter the API key, API secret and user ID, save, then test again.';
    }
    return $res;
}

function sh_courier_probe_paperfly(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $missing = sh_courier_test_missing($courier);
    if ($missing) { return sh_courier_test_incomplete(array_values($missing), 'Paperfly'); }

    // Paperfly's merchant API (paperflykey + Basic auth) documents only order
    // placement, order cancellation and tracking by a real reference — there
    // is no read-only credential or account endpoint to verify against.
    // Sending a booking or tracking a made-up reference would be dishonest,
    // so the test stops here and says exactly why.
    return sh_courier_test_result('unsupported', 0,
        'Paperfly has no documented read-only credential check (only order placement, cancellation and tracking by reference). No request was sent.',
        'Verify the paperflykey, username and password in the Paperfly merchant panel. Booking stays manual here until Paperfly offers a verifiable endpoint.');
}

function sh_courier_probe_sundarban(array $courier): array
{
    return sh_courier_test_result('unsupported', 0,
        'Sundarban Courier has no public merchant API or documented credential scheme. No request was sent.',
        'Use the Sundarban merchant panel or app. Parcels stay manual in this store.');
}

function sh_courier_probe_saparibahan(array $courier): array
{
    return sh_courier_test_result('unsupported', 0,
        'SA Paribahan advertises corporate API integration but publishes no endpoint, authentication scheme or sandbox. No request was sent.',
        'Contact SA Paribahan for corporate API access. Until then, record tracking numbers manually.');
}

function sh_redx_base_url(array $creds): string
{
    $base = trim((string)($creds['base_url'] ?? ''));
    return rtrim($base !== '' ? $base : 'https://openapi.redx.com.bd/v1.0.0-beta', '/');
}

function sh_courier_probe_redx(array $courier): array
{
    $creds = sh_courier_credentials($courier);
    $missing = sh_courier_test_missing($courier);
    if ($missing) { return sh_courier_test_incomplete(array_values($missing), 'RedX'); }

    // RedX OpenAPI: every call carries "API-ACCESS-TOKEN: Bearer <token>".
    // GET /pickup/stores lists this shop's pickup stores — account-specific,
    // read-only, and the first call every integration needs anyway.
    $http = sh_courier_http('GET', sh_redx_base_url($creds) . '/pickup/stores',
        ['API-ACCESS-TOKEN' => 'Bearer ' . trim((string)$creds['api_access_token'])], null, 15);
    if (!$http['ok']) { return sh_courier_test_http_failure($http, '/pickup/stores', $creds, 'RedX'); }
    if (isset($http['body']['raw'])) {
        return sh_courier_test_result('failed', (int)$http['status'],
            'RedX answered HTTP ' . (int)$http['status'] . ' but not with JSON. The base URL may point at the website instead of the API.',
            'Use https://openapi.redx.com.bd/v1.0.0-beta, save, then test again.', '/pickup/stores', $creds);
    }
    $stores = $http['body']['pickup_stores'] ?? null;
    if (!is_array($stores)) {
        return sh_courier_test_result('failed', (int)$http['status'],
            'RedX answered without the pickup-store list the endpoint documents.',
            'Check the API base URL; if it is correct, ask RedX whether API access is enabled for this shop.',
            '/pickup/stores', $creds);
    }
    if (!$stores) {
        return sh_courier_test_result('configuration_incomplete', (int)$http['status'],
            'RedX accepted the API access token but this shop has no pickup stores.',
            'Add a pickup store in the RedX merchant dashboard, then test again.',
            '/pickup/stores', $creds);
    }
    $first = is_array($stores[0] ?? null) ? (string)($stores[0]['name'] ?? $stores[0]['store_name'] ?? '') : '';
    return sh_courier_test_result('connected', (int)$http['status'],
        'RedX accepted the API access token and listed ' . count($stores) . ' pickup store'
            . (count($stores) === 1 ? '' : 's') . ($first !== '' ? ' (first: "' . $first . '")' : '') . '.',
        'Connection verified. A test never creates a parcel.', '/pickup/stores', $creds);
}

function sh_courier_probe_manual(array $courier): array
{
    $isSap = (string)($courier['code'] ?? '') === 'saparibahan';
    if ($isSap) { return sh_courier_probe_saparibahan($courier); }
    return sh_courier_test_result('unsupported', 0,
        'This courier has no API, so there is nothing to connect to. No request was sent.',
        'Nothing to do — record the tracking number manually when you hand the parcel over.');
}

// ---------------------------------------------------------------------------
// Test results: persistence + display
// ---------------------------------------------------------------------------

function sh_courier_test_table_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try { sh_query(sh_courier_test_results_sql()); }
    catch (Throwable $e) { sh_log_exception($e, 'courier-test-table'); }
}

function sh_courier_test_record(int $courierId, string $driver, array $result, int $adminId = 0): void
{
    if ($courierId <= 0) { return; }
    sh_courier_test_table_ensure();
    try {
        sh_insert('courier_test_results', [
            'courier_id'  => $courierId,
            'driver'      => mb_substr($driver, 0, 50),
            'result_code' => mb_substr((string)($result['state'] ?? 'failed'), 0, 40),
            'state'       => mb_substr((string)($result['state'] ?? 'failed'), 0, 40),
            'http_status' => (int)($result['http_status'] ?? 0) > 0 ? (int)$result['http_status'] : null,
            'note'        => mb_substr((string)($result['message'] ?? ''), 0, 255) ?: null,
            'next_action' => mb_substr((string)($result['next'] ?? ''), 0, 255) ?: null,
            'tested_by'   => $adminId > 0 ? $adminId : null,
            'tested_at'   => (string)($result['tested_at'] ?? date('Y-m-d H:i:s')),
        ]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'courier-test-record');
    }
}

function sh_courier_last_test(int $courierId): ?array
{
    try {
        return sh_one('SELECT * FROM courier_test_results WHERE courier_id = ? ORDER BY id DESC LIMIT 1', [$courierId]);
    } catch (Throwable $e) {
        return null;
    }
}

function sh_courier_test_ago(string $when): string
{
    $ts = strtotime($when);
    if ($ts === false) { return $when; }
    $diff = time() - $ts;
    if ($diff < 60) { return 'just now'; }
    if ($diff < 3600) { return floor($diff / 60) . ' min ago'; }
    if ($diff < 86400) { return floor($diff / 3600) . ' h ago'; }
    if ($diff < 604800) { return floor($diff / 86400) . ' d ago'; }
    return date('j M Y', $ts);
}

/**
 * Compact result block for a courier card / details page. Always explains that
 * the test is read-only and never shows a stored secret.
 */
function sh_courier_test_summary(array $courier): string
{
    $last = sh_courier_last_test((int)($courier['id'] ?? 0));
    if ($last === null) {
        return '<div class="sh-ctest sh-ctest--none">'
            . '<p class="sh-ctest__note">No connection test yet. A test sends one read-only request; it never books a parcel or changes an order.</p>'
            . '</div>';
    }
    $state = (string)($last['state'] ?? 'failed');
    $tone = sh_courier_test_state_tone($state);
    $badge = $tone === 'ok' ? 'sh-badge--ok' : ($tone === 'bad' ? 'sh-badge--bad' : ($tone === 'warn' ? 'sh-badge--warn' : 'sh-badge--muted'));
    $when = (string)($last['tested_at'] ?? '');
    $http = (int)($last['http_status'] ?? 0);
    $html = '<div class="sh-ctest sh-ctest--' . e($tone) . '" data-courier-test="' . (int)($courier['id'] ?? 0) . '">';
    $html .= '<div class="sh-ctest__head"><span class="sh-badge ' . $badge . '">' . e(sh_courier_test_state_label($state)) . '</span>'
        . '<span class="sh-ctest__meta">' . ($http > 0 ? 'HTTP ' . $http . ' · ' : '') . e(sh_courier_test_ago($when)) . '</span></div>';
    if (trim((string)($last['note'] ?? '')) !== '') {
        $html .= '<p class="sh-ctest__note">' . e((string)$last['note']) . '</p>';
    }
    if (trim((string)($last['next_action'] ?? '')) !== '') {
        $html .= '<p class="sh-ctest__next"><strong>Next:</strong> ' . e((string)$last['next_action']) . '</p>';
    }
    return $html . '</div>';
}

/**
 * Run the provider-specific read-only test for one courier and record it.
 * Nothing here creates a shipment, pickup, label or charge.
 */
function sh_courier_test(array $courier, int $adminId = 0): array
{
    $driver = (string)($courier['driver'] ?? 'manual');
    $code = (string)($courier['code'] ?? '');

    $result = match (true) {
        $code === 'saparibahan' => sh_courier_probe_saparibahan($courier),
        $driver === 'steadfast' => sh_courier_probe_steadfast($courier),
        $driver === 'pathao'    => sh_courier_probe_pathao($courier),
        $driver === 'ecourier'  => sh_courier_probe_ecourier($courier),
        $driver === 'paperfly'  => sh_courier_probe_paperfly($courier),
        $driver === 'sundarban' => sh_courier_probe_sundarban($courier),
        $driver === 'redx'      => sh_courier_probe_redx($courier),
        default                 => sh_courier_probe_manual($courier),
    };
    $result['driver'] = $driver;
    $result['courier'] = (string)($courier['name'] ?? '');
    sh_courier_test_record((int)($courier['id'] ?? 0), $driver, $result, $adminId);
    return $result;
}
