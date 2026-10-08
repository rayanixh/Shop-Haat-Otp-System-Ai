<?php
/**
 * Order lifecycle, manual payment handling, digital code delivery and the
 * modular automatic-gateway architecture.
 *
 * Everything money-related runs inside a database transaction and is
 * recalculated from database values only.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/notifications.php';

function sh_payment_methods(bool $activeOnly = true): array
{
    try {
        return sh_all(
            'SELECT pm.*, g.code AS gateway_code, g.status AS gateway_status, g.mode AS gateway_mode
             FROM payment_methods pm
             LEFT JOIN payment_gateways g ON g.id = pm.gateway_id
             ' . ($activeOnly ? 'WHERE pm.status = 1' : '') . '
             ORDER BY pm.sort_order ASC, pm.id ASC'
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'payment-methods');
        return [];
    }
}

/**
 * Official brand logos bundled locally for the built-in methods. An admin-uploaded
 * logo (payment_methods.logo) always takes priority; the bundled mark is the fallback.
 * Cash on Delivery has no third-party brand, so it renders a cash/delivery icon.
 */
function sh_payment_brand_logos(): array
{
    return ['bkash' => 'bkash.png', 'nagad' => 'nagad.png', 'rocket' => 'rocket.png'];
}

function sh_payment_logo_url(array $method): string
{
    $own = sh_logo_image($method['logo'] ?? null);
    if ($own !== '') { return $own; }
    $file = sh_payment_brand_logos()[strtolower((string)($method['code'] ?? ''))] ?? null;
    if ($file === null && ($method['type'] ?? '') === 'cod') { $file = 'cod.svg'; }
    if ($file !== null && is_file(SH_ROOT . '/assets/images/payments/' . $file)) {
        return sh_asset('assets/images/payments/' . $file);
    }
    return '';
}

/** Only methods a customer can actually complete right now. */
function sh_payment_methods_available(bool $hasPhysical = true): array
{
    $out = [];
    foreach (sh_payment_methods(true) as $m) {
        if ($m['type'] === 'cod' && !$hasPhysical) { continue; } // no COD for digital-only orders
        if ($m['type'] === 'gateway') {
            if ((int)($m['gateway_status'] ?? 0) !== 1) { continue; }
            $gw = sh_gateway_by_id((int)$m['gateway_id']);
            if ($gw === null || !sh_gateway_is_configured($gw)) { continue; }
        }
        if ($m['type'] === 'manual' && trim((string)$m['account_number']) === '') { continue; }
        $out[] = $m;
    }
    return $out;
}

function sh_payment_method(int $id): ?array
{
    return sh_one('SELECT * FROM payment_methods WHERE id = ? AND status = 1 LIMIT 1', [$id]);
}

// ---------------------------------------------------------------------------
// Order creation
// ---------------------------------------------------------------------------
/**
 * Creates an order atomically from the server-side cart summary.
 * @return array{ok:bool,error?:string,order_id?:int,order_number?:string}
 */
