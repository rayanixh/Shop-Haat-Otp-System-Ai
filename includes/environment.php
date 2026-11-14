<?php
/**
 * Deployment environment resolution.
 *
 * The offline OTP driver is a test tool: it reports success without sending an
 * SMS. It must never be able to run silently on a live store, so every module
 * that can send or reveal a code asks this file whether the deployment is a
 * development environment.
 *
 * Resolution order (first match wins):
 *   1. `SH_ENVIRONMENT` constant — define it in config/environment.php.
 *   2. `SH_ENVIRONMENT` environment variable / server variable (some cPanel
 *      hosts expose an "Environment variables" panel; useful for staging).
 *   3. Default: "production".
 *
 * The default is deliberately the safe one: an installation that never sets
 * anything is treated as production, so offline OTP fails closed.
 *
 * Accepted values: development, dev, local, localhost, testing, test, staging
 * (development). Anything else — including typos — resolves to production.
 */

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }

if (!function_exists('sh_environment_raw')) {
    /** The raw configured value, before normalisation. */
    function sh_environment_raw(): string
    {
        if (defined('SH_ENVIRONMENT')) { return (string)SH_ENVIRONMENT; }
        $file = SH_ROOT . '/config/environment.php';
        // A site-specific override file, if the owner created one. Loading it
        // here means config.php does not have to be edited to switch modes.
        if (!defined('SH_ENVIRONMENT') && is_file($file)) {
            $value = require $file;
            if (is_string($value) && $value !== '') { return $value; }
        }
        $env = getenv('SH_ENVIRONMENT');
        if (is_string($env) && $env !== '') { return $env; }
        if (isset($_SERVER['SH_ENVIRONMENT']) && is_scalar($_SERVER['SH_ENVIRONMENT'])) {
            return (string)$_SERVER['SH_ENVIRONMENT'];
        }
        return '';
    }
}

if (!function_exists('sh_environment')) {
    /** 'development' or 'production' (never empty). */
    function sh_environment(): string
    {
        $raw = strtolower(trim(sh_environment_raw()));
        if ($raw === '') { return 'production'; }
        $development = ['development', 'dev', 'local', 'localhost', 'testing', 'test', 'staging', 'sandbox'];
        return in_array($raw, $development, true) ? 'development' : 'production';
    }
}

if (!function_exists('sh_is_development')) {
    function sh_is_development(): bool
    {
        return sh_environment() === 'development';
    }
}

if (!function_exists('sh_is_production')) {
    function sh_is_production(): bool
    {
        return !sh_is_development();
    }
}
