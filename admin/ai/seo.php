<?php
require_once __DIR__ . '/_ai.php';

$type = in_array(sh_get('type'), ['product', 'category'], true) ? sh_get('type') : 'product';
$rid = sh_int($_GET['id'] ?? 0);
$item = null;

if ($rid > 0) {
    $item = $type === 'product'
        ? sh_one('SELECT id, name, meta_title, meta_description, meta_keywords FROM products WHERE id = ? LIMIT 1', [$rid])
        : sh_one('SELECT id, name, meta_title, meta_description, meta_keywords FROM categories WHERE id = ? LIMIT 1', [$rid]);
}
$list = $type === 'product'
    ? sh_all('SELECT id, name FROM products ORDER BY id DESC LIMIT 50')
    : sh_all('SELECT id, name FROM categories ORDER BY name ASC LIMIT 50');

$adminPage = 'ai_seo';
$adminTitle = 'SEO AI';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('search', 20) ?> SEO AI</h2>
    <p class="sh-aihead__sub">Meta title, description and keywords for products and categories.</p>
  </div>
</div>

<?php sh_ai_subnav('seo'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-aigrid">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('search', 17) ?> Choose an item</h2></div>
      <div class="sh-panel__body">
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="seo-type">Type</label>
            <select class="sh-select" id="seo-type" onchange="location.href=<?= json_encode(sh_url('admin/ai/seo.php?type=')) ?>+this.value">
              <option value="product" <?= $type === 'product' ? 'selected' : '' ?>>Product</option>
              <option value="category" <?= $type === 'category' ? 'selected' : '' ?>>Category</option>
            </select>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="seo-item">Item</label>
            <select class="sh-select" id="seo-item" onchange="if(this.value)location.href=this.value">
              <option value="">Select…</option>
              <?php foreach ($list as $r): ?>
                <option value="<?= e(sh_url('admin/ai/seo.php?type=' . $type . '&id=' . (int)$r['id'])) ?>"
                  <?= $rid === (int)$r['id'] ? 'selected' : '' ?>><?= e((string)$r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php if ($item !== null): ?>
          <p class="sh-field__label" style="margin-top:6px">Current SEO</p>
          <div class="sh-aicurrent">
            <strong>Title:</strong> <?= e((string)($item['meta_title'] ?: '—')) ?><br>
            <strong>Description:</strong> <?= e((string)($item['meta_description'] ?: '—')) ?><br>
            <strong>Keywords:</strong> <?= e((string)($item['meta_keywords'] ?: '—')) ?>
          </div>
          <div class="sh-aiactions" style="margin-top:12px"
               data-ai-<?= $type === 'product' ? 'product' : 'category' ?>="<?= (int)$item['id'] ?>">
            <button class="sh-btn" data-ai-generate="<?= $type === 'product' ? 'seo' : 'category_content' ?>"
                    <?= $aiConfig['has_key'] ? '' : 'disabled' ?>><?= sh_icon('zap', 15) ?> Generate SEO</button>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($item !== null): ?>
      <div class="sh-panel sh-aiblock" data-ai-block="<?= $type === 'product' ? 'seo' : 'category_content' ?>" hidden>
        <div class="sh-panel__head">
          <h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> Generated SEO</h2>
          <div class="sh-panel__actions">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-regenerate="<?= $type === 'product' ? 'seo' : 'category_content' ?>">Regenerate</button>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-copy>Copy</button>
            <button class="sh-btn sh-btn--sm" data-ai-save="<?= $type === 'product' ? 'seo' : 'category_content' ?>">Save</button>
          </div>
        </div>
        <div class="sh-panel__body"><div class="sh-airesult-box" data-ai-output>—</div></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> Existing fields reused</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note">Products already had <code>meta_title</code> and <code>meta_description</code>;
        those are reused rather than duplicated. Only <code>meta_keywords</code> was added.</p>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
