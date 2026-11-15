# 12 — Rate-limit fail-safe

**Status: FIXED (the limiter silently allowed everything when its store broke) · PASSED (34 checks)**

```
$ php tests/rate-limit-failsafe.php
passed 34   failed 0
RATE LIMIT FAIL-SAFE SUITE: ALL CHECKS PASSED
```

## The defect

`sh_file_rate_limit()` returned `true` on every storage failure: no directory, an unwritable
counter file, a failed lock, a failed write, any exception. The docblock called that
deliberate ("a broken log directory must not lock the owner out of repair"), and for flood
protection that is the right call.

For a limiter guarding a **secret** it is not. An attacker who can break the counter store —
fill the disk, force `logs/` read-only — removes the throttle entirely and gets unlimited
guesses at the installer unlock token or the backup cron token. The limiter's failure mode was
the attack.

## The fix: the policy is per surface, not global

`sh_file_rate_limit($bucket, $key, $max, $window, $dir = '', bool $failClosed = false)`

| Surface | Policy | Why |
| --- | --- | --- |
| `install-unlock` | **fail closed** | brute-force protection of a secret |
| `backup-cron-token` | **fail closed** | brute-force protection of a secret |
| `csp` (violation collector) | fail open | telemetry must never be dropped because a counter file is missing |
| `email_webhook` | fail open | a mail provider retrying a webhook must not be refused |

Failing closed cannot lock the owner out of the installer: the throttle is only consulted for
a **wrong** token, so a correct token always works (`$shUnlockThrottled = !$shUnlockOk && …`).

Every refusal writes one line to the security log naming the bucket and the reason
("cannot open the counter file", "counter error: …"), never the token — so a store that suddenly
refuses installer logins has an explanation instead of a mystery.

## Checks

| Section | What it proves |
| --- | --- |
| 1 | A working limiter still counts, blocks the 4th attempt at max 3, isolates keys, resets a stale window, survives a corrupted counter file, and cannot be made to write outside its directory by a `../../etc/passwd` bucket name |
| 2 | Broken storage on flood-protection buckets → allowed, and **not** spammed into the log |
| 3 | Broken storage on secret buckets → refused, logged once, message never says "token" |
| 4 | The endpoints are wired to the right policy (install unlock and cron pass `failClosed: true`; CSP and email do not) |
| 5 | The OTP limiter fails closed when its own store is unavailable (`'unavailable'`, generic message, no "allow" fallback) |
| 6 | A refusal does not touch the counter file (a fail-closed denial is not counted as an attempt) |

Example of the executed evidence:

```
ok   an uncreatable directory denies a fail-closed bucket
ok   the denial is logged so the operator can see why
ok   the same directory still allows a fail-open bucket
ok   the default storage directory is created on demand
```

## The OTP limiter (database-backed, unchanged, re-asserted)

`sh_otp_rate_begin()` returns `'unavailable'` — i.e. **refuses to send a code** — when the
rate tables cannot be ensured, when the transaction cannot be opened, or when any exception
occurs mid-reservation. It never falls back to "allowed". Its message to the visitor is
generic ("Verification is temporarily unavailable. Please try again shortly.") so a broken
store is not advertised. This was already correct from the OTP phase; the suite now pins it so
a future refactor cannot quietly flip the default.

## Residual risk

| Item | Note |
| --- | --- |
| Fail-closed availability | If `logs/` becomes unwritable, installer upgrades and scheduled backups refuse until it is fixed. That is the intended trade — the log line says exactly what to fix. |
| Shared hosting `open_basedir` | A counter file outside the allowed paths fails closed for the two secret buckets. Correct, and visible in the log. |
| Distributed hosts | The limiter is per server, so two web nodes each allow the full quota. For a single cPanel store (the target deployment) this is exact; for a multi-node cluster it needs a shared store. Documented, not hidden. |
