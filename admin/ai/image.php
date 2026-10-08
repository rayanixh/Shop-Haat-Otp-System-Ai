<?php
require_once __DIR__ . '/_ai.php';

$pid = sh_int($_GET['id'] ?? 0);
$product = $pid > 0 ? sh_one('SELECT p.*, c.name AS category_name FROM products p
    LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1', [$pid]) : null;
$list = sh_all('SELECT id, name FROM products ORDER BY id DESC LIMIT 50');
$imageTargets = $aiInstalled ? sh_ai_image_capable_targets() : [];
$imageRoute = $aiInstalled ? sh_ai_route('product_image') : ['provider' => null, 'model' => ''];
$routeOk = $imageRoute['provider'] !== null && $imageRoute['model'] !== ''
    && sh_ai_model_supports((int)$imageRoute['provider']['id'], $imageRoute['model'], 'image');

$adminPage = 'ai_image';
$adminTitle = 'Image AI';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('image', 20) ?> Image AI</h2>
    <p class="sh-aihead__sub">Generate a product photo and attach it to the product.</p>
  </div>
</div>

<?php sh_ai_subnav('image'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-aigrid">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">
    <div class="sh-panel">
      <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> Choose a product</h2></div>
      <div class="sh-panel__body">
        <div class="sh-field">
          <label class="sh-field__label" for="img-pick">Product</label>
          <select class="sh-select" id="img-pick" onchange="if(this.value)location.href=this.value">
            <option value="">Select…</option>
            <?php foreach ($list as $r): ?>
              <option value="<?= e(sh_url('admin/ai/image.php?id=' . (int)$r['id'])) ?>"
                <?= $pid === (int)$r['id'] ? 'selected' : '' ?>><?= e((string)$r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($product !== null): ?>
          <div class="sh-aiproduct" style="margin-top:6px">
            <img class="sh-aiproduct__img" src="<?= e(sh_product_image($product['image'])) ?>" alt="" loading="lazy">
            <div class="sh-aiproduct__meta">
              <p><strong>Current image</strong></p>
              <p class="sh-table__meta"><?= e((string)($product['image'] ?: 'none')) ?></p>
            </div>
          </div>

          <div data-ai-product="<?= (int)$product['id'] ?>">
            <?php if (!$routeOk && $aiConfig['has_key']): ?>
              <div class="sh-alert sh-alert--warning" style="margin-top:12px"><?= sh_icon('alert', 17) ?>
                <div><strong>This provider/model does not support image generation.</strong>
                  <?= $imageRoute['provider'] ? e((string)$imageRoute['provider']['name']) . ($imageRoute['model'] !== '' ? ' · ' . e($imageRoute['model']) : ' has no image model set') : 'No provider is routed for images' ?>.
                  <?= $imageTargets ? 'Pick a compatible provider below.' : 'Add an image-capable model under <a href="' . e(sh_url('admin/ai/models.php')) . '">Models</a> (e.g. OpenAI gpt-image-1, Gemini imagen-4.0-generate-001, or an OpenRouter model with image output).' ?>
                </div></div>
            <?php endif; ?>
            <div class="sh-field" style="margin-top:12px">
              <label class="sh-field__label" for="img-target">Provider / model</label>
              <select class="sh-select" id="img-target" data-ai-image-target>
                <option value="" <?= $routeOk ? '' : 'disabled' ?>><?= $routeOk ? 'Routed: ' . e((string)$imageRoute['provider']['name']) . ' · ' . e($imageRoute['model']) : 'Choose an image-capable provider…' ?></option>
                <?php foreach ($imageTargets as $t): ?>
                  <option value="<?= (int)$t['provider_id'] ?>|<?= e($t['model']) ?>"><?= e($t['provider']) ?> · <?= e($t['model_name'] !== $t['model'] ? $t['model_name'] . ' (' . $t['model'] . ')' : $t['model']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="sh-field__hint">Only models flagged as image output are listed. Change the default route in AI Settings → Task routing.</span>
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="img-prompt">Image prompt</label>
              <textarea class="sh-textarea" id="img-prompt" data-ai-image-prompt rows="4"
                        placeholder="Leave blank and press “Suggest prompt” to build one from the product data."></textarea>
            </div>
            <div class="sh-aiactions">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-generate="image_prompt"
                      <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Suggest prompt</button>
              <button class="sh-btn" data-ai-image-generate <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>
                <?= sh_icon('image', 15) ?> Generate image</button>
            </div>
            <p class="sh-panel__note" style="margin-top:8px">
              The generated file is saved to the existing product upload folder. Your current product image is
              <strong>not</strong> replaced until you press “Use this image”.
            </p>

            <div class="sh-aiblock" data-ai-block="image_prompt" hidden style="margin-top:12px">
              <p class="sh-field__label">Suggested prompt</p>
              <div class="sh-airesult-box" data-ai-output>—</div>
            </div>

            <div class="sh-aiimageout" data-ai-image-output hidden style="margin-top:12px">
              <p class="sh-field__label">Generated image</p>
              <img class="sh-aiimage" data-ai-image-preview src="" alt="Generated product image">
              <div class="sh-aiactions" style="margin-top:10px">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-image-generate>Regenerate</button>
                <button class="sh-btn sh-btn--sm" data-ai-image-use>Use this image</button>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> Notes</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note">Images are validated with <code>getimagesize()</code> before being written, so a
        non-image response can never land in the uploads folder. Capability is checked before any request: a text-only
        model is refused, not attempted. <?= count($imageTargets) ?> image-capable model<?= count($imageTargets) === 1 ? '' : 's' ?> configured.</p>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
