<?php
/**
 * Email delivery log.
 *
 * What was attempted, over which transport, and what the mail server or the
 * provider said about it. The table deliberately has no column for a message
 * body and no column for a code: nothing that could be replayed is stored.
 *
 * "Accepted" means the server took the message. Only the provider webhook can
 * move a row to delivered / bounced / complaint, which is why the webhook secret
 * lives on this screen.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/mail.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');

sh_email_schema_ensure();

$errors = [];
$retryResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = (string)($_POST['form'] ?? '');

    if ($form === 'retry_notice') {
        $logId = sh_int($_POST['log_id'] ?? 0);
        $retryResult = sh_email_retry_notification($logId);
        sh_log_line('admin', 'Email retry for log #' . $logId . ' — ' . (!empty($retryResult['ok']) ? 'accepted' : 'failed'));
        sh_flash(!empty($retryResult['ok']) ? 'success' : 'error', !empty($retryResult['ok'])
            ? 'The notification was re-sent.'
            : 'The notification could not be re-sent: ' . (string)($retryResult['error'] ?? 'unknown error'));
        sh_redirect('admin/email-log.php');
    }

    if ($form === 'webhook_secret') {
        if (!empty($_POST['rotate_secret'])) {
            $secret = bin2hex(random_bytes(24));
            sh_setting_save('email_webhook_secret', $secret);
            sh_log_line('admin', 'Email webhook secret rotated');
            sh_flash('success', 'A new webhook secret was generated. Update the provider webhook URL — the old one stops working immediately.');
        } elseif (!empty($_POST['clear_secret'])) {
            sh_setting_save('email_webhook_secret', '');
            sh_log_line('admin', 'Email webhook secret removed');
            sh_flash('warning', 'The webhook secret was removed, so provider events are now refused. Delivery states will stay at “accepted”.');
        }
        sh_redirect('admin/email-log.php');
    }

    if ($form === 'cleanup') {
        $removed = sh_email_challenge_cleanup(24);
        sh_log_line('admin', 'Email challenge cleanup removed ' . $removed . ' row(s)');
        sh_flash('success', 'Finished challenges cleaned up (' . $removed . ' row(s) removed).');
        sh_redirect('admin/email-log.php');
    }
}

$status = (string)sh_get('status');
$rows = sh_email_log_recent(100, $status);
$stats = sh_email_log_stats(7);
$webhookSecret = sh_email_webhook_secret();
$webhookUrl = rtrim(sh_site_url(), '/') . sh_url('api/email-events.php') . ($webhookSecret !== '' ? '?key=' . $webhookSecret : '');
$sendingDomain = '';
$fromEmail = (string)sh_setting('smtp_from_email', '');
if (str_contains($fromEmail, '@')) { $sendingDomain = substr($fromEmail, strrpos($fromEmail, '@') + 1); }

/**
 * Live DNS lookup for the three records, when the host allows DNS queries.
 * A missing record is reported as missing; nothing is guessed or invented.
 *
 * @return array<string,array{state:string,detail:string}>
 */
function sh_email_dns_state(string $domain): array
{
    $out = [
        'spf'   => ['state' => 'unknown', 'detail' => 'No sending domain configured yet.'],
        'dkim'  => ['state' => 'unknown', 'detail' => 'No sending domain configured yet.'],
        'dmarc' => ['state' => 'unknown', 'detail' => 'No sending domain configured yet.'],
    ];
    if ($domain === '' || !function_exists('dns_get_record')) {
        return $out;
    }

    try {
        $txt = dns_get_record($domain, DNS_TXT) ?: [];
        $spf = '';
        foreach ($txt as $rec) {
            $v = (string)($rec['txt'] ?? '');
            if (str_starts_with($v, 'v=spf1')) { $spf .= ($spf === '' ? '' : ' | ') . $v; }
        }
        $out['spf'] = $spf === ''
            ? ['state' => 'missing', 'detail' => 'No SPF record found for ' . $domain . '. The provider issues the exact value to publish (for Brevo: v=spf1 include:spf.brevo.com ~all).']
            : ['state' => 'found', 'detail' => $spf];

        $selector = trim((string)sh_setting('dkim_selector', ''));
        if ($selector !== '') {
            $dkimName = $selector . '._domainkey.' . $domain;
            $dk = dns_get_record($dkimName, DNS_TXT) ?: [];
            $found = '';
            foreach ($dk as $rec) { $found .= (string)($rec['txt'] ?? ''); }
            $out['dkim'] = $found === ''
                ? ['state' => 'missing', 'detail' => 'No TXT record found at ' . $dkimName . '. Publish the record your provider issued.']
                : ['state' => 'found', 'detail' => $dkimName . ' → ' . mb_substr($found, 0, 200)];
        } else {
            $out['dkim'] = ['state' => 'pending', 'detail' => 'Set the DKIM selector above first; the record name depends on it.'];
        }

        $dm = dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
        $dmarc = '';
        foreach ($dm as $rec) {
            $v = (string)($rec['txt'] ?? '');
            if (str_starts_with($v, 'v=DMARC1')) { $dmarc = $v; }
        }
        $out['dmarc'] = $dmarc === ''
            ? ['state' => 'missing', 'detail' => 'No DMARC record found at _dmarc.' . $domain . '. Start with "v=DMARC1; p=none; rua=mailto:postmaster@' . $domain . '" and tighten it after SPF and DKIM align.']
            : ['state' => 'found', 'detail' => $dmarc];
    } catch (Throwable $e) {
        sh_log_exception($e, 'email-dns');
    }
    return $out;
}

