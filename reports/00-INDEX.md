# Phase index — fifteen phase reports plus the final verification

Branch: `arena/c6d8fd27-shop-haat-otp-system-ai` · base `31d1815` (main) · PR #16
Audited commit range: `31d1815..HEAD`
Date: 2026-11-14

> **Reconciled in the final verification pass (report 16).** Three numbers in the
> earlier reports were wrong and are corrected here and in the reports themselves:
>
> | Claim | Status | Corrected fact |
> | --- | --- | --- |
> | `tests/admin-access-url.php` — "240 passed, 9 could not run" (reports 14, 15) | **stale** | 249/0 when a runnable PHP binary is supplied (`SH_TEST_PHP_BIN`); without one it is 240 passed + **9 BLOCKED**, and the suite now says so instead of failing |
> | "1,528 checks green" (report 15) | **stale total** | **1,738 passed · 0 failed** across 21 PHP suites (1,537 before the two new suites), plus 16 rendered-template cases |
> | "237/237 files lint clean" | **stale count** | **241/241** PHP files lint clean (four files added since: three tests and one tool) |
>
> No other claim in reports 01–15 changed. Report 16 records the five defects found
> and fixed in this pass, everything that was re-executed, and the final verdict.
>
> **New engine evidence:** `tests/mysql-integration.php` passed as a required CI step on the
> real `mysql:8.0` service (run `43621578351`, commit `a11ffdf`) — see report 03. MySQL
> concurrency, rollback, row locking and the unique-TrxID migration are therefore verified on
> MySQL 8.0, not inferred. MariaDB remains untested (R-14).

Every report states what was **executed** in this environment and what was **not**, and
why. Nothing below is inferred from a screenshot, a plan, or an assumption: a claim is
either backed by a command whose output is quoted, by the shipped code, or it is marked
`NOT EXECUTED`.

## Status vocabulary

| Status | Meaning |
| --- | --- |
| **PASSED** | A suite ran here and every check it owns succeeded. |
| **FIXED** | Code was changed in this phase because the audit found a real defect. |
| **CONFIRMED** | The property was verified by executing the shipped code (not by re-reading it). |
| **NOT EXECUTED** | The environment cannot run it (no MySQL server, no browser, no provider credentials). The report says what is needed. |
| **CREDENTIAL REQUIRED** | Needs a real merchant/courier/mail provider account. |
| **HOSTING LIMITED** | Depends on the host's PHP/Apache configuration; the app can only detect and explain it. |

## The fifteen phases

| # | Report | Headline status |
| --- | --- | --- |
| 01 | [Payment workflow security](01-payment-workflow-security.md) | **FIXED** — DB-level TrxID uniqueness added; app-level checks confirmed |
| 02 | [IDOR / object authorisation](02-idor-object-authorisation.md) | **PASSED** — 42-check scan over every route |
| 03 | [Real MySQL execution](03-real-mysql-execution.md) | **PASSED on MySQL 8.0 in CI** (11 engine scenarios, run `43621578351`); not runnable in this sandbox, MariaDB untested |
| 04 | [Backup scheduler](04-backup-scheduler.md) | **FIXED + PASSED** — 49 checks (lock, verify, prune, lease) |
| 05 | [Cron authentication](05-cron-authentication.md) | **FIXED + CONFIRMED** — the shipped token parser is executed with real argv (32 checks); a CLI run without a database no longer exits silently |
| 06 | [Restore rehearsal](06-restore-rehearsal.md) | **PARTLY EXECUTED** — inspector (24 checks) + the new `tools/restore-rehearsal.php`; the import itself needs a MySQL server |
| 07 | [CSP browser verification](07-csp-browser-verification.md) | **NOT EXECUTED** (no browser) — 31 static checks pass |
| 08 | [Upload security](08-upload-security.md) | **FIXED + PASSED** — 51 checks, validation split from the write path; the product-management suite now executes that validator instead of matching an old expression |
| 09 | [Courier and webhooks](09-courier-and-webhooks.md) | **PASSED** — 39 checks (signature, TLS, replay, redaction) |
| 10 | [Notification retry](10-notification-retry.md) | **PASSED** — claim/retry contract verified |
| 11 | [Performance](11-performance.md) | **NOT EXECUTED** (no load generator) — static review only |
| 12 | [Rate-limit fail-safe](12-rate-limit-fail-safe.md) | **FIXED + PASSED** — 34 checks, fail-closed for secrets |
| 13 | [Secret migration write failure](13-secret-migration-write-failure.md) | **FIXED + PASSED** — 49 checks |
| 14 | [Full browser regression](14-browser-regression.md) | **NOT EXECUTED** — no browser in this sandbox |
| 15 | [Final integration](15-final-integration.md) | **PASSED** — 1,738 checks, 241/241 files lint clean, security scan clean |
| 16 | [Final verification and production decision](16-final-verification.md) | **CONDITIONALLY READY** — see the verdict and the blocked-test list |

