<?php
/** Roles & permissions — Super Admin only. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';
require_once SH_ROOT . '/includes/admin-tools.php';

sh_session_start();
$admin = sh_require_superadmin();
sh_require_perm('roles.manage');
sh_perms_schema_ensure();

$catalog = sh_perms_catalog();
$ownerOnly = sh_perms_superadmin_only();
$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);
    $role = $id > 0 ? sh_one('SELECT * FROM admin_roles WHERE id = ?', [$id]) : null;

    if ($form === 'save') {
        if ($role !== null && (int)$role['is_system'] === 1) { sh_flash('error', 'The Super Admin role cannot be edited.'); sh_redirect('admin/roles.php'); }
        $name = trim(sh_post('name'));
        $desc = mb_substr(trim(sh_post('description')), 0, 255);
        $perms = $_POST['perms'] ?? [];
        $perms = is_array($perms) ? array_values(array_intersect(array_map('strval', $perms), sh_perms_all())) : [];
        $perms = array_values(array_diff($perms, $ownerOnly));
        if ($name === '' || mb_strlen($name) > 80) { $errors['name'] = 'Please enter a role name (max 80 characters).'; }
        $slug = $role['slug'] ?? preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name));
        $slug = trim((string)$slug, '_') ?: 'role_' . time();
        if ($role === null && sh_one('SELECT id FROM admin_roles WHERE slug = ?', [$slug])) { $slug .= '_' . substr(bin2hex(random_bytes(2)), 0, 4); }
        if (!$errors) {
            $data = ['name' => $name, 'description' => $desc ?: null, 'permissions' => json_encode($perms)];
            try {
                if ($role !== null) {
                    sh_update('admin_roles', $data, 'id = ?', [$id]);
                    $before = sh_role_permissions($role);
                    sh_audit('role_updated', 'role', $id, $name, ['name' => $role['name'], 'permissions' => $before], ['name' => $name, 'permissions' => $perms]);
                    sh_flash('success', 'Role updated.');
                } else {
                    $data['slug'] = $slug;
                    $id = sh_insert('admin_roles', $data);
                    sh_audit('role_created', 'role', $id, $name, null, ['permissions' => $perms]);
                    sh_flash('success', 'Role created.');
                }
                sh_redirect('admin/roles.php');
            } catch (Throwable $e) { sh_log_exception($e, 'roles'); $errors['general'] = 'The role could not be saved. The error has been logged.'; }
        }
        $editId = $id;
    }

    if ($form === 'delete') {
        if ($role === null) { sh_flash('error', 'Role not found.'); }
        elseif ((int)$role['is_system'] === 1) { sh_flash('error', 'System roles cannot be deleted.'); }
        elseif ((int)sh_val('SELECT COUNT(*) FROM admins WHERE role_id = ?', [$id], 0) > 0) { sh_flash('error', 'Reassign the admins using this role before deleting it.'); }
        else {
            sh_query('DELETE FROM admin_roles WHERE id = ?', [$id]);
            sh_audit('role_deleted', 'role', $id, (string)$role['name'], ['permissions' => sh_role_permissions($role)], null);
            sh_flash('success', 'Role deleted.');
        }
        sh_redirect('admin/roles.php');
    }
}

$editing = $editId > 0 ? sh_one('SELECT * FROM admin_roles WHERE id = ?', [$editId]) : null;
$showForm = $editing !== null || isset($_GET['new']) || $errors;
$selected = $errors ? (array)($_POST['perms'] ?? []) : sh_role_permissions($editing);
$roles = sh_roles_all();

$adminPage = 'roles';
$adminTitle = 'Roles & Permissions';
require __DIR__ . '/_layout.php';
?>
<?php if ($showForm): ?>
<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> <?= $editing ? 'Edit role: ' . e($editing['name']) : 'New role' ?></h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/roles.php')) ?>">Cancel</a></div></div>
  <div class="sh-panel__body">
    <?php if (!empty($errors['general'])): ?><div class="sh-alert sh-alert--error"><?= e($errors['general']) ?></div><?php endif; ?>
    <?php if ($editing && (int)$editing['is_system'] === 1): ?><div class="sh-alert sh-alert--info"><?= sh_icon('info', 16) ?><span>The Super Admin role always has every permission and cannot be edited.</span></div><?php endif; ?>
    <form method="post" class="sh-form"><?= sh_csrf_field() ?><input type="hidden" name="form" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="sh-grid2">
        <div class="sh-field"><label class="sh-field__label" for="r-name">Role name <span class="sh-field__req">*</span></label><input class="sh-input" id="r-name" name="name" required maxlength="80" value="<?= e($errors ? sh_post('name') : (string)($editing['name'] ?? '')) ?>"><?php if (!empty($errors['name'])): ?><span class="sh-field__error"><?= e($errors['name']) ?></span><?php endif; ?></div>
        <div class="sh-field"><label class="sh-field__label" for="r-desc">Description</label><input class="sh-input" id="r-desc" name="description" maxlength="255" value="<?= e($errors ? sh_post('description') : (string)($editing['description'] ?? '')) ?>"></div>
      </div>
      <div class="sh-perm-grid">
        <?php foreach ($catalog as $group => $perms): ?>
          <fieldset class="sh-perm-group">
            <legend><?= e($group) ?></legend>
            <?php foreach ($perms as $key => $label): $locked = in_array($key, $ownerOnly, true); ?>
              <label class="sh-perm-item <?= $locked ? 'is-locked' : '' ?>">
                <input type="checkbox" name="perms[]" value="<?= e($key) ?>" <?= $locked ? 'disabled' : '' ?> <?= in_array($key, $selected, true) && !$locked ? 'checked' : '' ?>>
                <span><?= e($label) ?><?= $locked ? ' <small>(Super Admin only)</small>' : '' ?></span>
              </label>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
      </div>
      <?php if (!$editing || (int)$editing['is_system'] !== 1): ?><button class="sh-btn" type="submit" style="margin-top:12px"><?= sh_icon('check-circle', 15) ?> Save role</button><?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="sh-panel">
  <div class="sh-panel__head"><h2 class="sh-panel__title"><?= sh_icon('shield', 17) ?> Roles (<?= count($roles) ?>)</h2>
    <div class="sh-panel__actions"><a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/admins.php')) ?>"><?= sh_icon('users', 14) ?> Admin users</a><a class="sh-btn sh-btn--sm" href="<?= e(sh_url('admin/roles.php?new=1')) ?>"><?= sh_icon('plus', 14) ?> New role</a></div></div>
  <div class="sh-tablewrap">
    <table class="sh-table">
      <thead><tr><th>Role</th><th>Permissions</th><th>Admins</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r): $n = (int)$r['is_system'] === 1 ? count(sh_perms_all()) : count(sh_role_permissions($r)); ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong><?= (int)$r['is_system'] === 1 ? ' <span class="sh-badge sh-badge--ok">System</span>' : '' ?><?php if ($r['description']): ?><div class="sh-table__meta"><?= e($r['description']) ?></div><?php endif; ?></td>
          <td><?= $n ?> of <?= count(sh_perms_all()) ?></td>
          <td><?= (int)$r['admin_count'] ?></td>
          <td><div class="sh-actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/roles.php?edit=' . (int)$r['id'])) ?>"><?= sh_icon((int)$r['is_system'] === 1 ? 'eye' : 'pencil', 13) ?> <?= (int)$r['is_system'] === 1 ? 'View' : 'Edit' ?></a>
            <?php if ((int)$r['is_system'] !== 1): ?><form method="post" data-confirm="Delete the role <?= e($r['name']) ?>?"><?= sh_csrf_field() ?><input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="sh-btn sh-btn--sm sh-btn--ghost" type="submit"><?= sh_icon('trash', 13) ?></button></form><?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
