<?php
require_once __DIR__ . '/_ai.php';

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$newDriver = sh_get('new');
$drivers = sh_ai_drivers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (!$aiInstalled) { sh_flash('error', 'Run the installer in AI Settings first.'); sh_redirect('admin/ai/providers.php'); }
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);

    if ($form === 'toggle' && $id > 0) {
        $row = sh_ai_provider_row($id);
        if ($row !== null) {
            sh_update('ai_providers', ['status' => (int)$row['status'] === 1 ? 0 : 1], 'id = ?', [$id]);
            sh_flash('success', $row['name'] . ((int)$row['status'] === 1 ? ' disabled.' : ' enabled.'));
        }
        sh_redirect('admin/ai/providers.php');
    }

    if ($form === 'default' && $id > 0) {
        if (sh_ai_provider_row($id) !== null) { sh_ai_provider_set_default($id); sh_flash('success', 'Default provider updated.'); }
        sh_redirect('admin/ai/providers.php');
    }

    if ($form === 'delete' && $id > 0) {
        $row = sh_ai_provider_row($id);
        if ($row !== null) {
            sh_ai_provider_delete($id);
            sh_log_line('admin', 'AI provider "' . $row['name'] . '" deleted by ' . ($admin['email'] ?? ''));
            sh_flash('success', 'Provider removed. Tasks that used it now fall back to the default provider.');
        }
        sh_redirect('admin/ai/providers.php');
    }

    if ($form === 'save') {
        $driver = sh_post('driver');
        if (!isset($drivers[$driver])) { $errors['driver'] = 'Choose a valid provider type.'; }
        $name = trim(sh_post('name'));
        if ($name === '') { $errors['name'] = 'Give this provider a name.'; }
        $base = trim(sh_post('base_url'));
        if ($base !== '' && !preg_match('#^https?://[^\s]+$#i', $base)) { $errors['base_url'] = 'The base URL must start with http:// or https://.'; }
        if ($driver === 'openai_compatible' && $base === '') { $errors['base_url'] = 'A custom provider needs its base URL.'; }
        $extra = trim((string)($_POST['extra_headers'] ?? ''));
        if ($extra !== '') {
            $dec = json_decode($extra, true);
            if (!is_array($dec)) { $errors['extra_headers'] = 'Optional headers must be a JSON object, e.g. {"X-Org": "abc"}.'; }
            else { $extra = json_encode($dec, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
        }
        $keyPlain = (string)($_POST['api_key'] ?? '');
        if ($id === 0 && trim($keyPlain) === '') { $errors['api_key'] = 'Paste the API key for this provider.'; }

        if (!$errors) {
            $pid = sh_ai_provider_save([
                'name' => $name, 'driver' => $driver, 'base_url' => $base,
                'auth_header' => sh_post('auth_header'), 'extra_headers' => $extra,
                'default_model' => sh_post('default_model'), 'default_image_model' => sh_post('default_image_model'),
                'status' => !empty($_POST['status']), 'is_default' => !empty($_POST['is_default']),
                'api_key_plain' => trim($keyPlain), 'clear_key' => !empty($_POST['clear_key']),
            ], $id);
            sh_ai_models_seed($pid);
            foreach ([sh_post('default_model'), sh_post('default_image_model')] as $mid) {
                if (trim($mid) !== '') { sh_ai_model_ensure($pid, trim($mid)); }
            }
            sh_log_line('admin', 'AI provider "' . $name . '" saved by ' . ($admin['email'] ?? ''));
            sh_flash('success', $name . ' saved. Use "Test connection" to verify the key.');
            sh_redirect('admin/ai/providers.php');
        }
        $editId = $id;
    }
}

$rows = $aiInstalled ? sh_ai_providers_all(true) : [];
$editing = $editId > 0 ? sh_ai_provider_row($editId) : null;
$showForm = $editing !== null || $newDriver !== '' || $errors;
$formDriver = $editing['driver'] ?? (isset($drivers[$newDriver]) ? $newDriver : 'openai');
$val = static function (string $k, string $d = '') use ($editing): string {
    if (isset($_POST['form']) && $_POST['form'] === 'save') { return (string)($_POST[$k] ?? $d); }
    return (string)($editing[$k] ?? $d);
};

$adminPage = 'ai_providers';
$adminTitle = 'AI Providers';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('cpu', 20) ?> AI Providers</h2>
    <p class="sh-aihead__sub">Connect OpenAI, OpenRouter, Gemini, Claude or any OpenAI-compatible API. Keys stay encrypted on the server.</p>
  </div>
</div>

<?php sh_ai_subnav('providers'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($aiInstalled): ?>
<div class="sh-cards sh-cards--3">
  <?php foreach ($rows as $p):
    $on = (int)$p['status'] === 1;
    $hasKey = trim((string)$p['api_key']) !== '';
    $modelCount = count(sh_ai_models((int)$p['id']));
  ?>
  <section class="sh-panel sh-paycard" id="provider-<?= (int)$p['id'] ?>">
    <div class="sh-paycard__head">
      <div class="sh-paycard__logo sh-paycard__logo--cod sh-aiprov__mark" data-driver="<?= e((string)$p['driver']) ?>">
        <?= sh_icon('cpu', 24) ?>
      </div>
      <div class="sh-paycard__meta">
        <h2 class="sh-paycard__name"><?= e((string)$p['name']) ?></h2>
        <div class="sh-paycard__tags">
          <span class="sh-badge sh-badge--muted"><?= e(sh_ai_driver_label((string)$p['driver'])) ?></span>
          <span class="sh-statuspill <?= $on ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $on ? 'Enabled' : 'Disabled' ?></span>
          <?php if ((int)$p['is_default'] === 1): ?><span class="sh-badge sh-badge--ok">Default</span><?php endif; ?>
          <?php if (!$hasKey): ?><span class="sh-badge sh-badge--warn">No API key</span><?php endif; ?>
        </div>
      </div>
    </div>
    <dl class="sh-aiprov__facts">
      <dt>Base URL</dt><dd class="sh-break"><?= e((string)($p['base_url'] ?: ($drivers[$p['driver']] ?? null ? $drivers[$p['driver']]::defaultBaseUrl() : '—'))) ?></dd>
      <dt>API key</dt><dd><code><?= e($hasKey ? sh_ai_provider_masked_key($p) : 'not set') ?></code></dd>
      <dt>Text model</dt><dd class="sh-break"><?= e((string)($p['default_model'] ?: 'not set')) ?></dd>
      <dt>Image model</dt><dd class="sh-break"><?= e((string)($p['default_image_model'] ?: '—')) ?></dd>
      <dt>Models</dt><dd><?= $modelCount ?> in catalogue<?= $p['models_synced_at'] ? ' · synced ' . e(sh_ai_ago((string)$p['models_synced_at'])) : '' ?></dd>
      <dt>Last test</dt>
      <dd>
        <?php if ($p['last_tested_at']): ?>
          <span class="sh-badge <?= (int)$p['last_test_ok'] === 1 ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= (int)$p['last_test_ok'] === 1 ? 'OK' : 'Failed' ?></span>
          <span class="sh-table__meta sh-break"><?= e((string)$p['last_test_note']) ?> · <?= e(sh_ai_ago((string)$p['last_tested_at'])) ?></span>
        <?php else: ?><span class="sh-table__meta">never</span><?php endif; ?>
      </dd>
    </dl>
    <p class="sh-airesult" data-ai-test-result="<?= (int)$p['id'] ?>" hidden></p>
    <div class="sh-actions">
      <button class="sh-btn sh-btn--sm" type="button" data-ai-test data-provider="<?= (int)$p['id'] ?>" <?= $hasKey ? '' : 'disabled' ?>><?= sh_icon('zap', 13) ?> Test connection</button>
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/providers.php?edit=' . (int)$p['id'])) ?>#edit"><?= sh_icon('pencil', 13) ?> Edit</a>
      <form method="post" style="display:inline"><?= sh_csrf_field() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= $on ? 'Disable' : 'Enable' ?></button></form>
      <?php if ((int)$p['is_default'] !== 1): ?>
        <form method="post" style="display:inline"><?= sh_csrf_field() ?><input type="hidden" name="form" value="default"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Make default</button></form>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="sh-panel sh-aiprov__add">
    <div class="sh-panel__body">
      <p class="sh-field__label">Add a provider</p>
      <div class="sh-actions">
        <?php foreach ($drivers as $key => $cls): ?>
          <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/providers.php?new=' . $key)) ?>#edit"><?= sh_icon('plus', 13) ?> <?= e($cls::defaultLabel()) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<?php if ($showForm): $cls = $drivers[$formDriver]; ?>
<div class="sh-cards" id="edit" style="margin-top:14px">
  <section class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?> <?= $editing ? 'Edit ' . e((string)$editing['name']) : 'New ' . e($cls::defaultLabel()) . ' provider' ?></h2>
    </div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="pv-driver">Provider type</label>
            <select class="sh-select" id="pv-driver" name="driver" <?= $editing ? 'disabled' : '' ?> onchange="location.href='<?= e(sh_url('admin/ai/providers.php?new=')) ?>'+this.value+'#edit'">
              <?php foreach ($drivers as $key => $c): ?>
                <option value="<?= e($key) ?>" <?= $formDriver === $key ? 'selected' : '' ?>><?= e($c::defaultLabel()) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($editing): ?><input type="hidden" name="driver" value="<?= e($formDriver) ?>"><?php endif; ?>
            <?php if ($cls::helpText() !== ''): ?><span class="sh-field__hint"><?= e($cls::helpText()) ?></span><?php endif; ?>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="pv-name">Provider name</label>
            <input class="sh-input" id="pv-name" name="name" maxlength="80" required value="<?= e($val('name', $cls::defaultLabel())) ?>">
          </div>
        </div>

        <div class="sh-field">
          <label class="sh-field__label" for="pv-base">API base URL</label>
          <input class="sh-input" id="pv-base" name="base_url" maxlength="255" value="<?= e($val('base_url')) ?>" placeholder="<?= e($cls::defaultBaseUrl() ?: 'https://api.example.com/v1') ?>" inputmode="url">
          <span class="sh-field__hint"><?= $cls::defaultBaseUrl() !== '' ? 'Leave blank for ' . e($cls::defaultBaseUrl()) . '.' : 'Required. Include the version segment (…/v1).' ?></span>
        </div>

        <div class="sh-field">
          <label class="sh-field__label" for="pv-key">API key</label>
          <div class="sh-secret">
            <input class="sh-input" id="pv-key" type="password" name="api_key" value="" autocomplete="new-password"
                   placeholder="<?= $editing && trim((string)$editing['api_key']) !== '' ? 'Stored — leave blank to keep' : 'Paste the key' ?>">
            <button class="sh-secret__btn" type="button" data-reveal="pv-key" aria-label="Show or hide"><?= sh_icon('eye', 15) ?></button>
          </div>
          <span class="sh-field__hint">Encrypted at rest (AES-256). Used only from the server; never returned to the browser.
            <?php if ($editing && trim((string)$editing['api_key']) !== ''): ?> Current: <code><?= e(sh_ai_provider_masked_key($editing)) ?></code><?php endif; ?></span>
        </div>
        <?php if ($editing && trim((string)$editing['api_key']) !== ''): ?>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_key" value="1"><span>Remove the stored API key</span></label>
        <?php endif; ?>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="pv-model">Default text model</label>
            <input class="sh-input" id="pv-model" name="default_model" maxlength="120" list="pv-model-list" value="<?= e($val('default_model', $cls::starterModels()[0] ?? '')) ?>" placeholder="Type a model ID">
            <datalist id="pv-model-list">
              <?php foreach ($editing ? sh_ai_models((int)$editing['id'], true) : [] as $m): if (empty($m['output_text'])) { continue; } ?>
                <option value="<?= e((string)$m['model_id']) ?>"><?= e((string)$m['name']) ?></option>
              <?php endforeach; ?>
              <?php if (!$editing): foreach ($cls::starterModels() as $m): ?><option value="<?= e($m) ?>"><?php endforeach; endif; ?>
            </datalist>
            <span class="sh-field__hint">Any model ID accepted. After saving, press "Load models" in the Models tab to fill the list from the provider.</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="pv-imodel">Default image model</label>
            <input class="sh-input" id="pv-imodel" name="default_image_model" maxlength="120" list="pv-imodel-list" value="<?= e($val('default_image_model')) ?>" placeholder="<?= $formDriver === 'anthropic' ? 'Not supported by Claude' : 'Optional' ?>" <?= $formDriver === 'anthropic' ? 'disabled' : '' ?>>
            <datalist id="pv-imodel-list">
              <?php foreach ($editing ? sh_ai_models((int)$editing['id'], true) : [] as $m): if (empty($m['output_image'])) { continue; } ?>
                <option value="<?= e((string)$m['model_id']) ?>"><?= e((string)$m['name']) ?></option>
              <?php endforeach; ?>
            </datalist>
          </div>
        </div>

        <?php if ($cls::supportsCustomHeaders() || $formDriver === 'openai'): ?>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="pv-auth"><?= $formDriver === 'openai' ? 'Organisation ID (optional)' : 'Authentication header' ?></label>
            <input class="sh-input" id="pv-auth" name="auth_header" maxlength="120" value="<?= e($val('auth_header')) ?>"
                   placeholder="<?= $formDriver === 'openai' ? 'org-…' : 'Authorization: Bearer {key}' ?>">
            <?php if ($formDriver !== 'openai'): ?><span class="sh-field__hint">Default is <code>Authorization: Bearer {key}</code>. Use <code>x-api-key: {key}</code> for key-header APIs.</span><?php endif; ?>
          </div>
          <?php if ($cls::supportsCustomHeaders()): ?>
          <div class="sh-field">
            <label class="sh-field__label" for="pv-extra">Optional HTTP headers (JSON)</label>
            <textarea class="sh-textarea" id="pv-extra" name="extra_headers" rows="3" placeholder='{"X-Custom": "value"}'><?= e($val('extra_headers')) ?></textarea>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="status" value="1" <?= (isset($_POST['form']) ? !empty($_POST['status']) : ($editing ? (int)$editing['status'] === 1 : true)) ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Enabled</span></label>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="is_default" value="1" <?= (isset($_POST['form']) ? !empty($_POST['is_default']) : ($editing ? (int)$editing['is_default'] === 1 : !$rows)) ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Use as the default provider for tasks without a specific route</span></label>

        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save provider</button>
          <a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/ai/providers.php')) ?>">Cancel</a>
        </div>
      </form>
      <?php if ($editing): ?>
        <form method="post" data-confirm="Remove <?= e((string)$editing['name']) ?>? Its model catalogue is deleted; history entries are kept." style="margin-top:12px">
          <?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?> Delete this provider</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
