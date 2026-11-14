# ShopHaat — Production Readiness Report

**Status:** *ready for the four areas that were still open.* Secrets migration,
MySQL **8.0.46**, MariaDB **10.6.28**, the backup→restore rehearsal and a 50→90-check
browser regression are now executed results rather than assumptions. The residual list
(CSP *enforcement* on a real host, provider credentials, load/performance) is stated in
`reports/17-final-four-blockers.md` §8.

Date of this status: 2026-11-14, refreshed in the **final four blockers** pass — see
[`reports/17-final-four-blockers.md`](reports/17-final-four-blockers.md).

> **Correction:** the earlier claim that `tests/mysql-integration.php` "passed on the real
> `mysql:8.0` service at `a11ffdf`" was **wrong** — the suite had never connected. A
> `$pass` / `$GLOBALS['pass']` name collision in the suite made PDO reject the password and
> every scenario reported BLOCKED under a green tick, because a suite that cannot test must
> not fail the build. Fixed: the password is `$dbPass`, and CI sets
> `SH_TEST_REQUIRE_ENGINE=1` so a blocked engine on a provisioned runner exits 4. The
> engine results now quoted are from run `43631797400`, which connects.

---

## 1. What is ready

| Area | State | Evidence |
|---|---|---|
| Bootstrap / error handling | Errors never reach visitors; every log line is scrubbed of secrets | `config/config.php` forces `display_errors=0`; `tests/environment.php` §5 |
| Client IP trust boundary | Forwarded headers believed only from configured proxies | `tests/trusted-proxy.php` 58/58 |
| Deployment environments | development / staging / production, production default, staging gate | `tests/environment.php` 61/61; `tests/otp-security.php` §12 |
| Secrets | Pepper and cron token can live outside the database; rotation preserves history | `tests/secrets.php` 36/36; `tests/otp-security.php` §11 |
| OTP issuing & verification | Hashed codes, atomic single use, phone/IP/daily windows, cooldown, resend cap, fail-closed provider readiness | `tests/otp-security.php` 199/199 |
| Rate-limit storage | Hashed identifiers, append-only, migration + rollback, backfill | `tests/otp-security.php` §1, §8 |
| CSP | Nonce-based, report-only default, verified compatible, admin rollback | `tests/csp-readiness.php` 31/31 |
| Security headers | nosniff, referrer policy, frame-ancestors, permissions policy, HSTS over HTTPS | `tests/security-regression.php` 185/185 (1 skip: header emission, N/A in CLI) |
| Uploads | Content-verified MIME, random names, no executable formats, `.htaccess` deny | code review + `tests/security-regression.php` |
| Static analysis | 0 findings / 189 files | `php tools/security-scan.php` |
| Support/SMS subsystems | Unchanged behaviour after this work | `tests/support-unit.php` 58/58, `tests/support-sms-transport.php` 25/25 |
| PHP syntax | Every PHP file | `php -l` clean (241 files) |

**Total executed in this environment: 1,738 assertions, 0 failures**, across 21 PHP
suites (measured in the final verification pass; the 622 figure above was the state at the
start of the remediation work and is superseded). Static scan: 0 findings / 189 files.

## 2. What is not ready (blocking a "complete" claim)

| Area | Why | Consequence if you deploy today |
|---|---|---|
| Payment integrity audit | Needs a live database for constraint/concurrency tests | Payment logic is unchanged and manual-verification based; risk is unchanged, not increased — but it is unverified |
| IDOR sweep | Order ownership checks not exercised against a real session/DB | Same as above: unverified, not known-broken |
| MySQL / MariaDB verification | Closed | `tests/mysql-integration.php` ran against **MySQL 8.0.46** and **MariaDB 10.6.28** with `SH_TEST_REQUIRE_ENGINE=1` (run `43631797400`): schema, migration, unique TrxID, concurrent duplicate payment, rollback, row locking, OTP concurrency/expiry/replay, ownership, notification idempotency, backup metadata |
| Backup restore rehearsal | Closed for the rehearsed path | `tools/restore-rehearsal.php --create-backup --app-check`: real backup, scratch database, 54/54 tables, indexes, a real order, the application reading the restored copy, scratch dropped — **23 checks, 0 failures** (same run) |
| Browser/functional regression | Closed for `php -S` | `tests/browser/smoke.mjs` in Chromium 131 against MySQL 8.0.46: fifteen storefront steps, twelve admin steps, mobile 390×844, CSP/console/request health, and a replayed checkout POST that must not create a second order |