## Environment facts behind the "NOT EXECUTED" rows

Recorded once, referenced by the reports that depend on them:

```
$ .tmpharness/node_modules/.bin/php-wasm-cli -r 'echo PHP_VERSION, " ", php_uname();'
8.5.12 Emscripten emscripten 4.0.19 #1 wasm32      (no web server, no browser)

$ command -v mysql mariadb mysqld
(no output — no MySQL/MariaDB anywhere in the sandbox)

$ php -r 'var_dump(php_uname(), extension_loaded("pdo_mysql"));'
string(38) "Emscripten emscripten 4.0.19 #1 wasm32"
bool(true)    // pdo_mysql is compiled in, but the wasm socket layer traps the
              // whole process inside pdo_mysql::__construct when it connects.

$ php -r 'var_dump(is_executable(PHP_BINARY));'
bool(false)   // php-wasm reports a temporary binary that a subprocess cannot run

$ SH_TEST_PHP_BIN="$PWD/.tmpharness/node_modules/.bin/php-wasm-cli" \
      php tests/admin-access-url.php
… passed 249, failed 0        // the node CLI *can* spawn itself; this is the
                              // supported way to run the subprocess-based suites
```

`SH_TEST_PHP_BIN` (read by `tests/_support_php_bin.php`) is the only supported way to
run a suite that needs a child process. It verifies the candidate binary by executing
it, and a suite that cannot get one reports **BLOCKED** — never a failure and never a
pass. `php-wasm` also always returns shell status 0, so process exit codes are not
observable here; on a real host they are.

Outbound network access exists for exactly four hosts (github.com, codeload.github.com,
api.github.com, registry.npmjs.org, pypi.org, files.pythonhosted.org), so no live merchant,
courier, SMS or SMTP provider can be contacted either.

## Files added by this work

| File | What it is |
| --- | --- |
| `tests/payment-security.php` | Payment workflow contract (53 checks) |
| `tests/idor-scan.php` | Object-authorisation scan over every route (42 checks) |
| `tests/backup-scheduler.php` | Scheduler lock/verify/prune/lease (49 checks) |
| `tests/backup-inspect.php` | Dump inspection before a restore (24 checks) |
| `tests/uploads.php` | Upload rejection matrix + web-server guard (51 checks) |
| `tests/webhooks-and-retries.php` | Email webhook, courier, notification retry (39 checks) |
| `tests/rate-limit-failsafe.php` | Fail-open vs fail-closed policy (34 checks) |
| `tests/secrets-migration.php` | "Move the pepper" failure paths (49 checks) |
| `tools/backup-inspect.php` | CLI used by the restore rehearsal |
| `tools/restore-rehearsal.php` | Dump → scratch database → verification → drop, with a safety gate |
| `tests/mysql-integration.php` | The 11 MySQL/MariaDB engine scenarios (staging/CI, refuses non-test databases) |
| `tests/cron-cli-auth.php` | Executes the shipped cron token parser with real argv/$_GET (32 checks) |
| `tests/_support_php_bin.php` | Finds a PHP binary that can actually spawn a child; used to decide PASS vs BLOCKED |
| `install/migrations/2026-11-payment-transaction-unique.sql` (+ rollback) | TrxID uniqueness for existing stores |
| `reports/00-INDEX.md` … `reports/15-final-integration.md` | These reports |
