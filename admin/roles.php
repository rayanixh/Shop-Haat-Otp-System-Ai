<?php
/**
 * Roles & permissions — Super Admin only.
 *
 * This page lists the roles and removes them. Creating and editing a role lives
 * on admin/role-edit.php, because the permission matrix is the largest form in
 * the panel and does not belong underneath a listing.
 */
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

$newUrl = sh_url('admin/role-edit.php?new=1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);
    $role = $id > 0 ? sh_one('SELECT * FROM admin_roles WHERE id = ?', [$id]) : null;

    if ($form === 'save') {
        // The role form has its own screen now: a save posted here (an open tab
        // from before the change) is forwarded with its id, never dropped.
        sh_flash('info', 'The role form has moved to its own page.');
        sh_redirect('admin/role-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
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

// An old bookmark to ?edit= / ?new= opens the dedicated screen instead of
// silently showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit', 'new']);
    if ($forwardId > 0) { sh_redirect('admin/role-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
    if (isset($_GET['new'])) { sh_redirect('admin/role-edit.php?new=1' . ($keep !== '' ? '&' . $keep : '')); }
}

$roles = sh_roles_all();

$adminPage = 'roles';
$adminTitle = 'Roles & Permissions';
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_header(
    'Roles & Permissions',
    'A role bundles the permissions an admin account gets. Super Admin always has every permission.',
    sh_admin_add_button('Add role', $newUrl, '', 'sh-btn sh-btn--sm', 'roles.manage')
    . '<a class="sh-btn sh-btn--sm sh-btn--ghost" href="' . e(sh_url('admin/admins.php')) . '">' . sh_icon('users', 14) . ' Admin users</a>',
    'shield'
) ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <span class="sh-formcard__note"><?= (int)count($roles) ?> role<?= count($roles) === 1 ? '' : 's' ?></span>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Role</th><th>Permissions</th><th>Admins</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($roles as $r): $isSystem = (int)$r['is_system'] === 1; $n = $isSystem ? count(sh_perms_all()) : count(sh_role_permissions($r)); ?>
        <tr>
          <td data-label="Role"><strong><?= e($r['name']) ?></strong><?= $isSystem ? ' <span class="sh-badge sh-badge--ok">System</span>' : '' ?><?php if ($r['description']): ?><div class="sh-table__meta"><?= e($r['description']) ?></div><?php endif; ?></td>
          <td data-label="Permissions"><?= (int)$n ?> of <?= count(sh_perms_all()) ?></td>
          <td data-label="Admins"><?= (int)$r['admin_count'] ?></td>
          <td data-label="Actions"><div class="sh-table__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/role-edit.php?edit=' . (int)$r['id'])) ?>" title="<?= $isSystem ? 'View' : 'Edit' ?> <?= e($r['name']) ?>"><?= sh_icon($isSystem ? 'eye' : 'pencil', 13) ?> <?= $isSystem ? 'View' : 'Edit' ?></a>
            <?php if (!$isSystem): ?>
              <form method="post" data-confirm="Delete the role <?= e($r['name']) ?>?"><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" aria-label="Delete role <?= e($r['name']) ?>"><?= sh_icon('trash', 13) ?></button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
