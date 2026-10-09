<?php
/**
 * Structured security event log. OTP values and API secrets are never written
 * here; any phone/email inside the metadata is masked.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_superadmin();

$event = sh_get('event');
$q = sh_get('q');

$where = ['1=1'];
$args = [];
if ($event !== '') {
    $where[] = 'event = ?';
    $args[] = $event;
}
if ($q !== '') {
    $where[] = '(CAST(user_id AS CHAR) = ? OR ip_address LIKE ? OR metadata LIKE ?)';
    array_push($args, $q, "%$q%", "%$q%");
}
$whereSql = implode(' AND ', $where);

$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 30;
$total = (int)sh_val("SELECT COUNT(*) FROM security_logs WHERE $whereSql", $args, 0);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$rows = sh_all(
    "SELECT sl.*, u.email AS user_email FROM security_logs sl
     LEFT JOIN users u ON u.id = sl.user_id
     WHERE $whereSql ORDER BY sl.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $args
);

// Distinct event types for the filter dropdown.
$events = sh_all('SELECT DISTINCT event FROM security_logs ORDER BY event');

function sh_security_meta(?string $json): array
{
    if ($json === null || $json === '') { return []; }
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}

$adminPage = 'security_logs';
$adminTitle = 'Security Logs';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('lock', 17) ?> Security Events (<?= number_format($total) ?>)</h2>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field">
        <label class="sh-field__label" for="f-event">Event</label>
        <select class="sh-select" id="f-event" name="event">
          <option value="">All events</option>
          <?php foreach ($events as $ev): ?>
            <option value="<?= e($ev['event']) ?>" <?= $event === $ev['event'] ? 'selected' : '' ?>><?= e($ev['event']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="f-q">Search</label>
        <input class="sh-input" id="f-q" name="q" value="<?= e($q) ?>" placeholder="User id, IP or metadata">
      </div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($event !== '' || $q !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/security-logs.php')) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Time</th><th>Event</th><th>User</th><th>IP</th><th>Details</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr class="sh-table--empty"><td colspan="5">No security events found.</td></tr>
      <?php else: foreach ($rows as $r):
        $meta = sh_security_meta($r['metadata']);
        $bad = in_array($r['event'], ['otp_failed', 'otp_expired', 'otp_rate_limited', 'order_blocked', 'login_failed', 'phone_verification_reset'], true);
      ?>
        <tr>
          <td class="sh-table__meta"><?= e(date('d M Y H:i:s', strtotime($r['created_at']))) ?></td>
          <td><span class="sh-badge <?= $bad ? 'sh-badge--bad' : 'sh-badge--muted' ?>"><?= e($r['event']) ?></span></td>
          <td class="sh-table__meta"><?= $r['user_id'] ? '#' . (int)$r['user_id'] . ($r['user_email'] ? ' ' . e($r['user_email']) : '') : '—' ?></td>
          <td class="sh-table__meta"><?= e((string)$r['ip_address']) ?></td>
          <td class="sh-table__meta">
            <?php if ($meta): foreach ($meta as $k => $v): ?>
              <div><?= e($k) ?>: <strong><?= e(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES)) ?></strong></div>
            <?php endforeach; else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body"><?= sh_paginate($total, $per, $page, sh_url('admin/security-logs.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
