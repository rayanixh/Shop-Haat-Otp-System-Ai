<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/verification.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('integrations.manage');
sh_verify_schema_ensure();
$errors = [];
$testResult = null;

$editId = sh_int($_GET['edit'] ?? 0);
$editing = $editId > 0 ? sh_verify_provider($editId) : null;
$showForm = isset($_GET['new']) || $editing !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'ip') {
        $prov = sh_post('verify_ip_provider');
        if (!isset(sh_verify_ip_providers()[$prov])) { $prov = 'ipwhois'; }
        sh_setting_save('verify_ip_provider', $prov);
        $key = trim(sh_post('verify_ip_key'));
        if ($key !== '' && strpos($key, '•') === false) { sh_setting_save('verify_ip_key', sh_verify_encrypt($key)); }
        if (!empty($_POST['clear_ip_key'])) { sh_setting_save('verify_ip_key', ''); }
        sh_setting_save('verify_ip_ttl_days', (string)max(1, min(365, sh_int($_POST['verify_ip_ttl_days'] ?? 30))));
        sh_setting_save('verify_courier_ttl_hours', (string)max(1, min(720, sh_int($_POST['verify_courier_ttl_hours'] ?? 24))));
        $mode = sh_post('verify_proxy_mode');
        if (!in_array($mode, ['auto', 'none', 'cloudflare', 'xff'], true)) { $mode = 'auto'; }
        sh_setting_save('verify_proxy_mode', $mode);
        $proxies = [];
        foreach (preg_split('/[\s,]+/', sh_post('verify_trusted_proxies'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $px) {
            $ipPart = explode('/', $px)[0];
            if (filter_var($ipPart, FILTER_VALIDATE_IP)) { $proxies[] = $px; }
        }
        sh_setting_save('verify_trusted_proxies', implode("\n", $proxies));
        sh_log_line('admin', 'Customer verification settings updated by ' . $admin['email']);
        sh_flash('success', 'IP and proxy settings saved.');
        sh_redirect('admin/verification.php');
    }

    if ($form === 'provider_delete') {
        $id = sh_int($_POST['id'] ?? 0);
        sh_query('DELETE FROM verify_providers WHERE id = ?', [$id]);
        sh_query('DELETE FROM verify_courier_cache WHERE provider_id = ?', [$id]);
        sh_flash('success', 'Courier verification provider removed.');
        sh_redirect('admin/verification.php');
    }

    if ($form === 'provider_toggle') {
        $id = sh_int($_POST['id'] ?? 0);
        sh_query('UPDATE verify_providers SET enabled = 1 - enabled WHERE id = ?', [$id]);
        sh_redirect('admin/verification.php');
    }

    if ($form === 'provider_test') {
        $p = sh_verify_provider(sh_int($_POST['id'] ?? 0));
        $phone = sh_phone_normalize(sh_post('test_phone'));
        if ($p === null) { $errors[] = 'Provider not found.'; }
        elseif ($phone === '') { $errors[] = 'Enter a valid phone number to test with.'; }
        else { $testResult = ['provider' => $p['name'], 'phone' => $phone] + sh_verify_provider_query($p, $phone); }
    }

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
            sh_redirect('admin/verification.php');
        }
        $editing = $d + ['id' => $id];
        $showForm = true;
    }
}

$providers = sh_verify_providers();
$ipProvider = (string)sh_setting('verify_ip_provider', 'ipwhois');
$ipKeyMask = sh_verify_mask(sh_verify_decrypt((string)sh_setting('verify_ip_key', '')));
$proxyMode = (string)sh_setting('verify_proxy_mode', 'auto');
$presets = sh_verify_provider_presets();
$preset = isset($_GET['preset']) && isset($presets[$_GET['preset']]) ? $presets[$_GET['preset']] : $presets['generic'];
$f = static fn(string $k, $d = '') => e((string)($editing[$k] ?? $preset[$k] ?? $d));

$adminPage = 'verification';
$adminTitle = 'Customer Verification';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?><div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>
<?php if ($testResult): ?>
  <div class="sh-alert <?= $testResult['status'] === 'error' ? 'sh-alert--error' : 'sh-alert--success' ?>"><?= sh_icon($testResult['status'] === 'error' ? 'x-circle' : 'check-circle', 17) ?>
    <div><strong><?= e($testResult['provider']) ?></strong> · <?= e(sh_phone_display($testResult['phone'])) ?> —
      <?php if ($testResult['status'] === 'error'): ?>Request failed: <?= e((string)$testResult['error']) ?>
      <?php elseif ($testResult['status'] === 'empty'): ?>Request succeeded; no records for this number.
      <?php else: ?>Total <?= (int)$testResult['total'] ?> · Delivered <?= (int)$testResult['delivered'] ?> · Returned <?= (int)$testResult['returned'] ?> · Cancelled <?= (int)$testResult['cancelled'] ?><?= $testResult['success_rate'] !== null ? ' · ' . e((string)$testResult['success_rate']) . '%' : '' ?><?php endif; ?>
      <span class="sh-table__meta">(test results are not cached)</span></div></div>
<?php endif; ?>

