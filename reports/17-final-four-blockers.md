# Report 17 — The final four blockers: secrets, engines, restore, browser

Branch: `arena/c6d8fd27-shop-haat-otp-system-ai` · base `31d1815` (main) · PR #16
Date: 2026-11-14
Scope: the four verification gaps left open by report 16 — Phase 1 secrets migration,
Phase 2 MySQL/MariaDB integration, Phase 3 real restore rehearsal, Phase 4 real browser
regression — plus the regression run and this reporting pass.

This report is written from executed output only. Where an environment could not run
something, the row says so and names what is missing. Nothing here is inferred from a
plan, a screenshot or a previous report.

---

## 0. Headline

| Phase | Question | Verdict | Evidence |
|---|---|---|---|
| 1 | Does the secrets migration survive real permission semantics and real failure modes? | **PASSED** — no application defect | `tests/secrets-migration.php` 68/0/0 here, 51/0/13 with `SH_TEST_ASSUME_NO_PERM_ENFORCEMENT=1`; both modes are required CI steps and are green (run `43631797400`) |
| 2 | Does the schema, migration, unique TrxID, concurrency, rollback, locking, OTP replay and idempotency work on real engines? | **PASSED on MySQL 8.0.46 and MariaDB 10.6.28** | `tests/mysql-integration.php` with `SH_TEST_REQUIRE_ENGINE=1` in both CI engine jobs (run `43631797400`), server versions quoted below |
| 3 | Can a real backup actually be restored and does the application read it? | **PASSED** — 23 checks, 0 failures, on MySQL 8.0.46 | rehearsal step of run `43631797400`; refusal matrix 10/0/2 here |
| 4 | Does the storefront and admin panel work in a real browser against a real database? | **PASSED** — 50 checks, 0 failures | Chromium 131.0.6778.33 against MySQL 8.0.46 + `php -S`, run `43631797400` |

**Verdict of this pass: READY, with the four previously-open blockers closed.** The
conditions that kept the project at CONDITIONALLY READY in report 16 no longer exist as
*unknowns*: each one now has an executed result. What remains is listed in §8 — the
honest residual list (no browser/CSP enforcement against a live HTTPS host, no provider
credentials, no load/performance measurement).

### A correction that matters more than any of the four results

Report 16 and `reports/00-INDEX.md` stated that `tests/mysql-integration.php` "passed as a
required CI step on the real `mysql:8.0` service (run `43621578351`)". **That statement was
wrong.** The suite has never connected to an engine until this pass. It reported

```
engine tested : not connected
PASS 1 · FAIL 0 · BLOCKED 12 · NOT TESTED 0
  BLOCKED   connect to MySQL/MariaDB — connection failed:
            PDO::__construct(): Argument #3 ($password) must be of type ?string, int given
```

The cause was a defect in the harness, not in the application:

```php
// tests/mysql-integration.php, before
$GLOBALS['pass'] = 0;                       // the pass counter …
$pass = (string)getenv('SH_TEST_MYSQL_PASS');   // … and the password, same name
mi_pass('target database is identifiable as disposable', $dbName);  // $GLOBALS['pass'] = 1
$pdo = new PDO($dsn, $user, $pass, …);      // $pass is now the integer 1
```

The first `mi_pass()` replaced the password with the counter, PDO refused the argument, and
the suite — whose exit code only counts `FAIL`, correctly, because a machine without MySQL
must not fail — printed a green tick. Two independent errors compounded: a global-name
collision in the suite, and a *reporting* design in which "BLOCKED everything" exits 0.
Both are fixed: the password is `$dbPass`, and CI sets `SH_TEST_REQUIRE_ENGINE=1`, which
turns a blocked connection on a provisioned runner into exit code 4.

A second, related dishonesty is also fixed. The CI steps used `cmd | tee log`, and a shell
without `pipefail` reports **tee's** exit status, so a suite with failures exited 0. During
this pass two runs went green while carrying `passed 6, failed 2` and `passed 19, failed 4`
in their own output. The steps now set `pipefail` and capture `rc=$?`, and the previous
two runs are quoted in §7 as red evidence, not as successes.

