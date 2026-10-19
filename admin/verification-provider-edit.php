<?php
/**
 * Admin — create or edit one courier verification provider.
 *
 * admin/verification.php keeps the IP/proxy settings, the provider list with its
 * test, enable/disable and remove actions; the provider record itself — API
 * shape, authentication, response mapping and timeouts — is edited here, so the
 * largest form in the integrations area is not rendered under the listing.
 *
 *   admin/verification-provider-edit.php                    → add (count-field preset)
 *   admin/verification-provider-edit.php?new=1&preset=list  → add (parcel-list preset)
 *   admin/verification-provider-edit.php?edit=4             → edit provider 4
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/verification.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');
require_once SH_ROOT . '/includes/admin-tools.php';
sh_verify_schema_ensure();

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$editing = $editId > 0 ? sh_verify_provider($editId) : null;

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id', 'preset']);
$listUrl = sh_url('admin/verification.php') . ($keepQs !== '' ? '?' . $keepQs : '');

// The preset only seeds a blank form; an existing provider always wins.
$presets = sh_verify_provider_presets();
$presetKey = sh_get('preset');
if (!isset($presets[$presetKey])) { $presetKey = 'generic'; }

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF first: nothing below runs without a valid token.
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'provider_save') { sh_redirect($listUrl); }

    if ($form === 'provider_save') {
        $id = sh_int($_POST['id'] ?? 0);
        $cur = $id > 0 ? sh_verify_provider($id) : null;
        $d = [
            'name'            => mb_substr(trim(sh_post('name')), 0, 80),
            'enabled'         => !empty($_POST['enabled']) ? 1 : 0,
            'base_url'        => trim(sh_post('base_url')),
            'endpoint'        => trim(sh_post('endpoint')),
            'http_method'     => sh_post('http_method') === 'POST' ? 'POST' : 'GET',
            'auth_type'       => in_array(sh_post('auth_type'), ['header', 'bearer', 'basic', 'query', 'none'], true) ? sh_post('auth_type') : 'header',
            'auth_name'       => mb_substr(trim(sh_post('auth_name')), 0, 80),
            'secret_name'     => mb_substr(trim(sh_post('secret_name')), 0, 80),
            'extra_headers'   => trim(sh_post('extra_headers')),
            'phone_param'     => mb_substr(trim(sh_post('phone_param')), 0, 60) ?: 'phone',
            'phone_format'    => in_array(sh_post('phone_format'), ['local', 'intl', 'plus'], true) ? sh_post('phone_format') : 'local',
            'body_type'       => in_array(sh_post('body_type'), ['query', 'json', 'form'], true) ? sh_post('body_type') : 'query',
            'map_total'       => mb_substr(trim(sh_post('map_total')), 0, 120),
            'map_delivered'   => mb_substr(trim(sh_post('map_delivered')), 0, 120),
            'map_returned'    => mb_substr(trim(sh_post('map_returned')), 0, 120),
            'map_cancelled'   => mb_substr(trim(sh_post('map_cancelled')), 0, 120),
            'map_list'        => mb_substr(trim(sh_post('map_list')), 0, 120),
            'map_list_status' => mb_substr(trim(sh_post('map_list_status')), 0, 60) ?: 'status',
            'status_delivered'=> trim(sh_post('status_delivered')),
            'status_returned' => trim(sh_post('status_returned')),
            'status_cancelled'=> trim(sh_post('status_cancelled')),
            'timeout_sec'     => max(3, min(20, sh_int($_POST['timeout_sec'] ?? 8))),
        ];
        if ($d['name'] === '') { $errors[] = 'Provider name is required.'; }
        if ($d['base_url'] !== '' && !preg_match('~^https?://~i', $d['base_url'])) { $errors[] = 'API base URL must start with http:// or https://.'; }
        if ($d['base_url'] === '' && !preg_match('~^https?://~i', $d['endpoint'])) { $errors[] = 'Enter an API base URL or a full endpoint URL.'; }
        if ($d['extra_headers'] !== '' && !is_array(json_decode($d['extra_headers'], true))) { $errors[] = 'Required headers must be a JSON object, e.g. {"X-Client":"shophaat"}.'; }
        if ($d['map_list'] === '' && $d['map_delivered'] === '' && $d['map_total'] === '') { $errors[] = 'Map at least the delivered count (or a parcel list path).'; }
        $code = $cur['code'] ?? (preg_replace('/[^a-z0-9]+/', '-', strtolower($d['name'])) . '-' . substr(bin2hex(random_bytes(3)), 0, 5));
        $key = trim(sh_post('api_key'));
        $secret = trim(sh_post('api_secret'));
        if (!$errors) {
            if ($cur === null) {
                $d['code'] = $code;
                $d['api_key'] = sh_verify_encrypt($key);
                $d['api_secret'] = sh_verify_encrypt($secret);
                $id = sh_insert('verify_providers', $d);
            } else {
                if ($key !== '' && strpos($key, '•') === false) { $d['api_key'] = sh_verify_encrypt($key); }
                if ($secret !== '' && strpos($secret, '•') === false) { $d['api_secret'] = sh_verify_encrypt($secret); }
                sh_update('verify_providers', $d, 'id = ?', [$id]);
                sh_query('DELETE FROM verify_courier_cache WHERE provider_id = ?', [$id]); // mapping may have changed
            }
            sh_log_line('admin', 'Courier verification provider "' . $d['name'] . '" saved by ' . $admin['email']);
            sh_flash('success', 'Courier verification provider saved.');
            sh_redirect($listUrl);
        }
    }

}

if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That verification provider no longer exists.');
    sh_redirect($listUrl);
}
$isNew = $editing === null;
$preset = $presets[$presetKey];
// Field values: what was submitted, then the saved row, then the preset default.
$f = static fn(string $k, $d = '') => e((string)($_POST[$k] ?? $editing[$k] ?? $preset[$k] ?? $d));
// Only the encrypted secrets need the saved row directly.
$keyMask = !empty($editing['api_key']) ? sh_verify_mask(sh_verify_decrypt((string)$editing['api_key'])) : '';
$secretMask = !empty($editing['api_secret']) ? sh_verify_mask(sh_verify_decrypt((string)$editing['api_secret'])) : '';

$adminPage = 'verification';
$adminTitle = $isNew ? 'Add verification provider' : 'Edit verification provider';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Customer verification' => $listUrl,
    ($isNew ? 'Add provider' : (string)($editing['name'] ?? 'Provider')) => '',
]) ?>

<?= sh_admin_error_summary($errors) ?>

<div class="sh-panel sh-formcard">
  <div class="sh-panel__body">
    <?php if ($isNew): ?>
      <p class="sh-formcard__note" style="margin-bottom:12px">
        Start from
        <a href="?new=1&amp;preset=generic">the generic template</a> or
        <a href="?new=1&amp;preset=list">the parcel-list template</a> — both can be edited freely below.
      </p>
    <?php endif; ?>
  <form method="post" novalidate id="sh-vp-form">
      <?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="provider_save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Provider name</label><input class="sh-input" name="name" required value="<?= $f('name') ?>" placeholder="e.g. Pathao"></div>
          <div class="sh-field" style="align-self:end"><label class="sh-toggle"><input type="checkbox" name="enabled" value="1" <?= ($editing['enabled'] ?? 1) ? 'checked' : '' ?>><span class="sh-toggle__track"></span><span>Enabled</span></label></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">API base URL</label><input class="sh-input" name="base_url" value="<?= $f('base_url') ?>" placeholder="https://api.courier.example"></div>
          <div class="sh-field"><label class="sh-field__label">API endpoint</label><input class="sh-input" name="endpoint" value="<?= $f('endpoint') ?>" placeholder="/v1/customer-history or /v1/history/{phone}"><span class="sh-field__hint">Use <code>{phone}</code> to place the number in the path.</span></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">HTTP method</label>
            <select class="sh-select" name="http_method"><option value="GET" <?= $f('http_method') === 'GET' ? 'selected' : '' ?>>GET</option><option value="POST" <?= $f('http_method') === 'POST' ? 'selected' : '' ?>>POST</option></select></div>
          <div class="sh-field"><label class="sh-field__label">Request body (POST)</label>
            <select class="sh-select" name="body_type"><?php foreach (['query' => 'Query string', 'json' => 'JSON body', 'form' => 'Form body'] as $k => $l): ?><option value="<?= $k ?>" <?= $f('body_type') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Authentication</label>
            <select class="sh-select" name="auth_type"><?php foreach (['header' => 'API key header', 'bearer' => 'Bearer token', 'basic' => 'HTTP Basic (key:secret)', 'query' => 'Query parameter', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= $f('auth_type') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="sh-field"><label class="sh-field__label">Header / parameter name</label><input class="sh-input" name="auth_name" value="<?= $f('auth_name') ?>" placeholder="Api-Key"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">API key / token</label><input class="sh-input" name="api_key" autocomplete="off" spellcheck="false" value="<?= e(!empty($editing['api_key']) && !empty($editing['id']) ? sh_verify_mask(sh_verify_decrypt($editing['api_key'])) : '') ?>" placeholder="Stored encrypted"></div>
          <div class="sh-field"><label class="sh-field__label">Secret (optional)</label><input class="sh-input" name="api_secret" autocomplete="off" spellcheck="false" value="<?= e(!empty($editing['api_secret']) && !empty($editing['id']) ? sh_verify_mask(sh_verify_decrypt($editing['api_secret'])) : '') ?>"><input class="sh-input" style="margin-top:6px" name="secret_name" value="<?= $f('secret_name') ?>" placeholder="Secret header name (e.g. Secret-Key)"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label">Required headers (JSON object, optional)</label><input class="sh-input" name="extra_headers" value="<?= $f('extra_headers') ?>" placeholder='{"X-Client":"shophaat"}'></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Phone parameter</label><input class="sh-input" name="phone_param" value="<?= $f('phone_param') ?>"></div>
          <div class="sh-field"><label class="sh-field__label">Phone format sent</label>
            <select class="sh-select" name="phone_format"><?php foreach (['local' => '01XXXXXXXXX', 'intl' => '8801XXXXXXXXX', 'plus' => '+8801XXXXXXXXX'] as $k => $l): ?><option value="<?= $k ?>" <?= $f('phone_format') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>

        <h3 class="sh-cv__label" style="margin:14px 0 8px">Response mapping — option A: count fields</h3>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Total orders path</label><input class="sh-input" name="map_total" value="<?= $f('map_total') ?>" placeholder="data.total"></div>
          <div class="sh-field"><label class="sh-field__label">Delivered / received path</label><input class="sh-input" name="map_delivered" value="<?= $f('map_delivered') ?>" placeholder="data.delivered"></div>
          <div class="sh-field"><label class="sh-field__label">Returned / not received path</label><input class="sh-input" name="map_returned" value="<?= $f('map_returned') ?>" placeholder="data.returned"></div>
          <div class="sh-field"><label class="sh-field__label">Cancelled path</label><input class="sh-input" name="map_cancelled" value="<?= $f('map_cancelled') ?>" placeholder="data.cancelled"></div>
        </div>
        <h3 class="sh-cv__label" style="margin:14px 0 8px">Response mapping — option B: parcel list with statuses</h3>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Parcel list path (leave empty to use option A)</label><input class="sh-input" name="map_list" value="<?= $f('map_list') ?>" placeholder="data.parcels"></div>
          <div class="sh-field"><label class="sh-field__label">Status field inside each parcel</label><input class="sh-input" name="map_list_status" value="<?= $f('map_list_status') ?>" placeholder="status"></div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label">Statuses that mean DELIVERED</label><textarea class="sh-input" name="status_delivered" rows="2"><?= $f('status_delivered') ?></textarea></div>
          <div class="sh-field"><label class="sh-field__label">Statuses that mean RETURNED</label><textarea class="sh-input" name="status_returned" rows="2"><?= $f('status_returned') ?></textarea></div>
          <div class="sh-field"><label class="sh-field__label">Statuses that mean CANCELLED</label><textarea class="sh-input" name="status_cancelled" rows="2"><?= $f('status_cancelled') ?></textarea></div>
          <div class="sh-field"><label class="sh-field__label">Timeout (seconds)</label><input class="sh-input" name="timeout_sec" type="number" min="3" max="20" value="<?= $f('timeout_sec', 8) ?>"><span class="sh-field__hint">Checks run when an admin opens the order, never during checkout.</span></div>
        </div>
      <div class="sh-formcard__foot sh-formcard__foot--sticky">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Create provider' : 'Save changes' ?></button>
        <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      </div>
  </form>
  </div>
</div>

<?php if (!$isNew): ?>
  <form method="post" action="<?= e(sh_url('admin/verification.php')) ?>" id="sh-vp-delete">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="provider_delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
