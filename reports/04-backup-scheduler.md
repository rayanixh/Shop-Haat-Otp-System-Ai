# 04 — Backup scheduler

**Status: FIXED (four defects) · PASSED (49 checks) · NOT EXECUTED (a real MySQL dump)**

```
$ php tests/backup-scheduler.php
passed 49   failed 0
BACKUP SCHEDULER TEST SUITE: ALL CHECKS PASSED
```

## The defects found, in the order they matter

### 1. The timestamp was stamped before the backup existed

`sh_backup_run_scheduled()` wrote `backup_last_auto = now()` **before** calling
`sh_backup_create()`. A failed night therefore looked successful: the next run was 24 hours
away and the store quietly went days without a backup.

Now: create → verify the file → only then stamp. A failure or an exception leaves the
timestamp untouched, so the next cron run retries immediately.

### 2. Retention ran even when the new backup failed

Pruning after a failed run deletes the previous good backups and leaves the store with the
corrupt one. Retention now runs **only** after a verified success.

### 3. Two cron invocations could both create a backup

There was no lock. Two overlapping runs (a slow dump plus a second hit) produced two files,
two `db_backups` rows, and pruning races.

Now: `flock()` on `storage/backups/.backup-scheduler.lock`. The second caller returns
`['ok' => false, 'skipped' => 'locked']`, which the cron endpoint answers with HTTP 200 and
*"Skipped: another backup is already running"* — a skip is not an error. The lock is
released in a `finally`, so a crashed run cannot leave it held.

### 4. An empty or truncated file was recorded as `completed`

`sh_backup_create()` inserted `status = 'completed'` for whatever file it had written.
`gzopen()` also happily reads an *uncompressed* file, so a dump that was never gzipped passed
the old check.

Now: `sh_backup_verify_file()` rejects a missing/empty file, checks the **gzip magic bytes**
before trusting the stream, and proves the stream still decompresses. On failure the file is
deleted, a `failed` row is recorded with the reason, and the caller gets the reason.

## The retention rules that now hold

| Rule | Check in the suite |
| --- | --- |
| Keep exactly `backup_keep` completed backups | `retention keeps exactly the configured number` |
| Never delete the newest backup | `the newest backup always survives` |
| Never delete a file that is being downloaded | `a backup being downloaded is not deleted` |
| An abandoned download lease (2 h) does not block pruning forever | `an abandoned lease (2 hours old) does not block retention forever` |
| Delete the `db_backups` row only after `unlink()` succeeded | `a deleted file has no leftover record` |
| `failed` rows are not counted as retained backups | `failed rows are ignored by retention` |

The download lease is what makes the two features compatible: `admin/backups.php` holds a
lease around `readfile()`, and pruning skips leased files. Without it, retention could delete
the file a browser was mid-download of.

## Recovery behaviour that is now explicit

| Failure | Timestamp | Retention | Result to the caller |
| --- | --- | --- | --- |
| creator returns `ok=false` | unchanged | skipped | `['ok' => false, 'error' => …]` |
| creator throws (disk full, crash) | unchanged | skipped | failure, lock released |
| file missing after "success" | unchanged | skipped | failure with the verify reason |
| file empty | unchanged | skipped | "The backup file is empty." |
| `.gz` but not gzip | unchanged | skipped | "…is not a gzip file." |
| lock held by another run | unchanged | n/a | `skipped: locked` |
| success | stamped | runs | `['ok' => true, 'file' => …, 'size' => …]` |

## What is not proven here

* **A real dump.** `sh_backup_create()` uses `SHOW FULL TABLES`, `SHOW CREATE TABLE` and
  `SELECT DATABASE()` — MySQL only. No MySQL server exists in this sandbox, so the suite
  injects a creator stub and exercises the scheduler around it. The dump writer itself is
  reviewed, not executed (see report 03 for how to run it on staging).
* **A multi-gigabyte dump.** The verify step reads the first 64 KB; it does not prove the
  tail of a very large file. Report 06's inspector does (it reads the whole stream).

## Operator checklist

```bash
# 1. schedule it (see report 05 for the auth form)
php /home/USER/public_html/cron/backup.php --token=YOUR_TOKEN

# 2. confirm what happened
ls -lh storage/backups/
# and Admin → Backups shows the row as "completed", or "failed" with the reason

# 3. before you restore, inspect the file
php tools/backup-inspect.php storage/backups/shophaat-….sql.gz
```
