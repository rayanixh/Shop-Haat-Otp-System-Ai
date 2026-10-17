<?php
/**
 * Admin — create or edit one administrator account. Super Admin only.
 *
 * admin/admins.php keeps the account list and the delete action; the record
 * itself (name, email, role, status, password) is created and edited here, so
 * the listing never renders a form.
 *
 *   admin/admin-edit.php          → add
 *   admin/admin-edit.php?edit=3   → edit admin 3
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_superadmin();
sh_require_perm('admins.manage');
sh_perms_schema_ensure();

$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);
$roles = sh_roles_all();
$roleById = [];
foreach ($roles as $r) { $roleById[(int)$r['id']] = $r; }

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/admins.php') . ($keepQs !== '' ? '?' . $keepQs : '');

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF first: nothing below runs without a valid token.
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'save') { sh_redirect($listUrl); }
}

if ($form === 'save') {
    $id = sh_int($_POST['id'] ?? 0);
    $name = trim(sh_post('name'));
    $email = mb_strtolower(trim(sh_post('email')));
    $roleId = sh_int($_POST['role_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $status = sh_post('status') === 'disabled' ? 'disabled' : 'active';
    if ($name === '' || mb_strlen($name) > 120) { $errors['name'] = 'Please enter a name (max 120 characters).'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Please enter a valid email address.'; }
    if (!isset($roleById[$roleId])) { $errors['role_id'] = 'Please choose a role.'; }
    // Administrator policy: a minimum length (12 by default) and nothing else.
    // Any characters are accepted, including spaces inside a passphrase, and the
    // exact bytes the operator typed are hashed.
    if ($id === 0 && $password === '') {
        $errors['password'] = 'A password is required.';
    } elseif ($password !== '' && ($pwProblem = sh_admin_password_problem($password)) !== null) {
        $errors['password'] = $pwProblem;
    }
    $dupe = sh_one('SELECT id FROM admins WHERE email = ? AND id <> ?', [$email, $id]);
    if ($dupe) { $errors['email'] = 'Another admin already uses this email.'; }
    if ($id === (int)$admin['id'] && $status === 'disabled') { $errors['status'] = 'You cannot disable your own account.'; }
    if (!$errors) {
        $isSuper = ($roleById[$roleId]['slug'] ?? '') === 'super_admin';
        $data = ['name' => $name, 'email' => $email, 'role_id' => $roleId, 'role' => $isSuper ? 'superadmin' : 'manager', 'status' => $status];
        if ($id === (int)$admin['id']) { unset($data['role'], $data['role_id']); } // never demote yourself by accident
        if ($password !== '') { $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
        try {
            if ($id > 0) {
                $before = sh_one('SELECT name, email, role_id, status FROM admins WHERE id = ?', [$id]) ?? [];
                sh_update('admins', $data, 'id = ?', [$id]);
                $diff = [];
                foreach (['name', 'email', 'role_id', 'status'] as $k) { if (isset($data[$k]) && (string)($before[$k] ?? '') !== (string)$data[$k]) { $diff[$k] = [$before[$k] ?? null, $data[$k]]; } }
                if ($password !== '') { $diff['password'] = ['••••', '•••• (changed)']; }
                if ($diff) { sh_audit('admin_updated', 'admin', $id, $email, array_map(static fn($d) => $d[0], $diff), array_map(static fn($d) => $d[1], $diff)); }
                sh_flash('success', 'Admin account updated.');
            } else {
                $id = sh_insert('admins', $data);
                sh_audit('admin_created', 'admin', $id, $email, null, ['role' => $roleById[$roleId]['name'], 'status' => $status]);
                sh_flash('success', 'Admin account created.');
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'admins');
            $errors['general'] = 'The account could not be saved. The error has been logged.';
        }
        if (!$errors) { sh_redirect($listUrl); }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_one('SELECT id, name, email, role, role_id, status FROM admins WHERE id = ?', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That admin account no longer exists.');
    sh_redirect($listUrl);
}
$isNew      = $editing === null;
$isSelf     = $editing !== null && (int)$editing['id'] === (int)$admin['id'];
$val = static function (string $k, $fb = '') use ($editing, $errors) {
    if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; }
    return (string)($editing[$k] ?? $fb);
};

$adminPage = 'admins';
$adminTitle = $isNew ? 'Add admin' : 'Edit admin';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Admin users' => $listUrl,
    ($isNew ? 'Add admin' : (string)$editing['name']) => '',
]) ?>

<?= sh_admin_error_summary($errors) ?>

<?php if ($isSelf): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('info', 16) ?>
    <span>This is your own account. Your role cannot be changed and it cannot be disabled from here.</span></div>
<?php endif; ?>

<form method="post" novalidate autocomplete="off" id="sh-admin-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__body">

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('user', 15) ?> Account</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Name',
              '<input class="sh-input" id="a-name" name="name" required maxlength="120" value="' . e($val('name')) . '">',
              '', $errors['name'] ?? null, 'a-name', true) ?>
          <?= sh_admin_field('Email',
              '<input class="sh-input" id="a-email" type="email" name="email" required value="' . e($val('email')) . '">',
              'Used to sign in to this panel.', $errors['email'] ?? null, 'a-email', true) ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('shield', 15) ?> Access</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Role',
              '<select class="sh-select" id="a-role" name="role_id"' . ($isSelf ? ' disabled' : '') . '>'
              . implode('', array_map(
                  static fn(array $r): string => '<option value="' . (int)$r['id'] . '"'
                      . ((int)$val('role_id', 0) === (int)$r['id'] ? ' selected' : '') . '>' . e((string)$r['name']) . '</option>',
                  $roles
              ))
              . '</select>'
              . ($isSelf ? '<input type="hidden" name="role_id" value="' . (int)$editing['role_id'] . '">' : ''),
              $isSelf ? 'You cannot change your own role.' : 'Which permissions this account has.',
              $errors['role_id'] ?? null, 'a-role', true) ?>
          <?= sh_admin_field('Status',
              '<select class="sh-select" id="a-status" name="status">'
              . '<option value="active"' . ($val('status', 'active') === 'active' ? ' selected' : '') . '>Active</option>'
              . '<option value="disabled"' . ($val('status') === 'disabled' ? ' selected' : '') . '>Disabled — cannot sign in</option>'
              . '</select>',
              'A disabled account keeps its history but cannot sign in.', $errors['status'] ?? null, 'a-status') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('key', 15) ?> Password</h3>
        <?= sh_admin_field($isNew ? 'Password' : 'New password',
            '<input class="sh-input" id="a-pass" type="password" name="password" minlength="' . (int)sh_password_min_length('admin') . '" autocomplete="new-password"' . ($isNew ? ' required' : '') . '>',
            $isNew ? 'At least ' . (int)sh_password_min_length('admin') . ' characters.' : 'Leave blank to keep the current password.',
            $errors['password'] ?? null, 'a-pass', $isNew) ?>
      </div>

    </div>
    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Create admin' : 'Save changes' ?></button>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>">Cancel</a>
      <?php if (!$isNew && !$isSelf): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-admin-delete"
                data-confirm="Delete the admin account &quot;<?= e((string)$editing['email']) ?>&quot;? This cannot be undone.">
          <?= sh_icon('trash', 15) ?> Delete account</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$isNew && !$isSelf): ?>
  <form method="post" action="<?= e(sh_url('admin/admins.php')) ?>" id="sh-admin-delete">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
