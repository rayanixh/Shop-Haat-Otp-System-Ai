<?php
require_once __DIR__ . '/_ai.php';

$pid = sh_int($_GET['id'] ?? 0);
$q = trim(sh_get('q'));
$product = null;
$tags = [];

if ($pid > 0) {
    $product = sh_one(
        'SELECT p.*, c.name AS category_name, b.name AS brand_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN brands b ON b.id = p.brand_id
         WHERE p.id = ? LIMIT 1', [$pid]
    );
    if ($product !== null && $aiInstalled) {
        try { $tags = array_column(sh_all('SELECT tag FROM product_tags WHERE product_id = ? ORDER BY tag', [$pid]), 'tag'); }
        catch (Throwable $e) { $tags = []; }
    }
}

$list = $q !== ''
    ? sh_all('SELECT id, name, image FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name ASC LIMIT 25',
        ['%' . $q . '%', '%' . $q . '%'])
    : sh_all('SELECT id, name, image FROM products ORDER BY id DESC LIMIT 25');

$adminPage = 'ai_product';
$adminTitle = 'Product AI';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('box', 20) ?> Product AI</h2>
    <p class="sh-aihead__sub">Generate titles, descriptions, tags and SEO for a single product.</p>
  </div>
</div>

<?php sh_ai_subnav('product'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-aigrid">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">
    <?php if ($product === null): ?>
      <div class="sh-panel">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('search', 17) ?> Choose a product</h2></div>
        <div class="sh-panel__body" style="padding-bottom:0">
          <form class="sh-filterbar" method="get">
            <div class="sh-field"><label class="sh-field__label" for="ai-q">Search</label>
              <input class="sh-input" id="ai-q" name="q" value="<?= e($q) ?>" placeholder="Product name or SKU"></div>
            <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('search', 14) ?> Search</button>
            <?php if ($q !== ''): ?>
              <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/products.php')) ?>">Reset</a>
            <?php endif; ?>
          </form>
        </div>
        <div class="sh-tablewrap">
          <table class="sh-table">
            <thead><tr><th>Product</th><th style="text-align:right">Action</th></tr></thead>
            <tbody>
            <?php if (!$list): ?>
              <tr class="sh-table--empty"><td colspan="2">No products found.</td></tr>
            <?php else: foreach ($list as $p): ?>
              <tr>
                <td><div class="sh-table__cell">
                  <img class="sh-table__thumb" src="<?= e(sh_product_image($p['image'])) ?>" alt="" loading="lazy">
                  <div class="sh-table__name"><?= e((string)$p['name']) ?></div></div></td>
                <td style="text-align:right">
                  <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/ai/products.php?id=' . (int)$p['id'])) ?>">Open</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php else: ?>
      <div class="sh-panel" data-ai-product="<?= (int)$product['id'] ?>">
        <div class="sh-panel__head">
          <h2 class="sh-panel__title"><?= sh_icon('box', 17) ?> <?= e((string)$product['name']) ?></h2>
          <div class="sh-panel__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/products.php?edit=' . (int)$product['id'])) ?>">Edit product</a>
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/products.php')) ?>">Change</a>
          </div>
        </div>
        <div class="sh-panel__body">
          <div class="sh-aiproduct">
            <img class="sh-aiproduct__img" src="<?= e(sh_product_image($product['image'])) ?>" alt="" loading="lazy">
            <div class="sh-aiproduct__meta">
              <p><strong>Category:</strong> <?= e((string)($product['category_name'] ?? '—')) ?></p>
              <p><strong>Brand:</strong> <?= e((string)($product['brand_name'] ?? '—')) ?></p>
              <p><strong>Price:</strong> <?= e(sh_money($product['price'])) ?></p>
              <p><strong>Tags:</strong> <?= $tags ? e(implode(', ', $tags)) : 'none yet' ?></p>
            </div>
          </div>

          <div class="sh-aiactions">
            <button class="sh-btn sh-btn--sm" data-ai-generate="title" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate title</button>
            <button class="sh-btn sh-btn--sm" data-ai-generate="description" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate description</button>
            <button class="sh-btn sh-btn--sm" data-ai-generate="short" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Short description</button>
            <button class="sh-btn sh-btn--sm" data-ai-generate="tags" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate tags</button>
            <button class="sh-btn sh-btn--sm" data-ai-generate="seo" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate SEO</button>
            <button class="sh-btn sh-btn--sm" data-ai-generate="category" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Suggest category</button>
            <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-generate-all <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate all</button>
          </div>
        </div>
      </div>

      <?php
      $fields = [
        'title' => ['Title', 'input', (string)$product['name']],
        'description' => ['Description', 'html', (string)$product['description']],
        'short' => ['Short description', 'textarea', (string)$product['short_description']],
        'tags' => ['Tags', 'tags', implode(', ', $tags)],
        'seo' => ['SEO', 'seo', ''],
        'category' => ['Category suggestion', 'category', (string)($product['category_name'] ?? '')],
      ];
      foreach ($fields as $key => [$label, $kind, $current]): ?>
        <div class="sh-panel sh-aiblock" data-ai-block="<?= e($key) ?>" hidden>
          <div class="sh-panel__head">
            <h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> <?= e($label) ?></h2>
            <div class="sh-panel__actions">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-regenerate="<?= e($key) ?>">Regenerate</button>
              <button class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-copy>Copy</button>
              <button class="sh-btn sh-btn--sm" data-ai-save="<?= e($key) ?>">Save</button>
            </div>
          </div>
          <div class="sh-panel__body">
            <?php if ($kind !== 'seo' && $kind !== 'category'): ?>
              <p class="sh-field__label">Current</p>
              <div class="sh-aicurrent"><?= $current !== '' ? e(sh_excerpt(strip_tags($current), 220)) : '<em>empty</em>' ?></div>
            <?php endif; ?>
            <p class="sh-field__label" style="margin-top:10px">AI generated</p>
            <div class="sh-airesult-box" data-ai-output>—</div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> How this works</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note">
        Each button sends the product's real data to <?= e($aiConfig['has_key'] ? $aiConfig['provider'] : 'your AI provider') ?>
        and shows the result for review. Nothing is written to the database until you press
        <strong>Save</strong><?= $aiConfig['auto_save'] ? ' — except that Auto save is currently ON, so text fields are applied immediately.' : '.' ?>
      </p>
      <p class="sh-panel__note" style="margin-top:8px">
        Prompts include the product name, category, brand, price and any existing description, and instruct
        the model not to invent specifications.
      </p>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
