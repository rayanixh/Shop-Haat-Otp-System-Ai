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
- `sh_file_rate_limit()` in `includes/security.php` serializes each counter read/increment/write using stable per-bucket/hash-prefix lock files under a private `.locks` subdirectory. Cleanup uses the same nonblocking shard lock before unlinking, so no new-version writer can have an open counter handle waiting on a file that is removed. Counter writes check truncation, rewinds, complete writes and flushes. The counter and lock directories are mode `0700`; counter and lock files are mode `0600`; permission/storage failures preserve the existing fail-open/fail-closed caller policy.
- Expired counters are swept automatically by `sh_file_rate_limit_cleanup()` on limiter calls using a persistent sequence queue, not by restarting a directory walk. The cursor (`head`/`tail`) is stored atomically in `.cleanup-queue/state.json` under a stable `.locks/cleanup-queue.lck`; each job names only a hashed counter filename. Each sweep inspects at most 24 queued jobs by default and hard-caps at 32, with direct sequence-addressed reads and no `readdir`, `scandir`, `glob`, or unbounded walk. New/touched counter files are indexed once with opaque marker files; active counters are re-queued, while expired/missing entries are retired. Queue processing and counter writers share the stable per-bucket/hash-prefix locks; cleanup acquires the shard lock before reading/unlinking a counter, and writers enqueue only after releasing that lock, avoiding lock-order cycles and open-inode/unlink races. A crash before cursor advancement can cause a harmless duplicate job; job processing is idempotent and remains budgeted. The public HTML/JSON tracker makes three limiter calls, so queue inspection is capped at 72 jobs per HTTP request.
- HTML and JSON tracking retain order-number plus checkout-phone verification. The public HTML query does not select the internal order ID or payment-method ID; it selects order reference/status/payment status/payment-method type/placed time, with only the method type needed for COD timeline classification. Payment data is limited to kind/status/submission and verification timestamps plus a database-computed `has_reference` boolean; raw transaction IDs and gateway references are not fetched into PHP. Payment, item, and audit-time reads are joined/filtered by the verified order reference and phone; the internal numeric ID remains inside SQL joins but is not returned to PHP or the browser. The API tracker returns only reference/status/payment/placed time. Neither tracker loads customer contact/address/total or line-item content. HTML tracking no longer echoes the submitted phone or payment-method detail; responses are `no-store, private`.
- New references in `sh_order_number()` use 12 random bytes (96 bits) encoded as 24 uppercase hexadecimal characters after the validated two-letter store prefix. The existing schema has a unique `uq_orders_number` index and `VARCHAR(30)` accommodates the value. Order creation retries up to five times when the database specifically reports a unique-reference collision; other SQL failures abort the transaction. The temporary pre-reference value also uses 12 random bytes. The legacy function argument remains for caller compatibility but is not incorporated into the customer-visible reference.

### Customer-support lookup review

- Reviewed the legacy reference lookup in `includes/support.php`. It previously looked up numeric database IDs and allowed phone-based access even for a signed-in user whose session did not own the order.
- Signed-in support lookups read the owner ID from `sh_user_id()` and push `order_number IN (...) AND user_id = ?` into SQL; no submitted identity/user ID is trusted, and the projection is limited to `id`, `order_number`, statuses, created time, total, and delivery fee needed by order facts. Other customers' and unknown references return the same generic not-found state. Anonymous support requires both reference and checkout phone before querying and pushes both `order_number IN (...)` and normalized `customer_phone IN (...)` variants into SQL. Wrong phone and unknown reference both return generic not-found. Correct reference-plus-checkout-phone verification remains supported and uses the shared tracking limiter; failed pairs remain rate limited.
- Removed numeric database-ID reference resolution from the legacy support lookup and its parser. Updated `includes/ai-support.php` parsing to recognize the new opaque references and existing customer-visible reference formats without extracting bare numeric database IDs. AI-support order reads remain constrained to the signed-in owner or guest-order IDs in the support owner context.

### Regression checks

