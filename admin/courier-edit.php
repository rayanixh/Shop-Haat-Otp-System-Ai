<?php
/**
 * Admin — create or edit one courier.
 *
 * admin/couriers.php keeps the two things that genuinely belong beside the
 * records: the per-provider card (enable toggle, credentials, tracking template
 * and the connection test) and the custom-courier list with its toggle/delete
 * actions. The full record — name, machine code, driver, tracking template,
 * description, logo, sort order and credentials — is created and edited here, so
 * a long form is never rendered underneath the listing.
 *
 *   admin/courier-edit.php          → add
 *   admin/courier-edit.php?edit=4   → edit courier 4
 */
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

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/couriers.php') . ($keepQs !== '' ? '?' . $keepQs : '');

$form = '';

$courierAction = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $courierAction = sh_post('courier_action');
    // "Test connection" saves this form first, then tests the saved record.
    if ($form !== 'save' && $courierAction !== 'test') { sh_redirect($listUrl); }
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
        // Extension, real MIME, size and dimensions are all validated inside
        // sh_upload_image(); the stored logo is replaced only when the new file
        // is confirmed on disk, so a rejected upload never loses the old one.
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
            'credentials'  => sh_courier_credentials_store($creds),
            'sort_order'   => sh_int($_POST['sort_order'] ?? 0),
            'status'       => !empty($_POST['status']) ? 1 : 0,
        ];
        try {
            if ($id > 0) { sh_update('couriers', $data, 'id = ?', [$id]); sh_flash('success', 'Courier updated.'); }
            else { sh_insert('couriers', $data); sh_flash('success', 'Courier created.'); }
            sh_log_line('admin', 'Courier "' . $data['name'] . '" saved by ' . $admin['email']);
        } catch (Throwable $e) {
            sh_log_exception($e, 'courier-save');
            $errors['general'] = 'The courier could not be saved.';
        }
        if (!$errors) {
            if ($courierAction === 'test' && $id > 0) {
                $saved = sh_courier_by_id($id);
                $r = $saved !== null ? sh_courier_test($saved, (int)$admin['id']) : null;
                if ($r === null) {
                    sh_flash('error', 'The courier was saved but could not be reloaded for the test.');
                } else {
                    $type = $r['state'] === 'connected' ? 'success' : (in_array($r['state'], ['unsupported'], true) ? 'info' : 'error');
                    sh_flash($type, $r['state_label'] . ': ' . $r['message'] . ($r['next'] !== '' ? ' ' . $r['next'] : ''));
                }
                sh_redirect('admin/courier-edit.php?edit=' . $id);
            }
            sh_redirect($listUrl);
        }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_courier_by_id($editId) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That courier no longer exists.');
    sh_redirect($listUrl);
}
$isNew      = $editing === null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$editDriver = $val('driver', 'manual');
$editCreds  = $editing !== null ? sh_courier_credentials($editing) : [];
$showCreds  = array_merge(sh_courier_fields($editDriver), sh_courier_optional_fields($editDriver));
$driverMap  = sh_courier_drivers();
$apiDrivers = sh_courier_api_drivers();
$providers  = sh_courier_providers();
$isProvider = $editing !== null && isset($providers[(string)$editing['code']]);
$curLogo    = sh_logo_image($val('logo'));
$statusOn   = $errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 0) === 1;

$adminPage = 'couriers';
$adminTitle = $isNew ? 'Add courier' : 'Edit courier';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Couriers' => $listUrl,
    ($isNew ? 'Add courier' : (string)$editing['name']) => '',
]) ?>

<?= sh_admin_error_summary($errors) ?>

<?php if ($errors): ?>
  <div class="sh-panel" style="margin-bottom:14px"><div class="sh-panel__body">
    <p class="sh-formcard__note">Nothing was saved, so the values below are the ones you submitted. Fix the marked fields and save again.</p>
  </div></div>
<?php elseif (!$isNew): ?>
  <div class="sh-stats" style="margin-bottom:14px">
    <div class="sh-stat"><span class="sh-stat__label">Machine code</span>
      <span class="sh-stat__value" style="font-size:14px"><?= e((string)$editing['code']) ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Driver</span>
      <span class="sh-stat__value" style="font-size:14px"><?= e($driverMap[(string)$editing['driver']] ?? (string)$editing['driver']) ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Credentials</span>
      <span class="sh-stat__value" style="font-size:14px"><?= $editCreds ? 'Stored' : 'None' ?></span></div>
    <div class="sh-stat"><span class="sh-stat__label">Availability</span>
      <span class="sh-stat__value" style="font-size:14px"><?= (int)$editing['status'] === 1 ? 'Enabled' : 'Disabled' ?></span></div>
  </div>
<?php endif; ?>

<?php if (!$isNew): ?>
  <div class="sh-ctest--page"><?= sh_courier_test_summary($editing) ?></div>
<?php endif; ?>