function sh_create_order(array $input): array
{
    $pdo = sh_db();
    $methodId = (int)($input['payment_method_id'] ?? 0);
    $method = sh_payment_method($methodId);
    if ($method === null) {
        return ['ok' => false, 'error' => 'Please choose a valid payment method.'];
    }

    $zone = ($input['delivery_zone'] ?? 'inside') === 'outside' ? 'outside' : 'inside';
    $summary = sh_cart_summary($input['coupon_code'] ?? null, $zone);
    if (!$summary['items']) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }
    if ($method['type'] === 'cod' && !$summary['has_physical']) {
        return ['ok' => false, 'error' => 'Cash on Delivery is not available for digital-only orders.'];
    }

    $userId = sh_user_id();
    // Orders are never created for guests. Every order-creation entry point
    // (checkout.php) requires an authenticated account; this gate is the final
    // server-side backstop so no caller can bypass login and place a guest order.
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Please login to continue to checkout.'];
    }

    // Checkout and order creation are intentionally OTP-free in BOTH
    // authentication modes. A signed-in customer can place unlimited orders
    // during their active session; no verification gate is applied here.

    // Fall back to the account email for phone-only customers who did not
    // supply an order email.
    $email = trim((string)($input['customer_email'] ?? ''));
    if ($email === '' && $userId !== null) {
        $email = (string)(sh_user_field($userId, 'email') ?? '');
    }
    if ($email === '') {
        $email = sh_synthetic_email((string)($input['customer_phone'] ?? ''));
    }

    try {
        $pdo->beginTransaction();

        // Re-verify stock inside the transaction with row locks.
        foreach ($summary['items'] as $it) {
            $row = sh_one('SELECT stock, status, name FROM products WHERE id = ? FOR UPDATE', [$it['product_id']]);
            if ($row === null || (int)$row['status'] !== 1) {
                throw new RuntimeException(($row['name'] ?? 'A product') . ' is no longer available.');
            }
            if ((int)$row['stock'] < $it['quantity']) {
                throw new RuntimeException('Insufficient stock for ' . $row['name'] . '. Please update your cart.');
            }
        }

        $hasDigital = false;
        foreach ($summary['items'] as $it) {
            if ($it['product_type'] === 'digital') { $hasDigital = true; break; }
        }

        require_once SH_ROOT . '/includes/verification.php';
        $orderId = sh_insert('orders', sh_verify_order_ip_fields() + [
            'order_number'        => 'TMP' . bin2hex(random_bytes(6)),
            'user_id'             => $userId,
            'customer_name'       => $input['customer_name'],
            'customer_email'      => $email,
            'customer_phone'      => $input['customer_phone'],
            'phone_verified_at'   => null,
            'verification_required' => 0,
            'verification_method' => null,
            'shipping_address'    => $input['address_line'] ?? null,
            'shipping_area'       => $input['area'] ?? null,
            'shipping_city'       => $input['city'] ?? null,
            'shipping_postcode'   => $input['postcode'] ?? null,
            'order_note'          => $input['note'] ?? null,
            'payment_method_id'   => (int)$method['id'],
            'payment_method_name' => $method['name'],
            'coupon_id'           => $summary['coupon']['id'] ?? null,
            'coupon_code'         => $summary['coupon']['code'] ?? null,
            'subtotal'            => $summary['subtotal'],
            'discount'            => $summary['discount'],
            'delivery_fee'        => $summary['delivery'],
            'total'               => $summary['total'],
            'has_digital'         => $hasDigital ? 1 : 0,
            'status'              => $method['type'] === 'cod' ? 'processing' : 'awaiting_payment',
            'payment_status'      => 'unpaid',
        ]);

        $orderNumber = sh_order_number($orderId);
        sh_query('UPDATE orders SET order_number = ? WHERE id = ?', [$orderNumber, $orderId]);

        foreach ($summary['items'] as $it) {
            sh_order_items_ensure_schema();
            sh_insert('order_items', [
                'order_id'        => $orderId,
                'product_id'      => $it['product_id'],
                'product_name'    => $it['name'],
                'product_image'   => $it['image'],
                'product_variant' => ($it['variant'] ?? '') !== '' ? mb_substr((string)$it['variant'], 0, 190) : null,
                'product_type'  => $it['product_type'],
                'unit_price'    => $it['unit_price'],
                'quantity'      => $it['quantity'],
                'line_total'    => $it['line_total'],
            ]);
            // Reserve stock immediately so two customers cannot buy the same unit.
            sh_query('UPDATE products SET stock = stock - ?, sold_count = sold_count + ? WHERE id = ?',
                [$it['quantity'], $it['quantity'], $it['product_id']]);
        }

        if (!empty($summary['coupon']['id'])) {
            sh_query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$summary['coupon']['id']]);
        }

        // Payment record
        sh_insert('payments', [
            'order_id'          => $orderId,
            'payment_method_id' => (int)$method['id'],
            'gateway_id'        => $method['gateway_id'] ?: null,
            'method_name'       => $method['name'],
            'kind'              => $method['type'],
            'amount'            => $summary['total'],
            'status'            => $method['type'] === 'cod' ? 'pending' : 'pending',
        ]);

        // Clear the cart within the same transaction.
        $cartId = sh_cart_id(false);
        if ($cartId > 0) { sh_query('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]); }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'order-create');
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The order could not be placed. Please try again.'];
    }

    // Post-commit side effects — must never roll the order back.
    sh_session_start();
    $_SESSION['last_order_id'] = $orderId;
    $_SESSION['guest_orders'][] = $orderId;

    $order = sh_order_get($orderId);
    if ($order) {
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            sh_admin_notify('new_order', 'New order ' . $order['order_number'], $order['customer_name'] . ' · ' . sh_money($order['total']) . ' · ' . (string)$order['payment_method_name'], 'admin/orders.php?id=' . $orderId);
            foreach ($summary['items'] as $it) { if (!empty($it['product_id'])) { sh_stock_check_alert((int)$it['product_id']); } }
        } catch (Throwable $e) { sh_log_exception($e, 'admin-notify'); }
        sh_notify('order_created', sh_order_notify_payload($order));
        // Telegram additionally receives an interactive control card. A failure
        // here must never affect the order, so it is fully contained.
        try {
            if (function_exists('sh_tg_push_order')) { sh_tg_push_order((int)$order['id'], '🛒 NEW ORDER'); }
        } catch (Throwable $e) { sh_log_exception($e, 'tg-push-order'); }
        if ($method['type'] === 'cod') { sh_check_low_stock_for_order($orderId); }
    }

    return ['ok' => true, 'order_id' => $orderId, 'order_number' => $orderNumber, 'method_type' => $method['type']];
}