- Expanded `tests/idor-scan.php`, `tests/security-regression.php`, and `tests/support-unit.php` with source assertions for owner-scoped customer access, tracking minimization, reference entropy/collision retry, and support SQL privacy. Expanded `tests/rate-limit-failsafe.php` with a 100-expired-plus-5-active repeated-sweep case, per-sweep inspection budget checks, separate-process cursor advancement, active-counter preservation, and concurrent cleanup/counter-update workers. These are test cases added; runtime outcomes are not claimed because PHP CLI is unavailable.
- The added PHP tests have **not** been executed because no PHP runtime is available in the workspace; they are not reported as passing.

## Files changed

- `includes/auth.php`
- `includes/order-timeline.php`
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
- `tests/rate-limit-failsafe.php`
- `tests/security-regression.php`
- `tests/support-unit.php`

Pre-edit copies of affected existing files were saved under `/tmp/shophaat-phase1-backup/` before their respective edits. The newly created tracking helper has no pre-edit copy.

## Exact files changed in this final review

- `includes/security.php` — persistent bounded queue/cursor cleanup and post-shard-lock index registration.
- `includes/support.php` — session-derived owner predicate and anonymous reference-plus-phone SQL projection.
- `includes/ai-support.php` — explicit guard against co-loading the separate legacy/current support modules, which have overlapping global helper names.
- `tests/rate-limit-failsafe.php` — repeated 100-counter cleanup, budget, process-persistence, and concurrent update/cleanup cases.
- `tests/support-unit.php` — source assertions for session-derived SQL authorization, minimal projections, and module co-load guards.
- `tests/security-regression.php` — source assertions for persistent rotation and the no-directory-walk invariant.
- `SHOP_HAAT_PHASE_1_SECURITY_REPORT.md` — this final review, checks, risks, and blocked-test record.

Other reviewed files (`includes/order-tracking.php`, `track.php`, `api/orders.php`, `includes/payment.php`, `includes/functions.php`) were inspected for wiring/signature compatibility and were not changed by this final review. Declaration inspection found six overlapping global helper names in the legacy `includes/support.php` and current `includes/ai-support.php` modules, with differing signatures; production routes load only the current module, while legacy test files load only the legacy module. Both entry files now stop explicitly before declarations if the other module was loaded first, preventing an opaque redeclaration fatal.

## Checks executed and results

| Check | Result |
|---|---|
| Prior ZIP/source parity review | Passed: 7 reviewed files (tracking limiter, tracking routes, support lookup, AI-support, and report) matched the committed pre-review source byte-for-byte before these final fixes. |
| `git diff --check` | Passed; no whitespace errors reported. |
| PHP 8.3 syntax parsing using the `php-parser` npm package in `/tmp` (not added to project dependencies) | Passed: 22/22 working-tree PHP files in the Phase 1 change set and requested source review parsed successfully. This is a parser check, not PHP CLI lint or runtime execution. |
| Custom Python source assertions: cleanup queue/cursor/lock contract, budgets, configured tracking limits, support SQL ownership, include wiring, co-load guards, and regression harness wiring | Passed: 15/15. These are textual/source checks only. |
| Function declaration/include/signature review across the eight requested files | No duplicate declarations within an individual file and no missing shared-tracker includes found. Six same-named helper functions exist across the separate legacy/current support modules with incompatible signatures; both modules now throw an explicit `LogicException` before declaring if the other is already loaded. Existing callers of `sh_file_rate_limit_cleanup()` and `sh_support_tool_order()` remain compatible; the cleanup's optional third by-reference inspection output does not change its two-argument callers. |
| `tests/idor-scan.php` | Not run: PHP CLI unavailable. |
| `tests/rate-limit-failsafe.php` (100-file repeated sweeps, budget checks, cursor persistence across processes, and concurrent cleanup/counter updates) | Not run: PHP CLI unavailable; repeated-sweep, cross-process, and concurrency results are NOT RUN. Test cases are present but no runtime outcome is claimed. |
| `tests/security-regression.php` | Not run: PHP CLI unavailable. |
| `tests/support-unit.php` (including new SQL ownership/projection source assertions) | Not run: PHP CLI unavailable. |
| PHP built-in lint / application execution | Not run: PHP CLI/runtime unavailable. |
| MySQL/MariaDB-backed checks | Not run: no database runtime was available. |
| Synthetic Customer A/B cross-account access checks | Not run: no authorized disposable local/staging database and browser/request environment was established. |
| Browser/UI regression and live public-tracker rate-limit tests | Not run. |
| PHP integration check of public timeline SQL and anonymous support response parity | Not run: no PHP/database runtime. |
| Final project ZIP integrity and changed-file coverage | Passed: 417 archive entries, `unzip -t` found no errors, all 25 paths changed from base/current working tree were present, and installation DB secrets/lock marker were excluded. |

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
| Expired-counter cleanup: repeated sweeps over 100 expired entries with five active counters; each budget-seven sweep; cursor persistence in separate PHP processes | NOT RUN: PHP CLI unavailable. The test harness asserts eventual removal, unchanged active counters, and per-sweep budget, but no observed repeated-sweep result is available. |
| Concurrent cleanup plus active counter update stress | NOT RUN: PHP CLI unavailable. Worker tests are added; no runtime evidence of concurrency results is available. |
| Anonymous support: known/unknown reference with missing phone and wrong phone | Not run against the application. Source now returns a phone prompt before querying for a missing phone and generic not-found for wrong/unknown references, but response parity is not runtime-verified. |
| Force a duplicate order-reference collision and confirm bounded retry / unique-index behavior | Not run against a database. Source uses the unique index and retries up to five explicit duplicate-key collisions; integration behavior remains unverified. |
| A updates own profile/address and views own orders | Not run: no test account or app runtime. |
| Existing OTP and login flow | Not run: no PHP runtime, database, or request environment. |

