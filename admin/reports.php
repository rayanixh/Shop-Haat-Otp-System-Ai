<?php
/**
 * Reports — sales, orders, products and customers backed by live order data.
 *
 * Financial totals use the shared reporting rule: only an order whose persisted
 * payment_status is verified and whose order status is still valid contributes
 * to paid revenue. This keeps pending/rejected/unpaid COD and refunded/returned
 * records out of net paid sales without joining payments into order totals.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/reporting.php';

sh_session_start();
$admin = sh_require_perm('reports.view');

// Product/variant reporting relies on the immutable order-item snapshot column.
// Existing installations are migrated by this established, pre-transaction helper.
$reportSchemaError = '';
try {
    if (!sh_order_items_ensure_schema()) {
        $reportSchemaError = 'The order-item reporting schema is not ready. No figures have been shown; check the application error log and database permissions.';
    }
} catch (Throwable $e) {
    sh_log_exception($e, 'reports-order-items-schema');
    $reportSchemaError = 'The order-item reporting schema could not be checked. No figures have been shown; check the application error log.';
}

$tabs = ['sales' => 'Sales', 'orders' => 'Orders', 'products' => 'Products', 'customers' => 'Customers'];
$tab = isset($tabs[sh_get('tab')]) ? sh_get('tab') : 'sales';
$presets = sh_reporting_presets();
$defaultDates = $presets['last30'];
$fromInput = sh_get('from');
$toInput = sh_get('to');
$dateNotice = '';
try {
    if ($fromInput !== '' || $toInput !== '') {
        if (!sh_reporting_date_valid($fromInput) || !sh_reporting_date_valid($toInput)) {
            throw new InvalidArgumentException('The supplied date range was invalid. The last 30 days are shown instead.');
        }
        $range = sh_reporting_range($fromInput, $toInput);
    } else {
        $range = sh_reporting_range($defaultDates['from'], $defaultDates['to']);
    }
} catch (InvalidArgumentException $e) {
    $range = sh_reporting_range($defaultDates['from'], $defaultDates['to']);
    $dateNotice = $e->getMessage();
}

$orderStatus = sh_get('order_status');
if (!in_array($orderStatus, sh_reporting_order_statuses(), true)) { $orderStatus = ''; }
$paymentStatus = sh_get('payment_status');
if (!in_array($paymentStatus, sh_reporting_payment_statuses(), true)) { $paymentStatus = ''; }
$methodId = sh_int($_GET['method_id'] ?? 0);
if ($methodId < 0) { $methodId = 0; }
$q = mb_substr(trim(sh_get('q')), 0, 100);
$page = max(1, sh_int($_GET['page'] ?? 1));
$perPage = 25;
$methods = [];
try {
    $methods = sh_all('SELECT id, name, type, status FROM payment_methods ORDER BY sort_order ASC, id ASC');
    if ($methodId > 0 && !array_filter($methods, static fn(array $method): bool => (int)$method['id'] === $methodId)) {
        $methodId = 0;
    }
} catch (Throwable $e) {
    // The schema requires payment_methods, but do not present fake data if a
    // damaged installation cannot load the filter options.
    sh_log_exception($e, 'reports-payment-method-options');
}

$queryBase = ['tab' => $tab, 'from' => $range['from'], 'to' => $range['to']];
if ($tab === 'orders') {
    if ($orderStatus !== '') { $queryBase['order_status'] = $orderStatus; }
    if ($paymentStatus !== '') { $queryBase['payment_status'] = $paymentStatus; }
    if ($methodId > 0) { $queryBase['method_id'] = $methodId; }
}
if (in_array($tab, ['orders', 'products', 'customers'], true) && $q !== '') { $queryBase['q'] = $q; }
$reportUrl = static function (array $extra = []) use ($queryBase): string {
    $params = array_merge($queryBase, $extra);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') { unset($params[$key]); }
    }
    return sh_url('admin/reports.php') . '?' . http_build_query($params);
};
$tabUrl = static function (string $target) use ($range): string {
    return sh_url('admin/reports.php') . '?' . http_build_query([
        'tab' => $target,
        'from' => $range['from'],
        'to' => $range['to'],
    ]);
};

$reportError = $reportSchemaError;
$summary = [];
$dailyRows = [];
$orderRows = [];
$productRows = [];
$customerRows = [];
$paymentBreakdown = [];
$statusBreakdown = [];
$topProducts = [];
$totalRows = 0;
$totalPages = 1;
$csvHeaders = [];
$csvRows = [];
$hasData = false;
$exportRequested = sh_get('export') === 'csv';

if ($reportError === '') {
try {
    switch ($tab) {
        case 'sales':
            $summary = sh_reporting_sales_summary($range);
            $dailyRows = sh_reporting_daily_sales($range);
            $paymentBreakdown = sh_reporting_payment_method_breakdown($range);
            $statusBreakdown = sh_reporting_order_status_breakdown($range);
            $topProducts = sh_reporting_top_products($range, 10);
            $hasData = (int)$summary['orders'] > 0;
            $csvHeaders = ['Date', 'Orders', 'Order value', 'Paid orders', 'Net paid revenue', 'Discounts on paid orders', 'Delivery fees on paid orders'];
            foreach ($dailyRows as $row) {
                $csvRows[] = [
                    $row['day'], (int)$row['orders'], number_format((float)$row['order_value'], 2, '.', ''),
                    (int)$row['paid_orders'], number_format((float)$row['paid_revenue'], 2, '.', ''),
                    number_format((float)$row['discounts'], 2, '.', ''), number_format((float)$row['delivery_fees'], 2, '.', ''),
                ];
            }
            break;

        case 'orders':
            $where = [sh_reporting_date_condition('o')];
            $params = sh_reporting_date_params($range);
            if ($orderStatus !== '') { $where[] = 'o.status = ?'; $params[] = $orderStatus; }
            if ($paymentStatus !== '') { $where[] = 'o.payment_status = ?'; $params[] = $paymentStatus; }
            if ($methodId > 0) { $where[] = 'o.payment_method_id = ?'; $params[] = $methodId; }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = "(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ?
                    OR EXISTS (SELECT 1 FROM order_items oi_search WHERE oi_search.order_id = o.id
                               AND (oi_search.product_name LIKE ? OR COALESCE(oi_search.product_variant, '') LIKE ?)))";
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            $whereSql = implode(' AND ', $where);
            $itemSummarySql = "SELECT oi.order_id, COALESCE(SUM(oi.quantity), 0) AS item_quantity,
                    GROUP_CONCAT(CONCAT(oi.product_name,
                        CASE WHEN oi.product_variant IS NOT NULL AND oi.product_variant <> ''
                             THEN CONCAT(' (', oi.product_variant, ')') ELSE '' END)
                        ORDER BY oi.id SEPARATOR ' • ') AS product_list
                FROM order_items oi GROUP BY oi.order_id";
            $orderSelect = "SELECT o.id, o.order_number, o.customer_name, o.customer_email, o.customer_phone,
                    o.total, o.payment_method_name, o.payment_status, o.status, o.created_at,
                    COALESCE(items.item_quantity, 0) AS item_quantity,
                    COALESCE(items.product_list, '') AS product_list
                FROM orders o
                LEFT JOIN ($itemSummarySql) items ON items.order_id = o.id
                WHERE $whereSql";
            $totalRows = (int)sh_val("SELECT COUNT(*) FROM orders o WHERE $whereSql", $params, 0);
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            $page = min($page, $totalPages);
            $orderRows = sh_all($orderSelect . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
            $hasData = $totalRows > 0;
            $csvHeaders = ['Order number', 'Customer', 'Email', 'Phone', 'Products', 'Quantity', 'Order total', 'Payment method', 'Payment status', 'Order status', 'Order date'];
            if ($exportRequested) {
                foreach (sh_all($orderSelect . ' ORDER BY o.created_at DESC, o.id DESC', $params) as $row) {
                    $csvRows[] = [
                        $row['order_number'], $row['customer_name'], $row['customer_email'], $row['customer_phone'],
                        $row['product_list'], (int)$row['item_quantity'], number_format((float)$row['total'], 2, '.', ''),
                        trim((string)$row['payment_method_name']) ?: 'Unknown', $row['payment_status'], $row['status'], $row['created_at'],
                    ];
                }
            }
            break;

        case 'products':
            $paid = sh_reporting_paid_order_condition('o');
            $dateWhere = sh_reporting_date_condition('o');
            $dateParams = sh_reporting_date_params($range);
            // Order-item snapshots preserve sold names, images and package/variant
            // information even when a current catalogue product was edited or removed.
            $soldSql = "SELECT oi.product_id, oi.product_name,
                    COALESCE(NULLIF(MAX(oi.product_image), ''), MAX(p.image), '') AS product_image,
                    COALESCE(oi.product_variant, '') AS product_variant,
                    COALESCE(MAX(p.sku), '') AS sku,
                    MAX(oi.product_type) AS product_type,
                    MAX(p.stock) AS stock,
                    COALESCE(SUM(oi.quantity), 0) AS quantity_sold,
                    COALESCE(SUM(oi.line_total), 0) AS gross_item_sales,
                    COALESCE(SUM(CASE WHEN o.subtotal > 0 THEN o.discount * oi.line_total / o.subtotal ELSE 0 END), 0) AS allocated_discount,
                    COUNT(DISTINCT oi.order_id) AS order_count
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE $dateWhere AND $paid
                GROUP BY oi.product_id, oi.product_name, COALESCE(oi.product_variant, '')";
            // A catalogue row is included with zero sales only when it has no
            // valid paid line in this period. This keeps zeros visible without
            // duplicating products that have one or more sold variants.
            $zeroSql = "SELECT p.id AS product_id, p.name AS product_name, COALESCE(p.image, '') AS product_image,
                    '' AS product_variant, COALESCE(p.sku, '') AS sku, p.product_type, p.stock,
                    0 AS quantity_sold, 0 AS gross_item_sales, 0 AS allocated_discount, 0 AS order_count
                FROM products p
                WHERE NOT EXISTS (
                    SELECT 1 FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id = p.id AND $dateWhere AND $paid
                )";
            $productUnion = $soldSql . ' UNION ALL ' . $zeroSql;
            $productParams = array_merge($dateParams, $dateParams);
            $productSearch = '';
            if ($q !== '') {
                $like = '%' . $q . '%';
                $productSearch = " WHERE (rp.product_name LIKE ? OR rp.sku LIKE ? OR rp.product_variant LIKE ?)";
                array_push($productParams, $like, $like, $like);
            }
            $productFrom = ' FROM (' . $productUnion . ') rp' . $productSearch;
            $totalRows = (int)sh_val('SELECT COUNT(*)' . $productFrom, $productParams, 0);
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            $page = min($page, $totalPages);
            $productRows = sh_all('SELECT rp.*' . $productFrom
                . ' ORDER BY rp.quantity_sold DESC, rp.gross_item_sales DESC, rp.product_name ASC, rp.product_variant ASC'
                . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $productParams);
            $hasData = $totalRows > 0;
            $csvHeaders = ['Product', 'Variant/package', 'SKU', 'Type', 'Quantity sold', 'Gross item sales', 'Allocated discounts', 'Net item sales', 'Orders', 'Stock'];
            if ($exportRequested) {
                foreach (sh_all('SELECT rp.*' . $productFrom
                    . ' ORDER BY rp.quantity_sold DESC, rp.gross_item_sales DESC, rp.product_name ASC, rp.product_variant ASC', $productParams) as $row) {
                    $gross = (float)$row['gross_item_sales'];
                    $discount = (float)$row['allocated_discount'];
                    $csvRows[] = [
                        $row['product_name'], $row['product_variant'], $row['sku'], $row['product_type'],
                        (int)$row['quantity_sold'], number_format($gross, 2, '.', ''), number_format($discount, 2, '.', ''),
                        number_format($gross - $discount, 2, '.', ''), (int)$row['order_count'],
                        $row['stock'] === null ? 'Removed' : (string)(int)$row['stock'],
                    ];
                }
            }
            break;

        case 'customers':
            $paid = sh_reporting_paid_order_condition('o');
            $dateWhere = sh_reporting_date_condition('o');
            $dateParams = sh_reporting_date_params($range);
            // Aggregate registered users by immutable user_id before joining to
            // users. That avoids one row per order and works for customers with
            // no orders in the selected period.
            $registeredMetrics = "SELECT o.user_id, COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN o.status IN ('completed','delivered') THEN 1 ELSE 0 END), 0) AS completed_orders,
                    COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_amount,
                    MAX(o.created_at) AS last_order_at
                FROM orders o
                WHERE $dateWhere AND o.user_id IS NOT NULL
                GROUP BY o.user_id";
            $registeredSql = "SELECT 'registered' AS customer_source, u.id AS customer_id,
                    u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                    u.status AS customer_status,
                    COALESCE(m.total_orders, 0) AS total_orders,
                    COALESCE(m.completed_orders, 0) AS completed_orders,
                    COALESCE(m.paid_amount, 0) AS paid_amount,
                    m.last_order_at
                FROM users u
                LEFT JOIN ($registeredMetrics) m ON m.user_id = u.id";
            // Legacy guest orders have no reliable user_id relationship. Group
            // them by the stored contact identity rather than guessing a link to
            // an account; this avoids merging unrelated customers or duplicates.
            $guestKey = "CASE
                    WHEN o.customer_phone IS NOT NULL AND TRIM(o.customer_phone) <> '' THEN CONCAT('phone:', o.customer_phone)
                    WHEN o.customer_email IS NOT NULL AND TRIM(o.customer_email) <> '' THEN CONCAT('email:', LOWER(o.customer_email))
                    ELSE CONCAT('order:', o.id)
                END";
            $guestSql = "SELECT 'guest' AS customer_source, 0 AS customer_id,
                    MAX(o.customer_name) AS customer_name,
                    MAX(o.customer_email) AS customer_email,
                    MAX(o.customer_phone) AS customer_phone,
                    'guest' AS customer_status,
                    COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN o.status IN ('completed','delivered') THEN 1 ELSE 0 END), 0) AS completed_orders,
                    COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_amount,
                    MAX(o.created_at) AS last_order_at
                FROM orders o
                WHERE $dateWhere AND o.user_id IS NULL
                GROUP BY $guestKey";
            $customerUnion = $registeredSql . ' UNION ALL ' . $guestSql;
            $customerParams = array_merge($dateParams, $dateParams);
            $customerSearch = '';
            if ($q !== '') {
                $like = '%' . $q . '%';
                $customerSearch = ' WHERE (cr.customer_name LIKE ? OR cr.customer_email LIKE ? OR cr.customer_phone LIKE ?)';
                array_push($customerParams, $like, $like, $like);
            }
            $customerFrom = ' FROM (' . $customerUnion . ') cr' . $customerSearch;
            $totalRows = (int)sh_val('SELECT COUNT(*)' . $customerFrom, $customerParams, 0);
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            $page = min($page, $totalPages);
            $customerRows = sh_all('SELECT cr.*' . $customerFrom
                . ' ORDER BY cr.paid_amount DESC, cr.total_orders DESC, cr.last_order_at DESC, cr.customer_name ASC'
                . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $customerParams);
            $hasData = $totalRows > 0;
            $csvHeaders = ['Customer', 'Email', 'Phone', 'Source', 'Status', 'Total orders', 'Completed orders', 'Paid amount', 'Last order date'];
            if ($exportRequested) {
                foreach (sh_all('SELECT cr.*' . $customerFrom
                    . ' ORDER BY cr.paid_amount DESC, cr.total_orders DESC, cr.last_order_at DESC, cr.customer_name ASC', $customerParams) as $row) {
                    $csvRows[] = [
                        $row['customer_name'], $row['customer_email'], $row['customer_phone'], $row['customer_source'],
                        $row['customer_status'], (int)$row['total_orders'], (int)$row['completed_orders'],
                        number_format((float)$row['paid_amount'], 2, '.', ''), $row['last_order_at'] ?? '',
                    ];
                }
            }
            break;
    }
} catch (Throwable $e) {
    sh_log_exception($e, 'reports-data');
    $reportError = 'The report data could not be loaded. No figures have been shown as zero; check the application error log and database connection.';
    $summary = []; $dailyRows = []; $orderRows = []; $productRows = []; $customerRows = [];
    $paymentBreakdown = []; $statusBreakdown = []; $topProducts = []; $csvRows = [];
    $hasData = false;
}
}

if ($exportRequested && $reportError === '') {
    if (!sh_admin_can('reports.export')) { sh_admin_deny('Your role does not allow exporting reports.'); }
    sh_audit('report_exported', 'report', null, $tabs[$tab] . ' ' . $range['from'] . ' to ' . $range['to']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shophaat-' . $tab . '-report-' . $range['from'] . '-to-' . $range['to'] . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $csvHeaders);
    foreach ($csvRows as $row) { fputcsv($out, $row); }
    fclose($out);
    exit;
}

$adminPage = 'reports:' . $tab;
$adminTitle = $tabs[$tab] . ' Reports';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <p class="sh-panel__note">
      <?= sh_icon('bar-chart', 15) ?> Order-created dates <?= e($range['from']) ?> to <?= e($range['to']) ?> · Bangladesh business calendar.
    </p>
    <div class="sh-panel__actions">
      <?php if ($reportError === '' && ($hasData || $tab === 'sales') && sh_admin_can('reports.export')): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e($reportUrl(['export' => 'csv', 'page' => null])) ?>"><?= sh_icon('download', 14) ?> Export CSV</a>
      <?php endif; ?>
    </div>
    <div class="sh-seg" aria-label="Report type">
      <?php foreach ($tabs as $key => $label): ?>
        <a class="sh-seg__item <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e($tabUrl($key)) ?>"><?= e($label) ?> report</a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="sh-panel__body" style="padding-bottom:0">
    <?php if ($dateNotice !== ''): ?><div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?><span><?= e($dateNotice) ?></span></div><?php endif; ?>
    <?php if ($reportError !== ''): ?><div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?><span><?= e($reportError) ?></span></div><?php endif; ?>
    <p class="sh-panel__note" style="margin:0 0 10px">Use From and To for a custom date range. Every tab uses the same order-created date boundaries.</p>
    <form class="sh-filterbar" method="get">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="sh-field"><label class="sh-field__label" for="r-from">From</label><input class="sh-input" id="r-from" type="date" name="from" value="<?= e($range['from']) ?>" required></div>
      <div class="sh-field"><label class="sh-field__label" for="r-to">To</label><input class="sh-input" id="r-to" type="date" name="to" value="<?= e($range['to']) ?>" required></div>
      <?php if ($tab === 'orders'): ?>
        <div class="sh-field"><label class="sh-field__label" for="r-order-status">Order status</label>
          <select class="sh-select" id="r-order-status" name="order_status"><option value="">All statuses</option>
            <?php foreach (sh_reporting_order_statuses() as $status): ?><option value="<?= e($status) ?>" <?= $orderStatus === $status ? 'selected' : '' ?>><?= e(sh_status_label($status)) ?></option><?php endforeach; ?>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="r-payment-status">Payment status</label>
          <select class="sh-select" id="r-payment-status" name="payment_status"><option value="">All payments</option>
            <?php foreach (sh_reporting_payment_statuses() as $status): ?><option value="<?= e($status) ?>" <?= $paymentStatus === $status ? 'selected' : '' ?>><?= e(sh_status_label($status)) ?></option><?php endforeach; ?>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="r-method">Payment method</label>
          <select class="sh-select" id="r-method" name="method_id"><option value="">All methods</option>
            <?php foreach ($methods as $method): ?><option value="<?= (int)$method['id'] ?>" <?= $methodId === (int)$method['id'] ? 'selected' : '' ?>><?= e((string)$method['name']) ?></option><?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <?php if (in_array($tab, ['orders', 'products', 'customers'], true)): ?>
        <div class="sh-field"><label class="sh-field__label" for="r-q">Search</label>
          <input class="sh-input" id="r-q" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $tab === 'orders' ? 'Order, customer, phone or product' : ($tab === 'products' ? 'Product, SKU or package' : 'Name, email or phone') ?>"></div>
      <?php endif; ?>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Apply</button>
      <?php if (($tab === 'orders' && ($orderStatus !== '' || $paymentStatus !== '' || $methodId > 0 || $q !== '')) || ($tab !== 'orders' && $q !== '')): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/reports.php') . '?' . http_build_query(['tab' => $tab, 'from' => $range['from'], 'to' => $range['to']])) ?>">Reset filters</a>
      <?php endif; ?>
    </form>
    <div class="sh-actions" style="margin:12px 0">
      <?php foreach ($presets as $preset): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/reports.php') . '?' . http_build_query(['tab' => $tab, 'from' => $preset['from'], 'to' => $preset['to']])) ?>"><?= e($preset['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($reportError === ''): ?>
    <?php if ($tab === 'sales'): ?>
      <div class="sh-panel__body">
        <div class="sh-stats sh-stats--4">
          <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('dollar', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$summary['paid_revenue'])) ?></div><div class="sh-stat__label">Net paid revenue</div></div></div>
          <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('file-text', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$summary['paid_subtotal'])) ?></div><div class="sh-stat__label">Paid item subtotal</div></div></div>
          <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('package', 20) ?></span><div><div class="sh-stat__value"><?= number_format((int)$summary['paid_orders']) ?></div><div class="sh-stat__label">Paid orders</div></div></div>
          <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('shopping-cart', 20) ?></span><div><div class="sh-stat__value"><?= number_format((int)$summary['orders']) ?></div><div class="sh-stat__label">All orders</div></div></div>
          <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('file-text', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$summary['order_value'])) ?></div><div class="sh-stat__label">All order value</div></div></div>
          <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('box', 20) ?></span><div><div class="sh-stat__value"><?= number_format((int)$summary['items_sold']) ?></div><div class="sh-stat__label">Items sold</div></div></div>
          <div class="sh-stat sh-stat--muted"><span class="sh-stat__icon"><?= sh_icon('tag', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$summary['paid_discounts'])) ?></div><div class="sh-stat__label">Paid discounts</div></div></div>
          <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('truck', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$summary['paid_delivery_fees'])) ?></div><div class="sh-stat__label">Paid delivery fees</div></div></div>
        </div>
        <p class="sh-panel__note" style="margin:14px 0 0">Paid item subtotal − paid discounts + paid delivery fees = net paid revenue. All order value includes every order created in the period. Net paid revenue includes only verified payments on non-cancelled, non-returned, non-refunded orders. Unpaid or merely delivered Cash on Delivery orders remain outside paid revenue until their payment status is verified.</p>
        <?php if ((int)$summary['refunded_orders'] > 0): ?><p class="sh-panel__note" style="margin:7px 0 0">Refunded records: <?= number_format((int)$summary['refunded_orders']) ?> · <?= e(sh_money((float)$summary['refunded_value'])) ?> excluded from net paid revenue.</p><?php endif; ?>
      </div>

      <?php if (!$hasData): ?>
        <div class="sh-admin-empty"><?= sh_icon('bar-chart', 28) ?><p>No orders were created in this period. Change the dates or choose another preset.</p></div>
      <?php else: ?>
        <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">Daily sales trend</h2></div>
        <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Date</th><th>Orders</th><th>Order value</th><th>Paid orders</th><th>Net paid revenue</th><th>Discounts</th><th>Delivery</th></tr></thead><tbody>
          <?php foreach ($dailyRows as $row): ?>
            <tr><td data-label="Date"><strong><?= e(date('d M Y', strtotime((string)$row['day']))) ?></strong></td><td data-label="Orders"><?= number_format((int)$row['orders']) ?></td><td data-label="Order value"><?= e(sh_money((float)$row['order_value'])) ?></td><td data-label="Paid orders"><?= number_format((int)$row['paid_orders']) ?></td><td data-label="Net paid revenue"><strong><?= e(sh_money((float)$row['paid_revenue'])) ?></strong></td><td data-label="Discounts"><?= e(sh_money((float)$row['discounts'])) ?></td><td data-label="Delivery"><?= e(sh_money((float)$row['delivery_fees'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>

        <div class="sh-dash-grid" style="padding:14px">
          <div class="sh-panel"><div class="sh-panel__head"><h2 class="sh-panel__title">Payment-method breakdown</h2></div><div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Method</th><th>Orders</th><th>Order value</th><th>Paid orders</th><th>Paid revenue</th></tr></thead><tbody>
            <?php foreach ($paymentBreakdown as $row): ?><tr><td data-label="Method"><strong><?= e((string)$row['method_name']) ?></strong></td><td data-label="Orders"><?= number_format((int)$row['orders']) ?></td><td data-label="Order value"><?= e(sh_money((float)$row['order_value'])) ?></td><td data-label="Paid orders"><?= number_format((int)$row['paid_orders']) ?></td><td data-label="Paid revenue"><?= e(sh_money((float)$row['paid_revenue'])) ?></td></tr><?php endforeach; ?>
          </tbody></table></div></div>
          <div class="sh-panel"><div class="sh-panel__head"><h2 class="sh-panel__title">Order-status breakdown</h2></div><div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Status</th><th>Orders</th><th>Order value</th><th>Paid orders</th><th>Paid revenue</th></tr></thead><tbody>
            <?php foreach ($statusBreakdown as $row): ?><tr><td data-label="Status"><span class="sh-badge <?= e(sh_status_class((string)$row['status'])) ?>"><?= e(sh_status_label((string)$row['status'])) ?></span></td><td data-label="Orders"><?= number_format((int)$row['orders']) ?></td><td data-label="Order value"><?= e(sh_money((float)$row['order_value'])) ?></td><td data-label="Paid orders"><?= number_format((int)$row['paid_orders']) ?></td><td data-label="Paid revenue"><?= e(sh_money((float)$row['paid_revenue'])) ?></td></tr><?php endforeach; ?>
          </tbody></table></div></div>
        </div>

        <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">Top-selling products</h2></div>
        <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Product</th><th>Units</th><th>Gross item sales</th><th>Discounts</th><th>Net item sales</th><th>Orders</th></tr></thead><tbody>
          <?php if (!$topProducts): ?><tr class="sh-table--empty"><td colspan="6">No valid paid product sales in this period.</td></tr>
          <?php else: foreach ($topProducts as $row): $gross = (float)$row['gross_item_sales']; $discount = (float)$row['allocated_discount']; ?>
            <tr><td data-label="Product"><strong><?= e((string)$row['product_name']) ?></strong><?php if ((string)$row['product_variant'] !== ''): ?><div class="sh-table__meta"><?= e((string)$row['product_variant']) ?></div><?php endif; ?></td><td data-label="Units"><?= number_format((int)$row['quantity_sold']) ?></td><td data-label="Gross item sales"><?= e(sh_money($gross)) ?></td><td data-label="Discounts"><?= e(sh_money($discount)) ?></td><td data-label="Net item sales"><strong><?= e(sh_money($gross - $discount)) ?></strong></td><td data-label="Orders"><?= number_format((int)$row['order_count']) ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody></table></div>
      <?php endif; ?>

    <?php elseif ($tab === 'orders'): ?>
      <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">Orders (<?= number_format($totalRows) ?>)</h2></div>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Order</th><th>Customer</th><th>Products</th><th>Qty</th><th>Total</th><th>Payment method</th><th>Payment</th><th>Order status</th><th>Date</th></tr></thead><tbody>
        <?php if (!$orderRows): ?><tr class="sh-table--empty"><td colspan="9">No orders match these filters.</td></tr>
        <?php else: foreach ($orderRows as $row): ?>
          <tr>
            <td data-label="Order"><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$row['id'])) ?>" style="font-weight:700"><?= e((string)$row['order_number']) ?></a></td>
            <td data-label="Customer"><strong><?= e((string)$row['customer_name']) ?></strong><div class="sh-table__meta"><?= e((string)$row['customer_phone']) ?><?= trim((string)$row['customer_email']) !== '' ? ' · ' . e((string)$row['customer_email']) : '' ?></div></td>
            <td data-label="Products"><?= $row['product_list'] !== '' ? e((string)$row['product_list']) : '<span class="sh-table__meta">No item snapshot</span>' ?></td>
            <td data-label="Quantity"><?= number_format((int)$row['item_quantity']) ?></td>
            <td data-label="Order total"><strong><?= e(sh_money((float)$row['total'])) ?></strong></td>
            <td data-label="Payment method"><?= e(trim((string)$row['payment_method_name']) ?: 'Unknown') ?></td>
            <td data-label="Payment"><span class="sh-badge <?= e(sh_status_class((string)$row['payment_status'])) ?>"><?= e(sh_status_label((string)$row['payment_status'])) ?></span></td>
            <td data-label="Order status"><span class="sh-badge <?= e(sh_status_class((string)$row['status'])) ?>"><?= e(sh_status_label((string)$row['status'])) ?></span></td>
            <td data-label="Order date" class="sh-table__meta"><?= e(date('d M Y, h:i A', strtotime((string)$row['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>

    <?php elseif ($tab === 'products'): ?>
      <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">Products (<?= number_format($totalRows) ?>)</h2></div>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Product</th><th>Units sold</th><th>Gross item sales</th><th>Discounts</th><th>Net item sales</th><th>Orders</th><th>Stock</th></tr></thead><tbody>
        <?php if (!$productRows): ?><tr class="sh-table--empty"><td colspan="7">No products match this report search.</td></tr>
        <?php else: foreach ($productRows as $row): $gross = (float)$row['gross_item_sales']; $discount = (float)$row['allocated_discount']; ?>
          <tr>
            <td data-label="Product"><div class="sh-table__cell"><img class="sh-table__thumb" src="<?= e(sh_product_image((string)($row['product_image'] ?? ''))) ?>" alt="" loading="lazy"><div><strong><?= e((string)$row['product_name']) ?></strong><?php if ((string)$row['product_variant'] !== ''): ?><div class="sh-table__meta"><?= e((string)$row['product_variant']) ?></div><?php endif; ?><?php if ((string)$row['sku'] !== ''): ?><div class="sh-table__meta">SKU <?= e((string)$row['sku']) ?></div><?php endif; ?></div></div></td>
            <td data-label="Units sold"><?= number_format((int)$row['quantity_sold']) ?></td>
            <td data-label="Gross item sales"><?= e(sh_money($gross)) ?></td>
            <td data-label="Discounts"><?= e(sh_money($discount)) ?></td>
            <td data-label="Net item sales"><strong><?= e(sh_money($gross - $discount)) ?></strong></td>
            <td data-label="Orders"><?= number_format((int)$row['order_count']) ?></td>
            <td data-label="Stock"><?= $row['stock'] === null ? 'Removed' : (string)(int)$row['stock'] ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
      <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)"><p class="sh-muted" style="margin:0">Sales-performance columns use valid paid order-item snapshots. Catalogue products without valid paid sales in this period are shown with zero values.</p></div>

    <?php elseif ($tab === 'customers'): ?>
      <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">Customers (<?= number_format($totalRows) ?>)</h2></div>
      <div class="sh-tablewrap"><table class="sh-table"><thead><tr><th>Customer</th><th>Contact</th><th>Source / status</th><th>Orders</th><th>Completed</th><th>Paid amount</th><th>Last order</th></tr></thead><tbody>
        <?php if (!$customerRows): ?><tr class="sh-table--empty"><td colspan="7">No customers match this report search.</td></tr>
        <?php else: foreach ($customerRows as $row): $registered = (string)$row['customer_source'] === 'registered'; $email = (string)$row['customer_email']; ?>
          <tr>
            <td data-label="Customer"><?php if ($registered): ?><a href="<?= e(sh_url('admin/customers.php?id=' . (int)$row['customer_id'])) ?>" style="font-weight:700"><?= e((string)$row['customer_name']) ?></a><?php else: ?><strong><?= e((string)$row['customer_name']) ?></strong><?php endif; ?></td>
            <td data-label="Contact"><?php if ($email !== '' && !sh_is_synthetic_email($email)): ?><?= e($email) ?><br><?php endif; ?><span class="sh-table__meta"><?= e((string)$row['customer_phone']) ?></span></td>
            <td data-label="Source / status"><?php if ($registered): ?><span class="sh-statuspill <?= (string)$row['customer_status'] === 'active' ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= e(ucfirst((string)$row['customer_status'])) ?></span><?php else: ?><span class="sh-badge sh-badge--muted">Guest / legacy</span><?php endif; ?></td>
            <td data-label="Orders"><?= number_format((int)$row['total_orders']) ?></td>
            <td data-label="Completed"><?= number_format((int)$row['completed_orders']) ?></td>
            <td data-label="Paid amount"><strong><?= e(sh_money((float)$row['paid_amount'])) ?></strong></td>
            <td data-label="Last order" class="sh-table__meta"><?= !empty($row['last_order_at']) ? e(date('d M Y, h:i A', strtotime((string)$row['last_order_at']))) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody></table></div>
      <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)"><p class="sh-muted" style="margin:0">Registered customers are joined through orders.user_id. Legacy guest orders are grouped by their stored contact identity rather than guessed against an account, which prevents duplicate or incorrect customer merges.</p></div>
    <?php endif; ?>

    <?php if ($tab !== 'sales' && $totalPages > 1): ?>
      <div class="sh-panel__body"><?= sh_paginate($totalRows, $perPage, $page, $reportUrl(['page' => null])) ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
