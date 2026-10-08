<?php
require_once __DIR__ . '/_ai.php';

$cid = sh_int($_GET['id'] ?? 0);
$cats = sh_all('SELECT id, name, description FROM categories ORDER BY sort_order ASC, name ASC');
$category = $cid > 0 ? sh_one('SELECT * FROM categories WHERE id = ? LIMIT 1', [$cid]) : null;

$adminPage = 'ai_category';
$adminTitle = 'Category AI';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('grid', 20) ?> Category AI</h2>
    <p class="sh-aihead__sub">Write a listing description and SEO metadata for a category.</p>
  </div>
</div>

<?php sh_ai_subnav('category'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-aigrid">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('grid', 17) ?> Choose a category</h2></div>
      <div class="sh-panel__body">
        <div class="sh-field">
          <label class="sh-field__label" for="cat-pick">Category</label>
          <select class="sh-select" id="cat-pick" onchange="if(this.value)location.href=this.value">
            <option value="">Select…</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= e(sh_url('admin/ai/category.php?id=' . (int)$c['id'])) ?>"
                <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($category !== null): ?>
          <p class="sh-field__label" style="margin-top:6px">Current description</p>
          <div class="sh-aicurrent"><?= trim((string)$category['description']) !== ''
              ? e((string)$category['description']) : '<em>empty</em>' ?></div>
          <div class="sh-aiactions" style="margin-top:12px" data-ai-category="<?= (int)$category['id'] ?>">
            <button class="sh-btn" data-ai-generate="category_content" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>
              <?= sh_icon('zap', 15) ?> Generate content &amp; SEO</button>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($category !== null): ?>
      <div class="sh-panel sh-aiblock" data-ai-block="category_content" hidden>
        <div class="sh-panel__head">
          <h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> Generated content</h2>
          <div class="sh-panel__actions">
            <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-regenerate="category_content">Regenerate</button>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-copy>Copy</button>
            <button class="sh-btn sh-btn--sm" data-ai-save="category_content">Save</button>
          </div>
        </div>
        <div class="sh-panel__body"><div class="sh-airesult-box" data-ai-output>—</div></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> Saved fields</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note">Writes to <code>categories.description</code>, <code>meta_title</code>,
        <code>meta_description</code> and <code>meta_keywords</code>. The three SEO columns were added by the
        AI installer because the store did not have them.</p>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
