# 05 — Cron authentication

**Status: FIXED — the documented command never authenticated · PASSED (checks inside the backup suite)**

## The defect

The deployment checklist told operators to add this to cPanel → Cron Jobs:

```
php /home/USER/public_html/cron/backup.php?token=YOUR_TOKEN
```

Two things are wrong with it, and either one alone is fatal:

1. **PHP does not populate `$_GET` from argv.** On the CLI the query string is not parsed, so
   `$token` was always empty.
2. **PHP would try to open a file whose name contains `?token=…`.** The path does not exist.

So the scheduled backup either exited immediately or ran unauthenticated — and because the
old endpoint only checked `$_GET['token']`, a CLI run could never satisfy it. Backups looked
scheduled and were not being taken.

The second problem is the documentation itself: an operator following the checklist had no
way to make it work.

## What `cron/backup.php` accepts now

| Surface | Accepted forms |
| --- | --- |
| HTTP | `?token=…` · `X-Backup-Token: …` header (keeps the token out of access logs) |
| CLI | `--token=…` · a single positional argument · `SH_BACKUP_CRON_TOKEN` environment variable |

Rules:

* The expected token is resolved through `sh_secret('backup_cron_token')` first (constant,
  environment or `config/secrets.php`) and falls back to the `settings` row, so an existing
  installation keeps working without a migration.
* Comparison uses `hash_equals()` — no timing oracle.
* **Nothing is configured → 403 with guidance**, never "allow". A store that has not generated
  a token cannot be backed up by an anonymous HTTP hit.
* The token is never echoed, never logged, and never included in an error message. The log
  line says `Scheduled backup refused: invalid token (http)`.
* Repeated HTTP failures are rate limited (`sh_file_rate_limit('backup-cron-token', …)`,
  10 per 15 min) and the limiter **fails closed** (report 12): if the counter store is broken,
  the request is refused rather than allowing unlimited guessing.
* Exit codes: `0` success or nothing-to-do, `1` any refusal or failure — so cPanel's cron mail
  and any monitoring see the truth.
* `sh_admin_notify()` records completion and failure in the panel.

## Documentation fixed

`SHOP_HAAT_DEPLOYMENT_CHECKLIST.md` no longer contains the broken form. It now shows both
supported call styles and explains *why* the CLI form needs an argument:

```bash
# PHP CLI (recommended: no web request, no token in a URL)
php /home/USER/public_html/cron/backup.php --token=YOUR_TOKEN

# or, if your host prefers an HTTP call
wget -q -O /dev/null "https://your-domain/cron/backup.php?token=YOUR_TOKEN"
```

The cron hint panel in Admin → Backups shows the same, with the reason inline.

## Checks

From `tests/backup-scheduler.php` §7:

```
ok   the cron endpoint compares tokens with hash_equals
ok   the cron endpoint reads a CLI argument
ok   the cron endpoint reads an environment variable
ok   the cron endpoint never echoes the token
ok   the cron endpoint refuses when no token is configured
ok   the cron endpoint rate limits HTTP token failures
ok   the cron endpoint uses the secret store first
ok   the checklist documents the CLI form with --token
ok   the checklist no longer documents the broken ?token= CLI form
```

## Not executed here, and why

The endpoint cannot be driven end-to-end in this sandbox because `sh_require_installed()`
correctly refuses to run against an uninstalled checkout (it redirects to the installer).
Verifying the real exit codes needs an installed staging store:

```bash
php cron/backup.php                          # exit 1, "no cron token is configured"
php cron/backup.php --token=wrong            # exit 1, "Forbidden."
php cron/backup.php --token=$TOKEN           # exit 0, "OK shophaat-….sql.gz · 1.2 MB"
SH_BACKUP_CRON_TOKEN=$TOKEN php cron/backup.php   # exit 0
curl -s -o /dev/null -w '%{http_code}\n' "https://store/cron/backup.php?token=wrong"   # 403
curl -s -o /dev/null -w '%{http_code}\n' -H "X-Backup-Token: $TOKEN" https://store/cron/backup.php   # 200
```
