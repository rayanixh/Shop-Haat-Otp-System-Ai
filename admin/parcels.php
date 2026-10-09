<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/courier.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('orders.update_status', 'integrations.manage');
sh_courier_schema_ensure();

$viewId = sh_int($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'create') {
        $orderId = sh_int($_POST['order_id'] ?? 0);
        $orderNumber = strtoupper(sh_post('order_number'));
        if ($orderId <= 0 && $orderNumber !== '') {
            $o = sh_one('SELECT id FROM orders WHERE order_number = ? LIMIT 1', [$orderNumber]);
            $orderId = $o ? (int)$o['id'] : 0;
        }
        if ($orderId <= 0) {
            sh_flash('error', 'Choose a valid order for this parcel.');
            sh_redirect('admin/parcels.php');
        }
        $res = sh_shipment_create([
            'order_id'       => $orderId,
            'courier_id'     => sh_int($_POST['courier_id'] ?? 0),
            'tracking_number' => sh_post('tracking_number'),
            'package_weight' => sh_post('package_weight'),
            'package_type'   => sh_post('package_type'),
            'cod_amount'     => sh_post('cod_amount'),
            'shipping_cost'  => sh_post('shipping_cost'),
            'note'           => sh_post('note'),
        ], (int)$admin['id']);

        if (empty($res['ok'])) {
            sh_flash('error', $res['error'] ?? 'The parcel could not be created.');
            sh_redirect('admin/parcels.php');
        }
        sh_flash('success', 'Parcel ' . $res['shipment_number'] . ' created.');
        sh_redirect('admin/parcels.php?id=' . (int)$res['shipment_id']);
    }

    if ($form === 'status') {
        $id = sh_int($_POST['id'] ?? 0);
        $status = sh_post('status');
        $res = sh_shipment_set_status($id, $status, sh_post('note'), (int)$admin['id']);
        if (empty($res['ok'])) {
            sh_flash('error', $res['error'] ?? 'The parcel status could not be updated.');
        } else {
            $msg = 'Parcel status updated to ' . sh_shipment_status_label($status) . '.';
            if (!empty($res['synced']['changed'])) {
                $msg .= ' The order was moved to "' . sh_status_label($res['synced']['order_status']) . '".';
            }
            sh_flash('success', $msg);
        }
        sh_redirect('admin/parcels.php?id=' . $id);
    }

    if ($form === 'book') {
        $id = sh_int($_POST['id'] ?? 0);
        $res = sh_courier_book($id, (int)$admin['id']);
        if (empty($res['ok'])) { sh_flash('error', $res['error'] ?? 'Booking failed.'); }
        else { sh_flash('success', 'Parcel booked.'); }
        sh_redirect('admin/parcels.php?id=' . $id);
    }

    if ($form === 'fetch') {
        $id = sh_int($_POST['id'] ?? 0);
        $res = sh_courier_fetch_status($id, (int)$admin['id']);
        if (empty($res['ok'])) { sh_flash('error', $res['error'] ?? 'Status check failed.'); }
        else { sh_flash('success', $res['message'] ?? 'Status refreshed.'); }
        sh_redirect('admin/parcels.php?id=' . $id);
    }
}

$adminPage = 'parcels';

