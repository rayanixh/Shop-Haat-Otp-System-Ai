<?php
/**
 * Deployment environment resolution.
 *
 * Three distinct environments, in the order of trust they grant:
 *
 *   development  Offline OTP may be enabled explicitly; test-only code exposure
 *                is allowed but still restricted to a superadmin and the offline
 *                driver; diagnostics may be verbose. Never use on a live store.
 *
 *   staging      A dress rehearsal of production. Real provider configuration is
 *                required (the offline driver is refused), no OTP code is ever
 *                echoed, and no unrestricted debug output is produced. Access is
 *                expected to be protected — see sh_staging_gate() below.
 *
 *   production   Strict: no offline OTP, no code echo, no debug output, no
 *                development affordances, production error handling.
 *
 * Resolution order (first match wins):
 *   1. the `SH_ENVIRONMENT` constant — define it in config/environment.php;
 *   2. the `SH_ENVIRONMENT` environment / server variable (cPanel "Environment
 *      Variables", staging hosts, CI);
 *   3. the default: production.
 *
 * Production is the default on purpose. An installation that never configures
 * anything — or that contains a typo — is treated as production, so the offline
 * driver and code echo fail closed rather than silently opening.
 *
 * Accepted values (case-insensitive):
 *   development : development, dev, local, localhost, sandbox
 *   staging     : staging, stage, preprod, pre-production, uat
 *   production  : production, prod, live, and everything else
 *
 * "test"/"testing" used to be treated as development. It still is, because many
 * existing deployments rely on it for local runs — but it is no longer how
 * staging is expressed: staging is its own environment and no longer receives
 * development privileges.
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
            if (is_string($value) && trim($value) !== '') { return $value; }
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
    /** 'development', 'staging' or 'production' (never empty, never a typo). */
    function sh_environment(): string
    {
        $raw = strtolower(trim(sh_environment_raw()));
        if ($raw === '') { return 'production'; }

        $development = ['development', 'dev', 'local', 'localhost', 'sandbox', 'testing', 'test'];
        if (in_array($raw, $development, true)) { return 'development'; }

        $staging = ['staging', 'stage', 'preprod', 'pre-production', 'preproduction', 'uat'];
        if (in_array($raw, $staging, true)) { return 'staging'; }

        // production, prod, live and anything unrecognised.
        return 'production';
    }
}

if (!function_exists('sh_is_development')) {
    function sh_is_development(): bool
    {
        return sh_environment() === 'development';
    }
}

if (!function_exists('sh_is_staging')) {
    function sh_is_staging(): bool
    {
        return sh_environment() === 'staging';
    }
}

if (!function_exists('sh_is_production')) {
    function sh_is_production(): bool
    {
        return sh_environment() === 'production';
    }
}

if (!function_exists('sh_environment_label')) {
    /** Human label for the admin screens (never derived from user input). */
    function sh_environment_label(): string
    {
        return match (sh_environment()) {
            'development' => 'Development',
            'staging'     => 'Staging',
            default       => 'Production',
        };
    }
}

if (!function_exists('sh_debug_allowed')) {
    /**
     * May this deployment show verbose diagnostics?
     *
     * Development only. Staging is deliberately excluded: it holds real customer
     * data copied from production often enough that unrestricted debug output
     * would be a disclosure risk, and a staging store is frequently reachable
     * from the public internet.
     */
    function sh_debug_allowed(): bool
    {
        return sh_is_development();
    }
}

if (!function_exists('sh_staging_credentials')) {
    /**
     * Optional HTTP basic-auth credentials that protect a staging deployment.
     *
     * Configure SH_STAGING_USER / SH_STAGING_PASS (constants in
     * config/environment.php or environment variables). Nothing is hardcoded and
     * no password is ever logged.
     *
     * @return array{0:string,1:string}|null
     */
    function sh_staging_credentials(): ?array
    {
        $user = '';
        $pass = '';
        if (defined('SH_STAGING_USER')) { $user = (string)SH_STAGING_USER; }
        elseif (($v = getenv('SH_STAGING_USER')) !== false) { $user = (string)$v; }
        if (defined('SH_STAGING_PASS')) { $pass = (string)SH_STAGING_PASS; }
        elseif (($v = getenv('SH_STAGING_PASS')) !== false) { $pass = (string)$v; }
        if (trim($user) === '' || $pass === '') { return null; }
        return [$user, $pass];
    }
}

if (!function_exists('sh_staging_authenticated')) {
    /**
     * May this request see the staging site?
     *
     * True for every environment except staging, true on staging when no gate is
     * configured, and otherwise only for a correct HTTP basic-auth pair. Split
     * from the header emission so the decision can be unit-tested without a
     * web server.
     */
    function sh_staging_authenticated(): bool
    {
        if (!sh_is_staging()) { return true; }
        $credentials = sh_staging_credentials();
        if ($credentials === null) { return true; }

        [$user, $pass] = $credentials;
        $givenUser = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
        $givenPass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
        return $givenUser !== '' && $givenPass !== ''
            && hash_equals($user, $givenUser)
            && hash_equals($pass, $givenPass);
    }
}

if (!function_exists('sh_staging_gate')) {
    /**
     * Close a staging deployment to anonymous visitors when credentials exist.
     *
     * Called once, early, from config/config.php. When staging has no gate
     * configured the request continues (a host already behind a VPN or a
     * host-level password must not be broken) and the condition is surfaced in
     * Admin → System Health instead of failing silently.
     *
     * @return bool true when the request must stop here
     */
    function sh_staging_gate(): bool
    {
        if (sh_staging_authenticated()) { return false; }
        if (!headers_sent()) {
            header('WWW-Authenticate: Basic realm="ShopHaat staging", charset="UTF-8"');
            header('HTTP/1.1 401 Unauthorized');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo '<!doctype html><meta name="robots" content="noindex">'
           . '<title>Staging</title><p>This staging site is private. Sign in to continue.</p>';
        return true;
    }
}

if (!function_exists('sh_staging_unprotected')) {
    /** True when this is staging with no access protection configured. */
    function sh_staging_unprotected(): bool
    {
        return sh_is_staging() && sh_staging_credentials() === null;
    }
}
