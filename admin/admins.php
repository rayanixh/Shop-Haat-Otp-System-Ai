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

$newUrl = sh_url('admin/admin-edit.php?new=1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    $form = sh_post('form');
    $id = sh_int($_POST['id'] ?? 0);

    if ($form === 'save') {
        // The account form has its own screen now: a save posted here (an open
        // tab from before the change) is forwarded with its id, never dropped.
        sh_flash('info', 'The admin account form has moved to its own page.');
        sh_redirect('admin/admin-edit.php' . ($id > 0 ? '?edit=' . $id : '?new=1'));
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

// An old bookmark to ?edit= / ?new= opens the dedicated screen instead of
// silently showing the list.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $forwardId = sh_int($_GET['edit'] ?? 0);
    $keep = sh_admin_keep_query(['edit', 'new']);
    if ($forwardId > 0) { sh_redirect('admin/admin-edit.php?edit=' . $forwardId . ($keep !== '' ? '&' . $keep : '')); }
    if (isset($_GET['new'])) { sh_redirect('admin/admin-edit.php?new=1' . ($keep !== '' ? '&' . $keep : '')); }
}

$list = sh_all('SELECT a.id, a.name, a.email, a.role, a.role_id, a.status, a.last_login_at, a.created_at, r.name AS role_name FROM admins a LEFT JOIN admin_roles r ON r.id = a.role_id ORDER BY a.id ASC');

$adminPage = 'admins';
$adminTitle = 'Admin Users';
require __DIR__ . '/_layout.php';
?>
<?= sh_admin_header(
    'Admin Users',
    'Accounts that can sign in to this panel. Every change is recorded in the audit log.',
    sh_admin_add_button('Add admin', $newUrl, '', 'sh-btn sh-btn--sm', 'admins.manage')
    . '<a class="sh-btn sh-btn--sm sh-btn--ghost" href="' . e(sh_url('admin/roles.php')) . '">' . sh_icon('shield', 14) . ' Roles &amp; permissions</a>',
    'users'
) ?>

<div class="sh-panel">
  <div class="sh-panel__head">
    <span class="sh-formcard__note"><?= (int)count($list) ?> account<?= count($list) === 1 ? '' : 's' ?></span>
  </div>
  <div class="sh-tablewrap sh-tablewrap--cards">
    <table class="sh-table">
      <thead><tr><th>Admin</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th data-label="" style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): $self = (int)$a['id'] === (int)$admin['id']; ?>
        <tr>
          <td data-label="Admin"><strong><?= e($a['name']) ?></strong><?= $self ? ' <span class="sh-badge sh-badge--muted">You</span>' : '' ?><div class="sh-table__meta"><?= e($a['email']) ?></div></td>
          <td data-label="Role"><span class="sh-badge <?= $a['role'] === 'superadmin' ? 'sh-badge--ok' : 'sh-badge--muted' ?>"><?= e($a['role_name'] ?? ($a['role'] === 'superadmin' ? 'Super Admin' : 'Manager (legacy)')) ?></span></td>
          <td data-label="Status"><span class="sh-badge <?= $a['status'] === 'active' ? 'sh-badge--ok' : 'sh-badge--bad' ?>"><?= e(ucfirst((string)$a['status'])) ?></span></td>
          <td data-label="Last login" class="sh-table__meta"><?= $a['last_login_at'] ? e(date('d M Y, h:i A', strtotime((string)$a['last_login_at']))) : 'Never' ?></td>
          <td data-label="Created" class="sh-table__meta"><?= e(date('d M Y', strtotime((string)$a['created_at']))) ?></td>
          <td data-label="Actions"><div class="sh-table__actions">
            <a class="sh-btn sh-btn--sm sh-btn--ghost" href="<?= e(sh_url('admin/admin-edit.php?edit=' . (int)$a['id'])) ?>" title="Edit <?= e($a['name']) ?>"><?= sh_icon('pencil', 13) ?> Edit</a>
            <?php if (!$self): ?>
              <form method="post" data-confirm="Delete the admin account <?= e($a['email']) ?>? This cannot be undone."><?= sh_csrf_field() ?>
                <input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="sh-btn sh-btn--sm sh-btn--bad" type="submit" aria-label="Delete <?= e($a['email']) ?>"><?= sh_icon('trash', 13) ?></button></form>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
