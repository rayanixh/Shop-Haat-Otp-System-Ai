# ShopHaat — Deployment Checklist

Ordered, with the command or screen for each step. Nothing here requires SSH,
Composer, Node or Docker.

## 0. Before you touch the live store

- [ ] **Back up the database** — Admin → Backups → "Create backup" (or
      `mysqldump` from cPanel → phpMyAdmin → Export). Keep the file off the
      server.
- [ ] **Back up the files** — cPanel → File Manager → compress the project
      folder (or your host's backup feature).
- [ ] Confirm the PHP version is **8.0+** (8.1–8.3 recommended; this work was
      tested on 8.5.12).
- [ ] Confirm extensions **pdo, pdo_mysql, json** are enabled. `mbstring`, `dom`,
      `curl`, `openssl`, `fileinfo`, `gd` are optional and degrade with a
      documented fallback (Admin → System Health lists what is in use).

## 1. Upload the files

- [ ] Upload and overwrite, keeping `config/database.php`, `config/installed.lock`,
      `uploads/` and `logs/` as they are.
- [ ] Keep `.htaccess`, `.user.ini`, `uploads/.htaccess`, `logs/.htaccess` —
      these are the hardening layer.
- [ ] If the host runs **nginx**, port the deny rules (they are Apache-only):
      `location ^~ /config/ { deny all; }`,
      `location ^~ /includes/ { deny all; }`,
      `location ^~ /storage/ { deny all; }`,
      `location ^~ /logs/ { deny all; }`, and
      `location ~* ^/uploads/.*\.(php|phtml|phar|pl|py|cgi|sh|html?)$ { deny all; }`.

## 2. Apply migrations

- [ ] Run `install/migrations/2026-11-otp-rate-limits.sql` in phpMyAdmin
      (**only** if it has not already been applied; it is
      `CREATE TABLE IF NOT EXISTS`, so re-running is harmless), **or** simply
      open any page that issues an OTP — the lazy bootstrap creates the tables
      and stamps the version.
- [ ] Confirm Admin → System Health shows no database error and the OTP row.

## 3. Configure the environment

- [ ] Leave `config/environment.php` absent (or `return 'production';`) on the
      live store. It is the only file that can relax the guard rails.
- [ ] On a **staging** copy: `return 'staging';` plus
      `define('SH_STAGING_USER', …)` / `define('SH_STAGING_PASS', …)` so the site
      asks for a password. Admin → System Health must show
      "Staging (protected)".
- [ ] Never set `development` on a host that holds real customer data.

## 4. Secrets

- [ ] Open **Admin → Security → Deployment, secrets and client IPs**.
      The OTP pepper shows a source of *database settings*.
- [ ] Click **Move to config/secrets.php** (recommended). The file is written
      `0600`; existing rate limits keep counting because the previous value is
      still recognised.
- [ ] Verify `config/secrets.php` is not downloadable:
      `https://your-domain/config/secrets.php` must return 403/404 (Apache with
      the shipped `.htaccess`) — on nginx use the deny block above.
- [ ] Optional: put the same value in an environment variable instead
      (`SH_SECRET_OTP_RATE_PEPPER`) and delete `config/secrets.php`.

**Rotating the pepper later:** set `SH_SECRET_OTP_RATE_PEPPER` to the new value
and `SH_SECRET_OTP_RATE_PEPPER_PREVIOUS` to the old one; keep both for at least
24 hours (the daily window), then remove the previous entry. Rate limits are
never reset during that window.

## 5. Client IP / trusted proxies

- [ ] Not behind a proxy, or you do not use per-IP limits behind one: nothing to
      do. The connection address is used.
- [ ] **Behind Cloudflare:** Admin → Security → **Trust Cloudflare ranges**
      (fetches Cloudflare's published list live and saves it with mode
      `cloudflare`). Re-run after a few months or when the cache expires.
- [ ] **Behind another proxy/load balancer:** Admin → Customer Verification →
      Trusted proxies → one IP or CIDR per line (e.g. `10.0.0.0/8`), mode `xff`.
- [ ] Verify in Admin → System Health that the "Client IP policy" row shows the
      expected mode and range count.
- [ ] `SH_TRUSTED_PROXIES` / `SH_PROXY_MODE` environment variables can seed the
      same values when the database is not reachable yet (e.g. during install).

## 6. Phone OTP / SMS

- [ ] Admin → Security: the readiness callout must be green before phone OTP is
      enabled. TextBee needs its API key; a custom gateway needs an **HTTPS** URL.
- [ ] Send a test code and confirm a real SMS arrives.
- [ ] Confirm rate limits: a second request inside the cooldown must be refused
      (HTTP 429 in the API response).

## 7. Content Security Policy

- [ ] The default is **report-only**: nothing is blocked, violations are logged.
- [ ] Leave it for a day of normal traffic, then check Admin → Error Logs for
      `CSP violation` lines. The shipped templates were verified to contain no
      inline handlers, no nonce-less inline scripts, no remote scripts and no
      `eval` (`tests/csp-readiness.php`), so a quiet log is expected.
- [ ] Switch to **Enforce** on a staging copy first, browse customer + admin
      flows, then switch production. **Off** is the rollback if anything breaks.

## 8. Web server / PHP settings

- [ ] `display_errors = Off`, `log_errors = On` (shipped in `.user.ini` and
      `.htaccess`; the application also forces it in `config/config.php`).
- [ ] `upload_max_filesize` ≥ 64M and `post_max_size` ≥ 68M if you upload large
      product images.
- [ ] HTTPS enabled; the application sends HSTS only over HTTPS.
- [ ] `logs/`, `config/`, `storage/` not reachable over HTTP (see §1).

## 9. Cron

- [ ] Add the scheduled backup call from cPanel → Cron Jobs. Use **one** of:

      ```bash
      # PHP CLI (recommended: no web request, no token in a URL)
      php /home/USER/public_html/cron/backup.php --token=YOUR_TOKEN

      # or, if your host prefers an HTTP call
      wget -q -O /dev/null "https://your-domain/cron/backup.php?token=YOUR_TOKEN"
      ```

      `YOUR_TOKEN` is shown on Admin → Backups. **PHP does not read a query
      string from the command line**, so `php cron/backup.php?token=…` never
      authenticates — use `--token=…` or the `SH_BACKUP_CRON_TOKEN` environment
      variable. Setting the token in `config/secrets.php`
      (`'backup_cron_token' => '…'`) keeps it out of the database as well.
- [ ] Confirm one run produced a file in Admin → Backups.

## 10. Post-deploy verification

- [ ] Load the storefront, a product page, the cart and checkout (mobile width
      too).
- [ ] Sign in as a customer (email+password **and** phone OTP if enabled).
- [ ] Place one test order end-to-end and confirm the order and payment rows.
- [ ] Sign in to the admin panel; open Dashboard, Products, Orders, Settings,
      System Health.
- [ ] Run the shipped checkers on the server if you have a terminal
      (optional): `php tools/security-scan.php`.
- [ ] Watch `logs/app-*.log` for 24 hours for `error`/`security` channels.

## 11. Rollback

| Change | Rollback |
|---|---|
| OTP rate-limit tables | `install/migrations/2026-11-otp-rate-limits-rollback.sql` (drops only the two new tables) |
| Files | restore the backup from step 0 |
| CSP | Admin → Security → set **Report only** or **Off** |
| Trusted proxies | Admin → Customer Verification → clear the list (mode returns to connection-address only) |
| OTP pepper | set `SH_SECRET_OTP_RATE_PEPPER` to the previous value, or delete `config/secrets.php` (the stored copy in `settings` is still honoured) |
| Environment | delete `config/environment.php` (defaults to production) |
