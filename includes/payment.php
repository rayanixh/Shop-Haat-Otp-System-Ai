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

/**
 * Is this active method genuinely usable for an order of this type?
 * Keep this check reusable by both the checkout form and the server-side order
 * creator so a crafted payment_method_id cannot create an unpayable order.
 */
function sh_payment_method_available_for_order(array $method, bool $hasPhysical = true): bool
{
    $type = (string)($method['type'] ?? '');
    if (!in_array($type, ['manual', 'cod', 'gateway'], true)) { return false; }
    if ($type === 'cod') { return $hasPhysical; } // no COD for digital-only orders
    if ($type === 'manual') { return trim((string)($method['account_number'] ?? '')) !== ''; }
    $gatewayId = (int)($method['gateway_id'] ?? 0);
    $gateway = $gatewayId > 0 ? sh_gateway_by_id($gatewayId) : null;
    return $gateway !== null
        && (int)($gateway['status'] ?? 0) === 1
        && sh_gateway_is_configured($gateway)
        && sh_gateway_can_initiate($gateway);
}

/** Only methods a customer can actually complete right now. */
function sh_payment_methods_available(bool $hasPhysical = true): array
{
    $out = [];
    foreach (sh_payment_methods(true) as $m) {
        if (sh_payment_method_available_for_order($m, $hasPhysical)) { $out[] = $m; }
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
    if (!sh_payment_method_available_for_order($method, (bool)$summary['has_physical'])) {
        return ['ok' => false, 'error' => 'That payment method is no longer available. Please choose another method.'];
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

    // The browser page validates these too, but this server-side backstop keeps
    // any future/API caller from creating an incomplete order record.
    $customerName = trim((string)($input['customer_name'] ?? ''));
    $customerPhone = trim((string)($input['customer_phone'] ?? ''));
    $addressLine = trim((string)($input['address_line'] ?? ''));
    $area = trim((string)($input['area'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $postcode = trim((string)($input['postcode'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    if ($customerName === '' || mb_strlen($customerName) > 110 || !sh_valid_phone($customerPhone)
        || mb_strlen($addressLine) > 240 || mb_strlen($area) > 120 || mb_strlen($city) > 110
        || mb_strlen($postcode) > 20 || mb_strlen($note) > 480) {
        return ['ok' => false, 'error' => 'Please review your checkout details and try again.'];
    }
    if (!empty($summary['has_physical']) && ($addressLine === '' || mb_strlen($addressLine) > 240 || $city === '' || mb_strlen($city) > 110)) {
        return ['ok' => false, 'error' => 'Please provide a valid delivery address and city.'];
    }

    // Fall back to the account email for phone-only customers who did not
    // supply an order email.
    $email = trim((string)($input['customer_email'] ?? ''));
    if ($email !== '' && !sh_valid_email($email)) {
        return ['ok' => false, 'error' => 'Please provide a valid email address.'];
    }
    if ($email === '' && $userId !== null) {
        $email = (string)(sh_user_field($userId, 'email') ?? '');
    }
    if ($email === '') {
        $email = sh_synthetic_email(sh_phone_normalize($customerPhone));
    }

    // Keep the exact cart rows represented by this server-side summary. A new
    // item added in another tab after checkout begins must not be cleared by the
    // successful order's cleanup.
    $cartId = sh_cart_id(false);
    $cartItemIds = array_values(array_unique(array_filter(array_map(
        static fn(array $item): int => (int)($item['item_id'] ?? 0),
        $summary['items']
    ))));
    if ($cartId <= 0 || !$cartItemIds) {
        return ['ok' => false, 'error' => 'Your cart changed. Please review it and try again.'];
    }

    // IMPORTANT: all lazy schema work happens before BEGIN. MySQL/MariaDB DDL
    // implicitly commits the current connection, which used to make the first
    // checkout report "There is no active transaction" after clearing its cart.
    if ($pdo->inTransaction()) {
        sh_log_line('order-create', 'Refused to start checkout while the shared PDO connection already has an active transaction.');
        return ['ok' => false, 'error' => 'The order could not be started safely. Your cart is unchanged; please try again.'];
    }
    try {
        if (!sh_order_items_ensure_schema()) {
            return ['ok' => false, 'error' => 'The order system is being prepared. Your cart is unchanged; please try again shortly.'];
        }
        require_once SH_ROOT . '/includes/verification.php';
        $verificationFields = sh_verify_order_ip_fields();
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-create-preflight');
        return ['ok' => false, 'error' => 'The order could not be prepared. Your cart is unchanged; please try again.'];
    }

    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;

        // Lock the actual cart rows used for this order before stock/order work.
        // If a stale page refers to a changed cart, fail before writing anything.
        $itemPlaceholders = implode(',', array_fill(0, count($cartItemIds), '?'));
        $lockedCartRows = sh_all(
            'SELECT id, product_id, quantity FROM cart_items WHERE cart_id = ? AND id IN (' . $itemPlaceholders . ') FOR UPDATE',
            array_merge([$cartId], $cartItemIds)
        );
        $expectedCartRows = [];
        foreach ($summary['items'] as $item) {
            $itemId = (int)($item['item_id'] ?? 0);
            if ($itemId <= 0 || isset($expectedCartRows[$itemId])) {
                throw new DomainException('Your cart changed. Please review it and try again.');
            }
            $expectedCartRows[$itemId] = [
                'product_id' => (int)$item['product_id'],
                'quantity' => (int)$item['quantity'],
            ];
        }
        if (count($lockedCartRows) !== count($expectedCartRows)) {
            throw new DomainException('Your cart changed. Please review it and try again.');
        }
        foreach ($lockedCartRows as $row) {
            $expected = $expectedCartRows[(int)$row['id']] ?? null;
            // Do not use an old total to delete a row whose quantity changed in
            // another tab (or whose stock was reduced and had been clamped in
            // the preview). The customer keeps that row and can review it.
            if ($expected === null || (int)$row['product_id'] !== $expected['product_id']
                || (int)$row['quantity'] !== $expected['quantity']) {
                throw new DomainException('Your cart changed. Please review it and try again.');
            }
        }

        // Lock and revalidate the coupon before consuming its usage. The summary
        // was calculated before BEGIN, so without this lock concurrent orders
        // could both consume the last allowed use.
        $lockedCouponId = null;
        if (!empty($summary['coupon']['id'])) {
            $lockedCoupon = sh_one('SELECT * FROM coupons WHERE id = ? FOR UPDATE', [(int)$summary['coupon']['id']]);
            $couponCheck = sh_coupon_evaluate_record($lockedCoupon, (float)$summary['subtotal']);
            if (empty($couponCheck['valid'])) {
                throw new DomainException((string)($couponCheck['error'] ?? 'Your coupon changed. Please review your order and try again.'));
            }
            if (abs((float)$couponCheck['discount'] - (float)$summary['discount']) > 0.004) {
                throw new DomainException('Your coupon discount changed. Please review your order and try again.');
            }
            $lockedCouponId = (int)$lockedCoupon['id'];
        }

        // Re-verify stock inside the transaction with row locks.
        foreach ($summary['items'] as $it) {
            $row = sh_one('SELECT stock, status, name, price, product_type FROM products WHERE id = ? FOR UPDATE', [$it['product_id']]);
            if ($row === null || (int)$row['status'] !== 1) {
                throw new DomainException(($row['name'] ?? 'A product') . ' is no longer available.');
            }
            if ((string)$row['product_type'] !== (string)$it['product_type']
                || abs((float)$row['price'] - (float)$it['unit_price']) > 0.004) {
                throw new DomainException('A product price changed. Please review your cart and try again.');
            }
            if ((int)$row['stock'] < $it['quantity']) {
                throw new DomainException('Insufficient stock for ' . $row['name'] . '. Please update your cart.');
            }
        }

        $hasDigital = false;
        foreach ($summary['items'] as $it) {
            if ($it['product_type'] === 'digital') { $hasDigital = true; break; }
        }

        $orderId = sh_insert('orders', $verificationFields + [
            'order_number'        => 'TMP' . bin2hex(random_bytes(6)),
            'user_id'             => $userId,
            'customer_name'       => $customerName,
            'customer_email'      => $email,
            'customer_phone'      => $customerPhone,
            'phone_verified_at'   => null,
            'verification_required' => 0,
            'verification_method' => null,
            'shipping_address'    => $addressLine,
            'shipping_area'       => $area,
            'shipping_city'       => $city,
            'shipping_postcode'   => $postcode,
            'order_note'          => $note,
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
            sh_insert('order_items', [
                'order_id'        => $orderId,
                'product_id'      => $it['product_id'],
                'product_name'    => $it['name'],
                'product_image'   => $it['image'],
                'product_variant' => ($it['variant'] ?? '') !== '' ? mb_substr((string)$it['variant'], 0, 190) : null,
                'product_type'    => $it['product_type'],
                'unit_price'      => $it['unit_price'],
                'quantity'        => $it['quantity'],
                'line_total'      => $it['line_total'],
            ]);
            // Reserve stock immediately so two customers cannot buy the same unit.
            sh_query('UPDATE products SET stock = stock - ?, sold_count = sold_count + ? WHERE id = ?',
                [$it['quantity'], $it['quantity'], $it['product_id']]);
        }

        if ($lockedCouponId !== null) {
            sh_query('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$lockedCouponId]);
        }

        sh_insert('payments', [
            'order_id'          => $orderId,
            'payment_method_id' => (int)$method['id'],
            'gateway_id'        => $method['gateway_id'] ?: null,
            'method_name'       => $method['name'],
            'kind'              => $method['type'],
            'amount'            => $summary['total'],
            'status'            => 'pending',
        ]);

        // Do not clear a cart after an accidental implicit commit. All current
        // order writes must still be protected by this exact PDO transaction.
        if (!$pdo->inTransaction()) {
            throw new LogicException('Checkout transaction ended unexpectedly before cart cleanup.');
        }

        // Delete only the locked rows that became saved order items. New rows
        // added in another tab remain in the customer cart.
        $deleted = sh_query(
            'DELETE FROM cart_items WHERE cart_id = ? AND id IN (' . $itemPlaceholders . ')',
            array_merge([$cartId], $cartItemIds)
        )->rowCount();
        if ($deleted !== count($cartItemIds)) {
            throw new DomainException('Your cart changed. Please review it and try again.');
        }
        if (!$pdo->inTransaction()) {
            throw new LogicException('Checkout transaction ended unexpectedly before commit.');
        }

        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        $rolledBack = false;
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); $rolledBack = true; }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'order-create-rollback'); }
        }
        sh_log_exception($e, 'order-create');
        return [
            'ok' => false,
            // PDOException extends RuntimeException, so never expose its raw
            // database/provider text (including "There is no active transaction").
            // If a connection failed during COMMIT and PDO no longer knows its
            // state, do not falsely promise the cart/order rolled back; ask the
            // customer to check their orders before retrying instead.
            'error' => $e instanceof DomainException
                ? $e->getMessage()
                : ($rolledBack
                    ? 'The order could not be placed. Your cart is still available; please try again.'
                    : 'We could not confirm the order. Please check your orders before trying again.'),
        ];
    }

    // Post-commit side effects — must never roll the order back.
    sh_session_start();
    $_SESSION['last_order_id'] = $orderId;
    if (!isset($_SESSION['guest_orders']) || !is_array($_SESSION['guest_orders'])) {
        $_SESSION['guest_orders'] = [];
    }
    if (!in_array($orderId, array_map('intval', $_SESSION['guest_orders']), true)) {
        $_SESSION['guest_orders'][] = $orderId;
    }

    // A post-commit reload is only for notifications. If the database becomes
    // temporarily unavailable at this point, keep returning the committed order
    // result so checkout can consume its token and show the payment step.
    try { $order = sh_order_get($orderId); }
    catch (Throwable $e) { sh_log_exception($e, 'order-create-post-commit-reload'); $order = null; }
    if ($method['type'] === 'cod') {
        // Attempt independently of the optional post-commit order reload above.
        // The keyed dispatcher prevents duplicates if the first attempt worked.
        sh_emit_cod_order_created_notifications($orderId);
    }
    if ($order) {
        // Stock warnings are independent of the customer-facing payment flow.
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            foreach ($summary['items'] as $it) {
                if (!empty($it['product_id'])) { sh_stock_check_alert((int)$it['product_id']); }
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'order-stock-notify');
        }
        // Manual/gateway orders intentionally send no NEW ORDER provider message
        // here. Their first consolidated provider event is payment_submitted.
    }

    return ['ok' => true, 'order_id' => $orderId, 'order_number' => $orderNumber, 'method_type' => $method['type']];
}

/**
 * Emit the one allowed initial COD event after its order/payment snapshot is
 * committed. The delivery key is stable, so this helper is also safe recovery
 * if a request ends after commit but before its post-commit side effects.
 */
function sh_emit_cod_order_created_notifications(int $orderId): void
{
    try {
        $order = sh_order_get($orderId);
        $payment = sh_order_latest_payment($orderId);
        if ($order === null || !sh_order_is_cod($order, $payment)) {
            sh_log_line('cod-order-notify', 'Suppressed non-COD or missing order notification for order ' . $orderId);
            return;
        }

        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            sh_admin_notify(
                'new_order',
                'New COD order ' . $order['order_number'],
                $order['customer_name'] . ' · ' . sh_money($order['total']) . ' · ' . (string)$order['payment_method_name'],
                'admin/orders.php?id=' . $orderId,
                'cod-order-' . $orderId
            );
        } catch (Throwable $e) {
            sh_log_exception($e, 'cod-admin-notify');
        }

        // sh_notify claims order-created:<id>:1 per channel before it contacts a
        // provider, so recovery calls cannot create duplicate NEW ORDER media.
        sh_notify('order_created', sh_order_created_notify_payload($order, $payment));
        sh_check_low_stock_for_order($orderId);
    } catch (Throwable $e) {
        sh_log_exception($e, 'cod-order-notify');
    }
}

/**
 * Atomically replace an unpaid/rejected order's selected payment method.
 * This is intentionally server-side rather than two unrelated UPDATEs in the
 * page controller, so an order and its payment snapshot cannot diverge.
 */
function sh_switch_order_payment_method(int $orderId, int $methodId): array
{
    $userId = sh_user_id();
    if ($userId <= 0) { return ['ok' => false, 'error' => 'Please sign in to change the payment method.']; }
    if ($orderId <= 0 || $methodId <= 0) { return ['ok' => false, 'error' => 'Choose a valid payment method.']; }

    $method = sh_payment_method($methodId);
    if ($method === null) { return ['ok' => false, 'error' => 'That payment method is not available.']; }

    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('payment-method-switch', 'Refused method switch while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'The payment method could not be changed safely. Please try again.'];
    }

    $transactionStarted = false;
    $emitCod = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new DomainException('Order not found.'); }
        if ((int)$order['user_id'] !== $userId) { throw new DomainException('You are not allowed to change this order.'); }
        if (in_array((string)$order['payment_status'], ['submitted', 'verified', 'refunded'], true)
            || in_array((string)$order['status'], ['cancelled', 'completed'], true)) {
            throw new DomainException('The payment method can no longer be changed for this order.');
        }

        $hasPhysical = (int)sh_val(
            "SELECT COUNT(*) FROM order_items WHERE order_id = ? AND product_type = 'physical'",
            [$orderId],
            0
        ) > 0;
        if (!sh_payment_method_available_for_order($method, $hasPhysical)) {
            throw new DomainException('That payment method is no longer available. Please choose another method.');
        }

        $payment = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        if ($payment !== null && (string)($payment['kind'] ?? '') === 'cod') {
            // A COD selection has already committed and emitted the sole
            // order-created event. Do not turn it into another payment type.
            throw new DomainException('Cash on Delivery has already been placed for this order.');
        }
        if ((int)$order['payment_method_id'] === (int)$method['id']
            && $payment !== null && (string)($payment['kind'] ?? '') === (string)$method['type']) {
            if (!$pdo->inTransaction()) {
                throw new LogicException('Payment-method transaction ended unexpectedly before unchanged commit.');
            }
            $pdo->commit();
            $transactionStarted = false;
            return ['ok' => true, 'unchanged' => true, 'method_type' => (string)$method['type']];
        }

        $kind = (string)$method['type'];
        $gatewayId = $kind === 'gateway' && !empty($method['gateway_id']) ? (int)$method['gateway_id'] : null;
        $orderStatus = $kind === 'cod' ? 'processing' : 'awaiting_payment';
        sh_query(
            "UPDATE orders
             SET payment_method_id = ?, payment_method_name = ?, payment_status = 'unpaid', status = ?
             WHERE id = ?",
            [(int)$method['id'], (string)$method['name'], $orderStatus, $orderId]
        );

        if ($payment !== null) {
            sh_query(
                "UPDATE payments
                 SET payment_method_id = ?, gateway_id = ?, method_name = ?, kind = ?,
                     transaction_id = NULL, sender_phone = NULL, gateway_reference = NULL,
                     gateway_payload = NULL, status = 'pending', admin_note = NULL,
                     verified_by = NULL, verified_at = NULL
                 WHERE id = ?",
                [(int)$method['id'], $gatewayId, (string)$method['name'], $kind, (int)$payment['id']]
            );
        } else {
            sh_insert('payments', [
                'order_id' => $orderId,
                'payment_method_id' => (int)$method['id'],
                'gateway_id' => $gatewayId,
                'method_name' => (string)$method['name'],
                'kind' => $kind,
                'amount' => $order['total'],
                'status' => 'pending',
            ]);
        }

        if (!$pdo->inTransaction()) {
            throw new LogicException('Payment-method transaction ended unexpectedly before commit.');
        }
        $pdo->commit();
        $transactionStarted = false;
        $emitCod = $kind === 'cod';
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'payment-method-switch-rollback'); }
        }
        sh_log_exception($e, 'payment-method-switch');
        return [
            'ok' => false,
            'error' => $e instanceof DomainException
                ? $e->getMessage()
                : 'The payment method could not be changed. Please try again.',
        ];
    }

    if ($emitCod) { sh_emit_cod_order_created_notifications($orderId); }
    return ['ok' => true, 'method_type' => (string)$method['type']];
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

