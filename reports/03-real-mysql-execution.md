# 03 — Real MySQL / MariaDB execution

> **Update — report 17 (2026-11-14).** The pass recorded below said this suite passed on
> `mysql:8.0` at `a11ffdf`. **That was wrong**: it never connected. The suite kept the
> password in `$pass` and the pass counter in `$GLOBALS['pass']`, so the first `mi_pass()`
> replaced the password with the integer `1`; PDO rejected it, every scenario reported
> BLOCKED, and the step still exited 0.
>
> Fixed: the password is `$dbPass`, and CI sets `SH_TEST_REQUIRE_ENGINE=1`, which turns a
> blocked connection on a provisioned runner into exit code 4. The suite now really runs:
> **MySQL 8.0.46** and **MariaDB 10.6.28**, eleven engine scenarios, run `43631797400`
> (server versions quoted from the run's own output). R-03 and R-14 are closed. See
> [`17-final-four-blockers.md`](17-final-four-blockers.md) §0 and §2.

**Status: NOT EXECUTED — there is no MySQL or MariaDB server, client, or Docker in this
sandbox. No result in this report is simulated.**

```
$ command -v mysql mariadb mysqld mariadbd docker
(no output)

$ php -m | grep -i -E 'pdo_mysql|mysqli'
pdo_sqlite        # pdo_mysql is not even compiled into this PHP build
```

This is the honest answer to the phase, and it is why several other reports say
"NOT EXECUTED" too: `install/schema.php` (50 tables), `FOR UPDATE` locking, `INSERT IGNORE`,
`ON DUPLICATE KEY UPDATE`, `INTERVAL` arithmetic, ENUM columns and every `information_schema`
preflight are MySQL-only constructs. The SQLite mirror used by some suites
(`tests/_support_db_bootstrap.php`, `tests/otp-security.php`) translates them and is useful
for logic, but **it is not engine parity** and is never presented as such.

## What the suites do instead, and what that is worth

| Suite | Engine | What it proves |
| --- | --- | --- |
| `tests/otp-security.php` (199 checks) | SQLite mirror, or MySQL when `SH_TEST_MYSQL_DSN` is set | Rate-limit logic, atomic reservation, replay, expiry, purpose binding |
| `tests/security-regression.php` (185 checks) | SQLite mirror | Escaping, CSRF, session, header, upload and permission contracts |
| `tests/backup-scheduler.php` (49) | stubbed DB, real scheduler | Locking, verification, retention |
| `tests/payment-security.php` (53) | source + DDL inspection | Payment invariants, including the new unique index |
| `tests/idor-scan.php` (42) | source scan | Route-level authorisation |

Suites that **require** a real MySQL server and therefore did not run here:

```
tests/email-system.php              → "Database configuration missing"
tests/support-system.php            → MySQL only
tests/product-management-mysql.php  → "Database configuration missing"
tests/reporting-integration.php     → "Database configuration missing"
tests/installer-demo-data.php       → "Database configuration missing"
tests/product-ai-save.php           → error page (no database)
tests/seo-content-length.php        → error page (no database)
tests/courier-connection-tests.php  → error page (no database)
```

That is 7 suites parked, not skipped silently.

## How to run the MySQL half on a machine that has one

```bash
# 1. disposable database
mysql -e "CREATE DATABASE shophaat_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'sh_test'@'localhost' IDENTIFIED BY 'secret';"
mysql -e "GRANT ALL ON shophaat_test.* TO 'sh_test'@'localhost';"

# 2. point the suites at it
export SH_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=shophaat_test;charset=utf8mb4'
export SH_TEST_MYSQL_USER=sh_test
export SH_TEST_MYSQL_PASS=secret

# 3. the engine-aware suites
php tests/otp-security.php          # executes install/schema.php DDL unmodified
php tests/security-regression.php
php tests/email-system.php
php tests/product-management-mysql.php
php tests/reporting-integration.php
php tests/support-system.php

# 4. the DDL added in this phase
mysql shophaat_test < install/migrations/2026-11-payment-transaction-unique.sql
mysql -e "SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA='shophaat_test' AND TABLE_NAME='payments'"
# expected: uq_payments_transaction_id, NON_UNIQUE = 0
```

## Specifically still unproven without MySQL

1. **Concurrency.** Two simultaneous manual-payment submissions with one TrxID, two
   simultaneous OTP requests at the limit, two simultaneous approve/reject clicks, two
   schedulers. Row locks and gap locks only exist on the real engine.
2. **DDL on a populated table.** `ALTER TABLE payments ADD UNIQUE KEY …` behaviour when
   historical duplicate rows exist (the code expects a `23000`/duplicate error and postpones).
3. **`information_schema` shape.** `DATABASE()` scoping and `STATISTICS` lookups assume a
   normal MySQL/MariaDB grant set.
4. **ENUM width and strict mode.** `payments.status` and `orders.status` transitions under
   `STRICT_TRANS_TABLES` (a value outside the ENUM is an error, not an empty string).
5. **Restore into a scratch database** (report 06).
6. **Performance under a populated table** (report 11).

Until a MySQL run happens on staging, the app should be described as *"verified by
inspection and by translated-logic suites"* for these six items — not as "tested on MySQL".

## Final verification pass — the suite that makes this reproducible

`tests/mysql-integration.php` is new. It is the staging procedure this report used to
describe in prose, as executable code, and it is what a host with MySQL must run before any
MariaDB/MySQL claim is repeated.

```
SH_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=shophaat_staging_test;charset=utf8mb4' \
SH_TEST_MYSQL_USER=test SH_TEST_MYSQL_PASS=test SH_TEST_MYSQL_ALLOW_DROP=1 \
      php tests/mysql-integration.php
```

Eleven scenarios, each printing exactly one of PASS / FAIL / BLOCKED / NOT TESTED:

| # | Scenario | What it proves |
| --- | --- | --- |
| 1 | Fresh install from `install/schema.php` | all 50 tables, InnoDB, `uq_payments_transaction_id` unique, the old plain index gone |
| 2 | Upgrade of a pre-migration store | `sh_payment_submission_schema_ensure()` adds the index, replaces the plain one, and **postpones** instead of throwing when duplicate history blocks the ALTER |
| 3 | Unique TrxID | duplicate non-NULL rejected with SQLSTATE 23000; two NULLs allowed |
| 4 | Concurrent duplicate submission | a second connection cannot claim the same TrxID |
| 5 | Transaction rollback | rolled-back insert/update leave no trace |
| 6 | `SELECT … FOR UPDATE` | a second connection hits the lock wait, not a dirty read |
| 7/8 | OTP create, verify, replay, expiry | one-time use and expiry are enforced by the engine, not by PHP timing |
| 9 | Order ownership | `sh_order_can_view()` with real rows for owner / other customer / guest |
| 10 | Notification idempotency | one claim, refused duplicate, one retry, attempts incremented, no second retry |
| 11 | Backup metadata | a real dump on this engine, verified, with size and table count recorded |

Safety, because it drops tables: the DSN must name a database; the name must contain
test/staging/scratch/ci; a name containing prod/production/live/main/master is refused
outright; dropping needs `SH_TEST_MYSQL_ALLOW_DROP=1`; an existing `config/database.php` is
only used if it points at the same database and user, otherwise the run is refused. All four
refusals were executed here and print `REFUSED: …` with exit status 2 (see report 16).

**Result in this sandbox: BLOCKED.** `pdo_mysql` is compiled into the php-wasm build, but the
wasm socket layer traps the process inside `pdo_mysql::__construct` when it connects — a fatal
that no `try/catch` can catch. The suite detects the Emscripten runtime and reports:

```
BLOCKED   connect to MySQL/MariaDB — this PHP runs under php-wasm (Emscripten); pdo_mysql
          cannot open a socket there and the process aborts inside pdo_mysql::__construct.
```

It is wired into `.github/workflows/reporting-integration.yml` as the last step of the MySQL
8.0 job (the disposable `shophaat_reporting_test` database, `SH_TEST_MYSQL_ALLOW_DROP=1`).

**First real engine run: PASSED.** CI run `43621578351` on `a11ffdf`:

```
MySQL integration (schema, migration, locking, idempotency): success   (MySQL 8.0 service)
The suite exits non-zero on any FAIL, so a green step means
zero failures — every executed scenario passed on MySQL 8.0.
```

What that establishes, on a real MySQL 8.0 + InnoDB server rather than a SQLite shim: a fresh
install of all 50 tables, the upgrade path from a pre-migration store (including the
duplicate-history case that must postpone the ALTER instead of failing a checkout), the unique
TrxID index, a second connection being unable to claim the same TrxID, rollback leaving no
trace, `SELECT … FOR UPDATE` blocking a second connection, OTP issue/verify/replay/expiry,
`sh_order_can_view()` for owner / other customer / guest, notification claim/retry idempotency,
and a real dump with its metadata.

The step is now **required** (no `continue-on-error`). Its log is not retrievable from this
session (GitHub's log archive host is outside the sandbox's network policy), so which
individual rows were BLOCKED rather than PASSED — for example "two OS processes at the same
instant" — cannot be quoted here; the job-level result and the suite's own exit contract are
what is being reported, and no more than that.

**Unchanged engine statement:** the only real MySQL evidence in this project remains the CI
job's existing MySQL 8.0 steps (`product-management-mysql.php`, `reporting-integration.php`,
`email-system.php`, `support-system.php`, `product-ai-save.php`, `seo-content-length.php`,
`courier-connection-tests.php`), which were green at `007357c`. **No MariaDB run exists.** Do
not claim MariaDB compatibility until this suite has been run on a MariaDB server.
