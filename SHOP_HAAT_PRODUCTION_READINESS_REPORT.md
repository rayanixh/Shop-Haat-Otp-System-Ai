# ShopHaat — Production Readiness Report

**Status:** *conditionally ready.* The security, OTP, environment and hosting
layers are in a verifiable state; payments, IDOR and the database/performance
audits are **not** finished, and that is stated plainly rather than papered over.

Date of this status: 2026-11-14.

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
| PHP syntax | Every PHP file | `php -l` clean (221 files) |

**Total executed in this environment: 622 assertions, 0 failures.**

## 2. What is not ready (blocking a "complete" claim)

| Area | Why | Consequence if you deploy today |
|---|---|---|
| Payment integrity audit | Needs a live database for constraint/concurrency tests | Payment logic is unchanged and manual-verification based; risk is unchanged, not increased — but it is unverified |
| IDOR sweep | Order ownership checks not exercised against a real session/DB | Same as above: unverified, not known-broken |
| MySQL verification | No MySQL in the sandbox; suites ran on a SQLite shim | Locking semantics are approximated, not proven |
| Backup restore rehearsal | No isolated environment | Backups exist; the restore path is untested |
| Browser/functional regression | No web server or browser here | UI rendering of the new admin panel was not visually inspected |

None of the above is a claim that something is broken — it is a statement that it
has not been *proven*, and this report does not present assumptions as facts.

## 3. Deployment gates

1. §0–§4 of `SHOP_HAAT_DEPLOYMENT_CHECKLIST.md` (backup, files, migration,
   environment, secrets).
2. Configure trusted proxies if the store is behind Cloudflare or a load balancer.
3. Confirm Admin → System Health: environment row green, client-IP policy green,
   OTP provider ready, no missing required extension.
4. Run `SH_TEST_MYSQL_DSN=… php tests/otp-security.php` on staging.
5. Enforce CSP on staging, browse, then production.

## 4. Verdict

* **Security-critical issues found in this phase:** all fixed and covered by
  executed tests (F-101 Critical, F-102 High, F-103 Medium, F-104 Low,
  F-106 Medium).
* **Known unresolved Critical issues:** none in the code paths audits covered.
* **Known unresolved High issues:** none confirmed; two audited areas
  (payments, IDOR) remain *unverified* — see `SHOP_HAAT_REMAINING_RISKS.md`
  R-01/R-02.
* **What this report does not say:** it does not say the application is 100 %
  secure, and it does not say it is fully production-ready while R-01, R-02, R-03
  and R-06 are open.
