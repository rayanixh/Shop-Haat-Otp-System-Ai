<?php
/**
 * ShopHaat — optional server-side secrets file (EXAMPLE).
 *
 * Copy to `config/secrets.php` and fill in only the values you need, or set the
 * equivalent environment variables in cPanel → Setup PHP → Environment
 * Variables. Either way the secret lives outside the database, so a database
 * export or a phpMyAdmin backup no longer contains it.
 *
 * Recommended (most secure) order:
 *   1. environment variables on the hosting account;
 *   2. this file, with permissions 0600, in `config/` (denied by the shipped
 *      .htaccess for Apache/LiteSpeed; on nginx add `location ^~ /config/ { deny all; }`);
 *   3. leaving it unset — the application then keeps the value in `settings`.
 *
 * The values below are placeholders. Never commit a real secrets file to
 * version control, and never paste its contents into a support ticket.
 *
 * Resolution is implemented in includes/secrets.php:
 *   constant  SH_SECRET_<KEY>   (e.g. SH_SECRET_OTP_RATE_PEPPER)
 *   env var   SH_SECRET_<KEY>   or SH_<KEY>
 *   this file key <key>         (lower_snake_case)
 */

return [
    /*
     * Pepper for the OTP rate-limit identifiers (HMAC-SHA256).
     *
     * Generate one with:
     *     php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
     *
     * Or let the application create it: Admin → Security → Phone OTP →
     * "Move the OTP pepper to config/secrets.php". Existing rate-limit history
     * keeps counting after the move.
     */
    // 'otp_rate_pepper' => 'PASTE-64-HEX-CHARACTERS-HERE',

    /*
     * Previous pepper(s), comma separated. Only needed when the pepper itself
     * is rotated: the old value lets the window keep counting rows hashed with
     * it, so a rotation does not silently reset every limit. Remove the entry
     * once the longest window (the daily limit) has passed.
     */
    // 'otp_rate_pepper_previous' => '',

    /*
     * Cron token for cron/backup.php. Stored in `settings` by default because
     * the backup screen must show it; moving it here disables that screen's
     * value and requires the same value in the cron URL.
     */
    // 'backup_cron_token' => 'PASTE-A-LONG-RANDOM-TOKEN',
];
