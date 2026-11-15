# 02 — IDOR / object-level authorisation

**Status: PASSED — 42 checks over 62 admin pages, 11 API endpoints and every order screen**

```
$ php tests/idor-scan.php
passed 42   failed 0
IDOR SCAN: ALL CHECKS PASSED
```

The scan enumerates the real files and asserts the project's rules; a new admin page
without a guard, or a new endpoint without auth or a documented compensating control,
fails the build. It is not a smoke test that can be satisfied by a comment.

## Rules and results

| # | Rule | Result |
| --- | --- | --- |
| 1 | Every `admin/*.php` page (56 after excluding partials, login and the redirector) calls a guard | `56 admin pages checked, every one calls a guard` |
| 2 | Every admin page that both writes and reads POST calls `sh_csrf_require()` | `24 mutating admin pages, every one calls sh_csrf_require()` |
| 3 | No SQL string is built by concatenating `$_GET`/`$_POST` | 0 hits across 237 files |
| 4 | Every id read from a request is cast (`sh_int`, `(int)`, `max(0, …)`) | 0 uncast ids |
| 5 | Customer order screens pair `sh_order_get()` with `sh_order_can_view()` | 6/6 screens |
| 6 | Customer order lists are scoped by `user_id` | `orders.php`, support conversations |
| 7 | Every API endpoint is either guarded or on the reviewed-public list **and** keeps its control | 11/11 |
| 8 | Support conversations are owner-scoped for customers | message read, send, retry |
| 9 | No page accepts `user_id` / `admin_id` from the request | 0 hits |
| 10 | Admin sub-resource views require a specific permission, not "any admin" | 6/6 |

## Why these rules and not "click every URL"

Broken object authorisation is per-route: the property is *"for every route, every id that
arrives from a client is resolved through a check that decides whether this caller may
touch that object"*. `sh_order_get()` deliberately does **not** scope by user — it is the
raw loader — so every caller must add `sh_order_can_view()`, an ownership comparison, or an
admin permission. That is exactly what rule 5 encodes, file by file.

### The interesting call sites, spelled out

| File | Guard |
| --- | --- |
| `order-details.php`, `order-success.php` | `sh_order_get()` then `sh_order_can_view()` (exits 404 with a neutral page) |
| `payment.php` | `sh_require_login()` **before** the order is read; `sh_order_can_view()` before anything is rendered or submitted |
| `checkout.php` (`?retry_order=`) | `(int)$retryOrder['user_id'] !== (int)$user['id']` → 404 |
| `api/orders.php` | login for every action except `track`; `track` needs order number **and** phone (`WHERE order_number = ? AND customer_phone = ?`) |
| `api/payment.php` (callback) | must match a pre-bound `gateway_reference` (`hash_equals`) |
| `api/support.php` | `sh_support_conversation_for_owner()` / owner passed by the server, never by the request |
| `admin/orders.php`, `admin/payments.php`, `admin/customers.php`, `admin/parcels.php` | `sh_require_perm('orders.view' | 'payments.*' | …)` |
| `admin/verification-fetch.php` (AJAX fragment) | `sh_csrf_valid()` + permission on the page that embeds it; answers with a refresh hint, never a full error page |

## Reviewed-public endpoints (deliberate, with the control that makes them safe)

| Endpoint | Control |
| --- | --- |
| `api/csp-report.php` | POST only, 16 KB body cap, values truncated and stripped of control characters, nothing echoed, file-backed rate limit |
| `api/email-events.php` | HMAC-SHA256 over the raw body or a shared key, compared with `hash_equals` (report 09) |
| `api/search.php` | read-only catalogue suggestions |
| `api/otp.php` | CSRF + durable rate limits; no object ids in the payload |
| `api/cart.php` | session cart only, CSRF required, wishlist writes scoped by `user_id` |
| `api/support.php` | origin check + `Sec-Fetch-Site` + per-action permissions |
| `api/orders.php` (`track`) | two-factor lookup (number + phone) limits enumeration |

## What this scan cannot prove

* It does not log in. The rules are structural; a role matrix mistake inside
  `sh_admin_can()` would not be caught here. Permission semantics are covered by
  `tests/security-regression.php` and `tests/admin-access-url.php`.
* It does not test the database's row-level behaviour (there is no MySQL server here).
  Rule 5's runtime half — *with a second customer's order id, the page returns 404* — is a
  five-minute manual check listed in report 14.
