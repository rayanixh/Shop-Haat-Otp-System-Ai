<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/reporting.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('dashboard.view');
sh_admin_schema_ensure();
// Fallback scheduler for hosts without cron: runs only when a backup is due.
try { $autoBackup = sh_backup_run_scheduled(); if ($autoBackup && !empty($autoBackup['ok'])) { sh_admin_notify('backup', 'Scheduled backup completed', $autoBackup['file'] . ' · ' . sh_bytes_human((int)$autoBackup['size']), 'admin/backups.php', 'backup-' . date('Ymd')); } } catch (Throwable $e) {}

$reportPresets = sh_reporting_presets();
$todayRange = sh_reporting_range($reportPresets['today']['from'], $reportPresets['today']['to']);
$today = sh_reporting_sales_summary($todayRange);
$pendingOrders = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status IN ('pending','awaiting_payment','payment_submitted','processing')", [], 0);
$completed    = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status IN ('completed','delivered')", [], 0);
$cancelled    = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'", [], 0);
$returned     = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status = 'returned'", [], 0);
$pendingPay   = (int)sh_val("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND transaction_id IS NOT NULL", [], 0);
$customers    = (int)sh_val('SELECT COUNT(*) FROM users', [], 0);

$periods = [];
foreach (['today' => 'Today', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'this_month' => 'This month'] as $key => $label) {
    $periods[$label] = sh_reporting_sales_summary(sh_reporting_range($reportPresets[$key]['from'], $reportPresets[$key]['to']));
}

