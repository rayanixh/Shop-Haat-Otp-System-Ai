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
require_once SH_ROOT . '/includes/admin-tools.php';
sh_verify_schema_ensure();
$errors = [];
$testResult = null;

$newUrl = sh_url('admin/verification-provider-edit.php?new=1');

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
        // The provider form has its own screen now: a save posted here (an open
        // tab from before the change) is forwarded with its id, never dropped.
        sh_flash('info', 'The provider form has moved to its own page.');
        $id = sh_int($_POST['id'] ?? 0);
        sh_redirect('admin/verification-provider-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
    }
}

// An old bookmark to ?edit= / ?new= opens the dedicated screen instead of
// silently showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit', 'new', 'preset']);
    if ($forwardId > 0) { sh_redirect('admin/verification-provider-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
    if (isset($_GET['new'])) {
        $presetKey = sh_get('preset');
        sh_redirect('admin/verification-provider-edit.php?new=1' . ($presetKey !== '' ? '&preset=' . urlencode($presetKey) : ''));
    }
}

$providers = sh_verify_providers();
$ipProvider = (string)sh_setting('verify_ip_provider', 'ipwhois');
$ipKeyMask = sh_verify_mask(sh_verify_decrypt((string)sh_setting('verify_ip_key', '')));
$proxyMode = (string)sh_setting('verify_proxy_mode', 'auto');
$adminPage = 'verification';
$adminTitle = 'Customer Verification';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Customer Verification',
    'A courier provider answers how many parcels were delivered, returned or cancelled for a phone number. Checks run when an admin opens an order, never during checkout.',
    sh_admin_add_button('Add provider', $newUrl, '', 'sh-btn sh-btn--sm', 'integrations.manage'),
    'shield'
) ?>
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

  <section class="sh-panel" id="sh-verify-providers">
    <div class="sh-panel__head sh-cv__head">
      <h2 class="sh-panel__title"><?= sh_icon('truck', 17) ?> Courier verification providers</h2>
      <div class="sh-cv__tools">
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/verification.php?new=1&preset=list')) ?>"><?= sh_icon('plus', 13) ?> Add with parcel-list template</a>
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
                <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/verification-provider-edit.php?edit=' . (int)$p['id'])) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
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

</div>
<?php require __DIR__ . '/_footer.php'; ?>
