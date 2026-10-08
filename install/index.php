<?php
/**
 * ShopHaat installer. Runs before any database exists, so it must be
 * completely self-contained and never fatal.
 */
declare(strict_types=1);

define('SH_ROOT_INSTALL', dirname(__DIR__));
require_once SH_ROOT_INSTALL . '/config/config.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/demo-data.php';

sh_session_start();

/*
 * Installer access rule
 * ---------------------
 * The installer is only reachable when the store genuinely needs setting up or
 * repairing. If the database is present and healthy the visitor is sent straight
 * back to the storefront -- including when they hand-type ?step=repair.
 */
$shState = sh_db_state();
if ($shState['status'] === 'ok' && sh_is_locked()) {
    sh_redirect('index.php');
}

$errors = [];
$notice = '';
$done = false;

$form = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'site_name' => 'ShopHaat', 'admin_email' => '', 'admin_password' => '',
    'admin_password2' => '', 'demo_data' => '1',
];
foreach ($form as $k => $v) {
    if (isset($_POST[$k]) && is_string($_POST[$k])) { $form[$k] = trim($_POST[$k]); }
}

// On a repair, pre-fill the saved connection so the operator mostly confirms what is there.
// The stored password is deliberately NOT pre-filled into the HTML.
$savedCfg = sh_db_config();
if ($savedCfg !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $form['db_host'] = (string)$savedCfg['host'];
    $form['db_name'] = (string)$savedCfg['name'];
    $form['db_user'] = (string)$savedCfg['user'];
}

