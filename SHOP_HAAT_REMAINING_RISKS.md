# ShopHaat — Remaining Risks

Everything below is **open**. Nothing in this file has been “mitigated in
documentation only”; each entry says what is actually true today.

Severity is about *this* application in a typical cPanel deployment, not a
theoretical maximum.

---

## R-01 · Payments were not audited end-to-end (High, open)

* **What is known:** checkout totals are recalculated server-side
  (`api/checkout.php`, `includes/payment.php`), and manual payment submission
  requires CSRF + order ownership.
* **What is not verified:** transaction-id uniqueness under concurrency,
  duplicate submission handling, allowed payment status transitions, and whether
  an admin-side verification can be replayed against another order.
* **Why it is open:** requires a live database to test the constraints, and the
  sandbox has no MySQL.
* **Interim mitigation:** manual gateway verification stays admin-driven; no
  automated callback marks an order paid. Do not enable an automated gateway
  until the audit is completed.

## R-02 · IDOR sweep on customer endpoints not completed (High, open)

* **What is known:** `api/orders.php`, `order-details.php` and `track.php`
  resolve the order from the session or from a phone+order-number pair.
* **What is not verified:** every customer-facing endpoint and AJAX handler has
  a server-side ownership check (order, address, profile, verification data).
* **Interim mitigation:** none specific — this is the next audit item.

## R-03 · MySQL-specific behaviour untested in this environment (Medium, open)

All database work here ran on SQLite through a translating shim. Locking
(`SELECT … FOR UPDATE`), `INSERT … ON DUPLICATE KEY`, and index behaviour are
MySQL/MariaDB features that the shim approximates, not proves.
**Action:** run `SH_TEST_MYSQL_DSN='mysql:host=…;dbname=…' php tests/otp-security.php`
and the other DB suites against a staging database before relying on them.

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

## R-06 · Backup restore has not been rehearsed (Medium, open)

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

## Risk accepted deliberately

| Item | Why |
|---|---|
| `style-src 'unsafe-inline'` in the CSP | Hundreds of existing inline `style=` attributes; removing them is a UI rewrite, not a security fix. Script execution is what matters and it is nonce-locked. |
| `test`/`testing` still map to development | Existing deployments rely on it for local runs; documented, and staging is now separate. |
| Manual (not automated) bKash/Nagad/Rocket verification | That is the product workflow today; automating it would require provider onboarding that does not exist in this repository. |
