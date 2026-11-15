# 10 — Notification retry

**Status: PASSED (contract verified by `tests/webhooks-and-retries.php` §4–5) · NOT EXECUTED
(the executed half needs MySQL)**

```
$ php tests/webhooks-and-retries.php
ok   a keyed delivery must be claimed or it is not sent
ok   the claim writes a unique idempotency key
ok   two concurrent sends cannot both claim the same key
ok   a retry only claims a delivery that actually failed
ok   the retry is conditional on the row still being failed
ok   the attempt counter increases on retry
ok   an already delivered event is never re-sent
ok   the retry control is on the admin notifications page
ok   the retry action requires CSRF
ok   the retry action requires a permission
ok   the channel-specific retry only re-sends the failed channel
```

## The design being verified

Delivery is **claimed before it is attempted**, keyed by
`(channel, event, idempotency_key)` in `notification_logs`:

1. `sh_notification_claim()` inserts a row with `status = 'skipped'` (a short-lived
   reservation) and `attempts = 1`.
2. If the insert fails because the key already exists, the existing row decides:
   * status `failed` **and** this is a retry → the row is flipped back to `skipped` with
     `attempts = attempts + 1`, but only `WHERE id = ? AND status = 'failed'` — so two clicks
     cannot both claim it (`rowCount() === 1` is the gate);
   * any other status → **not claimed**, nothing is sent ("an already delivered event is never
     re-sent");
   * the key exists but is not retryable → the caller receives the stored status, so the UI
     can show "already delivered".
3. If the idempotency store itself is unavailable, a **keyed** event is not sent at all:
   *"Notification idempotency storage is unavailable; delivery was not attempted."*
   Failing open here would allow duplicate customer emails on every checkout retry.
4. A retry is an **admin** action (`admin/notifications.php`): permission required, CSRF
   required, and a channel-specific retry re-sends only the failed channel
   (`Not selected for this channel-specific retry.`).

Order and payment notifications also travel through this path — `sh_emit_payment_submitted_notifications()`
runs after the payment transaction commits, so a provider outage can be retried from the panel
without touching the payment record.

## Why the executed half needs MySQL

`notification_logs` is a MySQL table (`INSERT`, a unique key on the idempotency key, ENUM
status). The claim logic depends on the duplicate-key error to detect "already claimed", which
SQLite does not reproduce faithfully enough to be worth pretending. So the suite asserts the
contract and the tournament of states is left to a real database:

```bash
# on staging, with MySQL
php tests/email-system.php          # covers webhook + delivery log
# then, by hand:
#  1. disable SMTP host, place an order          → notification row = failed
#  2. Admin → Notifications → Retry               → attempts 1 → 2, one email
#  3. click Retry again immediately               → not claimed, no second email
#  4. restore SMTP, Retry                         → delivered, attempts 3
```

## Residual risk

| Item | Note |
| --- | --- |
| Automatic retry | There is no background retryer: a failed notification stays failed until an admin retries it. Deliberate (no cron worker), and it is visible in the panel rather than silently dropped. |
| Attempt ceiling | `attempts` is recorded but not capped; the admin can retry indefinitely. Acceptable for a manual action, worth a cap if it ever becomes automatic. |
| Telegram/WhatsApp channels | Share the same claim path; live verification needs their tokens (report 09). |
