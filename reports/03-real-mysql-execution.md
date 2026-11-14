# 03 — Real MySQL / MariaDB execution

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