/* ---------------- Single parcel view ---------------- */
if ($viewId > 0) {
    $shipment = sh_one(
        'SELECT s.*, o.order_number, o.customer_name, o.customer_email, o.customer_phone,
                o.total AS order_total, o.payment_status, o.status AS order_status, o.payment_method_name,
                c.name AS courier_name, c.driver AS courier_driver, c.tracking_url AS courier_tracking_url
         FROM shipments s
         JOIN orders o ON o.id = s.order_id
         LEFT JOIN couriers c ON c.id = s.courier_id
         WHERE s.id = ? LIMIT 1',
        [$viewId]
    );
    if ($shipment === null) {
        http_response_code(404);
        $adminTitle = 'Parcel not found';
        require __DIR__ . '/_layout.php';
        echo '<div class="sh-panel"><div class="sh-panel__body"><p class="sh-panel__note">This parcel does not exist.</p>'
            . '<a class="sh-btn sh-btn--sm" style="margin-top:10px" href="' . e(sh_url('admin/parcels.php')) . '">Back to parcels</a></div></div>';
        require __DIR__ . '/_footer.php';
        exit;
    }
    $events = sh_shipment_events($viewId);
    $courierForAction = $shipment['courier_id'] ? sh_courier_by_id((int)$shipment['courier_id']) : null;
    $trackUrl = $courierForAction ? sh_courier_tracking_url($courierForAction, (string)$shipment['tracking_number']) : '';
    $apiReady = $courierForAction && in_array($courierForAction['driver'], sh_courier_api_drivers(), true) && sh_courier_is_configured($courierForAction);
    $manualCourier = $courierForAction && in_array($courierForAction['driver'], ['manual', 'custom'], true);

    $adminTitle = 'Parcel ' . $shipment['shipment_number'];
    require __DIR__ . '/_layout.php';
    ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/parcels.php')) ?>"><?= sh_icon('chevron-left', 14) ?> All parcels</a>
      <span class="sh-badge <?= e(sh_shipment_status_class($shipment['status'])) ?>"><?= e(sh_shipment_status_label($shipment['status'])) ?></span>
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php?id=' . (int)$shipment['order_id'])) ?>">
        <?= sh_icon('package', 14) ?> Order <?= e($shipment['order_number']) ?></a>
      <?php if ($trackUrl !== '' && $shipment['tracking_number']): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e($trackUrl) ?>" target="_blank" rel="noopener"><?= sh_icon('external', 14) ?> Track online</a>
      <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px" class="sh-pargrid">
      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('clock', 17) ?> Timeline</h2></div>
          <div class="sh-tablewrap">
            <table class="sh-table">
              <thead><tr><th>Status</th><th>Note</th><th>Source</th><th>By</th><th>When</th></tr></thead>
              <tbody>
              <?php if (!$events): ?><tr class="sh-table--empty"><td colspan="5">No events yet.</td></tr>
              <?php else: foreach ($events as $ev): ?>
                <tr>
                  <td><span class="sh-badge <?= e(sh_shipment_status_class($ev['status'])) ?>"><?= e(sh_shipment_status_label($ev['status'])) ?></span></td>
                  <td class="sh-table__meta" style="max-width:280px"><?= e((string)($ev['note'] ?? '')) ?></td>
                  <td><?= $ev['source'] === 'api' ? '<span class="sh-badge sh-badge--muted">API</span>' : '<span class="sh-table__meta">Manual</span>' ?></td>
                  <td class="sh-table__meta"><?= e((string)($ev['admin_name'] ?? '')) ?></td>
                  <td class="sh-table__meta"><?= e(date('d M, h:i A', strtotime($ev['created_at']))) ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Delivery details</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.9">
            <strong><?= e($shipment['recipient_name']) ?></strong> ·
            <?= sh_icon('phone', 13) ?> <?= e($shipment['recipient_phone']) ?><br>
            <?= sh_icon('map-pin', 13) ?> <?= e((string)$shipment['recipient_address']) ?>
            <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px 22px" class="sh-table__meta">
              <span>Weight: <strong><?= e(rtrim(rtrim(number_format((float)$shipment['package_weight'], 2), '0'), '.')) ?> kg</strong></span>
              <span>Type: <strong><?= e($shipment['package_type']) ?></strong></span>
              <span>COD: <strong><?= e(sh_money($shipment['cod_amount'])) ?></strong></span>
              <span>Shipping cost: <strong><?= e(sh_money($shipment['shipping_cost'])) ?></strong></span>
              <span>Order total: <strong><?= e(sh_money($shipment['order_total'])) ?></strong></span>
              <span>Payment: <strong><?= e(sh_status_label($shipment['payment_status'])) ?></strong></span>
            </div>
            <?php if ((string)$shipment['note'] !== ''): ?>
              <div style="margin-top:10px" class="sh-table__meta"><strong>Note:</strong> <?= e((string)$shipment['note']) ?></div>
            <?php endif; ?>
            <div style="margin-top:10px" class="sh-table__meta">
              Created <?= e(date('d M Y, h:i A', strtotime($shipment['created_at']))) ?>
              <?= $shipment['booked_at'] ? ' · Booked ' . e(date('d M, h:i A', strtotime($shipment['booked_at']))) : '' ?>
              <?= $shipment['delivered_at'] ? ' · Delivered ' . e(date('d M, h:i A', strtotime($shipment['delivered_at']))) : '' ?>
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('refresh', 17) ?> Update status</h2></div>
          <div class="sh-panel__body">
            <p class="sh-panel__note" style="margin-bottom:10px">
              Marking a parcel <strong>Delivered</strong> completes the order; <strong>Returned</strong> cancels it.
              Other movement sets the order to Processing.</p>
            <form method="post">
              <?= sh_csrf_field() ?>
              <input type="hidden" name="form" value="status"><input type="hidden" name="id" value="<?= (int)$shipment['id'] ?>">
              <div class="sh-field">
                <label class="sh-field__label" for="p-status">Parcel status</label>
                <select class="sh-select" id="p-status" name="status">
                  <?php foreach (sh_shipment_statuses() as $s => $label): ?>
                    <option value="<?= e($s) ?>" <?= $shipment['status'] === $s ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sh-field">
                <label class="sh-field__label" for="p-note">Note (optional)</label>
                <input class="sh-input" id="p-note" name="note" maxlength="255" placeholder="e.g. Rider call before delivery">
              </div>
              <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> Apply status</button>
            </form>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Courier</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.9">
            <strong><?= e((string)($shipment['courier_name'] ?? 'Not assigned')) ?></strong>
            <?php if ($courierForAction): ?>
              <div class="sh-table__meta"><?= e(sh_courier_drivers()[$courierForAction['driver']] ?? $courierForAction['driver']) ?></div>
            <?php endif; ?>
            <div style="margin-top:8px">
              Tracking number:
              <?php if ($shipment['tracking_number']): ?>
                <code><?= e($shipment['tracking_number']) ?></code>
              <?php else: ?><span class="sh-table__meta">—</span><?php endif; ?>
            </div>

            <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px">
              <?php if ($courierForAction === null): ?>
                <span class="sh-table__meta">Assign a courier on the order page or create a parcel with one to enable booking.</span>
              <?php elseif ($manualCourier): ?>
                <?php if (in_array($shipment['status'], ['draft', 'cancelled'], true)): ?>
                  <form method="post"><?= sh_csrf_field() ?>
                    <input type="hidden" name="form" value="book"><input type="hidden" name="id" value="<?= (int)$shipment['id'] ?>">
                    <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 14) ?> Mark as booked</button></form>
                <?php endif; ?>
                <span class="sh-table__meta">Manual courier — update statuses above.</span>
              <?php elseif ($apiReady): ?>
                <?php if (in_array($shipment['status'], ['draft', 'cancelled'], true)): ?>
                  <form method="post"><?= sh_csrf_field() ?>
                    <input type="hidden" name="form" value="book"><input type="hidden" name="id" value="<?= (int)$shipment['id'] ?>">
                    <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('send', 14) ?> Book with <?= e((string)$shipment['courier_name']) ?></button></form>
                <?php endif; ?>
                <?php if ($shipment['tracking_number']): ?>
                  <form method="post"><?= sh_csrf_field() ?>
                    <input type="hidden" name="form" value="fetch"><input type="hidden" name="id" value="<?= (int)$shipment['id'] ?>">
                    <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('rotate', 14) ?> Fetch status</button></form>
                <?php endif; ?>
              <?php else: ?>
                <span class="sh-table__meta"><?= e(sh_courier_status_text($courierForAction)) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <style>@media (max-width: 1050px){.sh-pargrid{grid-template-columns:minmax(0,1fr)!important}}</style>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