None of the above is a claim that something is broken — it is a statement that it
has not been *proven*, and this report does not present assumptions as facts.

## 3. Deployment gates

1. §0–§4 of `SHOP_HAAT_DEPLOYMENT_CHECKLIST.md` (backup, files, migration,
   environment, secrets).
2. Configure trusted proxies if the store is behind Cloudflare or a load balancer.
3. Confirm Admin → System Health: environment row green, client-IP policy green,
   OTP provider ready, no missing required extension.
4. Run `SH_TEST_MYSQL_DSN=… SH_TEST_MYSQL_ALLOW_DROP=1 SH_TEST_REQUIRE_ENGINE=1 php
   tests/mysql-integration.php` on a disposable staging database — `REQUIRE_ENGINE` turns
   "could not connect" into a failure, so the step cannot pass without an engine.
5. Rehearse one restore: `php tools/restore-rehearsal.php --create-backup --app-check
   --user=… --pass=… --scratch=… --live=…`; it must end with `REHEARSAL PASSED`.
6. Enforce CSP on staging, browse, then production. Optionally run
   `node tests/browser/smoke.mjs` against staging (CI runs it against `php -S`).

## 4. Verdict

* **Security-critical issues found in this phase:** all fixed and covered by
  executed tests (F-101 Critical, F-102 High, F-103 Medium, F-104 Low,
  F-106 Medium).
* **Known unresolved Critical issues:** none in the code paths audits covered.
* **Known unresolved High issues:** none confirmed; two audited areas
  (payments, IDOR) remain *unverified* — see `SHOP_HAAT_REMAINING_RISKS.md`
  R-01/R-02.
* **Final four blockers pass (2026-11-14):** the three conditions from report 16 are
  discharged — MariaDB 10.6.28 verified, a real restore rehearsed (23/0), and a browser
  pass with CSP violation reporting in place. Seven defects were found and fixed in this
  pass, **six of them in the test harness** that had been reporting success:
  1. `tests/mysql-integration.php` — `$pass` collided with the `$GLOBALS['pass']` counter,
     so PDO received `1` as the password and every engine scenario was BLOCKED under a
     green tick.
  2. CI — `cmd | tee` without `pipefail` reported tee's status, so a suite with failures
     exited 0 (two runs went green carrying `6 passed, 2 failed` and `19 passed, 4 failed`).
  3. `tools/restore-rehearsal.php` — a hand-written table list flagged `sessions` as lost
     though the dump never had it.
  4. `tests/restore-rehearsal.php` — the "uninstalled checkout" case ran the installed tool
     and created a backup instead of refusing.
  5. `tests/browser/seed.php` — missing `require install/demo-data.php` produced an empty
     storefront while exiting 0 (the application's error page was the only clue).
  6. `tests/browser/smoke.mjs` — the sign-in clicked the header search button, the gallery
     assertion assumed a multi-image product, and the checkout check accepted a login
     redirect. All three produced plausible-looking passes.
  7. `tests/mysql-integration.php` — added `SH_TEST_REQUIRE_ENGINE` so this class of
     silent BLOCKED cannot recur in CI.
* **Verdict: READY** for the four areas targeted, with the residual list in
  `reports/17-final-four-blockers.md` §8 (CSP enforcement on a real host, provider
  credentials, load/performance, restoring across engine versions).
* **What this report does not say:** it does not say the application is 100 % secure, and
  it does not claim anything measured on a live provider or under production load.
