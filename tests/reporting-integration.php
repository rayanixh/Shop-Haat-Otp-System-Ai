<?php
/**
 * Reporting integration test.
 *
 * This is intentionally a disposable-database test. It exercises the existing
 * checkout, manual-payment approval and reporting helpers against the canonical
 * MySQL schema; it never creates reporting tables or changes application data.
 *
 * Run with a test-only config/database.php that points at an empty MySQL/MariaDB
 * database. The GitHub Actions workflow creates that configuration.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/reporting-integration.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/reporting.php';
require_once SH_ROOT . '/includes/order-timeline.php';

/** @param mixed $actual @param mixed $expected */
function reporting_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

function reporting_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function reporting_assert_money(float $expected, $actual, string $message): void
{
    if (abs($expected - (float)$actual) > 0.004) {
        throw new RuntimeException($message . ' (expected ' . number_format($expected, 2, '.', '')
            . ', got ' . number_format((float)$actual, 2, '.', '') . ')');
    }
}

/** @return array<string,mixed> */
function reporting_timeline_step(array $timeline, string $key): array
{
    foreach (($timeline['steps'] ?? []) as $step) {
        if (($step['key'] ?? '') === $key) { return $step; }
    }
    throw new RuntimeException('Timeline step not found: ' . $key);
}

