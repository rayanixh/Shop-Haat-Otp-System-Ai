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
            sh_ai_providers_all(true);
            sh_flash('success', $row['name'] . ((int)$row['status'] === 1 ? ' disabled.' : ' enabled.'));
        }
        sh_redirect('admin/ai/providers.php');
    }

    if ($form === 'default' && $id > 0) {
        $row = sh_ai_provider_row($id);
        if ($row !== null) {
            sh_ai_provider_set_default($id);
            sh_flash('success', $row['name'] . ' is now the default provider.');
        }
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
        if ($driver === 'openai_compatible' && $base === '' && $id === 0) { $errors['base_url'] = 'A custom provider needs its base URL.'; }
        $extra = trim((string)($_POST['extra_headers'] ?? ''));
        if ($extra !== '') {
            $dec = json_decode($extra, true);
            if (!is_array($dec)) { $errors['extra_headers'] = 'Optional headers must be a JSON object, e.g. {"X-Org": "abc"}.'; }
            else { $extra = json_encode($dec, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
        }
        $keyPlain = (string)($_POST['api_key'] ?? '');
        if ($id === 0 && trim($keyPlain) === '') { $errors['api_key'] = 'Paste the API key for this provider.'; }
        $defaultModel = trim(sh_post('default_model'));
        $imageModel = trim(sh_post('default_image_model'));
        if ($driver === 'anthropic' && $imageModel !== '') {
            $errors['default_image_model'] = 'Claude cannot generate images. Leave the image model blank.';
        }

        if (!$errors) {
            $pid = sh_ai_provider_save([
                'name' => $name, 'driver' => $driver, 'base_url' => $base,
                'auth_header' => sh_post('auth_header'), 'extra_headers' => $extra,
                'default_model' => $defaultModel, 'default_image_model' => $imageModel,
                'status' => !empty($_POST['status']), 'is_default' => !empty($_POST['is_default']),
                'api_key_plain' => trim($keyPlain), 'clear_key' => !empty($_POST['clear_key']),
            ], $id);
            sh_ai_models_seed($pid);
            foreach ([$defaultModel, $imageModel] as $mid) {
                if ($mid !== '') { sh_ai_model_ensure($pid, $mid); }
            }
            sh_log_line('admin', 'AI provider "' . $name . '" saved by ' . ($admin['email'] ?? ''));
            $wantsTest = sh_post('save_action') === 'test';
            sh_flash('success', $name . ' saved.' . ($wantsTest ? ' Running a read-only connection test…' : ' Use "Test connection" to verify it.'));
            if ($wantsTest && $pid > 0) {
                sh_redirect('admin/ai/providers.php?edit=' . $pid . '&run_test=1#provider-' . $pid);
            }
            sh_redirect('admin/ai/providers.php#provider-' . $pid);
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

$driverIcons = ['openai' => 'cpu', 'openrouter' => 'link', 'gemini' => 'grid', 'anthropic' => 'shield', 'openai_compatible' => 'database'];
$driverIcon = static fn(string $d): string => $driverIcons[$d] ?? 'cpu';
$driverPitch = [
    'openai' => 'GPT text models and image generation. Paste a key that starts with sk-.',
    'openrouter' => 'One key for many vendors. Model IDs look like openai/gpt-4o-mini.',
    'gemini' => 'A Google AI Studio key for Gemini text and Imagen images.',
    'anthropic' => 'Claude text models. Claude cannot generate images.',
    'openai_compatible' => 'LM Studio, Groq, Together, vLLM or a corporate gateway.',
];

$total = count($rows);
$enabledCount = 0;
$testedCount = 0;
$failedCount = 0;
foreach ($rows as $r) {
    if ((int)$r['status'] === 1) { $enabledCount++; }
    if (!empty($r['last_tested_at'])) { $testedCount++; if ((int)$r['last_test_ok'] !== 1) { $failedCount++; } }
}
$untestedCount = $total - $testedCount;
$runTestId = sh_int($_GET['run_test'] ?? 0);

$adminPage = 'ai_providers';
$adminTitle = 'AI Providers';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('cpu', 20) ?> AI Providers</h2>
    <p class="sh-aihead__sub">Every AI screen uses one of these accounts. Keys are encrypted at rest and never sent to the browser.</p>
  </div>
  <?php if ($aiInstalled): ?>
  <div class="sh-aihead__actions">
    <button class="sh-btn" type="button" data-modal-open="sh-aiprov-add"><?= sh_icon('plus', 15) ?> Add provider</button>
  </div>
  <?php endif; ?>
</div>

<?php sh_ai_subnav('providers'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($aiInstalled): ?>
  <?php if ($total > 0): ?>
  <div class="sh-aiprov-stats" role="group" aria-label="Provider overview">
    <div class="sh-aiprov-stat">
      <span class="sh-aiprov-stat__icon"><?= sh_icon('cpu', 16) ?></span>
      <span><strong><?= $total ?></strong><small>provider<?= $total === 1 ? '' : 's' ?></small></span>
    </div>
    <div class="sh-aiprov-stat">
      <span class="sh-aiprov-stat__icon sh-aiprov-stat__icon--ok"><?= sh_icon('check-circle', 16) ?></span>
      <span><strong><?= $enabledCount ?></strong><small>enabled</small></span>
    </div>
    <div class="sh-aiprov-stat">
      <span class="sh-aiprov-stat__icon sh-aiprov-stat__icon--warn"><?= sh_icon('clock', 16) ?></span>
      <span><strong><?= $untestedCount ?></strong><small>not tested</small></span>
    </div>
    <div class="sh-aiprov-stat">
      <span class="sh-aiprov-stat__icon sh-aiprov-stat__icon--bad"><?= sh_icon('alert', 16) ?></span>
      <span><strong><?= $failedCount ?></strong><small>last test failed</small></span>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($aiInstalled && $showForm): $cls = $drivers[$formDriver]; ?>
<div class="sh-aiprov-form" id="edit">
  <section class="sh-panel" id="provider-form">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title">
        <?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
        <?= $editing ? 'Edit ' . e((string)$editing['name']) : 'New ' . e($cls::defaultLabel()) . ' provider' ?>
      </h2>
      <div class="sh-panel__actions">
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/providers.php')) ?>"><?= sh_icon('x', 13) ?> Close</a>
      </div>
    </div>
    <div class="sh-panel__body">
      <form method="post" novalidate autocomplete="off">
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

        <div class="sh-aiprov-formsec">
          <p class="sh-aiprov-formsec__title">Provider and connection</p>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="pv-driver">Provider type</label>
              <select class="sh-select" id="pv-driver" name="driver" <?= $editing ? 'disabled' : '' ?> data-navigate-prefix="<?= e(sh_url('admin/ai/providers.php?new=')) ?>" data-navigate-suffix="#edit">
                <?php foreach ($drivers as $key => $c): ?>
                  <option value="<?= e($key) ?>" <?= $formDriver === $key ? 'selected' : '' ?>><?= e($c::defaultLabel()) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($editing): ?><input type="hidden" name="driver" value="<?= e($formDriver) ?>"><?php endif; ?>
              <?php if ($cls::helpText() !== ''): ?><span class="sh-field__hint"><?= e($cls::helpText()) ?></span><?php endif; ?>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="pv-name">Display name</label>
              <input class="sh-input" id="pv-name" name="name" maxlength="80" required value="<?= e($val('name', $cls::defaultLabel())) ?>">
              <span class="sh-field__hint">Shown in cards and in AI History.</span>
            </div>
          </div>

          <div class="sh-field">
            <label class="sh-field__label" for="pv-base">API base URL</label>
            <input class="sh-input sh-input--mono" id="pv-base" name="base_url" maxlength="255" value="<?= e($val('base_url')) ?>" placeholder="<?= e($cls::defaultBaseUrl() ?: 'https://api.example.com/v1') ?>" inputmode="url">
            <span class="sh-field__hint"><?= $cls::defaultBaseUrl() !== '' ? 'Leave blank for ' . e($cls::defaultBaseUrl()) . '.' : 'Required. Include the version segment (…/v1).' ?></span>
          </div>

          <div class="sh-field">
            <label class="sh-field__label" for="pv-key">API key</label>
            <div class="sh-secret">
              <input class="sh-input" id="pv-key" type="password" name="api_key" value="" autocomplete="new-password" spellcheck="false"
                     placeholder="<?= $editing && trim((string)$editing['api_key']) !== '' ? 'Saved — leave blank to keep' : 'Paste the key' ?>">
              <button class="sh-secret__btn" type="button" data-reveal="pv-key" aria-label="Show or hide the key"><?= sh_icon('eye', 15) ?></button>
            </div>
            <span class="sh-field__hint">Encrypted with AES-256. Only the server uses it; it is never returned to this page.
              <?php if ($editing && trim((string)$editing['api_key']) !== ''): ?> Stored: <code><?= e(sh_ai_provider_masked_key($editing)) ?></code><?php endif; ?></span>
            <?php if (isset($errors['api_key'])): ?><span class="sh-field__error"><?= e($errors['api_key']) ?></span><?php endif; ?>
          </div>
          <?php if ($editing && trim((string)$editing['api_key']) !== ''): ?>
            <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="clear_key" value="1"><span>Remove the stored API key</span></label>
          <?php endif; ?>
        </div>

        <div class="sh-aiprov-formsec">
          <p class="sh-aiprov-formsec__title">Models</p>
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
              <span class="sh-field__hint">Any model ID the account can use. After saving, load the catalogue in the Models tab.</span>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="pv-imodel">Default image model</label>
              <input class="sh-input" id="pv-imodel" name="default_image_model" maxlength="120" list="pv-imodel-list" value="<?= e($val('default_image_model')) ?>" placeholder="<?= $formDriver === 'anthropic' ? 'Not supported by Claude' : 'Optional' ?>" <?= $formDriver === 'anthropic' ? 'disabled' : '' ?>>
              <datalist id="pv-imodel-list">
                <?php foreach ($editing ? sh_ai_models((int)$editing['id'], true) : [] as $m): if (empty($m['output_image'])) { continue; } ?>
                  <option value="<?= e((string)$m['model_id']) ?>"><?= e((string)$m['name']) ?></option>
                <?php endforeach; ?>
              </datalist>
              <?php if (isset($errors['default_image_model'])): ?><span class="sh-field__error"><?= e($errors['default_image_model']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($cls::supportsCustomHeaders() || $formDriver === 'openai'): ?>
        <div class="sh-aiprov-formsec">
          <p class="sh-aiprov-formsec__title">Advanced</p>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="pv-auth"><?= $formDriver === 'openai' ? 'Organisation ID (optional)' : 'Authentication header' ?></label>
              <input class="sh-input sh-input--mono" id="pv-auth" name="auth_header" maxlength="120" value="<?= e($val('auth_header')) ?>"
                     placeholder="<?= $formDriver === 'openai' ? 'org-…' : 'Authorization: Bearer {key}' ?>">
              <?php if ($formDriver !== 'openai'): ?><span class="sh-field__hint">Default is <code>Authorization: Bearer {key}</code>. Use <code>x-api-key: {key}</code> for key-header APIs.</span><?php endif; ?>
            </div>
            <?php if ($cls::supportsCustomHeaders()): ?>
            <div class="sh-field">
              <label class="sh-field__label" for="pv-extra">Optional HTTP headers (JSON)</label>
              <textarea class="sh-textarea" id="pv-extra" name="extra_headers" rows="3" spellcheck="false" placeholder='{"X-Custom": "value"}'><?= e($val('extra_headers')) ?></textarea>
              <?php if (isset($errors['extra_headers'])): ?><span class="sh-field__error"><?= e($errors['extra_headers']) ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="sh-aiprov-formsec">
          <p class="sh-aiprov-formsec__title">Availability</p>
          <label class="sh-toggle" style="margin-bottom:10px">
            <input type="checkbox" name="status" value="1" <?= (isset($_POST['form']) ? !empty($_POST['status']) : ($editing ? (int)$editing['status'] === 1 : true)) ? 'checked' : '' ?>>
            <span class="sh-toggle__track"></span><span>Enabled — tasks may use this provider</span></label>
          <label class="sh-toggle" style="margin-bottom:4px">
            <input type="checkbox" name="is_default" value="1" <?= (isset($_POST['form']) ? !empty($_POST['is_default']) : ($editing ? (int)$editing['is_default'] === 1 : !$rows)) ? 'checked' : '' ?>>
            <span class="sh-toggle__track"></span><span>Use as the default provider when a task has no route</span></label>
        </div>

        <div class="sh-actions">
          <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save provider</button>
          <button class="sh-btn sh-btn--ghost" type="submit" name="save_action" value="test" data-no-lock><?= sh_icon('zap', 15) ?> Save &amp; test connection</button>
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

<?php if ($aiInstalled && !$rows): ?>
  <section class="sh-panel sh-aiprov-empty">
    <div class="sh-panel__body">
      <span class="sh-aiprov-empty__icon"><?= sh_icon('cpu', 26) ?></span>
      <h2 class="sh-aiprov-empty__title">No AI provider yet</h2>
      <p class="sh-aiprov-empty__text">Add OpenAI, OpenRouter, Google Gemini, Anthropic Claude or any OpenAI-compatible endpoint. Nothing is generated until you add one.</p>
      <div class="sh-actions" style="justify-content:center">
        <button class="sh-btn" type="button" data-modal-open="sh-aiprov-add"><?= sh_icon('plus', 15) ?> Add your first provider</button>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($aiInstalled && $rows): ?>
<div class="sh-aiprov-grid">
  <?php foreach ($rows as $p):
    $on = (int)$p['status'] === 1;
    $hasKey = trim((string)$p['api_key']) !== '';
    $modelCount = count(sh_ai_models((int)$p['id']));
    $driver = (string)$p['driver'];
    $baseDisplay = (string)($p['base_url'] ?: (isset($drivers[$driver]) ? $drivers[$driver]::defaultBaseUrl() : '—'));
    $testCode = (string)($p['last_test_code'] ?? '');
    if ($testCode === '' && !empty($p['last_tested_at'])) { $testCode = (int)$p['last_test_ok'] === 1 ? 'connected' : 'failed'; }
    $tone = $testCode !== '' ? sh_ai_test_state_tone($testCode) : 'muted';
    $nextAction = trim((string)($p['last_test_next'] ?? ''));
  ?>
  <article class="sh-aiprov" id="provider-<?= (int)$p['id'] ?>" data-ai-providers data-ai-card="<?= (int)$p['id'] ?>">
    <header class="sh-aiprov__head">
      <span class="sh-aiprov__mark sh-aiprov__mark--<?= e($tone) ?>" data-driver="<?= e($driver) ?>"><?= sh_icon($driverIcon($driver), 19) ?></span>
      <div class="sh-aiprov__id">
        <h2 class="sh-aiprov__name"><?= e((string)$p['name']) ?></h2>
        <p class="sh-aiprov__type"><?= e(sh_ai_driver_label($driver)) ?><?= !$hasKey ? ' · no key' : '' ?></p>
      </div>
      <div class="sh-aiprov__flags">
        <span class="sh-statuspill <?= $on ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>"><?= $on ? 'Enabled' : 'Disabled' ?></span>
        <?php if ((int)$p['is_default'] === 1): ?><span class="sh-badge sh-badge--ok">Default</span><?php endif; ?>
        <?php if (!$hasKey): ?><span class="sh-badge sh-badge--warn">Key needed</span><?php endif; ?>
      </div>
    </header>

    <dl class="sh-aiprov__meta">
      <div class="sh-aiprov__meta-item sh-aiprov__meta-item--wide">
        <dt>Base URL</dt>
        <dd class="sh-break" title="<?= e($baseDisplay) ?>"><?= e($baseDisplay !== '' ? $baseDisplay : 'not set') ?></dd>
      </div>
      <div class="sh-aiprov__meta-item">
        <dt>Text model</dt>
        <dd class="sh-break"><?= e((string)($p['default_model'] ?: 'not set')) ?></dd>
      </div>
      <div class="sh-aiprov__meta-item">
        <dt>Image model</dt>
        <dd class="sh-break"><?= e((string)($p['default_image_model'] ?: '—')) ?></dd>
      </div>
      <div class="sh-aiprov__meta-item">
        <dt>Models</dt>
        <dd><?= $modelCount ?> catalogued</dd>
      </div>
      <div class="sh-aiprov__meta-item">
        <dt>Catalogue synced</dt>
        <dd><?= $p['models_synced_at'] ? e(sh_ai_ago((string)$p['models_synced_at'])) : 'never' ?></dd>
      </div>
    </dl>

    <div class="sh-aiprov__test" data-ai-test-state="<?= e($testCode) ?>">
      <div class="sh-aiprov__testhead">
        <span class="sh-badge <?= $tone === 'ok' ? 'sh-badge--ok' : ($tone === 'bad' ? 'sh-badge--bad' : ($tone === 'warn' ? 'sh-badge--warn' : 'sh-badge--muted')) ?>" data-ai-test-chip>
          <?= $testCode !== '' ? e(sh_ai_test_state_label($testCode)) : 'Not tested' ?>
        </span>
        <span class="sh-aiprov__testtime" data-ai-test-time><?= !empty($p['last_tested_at']) ? e(sh_ai_ago((string)$p['last_tested_at'])) : 'No test recorded' ?></span>
      </div>
      <p class="sh-aiprov__note sh-break" data-ai-test-note<?= empty($p['last_test_note']) ? ' hidden' : '' ?>><?= e((string)($p['last_test_note'] ?? '')) ?></p>
      <p class="sh-aiprov__next sh-break" data-ai-test-next<?= $nextAction === '' ? ' hidden' : '' ?>><?= e($nextAction) ?></p>
      <p class="sh-aiprov__live" data-ai-test-result="<?= (int)$p['id'] ?>" role="status" aria-live="polite" hidden></p>
    </div>

    <footer class="sh-aiprov__actions">
      <button class="sh-btn sh-btn--sm" type="button" data-ai-test data-provider="<?= (int)$p['id'] ?>" <?= $hasKey ? '' : 'disabled' ?> title="<?= $hasKey ? 'Runs a read-only provider request. It never generates billable content beyond one short probe.' : 'Save an API key first' ?>">
        <?= sh_icon('zap', 13) ?> <span data-ai-test-label>Test connection</span>
      </button>
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/providers.php?edit=' . (int)$p['id'])) ?>#edit"><?= sh_icon('pencil', 13) ?> Edit</a>
      <form method="post" style="display:contents"><?= sh_csrf_field() ?><input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon($on ? 'x-circle' : 'check-circle', 13) ?> <?= $on ? 'Disable' : 'Enable' ?></button></form>
      <?php if ((int)$p['is_default'] !== 1): ?>
        <form method="post" style="display:contents"><?= sh_csrf_field() ?><input type="hidden" name="form" value="default"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('star', 13) ?> Make default</button></form>
      <?php endif; ?>
    </footer>
    <?php if ($runTestId === (int)$p['id'] && $hasKey): ?>
      <span hidden data-ai-test-autorun="<?= (int)$p['id'] ?>"></span>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$pick = '';
foreach ($drivers as $key => $c) {
    $pick .= '<a class="sh-aiprov-pick" href="' . e(sh_url('admin/ai/providers.php?new=' . $key)) . '#edit">'
        . '<span class="sh-aiprov__mark" data-driver="' . e($key) . '">' . sh_icon($driverIcon($key), 18) . '</span>'
        . '<span class="sh-aiprov-pick__text"><strong>' . e($c::defaultLabel()) . '</strong>'
        . '<small>' . e($driverPitch[$key] ?? $c::helpText()) . '</small></span>'
        . '<span class="sh-aiprov-pick__go">' . sh_icon('chevron-right', 15) . '</span></a>';
}
echo sh_admin_modal('sh-aiprov-add', 'Add a provider', '<p class="sh-aiprov-pick__lead">Choose the API you want to connect. You can change the display name and models afterwards.</p>' . $pick, '', 'plus');
?>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
