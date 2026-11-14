# ShopHaat — Remaining Risks

Everything below is **open**. Nothing in this file has been “mitigated in
documentation only”; each entry says what is actually true today.

Severity is about *this* application in a typical cPanel deployment, not a
theoretical maximum.

---

## R-01 · Payment concurrency is still unverified on a real engine (High, open — narrower than before)

* **Now verified:** `tests/payment-security.php` (53 checks) covers server-side
  price recalculation, quantity/discount/shipping manipulation, TrxID handling,
  duplicate submission, ownership, the allowed status transitions and admin
  verification; `install/schema.php` carries `UNIQUE KEY uq_payments_transaction_id`
  and the migration that adds it to an existing store.
* **Still not verified:** the same guarantees **under real concurrency on MySQL/
  MariaDB** (two simultaneous submissions, `SELECT … FOR UPDATE`, rollback after a
  duplicate key). `tests/mysql-integration.php` scenarios 3–5 exist for exactly
  that and are BLOCKED without a server.
* **Interim mitigation unchanged:** manual gateway verification stays
  admin-driven; no automated callback marks an order paid. Do not enable an
  automated gateway until the engine run is attached.

## R-02 · IDOR verified statically and at predicate level, not over HTTP (High, open — narrower)

* **Now verified:** `tests/idor-scan.php` (42 checks) walks every customer route
  and AJAX handler and confirms each one consults an ownership check
  (`sh_order_can_view()`, session user id) before returning data or mutating it.
  `tests/mysql-integration.php` §9 executes `sh_order_can_view()` against real
  order rows for owner / other customer / guest / guest-with-session cases.
* **Still not verified:** two real sessions (customer A and customer B) over HTTP
  with a changed order id, an expired session and a POST ownership swap. That
  needs a web server; the response codes could not be observed here.
* **Interim mitigation:** none specific — run the HTTP matrix in
  `reports/02-idor-object-authorisation.md` on staging.

## R-03 · MySQL-specific behaviour untested in this environment (Medium, open)

All database work here ran on SQLite through a translating shim. Locking
(`SELECT … FOR UPDATE`), `INSERT … ON DUPLICATE KEY`, and index behaviour are
MySQL/MariaDB features that the shim approximates, not proves.
**Action:** run `tests/mysql-integration.php` (eleven engine scenarios) against a
disposable staging database, and then repeat it on MariaDB — see R-14.

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

## R-06 · Backup restore is automated but still unrehearsed here (Medium, open)

Backups are created (`cron/backup.php`, `sh_backup_create()`), stored under
`storage/backups/` with an Apache deny rule + `index.php` 403, and downloaded
through an authenticated admin action. **A restore has never been performed in an
isolated environment** — the procedure exists on paper only.
**Action:** restore the latest dump into a scratch database before you need it.

## R-07 · CSP enforcement not exercised in a browser (Medium, open)

Static verification is strong (no inline handlers, no nonce-less inline scripts,
no remote scripts, no `eval`), and the policy omits `unsafe-inline`/`unsafe-eval`
for scripts. It has not been executed in a browser with `enforce` enabled.
**Action:** enforce on staging, browse customer + admin flows, then production.
`style-src 'unsafe-inline'` remains by design (existing templates use inline
`style=` attributes).

## R-08 · No web-server or browser regression run after these changes (Medium, open)

No Apache/nginx and no browser exist in this sandbox. Templates were linted and
unit-tested, and the new admin panel is plain markup, but "it renders correctly"
is **not** claimed.
**Action:** run `tests/ui-ux-audit.mjs` and `tests/product-management-behavior.mjs`
in a browser harness, then click through Admin → Security and Admin → System
Health.

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

## R-14 · No MariaDB run exists (Medium, open)

MySQL 8.0 is exercised by the CI job's own steps, and the eleven-scenario suite
targets MySQL. MariaDB differs in `information_schema` details, index limits,
`NOWAIT` support and duplicate-key behaviour after a rollback, and **no MariaDB
server has ever been used**. cPanel hosts ship either engine, so this is a real
deployment question, not a technicality.
**Action:** run `tests/mysql-integration.php` on MariaDB 10.6+ before promising
MariaDB support.

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
