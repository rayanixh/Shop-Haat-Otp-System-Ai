<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/support.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('support.manage');

sh_support_ensure_schema();
$defaults = sh_support_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $act = (string)($_POST['act'] ?? 'save');
    if ($act === 'save') {
        $save = static function (string $key, string $val): void { sh_setting_save('support_' . $key, $val); };
        $save('enabled', !empty($_POST['support_enabled']) ? '1' : '0');
        $save('ai_enabled', !empty($_POST['support_ai_enabled']) ? '1' : '0');
        $save('provider_id', (string)max(0, (int)($_POST['support_provider_id'] ?? 0)));
        $save('model', mb_substr(trim((string)($_POST['support_model'] ?? '')), 0, 160));
        foreach (['max_input', 'max_tokens', 'history_turns', 'timeout', 'rate_per_min', 'daily_ai_limit', 'retention_days'] as $k) {
            $save($k, (string)(int)($_POST['support_' . $k] ?? $defaults[$k]));
        }
        $langs = array_values(array_intersect(['bn', 'banglish', 'en'], (array)($_POST['support_lang'] ?? [])));
        $save('languages', $langs !== [] ? implode(',', $langs) : 'bn,banglish,en');
        foreach (['system_prompt', 'business_hours', 'contact', 'availability', 'delivery_policy', 'return_policy', 'payment_policy',
                  'welcome_bn', 'welcome_banglish', 'welcome_en', 'fallback_bn', 'fallback_banglish', 'fallback_en'] as $k) {
            $save($k, mb_substr(trim((string)($_POST['support_' . $k] ?? '')), 0, $k === 'system_prompt' ? 4000 : 6000));
        }
        sh_log_line('admin', 'Support settings updated');
        sh_flash('success', 'Support settings saved.');
    } elseif ($act === 'test_ai') {
        $res = sh_support_ai_probe(sh_support_cfg());
        if (!empty($res['ok'])) {
            sh_flash('success', 'Support AI test passed — ' . $res['provider'] . ' · ' . $res['model'] . ' responded in ' . (int)$res['ms'] . 'ms: "' . $res['sample'] . '"');
        } else {
            sh_flash('error', 'Support AI test failed: ' . $res['error'] . ' The chat keeps working in no-AI mode (verified store data + FAQs + human handoff).');
        }
    } elseif ($act === 'faq_save') {
        $fid = (int)($_POST['faq_id'] ?? 0);
        $row = [
            'title' => mb_substr(trim((string)($_POST['faq_title'] ?? '')), 0, 180),
            'keywords' => mb_substr(trim((string)($_POST['faq_keywords'] ?? '')), 0, 4000),
            'answer_bn' => trim((string)($_POST['faq_answer_bn'] ?? '')),
            'answer_banglish' => trim((string)($_POST['faq_answer_banglish'] ?? '')),
            'answer_en' => trim((string)($_POST['faq_answer_en'] ?? '')),
            'sort_order' => (int)($_POST['faq_sort'] ?? 0),
            'status' => !empty($_POST['faq_status']) ? 1 : 0,
        ];
        if ($row['title'] === '' || $row['keywords'] === '') {
            sh_flash('error', 'FAQ needs a title and at least one keyword.');
        } else {
            if ($fid > 0) { sh_update('support_faqs', $row, ['id' => $fid]); sh_flash('success', 'FAQ updated.'); }
            else { sh_insert('support_faqs', $row); sh_flash('success', 'FAQ added.'); }
        }
    } elseif ($act === 'faq_delete') {
        $fid = (int)($_POST['faq_id'] ?? 0);
        if ($fid > 0) { sh_delete('support_faqs', ['id' => $fid]); sh_flash('success', 'FAQ deleted.'); }
    } elseif ($act === 'purge') {
        $n = sh_support_purge(sh_support_cfg());
        sh_flash('success', 'Cleanup done — removed ' . $n . ' resolved/closed conversation(s) older than the retention window.');
    }
    sh_redirect('admin/support-settings.php');
}

