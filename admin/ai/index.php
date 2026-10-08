<?php
require_once __DIR__ . '/_ai.php';

$stats = sh_ai_stats();
$recent = [];
if ($aiInstalled) {
    try {
        $recent = sh_all(
            'SELECT g.*, p.name AS product_name, c.name AS category_name
             FROM ai_generations g
             LEFT JOIN products p ON p.id = g.reference_id AND g.reference_type = "product"
             LEFT JOIN categories c ON c.id = g.reference_id AND g.reference_type = "category"
             ORDER BY g.id DESC LIMIT 12'
        );
    } catch (Throwable $e) { $recent = []; }
}

$adminPage = 'ai';
$adminTitle = 'AI Auto Work';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('cpu', 20) ?> AI Auto Work</h2>
    <p class="sh-aihead__sub">AI-powered automation for products, content, SEO and blogs.</p>
  </div>
</div>

<?php sh_ai_subnav('dashboard'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<div class="sh-stats">
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('cpu', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['total']) ?></div>
      <div class="sh-stat__label">Total generations</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('box', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['product']) ?></div>
      <div class="sh-stat__label">Product</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('file', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['blog']) ?></div>
      <div class="sh-stat__label">Blog</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('image', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['image']) ?></div>
      <div class="sh-stat__label">Image</div></div></div>
</div>

<div class="sh-stats">
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--green"><?= sh_icon('check-circle', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['success']) ?></div>
      <div class="sh-stat__label">Successful</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--amber"><?= sh_icon('x-circle', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['failed']) ?></div>
      <div class="sh-stat__label">Failed</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon sh-stat__icon--blue"><?= sh_icon('zap', 20) ?></span>
    <div><div class="sh-stat__value"><?= number_format($stats['tokens']) ?></div>
      <div class="sh-stat__label">Tokens used</div></div></div>
  <div class="sh-stat"><span class="sh-stat__icon"><?= sh_icon('settings', 20) ?></span>
    <div><div class="sh-stat__value" style="font-size:14px">
      <?= $aiConfig['has_key'] ? e($aiConfig['provider']) . ' · ' . e($aiConfig['model'] ?: 'no model') : 'No provider' ?></div>
      <div class="sh-stat__label">Default provider<?= $stats['cost'] > 0 ? ' · $' . number_format($stats['cost'], 4) . ' spent' : '' ?></div></div></div>
</div>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('clock', 17) ?> Recent AI activity</h2>
    <div class="sh-panel__actions">
      <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/history.php')) ?>">Full history</a>
    </div>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Type</th><th>Item</th><th>Status</th><th>Provider · model</th><th>When</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr class="sh-table--empty"><td colspan="5">
          No generations yet. Pick a screen above to create your first one.</td></tr>
      <?php else: foreach ($recent as $g): ?>
        <tr>
          <td class="sh-table__name"><?= e(sh_ai_type_label((string)$g['type'])) ?></td>
          <td class="sh-table__meta" style="max-width:280px">
            <?= e((string)($g['product_name'] ?? $g['category_name'] ?? ($g['reference_type'] ?: '—'))) ?></td>
          <td><span class="sh-badge <?= $g['status'] === 'success' ? 'sh-badge--ok' : 'sh-badge--bad' ?>">
            <?= e((string)$g['status']) ?></span>
            <?php if (!empty($g['error_message'])): ?>
              <div class="sh-table__meta"><?= e(sh_excerpt((string)$g['error_message'], 60)) ?></div>
            <?php endif; ?></td>
          <td class="sh-table__meta sh-break"><?= e((string)($g['provider'] ?? '')) ?><?= $g['provider'] && $g['model'] ? ' · ' : '' ?><?= e((string)($g['model'] ?? '—')) ?></td>
          <td class="sh-table__meta"><?= e(sh_ai_ago((string)$g['created_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