/* ---------------- Parcel list + create ---------------- */
$q = sh_get('q');
$fCourier = sh_get('courier');
$fStatus = sh_get('status');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1']; $args = [];
if ($q !== '') {
    $where[] = '(s.shipment_number LIKE ? OR s.tracking_number LIKE ? OR o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($fCourier !== '') { $where[] = 's.courier_id = ?'; $args[] = (int)$fCourier; }
if ($fStatus !== '') { $where[] = 's.status = ?'; $args[] = $fStatus; }
$whereSql = implode(' AND ', $where);

$total = 0; $rows = [];
try {
    $total = (int)sh_val("SELECT COUNT(*) FROM shipments s JOIN orders o ON o.id = s.order_id WHERE $whereSql", $args, 0);
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $rows = sh_all(
        "SELECT s.*, o.order_number, o.customer_name, o.customer_phone, o.total AS order_total,
                c.name AS courier_name, c.tracking_url AS courier_tracking_url
         FROM shipments s
         JOIN orders o ON o.id = s.order_id
         LEFT JOIN couriers c ON c.id = s.courier_id
         WHERE $whereSql
         ORDER BY s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
        $args
    );
} catch (Throwable $e) {
    sh_log_exception($e, 'parcels-list');
}
$pages = max(1, (int)ceil($total / $per));

// Pre-fill the create form from the order page link.
$preOrder = null;
$preOrderId = sh_int($_GET['order_id'] ?? 0);
$orderNum = strtoupper(sh_get('order_number'));
if ($preOrderId > 0) {
    $preOrder = sh_order_get($preOrderId);
} elseif ($orderNum !== '') {
    $preOrder = sh_one('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [$orderNum]);
}
$activeCouriers = sh_couriers(true);

$adminTitle = 'Parcels';
require __DIR__ . '/_layout.php';
?>
<div style="display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px" class="sh-parlist">
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('package', 17) ?> Parcels (<?= number_format($total) ?>)</h2>
    </div>
    <div class="sh-panel__body" style="padding-bottom:0">
      <form class="sh-filterbar" method="get">
        <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
          <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="Parcel no, tracking, order, name"></div>
        <div class="sh-field"><label class="sh-field__label" for="f-cr">Courier</label>
          <select class="sh-select" id="f-cr" name="courier">
            <option value="">All</option>
            <?php foreach (sh_couriers() as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $fCourier === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="f-st">Status</label>
          <select class="sh-select" id="f-st" name="status">
            <option value="">All</option>
            <?php foreach (sh_shipment_statuses() as $s => $label): ?>
              <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
        <?php if ($q !== '' || $fCourier !== '' || $fStatus !== ''): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/parcels.php')) ?>">Reset</a><?php endif; ?>
      </form>
    </div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Parcel</th><th>Courier</th><th>Order</th><th>Customer</th><th>COD</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No parcels match these filters.</td></tr>
        <?php else: foreach ($rows as $p): ?>
          <tr>
            <td><a href="<?= e(sh_url('admin/parcels.php?id=' . (int)$p['id'])) ?>" style="font-weight:600"><?= e($p['shipment_number']) ?></a>
              <?php if ($p['tracking_number']): ?><div class="sh-table__meta"><?= e($p['tracking_number']) ?></div><?php endif; ?>
              <div class="sh-table__meta"><?= e(date('d M, h:i A', strtotime($p['created_at']))) ?></div></td>
            <td><?= $p['courier_name'] ? e($p['courier_name']) : '<span class="sh-table__meta">Unassigned</span>' ?></td>
            <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$p['order_id'])) ?>"><?= e($p['order_number']) ?></a>
              <div class="sh-table__meta"><?= e(sh_money($p['order_total'])) ?></div></td>
            <td><?= e($p['customer_name']) ?><div class="sh-table__meta"><?= e($p['customer_phone']) ?></div></td>
            <td style="font-weight:700"><?= e(sh_money($p['cod_amount'])) ?></td>
            <td><span class="sh-badge <?= e(sh_shipment_status_class($p['status'])) ?>"><?= e(sh_shipment_status_label($p['status'])) ?></span></td>
            <td style="text-align:right"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/parcels.php?id=' . (int)$p['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/parcels.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
    <?php endif; ?>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('plus', 17) ?> New parcel</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="create">
        <input type="hidden" name="order_id" value="<?= (int)($preOrder['id'] ?? 0) ?>">

        <div class="sh-field"><label class="sh-field__label" for="np-order">Order number <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="np-order" name="order_number" required
                 value="<?= e($preOrder ? (string)$preOrder['order_number'] : '') ?>"
                 placeholder="SH25010100001" style="text-transform:uppercase"
                 <?= $preOrder ? 'readonly' : '' ?>>
          <span class="sh-field__hint"><?= $preOrder ? 'Order selected below.' : 'Enter the order number, or open an order and click “Create parcel”.' ?></span></div>

        <?php if ($preOrder): ?>
          <div style="background:var(--sh-bg);border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:12.6px;line-height:1.7">
            <strong><?= e($preOrder['customer_name']) ?></strong> · <?= e($preOrder['customer_phone']) ?><br>
            <span class="sh-table__meta"><?= e(trim((string)$preOrder['shipping_address'] . ', ' . (string)$preOrder['shipping_city'])) ?></span><br>
            Total <strong><?= e(sh_money($preOrder['total'])) ?></strong> ·
            <span class="sh-badge <?= e(sh_status_class($preOrder['payment_status'])) ?>"><?= e(sh_status_label($preOrder['payment_status'])) ?></span>
          </div>
        <?php endif; ?>

        <div class="sh-field"><label class="sh-field__label" for="np-courier">Courier</label>
          <select class="sh-select" id="np-courier" name="courier_id">
            <option value="">— choose later —</option>
            <?php foreach ($activeCouriers as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sh-field"><label class="sh-field__label" for="np-tracking">Tracking / consignment number</label>
          <input class="sh-input" id="np-tracking" name="tracking_number" maxlength="120" placeholder="Leave blank until booked"></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="np-weight">Weight (kg)</label>
            <input class="sh-input" id="np-weight" name="package_weight" inputmode="decimal" value="0"></div>
          <div class="sh-field"><label class="sh-field__label" for="np-type">Package type</label>
            <input class="sh-input" id="np-type" name="package_type" maxlength="60" value="Parcel"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="np-cod">COD amount</label>
            <input class="sh-input" id="np-cod" name="cod_amount" inputmode="decimal" placeholder="auto">
            <span class="sh-field__hint">Blank = order total for COD orders, otherwise 0.</span></div>
          <div class="sh-field"><label class="sh-field__label" for="np-cost">Shipping cost</label>
            <input class="sh-input" id="np-cost" name="shipping_cost" inputmode="decimal" value="0"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="np-note">Note</label>
          <textarea class="sh-textarea" id="np-note" name="note" rows="3" maxlength="500"></textarea></div>
        <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('plus', 15) ?> Create parcel</button>
      </form>
    </div>
  </div>
</div>
<style>@media (max-width: 1100px){.sh-parlist{grid-template-columns:minmax(0,1fr)!important}}</style>
<?php require __DIR__ . '/_footer.php'; ?>
