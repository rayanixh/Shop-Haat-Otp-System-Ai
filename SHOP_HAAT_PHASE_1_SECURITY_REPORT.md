# Shop-Haat Phase 1 Security Hardening Report

**Scope:** Customer privacy and IDOR hardening in the PHP + MySQL/MariaDB cPanel-compatible application. This report covers Phase 1 only. No Phase 2 work was started.

## Summary

Phase 1 changes were reviewed and the requested source-level test suites were executed under an isolated PHP-WASM 8.5.12 CLI. The bounded cleanup test actually removed 100 expired counters in 15 repeated sweeps while preserving five active counters, with 105 total queued jobs inspected and the configured per-sweep budget maintained. The cross-process cursor and concurrent-worker cases were blocked because the WASM runtime could not execute child PHP processes. MySQL-backed, HTTP Customer A/B, public-tracking response/threshold, and business-workflow tests were not run.

This is partial runtime evidence from PHP-WASM, not a native PHP/cPanel host or database verification. No production credentials or customer data were used. Phase 1 is not claimed verified or approved.

## Runtime environment and isolation

- Native `php`, `mysql`, `mariadb`, `mysqld`, Docker and Podman were unavailable. The workspace has no `config/database.php`, disposable test DSN or staging URL. No production configuration or customer data was accessed.
- Installed `@php-wasm/cli` 3.1.66 only under `/tmp`; it ran PHP 8.5.12 with `pdo_mysql` listed, but no MySQL/MariaDB server was available. `tests/_support_php_bin.php` reported no runnable child PHP binary, so `proc_open` worker tests cannot run in this WASM CLI.
- The WASM CLI reports host exit status 0 even when a PHP script requests a nonzero exit (`php-wasm-cli -r 'exit(7);'` returned host status 0). Therefore the test suite's printed PASS/FAIL/BLOCKED summary is recorded alongside the host command status; host status alone is not treated as a pass.
- A disposable MySQL/HTTP environment could not safely be provisioned in this workspace. The database tests have destructive guards; they were not bypassed.

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

- Updated `tests/idor-scan.php`'s payment route classification to assert both authenticated manual submission and verified server-to-server callback controls. Updated `tests/security-regression.php` to assert cleanup wiring from the correct source file and to detect trailing whitespace attached to `T_CLOSE_TAG`. Updated `tests/support-unit.php` assertions for the SQL-pushed ownership rules and module sentinels.
- Expanded `tests/rate-limit-failsafe.php` with 100 expired and five active counters, repeated bounded sweeps, per-sweep inspection counts, separate-process cursor persistence and concurrent update/cleanup worker cases. The repeated-sweep portion was executed under PHP-WASM; process/concurrency cases were explicitly blocked because no runnable child PHP binary exists.

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

- `includes/support.php` — corrected the module sentinel guard and retained session-derived owner/phone-filtered SQL lookup.
- `includes/ai-support.php` — corrected the reciprocal module sentinel guard for overlapping legacy/current helper declarations.
- `tests/rate-limit-failsafe.php` — added child-process availability detection, corrected blocked-case accounting, and emitted measured repeated-sweep counts; child-process checks report BLOCKED when no runnable subprocess exists.
- `tests/idor-scan.php` — classify the mixed manual-payment/gateway callback endpoint by its actual controls.
- `tests/support-unit.php` — source assertions for session-derived SQL authorization, minimal projections, and module sentinels.
- `tests/security-regression.php` — source assertions for queue rotation and a tokenizer-correct trailing-output probe.
- `SHOP_HAAT_PHASE_1_SECURITY_REPORT.md` — this final review, checks, risks, and blocked-test record.

Other reviewed files (`includes/order-tracking.php`, `track.php`, `api/orders.php`, `includes/payment.php`, `includes/functions.php`) were inspected for wiring/signature compatibility and were not changed by this final review. Declaration inspection found six overlapping global helper names in the legacy `includes/support.php` and current `includes/ai-support.php` modules, with differing signatures; production routes load only the current module, while legacy test files load only the legacy module. Both entry files now use distinct module sentinel constants to stop before declarations if the other module was loaded first, preventing an opaque redeclaration fatal.

## Checks executed and results

Commands below used `/tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli` (PHP 8.5.12 WASM), except the explicit Node/jsdom behavior suites. The WASM host does not propagate PHP `exit()` status; results therefore include both the host exit code and the script's printed summary.

Initial WASM runs exposed test-harness issues rather than application runtime results: support-module guards used `function_exists()` (which sees declarations registered from the same file), the tokenizer probe did not account for whitespace attached to `T_CLOSE_TAG`, and the IDOR allowlist omitted the mixed manual-payment/gateway-callback controls. Those harness issues were corrected and the four requested suites were rerun; the final outputs below are from the corrected files.

