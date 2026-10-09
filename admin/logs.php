<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('system.logs');

$files = glob(SH_LOG_DIR . '/app-*.log') ?: [];
rsort($files);
$names = array_map('basename', $files);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    if (sh_post('form') === 'delete') {
        $target = basename(sh_post('file'));
        // Only ever touch files matching our own log pattern inside the log directory.
        if (preg_match('/^app-\d{4}-\d{2}-\d{2}\.log$/', $target) && in_array($target, $names, true)) {
            unlink(SH_LOG_DIR . '/' . $target);
            sh_flash('success', 'Log file ' . $target . ' deleted.');
        } else {
            sh_flash('error', 'That log file could not be found.');
        }
        sh_redirect('admin/logs.php');
    }
}

$current = basename(sh_get('file', $names[0] ?? ''));
if ($current !== '' && !in_array($current, $names, true)) { $current = $names[0] ?? ''; }

$lines = [];
if ($current !== '') {
    $path = SH_LOG_DIR . '/' . $current;
    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($raw)) { $lines = array_slice(array_reverse($raw), 0, 400); }
}

$adminPage = 'logs';
$adminTitle = 'Error Logs';
require __DIR__ . '/_layout.php';
?>
<div class="sh-panel">
  <div class="sh-panel__head">
    <h2 class="sh-panel__title"><?= sh_icon('list', 17) ?> <?= $current !== '' ? e($current) : 'No log files' ?></h2>
    <div class="sh-panel__actions">
      <?php if ($current !== ''): ?>
        <form method="post" data-confirm="Delete this log file?">
          <?= sh_csrf_field() ?><input type="hidden" name="form" value="delete">
          <input type="hidden" name="file" value="<?= e($current) ?>">
          <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 14) ?> Delete file</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (count($names) > 1): ?>
    <div class="sh-panel__body" style="padding-bottom:0">
      <div style="display:flex;gap:7px;flex-wrap:wrap">
        <?php foreach (array_slice($names, 0, 14) as $n): ?>
          <a class="sh-btn sh-btn--sm <?= $n === $current ? '' : 'sh-btn--ghost' ?>"
             href="<?= e(sh_url('admin/logs.php?file=' . urlencode($n))) ?>"><?= e(substr($n, 4, 10)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="sh-panel__body">
    <?php if (!$lines): ?>
      <p class="sh-panel__note">No entries. That is a good sign.</p>
    <?php else: ?>
      <p class="sh-panel__note" style="margin-bottom:9px">Showing the <?= count($lines) ?> most recent entries, newest first.</p>
      <div style="max-height:620px;overflow:auto;background:#151b2b;border-radius:8px;padding:12px">
        <?php foreach ($lines as $line):
          $bad = stripos($line, '[uncaught]') !== false || stripos($line, '[sql]') !== false || stripos($line, 'fatal') !== false;
          $sec = stripos($line, '[security]') !== false; ?>
          <div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.7px;line-height:1.65;white-space:pre-wrap;word-break:break-word;color:<?= $bad ? '#ff9b8a' : ($sec ? '#8fd3ff' : '#c9d2e0') ?>"><?= e($line) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