---

## 1. Phase 1 — Secrets migration

Command (both local and CI):

```bash
php tests/secrets-migration.php
SH_TEST_ASSUME_NO_PERM_ENFORCEMENT=1 php tests/secrets-migration.php
```

| Test | Expected | Actual | Status |
|---|---|---|---|
| Atomic create: `.tmp` file written, `chmod 0600`, `rename()` into place | Temp file never visible as the final name; mode 0600; content exact | Passed: `sh_secret_write_file()` writes `$file.<hex>.tmp` under `LOCK_EX`, chmods, renames, unlinks on failure, refreshes the cache | PASSED |
| Refuses a conflicting value for an existing key | Old value untouched, clear message | Passed | PASSED |
| Key whitelist `/^[a-z0-9_]+$/` | Injection-shaped keys refused | Passed | PASSED |
| Replace an existing secret | New value live, old value gone, mode preserved | Passed | PASSED |
| Failed write (target path is a directory, so `rename()` cannot replace it) | Old secret still valid, no temp file left behind, error reported | Passed — this failure is reproducible **as root**, which is why it is the honest test | PASSED |
| Missing config directory | Explained, not a stack trace | Passed | PASSED |
| Ownership/mode verification | 0600 on the file, no world-readable window | Passed; the suite distinguishes "chmod has no effect here" from "the mode is wrong" | PASSED |
| No secret reaches a log or an error message | No plaintext in any captured line | Passed | PASSED |
| Re-runnable | Running twice changes nothing | Passed | PASSED |
| Permission-denied path on a host that enforces POSIX permissions | `cannot-write` path exercised | Local run: **BLOCKED by name** (`SH_TEST_ASSUME_NO_PERM_ENFORCEMENT=1`: this PHP runs as uid 1001 with modes honoured… and the CI security job runs the enforcing variant) | BLOCKED locally, PASSED in CI |

Counts: **68 passed, 0 failed** as a normal account; **51 passed, 0 failed, 13 blocked**
with the non-enforcing switch. Every blocked row names itself and says how to re-run it.

**Application code changed in this phase: none.** `includes/secrets.php` was read line by
line against the failure the reports described (a 42/7 result on a root-like host) and the
seven failures were all environment artefacts: `keep-me` versus `cannot-write`, `0600`
versus `0400`, and cached filesystem state in the test process. The harness now probes
whether permissions can deny a write at all and reports the permission checks **BLOCKED by
name** instead of pretending they passed or failed.

## 2. Phase 2 — MySQL and MariaDB

Command (CI, both engine jobs):

```bash
SH_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=shophaat_staging_test;charset=utf8mb4' \
SH_TEST_MYSQL_USER=shophaat_test SH_TEST_MYSQL_PASS=… SH_TEST_MYSQL_ALLOW_DROP=1 \
SH_TEST_REQUIRE_ENGINE=1 php tests/mysql-integration.php
```

Engines actually used, quoted from the run's own annotation:

```
engine: Server         : 8.0.46 (MySQL)              # mysql:8.0 service
engine: Server         : 10.6.28-MariaDB-ubu2204 (MariaDB)   # mariadb:10.6 service
```

