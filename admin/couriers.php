<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/courier.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');
require_once SH_ROOT . '/includes/admin-tools.php';
sh_courier_schema_ensure();
sh_courier_providers_ensure();

$newUrl = sh_url('admin/courier-edit.php?new=1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'save') {
        // The full courier form lives on its own screen now. A save posted to
        // this listing (an open tab from before the change) is forwarded with
        // its id, never silently dropped. Nothing from the body is stored.
        sh_flash('info', 'The full courier form has moved to its own page.');
        $id = sh_int($_POST['id'] ?? 0);
        sh_redirect('admin/courier-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }

    if ($form === 'toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        $c = sh_courier_by_id($id);
        if ($c === null) {
            sh_flash('error', 'That courier does not exist.');
        } else {
            sh_query('UPDATE couriers SET status = 1 - status WHERE id = ?', [$id]);
            sh_flash('success', 'Courier updated.');
        }
        sh_redirect('admin/couriers.php');
    }

    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        if ((int)sh_val('SELECT COUNT(*) FROM shipments WHERE courier_id = ?', [$id], 0) > 0) {
            sh_query('UPDATE couriers SET status = 0 WHERE id = ?', [$id]);
            sh_flash('info', 'This courier is used by existing parcels, so it was disabled instead of deleted.');
        } else {
            sh_query('DELETE FROM couriers WHERE id = ?', [$id]);
            sh_flash('success', 'Courier deleted.');
        }
        sh_redirect('admin/couriers.php');
    }

    // Per-provider card: enable toggle + credentials + tracking template only.
    if ($form === 'card') {
        $id = sh_int($_POST['id'] ?? 0);
        $c = sh_courier_by_id($id);
        if ($c === null) { sh_flash('error', 'That courier does not exist.'); sh_redirect('admin/couriers.php'); }
        $creds = sh_courier_credentials($c);
        foreach (array_merge(sh_courier_fields($c['driver']), sh_courier_optional_fields($c['driver'])) as $field => $label) {
            $posted = trim((string)($_POST['cred_' . $field] ?? ''));
            if ($posted !== '') { $creds[$field] = $posted; }
        }
        if (!empty($_POST['clear_credentials'])) { $creds = []; }
        $tracking = sh_post('tracking_url');
        if ($tracking !== '' && !preg_match('~^https?://~i', $tracking)) {
            sh_flash('error', 'The tracking URL must start with http:// or https://.');
            sh_redirect('admin/couriers.php#courier-' . $id);
        }
        $newStatus = !empty($_POST['status']) ? 1 : 0;
        sh_update('couriers', [
            'status'       => $newStatus,
            'tracking_url' => $tracking !== '' ? $tracking : null,
            'credentials'  => $creds ? json_encode($creds, JSON_UNESCAPED_SLASHES) : null,
        ], 'id = ?', [$id]);
        sh_log_line('admin', 'Courier "' . $c['name'] . '" updated by ' . $admin['email']);
        $oldCreds = sh_courier_credentials($c);
        $credChanged = $oldCreds !== $creds;
        $oldA = ['enabled' => (int)$c['status'], 'tracking_url' => (string)($c['tracking_url'] ?? ''), 'credentials' => $oldCreds ? '•••• (hidden)' : '(empty)'];
        $newA = ['enabled' => $newStatus, 'tracking_url' => $tracking, 'credentials' => $creds ? ($credChanged ? '•••• (changed)' : '•••• (hidden)') : '(empty)'];
        if ($oldA !== $newA) { sh_audit('settings_changed', 'settings', $id, 'courier_' . $c['code'], $oldA, $newA); }
        sh_flash('success', $c['name'] . ' saved.');
        sh_redirect('admin/couriers.php#courier-' . $id);
    }

    if ($form === 'test') {
        $id = sh_int($_POST['id'] ?? 0);
        $c = sh_courier_by_id($id);
        if ($c === null) { sh_flash('error', 'That courier does not exist.'); }
        else {
            $r = sh_courier_test($c);
            sh_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? $r['message'] : $r['error']);
        }
        sh_redirect('admin/couriers.php#courier-' . $id);
    }
}

