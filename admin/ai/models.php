<?php
require_once __DIR__ . '/_ai.php';

$errors = [];
$providers = $aiInstalled ? sh_ai_providers_all() : [];
$pid = sh_int($_GET['provider'] ?? 0);
if ($pid === 0 && $providers) { $pid = (int)($aiConfig['provider_id'] ?: $providers[0]['id']); }
$provider = $pid > 0 ? sh_ai_provider_row($pid) : null;
$fCap = sh_get('cap');          // '', text, image
$q = trim(sh_get('q'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (!$aiInstalled) { sh_flash('error', 'Run the installer in AI Settings first.'); sh_redirect('admin/ai/models.php'); }
    $form = sh_post('form');
    $pid = sh_int($_POST['provider_id'] ?? $pid);
    $provider = sh_ai_provider_row($pid);
    if ($provider === null) { sh_flash('error', 'Unknown provider.'); sh_redirect('admin/ai/models.php'); }
    $back = 'admin/ai/models.php?provider=' . $pid;

    if ($form === 'sync') {
        $res = sh_ai_models_sync($pid);
        sh_flash(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok'])
            ? (int)$res['count'] . ' models loaded from ' . $provider['name'] . '.'
            : (string)$res['error']);
        sh_redirect($back);
    }

    if ($form === 'add') {
        $mid = trim(sh_post('model_id'));
        if ($mid === '' || mb_strlen($mid) > 120) { $errors[] = 'Enter a model ID.'; }
        else {
            $adapter = sh_ai_adapter($provider);
            $caps = $adapter ? $adapter->guessCapabilities($mid) : [];
            $caps = [
                'input_text'   => 1,
                'input_image'  => !empty($_POST['input_image']) ? 1 : 0,
                'output_text'  => !empty($_POST['output_text']) ? 1 : 0,
                'output_image' => !empty($_POST['output_image']) ? 1 : 0,
            ] + $caps;
            if ($caps['output_image'] && $adapter && !$adapter->supportsImages()) {
                $errors[] = sh_ai_driver_label((string)$provider['driver']) . ' has no image-generation API, so a model here cannot be marked as image output.';
            } elseif (!$caps['output_text'] && !$caps['output_image']) {
                $errors[] = 'A model must produce text or images.';
            } else {
                sh_ai_model_upsert($pid, ['id' => $mid, 'name' => trim(sh_post('name')) ?: $mid] + $caps, 'manual');
                sh_flash('success', 'Model "' . $mid . '" added.');
                sh_redirect($back);
            }
        }
    }

    if ($form === 'row') {
        $id = sh_int($_POST['id'] ?? 0);
        $m = sh_one('SELECT * FROM ai_models WHERE id = ? AND provider_id = ? LIMIT 1', [$id, $pid]);
        if ($m !== null) {
            $do = sh_post('do');
            if ($do === 'toggle') {
                sh_update('ai_models', ['status' => (int)$m['status'] === 1 ? 0 : 1], 'id = ?', [$id]);
            } elseif ($do === 'default_text' && !empty($m['output_text'])) {
                sh_update('ai_providers', ['default_model' => $m['model_id']], 'id = ?', [$pid]);
                sh_query('UPDATE ai_models SET is_default = 0 WHERE provider_id = ? AND output_text = 1 AND output_image = 0', [$pid]);
                sh_query('UPDATE ai_models SET is_default = 1, status = 1 WHERE id = ?', [$id]);
            } elseif ($do === 'default_image' && !empty($m['output_image'])) {
                sh_update('ai_providers', ['default_image_model' => $m['model_id']], 'id = ?', [$pid]);
                sh_query('UPDATE ai_models SET status = 1 WHERE id = ?', [$id]);
            } elseif ($do === 'delete') {
                sh_query('DELETE FROM ai_models WHERE id = ?', [$id]);
            } elseif ($do === 'caps') {
                $adapter = sh_ai_adapter($provider);
                $oi = !empty($_POST['output_image']) && $adapter && $adapter->supportsImages() ? 1 : 0;
                sh_update('ai_models', [
                    'input_image' => !empty($_POST['input_image']) ? 1 : 0,
                    'output_text' => !empty($_POST['output_text']) ? 1 : 0,
                    'output_image' => $oi,
                    'source' => 'manual',
                ], 'id = ?', [$id]);
            }
            sh_ai_providers_all(true);
        }
        sh_redirect($back . ($q !== '' ? '&q=' . rawurlencode($q) : '') . ($fCap !== '' ? '&cap=' . rawurlencode($fCap) : ''));
    }
}

$models = $provider ? sh_ai_models($pid) : [];
if ($fCap === 'image') { $models = array_values(array_filter($models, static fn($m) => !empty($m['output_image']))); }
if ($fCap === 'text')  { $models = array_values(array_filter($models, static fn($m) => !empty($m['output_text']))); }
if ($q !== '') {
    $models = array_values(array_filter($models, static fn($m) => stripos($m['model_id'] . ' ' . $m['name'], $q) !== false));
}
$adapterOk = $provider ? sh_ai_adapter($provider) : null;

$adminPage = 'ai_models';
$adminTitle = 'AI Models';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('list', 20) ?> AI Models</h2>
    <p class="sh-aihead__sub">Catalogue per provider with capability flags. Image tasks only run on models marked as image output.</p>
  </div>
</div>

<?php sh_ai_subnav('models'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($aiInstalled && !$providers): ?>
  <div class="sh-panel"><div class="sh-panel__body sh-panel__note">
    No providers yet. <a href="<?= e(sh_url('admin/ai/providers.php')) ?>">Add a provider</a> first.</div></div>
<?php elseif ($aiInstalled && $provider !== null): ?>

<div class="sh-panel">
  <div class="sh-panel__body">
    <form method="get" class="sh-aimodel-filter">
      <label class="sh-field__label" for="md-provider" style="margin:0">Provider</label>
      <select class="sh-select" id="md-provider" name="provider" style="max-width:260px" onchange="this.form.submit()">
        <?php foreach ($providers as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pid ? 'selected' : '' ?>><?= e((string)$p['name']) ?> (<?= e(sh_ai_driver_label((string)$p['driver'])) ?>)</option>
        <?php endforeach; ?>
      </select>
      <select class="sh-select" name="cap" style="max-width:170px" onchange="this.form.submit()">
        <option value="">All capabilities</option>
        <option value="text" <?= $fCap === 'text' ? 'selected' : '' ?>>Text output</option>
        <option value="image" <?= $fCap === 'image' ? 'selected' : '' ?>>Image output</option>
      </select>
      <input class="sh-input" name="q" value="<?= e($q) ?>" placeholder="Search model ID…" style="max-width:220px">
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('search', 13) ?> Filter</button>
    </form>

    <div class="sh-actions" style="margin-top:12px">
      <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="sync"><input type="hidden" name="provider_id" value="<?= $pid ?>">
        <button class="sh-btn sh-btn--sm" type="submit" <?= trim((string)$provider['api_key']) !== '' || $provider['driver'] === 'openrouter' ? '' : 'disabled' ?>>
          <?= sh_icon('download', 13) ?> Load models from <?= e((string)$provider['name']) ?></button></form>
      <span class="sh-table__meta">
        <?= $provider['models_synced_at'] ? 'Last loaded ' . e(sh_ai_ago((string)$provider['models_synced_at'])) . '.' : 'Not loaded yet — the list below is the starter set.' ?>
        Default text: <strong><?= e((string)($provider['default_model'] ?: '—')) ?></strong> ·
        Default image: <strong><?= e((string)($provider['default_image_model'] ?: '—')) ?></strong>
      </span>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> <?= count($models) ?> model<?= count($models) === 1 ? '' : 's' ?></h2></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Model ID</th><th>Name</th><th>Input</th><th>Output</th><th>Context</th><th>Price / 1M</th><th>Enabled</th><th>Default</th><th></th></tr></thead>
      <tbody>
      <?php if (!$models): ?>
        <tr class="sh-table--empty"><td colspan="9">No models match. Load the catalogue or add one manually below.</td></tr>
      <?php endif; ?>
      <?php foreach ($models as $m):
        $isText = (string)$provider['default_model'] === (string)$m['model_id'];
        $isImg = (string)$provider['default_image_model'] === (string)$m['model_id'];
        $pp = $m['prompt_price'] !== null ? '$' . rtrim(rtrim(number_format((float)$m['prompt_price'] * 1000000, 4, '.', ''), '0'), '.') : null;
        $cp = $m['completion_price'] !== null ? '$' . rtrim(rtrim(number_format((float)$m['completion_price'] * 1000000, 4, '.', ''), '0'), '.') : null;
      ?>
        <tr>
          <td class="sh-break"><code><?= e((string)$m['model_id']) ?></code></td>
          <td class="sh-break"><?= e((string)$m['name']) ?><?php if ($m['source'] === 'manual'): ?> <span class="sh-badge sh-badge--muted">manual</span><?php endif; ?></td>
          <td><span class="sh-aicap"><span class="<?= $m['input_text'] ? 'on' : '' ?>">TEXT</span><span class="<?= $m['input_image'] ? 'on' : '' ?>">IMAGE</span></span></td>
          <td><span class="sh-aicap"><span class="<?= $m['output_text'] ? 'on' : '' ?>">TEXT</span><span class="<?= $m['output_image'] ? 'on' : '' ?>">IMAGE</span></span></td>
          <td class="sh-table__meta"><?= $m['context_length'] ? number_format((int)$m['context_length']) : '—' ?></td>
          <td class="sh-table__meta"><?= $pp !== null ? e($pp) . ' in / ' . e((string)$cp) . ' out' : '—' ?></td>
          <td>
            <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="row"><input type="hidden" name="do" value="toggle">
              <input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="sh-statuspill <?= (int)$m['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>" type="submit" style="border:0;cursor:pointer"><?= (int)$m['status'] === 1 ? 'On' : 'Off' ?></button>
            </form>
          </td>
          <td>
            <div class="sh-actions" style="gap:4px">
              <?php if ($isText): ?><span class="sh-badge sh-badge--ok">Text</span>
              <?php elseif ($m['output_text']): ?>
                <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="row"><input type="hidden" name="do" value="default_text">
                  <input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Set text</button></form>
              <?php endif; ?>
              <?php if ($isImg): ?><span class="sh-badge sh-badge--ok">Image</span>
              <?php elseif ($m['output_image']): ?>
                <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="row"><input type="hidden" name="do" value="default_image">
                  <input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Set image</button></form>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <details class="sh-aimodel-edit">
              <summary class="sh-btn sh-btn--sm sh-btn--ghost" style="list-style:none">Edit</summary>
              <form method="post" style="margin-top:8px;display:grid;gap:6px">
                <?= sh_csrf_field() ?><input type="hidden" name="form" value="row"><input type="hidden" name="do" value="caps">
                <input type="hidden" name="provider_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <label class="sh-check"><input type="checkbox" name="input_image" value="1" <?= $m['input_image'] ? 'checked' : '' ?>><span>Accepts images</span></label>
                <label class="sh-check"><input type="checkbox" name="output_text" value="1" <?= $m['output_text'] ? 'checked' : '' ?>><span>Outputs text</span></label>
                <label class="sh-check"><input type="checkbox" name="output_image" value="1" <?= $m['output_image'] ? 'checked' : '' ?> <?= $adapterOk && $adapterOk->supportsImages() ? '' : 'disabled' ?>><span>Outputs images</span></label>
                <div class="sh-actions"><button class="sh-btn sh-btn--sm" type="submit">Save</button>
                  <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" name="do" value="delete" formnovalidate>Remove</button></div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('plus', 17) ?> Add a model manually</h2></div>
  <div class="sh-panel__body">
    <form method="post" novalidate>
      <?= sh_csrf_field() ?><input type="hidden" name="form" value="add"><input type="hidden" name="provider_id" value="<?= $pid ?>">
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="md-id">Model ID</label>
          <input class="sh-input" id="md-id" name="model_id" maxlength="120" required placeholder="<?= e($provider['driver'] === 'openrouter' ? 'vendor/model-name' : 'model-id') ?>"></div>
        <div class="sh-field"><label class="sh-field__label" for="md-name">Display name (optional)</label>
          <input class="sh-input" id="md-name" name="name" maxlength="160"></div>
      </div>
      <div class="sh-actions" style="margin-bottom:12px">
        <label class="sh-check"><input type="checkbox" name="output_text" value="1" checked><span>Outputs text</span></label>
        <label class="sh-check"><input type="checkbox" name="input_image" value="1"><span>Accepts images</span></label>
        <label class="sh-check"><input type="checkbox" name="output_image" value="1" <?= $adapterOk && $adapterOk->supportsImages() ? '' : 'disabled' ?>><span>Outputs images</span></label>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('plus', 15) ?> Add model</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
