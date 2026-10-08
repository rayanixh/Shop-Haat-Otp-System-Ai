<?php
/** Admin accounts — Super Admin only. */
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);

    if ($form === 'save') {
        $name = trim(sh_post('name'));
        $email = mb_strtolower(trim(sh_post('email')));
        $roleId = sh_int($_POST['role_id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');
        $status = sh_post('status') === 'disabled' ? 'disabled' : 'active';
        if ($name === '' || mb_strlen($name) > 120) { $errors['name'] = 'Please enter a name (max 120 characters).'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Please enter a valid email address.'; }
        if (!isset($roleById[$roleId])) { $errors['role_id'] = 'Please choose a role.'; }
        if ($id === 0 && strlen($password) < 8) { $errors['password'] = 'A password of at least 8 characters is required.'; }
        if ($id > 0 && $password !== '' && strlen($password) < 8) { $errors['password'] = 'New password must be at least 8 characters.'; }
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
                sh_redirect('admin/admins.php');
            } catch (Throwable $e) { sh_log_exception($e, 'admins'); $errors['general'] = 'The account could not be saved. The error has been logged.'; }
        }
        $editId = $id;
    }

    if ($form === 'delete') {
        $target = sh_one('SELECT id, email, role FROM admins WHERE id = ?', [$id]);
        if ($target === null) { sh_flash('error', 'Admin not found.'); }
        elseif ($id === (int)$admin['id']) { sh_flash('error', 'You cannot delete your own account.'); }
        elseif ($target['role'] === 'superadmin' && (int)sh_val("SELECT COUNT(*) FROM admins WHERE role = 'superadmin' AND status = 'active'", [], 0) <= 1) { sh_flash('error', 'At least one active Super Admin must remain.'); }
        else {
            sh_query('DELETE FROM admins WHERE id = ?', [$id]);
            sh_audit('admin_deleted', 'admin', $id, (string)$target['email']);
            sh_flash('success', 'Admin account deleted.');
        }
        sh_redirect('admin/admins.php');
    }
}

$editing = $editId > 0 ? sh_one('SELECT id, name, email, role, role_id, status FROM admins WHERE id = ?', [$editId]) : null;
$showForm = $editing !== null || isset($_GET['new']) || $errors;
$val = static function (string $k, $fb = '') use ($editing, $errors) { if ($errors && isset($_POST[$k])) { return (string)$_POST[$k]; } return (string)($editing[$k] ?? $fb); };
$list = sh_all('SELECT a.id, a.name, a.email, a.role, a.role_id, a.status, a.last_login_at, a.created_at, r.name AS role_name FROM admins a LEFT JOIN admin_roles r ON r.id = a.role_id ORDER BY a.id ASC');

$adminPage = 'admins';
$adminTitle = 'Admin Users';
require __DIR__ . '/_layout.php';
?>
<?php if ($showForm): ?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon($editing ? 'pencil' : 'user-plus', 17) ?> <?= $editing ? 'Edit admin' : 'New admin' ?></h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/admins.php')) ?>">Cancel</a></div></div>
  <div class="sh-panel__body">
    <?php if (!empty($errors['general'])): ?><div class="sh-alert sh-alert--error"><?= e($errors['general']) ?></div><?php endif; ?>
    <form method="post" class="sh-form" autocomplete="off"><?= sh_csrf_field() ?><input type="hidden" name="form" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="a-name">Name <span class="sh-field__req">*</span></label><input class="sh-input" id="a-name" name="name" required maxlength="120" value="<?= e($val('name')) ?>"><?php if (!empty($errors['name'])): ?><span class="sh-field__error"><?= e($errors['name']) ?></span><?php endif; ?></div>
        <div class="sh-field"><label class="sh-field__label" for="a-email">Email <span class="sh-field__req">*</span></label><input class="sh-input" id="a-email" type="email" name="email" required value="<?= e($val('email')) ?>"><?php if (!empty($errors['email'])): ?><span class="sh-field__error"><?= e($errors['email']) ?></span><?php endif; ?></div>
      </div>
      <div class="sh-grid3">
        <div class="sh-field"><label class="sh-field__label" for="a-role">Role <span class="sh-field__req">*</span></label>
          <select class="sh-select" id="a-role" name="role_id" <?= $editing && (int)$editing['id'] === (int)$admin['id'] ? 'disabled' : '' ?>>
            <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$val('role_id', 0) === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if ($editing && (int)$editing['id'] === (int)$admin['id']): ?><input type="hidden" name="role_id" value="<?= (int)$editing['role_id'] ?>"><span class="sh-field__hint">You cannot change your own role.</span><?php endif; ?>
          <?php if (!empty($errors['role_id'])): ?><span class="sh-field__error"><?= e($errors['role_id']) ?></span><?php endif; ?></div>
        <div class="sh-field"><label class="sh-field__label" for="a-status">Status</label>
          <select class="sh-select" id="a-status" name="status"><option value="active" <?= $val('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="disabled" <?= $val('status') === 'disabled' ? 'selected' : '' ?>>Disabled</option></select>
          <?php if (!empty($errors['status'])): ?><span class="sh-field__error"><?= e($errors['status']) ?></span><?php endif; ?></div>
        <div class="sh-field"><label class="sh-field__label" for="a-pass"><?= $editing ? 'New password (leave blank to keep)' : 'Password' ?><?= $editing ? '' : ' <span class="sh-field__req">*</span>' ?></label>
          <input class="sh-input" id="a-pass" type="password" name="password" minlength="8" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
          <?php if (!empty($errors['password'])): ?><span class="sh-field__error"><?= e($errors['password']) ?></span><?php endif; ?></div>
      </div>
      <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> Save admin</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('users', 17) ?> Admin accounts (<?= count($list) ?>)</h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/roles.php')) ?>"><?= sh_icon('shield', 14) ?> Roles &amp; permissions</a><a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/admins.php?new=1')) ?>"><?= sh_icon('plus', 14) ?> New admin</a></div></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Admin</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><strong><?= e($a['name']) ?></strong><?= (int)$a['id'] === (int)$admin['id'] ? ' <span class="sh-badge sh-badge--muted">You</span>' : '' ?><div class="sh-table__meta"><?= e($a['email']) ?></div></td>
          <td><span class="sh-badge <?= $a['role'] === 'superadmin' ? 'sh-badge--ok' : 'sh-badge--muted' ?>"><?= e($a['role_name'] ?? ($a['role'] === 'superadmin' ? 'Super Admin' : 'Manager (legacy)')) ?></span></td>
          <td><span class="sh-badge <?= $a['status'] === 'active' ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= e(ucfirst($a['status'])) ?></span></td>
          <td class="sh-table__meta"><?= $a['last_login_at'] ? e(date('d M Y, h:i A', strtotime($a['last_login_at']))) : 'Never' ?></td>
          <td class="sh-table__meta"><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
          <td><div class="sh-actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/admins.php?edit=' . (int)$a['id'])) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
            <?php if ((int)$a['id'] !== (int)$admin['id']): ?><form method="post" data-confirm="Delete the admin account <?= e($a['email']) ?>? This cannot be undone."><?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?></button></form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
