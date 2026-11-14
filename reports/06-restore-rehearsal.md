# 06 — Restore rehearsal

**Status: PARTLY EXECUTED.** The file-integrity half runs here (24 checks pass). The
"import into a scratch database and compare" half **needs MySQL** and is written out as an
exact procedure instead of being claimed.

```
$ php tests/backup-inspect.php
passed 24   failed 0
BACKUP INSPECTOR SUITE: ALL CHECKS PASSED
```

## Why the first half matters more than it sounds

The failure everyone actually experiences is not "the restore script is wrong" — it is
"the backup was never complete". A dump that was cut short by a timeout, a `.gz` that was
never gzipped, or a file left behind by a crashed run all look like backups in a directory
listing. `tools/backup-inspect.php` refuses them before an operator imports garbage over a
live database.

```bash
php tools/backup-inspect.php storage/backups/shophaat-20261114-031500-ab12cd34ef.sql.gz
```

```
Backup inspection: USABLE
------------------------------------------------------------
file            : shophaat-20261114-031500-ab12cd34ef.sql.gz
size            : 1,048,576 (gzip)
uncompressed    : 5,242,880 bytes
tables          : 50
  users, products, orders, payments, categories, order_items, …
INSERT groups   : 812
DROP TABLE      : 50
FK guard paired : yes
```

### What it refuses (each one is a test)

| Condition | Message |
| --- | --- |
| missing / empty file | "The file is empty." |
| `.gz` without gzip magic | "This file is named .gz but is not a gzip stream." |
| stream that cannot be read | "the file is corrupt or truncated" |
| missing ShopHaat header | "this does not look like a ShopHaat backup" |
| no `CREATE TABLE` | "restoring this file would create nothing" |
| `FOREIGN_KEY_CHECKS=1` never restored | "the import would leave the session inconsistent" |
| missing end-of-backup marker | "the file was probably cut short" |
| NUL bytes | "it is not a plain SQL text file" |

Warnings (not failures): no `SET NAMES`, no `FOREIGN_KEY_CHECKS=0`, DROP/TABLE count
mismatch, and a pre-launch store whose dump legitimately has no `orders`/`payments` rows.

The tool is read-only: it never writes into the backup folder (asserted by a hash comparison
in the suite).

## The half that needs MySQL — run this on staging

```bash
# 0. inspect first, always
php tools/backup-inspect.php /path/to/shophaat-….sql.gz

# 1. scratch database, never the live one
mysql -e "CREATE DATABASE shophaat_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. import
gunzip -c /path/to/shophaat-….sql.gz | mysql shophaat_restore
#   (or, uncompressed: mysql shophaat_restore < shophaat-….sql)

# 3. prove the schema arrived
mysql -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA='shophaat_restore'"          # expect 50

# 4. compare row counts against the source, table by table
for t in users products orders order_items payments settings; do
  a=$(mysql -N -B -e "SELECT COUNT(*) FROM shophaat.$t")
  b=$(mysql -N -B -e "SELECT COUNT(*) FROM shophaat_restore.$t")
  printf '%-14s source=%-8s restored=%-8s %s\n' "$t" "$a" "$b" "$([ "$a" = "$b" ] && echo OK || echo MISMATCH)"
done

# 5. prove the *new* unique index survived the round trip
mysql -e "SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA='shophaat_restore' AND TABLE_NAME='payments'
            AND INDEX_NAME='uq_payments_transaction_id'"        # NON_UNIQUE = 0

# 6. point a disposable copy of the app at shophaat_restore (config/database.php),
#    log in as an admin, open Admin → Dashboard, Admin → Orders, and one product page.
#    A restore that imports cleanly but renders a 500 is not a restore.

# 7. drop it
mysql -e "DROP DATABASE shophaat_restore;"
```

## What is still unproven, stated plainly

* The import itself, the row-count comparison, and the render check **have not been run** in
  this environment (no MySQL, no web server). They are steps for staging.
* Step 6 is the one people skip. A dump can import without errors and still be useless if a
  later migration (`sh_payment_submission_schema_ensure()`, `sh_otp_schema_ensure()`) has to
  re-add a column that the dump predates. Both helpers are lazy and self-healing, which is why
  the app should still render — but that is a design argument, not a test result.
* The rehearsal should be repeated **after** the phase-01 migration, so the restored store
  contains `uq_payments_transaction_id` — otherwise the very next payment submission will
  re-add it, which is fine but hides whether the dump captured it.
