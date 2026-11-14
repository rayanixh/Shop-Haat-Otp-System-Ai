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

// ---------------------------------------------------------------------------
// Installer access decision (see includes/install-guard.php for the rationale).
//
// A store that was installed before resolves to "repair" mode. In that mode the
// installer will only re-create missing tables over the SAVED connection: it
// ignores every database credential in the request, never modifies an existing
// administrator account, and never rewrites config/database.php. Full setup
// (or an administrator reset) additionally requires the owner's unlock token.
// ---------------------------------------------------------------------------
require_once SH_ROOT_INSTALL . '/includes/install-guard.php';

$shUnlockGiven = (string)($_POST['install_unlock'] ?? '');
$shUnlockOk = $shUnlockGiven !== '' && sh_install_unlock_matches($shUnlockGiven);
$shAccess = sh_install_access(
    sh_is_locked(),
    sh_db_config() !== null,
    $shUnlockOk,
    sh_install_unlock_configured()
);
// Unlock attempts are rate limited per IP so the token cannot be brute forced
// from the open internet. The limiter fails CLOSED here: if the counter store is
// unavailable (full disk, read-only logs/) we refuse the *wrong* token instead of
// letting an attacker remove the throttle by breaking the store first. A correct
// token never consults the limiter, so the owner can still get in and repair.
$shUnlockThrottled = !$shUnlockOk && $shUnlockGiven !== ''
    && !sh_file_rate_limit('install-unlock', sh_client_ip(), 5, 900, '', true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_log_line('install', 'Installer accessed: mode=' . $shAccess['mode']
        . ' state=' . (string)$shState['status']
        . ' unlock_supplied=' . ($shUnlockGiven !== '' ? 'yes' : 'no'));
}

$errors = [];
$notice = '';
$done = false;

$form = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'site_name' => 'ShopHaat', 'admin_email' => '', 'admin_password' => '',
    'admin_password2' => '', 'demo_data' => '0',
];
// Credentials are taken exactly as typed: trimming a password silently changes
// what the administrator chose, and a database password may legitimately begin
// or end with a space.
$verbatim = ['db_pass', 'admin_password', 'admin_password2'];
foreach ($form as $k => $v) {
    if (!isset($_POST[$k]) || !is_string($_POST[$k])) { continue; }
    $form[$k] = in_array($k, $verbatim, true) ? (string)$_POST[$k] : trim((string)$_POST[$k]);
}
// One radio control, normalised to exactly '0' or '1' so the seeding decision
// can never be flipped by an unexpected value (including a crafted duplicate
// field) and the rendered control always reflects what the backend will do.
$form['demo_data'] = ($form['demo_data'] === '1') ? '1' : '0';

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
// Required checks block the install. Optional extensions are reported with the
// behaviour used instead — the application ships fallbacks for every one of
// them (includes/compat.php), so a host without mbstring, DOM, cURL or GD still
// installs and runs, it just loses the related convenience feature.
// Every row carries the value this server actually reported. `need` and `fix`
// are presentation-only text; `ok` and `value` are the detection results and
// are exactly the same checks this installer has always used.
$requirements = [
    ['name' => 'PHP version', 'need' => '8.1 or newer',
     'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'value' => PHP_VERSION,
     'fix' => 'Switch this hosting account to PHP 8.1 or newer (cPanel → Select PHP Version), then reload this page.'],
    ['name' => 'PDO extension', 'need' => '',
     'ok' => extension_loaded('pdo'), 'value' => extension_loaded('pdo') ? 'Enabled' : 'Missing',
     'fix' => 'Enable the PDO extension for the selected PHP version in cPanel → Select PHP Version → Extensions.'],
    ['name' => 'pdo_mysql driver', 'need' => '',
     'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing',
     'fix' => 'Enable pdo_mysql. The store cannot reach MySQL or MariaDB without this driver.'],
    ['name' => 'JSON extension', 'need' => '',
     'ok' => extension_loaded('json'), 'value' => extension_loaded('json') ? 'Enabled' : 'Missing',
     'fix' => 'Enable the JSON extension for this PHP version.'],
    ['name' => '/config directory', 'need' => 'writable',
     'ok' => is_writable(SH_CONFIG_DIR), 'value' => is_writable(SH_CONFIG_DIR) ? 'Writable' : 'Not writable',
     'fix' => 'Set the config folder permission to 0755 in cPanel → File Manager → Permissions.'],
    ['name' => '/uploads directory', 'need' => 'writable',
     'ok' => is_writable(SH_UPLOAD_DIR), 'value' => is_writable(SH_UPLOAD_DIR) ? 'Writable' : 'Not writable',
     'fix' => 'Set the uploads folder permission to 0755 in cPanel → File Manager → Permissions.'],
    ['name' => '/logs directory', 'need' => 'writable',
     'ok' => is_writable(SH_LOG_DIR), 'value' => is_writable(SH_LOG_DIR) ? 'Writable' : 'Not writable',
     'fix' => 'Set the logs folder permission to 0755 in cPanel → File Manager → Permissions.'],
];
$reqTotal = count($requirements);
$reqPassed = 0;
$reqFixes = [];
foreach ($requirements as $r) {
    if ($r['ok']) { $reqPassed++; } else { $reqFixes[] = $r; }
}
$reqFailed = $reqTotal - $reqPassed;
$reqOk = $reqFailed === 0;

// Optional extensions: never block installation, always reported.
$optionalRequirements = [];
foreach (sh_extension_requirements() as $ext) {
    if ($ext['required']) { continue; }
    $optionalRequirements[] = [
        'name'     => $ext['label'],
        'purpose'  => $ext['purpose'],
        'ok'       => $ext['ok'],
        'fallback' => $ext['ok'] ? '' : $ext['fallback'],
    ];
}
$optTotal = count($optionalRequirements);
$optAvailable = 0;
foreach ($optionalRequirements as $o) { if ($o['ok']) { $optAvailable++; } }
$checkedAt = date('H:i');