| # | Scenario | Expected | Actual | Status |
|---|---|---|---|---|
| 0 | Connect, refuse a production-looking database, record engine + version | Connects, refuses a name like `orders`, prints version | Connected on both engines; `REFUSED` for non-disposable names (exit 3) | PASSED |
| 1 | Fresh installation from `install/schema.php` | All 50 tables, InnoDB, utf8mb4, no error | Schema created and read back on both engines | PASSED |
| 2 | Upgrade/migration of an existing store | Existing rows survive, additive columns added, idempotent on re-run | Passed | PASSED |
| 3 | Unique transaction id (`uq_payments_transaction_id`) | Duplicate TrxID rejected by the engine, not by PHP | Duplicate-key error raised by the server | PASSED |
| 4 | Concurrent duplicate payment submission | One row wins, the other is refused, no double credit | Two separate connections; one succeeds, one is refused | PASSED |
| 5 | Transaction rollback | Nothing persists after a forced failure | Rows unchanged after rollback | PASSED |
| 6 | `SELECT … FOR UPDATE` row locking | Second connection blocks until commit, then sees the new value | Observed with two live connections | PASSED |
| 7 | OTP creation and verification under concurrency | A code is single-use even when submitted twice at once | One success, one refusal | PASSED |
| 8 | OTP expiry and replay | Expired code refused, replayed code refused | Passed | PASSED |
| 9 | Order ownership | A customer cannot read another customer's order | Refused at the query predicate level | PASSED |
| 10 | Notification idempotency | The same event never sends twice | One insert, second is a no-op | PASSED |
| 11 | Backup metadata | The backup row records engine, version and size | Passed | PASSED |

Exit code 0 with `SH_TEST_REQUIRE_ENGINE=1` means the suite connected (a blocked connection
exits 4) and that no scenario failed (`exit($fail > 0 ? 1 : 0)`).

**What this does not prove:** the engine suite uses two connections inside one PHP process
for the concurrency rows; it does not spin up separate OS processes hammering the same row.
Two live connections do exercise the server's locking and duplicate-key paths, which is the
behaviour at issue, but "no lock contention under 200 concurrent checkouts" is not claimed.

## 3. Phase 3 — Real restore rehearsal

Local refusals (this environment, no MySQL):

```bash
SH_TEST_PHP_BIN=<php> php tests/restore-rehearsal.php
→ Backup restore rehearsal: passed 10, failed 0, blocked 2
```

Real rehearsal (CI, MySQL 8.0.46):

```bash
php tools/restore-rehearsal.php --create-backup --app-check \
  --host=127.0.0.1 --port=3306 --user=root --pass=… \
  --scratch=shophaat_restore_test_<HHMMSS> --live=shophaat_reporting_test
→ Backup restore rehearsal: passed 23, failed 0
```

| Test | Command / mechanism | Expected | Actual | Status |
|---|---|---|---|---|
| Missing `--dump` | `--user=x` | Refused, exit 2 | `REFUSED: --dump=FILE (or --create-backup) is required` | PASSED |
| Dump does not exist | `--dump=/tmp/nope.sql` | Refused, exit 2 | `REFUSED: the dump file does not exist` | PASSED |
| Empty dump | 0-byte file | Fails inspection, summary still prints | `FAIL: the dump is empty or unreadable` | PASSED |
| `.gz` that is not gzip | text file named `.sql.gz` | Refused on magic bytes | `FAIL … gzip magic bytes` | PASSED |
| Dump with no `CREATE TABLE` | SQL with only an `INSERT` | Rejected | `FAIL: the dump contains CREATE TABLE statements` | PASSED |
| Non-disposable scratch name | `--scratch=orders` | Refused | `must contain test/scratch/restore/staging` | PASSED |
| Scratch == live database | `--scratch=shophaat_test --live=shophaat_test` | Refused | `the scratch database is the live database` | PASSED |
| Production-looking name | `--scratch=shophaat_production_test` | Refused | `looks like a production database` | PASSED |
| Invalid characters | `--scratch=bad-name!` | Refused | `letters, digits and underscores` | PASSED |
| `--create-backup` on an uninstalled checkout | copy of the tool under a bare root | Refused | `needs an installed checkout (config/database.php is missing)` | PASSED |
| Exit codes 2/3 distinguishable | child process status | 2 ≠ 3 | BLOCKED here: php-wasm always reports shell status 0; the messages were checked instead. PASSED in CI | BLOCKED locally |
| **Real rehearsal** | `--create-backup --app-check` | Backup, import, verify, app reads it, scratch dropped | 23 checks passed: file integrity, gzip round trip, 54 tables of 54 dumped, `uq_payments_transaction_id` still unique, settings/orders/products/users row counts, a representative order with its items and payment, zero orphan order items, application bootstrapped against the restored copy, scratch dropped | PASSED |

