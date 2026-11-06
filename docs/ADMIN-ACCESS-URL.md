# Custom Admin Access URL — deployment, verification and recovery

This document describes the **Admin Access URL** feature on **Admin → Security & Admin Access → Admin Access URL**. It is written for cPanel / Apache / LiteSpeed shared hosting and uses only PHP, MySQL/MariaDB and the shipped `.htaccess`. It requires no SSH, Node.js, Redis, Docker, cron job or paid add-on.

## What the feature does — and does not do

A custom URL is a **routing layer that reduces route discovery**, not an authentication mechanism. Every admin page, AJAX action, upload, export and settings change still requires:

* a valid administrator session (`sh_require_admin()`),
* the role permission for that action (`sh_require_perm()` / `sh_admin_can()`),
* CSRF validation on every state-changing request (`sh_require_admin()` calls it centrally),
* the existing idle timeout, login throttling/lockout, secure HttpOnly SameSite cookies and session-ID regeneration on login.

Knowing the secret URL never grants access. Hiding `/admin.php` also never replaces those checks. See the [OWASP access-control checklist](https://devguide.owasp.org/en/04-design/02-web-app-checklist/07-access-controls/).

## How routing works on cPanel

1. The root `.htaccess` contains a single-segment rewrite. A request for one 32–64 character URL-safe segment that is not an existing file or directory is internally rewritten to `admin/custom-route.php`:

   ```apache
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteCond %{REQUEST_FILENAME} !-d
   RewriteRule ^[a-z0-9_-]{32,64}/?$ admin/custom-route.php [L,QSA,NC]
   ```

2. `admin/custom-route.php` reads the raw request path, compares it in constant time with the stored active path (or with a staged pending path), and only then defines `SH_ADMIN_ROUTE_CONTEXT`. It validates `__admin_route` against a strict allowlist of real files inside `admin/` before including one. The included file always runs its normal authentication and permission guards.

3. `config/config.php` calls `sh_admin_access_guard_request()` at bootstrap. When a custom route is active, every legacy `/admin.php` or `/admin/*.php` request is answered with a generic `404 Not Found` before any admin markup is produced — including include-only partials, AJAX fragments and export handlers. The `.htaccess` additionally denies `/admin.php` with a 403 for requests that never reach PHP.

4. Admin-to-admin links are rewritten by `sh_url()` → `sh_admin_access_map_url()` only while `SH_ADMIN_ROUTE_CONTEXT` is defined on an authenticated admin request. Storefront pages, public APIs, `robots.txt`, `sitemap.xml`, JavaScript and CSS never receive the secret.

**cPanel requirements:** hidden files must be visible in File Manager so the shipped `.htaccess` is actually uploaded; Apache/LiteSpeed `mod_rewrite` must be enabled; the directory must permit the shipped directives (usually `AllowOverride All`). On hosts where rewriting is unavailable, the proposed URL will return 404 — **do not apply it**. The current route stays active until you explicitly apply a verified route.

## Safe configuration sequence (no lockout)

1. Sign in as the **Super Admin** through the current route.
2. Open **Admin → Security & Admin Access → Admin Access URL**.
3. Press **Generate secure suggestion** (192 bits from `random_bytes()`), or type your own path: one lowercase URL-safe segment of 32–64 characters. Domains, extra segments, slashes, spaces, dots, query strings, fragments, percent-encoding and reserved/colliding routes are rejected server-side.
4. Press **Save proposed route**. This only stages the value in the existing `settings` table. The current route stays fully active. Copy the displayed **Proposed Admin URL** and keep it in your password manager.
5. Press **Verify proposed route**. The application opens the staged path in the *same authenticated browser session* (a one-use session ticket, no query-string verification token), requires the superadmin role, and records a `verified` marker valid for one hour. The old route is still active.
6. Tick the explicit **"I opened the proposed URL … and confirmed the admin panel, forms and links work"** checkbox and press **Apply verified route**. The checkbox is validated server-side (not just a browser `confirm()`); without it nothing changes. Only now is the staged path promoted to active and the previous route blocked. The browser is redirected to the new URL. Confirm you can open the dashboard, settings, forms, AJAX actions and uploads on the new route before closing the old tab.

If verification fails at any point, nothing changes: the active route (or the standard `/admin/` route when the feature is off) remains usable.

**Disable / restore:** an authenticated superadmin can press **Disable and clear custom route** on the same page. The standard `/admin/` route becomes reachable again, still protected by the normal admin login, roles and CSRF. There is no unauthenticated disable endpoint.

## Recovery if the custom URL is lost (cPanel, no backdoor)

This is an out-of-band hosting-owner procedure. It uses the hosting account's own phpMyAdmin and does **not** create a bypass token, default password or unauthenticated recovery URL.

1. Log in to cPanel → **phpMyAdmin** → select the ShopHaat database (the name is in `config/database.php`).
2. Back up the database first (cPanel → Backup / phpMyAdmin → Export).
3. Run these statements in phpMyAdmin's SQL tab:

   ```sql
   UPDATE settings SET setting_value = '0' WHERE setting_key = 'admin_access_enabled';
   UPDATE settings SET setting_value = '' WHERE setting_key IN
     ('admin_access_secret_path', 'admin_access_pending_secret',
      'admin_access_verified_secret', 'admin_access_verified_at', 'admin_access_verified_by');
   ```

4. Open the standard admin URL (`/admin/login.php`) and sign in normally with the existing administrator account. No password is reset and no account is created.
5. In **Admin → Security & Admin Access**, generate and verify a new custom path before applying it again.

If the `.htaccess` rewrite itself was damaged, restore the shipped root `.htaccess` from your deployment backup or release archive using cPanel File Manager. Keep `.htaccess` visible (File Manager → Settings → Show Hidden Files). Do not add a public "recovery" PHP file: that would reintroduce the predictable entry point the feature removes.

## Storage and rollback

The feature reuses the existing `settings` table; **no migration, new table or new column is required**. Keys used:

| Key | Meaning |
| --- | --- |
| `admin_access_enabled` | `1` only after a verified route has been applied |
| `admin_access_secret_path` | active normalized path segment |
| `admin_access_pending_secret` | staged (not yet applied) path |
| `admin_access_verified_secret` / `_at` / `_by` | one-hour verification marker and superadmin id |

**Rollback** is exactly the recovery SQL above (or the authenticated **Disable and clear custom route** button). It leaves all admin accounts, roles, sessions, customer accounts, orders, products and support conversations untouched. Audit/security log entries record only a non-reversible path fingerprint, never the path itself, and never passwords or tokens.

## Validation rules (server-side)

* One segment only; 32–64 characters; `a–z`, `0–9`, `-`, `_`; must start and end alphanumerically; normalized to lowercase.
* Reject leading/trailing/multiple slashes, backslashes, whitespace, `.`, `:`, `%`, `?`, `#`, complete URLs, and encoded traversal.
* Reject reserved admin/customer/API/asset namespaces and prefixes (`admin-`, `api-`, `assets-`, `sitemap-`, …).
* Reject a path that already exists as an application file/directory or as a live catalogue/category/brand/page slug.
* Reject an empty, stale, unverified or expired candidate on apply; the active route is never removed before a verified replacement is stored.

## `.htaccess` and hosting limitations

* The rewrite is written for Apache / LiteSpeed with `mod_rewrite` and `AllowOverride All`. On nginx, IIS, or a host with rewriting disabled the proposed URL returns 404 — **do not apply it** in that case; the site keeps working through the current route.
* A static `.htaccess` cannot read the `settings` table, so it cannot conditionally deny `/admin/*.php` only while the custom route is active. The unconditional bootstrap guard (`sh_admin_access_guard_request()` in `config/config.php`) is what returns the generic 404 for every legacy admin path after apply; `admin/.htaccess` is intentionally empty. Every admin PHP file also repeats its own `sh_require_admin()` / `sh_require_perm()` check.
* `router.php` mirrors the rewrite for PHP's built-in development server, which is useful for local staging but is **not** a cPanel deployment mechanism.
* Deny rules for `config/`, `includes/`, `ai/`, `lib/`, `logs/` and `tests/` (plus the root `FilesMatch` rules for credential and backup files) are existing defence-in-depth. They do not replace PHP-side authorization.
* A CDN/proxy in front of the origin may cache a 404 for the old path. Purge it after applying a new route.

## Validation actually performed

`tests/admin-access-url.php` executes the real functions in `includes/admin-access.php` against a real PDO database and runs the routing guards in isolated PHP subprocesses (a blocked request terminates the process with a generic 404). With no MySQL DSN configured it uses in-memory SQLite with an explicit MySQL-SQL translation layer; set `SH_TEST_MYSQL_DSN`, `SH_TEST_MYSQL_USER` and `SH_TEST_MYSQL_PASS` to run the same suite against real MySQL/MariaDB. `SH_TEST_PHP_BIN` can point the subprocess checks at a specific PHP binary.

The suite covers: path normalization/validation, filesystem and live-slug collisions, CSPRNG suggestions, the staged → verified → applied → disabled lifecycle, link rewriting, include allowlist, generic 404s for `/admin.php` and direct admin PHP files, the dispatcher exception, unauthenticated `sh_require_admin()` redirect, independent auth/CSRF/RBAC on admin and customer APIs, secret-free public files, and the cPanel recovery documentation. `tests/admin-access-regression.mjs` adds source-level checks.

**Not validated here:** a live Apache/LiteSpeed/cPanel request, a real MySQL 8/MariaDB connection, an authenticated browser login and dashboard through the new URL, file uploads/exports over HTTP, and the phpMyAdmin recovery SQL on a live cPanel account. Those must be repeated on staging before enabling customer traffic.