/**
 * Idempotency: an order token held in the session makes duplicate submissions
 * impossible even with rapid double-clicks.
 */
function sh_order_idempotency_token(): string
{
    sh_session_start();
    if (empty($_SESSION['order_token'])) {
        $_SESSION['order_token'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['order_token'];
}

/** Consume the order token after a successful order so a replay cannot double-place. */
function sh_order_idempotency_reset(): void
{
    sh_session_start();
    unset($_SESSION['order_token']);
}

function sh_order_get(int $id): ?array
{
    return sh_one('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
}

/** Add order_items.product_variant on installs that predate it (idempotent, runs once per request). */
function sh_order_items_ensure_schema(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                         ['order_items', 'product_variant'], 0);
        if ($n === 0) {
            sh_db()->exec('ALTER TABLE order_items ADD COLUMN product_variant VARCHAR(190) DEFAULT NULL AFTER product_image');
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-items-schema');
    }
}

/**
 * Thumbnail URL for an order line. Order of preference: the image snapshot
 * saved with the order, the product's current image, the neutral placeholder.
 */
function sh_order_item_image(array $item): string
{
    $snap = (string)($item['product_image'] ?? '');
    if ($snap !== '' && is_file(SH_UPLOAD_DIR . '/products/' . basename($snap))) {
        return sh_product_image($snap);
    }
    if (!empty($item['product_id'])) {
        static $cache = [];
        $pid = (int)$item['product_id'];
        if (!array_key_exists($pid, $cache)) {
            try { $cache[$pid] = (string)sh_val('SELECT image FROM products WHERE id = ?', [$pid], ''); }
            catch (Throwable $e) { $cache[$pid] = ''; }
        }
        if ($cache[$pid] !== '') { return sh_product_image($cache[$pid]); }
    }
    return sh_product_image(null);
}

/** Variant/package line for display; falls back to the live category for legacy orders. */
function sh_order_item_variant_text(array $item): string
{
    $v = trim((string)($item['product_variant'] ?? ''));
    if ($v !== '') { return $v; }
    if (!empty($item['product_id'])) {
        try {
            $r = sh_one('SELECT p.sku, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1', [(int)$item['product_id']]);
            if ($r) { return sh_order_item_variant((string)($r['category_name'] ?? ''), (string)($r['sku'] ?? '')); }
        } catch (Throwable $e) { /* product removed */ }
    }
    return '';
}

/** Items for many orders at once (list screens): [order_id => rows]. */
function sh_order_items_for(array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if (!$orderIds) { return []; }
    sh_order_items_ensure_schema();
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $out = [];
    foreach (sh_all("SELECT * FROM order_items WHERE order_id IN ($ph) ORDER BY order_id, id", $orderIds) as $r) {
        $out[(int)$r['order_id']][] = $r;
    }
    return $out;
}

function sh_order_items(int $orderId): array
{
    sh_order_items_ensure_schema();
    return sh_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

function sh_order_notify_payload(array $order): array
{
    return [
        'order_id'       => (int)$order['id'],
        'order_number'   => $order['order_number'],
        'customer_name'  => $order['customer_name'],
        'customer_email' => $order['customer_email'],
        'customer_phone' => $order['customer_phone'],
        'total'          => $order['total'],
        'payment_method' => $order['payment_method_name'],
        'status'         => $order['status'],
        'items'          => sh_order_items((int)$order['id']),
    ];
}

/** IDOR guard: a customer may only view their own orders. */
function sh_order_can_view(array $order): bool
{
    $uid = sh_user_id();
    if ($uid > 0 && (int)$order['user_id'] === $uid) { return true; }
    sh_session_start();
    $guest = $_SESSION['guest_orders'] ?? [];
    return is_array($guest) && in_array((int)$order['id'], array_map('intval', $guest), true);
}

// ---------------------------------------------------------------------------
// Manual payment submission
// ---------------------------------------------------------------------------
function sh_submit_manual_payment(int $orderId, string $transactionId, string $senderPhone): array
{
    $order = sh_order_get($orderId);
    if ($order === null) { return ['ok' => false, 'error' => 'Order not found.']; }
    if (!sh_order_can_view($order)) { return ['ok' => false, 'error' => 'You are not allowed to modify this order.']; }
    if (in_array($order['payment_status'], ['verified'], true)) {
        return ['ok' => false, 'error' => 'This order has already been paid.'];
    }
    $transactionId = strtoupper(trim($transactionId));
    if (strlen($transactionId) < 4 || strlen($transactionId) > 60) {
        return ['ok' => false, 'error' => 'Enter the transaction ID exactly as shown in your payment confirmation.'];
    }
    if (!sh_valid_phone($senderPhone)) {
        return ['ok' => false, 'error' => 'Enter the mobile number you paid from.'];
    }
    $dupe = sh_one('SELECT id FROM payments WHERE transaction_id = ? AND order_id <> ? AND status <> \'rejected\' LIMIT 1',
        [$transactionId, $orderId]);
    if ($dupe !== null) {
        return ['ok' => false, 'error' => 'This transaction ID has already been submitted for another order.'];
    }

    $pdo = sh_db();
    try {
        $pdo->beginTransaction();
        $pay = sh_one('SELECT id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        if ($pay) {
            sh_query('UPDATE payments SET transaction_id = ?, sender_phone = ?, status = \'pending\' WHERE id = ?',
                [$transactionId, $senderPhone, $pay['id']]);
        } else {
            sh_insert('payments', [
                'order_id'          => $orderId,
                'payment_method_id' => $order['payment_method_id'],
                'method_name'       => $order['payment_method_name'],
                'kind'              => 'manual',
                'amount'            => $order['total'],
                'transaction_id'    => $transactionId,
                'sender_phone'      => $senderPhone,
                'status'            => 'pending',
            ]);
        }
        // Never auto-verify a manual payment.
        sh_query('UPDATE orders SET status = \'payment_submitted\', payment_status = \'submitted\' WHERE id = ?', [$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'manual-payment');
        return ['ok' => false, 'error' => 'Could not record your payment. Please try again.'];
    }

    $fresh = sh_order_get($orderId);
    sh_notify('payment_submitted', array_merge(sh_order_notify_payload($fresh), ['transaction_id' => $transactionId]));
    try {
        require_once SH_ROOT . '/includes/admin-tools.php';
        sh_admin_notify('payment_pending', 'Payment waiting for verification · ' . $fresh['order_number'], sh_money($fresh['total']) . ' · TrxID ' . $transactionId, 'admin/payments.php?status=pending', 'pay-pending-' . $orderId);
    } catch (Throwable $e) {}
    try {
        if (function_exists('sh_tg_push_order')) { sh_tg_push_order($orderId, '💳 PAYMENT SUBMITTED'); }
    } catch (Throwable $e) { sh_log_exception($e, 'tg-push-payment'); }
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Admin verification
// ---------------------------------------------------------------------------
function sh_approve_payment(int $paymentId, int $adminId, string $note = ''): array
{
    $pdo = sh_db();
    $orderId = 0;
    try {
        $pdo->beginTransaction();
        $pay = sh_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if ($pay === null) { throw new RuntimeException('Payment record not found.'); }
        if ($pay['status'] === 'verified') { throw new RuntimeException('This payment is already verified.'); }
        $orderId = (int)$pay['order_id'];

        sh_query('UPDATE payments SET status = \'verified\', verified_by = ?, verified_at = NOW(), admin_note = ? WHERE id = ?',
            [$adminId, mb_substr($note, 0, 250), $paymentId]);
        sh_query('UPDATE orders SET payment_status = \'verified\', status = \'processing\' WHERE id = ?', [$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'payment-approve');
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not approve this payment.'];
    }

    $order = sh_order_get($orderId);
    sh_notify('payment_approved', sh_order_notify_payload($order));
    sh_notify('order_processing', sh_order_notify_payload($order));

    // Digital fulfilment happens only after verified payment.
    if ((int)$order['has_digital'] === 1) {
        sh_deliver_digital_codes($orderId);
    } else {
        sh_check_low_stock_for_order($orderId);
    }
    return ['ok' => true];
}

function sh_reject_payment(int $paymentId, int $adminId, string $note = ''): array
{
    $pdo = sh_db();
    $orderId = 0;
    try {
        $pdo->beginTransaction();
        $pay = sh_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if ($pay === null) { throw new RuntimeException('Payment record not found.'); }
        if ($pay['status'] === 'verified') { throw new RuntimeException('A verified payment cannot be rejected.'); }
        $orderId = (int)$pay['order_id'];
        sh_query('UPDATE payments SET status = \'rejected\', verified_by = ?, verified_at = NOW(), admin_note = ? WHERE id = ?',
            [$adminId, mb_substr($note, 0, 250), $paymentId]);
        sh_query('UPDATE orders SET payment_status = \'rejected\', status = \'payment_rejected\' WHERE id = ?', [$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'payment-reject');
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not reject this payment.'];
    }
    $order = sh_order_get($orderId);
    sh_notify('payment_rejected', array_merge(sh_order_notify_payload($order), ['note' => $note]));
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Digital code delivery (never issues the same code twice)
// ---------------------------------------------------------------------------
function sh_deliver_digital_codes(int $orderId): array
{
    $pdo = sh_db();
    $delivered = [];
    $shortfall = [];
    try {
        $pdo->beginTransaction();
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new RuntimeException('Order not found.'); }
        if ($order['payment_status'] !== 'verified') {
            throw new RuntimeException('Codes can only be delivered after the payment is verified.');
        }
        if ((int)$order['codes_delivered'] === 1) {
            $pdo->commit();
            return ['ok' => true, 'codes' => [], 'already' => true];
        }

        foreach (sh_all('SELECT * FROM order_items WHERE order_id = ? AND product_type = \'digital\'', [$orderId]) as $item) {
            $need = (int)$item['quantity'];
            $already = (int)sh_val('SELECT COUNT(*) FROM product_codes WHERE order_item_id = ?', [(int)$item['id']], 0);
            $need -= $already;
            if ($need <= 0) { continue; }

            // Lock available codes so a concurrent order cannot take them.
            $rows = sh_all(
                'SELECT id, code FROM product_codes
                 WHERE product_id = ? AND status = \'available\'
                 ORDER BY id ASC LIMIT ' . $need . ' FOR UPDATE',
                [(int)$item['product_id']]
            );
            if (count($rows) < $need) {
                $shortfall[] = $item['product_name'] . ' (needed ' . $need . ', available ' . count($rows) . ')';
            }
            foreach ($rows as $c) {
                sh_query(
                    'UPDATE product_codes SET status = \'used\', order_id = ?, order_item_id = ?, delivered_at = NOW()
                     WHERE id = ? AND status = \'available\'',
                    [$orderId, (int)$item['id'], (int)$c['id']]
                );
                $delivered[] = ['product' => $item['product_name'], 'code' => $c['code']];
            }
            // Digital stock mirrors the number of remaining codes.
            sh_query(
                'UPDATE products SET stock = (SELECT COUNT(*) FROM product_codes WHERE product_id = ? AND status = \'available\')
                 WHERE id = ?',
                [(int)$item['product_id'], (int)$item['product_id']]
            );
        }

        if ($shortfall) {
            // Do not half-deliver: roll back and let the admin restock.
            throw new RuntimeException('Not enough digital codes in stock for: ' . implode('; ', $shortfall));
        }

        sh_query('UPDATE orders SET codes_delivered = 1, status = \'completed\' WHERE id = ?', [$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'code-delivery');
        return ['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Code delivery failed.'];
    }

    $order = sh_order_get($orderId);
    $payload = sh_order_notify_payload($order);
    $payload['codes'] = $delivered;
    sh_notify('code_delivered', $payload);
    sh_notify('order_completed', sh_order_notify_payload($order));
    sh_check_low_stock_for_order($orderId);
    return ['ok' => true, 'codes' => $delivered];
}

function sh_order_codes(int $orderId): array
{
    return sh_all(
        'SELECT pc.code, pc.delivered_at, oi.product_name
         FROM product_codes pc
         JOIN order_items oi ON oi.id = pc.order_item_id
         WHERE pc.order_id = ? ORDER BY oi.product_name, pc.id',
        [$orderId]
    );
}

function sh_check_low_stock_for_order(int $orderId): void
{
    try {
        $rows = sh_all(
            'SELECT p.id, p.name, p.stock, p.low_stock_threshold
             FROM order_items oi JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ? AND p.stock <= p.low_stock_threshold',
            [$orderId]
        );
        foreach ($rows as $p) {
            sh_notify('low_stock', [
                'order_id'     => $orderId,
                'product_name' => $p['name'],
                'stock'        => (int)$p['stock'],
            ]);
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'low-stock');
    }
}

function sh_complete_order(int $orderId): array
{
    $order = sh_order_get($orderId);
    if ($order === null) { return ['ok' => false, 'error' => 'Order not found.']; }
    sh_query('UPDATE orders SET status = \'completed\' WHERE id = ?', [$orderId]);
    sh_notify('order_completed', sh_order_notify_payload(sh_order_get($orderId)));
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Automatic gateway architecture
// ---------------------------------------------------------------------------
function sh_gateways(): array
{
    try {
        return sh_all('SELECT * FROM payment_gateways ORDER BY sort_order, id');
    } catch (Throwable $e) {
        sh_log_exception($e, 'gateways');
        return [];
    }
}

function sh_gateway_by_id(int $id): ?array
{
    return sh_one('SELECT * FROM payment_gateways WHERE id = ? LIMIT 1', [$id]);
}

function sh_gateway_credentials(array $gateway): array
{
    $raw = (string)($gateway['credentials'] ?? '');
    if ($raw === '') { return []; }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Required credential fields per driver. */
function sh_gateway_fields(string $driver): array
{
    return match ($driver) {
        'sslcommerz' => ['store_id' => 'Store ID', 'store_password' => 'Store Password'],
        'aamarpay'   => ['store_id' => 'Store ID', 'signature_key' => 'Signature Key'],
        'shurjopay'  => ['username' => 'Merchant Username', 'password' => 'Merchant Password', 'prefix' => 'Order Prefix'],
        default      => ['api_key' => 'API Key', 'api_secret' => 'API Secret'],
    };
}

function sh_gateway_is_configured(array $gateway): bool
{
    $creds = sh_gateway_credentials($gateway);
    foreach (array_keys(sh_gateway_fields($gateway['driver'])) as $f) {
        if (trim((string)($creds[$f] ?? '')) === '') { return false; }
    }
    return true;
}

/**
 * Honest status reporting: we do not ship fabricated live integrations.
 * A driver is only "active" when official credentials have been supplied.
 */
function sh_gateway_status_text(array $gateway): string
{
    if (!sh_gateway_is_configured($gateway)) {
        return 'Not configured — enter the merchant credentials issued by ' . $gateway['name'] . ' to activate this gateway.';
    }
    return ucfirst($gateway['mode']) . ' mode credentials saved.';
}

/**
 * Verifies a gateway callback server-side and settles the order exactly once.
 * Duplicate callbacks are rejected by the unique (gateway_id, gateway_reference) index.
 */
function sh_gateway_settle(int $orderId, int $gatewayId, string $reference, array $payload, bool $verified): array
{
    $pdo = sh_db();
    try {
        $pdo->beginTransaction();
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new RuntimeException('Order not found.'); }

        $existing = sh_one('SELECT id, status FROM payments WHERE gateway_id = ? AND gateway_reference = ? LIMIT 1',
            [$gatewayId, $reference]);
        if ($existing !== null && $existing['status'] === 'verified') {
            $pdo->commit();
            return ['ok' => true, 'duplicate' => true];
        }

        $pay = sh_one('SELECT id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        $status = $verified ? 'verified' : 'failed';
        $safePayload = json_encode(sh_gateway_scrub($payload), JSON_UNESCAPED_SLASHES);
        if ($pay) {
            sh_query('UPDATE payments SET gateway_id = ?, gateway_reference = ?, gateway_payload = ?, status = ?, verified_at = NOW() WHERE id = ?',
                [$gatewayId, $reference, $safePayload, $status, $pay['id']]);
        } else {
            sh_insert('payments', [
                'order_id' => $orderId, 'gateway_id' => $gatewayId, 'kind' => 'gateway',
                'amount' => $order['total'], 'gateway_reference' => $reference,
                'gateway_payload' => $safePayload, 'status' => $status,
            ]);
        }
        if ($verified) {
            sh_query('UPDATE orders SET payment_status = \'verified\', status = \'processing\' WHERE id = ?', [$orderId]);
        } else {
            sh_query('UPDATE orders SET payment_status = \'rejected\', status = \'payment_rejected\' WHERE id = ?', [$orderId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        sh_log_exception($e, 'gateway-settle');
        return ['ok' => false, 'error' => 'Gateway settlement failed.'];
    }

    $order = sh_order_get($orderId);
    if ($verified) {
        sh_notify('payment_approved', sh_order_notify_payload($order));
        if ((int)$order['has_digital'] === 1) { sh_deliver_digital_codes($orderId); }
    } else {
        sh_notify('payment_rejected', sh_order_notify_payload($order));
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            sh_admin_notify('payment_failed', 'Gateway payment failed · ' . $order['order_number'], sh_money($order['total']), 'admin/orders.php?id=' . $orderId, 'pay-failed-' . $orderId);
        } catch (Throwable $e) {}
    }
    return ['ok' => true];
}

/** Remove anything credential-like before persisting a gateway payload. */
function sh_gateway_scrub(array $payload): array
{
    $bad = ['store_passwd', 'store_password', 'signature_key', 'password', 'token', 'api_key', 'api_secret', 'secret'];
    $out = [];
    foreach ($payload as $k => $v) {
        if (in_array(strtolower((string)$k), $bad, true)) { continue; }
        $out[$k] = is_scalar($v) ? mb_substr((string)$v, 0, 300) : '[object]';
    }
    return $out;
}
