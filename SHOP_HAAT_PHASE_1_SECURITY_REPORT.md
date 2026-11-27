# Shop-Haat Phase 1 Security Hardening Report

**Scope:** Customer privacy and IDOR hardening in the PHP + MySQL/MariaDB cPanel-compatible application. This report covers Phase 1 only. No Phase 2 work was started.

## Summary

Phase 1 implementation changes are present in the working tree. Customer-facing account, address, and order reads now derive identity from the authenticated session and use owner-constrained lookups. Customer payment writes include the owner predicate in their locked order lookup and update. The public HTML and JSON order trackers share a file-backed atomic rate limiter and return reduced order data. New order references use cryptographic randomness instead of embedding a database ID. Customer-support order lookups were also tightened: signed-in support lookups require order ownership, internal numeric IDs are no longer accepted as order references, and anonymous support lookups share the public tracking rate budget.

No PHP application tests, database-backed tests, browser tests, or synthetic Customer A/B request tests were executed. A PHP syntax-parser pass and source-level checks did run successfully; these do not establish runtime authorization or workflow behavior.

## Implemented changes

### Session identity and ownership

- Added shared customer/address helpers in `includes/auth.php`:
  - `sh_require_authenticated_customer()` delegates to the application's existing login/verification gate.
  - `sh_customer_address_get()` and `sh_customer_owns_address()` resolve address access using `sh_user_id()` from the server session, not a submitted owner ID.
- Updated `account.php`, `orders.php`, `addresses.php`, and checkout/customer payment paths to use the centralized customer guard.
- Address delete/default actions resolve the address through the session-owner helper first, then retain `user_id` in the SQL mutation.
- Added `sh_customer_order_get()` in `includes/payment.php`. Authenticated customers load only `WHERE id = ? AND user_id = ?`; legacy guest orders are limited to IDs retained in that same anonymous PHP session and must still have `user_id IS NULL`. A signed-in customer cannot fall back to a guest-order session list.
- Updated `order-details.php`, `order-success.php`, `payment.php`, `checkout.php`, and non-tracking actions in `api/orders.php` to use owner/session-scoped order loading. Expired-session recovery is initiated before an order is loaded in the affected customer views.
- Payment-method reselection and manual payment submission now lock owner-constrained orders and repeat the owner predicate in order updates. The API submit path requires the authenticated customer guard and CSRF validation; the separate server-to-server gateway callback remains a distinct callback flow.

### Public tracking and order references

- Added `includes/order-tracking.php`, shared by `track.php`, `api/orders.php`, and anonymous support lookup. It applies locked file-counter limits by source IP (40 per 10 minutes), canonicalized order reference (20 per hour), and reference/normalized-phone pair (6 per 15 minutes). Legacy hyphenated and de-hyphenated reference forms share the same order bucket. Counter storage fails closed if it cannot be used safely.
- `sh_file_rate_limit()` in `includes/security.php` uses `flock(LOCK_EX)` around each counter read/increment/write, truncates and rewinds only while holding the lock, checks for full writes and flush success, and releases the lock when complete. The limits directory is set to mode `0700` and counter files to `0600`; mode/writeability checks fail closed. The stored names are keyed hashes, not raw IPs, phones, or order references. This source-level review does not replace a live concurrent PHP request test; filesystem `flock` behavior on shared/network filesystems remains host-dependent.
- Client IP uses `sh_client_ip()` from `includes/client-ip.php`: the TCP peer (`REMOTE_ADDR`) is used unless it matches an explicitly trusted proxy; forwarding headers are ignored from untrusted peers. With a trusted peer, configured proxy-mode rules select/validate the forwarded address. The current installation's proxy settings could not be runtime-verified because there is no installed database configuration in this workspace.
- HTML and JSON tracking retain the order-number plus checkout-phone verification. Public queries return only the order reference/status/payment status/placed time fields required by their UI/API and avoid loading customer email, phone, address, order total, and database ID. The HTML tracker no longer renders payment-method detail or re-populates the submitted phone; tracking responses are marked `no-store, private`.
- New references in `sh_order_number()` use 12 random bytes (96 bits) encoded as 24 uppercase hexadecimal characters after the validated two-letter store prefix. The existing schema has a unique `uq_orders_number` index and `VARCHAR(30)` accommodates the value. Order creation retries up to five times when the database specifically reports a unique-reference collision; other SQL failures abort the transaction. The temporary pre-reference value also uses 12 random bytes. The legacy function argument remains for caller compatibility but is not incorporated into the customer-visible reference.

### Customer-support lookup review

- Reviewed the legacy reference lookup in `includes/support.php`. It previously looked up numeric database IDs and allowed phone-based access even for a signed-in user whose session did not own the order.
- Signed-in support lookups now authorize only by session-account ownership; unauthorized references return a generic not-found result. Anonymous support continues to support the existing reference-plus-checkout-phone flow, now subject to the shared tracking limiter.
- Removed numeric database-ID reference resolution from the legacy support lookup and its parser. Updated `includes/ai-support.php` parsing to recognize the new opaque references and existing customer-visible reference formats without extracting bare numeric database IDs. AI-support order reads remain constrained to the signed-in owner or guest-order IDs in the support owner context.

### Regression checks

- Expanded `tests/idor-scan.php`, `tests/security-regression.php`, and `tests/support-unit.php` with source-level assertions for owner-scoped order/address access, payment mutation predicates, tracking limits and reduced fields, random references, support lookup ownership, and rejection of numeric internal order IDs.
- These PHP test scripts have **not** been executed because no PHP runtime is available in the workspace.

