<?php
require_once __DIR__ . '/_ai.php';
require_once SH_ROOT . '/includes/product-schema.php';
sh_product_schema_ensure();

// The create flow needs the real category list and an honest answer about
// whether an image model is configured at all.
$aiCats = ($aiInstalled ? sh_all('SELECT id, name FROM categories ORDER BY name ASC') : []);
$aiImageReady = $aiInstalled ? sh_ai_image_generation_ready() : ['ok' => false, 'error' => 'AI Auto Work is not installed.'];
$aiCanCreate = sh_admin_can('products.create');
if ($aiCanCreate) {
    $adminScripts = is_array($adminScripts ?? null) ? $adminScripts : [];
    $adminScripts['ai_product_create'] = 'assets/js/ai-product-create.js';
}

$pid = sh_int($_GET['id'] ?? 0);
$q = trim(sh_get('q'));
$product = null;
$tags = [];

if ($pid > 0) {
    $product = sh_one(
        'SELECT p.*, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
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
    <p class="sh-aihead__sub">Generate a complete product from its name, or improve fields of a product that already exists.</p>
  </div>
</div>

<?php sh_ai_subnav('product'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<?php if ($aiCanCreate): ?>
<div class="sh-panel sh-aicreate" data-ai-create
     data-endpoint="<?= e(sh_url('api/ai.php')) ?>"
     data-image-ready="<?= !empty($aiImageReady['ok']) ? '1' : '0' ?>">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Generate Full Product</h2>
    <div class="sh-panel__actions">
      <span class="sh-badge <?= !empty($aiImageReady['ok']) ? 'sh-badge--ok' : '' ?>">
        <?= !empty($aiImageReady['ok']) ? 'Image generation ready' : 'Image generation not configured' ?>
      </span>
    </div>
  </div>
  <div class="sh-panel__body">

    <form class="sh-aicreate__start" data-ai-create-start novalidate>
      <?= sh_csrf_field() ?>
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="aic-name">Product name <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="aic-name" name="name" maxlength="190" required
                 placeholder="e.g. Stainless Steel Electric Kettle 1.8L" autocomplete="off">
          <span class="sh-field__hint">The only required input. Everything else is generated from it.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="aic-notes">Anything the AI must know (optional)</label>
          <input class="sh-input" id="aic-notes" name="notes" maxlength="600"
                 placeholder="e.g. 2 year warranty card included, matte black" autocomplete="off">
          <span class="sh-field__hint">Facts typed here are used as-is; the model is told not to invent the rest.</span>
        </div>
      </div>

      <label class="sh-check" style="margin:2px 0 10px">
        <input type="checkbox" name="want_gallery" value="1" data-ai-want-gallery>
        <span>Also generate two gallery images (uses more image credits)</span>
      </label>

      <?php if (empty($aiImageReady['ok'])): ?>
        <div class="sh-alert sh-alert--warning" style="margin-bottom:12px">
          <?= sh_icon('alert', 17) ?>
          <div><strong>Product images cannot be generated yet.</strong>
            <?= e((string)($aiImageReady['error'] ?? 'No image model is configured.')) ?>
            You can still generate the text and upload a photo in the review step.</div>
        </div>
      <?php endif; ?>

      <div class="sh-aicreate__bar">
        <button class="sh-btn" type="submit" data-ai-create-run <?= ($aiInstalled && !empty($aiConfig['has_key'])) ? '' : 'disabled' ?>>
          <?= sh_icon('cpu', 15) ?> Generate Full Product
        </button>
        <span class="sh-aicreate__status" data-ai-create-status role="status" aria-live="polite"></span>
      </div>
      <p class="sh-field__hint" style="margin-top:6px">
        Nothing is saved by generating. The draft opens below for review, and the product is created only when you press <strong>Save Product</strong>.
      </p>
    </form>

    <!-- Review form. Hidden until a draft arrives. -->
    <form class="sh-aicreate__review" data-ai-create-review hidden novalidate data-dirty-guard>
      <?= sh_csrf_field() ?>

      <div class="sh-aicreate__notice" data-ai-create-notice hidden></div>

      <div class="sh-aicreate__grid">
        <div class="sh-aicreate__fields">
          <div class="sh-grid2">
            <div class="sh-field"><label class="sh-field__label" for="aic-r-name">Product name <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="aic-r-name" data-f="name" maxlength="190" required></div>
            <div class="sh-field"><label class="sh-field__label" for="aic-r-slug">URL slug</label>
              <input class="sh-input" id="aic-r-slug" data-f="slug" maxlength="190">
              <span class="sh-field__hint">Checked for uniqueness; the storefront URL is built from it.</span></div>
          </div>

          <div class="sh-grid2">
            <div class="sh-field"><label class="sh-field__label" for="aic-r-cat">Category <span class="sh-field__req">*</span></label>
              <select class="sh-select" id="aic-r-cat" data-f="category_id">
                <option value="">Choose an existing category</option>
                <?php foreach ($aiCats as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= e((string)$c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="sh-field__hint" data-ai-category-note hidden></span></div>
            <div class="sh-field"><label class="sh-field__label" for="aic-r-sku">SKU</label>
              <input class="sh-input" id="aic-r-sku" data-f="sku" maxlength="60"></div>
          </div>

          <div class="sh-grid3">
            <div class="sh-field"><label class="sh-field__label" for="aic-r-price">Selling price <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="aic-r-price" data-f="price" inputmode="decimal" required>
              <span class="sh-field__hint">AI never invents a price — enter the real one.</span></div>
            <div class="sh-field"><label class="sh-field__label" for="aic-r-compare">Compare-at price</label>
              <input class="sh-input" id="aic-r-compare" data-f="compare_price" inputmode="decimal">
              <span class="sh-field__hint">Optional. 0 or empty means no discount.</span></div>
            <div class="sh-field"><label class="sh-field__label" for="aic-r-stock">Stock quantity <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="aic-r-stock" data-f="stock" type="number" min="0" step="1" value="0">
              <span class="sh-field__hint">AI never invents stock — enter the real count.</span></div>
          </div>

          <div class="sh-field"><label class="sh-field__label" for="aic-r-short">Short description</label>
            <input class="sh-input" id="aic-r-short" data-f="short_description" maxlength="300"></div>
          <div class="sh-field"><label class="sh-field__label" for="aic-r-desc">Full description</label>
            <textarea class="sh-textarea" id="aic-r-desc" data-f="description" rows="8"></textarea></div>
          <div class="sh-field"><label class="sh-field__label" for="aic-r-specs">Specifications</label>
            <textarea class="sh-textarea" id="aic-r-specs" data-f="specifications" rows="6"
                      placeholder="One per line: Label: Value"></textarea></div>
          <div class="sh-field"><label class="sh-field__label" for="aic-r-tags">Product tags</label>
            <input class="sh-input" id="aic-r-tags" data-f="tags" placeholder="comma, separated, tags">
            <span class="sh-field__hint">Stored in the existing product_tags table.</span></div>

          <div class="sh-formsec" style="margin-top:4px">
            <h3 class="sh-formsec__title"><?= sh_icon('search', 15) ?> Search engine fields</h3>
            <div class="sh-grid2">
              <div class="sh-field"><label class="sh-field__label" for="aic-r-mtitle">SEO title</label>
                <input class="sh-input" id="aic-r-mtitle" data-f="meta_title"></div>
              <div class="sh-field"><label class="sh-field__label" for="aic-r-mdesc">SEO meta description</label>
                <input class="sh-input" id="aic-r-mdesc" data-f="meta_description"></div>
            </div>
            <div class="sh-grid3">
              <div class="sh-field"><label class="sh-field__label" for="aic-r-focus">Focus keyword</label>
                <input class="sh-input" id="aic-r-focus" data-f="focus_keyword" maxlength="120"></div>
              <div class="sh-field"><label class="sh-field__label" for="aic-r-related">Related phrases</label>
                <input class="sh-input" id="aic-r-related" data-f="related_phrases" maxlength="500"></div>
              <div class="sh-field"><label class="sh-field__label" for="aic-r-keywords">SEO keywords</label>
                <input class="sh-input" id="aic-r-keywords" data-f="keywords" maxlength="300"></div>
            </div>
          </div>

          <div class="sh-grid2">
            <div class="sh-field"><label class="sh-field__label" for="aic-r-alt">Image alt text</label>
              <input class="sh-input" id="aic-r-alt" data-f="image_alt" maxlength="190"></div>
            <div class="sh-field"><label class="sh-check" style="margin-top:28px">
              <input type="checkbox" data-f="status" checked>
              <span>Visible on the storefront when saved</span></label></div>
          </div>
        </div>

        <div class="sh-aicreate__side">
          <div class="sh-field">
            <label class="sh-field__label">Featured image</label>
            <div class="sh-aicreate__image" data-ai-image-wrap>
              <img data-ai-image alt="" hidden>
              <span class="sh-aicreate__imageempty" data-ai-image-empty>No image yet</span>
            </div>
            <div class="sh-aicreate__image-actions">
              <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-ai-image-retry>
                <?= sh_icon('refresh', 14) ?> Generate image</button>
              <label class="sh-btn sh-btn--sm sh-btn--ghost" for="aic-r-upload">
                <?= sh_icon('image', 14) ?> Upload my own</label>
              <input class="sh-sr-only" id="aic-r-upload" type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-ai-upload>
            </div>
            <p class="sh-field__hint" data-ai-image-note></p>
            <input type="hidden" data-ai-image-file>
            <div class="sh-aicreate__gallery" data-ai-gallery hidden></div>
          </div>

          <div class="sh-aicreate__notes" data-ai-notes hidden></div>
        </div>
      </div>

      <div class="sh-formcard__foot">
        <button class="sh-btn" type="submit" data-ai-save-product><?= sh_icon('check-circle', 15) ?> Save Product</button>
        <button class="sh-btn sh-btn--ghost" type="button" data-ai-discard>Discard draft</button>
        <span class="sh-dirty" data-dirty-note hidden><?= sh_icon('info', 13) ?> Unsaved changes</span>
        <span class="sh-aicreate__status" data-ai-save-status role="status" aria-live="polite"></span>
      </div>
    </form>

    <div class="sh-aicreate__saved" data-ai-create-saved hidden>
      <div class="sh-alert sh-alert--success"><?= sh_icon('check-circle', 17) ?>
        <div><strong>Product saved</strong> <span data-ai-saved-message></span></div></div>
      <div class="sh-aicreate__savedactions">
        <a class="sh-btn sh-btn--sm" data-ai-saved-edit href="#"><?= sh_icon('pencil', 14) ?> Edit product</a>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-saved-view target="_blank" rel="noopener" href="#"><?= sh_icon('external', 14) ?> View on store</a>
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-ai-saved-again><?= sh_icon('plus', 14) ?> Create another</button>
      </div>
      <p class="sh-field__hint" data-ai-saved-verify></p>
    </div>
  </div>
</div>
<?php endif; ?>

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
              <p><strong>Price:</strong> <?= e(sh_money($product['price'])) ?></p>
              <p><strong>Tags:</strong> <?= $tags ? e(implode(', ', $tags)) : 'none yet' ?></p>
            </div>
          </div>

          <div class="sh-aiactions">
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="title" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate title</button>
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="description" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate description</button>
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="short" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Short description</button>
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="tags" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate tags</button>
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="seo" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate SEO</button>
            <button type="button" class="sh-btn sh-btn--sm" data-ai-generate="category" <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Suggest category</button>
            <button type="button" class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-generate-all <?= $aiConfig['has_key'] ? '' : 'disabled' ?>>Generate all</button>
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
              <button type="button" class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-regenerate="<?= e($key) ?>">Regenerate</button>
              <button type="button" class="sh-btn sh-btn--sm sh-btn--ghost" data-ai-copy>Copy</button>
              <button type="button" class="sh-btn sh-btn--sm" data-ai-save="<?= e($key) ?>">Save</button>
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
        <strong>Save product</strong> on the review screen.
      </p>
      <p class="sh-panel__note" style="margin-top:8px">
        Prompts include the product name, category, price and any existing description, and instruct
        the model not to invent specifications.
      </p>
    </div>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
