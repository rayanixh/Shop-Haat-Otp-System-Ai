# Phase index — fifteen reports

Branch: `arena/c6d8fd27-shop-haat-otp-system-ai` · base `31d1815` (main) · PR #16
Audited commit range: `31d1815..HEAD`
Date: 2026-11-14

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
| 03 | [Real MySQL execution](03-real-mysql-execution.md) | **NOT EXECUTED** — no MySQL/MariaDB binary in this sandbox |
| 04 | [Backup scheduler](04-backup-scheduler.md) | **FIXED + PASSED** — 49 checks (lock, verify, prune, lease) |
| 05 | [Cron authentication](05-cron-authentication.md) | **FIXED** — CLI form was documented but never authenticated |
| 06 | [Restore rehearsal](06-restore-rehearsal.md) | **PARTLY EXECUTED** — inspector + 24 checks; import needs MySQL |
| 07 | [CSP browser verification](07-csp-browser-verification.md) | **NOT EXECUTED** (no browser) — 31 static checks pass |
| 08 | [Upload security](08-upload-security.md) | **FIXED + PASSED** — 51 checks, validation split from the write path |
| 09 | [Courier and webhooks](09-courier-and-webhooks.md) | **PASSED** — 39 checks (signature, TLS, replay, redaction) |
| 10 | [Notification retry](10-notification-retry.md) | **PASSED** — claim/retry contract verified |
| 11 | [Performance](11-performance.md) | **NOT EXECUTED** (no load generator) — static review only |
| 12 | [Rate-limit fail-safe](12-rate-limit-fail-safe.md) | **FIXED + PASSED** — 34 checks, fail-closed for secrets |
| 13 | [Secret migration write failure](13-secret-migration-write-failure.md) | **FIXED + PASSED** — 49 checks |
| 14 | [Full browser regression](14-browser-regression.md) | **NOT EXECUTED** — no browser in this sandbox |
| 15 | [Final integration](15-final-integration.md) | **PASSED** — 1,528 checks, 237/237 files lint clean |

## Environment facts behind the "NOT EXECUTED" rows

Recorded once, referenced by the reports that depend on them:

```
$ .tmpharness/node_modules/.bin/php-wasm-cli -v
PHP 8.4 (php-wasm build; no web server, no MySQL client/server, no browser)

$ command -v mysql mariadb mysqld
(no output — no MySQL/MariaDB anywhere in the sandbox)

$ php -r 'var_dump(is_executable(PHP_BINARY));'
bool(false)   // php-wasm reports a temporary binary that a subprocess cannot run
```

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
| `install/migrations/2026-11-payment-transaction-unique.sql` (+ rollback) | TrxID uniqueness for existing stores |
| `reports/00-INDEX.md` … `reports/15-final-integration.md` | These reports |
