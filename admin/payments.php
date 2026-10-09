<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('payments.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $id = sh_int($_POST['id'] ?? 0);
    $note = mb_substr(sh_post('admin_note'), 0, 250);
    $form = sh_post('form');

    if ($form === 'approve') {
        if (sh_perm_denied_flash('payments.verify')) { sh_redirect('admin/payments.php?id=' . $id); }
        $cur = sh_one('SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ?', [$id]);
        $dupes = $cur ? sh_payment_duplicates((string)$cur['transaction_id'], $id) : [];
        if ($dupes && sh_post('confirm_duplicate') !== '1') {
            sh_flash('error', 'Duplicate Transaction ID: this TrxID was already used on another order. Tick the confirmation box if you have verified it against your merchant statement.');
            sh_redirect('admin/payments.php?id=' . $id);
        }
        $res = sh_approve_payment($id, (int)$admin['id'], $note);
        if (!empty($res['ok'])) {
            sh_log_line('admin', 'Payment #' . $id . ' approved by ' . $admin['email']);
            sh_audit('payment_verified', 'payment', $id, 'Payment #' . $id . ($cur ? ' · ' . $cur['order_number'] : ''), 'pending', array_filter(['status' => 'verified', 'note' => $note, 'duplicate_confirmed' => $dupes ? 'yes' : null]));
            sh_flash('success', 'Payment approved. The order moved to Processing and notifications were dispatched.');
        } else {
            sh_flash('error', $res['error'] ?? 'The payment could not be approved.');
        }
        sh_redirect('admin/payments.php?id=' . $id);
    }

    if ($form === 'reject') {
        if (sh_perm_denied_flash('payments.reject')) { sh_redirect('admin/payments.php?id=' . $id); }
        if ($note === '') {
            sh_flash('error', 'Please write a short reason before rejecting a payment.');
        } else {
            $res = sh_reject_payment($id, (int)$admin['id'], $note);
            if (!empty($res['ok'])) {
                sh_log_line('admin', 'Payment #' . $id . ' rejected by ' . $admin['email']);
                $cur = sh_one('SELECT o.order_number FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ?', [$id]);
                sh_audit('payment_rejected', 'payment', $id, 'Payment #' . $id . ($cur ? ' · ' . $cur['order_number'] : ''), 'pending', ['status' => 'rejected', 'note' => $note]);
                sh_flash('success', 'Payment rejected and the customer has been notified.');
            } else {
                sh_flash('error', $res['error'] ?? 'The payment could not be rejected.');
            }
        }
        sh_redirect('admin/payments.php?id=' . $id);
    }
}

$adminPage = 'payments';
$viewId = sh_int($_GET['id'] ?? 0);