<?php if ($isProvider): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('shield', 17) ?>
    <div><strong><?= e((string)$editing['name']) ?></strong> is a built-in provider. Its enable toggle, credentials, tracking template and
      connection test stay on its <a href="<?= e($listUrl) ?>#courier-<?= (int)$editing['id'] ?>">courier card</a>, it cannot be deleted
      from here, and the fields below only need changing for unusual setups.</div></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate id="sh-courier-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
  <input type="hidden" name="current_logo" value="<?= e($val('logo')) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__body">

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('tag', 15) ?> Name, code and driver</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Courier name',
              '<input class="sh-input" id="cr-name" name="name" required maxlength="100" value="' . e($val('name')) . '">',
              '', $errors['name'] ?? null, 'cr-name', true) ?>
          <?= sh_admin_field('Machine code',
              '<input class="sh-input" id="cr-code" name="code" maxlength="40" value="' . e($val('code')) . '" placeholder="auto">',
              'Lowercase letters, numbers and underscores. Generated from the name when left blank.', $errors['code'] ?? null, 'cr-code') ?>
        </div>
        <div class="sh-grid2">
          <?= sh_admin_field('Driver',
              '<select class="sh-select" id="cr-driver" name="driver">'
                  . implode('', array_map(
                      static fn(string $k, string $label): string => '<option value="' . e($k) . '"' . ($editDriver === $k ? ' selected' : '') . '>'
                          . e($label) . (in_array($k, sh_courier_api_drivers(), true) ? ' — API ready' : '') . '</option>',
                      array_keys($driverMap),
                      array_values($driverMap)
                  ))
                  . '</select>',
              'The driver decides which credential fields this courier has and how tracking behaves.',
              $errors['driver'] ?? null, 'cr-driver') ?>
          <?= sh_admin_field('Sort order',
              '<input class="sh-input" id="cr-sort" name="sort_order" type="number" value="' . e($val('sort_order', '0')) . '">',
              'Lower numbers are listed first when creating a parcel.', null, 'cr-sort') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('package', 15) ?> Tracking</h3>
        <?= sh_admin_field('Tracking URL template',
            '<input class="sh-input sh-input--mono" id="cr-track" name="tracking_url" maxlength="255" value="' . e($val('tracking_url')) . '" placeholder="https://example.com/track/{tracking}">',
            'Use <code>{tracking}</code> where the consignment number goes. Leave blank to track manually.',
            $errors['tracking_url'] ?? null, 'cr-track') ?>
        <?= sh_admin_field('Description',
            '<input class="sh-input" id="cr-desc" name="description" maxlength="190" value="' . e($val('description')) . '">',
            'Optional note shown to staff when choosing a courier.', null, 'cr-desc') ?>
      </div>

      <?php if ($showCreds): ?>
        <div class="sh-formsec">
          <h3 class="sh-formsec__title"><?= sh_icon('shield', 15) ?> Merchant credentials</h3>
          <p class="sh-formcard__note" style="margin:-4px 0 12px">
            A saved value is never shown again. Leave a field blank to keep what is stored.
          </p>
          <?php foreach ($showCreds as $field => $label): $has = trim((string)($editCreds[$field] ?? '')) !== ''; $fid = 'cr-' . $field; ?>
            <?= sh_admin_field($label,
                '<div class="sh-secret"><input class="sh-input" id="' . e($fid) . '" type="password" name="cred_' . e($field) . '"'
                . ' autocomplete="new-password" spellcheck="false" placeholder="' . ($has ? 'Saved — leave blank to keep' : 'Issued by the courier') . '">'
                . '<button class="sh-secret__btn" type="button" data-reveal="' . e($fid) . '" aria-label="Show or hide">' . sh_icon('eye', 15) . '</button></div>',
                ($has ? 'A value is stored for this field.' : '')
                    . ($field === 'base_url' ? ' ' . e(sh_courier_base_url_hint($editDriver)) : ''),
                $errors['cred_' . $field] ?? null) ?>
          <?php endforeach; ?>
          <?php if ($editCreds): ?>
            <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_credentials" value="1">
              <span>Remove all stored credentials for this courier</span></label>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('image', 15) ?> Logo and availability</h3>
        <?= sh_admin_field('Logo',
            '<input class="sh-input" id="cr-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">',
            'Optional. PNG, JPG, WebP or GIF, maximum 2 MB, shown beside the courier when choosing one.', $errors['logo'] ?? null, 'cr-logo') ?>
        <?php if ($curLogo !== ''): ?>
          <p style="margin:-4px 0 12px;display:flex;align-items:center;gap:9px">
            <img src="<?= e($curLogo) ?>" alt="Current logo" style="height:34px;object-fit:contain">
            <span class="sh-field__hint">Current logo — upload a file to replace it.</span>
          </p>
        <?php elseif ($val('logo') !== ''): ?>
          <p class="sh-field__hint" style="margin:-4px 0 12px;color:#c62828">
            The saved logo file (<?= e($val('logo')) ?>) is missing from the server. Upload it again to replace it.
          </p>
        <?php endif; ?>
        <label class="sh-check" style="margin-bottom:4px">
          <input type="checkbox" name="status" value="1" <?= $statusOn ? 'checked' : '' ?>>
          <span>Available when creating parcels</span></label>
      </div>

    </div>
    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Create courier' : 'Save changes' ?></button>
      <?php if (!$isNew): ?>
        <button class="sh-btn sh-btn--ghost" type="submit" name="courier_action" value="test" data-no-lock title="Saves these settings, then sends one read-only request. No parcel is created."><?= sh_icon('zap', 15) ?> Test connection</button>
      <?php endif; ?>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      <?php if (!$isNew && !$isProvider): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-courier-delete"
                data-confirm="Delete &quot;<?= e((string)$editing['name']) ?>&quot;? Parcels that already use it keep their history.">
          <?= sh_icon('trash', 15) ?> Delete courier</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$isNew && !$isProvider): ?>
  <form method="post" action="<?= e(sh_url('admin/couriers.php')) ?>" id="sh-courier-delete">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
