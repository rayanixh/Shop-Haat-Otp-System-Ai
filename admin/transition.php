<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/transition.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('storefront.manage');
$errors = [];
$prefix = sh_transition_prefix();
$self = 'admin/transition.php';
$scopeLabel = 'Page Transition';

$unlinkTransition = static function (string $file): void {
    if ($file === '') { return; }
    $path = SH_UPLOAD_DIR . '/transitions/' . basename($file);
    if (is_file($path)) { @unlink($path); }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $cfg = sh_transition_config();
    $form = sh_post('form');

    if ($form === 'remove_media' || $form === 'remove_bg') {
        $key = $form === 'remove_bg' ? 'bg_media' : 'media';
        $unlinkTransition($cfg[$key]);
        sh_setting_save($prefix . $key, '');
        if ($form === 'remove_bg' && in_array($cfg['bg_type'], ['image', 'gif'], true)) {
            sh_setting_save($prefix . 'bg_type', 'solid');
        }
        sh_flash('success', $form === 'remove_bg' ? 'Background media removed.' : 'Transition media removed.');
        sh_redirect($self);
    }

    $enabled = !empty($_POST['transition_enabled']) ? '1' : '0';

    $bgType = sh_post('bg_type');
    if (!isset(sh_transition_bg_types()[$bgType])) { $bgType = 'solid'; }
    $c1 = sh_transition_hex(sh_post('bg_color'), '');
    $c2 = sh_transition_hex(sh_post('bg_color2'), '');
    if ($c1 === '') { $errors['bg_color'] = 'Background colour must be a valid hex colour such as #111827.'; }
    if ($bgType === 'gradient' && $c2 === '') { $errors['bg_color2'] = 'Gradient colour 2 must be a valid hex colour.'; }
    if ($c2 === '') { $c2 = $c1; }
    $dir = sh_post('bg_direction');
    if (!isset(sh_transition_directions()[$dir])) { $dir = 'diagonal'; }

    $preset = sh_post('transition_duration');
    if ($preset === 'custom') {
        $duration = sh_int($_POST['transition_custom'] ?? 0);
        if ($duration < 150 || $duration > 5000) { $errors['duration'] = 'Custom duration must be between 150 and 5000 milliseconds.'; }
    } else {
        $duration = sh_int($preset);
        if (!isset(sh_transition_presets()[$duration])) { $duration = 450; }
    }
    $fade = sh_post('fade_style');
    if (!isset(sh_transition_fades()[$fade])) { $fade = 'smooth'; }

    // Background image / GIF upload.
    $bgMedia = $cfg['bg_media'];
    if (!empty($_FILES['bg_media']['name'])) {
        $up = sh_upload_image($_FILES['bg_media'], 'transitions', 0, 6000);
        if (!empty($up['ok'])) {
            if ($bgMedia !== '' && $bgMedia !== $up['file']) { $unlinkTransition($bgMedia); }
            $bgMedia = $up['file'];
            $bgType = str_ends_with(strtolower($bgMedia), '.gif') ? 'gif' : 'image';
        } else {
            $errors['bg_media'] = $up['error'] ?? 'The background file could not be uploaded.';
        }
    }
    if (in_array($bgType, ['image', 'gif'], true) && $bgMedia === '') {
        $errors['bg_type'] = 'Upload a background image or GIF first, or choose a colour/gradient background.';
    }

    // Centered media upload (logo / image / GIF).
    $mediaMode = sh_post('media_mode');
    $media = $cfg['media'];
    if ($mediaMode === 'none') {
        $unlinkTransition($media);
        $media = '';
    } elseif (!empty($_FILES['transition_media']['name'])) {
        $up = sh_upload_image($_FILES['transition_media'], 'transitions', 0, 4000);
        if (!empty($up['ok'])) {
            if ($media !== '' && $media !== $up['file']) { $unlinkTransition($media); }
            $media = $up['file'];
        } else {
            $errors['media'] = $up['error'] ?? 'The transition media could not be uploaded.';
        }
    } elseif ($media === '' && $mediaMode !== '' && $mediaMode !== 'none') {
        $errors['media_mode'] = 'Upload an image or GIF for the transition media, or choose "None".';
    }

    if (!$errors) {
        sh_setting_save($prefix . 'enabled', $enabled);
        sh_setting_save($prefix . 'bg_type', $bgType);
        sh_setting_save($prefix . 'bg_color', $c1);
        sh_setting_save($prefix . 'bg_color2', $c2);
        sh_setting_save($prefix . 'bg_direction', $dir);
        sh_setting_save($prefix . 'bg_media', $bgMedia);
        sh_setting_save($prefix . 'media', $media);
        sh_setting_save($prefix . 'duration', (string)$duration);
        sh_setting_save($prefix . 'fade', $fade);
        sh_log_line('admin', $scopeLabel . ' settings updated by ' . ($admin['email'] ?? ''));
        sh_flash('success', $scopeLabel . ' settings saved.');
        sh_redirect($self);
    }
}