$cfg = sh_support_cfg();
$stats = sh_support_stats();
$providers = [];
$modelOptions = [];
$routeNow = null;
$aiInstalled = function_exists('sh_ai_installed') && sh_ai_installed();
try {
    if ($aiInstalled) {
        $providers = sh_ai_providers_usable();
        foreach (sh_ai_models(0, true) as $m) { $modelOptions[(string)$m['model_id']] = (string)$m['model_id']; }
        foreach ($providers as $p) { if ((string)$p['default_model'] !== '') { $modelOptions[(string)$p['default_model']] = (string)$p['default_model']; } }
        $routeNow = sh_support_ai_route($cfg);
    }
} catch (Throwable $e) { sh_log_exception($e, 'support-settings'); }
$lastTest = json_decode((string)sh_setting('support_ai_last_test', ''), true);
$faqs = [];
try { $faqs = sh_all('SELECT * FROM support_faqs ORDER BY sort_order ASC, id ASC'); } catch (Throwable $e) {}
$editFaq = null;
$editId = (int)($_GET['faq_edit'] ?? 0);
if ($editId > 0) { foreach ($faqs as $f) { if ((int)$f['id'] === $editId) { $editFaq = $f; break; } } }

$adminPage = 'support-settings';
$adminTitle = 'Support Settings';
require __DIR__ . '/_layout.php';

$field = static function (string $name, string $label, string $type, $value, string $hint = '', array $attrs = []): void {
    echo '<div class="sh-field"><label for="f-' . e($name) . '">' . e($label) . '</label>';
    $extra = '';
    foreach ($attrs as $k => $v) { $extra .= ' ' . e($k) . '="' . e((string)$v) . '"'; }
    if ($type === 'textarea') {
        echo '<textarea class="sh-textarea" id="f-' . e($name) . '" name="' . e($name) . '"' . $extra . '>' . e((string)$value) . '</textarea>';
    } else {
        echo '<input class="sh-input" id="f-' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e((string)$value) . '"' . $extra . '>';
    }
    if ($hint !== '') { echo '<p class="sh-field__hint">' . e($hint) . '</p>'; }
    echo '</div>';
};
$week = (array)($stats['week'] ?? []);
$today = (array)($stats['today'] ?? []);
$num = static fn($v): string => number_format((int)$v);
?>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('message', 17) ?> AI Customer Support</h2>
    <a class="sh-btn sh-btn-soft" href="support.php"><?= sh_icon('message', 15) ?> Open inbox</a>
  </div>
  <div class="sh-panel__body">
    <?php if ($routeNow !== null): ?>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 16) ?><span><strong>AI route configured:</strong> <?= e($routeNow['provider']['name']) ?> · model <code><?= e($routeNow['model']) ?></code>.<?php if (is_array($lastTest)): ?> Last live test: <?= !empty($lastTest['ok']) ? 'passed' : 'failed' ?> (<?= e($lastTest['at'] ?? '') ?><?= !empty($lastTest['ms']) ? ', ' . (int)$lastTest['ms'] . 'ms' : '' ?>)<?php if (!empty($lastTest['error'])): ?> — <?= e($lastTest['error']) ?><?php endif; ?>.<?php else: ?> Not live-tested yet — use “Test support AI” below; a configured route is not proof the provider answers.<?php endif; ?></span></div>
    <?php else: ?>
      <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 16) ?><span><strong>No-AI mode is active.</strong> <?php if (!$aiInstalled): ?>The AI module has no enabled provider with a key yet. <?php endif; ?>The chat still fully works: product search, prices, stock and order status come from the live database, FAQs answer common questions, and customers can always reach a human. To enable AI phrasing, add a provider under <a href="ai/providers.php">AI Providers</a> (a free-tier or local OpenAI-compatible endpoint works), then test below. No paid API is required.</span></div>
    <?php endif; ?>

    <p class="sh-panel__note">Last 7 days — customer messages: <strong><?= $num($week['customer_messages'] ?? 0) ?></strong> · AI requests: <strong><?= $num($week['ai_requests'] ?? 0) ?></strong> · AI errors: <strong><?= $num($week['ai_errors'] ?? 0) ?></strong> · no-AI fallback replies: <strong><?= $num($week['fallback_replies'] ?? 0) ?></strong> · agent replies: <strong><?= $num($week['admin_replies'] ?? 0) ?></strong> · handoffs: <strong><?= $num($week['handoffs'] ?? 0) ?></strong>. Today: <?= $num($today['customer_messages'] ?? 0) ?> messages, <?= $num($today['ai_requests'] ?? 0) ?> AI requests<?= (int)$cfg['daily_ai_limit'] > 0 ? ' of ' . $num($cfg['daily_ai_limit']) . ' daily limit' : '' ?>.</p>
  </div>
