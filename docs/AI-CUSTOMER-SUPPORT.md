# AI Customer Support — human handoff, customer UI and deployment notes

This guide covers the customer-support work in the existing ShopHaat PHP + MySQL/MariaDB application: the human-support state machine, the single handoff confirmation, the customer chat page cleanup, and the Custom Admin Access URL. See [ADMIN-ACCESS-URL.md](ADMIN-ACCESS-URL.md) for the full admin-URL runbook. No Node.js, Composer, Redis, queue, worker, Docker, SSH or paid service is required at runtime.

## Root cause of the human-support bug

The active chat is `api/support.php` → `includes/ai-support.php` (not the older `includes/support.php`, which is retained only for legacy/test compatibility). Before this change:

* human ownership was inferred in several places from `ai_paused`/`status`/`assigned_admin_id` with slightly different rules, so a retry, a second tab, or an agent takeover could re-enter the AI/FAQ/fallback pipeline;
* the handoff confirmation was written unconditionally on each handoff path, with no durable "already sent" record, so refresh/retry/concurrent requests and repeated customer messages could produce extra notices;
* the chat UI also rendered "message saved for support" text while a human owned the thread, which looked like a second automatic reply;
* `assign` did not run the confirmation path at all, so a direct AI → agent transfer could happen without the one expected notice.

**Fix:** one shared ownership gate, one idempotent handoff-event marker, and one transactional confirmation write. Hiding a UI string alone would not have fixed the backend.

## Exact state-machine changes

No new conversation columns and no new migration were added. The four requested states are derived from the existing DB architecture by `sh_support_human_state()`:

| Derived state | Existing columns |
| --- | --- |
| `ai_active` | `status = 'open'`, `ai_paused = 0`, `assigned_admin_id` empty |
| `human_requested` | `ai_paused = 1` or `status = 'pending'` |
| `human_active` | `assigned_admin_id > 0` or `status = 'assigned'` |
| `resolved` | `status IN ('resolved','closed')` |

`sh_support_human_controlled()` is true for everything except `ai_active`. It is now used by:

* `sh_support_customer_send()` — saves the customer row with `reply_status = 'none'`, updates `subject`/`last_message_at`/`unread_for_admin`, keeps assignment/status/AI-pause untouched, and **returns before** any AI, FAQ, fallback, handoff event or system notice;
* `sh_support_customer_retry()` — an already-saved human-owned row is a 409 no-op or a `human_controlled` acknowledgement; a forced `pending` row is reset to `none` without invoking AI;
* `sh_support_process_customer_message()` — re-reads the live conversation row and, if a human now owns it, marks pending customer rows `reply_status = 'paused'` and returns `['paused' => true]` before inference. This closes the race where a queued AI turn starts after a takeover;
* `api/support.php` — uses the same shared gate for its legacy footer-widget protocol, so both customer UIs behave identically;
* `sh_support_admin_reply()` / `sh_support_admin_transition()` — agent replies, `takeover` and `assign` use the same confirmation helper.

### One-time confirmation enforcement

* `sh_support_handoff_confirmation_key($id)` derives a deterministic 36-character `event_key` from `hash('sha256', 'shophaat-handoff-confirmation|<id>|<cycle>')`. `cycle` counts `resume_ai` / `ai_resumed` events, so an explicitly authorized Resume AI starts a new ownership cycle and a later genuine handoff can send one new notice.
* `sh_support_confirm_handoff_once()` runs **inside the same transaction that holds `SELECT ... FOR UPDATE`** on the conversation row. It inserts the marker with `INSERT ... ON DUPLICATE KEY UPDATE id = id` against the existing unique `support_handoff_events.event_key` index. A duplicate writer is a no-op; it never inserts a second notice.
* The public confirmation message is written only when `$before` (read under that lock) was `ai_active`. A legacy thread already handed off before this marker existed gets the marker but **no duplicate message** — its history is preserved verbatim.
* The genuine AI → human transitions are: a customer handoff request (including handoff intent in their message), an explicit `admin_transition('takeover')`, a first public agent reply, and a direct `admin_transition('assign')`. Refresh, retry, duplicate `client_message_id`, repeated handoff, `unassign`, `resolve`, `reopen` and `resume_ai` add no notice.
* `resume_ai` clears the assignee and sets `status = 'open', ai_paused = 0`. It is the **only** path that resumes automatic replies, and `api/support.php` keeps it `admin_`-prefixed, POST + CSRF protected, and gated by `sh_require_admin()` / `sh_admin_can('support.view','support.reply','support.manage')`.

### Customer interface change

The secondary **Contact Support** section on `support.php` (heading, explanatory copy, name/email/subject/message form, "Send message" button and its server-side POST mail handler) was removed, along with the trailing empty layout space (`.sh-support-chat { margin: 0 }`). The conversation, the **Request human support** button, the admin support inbox and customer-to-agent messaging are untouched. The three "saved for support" strings were removed from `assets/js/support.js`; the only notice a customer sees during handoff is the one server-side confirmation.