/** @return int */
function reporting_test_insert_order(array $values): int
{
    return sh_insert('orders', $values + [
        'order_number' => 'SH-TEST-' . bin2hex(random_bytes(4)),
        'customer_name' => 'Reporting Guest',
        'customer_email' => 'guest-reporting@example.test',
        'customer_phone' => '01700000009',
        'subtotal' => 0.00,
        'discount' => 0.00,
        'delivery_fee' => 0.00,
        'total' => 0.00,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
}

try {
    $pdo = sh_db();
    // This test database is empty in CI. The guard keeps accidental execution
    // against a populated database from silently mixing fixture data with it.
    foreach (sh_schema_tables() as $table) {
        $count = (int)sh_val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$table], 0);
        if ($count > 0) {
            $rows = (int)sh_val('SELECT COUNT(*) FROM `' . $table . '`', [], 0);
            reporting_assert_same(0, $rows, 'Reporting integration test requires an empty database');
        }
    }

    foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }
    sh_schema_seed($pdo, ['site_name' => 'ShopHaat Reporting Test', 'admin_email' => 'admin@example.test']);

    $adminId = sh_insert('admins', [
        'name' => 'Reporting Admin',
        'email' => 'reporting-admin@example.test',
        'password_hash' => password_hash('not-used-by-test', PASSWORD_DEFAULT),
        'role' => 'superadmin',
        'status' => 'active',
    ]);
    $userId = sh_insert('users', [
        'name' => 'Reporting Customer',
        'email' => 'reporting-customer@example.test',
        'phone' => '01700000001',
        'password_hash' => password_hash('not-used-by-test', PASSWORD_DEFAULT),
        'status' => 'active',
    ]);

    $productA = sh_insert('products', [
        'name' => 'Report Phone',
        'slug' => 'report-phone',
        'sku' => 'REP-PHONE',
        'price' => 400.00,
        'compare_price' => 450.00,
        'stock' => 30,
        'product_type' => 'physical',
        'image' => 'report-phone.jpg',
        'status' => 1,
    ]);
    $productB = sh_insert('products', [
        'name' => 'Report Charger',
        'slug' => 'report-charger',
        'sku' => 'REP-CHARGER',
        'price' => 200.00,
        'stock' => 30,
        'product_type' => 'physical',
        'image' => 'report-charger.jpg',
        'status' => 1,
    ]);
    $zeroProduct = sh_insert('products', [
        'name' => 'No Sales Product',
        'slug' => 'no-sales-product',
        'sku' => 'REP-ZERO',
        'price' => 99.00,
        'stock' => 30,
        'product_type' => 'physical',
        'status' => 1,
    ]);
    sh_insert('coupons', [
        'code' => 'REPORT100',
        'type' => 'fixed',
        'value' => 100.00,
        'min_order' => 0.00,
        'max_discount' => 0.00,
        'status' => 1,
    ]);

    $manualMethod = sh_one("SELECT * FROM payment_methods WHERE code = 'bkash' LIMIT 1");
    $codMethod = sh_one("SELECT * FROM payment_methods WHERE code = 'cod' LIMIT 1");
    reporting_assert($manualMethod !== null && $codMethod !== null, 'Seeded payment methods are required');

    // Session-expiry recovery: auth is never trusted after its TTL, a harmless
    // browser hint turns a host-purged session into a Login notice, CSRF rotates
    // at login, and the independent cart token survives recovery.
    sh_session_start();
    $_SESSION = [];
    $_SESSION['cart_token'] = str_repeat('a', 64);
    $_SESSION['csrf_token'] = 'old-form-token';
    sh_login_user($userId);
    reporting_assert(sh_customer_auth_hint_present(), 'Login must set only the non-authentication expiry hint cookie');
    reporting_assert(!isset($_SESSION['csrf_token']), 'Login must rotate the prior CSRF token');
    $freshCsrf = sh_csrf_token();
    reporting_assert($freshCsrf !== '' && $freshCsrf !== 'old-form-token', 'A fresh CSRF token must be issued after login');
    reporting_assert_same(str_repeat('a', 64), $_SESSION['cart_token'], 'Login must preserve the cart token');

    $_SESSION['user_login_at'] = time() - sh_session_ttl() - 1;
    reporting_assert(sh_customer_session_expired(), 'A customer login beyond its TTL must be treated as expired');
    reporting_assert_same(null, sh_user(), 'Expired customer session must not resolve to an authenticated user');
    reporting_assert_same(str_repeat('a', 64), $_SESSION['cart_token'], 'Automatic logout must not delete the cart token');
    $expiredPayload = sh_customer_auth_required_payload('checkout.php', true);
    reporting_assert_same(true, $expiredPayload['auth_required'], 'Expired API response must request sign-in');
    reporting_assert_same(true, $expiredPayload['session_expired'], 'Expired API response must identify session expiration');
    reporting_assert(str_contains($expiredPayload['login_url'], 'login.php?redirect=checkout.php'), 'Expired API response must preserve the checkout return target');
    $expiredCsrf = sh_csrf_json_failure(true, 'checkout.php');
    reporting_assert_same(401, $expiredCsrf['status'], 'Expired AJAX form must receive an authentication-required response');
    reporting_assert_same(true, $expiredCsrf['payload']['session_expired'], 'Expired AJAX form must identify the expired session');
    $staleCsrf = sh_csrf_json_failure(false, 'checkout.php');
    reporting_assert_same(403, $staleCsrf['status'], 'Stale CSRF form must be rejected without an invalid-session status page');
    reporting_assert_same(true, $staleCsrf['payload']['refresh_required'], 'Stale CSRF form must request a safe reload rather than replay');
    reporting_assert(sh_consume_customer_session_expired_notice(), 'Expired customer notice must be consumable once');
    $expiryFlash = sh_flash_pull();
    reporting_assert_same('Your session has expired. Please log in again to continue.', (string)($expiryFlash[0]['message'] ?? ''), 'Login must receive the clean session-expired notice');
    reporting_assert(!sh_customer_auth_hint_present(), 'Expiry hint must clear after the notice is prepared');

    // Simulate server-side session garbage collection while the browser still
    // has its non-authentication marker cookie.
    sh_login_user($userId);
    reporting_assert(sh_customer_auth_hint_present(), 'A renewed login must recreate the expiry hint');
    $_SESSION = [];
    reporting_assert(sh_customer_session_expired(), 'Lost server-side session must be detected from the browser expiry hint');
    reporting_assert(sh_consume_customer_session_expired_notice(), 'Lost server-side session must still create a Login notice');
    sh_flash_pull();

    // Place a real cart-backed checkout with two item lines and a persisted coupon.
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_login_at'] = time();
    $_SESSION['cart_token'] = str_repeat('a', 64);
    reporting_assert(!empty(sh_cart_add($productA, 2)['ok']), 'First product must enter the cart');
    reporting_assert(!empty(sh_cart_add($productB, 1)['ok']), 'Second product must enter the cart');

    $checkout = sh_create_order([
        'payment_method_id' => (int)$manualMethod['id'],
        'coupon_code' => 'REPORT100',
        'delivery_zone' => 'inside',
        'customer_name' => 'Reporting Customer',
        'customer_email' => 'reporting-customer@example.test',
        'customer_phone' => '01700000001',
        'address_line' => '1 Test Road',
        'area' => 'Test Area',
        'city' => 'Dhaka',
        'postcode' => '1200',
        'note' => 'Reporting integration test',
    ]);
    reporting_assert(!empty($checkout['ok']), 'Cart-backed manual checkout must succeed');
    $manualOrderId = (int)$checkout['order_id'];
    $rangeDates = sh_reporting_presets()['today'];
    $range = sh_reporting_range($rangeDates['from'], $rangeDates['to']);

    // An awaiting/manual order exists in operational reports but does not count
    // as paid revenue or paid item sales until verification succeeds.
    $beforeApproval = sh_reporting_sales_summary($range);
    reporting_assert_same(1, $beforeApproval['orders'], 'Pending checkout must be in all-order count');
    reporting_assert_same(0, $beforeApproval['paid_orders'], 'Pending checkout must not be a paid order');
    reporting_assert_money(0.00, $beforeApproval['paid_revenue'], 'Pending checkout must not add paid revenue');
    reporting_assert_same(0, $beforeApproval['items_sold'], 'Pending checkout items must not count as paid items');
    $newOrderTimeline = sh_order_timeline(sh_order_get($manualOrderId), sh_order_latest_payment($manualOrderId), sh_order_items($manualOrderId));
    reporting_assert_same('done', reporting_timeline_step($newOrderTimeline, 'order_placed')['state'], 'New order must retain its placed milestone');
    reporting_assert_same('current', reporting_timeline_step($newOrderTimeline, 'payment_submitted')['state'], 'New prepaid order must await payment submission');
    reporting_assert_same('pending', reporting_timeline_step($newOrderTimeline, 'payment_verified')['state'], 'Unpaid order must not show payment verified');

    $submitted = sh_submit_manual_payment($manualOrderId, 'REPORTTRX1001', '01700000001');
    reporting_assert(!empty($submitted['ok']), 'Manual payment submission must succeed');
    $payment = sh_one('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$manualOrderId]);
    reporting_assert($payment !== null, 'Checkout must create a payment record');
    $submittedTimeline = sh_order_timeline(sh_order_get($manualOrderId), $payment, sh_order_items($manualOrderId));
    reporting_assert_same('done', reporting_timeline_step($submittedTimeline, 'payment_submitted')['state'], 'Submitted payment must complete its submission milestone');
    reporting_assert_same('current', reporting_timeline_step($submittedTimeline, 'payment_verified')['state'], 'Submitted payment must await verification');
    $approved = sh_approve_payment((int)$payment['id'], $adminId, 'Reporting test approval');
    reporting_assert(!empty($approved['ok']), 'Manual payment approval must succeed');

    $verifiedManual = sh_one('SELECT * FROM orders WHERE id = ?', [$manualOrderId]);
    reporting_assert_same('verified', (string)$verifiedManual['payment_status'], 'Approved order payment status must persist');
    reporting_assert_same('processing', (string)$verifiedManual['status'], 'Approved order status must persist');
    reporting_assert_money(1000.00, $verifiedManual['subtotal'], 'Checkout subtotal must be persisted');
    reporting_assert_money(100.00, $verifiedManual['discount'], 'Checkout discount must be persisted');
    reporting_assert_money(60.00, $verifiedManual['delivery_fee'], 'Checkout delivery fee must be persisted');
    reporting_assert_money(960.00, $verifiedManual['total'], 'Checkout payable total must be persisted');
    $processingTimeline = sh_order_timeline($verifiedManual, sh_order_latest_payment($manualOrderId), sh_order_items($manualOrderId));
    reporting_assert_same('done', reporting_timeline_step($processingTimeline, 'payment_verified')['state'], 'Verified payment must complete its verification milestone');
    reporting_assert_same('current', reporting_timeline_step($processingTimeline, 'processing')['state'], 'Verified processing order must highlight processing');
    reporting_assert_same('pending', reporting_timeline_step($processingTimeline, 'delivery')['state'], 'Verified payment must not imply delivery');

    // A delivered COD order remains unpaid until its stored payment status is
    // verified. It must therefore increase all-order totals, not paid revenue.
    reporting_assert(!empty(sh_cart_add($productA, 1)['ok']), 'COD product must enter the cart');
    $codCheckout = sh_create_order([
        'payment_method_id' => (int)$codMethod['id'],
        'delivery_zone' => 'inside',
        'customer_name' => 'Reporting Customer',
        'customer_email' => 'reporting-customer@example.test',
        'customer_phone' => '01700000001',
        'address_line' => '1 Test Road',
        'area' => 'Test Area',
        'city' => 'Dhaka',
        'postcode' => '1200',
    ]);
    reporting_assert(!empty($codCheckout['ok']), 'COD checkout must succeed');
    $codOrderId = (int)$codCheckout['order_id'];
    sh_query("UPDATE orders SET status = 'delivered' WHERE id = ?", [$codOrderId]);
    $deliveredCodTimeline = sh_order_timeline(sh_order_get($codOrderId), sh_order_latest_payment($codOrderId), sh_order_items($codOrderId));
    reporting_assert($deliveredCodTimeline['is_cod'], 'COD route must be determined from its persisted payment snapshot');
    reporting_assert_same('not-required', reporting_timeline_step($deliveredCodTimeline, 'payment_not_required')['state'], 'COD must not require prepaid milestones');
    reporting_assert_same('done', reporting_timeline_step($deliveredCodTimeline, 'delivery')['state'], 'Delivered COD must complete its delivery milestone');

    // Add persisted invalid and guest records to exercise the same production
    // status/payment fields that the reports read. Add a second payment record
    // to prove reporting does not multiply order-level financial totals.
    $cancelledId = reporting_test_insert_order([
        'order_number' => 'SH-REPORT-CANCELLED',
        'user_id' => $userId,
        'customer_name' => 'Reporting Customer',
        'customer_email' => 'reporting-customer@example.test',
        'customer_phone' => '01700000001',
        'payment_method_id' => (int)$manualMethod['id'],
        'payment_method_name' => (string)$manualMethod['name'],
        'subtotal' => 250.00,
        'total' => 250.00,
        'status' => 'cancelled',
        'payment_status' => 'verified',
    ]);
    $refundedId = reporting_test_insert_order([
        'order_number' => 'SH-REPORT-REFUNDED',
        'customer_name' => 'Reporting Guest',
        'customer_email' => 'guest-reporting@example.test',
        'customer_phone' => '01700000009',
        'payment_method_id' => (int)$manualMethod['id'],
        'payment_method_name' => (string)$manualMethod['name'],
        'subtotal' => 200.00,
        'total' => 200.00,
        'status' => 'returned',
        'payment_status' => 'refunded',
    ]);
    $guestPendingId = reporting_test_insert_order([
        'order_number' => 'SH-REPORT-GUEST',
        'customer_name' => 'Reporting Guest',
        'customer_email' => 'guest-reporting@example.test',
        'customer_phone' => '01700000009',
        'payment_method_id' => (int)$codMethod['id'],
        'payment_method_name' => (string)$codMethod['name'],
        'subtotal' => 120.00,
        'total' => 120.00,
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
    foreach ([[$cancelledId, $productA, 1, 250.00], [$refundedId, $productA, 1, 200.00], [$guestPendingId, $productB, 1, 120.00]] as [$orderId, $productId, $quantity, $lineTotal]) {
        $product = sh_one('SELECT name, image, product_type, price FROM products WHERE id = ?', [$productId]);
        sh_insert('order_items', [
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_name' => $product['name'],
            'product_image' => $product['image'],
            'product_variant' => 'test-package',
            'product_type' => $product['product_type'],
            'unit_price' => $product['price'],
            'quantity' => $quantity,
            'line_total' => $lineTotal,
        ]);
    }
    sh_insert('payments', [
        'order_id' => $manualOrderId,
        'payment_method_id' => (int)$manualMethod['id'],
        'method_name' => (string)$manualMethod['name'],
        'kind' => 'manual',
        'amount' => 960.00,
        'status' => 'pending',
    ]);

    $summary = sh_reporting_sales_summary($range);
    reporting_assert_same(5, $summary['orders'], 'All statuses must remain in all-order count');
    reporting_assert_money(1990.00, $summary['order_value'], 'All-order value must include every created order once');
    reporting_assert_same(1, $summary['paid_orders'], 'Only the verified valid order is paid');
    reporting_assert_money(1000.00, $summary['paid_subtotal'], 'Paid subtotal must come from the verified order');
    reporting_assert_money(100.00, $summary['paid_discounts'], 'Paid discounts must come from the verified order');
    reporting_assert_money(60.00, $summary['paid_delivery_fees'], 'Paid delivery must come from the verified order');
    reporting_assert_money(960.00, $summary['paid_revenue'], 'Paid revenue must not double count payment or item rows');
    reporting_assert_same(3, $summary['items_sold'], 'Only valid paid order-item quantities count');
    reporting_assert_same(1, $summary['refunded_orders'], 'Refunded record must be visible as excluded');
    reporting_assert_money(200.00, $summary['refunded_value'], 'Refund value must be reported separately');

    $daily = sh_reporting_daily_sales($range);
    reporting_assert_same(1, count($daily), 'Today range must produce one daily row');
    reporting_assert_same(5, $daily[0]['orders'], 'Daily order count must include non-paid orders');
    reporting_assert_money(960.00, $daily[0]['paid_revenue'], 'Daily paid revenue must match summary');

    $methods = sh_reporting_payment_method_breakdown($range);
    $methodRevenue = [];
    foreach ($methods as $row) { $methodRevenue[(string)$row['method_name']] = (float)$row['paid_revenue']; }
    reporting_assert_money(960.00, $methodRevenue[(string)$manualMethod['name']] ?? 0, 'Manual method breakdown must match paid summary');
    reporting_assert_money(0.00, $methodRevenue[(string)$codMethod['name']] ?? 0, 'Unpaid COD must remain zero paid revenue');

    $statuses = sh_reporting_order_status_breakdown($range);
    $statusRevenue = [];
    foreach ($statuses as $row) { $statusRevenue[(string)$row['status']] = (float)$row['paid_revenue']; }
    reporting_assert_money(0.00, $statusRevenue['delivered'] ?? 0, 'Delivered unpaid COD must remain excluded');
    reporting_assert_money(0.00, $statusRevenue['cancelled'] ?? 0, 'Cancelled verified order must remain excluded');
    reporting_assert_money(0.00, $statusRevenue['returned'] ?? 0, 'Refunded/returned order must remain excluded');

    $topProducts = sh_reporting_top_products($range, 10);
    $topByProduct = [];
    foreach ($topProducts as $row) { $topByProduct[(int)$row['product_id']] = $row; }
    reporting_assert_same(2, count($topByProduct), 'Only two products have valid paid sales');
    reporting_assert_same(2, (int)$topByProduct[$productA]['quantity_sold'], 'Product A valid paid quantity must use order items');
    reporting_assert_money(800.00, $topByProduct[$productA]['gross_item_sales'], 'Product A gross item sales must match snapshot');
    reporting_assert_money(80.00, $topByProduct[$productA]['allocated_discount'], 'Product A discount allocation must be proportional');
    reporting_assert_same(1, (int)$topByProduct[$productB]['quantity_sold'], 'Product B valid paid quantity must use order items');
    reporting_assert_money(20.00, $topByProduct[$productB]['allocated_discount'], 'Product B discount allocation must be proportional');

    // The Products Report's zero-sale catalogue branch must retain current
    // catalogue products that have no valid paid order item in the range.
    $zeroRows = sh_all(
        'SELECT p.id FROM products p WHERE NOT EXISTS (
            SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE oi.product_id = p.id AND ' . sh_reporting_date_condition('o')
            . ' AND ' . sh_reporting_paid_order_condition('o') . ')',
        sh_reporting_date_params($range)
    );
    reporting_assert(in_array($zeroProduct, array_map(static fn(array $row): int => (int)$row['id'], $zeroRows), true),
        'Zero-sale catalogue product must remain in the Products Report source');

    // Replicate the report's safe registered/guest aggregation shape to ensure
    // a registered user is not duplicated by multiple orders and guest records
    // group only by their persisted contact identity.
    $dateWhere = sh_reporting_date_condition('o');
    $paid = sh_reporting_paid_order_condition('o');
    $customers = sh_all(
        "SELECT cr.* FROM (
            SELECT 'registered' AS customer_source, u.id AS customer_id,
                COUNT(o.id) AS total_orders,
                COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_amount
            FROM users u LEFT JOIN orders o ON o.user_id = u.id AND $dateWhere
            GROUP BY u.id
            UNION ALL
            SELECT 'guest' AS customer_source, 0 AS customer_id,
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_amount
            FROM orders o WHERE $dateWhere AND o.user_id IS NULL
            GROUP BY CASE WHEN o.customer_phone IS NOT NULL AND TRIM(o.customer_phone) <> ''
                THEN CONCAT('phone:', o.customer_phone)
                WHEN o.customer_email IS NOT NULL AND TRIM(o.customer_email) <> ''
                THEN CONCAT('email:', LOWER(o.customer_email))
                ELSE CONCAT('order:', o.id) END
        ) cr",
        array_merge(sh_reporting_date_params($range), sh_reporting_date_params($range))
    );
    $registered = array_values(array_filter($customers, static fn(array $row): bool => (int)$row['customer_id'] === $userId));
    $guest = array_values(array_filter($customers, static fn(array $row): bool => (string)$row['customer_source'] === 'guest'));
    reporting_assert_same(1, count($registered), 'Registered customer must have one aggregate row');
    reporting_assert_same(3, (int)$registered[0]['total_orders'], 'Registered customer order count must not duplicate joins');
    reporting_assert_money(960.00, $registered[0]['paid_amount'], 'Registered customer paid amount must match sales rule');
    reporting_assert_same(1, count($guest), 'Guest records sharing a stored contact must aggregate once');
    reporting_assert_same(2, (int)$guest[0]['total_orders'], 'Guest aggregate must retain both guest orders');

    $cancelledTimeline = sh_order_timeline(sh_order_get($cancelledId), sh_order_latest_payment($cancelledId), sh_order_items($cancelledId));
    reporting_assert_same('Cancelled', (string)$cancelledTimeline['problem']['label'], 'Cancelled order must show a terminal cancellation state');
    reporting_assert_same('pending', reporting_timeline_step($cancelledTimeline, 'delivery')['state'], 'Cancelled order must not mark delivery complete');

    $rejectedId = reporting_test_insert_order([
        'order_number' => 'SH-REPORT-REJECTED',
        'customer_name' => 'Rejected Payment Customer',
        'customer_email' => 'rejected@example.test',
        'customer_phone' => '01700000008',
        'payment_method_id' => (int)$manualMethod['id'],
        'payment_method_name' => (string)$manualMethod['name'],
        'subtotal' => 100.00,
        'total' => 100.00,
        'status' => 'payment_rejected',
        'payment_status' => 'rejected',
    ]);
    sh_insert('payments', [
        'order_id' => $rejectedId,
        'payment_method_id' => (int)$manualMethod['id'],
        'method_name' => (string)$manualMethod['name'],
        'kind' => 'manual',
        'amount' => 100.00,
        'transaction_id' => 'REPORTREJECTED',
        'status' => 'rejected',
    ]);
    $rejectedTimeline = sh_order_timeline(sh_order_get($rejectedId), sh_order_latest_payment($rejectedId), []);
    reporting_assert_same('bad', reporting_timeline_step($rejectedTimeline, 'payment_verified')['state'], 'Rejected payment must not appear verified');
    reporting_assert_same('Payment Rejected', (string)reporting_timeline_step($rejectedTimeline, 'payment_verified')['label'], 'Rejected payment must state its actual outcome');

    sh_query("UPDATE orders SET status = 'delivered' WHERE id = ?", [$manualOrderId]);
    $deliveredTimeline = sh_order_timeline(sh_order_get($manualOrderId), sh_order_latest_payment($manualOrderId), sh_order_items($manualOrderId));
    foreach ($deliveredTimeline['steps'] as $step) {
        reporting_assert_same('done', $step['state'], 'Delivered prepaid order must complete every applicable milestone');
    }
    $legacyStatusOrder = sh_order_get($manualOrderId);
    $legacyStatusOrder['status'] = 'Delivered';
    $legacyStatusOrder['payment_status'] = 'Verified';
    $legacyTimeline = sh_order_timeline($legacyStatusOrder, sh_order_latest_payment($manualOrderId), sh_order_items($manualOrderId));
    reporting_assert_same('done', reporting_timeline_step($legacyTimeline, 'delivery')['state'], 'Capitalized legacy delivered status must normalize safely');
    $legacyCompleteOrder = $legacyStatusOrder;
    $legacyCompleteOrder['status'] = 'Complete';
    $completedTimeline = sh_order_timeline($legacyCompleteOrder, sh_order_latest_payment($manualOrderId), sh_order_items($manualOrderId));
    reporting_assert_same('done', reporting_timeline_step($completedTimeline, 'delivery')['state'], 'Legacy completed status must complete final delivery safely');

    reporting_assert(!sh_reporting_date_valid('2026-02-30'), 'Invalid calendar dates must be rejected');
    $lastSeven = sh_reporting_presets()['last7'];
    reporting_assert_same(7, sh_reporting_range($lastSeven['from'], $lastSeven['to'], 7)['days'], 'Range helper must preserve inclusive day counts');

    echo "Reporting integration passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Reporting integration failed: " . $e->getMessage() . "\n");
    exit(1);
}