## Files changed

- `includes/auth.php`
- `includes/security.php`
- `includes/payment.php`
- `includes/functions.php`
- `includes/order-tracking.php` (new)
- `includes/support.php`
- `includes/ai-support.php`
- `account.php`
- `addresses.php`
- `orders.php`
- `checkout.php`
- `payment.php`
- `order-details.php`
- `order-success.php`
- `api/orders.php`
- `api/payment.php`
- `track.php`
- `tests/idor-scan.php`
- `tests/security-regression.php`
- `tests/support-unit.php`

Pre-edit copies of affected existing files were saved under `/tmp/shophaat-phase1-backup/` before their respective edits. The newly created tracking helper has no pre-edit copy.

## Checks executed and results

| Check | Result |
|---|---|
| `git diff --check` | Passed; no whitespace errors reported. |
| PHP 8.3 syntax parsing using the `php-parser` npm package in `/tmp` (not added to project dependencies) | Passed: all 20 changed PHP files, including the new tracking helper, parsed successfully. This is a parser check, not PHP CLI lint or runtime execution. |
| Custom Python source assertions: rate-limiter signature/locking, shared tracker wiring, CSPRNG reference generation, session-owner SQL, and support restrictions | Passed: all eight assertions. These are textual/source checks only. |
| `tests/idor-scan.php` | Not run: PHP CLI unavailable. |
| `tests/security-regression.php` | Not run: PHP CLI unavailable. |
| `tests/support-unit.php` | Not run: PHP CLI unavailable. |
| PHP built-in lint / application execution | Not run: PHP CLI/runtime unavailable. |
| MySQL/MariaDB-backed checks | Not run: no database runtime was available. |
| Synthetic Customer A/B cross-account access checks | Not run: no authorized disposable local/staging database and browser/request environment was established. |
| Browser/UI regression and live public-tracker rate-limit tests | Not run. |

### Requested real-request test matrix

No HTTP request was sent to the application in this workspace, so there are no HTTP status/body observations to report. The results below are **not run / blocked**, not passes:

| Requested scenario | Actual result |
|---|---|
| Create independent synthetic Customer A and Customer B accounts | Not performed: no runnable PHP application/database environment or disposable DB was configured. No synthetic accounts were created. |
| A requests B's profile, email, or phone | Not run: no authenticated HTTP client/session and no test database. |
| A reads or modifies B's address; verify B's DB row is unchanged | Not run: no HTTP application or database available to execute and inspect the mutation. |
| A reads B's order/details; change submitted user/order IDs | Not run: no authenticated HTTP application/database. |
| Unauthenticated requests to private customer endpoints | Not run over HTTP. The source-level guard assertions are not substitutes for responses. |
| Public tracking invalid pair, known pair, per-IP threshold, per-order threshold, and concurrent attempts | Not run against the PHP limiter. No runtime/file-counter HTTP server was available. The configured policy in source is 40 per IP/10 minutes, 20 per normalized order key/hour, and 6 per order/phone pair/15 minutes. |
| Force a duplicate order-reference collision and confirm bounded retry / unique-index behavior | Not run against a database. Source uses the unique index and retries up to five explicit duplicate-key collisions; integration behavior remains unverified. |
| A updates own profile/address and views own orders | Not run: no test account or app runtime. |
| Existing OTP and login flow | Not run: no PHP runtime, database, or request environment. |

A parser-discovered syntax error in an intermediate version of `tests/idor-scan.php` was corrected. The final PHP parser pass succeeded. No application test results are being represented as passed.

## Incomplete verification and remaining risks

1. **Runtime authorization behavior is unverified.** The requested synthetic Customer A/B checks remain necessary for profile, address, order/detail, payment, changed IDs, unauthenticated APIs, and unauthorized AJAX paths.
2. **Full workflow regressions are unverified.** Login/registration/OTP, guest-order recovery, address management, checkout/retry, payment submission, support chat, and admin workflows need execution against a disposable or staging database with synthetic data.
3. **Rate-limit deployment assumptions:** the limiter uses local file storage under the configured log directory. It requires writable storage and consistent sharing if the application is served by multiple nodes. It intentionally fails closed if storage/locking/writes fail, which can temporarily deny public tracking. Counter-file growth/cleanup should be monitored on long-lived installations.
4. **Legacy order references:** new references are unpredictable, but historical sequential references remain valid for existing orders. They are protected by the shared rate limits, but do not gain the entropy of newly generated references.
5. **Session-bound guest access:** legacy guest order visibility depends on the guest order ID list remaining in the same PHP session; session expiration or browser/device changes may prevent access and require the existing recovery/support workflow.
6. **Application-specific parser limits:** a successful `php-parser` syntax parse does not replace the target server's PHP version lint, extension checks, SQL execution, or integration testing.
7. **Existing authorized back-office paths:** admin, Telegram, courier, notification, and gateway lifecycle functions contain intentionally unscoped internal order reads. They were not broadly rewritten because those operations are not customer-facing selectors; their route permissions and callbacks still require deployment-specific verification.

## Phase status

Phase 1 application changes and this report are prepared. Verification remains partial as listed above. No Phase 2 work has begun.

## Project package

`SHOP_HAAT_PHASE_1_COMPLETE_PROJECT.zip` contains the repository's tracked project files plus all current working-tree changes, including this report and `includes/order-tracking.php`. It excludes `.git`, local backups, runtime logs, uploaded customer/store data, and ignored installation secrets such as `config/database.php` and `config/installed.lock`; a fresh deployment must supply its own protected configuration and install state.