/**
 * Add order_items.product_variant on installs that predate it.
 *
 * MySQL/MariaDB DDL implicitly commits the active connection.  This migration
 * therefore MUST run before an order transaction starts; doing it after an
 * order insert is what previously left a partially committed order, deleted the
 * cart, and made PDO throw "There is no active transaction" on commit.
 */
function sh_order_items_ensure_schema(): bool
{
    static $done = false;
    static $ready = false;
    if ($done) { return $ready; }

    try {
        $pdo = sh_db();
        if ($pdo->inTransaction()) {
            // Never issue ALTER TABLE in a business transaction. The caller can
            // fail safely and preserve the cart rather than lose atomicity.
            sh_log_line('order-items-schema', 'Deferred product_variant migration because a transaction is active.');
            return false;
        }
        $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                         ['order_items', 'product_variant'], 0);
        if ($n === 0) {
            $pdo->exec('ALTER TABLE order_items ADD COLUMN product_variant VARCHAR(190) DEFAULT NULL AFTER product_image');
        }
        $ready = true;
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-items-schema');
        $ready = false;
    }
    $done = true;
    return $ready;
}

/**
 * Add payment-submission metadata to older installs without touching existing
 * payments.  submission_version gives each rejected/resubmitted payment attempt
 * a stable idempotency key, while submitted_at is the customer-facing event time.
 */
