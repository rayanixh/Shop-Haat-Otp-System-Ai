# ShopHaat — Remaining Risks

Everything below is **open**. Nothing in this file has been “mitigated in
documentation only”; each entry says what is actually true today.

Severity is about *this* application in a typical cPanel deployment, not a
theoretical maximum.

---

## R-01 · Payment concurrency is verified with two live connections, not under load (Low, open — narrowed)

* **Verified on a real engine (2026-11-14):** `tests/mysql-integration.php` now
  really connects — MySQL **8.0.46** and MariaDB **10.6.28**, run `43631797400`,
  with `SH_TEST_REQUIRE_ENGINE=1` so a blocked connection fails the job. Scenarios
  3–7 passed on both: the unique TrxID index rejects a duplicate, a second
  connection cannot claim the same payment, a rollback leaves no trace,
  `SELECT … FOR UPDATE` blocks the second connection until commit, and a code
  submitted twice at once is honoured once.
* **Corrected:** report 16 said this suite "passed at `a11ffdf`". It had not
  connected — a `$pass` / `$GLOBALS['pass']` name collision in the suite made PDO
  reject the password and every scenario reported BLOCKED under a green tick. See
  `reports/17-final-four-blockers.md` §0.
* **Still open:** concurrency is proven with two connections inside one process,
  not with hundreds of simultaneous checkouts.
* **Action before go-live:** nothing beyond §5 of the deployment checklist.
## R-02 · IDOR verified at predicate level and for the signed-out case (Medium, open — narrower)

