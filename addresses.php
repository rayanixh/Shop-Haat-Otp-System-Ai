<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$user = sh_require_login();
$uid = (int)$user['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    if ($form === 'delete') {
        $id = sh_int($_POST['id'] ?? 0);
        // Ownership check prevents IDOR.
        sh_query('DELETE FROM addresses WHERE id = ? AND user_id = ?', [$id, $uid]);
        sh_flash('info', 'Address removed.');
        sh_redirect('addresses.php');
    } elseif ($form === 'default') {
        $id = sh_int($_POST['id'] ?? 0);
        $own = sh_one('SELECT id FROM addresses WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($own) {
            sh_query('UPDATE addresses SET is_default = 0 WHERE user_id = ?', [$uid]);
            sh_query('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?', [$id, $uid]);
            sh_flash('success', 'Default address updated.');
        }
        sh_redirect('addresses.php');
    } else {
        $v = new ShValidator($_POST);
        $v->required('full_name', 'Full name')->maxLen('full_name', 110, 'Full name')
          ->required('phone', 'Phone number')->phone('phone', 'Phone number')
          ->required('address_line', 'Address')->maxLen('address_line', 240, 'Address')
          ->required('city', 'City')->maxLen('city', 110, 'City');
        $errors = $v->errors();
        if (!$errors) {
            try {
                $isDefault = !empty($_POST['is_default']) ? 1 : 0;
                if ($isDefault) { sh_query('UPDATE addresses SET is_default = 0 WHERE user_id = ?', [$uid]); }
                sh_insert('addresses', [
                    'user_id' => $uid,
                    'label' => mb_substr(sh_post('label', 'Home'), 0, 60) ?: 'Home',
                    'full_name' => sh_post('full_name'),
                    'phone' => sh_post('phone'),
                    'address_line' => sh_post('address_line'),
                    'area' => sh_post('area') ?: null,
                    'city' => sh_post('city'),
                    'postcode' => sh_post('postcode') ?: null,
                    'is_default' => $isDefault,
                ]);
                sh_flash('success', 'Address saved.');
                sh_redirect('addresses.php');
            } catch (Throwable $e) {
                sh_log_exception($e, 'address-save');
                $errors['general'] = 'The address could not be saved.';
            }
        }
    }
}

$addresses = sh_all('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC', [$uid]);
$accountPage = 'addresses';
$pageTitle = 'My Addresses';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Addresses</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('map-pin', 18) ?> Saved Addresses (<?= count($addresses) ?>)</h1></div>
        <?php if ($errors): ?>
          <div class="sh-alert sh-alert--error"><?= sh_icon('x-circle', 16) ?>
            <div><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
        <?php endif; ?>
        <?php if (!$addresses): ?>
          <p style="color:var(--sh-muted);font-size:13.5px">You have not saved any address yet.</p>
        <?php else: ?>
          <div class="sh-grid2">
            <?php foreach ($addresses as $a): ?>
              <div style="border:1px solid var(--sh-line);border-radius:8px;padding:13px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                  <strong style="font-size:13.5px"><?= e($a['label']) ?></strong>
                  <?php if ((int)$a['is_default'] === 1): ?><span class="sh-badge sh-badge--ok">Default</span><?php endif; ?>
                </div>
                <p style="font-size:13px;line-height:1.7;color:var(--sh-ink-2)">
                  <?= e($a['full_name']) ?><br>
                  <?= e($a['address_line']) ?><br>
                  <?= e(trim(($a['area'] ? $a['area'] . ', ' : '') . $a['city'] . ' ' . (string)$a['postcode'])) ?><br>
                  <?= e($a['phone']) ?>
                </p>
                <div style="display:flex;gap:7px;margin-top:10px;flex-wrap:wrap">
                  <?php if ((int)$a['is_default'] !== 1): ?>
                    <form method="post"><?= sh_csrf_field() ?>
                      <input type="hidden" name="form" value="default"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                      <button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit">Set as default</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" data-confirm="Delete this address?"><?= sh_csrf_field() ?>
                    <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit"><?= sh_icon('trash', 13) ?> Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="sh-section">
        <div class="sh-section__head"><h2 class="sh-section__title"><?= sh_icon('plus', 18) ?> Add New Address</h2></div>
        <form method="post" novalidate>
          <?= sh_csrf_field() ?>
          <input type="hidden" name="form" value="create">
          <div class="sh-grid3">
            <div class="sh-field"><label class="sh-field__label" for="ad-label">Label</label>
              <input class="sh-input" id="ad-label" name="label" value="Home" maxlength="60"></div>
            <div class="sh-field"><label class="sh-field__label" for="ad-name">Full name <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="ad-name" name="full_name" required maxlength="110" value="<?= e($user['name']) ?>"></div>
            <div class="sh-field"><label class="sh-field__label" for="ad-phone">Phone <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="ad-phone" name="phone" required value="<?= e((string)$user['phone']) ?>"></div>
          </div>
          <div class="sh-field"><label class="sh-field__label" for="ad-line">Street address <span class="sh-field__req">*</span></label>
            <input class="sh-input" id="ad-line" name="address_line" required maxlength="240"></div>
          <div class="sh-grid3">
            <div class="sh-field"><label class="sh-field__label" for="ad-area">Area</label>
              <input class="sh-input" id="ad-area" name="area" maxlength="110"></div>
            <div class="sh-field"><label class="sh-field__label" for="ad-city">City <span class="sh-field__req">*</span></label>
              <input class="sh-input" id="ad-city" name="city" required maxlength="110" value="Dhaka"></div>
            <div class="sh-field"><label class="sh-field__label" for="ad-post">Postcode</label>
              <input class="sh-input" id="ad-post" name="postcode" maxlength="20"></div>
          </div>
          <label class="sh-check" style="margin-bottom:12px"><input type="checkbox" name="is_default" value="1"><span>Use as my default delivery address</span></label>
          <button class="sh-btn" type="submit"><?= sh_icon('plus', 15) ?> Save address</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
