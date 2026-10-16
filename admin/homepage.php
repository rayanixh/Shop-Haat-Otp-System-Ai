<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('storefront.manage');

$editId = sh_int($_GET['edit'] ?? 0);

/** Delete an uploaded promo file (never the bundled defaults). */
function sh_promo_delete_file(?string $image): void
{
    $image = trim((string)$image);
    if ($image === '' || str_starts_with($image, 'promo/')) { return; }
    $path = SH_UPLOAD_DIR . '/promo/' . basename($image);
    if (is_file($path) && !unlink($path)) {
        sh_log_line('promo', 'Could not delete promo image ' . $path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);
    $row = $id > 0 ? sh_one('SELECT * FROM promo_slides WHERE id = ? LIMIT 1', [$id]) : null;

    if ($form === 'toggle' && $row) {
        sh_query('UPDATE promo_slides SET status = 1 - status WHERE id = ?', [$id]);
        sh_flash('success', 'Visibility updated.');
        sh_redirect('admin/homepage.php');
    }

    if ($form === 'move' && $row) {
        $dir = sh_post('dir') === 'up' ? 'up' : 'down';
        $op = $dir === 'up' ? '<' : '>';
        $ord = $dir === 'up' ? 'DESC' : 'ASC';
        $neighbour = sh_one(
            "SELECT id, sort_order FROM promo_slides
             WHERE placement = ? AND sort_order $op ? ORDER BY sort_order $ord LIMIT 1",
            [$row['placement'], (int)$row['sort_order']]
        );
        if ($neighbour) {
            sh_query('UPDATE promo_slides SET sort_order = ? WHERE id = ?', [(int)$neighbour['sort_order'], $id]);
            sh_query('UPDATE promo_slides SET sort_order = ? WHERE id = ?', [(int)$row['sort_order'], (int)$neighbour['id']]);
            sh_flash('success', 'Order updated.');
        }
        sh_redirect('admin/homepage.php');
    }

    if ($form === 'delete' && $row) {
        if ($row['placement'] === 'card') {
            sh_flash('error', 'The three feature cards cannot be deleted — edit them or switch them off instead.');
        } else {
            sh_promo_delete_file($row['image']);
            sh_query('DELETE FROM promo_slides WHERE id = ?', [$id]);
            sh_log_line('promo', 'Slide #' . $id . ' deleted by ' . $admin['email']);
            sh_flash('success', 'Slide deleted.');
        }
        sh_redirect('admin/homepage.php');
    }

    // Slider timing. Both values are clamped to the band the storefront is built
    // for (4-6 s per slide, 600-1000 ms crossfade) and re-clamped on read by
    // sh_slider_interval_ms()/sh_slider_fade_ms(), so a bad value can never
    // reach the homepage even by editing the settings table directly.
    if ($form === 'slider_timing') {
        $intervalMs = sh_int($_POST['slider_interval_ms'] ?? 0);
        $fadeMs     = sh_int($_POST['slider_fade_ms'] ?? 0);
        if ($intervalMs < 4000 || $intervalMs > 6000) {
            sh_flash('error', 'Time per slide must be between 4000 and 6000 milliseconds (4-6 seconds).');
        } elseif ($fadeMs < 600 || $fadeMs > 1000) {
            sh_flash('error', 'Transition length must be between 600 and 1000 milliseconds.');
        } else {
            sh_setting_save('slider_interval_ms', (string)$intervalMs);
            sh_setting_save('slider_fade_ms', (string)$fadeMs);
            sh_log_line('promo', 'Slider timing updated by ' . $admin['email']);
            sh_flash('success', 'Slider timing saved.');
        }
        sh_redirect('admin/homepage.php');
    }

    // The form now lives on admin/homepage-edit.php. Anything that still posts
    // a save here (a tab opened before the change) is forwarded rather than
    // dropped, and nothing from the body is stored.
    if ($form === 'save' || $form === 'delete_image') {
        sh_flash('info', 'The homepage image form has moved to its own page. Please submit again there.');
        sh_redirect('admin/homepage-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=slider'));
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($editId > 0) { sh_redirect('admin/homepage-edit.php?edit=' . $editId); }
    $newPlacement = sh_get('new');
    if ($newPlacement === 'slider' || $newPlacement === 'card') {
        sh_redirect('admin/homepage-edit.php?new=' . $newPlacement);
    }
}
$slides = sh_all("SELECT * FROM promo_slides WHERE placement = 'slider' ORDER BY sort_order ASC, id ASC");
$cards  = sh_all("SELECT * FROM promo_slides WHERE placement = 'card'   ORDER BY sort_order ASC, id ASC");

$adminPage = 'homepage';
$adminTitle = 'Homepage Images';
require __DIR__ . '/_layout.php';
?>
<?= sh_admin_header(
    'Homepage images',
    '',
    sh_admin_add_button('Add slide', sh_url('admin/homepage-edit.php?new=slider'))
    . sh_admin_add_button('Add feature card', sh_url('admin/homepage-edit.php?new=card'), '', 'sh-btn sh-btn--sm sh-btn--ghost'),
    'layout'
) ?>

<div class="sh-promoadmin">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">

    <div class="sh-panel">
      <div class="sh-panel__head">
        <h2 class="sh-panel__title"><?= sh_icon('layout', 17) ?> Slider slides</h2>
        <div class="sh-panel__actions"><span class="sh-formcard__note"><?= count($slides) ?> slide<?= count($slides) === 1 ? '' : 's' ?></span></div>
      </div>
      <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:10px">
        <?php if (!$slides): ?>
          <p class="sh-panel__note">No slides yet. Add one and it will appear on the homepage.</p>
        <?php else: foreach ($slides as $i => $sl): $img = sh_promo_image($sl['image']); ?>
          <div class="sh-promorow">
            <div class="sh-promorow__thumb">
              <?php if ($img !== ''): ?>
                <img src="<?= e($img) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="sh-promorow__noimg"><?= sh_icon('image', 18) ?></span>
              <?php endif; ?>
            </div>
            <div class="sh-promorow__body">
              <div class="sh-promorow__title"><?= e($sl['title']) ?></div>
              <div class="sh-promorow__meta">
                <?= $sl['tag'] ? e($sl['tag']) . ' &middot; ' : '' ?>
                <?= e(mb_strimwidth(trim((string)$sl['subtitle']), 0, 90, '…')) ?>
              </div>
              <div class="sh-promorow__meta">Badge, heading and text only — slides no longer show a button.</div>
              <?php if ($img === '' && trim((string)$sl['image']) !== ''): ?>
                <div class="sh-promorow__meta" style="color:#c62828">Image file missing — re-upload it.</div>
              <?php endif; ?>
            </div>
            <span class="sh-statuspill <?= (int)$sl['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
              <?= (int)$sl['status'] === 1 ? 'Active' : 'Hidden' ?></span>
            <div class="sh-promorow__acts">
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="move"><input type="hidden" name="id" value="<?= (int)$sl['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>><?= sh_icon('chevron-up', 13) ?></button></form>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="move"><input type="hidden" name="id" value="<?= (int)$sl['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Move down" <?= $i === count($slides) - 1 ? 'disabled' : '' ?>><?= sh_icon('chevron-down', 13) ?></button></form>
              <a class="sh-btn sh-btn--sm sh-btn--ghost" title="Edit" href="<?= e(sh_url('admin/homepage.php?edit=' . (int)$sl['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$sl['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Show / hide"><?= sh_icon('eye', 13) ?></button></form>
              <form method="post" data-confirm="Delete this slide and its image?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$sl['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" title="Delete"><?= sh_icon('trash', 13) ?></button></form>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="sh-panel">
      <div class="sh-panel__head">
        <h2 class="sh-panel__title"><?= sh_icon('grid', 17) ?> Feature cards</h2>
      </div>
      <div class="sh-panel__body" style="display:flex;flex-direction:column;gap:10px">
        <p class="sh-panel__note" style="margin-bottom:2px">
          The three cards beside the slider. They cannot be deleted, but you can change the image, text,
          link and order, or switch one off.</p>
        <?php foreach ($cards as $i => $c): $img = sh_promo_image($c['image']); ?>
          <div class="sh-promorow">
            <div class="sh-promorow__thumb">
              <?php if ($img !== ''): ?>
                <img src="<?= e($img) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="sh-promorow__noimg"><?= sh_icon('image', 18) ?></span>
              <?php endif; ?>
            </div>
            <div class="sh-promorow__body">
              <div class="sh-promorow__title"><?= e($c['title']) ?></div>
              <div class="sh-promorow__meta"><?= e((string)$c['subtitle']) ?></div>
              <?php if ($img === '' && trim((string)$c['image']) !== ''): ?>
                <div class="sh-promorow__meta" style="color:#c62828">Image file missing — re-upload it.</div>
              <?php endif; ?>
            </div>
            <span class="sh-statuspill <?= (int)$c['status'] === 1 ? 'sh-statuspill--on' : 'sh-statuspill--off' ?>">
              <?= (int)$c['status'] === 1 ? 'Active' : 'Hidden' ?></span>
            <div class="sh-promorow__acts">
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="move"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>><?= sh_icon('chevron-up', 13) ?></button></form>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="move"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Move down" <?= $i === count($cards) - 1 ? 'disabled' : '' ?>><?= sh_icon('chevron-down', 13) ?></button></form>
              <a class="sh-btn sh-btn--sm sh-btn--ghost" title="Edit" href="<?= e(sh_url('admin/homepage.php?edit=' . (int)$c['id'])) ?>"><?= sh_icon('pencil', 13) ?></a>
              <form method="post"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit" title="Show / hide"><?= sh_icon('eye', 13) ?></button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> Slider timing</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin:0 0 12px">
        Slides move on their own with a crossfade — the outgoing image stays in place until the next one has
        finished fading in, so a slide change never shows a blank frame or a white flash. Visitors whose browser
        asks for reduced motion get an instant change instead, and the rotation pauses while the tab is hidden.
      </p>
      <form method="post" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="slider_timing">
        <div class="sh-field">
          <label class="sh-field__label" for="sl-interval">Time on each slide (ms)</label>
          <input class="sh-input" id="sl-interval" name="slider_interval_ms" type="number" inputmode="numeric"
                 min="4000" max="6000" step="100" required value="<?= (int)sh_slider_interval_ms() ?>" style="max-width:160px">
          <p class="sh-form__hint">4000–6000 (4–6 seconds). Default 5200.</p>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="sl-fade">Crossfade length (ms)</label>
          <input class="sh-input" id="sl-fade" name="slider_fade_ms" type="number" inputmode="numeric"
                 min="600" max="1000" step="50" required value="<?= (int)sh_slider_fade_ms() ?>" style="max-width:160px">
          <p class="sh-form__hint">600–1000. Default 700.</p>
        </div>
        <div class="sh-actions">
          <button class="sh-btn sh-btn--sm" type="submit"><?= sh_icon('check-circle', 14) ?> Save timing</button>
        </div>
      </form>
    </div>
  </div>

  <div class="sh-panel" style="align-self:start">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('info', 17) ?> How this page works</h2></div>
    <div class="sh-panel__body">
      <p class="sh-panel__note" style="margin:0 0 10px">
        Slides rotate at the top of the homepage; the three feature cards sit beneath them.
        Use <strong>Add slide</strong> or <strong>Edit</strong> to open the form on its own page —
        the listing stays short so ordering and visibility are easy to manage on a phone.</p>
      <ul class="sh-panel__note" style="margin:0 0 12px 16px;padding:0">
        <li>JPG, PNG, WebP or GIF images.</li>
        <li>Order is changed with the ↑ ↓ buttons, not by editing numbers.</li>
        <li>Feature cards cannot be deleted — switch one off instead.</li>
        <li>Slides show the image, badge, heading and description. Slides have no button; use a feature card when you need one.</li>
        <li>A slide switched off is removed from the homepage — it is not loaded, not just hidden.</li>
      </ul>
      <p class="sh-panel__note">
        <a href="<?= e(sh_url('index.php')) ?>" target="_blank" rel="noopener">Open the homepage <?= sh_icon('external', 12) ?></a>
      </p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