function sh_payment_submission_schema_ensure(): bool
{
    static $done = false;
    static $ok = false;
    if ($done) { return $ok; }
    $done = true;

    try {
        $pdo = sh_db();
        // ALTER TABLE has an implicit commit on MySQL/MariaDB. Both callers run
        // this preflight before BEGIN; guard direct/future callers too.
        if ($pdo->inTransaction()) {
            sh_log_line('payment-submission-schema', 'Deferred payment metadata migration because a transaction is active.');
            return false;
        }
        $st = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute(['payments']);
        $columns = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        if (!isset($columns['submitted_at'])) {
            $pdo->exec('ALTER TABLE payments ADD COLUMN submitted_at DATETIME DEFAULT NULL AFTER status');
        }
        if (!isset($columns['submission_version'])) {
            $pdo->exec('ALTER TABLE payments ADD COLUMN submission_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER submitted_at');
        }

        $idx = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $idx->execute(['payments', 'idx_payments_transaction_id']);
        if ((int)$idx->fetchColumn() === 0) {
            // Supports a locking duplicate-TrxID lookup; a customer can still
            // correct and resubmit their own rejected order record.
            $pdo->exec('ALTER TABLE payments ADD KEY idx_payments_transaction_id (transaction_id)');
        }
        $ok = true;
    } catch (Throwable $e) {
        sh_log_exception($e, 'payment-submission-schema');
    }
    return $ok;
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

/**
 * Resolve an order item's real stored image into a provider-fetchable public URL.
 * A neutral placeholder is deliberately never returned: messaging providers must
 * only receive a picture that actually belongs to the ordered product.
 */
function sh_order_item_public_image_url(array $item): string
{
    $filename = '';
    $snapshot = basename((string)($item['product_image'] ?? ''));
    if ($snapshot !== '' && is_file(SH_UPLOAD_DIR . '/products/' . $snapshot)) {
        $filename = $snapshot;
    } elseif (!empty($item['product_id'])) {
        try {
            $current = basename((string)sh_val(
                'SELECT image FROM products WHERE id = ? LIMIT 1',
                [(int)$item['product_id']],
                ''
            ));
            if ($current !== '' && is_file(SH_UPLOAD_DIR . '/products/' . $current)) {
                $filename = $current;
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'order-item-public-image');
        }
    }
    if ($filename === '') { return ''; }

    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) { return ''; }
    $base = sh_public_site_url();
    if ($base === '') { return ''; }
    return $base . '/uploads/products/' . rawurlencode($filename);
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
        'customer_phone'    => $order['customer_phone'],
        'shipping_address'  => $order['shipping_address'] ?? '',
        'shipping_area'     => $order['shipping_area'] ?? '',
        'shipping_city'     => $order['shipping_city'] ?? '',
        'shipping_postcode' => $order['shipping_postcode'] ?? '',
        'order_note'        => $order['order_note'] ?? '',
        'coupon_code'       => $order['coupon_code'] ?? '',
        'subtotal'          => $order['subtotal'] ?? 0,
        'discount'          => $order['discount'] ?? 0,
        'delivery_fee'      => $order['delivery_fee'] ?? 0,
        'total'             => $order['total'],
        'payment_method'    => $order['payment_method_name'],
        'status'            => $order['status'],
        'payment_status'    => $order['payment_status'] ?? '',
        'order_created_at'  => $order['created_at'] ?? '',
        'items'             => sh_order_items((int)$order['id']),
    ];
}

