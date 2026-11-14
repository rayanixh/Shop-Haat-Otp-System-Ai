# 16 — Final verification and production decision

**Verdict: CONDITIONALLY READY.**

Branch `arena/c6d8fd27-shop-haat-otp-system-ai` · PR #16 · base `31d1815` (main)
Date: 2026-11-14

A verdict of PRODUCTION READY is not available here, and not because anything was found
broken. The rule for this pass was that no critical security or data-integrity test may be
left unverified — and three of them cannot be run in this sandbox at all: concurrent MySQL
behaviour, a real restore into a scratch database, and browser enforcement of CSP. They are
listed below with exactly what is needed to close each one. Everything that *could* be
executed was executed, and everything that could not is labelled BLOCKED or NOT TESTED
rather than assumed.

---

## 1. What was executed in this pass

All numbers are measured output, not estimates. `SH_TEST_PHP_BIN` points at the node CLI
(`.tmpharness/node_modules/.bin/php-wasm-cli`), which is what makes the subprocess-based
suites runnable here.

| Suite | Result |
| --- | --- |
| `tests/otp-security.php` | 199 / 0 |
| `tests/security-regression.php` | 185 / 0 (+1 skipped: header emission, N/A in CLI) |
| `tests/human-support-handoff.php` | 184 / 0 |
| `tests/product-management-integration.php` | **169 / 0** (16 cases) |
| `tests/support-sms-notifications.php` | 110 / 0 |
| `tests/environment.php` | 61 / 0 |
| `tests/trusted-proxy.php` | 58 / 0 |
| `tests/support-unit.php` | 58 / 0 |
| `tests/payment-security.php` | 53 / 0 |
| `tests/uploads.php` | 51 / 0 |
| `tests/secrets-migration.php` | 49 / 0 |
| `tests/backup-scheduler.php` | 49 / 0 |
| `tests/idor-scan.php` | 42 / 0 |
| `tests/webhooks-and-retries.php` | 39 / 0 |
| `tests/secrets.php` | 36 / 0 |
| `tests/rate-limit-failsafe.php` | 34 / 0 |
| `tests/cron-cli-auth.php` | **32 / 0** (new) |
| `tests/csp-readiness.php` | 31 / 0 |
| `tests/support-sms-transport.php` | 25 / 0 |
| `tests/backup-inspect.php` | 24 / 0 |
| `tests/admin-access-url.php` | **249 / 0** (240 + 9 BLOCKED without a runnable binary) |
| `tests/product-management-render.php` | 16 rendered-template cases produced |
| **Total** | **1,738 passed · 0 failed** |

Static gates, both re-run at the end of this pass:

```
$ php -l every PHP file (tracked + new)
lint failures: 0 of 241

$ php tools/security-scan.php
No findings.  critical 0  high 0  medium 0  low 0  (files scanned: 189)
```

Environment, measured:

```
PHP 8.5.12 (php-wasm / Emscripten, wasm32) · uid 1001 (not root)
pdo_mysql: loaded, but its socket layer traps the process on connect
no mysql | mariadb | mysqld client or server · no web server (php -S refuses)
no browser (no chromium/firefox; no CDN access to fetch one)
outbound network: github.com, codeload.github.com, api.github.com, registry.npmjs.org, pypi.org
```

---

## 2. Defects found in this pass, and what was changed

Five findings. One is an application defect, two are defects in the *tests* (which is why
they matter: a wrong test is how a real regression gets missed or a false alarm gets
believed), and two are verification gaps closed with new code.

### D-16-1 · `cron/backup.php` exited silently with status 0 when the store was not set up

`sh_require_installed()` answered "not installed" with `header('Location: …'); exit;`. In a
CLI process a Location header does nothing, so the documented cron line produced **no output
and exit 0** — a scheduled backup that never ran looked successful in the cPanel cron log,
which is the one place an operator would look.

*Fixed in* `config/config.php`: a CLI branch writes the cause and next step to STDERR and
exits non-zero.

```
$ php cron/backup.php --token=x
Shop Haat: cannot run from the command line — config/database.php is missing. Finish the web
installer, then run this command again.
```

*Verified:* `tests/cron-cli-auth.php` §8 runs the real entry point in a child process and
asserts the message, that it names the cause, and that there is no fatal error. The shell
status could not be observed (php-wasm always returns 0); on a host `exit(1)` is honoured.

### D-16-2 · Nine checks were reported as FAIL for a missing dependency

`tests/admin-access-url.php` spawned PHP subprocesses through `PHP_BINARY` without checking
that the binary could run. Under php-wasm `is_executable(PHP_BINARY)` is `true` while the
process cannot start, so nine checks read as security **failures** in the previous reports.

