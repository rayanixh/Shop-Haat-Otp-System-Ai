<?php
require_once __DIR__ . '/_ai.php';

$q = trim(sh_get('q'));
$rows = $q !== ''
    ? sh_all('SELECT p.id, p.name, p.image, p.short_description, c.name AS category_name
              FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.name LIKE ? ORDER BY p.name ASC LIMIT 100', ['%' . $q . '%'])
    : sh_all('SELECT p.id, p.name, p.image, p.short_description, c.name AS category_name
              FROM products p LEFT JOIN categories c ON c.id = p.category_id
              ORDER BY p.id DESC LIMIT 100');

$bulkProviders = $aiInstalled ? sh_ai_providers_usable() : [];
$adminPage = 'ai_bulk';
$adminTitle = 'Bulk AI Generator';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('list', 20) ?> Bulk AI Generator</h2>
    <p class="sh-aihead__sub">Queue many products and process them in controlled batches.</p>
  </div>
</div>

<?php sh_ai_subnav('bulk'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> What to generate</h2>
  </div>
  <div class="sh-panel__body">
    <div class="sh-aitasks">
      <?php foreach (['title' => 'Title', 'description' => 'Description', 'short' => 'Short description',
                      'tags' => 'Tags', 'seo' => 'SEO', 'image' => 'Image'] as $k => $lbl): ?>
        <label class="sh-check"><input type="checkbox" data-ai-task value="<?= e($k) ?>"><span><?= e($lbl) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="sh-grid2" style="margin-top:12px">
      <div class="sh-field" style="margin:0">
        <label class="sh-field__label" for="bulk-provider">Provider</label>
        <select class="sh-select" id="bulk-provider" data-ai-bulk-provider data-ai-route-provider data-kind="text" data-target="bulk-model">
          <option value="0">Per-task routing (AI Settings)</option>
          <?php foreach ($bulkProviders as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['name']) ?> · <?= e((string)($p['default_model'] ?: 'no default model')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sh-field" style="margin:0">
        <label class="sh-field__label" for="bulk-model">Text model (optional)</label>
        <input class="sh-input" id="bulk-model" data-ai-bulk-model list="bulk-model-list" placeholder="Provider default model" maxlength="120" disabled>
        <datalist id="bulk-model-list"></datalist>
      </div>
    </div>
    <p class="sh-panel__note" style="margin-top:10px">
      Every job in this batch runs through the chosen provider; Image jobs use that provider's image model.
      <?= $aiConfig['fallback_enabled'] ? 'Fallback is on: a job that fails transiently retries on the fallback provider.' : 'Fallback is off: failed jobs stay failed and can be retried.' ?>
      Bulk results are written straight to the products — you are confirming the batch up front.
      Jobs run <?= (int)$aiConfig['batch_size'] ?> at a time, never all at once.
      Maximum <?= (int)SH_AI_MAX_BATCH ?> jobs per batch (products × tasks).
    </p>
    <div class="sh-aiactions" style="margin-top:12px">
      <button class="sh-btn" data-ai-bulk-start <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>
        <?= sh_icon('zap', 15) ?> Generate selected</button>
      <span class="sh-panel__note" data-ai-bulk-count>0 products selected</span>
    </div>

    <div class="sh-aiprogress" data-ai-progress hidden>
      <div class="sh-aiprogress__bar"><span data-ai-progress-fill style="width:0%"></span></div>
      <p class="sh-panel__note" data-ai-progress-text>Preparing…</p>
      <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-retry hidden>Retry failed</button>
    </div>
  </div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Products (<?= count($rows) ?>)</h2>
    <div class="sh-panel__actions">
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-ai-select-all>Select all</button>
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-ai-select-none>Clear</button>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="b-q">Search</label>
        <input class="sh-input" id="b-q" name="q" value="<?= e($q) ?>" placeholder="Product name"></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
      <?php if ($q !== ''): ?><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/bulk.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th style="width:38px"></th><th>Product</th><th>Category</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="4">No products found.</td></tr>
      <?php else: foreach ($rows as $p): ?>
        <tr data-ai-row="<?= (int)$p['id'] ?>">
          <td><input type="checkbox" data-ai-product-check value="<?= (int)$p['id'] ?>"></td>
          <td><div class="sh-table__cell">
            <img class="sh-table__thumb" src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy">
            <div><div class="sh-table__name"><?= e((string)$p['name']) ?></div>
              <div class="sh-table__meta"><?= e(sh_excerpt((string)$p['short_description'], 60) ?: 'No short description') ?></div>
            </div></div></td>
          <td class="sh-table__meta"><?= e((string)($p['category_name'] ?? '—')) ?></td>
          <td><span class="sh-badge" data-ai-row-status>—</span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
