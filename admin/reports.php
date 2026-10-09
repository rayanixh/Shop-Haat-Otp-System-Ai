<?php
/** Reports — sales, orders, products, customers (server-side aggregation, CSV export). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_perm('reports.view');

$tabs = ['sales' => 'Sales', 'orders' => 'Orders', 'products' => 'Products', 'customers' => 'Customers'];
$tab = isset($tabs[sh_get('tab')]) ? sh_get('tab') : 'sales';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', sh_get('from')) ? sh_get('from') : date('Y-m-d', time() - 29 * 86400);
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', sh_get('to')) ? sh_get('to') : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) > 366 * 86400) { $from = date('Y-m-d', strtotime($to) - 366 * 86400); }
$range = [$from . ' 00:00:00', $to . ' 23:59:59'];
$REV = "CASE WHEN o.payment_status = 'verified' OR o.status IN ('completed','delivered') THEN o.total ELSE 0 END";

$rows = []; $columns = []; $summary = null;
switch ($tab) {
    case 'sales':
        $columns = ['Date', 'Orders', 'Revenue', 'Completed', 'Cancelled', 'Returned', 'Avg order'];
        foreach (sh_all("SELECT DATE(o.created_at) d, COUNT(*) c, COALESCE(SUM($REV),0) rev, SUM(o.status IN ('completed','delivered')) done, SUM(o.status = 'cancelled') canc, SUM(o.status = 'returned') ret
                         FROM orders o WHERE o.created_at BETWEEN ? AND ? GROUP BY DATE(o.created_at) ORDER BY d DESC", $range) as $r) {
            $rows[] = [$r['d'], (int)$r['c'], (float)$r['rev'], (int)$r['done'], (int)$r['canc'], (int)$r['ret'], (int)$r['c'] > 0 ? round((float)$r['rev'] / (int)$r['c'], 2) : 0.0];
        }
        $summary = sh_sales_period($range[0], $range[1]);
        break;
    case 'orders':
        $columns = ['Status', 'Orders', 'Order value', 'Share'];
        $all = sh_all("SELECT o.status, COUNT(*) c, COALESCE(SUM(o.total),0) amt FROM orders o WHERE o.created_at BETWEEN ? AND ? GROUP BY o.status ORDER BY c DESC", $range);
        $tot = array_sum(array_column($all, 'c'));
        foreach ($all as $r) { $rows[] = [sh_status_label($r['status']), (int)$r['c'], (float)$r['amt'], $tot > 0 ? round((int)$r['c'] / $tot * 100, 1) . '%' : '0%']; }
        $columns2 = ['Payment method', 'Orders', 'Order value'];
        $byMethod = sh_all("SELECT COALESCE(o.payment_method_name, 'Unknown') m, COUNT(*) c, COALESCE(SUM(o.total),0) amt FROM orders o WHERE o.created_at BETWEEN ? AND ? GROUP BY o.payment_method_name ORDER BY c DESC", $range);
        break;
    case 'products':
        $columns = ['Product', 'SKU', 'Units sold', 'Revenue', 'Orders', 'Stock'];
        foreach (sh_all("SELECT oi.product_id, oi.product_name, p.sku, p.stock, p.product_type, SUM(oi.quantity) qty, COALESCE(SUM(oi.line_total),0) rev, COUNT(DISTINCT oi.order_id) oc
                         FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products p ON p.id = oi.product_id
                         WHERE o.created_at BETWEEN ? AND ? AND o.status NOT IN ('cancelled','payment_rejected') GROUP BY oi.product_id, oi.product_name ORDER BY qty DESC LIMIT 100", $range) as $r) {
            $rows[] = [$r['product_name'], (string)($r['sku'] ?? ''), (int)$r['qty'], (float)$r['rev'], (int)$r['oc'], $r['product_type'] === 'digital' ? 'Digital' : (string)(int)$r['stock']];
        }
        break;
    case 'customers':
        $columns = ['Customer', 'Phone', 'Orders', 'Completed', 'Returned', 'Total spend', 'Last order'];
        foreach (sh_all("SELECT o.user_id, o.customer_name, o.customer_phone, COUNT(*) c, SUM(o.status IN ('completed','delivered')) done, SUM(o.status = 'returned') ret, COALESCE(SUM($REV),0) spend, MAX(o.created_at) last_at
                         FROM orders o WHERE o.created_at BETWEEN ? AND ? GROUP BY COALESCE(o.user_id, 0), o.customer_phone, o.customer_name ORDER BY spend DESC LIMIT 100", $range) as $r) {
            $rows[] = [$r['customer_name'], $r['customer_phone'], (int)$r['c'], (int)$r['done'], (int)$r['ret'], (float)$r['spend'], date('d M Y', strtotime($r['last_at'])), 'uid' => (int)$r['user_id']];
        }
        $newCustomers = (int)sh_val('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?', $range, 0);
        break;
}

if (sh_get('export') === 'csv') {
    if (!sh_admin_can('reports.export')) { sh_admin_deny('Your role does not allow exporting reports.'); }
    sh_audit('report_exported', 'report', null, $tabs[$tab] . ' ' . $from . ' to ' . $to);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shophaat-' . $tab . '-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $columns);
    foreach ($rows as $r) { fputcsv($out, array_values(array_filter($r, static fn($k) => is_int($k), ARRAY_FILTER_USE_KEY))); }
    fclose($out);
    exit;
}

$money = static fn($v) => is_float($v) ? sh_money($v) : (string)$v;
$qs = static fn(array $extra = []) => sh_url('admin/reports.php') . '?' . http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to], $extra));

$adminPage = 'reports:' . $tab;
$adminTitle = 'Reports';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head sh-panel__head--wrap">
    <div class="sh-seg"><?php foreach ($tabs as $k => $l): ?><a class="sh-seg__item <?= $tab === $k ? 'is-active' : '' ?>" href="<?= e(sh_url('admin/reports.php?tab=' . $k . '&from=' . $from . '&to=' . $to)) ?>"><?= e($l) ?> report</a><?php endforeach; ?></div>
    <?php if ($rows && sh_admin_can('reports.export')): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e($qs(['export' => 'csv'])) ?>"><?= sh_icon('download', 14) ?> Export CSV</a><?php endif; ?>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="sh-field"><label class="sh-field__label" for="r-from">From</label><input class="sh-input" id="r-from" type="date" name="from" value="<?= e($from) ?>"></div>
      <div class="sh-field"><label class="sh-field__label" for="r-to">To</label><input class="sh-input" id="r-to" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Apply</button>
      <?php foreach (['Today' => [date('Y-m-d'), date('Y-m-d')], '7 days' => [date('Y-m-d', time() - 6 * 86400), date('Y-m-d')], '30 days' => [date('Y-m-d', time() - 29 * 86400), date('Y-m-d')], 'This month' => [date('Y-m-01'), date('Y-m-d')]] as $l => [$f, $t]): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/reports.php?tab=' . $tab . '&from=' . $f . '&to=' . $t)) ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </form>
  </div>
  <?php if ($tab === 'sales' && $summary): ?>
    <div class="sh-panel__body"><div class="sh-stats sh-stats--4">
      <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('dollar', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money($summary['revenue'])) ?></div><div class="sh-stat__label">Revenue</div></div></div>
      <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('package', 20) ?></span><div><div class="sh-stat__value"><?= number_format($summary['orders']) ?></div><div class="sh-stat__label">Orders</div></div></div>
      <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('check-circle', 20) ?></span><div><div class="sh-stat__value"><?= number_format($summary['completed']) ?></div><div class="sh-stat__label">Completed</div></div></div>
      <div class="sh-stat sh-stat--bad"><span class="sh-stat__icon"><?= sh_icon('x-circle', 20) ?></span><div><div class="sh-stat__value"><?= number_format($summary['cancelled'] + $summary['returned']) ?></div><div class="sh-stat__label">Cancelled + returned</div></div></div>
    </div></div>
  <?php elseif ($tab === 'customers'): ?>
    <div class="sh-panel__body"><p class="sh-muted" style="margin:0"><strong><?= number_format($newCustomers ?? 0) ?></strong> new customer account(s) registered in this period. Showing top 100 buyers by spend.</p></div>
  <?php endif; ?>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="<?= count($columns) ?>">No data available for this period.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr><?php foreach ($r as $k => $v): if (!is_int($k)) { continue; } ?>
          <td><?php if ($k === 0 && $tab === 'customers' && !empty($r['uid'])): ?><a href="<?= e(sh_url('admin/customers.php?id=' . (int)$r['uid'])) ?>"><strong><?= e((string)$v) ?></strong></a><?php else: ?><?= $k === 0 ? '<strong>' . e($money($v)) . '</strong>' : e($money($v)) ?><?php endif; ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($tab === 'orders' && !empty($byMethod)): ?>
    <div class="sh-panel__head" style="border-top:1px solid var(--sh-line)"><h2 class="sh-panel__title">By payment method</h2></div>
    <div class="sh-tablewrap"><table class="sh-table"><thead><tr><?php foreach ($columns2 as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($byMethod as $r): ?><tr><td><strong><?= e($r['m']) ?></strong></td><td><?= (int)$r['c'] ?></td><td><?= e(sh_money($r['amt'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)"><p class="sh-muted" style="margin:0">Revenue counts verified payments and delivered or completed orders only. Reports are limited to the top 100 rows for products and customers.</p></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