*Fixed with* `tests/_support_php_bin.php` (verifies a candidate by executing it; honours
`SH_TEST_PHP_BIN`) and a BLOCKED reporter in `sh_test_run()`. Measured:

```
without SH_TEST_PHP_BIN : passed 240, failed 0, blocked 9
with    SH_TEST_PHP_BIN : passed 249, failed 0
```

### D-16-3 · A stale string assertion turned CI red at `e65b2a7`

`product-management-integration.php` (P05) asserted the upload size check by matching the
literal `$file['size'] > $maxBytes`. The upload hardening in `ac63117` moved that check into
`sh_upload_validate_image()` as `$maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes` —
same behaviour, stronger code — and the literal stopped matching. The suite exits non-zero,
so the job failed at `e65b2a7` while the application was fine.

*Fixed, not relaxed:* the assertion now calls the shipped validator with an oversize file and
with a real 1×1 PNG exactly at the limit, and separately asserts the write path delegates to
that validator. `169 checks, 0 failures`.

### D-16-4 · No executable MySQL/MariaDB suite existed

Report 03 described the staging procedure in prose. `tests/mysql-integration.php` now runs it:
eleven engine scenarios (fresh install, upgrade/migration including the duplicate-history
ALTER, unique TrxID, concurrent duplicate submission, rollback, `SELECT … FOR UPDATE`, OTP
issue/verify/replay/expiry, order ownership, notification idempotency, backup metadata), each
printing PASS/FAIL/BLOCKED/NOT TESTED, with the engine and version printed at the top.

It refuses to touch anything it cannot identify as disposable: the DSN must name a database,
the name must contain test/staging/scratch/ci, prod/production/live/main/master is refused
outright, dropping needs `SH_TEST_MYSQL_ALLOW_DROP=1`, and an existing `config/database.php`
is only reused when it points at the same database *and* user. All four refusals were
executed here and print `REFUSED: …`.

Wired into the MySQL 8.0 CI job as its last step (`continue-on-error: true` until it has one
green run, then flip that line so a regression fails the build).

### D-16-5 · The restore rehearsal was a checklist, not a procedure

`tools/restore-rehearsal.php` performs it: dump integrity (gzip magic, application header,
CREATE TABLE count, statement count) *before* creating anything, a safety gate on the scratch
database name, a streamed `gunzip | mysql` import with the password in `MYSQL_PWD`, then
verification of every required table, the unique TrxID index, row counts, a representative
order with its items and payment, and an orphan check — then it drops the scratch database.
Step 3 is **BLOCKED** here (no client, no server); it was not marked PASSED.

Also added: `tests/cron-cli-auth.php` (executes the shipped cron token parser with real argv,
`$_GET` and `$_SERVER`, including the documented command parsed out of the deployment
checklist) and the BLOCKED category in `sh_test_run()`.

---

## 3. Phase-by-phase status

| Phase | Status here | Evidence / why not |
| --- | --- | --- |
| 1. Report reconciliation | **DONE** | Corrected `admin-access-url` 240→249/0 and totals 1,528→1,738, lint 237→241; see §5 |
| 2. Safe MySQL/MariaDB suite | **BLOCKED** (suite shipped, refusals executed) | No server; `pdo_mysql` traps under wasm. Real MySQL 8.0 evidence exists from CI's pre-existing steps |
| 3. Session IDOR | **PARTLY EXECUTED** | 42-check route scan passes; ownership predicate executed against real rows in `mysql-integration` §9 *design*; HTTP-level A/B session test needs a server → NOT TESTED |
| 4. Backup + restore rehearsal | **PARTLY EXECUTED** | Inspector 24/0, scheduler 49/0; real import into a scratch DB NOT TESTED (no client/server) |
| 5. Cron / shared hosting | **EXECUTED** | 32/0 behavioural; CLI message fixed; HTTP surface NOT TESTED (no server) |
| 6. Payment lifecycle | **PARTLY EXECUTED** | 53/0 contract suite; `reporting-integration.php` drives submit→approve→report on MySQL in CI; live concurrency BLOCKED |
| 7. Uploads | **EXECUTED** | 51/0 + the validator now executed by the product suite; re-encoding not applied (no GD/Imagick) and not mandatory |
| 8. Webhooks / notifications | **PARTLY EXECUTED** | 39/0 with stubbed transports; no provider credentials → live verification NOT TESTED |
| 9. Browser / mobile regression | **NOT TESTED** | No browser, no web server. 16 rendered-template cases are not a browser |
| 10. CSP in a real browser | **NOT TESTED** | 31/0 static readiness; enforcement needs a browser |
| 11. Performance | **NOT TESTED** | No load generator; no numbers invented (static review only) |
| 12. Secrets + rate limiting re-test | **EXECUTED** | 49/0, 36/0, 34/0 as uid 1001, so the unwritable-directory and permission cases are real |
| 13. Report reconciliation | **DONE** | This report plus the corrections in 00, 03, 05, 06, 08, 14, 15 and the root documents |
| 14. Final decision | **DONE** | CONDITIONALLY READY — §6 |