A parser-discovered syntax error in an intermediate version of `tests/idor-scan.php` was corrected. The final PHP parser pass succeeded. No application test results are being represented as passed.

## Incomplete verification and remaining risks

1. **Runtime authorization behavior is unverified.** The requested synthetic Customer A/B checks remain necessary for profile, address, order/detail, payment, changed IDs, unauthenticated APIs, and unauthorized AJAX paths.
2. **Full workflow regressions are unverified.** Login/registration/OTP, guest-order recovery, address management, checkout/retry, payment submission, support chat, and admin workflows need execution against a disposable or staging database with synthetic data.
3. **Rate-limit deployment assumptions:** storage, queue state, queue jobs, index markers, and stable lock files live under the configured local log directory; PHP must support atomic same-filesystem `rename()` and `flock()` shared by workers, and the PHP account must create/chmod private `0700` directories and `0600` files. NFS/shared-host semantics must be verified on the actual host. The sequence queue avoids scan starvation and spends at most the configured job budget per sweep. Counters created by older versions are indexed when next touched; an untouched legacy counter is not discoverable by the new queue and may remain on disk, so monitor/plan one-time maintenance if legacy volume exists. Active counters keep one queue marker/job; expired ones remove both. Queue state is monotonic and marker/job storage tracks live indexed counters. Fail-closed tracking denies requests if counter storage or required queue registration fails.
4. **Legacy order references:** new references are unpredictable, but historical sequential references remain valid for existing orders. They are protected by shared limits, but do not gain the entropy of newly generated references. Database collision retries and the unique-index behavior still need a runtime DB test.
5. **Session-bound guest access:** legacy guest order visibility depends on the guest order ID list remaining in the same PHP session; session expiration or browser/device changes may prevent access and require the existing recovery/support workflow.
6. **Application-specific parser limits:** a successful `php-parser` syntax parse does not replace the target server's PHP version lint, extension checks, SQL execution, or integration testing.
7. **Existing authorized back-office paths:** admin, Telegram, courier, notification, and gateway lifecycle functions contain intentionally unscoped internal order reads. They were not broadly rewritten because those operations are not customer-facing selectors; their route permissions and callbacks still require deployment-specific verification.

## Phase status

**Independent approval readiness:** The implementation is prepared for independent source review, but Phase 1 is **not ready to be approved as runtime-verified** until the blocked PHP cleanup/concurrency tests, disposable-DB, HTTP, and regression tests listed above are run. Repeated-sweep and concurrent test results are NOT RUN, not inferred from source assertions. No Phase 2 work has begun.

## Project package

`SHOP_HAAT_PHASE_1_COMPLETE_PROJECT.zip` contains the repository's tracked project files plus all current working-tree changes, including this report, the cleanup/concurrency regression test, and `includes/order-tracking.php`. It excludes `.git`, local backups, runtime logs, uploaded customer/store data, and ignored installation secrets such as `config/database.php` and `config/installed.lock`; a fresh deployment must supply its own protected configuration and install state.
