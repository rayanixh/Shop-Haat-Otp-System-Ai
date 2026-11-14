# 13 — Secret migration write failure

**Status: FIXED (dead end → guided recovery, audited) · PASSED (49 checks)**

```
$ php tests/secrets-migration.php
passed 49   failed 0
SECRETS MIGRATION SUITE: ALL CHECKS PASSED
```

"Move the OTP pepper out of the database" writes `config/secrets.php`. On shared hosting that
file's folder is frequently not writable by PHP, so the interesting question is not "does the
move work" but "what happens when the write fails".

## Before

`sh_secret_write_file()` already failed safely (atomic temp file + `rename`, `0600`, refuses to
overwrite a different value, refuses a file it cannot parse). What it did **not** do was tell
the operator anything useful: the panel showed the raw error, wrote no audit entry, and offered
no next step. The pepper was safe — the person was stuck.

## The failure contract, now executed and pinned

| Situation | Behaviour | Check |
| --- | --- | --- |
| normal write | file created `0600`, source becomes `config/secrets.php`, value resolves immediately (cache invalidated) | `the file is readable only by the account (0600)` |
| same value written twice | succeeds (idempotent), no temp file left | `re-running with the same value is idempotent` |
| different value already present | refused before the filesystem is touched: *"config/secrets.php already defines this secret. Edit the file to change it."* | `a different value is refused even when the folder is unwritable` |
| folder not writable | refused with *"The config folder is not writable by PHP."*; **no file created**, no temp file, existing pepper still resolves | `an unwritable config folder is reported as a failure` |
| folder becomes read-only with a pepper in place | refused; the file is byte-identical afterwards; the pepper keeps working | `the old pepper still resolves during and after the failure` |
| existing file does not parse | refused, file untouched byte for byte, `sh_secret()` returns nothing instead of crashing | `a broken file is left alone` |
| target file read-only but folder writable | `rename()` succeeds (POSIX needs folder write access); permissions re-tightened to `0600` | `the replacement file keeps 0600 permissions` |
| value contains `'` or `\` | escaped in the emitted PHP and round-trips exactly | `it round-trips exactly` |
| a key that is not `lower_snake_case` | dropped rather than emitted | `a key that is not lower_snake_case is dropped` |
| after a failure | the pepper in use is **unchanged**, so rate-limit keys and every existing limit survive | `the pepper in use is unchanged by a failed write` |
| recovery | fixing permissions and pressing the button again succeeds | `the migration succeeds once permissions are fixed` |

Failure messages never contain the secret, the existing secret, or any part of either.

## What the admin now sees

On failure `admin/security.php`:

* writes a security audit row `otp_pepper_move_failed` (with the reason) and an application log
  line;
* flashes an error that says **nothing was changed, the current pepper is still in use, and
  verification plus its rate limits keep working**;
* gives the manual route: copy the value from the `settings` row (`otp_rate_pepper` — never
  printed on the page) into `config/secrets.php` as
  `return ['otp_rate_pepper' => '…'];`, `0600`, then reload — the source label changes;
* offers the alternative for hosts that give PHP no write access to `config/`: set
  `SH_SECRET_OTP_RATE_PEPPER` as an environment variable.

No success message can be shown on a failure path (asserted by the suite), and the pepper is
never rendered into HTML (`sh_otp_rate_pepper(true)` appears nowhere in the panel).

## Residual risk

| Item | Note |
| --- | --- |
| The settings row keeps a copy | After a successful move the pepper still exists in the `settings` table until the operator deletes it. The panel says to wait a full daily window (so old rate-limit keys still match) and then remove it. There is no automatic cleanup — a deliberate choice, because deleting it early resets every limit. |
| Environment-variable route | Needs the host's PHP environment panel; the app cannot set it (`HOSTING LIMITED`). |
| Rotation | `sh_otp_rate_previous_peppers()` keeps rotated peppers counted for the active window, but a rotation is a manual, documented operation, not a button. |