// ---------------------------------------------------------------------------
// Handle submission
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sh_csrf_valid()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif (!$reqOk) {
        $errors[] = 'Please resolve the server requirement issues listed above before installing.';
    } elseif (($_POST['form'] ?? '') === 'unlock') {
        // A deliberate unlock attempt: give the owner explicit feedback instead
        // of silently running a repair.
        if ($shUnlockThrottled) {
            $errors[] = 'Too many unlock attempts from this address. Please wait 15 minutes and try again.';
        } elseif ($shUnlockOk) {
            $notice = 'Installer unlocked. This applies to the submission you send next, so keep the page open and '
                . 'complete the form below.';
        } else {
            $errors[] = 'That unlock text did not match the file on the server. '
                . 'Check the file content — the whole line is compared, including case.';
        }
    } elseif ($shAccess['mode'] === 'repair') {
        // ------------------------------------------------------------------
        // Repair only. Nothing from the request body is used to reach the
        // database, so an anonymous visitor cannot redirect this store at a
        // database of their choosing or add themselves as an administrator.
        // ------------------------------------------------------------------
        if ($shAccess['can_repair'] === false) {
            $errors[] = 'The installation lock file is present but config/database.php is missing, so there is '
                . 'no saved connection to repair. Restore config/database.php from your backup, or unlock the '
                . 'installer as the store owner to configure a new database.';
        } else {
            $saved = sh_db_config();
            try {
                // Step 1: reach the server with the SAVED credentials.
                $pdo = sh_db_connect($saved['host'], null, $saved['user'], $saved['pass'], (int)$saved['port'], $saved['charset']);
                // Step 2: the database named in the saved configuration may
                // itself be gone (dropped or renamed by the host). Recreate only
                // that name.
                $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
                $exists->execute([$saved['name']]);
                if ((int)$exists->fetchColumn() === 0) {
                    $pdo->exec('CREATE DATABASE `' . str_replace('`', '', (string)$saved['name'])
                        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                }
                $pdo = sh_db_connect($saved['host'], $saved['name'], $saved['user'], $saved['pass'], (int)$saved['port'], $saved['charset']);

                // Step 3: re-create anything missing. CREATE TABLE IF NOT EXISTS
                // never drops or alters a table that already holds data.
                foreach (sh_schema_sql() as $ddl) { $pdo->exec($ddl); }

                $missing = [];
                $chk = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
                foreach (sh_schema_tables() as $t) {
                    $chk->execute([$saved['name'], $t]);
                    if ((int)$chk->fetchColumn() === 0) { $missing[] = $t; }
                }
                if ($missing) {
                    throw new RuntimeException('Tables not created: ' . implode(', ', $missing));
                }

                // Step 4: existing administrators are never touched. Only a
                // store that has no administrator at all may create its first
                // one — that state can only be produced through real database
                // access, which an anonymous visitor does not have.
                $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
                if ($adminCount > 0) {
                    $done = true;
                    $notice = 'Database repaired against ' . $saved['name']
                        . '. The existing administrator account was left untouched — sign in with your usual credentials.';
                } else {
                    // A first administrator for an empty admins table. The
                    // submitted values are validated exactly as on a fresh
                    // install and inserted once, never as an upsert.
                    $v = new ShValidator($_POST);
                    $v->required('admin_email', 'Admin email')->email('admin_email', 'Admin email')
                      ->required('admin_password', 'Admin password')->password('admin_password', 'admin')
                      ->matchesRaw('admin_password2', 'admin_password', 'Password confirmation');
                    if ($v->fails()) {
                        $errors = array_merge($errors, array_values($v->errors()));
                        $errors[] = 'The database was repaired, but no administrator exists yet. '
                            . 'Enter the email address and password for the first administrator account.';
                    } else {
                        $ins = $pdo->prepare('INSERT INTO admins (name, email, password_hash, role, status) VALUES (?,?,?,?,?)');
                        $ins->execute([
                            'Store Administrator',
                            trim((string)$_POST['admin_email']),
                            password_hash((string)$_POST['admin_password'], PASSWORD_DEFAULT),
                            'superadmin',
                            'active',
                        ]);
                        sh_log_line('install', 'Repair created the first administrator account.');
                        $done = true;
                        $notice = 'Database repaired against ' . $saved['name'] . ' and the store administrator was created.';
                    }
                }

                if ($done) {
                    file_put_contents(SH_LOCK_FILE, json_encode([
                        'installed_at' => date('c'),
                        'version'      => '1.0.0',
                        'php'          => PHP_VERSION,
                        'repair'       => true,
                    ], JSON_PRETTY_PRINT));
                }
            } catch (Throwable $e) {
                sh_log_exception($e, 'install-repair');
                $errors[] = 'The repair could not reach the saved database. Your configuration was not changed. '
                    . 'Check that the MySQL server is running and that the database user in config/database.php '
                    . 'still exists.';
            }
        }
    } else {
        $v = new ShValidator($_POST);
        $v->required('db_host', 'Database host')
          ->required('db_name', 'Database name')
          ->required('db_user', 'Database username')
          ->required('site_name', 'Website name')->maxLen('site_name', 100, 'Website name')
          ->required('admin_email', 'Admin email')->email('admin_email', 'Admin email')
          ->required('admin_password', 'Admin password')->password('admin_password', 'admin')
          ->matchesRaw('admin_password2', 'admin_password', 'Password confirmation');
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

                // One shared call creates the administrator and applies the Demo
                // Data choice in a single transaction (see
                // sh_install_create_store()). It refuses to run when an
                // administrator already exists, so a repeated install can never
                // silently reset somebody's password. Recovery is the owner
                // unlock path, not a re-run of this form.
                //
                // $form['demo_data'] is normalised to exactly '0' or '1' during
                // validation, so a crafted "demo_data=0,1" or an empty value cannot
                // slip past this compare. The backend makes the decision from the
                // validated value, never from the state of a control in the browser.
                $ownerRecovery = $shAccess['mode'] === 'unlocked';
                $shSeedReport = sh_install_create_store($pdo, $form, $logoFile ?? '', $siteUrl, $ownerRecovery);
                sh_log_line('install', ($ownerRecovery ? 'Owner-authorized install/recovery: ' : 'Seeding: ')
                    . json_encode($shSeedReport));

                // Write config
                $cfgContent = "<?php
// Note: the setup wizard deliberately does NOT render the Page Transition. It
// runs before the store has any settings to read (the transition is stored in
// the settings table) and it must stay usable with no JavaScript at all.
?>
<?php\n// Generated by the ShopHaat installer on " . date('Y-m-d H:i:s') . ".\n"
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
                $notice = !empty($shSeedReport['recovered'])
                    ? 'The existing administrator password was reset through the owner unlock.'
                    : 'Installation completed successfully.';
            } catch (Throwable $e) {
                sh_log_exception($e, 'install');
                // The exception text can carry the database host, user or a
                // filesystem path, so it is written to the log only -- never
                // rendered to the browser.
                $errors[] = 'The installation could not be completed. The technical details were written to the '
                    . 'installation log; nothing was left half-configured. Check the database details and try again.';
            }
        }
    }
}

