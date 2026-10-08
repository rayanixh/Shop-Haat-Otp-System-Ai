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
sh_courier_schema_ensure();
sh_courier_providers_ensure();

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

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
        require_once SH_ROOT . '/includes/admin-tools.php';
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

    if ($form === 'save') {
        $id = sh_int($_POST['id'] ?? 0);
        $existing = $id > 0 ? sh_courier_by_id($id) : null;
        $drivers = sh_courier_drivers();
        $driver = sh_post('driver');
        if (!isset($drivers[$driver])) { $driver = 'manual'; }

        $v = new ShValidator($_POST);
        $v->required('name', 'Courier name')->maxLen('name', 100, 'Courier name');
        $errors = $v->errors();

        $code = strtolower(preg_replace('/[^a-z0-9_]/i', '', sh_post('code')) ?? '');
        if ($code === '') { $code = str_replace('-', '_', sh_slug(sh_post('name'))); }
        if ($code === '') { $errors['code'] = 'Enter a short machine code.'; }
        if (sh_one('SELECT id FROM couriers WHERE code = ? AND id <> ? LIMIT 1', [$code, $id])) {
            $errors['code'] = 'That courier code is already in use.';
        }

        // Merge credentials: a blank field keeps the stored secret.
        $creds = $existing !== null ? sh_courier_credentials($existing) : [];
        foreach (array_merge(sh_courier_fields($driver), sh_courier_optional_fields($driver)) as $field => $label) {
            $posted = trim((string)($_POST['cred_' . $field] ?? ''));
            if ($posted !== '') { $creds[$field] = $posted; }
            if (!empty($_POST['clear_credentials'])) { unset($creds[$field]); }
        }

        $logo = sh_post('current_logo');
        if (!empty($_FILES['logo']['name'])) {
            $up = sh_upload_image($_FILES['logo'], 'logos', 0, 2000);
            if (!empty($up['ok'])) { $logo = $up['file']; }
            else { $errors['logo'] = $up['error'] ?? 'The logo could not be uploaded.'; }
        }

        if (!$errors) {
            $data = [
                'code'         => $code,
                'name'         => sh_post('name'),
                'driver'       => $driver,
                'logo'         => $logo ?: null,
                'tracking_url' => sh_post('tracking_url') ?: null,
                'description'  => sh_post('description') ?: null,
                'credentials'  => $creds ? json_encode($creds, JSON_UNESCAPED_SLASHES) : null,
                'sort_order'   => sh_int($_POST['sort_order'] ?? 0),
                'status'       => !empty($_POST['status']) ? 1 : 0,
            ];
            try {
                if ($id > 0) { sh_update('couriers', $data, 'id = ?', [$id]); sh_flash('success', 'Courier updated.'); }
                else { sh_insert('couriers', $data); sh_flash('success', 'Courier created.'); }
                sh_log_line('admin', 'Courier "' . $data['name'] . '" saved by ' . $admin['email']);
                sh_redirect('admin/couriers.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'courier-save');
                $errors['general'] = 'The courier could not be saved.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_courier_by_id($editId) : null;
if ($editing === null && isset($_GET['new'])) { $errors = $errors ?: []; $showNew = true; }
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$editDriver = $val('driver', 'manual');
$editCreds = $editing !== null ? sh_courier_credentials($editing) : [];
$rows = sh_couriers();

$adminPage = 'couriers';
$adminTitle = 'Couriers';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

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
        <a class="sh-courier__edit" href="<?= e(sh_url('admin/couriers.php?edit=' . (int)$c['id'])) ?>#edit"><?= sh_icon('pencil', 13) ?> Advanced</a>
      </div>
    </form>
  </section>
  <?php endforeach; ?>
</div>

<?php $showNew = $showNew ?? false; if ($otherRows || $editing !== null || $errors || $showNew): ?>
<div class="sh-cards" style="margin-top:14px" id="edit">
  <?php if ($otherRows): ?>
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Custom couriers</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Courier</th><th>Driver</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($otherRows as $c): ?>
          <tr>
            <td><div class="sh-table__cell">
              <?php $cLogo = sh_courier_logo_url($c); ?>
              <?php if ($cLogo !== ''): ?><img class="sh-table__thumb" style="object-fit:contain;background:#fff" src="<?= e($cLogo) ?>" alt="" loading="lazy">
              <?php else: ?><span class="sh-table__thumb" style="display:grid;place-items:center;color:var(--sh-muted)"><?= sh_icon('truck', 17) ?></span><?php endif; ?>
              <div><div class="sh-table__name"><?= e($c['name']) ?></div><div class="sh-table__meta"><?= e($c['code']) ?></div></div></div></td>
            <td><?= e(sh_courier_drivers()[$c['driver']] ?? $c['driver']) ?></td>
            <td><span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= (int)$c['status'] === 1 ? 'Enabled' : 'Disabled' ?></span></td>
            <td><div class="sh-table__actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/couriers.php?edit=' . (int)$c['id'])) ?>#edit"><?= sh_icon('pencil', 13) ?></a>
              <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Enable / disable"><?= sh_icon('eye', 13) ?></button></form>
              <form method="post" data-confirm="Delete <?= e($c['name']) ?>?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($editing !== null || $errors || $showNew): ?>
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?> <?= $editing ? 'Edit courier — ' . e($editing['name']) : 'New courier' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="current_logo" value="<?= e($val('logo')) ?>">
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="cr-name">Courier name <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="cr-name" name="name" required maxlength="100" value="<?= e($val('name')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="cr-code">Machine code</label>
            <input class="sh-input" id="cr-code" name="code" maxlength="40" value="<?= e($val('code')) ?>" placeholder="auto"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="cr-driver">Driver</label>
            <select class="sh-select" id="cr-driver" name="driver">
              <?php foreach (sh_courier_drivers() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $editDriver === $k ? 'selected' : '' ?>><?= e($label) ?><?= in_array($k, sh_courier_api_drivers(), true) ? ' — API ready' : '' ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="sh-field"><label class="sh-field__label" for="cr-sort">Sort order</label>
            <input class="sh-input" id="cr-sort" name="sort_order" type="number" value="<?= e($val('sort_order', '0')) ?>"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="cr-track">Tracking URL template</label>
          <input class="sh-input sh-input--mono" id="cr-track" name="tracking_url" maxlength="255" value="<?= e($val('tracking_url')) ?>" placeholder="https://example.com/track/{tracking}"></div>
        <?php $showCreds = array_merge(sh_courier_fields($editDriver), sh_courier_optional_fields($editDriver)); ?>
        <?php if ($showCreds): ?>
          <p class="sh-courier__section">Merchant credentials</p>
          <?php foreach ($showCreds as $field => $label): $has = trim((string)($editCreds[$field] ?? '')) !== ''; ?>
            <div class="sh-field"><label class="sh-field__label" for="cr-<?= e($field) ?>"><?= e($label) ?></label>
              <div class="sh-secret">
                <input class="sh-input" id="cr-<?= e($field) ?>" type="password" name="cred_<?= e($field) ?>" autocomplete="new-password" placeholder="<?= $has ? 'Saved — leave blank to keep' : 'Issued by the courier' ?>">
                <button class="sh-secret__btn" type="button" data-reveal="cr-<?= e($field) ?>" aria-label="Show or hide"><?= sh_icon('eye', 15) ?></button>
              </div></div>
          <?php endforeach; ?>
          <?php if ($editCreds): ?><label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_credentials" value="1"><span>Remove all stored credentials</span></label><?php endif; ?>
        <?php endif; ?>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="cr-desc">Description</label>
            <input class="sh-input" id="cr-desc" name="description" maxlength="190" value="<?= e($val('description')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="cr-logo">Logo (custom couriers)</label>
            <input class="sh-input" id="cr-logo" type="file" name="logo" accept="image/*"></div>
        </div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 0) === 1) ? 'checked' : '' ?>>
          <span>Available when creating parcels</span></label>
        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save courier' : 'Create courier' ?></button>
          <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/couriers.php')) ?>">Cancel</a>
        </div>
      </form>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($editing === null && !$errors && !$showNew): ?>
  <div class="sh-actions" style="margin-top:14px">
    <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/couriers.php?edit=0&new=1')) ?>#edit"><?= sh_icon('plus', 15) ?> Add a custom courier</a>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
