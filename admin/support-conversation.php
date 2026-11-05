<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/admin-auth.php';
require_once SH_ROOT . '/includes/admin-perms.php';

sh_session_start();
sh_require_admin();
sh_perms_schema_ensure();
sh_require_perm('support.view', 'support.reply', 'support.manage');

// Keep old bookmarks/internal links working, but route all conversation access
// through the current inbox/API and its permission-checked controls.
$conversationId = max(0, sh_int($_GET['id'] ?? 0));
$target = 'admin/support.php' . ($conversationId > 0 ? '?id=' . $conversationId : '');
sh_redirect($target);