/** Optional username support for stores that added a users.username field. */
function sh_order_customer_username(array $order): string
{
    $userId = (int)($order['user_id'] ?? 0);
    if ($userId <= 0 || !sh_table_has_column('users', 'username')) { return ''; }
    try {
        return trim((string)sh_val('SELECT username FROM users WHERE id = ? LIMIT 1', [$userId], ''));
    } catch (Throwable $e) {
        sh_log_exception($e, 'payment-customer-username');
        return '';
    }
}

/** Latest persisted payment record for an order (used only for server-side notifications). */
function sh_order_latest_payment(int $orderId): ?array
{
    if ($orderId <= 0) { return null; }
    try {
        return sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-latest-payment');
        return null;
    }
}

/** Determine COD from its immutable payment snapshot before consulting live settings. */
function sh_order_is_cod(array $order, ?array $payment = null): bool
{
    if ($payment !== null && trim((string)($payment['kind'] ?? '')) !== '') {
        return (string)$payment['kind'] === 'cod';
    }
    $methodId = (int)($order['payment_method_id'] ?? 0);
    if ($methodId <= 0) { return false; }
    try {
        return (string)sh_val('SELECT type FROM payment_methods WHERE id = ? LIMIT 1', [$methodId], '') === 'cod';
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-is-cod');
        return false;
    }
}

/**
 * Build the one allowed order-created provider event: a COD order after commit.
 * Its fixed key prevents reloads or duplicate checkout callbacks from re-sending
 * the consolidated summary or any supporting product media.
 */
function sh_order_created_notify_payload(array $order, ?array $payment = null): array
{
    $orderId = (int)($order['id'] ?? 0);
    $payment = $payment ?? sh_order_latest_payment($orderId);
    $payload = sh_order_notify_payload($order);
    $payload['payment_id'] = (int)($payment['id'] ?? 0);
    $payload['payment_amount'] = $payment['amount'] ?? ($order['total'] ?? 0);
    $payload['transaction_id'] = (string)($payment['transaction_id'] ?? '');
    $payload['sender_phone'] = (string)($payment['sender_phone'] ?? '');
    $payload['notification_key'] = 'order-created:' . $orderId . ':1';
    $payload['notification_version'] = 1;
    $username = sh_order_customer_username($order);
    if ($username !== '') { $payload['customer_username'] = $username; }
    return $payload;
}

/**
 * Build the payment-submitted event only from persisted order/payment records.
 * The browser never supplies any product, amount, status or image information.
 */
