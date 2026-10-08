<?php
/**
 * Customer Verification card body. Expects $order and $vb (sh_verify_order_bundle()).
 * Rendered inside admin/orders.php and returned by admin/verification-fetch.php.
 */
$cv = $vb['courier'];
$risk = $cv['risk'];
$geo = $vb['geo'];
$manual = $vb['manual'];
$isCod = stripos((string)($order['payment_method_name'] ?? ''), 'cash') !== false || stripos((string)($order['payment_method_name'] ?? ''), 'cod') !== false;
$pending = $cv['configured'] && $cv['pending'];
$ipPending = $vb['ip'] !== '' && $vb['ip_configured'] && ($geo === null);
$fmt = static fn(?string $dt): string => $dt ? date('d M Y, h:i A', strtotime($dt)) : '—';
?>
<div class="sh-cv" data-cv data-cv-pending="<?= ($pending || $ipPending) ? '1' : '0' ?>">
  <div class="sh-cv__group">
    <div class="sh-cv__label"><?= sh_icon('user', 13) ?> Customer</div>
    <div class="sh-cv__who">
      <strong><?= e($order['customer_name']) ?></strong>
      <span><?= e(sh_phone_display(sh_phone_normalize($order['customer_phone']) ?: $order['customer_phone'])) ?></span>
      <?php if (!sh_is_synthetic_email((string)$order['customer_email'])): ?><span class="sh-cv__muted"><?= e($order['customer_email']) ?></span><?php endif; ?>
      <?php if ($isCod): ?><span class="sh-pill sh-pill--info">Cash on Delivery</span><?php endif; ?>
    </div>
  </div>

  <?php $cvCanCourier = !function_exists('sh_admin_can') || sh_admin_can('verification.courier'); $cvCanIp = !function_exists('sh_admin_can') || sh_admin_can('verification.ip'); $cvCanLoc = !function_exists('sh_admin_can') || sh_admin_can('verification.location'); ?>
  <div class="sh-cv__group">
    <div class="sh-cv__label"><?= sh_icon('truck', 13) ?> Courier delivery history</div>
    <?php if (!$cvCanCourier): ?>
      <p class="sh-cv__muted">Hidden — your role does not include permission to view courier history.</p>
    <?php elseif (!$cv['configured']): ?>
      <p class="sh-cv__muted">Courier verification unavailable — no courier verification provider is configured.
        <a href="<?= e(sh_url('admin/verification.php')) ?>">Set up providers</a>.</p>
    <?php elseif ($pending): ?>
      <p class="sh-cv__muted sh-cv__checking"><span class="sh-cv__spin"></span> Checking delivery history…</p>
    <?php else: ?>
      <?php if ($cv['ok_count'] === 0): ?>
        <p class="sh-cv__muted">Delivery history unavailable — every configured courier request failed. This does not indicate risk.</p>
      <?php else: ?>
        <div class="sh-cv__stats">
          <div class="sh-cv__stat"><small>Total</small><b><?= (int)$cv['total'] ?></b></div>
          <div class="sh-cv__stat sh-cv__stat--ok"><small>Delivered</small><b><?= (int)$cv['delivered'] ?></b></div>
          <div class="sh-cv__stat sh-cv__stat--bad"><small>Returned</small><b><?= (int)$cv['returned'] ?></b></div>
          <div class="sh-cv__stat"><small>Cancelled</small><b><?= (int)$cv['cancelled'] ?></b></div>
        </div>
        <div class="sh-cv__rate">
          <span>Success rate: <strong><?= $cv['success_rate'] === null ? '—' : e(number_format((float)$cv['success_rate'], 1)) . '%' ?></strong></span>
          <span class="sh-pill <?= e($risk['class']) ?>"><?= e($risk['label']) ?></span>
        </div>
        <?php if ($cv['success_rate'] !== null): ?>
          <div class="sh-cv__bar" aria-hidden="true"><span style="width:<?= (float)$cv['success_rate'] ?>%"></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (count($cv['providers']) > 1 || $cv['error_count'] > 0): ?>
        <ul class="sh-cv__breakdown">
          <?php foreach ($cv['providers'] as $pr): ?>
            <li>
              <span class="sh-cv__pname"><?= e($pr['name']) ?></span>
              <?php if ($pr['status'] === 'ok'): ?>
                <span><em class="sh-cv__ok"><?= (int)$pr['delivered'] ?> Delivered</em> • <em class="sh-cv__bad"><?= (int)$pr['returned'] ?> Returned</em><?= $pr['cancelled'] ? ' • ' . (int)$pr['cancelled'] . ' Cancelled' : '' ?></span>
              <?php elseif ($pr['status'] === 'empty'): ?><span class="sh-cv__muted">No records</span>
              <?php elseif ($pr['status'] === 'pending'): ?><span class="sh-cv__muted">Checking…</span>
              <?php else: ?><span class="sh-cv__muted" title="<?= e((string)$pr['error']) ?>">Unavailable</span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="sh-cv__foot">Last checked: <?= e($fmt($cv['last_checked'])) ?><?= $risk['key'] === 'insufficient' && $cv['ok_count'] > 0 ? ' · fewer than 3 decided parcels, so no risk level is shown' : '' ?></p>
    <?php endif; ?>

    <?php if ($manual && $manual['manual_status'] !== ''): ?>
      <p class="sh-cv__manual">
        <span class="sh-pill <?= $manual['manual_status'] === 'verified' ? 'sh-badge--ok' : 'sh-badge--muted' ?>">
          <?= $manual['manual_status'] === 'verified' ? 'Manually verified' : 'Unable to verify' ?></span>
        <small>by admin · <?= e($fmt($manual['manual_at'])) ?><?= $manual['manual_note'] ? ' · ' . e($manual['manual_note']) : '' ?></small>
      </p>
    <?php endif; ?>
  </div>

  <div class="sh-cv__group">
    <div class="sh-cv__label"><?= sh_icon('map-pin', 13) ?> Access information</div>
    <?php if (!$cvCanIp): ?>
      <p class="sh-cv__muted">Hidden — your role does not include permission to view the order IP address.</p>
    <?php elseif ($vb['ip'] === ''): ?>
      <p class="sh-cv__muted">No IP address was recorded for this order (placed before IP capture was enabled).</p>
    <?php else: ?>
      <dl class="sh-cv__kv">
        <div><dt>Order IP</dt><dd><code><?= e($vb['ip']) ?></code></dd></div>
        <div><dt>IP version</dt><dd>IPv<?= (int)$vb['ip_version'] ?></dd></div>
        <?php if (!$cvCanLoc): ?>
          <div><dt>Approximate location</dt><dd class="sh-cv__muted">Hidden for your role</dd></div>
        <?php elseif (!$vb['ip_configured']): ?>
          <div><dt>Approximate location</dt><dd class="sh-cv__muted">Location lookup is disabled</dd></div>
        <?php elseif ($ipPending): ?>
          <div><dt>Approximate location</dt><dd class="sh-cv__muted sh-cv__checking"><span class="sh-cv__spin"></span> Looking up…</dd></div>
        <?php elseif ($geo && $geo['status'] === 'ok'): ?>
          <div><dt>Approximate location</dt><dd><?= e(sh_verify_ip_location_text($geo) ?: '—') ?></dd></div>
        <?php elseif ($geo && $geo['status'] === 'private'): ?>
          <div><dt>Approximate location</dt><dd class="sh-cv__muted">Location unavailable (private network address)</dd></div>
        <?php else: ?>
          <div><dt>Approximate location</dt><dd class="sh-cv__muted">Location unavailable</dd></div>
        <?php endif; ?>
        <div><dt>Order time</dt><dd><?= e($fmt($order['created_at'])) ?></dd></div>
      </dl>
      <?php if ($vb['previous_ips']): ?>
        <details class="sh-cv__prev">
          <summary>Previous order IPs (<?= count($vb['previous_ips']) ?>)</summary>
          <ul>
            <?php foreach ($vb['previous_ips'] as $pi): ?>
              <li><code><?= e((string)$pi['order_ip']) ?></code> <span class="sh-cv__muted"><?= e((string)$pi['order_number']) ?> · <?= e(date('d M Y', strtotime((string)$pi['created_at']))) ?><?= $pi['order_ip'] === $vb['ip'] ? ' · same as this order' : '' ?></span></li>
            <?php endforeach; ?>
          </ul>
          <p class="sh-cv__foot">Informational only — shared or repeated IPs are common on mobile networks.</p>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <p class="sh-cv__disclaimer">Verification aid only. Courier history and the approximate IP location are indicators, not a judgement about this customer. The IP location is approximate (city/region level) and is not the customer's address.</p>
</div>