</div>

<form method="post" class="sh-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="act" value="save">

  <div class="sh-panel">
    <div class="sh-panel__head"><h3 class="sh-panel__title">General</h3></div>
    <div class="sh-panel__body">
      <label class="sh-check"><input type="checkbox" name="support_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> Support chat enabled (storefront widget + API)</label>
      <div class="sh-field"><label>Enabled languages (auto-detected per message)</label>
        <?php foreach (['bn' => 'বাংলা (Bengali script)', 'banglish' => 'Banglish (Bengali in Latin letters)', 'en' => 'English'] as $code => $label): ?>
          <label class="sh-check" style="margin-right:14px"><input type="checkbox" name="support_lang[]" value="<?= $code ?>" <?= in_array($code, $cfg['languages'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
      <?php
        $field('support_max_input', 'Maximum customer message length (characters)', 'number', $cfg['max_input'], '200–8000.', ['min' => 200, 'max' => 8000]);
        $field('support_rate_per_min', 'Messages per minute per visitor', 'number', $cfg['rate_per_min'], 'Per-session limit; a looser per-IP limit applies on top.', ['min' => 1, 'max' => 60]);
        $field('support_retention_days', 'Keep resolved/closed conversations (days)', 'number', $cfg['retention_days'], '0 = keep forever. Older ones are removed by the cleanup button below.', ['min' => 0, 'max' => 3650]);
        $field('support_availability', 'Human availability note (shown in the widget)', 'text', $cfg['availability'], 'Example: Agents reply 9am–10pm (GMT+6). Leave empty to hide.');
        $field('support_business_hours', 'Business hours (internal reference)', 'text', $cfg['business_hours']);
        $field('support_contact', 'Alternative contact (internal reference)', 'text', $cfg['contact'], 'Phone/WhatsApp shown nowhere automatically — kept for your team.');
      ?>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h3 class="sh-panel__title">AI generation</h3></div>
    <div class="sh-panel__body">
      <label class="sh-check"><input type="checkbox" name="support_ai_enabled" value="1" <?= $cfg['ai_enabled'] ? 'checked' : '' ?>> Use AI to phrase replies when a provider is available</label>
      <p class="sh-field__hint">Turning this off (or having no working provider) switches the assistant to deterministic no-AI replies — still using verified prices, stock and order data. The model never invents prices/stock and can never cancel or change anything; a server-side guard replaces any reply that cites unverified amounts.</p>
      <div class="sh-field"><label for="f-support_provider_id">Support AI provider</label>
        <select class="sh-input" id="f-support_provider_id" name="support_provider_id">
          <option value="0" <?= (int)$cfg['provider_id'] === 0 ? 'selected' : '' ?>>AI task routing (Support chat task → default provider)</option>
          <?php foreach ($providers as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$cfg['provider_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['driver']) ?><?= (string)$p['default_model'] !== '' ? ' · ' . e($p['default_model']) : '' ?>)</option>
          <?php endforeach; ?>
        </select>
        <p class="sh-field__hint">Providers and API keys are managed under <a href="ai/providers.php">AI Providers</a>; keys stay encrypted and are never shown here.</p>
      </div>
      <?php $field('support_model', 'Model override (optional)', 'text', $cfg['model'], 'Leave empty to use the task/provider default model. A small, fast chat model is best for support.', ['list' => 'support-models']); ?>
      <datalist id="support-models"><?php foreach ($modelOptions as $mo): ?><option value="<?= e($mo) ?>"><?php endforeach; ?></datalist>
      <?php
        $field('support_timeout', 'AI request timeout (seconds)', 'number', $cfg['timeout'], '5–90. On timeout the customer gets the verified no-AI answer instead of waiting.', ['min' => 5, 'max' => 90]);
        $field('support_max_tokens', 'Max reply tokens', 'number', $cfg['max_tokens'], '50–2000.', ['min' => 50, 'max' => 2000]);
        $field('support_history_turns', 'Conversation turns sent to the AI', 'number', $cfg['history_turns'], '2–30. Bounded so long chats stay fast and cheap.', ['min' => 2, 'max' => 30]);
        $field('support_daily_ai_limit', 'Daily AI request limit (whole store)', 'number', $cfg['daily_ai_limit'], '0 = unlimited. When reached, chats continue in no-AI mode until tomorrow.', ['min' => 0]);
        $field('support_system_prompt', 'Extra assistant instructions (optional)', 'textarea', $cfg['system_prompt'], 'Tone/personality/store specifics. Safety rules (verified facts only, read-only) always apply on top.', ['rows' => 4]);
      ?>
    </div>
  </div>

  <div class="sh-panel">
    <div class="sh-panel__head"><h3 class="sh-panel__title">Messages &amp; policies</h3></div>
    <div class="sh-panel__body">
      <?php
        $field('support_welcome_bn', 'Welcome message — বাংলা', 'textarea', $cfg['welcome_bn'], '', ['rows' => 2]);
        $field('support_welcome_banglish', 'Welcome message — Banglish', 'textarea', $cfg['welcome_banglish'], '', ['rows' => 2]);
        $field('support_welcome_en', 'Welcome message — English', 'textarea', $cfg['welcome_en'], '', ['rows' => 2]);
        $field('support_fallback_bn', 'When unsure — বাংলা', 'textarea', $cfg['fallback_bn'], 'Shown instead of guessing.', ['rows' => 2]);
        $field('support_fallback_banglish', 'When unsure — Banglish', 'textarea', $cfg['fallback_banglish'], '', ['rows' => 2]);
        $field('support_fallback_en', 'When unsure — English', 'textarea', $cfg['fallback_en'], '', ['rows' => 2]);
        $field('support_delivery_policy', 'Delivery policy (exact text wins over FAQs)', 'textarea', $cfg['delivery_policy'], 'Only write charges/times you can honour.', ['rows' => 3]);
        $field('support_return_policy', 'Return / exchange policy', 'textarea', $cfg['return_policy'], '', ['rows' => 3]);
        $field('support_payment_policy', 'Payment policy', 'textarea', $cfg['payment_policy'], '', ['rows' => 3]);
      ?>
    </div>
  </div>

  <p><button class="sh-btn sh-btn-primary" type="submit"><?= sh_icon('check-circle', 15) ?> Save support settings</button></p>
</form>

<div class="sh-panel">
  <div class="sh-panel__head"><h3 class="sh-panel__title">Test support AI</h3></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">Sends one tiny real request through the exact provider/model the chat would use. Nothing is marked “working” unless this passes. Customer chats never depend on it — failures fall back to no-AI replies automatically.</p>
    <form method="post"><?= sh_csrf_field() ?><input type="hidden" name="act" value="test_ai">
      <button class="sh-btn sh-btn-soft" type="submit"><?= sh_icon('zap', 15) ?> Test support AI now</button>
    </form>
    <?php if (is_array($lastTest)): ?>
      <p class="sh-panel__note" style="margin-top:10px">Last test <?= e($lastTest['at'] ?? '') ?>: <strong><?= !empty($lastTest['ok']) ? 'Passed' : 'Failed' ?></strong>
      <?php if (!empty($lastTest['provider'])): ?> · <?= e($lastTest['provider']) ?> · <?= e($lastTest['model'] ?? '') ?><?php endif; ?>
      <?php if (!empty($lastTest['ms'])): ?> · <?= (int)$lastTest['ms'] ?>ms<?php endif; ?>
      <?php if (!empty($lastTest['sample'])): ?><br>Reply: “<?= e($lastTest['sample']) ?>”<?php endif; ?>
      <?php if (!empty($lastTest['error'])): ?><br>Error: <?= e($lastTest['error']) ?><?php endif; ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h3 class="sh-panel__title">FAQs (<?= count($faqs) ?>)</h3></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">FAQs are matched by comma-separated keywords (any language) and answered in the customer’s language. They work with or without AI.</p>
    <div class="sh-tablewrap"><table class="sh-table">
      <thead><tr><th>FAQ</th><th>Keywords</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if ($faqs === []): ?><tr class="sh-table--empty"><td colspan="4">No FAQs yet.</td></tr><?php endif; ?>
      <?php foreach ($faqs as $f): ?>
        <tr>
          <td><strong><?= e($f['title']) ?></strong><div class="sh-table__meta"><?= e(mb_substr((string)($f['answer_en'] !== '' ? $f['answer_en'] : $f['answer_bn']), 0, 110)) ?>…</div></td>
          <td><?= e(mb_substr((string)$f['keywords'], 0, 90)) ?></td>
          <td><?= (int)$f['status'] === 1 ? '<span class="sh-badge sh-badge--ok">Active</span>' : '<span class="sh-badge">Off</span>' ?></td>
          <td style="white-space:nowrap">
            <a class="sh-btn sh-btn-soft" href="support-settings.php?faq_edit=<?= (int)$f['id'] ?>#faq-form">Edit</a>
            <form method="post" style="display:inline" data-confirm="Delete this FAQ?"><?= sh_csrf_field() ?><input type="hidden" name="act" value="faq_delete"><input type="hidden" name="faq_id" value="<?= (int)$f['id'] ?>"><button class="sh-btn sh-btn-soft" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>

    <form method="post" class="sh-form" id="faq-form" style="margin-top:16px">
      <?= sh_csrf_field() ?><input type="hidden" name="act" value="faq_save"><input type="hidden" name="faq_id" value="<?= (int)($editFaq['id'] ?? 0) ?>">
      <h4 style="margin:0 0 10px"><?= $editFaq ? 'Edit FAQ' : 'Add FAQ' ?></h4>
      <?php
        $field('faq_title', 'Title', 'text', $editFaq['title'] ?? '');
        $field('faq_keywords', 'Keywords (comma-separated, any language)', 'textarea', $editFaq['keywords'] ?? '', 'Example: delivery time, koto din, কত দিন', ['rows' => 2]);
        $field('faq_answer_bn', 'Answer — বাংলা', 'textarea', $editFaq['answer_bn'] ?? '', '', ['rows' => 3]);
        $field('faq_answer_banglish', 'Answer — Banglish', 'textarea', $editFaq['answer_banglish'] ?? '', '', ['rows' => 3]);
        $field('faq_answer_en', 'Answer — English', 'textarea', $editFaq['answer_en'] ?? '', '', ['rows' => 3]);
        $field('faq_sort', 'Sort order', 'number', $editFaq['sort_order'] ?? 0);
      ?>
      <label class="sh-check"><input type="checkbox" name="faq_status" value="1" <?= ($editFaq === null || (int)$editFaq['status'] === 1) ? 'checked' : '' ?>> Active</label>
      <p><button class="sh-btn sh-btn-primary" type="submit"><?= $editFaq ? 'Save FAQ' : 'Add FAQ' ?></button>
      <?php if ($editFaq): ?><a class="sh-btn sh-btn-soft" href="support-settings.php">Cancel editing</a><?php endif; ?></p>
    </form>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head"><h3 class="sh-panel__title">Data retention</h3></div>
  <div class="sh-panel__body">
    <p class="sh-panel__note">Conversations are stored in MySQL. Resolved/closed conversations older than <?= (int)$cfg['retention_days'] ?> day(s) can be removed (messages and events go with them). Open/pending chats are never purged. Set the window to 0 to keep everything.</p>
    <form method="post" data-confirm="Remove old resolved/closed conversations now?"><?= sh_csrf_field() ?><input type="hidden" name="act" value="purge">
      <button class="sh-btn sh-btn-soft" type="submit"><?= sh_icon('trash', 15) ?> Run cleanup now</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
