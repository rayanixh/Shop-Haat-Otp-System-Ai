<?php
/**
 * Admin — shopper-facing brands, product types and common colour swatches.
 *
 * These are dictionary rows, not product copy. Product URLs, descriptions,
 * order snapshots and the legacy fulfilment `products.product_type` ENUM are
 * untouched. Records used by existing products cannot be deleted; hide them
 * instead so historical data stays intact.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';
require_once SH_ROOT . '/includes/product-schema.php';
sh_product_schema_ensure();

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('catalog.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

/** @return array{table:string,label:string,singular:string,has_hex:bool} */
function sh_attributes_config(string $kind): array
{
    return match ($kind) {
        'brands'  => ['table' => 'brands',         'label' => 'Brands',        'singular' => 'Brand',        'has_hex' => false],
        'types'   => ['table' => 'product_types',  'label' => 'Product types', 'singular' => 'Product type', 'has_hex' => false],
        'colours' => ['table' => 'product_colours', 'label' => 'Common colours','singular' => 'Colour',       'has_hex' => true],
        default => throw new InvalidArgumentException('Unknown attribute group.'),
    };
}

function sh_attributes_rows(string $kind): array
{
    return match ($kind) {
        'brands'  => sh_all('SELECT id, name, slug, status FROM brands ORDER BY name ASC'),
        'types'   => sh_all('SELECT id, name, slug, status FROM product_types ORDER BY name ASC'),
        'colours' => sh_all('SELECT id, name, slug, hex, status FROM product_colours ORDER BY name ASC'),
        default => [],
    };
}

function sh_attributes_find(string $kind, int $id): ?array
{
    return match ($kind) {
        'brands'  => sh_one('SELECT id, name, slug, status FROM brands WHERE id = ? LIMIT 1', [$id]),
        'types'   => sh_one('SELECT id, name, slug, status FROM product_types WHERE id = ? LIMIT 1', [$id]),
        'colours' => sh_one('SELECT id, name, slug, hex, status FROM product_colours WHERE id = ? LIMIT 1', [$id]),
        default => null,
    };
}

function sh_attributes_slug(string $kind, string $name, int $id): string
{
    $cfg = sh_attributes_config($kind);
    $base = sh_slug($name);
    if ($base === '') { $base = 'item'; }
    $slug = mb_substr($base, 0, 140);
    $suffix = 2;
    while (true) {
        $dupe = sh_one('SELECT id FROM `' . $cfg['table'] . '` WHERE slug = ? AND id <> ? LIMIT 1', [$slug, $id]);
        if ($dupe === null) { return $slug; }
        $slug = mb_substr($base, 0, 134) . '-' . $suffix++;
    }
}

/** @return array{ok:bool,message:string} */
function sh_attributes_save(string $kind, int $id, string $name, string $hex, int $status): array
{
    $cfg = sh_attributes_config($kind);
    $name = trim(mb_substr($name, 0, 120));
    if ($name === '') {
        return ['ok' => false, 'message' => $cfg['singular'] . ' name cannot be empty.'];
    }
    $status = $status === 1 ? 1 : 0;
    $slug = sh_attributes_slug($kind, $name, $id);
    $hex = sh_product_clean_hex($hex);
    try {
        if ($id > 0) {
            $existing = sh_attributes_find($kind, $id);
            if ($existing === null) { return ['ok' => false, 'message' => 'That record no longer exists.']; }
            if ($kind === 'brands') {
                sh_update('brands', ['name' => $name, 'slug' => $slug, 'status' => $status], 'id = ?', [$id]);
            } elseif ($kind === 'types') {
                sh_update('product_types', ['name' => $name, 'slug' => $slug, 'status' => $status], 'id = ?', [$id]);
            } else {
                sh_update('product_colours', ['name' => $name, 'slug' => $slug, 'hex' => $hex, 'status' => $status], 'id = ?', [$id]);
            }
            sh_audit('catalog_attribute_updated', $cfg['singular'], $id, $name, $existing, ['name' => $name, 'status' => $status]);
        } else {
            if ($kind === 'brands') {
                $newId = sh_insert('brands', ['name' => $name, 'slug' => $slug, 'status' => $status]);
            } elseif ($kind === 'types') {
                $newId = sh_insert('product_types', ['name' => $name, 'slug' => $slug, 'status' => $status]);
            } else {
                $newId = sh_insert('product_colours', ['name' => $name, 'slug' => $slug, 'hex' => $hex, 'status' => $status]);
            }
            sh_audit('catalog_attribute_created', $cfg['singular'], $newId, $name, null, ['name' => $name, 'status' => $status]);
        }
        return ['ok' => true, 'message' => $cfg['singular'] . ' saved.'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'catalog-attribute-save');
        return ['ok' => false, 'message' => 'The record could not be saved. The error has been logged.'];
    }
}

