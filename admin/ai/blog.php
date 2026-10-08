<?php
require_once __DIR__ . '/_ai.php';

$posts = [];
if ($aiInstalled) {
    try { $posts = sh_all('SELECT * FROM blog_posts ORDER BY id DESC LIMIT 15'); }
    catch (Throwable $e) { $posts = []; }
}

$adminPage = 'ai_blog';
$adminTitle = 'Blog AI';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('file', 20) ?> Blog AI</h2>
    <p class="sh-aihead__sub">Draft a complete article with headings, excerpt and SEO metadata.</p>
  </div>
</div>

<?php sh_ai_subnav('blog'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-aigrid">
  <div class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> Article brief</h2></div>
    <div class="sh-panel__body">
      <div class="sh-field">
        <label class="sh-field__label" for="bl-topic">Topic <span class="sh-field__req">*</span></label>
        <input class="sh-input" id="bl-topic" data-ai-blog="topic" placeholder="Best summer fashion products">
      </div>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="bl-aud">Target audience</label>
          <input class="sh-input" id="bl-aud" data-ai-blog="audience" placeholder="Young professionals in Dhaka">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="bl-len">Approx. length (words)</label>
          <input class="sh-input" id="bl-len" data-ai-blog="length" type="number" min="200" max="2000" value="700">
        </div>
      </div>
      <div class="sh-field">
        <label class="sh-field__label" for="bl-kw">Keywords</label>
        <input class="sh-input" id="bl-kw" data-ai-blog="keywords" placeholder="summer fashion, cotton shirt">
        <span class="sh-field__hint">Comma separated. Language and tone come from AI Settings.</span>
      </div>
      <button class="sh-btn sh-btn--block" data-ai-blog-generate <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>
        <?= sh_icon('zap', 15) ?> Generate blog post</button>

      <div class="sh-aiblogout" data-ai-blog-output hidden>
        <p class="sh-field__label" style="margin-top:14px">Preview</p>
        <div class="sh-airesult-box" data-ai-blog-preview></div>
        <div class="sh-aiactions" style="margin-top:10px">
          <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-blog-generate>Regenerate</button>
          <button class="sh-btn sh-btn--sm" data-ai-blog-save="draft">Save as draft</button>
          <button class="sh-btn sh-btn--sm" data-ai-blog-save="publish">Publish</button>
        </div>
        <p class="sh-panel__note" style="margin-top:8px">
          <?= $aiConfig['auto_publish']
            ? 'Auto publish is ON — “Publish” goes live immediately.'
            : 'Auto publish is OFF, so “Publish” still saves a draft. Enable it in AI Settings to publish directly.' ?>
        </p>
      </div>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Recent posts</h2></div>
    <div class="sh-tablewrap">
      <table class="sh-table">
        <thead><tr><th>Title</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        <?php if (!$posts): ?>
          <tr class="sh-table--empty"><td colspan="3">No posts yet.</td></tr>
        <?php else: foreach ($posts as $b): ?>
          <tr>
            <td class="sh-table__name"><?= e((string)$b['title']) ?>
              <div class="sh-table__meta">/<?= e((string)$b['slug']) ?></div></td>
            <td><span class="sh-badge <?= $b['status'] === 'published' ? 'sh-badge--ok' : '' ?>">
              <?= e((string)$b['status']) ?></span></td>
            <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime((string)$b['created_at']))) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
