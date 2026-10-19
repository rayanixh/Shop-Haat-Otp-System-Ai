<?php
/**
 * Reporting and sales-analytics data access.
 *
 * Every financial aggregate is calculated at the order level. This is
 * deliberate: an order can contain many order_items and, on legacy installs,
 * more than one payment row. Joining either table into an order-total query
 * without first aggregating would multiply revenue.
 */
declare(strict_types=1);

/** ShopHaat operates in Bangladesh; use one explicit business calendar for report presets and boundaries. */
function sh_reporting_timezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) { return $timezone; }
    try {
        $timezone = new DateTimeZone('Asia/Dhaka');
    } catch (Throwable $e) {
        // DateTimeZone('Asia/Dhaka') is part of PHP's bundled timezone database,
        // but keep reports functional on an unusually restricted host.
        $timezone = new DateTimeZone(date_default_timezone_get());
    }
    return $timezone;
}

/** Strict YYYY-MM-DD validation; regex-only date handling accepts values such as 2026-99-99. */
function sh_reporting_date_valid(string $date): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) { return false; }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, sh_reporting_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed instanceof DateTimeImmutable
        && $parsed->format('Y-m-d') === $date
        && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0));
}

/**
 * Normalize a user-selected report range to inclusive calendar dates and an
 * exclusive SQL end timestamp. The exclusive upper boundary cannot lose rows
 * because of time precision and does not rely on a 23:59:59 sentinel.
 *
 * @return array{from:string,to:string,from_sql:string,to_exclusive:string,days:int}
 */
function sh_reporting_range(string $from, string $to, int $maxDays = 366): array
{
    if (!sh_reporting_date_valid($from) || !sh_reporting_date_valid($to)) {
        throw new InvalidArgumentException('Choose valid report dates.');
    }
    $timezone = sh_reporting_timezone();
    $start = new DateTimeImmutable($from . ' 00:00:00', $timezone);
    $end = new DateTimeImmutable($to . ' 00:00:00', $timezone);
    if ($start > $end) { [$start, $end] = [$end, $start]; }

    $days = (int)$start->diff($end)->format('%a') + 1;
    $maxDays = max(1, min(3660, $maxDays));
    if ($days > $maxDays) {
        $start = $end->sub(new DateInterval('P' . ($maxDays - 1) . 'D'));
        $days = $maxDays;
    }

    return [
        'from' => $start->format('Y-m-d'),
        'to' => $end->format('Y-m-d'),
        'from_sql' => $start->format('Y-m-d 00:00:00'),
        'to_exclusive' => $end->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00'),
        'days' => $days,
    ];
}

/** Common date presets used by every report tab. */
function sh_reporting_presets(): array
{
    $today = new DateTimeImmutable('today', sh_reporting_timezone());
    $lastMonthEnd = $today->modify('first day of this month')->sub(new DateInterval('P1D'));
    $lastMonthStart = $lastMonthEnd->modify('first day of this month');

    $format = static fn(DateTimeImmutable $date): string => $date->format('Y-m-d');
    return [
        'today' => ['label' => 'Today', 'from' => $format($today), 'to' => $format($today)],
        'yesterday' => ['label' => 'Yesterday', 'from' => $format($today->sub(new DateInterval('P1D'))), 'to' => $format($today->sub(new DateInterval('P1D')))],
        'last7' => ['label' => 'Last 7 Days', 'from' => $format($today->sub(new DateInterval('P6D'))), 'to' => $format($today)],
        'last30' => ['label' => 'Last 30 Days', 'from' => $format($today->sub(new DateInterval('P29D'))), 'to' => $format($today)],
        'this_month' => ['label' => 'This Month', 'from' => $format($today->modify('first day of this month')), 'to' => $format($today)],
        'last_month' => ['label' => 'Last Month', 'from' => $format($lastMonthStart), 'to' => $format($lastMonthEnd)],
    ];
}

/** Internal aliases are fixed by callers, but reject anything unexpected before interpolating one into SQL. */
function sh_reporting_alias(string $alias): string
{
    if (preg_match('/^[a-z][a-z0-9_]*$/i', $alias) !== 1) {
        throw new InvalidArgumentException('Invalid report query alias.');
    }
    return $alias;
}

/** SQL condition and arguments for the selected order-created-at range. */
function sh_reporting_date_condition(string $alias = 'o'): string
{
    $alias = sh_reporting_alias($alias);
    return $alias . '.created_at >= ? AND ' . $alias . '.created_at < ?';
}

/** @return array{0:string,1:string} */
function sh_reporting_date_params(array $range): array
{
    if (!isset($range['from_sql'], $range['to_exclusive'])) {
        throw new InvalidArgumentException('Invalid report date range.');
    }
    return [(string)$range['from_sql'], (string)$range['to_exclusive']];
}