function sh_attributes_usage_count(string $kind, int $id): int
{
    try {
        if ($kind === 'brands') {
            return (int)sh_val('SELECT COUNT(*) FROM products WHERE brand_id = ?', [$id], 0);
        }
        if ($kind === 'types') {
            if (!sh_table_has_column('products', 'catalog_type_id')) { return 0; }
            return (int)sh_val('SELECT COUNT(*) FROM products WHERE catalog_type_id = ?', [$id], 0);
        }
        return (int)sh_val('SELECT COUNT(*) FROM product_colour_selections WHERE colour_id = ?', [$id], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function sh_attributes_toggle(string $kind, int $id): void
{
    $row = sh_attributes_find($kind, $id);
    if ($row === null) { return; }
    $status = (int)$row['status'] === 1 ? 0 : 1;
    if ($kind === 'brands') {
        sh_update('brands', ['status' => $status], 'id = ?', [$id]);
    } elseif ($kind === 'types') {
        sh_update('product_types', ['status' => $status], 'id = ?', [$id]);
    } else {
        sh_update('product_colours', ['status' => $status], 'id = ?', [$id]);
    }
    sh_audit('catalog_attribute_toggled', sh_attributes_config($kind)['singular'], $id, (string)$row['name'], ['status' => (int)$row['status']], ['status' => $status]);
}

/** @return array{ok:bool,message:string} */
function sh_attributes_delete(string $kind, int $id): array
{
    $cfg = sh_attributes_config($kind);
    $row = sh_attributes_find($kind, $id);
    if ($row === null) { return ['ok' => false, 'message' => 'That record no longer exists.']; }
    $used = sh_attributes_usage_count($kind, $id);
    if ($used > 0) {
        return ['ok' => false, 'message' => $cfg['singular'] . ' "' . $row['name'] . '" is used by ' . $used
            . ' product selection(s). Hide it instead so existing product data stays intact.'];
    }
    try {
        if ($kind === 'brands') {
            sh_query('DELETE FROM brands WHERE id = ?', [$id]);
        } elseif ($kind === 'types') {
            sh_query('DELETE FROM product_types WHERE id = ?', [$id]);
        } else {
            sh_query('DELETE FROM product_colours WHERE id = ?', [$id]);
        }
        sh_audit('catalog_attribute_deleted', $cfg['singular'], $id, (string)$row['name'], $row, null);
        return ['ok' => true, 'message' => $cfg['singular'] . ' deleted.'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'catalog-attribute-delete');
        return ['ok' => false, 'message' => 'The record could not be deleted. The error has been logged.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $kind = sh_post('kind');
    $action = sh_post('action');
    try {
        $cfg = sh_attributes_config($kind);
    } catch (Throwable $e) {
        sh_redirect('admin/product-attributes.php');
    }
    $id = sh_int($_POST['id'] ?? 0);
    $result = ['ok' => true, 'message' => ''];
    if ($action === 'save') {
        $result = sh_attributes_save(
            $kind,
            $id,
            sh_post('name'),
            sh_post('hex'),
            !empty($_POST['status']) ? 1 : 0
        );
    } elseif ($action === 'toggle') {
        sh_attributes_toggle($kind, $id);
        $result = ['ok' => true, 'message' => $cfg['singular'] . ' visibility updated.'];
    } elseif ($action === 'delete') {
        $result = sh_attributes_delete($kind, $id);
    } else {
        $result = ['ok' => false, 'message' => 'Unknown action.'];
    }
    sh_flash($result['ok'] ? 'success' : 'error', $result['message']);
    sh_redirect('admin/product-attributes.php#' . $kind);
}

$groups = [
    'brands'  => sh_attributes_config('brands'),
    'types'   => sh_attributes_config('types'),
    'colours' => sh_attributes_config('colours'),
];
$adminPage = 'attributes';
$adminTitle = 'Brands, Types & Colours';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Brands, Types & Colours',
    'Manage the structured choices used by the product editor. Product URLs, orders and existing product descriptions are not changed here.',
    '',
    'tag'
) ?>

<div class="sh-attrs__grid">
  <?php foreach ($groups as $kind => $cfg): ?>
    <?php $rows = sh_attributes_rows($kind); ?>
    <section class="sh-panel sh-attrs__panel" id="<?= e($kind) ?>" aria-labelledby="attrs-<?= e($kind) ?>-title">
      <div class="sh-panel__head">
        <h2 class="sh-panel__title" id="attrs-<?= e($kind) ?>-title"><?= sh_icon('tag', 16) ?> <?= e($cfg['label']) ?></h2>
        <span class="sh-pg__count"><?= count($rows) ?></span>
      </div>
      <div class="sh-panel__body">
        <?php if (!$rows): ?>
          <p class="sh-pg__hint">No <?= e(strtolower($cfg['label'])) ?> yet. Add the first one below.</p>
        <?php else: ?>
          <div class="sh-attrs__list">
            <?php foreach ($rows as $row): ?>
              <form class="sh-attrs__item" method="post">
                <?= sh_csrf_field() ?>
                <input type="hidden" name="kind" value="<?= e($kind) ?>">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <div>
                  <input class="sh-input sh-input--sm" type="text" name="name" maxlength="120" value="<?= e($row['name']) ?>" aria-label="<?= e($cfg['singular']) ?> name">
                  <?php if ($cfg['has_hex']): ?>
                    <input type="color" name="hex" value="<?= e(sh_product_clean_hex($row['hex'])) ?>" aria-label="<?= e($cfg['singular']) ?> swatch">
                  <?php endif; ?>
                  <small>
                    <?php if ($cfg['has_hex']): ?><span class="sh-attrs__dot" style="background:<?= e(sh_product_clean_hex($row['hex'])) ?>"></span><?php endif; ?>
                    <?= (int)$row['status'] === 1 ? 'Available' : 'Hidden' ?> · <?= e($row['slug']) ?>
                  </small>
                </div>
                <div class="sh-attrs__actions">
                  <label class="sh-pg__hint" style="margin:0"><input type="checkbox" name="status" value="1" <?= (int)$row['status'] === 1 ? 'checked' : '' ?>> Active</label>
                  <button class="sh-btn sh-btn--sm" type="submit" name="action" value="save">Save</button>
                  <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" name="action" value="toggle"><?= (int)$row['status'] === 1 ? 'Hide' : 'Show' ?></button>
                  <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" name="action" value="delete"
                          data-confirm="Delete <?= e($cfg['singular']) ?> &quot;<?= e($row['name']) ?>&quot;?">Delete</button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form class="sh-attrs__form" method="post">
          <?= sh_csrf_field() ?>
          <input type="hidden" name="kind" value="<?= e($kind) ?>">
          <input type="hidden" name="id" value="0">
          <input type="hidden" name="status" value="1">
          <div class="sh-field"><label class="sh-field__label" for="attr-new-<?= e($kind) ?>">Add <?= e(strtolower($cfg['singular'])) ?></label>
            <input class="sh-input" id="attr-new-<?= e($kind) ?>" name="name" maxlength="120" placeholder="<?= e($cfg['singular']) ?> name"></div>
          <?php if ($cfg['has_hex']): ?>
            <div class="sh-field"><label class="sh-field__label" for="attr-hex-<?= e($kind) ?>">Swatch</label>
              <input type="color" id="attr-hex-<?= e($kind) ?>" name="hex" value="#888888"></div>
          <?php endif; ?>
          <button class="sh-btn sh-btn--sm" type="submit" name="action" value="save"><?= sh_icon('plus', 13) ?> Add</button>
        </form>
      </div>
    </section>
  <?php endforeach; ?>
</div>

<p class="sh-table__meta" style="margin-top:12px">
  A used record is protected from deletion. Hiding it removes it from new product choices without altering existing products or order history.
</p>

<?php require __DIR__ . '/_footer.php'; ?>