// ---------------------------------------------------------------------------
// Server requirement checks
// ---------------------------------------------------------------------------
$requirements = [
    ['label' => 'PHP 8.1 or newer',     'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'value' => PHP_VERSION],
    ['label' => 'PDO extension',        'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'Enabled' : 'Missing'],
    ['label' => 'pdo_mysql driver',     'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing'],
    ['label' => 'mbstring extension',   'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? 'Enabled' : 'Missing'],
    ['label' => 'GD or Imagick',        'ok' => extension_loaded('gd') || extension_loaded('imagick'), 'value' => extension_loaded('gd') ? 'GD' : (extension_loaded('imagick') ? 'Imagick' : 'Missing')],
    ['label' => '/config writable',     'ok' => is_writable(SH_CONFIG_DIR), 'value' => is_writable(SH_CONFIG_DIR) ? 'Writable' : 'Not writable'],
    ['label' => '/uploads writable',    'ok' => is_writable(SH_UPLOAD_DIR), 'value' => is_writable(SH_UPLOAD_DIR) ? 'Writable' : 'Not writable'],
    ['label' => '/logs writable',       'ok' => is_writable(SH_LOG_DIR), 'value' => is_writable(SH_LOG_DIR) ? 'Writable' : 'Not writable'],
];
$reqOk = true;
foreach ($requirements as $r) { if (!$r['ok']) { $reqOk = false; } }

// ---------------------------------------------------------------------------
// Handle submission
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sh_csrf_valid()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif (!$reqOk) {
        $errors[] = 'Please resolve the server requirement issues listed above before installing.';
    } else {
        $v = new ShValidator($_POST);
        $v->required('db_host', 'Database host')
          ->required('db_name', 'Database name')
          ->required('db_user', 'Database username')
          ->required('site_name', 'Website name')->maxLen('site_name', 100, 'Website name')
          ->required('admin_email', 'Admin email')->email('admin_email', 'Admin email')
          ->required('admin_password', 'Admin password')->password('admin_password')
          ->matches('admin_password2', 'admin_password', 'Password confirmation');
        if ($v->fails()) { $errors = array_merge($errors, array_values($v->errors())); }

        $logoFile = null;
        if (!$errors && isset($_FILES['site_logo']) && ($_FILES['site_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = sh_upload_image($_FILES['site_logo'], 'logos', 0, 2000);
            if (!$up['ok']) { $errors[] = 'Website logo: ' . $up['error']; }
            else { $logoFile = $up['file']; }
        }

        // Step 2: connect
        $pdo = null;
        if (!$errors) {
            try {
                $pdo = sh_db_connect($form['db_host'], null, $form['db_user'], $form['db_pass']);
            } catch (PDOException $e) {
                sh_log_line('install', 'Connection failed: ' . $e->getMessage());
                $errors[] = 'Could not connect to the database server. Check the host, username and password.';
            }
        }
        // Step 3: detect engine + select/create database
        if (!$errors && $pdo) {
            try {
                $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
                $isMaria = stripos($version, 'mariadb') !== false;
                if (!$isMaria && version_compare(preg_replace('/[^0-9.].*$/', '', $version), '5.7.0', '<')) {
                    $errors[] = 'MySQL 5.7+ or MariaDB 10.2+ is required. Detected: ' . $version;
                }
                if (!$errors) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
                    $exists->execute([$form['db_name']]);
                    if ((int)$exists->fetchColumn() === 0) {
                        try {
                            $pdo->exec('CREATE DATABASE `' . str_replace('`', '', $form['db_name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        } catch (PDOException $e) {
                            $errors[] = 'The database "' . $form['db_name'] . '" does not exist and could not be created. Please create it in cPanel first.';
                        }
                    }
                }
                if (!$errors) {
                    $pdo = sh_db_connect($form['db_host'], $form['db_name'], $form['db_user'], $form['db_pass']);
                }
            } catch (PDOException $e) {
                sh_log_line('install', 'Database selection failed: ' . $e->getMessage());
                $errors[] = 'Connected to the server but could not open the database "' . e($form['db_name']) . '".';
            }
        }
        // Step 4-7: schema, admin, settings, verification
        if (!$errors && $pdo) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }

                // Verify every expected table now exists
                $missing = [];
                $chk = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
                foreach (sh_schema_tables() as $t) {
                    $chk->execute([$form['db_name'], $t]);
                    if ((int)$chk->fetchColumn() === 0) { $missing[] = $t; }
                }
                if ($missing) {
                    throw new RuntimeException('Tables not created: ' . implode(', ', $missing));
                }

                $siteUrl = rtrim(sh_site_url(), '/');
                sh_schema_seed($pdo, [
                    'site_name'   => $form['site_name'],
                    'site_url'    => $siteUrl,
                    'logo'        => $logoFile ?? '',
                    'admin_email' => $form['admin_email'],
                ]);

                // Admin account
                $adm = $pdo->prepare('INSERT INTO admins (name, email, password_hash, role, status)
                                      VALUES (?,?,?,?,\'active\')
                                      ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = \'active\'');
                $adm->execute([
                    'Store Administrator',
                    $form['admin_email'],
                    password_hash($form['admin_password'], PASSWORD_DEFAULT),
                    'superadmin',
                ]);

                if ($form['demo_data'] === '1') {
                    sh_install_demo_data($pdo);
                }

                // Write config
                $cfgContent = "<?php\n// Generated by the ShopHaat installer on " . date('Y-m-d H:i:s') . ".\n"
                    . "// Do not share this file. Keep it outside version control.\nreturn "
                    . var_export([
                        'host'    => $form['db_host'],
                        'name'    => $form['db_name'],
                        'user'    => $form['db_user'],
                        'pass'    => $form['db_pass'],
                        'port'    => 3306,
                        'charset' => 'utf8mb4',
                    ], true) . ";\n";
                if (file_put_contents(SH_DB_CONFIG_FILE, $cfgContent) === false) {
                    throw new RuntimeException('Unable to write config/database.php. Set the /config folder to 755 and retry.');
                }
                if (!chmod(SH_DB_CONFIG_FILE, 0640)) {
                    sh_log_line('install', 'Could not tighten permissions on the database config file.');
                }

                // Make sure every upload subdirectory exists and is protected.
                foreach (['products', 'logos', 'payments', 'promo', 'categories'] as $sub) {
                    $d = SH_UPLOAD_DIR . '/' . $sub;
                    if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) {
                        sh_log_line('install', 'Could not create upload directory: ' . $d);
                    }
                }
                $uploadGuard = SH_UPLOAD_DIR . '/.htaccess';
                if (!is_file($uploadGuard)) {
                    file_put_contents($uploadGuard,
                        "# Uploaded files must never be executed as code.\n"
                        . "<FilesMatch \"\\.(php|phtml|php[0-9]|pl|py|cgi|sh|htaccess)$\">\n"
                        . "  Require all denied\n</FilesMatch>\n"
                        . "Options -ExecCGI -Indexes\nRemoveHandler .php .phtml .phar\n"
                        // php_flag is mod_php-only; unguarded it 500s the whole
                        // folder on FastCGI/PHP-FPM hosts and hides every image.
                        . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
                        . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n");
                }

                // Lock
                file_put_contents(SH_LOCK_FILE, json_encode([
                    'installed_at' => date('c'),
                    'version'      => '1.0.0',
                    'php'          => PHP_VERSION,
                ], JSON_PRETTY_PRINT));

                $done = true;
                $notice = 'Installation completed successfully.';
            } catch (Throwable $e) {
                sh_log_exception($e, 'install');
                $errors[] = 'Installation failed: ' . $e->getMessage();
            }
        }
    }
}

$token = sh_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Install ShopHaat</title>
<style>
:root{--brand:#e8501b;--ink:#1f2430;--muted:#697084;--line:#e3e6ec;--bg:#f4f5f7;--ok:#1a7f4b;--bad:#c62828;color-scheme:light}
*,*::before,*::after{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:24px 14px}
.wrap{max-width:820px;margin:0 auto}
.head{text-align:center;margin-bottom:22px}
.head h1{margin:0 0 6px;font-size:24px}
.head p{margin:0;color:var(--muted);font-size:14px}
.mark{display:inline-flex;align-items:center;gap:9px;font-weight:800;font-size:20px;letter-spacing:-.3px;margin-bottom:10px}
.mark span{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:8px;background:var(--brand);color:#fff;font-size:16px}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:22px;margin-bottom:18px}
.card h2{margin:0 0 14px;font-size:15px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
@media(max-width:640px){.grid{grid-template-columns:1fr}}
label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
.f-in{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:6px;font-size:14px;font-family:inherit;background:#fff;color:var(--ink)}
.f-in:focus{outline:2px solid rgba(232,80,27,.25);border-color:var(--brand)}
.hint{font-size:12px;color:var(--muted);margin-top:4px}
.btn{background:var(--brand);color:#fff;border:0;padding:12px 26px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;width:100%}
.btn:hover{background:#cf4415}
.btn[disabled]{background:#b9bec9;cursor:not-allowed}
.req{list-style:none;margin:0;padding:0}
.req li{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px dashed var(--line);font-size:13.5px}
.req li:last-child{border-bottom:0}
.ok{color:var(--ok);font-weight:600}.bad{color:var(--bad);font-weight:600}
.alert{border-radius:7px;padding:12px 14px;font-size:13.5px;margin-bottom:16px;border:1px solid}
.alert--bad{background:#fdecea;border-color:#f5c2bd;color:#8c1d13}
.alert--ok{background:#e8f6ee;border-color:#bfe3cd;color:#14603a}
.alert ul{margin:6px 0 0;padding-left:18px}
.done{text-align:center}
.done h2{font-size:20px;color:var(--ok);text-transform:none;letter-spacing:0;margin-bottom:8px}
.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
.actions a{display:inline-block;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px}
.a1{background:var(--brand);color:#fff}.a2{background:#fff;border:1px solid var(--line);color:var(--ink)}
.check{display:flex;gap:9px;align-items:flex-start;font-size:13.5px}
.check input{margin-top:3px}
.pw-live{margin:6px 0 0;font-size:12.5px;font-weight:600}
.pw-live--ok{color:#1a7f4b}
.pw-live--bad{color:#c62828}
.hint code{background:#f1f2f5;padding:1px 5px;border-radius:4px;font-size:12px}
.steps{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;font-size:12px;color:var(--muted);justify-content:center}
.steps b{color:var(--ink)}
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="mark"><span>SH</span> ShopHaat</div>
    <h1><?= $done ? 'Installation complete' : 'Install your store' ?></h1>
    <p><?= $done ? 'Your marketplace is ready to use.' : 'Enter your cPanel MySQL details and create the admin account.' ?></p>
  </div>

<?php if ($done): ?>
  <div class="card done">
    <h2>Setup finished</h2>
    <p>The database tables were created and verified, the administrator account is active and the installation lock file has been written. The installer will not appear again.</p>
    <div class="alert alert--bad" style="text-align:left">
      <strong>Final security step:</strong> delete the <code>/install</code> folder from your hosting account now that setup is complete.
    </div>
    <div class="actions">
      <a class="a1" href="<?= e(sh_url('index.php')) ?>">Open the website</a>
      <a class="a2" href="<?= e(sh_url('admin/login.php')) ?>">Go to admin panel</a>
    </div>
  </div>
<?php else: ?>
  <div class="steps"><b>1.</b> Requirements <span>›</span> <b>2.</b> Database <span>›</span> <b>3.</b> Admin account <span>›</span> <b>4.</b> Finish</div>

  <?php if ($errors): ?>
    <div class="alert alert--bad"><strong>Installation could not continue:</strong>
      <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <div class="card">
    <h2>Server requirements</h2>
    <ul class="req">
      <?php foreach ($requirements as $r): ?>
        <li><span><?= e($r['label']) ?></span><span class="<?= $r['ok'] ? 'ok' : 'bad' ?>"><?= e($r['value']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
    <div class="card">
      <h2>Database connection</h2>
      <div class="grid">
        <div>
          <label for="db_host">Database host</label>
          <input class="f-in" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required>
          <p class="hint">Usually <code>localhost</code> on cPanel.</p>
        </div>
        <div>
          <label for="db_name">Database name</label>
          <input class="f-in" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required>
          <p class="hint">e.g. <code>cpuser_shop</code></p>
        </div>
        <div>
          <label for="db_user">Database username</label>
          <input class="f-in" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required>
        </div>
        <div>
          <label for="db_pass">Database password</label>
          <input class="f-in" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Website</h2>
      <div class="grid">
        <div>
          <label for="site_name">Website name</label>
          <input class="f-in" id="site_name" name="site_name" value="<?= e($form['site_name']) ?>" required>
        </div>
        <div>
          <label for="site_logo">Website logo (optional)</label>
          <input class="f-in" id="site_logo" name="site_logo" type="file" accept="image/*">
          <p class="hint">PNG/JPG/WEBP, max 1 MB.</p>
        </div>
      </div>
      <div style="margin-top:14px" class="check">
        <input type="checkbox" id="demo_data" name="demo_data" value="1" <?= $form['demo_data'] === '1' ? 'checked' : '' ?>>
        <label for="demo_data" style="font-weight:500;margin:0">Install starter catalogue (categories, brands, sample products and banners). Recommended for a first install — you can delete these items later from the admin panel.</label>
      </div>
    </div>

    <div class="card">
      <h2>Administrator account</h2>
      <div class="grid">
        <div>
          <label for="admin_email">Admin email</label>
          <input class="f-in" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>" required>
        </div>
        <div>
          <label for="admin_password">Admin password</label>
          <input class="f-in" id="admin_password" name="admin_password" type="password" required
                 minlength="8" autocomplete="new-password"
                 aria-describedby="pw-rules" placeholder="e.g. Shop2024pass">
          <p class="hint" id="pw-rules">
            <strong>Must be at least 8 characters and contain both a letter and a number.</strong><br>
            Good examples: <code>Shop2024pass</code> or <code>MyStore99</code><br>
            Symbols are allowed. Letters and digits from any language are accepted.
          </p>
          <p class="pw-live" id="pw-live" hidden></p>
        </div>
        <div>
          <label for="admin_password2">Confirm password</label>
          <input class="f-in" id="admin_password2" name="admin_password2" type="password" required>
        </div>
      </div>
    </div>

    <button class="btn" type="submit" <?= $reqOk ? '' : 'disabled' ?>>Install ShopHaat</button>
  </form>
<?php endif; ?>
</div>

<script>
(function () {
  var pw = document.getElementById('admin_password');
  var pw2 = document.getElementById('admin_password2');
  var live = document.getElementById('pw-live');
  if (!pw || !live) { return; }

  function evaluate() {
    var v = pw.value;
    if (v === '') { live.hidden = true; pw.setCustomValidity(''); return; }
    var longEnough = v.length >= 8;
    var hasLetter = /\p{L}/u.test(v);
    var hasNumber = /\p{N}/u.test(v);
    var missing = [];
    if (!longEnough) { missing.push('8 characters'); }
    if (!hasLetter) { missing.push('a letter'); }
    if (!hasNumber) { missing.push('a number'); }

    live.hidden = false;
    if (missing.length === 0) {
      live.textContent = 'Password meets the requirements.';
      live.className = 'pw-live pw-live--ok';
      pw.setCustomValidity('');
    } else {
      live.textContent = 'Still needs: ' + missing.join(', ') + '.';
      live.className = 'pw-live pw-live--bad';
      pw.setCustomValidity('Password needs ' + missing.join(', ') + '.');
    }
  }
  pw.addEventListener('input', evaluate);

  if (pw2) {
    function match() {
      pw2.setCustomValidity(pw2.value !== '' && pw2.value !== pw.value ? 'The two passwords do not match.' : '');
    }
    pw2.addEventListener('input', match);
    pw.addEventListener('input', match);
  }
})();
</script>
</body>
</html>