$token = sh_csrf_token();

// ---------------------------------------------------------------------------
// Presentation state
// ---------------------------------------------------------------------------
// The wizard header is derived from results the backend already produced, never
// from a timer, a cookie or a stored flag. A step is only shown as complete
// when the check behind it genuinely passed, so the progress bar cannot
// overstate how far the installation has got.
//
// Steps 2 (database) and 3 (store) are collected by the single form below and
// validated together by the server; a repair deliberately skips step 3.
$stepLabels = ['Server requirements', 'Database configuration', 'Store configuration', 'Installation complete'];
if ($done) {
    $stepStates = ['complete', 'complete', 'complete', 'complete'];
} elseif ($shAccess['mode'] === 'repair') {
    $stepStates = [$reqOk ? 'complete' : 'current', $reqOk ? 'current' : 'pending', 'skipped', 'pending'];
} elseif (!$reqOk) {
    $stepStates = ['current', 'pending', 'pending', 'pending'];
} else {
    $stepStates = ['complete', 'current', 'pending', 'pending'];
}
$stepsComplete = 0;
foreach ($stepStates as $state) {
    if ($state === 'complete') { $stepsComplete++; }
}
$currentStep = 1;
foreach ($stepStates as $i => $state) {
    if ($state === 'current') { $currentStep = $i + 1; break; }
}
if ($done) { $currentStep = 4; }
$progressPct = (int)round($stepsComplete / count($stepLabels) * 100);

$stepHint = [
    'complete' => 'completed',
    'current'  => 'current step',
    'pending'  => 'not started',
    'skipped'  => 'not part of a repair',
];

if ($done) {
    $modeLabel = 'Installed';
    $modeIcon  = 'check';
} elseif ($shAccess['mode'] === 'repair') {
    $modeLabel = 'Repair mode';
    $modeIcon  = 'shield';
} elseif ($shAccess['mode'] === 'unlocked') {
    $modeLabel = 'Owner unlock';
    $modeIcon  = 'lock';
} else {
    $modeLabel = 'New installation';
    $modeIcon  = 'lock';
}