function sh_payment_submitted_notify_payload(array $order, array $payment): array
{
    $paymentId = (int)($payment['id'] ?? 0);
    $storedVersion = (int)($payment['submission_version'] ?? 0);
    $version = max(1, $storedVersion);
    $submittedAt = (string)($payment['submitted_at'] ?? '');
    if ($submittedAt === '') { $submittedAt = (string)($payment['updated_at'] ?? $payment['created_at'] ?? ''); }
    // On a locked-down legacy host the optional submission_version migration may
    // be unavailable. A fingerprint of persisted fields still distinguishes a
    // genuine corrected re-submission from a browser retry of the same record.
    $legacySuffix = 'legacy-' . substr(hash('sha256', implode('|', [
        (string)($payment['transaction_id'] ?? ''),
        (string)($payment['gateway_reference'] ?? ''),
        (string)($payment['sender_phone'] ?? ''),
        $submittedAt,
    ])), 0, 16);
    $eventSuffix = $storedVersion > 0 ? (string)$storedVersion : $legacySuffix;

    $payload = array_merge(sh_order_notify_payload($order), [
        'payment_id'           => $paymentId,
        // Gateway references are the persisted payment identifier for automatic
        // methods; never require a browser-style manual transaction ID there.
        'transaction_id'       => trim((string)($payment['transaction_id'] ?? '')) !== ''
            ? (string)$payment['transaction_id'] : (string)($payment['gateway_reference'] ?? ''),
        'gateway_reference'    => (string)($payment['gateway_reference'] ?? ''),
        'sender_phone'         => (string)($payment['sender_phone'] ?? ''),
        'payment_amount'        => $payment['amount'] ?? ($order['total'] ?? 0),
        'payment_submitted_at' => $submittedAt,
        'payment_status'       => (string)($order['payment_status'] ?? $payment['status'] ?? 'submitted'),
        'notification_key'     => 'payment-submitted:' . $paymentId . ':' . $eventSuffix,
        'notification_version' => $version,
    ]);
    $username = sh_order_customer_username($order);
    if ($username !== '') { $payload['customer_username'] = $username; }
    return $payload;
}

/**
 * Post-commit initial event for a persisted non-COD payment submission. Calling
 * this again after a browser retry is intentional recovery, not a second
 * provider trigger: the stable payment key is claimed per channel before send.
 */
function sh_emit_payment_submitted_notifications(int $orderId, int $paymentId, string $transactionId = ''): void
{
    try {
        $fresh = sh_order_get($orderId);
        $payment = $paymentId > 0 ? sh_one('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId]) : null;
        if ($fresh === null || $payment === null) {
            sh_log_line('payment', 'Payment submission saved but notification payload could not be reloaded for order ' . $orderId);
            return;
        }
        $eventPayload = sh_payment_submitted_notify_payload($fresh, $payment);
        sh_notify('payment_submitted', $eventPayload);
        try {
            require_once SH_ROOT . '/includes/admin-tools.php';
            sh_admin_notify(
                'payment_pending',
                'Payment waiting for verification · ' . $fresh['order_number'],
                sh_money($fresh['total']) . ' · Customer TrxID ' . ($transactionId !== '' ? $transactionId : (string)($payment['transaction_id'] ?? $payment['gateway_reference'] ?? '')),
                'admin/payments.php?status=pending',
                'pay-pending-' . $orderId . '-' . substr(hash('sha256', (string)$eventPayload['notification_key']), 0, 20)
            );
        } catch (Throwable $e) {
            sh_log_exception($e, 'payment-admin-notify');
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'payment-submission-notify');
    }
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
    $hasSubmissionMeta = sh_payment_submission_schema_ensure();

    // Payment submission is a customer-only action. Unlike the legacy
    // guest_orders display allowance, it must match the authenticated order owner.
    $userId = sh_user_id();
    if ($userId <= 0) { return ['ok' => false, 'error' => 'Please sign in to submit payment details.']; }
    if ($orderId <= 0) { return ['ok' => false, 'error' => 'Order not found.']; }

    $transactionId = mb_strtoupper(trim($transactionId));
    if (mb_strlen($transactionId) < 4 || mb_strlen($transactionId) > 60
        || preg_match('/^[^\s\x00-\x1f\x7f]+$/u', $transactionId) !== 1) {
        return ['ok' => false, 'error' => 'Enter the transaction ID exactly as shown in your payment confirmation.'];
    }
    if (!sh_valid_phone($senderPhone)) {
        return ['ok' => false, 'error' => 'Enter the mobile number you paid from.'];
    }
    $senderPhone = sh_phone_normalize($senderPhone);
    if ($senderPhone === '') {
        return ['ok' => false, 'error' => 'Enter the mobile number you paid from.'];
    }

    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('manual-payment', 'Refused payment submission while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'Your payment could not be recorded safely. Please try again.'];
    }
    $paymentId = 0;
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new DomainException('Order not found.'); }
        if ((int)$order['user_id'] !== $userId) {
            throw new DomainException('You are not allowed to modify this order.');
        }
        if (in_array((string)$order['payment_status'], ['verified', 'refunded'], true)) {
            throw new DomainException('This order has already been paid.');
        }
        if (in_array((string)$order['status'], ['cancelled', 'completed'], true)) {
            throw new DomainException('Payment details can no longer be submitted for this order.');
        }

        $pay = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        if ((string)$order['payment_status'] === 'submitted') {
            // A browser/network retry after the successful database commit is
            // idempotent. It receives success but never emits a second event.
            if ($pay !== null && hash_equals((string)($pay['transaction_id'] ?? ''), $transactionId)) {
                $paymentId = (int)$pay['id'];
                if (!$pdo->inTransaction()) {
                    throw new LogicException('Payment submission transaction ended unexpectedly before idempotent commit.');
                }
                $pdo->commit();
                $transactionStarted = false;
                // Recover safely if the original request died between its
                // committed payment and post-commit provider dispatch. A sent
                // keyed event is claimed and therefore never sent twice.
                sh_emit_payment_submitted_notifications($orderId, $paymentId, $transactionId);
                return ['ok' => true, 'already_submitted' => true];
            }
            throw new DomainException('Payment details are already pending verification for this order.');
        }

        // Never allow the JSON endpoint to turn a COD/gateway order into a
        // manual payment. Existing manual records remain valid even if an admin
        // later disables their payment-method row.
        $isManual = $pay !== null && (string)($pay['kind'] ?? '') === 'manual';
        if (!$isManual) {
            $method = !empty($order['payment_method_id'])
                ? sh_one('SELECT type FROM payment_methods WHERE id = ? LIMIT 1', [(int)$order['payment_method_id']])
                : null;
            $isManual = $method !== null && (string)$method['type'] === 'manual';
        }
        if (!$isManual) {
            throw new DomainException('This order is not using a manual payment method.');
        }

        // This lookup is performed inside the transaction and uses the indexed
        // transaction_id column, preserving duplicate detection across orders.
        $dupe = sh_one(
            "SELECT id FROM payments
             WHERE transaction_id = ? AND order_id <> ?
             LIMIT 1 FOR UPDATE",
            [$transactionId, $orderId]
        );
        if ($dupe !== null) {
            throw new DomainException('This transaction ID has already been submitted for another order.');
        }

        if ($pay !== null) {
            $paymentId = (int)$pay['id'];
            if ($hasSubmissionMeta) {
                sh_query(
                    "UPDATE payments
                     SET transaction_id = ?, sender_phone = ?, status = 'pending',
                         admin_note = NULL, verified_by = NULL, verified_at = NULL,
                         submitted_at = NOW(), submission_version = submission_version + 1
                     WHERE id = ?",
                    [$transactionId, $senderPhone, $paymentId]
                );
            } else {
                // A locked-down host may deny ALTER TABLE. Preserve the pre-
                // migration payment workflow instead of rejecting the payment.
                sh_query(
                    "UPDATE payments
                     SET transaction_id = ?, sender_phone = ?, status = 'pending',
                         admin_note = NULL, verified_by = NULL, verified_at = NULL
                     WHERE id = ?",
                    [$transactionId, $senderPhone, $paymentId]
                );
            }
        } else {
            $newPayment = [
                'order_id'          => $orderId,
                'payment_method_id' => $order['payment_method_id'],
                'method_name'       => $order['payment_method_name'],
                'kind'              => 'manual',
                'amount'            => $order['total'],
                'transaction_id'    => $transactionId,
                'sender_phone'      => $senderPhone,
                'status'            => 'pending',
            ];
            if ($hasSubmissionMeta) {
                $newPayment['submitted_at'] = date('Y-m-d H:i:s');
                $newPayment['submission_version'] = 1;
            }
            $paymentId = sh_insert('payments', $newPayment);
        }

        // A manual submission is always pending verification; it never approves
        // itself regardless of a valid-looking customer Transaction ID.
        sh_query("UPDATE orders SET status = 'payment_submitted', payment_status = 'submitted' WHERE id = ?", [$orderId]);
        if (!$pdo->inTransaction()) {
            throw new LogicException('Payment submission transaction ended unexpectedly before commit.');
        }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'manual-payment-rollback'); }
        }
        if ($e instanceof DomainException) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        sh_log_exception($e, 'manual-payment');
        return ['ok' => false, 'error' => 'Could not record your payment. Please try again.'];
    }

    // All notification work is deliberately post-commit. A provider timeout or
    // bad remote image can be retried without undoing the saved payment.
    sh_emit_payment_submitted_notifications($orderId, $paymentId, $transactionId);

    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Admin verification