Two defects in the *rehearsal tooling* were found by this pass and fixed:

* The verification compared the restored tables against a hand-written list that included
  `sessions`. That table exists only after the session handler has been used, so the fresh
  CI store never had it — a faithful restore was reported as lossy. The expected list now
  comes from the dump's own `CREATE TABLE` statements (`54 of 54 dumped tables restored`),
  plus a separate check that the core tables the dump carried all arrived.
* The "uninstalled checkout" case ran the installed tool from the real checkout, so on CI
  it created a backup instead of refusing. It now runs a copy of the tool under a bare
  root, which is the only honest way to reproduce "not installed" on an installed machine.

**What this does not prove:** the rehearsal imports into a scratch database on the same
server. A restore onto a *different server version* (e.g. dump from MySQL 8.0 into MariaDB
10.6) is not tested, and the dump format is the application's own `SHOW CREATE TABLE`
output, not `mysqldump`'s.

## 4. Phase 4 — Real browser and mobile regression

```bash
# CI job: mysql:8.0 service + php -S 127.0.0.1:8080 + Playwright 1.49.1 Chromium
php tests/browser/seed.php                  # the application's own installer path
node tests/browser/smoke.mjs
→ Browser regression: 50 passed, 0 failed
→ Base URL: http://127.0.0.1:8080 · Chromium 131.0.6778.33
```

The seeded store is a real install: `schema created: 50 tables`, `storefront products: 29`,
`categories: 7`, `active payment methods: 4`, `administrator account: 1`, two customer
accounts.

| Area | What the browser proved | Status |
|---|---|---|
| Storefront home | 200, product cards rendered from the seeded catalogue, every `<img>` decoded (`naturalWidth > 0`), no console errors, no JS exceptions, no failed requests | PASSED |
| Shop listing | 200, cards rendered | PASSED |
| Product page | 200, main image decoded, thumbnails present for the seeded multi-image product, "next" changes **both** the counter and the main image; a single-image product hides the navigation | PASSED |
| Add to cart → cart | The control exists, the cart lists the added line | PASSED |
| Customer sign-in | The password form (scoped to the form that owns the password field) signs in and `/account.php` still knows the customer on the next request | PASSED |
| Checkout → confirmation | The cart still holds the item, checkout renders its form and `order_token`, "Place Order" lands on `order-success.php` with an order number | PASSED |
| Admin sign-in | Admin shell renders with its navigation | PASSED |
| Admin guard without a session | `/admin/orders.php` shows no order data and answers with a login page or 403 | PASSED |
| Mobile 390×844 | Home, product and cart: no horizontal overflow; header visible; no button off-screen | PASSED |
| CSP | Zero violation reports collected from the browser on every page visited | PASSED |
| Console / requests | No console errors, no JS exceptions, no 4xx/5xx responses on any area | PASSED |

Test defects this pass found and fixed — all three had produced *plausible* results:

1. `the gallery "next" control exists` was asserted on a single-image product. The app
   hides the controls on purpose when there is one picture; the seed now creates a
   two-extra-image product (`product_images`, the same table the admin form writes) and the
   suite checks both behaviours.
2. The sign-in clicked `form button[type="submit"]`.first() — the first submit button on
   `login.php` belongs to the **header search box**, so the "sign-in" was a search that
   landed on a listing page. The assertion (`not on login.php`, no password field) passed.
   Both sign-in flows are now scoped to `form:has(input[name="password"])`.
3. The checkout check accepted any URL containing `checkout.php`, which includes
   `login.php?redirect=checkout.php`. It now requires the real checkout page and reports the
   landing URL, the `<h1>` and the cart count when it is not.