<div class="sh-cards">
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('map-pin', 17) ?> IP information &amp; trusted proxy</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="ip">
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="v-ipp">IP geolocation provider</label>
            <select class="sh-select" id="v-ipp" name="verify_ip_provider">
              <?php foreach (sh_verify_ip_providers() as $k => $pv): ?>
                <option value="<?= e($k) ?>" <?= $ipProvider === $k ? 'selected' : '' ?>><?= e($pv['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="sh-field__hint">Lookups run server-side only and are cached. Results are approximate (city / region level).</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="v-ipk">API key / token (if the provider needs one)</label>
            <input class="sh-input" id="v-ipk" name="verify_ip_key" type="text" autocomplete="off" spellcheck="false" value="<?= e($ipKeyMask) ?>" placeholder="Optional">
            <?php if ($ipKeyMask !== ''): ?><label class="sh-toggle" style="margin-top:6px"><input type="checkbox" name="clear_ip_key" value="1"><span class="sh-toggle__track"></span><span>Remove stored key</span></label><?php endif; ?>
          </div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="v-ipttl">Re-check IP information after (days)</label>
            <input class="sh-input" id="v-ipttl" name="verify_ip_ttl_days" type="number" min="1" max="365" value="<?= (int)sh_setting('verify_ip_ttl_days', '30') ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="v-cttl">Re-check courier history after (hours)</label>
            <input class="sh-input" id="v-cttl" name="verify_courier_ttl_hours" type="number" min="1" max="720" value="<?= (int)sh_setting('verify_courier_ttl_hours', '24') ?>">
          </div>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="v-proxy">Client IP detection</label>
            <select class="sh-select" id="v-proxy" name="verify_proxy_mode">
              <option value="auto" <?= $proxyMode === 'auto' ? 'selected' : '' ?>>Automatic (Cloudflare header if present, else connection IP)</option>
              <option value="none" <?= $proxyMode === 'none' ? 'selected' : '' ?>>Connection IP only (no proxy)</option>
              <option value="cloudflare" <?= $proxyMode === 'cloudflare' ? 'selected' : '' ?>>Cloudflare (CF-Connecting-IP from trusted proxies)</option>
              <option value="xff" <?= $proxyMode === 'xff' ? 'selected' : '' ?>>Reverse proxy (X-Forwarded-For from trusted proxies)</option>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="v-tp">Trusted proxy IPs / CIDRs</label>
            <textarea class="sh-input" id="v-tp" name="verify_trusted_proxies" rows="3" placeholder="One per line, e.g. 173.245.48.0/20"><?= e((string)sh_setting('verify_trusted_proxies', '')) ?></textarea>
            <span class="sh-field__hint">Forwarded headers are only trusted when the connection comes from one of these addresses, so visitors cannot spoof their IP.</span>
          </div>
        </div>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
      </form>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head sh-cv__head">
      <h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Courier verification providers</h2>
      <div class="sh-cv__tools">
        <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/verification.php?new=1&preset=generic')) ?>"><?= sh_icon('plus', 13) ?> Add provider (count fields)</a>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/verification.php?new=1&preset=list')) ?>"><?= sh_icon('plus', 13) ?> Add provider (parcel list)</a>
      </div>
    </div>
    <div class="sh-panel__body">
      <?php if (!$providers): ?>
        <p class="sh-panel__note">No courier verification provider yet. Add the courier's phone-history API (Pathao, Steadfast, RedX or any other service that reports delivered / returned counts per phone number). Each provider has its own URL, authentication, phone format and status mapping.</p>
      <?php else: ?>
        <div class="sh-vp-grid">
          <?php foreach ($providers as $p): $k = sh_verify_mask(sh_verify_decrypt($p['api_key'])); ?>
            <article class="sh-vp">
              <div class="sh-vp__head">
                <strong><?= e($p['name']) ?></strong>
                <span class="sh-pill <?= $p['enabled'] ? 'sh-badge--ok' : 'sh-badge--muted' ?>"><?= $p['enabled'] ? 'Enabled' : 'Disabled' ?></span>
              </div>
              <dl class="sh-cv__kv">
                <div><dt>Endpoint</dt><dd><code><?= e($p['http_method']) ?> <?= e(rtrim((string)$p['base_url'], '/') . '/' . ltrim((string)$p['endpoint'], '/')) ?></code></dd></div>
                <div><dt>Auth</dt><dd><?= e($p['auth_type']) ?><?= $k !== '' ? ' · ' . e($k) : '' ?></dd></div>
                <div><dt>Phone</dt><dd><code><?= e($p['phone_param']) ?></code> as <?= e($p['phone_format']) ?></dd></div>
                <div><dt>Mapping</dt><dd><?= $p['map_list'] !== '' ? 'Parcel list at <code>' . e($p['map_list']) . '</code>' : 'Counts: <code>' . e($p['map_delivered'] ?: '—') . '</code> / <code>' . e($p['map_returned'] ?: '—') . '</code>' ?></dd></div>
              </dl>
              <form method="post" class="sh-vp__test"><?= sh_csrf_field() ?><input type="hidden" name="form" value="provider_test"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input class="sh-input" name="test_phone" placeholder="01XXXXXXXXX" inputmode="tel"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('zap', 13) ?> Test</button>
              </form>
              <div class="sh-actions">
                <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/verification.php?edit=' . (int)$p['id'])) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
                <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="provider_toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= $p['enabled'] ? 'Disable' : 'Enable' ?></button></form>
                <form method="post" data-confirm="Remove this provider and its cached history?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="provider_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?> Remove</button></form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($showForm): ?>
  <section class="sh-panel" id="provider-form">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?> <?= $editing && !empty($editing['id']) ? 'Edit provider' : 'New provider' ?></h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="provider_save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
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
        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save provider</button>
          <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/verification.php')) ?>">Cancel</a>
        </div>
      </form>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
