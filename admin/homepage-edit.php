<?php
/**
 * Admin — create or edit one homepage slide or feature card.
 *
 * The listing (admin/homepage.php) keeps the top-of-section Add actions and the
 * ordering controls; this screen owns the form. The handler, validation, upload
 * rules, image replacement and audit logging are unchanged.
 *
 *   admin/homepage-edit.php?new=slider | ?new=card | ?edit=12
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/catalog.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('storefront.manage');
require_once SH_ROOT . '/includes/admin-tools.php';

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$requestedPlacement = sh_get('new') === 'card' ? 'card' : (sh_get('new') === 'slider' ? 'slider' : '');

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/homepage.php') . ($keepQs !== '' ? '?' . $keepQs : '');

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

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);
    $row = $id > 0 ? sh_one('SELECT * FROM promo_slides WHERE id = ? LIMIT 1', [$id]) : null;

    if ($form === 'delete_image' && $row) {
        sh_promo_delete_file($row['image']);
        sh_query('UPDATE promo_slides SET image = NULL WHERE id = ?', [$id]);
        sh_log_line('promo', 'Image removed from promo #' . $id . ' by ' . $admin['email']);
        sh_flash('success', 'Image removed. Upload a new one to replace it.');
        sh_redirect('admin/homepage-edit.php?edit=' . $id);
    }

    if ($form === 'delete' && $row) {
        if ($row['placement'] === 'card') {
            sh_flash('error', 'The three feature cards cannot be deleted — edit them or switch them off instead.');
            sh_redirect('admin/homepage-edit.php?edit=' . $id);
        }
        sh_promo_delete_file($row['image']);
        sh_query('DELETE FROM promo_slides WHERE id = ?', [$id]);
        sh_log_line('promo', 'Slide #' . $id . ' deleted by ' . $admin['email']);
        sh_flash('success', 'Slide deleted.');
        sh_redirect($listUrl);
    }

    if ($form !== 'save') { sh_redirect($listUrl); }
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
            sh_redirect($listUrl);
        } catch (Throwable $e) {
            sh_log_exception($e, 'promo-save');
            $errors['general'] = 'The changes could not be saved. The error has been logged.';
        }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_one('SELECT * FROM promo_slides WHERE id = ? LIMIT 1', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That slide no longer exists.');
    sh_redirect($listUrl);
}
if ($editing === null && $requestedPlacement === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'Choose whether to add a slider slide or a feature card.');
    sh_redirect($listUrl);
}
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};
$isCard = $editing !== null && $editing['placement'] === 'card';
$placement = $editing['placement'] ?? ($requestedPlacement !== '' ? $requestedPlacement : 'slider');
$chkStatus = $errors ? !empty($_POST['status']) : (int)($editing['status'] ?? 1) === 1;
$curImage = sh_promo_image($val('image'));

$adminPage = 'homepage';
$adminTitle = $editing !== null ? ($isCard ? 'Edit feature card' : 'Edit slide') : 'New slide';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Homepage images' => $listUrl,
    ($editing !== null
        ? (($isCard ? 'Feature card: ' : 'Slide: ') . (string)$editing['title'])
        : ($isCard ? 'Add feature card' : 'Add slide')) => '',
]) ?>

<div class="sh-panel sh-formcard">
  <div class="sh-panel__body">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= sh_csrf_field() ?>
        <input type="hidden" name="form" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <input type="hidden" name="placement" value="<?= e($editing['placement'] ?? ($placement ?? 'slider')) ?>">

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

        <div class="sh-formcard__foot sh-formcard__foot--sticky">
          <button class="sh-btn" type="submit">
          <?= sh_icon('check-circle', 15) ?> <?= $editing ? 'Save changes' : 'Add slide' ?></button>
          <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
        </div>
        <?php if ($editing): ?>
          <a class="sh-btn sh-btn--ghost sh-btn--block" style="margin-top:8px" href="<?= e(sh_url('admin/homepage.php')) ?>">Cancel</a>
        <?php endif; ?>
      </form>

      <?php if ($editing && trim((string)$editing['image']) !== ''): ?>
        <form method="post" style="margin-top:8px" data-confirm="Remove this image?">
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="delete_image">
          <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
          <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit">
            <?= sh_icon('trash', 14) ?> Delete current image</button>
        </form>
      <?php endif; ?>
  </div>
</div>

<?php if ($editing): ?>
  <form method="post" data-confirm="Delete &quot;<?= e((string)$editing['title']) ?>&quot; and its image?" style="margin-top:12px">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 14) ?> Delete this <?= $isCard ? 'card' : 'slide' ?></button>
    <?php if ($isCard): ?><span class="sh-formcard__note" style="margin-left:8px">Feature cards are fixed — switch one off instead of deleting it.</span><?php endif; ?>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