if ($done) {
    $pageTitle = 'Installation complete';
    $pageLead  = 'Your marketplace is ready to use.';
} elseif ($shAccess['mode'] === 'repair') {
    $pageTitle = 'Repair this store';
    $pageLead  = 'Re-create missing database tables over the saved connection. Existing tables, orders and '
        . 'administrator accounts are left exactly as they are.';
} else {
    $pageTitle = 'Install your store';
    $pageLead  = 'Welcome to ShopHaat. This one-time setup checks your hosting account, connects your MySQL '
        . 'database and creates the administrator account. It takes about two minutes.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="light">
<title><?= e($pageTitle) ?> &middot; ShopHaat</title>
<style>
/* ==========================================================================
   ShopHaat installer — self-contained stylesheet
   The installer runs before any database exists, so it cannot depend on
   assets/css/app.css (which the storefront theme rewrites through PHP).
   The tokens below mirror the storefront palette in app.css.
   1. Tokens        5. Panels / rows      9. Action bar
   2. Base          6. Summary / notices 10. Done state
   3. Masthead      7. Form fields       11. Responsive
   4. Steps         8. Buttons
   ========================================================================== */

/* 1. TOKENS --------------------------------------------------------------- */
:root {
  --brand: #e8501b;
  --brand-dark: #cc4415;
  --brand-soft: #fff1ec;
  --accent-soft: #eef3fa;
  --ink: #1f2430;
  --ink-2: #414958;
  --muted: #6b7385;
  --line: #e4e7ec;
  --line-2: #eef0f4;
  --bg: #f2f3f6;
  --surface: #ffffff;
  --surface-2: #fafbfc;
  --ok: #17804a;
  --ok-soft: #e7f5ee;
  --ok-line: #bfe3cd;
  --warn: #a35a00;
  --warn-soft: #fdf3e3;
  --warn-line: #f0d9b3;
  --bad: #c62828;
  --bad-soft: #fdecea;
  --bad-line: #f5c2bd;
  --radius: 10px;
  --radius-sm: 8px;
  --shadow: 0 1px 2px rgba(24, 29, 45, .06);
  color-scheme: light;
}

/* 2. BASE ----------------------------------------------------------------- */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--ink);
  font: 15px/1.55 "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
}
h1, h2, h3, p, ul, ol, dl, dd, figure { margin: 0; }
ul, ol { padding: 0; }
svg { display: block; }
button, input { font: inherit; color: inherit; }
.shell { width: 100%; max-width: 900px; margin: 0 auto; padding: 0 16px; }
.sprite { position: absolute; width: 0; height: 0; }
.sr-only {
  position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0;
  clip-path: inset(50%); white-space: nowrap; border: 0;
}
.ico { width: 15px; height: 15px; flex: none; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.ico--sm { width: 13px; height: 13px; }

/* 3. MASTHEAD ------------------------------------------------------------- */
.masthead { background: var(--surface); border-bottom: 1px solid var(--line); }
.masthead__inner { padding: 22px 16px 20px; }
.masthead__top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.brand { display: inline-flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 800; letter-spacing: -.2px; }
.brand__mark {
  display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 9px;
  background: var(--brand); color: #fff; font-size: 12.5px; font-weight: 800; letter-spacing: .3px;
}
.chip {
  display: inline-flex; flex: none; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px;
  border: 1px solid var(--line); background: var(--surface-2); color: var(--ink-2);
  font-size: 11.5px; font-weight: 650; white-space: nowrap;
}
.chip--mode { color: var(--ink); }
.masthead__title { margin-top: 16px; font-size: 23px; line-height: 1.25; letter-spacing: -.4px; }
.masthead__lead { margin-top: 6px; max-width: 64ch; color: var(--muted); font-size: 14px; }

/* 4. STEPS + PROGRESS ----------------------------------------------------- */
.steps { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 20px; list-style: none; }
.step { display: flex; align-items: flex-start; gap: 8px; min-width: 0; }
.step__num {
  flex: none; display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%;
  border: 1.5px solid var(--line); background: var(--surface); color: var(--muted);
  font-size: 11.5px; font-weight: 700; font-variant-numeric: tabular-nums;
}
.step__label { min-width: 0; font-size: 12.5px; line-height: 1.35; color: var(--muted); }
.step--complete .step__num { border-color: var(--ok); background: var(--ok); color: #fff; }
.step--complete .step__label { color: var(--ink-2); }
.step--current .step__num { border-color: var(--brand); color: var(--brand); }
.step--current .step__label { color: var(--ink); font-weight: 650; }
.step--skipped .step__label { color: #8d95a5; text-decoration: line-through; }
.progress { position: relative; height: 6px; margin-top: 14px; border-radius: 99px; background: var(--line-2); }
.progress__fill { height: 100%; border-radius: 99px; background: var(--brand); transition: width .25s ease; }
.progress__meta { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 8px; font-size: 12px; color: var(--muted); }

/* 5. PANELS / REQUIREMENT ROWS -------------------------------------------- */
.main { padding: 22px 0 34px; }
.panel {
  background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 18px; margin-bottom: 16px;
}
.panel__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.panel__title { font-size: 16px; letter-spacing: -.2px; }
.panel__sub { margin-top: 3px; max-width: 62ch; font-size: 12.5px; color: var(--muted); overflow-wrap: anywhere; }
.verdict {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px;
  border: 1px solid; font-size: 12px; font-weight: 650; white-space: nowrap;
}
.verdict--ok { background: var(--ok-soft); border-color: var(--ok-line); color: #14603a; }
.verdict--bad { background: var(--bad-soft); border-color: var(--bad-line); color: #8c1d13; }

.req-list { margin-top: 14px; list-style: none; border-top: 1px solid var(--line-2); }
.req {
  display: grid; grid-template-columns: minmax(0, 1fr) auto auto; align-items: center;
  gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--line-2); font-size: 13.5px;
}
.req:last-child { border-bottom: 0; }
.req__name { min-width: 0; font-weight: 600; overflow-wrap: anywhere; }
.req__need { margin-left: 8px; font-size: 11.5px; font-weight: 500; color: var(--muted); }
.req__value { font-size: 12.5px; color: var(--ink-2); text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.badge {
  display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 6px;
  border: 1px solid; font-size: 11.5px; font-weight: 700; letter-spacing: .2px; white-space: nowrap;
}
.badge--ok { background: var(--ok-soft); border-color: var(--ok-line); color: #14603a; }
.badge--bad { background: var(--bad-soft); border-color: var(--bad-line); color: #8c1d13; }

/* Optional extensions: two columns on desktop, one on mobile. */
.opt-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 14px; list-style: none; }
.opt { min-width: 0; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface-2); }
.opt__top { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.opt__name { flex: 1 1 110px; min-width: 0; font-size: 13px; font-weight: 650; overflow-wrap: anywhere; }
.opt__purpose { margin-top: 2px; font-size: 12.5px; color: var(--muted); }
.opt__fallback { margin-top: 6px; font-size: 12px; color: var(--warn); overflow-wrap: anywhere; }
.chip--ok { background: var(--surface-2); border-color: var(--line); color: var(--muted); }
.chip--warn { background: var(--warn-soft); border-color: var(--warn-line); color: var(--warn); }

/* 6. SUMMARY / NOTICES ---------------------------------------------------- */
.summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 16px; }
.metric { display: flex; flex-direction: column-reverse; min-width: 0; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface-2); }
.metric__value { display: block; font-size: 17px; font-weight: 750; letter-spacing: -.3px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.metric__label { display: block; margin-bottom: 2px; font-size: 11.5px; color: var(--muted); }
.metric--ok .metric__value { color: var(--ok); }
.metric--bad { border-color: var(--bad-line); background: #fff8f7; }
.metric--bad .metric__value { color: var(--bad); }

.callout { margin-bottom: 16px; padding: 12px 14px; border: 1px solid; border-radius: var(--radius-sm); font-size: 13px; }
.callout:last-child { margin-bottom: 0; }
.callout--bad { background: #fff8f7; border-color: var(--bad-line); color: #7d1c12; }
.callout--ok { background: var(--ok-soft); border-color: var(--ok-line); color: #14603a; }
.callout--info { background: var(--accent-soft); border-color: #cfdcee; color: #1b3f66; }
.callout__title { font-size: 13px; font-weight: 700; }
.callout__list { margin-top: 6px; padding-left: 18px; }
.callout__list li { margin-bottom: 4px; overflow-wrap: anywhere; }
.callout__list li:last-child { margin-bottom: 0; }
.callout__note { margin-top: 8px; font-size: 12.5px; }
.callout code { padding: 1px 5px; border-radius: 4px; background: rgba(31, 36, 48, .06); font-size: 12px; }

/* 7. FORM FIELDS ---------------------------------------------------------- */
.section + .section { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--line-2); }
.section__head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
.section__num {
  flex: none; display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%;
  border: 1px solid #f7cfc0; background: var(--brand-soft); color: var(--brand);
  font-size: 11.5px; font-weight: 800; font-variant-numeric: tabular-nums;
}
.section__title { font-size: 14.5px; letter-spacing: -.1px; }
.section__sub { margin-top: 2px; font-size: 12.5px; color: var(--muted); }
.field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
.field { min-width: 0; }
.field > label, .field__label { display: block; margin-bottom: 5px; font-size: 12.5px; font-weight: 650; }
.input {
  width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid var(--line); border-radius: var(--radius-sm);
  background: var(--surface); color: var(--ink); font-size: 14px;
}
.input:hover { border-color: #cfd5df; }
.input:focus { border-color: var(--brand); outline: 2px solid rgba(232, 80, 27, .16); outline-offset: 0; }
.input[type="file"] { padding: 7px 9px; font-size: 13px; }
.hint { margin-top: 5px; font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
.hint code { padding: 1px 5px; border-radius: 4px; background: var(--line-2); font-size: 11.5px; }
.radio {
  display: flex; align-items: flex-start; gap: 9px; padding: 10px 11px; border: 1px solid var(--line);
  border-radius: var(--radius-sm); background: var(--surface); cursor: pointer; font-size: 13.5px;
}
.radio + .radio { margin-top: 8px; }
.radio:hover { border-color: #cfd5df; }
.radio input { flex: none; width: 16px; height: 16px; margin: 2px 0 0; }
.radio span { min-width: 0; }
.radio__sub { display: block; margin-top: 1px; font-size: 12px; color: var(--muted); }
.pw-live { margin-top: 5px; font-size: 12.5px; font-weight: 650; }
.pw-live--ok { color: var(--ok); }
.pw-live--bad { color: var(--bad); }

/* utility spacing (all layout, no values baked into the markup) */
.mt-12 { margin-top: 12px; }
.mt-14 { margin-top: 14px; }
.mt-16 { margin-top: 16px; }
.ico--lg { width: 22px; height: 22px; }
.fieldset { margin: 16px 0 0; padding: 0; border: 0; }
.unlock-steps { margin-top: 12px; padding-left: 18px; font-size: 13px; }
.unlock-steps li { margin-bottom: 6px; }
.subblock { margin-top: 18px; padding-top: 16px; border-top: 1px dashed var(--line); }
.subblock__title { font-size: 13.5px; letter-spacing: -.1px; }

/* 8. BUTTONS -------------------------------------------------------------- */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  min-height: 44px; padding: 0 20px; border: 1px solid transparent; border-radius: var(--radius-sm);
  font-family: inherit; font-size: 14.5px; font-weight: 650; text-decoration: none; cursor: pointer;
}
.btn--primary { background: var(--brand); border-color: var(--brand); color: #fff; }
.btn--primary:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
.btn--primary[disabled] { background: #c3c8d2; border-color: #c3c8d2; color: #fff; cursor: not-allowed; }
.btn--ghost { background: var(--surface); border-color: var(--line); color: var(--ink-2); }
.btn--ghost:hover { border-color: #c9ced8; background: var(--surface-2); }
.btn:focus-visible, .input:focus-visible, .radio input:focus-visible, a:focus-visible {
  outline: 2px solid var(--brand); outline-offset: 2px;
}

/* 9. ACTION BAR ----------------------------------------------------------- */
.actionbar {
  display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  padding: 16px 18px; border: 1px solid var(--line); border-radius: var(--radius);
  background: var(--surface); box-shadow: var(--shadow);
}
.actionbar__info { flex: 1 1 320px; min-width: 0; }
.actionbar__title { font-size: 13.5px; font-weight: 700; }
.actionbar__text { margin-top: 3px; max-width: 62ch; font-size: 12.5px; color: var(--muted); }
.actionbar__buttons { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.actionbar__buttons form { display: block; margin: 0; }
.foot { padding: 18px 16px 30px; text-align: center; font-size: 12px; color: var(--muted); }

/* 10. DONE STATE ---------------------------------------------------------- */
.panel--done { padding: 26px 18px; text-align: center; }
.done__badge {
  display: inline-grid; place-items: center; width: 44px; height: 44px; margin-bottom: 10px;
  border: 1px solid var(--ok-line); border-radius: 50%; background: var(--ok-soft); color: #14603a;
}
.done__title { font-size: 18px; letter-spacing: -.2px; }
.done__text { max-width: 54ch; margin: 6px auto 0; font-size: 13.5px; color: var(--muted); }
.done__meta { margin-top: 4px; font-size: 12px; color: var(--muted); }
.done__notice { max-width: 560px; margin: 16px auto 0; text-align: left; }
.actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

/* 11. RESPONSIVE ---------------------------------------------------------- */
@media (max-width: 760px) {
  .steps { grid-template-columns: repeat(2, minmax(0, 1fr)); row-gap: 10px; }
  .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .field-grid { grid-template-columns: 1fr; }
  .opt-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
  .req { grid-template-columns: minmax(0, 1fr) auto; row-gap: 2px; column-gap: 10px; }
  .req__name { grid-column: 1; grid-row: 1; }
  .req__badge { grid-column: 2; grid-row: 1; justify-self: end; }
  .req__value { grid-column: 1 / -1; grid-row: 2; text-align: left; }
  .actionbar { padding: 14px; }
  .actionbar__buttons { flex: 1 1 100%; flex-direction: column; align-items: stretch; }
  .actionbar__buttons .btn { width: 100%; }
  .done__notice { text-align: left; }
}
@media (max-width: 430px) {
  .shell { padding: 0 12px; }
  .masthead__inner { padding: 18px 12px 16px; }
  .masthead__title { font-size: 20px; }
  .steps { grid-template-columns: 1fr; row-gap: 6px; }
  .panel { padding: 14px; }
  .panel--done { padding: 22px 14px; }
}
@media (prefers-reduced-motion: reduce) {
  .progress__fill { transition: none; }
}
</style>
</head>
<body>
<svg class="sprite" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
  <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
</svg>

<header class="masthead">
  <div class="shell masthead__inner">
    <div class="masthead__top">
      <span class="brand"><span class="brand__mark" aria-hidden="true">SH</span>ShopHaat</span>
      <span class="chip chip--mode">
        <svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-<?= e($modeIcon) ?>"/></svg>
        <?= e($modeLabel) ?>
      </span>
    </div>
    <h1 class="masthead__title"><?= e($pageTitle) ?></h1>
    <p class="masthead__lead"><?= e($pageLead) ?></p>

    <ol class="steps" aria-label="Installation steps">
      <?php foreach ($stepLabels as $i => $label): $st = $stepStates[$i]; ?>
        <li class="step step--<?= e($st) ?>"<?= $st === 'current' ? ' aria-current="step"' : '' ?>>
          <span class="step__num" aria-hidden="true"><?= $i + 1 ?></span>
          <span class="step__label"><?= e($label) ?><span class="sr-only"> (<?= e($stepHint[$st]) ?>)</span></span>
        </li>
      <?php endforeach; ?>
    </ol>

    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
         aria-valuenow="<?= (int)$progressPct ?>" aria-label="Installation progress">
      <div class="progress__fill" style="width:<?= (int)$progressPct ?>%"></div>
    </div>
    <p class="progress__meta">
      <span><?= $done ? 'All four steps are complete.' : 'Step ' . (int)$currentStep . ' of 4 &middot; ' . e($stepLabels[$currentStep - 1]) ?></span>
      <span><?= (int)$stepsComplete ?> of 4 steps complete</span>
    </p>
  </div>
</header>

<main class="shell main">
<?php if ($notice !== ''): ?>
  <div class="callout callout--ok" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <section class="panel panel--done" aria-labelledby="done-title">
    <span class="done__badge" aria-hidden="true"><svg class="ico ico--lg"><use href="#i-check"/></svg></span>
    <h2 class="done__title" id="done-title">Setup finished</h2>
    <p class="done__text">The installer is now locked and will not run again. You can sign in to the admin panel and start adding products.</p>
    <p class="done__meta"><?= (int)$reqPassed ?> of <?= (int)$reqTotal ?> required server checks passed</p>

    <div class="callout callout--bad done__notice">
      <p class="callout__title">Delete the <code>/install</code> folder from your hosting account now</p>
      <p class="callout__note">The installation lock already blocks it, but removing the folder is the safest end state for a live store.</p>
    </div>

    <div class="actions">
      <a class="btn btn--primary" href="<?= e(sh_url('index.php')) ?>">Open the website</a>
      <a class="btn btn--ghost" href="<?= e(sh_url('admin/login.php')) ?>">Go to admin panel</a>
    </div>
  </section>
<?php else: ?>

  <?php if ($errors): ?>
    <div class="callout callout--bad" role="alert">
      <p class="callout__title">Installation could not continue</p>
      <ul class="callout__list">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($shAccess['mode'] === 'unlocked'): ?>
    <div class="callout callout--ok" role="status">
      <p class="callout__title">Owner unlock accepted</p>
      <p class="callout__note">Changes made here affect an installed store. The unlock text is required again for the
      next submission, so finish in this same page.</p>
    </div>
  <?php endif; ?>

  <section class="panel" id="requirements" aria-labelledby="requirements-title">
    <div class="panel__head">
      <div>
        <h2 class="panel__title" id="requirements-title">Server requirements</h2>
        <p class="panel__sub">Checked on this server at <?= e($checkedAt) ?> (server time). Every required item
        must pass before the installer will write anything.</p>
      </div>
      <span class="verdict verdict--<?= $reqOk ? 'ok' : 'bad' ?>">
        <svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-<?= $reqOk ? 'check' : 'alert' ?>"/></svg>
        <?= $reqOk ? 'Ready to install' : 'Action required' ?>
      </span>
    </div>

    <ul class="req-list">
      <?php foreach ($requirements as $r): ?>
        <li class="req">
          <span class="req__name"><?= e($r['name']) ?><?php if ($r['need'] !== ''): ?><span class="req__need"><?= e($r['need']) ?></span><?php endif; ?></span>
          <span class="req__value"><?= e($r['value']) ?></span>
          <span class="badge req__badge badge--<?= $r['ok'] ? 'ok' : 'bad' ?>">
            <svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-<?= $r['ok'] ? 'check' : 'x' ?>"/></svg>
            <?= $r['ok'] ? 'Passed' : 'Failed' ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>

    <dl class="summary">
      <div class="metric">
        <dt class="metric__label">Required passed</dt>
        <dd class="metric__value"><?= (int)$reqPassed ?>/<?= (int)$reqTotal ?></dd>
      </div>
      <div class="metric<?= $reqFailed > 0 ? ' metric--bad' : '' ?>">
        <dt class="metric__label">Required failed</dt>
        <dd class="metric__value"><?= (int)$reqFailed ?></dd>
      </div>
      <div class="metric">
        <dt class="metric__label">Optional available</dt>
        <dd class="metric__value"><?= (int)$optAvailable ?>/<?= (int)$optTotal ?></dd>
      </div>
      <div class="metric metric--<?= $reqOk ? 'ok' : 'bad' ?>">
        <dt class="metric__label">Overall readiness</dt>
        <dd class="metric__value"><?= $reqOk ? 'Ready' : 'Blocked' ?></dd>
      </div>
    </dl>

    <?php if ($reqFixes): ?>
      <div class="callout callout--bad mt-16">
        <p class="callout__title"><?= count($reqFixes) === 1 ? 'One required check must be fixed first' : (count($reqFixes) . ' required checks must be fixed first') ?></p>
        <ul class="callout__list">
          <?php foreach ($reqFixes as $r): ?>
            <li><strong><?= e($r['name']) ?></strong> — <?= e($r['fix']) ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="callout__note">After fixing these in cPanel, use <strong>Refresh requirements</strong> at the bottom of this page.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="panel" aria-labelledby="optional-title">
    <div class="panel__head">
      <div>
        <h2 class="panel__title" id="optional-title">Optional extensions</h2>
        <p class="panel__sub">Recommended, not required — ShopHaat ships a fallback for each one, so nothing
        here can block the installation.</p>
      </div>
      <span class="chip">Optional</span>
    </div>

    <ul class="opt-grid">
      <?php foreach ($optionalRequirements as $o): ?>
        <li class="opt">
          <div class="opt__top">
            <span class="opt__name"><?= e($o['name']) ?></span>
            <?php if ($o['ok']): ?>
              <span class="chip chip--ok">
                <svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-check"/></svg>Available
              </span>
            <?php else: ?>
              <span class="chip chip--warn">
                <svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-alert"/></svg>Fallback
              </span>
            <?php endif; ?>
          </div>
          <p class="opt__purpose"><?= e($o['purpose']) ?></p>
          <?php if ($o['fallback'] !== ''): ?>
            <p class="opt__fallback">Uses: <?= e($o['fallback']) ?></p>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($shAccess['mode'] === 'repair'): ?>

    <section class="panel" aria-labelledby="repair-title">
      <div class="panel__head">
        <div>
          <h2 class="panel__title" id="repair-title">Restricted: this store is already installed</h2>
          <p class="panel__sub">Repair re-creates missing tables over the saved connection in
          <code>config/database.php</code>. Existing tables, orders and administrator accounts are left as they
          are, and no database credentials are accepted here.</p>
        </div>
        <span class="chip"><svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-shield"/></svg>Repair only</span>
      </div>

      <?php if ($shAccess['can_repair']): ?>
        <form method="post" novalidate id="repair-form" class="mt-16">
          <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
          <input type="hidden" name="form" value="repair">
          <div class="field-grid">
            <div class="field">
              <label for="admin_email">First administrator email</label>
              <input class="input" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>">
              <p class="hint">Only used when the store has no administrator at all.</p>
            </div>
            <div class="field">
              <label for="admin_password">Password for that account</label>
              <input class="input" id="admin_password" name="admin_password" type="password"
                     minlength="<?= (int)sh_password_min_length('admin') ?>" maxlength="200"
                     autocomplete="new-password">
            </div>
            <div class="field">
              <label for="admin_password2">Confirm password</label>
              <input class="input" id="admin_password2" name="admin_password2" type="password" autocomplete="new-password">
              <p class="hint">At least <?= (int)sh_password_min_length('admin') ?> characters.</p>
            </div>
          </div>
        </form>
      <?php else: ?>
        <div class="callout callout--bad mt-16">
          <p class="callout__title">config/database.php is missing</p>
          <p class="callout__note">There is no saved connection to repair. Restore that file from your backup and
          reload this page, or unlock the installer as the store owner below.</p>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel" aria-labelledby="unlock-title">
      <div class="panel__head">
        <div>
          <h2 class="panel__title" id="unlock-title">Store owner: unlock full setup</h2>
          <p class="panel__sub">Re-installing or resetting the administrator password requires proof that you
          control the server files.</p>
        </div>
        <span class="chip"><svg class="ico ico--sm" aria-hidden="true" focusable="false"><use href="#i-lock"/></svg>Owner only</span>
      </div>

      <ol class="unlock-steps">
        <?php foreach (sh_install_unlock_instructions() as $line): ?><li><?= $line ?></li><?php endforeach; ?>
      </ol>

      <?php if (!$shAccess['unlock_configured']): ?>
        <div class="callout callout--bad" style="margin-top:12px">
          <p class="callout__title">No unlock file was found</p>
          <p class="callout__note">Create it as described above, then reload this page.</p>
        </div>
      <?php endif; ?>

      <form method="post" novalidate id="unlock-form" class="mt-14">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
        <input type="hidden" name="form" value="unlock">
        <div class="field">
          <label for="install_unlock">Unlock text (contents of the file)</label>
          <input class="input" id="install_unlock" name="install_unlock" autocomplete="off" spellcheck="false"
                 placeholder="Paste the exact line you saved">
        </div>
        <div class="mt-12">
          <button class="btn btn--ghost" type="submit">Unlock the installer</button>
        </div>
      </form>
    </section>

  <?php else: ?>

    <form method="post" enctype="multipart/form-data" novalidate id="install-form">
      <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
      <?php if ($shAccess['mode'] === 'unlocked'): ?>
        <input type="hidden" name="install_unlock" value="<?= e($shUnlockGiven) ?>">
      <?php endif; ?>

      <div class="panel">
        <div class="section">
          <div class="section__head">
            <span class="section__num" aria-hidden="true">2</span>
            <div>
              <h2 class="section__title">Database configuration</h2>
              <p class="section__sub">The MySQL or MariaDB database ShopHaat will use. Create it in cPanel first
              if your host does not create it for you.</p>
            </div>
          </div>
          <div class="field-grid">
            <div class="field">
              <label for="db_host">Database host</label>
              <input class="input" id="db_host" name="db_host" value="<?= e($form['db_host']) ?>" required>
              <p class="hint">Usually <code>localhost</code>.</p>
            </div>
            <div class="field">
              <label for="db_name">Database name</label>
              <input class="input" id="db_name" name="db_name" value="<?= e($form['db_name']) ?>" required>
            </div>
            <div class="field">
              <label for="db_user">Database username</label>
              <input class="input" id="db_user" name="db_user" value="<?= e($form['db_user']) ?>" required>
            </div>
            <div class="field">
              <label for="db_pass">Database password</label>
              <input class="input" id="db_pass" name="db_pass" type="password" autocomplete="new-password">
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section__head">
            <span class="section__num" aria-hidden="true">3</span>
            <div>
              <h2 class="section__title">Store configuration</h2>
              <p class="section__sub">Your website name, logo and whether the store starts with sample content.</p>
            </div>
          </div>
          <div class="field-grid">
            <div class="field">
              <label for="site_name">Website name</label>
              <input class="input" id="site_name" name="site_name" value="<?= e($form['site_name']) ?>" required>
            </div>
            <div class="field">
              <label for="site_logo">Website logo (optional)</label>
              <input class="input" id="site_logo" name="site_logo" type="file" accept="image/*">
              <p class="hint">PNG, JPG or WEBP, up to 1 MB.</p>
            </div>
          </div>

          <fieldset class="fieldset">
            <legend class="field__label">Install demo data</legend>
            <label class="radio">
              <input type="radio" id="demo_on" name="demo_data" value="1" <?= $form['demo_data'] === '1' ? 'checked' : '' ?>>
              <span>On<span class="radio__sub">Adds sample products, categories and homepage content.</span></span>
            </label>
            <label class="radio">
              <input type="radio" id="demo_off" name="demo_data" value="0" <?= $form['demo_data'] === '1' ? '' : 'checked' ?>>
              <span>Off<span class="radio__sub">Start with an empty store.</span></span>
            </label>
          </fieldset>
        </div>

        <div class="subblock">
          <h3 class="subblock__title">Administrator account</h3>
          <p class="section__sub">The first account that can sign in to <code>admin/</code> and manage the store.</p>
          <div class="field-grid mt-12">
            <div class="field">
              <label for="admin_email">Admin email</label>
              <input class="input" id="admin_email" name="admin_email" type="email" value="<?= e($form['admin_email']) ?>" required>
            </div>
            <div class="field">
              <label for="admin_password">Admin password</label>
              <input class="input" id="admin_password" name="admin_password" type="password" required
                     minlength="<?= (int)sh_password_min_length('admin') ?>" maxlength="200"
                     autocomplete="new-password" aria-describedby="pw-rules">
              <p class="hint" id="pw-rules">At least <?= (int)sh_password_min_length('admin') ?> characters. Any characters you like.</p>
              <p class="pw-live" id="pw-live" hidden></p>
            </div>
            <div class="field">
              <label for="admin_password2">Confirm password</label>
              <input class="input" id="admin_password2" name="admin_password2" type="password" required>
            </div>
          </div>
        </div>
      </div>
    </form>

  <?php endif; // end repair / install branch ?>

  <?php
  // One action bar for every mode. The primary button is bound to the real form
  // above with form="…", so it submits exactly what the backend already
  // validates — there is no second code path and no browser-side decision
  // about whether the install may proceed.
  if ($shAccess['mode'] === 'repair') {
      $actionForm  = 'repair-form';
      $actionLabel = 'Repair database tables';
      $actionTitle = $shAccess['can_repair'] ? 'Repair runs over the saved connection' : 'Repair is not available';
      $actionText  = $shAccess['can_repair']
          ? 'Only missing tables are created. No administrator is modified and no credential from this page is used.'
          : 'config/database.php is missing, so there is no saved connection to repair. Restore it and reload this page.';
      $actionEnabled = $reqOk && $shAccess['can_repair'];
  } else {
      $actionForm  = 'install-form';
      $actionLabel = 'Continue';
      $actionTitle = $reqOk ? 'Ready when you are' : 'Installation is blocked';
      $actionText  = $reqOk
          ? 'Continue runs the existing installation checks on the server: it creates the tables, the administrator '
            . 'account and the store settings. Keep this page open until it finishes.'
          : 'Fix the failed required checks above, then press Refresh requirements. Nothing is written to your '
            . 'server until every required check passes.';
      $actionEnabled = $reqOk;
  }
  ?>
  <div class="actionbar">
    <div class="actionbar__info">
      <p class="actionbar__title"><?= e($actionTitle) ?></p>
      <p class="actionbar__text" id="actionbar-status"><?= e($actionText) ?></p>
    </div>
    <div class="actionbar__buttons">
      <form method="get">
        <button class="btn btn--ghost" type="submit">
          <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-refresh"/></svg>
          Refresh requirements
        </button>
      </form>
      <button class="btn btn--primary" type="submit" form="<?= e($actionForm) ?>"
              aria-describedby="actionbar-status" <?= $actionEnabled ? '' : 'disabled' ?>>
        <?= e($actionLabel) ?>
        <svg class="ico" aria-hidden="true" focusable="false"><use href="#i-arrow"/></svg>
      </button>
    </div>
  </div>

<?php endif; ?>
</main>

<footer class="shell foot">
  <p><?= e($pageTitle) ?> &middot; ShopHaat installer &middot; No database credentials leave this server.</p>
</footer>

<script<?= sh_csp_nonce_attr() ?>>
(function () {
  var pw = document.getElementById('admin_password');
  var pw2 = document.getElementById('admin_password2');
  var live = document.getElementById('pw-live');
  if (!pw || !live) { return; }

  var MIN = <?= (int)sh_password_min_length('admin') ?>;

  function evaluate() {
    var v = pw.value;
    if (v === '') { live.hidden = true; pw.setCustomValidity(''); return; }
    live.hidden = false;
    if (v.length >= MIN) {
      live.textContent = 'Password length is fine.';
      live.className = 'pw-live pw-live--ok';
      pw.setCustomValidity('');
    } else {
      live.textContent = 'At least ' + MIN + ' characters (' + (MIN - v.length) + ' more).';
      live.className = 'pw-live pw-live--bad';
      pw.setCustomValidity('Password must be at least ' + MIN + ' characters.');
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
