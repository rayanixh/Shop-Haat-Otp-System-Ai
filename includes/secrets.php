<?php
/**
 * Server-side secret resolution.
 *
 * Why
 * ---
 * The OTP rate-limit pepper is a cryptographic secret: anyone who reads it can
 * compute the identifiers stored in `otp_rate_limits`, and anyone who loses it
 * silently resets every limit for the store. The settings table is a convenient
 * place to put it, but it lives in the same database as the business data and
 * is therefore copied by every backup and every phpMyAdmin export.
 *
 * This module lets a secret come from somewhere safer, without breaking an
 * existing installation:
 *
 *   1. a PHP constant, e.g. define('SH_SECRET_OTP_RATE_PEPPER', '…');
 *   2. an environment variable, e.g. SH_SECRET_OTP_RATE_PEPPER or
 *      SH_OTP_RATE_PEPPER (cPanel: "Setup PHP" → Environment Variables, or the
 *      hosting panel's "Environment Variables" screen);
 *   3. config/secrets.php, a PHP file that returns an array (outside the web
 *      root when the host allows it, and denied by the shipped .htaccess when
 *      it is not);
 *   4. the value already stored by the application (legacy fallback).
 *
 * Rules honoured here:
 *   - a value is never printed, logged or returned to a browser;
 *   - an empty or obviously invalid value is ignored rather than used;
 *   - resolution is deterministic: the same request never produces two answers,
 *     and a missing secret never causes silent regeneration of a different one;
 *   - callers receive '' when nothing is configured, so they can decide whether
 *     failing closed or generating-and-persisting is the safer behaviour.
 */

if (!function_exists('sh_secret_config_file')) {
    /** Path of the optional secrets file (next to database.php). */
    function sh_secret_config_file(): string
    {
        $dir = defined('SH_CONFIG_DIR') ? SH_CONFIG_DIR : (defined('SH_ROOT') ? SH_ROOT . '/config' : __DIR__ . '/../config');
        return rtrim($dir, '/') . '/secrets.php';
    }
}

if (!function_exists('sh_secret_file_values')) {
    /**
     * The secrets file as an array, read once per request.
     *
     * A file that does not return an array (or throws) is treated as absent: a
     * broken secrets file must not take the store down.
     *
     * @return array<string,string>
     */
    function sh_secret_file_values(bool $refresh = false): array
    {
        static $values = null;
        if ($values !== null && !$refresh) { return $values; }
        $values = [];
        $file = sh_secret_config_file();
        if (!is_file($file)) { return $values; }
        try {
            $loaded = require $file;
            if (is_array($loaded)) {
                foreach ($loaded as $k => $v) {
                    if (is_string($k) && is_string($v) && trim($v) !== '') {
                        $values[strtolower($k)] = trim($v);
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('sh_log_line')) {
                sh_log_line('security', 'config/secrets.php could not be read; falling back to other secret sources.');
            }
        }
        return $values;
    }
}

if (!function_exists('sh_secret')) {
    /**
     * Resolve a secret by name.
     *
     * @param string $key      lower_snake_case name, e.g. 'otp_rate_pepper'
     * @param string $fallback value used when no secret source provides one
     */
    function sh_secret(string $key, string $fallback = ''): string
    {
        $key = strtolower(trim($key));
        if ($key === '') { return $fallback; }
        $upper = strtoupper($key);

        foreach (['SH_SECRET_' . $upper, 'SH_' . $upper] as $constant) {
            if (defined($constant)) {
                $v = trim((string)constant($constant));
                if ($v !== '') { return $v; }
            }
        }

        foreach (['SH_SECRET_' . $upper, 'SH_' . $upper] as $envName) {
            $v = getenv($envName);
            if (is_string($v) && trim($v) !== '') { return trim($v); }
        }

        $file = sh_secret_file_values();
        if (isset($file[$key]) && $file[$key] !== '') { return $file[$key]; }

        return $fallback;
    }
}

if (!function_exists('sh_secret_source')) {
    /**
     * Where a secret comes from — a label only, never the value.
     *
     * Used by the admin screens so an operator can confirm the pepper is
     * externally managed without the secret ever being rendered in HTML.
     */
    function sh_secret_source(string $key): string
    {
        $key = strtolower(trim($key));
        $upper = strtoupper($key);
        foreach (['SH_SECRET_' . $upper, 'SH_' . $upper] as $constant) {
            if (defined($constant) && trim((string)constant($constant)) !== '') { return 'constant'; }
        }
        foreach (['SH_SECRET_' . $upper, 'SH_' . $upper] as $envName) {
            $v = getenv($envName);
            if (is_string($v) && trim($v) !== '') { return 'environment'; }
        }
        $file = sh_secret_file_values();
        if (isset($file[$key]) && $file[$key] !== '') { return 'config/secrets.php'; }
        return 'none';
    }
}

if (!function_exists('sh_secret_is_external')) {
    function sh_secret_is_external(string $key): bool
    {
        return sh_secret_source($key) !== 'none';
    }
}

if (!function_exists('sh_secret_write_file')) {
    /**
     * Persist a secret into config/secrets.php.
     *
     * Refuses to overwrite an existing file that already defines a value for the
     * same key, so a rotation is always a deliberate edit by the operator. The
     * file is written atomically and chmod 0600; on a host where the account
     * cannot write (or cannot restrict permissions) the call fails and the
     * caller keeps using the value it already had.
     *
     * @param array<string,string> $values lower_snake_case key => secret
     * @return array{ok:bool,error?:string}
     */
    function sh_secret_write_file(array $values): array
    {
        $file = sh_secret_config_file();
        $existing = [];
        if (is_file($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded)) { $existing = $loaded; }
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'The existing config/secrets.php could not be read, so it was left untouched.'];
            }
        }
        foreach ($values as $k => $v) {
            if (isset($existing[$k]) && (string)$existing[$k] !== '' && (string)$existing[$k] !== (string)$v) {
                return ['ok' => false, 'error' => 'config/secrets.php already defines this secret. Edit the file to change it.'];
            }
            $existing[$k] = (string)$v;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) { return ['ok' => false, 'error' => 'The config folder is missing.']; }
        if (!is_writable($dir)) { return ['ok' => false, 'error' => 'The config folder is not writable by PHP.']; }

        $php = "<?php\n"
             . "/**\n"
             . " * ShopHaat server-side secrets.\n"
             . " *\n"
             . " * Generated by Admin → Security. Keep this file out of version control,\n"
             . " * out of any public folder and inside the hosting account only. Never\n"
             . " * paste its contents into a support ticket.\n"
             . " */\n"
             . "return [\n";
        foreach ($existing as $k => $v) {
            if (!preg_match('/^[a-z0-9_]+$/', (string)$k)) { continue; }
            $php .= "    '" . $k . "' => '" . addcslashes((string)$v, "\\'") . "',\n";
        }
        $php .= "];\n";

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'The secrets file could not be written.'];
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'The secrets file could not be moved into place.'];
        }
        @chmod($file, 0600);
        // The file changed during this request: drop the cached copy so the
        // caller (Admin → Security) immediately reads what it just wrote.
        sh_secret_file_values(true);
        return ['ok' => true];
    }
}
