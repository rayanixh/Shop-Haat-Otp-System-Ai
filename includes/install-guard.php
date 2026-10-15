<?php
/**
 * Installer access control.
 *
 * Why this exists
 * ---------------
 * The installer originally stayed reachable whenever the database was not
 * perfectly healthy — a dropped database, a missing lock file, or a one-minute
 * MySQL outage during a host restart. In that state an anonymous visitor could
 * POST the install form and the installer would:
 *
 *   - upsert an `admins` row from the submitted email + password with the
 *     `superadmin` role (`ON DUPLICATE KEY UPDATE password_hash = …`), and
 *   - overwrite `config/database.php` with a database of the visitor's choosing.
 *
 * That is a complete takeover of a running store from the public internet.
 *
 * The rule enforced here is simple and fail-closed:
 *
 *   The installer may create or modify an administrator account, or write
 *   `config/database.php`, only for a genuinely new store, or when the owner
 *   has proved filesystem access by placing a secret unlock token on the
 *   server.
 *
 * Repairing a damaged store (re-creating missing tables) stays available — it
 * runs against the *saved* connection only, ignores every credential supplied
 * by the browser, and can never touch an administrator account that already
 * exists.
 *
 * The decision logic lives in this file, separate from install/index.php, so the
 * automated tests can assert the full truth table without running the installer.
 */

const SH_INSTALL_UNLOCK_FILENAME = 'install-unlock.key';

/**
 * Where an unlock token may be placed. The parent directory is checked first
 * because on shared hosting it is usually outside the web root, so the token
 * cannot be downloaded even if the host ignores .htaccess.
 *
 * @return array<int,string>
 */
function sh_install_unlock_paths(): array
{
    $paths = [];
    if (defined('SH_ROOT')) {
        $paths[] = dirname(SH_ROOT) . '/shophaat-' . SH_INSTALL_UNLOCK_FILENAME;
    }
    if (defined('SH_CONFIG_DIR')) {
        $paths[] = rtrim(SH_CONFIG_DIR, '/') . '/' . SH_INSTALL_UNLOCK_FILENAME;
    }
    return $paths;
}

/** Content of the unlock token file, or null when no token is configured. */
function sh_install_unlock_token(): ?string
{
    foreach (sh_install_unlock_paths() as $path) {
        if (!is_file($path)) { continue; }
        $raw = @file_get_contents($path);
        if ($raw === false) { continue; }
        $token = trim($raw);
        // A token must have real entropy; a one-character file is not accepted.
        if (strlen($token) >= 16) { return $token; }
    }
    return null;
}

function sh_install_unlock_configured(): bool
{
    return sh_install_unlock_token() !== null;
}

/** Constant-time comparison against the configured unlock token. */
function sh_install_unlock_matches(string $given): bool
{
    $token = sh_install_unlock_token();
    if ($token === null || $given === '') { return false; }
    return hash_equals($token, $given);
}

/**
 * Truth table for installer permissions. Pure function: no database, no I/O.
 *
 * @return array{
 *   mode:string, installed:bool, has_config:bool, unlocked:bool,
 *   unlock_required:bool, unlock_configured:bool, can_repair:bool,
 *   can_write_config:bool, can_create_admin:bool
 * }
 */
function sh_install_access(bool $installed, bool $hasConfig, bool $unlockOk, bool $unlockConfigured): array
{
    if (!$installed && !$hasConfig) {
        // Brand-new deployment: exactly the documented first-run experience.
        return [
            'mode' => 'fresh', 'installed' => false, 'has_config' => false, 'unlocked' => true,
            'unlock_required' => false, 'unlock_configured' => $unlockConfigured,
            'can_repair' => true, 'can_write_config' => true, 'can_create_admin' => true,
        ];
    }

    if ($unlockOk) {
        // Owner proved filesystem access (e.g. migrating hosts or recovering a
        // forgotten administrator password).
        return [
            'mode' => 'unlocked', 'installed' => $installed, 'has_config' => $hasConfig, 'unlocked' => true,
            'unlock_required' => false, 'unlock_configured' => true,
            'can_repair' => true, 'can_write_config' => true, 'can_create_admin' => true,
        ];
    }

    // Store already installed (or a config from a previous install is present):
    // repair only. Credentials from the request are never used, no administrator
    // account is ever modified, and config/database.php is never rewritten.
    return [
        'mode' => 'repair', 'installed' => $installed, 'has_config' => $hasConfig, 'unlocked' => false,
        'unlock_required' => true, 'unlock_configured' => $unlockConfigured,
        // Without a saved connection there is nothing to repair against.
        'can_repair' => $hasConfig,
        'can_write_config' => false, 'can_create_admin' => false,
    ];
}

/**
 * Instructions shown to the owner when a restricted installer needs unlocking.
 * Deliberately explicit: a locked-out owner must not be left guessing.
 */
function sh_install_unlock_instructions(): array
{
    $preferred = sh_install_unlock_paths()[0] ?? 'config/' . SH_INSTALL_UNLOCK_FILENAME;
    $fallback  = sh_install_unlock_paths()[1] ?? ('config/' . SH_INSTALL_UNLOCK_FILENAME);
    return [
        'Create a text file named <code>' . htmlspecialchars(basename($preferred), ENT_QUOTES, 'UTF-8')
            . '</code> in the folder <strong>above</strong> your website folder (usually the one that contains '
            . '<code>public_html</code>), containing a single random line of at least 16 characters.',
        'If you cannot reach that folder, create it as <code>'
            . htmlspecialchars(basename($fallback), ENT_QUOTES, 'UTF-8') . '</code> inside your '
            . '<code>config/</code> folder instead.',
        'Re-open the installer and paste the same text into the box below. Deleting the file afterwards locks '
            . 'the installer again.',
        'A quick way to generate the text: <code>php -r "echo bin2hex(random_bytes(16));"</code>',
        'When you are finished, delete the file and the <code>/install</code> folder.',
    ];
}