$recentOrders = sh_all('SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.total, o.status, o.payment_status, o.created_at FROM orders o ORDER BY o.id DESC LIMIT 8');
$waitingProcessing = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status = 'processing'", [], 0);
$returnedOpen = (int)sh_val("SELECT COUNT(*) FROM orders WHERE status = 'returned' AND updated_at >= ?", [date('Y-m-d H:i:s', time() - 7 * 86400)], 0);
$failedPayments = (int)sh_val("SELECT COUNT(*) FROM payments WHERE status IN ('failed','rejected') AND created_at >= ?", [date('Y-m-d H:i:s', time() - 7 * 86400)], 0);
$lowStock = sh_low_stock_products(8);
$lowStockCount = (int)sh_val("SELECT COUNT(*) FROM products WHERE product_type = 'physical' AND status = 1 AND stock <= low_stock_threshold", [], 0);
$outStockCount = (int)sh_val("SELECT COUNT(*) FROM products WHERE product_type = 'physical' AND status = 1 AND stock <= 0", [], 0);
$failedNotices = (int)sh_val("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 86400)], 0);

// 30-day chart uses the same paid-sale rule and fills real zero-order dates.
$chartRange = sh_reporting_range($reportPresets['last30']['from'], $reportPresets['last30']['to']);
$days = [];
foreach (sh_reporting_daily_sales($chartRange) as $row) {
    $days[] = ['d' => (string)$row['day'], 'c' => (int)$row['orders'], 'amt' => (float)$row['paid_revenue']];
}
$maxAmt = max(1.0, max(array_column($days, 'amt')));
$chartTotal = array_sum(array_column($days, 'amt'));
$chartOrders = array_sum(array_column($days, 'c'));

$adminPage = 'dashboard';
$adminTitle = 'Dashboard';
require __DIR__ . '/_layout.php';
?>
<div class="sh-dash-cards">
  <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('dollar', 20) ?></span><div><div class="sh-stat__value"><?= e(sh_money((float)$today['paid_revenue'])) ?></div><div class="sh-stat__label">Today's paid sales</div></div></div>
  <div class="sh-stat sh-stat--brand"><span class="sh-stat__icon"><?= sh_icon('package', 20) ?></span><div><div class="sh-stat__value"><?= number_format($today['orders']) ?></div><div class="sh-stat__label">Today's orders</div></div></div>
  <div class="sh-stat sh-stat--warn"><span class="sh-stat__icon"><?= sh_icon('clock', 20) ?></span><div><div class="sh-stat__value"><?= number_format($pendingOrders) ?></div><div class="sh-stat__label">Pending orders</div></div></div>
  <div class="sh-stat sh-stat--ok"><span class="sh-stat__icon"><?= sh_icon('check-circle', 20) ?></span><div><div class="sh-stat__value"><?= number_format($completed) ?></div><div class="sh-stat__label">Completed orders</div></div></div>
  <div class="sh-stat sh-stat--bad"><span class="sh-stat__icon"><?= sh_icon('x-circle', 20) ?></span><div><div class="sh-stat__value"><?= number_format($cancelled) ?></div><div class="sh-stat__label">Cancelled orders</div></div></div>
  <div class="sh-stat sh-stat--muted"><span class="sh-stat__icon"><?= sh_icon('rotate', 20) ?></span><div><div class="sh-stat__value"><?= number_format($returned) ?></div><div class="sh-stat__label">Returned orders</div></div></div>
  <div class="sh-stat sh-stat--warn"><span class="sh-stat__icon"><?= sh_icon('credit-card', 20) ?></span><div><div class="sh-stat__value"><?= number_format($pendingPay) ?></div><div class="sh-stat__label">Pending payments</div></div></div>
  <div class="sh-stat sh-stat--info"><span class="sh-stat__icon"><?= sh_icon('users', 20) ?></span><div><div class="sh-stat__value"><?= number_format($customers) ?></div><div class="sh-stat__label">Total customers</div></div></div>
</div>

<?php if ($outStockCount > 0 || $lowStockCount > 0): ?>
  <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
    <span><?php if ($outStockCount > 0): ?><strong><?= (int)$outStockCount ?></strong> product<?= $outStockCount === 1 ? ' is' : 's are' ?> out of stock<?= $lowStockCount > $outStockCount ? ' and <strong>' . ($lowStockCount - $outStockCount) . '</strong> running low' : '' ?>.<?php else: ?><strong><?= (int)$lowStockCount ?></strong> product<?= $lowStockCount === 1 ? ' is' : 's are' ?> running low on stock.<?php endif; ?>
      <a href="<?= e(sh_url('admin/stock.php?view=alerts')) ?>">Review inventory</a>.</span></div>
<?php endif; ?>

<div class="sh-dash-grid">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('bar-chart', 17) ?> Net paid revenue — last 30 days</h2>
      <div class="sh-panel__actions"><span class="sh-panel__note"><?= e(sh_money($chartTotal)) ?> · <?= number_format($chartOrders) ?> orders</span></div>
    </div>
    <div class="sh-panel__body">
      <?php if ($chartOrders === 0): ?>
        <div class="sh-admin-empty"><?= sh_icon('bar-chart', 26) ?><p>No data available for this period.</p></div>
      <?php else: ?>
        <div class="sh-chart" role="img" aria-label="Daily net paid revenue for the last 30 days">
          <?php foreach ($days as $d): $h = $d['amt'] > 0 ? max(3, (int)round(($d['amt'] / $maxAmt) * 100)) : 1; ?>
            <div class="sh-chart__bar"><b><?= e(date('d M', strtotime($d['d']))) ?> · <?= e(sh_money($d['amt'])) ?> · <?= (int)$d['c'] ?> order<?= $d['c'] === 1 ? '' : 's' ?></b><i style="height:<?= $h ?>%;<?= $d['amt'] > 0 ? '' : 'background:var(--sh-line)' ?>"></i></div>
          <?php endforeach; ?>
        </div>
        <div class="sh-chart__axis"><span><?= e(date('d M', strtotime($days[0]['d']))) ?></span><span><?= e(date('d M', strtotime($days[14]['d']))) ?></span><span><?= e(date('d M', strtotime($days[29]['d']))) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('trending-up', 17) ?> Sales analytics</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-period">
        <thead><tr><th>Period</th><th>Net paid revenue</th><th>Orders</th><th>Completed</th><th>Cancelled</th></tr></thead>
        <tbody>
        <?php foreach ($periods as $label => $p): ?>
          <tr><td><strong><?= e($label) ?></strong></td><td><?= e(sh_money((float)$p['paid_revenue'])) ?></td><td><?= number_format((int)$p['orders']) ?></td><td><?= number_format((int)$p['completed_orders']) ?></td><td><?= number_format((int)$p['cancelled_orders']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="sh-panel__body" style="border-top:1px solid var(--sh-line)"><p class="sh-muted" style="margin:0">Net paid revenue counts verified payments only and excludes cancelled, returned and refunded orders. A delivered or completed Cash on Delivery order is not treated as paid until its payment status is verified.</p></div>
  </div>
</div>

<?php if (sh_admin_can('system.health')): $hc = json_decode((string)sh_setting('health_cache', ''), true); $hs = ['healthy' => 0, 'warning' => 0, 'error' => 0, 'unavailable' => 0];
  if (is_array($hc) && !empty($hc['items'])) { foreach ($hc['items'] as $hi) { $hs[$hi['state']] = ($hs[$hi['state']] ?? 0) + 1; } } ?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> System health</h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/system-health.php')) ?>">Open System Health</a></div></div>
  <div class="sh-panel__body">
    <?php if (!is_array($hc) || empty($hc['items'])): ?><p class="sh-muted" style="margin:0">No status check has run yet — open System Health to run the first check.</p>
    <?php else: $ov = $hs['error'] > 0 ? ['ERROR', 'sh-badge--bad'] : ($hs['warning'] > 0 ? ['WARNING', 'sh-badge--warn'] : ['HEALTHY', 'sh-badge--ok']); ?>
      <div class="sh-actions" style="gap:12px"><span class="sh-badge <?= $ov[1] ?>"><?= $ov[0] ?></span>
        <span class="sh-muted"><?= $hs['healthy'] ?> healthy · <?= $hs['warning'] ?> warning · <?= $hs['error'] ?> error · <?= $hs['unavailable'] ?> unavailable · checked <?= e(sh_time_ago($hc['checked_at'])) ?></span></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="sh-dash-grid">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Recent orders</h2>
      <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php')) ?>">View all</a></div>
    </div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$recentOrders): ?><tr class="sh-table--empty"><td colspan="5">No data available</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$o['id'])) ?>" style="font-weight:600"><?= e($o['order_number']) ?></a><div class="sh-table__meta"><?= e(date('d M, h:i A', strtotime($o['created_at']))) ?></div></td>
            <td><?= e($o['customer_name']) ?><div class="sh-table__meta"><?= e($o['customer_phone']) ?></div></td>
            <td style="font-weight:700"><?= e(sh_money($o['total'])) ?></td>
            <td><span class="sh-badge <?= e(sh_status_class($o['payment_status'])) ?>"><?= e(sh_status_label($o['payment_status'])) ?></span></td>
            <td><span class="sh-badge <?= e(sh_status_class($o['status'])) ?>"><?= e(sh_status_label($o['status'])) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('alert', 17) ?> Pending actions</h2></div>
      <div class="sh-panel__body">
        <?php
        $actions = [
            ['n' => $waitingProcessing, 'label' => 'Orders waiting for processing', 'sub' => 'Paid or COD orders not yet shipped', 'href' => 'admin/orders.php?status=processing', 'icon' => 'package', 'cls' => ''],
            ['n' => $pendingPay, 'label' => 'Payments awaiting verification', 'sub' => 'Manual payments with a submitted TrxID', 'href' => 'admin/payments.php?status=pending', 'icon' => 'credit-card', 'cls' => ''],
            ['n' => $lowStockCount, 'label' => 'Low-stock products', 'sub' => $outStockCount > 0 ? $outStockCount . ' out of stock' : 'At or below their threshold', 'href' => 'admin/stock.php?view=alerts', 'icon' => 'box', 'cls' => $outStockCount > 0 ? 'sh-action-row--bad' : ''],
            ['n' => $returnedOpen, 'label' => 'Returned orders (7 days)', 'sub' => 'Review refunds and restocking', 'href' => 'admin/orders.php?status=returned', 'icon' => 'rotate', 'cls' => 'sh-action-row--info'],
            ['n' => $failedPayments, 'label' => 'Failed / rejected payments (7 days)', 'sub' => 'Gateway failures and rejected manual payments', 'href' => 'admin/payments.php?status=failed', 'icon' => 'x-circle', 'cls' => 'sh-action-row--bad'],
            ['n' => $failedNotices, 'label' => 'Failed notifications (24h)', 'sub' => 'SMS / email / Telegram delivery errors', 'href' => 'admin/notifications.php', 'icon' => 'send', 'cls' => 'sh-action-row--bad'],
        ];
        $shown = array_filter($actions, static fn($a) => $a['n'] > 0);
        ?>
        <?php if (!$shown): ?><div class="sh-admin-empty"><?= sh_icon('check-circle', 26) ?><p>Nothing needs your attention right now.</p></div>
        <?php else: ?><div class="sh-actions-list">
          <?php foreach ($shown as $a): ?>
            <a class="sh-action-row <?= e($a['cls']) ?>" href="<?= e(sh_url($a['href'])) ?>"><span class="sh-action-row__icon"><?= sh_icon($a['icon'], 16) ?></span><span class="sh-action-row__text"><?= e($a['label']) ?><small><?= e($a['sub']) ?></small></span><span class="sh-action-row__count"><?= number_format($a['n']) ?></span></a>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Low stock products</h2>
        <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/stock.php?view=alerts')) ?>">Inventory</a></div></div>
      <div class="sh-panel__body">
        <?php if (!$lowStock): ?><div class="sh-admin-empty"><?= sh_icon('check-circle', 26) ?><p>No data available — all products are above their thresholds.</p></div>
        <?php else: ?><div class="sh-lowstock">
          <?php foreach ($lowStock as $ls): $st = sh_stock_status($ls); ?>
            <a class="sh-lowstock__row" href="<?= e(sh_url('admin/products.php?edit=' . (int)$ls['id'])) ?>"><img src="<?= e(sh_product_image($ls['image'])) ?>" alt="" loading="lazy"><div><strong><?= e($ls['name']) ?></strong><br><small>Stock <?= (int)$ls['stock'] ?> · threshold <?= (int)$ls['low_stock_threshold'] ?></small></div><span class="sh-badge <?= e($st['class']) ?>"><?= e(strtoupper($st['label'])) ?></span></a>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
