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

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

/** Save an uploaded promo image, returning the stored filename or null. */
function sh_promo_handle_upload(array $file, array &$errors, string $field = 'image'): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    // Dimensions capped generously; size is limited only by the server's PHP ceiling.
    $up = sh_upload_image($file, 'promo', 0, 6000);
    if (!empty($up['ok'])) { return $up['file']; }
    $errors[$field] = $up['error'] ?? 'The image could not be uploaded.';
    return null;
}

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

    if ($form === 'delete_image' && $row) {
        sh_promo_delete_file($row['image']);
        sh_query('UPDATE promo_slides SET image = NULL WHERE id = ?', [$id]);
        sh_log_line('promo', 'Image removed from promo #' . $id . ' by ' . $admin['email']);
        sh_flash('success', 'Image removed. Upload a new one to replace it.');
        sh_redirect('admin/homepage.php?edit=' . $id);
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

    if ($form === 'save') {
        $placement = sh_post('placement') === 'card' ? 'card' : 'slider';
        if ($row) { $placement = $row['placement']; }

        $v = new ShValidator($_POST);
        $v->required('title', 'Title')->maxLen('title', 160, 'Title')
          ->maxLen('subtitle', 255, 'Description')
          ->maxLen('button_text', 60, 'Button text')
          ->maxLen('button_url', 255, 'Button link')
          ->maxLen('tag', 60, 'Badge');
        $errors = $v->errors();

        $btnText = trim(sh_post('button_text'));
        $btnUrl = trim(sh_post('button_url'));
        if ($btnText !== '' && $btnUrl === '') {
            $errors['button_url'] = 'Add a link for the button, or clear the button text.';
        }
        // Block javascript: and other script-ish schemes.
        if ($btnUrl !== '' && preg_match('#^\s*(javascript|data|vbscript):#i', $btnUrl)) {
            $errors['button_url'] = 'That link type is not allowed.';
        }

        $overlay = sh_int($_POST['overlay'] ?? 70);
        if ($overlay < 0 || $overlay > 100) { $errors['overlay'] = 'Overlay must be between 0 and 100.'; }

        $image = $row['image'] ?? null;
        $uploaded = null;
        if (isset($_FILES['image'])) {
            $uploaded = sh_promo_handle_upload($_FILES['image'], $errors);
            if ($uploaded !== null) { $image = $uploaded; }
        }
        if (!$row && $image === null && !$errors) {
            $errors['image'] = 'Choose an image for this slide.';
        }

        if ($errors) {
            // Do not leave an orphan file behind if validation failed afterwards.
            if ($uploaded !== null) { sh_promo_delete_file($uploaded); }
        } else {
            $data = [
                'tag'         => trim(sh_post('tag')) ?: null,
                'title'       => trim(sh_post('title')),
                'subtitle'    => trim(sh_post('subtitle')) ?: null,
                'button_text' => $btnText ?: null,
                'button_url'  => $btnUrl ?: null,
                'image'       => $image,
                'overlay'     => $overlay,
                'status'      => !empty($_POST['status']) ? 1 : 0,
            ];
            try {
                if ($row) {
                    // Replacing the image? remove the old upload.
                    if ($uploaded !== null && $row['image'] !== $uploaded) { sh_promo_delete_file($row['image']); }
                    sh_update('promo_slides', $data, 'id = ?', [$id]);
                    sh_flash('success', 'Saved. The homepage is updated.');
                } else {
                    $next = (int)sh_val('SELECT COALESCE(MAX(sort_order),0)+1 FROM promo_slides WHERE placement = ?',
                        [$placement], 1);
                    $data['placement'] = $placement;
                    $data['sort_order'] = $next;
                    sh_insert('promo_slides', $data);
                    sh_flash('success', 'Slide added. It is now live on the homepage.');
                }
                sh_log_line('promo', 'Promo content saved by ' . $admin['email']);
                sh_redirect('admin/homepage.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'promo-save');
                $errors['general'] = 'The changes could not be saved. The error has been logged.';
            }
        }
        $editId = $id;
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM promo_slides WHERE id = ? LIMIT 1', [$editId]) : null;
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$slides = sh_all("SELECT * FROM promo_slides WHERE placement = 'slider' ORDER BY sort_order ASC, id ASC");
$cards  = sh_all("SELECT * FROM promo_slides WHERE placement = 'card'   ORDER BY sort_order ASC, id ASC");
$isCard = $editing && $editing['placement'] === 'card';

$adminPage = 'homepage';
$adminTitle = 'Homepage Images';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="sh-promoadmin">
  <div style="min-width:0;display:flex;flex-direction:column;gap:14px">

    <div class="sh-panel">
      <div class="sh-panel__head">
        <h2 class="sh-panel__title"><?= sh_icon('layout', 17) ?> Slider slides (<?= count($slides) ?>)</h2>
        <div class="sh-panel__actions">
          <a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/homepage.php?new=slider')) ?>">
            <?= sh_icon('plus', 14) ?> Add slide</a>
        </div>
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
                <?= $sl['button_text'] ? e($sl['button_text']) . ' &rarr; ' . e($sl['button_url']) : 'No button' ?>
              </div>
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
    <div class="sh-panel__head">
      <h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'plus', 17) ?>
        <?= $editing ? ($isCard ? 'Edit card' : 'Edit slide') : 'New slide' ?></h2>
    </div>
    <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="placement" value="<?= e($editing['placement'] ?? 'slider') ?>">

        <div class="sh-field">
          <label class="sh-field__label" for="pm-img">Image<?= $editing ? '' : ' <span class="sh-field__req">*</span>' ?></label>
          <input class="sh-input" id="pm-img" type="file" name="image" accept="image/*"
                 data-image-preview="promo-preview">
          <span class="sh-field__hint">
            JPG, PNG, WebP or GIF. Up to <?= e(sh_bytes_label(sh_server_upload_limit())) ?>.
            <?= $isCard ? 'Around 1200&times;520 px works well.' : 'Around 1600&times;600 px works well.' ?>
            <?= $editing ? 'Leave empty to keep the current image.' : '' ?>
          </span>
        </div>

        <?php $curImg = sh_promo_image($val('image')); ?>
        <div class="sh-promoprev" id="promo-preview" <?= $curImg === '' ? 'hidden' : '' ?>>
          <img src="<?= e($curImg) ?>" alt="Current image">
          <span class="sh-promoprev__cap" data-preview-caption>Current image</span>
        </div>
        <?php if ($curImg === '' && trim($val('image')) !== ''): ?>
          <p class="sh-field__hint" style="color:#c62828;margin:-4px 0 12px">
            The saved file (<?= e($val('image')) ?>) is missing from the server. Upload a replacement.</p>
        <?php endif; ?>

        <?php if (!$isCard): ?>
          <div class="sh-field">
            <label class="sh-field__label" for="pm-tag">Badge</label>
            <input class="sh-input" id="pm-tag" name="tag" maxlength="60" value="<?= e($val('tag')) ?>" placeholder="Flash Sale Live">
            <span class="sh-field__hint">Small pill above the title. Leave blank to hide it.</span>
          </div>
        <?php endif; ?>

        <div class="sh-field">
          <label class="sh-field__label" for="pm-title">Title <span class="sh-field__req">*</span></label>
          <input class="sh-input" id="pm-title" name="title" required maxlength="160" value="<?= e($val('title')) ?>">
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="pm-sub">Description</label>
          <textarea class="sh-textarea" id="pm-sub" name="subtitle" rows="2" maxlength="255"><?= e($val('subtitle')) ?></textarea>
        </div>
        <div class="sh-grid2">
          <div class="sh-field">
            <label class="sh-field__label" for="pm-bt">Button text</label>
            <input class="sh-input" id="pm-bt" name="button_text" maxlength="60" value="<?= e($val('button_text')) ?>">
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="pm-bu">Button link</label>
            <input class="sh-input" id="pm-bu" name="button_url" maxlength="255" value="<?= e($val('button_url')) ?>"
                   placeholder="products.php?flash=1">
          </div>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="pm-ov">Text readability overlay — <span data-overlay-out><?= e($val('overlay', '70')) ?></span>%</label>
          <input id="pm-ov" type="range" name="overlay" min="0" max="100" step="5"
                 value="<?= e($val('overlay', '70')) ?>" style="width:100%" data-overlay-range>
          <span class="sh-field__hint">Darkens the image so the text stays readable. Higher is darker.</span>
        </div>
        <label class="sh-check" style="margin-bottom:12px">
          <input type="checkbox" name="status" value="1" <?= ($errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1) ? 'checked' : '' ?>>
          <span>Show on the homepage</span></label>

        <button class="sh-btn sh-btn--block" type="submit">
          <?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Add slide' ?></button>
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/homepage.php')) ?>">Cancel</a>
        <?php endif; ?>
      </form>

      <?php if ($editing && trim((string)$editing['image']) !== ''): ?>
        <form method="post" style="margin-top:8px" data-confirm="Remove this image?">
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="delete_image">
          <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--bad sh-btn--block" type="submit">
            <?= sh_icon('trash', 14) ?> Delete current image</button>
        </form>
      <?php endif; ?>

      <p class="sh-panel__note" style="margin-top:12px">
        <a href="<?= e(sh_url('index.php')) ?>" target="_blank" rel="noopener">Open the homepage <?= sh_icon('external', 12) ?></a>
      </p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