$cfg = sh_transition_config();
$presets = sh_transition_presets();
$isPreset = isset($presets[$cfg['duration']]);
$mediaIsGif = $cfg['media'] !== '' && str_ends_with(strtolower($cfg['media']), '.gif');

$adminPage = 'transition';
$adminTitle = $scopeLabel;
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">

<div class="sh-cards">
  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('zap', 17) ?> <?= e($scopeLabel) ?></h2></div>
    <div class="sh-panel__body">
      <label class="sh-toggle" style="margin-bottom:14px">
        <input type="checkbox" name="transition_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
        <span class="sh-toggle__track"></span>
        <span>Enable page transitions (website and admin panel)</span>
      </label>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="tr-dur">Transition duration</label>
          <select class="sh-select" id="tr-dur" name="transition_duration" data-transition-duration>
            <?php foreach ($presets as $ms => $label): ?>
              <option value="<?= $ms ?>" <?= $isPreset && $cfg['duration'] === $ms ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
            <option value="custom" <?= !$isPreset ? 'selected' : '' ?>>Custom</option>
          </select>
          <div class="sh-field" style="margin:8px 0 0" data-transition-custom <?= $isPreset ? 'hidden' : '' ?>>
            <input class="sh-input" name="transition_custom" type="number" min="150" max="5000" step="50"
                   value="<?= !$isPreset ? (int)$cfg['duration'] : 750 ?>" placeholder="Milliseconds (150–5000)">
          </div>
          <span class="sh-field__hint">How long the overlay stays visible in total. The next page keeps loading underneath, so navigation is never slowed down on purpose.</span>
        </div>
        <div class="sh-field">
          <label class="sh-field__label" for="tr-fade">Fade style</label>
          <select class="sh-select" id="tr-fade" name="fade_style">
            <?php foreach (sh_transition_fades() as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $cfg['fade'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="sh-field__hint">Smooth: crisp ease-out. Soft: gentle overlapping fades. Cinematic: slower fades with a subtle scale.</span>
        </div>
      </div>
      <p class="sh-panel__note">One global setting: applies to every customer-facing page (home, categories, products, cart, checkout, payment, order success, tracking, orders, profile, wallet, login, signup) and to every admin panel page.</p>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('image', 17) ?> Background</h2></div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="tr-bg-type">Background type</label>
          <select class="sh-select" id="tr-bg-type" name="bg_type" data-tr-bg-type>
            <?php foreach (sh_transition_bg_types() as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $cfg['bg_type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sh-field" data-tr-when="gradient" <?= $cfg['bg_type'] === 'gradient' ? '' : 'hidden' ?>>
          <label class="sh-field__label" for="tr-dir">Gradient direction</label>
          <select class="sh-select" id="tr-dir" name="bg_direction">
            <?php foreach (sh_transition_directions() as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $cfg['bg_direction'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="tr-c1"><span data-tr-c1-label><?= $cfg['bg_type'] === 'gradient' ? 'Gradient colour 1' : 'Background colour' ?></span></label>
          <div class="sh-color__ctl">
            <label class="sh-color__well" style="background:<?= e($cfg['bg_color']) ?>">
              <input type="color" value="<?= e($cfg['bg_color']) ?>" data-tr-picker="tr-c1" aria-label="Pick background colour">
            </label>
            <input class="sh-input sh-color__hex" id="tr-c1" name="bg_color" value="<?= e($cfg['bg_color']) ?>" maxlength="7" spellcheck="false" autocomplete="off" pattern="#?[0-9a-fA-F]{6}">
          </div>
          <span class="sh-field__hint">Also used behind image/GIF backgrounds while they load.</span>
        </div>
        <div class="sh-field" data-tr-when="gradient" <?= $cfg['bg_type'] === 'gradient' ? '' : 'hidden' ?>>
          <label class="sh-field__label" for="tr-c2">Gradient colour 2</label>
          <div class="sh-color__ctl">
            <label class="sh-color__well" style="background:<?= e($cfg['bg_color2']) ?>">
              <input type="color" value="<?= e($cfg['bg_color2']) ?>" data-tr-picker="tr-c2" aria-label="Pick gradient colour 2">
            </label>
            <input class="sh-input sh-color__hex" id="tr-c2" name="bg_color2" value="<?= e($cfg['bg_color2']) ?>" maxlength="7" spellcheck="false" autocomplete="off" pattern="#?[0-9a-fA-F]{6}">
          </div>
        </div>
      </div>

      <div class="sh-field" data-tr-when="image gif" <?= in_array($cfg['bg_type'], ['image', 'gif'], true) ? '' : 'hidden' ?>>
        <label class="sh-field__label" for="tr-bg-media">Background image / GIF</label>
        <input class="sh-input" id="tr-bg-media" name="bg_media" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
        <span class="sh-field__hint">JPG, JPEG, PNG, WebP or GIF · covers the whole screen (cover / centred). Max <?= e(sh_bytes_label(sh_server_upload_limit())) ?>.<?= $cfg['bg_media'] !== '' ? ' Current: ' . e($cfg['bg_media']) : '' ?></span>
      </div>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('star', 17) ?> Transition media</h2></div>
    <div class="sh-panel__body">
      <div class="sh-grid2">
        <div class="sh-field">
          <label class="sh-field__label" for="tr-media-mode">Centered media</label>
          <select class="sh-select" id="tr-media-mode" name="media_mode" data-tr-media-mode>
            <option value="none" <?= $cfg['media'] === '' ? 'selected' : '' ?>>None — background only</option>
            <option value="image" <?= $cfg['media'] !== '' && !$mediaIsGif ? 'selected' : '' ?>>Image / logo</option>
            <option value="gif" <?= $mediaIsGif ? 'selected' : '' ?>>GIF</option>
          </select>
        </div>
        <div class="sh-field" data-tr-media-file <?= $cfg['media'] === '' ? 'hidden' : '' ?>>
          <label class="sh-field__label" for="tr-media">Upload image / GIF</label>
          <input class="sh-input" id="tr-media" name="transition_media" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
          <span class="sh-field__hint">Shown centred on top of the background, aspect ratio preserved, GIF animation kept.<?= $cfg['media'] !== '' ? ' Current: ' . e($cfg['media']) : '' ?></span>
        </div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save settings</button>
    </div>
  </section>

  <section class="sh-panel">
    <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('eye', 17) ?> Preview</h2></div>
    <div class="sh-panel__body">
      <div class="sh-pt-preview <?= $cfg['dark'] ? 'sh-pt--dark' : 'sh-pt--light' ?>" style="background:<?= e($cfg['bg_css']) ?>">
        <?php if ($cfg['media_url'] !== ''): ?>
          <img src="<?= e($cfg['media_url']) ?>" alt="Current transition media">
        <?php else: ?>
          <div class="sh-pt__mark sh-pt__mark--static"><span></span><span></span><span></span></div>
        <?php endif; ?>
      </div>
      <p class="sh-panel__note" style="margin-top:10px">
        <strong><?= $cfg['enabled'] ? 'Enabled' : 'Disabled' ?></strong> ·
        <?= e(sh_transition_bg_types()[$cfg['bg_type']]) ?> background ·
        <?= $cfg['media'] !== '' ? ($mediaIsGif ? 'GIF' : 'image') . ' media' : 'no media' ?> ·
        <?= (int)$cfg['duration'] ?> ms · <?= e(sh_transition_fades()[$cfg['fade']]) ?>
      </p>
      <div class="sh-actions" style="margin-top:10px">
        <button class="sh-btn sh-btn--sm sh-btn--ghost" type="button" data-transition-test
                data-transition-test-duration="<?= (int)$cfg['duration'] ?>" data-transition-test-media="<?= e($cfg['media_url']) ?>"
                data-transition-test-bg="<?= e($cfg['bg_css']) ?>" data-transition-test-dark="<?= $cfg['dark'] ? '1' : '0' ?>"
                data-transition-test-fade="<?= e($cfg['fade']) ?>"><?= sh_icon('zap', 13) ?> Play transition</button>
      </div>
    </div>
  </section>
</div>
</form>

<?php if ($cfg['media'] !== '' || $cfg['bg_media'] !== ''): ?>
<div class="sh-actions" style="margin-top:12px">
  <?php if ($cfg['bg_media'] !== ''): ?>
    <form method="post" data-confirm="Remove the background image/GIF?"><?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="remove_bg">
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?> Remove background media</button>
    </form>
  <?php endif; ?>
  <?php if ($cfg['media'] !== ''): ?>
    <form method="post" data-confirm="Remove the centred transition media?"><?= sh_csrf_field() ?>
      <input type="hidden" name="form" value="remove_media">
      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?> Remove transition media</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
