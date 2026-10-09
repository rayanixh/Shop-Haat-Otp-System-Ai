<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
$admin = sh_require_admin();
require_once SH_ROOT . '/includes/admin-perms.php';
sh_require_perm('storefront.manage');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');

    if ($form === 'theme') {
        $errors = sh_theme_save($_POST);
        if (!$errors) {
            sh_settings(true);
            sh_log_line('admin', 'Theme colours updated by ' . $admin['email']);
            sh_flash('success', 'Theme saved. The storefront now uses your colours.');
            sh_redirect('admin/theme.php');
        }
    }

    if ($form === 'reset') {
        sh_theme_reset();
        sh_log_line('admin', 'Theme reset to defaults by ' . $admin['email']);
        sh_flash('success', 'Theme restored to the default colours.');
        sh_redirect('admin/theme.php');
    }
}

$colors   = sh_theme_colors();
if ($errors) { foreach ($colors as $k => $v) { if (isset($_POST[$k])) { $colors[$k] = (string)$_POST[$k]; } } }
$defaults = sh_theme_defaults();
$registry = sh_theme_registry();
$groups   = sh_theme_groups();
$issues   = sh_theme_contrast_issues(sh_theme_colors());
$groupIcon = [
    'Primary Colors' => 'tag', 'Text Colors' => 'file', 'Button Colors' => 'check-circle', 'Link Colors' => 'external',
    'Status Colors' => 'info', 'Form Colors' => 'pencil', 'Header / Navigation' => 'layout', 'Footer Colors' => 'grid',
];

$adminPage = 'theme';
$adminTitle = 'Theme Customization';
require __DIR__ . '/_layout.php';
?>
<?php if ($errors): ?>
  <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 17) ?>
    <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<?php if ($issues): ?>
  <div class="sh-alert sh-alert--warning"><?= sh_icon('alert', 17) ?>
    <div><strong>Low contrast detected.</strong> These combinations are below the 4.5:1 readability guideline:
      <ul><?php foreach ($issues as [$label, $fg, $bg, $ratio]): ?>
        <li><?= e($label) ?> — <?= e($ratio) ?>:1
          <span class="sh-theme-swatch" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>">Aa</span></li>
      <?php endforeach; ?></ul></div></div>
<?php endif; ?>

<form method="post" novalidate id="sh-theme-form" class="sh-theme">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="theme">

  <div class="sh-theme__layout">
    <div class="sh-theme__groups">
      <?php foreach ($groups as $group => $keys): ?>
        <section class="sh-panel">
          <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($groupIcon[$group] ?? 'settings', 17) ?> <?= e($group) ?></h2></div>
          <div class="sh-panel__body sh-theme__grid">
            <?php foreach ($keys as $k): [$var, $label, $def] = $registry[$k]; $v = $colors[$k]; ?>
              <div class="sh-color" data-color-row="<?= e($k) ?>">
                <label class="sh-color__label" for="th-<?= e($k) ?>"><?= e($label) ?></label>
                <div class="sh-color__ctl">
                  <label class="sh-color__well" style="background:<?= e(sh_theme_hex($v) ?? $def) ?>" title="Pick a colour">
                    <input type="color" value="<?= e(sh_theme_hex($v) ?? $def) ?>" data-color-picker aria-label="<?= e($label) ?> picker">
                  </label>
                  <input class="sh-input sh-color__hex <?= isset($errors[$k]) ? 'sh-input--error' : '' ?>" id="th-<?= e($k) ?>" name="<?= e($k) ?>"
                         value="<?= e($v) ?>" maxlength="7" spellcheck="false" autocomplete="off" inputmode="text"
                         pattern="#?[0-9a-fA-F]{6}" data-color-hex data-var="<?= e($var) ?>" data-default="<?= e($def) ?>">
                  <button class="sh-color__reset" type="button" data-color-default title="Use default <?= e($def) ?>" aria-label="Reset <?= e($label) ?>"><?= sh_icon('rotate', 13) ?></button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <aside class="sh-theme__side">
      <div class="sh-panel sh-theme__sticky">
        <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('eye', 17) ?> Live preview</h2></div>
        <div class="sh-panel__body" style="padding:0">
          <div class="sh-tp" id="sh-theme-preview">
            <div class="sh-tp__topbar">Free delivery over BDT 2,000 <span>Track order</span></div>
            <div class="sh-tp__header">
              <span class="sh-tp__mark">SH</span><strong class="sh-tp__brand"><?= e(sh_setting('site_name', 'ShopHaat')) ?></strong>
              <span class="sh-tp__search"><span>Search products…</span><i></i></span>
            </div>
            <div class="sh-tp__nav"><b>Home</b><span>Electronics</span><span>Fashion</span><span>Digital</span></div>
            <div class="sh-tp__body">
              <h4>Featured products</h4>
              <div class="sh-tp__cards">
                <div class="sh-tp__card"><div class="sh-tp__img"></div><p>Wireless Earbuds</p><em>BDT 1,990</em><span class="sh-tp__btn">Add to cart</span></div>
                <div class="sh-tp__card"><div class="sh-tp__img"></div><p>Smart Watch</p><em>BDT 3,450</em><span class="sh-tp__btn sh-tp__btn--2">View</span></div>
              </div>
              <div class="sh-tp__form">
                <label>Email address</label>
                <div class="sh-tp__input">you@example.com</div>
                <div class="sh-tp__input sh-tp__input--focus">Focused input</div>
                <a class="sh-tp__link">Forgot password?</a>
              </div>
              <div class="sh-tp__status">
                <span class="sh-tp__pill sh-tp__pill--ok">Delivered</span>
                <span class="sh-tp__pill sh-tp__pill--warn">Pending</span>
                <span class="sh-tp__pill sh-tp__pill--bad">Cancelled</span>
                <span class="sh-tp__pill sh-tp__pill--info">Info</span>
              </div>
            </div>
            <div class="sh-tp__footer"><strong>Customer care</strong><span>Help center</span><span>Returns</span><small>© <?= date('Y') ?> <?= e(sh_setting('site_name', 'ShopHaat')) ?></small></div>
          </div>
        </div>
        <div class="sh-panel__body sh-theme__actions">
          <button class="sh-btn sh-btn--block" type="submit"><?= sh_icon('check-circle', 15) ?> Save changes</button>
          <button class="sh-btn sh-btn--ghost sh-btn--block" type="button" data-theme-revert><?= sh_icon('rotate', 15) ?> Discard unsaved edits</button>
          <p class="sh-theme__state"><?= sh_theme_is_custom() ? 'Custom theme active.' : 'Default theme active.' ?></p>
        </div>
      </div>
    </aside>
  </div>