### Admin URL

The Custom Admin Access URL is settings-backed (no new table/column), staged → verified → applied, and guarded server-side on every request. Full routing, recovery and `.htaccess` limitations are in [ADMIN-ACCESS-URL.md](ADMIN-ACCESS-URL.md).

## Files created / modified

**Support state machine:** `includes/ai-support.php` (shared state helpers, idempotent confirmation, gates on send/retry/reply/process/transition, transactional `assign`/`takeover`), `api/support.php` (shared gate), `assets/js/support.js` (no saved-message notices), `support.php` and `assets/css/support.css` (section + empty space removal), `admin/_layout.php` (initialized, permission-gated support badge).

**Admin URL / hardening:** `includes/admin-access.php` (dispatcher exception, pending/active recognition, wider reserved prefixes), `config/config.php` (bootstrap guard), `admin/security.php` (proposed URL copy control + runbook pointer), `includes/admin-auth.php` and `includes/admin-perms.php` (security logging of unauthenticated and RBAC denials), `admin/_layout.php` (guarded support badge), `docs/ADMIN-ACCESS-URL.md`.

**Schema / tests:** `install/schema.php` (aligned legacy `support_conversations.last_message_at` and `support_faqs` defaults with the additive upgrader), `tests/_support_db_bootstrap.php`, `tests/human-support-handoff.php`, `tests/admin-access-url.php`, `tests/security-regression.php`, `tests/support-unit.php`, `tests/support-chat-optimistic-behavior.mjs`, `docs/AI-CUSTOMER-SUPPORT.md`, and `.github/workflows/reporting-integration.yml` (new suites wired into the security job).

## Migration and rollback

* **No migration is required for this change.** The custom URL reuses the existing `settings` keys (`admin_access_enabled`, `admin_access_secret_path`, `admin_access_pending_secret`, `admin_access_verified_secret/_at/_by`). The support state machine reuses `support_conversations.status`, `ai_paused`, `assigned_admin_id`, `unread_for_admin/_customer` and the existing `support_handoff_events` unique `event_key`/`legacy_event_id` indexes.
* Existing stores still need the previously shipped additive support upgrader (`ai/support-schema.php` / `install/migrations/2026-10-ai-customer-support.sql`) if they have not run it. It only adds columns/indexes and backfills; it never drops rows. `install/schema.php` was aligned so a fresh install and an upgraded install produce the same nullable/default definitions.
* **Rollback for admin URL:** an authenticated Super Admin presses **Disable and clear custom route**, or the hosting owner runs the documented phpMyAdmin SQL. No password, token or unauthenticated recovery endpoint is created. See the runbook.
* **Rollback for support:** `install/migrations/2026-10-ai-customer-support-rollback.sql` remains non-destructive; it disables chat/AI without deleting conversations, messages, events, FAQs or metrics. Do not `DROP TABLE` support tables.

## Tests actually executed

Run in this session with the PHP 8.5 WASM CLI (`@php-wasm/cli 3.1.62`), Node.js 22 and jsdom 26.1.0. No native `php`, MySQL/MariaDB server, web server, browser or provider credential was available.

| Command | Result |
| --- | --- |
| `php -l` on every PHP file (WASM CLI) | No syntax errors. PHP 8.5 emits pre-existing `http_response_header` deprecation notices in unrelated files. |
| `php tools/security-scan.php --fail-on=high` | `critical 0 high 0 medium 0 low 0`, 179 files. |
| `php tests/security-regression.php` | `passed 185 failed 0 skipped 1` (header-emission check not applicable in CLI). |
| `php tests/support-unit.php` | `Passed: 58 Failed: 0`. |
| `php tests/human-support-handoff.php` | `passed 117 failed 0`, real PHP service functions + real PDO SQL on **SQLite** with MySQL SQL translated. |
| `SH_TEST_PHP_BIN=… php tests/admin-access-url.php` | `passed 164 failed 0`, real PDO + 9 isolated PHP subprocesses for 404/auth guards. |
| `node --check assets/js/support.js` | OK. |
| `node tests/admin-access-regression.mjs` | `11 passed, 0 failed`. |
| `node tests/admin-notification-regression.mjs` | `5 passed, 0 failed`. |
| `node tests/provider-identity-assets.mjs` | `5 passed`. |
| `node tests/support-chat-behavior.mjs` | All checks passed. |
| `node tests/support-chat-optimistic-behavior.mjs` | `63 passed, 0 failed`. |
| `node tests/page-transition-behavior.mjs` (jsdom + fixture renderer) | `passed 80 failed 0`. |
| `node tests/slider-behavior.mjs` (jsdom + fixture renderer) | `passed 44 failed 0`. |
| `node tests/navigation-drawer-behavior.mjs`, `no-autosave-behavior.mjs`, `provider-test-behavior.mjs` | All checks passed. |

