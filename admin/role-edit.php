<?php
/**
 * Admin — create or edit one role and its permissions. Super Admin only.
 *
 * The permission matrix is the largest form in the panel, so it has its own
 * screen; admin/roles.php keeps the role list and the delete action.
 *
 *   admin/role-edit.php          → add
 *   admin/role-edit.php?edit=4   → edit role 4 (the Super Admin role is read-only)
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

$catalog   = sh_perms_catalog();
$ownerOnly = sh_perms_superadmin_only();
$errors = [];
$editId = sh_int($_GET['edit'] ?? 0);

$keepQs  = sh_admin_keep_query(['edit', 'new', 'form', 'id']);
$listUrl = sh_url('admin/roles.php') . ($keepQs !== '' ? '?' . $keepQs : '');

$form = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF first: nothing below runs without a valid token.
    sh_csrf_require();
    $form = sh_post('form');
    if ($form !== 'save') { sh_redirect($listUrl); }
}

if ($form === 'save') {
    $id = sh_int($_POST['id'] ?? 0);
    $role = $id > 0 ? sh_one('SELECT * FROM admin_roles WHERE id = ?', [$id]) : null;
    if ($role !== null && (int)$role['is_system'] === 1) {
        sh_flash('error', 'The Super Admin role cannot be edited.');
        sh_redirect($listUrl);
    }
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
        } catch (Throwable $e) {
            sh_log_exception($e, 'roles');
            $errors['general'] = 'The role could not be saved. The error has been logged.';
        }
        if (!$errors) { sh_redirect($listUrl); }
    }
    $editId = $id;
}

$editing = $editId > 0 ? sh_one('SELECT * FROM admin_roles WHERE id = ?', [$editId]) : null;
if ($editId > 0 && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('error', 'That role no longer exists.');
    sh_redirect($listUrl);
}
$isNew      = $editing === null;
$isSystem   = $editing !== null && (int)$editing['is_system'] === 1;
$selected   = $errors ? (array)($_POST['perms'] ?? []) : sh_role_permissions($editing);
$userCount  = $editing !== null ? (int)sh_val('SELECT COUNT(*) FROM admins WHERE role_id = ?', [(int)$editing['id']], 0) : 0;

$adminPage = 'roles';
$adminTitle = $isNew ? 'Add role' : ($isSystem ? 'View role' : 'Edit role');
require __DIR__ . '/_layout.php';
?>

<?= sh_admin_edit_head([
    'Roles & permissions' => $listUrl,
    ($isNew ? 'Add role' : (string)$editing['name']) => '',
]) ?>

<?= sh_admin_error_summary($errors) ?>

<?php if ($isSystem): ?>
  <div class="sh-alert sh-alert--info"><?= sh_icon('shield', 16) ?>
    <span>The Super Admin role always has every permission and cannot be edited. Assign a different role if an account should have less access.</span></div>
<?php elseif (!$isNew): ?>
  <p class="sh-formcard__note">
    <?= count($selected) ?> of <?= count(sh_perms_all()) ?> permissions granted ·
    <?= (int)$userCount ?> admin account<?= $userCount === 1 ? '' : 's' ?> use this role<?php if ($userCount > 0 && !$isSystem): ?>
      (<a href="<?= e(sh_url('admin/admins.php')) ?>">manage admins</a>)<?php endif; ?>.
  </p>
<?php endif; ?>

<form method="post" novalidate id="sh-role-form">
  <?= sh_csrf_field() ?>
  <input type="hidden" name="form" value="save">
  <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

  <div class="sh-panel sh-formcard">
    <div class="sh-panel__body">

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('shield', 15) ?> Role</h3>
        <div class="sh-grid2">
          <?= sh_admin_field('Role name',
              '<input class="sh-input" id="r-name" name="name" required maxlength="80" value="'
              . e($errors ? sh_post('name') : (string)($editing['name'] ?? '')) . '"' . ($isSystem ? ' readonly' : '') . '>',
              '', $errors['name'] ?? null, 'r-name', !$isSystem) ?>
          <?= sh_admin_field('Description',
              '<input class="sh-input" id="r-desc" name="description" maxlength="255" value="'
              . e($errors ? sh_post('description') : (string)($editing['description'] ?? '')) . '"' . ($isSystem ? ' readonly' : '') . '>',
              'Optional. Shown beside the role when assigning it to an admin.', null, 'r-desc') ?>
        </div>
      </div>

      <div class="sh-formsec">
        <h3 class="sh-formsec__title"><?= sh_icon('key', 15) ?> Permissions</h3>
        <p class="sh-formcard__note" style="margin:-4px 0 12px">
          Tick what this role may do. Actions a role cannot do are refused by the server, whichever page they are requested from.
        </p>
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
      </div>

    </div>
    <div class="sh-formcard__foot sh-formcard__foot--sticky">
      <?php if (!$isSystem): ?>
        <button class="sh-btn" type="submit"><?= sh_icon('check-circle', 15) ?> <?= $isNew ? 'Create role' : 'Save changes' ?></button>
      <?php endif; ?>
      <a class="sh-btn sh-btn--ghost" href="<?= e($listUrl) ?>"><?= $isSystem ? 'Back to roles' : 'Cancel' ?></a>
      <?php if (!$isNew && !$isSystem): ?>
        <span class="sh-formcard__spacer"></span>
        <button class="sh-btn sh-btn--bad" type="submit" form="sh-role-delete"
                data-confirm="Delete the role &quot;<?= e((string)$editing['name']) ?>&quot;?"
                <?= $userCount > 0 ? 'disabled title="Reassign the admins using this role first"' : '' ?>>
          <?= sh_icon('trash', 15) ?> Delete role</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$isNew && !$isSystem): ?>
  <form method="post" action="<?= e(sh_url('admin/roles.php')) ?>" id="sh-role-delete">
    <?= sh_csrf_field() ?>
    <input type="hidden" name="form" value="delete">
    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
