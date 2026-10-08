<?php
require_once __DIR__ . '/_ai.php';

$viewId = sh_int($_GET['view'] ?? 0);
$fType = sh_get('type');
$fStatus = sh_get('status');
$page = max(1, sh_int($_GET['page'] ?? 1));
$per = 25;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('form') === 'prune') {
        try {
            sh_query('DELETE FROM ai_generations WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);
            sh_flash('success', 'Records older than 30 days were removed.');
        } catch (Throwable $e) {
            sh_log_exception($e, 'ai-prune');
            sh_flash('error', 'Those records could not be removed.');
        }
        sh_redirect('admin/ai/history.php');
    }
}

$rows = []; $total = 0; $pages = 1; $entry = null;
if ($aiInstalled) {
    try {
        $where = ['1=1']; $args = [];
        if ($fType !== '') { $where[] = 'type = ?'; $args[] = $fType; }
        if (in_array($fStatus, ['success', 'failed'], true)) { $where[] = 'status = ?'; $args[] = $fStatus; }
        $w = implode(' AND ', $where);
        $total = (int)sh_val("SELECT COUNT(*) FROM ai_generations WHERE $w", $args, 0);
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $rows = sh_all("SELECT * FROM ai_generations WHERE $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $args);
        if ($viewId > 0) { $entry = sh_one('SELECT * FROM ai_generations WHERE id = ? LIMIT 1', [$viewId]); }
    } catch (Throwable $e) { $rows = []; }
}
$types = [];
if ($aiInstalled) {
    try { $types = array_column(sh_all('SELECT DISTINCT type FROM ai_generations ORDER BY type'), 'type'); }
    catch (Throwable $e) { $types = []; }
}

$adminPage = 'ai_history';
$adminTitle = 'AI History';
require dirname(__DIR__) . '/_layout.php';
?>
<div class="sh-aihead">
  <div>
    <h2 class="sh-aihead__title"><?= sh_icon('clock', 20) ?> AI History</h2>
    <p class="sh-aihead__sub">Every generation, with its prompt, response and token usage.</p>
  </div>
</div>

<?php sh_ai_subnav('history'); ?>
<?php sh_ai_banner($aiInstalled, $aiConfig); ?>

<?php if ($entry !== null): ?>
  <div class="sh-panel">
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon('cpu', 17) ?> <?= e(sh_ai_type_label((string)$entry['type'])) ?> #<?= (int)$entry['id'] ?></h2>
      <div class="sh-panel__actions">
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/history.php')) ?>">Back</a>
      </div>
    </div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div><p class="sh-field__label">Status</p>
          <span class="sh-badge <?= $entry['status'] === 'success' ? 'sh-badge--ok' : 'sh-badge--bad' ?>">
            <?= e((string)$entry['status']) ?></span></div>
        <div><p class="sh-field__label">Provider / model</p>
          <p class="sh-panel__note"><?= e((string)($entry['provider'] ?? '—')) ?> · <?= e((string)($entry['model'] ?? '—')) ?></p></div>
        <div><p class="sh-field__label">Tokens</p>
          <p class="sh-panel__note"><?= number_format((int)$entry['tokens_used']) ?>
            <?php if ((int)($entry['prompt_tokens'] ?? 0) > 0): ?><span class="sh-table__meta">(<?= number_format((int)$entry['prompt_tokens']) ?> in / <?= number_format((int)$entry['completion_tokens']) ?> out)</span><?php endif; ?></p></div>
        <div><p class="sh-field__label">Cost</p>
          <p class="sh-panel__note"><?= $entry['cost'] !== null ? '$' . rtrim(rtrim(number_format((float)$entry['cost'], 6, '.', ''), '0'), '.') : 'not reported' ?>
            <?php if (!empty($entry['fallback_used'])): ?> <span class="sh-badge sh-badge--warn">fallback</span><?php endif; ?></p></div>
        <div><p class="sh-field__label">Duration</p>
          <p class="sh-panel__note"><?= number_format((int)$entry['duration_ms']) ?> ms</p></div>
      </div>
      <?php if (!empty($entry['error_message'])): ?>
        <p class="sh-field__label" style="margin-top:10px">Error</p>
        <div class="sh-aicurrent" style="color:#c62828"><?= e((string)$entry['error_message']) ?></div>
      <?php endif; ?>
      <p class="sh-field__label" style="margin-top:10px">Prompt</p>
      <pre class="sh-aipre"><?= e((string)$entry['prompt']) ?></pre>
      <p class="sh-field__label" style="margin-top:10px">Response</p>
      <pre class="sh-aipre"><?= e((string)$entry['response']) ?></pre>
    </div>
  </div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> Generations (<?= number_format($total) ?>)</h2>
    <div class="sh-panel__actions">
      <form method="post" data-confirm="Delete records older than 30 days?">
        <?= sh_csrf_field() ?><input type="hidden" name="form" value="prune">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 14) ?> Prune old</button>
      </form>
    </div>
  </div>
  <div class="sh-panel__body" style="padding-bottom:0">
    <form class="sh-filterbar" method="get">
      <div class="sh-field"><label class="sh-field__label" for="h-type">Type</label>
        <select class="sh-select" id="h-type" name="type">
          <option value="">All</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= e($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= e(sh_ai_type_label($t)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="sh-field"><label class="sh-field__label" for="h-st">Status</label>
        <select class="sh-select" id="h-st" name="status">
          <option value="">All</option>
          <option value="success" <?= $fStatus === 'success' ? 'selected' : '' ?>>Success</option>
          <option value="failed" <?= $fStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select></div>
      <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('filter', 14) ?> Filter</button>
      <?php if ($fType !== '' || $fStatus !== ''): ?>
        <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/ai/history.php')) ?>">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Type</th><th>Reference</th><th>Status</th><th>Provider</th><th>Model</th><th>Tokens</th><th>Cost</th><th>When</th><th style="text-align:right">Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr class="sh-table--empty"><td colspan="9">No generations recorded yet.</td></tr>
      <?php else: foreach ($rows as $g): ?>
        <tr>
          <td class="sh-table__name"><?= e(sh_ai_type_label((string)$g['type'])) ?></td>
          <td class="sh-table__meta"><?= e((string)($g['reference_type'] ?? '—')) ?>
            <?= $g['reference_id'] ? '#' . (int)$g['reference_id'] : '' ?></td>
          <td><span class="sh-badge <?= $g['status'] === 'success' ? 'sh-badge--ok' : 'sh-badge--bad' ?>">
            <?= e((string)$g['status']) ?></span></td>
          <td class="sh-table__meta"><?= e((string)($g['provider'] ?? '—')) ?><?php if (!empty($g['fallback_used'])): ?> <span class="sh-badge sh-badge--warn">fallback</span><?php endif; ?></td>
          <td class="sh-table__meta sh-break"><?= e((string)($g['model'] ?? '—')) ?></td>
          <td class="sh-table__meta"><?= number_format((int)$g['tokens_used']) ?></td>
          <td class="sh-table__meta"><?= isset($g['cost']) && $g['cost'] !== null ? '$' . rtrim(rtrim(number_format((float)$g['cost'], 6, '.', ''), '0'), '.') : '—' ?></td>
          <td class="sh-table__meta"><?= e(date('d M, H:i', strtotime((string)$g['created_at']))) ?></td>
          <td style="text-align:right">
            <a class="sh-btn sh-btn--sm sh-btn--ghost"
               href="<?= e(sh_url('admin/ai/history.php?view=' . (int)$g['id'])) ?>">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="sh-panel__body">
      <?= sh_paginate($total, $per, $page, sh_url('admin/ai/history.php') . '?' . http_build_query(array_diff_key($_GET, ['page' => 1]))) ?>
    </div>
  <?php endif; ?>
</div>
<?php require dirname(__DIR__) . '/_footer.php'; ?>