$dns = sh_email_dns_state($sendingDomain);

$adminPage = 'email_log';   // matches the System navigation row so it highlights
$adminTitle = 'Email Delivery Log';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-grid2">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Last 7 days</h2>
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/email.php')) ?>"><?= sh_icon('settings', 14) ?> Email settings</a></div>
    <div class="sh-panel__body">
      <div class="sh-tablewrap">
        <table class="sh-table">
          <thead><tr><th>Attempted</th><th>Accepted</th><th>Failed</th><th>Skipped</th><th>Delivered</th><th>Bounced</th><th>Complaints</th></tr></thead>
          <tbody><tr>
            <td><?= (int)$stats['total'] ?></td>
            <td><?= (int)$stats['accepted'] ?></td>
            <td><?= (int)$stats['failed'] ?></td>
            <td><?= (int)$stats['skipped'] ?></td>
            <td><?= (int)$stats['delivered'] ?></td>
            <td><?= (int)$stats['bounced'] ?></td>
            <td><?= (int)$stats['complaint'] ?></td>
          </tr></tbody>
        </table>
      </div>
      <p class="sh-panel__note" style="margin-top:12px">
        Accepted only means the mail server took the message. Inbox placement, bounces and complaints arrive
        through the provider webhook below; a submission alone can never prove delivery.
      </p>
      <form method="post" style="margin-top:10px">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="cleanup">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Clean up finished verification codes</button>
      </form>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('link', 17) ?> Provider webhook</h2></div>
    <div class="sh-panel__body">
      <?php if ($webhookSecret === ''): ?>
        <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?>
          <span>No webhook secret is configured, so provider events are refused. Delivery states stay at “accepted”.</span></div>
      <?php else: ?>
        <p class="sh-panel__note" style="margin-bottom:10px">
          Paste this URL into your provider's webhook settings (delivered, bounce, blocked, spam). The secret
          is compared in constant time; an event whose signature does not match is discarded before anything is stored.
        </p>
        <input class="sh-input" type="text" readonly data-select-on-focus value="<?= e($webhookUrl) ?>"
               style="font-size:12px;font-family:Menlo,Consolas,monospace">
        <p class="sh-field__hint">Signed events may also send <code>X-Shophaat-Signature: sha256=…</code>, an HMAC of the raw body with this same secret.</p>
      <?php endif; ?>
      <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="webhook_secret">
        <button class="sh-btn sh-btn--sm" type="submit" name="rotate_secret" value="1"><?= sh_icon('refresh', 14) ?> <?= $webhookSecret === '' ? 'Generate' : 'Rotate' ?> secret</button>
        <?php if ($webhookSecret !== ''): ?>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" name="clear_secret" value="1">Remove</button>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> SPF, DKIM and DMARC</h2></div>
  <div class="sh-panel__body">
    <?php if ($sendingDomain === ''): ?>
      <p class="sh-panel__note">Set the From address in the email settings first — the records belong to that domain.</p>
    <?php elseif (!function_exists('dns_get_record')): ?>
      <p class="sh-panel__note">
        This host does not allow DNS lookups from PHP, so the records cannot be checked here. Sending domain:
        <strong><?= e($sendingDomain) ?></strong>.
      </p>
    <?php else: ?>
      <div class="sh-tablewrap">
        <table class="sh-table">
          <thead><tr><th>Record</th><th>Hostname</th><th>Status</th><th>What is published / what to publish</th></tr></thead>
          <tbody>
            <tr>
              <td><strong>SPF</strong></td>
              <td class="sh-table__meta"><?= e($sendingDomain) ?> (TXT)</td>
              <td><span class="sh-badge <?= $dns['spf']['state'] === 'found' ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= e($dns['spf']['state']) ?></span></td>
              <td class="sh-table__meta"><?= e($dns['spf']['detail']) ?></td>
            </tr>
            <tr>
              <td><strong>DKIM</strong></td>
              <td class="sh-table__meta"><?= e((string)sh_setting('dkim_selector', '（selector not set）')) ?>._domainkey.<?= e($sendingDomain) ?></td>
              <td><span class="sh-badge <?= $dns['dkim']['state'] === 'found' ? 'sh-badge--ok' : ($dns['dkim']['state'] === 'pending' ? '' : 'sh-badge--bad') ?>"><?= e($dns['dkim']['state']) ?></span></td>
              <td class="sh-table__meta"><?= e($dns['dkim']['detail']) ?></td>
            </tr>
            <tr>
              <td><strong>DMARC</strong></td>
              <td class="sh-table__meta">_dmarc.<?= e($sendingDomain) ?> (TXT)</td>
              <td><span class="sh-badge <?= $dns['dmarc']['state'] === 'found' ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= e($dns['dmarc']['state']) ?></span></td>
              <td class="sh-table__meta"><?= e($dns['dmarc']['detail']) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="sh-panel__note" style="margin-top:12px">
        PHP cannot publish DNS records — these have to be created at the DNS provider for
        <strong><?= e($sendingDomain) ?></strong>. Only one SPF record may exist for a domain: if one is already
        published, add the provider's <code>include:</code> to it instead of creating a second. Keep DMARC at
        <code>p=none</code> until SPF and DKIM both align, then move to <code>quarantine</code> and finally
        <code>reject</code> once the reports show no legitimate mail failing.
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('mail', 17) ?> Delivery log</h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach (['' => 'All', 'accepted' => 'Accepted', 'failed' => 'Failed', 'skipped' => 'Skipped', 'bounced' => 'Bounced', 'complaint' => 'Complaint'] as $k => $label): ?>
        <a class="sh-btn sh-btn--sm <?= $status === $k ? '' : 'sh-btn--ghost' ?>" href="<?= e(sh_url('admin/email-log.php' . ($k === '' ? '' : '?status=' . $k))) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>When</th><th>Recipient</th><th>Purpose</th><th>Subject</th><th>Transport</th><th>Status</th><th>Detail</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="8">Nothing has been attempted yet.</td></tr>
      <?php else: foreach ($rows as $row):
        $st = (string)$row['status'];
        $badge = in_array($st, ['delivered', 'accepted'], true) ? 'sh-badge--ok' : (in_array($st, ['failed', 'bounced', 'complaint', 'blocked'], true) ? 'sh-badge--bad' : '');
        $retryable = in_array((string)$row['purpose'], ['password_changed', 'security_notice'], true) && $st === 'failed';
        ?>
        <tr>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime((string)$row['created_at']))) ?></td>
          <td class="sh-table__meta"><?= e((string)$row['recipient']) ?></td>
          <td class="sh-table__meta"><?= e(str_replace('_', ' ', (string)$row['purpose'])) ?></td>
          <td class="sh-table__meta" style="max-width:220px"><?= e((string)$row['subject']) ?></td>
          <td class="sh-table__meta"><?= e((string)$row['transport']) ?></td>
          <td><span class="sh-badge <?= $badge ?>"><?= e($st) ?></span>
            <?php if (!empty($row['category']) && $row['category'] !== 'accepted' && $row['category'] !== $st): ?>
              <span class="sh-table__meta"><?= e((string)$row['category']) ?></span>
            <?php endif; ?></td>
          <td class="sh-table__meta" style="max-width:320px"><?= e((string)($row['error_text'] ?? '')) ?></td>
          <td>
            <?php if ($retryable): ?>
              <form method="post">
                <?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="retry_notice">
                <input type="hidden" name="log_id" value="<?= (int)$row['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('refresh', 13) ?> Re-send</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">
      Verification and reset codes are never stored in this log and cannot be re-sent from it: a new code has to be
      requested, which is exactly what the customer-facing “send a new code” button does. Only password-change and
      security notices — whose text is derived from the account — can be re-sent here.
    </p>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