/**
 * One authoritative paid-sale rule used across reports and analytics.
 *
 * A fulfilled-looking order is not automatically paid: this keeps unpaid COD,
 * pending manual payments, rejected payments and legacy status edits out of
 * paid revenue. Refunded, cancelled and returned records also remain outside
 * net paid sales even when they once had a verified payment.
 */
function sh_reporting_paid_order_condition(string $alias = 'o'): string
{
    $alias = sh_reporting_alias($alias);
    return $alias . ".payment_status = 'verified'"
        . ' AND ' . $alias . ".status NOT IN ('cancelled','returned','payment_rejected')";
}

/** Order and payment statuses exposed by the report filter UI. */
function sh_reporting_order_statuses(): array
{
    return ['pending', 'awaiting_payment', 'payment_submitted', 'payment_verified', 'payment_rejected',
        'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'returned'];
}
function sh_reporting_payment_statuses(): array
{
    return ['unpaid', 'submitted', 'verified', 'rejected', 'refunded'];
}

/**
 * Summary calculated only from order rows. Item count is intentionally a
 * second aggregate so one-to-many order_items cannot duplicate money columns.
 *
 * @return array<string,int|float>
 */
function sh_reporting_sales_summary(array $range): array
{
    $where = sh_reporting_date_condition('o');
    $params = sh_reporting_date_params($range);
    $paid = sh_reporting_paid_order_condition('o');

    $summary = sh_one(
        "SELECT
            COUNT(*) AS orders,
            COALESCE(SUM(o.total), 0) AS order_value,
            COALESCE(SUM(CASE WHEN $paid THEN 1 ELSE 0 END), 0) AS paid_orders,
            COALESCE(SUM(CASE WHEN $paid THEN o.subtotal ELSE 0 END), 0) AS paid_subtotal,
            COALESCE(SUM(CASE WHEN $paid THEN o.discount ELSE 0 END), 0) AS paid_discounts,
            COALESCE(SUM(CASE WHEN $paid THEN o.delivery_fee ELSE 0 END), 0) AS paid_delivery_fees,
            COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_revenue,
            COALESCE(SUM(CASE WHEN o.payment_status = 'refunded' THEN 1 ELSE 0 END), 0) AS refunded_orders,
            COALESCE(SUM(CASE WHEN o.payment_status = 'refunded' THEN o.total ELSE 0 END), 0) AS refunded_value,
            COALESCE(SUM(CASE WHEN o.status IN ('completed','delivered') THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN o.status = 'returned' THEN 1 ELSE 0 END), 0) AS returned_orders
         FROM orders o
         WHERE $where",
        $params
    ) ?? [];

    $itemsSold = sh_val(
        'SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE ' . $where . ' AND ' . $paid,
        $params,
        0
    );

    return [
        'orders' => (int)($summary['orders'] ?? 0),
        'order_value' => (float)($summary['order_value'] ?? 0),
        'paid_orders' => (int)($summary['paid_orders'] ?? 0),
        'paid_subtotal' => (float)($summary['paid_subtotal'] ?? 0),
        'paid_discounts' => (float)($summary['paid_discounts'] ?? 0),
        'paid_delivery_fees' => (float)($summary['paid_delivery_fees'] ?? 0),
        'paid_revenue' => (float)($summary['paid_revenue'] ?? 0),
        'items_sold' => (int)$itemsSold,
        'refunded_orders' => (int)($summary['refunded_orders'] ?? 0),
        'refunded_value' => (float)($summary['refunded_value'] ?? 0),
        'completed_orders' => (int)($summary['completed_orders'] ?? 0),
        'cancelled_orders' => (int)($summary['cancelled_orders'] ?? 0),
        'returned_orders' => (int)($summary['returned_orders'] ?? 0),
    ];
}

/**
 * Daily rows for the selected range, including zero-value dates so charts have
 * a real zero-data state rather than gaps or fabricated values.
 *
 * @return list<array<string,int|float|string>>
 */
function sh_reporting_daily_sales(array $range): array
{
    $where = sh_reporting_date_condition('o');
    $paid = sh_reporting_paid_order_condition('o');
    $rows = sh_all(
        "SELECT DATE(o.created_at) AS day,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total), 0) AS order_value,
                COALESCE(SUM(CASE WHEN $paid THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_revenue,
                COALESCE(SUM(CASE WHEN $paid THEN o.discount ELSE 0 END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN $paid THEN o.delivery_fee ELSE 0 END), 0) AS delivery_fees
         FROM orders o
         WHERE $where
         GROUP BY DATE(o.created_at)
         ORDER BY day ASC",
        sh_reporting_date_params($range)
    );

    $byDay = [];
    foreach ($rows as $row) { $byDay[(string)$row['day']] = $row; }
    $out = [];
    $cursor = new DateTimeImmutable((string)$range['from'] . ' 00:00:00', sh_reporting_timezone());
    $end = new DateTimeImmutable((string)$range['to'] . ' 00:00:00', sh_reporting_timezone());
    while ($cursor <= $end) {
        $day = $cursor->format('Y-m-d');
        $row = $byDay[$day] ?? [];
        $out[] = [
            'day' => $day,
            'orders' => (int)($row['orders'] ?? 0),
            'order_value' => (float)($row['order_value'] ?? 0),
            'paid_orders' => (int)($row['paid_orders'] ?? 0),
            'paid_revenue' => (float)($row['paid_revenue'] ?? 0),
            'discounts' => (float)($row['discounts'] ?? 0),
            'delivery_fees' => (float)($row['delivery_fees'] ?? 0),
        ];
        $cursor = $cursor->add(new DateInterval('P1D'));
    }
    return $out;
}

/** @return list<array<string,int|float|string>> */
function sh_reporting_payment_method_breakdown(array $range): array
{
    $where = sh_reporting_date_condition('o');
    $paid = sh_reporting_paid_order_condition('o');
    return sh_all(
        "SELECT COALESCE(NULLIF(TRIM(o.payment_method_name), ''), 'Unknown') AS method_name,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total), 0) AS order_value,
                COALESCE(SUM(CASE WHEN $paid THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_revenue
         FROM orders o
         WHERE $where
         GROUP BY COALESCE(NULLIF(TRIM(o.payment_method_name), ''), 'Unknown')
         ORDER BY paid_revenue DESC, orders DESC, method_name ASC",
        sh_reporting_date_params($range)
    );
}

/** @return list<array<string,int|float|string>> */
function sh_reporting_order_status_breakdown(array $range): array
{
    $where = sh_reporting_date_condition('o');
    $paid = sh_reporting_paid_order_condition('o');
    return sh_all(
        "SELECT o.status,
                COUNT(*) AS orders,
                COALESCE(SUM(o.total), 0) AS order_value,
                COALESCE(SUM(CASE WHEN $paid THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN $paid THEN o.total ELSE 0 END), 0) AS paid_revenue
         FROM orders o
         WHERE $where
         GROUP BY o.status
         ORDER BY orders DESC, o.status ASC",
        sh_reporting_date_params($range)
    );
}

/**
 * Product performance from immutable order-item snapshots. Group by product
 * and stored variant/package so current catalogue edits do not rewrite history.
 *
 * @return list<array<string,int|float|string|null>>
 */
function sh_reporting_top_products(array $range, int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    $where = sh_reporting_date_condition('o');
    $paid = sh_reporting_paid_order_condition('o');
    return sh_all(
        "SELECT oi.product_id, oi.product_name, MAX(oi.product_image) AS product_image,
                COALESCE(oi.product_variant, '') AS product_variant,
                MAX(oi.product_type) AS product_type,
                COALESCE(MAX(p.sku), '') AS sku,
                COALESCE(SUM(oi.quantity), 0) AS quantity_sold,
                COALESCE(SUM(oi.line_total), 0) AS gross_item_sales,
                COALESCE(SUM(CASE WHEN o.subtotal > 0 THEN o.discount * oi.line_total / o.subtotal ELSE 0 END), 0) AS allocated_discount,
                COUNT(DISTINCT oi.order_id) AS order_count
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE $where AND $paid
         GROUP BY oi.product_id, oi.product_name, COALESCE(oi.product_variant, '')
         ORDER BY quantity_sold DESC, gross_item_sales DESC, oi.product_name ASC
         LIMIT $limit", // @security-scan-ignore sql_interpolation_unproven — $limit is an int-typed parameter clamped by max(1, min(100, $limit))
        sh_reporting_date_params($range)
    );
}

/** Backward-compatible compact summary used by older dashboard integrations. */
function sh_sales_period(string $from, ?string $to = null): array
{
    $fromDate = substr($from, 0, 10);
    $toDate = substr($to ?? $from, 0, 10);
    $range = sh_reporting_range($fromDate, $toDate);
    $summary = sh_reporting_sales_summary($range);
    return [
        'orders' => (int)$summary['orders'],
        'revenue' => (float)$summary['paid_revenue'],
        'completed' => (int)$summary['completed_orders'],
        'cancelled' => (int)$summary['cancelled_orders'],
        'returned' => (int)$summary['returned_orders'],
    ];
}
