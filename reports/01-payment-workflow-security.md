# 01 — Payment workflow security

**Status: FIXED (database-level uniqueness added) · CONFIRMED (everything else, by suite)**

Command for everything below:

```
$ php tests/payment-security.php
passed 53   failed 0
PAYMENT SECURITY SUITE: ALL CHECKS PASSED
```

## What was checked, and what the code actually does

### A. Submission (`api/payment.php` action=submit → `sh_submit_manual_payment()`)

| Property | Evidence |
| --- | --- |
| POST only; a GET gets 405 | `if ($_SERVER['REQUEST_METHOD'] !== 'POST') … 405` |
| Login is required **before** CSRF, so an expired session gets a clean 401 instead of a token error | `sh_require_login(...)` precedes `sh_csrf_require()` |
| CSRF is enforced | `sh_csrf_require();` |
| The order is taken from `$_POST['order_id']`, cast with `sh_int()`, and ownership is proved | `sh_order_get()` + `sh_order_can_view()` |
| The amount is **never** read from the request | the payment row must match the locked order row: `abs($pay['amount'] - $order['total']) <= 0.004` |
| A submission is always `pending` — it can never verify itself | `SET … status = 'pending'` |
| A resubmission clears `admin_note`, `verified_by`, `verified_at` and bumps `submission_version` | UPDATE in `sh_submit_manual_payment()` |
| The order moves to `payment_submitted`, not to paid | `UPDATE orders SET … payment_status = 'submitted'` |
| A paid or closed order cannot be submitted against | guards on `payment_status ∈ {verified, refunded}` and `status ∈ {cancelled, completed}` |
| A browser retry after a successful commit is idempotent and sends no second notification | `already_submitted` branch claims the notification key |

### B. Duplicate transaction IDs — the one real gap, now closed

Sequential protection already existed and still does:

```sql
SELECT id FROM payments WHERE transaction_id = ? AND order_id <> ? LIMIT 1 FOR UPDATE
```

That lock cannot see a row another request is about to insert, so two orders could claim
one bKash/Nagad TrxID under concurrency. **Fixed in this phase:**

1. `install/schema.php` — fresh installs get `UNIQUE KEY uq_payments_transaction_id (transaction_id)`
   (the old non-unique `idx_payments_transaction_id` is gone).
2. `sh_payment_submission_schema_ensure()` — existing stores are promoted in place:
   `''` is normalised to `NULL` (an empty string is not a reference and would collide with
   itself), then one `ALTER TABLE … DROP INDEX idx_payments_transaction_id, ADD UNIQUE KEY
   uq_payments_transaction_id (transaction_id)`.
3. If historical duplicates block the `ALTER`, nothing breaks: the application check stays,
   the error is logged, and the retry is postponed for 7 days
   (`payment_txn_unique_retry_at`) instead of failing on every checkout.
4. A concurrent insert that the index rejects is reported to the customer as
   *"This transaction ID has already been submitted for another order."* — detected by
   SQLSTATE `23000` plus the index name, not by string-matching the driver message.
5. `install/migrations/2026-11-payment-transaction-unique.sql` does the same by hand for
   stores that prefer phpMyAdmin. It **lists duplicates before changing anything**, only
   alters clean data, is idempotent, and ships a rollback.

> **Not executed here:** the `ALTER TABLE` itself. There is no MySQL/MariaDB server in this
> sandbox (see report 03), so the DDL is proven by inspection and by the migration's own
> guard logic, not by running it. Applying it on staging is the remaining step.

### C. Admin decisions (`sh_approve_payment()`, `sh_reject_payment()`)

* Both open their own transaction and refuse to run inside a caller's transaction.
* Both lock the payment **and** the order row (`FOR UPDATE`).
* A verified payment cannot be verified twice, and cannot be rejected at all.
* Rejection is idempotent (`$alreadyRejected` short-circuits the writes).
* Notifications and stock alerts are sent **after** `commit()`; a provider timeout cannot
  undo a decision.
* `admin/payments.php` writes `sh_audit('payment_verified' | 'payment_rejected', …)` and
  requires CSRF.

### D. Gateway callbacks

* Unknown or disabled gateway → 400; unconfigured merchant credentials → 400.
* A callback must match a **pre-bound** `gateway_reference` on the order's newest gateway
  payment (`hash_equals`); an unbound callback is refused, so knowing an order ID is not
  enough to settle it.
* A driver that has no official verification API returns `unsupported` and **refuses to
  settle** — the codebase does not invent a verification.
* Only `verified` / `declined` may change a payment; a transport error leaves it untouched
  (a forged callback cannot flip an order to `payment_rejected`).
* The request body cannot state its own result (`$_REQUEST['status'|'verified'|'paid']` is
  never read).
* A duplicate callback answers with the **persisted** outcome, not the retry payload.

## Remaining risk (honest list)

| Item | Why it is still open |
| --- | --- |
| TrxID unique index | Applied automatically, but not yet exercised against a real MySQL server. Run the migration on staging and confirm `NON_UNIQUE = 0`. |
| Gateway settlement | Every driver needs a real merchant account. Nothing about bKash/Nagad/SSLCommerz settlement can be proven without one (`CREDENTIAL REQUIRED`). |
| Payment states | The brief listed `pending/verified/rejected/failed/cancelled`; the schema matches, and `submitted` lives on **orders.payment_status**, not on payments. No mismatch was found in code. |
| Refunds | Not implemented in this codebase; a refund is recorded as an admin note. Out of scope for this phase, listed so it is not mistaken for "tested". |

## How to reproduce

```bash
php tests/payment-security.php                     # 53 checks
php tests/security-regression.php                  # 185 checks, payment sections included
mysql < install/migrations/2026-11-payment-transaction-unique.sql   # on staging
```