`tests/human-support-handoff.php` covers one confirmation, silent follow-ups, duplicate client IDs, repeated handoff, the live-DB AI gate, admin reply visibility, Resume AI and a second genuine cycle, agent takeover, legacy backfill, and the `assign`/`resolve`/`reopen`/`resume_ai` confirmation audit. `tests/admin-access-url.php` covers validation, collisions, CSPRNG, the no-lockout lifecycle, link rewriting, 404s, unauthenticated admin denial, independent API/endpoint guards and secret-free public files.

**These are not MySQL, HTTP or real-browser tests.** The SQLite translation cannot prove InnoDB `FOR UPDATE`, `ON DUPLICATE KEY UPDATE`, `UTC_TIMESTAMP()`, `information_schema` or foreign-key behavior under real concurrency. `SH_TEST_MYSQL_DSN` is provided for running the same suites against real MySQL/MariaDB. The jsdom harness checks fixed positioning, viewport metrics and messaging logic at source/DOM level, but cannot prove a narrow Android viewport, different viewport heights, address-bar expansion/collapse, safe-area insets, stacking order or actual touch scrolling — those still need a real device/browser. No live HTTP login, custom-route activation round trip, direct-admin/AJAX/upload/export request, storefront/checkout test or cPanel run was performed. DB/HTTP suites that printed the installer page or timed out here (`tests/support-system.php`, `reporting-integration.php`, `installer-demo-data.php`, `email-system.php`, `product-ai-save.php`, `courier-connection-tests.php`, `seo-content-length.php`, the SMTP/HTTP shell tests) were **not** run successfully and are not claimed as passing.

## Remaining issues / still required on staging

* Real PHP 8.1/8.2 + MySQL 8 / MariaDB run of `tests/human-support-handoff.php`, `tests/admin-access-url.php`, `tests/support-system.php` and the existing CI workflow.
* Real Apache/LiteSpeed/cPanel HTTP verification: log in through the new URL, old `/admin.php` and direct `/admin/*.php` return 404, assets/forms/AJAX/uploads/exports/internal links work, unauthenticated customers are denied on every admin action, and the phpMyAdmin recovery SQL restores the default route.
* Concurrency test of `sh_support_confirm_handoff_once()` against InnoDB (two simultaneous handoffs must still produce one row).
* Browser verification that the removed contact section leaves no empty band on desktop/mobile and that no saved-message notice reappears.
* Storefront regression: product, cart, checkout, customer login/registration, orders, payments, AI support and admin panel over real HTTP.
* cPanel hosts without `mod_rewrite` / `AllowOverride All` cannot use the custom URL; the standard route remains available. Static `.htaccess` cannot be DB-conditional, so the PHP bootstrap guard is mandatory.
* Provider text-generation test with real credentials, which is deliberately never assumed working.

## Staging acceptance checklist

* Send a customer chat message; verify the customer message and reply persist after refresh and that guest and signed-in histories do not cross accounts.
* Turn AI off and repeat. Verify deterministic FAQ/catalog/order behavior and a clear unavailable message; no provider request should be needed.
* Test an active product, an inactive product, stock/price display, and an unknown product. Only current active catalog records should produce product cards.
* Test an account order, a guest order from its original browser session, and an order ID/number entered from another browser/session. Verify the latter returns no private order facts. Check that no order/payment/cart/inventory state changes.
* Request human support. Confirm **exactly one** localized confirmation, one marker event, pending queue state and AI pause. Assign the thread, send a public reply, add an internal note, resolve, reopen, and explicitly resume AI. Verify internal notes never appear in the customer transcript.
* After handoff, send several customer follow-ups, retry after refresh and from two tabs. Every customer message is saved once, no AI/FAQ/fallback reply or repeated handoff notice is created, unread/assignment/history remain intact, and only an authorized **Resume AI** resumes automatic replies.
* Confirm that only support-authorized admins can access inbox/settings APIs, that CSRF and cross-origin POST checks reject invalid requests, and that input/rate limits fail safely.
* Check desktop and mobile page flow. The dedicated support page transcript and the admin inbox/history grow with the document and use explicit **Load older messages** batches — no nested or fixed-height scrolling areas, and no leftover contact section. The **floating widget** is the intentional exception: it is viewport-fixed and bottom-anchored above the mobile bottom navigation (with safe-area insets); only its message log scrolls internally, scrolling the website behind it must not move the popup, and input/Send/**Talk to a human** stay usable.
* Re-test ordinary account, checkout, payment, order, tracking and inventory workflows, plus the admin URL checks in [ADMIN-ACCESS-URL.md](ADMIN-ACCESS-URL.md).
