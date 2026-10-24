<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-tools.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/ai-support.php';
require_once SH_ROOT . '/ai/AIManager.php';

sh_session_start();
$admin = sh_require_admin();
sh_perms_schema_ensure();
sh_require_perm('support.manage');
if (!sh_support_schema_ensure()) {
    http_response_code(503);
    $adminTitle = 'Support Settings';
    $adminPage = 'support_settings';
    require __DIR__ . '/_layout.php';
    echo '<div class="sh-panel"><div class="sh-panel__body"><div class="sh-alert sh-alert--error"><span>Support tables are not available. Run the additive support migration in phpMyAdmin.</span></div></div></div>';
    require __DIR__ . '/_footer.php';
    exit;
}

$adminId = (int)$admin['id'];
$canConfigureAi = sh_admin_can('ai.use') && sh_ai_installed();
$aiInstalled = sh_ai_installed();

function sh_support_settings_ai_audit_snapshot(): array
{
    return [
        'enabled' => sh_support_setting('ai_enabled', '0'),
        'provider_id' => sh_ai_setting('support_chat_provider_id', '0'),
        'model' => sh_ai_setting('support_chat_model', ''),
        'request_timeout' => sh_support_setting('request_timeout', '15'),
        'output_tokens' => sh_support_setting('output_tokens', '350'),
        'daily_limit' => sh_support_setting('daily_limit', '100'),
        'customer_daily_limit' => sh_support_setting('customer_daily_limit', '15'),
        'context_limit' => sh_support_setting('context_limit', '8'),
    ];
}

function sh_support_settings_general_audit_snapshot(): array
{
    $snapshot = [];
    foreach (['enabled', 'handoff_enabled', 'hours', 'contact_email', 'contact_phone', 'personality', 'instructions', 'retention_days'] as $key) {
        $snapshot[$key] = $key === 'retention_days'
            ? (string)sh_support_retention_days()
            : sh_support_setting($key, '');
    }
    foreach (['delivery', 'payment', 'returns'] as $kind) {
        foreach (['en', 'bn', 'banglish'] as $language) {
            $key = 'policy_' . $kind . '_' . $language;
            $snapshot[$key] = sh_support_setting($key, '');
        }
    }
    return $snapshot;
}

function sh_support_settings_audit_changed_fields(array $before, array $after): array
{
    $changed = [];
    foreach ($after as $field => $value) {
        if (!array_key_exists($field, $before) || $before[$field] !== $value) { $changed[] = (string)$field; }
    }
    return $changed;
}