// ---------------------------------------------------------------------------
function sh_approve_payment(int $paymentId, int $adminId, string $note = ''): array
{
    // Telegram's authorized control surface records a system verifier as 0, so
    // only the payment ID itself is required here.
    if ($paymentId <= 0) { return ['ok' => false, 'error' => 'Payment approval could not be recorded.']; }
    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('payment-approve', 'Refused approval while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'Payment approval could not be recorded safely.'];
    }
    $orderId = 0;
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $pay = sh_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if ($pay === null) { throw new DomainException('Payment record not found.'); }
        if ((string)$pay['status'] === 'verified') { throw new DomainException('This payment is already verified.'); }
        $orderId = (int)$pay['order_id'];
        $order = sh_one('SELECT id, status, payment_status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null || in_array((string)$order['status'], ['cancelled', 'completed'], true)) {
            throw new DomainException('This order can no longer accept a payment approval.');
        }

        sh_query("UPDATE payments SET status = 'verified', verified_by = ?, verified_at = NOW(), admin_note = ? WHERE id = ?",
            [$adminId, mb_substr($note, 0, 250), $paymentId]);
        sh_query("UPDATE orders SET payment_status = 'verified', status = 'processing' WHERE id = ?", [$orderId]);
        if (!$pdo->inTransaction()) { throw new LogicException('Payment approval transaction ended unexpectedly before commit.'); }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'payment-approve-rollback'); }
        }
        sh_log_exception($e, 'payment-approve');
        return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Could not approve this payment.'];
    }

    try {
        $order = sh_order_get($orderId);
        if ($order === null) { throw new RuntimeException('The committed order could not be reloaded.'); }
        sh_notify('payment_approved', sh_order_notify_payload($order));
        sh_notify('order_processing', sh_order_notify_payload($order));

        // Digital fulfilment happens only after verified payment.
        if ((int)$order['has_digital'] === 1) {
            sh_deliver_digital_codes($orderId);
        } else {
            sh_check_low_stock_for_order($orderId);
        }
    } catch (Throwable $e) {
        // Approval is committed even if a post-commit notification/reload has a
        // transient problem. Never report it to the admin as an undone payment.
        sh_log_exception($e, 'payment-approve-post-commit');
    }
    return ['ok' => true];
}

function sh_reject_payment(int $paymentId, int $adminId, string $note = ''): array
{
    // Telegram's authorized control surface records a system verifier as 0, so
    // only the payment ID itself is required here.
    if ($paymentId <= 0) { return ['ok' => false, 'error' => 'Payment rejection could not be recorded.']; }
    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('payment-reject', 'Refused rejection while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'Payment rejection could not be recorded safely.'];
    }
    $orderId = 0;
    $alreadyRejected = false;
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $pay = sh_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if ($pay === null) { throw new DomainException('Payment record not found.'); }
        if ((string)$pay['status'] === 'verified') { throw new DomainException('A verified payment cannot be rejected.'); }
        $orderId = (int)$pay['order_id'];
        $order = sh_one('SELECT id, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null || in_array((string)($order['status'] ?? ''), ['cancelled', 'completed'], true)) {
            throw new DomainException('This order can no longer accept a payment rejection.');
        }
        if ((string)$pay['status'] === 'rejected') {
            $alreadyRejected = true;
        } else {
            sh_query("UPDATE payments SET status = 'rejected', verified_by = ?, verified_at = NOW(), admin_note = ? WHERE id = ?",
                [$adminId, mb_substr($note, 0, 250), $paymentId]);
            sh_query("UPDATE orders SET payment_status = 'rejected', status = 'payment_rejected' WHERE id = ?", [$orderId]);
        }
        if (!$pdo->inTransaction()) { throw new LogicException('Payment rejection transaction ended unexpectedly before commit.'); }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'payment-reject-rollback'); }
        }
        sh_log_exception($e, 'payment-reject');
        return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Could not reject this payment.'];
    }
    if ($alreadyRejected) { return ['ok' => true, 'already_rejected' => true]; }

    try {
        $order = sh_order_get($orderId);
        if ($order === null) { throw new RuntimeException('The committed order could not be reloaded.'); }
        sh_notify('payment_rejected', array_merge(sh_order_notify_payload($order), ['note' => $note]));
    } catch (Throwable $e) {
        sh_log_exception($e, 'payment-reject-post-commit');
    }
    return ['ok' => true];
}