### Phase 12 root causes, stated explicitly

The previously reported failures in the secrets/rate-limit area were **not** application
defects. They came from (a) assertions written against an older code shape, and (b) permission
tests whose meaning depends on the user running them. Re-run here as uid 1001 — a normal
user, not root, so `chmod` genuinely denies — the suites pass: 49/0, 36/0, 34/0, including the
unwritable folder, the partial-write failure, recovery after `chmod` is fixed, `0600`
permissions, and "the error never contains the secret". No assertion was flipped to reach that.

---

## 4. The database statement

* **MySQL 8.0:** real evidence exists, from the CI job's own MySQL 8.0 service — fresh
  schema, the additive migration, colour-variant keys, checkout/payment/reporting, email
  codes, AI product save, SEO lengths, courier tests and the support system. Those steps were
  green at `007357c`.
* **MariaDB:** **never run.** MySQL and MariaDB differ in `information_schema` details, index
  limits, `NOWAIT` support and duplicate-key behaviour after a rollback. No MariaDB claim is
  made anywhere in these reports.
* **SQLite mirrors** (used by most suites here) prove PHP logic, not engine behaviour. Every
  report that relies on them says so.
* Two SQLite/MySQL differences are known and are why the engine suite exists: `FOR UPDATE`
  locking and `information_schema`.

To close this: run `tests/mysql-integration.php` once on MySQL 8.0 and once on MariaDB 10.6+
against disposable databases, and attach both outputs.

---

## 5. Report reconciliation

| Document | What changed |
| --- | --- |
| `reports/00-INDEX.md` | Reconciliation banner (three stale numbers), corrected environment facts, phase table, files-added table |
| `reports/03-real-mysql-execution.md` | The eleven-scenario suite, its safety gate, the wasm `pdo_mysql` trap, the CI wiring |
| `reports/05-cron-authentication.md` | 32 behavioural checks; the CLI silent-exit defect and fix |
| `reports/06-restore-rehearsal.md` | `tools/restore-rehearsal.php`, its six steps, what was executed vs BLOCKED |
| `reports/08-upload-security.md` | The stale P05 assertion replaced with real execution (D-16-3) |
| `reports/14-browser-regression.md` | 240/9 corrected to 249/0, and why the nine were misreported |
| `reports/15-final-integration.md` | Totals 1,528 → 1,738; lint 237 → 241; two suites added |
| `SHOP_HAAT_PRODUCTION_READINESS_REPORT.md` | Numbers refreshed, verdict aligned to this report |
| `SHOP_HAAT_REMAINING_RISKS.md` | R-01/R-02 re-scoped, R-16-* added |
| `SHOP_HAAT_DEPLOYMENT_CHECKLIST.md` | MySQL suite, restore rehearsal and the cron CLI message added |
| `SHOP_HAAT_SECURITY_REMEDIATION_REPORT.md` | This pass's fixes appended |

Every claim in the shipped reports is now one of: executed here (with its output), executed in
CI (with the job and commit), shipped-code fact, or explicitly BLOCKED/NOT TESTED with the
reason and the procedure to close it.

---

## 6. Verdict

**CONDITIONALLY READY.**

Ready: the application is functionally complete, lint-clean, free of static-analysis findings,
and its security behaviours are covered by 1,738 executed assertions. Manual bKash/Nagad/Rocket
and COD are intact; no gateway, framework or dependency was added. cPanel compatibility is
preserved (plain PHP, no Node/Redis/SSH requirement, `.htaccess`-based protections, documented
CLI cron form that now actually works). The one application defect found in this pass could
have hidden a failed scheduled backup, and it is fixed.

Not ready — three conditions, in priority order:

1. **Run `tests/mysql-integration.php` on a staging MySQL 8.0 and a MariaDB 10.6+ database**
   and attach both outputs. Until then, concurrency, rollback, row locking and the unique-TrxID
   migration are verified only by design and by CI's older MySQL steps.
2. **Perform one real restore** with `tools/restore-rehearsal.php` into a scratch database, and
   open the restored copy's admin and storefront in a browser.
3. **Do one browser pass with CSP enforced** (customer flow and admin flow) — this is also the
   only thing that can close the responsive/mobile, console-error and broken-image rows.

Until those three are done, the correct statement is: *the code is believed sound, its
security properties are largely executed and measured, and the remaining risk is concentrated
in engine- and browser-level behaviour that no environment in this session could exercise.*