* **Verified:** ownership predicates in `includes/` are exercised against a real
  database (engine scenario 9: a customer cannot read another customer's order),
  and the browser run confirms `/admin/orders.php` without a session shows no
  order data and is answered with a login page or 403.
* **Still open:** a *signed-in second customer* walking another customer's order
  URLs in a browser. That needs two live sessions and a real order in the same
  store; the browser suite currently signs in one customer.
* **Action:** add a second signed-in context to `tests/browser/smoke.mjs` on
  staging, or accept the predicate-level proof.
## R-03 · MySQL 8.0 verified in CI (Closed)

`tests/mysql-integration.php` passed as a required CI step against a real
`mysql:8.0` service — server version **8.0.46**, run `43631797400`, eleven
engine scenarios + the connection/refusal checks, `SH_TEST_REQUIRE_ENGINE=1`.
MariaDB is covered separately under R-14.
## R-04 · `X-Forwarded-Proto` remains lenient when no proxy list is set (Low, open)

`sh_request_is_https()` believes `X-Forwarded-Proto: https` from any peer unless a
trusted-proxy list is configured. Impact is limited to the requesting client's own
session (Secure-cookie flag, generated URLs, HSTS). Fix by configuring a proxy
list (deployment checklist §5).

## R-05 · No image re-encoding (Low, open)

`sh_upload_image()` validates the real MIME type and stores a random name with a
safe extension, but does not re-encode the pixels. A polyglot file cannot execute
through a normal PHP setup; a misconfigured server that maps arbitrary extensions
to PHP would need `uploads/` made non-executable at the server level.
**Action:** on nginx, add the deny block from the deployment checklist.

## R-06 · Backup → restore rehearsal executed on MySQL 8.0.46 (Closed for the rehearsed path)

`tools/restore-rehearsal.php --create-backup --app-check` ran in CI: the
application created the backup, the tool imported it into a **new** scratch
database (created by root, dropped afterwards), verified file integrity, the gzip
round trip, 54 of 54 dumped tables, the unique payment index, settings/orders/
products/users row counts, a representative order with its items and payment,
zero orphan order items, and then pointed the application at the restored copy and
had it read the data through its own bootstrap. **23 checks, 0 failures.**

Refusals are executed too (missing dump, empty dump, a `.gz` that is not gzip, a
dump with no tables, a non-disposable name, the live name, a production-looking
name, invalid characters, `--create-backup` on an uninstalled checkout).

**Still open:** restoring onto a *different* engine version (8.0 → 10.6) and a
restore from `mysqldump` output rather than the application's own dump format.
## R-07 · CSP observed in a real browser, still report-only (Low, open)

The browser regression run collects `securitypolicyviolation` events on every page
it visits and asserts there are none: **zero violations across the storefront,
product, cart, checkout, admin and mobile passes** (Chromium 131.0.6778.33, run
`43631797400`).

**Still open:** *enforcement*. The store ships the policy report-only by default
and the run used `php -S`, so a real Apache/LiteSpeed host over HTTPS with
`enforce` switched on has not been exercised.
**Action:** enforce on staging, click through customer + admin, then production.
`style-src 'unsafe-inline'` remains by design.
## R-08 · Browser regression exists and runs in CI (Closed for `php -S`)

`tests/browser/smoke.mjs` drives Chromium against the application served by
`php -S` on a disposable MySQL store seeded through the application's own
installer: **50 checks, 0 failures** (run `43631797400`). It covers the storefront,
listing, product page and gallery behaviour, add-to-cart, customer sign-in,
checkout to a placed order, admin sign-in, the signed-out admin guard, a 390×844
mobile viewport (overflow, header, buttons) and CSP/console/request health.

**Still open:** Apache/LiteSpeed with `.htaccess` rewriting and real HTTPS. The
suite runs on staging with no code change, but it has not been run there.
## R-09 · Courier and webhook verification reviewed at file level only (Medium, open)

`includes/courier.php` drivers, the Telegram webhook and `api/email-events.php`
were read, not exercised. Webhook authenticity checks should be confirmed against
each provider's documentation before relying on them.

## R-10 · Notification retry semantics not load-tested (Low, open)

Retries are keyed to avoid duplicates, but concurrency (two workers retrying the
same row) was not exercised.

## R-11 · Performance is unmeasured (Low–Medium, open)

No profiling was performed. The schema has explicit indexes for the hot paths
(orders by customer/status/date, products by category/status, OTP and rate-limit
tables by phone/IP/time), and list pages paginate, but no query plan was reviewed
and no page was timed. No performance claims are made in this documentation.

## R-12 · Shared-hosting ceilings (Informational)

File-backed rate limiting (`logs/limits/`) fails **open** when the directory is
unwritable, by design, so a broken log directory cannot lock the owner out. That
also means per-IP limits degrade to "no limit" in that specific failure mode;
Admin → System Health reports a non-writable log directory.

## R-13 · Admin actions that write files can fail on hardened hosts (Low)

"Move the OTP pepper to config/secrets.php" needs a writable `config/`. When it
fails, the message says so and the stored pepper keeps working — no state is
half-changed.

---

## R-14 · MariaDB 10.6 verified (Closed)

`tests/mysql-integration.php` ran against a real `mariadb:10.6` service — server
version **10.6.28-MariaDB-ubu2204**, run `43631797400`, `SH_TEST_REQUIRE_ENGINE=1`
— and passed all eleven engine scenarios. MariaDB support is no longer an
assumption; the differences this risk named (`information_schema` details, index
limits, `NOWAIT`, duplicate-key behaviour after a rollback) are now exercised on
the engine itself.
## R-15 · A failed scheduled backup can still look quiet on some hosts (Low, open)

The CLI entry point now explains itself and exits non-zero when the store is not
installed (fixed in this pass). What remains is host-dependent: some hosts mail
cron output only when it is non-empty, and a *successful* run prints one line to
STDOUT. Confirm on staging that the cPanel cron output is delivered, or set
`MAILTO=` on the cron line.
**Note:** the shell status of `cron/backup.php` could not be observed in this
sandbox because the php-wasm CLI always returns 0; it was verified by reading the
`exit()` path and by observing the message.

## Risk accepted deliberately

| Item | Why |
|---|---|
| `style-src 'unsafe-inline'` in the CSP | Hundreds of existing inline `style=` attributes; removing them is a UI rewrite, not a security fix. Script execution is what matters and it is nonce-locked. |
| `test`/`testing` still map to development | Existing deployments rely on it for local runs; documented, and staging is now separate. |
| Manual (not automated) bKash/Nagad/Rocket verification | That is the product workflow today; automating it would require provider onboarding that does not exist in this repository. |