/* ---------------- Single payment review ---------------- */
if ($viewId > 0) {
    $pay = sh_one(
        'SELECT p.*, o.order_number, o.customer_name, o.customer_email, o.customer_phone, o.user_id,
                o.total AS order_total, o.status AS order_status, o.payment_status, o.has_digital,
                a.name AS verified_by_name
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         LEFT JOIN admins a ON a.id = p.verified_by
         WHERE p.id = ? LIMIT 1',
        [$viewId]
    );
    if ($pay === null) {
        http_response_code(404);
        $adminTitle = 'Payment not found';
        require __DIR__ . '/_layout.php';
        echo '<div class="sh-panel"><div class="sh-panel__body"><p class="sh-panel__note">This payment record does not exist.</p>'
            . '<a class="sh-btn sh-btn--sm" style="margin-top:10px" href="' . e(sh_url('admin/payments.php')) . '">Back to payments</a></div></div>';
        require __DIR__ . '/_footer.php';
        exit;
    }

    $amountMatches = abs((float)$pay['amount'] - (float)$pay['order_total']) < 0.01;
    $duplicates = $pay['transaction_id'] ? sh_payment_duplicates((string)$pay['transaction_id'], $viewId) : [];
    $duplicate = $duplicates[0] ?? null;
    $payAudit = sh_audit_for('payment', $viewId, 10);
    $pending = $pay['status'] === 'pending';

    $adminTitle = 'Payment review';
    require __DIR__ . '/_layout.php';
    ?>
    <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/payments.php')) ?>"><?= sh_icon('chevron-left', 14) ?> All payments</a>
      <span class="sh-badge <?= e(sh_status_class($pay['status'])) ?>"><?= e(sh_status_label($pay['status'])) ?></span>
    </div>

    <?php if ($duplicate !== null): ?>
      <div class="sh-alert sh-alert--error"><?= sh_icon('alert', 17) ?>
        <span><strong>Duplicate Transaction ID.</strong> The same transaction ID was also submitted on
          <?php foreach ($duplicates as $i => $d): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= e(sh_url('admin/payments.php?id=' . (int)$d['id'])) ?>"><?= e($d['order_number']) ?></a> (<?= e(sh_status_label($d['status'])) ?>)<?php endforeach; ?>.
          This payment will not be approved automatically — verify carefully with your merchant statement first.</span></div>
    <?php endif; ?>
    <?php if (!$amountMatches): ?>
      <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
        <span>The submitted amount (<?= e(sh_money($pay['amount'])) ?>) does not match the order total
          (<?= e(sh_money($pay['order_total'])) ?>).</span></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px" class="sh-paygrid">
      <div class="sh-panel">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Submitted payment details</h2></div>
        <div class="sh-tablewrap">
          <table class="sh-table">
            <tbody>
              <tr><th style="width:190px">Order</th><td>
                <a href="<?= e(sh_url('admin/orders.php?id=' . (int)$pay['order_id'])) ?>" style="font-weight:600"><?= e($pay['order_number']) ?></a></td></tr>
              <tr><th>Payment method</th><td><?= e((string)$pay['method_name']) ?> <span class="sh-table__meta">(<?= e((string)$pay['kind']) ?>)</span></td></tr>
              <tr><th>Transaction ID</th><td><code style="font-size:14px;font-weight:700"><?= e((string)($pay['transaction_id'] ?: '—')) ?></code></td></tr>
              <tr><th>Sender number</th><td><?= e((string)($pay['sender_phone'] ?: '—')) ?></td></tr>
              <tr><th>Amount submitted</th><td style="font-weight:700"><?= e(sh_money($pay['amount'])) ?></td></tr>
              <tr><th>Order total</th><td style="font-weight:700;color:<?= $amountMatches ? 'inherit' : '#c33' ?>"><?= e(sh_money($pay['order_total'])) ?></td></tr>
              <tr><th>Submitted at</th><td><?= e(date('d M Y, h:i A', strtotime($pay['created_at']))) ?></td></tr>
              <?php if ($pay['gateway_reference']): ?>
                <tr><th>Gateway reference</th><td><code><?= e($pay['gateway_reference']) ?></code></td></tr>
              <?php endif; ?>
              <?php if ($pay['verified_at']): ?>
                <tr><th>Reviewed</th><td><?= e(date('d M Y, h:i A', strtotime($pay['verified_at']))) ?>
                  by <?= e((string)($pay['verified_by_name'] ?? 'admin')) ?></td></tr>
              <?php endif; ?>
              <?php if ($pay['admin_note']): ?>
                <tr><th>Admin note</th><td><?= e($pay['admin_note']) ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Verification decision</h2></div>
          <div class="sh-panel__body">
            <?php if ($pending && !sh_admin_can('payments.verify', 'payments.reject')): ?>
              <p class="sh-panel__note">Your role can view payments but cannot verify or reject them.</p>
            <?php elseif (!$pending): ?>
              <p class="sh-panel__note">This payment has already been marked as
                <strong><?= e(sh_status_label($pay['status'])) ?></strong>. No further action is available.</p>
            <?php else: ?>
              <p class="sh-panel__note" style="margin-bottom:12px">
                Check the transaction ID against your <?= e((string)$pay['method_name']) ?> merchant statement before deciding.
                Approving releases the order<?= (int)$pay['has_digital'] === 1 ? ' and delivers the digital codes' : '' ?>.
              </p>
              <form method="post">
                <?= sh_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$pay['id'] ?>">
                <div class="sh-field">
                  <label class="sh-field__label" for="pn">Note (required to reject)</label>
                  <textarea class="sh-textarea" id="pn" name="admin_note" rows="3" maxlength="250"
                            placeholder="e.g. Matched statement entry at 14:20"></textarea>
                </div>
                <?php if ($duplicates): ?>
                  <label class="sh-check" style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin-bottom:10px">
                    <input type="checkbox" name="confirm_duplicate" value="1"> <span>I checked the merchant statement and confirm this duplicate Transaction ID is a genuine, separate payment.</span></label>
                <?php endif; ?>
                <?php if (sh_admin_can('payments.verify')): ?><button class="sh-btn sh-btn--block" type="submit" name="form" value="approve"
                        data-confirm-click="Approve this payment and release the order?">
                  <?= sh_icon('check-circle', 15) ?> Approve payment</button><?php endif; ?>
                <?php if (sh_admin_can('payments.reject')): ?><button class="sh-btn sh-btn--bad sh-btn--block" style="margin-top:8px" type="submit" name="form" value="reject"
                        data-confirm-click="Reject this payment?">
                  <?= sh_icon('x-circle', 15) ?> Reject payment</button><?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('user', 17) ?> Customer</h2></div>
          <div class="sh-panel__body" style="font-size:13.2px;line-height:1.85">
            <strong><?= e($pay['customer_name']) ?></strong><br>
            <?= sh_icon('mail', 13) ?> <?= e($pay['customer_email']) ?><br>
            <?= sh_icon('phone', 13) ?> <?= e($pay['customer_phone']) ?>
            <div class="sh-actions" style="margin-top:8px">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/orders.php?id=' . (int)$pay['order_id'])) ?>"><?= sh_icon('package', 13) ?> View order</a>
              <?php if ($pay['user_id']): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/customers.php?id=' . (int)$pay['user_id'])) ?>"><?= sh_icon('user', 13) ?> View customer</a>
              <?php else: ?><span class="sh-table__meta">Guest checkout</span><?php endif; ?>
            </div>
          </div>
        </div>
        <?php if ($payAudit): ?>
        <div class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('history', 17) ?> Admin activity</h2></div>
          <div class="sh-panel__body" style="font-size:12.8px;display:flex;flex-direction:column;gap:6px">
            <?php foreach ($payAudit as $a): ?><div><strong><?= e(sh_audit_action_label($a['action'])) ?></strong> · <?= e($a['admin_email']) ?> · <span class="sh-muted"><?= e(date('d M Y, h:i A', strtotime($a['created_at']))) ?></span></div><?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <style>@media (max-width: 1000px){.sh-paygrid{grid-template-columns:minmax(0,1fr)!important}}</style>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

/* ---------------- Payment list ---------------- */
$fStatus = sh_get('status', 'pending');
$q = sh_get('q');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;

$where = ['1=1']; $args = [];
if ($fStatus === 'refunded') { $where[] = "o.payment_status = 'refunded'"; }
elseif ($fStatus === 'duplicate') { $where[] = "p.transaction_id IS NOT NULL AND p.transaction_id <> '' AND EXISTS (SELECT 1 FROM payments pd WHERE pd.transaction_id = p.transaction_id AND pd.order_id <> p.order_id)"; }
elseif ($fStatus !== '' && $fStatus !== 'all') { $where[] = 'p.status = ?'; $args[] = $fStatus; }
if ($q !== '') { $where[] = '(p.transaction_id LIKE ? OR o.order_number LIKE ? OR p.sender_phone LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_email LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%"); }
$fMethod = sh_get('method');
if ($fMethod !== '' && in_array($fMethod, ['manual', 'cod', 'gateway'], true)) { $where[] = 'p.kind = ?'; $args[] = $fMethod; }
$whereSql = implode(' AND ', $where);

$total = (int)sh_val("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT p.*, o.order_number, o.customer_name, o.customer_phone, o.user_id, o.payment_status AS order_payment_status, o.total AS order_total
     FROM payments p JOIN orders o ON o.id = p.order_id
     WHERE $whereSql ORDER BY p.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);
$counts = [];
foreach (sh_all('SELECT status, COUNT(*) c FROM payments GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['c']; }
$counts['duplicate'] = (int)sh_val("SELECT COUNT(*) FROM payments p WHERE p.transaction_id IS NOT NULL AND p.transaction_id <> '' AND EXISTS (SELECT 1 FROM payments pd WHERE pd.transaction_id = p.transaction_id AND pd.order_id <> p.order_id)", [], 0);
$dupeMap = sh_payment_duplicate_map($rows);

if (in_array($fStatus, ['verified', 'rejected', 'all'], true)) { $adminPage = 'payments:' . $fStatus; }
$adminTitle = 'Payments';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('credit-card', 17) ?> Payments (<?= number_format($total) ?>)</h2>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px">
      <?php foreach ([['pending', 'Pending'], ['verified', 'Verified'], ['rejected', 'Rejected'], ['failed', 'Failed'], ['refunded', 'Refunded'], ['duplicate', 'Duplicate TrxID'], ['all', 'All']] as [$k, $label]): ?>
        <a class="sh-btn sh-btn--sm <?= $fStatus === $k ? '' : 'sh-btn--ghost' ?>"
           href="<?= e(sh_url('admin/payments.php?status=' . $k)) ?>"><?= e($label) ?>
          <?php if ($k !== 'all' && !empty($counts[$k])): ?> (<?= (int)$counts[$k] ?>)<?php endif; ?></a>
      <?php endforeach; ?>
    </div>
    <form class="sh-filterbar" method="get">
      <input type="hidden" name="status" value="<?= e($fStatus) ?>">
      <div class="sh-field"><label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="TrxID, order no, customer, phone"></div>
      <div class="sh-field"><label class="sh-field__label" for="f-method">Method</label>
        <select class="sh-select" id="f-method" name="method"><option value="">All</option>
          <?php foreach (['manual' => 'Manual (bKash / Nagad / Rocket)', 'cod' => 'Cash on Delivery', 'gateway' => 'Online gateway'] as $k => $l): ?><option value="<?= $k ?>" <?= $fMethod === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
      <?php if ($q !== '' || $fMethod !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/payments.php?status=' . $fStatus)) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Order</th><th>Method</th><th>Transaction ID</th><th>Amount</th><th>Status</th><th>Submitted</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="7">No data available</td></tr>
      <?php else: foreach ($rows as $p): ?>
        <tr>
          <td><a href="<?= e(sh_url('admin/orders.php?id=' . (int)$p['order_id'])) ?>" style="font-weight:600"><?= e($p['order_number']) ?></a>
            <div class="sh-table__meta"><?php if ($p['user_id']): ?><a href="<?= e(sh_url('admin/customers.php?id=' . (int)$p['user_id'])) ?>"><?= e($p['customer_name']) ?></a><?php else: ?><?= e($p['customer_name']) ?><?php endif; ?> · <?= e($p['customer_phone']) ?></div></td>
          <td><?= e((string)$p['method_name']) ?><div class="sh-table__meta"><?= e((string)$p['kind']) ?></div></td>
          <td><?= $p['transaction_id'] ? '<code>' . e($p['transaction_id']) . '</code>' : '<span class="sh-table__meta">not submitted</span>' ?>
            <?php if (isset($dupeMap[(int)$p['id']])): ?><div><span class="sh-dupe"><?= sh_icon('alert', 11) ?> Duplicate Transaction ID</span></div><?php endif; ?></td>
          <td style="font-weight:700"><?= e(sh_money($p['amount'])) ?>
            <?php if (abs((float)$p['amount'] - (float)$p['order_total']) >= 0.01): ?>
              <div class="sh-table__meta" style="color:#c33">order <?= e(sh_money($p['order_total'])) ?></div><?php endif; ?></td>
          <td><span class="sh-badge <?= e(sh_status_class($p['status'])) ?>"><?= e(sh_status_label($p['status'])) ?></span><?php if ($p['order_payment_status'] === 'refunded'): ?> <span class="sh-badge sh-badge--muted">Refunded</span><?php endif; ?></td>
          <td class="sh-table__meta"><?= e(date('d M, h:i A', strtotime($p['created_at']))) ?></td>
          <td style="text-align:right">
            <a class="sh-btn sh-btn--sm <?= $p['status'] === 'pending' && $p['transaction_id'] ? '' : 'sh-btn--ghost' ?>"
               href="<?= e(sh_url('admin/payments.php?id=' . (int)$p['id'])) ?>">
              <?= $p['status'] === 'pending' && $p['transaction_id'] ? 'Review' : 'View' ?></a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/payments.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