// An old bookmark to ?edit= / ?new= opens the dedicated screen instead of
// silently showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit', 'new']);
    if ($forwardId > 0) { sh_redirect('admin/courier-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
    if (isset($_GET['new'])) { sh_redirect('admin/courier-edit.php?new=1' . ($keep !== '' ? '&' . $keep : '')); }
}

$rows = sh_couriers();

$adminPage = 'couriers';
$adminTitle = 'Couriers';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Couriers',
    'Built-in providers keep their credentials, tracking template and connection test on their own card. Custom couriers you fulfil yourself are created and edited on a dedicated screen.',
    sh_admin_add_button('Add a courier', $newUrl, '', 'sh-btn sh-btn--sm', 'integrations.manage'),
    'truck'
) ?>

<?php
$providers = sh_courier_providers();
$byCode = [];
foreach ($rows as $c) { $byCode[$c['code']] = $c; }
$providerRows = [];
foreach ($providers as $code => $p) { if (isset($byCode[$code])) { $providerRows[] = $byCode[$code]; } }
$otherRows = array_values(array_filter($rows, static fn($c) => !isset($providers[$c['code']])));
?>

<div class="sh-cards sh-cards--3">
  <?php foreach ($providerRows as $c):
    $prov   = $providers[$c['code']];
    $creds  = sh_courier_credentials($c);
    $fields = array_merge(sh_courier_fields($c['driver']), sh_courier_optional_fields($c['driver']));
    $apiReady = in_array($c['driver'], sh_courier_api_drivers(), true);
    $configured = sh_courier_is_configured($c);
    $logo = sh_courier_logo_url($c);
    $on = (int)$c['status'] === 1;
  ?>
  <section class="sh-panel sh-courier" id="courier-<?= (int)$c['id'] ?>">
    <div class="sh-courier__head">
      <div class="sh-courier__logo">
        <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($c['name']) ?> logo" loading="lazy">
        <?php else: ?><?= sh_icon('truck', 22) ?><?php endif; ?>
      </div>
      <div class="sh-courier__meta">
        <h2 class="sh-courier__name"><?= e($c['name']) ?></h2>
        <div class="sh-courier__tags">
          <span class="sh-statuspill <?= $on ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $on ? 'Enabled' : 'Disabled' ?></span>
          <?php if ($apiReady): ?>
            <span class="sh-badge <?= $configured ? 'sh-badge--ok' : 'sh-badge--warn' ?>"><?= $configured ? 'API connected' : 'API — credentials needed' ?></span>
          <?php elseif (in_array($c['driver'], ['manual', 'custom'], true)): ?>
            <span class="sh-badge sh-badge--muted">Manual tracking</span>
          <?php else: ?>
            <span class="sh-badge sh-badge--muted"><?= $configured ? 'Credentials saved' : 'Manual tracking' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <form method="post" novalidate autocomplete="off" class="sh-courier__form">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="card">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

      <label class="sh-toggle">
        <input type="checkbox" name="status" value="1" <?= $on ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span>Available when creating parcels</span>
      </label>

      <?php if ($fields): ?>
        <p class="sh-courier__section">Merchant credentials</p>
        <?php foreach ($fields as $field => $label): $has = trim((string)($creds[$field] ?? '')) !== ''; $fid = 'cr-' . (int)$c['id'] . '-' . $field; ?>
          <div class="sh-field">
            <label class="sh-field__label" for="<?= e($fid) ?>"><?= e($label) ?></label>
            <div class="sh-secret">
              <input class="sh-input" id="<?= e($fid) ?>" type="password" name="cred_<?= e($field) ?>" autocomplete="new-password" spellcheck="false"
                     placeholder="<?= $has ? 'Saved — leave blank to keep' : 'Issued by ' . e($c['name']) ?>">
              <button class="sh-secret__btn" type="button" data-reveal="<?= e($fid) ?>" aria-label="Show or hide"><?= sh_icon('eye', 15) ?></button>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($creds): ?>
          <label class="sh-check"><input type="checkbox" name="clear_credentials" value="1"><span>Remove all stored credentials</span></label>
        <?php endif; ?>
      <?php endif; ?>

      <div class="sh-field" style="margin-top:10px">
        <label class="sh-field__label" for="tr-<?= (int)$c['id'] ?>">Tracking URL template</label>
        <input class="sh-input sh-input--mono" id="tr-<?= (int)$c['id'] ?>" name="tracking_url" maxlength="255" value="<?= e((string)$c['tracking_url']) ?>" placeholder="https://…/{tracking}">
        <span class="sh-field__hint">Use <code>{tracking}</code> where the consignment number goes.</span>
      </div>

      <div class="sh-actions">
        <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 14) ?> Save</button>
        <?php if ($apiReady): ?>
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" name="form" value="test" formnovalidate><?= sh_icon('shield', 14) ?> Test connection</button>
        <?php endif; ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e($prov['site']) ?>" target="_blank" rel="noopener"><?= sh_icon('external', 14) ?> Merchant portal</a>
        <a class="sh-courier__edit" href="<?= e(sh_url('admin/courier-edit.php?edit=' . (int)$c['id'])) ?>"><?= sh_icon('pencil', 13) ?> Advanced</a>
      </div>
    </form>
  </section>
  <?php endforeach; ?>
