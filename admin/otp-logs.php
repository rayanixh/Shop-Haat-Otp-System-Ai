<?php
/**
 * OTP request/verification log. Phones are always masked; OTP values and API
 * secrets are never stored or shown here.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_superadmin();

$purpose = sh_get('purpose');
$status = sh_get('status');

$where = ['1=1'];
$args = [];
if ($purpose !== '' && in_array($purpose, sh_otp_purposes(), true)) {
    $where[] = 'purpose = ?';
    $args[] = $purpose;
}
switch ($status) {
    case 'verified': $where[] = 'verified_at IS NOT NULL'; break;
    case 'expired':  $where[] = 'verified_at IS NULL AND expires_at < NOW()'; break;
    case 'failed':   $where[] = 'verified_at IS NULL AND attempts >= max_attempts'; break;
    case 'pending':  $where[] = 'verified_at IS NULL AND expires_at >= NOW() AND attempts < max_attempts'; break;
}
$whereSql = implode(' AND ', $where);

$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 30;
$total = (int)sh_val("SELECT COUNT(*) FROM otp_verifications WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT * FROM otp_verifications WHERE $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);

function sh_otp_log_status(array $r): string
{
    if ($r['verified_at'] !== null) { return 'verified'; }
    if (strtotime((string)$r['expires_at']) < time()) { return 'expired'; }
    if ((int)$r['attempts'] >= (int)$r['max_attempts']) { return 'failed'; }
    return 'pending';
}

$adminPage = 'otp_logs';
$adminTitle = 'OTP Logs';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> OTP Requests (<?= number_format($total) ?>)</h2>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field">
        <label class="sh-field__label" for="f-purpose">Purpose</label>
        <select class="sh-select" id="f-purpose" name="purpose">
          <option value="">All purposes</option>
          <?php foreach (sh_otp_purposes() as $p): ?>
            <option value="<?= e($p) ?>" <?= $purpose === $p ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $p))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="f-status">Status</label>
        <select class="sh-select" id="f-status" name="status">
          <option value="">All statuses</option>
          <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'expired' => 'Expired', 'failed' => 'Failed'] as $v => $l): ?>
            <option value="<?= e($v) ?>" <?= $status === $v ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($purpose !== '' || $status !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/otp-logs.php')) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead>
        <tr><th>Phone</th><th>Purpose</th><th>Status</th><th>Attempts</th><th>Resends</th><th>Provider</th><th>IP</th><th>Requested</th><th>Verified</th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="9">No OTP requests found.</td></tr>
      <?php else: foreach ($rows as $r):
        $st = sh_otp_log_status($r);
        $cls = $st === 'verified' ? 'sh-badge--ok' : ($st === 'failed' || $st === 'expired' ? 'sh-badge--bad' : 'sh-badge--warn');
      ?>
        <tr>
          <td style="font-weight:600"><?= e(sh_phone_mask($r['phone'])) ?></td>
          <td><?= e(ucwords(str_replace('_', ' ', $r['purpose']))) ?></td>
          <td><span class="sh-badge <?= e($cls) ?>"><?= e(ucfirst($st)) ?></span></td>
          <td><?= (int)$r['attempts'] ?>/<?= (int)$r['max_attempts'] ?></td>
          <td><?= (int)$r['resend_count'] ?></td>
          <td class="sh-table__meta"><?= e((string)($r['provider'] ?? '—')) ?></td>
          <td class="sh-table__meta"><?= e((string)$r['ip_address']) ?></td>
          <td class="sh-table__meta"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td class="sh-table__meta"><?= $r['verified_at'] ? e(date('d M Y H:i', strtotime($r['verified_at']))) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/otp-logs.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<p class="sh-panel__note">Phones are masked by design. Verification codes are never stored or shown — only their hash, expiry and attempt counters.</p>
<?php require __DIR__ . '/_footer.php'; ?>
