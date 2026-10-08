<?php
require_once __DIR__ . '/_ai.php';

$errors = [];
$installReport = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'install') {
        $installReport = sh_ai_migrate();
        $failed = array_filter($installReport, static fn($r) => empty($r['ok']));
        sh_flash($failed ? 'error' : 'success', $failed
            ? 'Some migration steps failed. Check the report below.'
            : 'AI Auto Work installed. Add a provider under AI Auto Work → Providers to start generating.');
        if (!$failed) { sh_redirect('admin/ai/settings.php'); }
        $aiInstalled = sh_ai_installed(true);
    }

    if ($form === 'save') {
        if (!$aiInstalled) {
            sh_flash('error', 'Run the installer first.');
            sh_redirect('admin/ai/settings.php');
        }
        // Per-task routing: provider id + model, blank = use the default provider.
        $providerIds = array_map(static fn($r) => (int)$r['id'], sh_ai_providers_all());
        foreach (sh_ai_tasks() as $tk => $tdef) {
            $pid = sh_int($_POST['route_provider'][$tk] ?? 0);
            $model = mb_substr(trim((string)($_POST['route_model'][$tk] ?? '')), 0, 120);
            if ($pid > 0 && !in_array($pid, $providerIds, true)) { $pid = 0; }
            if ($pid > 0 && $model !== '' && !sh_ai_model_supports($pid, $model, $tdef['kind'])) {
                $errors['route_' . $tk] = $tdef['label'] . ': the model "' . $model . '" cannot produce ' . $tdef['kind'] . ' output on that provider.';
                continue;
            }
            if ($pid > 0 && $model !== '') { sh_ai_model_ensure($pid, $model); }
            sh_ai_setting_save('task_' . $tk . '_provider', $pid > 0 ? (string)$pid : '');
            sh_ai_setting_save('task_' . $tk . '_model', $pid > 0 ? $model : '');
        }

        // Fallback (text and image separately). Off unless explicitly enabled.
        sh_ai_setting_save('fallback_enabled', !empty($_POST['fallback_enabled']) ? '1' : '0');
        foreach (['fallback' => 'text', 'fallback_image' => 'image'] as $fk => $kind) {
            $pid = sh_int($_POST[$fk . '_provider'] ?? 0);
            $model = mb_substr(trim((string)($_POST[$fk . '_model'] ?? '')), 0, 120);
            if ($pid > 0 && !in_array($pid, $providerIds, true)) { $pid = 0; }
            if ($pid > 0 && $model !== '' && !sh_ai_model_supports($pid, $model, $kind)) {
                $errors[$fk] = 'Fallback ' . $kind . ' model "' . $model . '" is not ' . $kind . '-capable on that provider.';
                continue;
            }
            if ($pid > 0 && $model !== '') { sh_ai_model_ensure($pid, $model); }
            sh_ai_setting_save($fk . '_provider', $pid > 0 ? (string)$pid : '');
            sh_ai_setting_save($fk . '_model', $pid > 0 ? $model : '');
        }

        $temp = (float)sh_post('temperature');
        if ($temp < 0 || $temp > 2) { $errors['temperature'] = 'Temperature must be between 0 and 2.'; }
        else { sh_ai_setting_save('temperature', (string)$temp); }

        $max = sh_int($_POST['max_tokens'] ?? 1200);
        if ($max < 100 || $max > 8000) { $errors['max_tokens'] = 'Max tokens must be between 100 and 8000.'; }
        else { sh_ai_setting_save('max_tokens', (string)$max); }

        $batch = sh_int($_POST['batch_size'] ?? 5);
        sh_ai_setting_save('batch_size', (string)max(1, min(10, $batch)));
        sh_ai_setting_save('max_retries', (string)max(0, min(5, sh_int($_POST['max_retries'] ?? 2))));

        sh_ai_setting_save('language', mb_substr(sh_post('language') ?: 'English', 0, 40));
        sh_ai_setting_save('tone', mb_substr(sh_post('tone') ?: 'Professional', 0, 40));
        sh_ai_setting_save('seo_mode', !empty($_POST['seo_mode']) ? '1' : '0');
        sh_ai_setting_save('auto_save', !empty($_POST['auto_save']) ? '1' : '0');
        sh_ai_setting_save('auto_publish', !empty($_POST['auto_publish']) ? '1' : '0');

        if (!$errors) {
            sh_log_line('admin', 'AI settings updated by ' . ($admin['email'] ?? ''));
            sh_flash('success', 'AI settings saved.');
            sh_redirect('admin/ai/settings.php');
        }
        $aiConfig = sh_ai_config();
    }
}

$providers = $aiInstalled ? sh_ai_providers_all() : [];
$usable = $aiInstalled ? sh_ai_providers_usable() : [];
/** Options for a provider <select>. */
$providerOptions = static function (int $selected) use ($providers): string {
    $h = '<option value="0">Default provider</option>';
    foreach ($providers as $p) {
        $dis = (int)$p['status'] !== 1 || trim((string)$p['api_key']) === '';
        $h .= '<option value="' . (int)$p['id'] . '"' . ($selected === (int)$p['id'] ? ' selected' : '') . ($dis ? ' disabled' : '') . '>'
            . e((string)$p['name']) . ($dis ? ' (disabled)' : '') . '</option>';
    }
    return $h;
};