4. `tests/browser/seed.php` did not `require install/demo-data.php`, so a demo install died
   on `Call to undefined function sh_install_demo_data()`, the application printed its error
   page, and the process still exited 0 — the storefront came up empty and eight browser
   checks failed for one cause. The seed now loads the module the installer loads, catches
   the installer's own message, prints it as `ERROR:` and exits non-zero when the catalogue,
   the administrator or the payment routes are missing.

**What this does not prove:** the run is against `php -S`, not Apache/LiteSpeed, so
`.htaccess` rewriting, mod_headers and real HTTPS are not exercised; CSP was observed as
"no violations reported" under the default report-only policy, not under enforcement; and
the order was placed with the manual Cash on Delivery route — the bKash/Nagad/Rocket
"submit the transaction id, wait for manual verification" screen was exercised by
`tests/payment-security.php` at suite level, not in the browser.

## 5. Phase 5 — Regression after the changes

* `php -l` on every PHP file: **243/243 clean** (was 241; `tests/browser/seed.php`,
  `tests/restore-rehearsal.php` and the two edited tools/tests are the difference).
* The local battery results are in §6.
* CI security job (green, run `43631797400`): syntax check of every file, static security
  scan (`--fail-on=high`), `tests/security-regression.php`, the support suites,
  `tests/admin-access-url.php`, `tests/cron-cli-auth.php`, both secrets-migration modes, the
  SMTP/Brevo TLS diagnostics.
* CI engine jobs (green): MySQL 8.0.46 and MariaDB 10.6.28.
* CI rehearsal and browser jobs (green): 23/0 and 50/0.

## 6. Local battery (this environment: PHP 8.5.12 php-wasm, no MySQL, no browser)

Environment: PHP 8.5.12 (php-wasm / Emscripten), uid 1001, **no MySQL server, no browser,
no web server**. Every suite below was executed here; the counts are each suite's own
summary line.

| Suite | Result here |
|---|---|
| `tests/security-regression.php` | 185 passed, 0 failed, 1 skipped (header emission needs a web server) |
| `tests/environment.php` | 61 / 0 |
| `tests/trusted-proxy.php` | 58 / 0 |
| `tests/otp-security.php` | 199 / 0 |
| `tests/payment-security.php` | 53 / 0 |
| `tests/idor-scan.php` | 42 / 0 |
| `tests/uploads.php` | 51 / 0 |
| `tests/backup-inspect.php` | 24 / 0 |
| `tests/backup-scheduler.php` | 49 / 0 |
| `tests/cron-cli-auth.php` | 32 / 0 |
| `tests/csp-readiness.php` | 31 / 0 |
| `tests/rate-limit-failsafe.php` | 34 / 0 |
| `tests/webhooks-and-retries.php` | 39 / 0 |
| `tests/secrets.php` | 36 / 0 |
| `tests/secrets-migration.php` | 68 / 0 / 0 |
| `tests/secrets-migration.php` (`SH_TEST_ASSUME_NO_PERM_ENFORCEMENT=1`) | 51 / 0 / **13 blocked, each named** |
| `tests/admin-access-url.php` | 249 / 0 |
| `tests/support-unit.php` | 58 / 0 |
| `tests/human-support-handoff.php` | 184 / 0 |
| `tests/support-sms-notifications.php` | 110 / 0 |
| `tests/support-sms-transport.php` | 25 / 0 |
| `tests/product-management-integration.php` | 169 checks / 0 failures / 16 cases |
| `tests/product-management-render.php` | 16 rendered cases (fixtures) |
| `tests/restore-rehearsal.php` | 10 passed, 0 failed, 2 blocked (see §3) |
| `tests/mysql-integration.php` | **BLOCKED**: 13 scenarios, no engine on this machine (green on MySQL 8.0.46 and MariaDB 10.6.28 in CI) |
| `tests/email-system.php`, `tests/support-system.php`, `tests/product-ai-save.php`, `tests/seo-content-length.php`, `tests/courier-connection-tests.php`, `tests/reporting-integration.php`, `tests/installer-demo-data.php` | need a database; they render the application's error page here and are executed in CI |

