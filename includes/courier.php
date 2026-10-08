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
        if ((int)sh_val('SELECT COUNT(*) FROM couriers', [], 0) === 0) {
            sh_courier_seed($pdo);
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

function sh_courier_credentials(array $courier): array
{
    $raw = (string)($courier['credentials'] ?? '');
    if ($raw === '') { return []; }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Required credential fields per driver (all must be present to be "configured"). */
function sh_courier_fields(string $driver): array
{
    return match ($driver) {
        'steadfast' => ['api_key' => 'API Key', 'secret_key' => 'Secret Key'],
        'pathao'    => ['client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'store_id' => 'Store ID'],
        'redx'      => ['api_access_token' => 'API Access Token'],
        'ecourier'  => ['api_key' => 'API Key', 'api_secret' => 'API Secret', 'user_id' => 'User ID'],
        'paperfly'  => ['username' => 'Username', 'password' => 'Password'],
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
        return sh_courier_is_configured($courier)
            ? 'API registered but not yet wired in — credentials saved.'
            : 'API registered but not yet wired in — enter credentials when available.';
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
function sh_courier_http(string $method, string $url, array $headers = [], ?array $json = null): array
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
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
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
                'timeout'       => 25,
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
    return rtrim($base !== '' ? $base : 'https://portal.pacakge.net/api/v1', '/');
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

/**
 * Admin "Test connection" for couriers with a real API client. Uses the
 * Steadfast balance endpoint (read-only) so nothing is booked.
 */
function sh_courier_test(array $courier): array
{
    if (!in_array($courier['driver'], sh_courier_api_drivers(), true)) {
        return ['ok' => false, 'error' => 'No API client is wired in for this courier yet, so there is nothing to test. Manual parcels still work.'];
    }
    if (!sh_courier_is_configured($courier)) {
        return ['ok' => false, 'error' => 'Enter the merchant credentials first.'];
    }
    $creds = sh_courier_credentials($courier);
    $http = sh_courier_http('GET', sh_steadfast_base_url($creds) . '/get_balance', sh_steadfast_headers($creds));
    if (!$http['ok']) {
        return ['ok' => false, 'error' => 'Steadfast did not accept the credentials: ' . sh_courier_api_error($http)];
    }
    $bal = $http['body']['current_balance'] ?? null;
    return ['ok' => true, 'message' => 'Connected to Steadfast.' . ($bal !== null ? ' Current balance: ' . sh_money($bal) . '.' : '')];
}