</div>

<section class="sh-panel" style="margin-top:14px" id="custom-couriers">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Custom couriers</h2>
    <div class="sh-panel__actions">
      <span class="sh-formcard__note"><?= (int)count($otherRows) ?> custom courier<?= count($otherRows) === 1 ? '' : 's' ?></span>
    </div>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Courier</th><th>Driver</th><th>Status</th><th>Tracking</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$otherRows): ?>
        <tr class="sh-table--empty"><td colspan="5">
          No custom couriers yet. <?= sh_admin_add_button('Add the first courier', $newUrl, '', 'sh-btn sh-btn--sm sh-btn--ghost', 'integrations.manage') ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($otherRows as $c): $cLogo = sh_courier_logo_url($c); ?>
        <tr>
          <td data-label="Courier"><div class="sh-table__cell">
            <?php if ($cLogo !== ''): ?><img class="sh-table__thumb" style="object-fit:contain;background:#fff" src="<?= e($cLogo) ?>" alt="" loading="lazy">
            <?php else: ?><span class="sh-table__thumb" style="display:grid;place-items:center;color:var(--sh-muted)"><?= sh_icon('truck', 17) ?></span><?php endif; ?>
            <div><div class="sh-table__name"><?= e($c['name']) ?></div><div class="sh-table__meta"><?= e($c['code']) ?></div></div></div></td>
          <td data-label="Driver"><?= e(sh_courier_drivers()[$c['driver']] ?? $c['driver']) ?></td>
          <td data-label="Status"><span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= (int)$c['status'] === 1 ? 'Enabled' : 'Disabled' ?></span></td>
          <td data-label="Tracking">
            <?php if (trim((string)($c['tracking_url'] ?? '')) !== ''): ?>
              <span class="sh-table__meta" style="white-space:normal"><?= sh_icon('external', 12) ?> <?= e((string)$c['tracking_url']) ?></span>
            <?php else: ?>
              <span class="sh-table__meta">Manual</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions"><div class="sh-table__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/courier-edit.php?edit=' . (int)$c['id'])) ?>" title="Edit <?= e($c['name']) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
            <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Enable / disable" aria-label="Enable or disable <?= e($c['name']) ?>"><?= sh_icon('eye', 13) ?></button></form>
            <form method="post" data-confirm="Delete <?= e($c['name']) ?>? Parcels that already use it keep their history."><?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" aria-label="Delete <?= e($c['name']) ?>"><?= sh_icon('trash', 13) ?></button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require __DIR__ . '/_footer.php'; ?>