**Total executed locally: 1,767 checks · 0 failures.** That is the sum of the 21 suites
that print a numeric summary (1,598, including the secrets suite's 68) plus
`tests/product-management-integration.php`'s 169. The secrets suite's second mode (51 with
13 blocked) re-runs the same checks under a different host model and is not added again.
The blocked rows above are named individually, with what would unblock them.

`php -l`: **243/243 files clean**. The static scan runs in CI on every push
(`tools/security-scan.php --fail-on=high`).


## 7. The runs, including the red ones

| Commit | Run | Result | Why it is in this report |
|---|---|---|---|
| `84a77ab` | 43626216352 | browser + rehearsal red | first honest run of the new suites |
| `0ad0621` | 43626679612 | both red, no diagnostics | `bash -e` aborted the step at the pipeline before the diagnostic block ran |
| `de57f54` | 43627088307 | **green, wrongly** | `cmd \| tee` without `pipefail` reported tee's status; the log said `6 passed, 2 failed` |
| `26f23c8` | 43627458336 | **green, wrongly** | log said `rehearsal: passed 19, failed 4` and `engine: PASS 1 · BLOCKED 12 · not connected` |
| `c0f6245` | 43627991965 | honest red | exposed the `$pass`/`$GLOBALS['pass']` collision, the `sessions` table assumption, the uninstalled-checkout case and the empty seed |
| `90930b0` | 43629416522 | seed red | `ERROR: the installer refused to finish: Call to undefined function sh_install_demo_data()` |
| `af02164` | 43629688406 | browser red | 29 products seeded; gallery and checkout failures |
| `1db1c64` | 43631165563 | all four green | browser 50/0, rehearsal 23/0 |
| `81205f7` | 43631797400 | all four green, engines named | MySQL 8.0.46, MariaDB 10.6.28, browser 50/0, rehearsal 23/0 |

The two "green, wrongly" rows are kept deliberately: they are the reason the pipeline now
runs under `pipefail` and the reason `SH_TEST_REQUIRE_ENGINE=1` exists. A report that only
lists the final green run would hide exactly the failure mode this pass was created to fix.

## 8. What is still not verified (residual, unchanged by this pass)

| # | Item | Status | Why |
|---|---|---|---|
| 1 | CSP **enforcement** under Apache/LiteSpeed over HTTPS | NOT TESTED | Needs a real host; the browser run used `php -S` and the default report-only policy |
| 2 | Real concurrency at scale (hundreds of simultaneous checkouts) | NOT TESTED | The engine suite uses two live connections; load testing needs a load generator |
| 3 | Courier and provider webhooks against the live providers | CREDENTIAL REQUIRED | No merchant credentials here; the signed-webhook contract is covered locally |
| 4 | Performance and page weight | NOT TESTED | No benchmark in this environment; unchanged from report 11 |
| 5 | Restore onto a different engine version (8.0 → 10.6) | NOT TESTED | The rehearsal restores within one server |
| 6 | Payment proof upload in a browser | NOT TESTED in the browser | Covered at suite level by `tests/payment-security.php`; the browser run used Cash on Delivery |

## 9. Verdict

**READY** for the four areas this pass targeted: secrets migration, MySQL 8.0.46,
MariaDB 10.6.28, backup→restore rehearsal, and a 50-check browser regression against a
real database. The two dishonest greens and the false "MySQL passed" claim of report 16 are
corrected above, and the harness that produced them can no longer produce them silently:
`pipefail` propagates a suite's exit code, and a blocked engine on a provisioned runner
fails the job.

R-01 (payment concurrency), R-02 (IDOR over HTTP), R-03/R-14 (engines), R-06 (restore),
R-07 (CSP in a browser) and R-08 (browser regression) are closed or narrowed as recorded in
`SHOP_HAAT_REMAINING_RISKS.md`. The residual list in §8 is unchanged and is not a defect
list — it is the set of things this hardware cannot prove.