| Exact command/check | Result |
|---|---|
| `php-wasm-cli --version` and extension probe | PHP 8.5.12 (WASM CLI); `pdo_mysql` extension reported loaded. No MySQL server was available. |
| `timeout 240 /tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli tests/idor-scan.php` | **PASS** — `passed 47 failed 0`; `IDOR SCAN: ALL CHECKS PASSED`. Host exit code 0; script summary indicates success. Source/static scan only, not HTTP authorization proof. |
| `timeout 240 /tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli tests/rate-limit-failsafe.php` | **PARTIAL / BLOCKED** — `passed 43 failed 0 blocked 2`; summary `RATE LIMIT FAIL-SAFE SUITE: SOME CHECKS BLOCKED`. Host exit code 0 (WASM masks PHP nonzero exits). Repeated-sweep details: `INFO repeated sweeps=15; expired remaining=0; total jobs inspected=105; active unchanged=yes`. Each seven-job sweep was within budget. The native child-process counter concurrency and separate-process cursor tests were BLOCKED by `no runnable PHP binary for a child process`. |
| `timeout 240 /tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli tests/security-regression.php` | **PASS with one environment skip** — `passed 188 failed 0 skipped 1`; the skipped check was actual header emission (`not applicable in this environment`). Host exit code 0. This suite is primarily source/regression assertions, not live HTTP. |
| `timeout 240 /tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli tests/support-unit.php` | **PASS** — `Passed: 66 · Failed: 0`. Host exit code 0. Pure/support-source tests only; no MySQL lookup was exercised. |
| `/tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli -l` on `tests/idor-scan.php`, `tests/rate-limit-failsafe.php`, `tests/security-regression.php`, and `tests/support-unit.php` | **PASS** — all four reported `No syntax errors detected`. |
| PHP-WASM lint of the requested source files and changed PHP set | **PASS** — 22/22 files parsed/linted without a PHP syntax error. This remains WASM PHP 8.5, not the production host PHP binary. |
| Custom source assertions (cleanup queue/cursor/lock contract, budgets, configured tracking limits, support SQL ownership, includes, module guards, and test wiring) | **PASS** — 15/15 textual assertions. Not runtime HTTP/database tests. |
| Top-level declaration/include/signature inspection across the eight requested sources | No within-file duplicate declarations; six pre-existing cross-module helper-name overlaps are guarded with module sentinels. No missing shared tracking-helper include found. Existing two-argument cleanup and support tool callers remain compatible. |
| `SH_JSDOM_PATH=/tmp/shophaat-jsdom/node_modules/jsdom/lib/api.js node tests/support-chat-behavior.mjs` | **PASS** — 76 passed, 0 failed. Simulated API/browser DOM only; no live HTTP or database. |
| Same environment with `node tests/support-chat-composer-behavior.mjs` | **PASS** — 32 passed, 0 failed. jsdom behavior harness only. |
| Same environment with `node tests/support-chat-optimistic-behavior.mjs` | **PASS** — 63 passed, 0 failed. Mocked API behavior only. |
| `php-wasm-cli -r 'exit(7);'` exit propagation probe | Host exit code 0 despite PHP requesting exit 7; confirms why suite summaries, not host status alone, are recorded. |
| `/tmp/shophaat-php-wasm/node_modules/.bin/php-wasm-cli tests/support-system.php` (disposable-DB preflight only) | **BLOCKED** — output: `BLOCKED support-system: database configuration is missing or incomplete`; no database mutations began. Host exit code 0 due WASM exit-status behavior. |
| MySQL/MariaDB integration, synthetic Customer A/B HTTP, live tracking/support API and workflow regressions | **NOT RUN** — no native PHP host, server, disposable DB, staging URL, or authenticated HTTP environment. No test credentials or customer rows were created. |
| Final ZIP integrity and changed-file coverage | **PASS** — 417 archive entries; all 25 base/current changed paths included; installation database config and install lock excluded. |

### Requested real-request test matrix

No HTTP request was sent to the application. The following are **NOT RUN / BLOCKED**, not passes:

| Exact scenario | Status and reason |
|---|---|
| Customer A reads Customer B's profile/contact; A reads/changes B's address; A reads B's order; tamper `user_id`/`order_id`; unauthenticated private endpoint calls | **NOT RUN** — no disposable DB, native PHP web server, authenticated HTTP client or synthetic accounts. No response bodies/statuses or database before/after state exist. |
| Unauthorized payment update and verification that B's row is unchanged | **NOT RUN** — no HTTP/database fixture. |
| Customer A reads/updates A's own profile, addresses and orders | **NOT RUN** — no HTTP/database fixture. |
| Anonymous support: known/unknown ref without phone; incorrect phone vs unknown ref; correct reference-plus-checkout-phone | **NOT RUN** — SQL and response parity require a disposable DB plus HTTP request session. Source/unit assertions are not response evidence. |
| Signed-in support owner-only order lookup | **NOT RUN** against MySQL/HTTP; source test confirms owner predicates only. |
| Public HTML and JSON tracking response fields/timeline | **NOT RUN** against HTTP/database. |
| Public tracker thresholds (40/IP/10m; 20/reference/hour; 6/reference-phone/15m) | **NOT RUN** through the actual public routes. Generic file limiter and repeated cleanup cases ran under PHP-WASM; they do not verify route-level thresholds or HTTP responses. |
| Signup/login, email/phone OTP, guest-order recovery, address management, checkout, COD creation, manual payment, order details/timeline, admin order management | **NOT RUN** — all require a disposable DB, and most require HTTP/session and email/OTP fixtures. |
| Support chat regression | **PASS, simulated only** — jsdom suites passed 76 + 32 + 63 assertions with mocked APIs; they do not verify server persistence, auth, or MySQL. |

### Rate-limit execution detail

`tests/rate-limit-failsafe.php` ran inside PHP-WASM's filesystem for the repeated single-process cleanup case. It created 100 expired and five active queued counter files, then executed 15 repeated sweeps with a requested per-sweep budget of 7; the test reported 105 jobs inspected total, zero expired files remaining and all five active files byte-for-byte unchanged. The test also reported every sweep within the budget. **This is an observed WASM runtime result, not a native filesystem or concurrent-worker result.**

Separate-process cursor persistence, concurrent counter increments and concurrent cleanup-plus-update stress were explicitly **BLOCKED / NOT RUN** because the test helper could not find a runnable child PHP executable. A cursor advancing over repeated calls in one WASM process does not satisfy the requested separate-process verification.

## Incomplete verification and remaining risks

1. **Runtime authorization behavior is unverified.** The requested synthetic Customer A/B checks remain necessary for profile, address, order/detail, payment, changed IDs, unauthenticated APIs, and unauthorized AJAX paths. No HTTP statuses/bodies or database before/after evidence were captured.
2. **Full workflow regressions are unverified.** Login/registration/OTP, guest-order recovery, address management, checkout/retry, payment submission, live support persistence, and admin workflows need execution against a disposable or staging database with synthetic data. The jsdom support-chat checks only cover mocked client behavior.
3. **Rate-limit deployment assumptions:** storage, queue state, queue jobs, index markers, and stable lock files live under the configured local log directory; PHP must support atomic same-filesystem `rename()` and `flock()` shared by workers, and the PHP account must create/chmod private `0700` directories and `0600` files. NFS/shared-host semantics must be verified on the actual host. The sequence queue avoids scan starvation and spends at most the configured job budget per sweep. Counters created by older versions are indexed when next touched; an untouched legacy counter is not discoverable by the new queue and may remain on disk, so monitor/plan one-time maintenance if legacy volume exists. Active counters keep one queue marker/job; expired ones remove both. Queue state is monotonic and marker/job storage tracks live indexed counters. Fail-closed tracking denies requests if counter storage or required queue registration fails.
4. **Legacy order references:** new references are unpredictable, but historical sequential references remain valid for existing orders. They are protected by shared limits, but do not gain the entropy of newly generated references. Database collision retries and the unique-index behavior still need a runtime DB test.
5. **Session-bound guest access:** legacy guest order visibility depends on the guest order ID list remaining in the same PHP session; session expiration or browser/device changes may prevent access and require the existing recovery/support workflow.
6. **Application-specific parser limits:** a successful `php-parser` syntax parse does not replace the target server's PHP version lint, extension checks, SQL execution, or integration testing.
7. **Existing authorized back-office paths:** admin, Telegram, courier, notification, and gateway lifecycle functions contain intentionally unscoped internal order reads. They were not broadly rewritten because those operations are not customer-facing selectors; their route permissions and callbacks still require deployment-specific verification.

## Phase status

**Independent approval readiness:** Phase 1 is **not approved or fully verified**. The repeated cleanup case passed only under PHP-WASM. Native cross-process/concurrent cleanup and counter behavior, the MySQL/HTTP Customer A/B matrix, anonymous support response parity, route-level tracking thresholds, and database/workflow regressions remain NOT RUN/BLOCKED. Independent approval requires those results from an isolated native PHP + disposable MySQL/staging environment. No Phase 2 work has begun.

## Project package

`SHOP_HAAT_PHASE_1_COMPLETE_PROJECT.zip` contains the repository's tracked project files plus all current working-tree changes, including this report, the cleanup/concurrency regression test, and `includes/order-tracking.php`. It excludes `.git`, local backups, runtime logs, uploaded customer/store data, and ignored installation secrets such as `config/database.php` and `config/installed.lock`; a fresh deployment must supply its own protected configuration and install state.