</form>

<?php if (sh_theme_is_custom()): ?>
<form method="post" class="sh-theme__resetbar" data-confirm="Reset every theme colour to the original defaults? Only colours are affected — no other settings or data change.">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="reset">
  <div>
    <strong>Reset to Default Theme</strong>
    <p>Restores the original colour palette. Products, orders, customers and all other settings stay untouched.</p>
  </div>
  <button class="sh-btn sh-btn--ghost" type="submit"><?= sh_icon('rotate', 15) ?> Reset to default theme</button>
</form>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('sh-theme-form');
  var preview = document.getElementById('sh-theme-preview');
  if (!form || !preview) { return; }

  function norm(v) {
    v = (v || '').trim().toLowerCase();
    if (v && v[0] !== '#') { v = '#' + v; }
    if (/^#[0-9a-f]{3}$/.test(v)) { v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]; }
    return /^#[0-9a-f]{6}$/.test(v) ? v : null;
  }
  function lum(hex) {
    var c = [1, 3, 5].map(function (i) { var x = parseInt(hex.substr(i, 2), 16) / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function apply() {
    var pri = null, sec = null, focus = null;
    form.querySelectorAll('[data-color-hex]').forEach(function (inp) {
      var hex = norm(inp.value);
      var row = inp.closest('[data-color-row]');
      var well = row.querySelector('.sh-color__well');
      var picker = row.querySelector('[data-color-picker]');
      inp.classList.toggle('sh-input--error', !hex);
      if (!hex) { return; }
      well.style.background = hex;
      if (picker.value !== hex) { picker.value = hex; }
      preview.style.setProperty(inp.getAttribute('data-var'), hex);
      if (inp.name === 'primary') { pri = hex; }
      if (inp.name === 'secondary') { sec = hex; }
      if (inp.name === 'input_focus') { focus = hex; }
      row.classList.toggle('sh-color--changed', hex !== inp.getAttribute('data-default'));
    });
    if (pri) { preview.style.setProperty('--sh-on-brand', lum(pri) > 0.45 ? '#1f2430' : '#ffffff'); }
    if (sec) { preview.style.setProperty('--sh-on-accent', lum(sec) > 0.45 ? '#1f2430' : '#ffffff'); }
    if (focus) {
      var r = parseInt(focus.substr(1, 2), 16), g = parseInt(focus.substr(3, 2), 16), b = parseInt(focus.substr(5, 2), 16);
      preview.style.setProperty('--sh-focus-ring', 'rgba(' + r + ',' + g + ',' + b + ',.14)');
    }
  }
  form.addEventListener('input', function (ev) {
    var t = ev.target;
    if (t.matches('[data-color-picker]')) {
      var hex = t.closest('[data-color-row]').querySelector('[data-color-hex]');
      hex.value = t.value;
    }
    apply();
  });
  form.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-color-default]');
    if (btn) {
      var hex = btn.closest('[data-color-row]').querySelector('[data-color-hex]');
      hex.value = hex.getAttribute('data-default');
      apply();
    }
    var revert = ev.target.closest('[data-theme-revert]');
    if (revert) {
      form.querySelectorAll('[data-color-hex]').forEach(function (inp) { inp.value = inp.defaultValue; });
      apply();
    }
  });
  form.addEventListener('submit', function (ev) {
    var bad = false;
    form.querySelectorAll('[data-color-hex]').forEach(function (inp) {
      var hex = norm(inp.value);
      if (hex) { inp.value = hex; } else { bad = true; inp.classList.add('sh-input--error'); }
    });
    if (bad) {
      ev.preventDefault();
      var first = form.querySelector('.sh-input--error');
      if (first) { first.focus(); first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
      if (window.shToast) { window.shToast('Please fix the highlighted colour values (use #RRGGBB).', 'error'); }
    }
  });
  apply();
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