// ---------------------------------------------------------------------------
// Digital code delivery (never issues the same code twice)
// ---------------------------------------------------------------------------
function sh_deliver_digital_codes(int $orderId): array
{
    if ($orderId <= 0) { return ['ok' => false, 'error' => 'Order not found.']; }
    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('code-delivery', 'Refused digital delivery while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'Code delivery could not be recorded safely.'];
    }
    $delivered = [];
    $shortfall = [];
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new DomainException('Order not found.'); }
        if ((string)$order['payment_status'] !== 'verified') {
            throw new DomainException('Codes can only be delivered after the payment is verified.');
        }
        if ((int)$order['codes_delivered'] === 1) {
            if (!$pdo->inTransaction()) { throw new LogicException('Code-delivery transaction ended unexpectedly before idempotent commit.'); }
            $pdo->commit();
            $transactionStarted = false;
            return ['ok' => true, 'codes' => [], 'already' => true];
        }

        foreach (sh_all("SELECT * FROM order_items WHERE order_id = ? AND product_type = 'digital'", [$orderId]) as $item) {
            $need = (int)$item['quantity'];
            $already = (int)sh_val('SELECT COUNT(*) FROM product_codes WHERE order_item_id = ?', [(int)$item['id']], 0);
            $need -= $already;
            if ($need <= 0) { continue; }

            // Lock available codes so a concurrent order cannot take them.
            $rows = sh_all(
                "SELECT id, code FROM product_codes
                 WHERE product_id = ? AND status = 'available'
                 ORDER BY id ASC LIMIT " . $need . ' FOR UPDATE',
                [(int)$item['product_id']]
            );
            if (count($rows) < $need) {
                $shortfall[] = $item['product_name'] . ' (needed ' . $need . ', available ' . count($rows) . ')';
            }
            foreach ($rows as $c) {
                sh_query(
                    "UPDATE product_codes SET status = 'used', order_id = ?, order_item_id = ?, delivered_at = NOW()
                     WHERE id = ? AND status = 'available'",
                    [$orderId, (int)$item['id'], (int)$c['id']]
                );
                $delivered[] = ['product' => $item['product_name'], 'code' => $c['code']];
            }
            // Digital stock mirrors the number of remaining codes.
            sh_query(
                "UPDATE products SET stock = (SELECT COUNT(*) FROM product_codes WHERE product_id = ? AND status = 'available')
                 WHERE id = ?",
                [(int)$item['product_id'], (int)$item['product_id']]
            );
        }

        if ($shortfall) {
            // Do not half-deliver: roll back and let the admin restock.
            throw new DomainException('Not enough digital codes in stock for: ' . implode('; ', $shortfall));
        }

        $hasPhysicalItems = (int)sh_val("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND product_type = 'physical'", [$orderId], 0) > 0;
        // A mixed basket can receive its digital codes now, but its physical
        // shipment remains in processing rather than falsely completing the
        // entire order.
        sh_query("UPDATE orders SET codes_delivered = 1, status = ? WHERE id = ?", [$hasPhysicalItems ? 'processing' : 'completed', $orderId]);
        if (!$pdo->inTransaction()) { throw new LogicException('Code-delivery transaction ended unexpectedly before commit.'); }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'code-delivery-rollback'); }
        }
        sh_log_exception($e, 'code-delivery');
        return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Code delivery failed.'];
    }

    try {
        $order = sh_order_get($orderId);
        if ($order === null) { throw new RuntimeException('The committed order could not be reloaded.'); }
        $payload = sh_order_notify_payload($order);
        $payload['codes'] = $delivered;
        sh_notify('code_delivered', $payload);
        if ((string)$order['status'] === 'completed') {
            sh_notify('order_completed', sh_order_notify_payload($order));
        }
        sh_check_low_stock_for_order($orderId);
    } catch (Throwable $e) {
        // The irreversible code allocation is committed; notification trouble is
        // logged for recovery and never represented as a failed delivery action.
        sh_log_exception($e, 'code-delivery-post-commit');
    }
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
    if ($orderId <= 0) { return ['ok' => false, 'error' => 'Order not found.']; }
    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('order-complete', 'Refused completion while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'The order could not be completed safely.'];
    }
    $already = false;
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new DomainException('Order not found.'); }
        if ((string)$order['status'] === 'cancelled') { throw new DomainException('A cancelled order cannot be completed.'); }
        if ((string)$order['status'] === 'completed') {
            $already = true;
        } else {
            sh_query("UPDATE orders SET status = 'completed' WHERE id = ?", [$orderId]);
        }
        if (!$pdo->inTransaction()) { throw new LogicException('Order-completion transaction ended unexpectedly before commit.'); }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'order-complete-rollback'); }
        }
        sh_log_exception($e, 'order-complete');
        return ['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'The order could not be completed.'];
    }
    if ($already) { return ['ok' => true, 'already' => true]; }

    try {
        $order = sh_order_get($orderId);
        if ($order === null) { throw new RuntimeException('The committed order could not be reloaded.'); }
        sh_notify('order_completed', sh_order_notify_payload($order));
    } catch (Throwable $e) {
        sh_log_exception($e, 'order-complete-post-commit');
    }
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
 * Whether this installation has a real server-side checkout-initiation driver.
 * Callback verification alone is not an initiation integration: presenting a
 * redirect/payment success without creating a provider session would strand a
 * customer's persisted order. No current driver implements that first request.
 */
function sh_gateway_can_initiate(array $gateway): bool
{
    return false;
}

/** Honest status reporting: configured credentials do not imply live checkout. */
function sh_gateway_status_text(array $gateway): string
{
    if (!sh_gateway_is_configured($gateway)) {
        return 'Not configured — enter the merchant credentials issued by ' . $gateway['name'] . ' to activate this gateway.';
    }
    if (!sh_gateway_can_initiate($gateway)) {
        return ucfirst((string)$gateway['mode']) . ' credentials are saved, but no server-side checkout-initiation driver is implemented. Customer checkout is kept unavailable rather than showing a fake payment redirect.';
    }
    return ucfirst((string)$gateway['mode']) . ' mode checkout is configured.';
}

