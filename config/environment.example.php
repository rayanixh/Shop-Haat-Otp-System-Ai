<?php
/**
 * ShopHaat — deployment environment (EXAMPLE).
 *
 * Copy to `config/environment.php` and keep it OUTSIDE version control.
 * Alternatively set the `SH_ENVIRONMENT` environment variable on the host
 * (cPanel → Setup PHP / Environment Variables), which needs no file at all.
 *
 * Accepted values:
 *   'development'  offline OTP allowed, test-code echo allowed for a superadmin,
 *                  verbose diagnostics. Never on a live store.
 *   'staging'      real provider required, no code echo, no debug output,
 *                  optionally protected by HTTP basic auth (below).
 *   'production'   the default. Strict.
 *
 * An unrecognised value is treated as 'production'.
 */
return 'production';

/*
 * Staging access gate (only read when the environment is 'staging').
 * Set both to protect the site with HTTP basic auth:
 *
 *   define('SH_STAGING_USER', 'staging-review');
 *   define('SH_STAGING_PASS', 'use-a-long-random-value');
 *
 * When they are absent the site stays reachable, and Admin → System Health
 * reports that staging is unprotected so it cannot go unnoticed.
 */