function sh_support_settings_audit_change(array $before, array $after): void
{
    $changed = sh_support_settings_audit_changed_fields($before, $after);
    if ($changed) {
        // Record only field names. Policy/instruction/contact contents may
        // include information that should not be copied into an audit log.
        sh_audit('support_settings_changed', 'support', null, 'Customer support settings', null, ['changed_fields' => $changed]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        sh_flash('error', 'The settings form is too large. Shorten the FAQ or policy text and try again.');
        sh_redirect('admin/support-settings.php');
    }
    sh_csrf_require();
    $form = sh_post('form');

    if (in_array($form, ['save_settings', 'save_ai_settings'], true)) {
        $configureAi = $form === 'save_ai_settings';
        if ($configureAi && !$canConfigureAi) { sh_admin_deny('Your role needs the AI tools permission to configure support AI.'); }
        $oldGeneralState = sh_support_settings_general_audit_snapshot();
        $oldAiState = $configureAi ? sh_support_settings_ai_audit_snapshot() : null;
        $result = sh_support_settings_save($adminId, $_POST, $configureAi);
        sh_support_settings_audit_change($oldGeneralState, sh_support_settings_general_audit_snapshot());
        if ($configureAi) {
            $newAiState = sh_support_settings_ai_audit_snapshot();
            if ($oldAiState !== $newAiState) { sh_audit('settings_changed', 'settings', null, 'customer_support_ai_configuration', $oldAiState, $newAiState); }
        }
        if (!empty($result['ok'])) {
            sh_flash('success', $configureAi ? 'Customer-support AI settings saved. Live messages will attempt the selected provider/model; the manual text test is optional.' : 'Customer-support settings saved.');
        } else { sh_flash('error', (string)($result['error'] ?? 'The support settings could not be saved.')); }
        sh_redirect('admin/support-settings.php' . ($configureAi ? '#ai' : '#settings'));
    }

    if ($form === 'test_provider') {
        if (!$canConfigureAi) { sh_admin_deny('Your role needs the AI tools permission to configure or test support AI.'); }
        $oldGeneralState = sh_support_settings_general_audit_snapshot();
        $oldAiState = sh_support_settings_ai_audit_snapshot();
        $result = sh_support_settings_save($adminId, $_POST, true);
        sh_support_settings_audit_change($oldGeneralState, sh_support_settings_general_audit_snapshot());
        $newAiState = sh_support_settings_ai_audit_snapshot();
        if ($oldAiState !== $newAiState) { sh_audit('settings_changed', 'settings', null, 'customer_support_ai_configuration', $oldAiState, $newAiState); }
        if (empty($result['ok'])) {
            sh_flash('error', (string)($result['error'] ?? 'Support settings could not be saved.'));
            sh_redirect('admin/support-settings.php#ai');
        }
        $selectedProviderId = max(0, sh_int($_POST['provider_id'] ?? 0));
        $provider = $selectedProviderId > 0 ? sh_ai_provider_row($selectedProviderId) : sh_ai_default_provider_row();
        $model = trim((string)($_POST['model'] ?? ''));
        if ($provider !== null && $model === '') { $model = trim((string)($provider['default_model'] ?? '')); }
        if ($provider === null || (int)($provider['id'] ?? 0) <= 0) {
            sh_flash('error', 'Select an enabled, configured provider with a text-capable model before testing.');
            sh_redirect('admin/support-settings.php#ai');
        }
        $test = sh_support_provider_test((int)$provider['id'], $model);
        sh_audit('support_provider_tested', 'support', null, 'Customer support provider text test', null, [
            'provider_id' => (int)$provider['id'], 'model' => $model,
            'ok' => !empty($test['ok']), 'code' => (string)($test['code'] ?? 'failed'),
        ]);
        $message = (string)($test['message'] ?? 'Provider test did not complete.');
        if (!empty($test['next'])) { $message .= ' Next: ' . (string)$test['next']; }
        sh_flash(!empty($test['ok']) ? 'success' : 'error', $message);
        sh_redirect('admin/support-settings.php#ai');
    }

    if ($form === 'faq_save') {
        $faqId = max(0, sh_int($_POST['faq_id'] ?? 0));
        $result = sh_support_faq_save($faqId, $adminId, $_POST);
        if (!empty($result['ok'])) {
            sh_audit('support_faq_saved', 'support', (int)$result['id'], 'FAQ #' . (int)$result['id'], null, [
                'enabled' => !empty($_POST['status']),
            ]);
            sh_flash('success', 'FAQ saved.');
        } else { sh_flash('error', (string)($result['error'] ?? 'The FAQ could not be saved.')); }
        sh_redirect('admin/support-settings.php?faq=' . (int)($result['id'] ?? $faqId) . '#faq-edit');
    }

    if ($form === 'faq_toggle') {
        $faqId = max(0, sh_int($_POST['faq_id'] ?? 0));
        $status = !empty($_POST['status']) ? 1 : 0;
        if ($faqId <= 0 || sh_support_faq_get($faqId) === null) {
            sh_flash('error', 'FAQ not found. Refresh the page and try again.');
            sh_redirect('admin/support-settings.php#faqs');
        }
        try {
            sh_update('support_faqs', ['status' => $status, 'updated_by' => $adminId, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$faqId]);
            sh_audit('support_faq_toggled', 'support', $faqId, 'FAQ #' . $faqId, null, ['enabled' => (bool)$status]);
            sh_flash('success', $status ? 'FAQ enabled.' : 'FAQ disabled.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'ai-support-faq-toggle');
            sh_flash('error', 'The FAQ status could not be changed.');
        }
        sh_redirect('admin/support-settings.php#faqs');
    }

    if ($form === 'faq_delete') {
        $faqId = max(0, sh_int($_POST['faq_id'] ?? 0));
        $result = sh_support_faq_delete($faqId);
        if (!empty($result['ok'])) { sh_audit('support_faq_deleted', 'support', $faqId, 'FAQ #' . $faqId); }
        sh_flash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok']) ? 'FAQ deleted.' : (string)($result['error'] ?? 'The FAQ could not be deleted.'));
        sh_redirect('admin/support-settings.php#faqs');
    }

    sh_flash('error', 'Unknown support settings action.');
    sh_redirect('admin/support-settings.php');
}

$settings = [
    'enabled' => sh_support_setting('enabled', '1'),
    'ai_enabled' => sh_support_setting('ai_enabled', '0'),
    'handoff_enabled' => sh_support_setting('handoff_enabled', '1'),
    'hours' => sh_support_setting('hours', ''),
    'contact_email' => sh_support_setting('contact_email', (string)sh_setting('contact_email', '')),
    'contact_phone' => sh_support_setting('contact_phone', (string)sh_setting('contact_phone', '')),
    'personality' => sh_support_setting('personality', 'Friendly, clear and professional.'),
    'instructions' => sh_support_setting('instructions', ''),
    'retention_days' => sh_support_retention_days(),
    'request_timeout' => (int)sh_support_setting('request_timeout', '15'),
    'output_tokens' => (int)sh_support_setting('output_tokens', '350'),
    'daily_limit' => (int)sh_support_setting('daily_limit', '100'),
    'customer_daily_limit' => (int)sh_support_setting('customer_daily_limit', '15'),
    'context_limit' => (int)sh_support_setting('context_limit', '8'),
];
foreach (['delivery', 'payment', 'returns'] as $kind) {
    foreach (['en', 'bn', 'banglish'] as $lang) {
        $key = 'policy_' . $kind . '_' . $lang;
        $settings[$key] = sh_support_setting($key, '');
    }
}

$providers = [];
$providerRows = $aiInstalled ? sh_ai_providers_all() : [];
foreach ($providerRows as $row) {
    if ((int)($row['status'] ?? 0) !== 1 || trim((string)($row['api_key'] ?? '')) === '') { continue; }
    $driver = sh_ai_drivers()[(string)($row['driver'] ?? '')] ?? null;
    if ($driver === null) { continue; }
    $providers[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'driver' => (string)$row['driver'],
        'secure' => sh_support_provider_endpoint_secure($row),
        'default_model' => (string)($row['default_model'] ?? ''),
        'is_default' => (int)($row['is_default'] ?? 0) === 1,
    ];
}
$savedProviderId = (int)sh_ai_setting('support_chat_provider_id', '0');
$defaultProvider = sh_ai_default_provider_row();
$selectedProvider = $savedProviderId > 0 ? sh_ai_provider_row($savedProviderId) : $defaultProvider;
$selectedProviderId = $selectedProvider !== null ? (int)$selectedProvider['id'] : 0;
$savedModel = sh_ai_setting('support_chat_model', '');
$aiState = sh_support_ai_state();
$aiStateMessage = !empty($aiState['available'])
    ? 'AI is enabled. Customer messages attempt ' . (string)$aiState['provider'] . ' / ' . (string)$aiState['model'] . '. Run the selected-model text test to verify actual inference.'
    : match ((string)($aiState['reason'] ?? '')) {
        'disabled' => 'AI replies are disabled. Customer messages are not sent to a model until this is enabled and saved.',
        'ai_module_missing' => 'AI Auto Work is not installed; install it before configuring a provider.',
        'provider_missing' => 'The configured provider ID no longer exists. Select an enabled provider.',
        'default_provider_missing' => 'No enabled default provider with a saved API key is available.',
        'provider_disabled' => 'The selected provider is disabled. Enable it in AI Providers before using support AI.',
        'missing_key' => 'No API key is saved for the selected provider.',
        'credential_decryption_failed' => 'The API key is saved but cannot be decrypted. Check server encryption prerequisites and re-save the key.',
        'https_required' => 'The selected provider endpoint must use HTTPS. No inference request was sent.',
        'model_unavailable' => 'The selected model is missing, disabled or not marked as text-capable. No inference request was sent.',
        default => 'Support AI configuration could not be read. Check the server log and run the selected-model text test after correcting the configuration.',
    };
$testCode = !empty($aiState['test_current']) ? (string)($aiState['test_code'] ?? '') : '';
$testTone = $testCode !== '' ? sh_ai_test_state_tone($testCode) : 'muted';
$testAt = $testCode !== '' ? sh_ai_setting('support_chat_test_at', '') : '';
$testMessage = $testCode !== '' ? sh_ai_setting('support_chat_test_message', '') : '';
$lastLiveDiagnostic = $aiInstalled ? sh_support_setting('last_diagnostic', '') : '';
$faqRows = sh_all('SELECT * FROM support_faqs ORDER BY status DESC, sort_order ASC, id ASC');
$editFaqId = max(0, sh_int($_GET['faq'] ?? 0));
$editFaq = $editFaqId > 0 ? sh_support_faq_get($editFaqId) : null;
$faqForm = $editFaq ?? ['question_en' => '', 'question_bn' => '', 'question_banglish' => '', 'answer_en' => '', 'answer_bn' => '', 'answer_banglish' => '', 'keywords' => '', 'status' => 1, 'sort_order' => 0];

$adminPage = 'support_settings';
$adminTitle = 'Support Settings & FAQs';
$adminStyles = ['assets/css/support-admin.css'];
$adminScripts = ['assets/js/support-settings.js'];
require __DIR__ . '/_layout.php';
?>
<div class="sh-support-settings" data-support-settings>
  <div class="sh-support-admin__intro">
    <div>
      <p class="sh-support-admin__eyebrow">Customer support configuration</p>
      <h2>Settings, AI guardrails &amp; FAQs</h2>
      <p>Keep contact and policy answers aligned with the live store. AI attempts only the configured existing provider/model; manual tests are diagnostic, and messages/orders remain read-only.</p>
    </div>
    <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/support.php')) ?>"><?= sh_icon('message', 14) ?> Open inbox</a>
  </div>

  <div class="sh-support-settings__alert <?= $settings['enabled'] === '1' ? 'is-on' : 'is-off' ?>">
    <strong><?= $settings['enabled'] === '1' ? 'Customer chat is enabled.' : 'Customer chat is disabled.' ?></strong>
    <span><?= e($aiStateMessage) ?></span>
  </div>

  <section class="sh-panel" id="settings">
    <div class="sh-panel__head"><h3>Customer chat and business details</h3></div>
    <div class="sh-panel__body">
      <form method="post" class="sh-support-settings__form">
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="save_settings">
        <input type="hidden" name="enabled" value="0">
        <label class="sh-support-settings__toggle"><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] === '1' ? 'checked' : '' ?>><span><strong>Enable customer chat</strong><small>Turning this off does not delete saved conversations. The existing contact form remains available.</small></span></label>
        <input type="hidden" name="handoff_enabled" value="0">
        <label class="sh-support-settings__toggle"><input type="checkbox" name="handoff_enabled" value="1" <?= $settings['handoff_enabled'] === '1' ? 'checked' : '' ?>><span><strong>Allow human-support handoff</strong><small>Requests appear in the authorized inbox. This is not a live-staff or response-time promise.</small></span></label>

        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="support-hours">Support hours shown to customers</label><input class="sh-input" id="support-hours" name="hours" maxlength="120" value="<?= e($settings['hours']) ?>" placeholder="Leave blank if not published"></div>
          <div class="sh-field"><label class="sh-field__label" for="support-email">Support email</label><input class="sh-input" id="support-email" name="contact_email" type="email" maxlength="254" value="<?= e($settings['contact_email']) ?>" placeholder="Uses store contact email when blank"></div>
          <div class="sh-field"><label class="sh-field__label" for="support-phone">Support phone</label><input class="sh-input" id="support-phone" name="contact_phone" maxlength="40" value="<?= e($settings['contact_phone']) ?>" placeholder="Uses store contact phone when blank"></div>
          <div class="sh-field"><label class="sh-field__label" for="support-retention">Resolved conversation retention (days)</label><input class="sh-input" id="support-retention" name="retention_days" type="number" min="30" max="3650" value="<?= (int)$settings['retention_days'] ?>"><small class="sh-formcard__note">At most 200 old resolved threads are pruned per daily visit. Open or pending threads are never pruned.</small></div>
        </div>

        <div class="sh-field"><label class="sh-field__label" for="support-personality">Assistant tone (style only)</label><textarea class="sh-textarea" id="support-personality" name="personality" rows="2" maxlength="500"><?= e($settings['personality']) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="support-instructions">Additional store instructions</label><textarea class="sh-textarea" id="support-instructions" name="instructions" rows="4" maxlength="2500" placeholder="Do not enter credentials or private customer information."><?= e($settings['instructions']) ?></textarea><small class="sh-formcard__note">Instructions cannot override safety rules. Product/order data and customer messages are treated as untrusted content.</small></div>

        <h4 class="sh-support-settings__subhead">Verified policy text (optional)</h4>
        <p class="sh-formcard__note">If left blank, the system uses the enabled FAQs and the existing Help Centre facts. It will not invent delivery, payment or return rules.</p>
        <?php foreach (['delivery' => 'Delivery', 'payment' => 'Payment', 'returns' => 'Returns & replacements'] as $kind => $label): ?>
          <fieldset class="sh-support-settings__policy"><legend><?= e($label) ?></legend>
            <div class="sh-field"><label class="sh-field__label" for="policy-<?= e($kind) ?>-en">English</label><textarea class="sh-textarea" id="policy-<?= e($kind) ?>-en" name="policy_<?= e($kind) ?>_en" rows="3" maxlength="2500"><?= e($settings['policy_' . $kind . '_en']) ?></textarea></div>
            <div class="sh-field"><label class="sh-field__label" for="policy-<?= e($kind) ?>-bn">বাংলা</label><textarea class="sh-textarea" id="policy-<?= e($kind) ?>-bn" name="policy_<?= e($kind) ?>_bn" rows="3" maxlength="2500"><?= e($settings['policy_' . $kind . '_bn']) ?></textarea></div>
            <div class="sh-field"><label class="sh-field__label" for="policy-<?= e($kind) ?>-banglish">Banglish</label><textarea class="sh-textarea" id="policy-<?= e($kind) ?>-banglish" name="policy_<?= e($kind) ?>_banglish" rows="3" maxlength="2500"><?= e($settings['policy_' . $kind . '_banglish']) ?></textarea></div>
          </fieldset>
        <?php endforeach; ?>

        <div class="sh-support-settings__ai" id="ai">
          <h4 class="sh-support-settings__subhead">Existing AI provider and usage limits</h4>
          <?php if (!$aiInstalled): ?>
            <div class="sh-alert sh-alert--info"><span>AI Auto Work is not installed. Customer messages can be saved, but ordinary replies require a configured model. The contact form remains available; install the existing AI module to configure support AI.</span></div>
          <?php elseif (!$canConfigureAi): ?>
            <div class="sh-alert sh-alert--info"><span>Your role can manage conversations and FAQs but does not have the AI tools permission. Ask the store owner to configure and test the provider.</span></div>
          <?php else: ?>
            <input type="hidden" name="ai_enabled" value="0">
            <label class="sh-support-settings__toggle"><input type="checkbox" name="ai_enabled" value="1" <?= $settings['ai_enabled'] === '1' ? 'checked' : '' ?>><span><strong>Enable AI replies</strong><small>When enabled, each ordinary customer message is sent to this exact provider/model. FAQs are context only; a failed inference leaves the message saved and offers a retry.</small></span></label>
            <div class="sh-grid2">
              <div class="sh-field"><label class="sh-field__label" for="support-provider">Configured provider</label>
                <select class="sh-select" id="support-provider" name="provider_id" data-provider-select data-default-provider-id="<?= (int)($defaultProvider['id'] ?? 0) ?>">
                  <option value="0" <?= $savedProviderId === 0 ? 'selected' : '' ?>>Use AI module default<?= $defaultProvider ? ' — ' . e((string)$defaultProvider['name']) : '' ?></option>
                  <?php foreach ($providers as $provider): ?>
                    <option value="<?= (int)$provider['id'] ?>" <?= $savedProviderId === (int)$provider['id'] ? 'selected' : '' ?> <?= !$provider['secure'] ? 'disabled' : '' ?>><?= e($provider['name']) ?> · <?= e($provider['driver']) ?><?= !$provider['secure'] ? ' · HTTPS required' : '' ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="sh-formcard__note">Keys remain in AI Providers and are never displayed or sent to this page. <a href="<?= e(sh_url('admin/ai/providers.php')) ?>">Manage existing AI providers</a>.</small>
              </div>
              <div class="sh-field"><label class="sh-field__label" for="support-model">Text model</label>
                <select class="sh-select" id="support-model" name="model" data-model-select>
                  <option value="" data-provider-id="0">Use selected provider’s default model</option>
                  <?php foreach ($providers as $provider):
                    $pid = (int)$provider['id'];
                    if (!$provider['secure']) { continue; }
                    $allModels = sh_ai_models($pid, true);
                    $byId = [];
                    foreach ($allModels as $modelRow) {
                        $mid = (string)$modelRow['model_id'];
                        if (sh_ai_model_supports($pid, $mid, 'text')) { $byId[$mid] = (string)($modelRow['name'] ?? $mid); }
                    }
                    if ($provider['default_model'] !== '' && sh_ai_model_supports($pid, $provider['default_model'], 'text')) { $byId[$provider['default_model']] = $provider['default_model']; }
                    foreach ($byId as $mid => $modelName): ?>
                      <option value="<?= e($mid) ?>" data-provider-id="<?= $pid ?>" <?= $selectedProviderId === $pid && $savedModel === $mid ? 'selected' : '' ?>><?= e($modelName) ?> — <?= e($provider['name']) ?></option>
                    <?php endforeach; endforeach; ?>
                </select>
              </div>
              <div class="sh-field"><label class="sh-field__label" for="support-timeout">Provider timeout (seconds, 5–20)</label><input class="sh-input" id="support-timeout" name="request_timeout" type="number" min="5" max="20" value="<?= (int)$settings['request_timeout'] ?>"></div>
              <div class="sh-field"><label class="sh-field__label" for="support-output">Maximum output tokens (64–800)</label><input class="sh-input" id="support-output" name="output_tokens" type="number" min="64" max="800" value="<?= (int)$settings['output_tokens'] ?>"></div>
              <div class="sh-field"><label class="sh-field__label" for="support-daily">Store-wide AI requests per day</label><input class="sh-input" id="support-daily" name="daily_limit" type="number" min="1" max="10000" value="<?= (int)$settings['daily_limit'] ?>"></div>
              <div class="sh-field"><label class="sh-field__label" for="support-customer-daily">AI requests per customer/session per day</label><input class="sh-input" id="support-customer-daily" name="customer_daily_limit" type="number" min="1" max="100" value="<?= (int)$settings['customer_daily_limit'] ?>"></div>
              <div class="sh-field"><label class="sh-field__label" for="support-context">Recent conversation turns (1–12)</label><input class="sh-input" id="support-context" name="context_limit" type="number" min="1" max="12" value="<?= (int)$settings['context_limit'] ?>"></div>
            </div>
            <div class="sh-support-settings__test">
              <div><strong>Selected-model text test status:</strong>
                <?php if (!empty($aiState['test_ok'])): ?><span class="sh-support-settings__state is-good">Selected model returned text in the latest test; live calls may still fail</span>
                <?php elseif ($testCode !== ''): ?><span class="sh-support-settings__state <?= $testTone === 'warn' ? 'is-warn' : 'is-bad' ?>"><?= e(sh_ai_test_state_label($testCode)) ?></span>
                <?php else: ?><span class="sh-support-settings__state">Not tested for this exact configuration (test is optional)</span><?php endif; ?>
                <?php if ($testMessage !== ''): ?><p><?= e($testMessage) ?></p><?php endif; ?>
                <?php if ($testAt !== ''): ?><small>Last test: <?= e($testAt) ?> UTC</small><?php endif; ?>
                <?php if ($lastLiveDiagnostic !== ''): ?><p class="sh-formcard__note"><strong>Last live inference diagnostic (redacted):</strong><br><?= e($lastLiveDiagnostic) ?></p><?php endif; ?>
              </div>
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" name="form" value="test_provider" formaction="<?= e(sh_url('admin/support-settings.php#ai')) ?>"><?= sh_icon('activity', 14) ?> Save settings &amp; test selected model</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="sh-support-settings__save">
          <button class="sh-btn" type="submit" name="form" value="save_settings"><?= sh_icon('check', 15) ?> Save support settings</button>
          <?php if ($canConfigureAi): ?><button class="sh-btn sh-btn--ghost" type="submit" name="form" value="save_ai_settings">Save AI configuration</button><?php endif; ?>
        </div>
      </form>
    </div>
  </section>

  <section class="sh-panel" id="faqs">
    <div class="sh-panel__head"><h3>Saved FAQs <span class="sh-support-settings__count"><?= count($faqRows) ?></span></h3></div>
    <div class="sh-panel__body">
      <p class="sh-formcard__note">Enabled English FAQs appear on the public Help Centre and may be used by chat. Do not put customer data, passwords, API keys or other private information in these answers.</p>
      <?php if (!$faqRows): ?><p class="sh-support-admin__empty">No FAQs are saved yet.</p>
      <?php else: ?><div class="sh-support-settings__faq-list">
        <?php foreach ($faqRows as $faq): ?>
          <article class="sh-support-settings__faq">
            <div class="sh-support-settings__faq-copy"><strong><?= e($faq['question_en']) ?></strong><span><?= (int)$faq['status'] ? 'Enabled' : 'Disabled' ?> · Sort <?= (int)$faq['sort_order'] ?></span></div>
            <div class="sh-support-settings__faq-actions">
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/support-settings.php?faq=' . (int)$faq['id'] . '#faq-edit')) ?>">Edit</a>
              <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="form" value="faq_toggle"><input type="hidden" name="faq_id" value="<?= (int)$faq['id'] ?>"><input type="hidden" name="status" value="<?= (int)$faq['status'] ? '0' : '1' ?>"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= (int)$faq['status'] ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" data-confirm="Delete this FAQ permanently?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="faq_delete"><input type="hidden" name="faq_id" value="<?= (int)$faq['id'] ?>"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Delete</button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </section>

  <section class="sh-panel" id="faq-edit">
    <div class="sh-panel__head"><h3><?= $editFaq ? 'Edit FAQ' : 'Add FAQ' ?></h3></div>
    <div class="sh-panel__body">
      <form method="post" class="sh-support-settings__form">
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="faq_save"><input type="hidden" name="faq_id" value="<?= $editFaq ? (int)$editFaq['id'] : 0 ?>">
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="faq-q-en">Question (English)</label><input class="sh-input" id="faq-q-en" name="question_en" maxlength="300" value="<?= e($faqForm['question_en']) ?>" required></div>
          <div class="sh-field"><label class="sh-field__label" for="faq-q-bn">Question (বাংলা)</label><input class="sh-input" id="faq-q-bn" name="question_bn" maxlength="300" value="<?= e((string)($faqForm['question_bn'] ?? '')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="faq-q-banglish">Question (Banglish)</label><input class="sh-input" id="faq-q-banglish" name="question_banglish" maxlength="300" value="<?= e((string)($faqForm['question_banglish'] ?? '')) ?>"></div>
          <div class="sh-field"><label class="sh-field__label" for="faq-keywords">Keywords (comma-separated)</label><input class="sh-input" id="faq-keywords" name="keywords" maxlength="500" value="<?= e((string)($faqForm['keywords'] ?? '')) ?>"></div>
        </div>
        <div class="sh-field"><label class="sh-field__label" for="faq-a-en">Answer (English)</label><textarea class="sh-textarea" id="faq-a-en" name="answer_en" rows="4" maxlength="5000" required><?= e($faqForm['answer_en']) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="faq-a-bn">Answer (বাংলা)</label><textarea class="sh-textarea" id="faq-a-bn" name="answer_bn" rows="4" maxlength="5000"><?= e((string)($faqForm['answer_bn'] ?? '')) ?></textarea></div>
        <div class="sh-field"><label class="sh-field__label" for="faq-a-banglish">Answer (Banglish)</label><textarea class="sh-textarea" id="faq-a-banglish" name="answer_banglish" rows="4" maxlength="5000"><?= e((string)($faqForm['answer_banglish'] ?? '')) ?></textarea></div>
        <div class="sh-grid2">
          <div class="sh-field"><label class="sh-field__label" for="faq-sort">Sort order</label><input class="sh-input" id="faq-sort" name="sort_order" type="number" min="-10000" max="10000" value="<?= (int)($faqForm['sort_order'] ?? 0) ?>"></div>
          <div class="sh-field"><input type="hidden" name="status" value="0"><label class="sh-support-settings__toggle"><input type="checkbox" name="status" value="1" <?= (int)($faqForm['status'] ?? 1) === 1 ? 'checked' : '' ?>><span><strong>Enable this FAQ</strong><small>Disabled answers are not used for customer replies.</small></span></label></div>
        </div>
        <div class="sh-support-settings__save"><button class="sh-btn" type="submit"><?= sh_icon('check', 15) ?> Save FAQ</button>
          <?php if ($editFaq): ?><a class="sh-btn sh-btn--ghost" href="<?= e(sh_url('admin/support-settings.php#faq-edit')) ?>">Add a new FAQ</a><?php endif; ?>
        </div>
      </form>
    </div>
  </section>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