/**
 * Verifies a gateway callback server-side and settles the order exactly once.
 * Duplicate callbacks are rejected by the unique (gateway_id, gateway_reference) index.
 */
function sh_gateway_settle(int $orderId, int $gatewayId, string $reference, array $payload, bool $verified): array
{
    $reference = trim($reference);
    if ($orderId <= 0 || $gatewayId <= 0 || $reference === '') {
        sh_log_line('gateway-settle', 'Refused callback with a missing order, gateway, or provider reference.');
        return ['ok' => false, 'error' => 'Gateway settlement could not be recorded safely.'];
    }
    // Gateway callbacks are also persisted payment submissions. Ensure the same
    // stable submission metadata used by manual payment notices is available.
    $hasSubmissionMeta = sh_payment_submission_schema_ensure();
    $pdo = sh_db();
    if ($pdo->inTransaction()) {
        sh_log_line('gateway-settle', 'Refused gateway settlement while the shared PDO connection already has a transaction.');
        return ['ok' => false, 'error' => 'Gateway settlement could not be recorded safely.'];
    }

    $paymentId = 0;
    $transactionStarted = false;
    try {
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = sh_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) { throw new DomainException('Order not found.'); }
        $existing = sh_one(
            'SELECT id, order_id, status FROM payments WHERE gateway_id = ? AND gateway_reference = ? LIMIT 1 FOR UPDATE',
            [$gatewayId, $reference]
        );
        if ($existing !== null && (int)$existing['order_id'] !== $orderId) {
            throw new DomainException('This gateway reference belongs to a different order.');
        }
        // Both a confirmed success and a confirmed decline are terminal for the
        // exact pre-bound provider reference. A provider retry must acknowledge
        // that settled outcome rather than re-emitting payment events.
        if ($existing !== null && in_array((string)$existing['status'], ['verified', 'failed', 'rejected', 'cancelled'], true)) {
            if (!$pdo->inTransaction()) { throw new LogicException('Gateway transaction ended unexpectedly before duplicate commit.'); }
            $pdo->commit();
            $transactionStarted = false;
            return [
                'ok' => true,
                'duplicate' => true,
                'verified' => (string)$existing['status'] === 'verified',
            ];
        }
        if (in_array((string)$order['status'], ['cancelled', 'completed'], true)) {
            throw new DomainException('This order can no longer accept a gateway payment.');
        }
        if (in_array((string)$order['payment_status'], ['verified', 'refunded'], true)) {
            throw new DomainException('This order payment has already been finalized.');
        }

        // Always validate the immutable order selection as well as any payment
        // row. A legacy gateway payment with a NULL gateway_id must not become
        // an authorization to settle it through an arbitrary configured gateway.
        $method = !empty($order['payment_method_id'])
            ? sh_one('SELECT type, gateway_id FROM payment_methods WHERE id = ? LIMIT 1', [(int)$order['payment_method_id']])
            : null;
        if ($method === null || (string)$method['type'] !== 'gateway' || (int)($method['gateway_id'] ?? 0) !== $gatewayId) {
            throw new DomainException('The gateway does not match this order.');
        }

        $pay = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        // A callback may settle only the payment method/persisted payment record
        // selected for this order. This prevents a valid reference from one
        // gateway being used to mutate a COD or manual order. The reference must
        // also have been bound by a real server-side initiator before the
        // callback; an inbound request never establishes that binding itself.
        if ($pay === null || (string)($pay['kind'] ?? '') !== 'gateway'
            || (int)($pay['gateway_id'] ?? 0) !== $gatewayId
            || trim((string)($pay['gateway_reference'] ?? '')) === ''
            || !hash_equals(trim((string)$pay['gateway_reference']), $reference)) {
            throw new DomainException('The gateway callback does not match this order payment.');
        }

        $status = $verified ? 'verified' : 'failed';
        $safePayload = json_encode(sh_gateway_scrub($payload), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($safePayload === false) { $safePayload = '{}'; }
        if ($pay) {
            $paymentId = (int)$pay['id'];
            if ($hasSubmissionMeta && $verified) {
                sh_query(
                    "UPDATE payments
                     SET gateway_id = ?, gateway_reference = ?, gateway_payload = ?, status = ?, verified_at = NOW(),
                         submitted_at = COALESCE(submitted_at, NOW()),
                         submission_version = CASE WHEN submission_version < 1 THEN 1 ELSE submission_version END
                     WHERE id = ?",
                    [$gatewayId, $reference, $safePayload, $status, $paymentId]
                );
            } else {
                sh_query('UPDATE payments SET gateway_id = ?, gateway_reference = ?, gateway_payload = ?, status = ?, verified_at = NOW() WHERE id = ?',
                    [$gatewayId, $reference, $safePayload, $status, $paymentId]);
            }
        } else {
            throw new LogicException('Gateway payment record disappeared before settlement.');
        }
        if ($verified) {
            sh_query("UPDATE orders SET payment_status = 'verified', status = 'processing' WHERE id = ?", [$orderId]);
        } else {
            sh_query("UPDATE orders SET payment_status = 'rejected', status = 'payment_rejected' WHERE id = ?", [$orderId]);
        }
        if (!$pdo->inTransaction()) {
            throw new LogicException('Gateway settlement transaction ended unexpectedly before commit.');
        }
        $pdo->commit();
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            try { $pdo->rollBack(); }
            catch (Throwable $rollbackError) { sh_log_exception($rollbackError, 'gateway-settle-rollback'); }
        }
        sh_log_exception($e, 'gateway-settle');
        return ['ok' => false, 'error' => 'Gateway settlement failed.'];
    }

    $order = sh_order_get($orderId);
    $payment = $paymentId > 0 ? sh_one('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId]) : null;
    if ($order === null) {
        sh_log_line('gateway-settle', 'Settlement committed but the order could not be reloaded: ' . $orderId);
        return ['ok' => true];
    }
    if ($verified) {
        // The one permitted initial provider event for every non-COD route is
        // emitted only after the successful payment record commits. Approval is
        // a legitimate later lifecycle event and remains separate.
        if ($payment !== null) {
            try { sh_notify('payment_submitted', sh_payment_submitted_notify_payload($order, $payment)); }
            catch (Throwable $e) { sh_log_exception($e, 'gateway-payment-submitted-notify'); }
        } else {
            sh_log_line('gateway-settle', 'Verified payment could not be reloaded for order ' . $orderId);
        }
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