$adminPage = 'ai_settings';
$adminTitle = 'AI Settings';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('settings', 20) ?> AI Settings</h2>
    <p class="sh-aihead__sub">Generation defaults, per-task provider routing and fallback.</p>
  </div>
</div>

<?php sh_ai_subnav('settings'); ?>

<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if (!$aiInstalled): ?>
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Install AI Auto Work</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin-bottom:12px">
        This creates the AI tables and adds SEO columns to categories. It never deletes data, never
        duplicates a column that already exists, and is safe to run more than once.
      </p>
      <form method="post"><?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="install">
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Run installer</button>
      </form>
      <?php if ($installReport): ?>
        <div class="sh-tablewrap" style="margin-top:14px">
          <table class="sh-table">
            <thead><tr><th>Item</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody><?php foreach ($installReport as $r): ?>
              <tr><td><?= e((string)$r['item']) ?></td>
                <td><span class="sh-badge <?= $r['ok'] ? 'sh-badge--ok' : 'sh-badge--bad' ?>">
                  <?= $r['ok'] ? 'OK' : 'Failed' ?></span></td>
                <td class="sh-table__meta"><?= e((string)$r['note']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<div class="sh-aigrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('sliders', 17) ?> Generation defaults</h2></div>
    <div class="sh-panel__body">
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-temp">Temperature</label>
            <input class="sh-input" id="ai-temp" name="temperature" inputmode="decimal"
                   value="<?= e((string)$aiConfig['temperature']) ?>">
            <span class="sh-field__hint">0 = predictable, 1 = creative.</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-tokens">Max tokens</label>
            <input class="sh-input" id="ai-tokens" name="max_tokens" type="number" min="100" max="8000"
                   value="<?= e((string)$aiConfig['max_tokens']) ?>">
            <span class="sh-field__hint">Caps the cost of one generation.</span>
          </div>
        </div>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-lang">Default language</label>
            <input class="sh-input" id="ai-lang" name="language" value="<?= e($aiConfig['language']) ?>" placeholder="English">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-tone">Writing tone</label>
            <select class="sh-select" id="ai-tone" name="tone">
              <?php foreach (['Professional', 'Friendly', 'Persuasive', 'Technical', 'Casual', 'Luxury'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $aiConfig['tone'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="ai-batch">Bulk batch size</label>
            <input class="sh-input" id="ai-batch" name="batch_size" type="number" min="1" max="10"
                   value="<?= e((string)$aiConfig['batch_size']) ?>">
            <span class="sh-field__hint">Jobs processed per request (1–10).</span>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ai-retry">Retry limit</label>
            <input class="sh-input" id="ai-retry" name="max_retries" type="number" min="0" max="5"
                   value="<?= e((string)$aiConfig['max_retries']) ?>">
          </div>
        </div>

        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="seo_mode" value="1" <?= $aiConfig['seo_mode'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>SEO optimisation</span></label>
        <label class="sh-toggle" style="margin-bottom:10px">
          <input type="checkbox" name="auto_save" value="1" <?= $aiConfig['auto_save'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Auto save generated text to the product</span></label>
        <label class="sh-toggle" style="margin-bottom:14px">
          <input type="checkbox" name="auto_publish" value="1" <?= $aiConfig['auto_publish'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Allow blog posts to be published directly (default is draft)</span></label>

        <div class="sh-panel__head" style="padding-left:0;padding-right:0;margin-top:6px"><h2 class="sh-panel__title"><?= sh_icon('layout', 17) ?> Task routing</h2></div>
        <p class="sh-panel__note" style="margin:8px 0 4px">Choose which provider and model handles each task. Leave "Default provider" to use
          <strong><?= e($aiConfig['provider'] ?: 'the default provider') ?></strong>. A blank model uses that provider's default model.</p>
        <?php if (!$providers): ?>
          <p class="sh-panel__note"><a href="<?= e(sh_url('admin/ai/providers.php')) ?>">Add a provider</a> to enable routing.</p>
        <?php endif; ?>
        <div data-ai-routes>
        <?php foreach (sh_ai_tasks() as $tk => $tdef):
          $rp = (int)sh_ai_setting('task_' . $tk . '_provider', '0'); $rm = sh_ai_setting('task_' . $tk . '_model', ''); ?>
          <div class="sh-airoute">
            <div class="sh-airoute__task"><?= e($tdef['label']) ?><small><?= e($tdef['group']) ?> · <?= $tdef['kind'] === 'image' ? 'needs image output' : 'text' ?></small></div>
            <div class="sh-field" style="margin:0">
              <select class="sh-select" name="route_provider[<?= e($tk) ?>]" data-ai-route-provider data-kind="<?= e($tdef['kind']) ?>" data-target="rm-<?= e($tk) ?>"><?= $providerOptions($rp) ?></select>
            </div>
            <div class="sh-field" style="margin:0">
              <input class="sh-input" id="rm-<?= e($tk) ?>" name="route_model[<?= e($tk) ?>]" list="rm-<?= e($tk) ?>-list" value="<?= e($rm) ?>" placeholder="Provider default model" maxlength="120" <?= $rp === 0 ? 'disabled' : '' ?>>
              <datalist id="rm-<?= e($tk) ?>-list">
                <?php if ($rp > 0): foreach (sh_ai_models($rp, true) as $m): if ($tdef['kind'] === 'image' ? empty($m['output_image']) : empty($m['output_text'])) { continue; } ?>
                  <option value="<?= e((string)$m['model_id']) ?>"><?= e((string)$m['name']) ?></option>
                <?php endforeach; endif; ?>
              </datalist>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <div class="sh-panel__head" style="padding-left:0;padding-right:0;margin-top:14px"><h2 class="sh-panel__title"><?= sh_icon('rotate', 17) ?> Fallback</h2></div>
        <label class="sh-toggle" style="margin:10px 0">
          <input type="checkbox" name="fallback_enabled" value="1" <?= $aiConfig['fallback_enabled'] ? 'checked' : '' ?>>
          <span class="sh-toggle__track"></span><span>Retry on a fallback provider after a timeout, rate limit or provider error</span></label>
        <p class="sh-panel__note" style="margin-bottom:8px">Off means a failure is reported as-is — providers are never switched silently. Every attempt is logged with the provider and model actually used.</p>
        <?php foreach (['fallback' => ['Text fallback', 'text'], 'fallback_image' => ['Image fallback', 'image']] as $fk => [$lbl, $kind]):
          $fp = (int)sh_ai_setting($fk . '_provider', '0'); $fm = sh_ai_setting($fk . '_model', ''); ?>
          <div class="sh-airoute">
            <div class="sh-airoute__task"><?= e($lbl) ?><small>used only when fallback is on</small></div>
            <div class="sh-field" style="margin:0">
              <select class="sh-select" name="<?= e($fk) ?>_provider" data-ai-route-provider data-kind="<?= e($kind) ?>" data-target="fm-<?= e($fk) ?>">
                <?= str_replace('>Default provider<', '>None<', $providerOptions($fp)) ?></select>
            </div>
            <div class="sh-field" style="margin:0">
              <input class="sh-input" id="fm-<?= e($fk) ?>" name="<?= e($fk) ?>_model" list="fm-<?= e($fk) ?>-list" value="<?= e($fm) ?>" placeholder="Provider default model" maxlength="120" <?= $fp === 0 ? 'disabled' : '' ?>>
              <datalist id="fm-<?= e($fk) ?>-list">
                <?php if ($fp > 0): foreach (sh_ai_models($fp, true) as $m): if ($kind === 'image' ? empty($m['output_image']) : empty($m['output_text'])) { continue; } ?>
                  <option value="<?= e((string)$m['model_id']) ?>"><?= e((string)$m['name']) ?></option>
                <?php endforeach; endif; ?>
              </datalist>
            </div>
          </div>
        <?php endforeach; ?>
        <div style="height:14px"></div>

        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save AI settings</button>
      </form>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px;min-width:0">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> Providers</h2></div>
      <div class="sh-panel__body">
        <?php if (!$providers): ?>
          <p class="sh-panel__note">No providers configured yet.</p>
        <?php else: foreach ($providers as $p): ?>
          <div class="sh-airoute" style="grid-template-columns:minmax(0,1fr) auto">
            <div class="sh-airoute__task sh-break"><?= e((string)$p['name']) ?>
              <small><?= e(sh_ai_driver_label((string)$p['driver'])) ?> · <?= e((string)($p['default_model'] ?: 'no model')) ?></small></div>
            <div class="sh-paycard__tags">
              <?php if ((int)$p['is_default'] === 1): ?><span class="sh-badge sh-badge--ok">Default</span><?php endif; ?>
              <span class="sh-statuspill <?= (int)$p['status'] === 1 && trim((string)$p['api_key']) !== '' ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
                <?= (int)$p['status'] !== 1 ? 'Disabled' : (trim((string)$p['api_key']) === '' ? 'No key' : 'Ready') ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" style="margin-top:10px" href="<?= e(sh_url('admin/ai/providers.php')) ?>"><?= sh_icon('settings', 13) ?> Manage providers</a>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Cost control</h2></div>
      <div class="sh-panel__body">
        <p class="sh-panel__note">
          Bulk jobs are queued and processed <strong><?= (int)$aiConfig['batch_size'] ?> at a time</strong>,
          never all at once. A single batch is capped at <?= (int)SH_AI_MAX_BATCH ?> jobs, each generation is
          capped at <?= (int)$aiConfig['max_tokens'] ?> tokens, and one admin is limited to 30 generations
          per minute. Every call is recorded with its token usage in
          <a href="<?= e(sh_url('admin/ai/history.php')) ?>">AI History</a>.
        </p>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Migration</h2></div>
      <div class="sh-panel__body">
        <p class="sh-panel__note" style="margin-bottom:10px">Safe to re-run after an update.</p>
        <form method="post"><?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="install">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Re-run migration</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
