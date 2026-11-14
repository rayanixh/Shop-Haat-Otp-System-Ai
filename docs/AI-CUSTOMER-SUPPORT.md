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

### Admin inbox: explicit join / ownership workflow

The shipped inbox already had a state machine but the actions were effectively invisible: `admin/support.php` did **not** emit `data-can-reply`, while `assets/js/support-admin.js` read `root.dataset.canReply === '1'` and hid the whole action bar, assignment controls and reply form whenever it was absent. That bug is fixed. On top of it:

* Every human-requested row now shows customer name (or **Guest customer**), latest-message preview, **request time** (the real `support_handoff_events.requested` timestamp, falling back to `created_at`), **last message time**, assignment status, human-support status, AI status (`Paused` / `Active`) and unread count. These are derived server-side by `sh_support_inbox_display()` and returned by both `admin_list` and `admin_thread`/`admin_poll`.
* The status labels are derived from the existing `status` / `ai_paused` / `assigned_admin_id` columns: `Pending human support` → **`In progress`** once an agent is assigned → `Closed / resolved`. No ENUM change or migration is needed, so existing installs are unaffected.
* Explicit actions: **Join Conversation**, **Assign to Me**, **Reply as Human**, **Leave Conversation**, **Close Conversation**, **Reopen Conversation**, plus the existing **Resume AI** (the **only** way AI resumes), Assign, Unassign and Take over.
* `join` / `assign_to_me` is an **atomic ownership claim**: the conversation row is locked with `SELECT ... FOR UPDATE`, then a conditional `UPDATE ... WHERE id = ? AND (assigned_admin_id IS NULL OR assigned_admin_id = 0) AND status NOT IN ('resolved','closed')` must change exactly one row. A second agent (even a superadmin) gets an honest 409 instead of silently taking over. `assign` now also refuses to overwrite a colleague's ownership for non-superadmins.
* Joining assigns the agent, sets `status = 'assigned'`, keeps `ai_paused = 1`, displays the agent name, and enables the human composer. **Reply as Human** claims an unowned pending thread first, then focuses a public reply. Agent replies append to the existing conversation with the correct sender name and timestamp; customer follow-ups are saved once, are delivered to the assigned inbox (`unread_for_admin = 1`), and never trigger AI, FAQ, fallback or a second handoff confirmation.
* `leave` releases ownership back to the pending queue with AI still paused; `close` sets `status = 'closed'`; `reopen` reopens with AI still paused. `api/support.php` has an explicit allowlist for all browser-driven transitions, still requires `sh_require_admin()`, `sh_admin_can(...)`, POST and CSRF, and every write uses prepared SQL.

### Human Support Notifications (SMS)

A compact **Human Support Notifications (SMS)** accordion now lives inside the existing **Customer chat and business details** panel on **Admin → Support Settings & FAQs**. It uses the original `.sh-panel`, `.sh-guide-accordion`, `.sh-grid2`, `.sh-field`, `.sh-btn`, `<details>/<summary>` and `.sh-support-settings__form` patterns: no new design system, no separate expanded section, no nested scroll containers and no excessive help text. Policy text, AI limits, Saved FAQs and the FAQ editor are collapsed by default and remain in their existing sections; only the selected SMS provider's fields are visible and submitted.

The feature is settings-backed: **no new table, column or migration**. It reuses the shipped SMS drivers (`sh_sms_deliver()` in `includes/phone-otp.php`), the existing `settings` table (including the additional `support_sms_test_diagnostic_id` value), the existing `support_handoff_events` unique `event_key` index for idempotency, and the existing `notification_logs` / `sh_notify_log()` logging when available. There is no second notification engine.

* Admin can enable/disable, set a support mobile number, choose a provider, enter credentials, **Save settings**, **Test SMS**, and **Clear result** — entirely from the UI, without editing PHP. SMS remains optional; customer chat, the admin inbox and human handoff continue working if SMS is disabled or unconfigured.
* Provider choices are limited to carriers this shared-hosting stack can actually call: **Offline / test mode**, **Use the store's configured SMS provider** (reuses `Security → SMS/OTP Provider` credentials), **TextBee** (dedicated API key; Device ID is optional), and **Custom SMS API** (dedicated HTTPS GET/POST JSON or form gateway with optional success marker). **Firebase is deliberately excluded** because Firebase Auth issues its own codes and cannot carry server-generated text.
* Secret fields are write-only: saved API keys, tokens, passwords, device IDs, request-body templates and header templates are never echoed into admin HTML or frontend JavaScript. A blank secret field keeps the stored value. Detailed provider responses, endpoint/method/auth labels, recipient masks, HTTP status, cURL errno/error, response excerpt and acceptance reason are available under **Admin → Security Logs → `support_sms_diagnostic`** and in the daily `app-*.log` channel `support-sms-diagnostic`. `sh_support_sms_scrub()` removes keys, tokens, long credential-like strings and phone numbers before anything is logged.
* **Test SMS is real and one-use.** The rendered form carries a one-use session nonce; a refresh/back-button resubmission cannot send a duplicate. Success is reported only when the provider confirms acceptance. Current TextBee documentation says a 200 response means *queued*, with `data.success: true` and an `smsBatchId`; the driver requires both, sends E.164 recipients to `POST https://api.textbee.dev/api/v1/gateway/send-sms` with `x-api-key`, and only includes `deviceId` in the JSON body when configured. A 200 is never reported as handset delivery. Generic HTTPS APIs can require a matching success field/value. Timeouts and HTTP 429 are not blindly retried.
* Useful failures replace the generic 500. `includes/errors.php` renders that page only after an uncaught exception/fatal; the previous SMS settings POST had no exception boundary, so any DB, cURL or provider failure could surface as the reported generic error. Save, test and send now catch exceptions, show an understandable admin message with a short diagnostic ID, and log the scrubbed technical detail. cURL errors distinguish cPanel outbound HTTPS failure, DNS failure, timeout and TLS/CA-bundle failure; HTTP 400/401/429/5xx have distinct explanations. Insecure plain-HTTP custom endpoints are rejected at save time and by the driver.
* The message is short and contains only a conversation reference plus a "customer waiting" note: `ShopHaat: a customer is waiting for human support. Conversation #N. Sign in to your Admin Inbox to reply.` No customer name, phone, email, message text, order, payment data, password, API key or private Admin URL is included.
* **One SMS per genuinely new request.** The claim key is derived from the AI → human ownership *cycle* (the same `resume_ai` counter as the one handoff confirmation), so the "Talk to a human" button and a message whose text asks for a human converge on the same claim. Retries, refreshes, later customer messages, join, reply, leave and close never notify again; a new request after the previous one was closed requires an explicit **Resume AI** and then produces exactly one new SMS.
* Saving configuration clears the previous connection/test result so a stale "connected" badge is never shown for credentials no longer in use.

**Reported error cause:** the exact deployed string was not present in this checkout and a local stubbed provider POST did not reproduce the generic 500. The concrete integration faults above are fixed, but the deployed cause still needs the real `logs/php-error.log` / `logs/app-*.log`, the real provider HTTP response, DB privileges and the failing cPanel request. The new secret-free diagnostic event and diagnostic ID are intended to make that attribution possible without hiding the error or faking success. **No provider credentials or delivery were invented.**

**Provider configuration still required from the store owner:** real credentials for TextBee or a custom HTTPS SMS gateway (or a real store SMS provider under Security), plus a successful **Test SMS** and a handset check on staging. No real provider credential, carrier acceptance or handset delivery was available or claimed in this environment.

### Chat composer sizing

The input type/padding and Send button text/icon/padding were reduced subtly (`13.5px` input, `12.8px` Send, 14px icons, `min-height: 40px` on both), keeping a comfortable touch target, one horizontal input + Send row on mobile, the orange focus/brand colour, the Bangla/Banglish/English placeholder and a balanced **Talk to a human** button. The viewport-fixed, bottom-anchored sheet, safe-area spacing and the rule that **only `.sh-schat-messages` scrolls** are unchanged; the page still scrolls normally.

### Admin URL

The Custom Admin Access URL is settings-backed (no new table/column), staged → verified → applied, and guarded server-side on every request. The admin now chooses a 3–80 character memorable path in any case; uppercase is normalized to lowercase and the complete origin + install-folder URL previews immediately. Random generation is optional, never the default or a requirement. Server-side authentication, Super Admin authorization, CSRF, secure sessions, the same-session ticket, atomic activation, the timezone fix and the old-route guarantee are unchanged. Full routing, recovery and `.htaccess` limitations are in [ADMIN-ACCESS-URL.md](ADMIN-ACCESS-URL.md).

## Files created / modified

**Support state machine:** `includes/ai-support.php` (shared state helpers, idempotent confirmation, gates on send/retry/reply/process/transition, transactional `assign`/`takeover`), `api/support.php` (shared gate), `assets/js/support.js` (no saved-message notices), `support.php` and `assets/css/support.css` (section + empty space removal), `admin/_layout.php` (initialized, permission-gated support badge).

**Admin URL / hardening:** `includes/admin-access.php` (dispatcher exception, pending/active recognition, reserved exact names and root PHP page collisions), `config/config.php` (bootstrap guard), `admin/security.php` (proposed URL copy control + runbook pointer), `includes/admin-auth.php` and `includes/admin-perms.php` (security logging of unauthenticated and RBAC denials), `admin/_layout.php` (guarded support badge), `docs/ADMIN-ACCESS-URL.md`.

**Admin inbox / human ownership:** `includes/ai-support.php` (derived inbox fields, `join`/`assign_to_me`/`leave`/`close` transitions, atomic ownership claim, SMS hook on both handoff paths), `api/support.php` (transition allowlist), `admin/support.php` (`data-can-reply` fix + explicit actions + state grid), `assets/js/support-admin.js` (inbox fields, action wiring, human composer), `assets/css/support-admin.css` (state grid, action bar, mobile layout).

**Human Support Notifications:** `includes/support-sms.php` (settings, credential mapping, idempotent claim, one-use Test SMS nonce, redacted Admin diagnostics, exception-safe save/test/send), `includes/phone-otp.php` (`sh_sms_deliver()` with credential overrides, current TextBee account endpoint, explicit provider acceptance, cURL/SSL/HTTP diagnostics), `admin/support-settings.php` (compact existing accordion with provider-hidden fields and CSRF-protected save/test/clear handlers), `tests/support-sms-notifications.php` and `tests/support-sms-transport.php`.

**Chat composer:** `assets/css/app.css`, `includes/support-widget.php`, `tests/support-chat-behavior.mjs`.

**Admin URL activation:** `includes/admin-access.php` (timezone-independent verification marker, single atomic activation with DB read-back, compensating rollback, reason codes, secure logging), `admin/security.php` (useful activation errors), `docs/ADMIN-ACCESS-URL.md`, `tests/admin-access-url.php`, `tests/admin-access-regression.mjs`.

**Corrective UI / SMS / custom-URL pass:** `admin/support-settings.php` (restored original compact accordion layout, provider-hidden SMS fields and exception-safe POST handlers), `assets/css/support-admin.css` (removed the SMS-specific alert design; kept ordinary document flow), `assets/js/support-settings.js` (hash-based accordion opening and provider panel toggles), `includes/support-sms.php` (one-use Test SMS nonce, redacted diagnostics, exception boundaries, optional TextBee device ID), `includes/phone-otp.php` (current TextBee account endpoint, explicit acceptance, cURL/SSL/HTTP diagnostics, by-reference custom driver registry), `includes/admin-access.php` (flexible 3–80 path, uppercase normalization, reserved/root-page collisions), `admin/security.php` (live complete URL preview, optional suggestion), `assets/js/admin-access.js` (new frontend validation/preview), `.htaccess`, `router.php`, `tests/support-sms-transport.php`, `tests/admin-access-preview-behavior.mjs`, `tests/support-settings-ui-behavior.mjs`, `tests/_support_db_bootstrap.php`, updated PHP/JS suites, docs and CI. **No database schema or migration changes.**

**Schema / tests:** `install/schema.php` (aligned legacy `support_conversations.last_message_at` and `support_faqs` defaults with the additive upgrader), `tests/_support_db_bootstrap.php`, `tests/human-support-handoff.php`, `tests/admin-access-url.php`, `tests/security-regression.php`, `tests/support-unit.php`, `tests/support-chat-optimistic-behavior.mjs`, `docs/AI-CUSTOMER-SUPPORT.md`, and `.github/workflows/reporting-integration.yml` (new suites wired into the security job).

## Migration and rollback

* **No migration is required for this change, including the corrective UI/SMS/URL pass.** SMS diagnostics use existing `settings`, `security_logs`, `notification_logs` and `app-*.log` surfaces; no DDL, column, index, ENUM value or SQL migration was added. The admin inbox, ownership actions and the entire Human Support Notifications feature reuse existing tables (`settings`, `support_conversations`, `support_messages`, `support_handoff_events`, `notification_logs`) and existing columns; no ENUM value, table or index was added. The custom URL reuses the existing `settings` keys (`admin_access_enabled`, `admin_access_secret_path`, `admin_access_pending_secret`, `admin_access_verified_secret/_at/_by`). The support state machine reuses `support_conversations.status`, `ai_paused`, `assigned_admin_id`, `unread_for_admin/_customer` and the existing `support_handoff_events` unique `event_key`/`legacy_event_id` indexes.
* Existing stores still need the previously shipped additive support upgrader (`ai/support-schema.php` / `install/migrations/2026-10-ai-customer-support.sql`) if they have not run it. It only adds columns/indexes and backfills; it never drops rows. `install/schema.php` was aligned so a fresh install and an upgraded install produce the same nullable/default definitions.
* **Rollback for admin URL:** an authenticated Super Admin presses **Disable and clear custom route**, or the hosting owner runs the documented phpMyAdmin SQL. No password, token or unauthenticated recovery endpoint is created. See the runbook.
* **Rollback for support:** `install/migrations/2026-10-ai-customer-support-rollback.sql` remains non-destructive; it disables chat/AI without deleting conversations, messages, events, FAQs or metrics. Do not `DROP TABLE` support tables.

## Tests actually executed

Run in this session with the PHP 8.5 WASM CLI (`@php-wasm/cli 3.1.62`), Node.js 22 and jsdom 26.1.0. The recreated `/tmp/sh_final_check.sh` full matrix finished with **`OVERALL_RC=0`**. No native `php`, MySQL/MariaDB server, web server, browser, cPanel account or provider credential was available.

| Command | Result |
| --- | --- |
| `php -l` on every PHP file (WASM CLI) | No syntax errors. PHP 8.5 emits pre-existing `$http_response_header` deprecation notices in seven unrelated files; they are not failures. |
| `node --check` on all shipped JS and test MJS files | No syntax errors. |
| `git diff --check` | Clean. |
| `config/database.php` tracking check | Not tracked. |
| `php tools/security-scan.php --fail-on=high` | `critical 0 high 0 medium 0 low 0`, 180 files, no findings. |
| `php tests/security-regression.php` | `passed 185 failed 0 skipped 1` (header-emission check not applicable in CLI). |
| `php tests/support-unit.php` | `Passed: 58 Failed: 0`. |
| `php tests/human-support-handoff.php` | `passed 184 failed 0`, real PHP service functions + real PDO SQL on **SQLite** with MySQL SQL translated. |
| `php tests/support-sms-notifications.php` | `passed 110 failed 0` — one SMS per request (both handoff paths), provider acceptance/rejection, diagnostics, one-use Test SMS nonce, disabled/misconfigured channel never breaking chat, secret-free body/logs, no duplicate after close/reopen/Resume AI. **Explicit test transport; no real carrier.** |
| `php tests/support-sms-transport.php` | `passed 25 failed 0` — real driver code with outbound HTTP stubbed: current TextBee endpoint, explicit acceptance, redacted diagnostics, cURL/SSL/HTTP errors, HTTPS-only generic APIs and custom-driver cfg. **No real carrier.** |
| `SH_TEST_PHP_BIN=… php tests/admin-access-url.php` | `passed 249 failed 0`, real PDO + isolated PHP subprocesses for 404/auth guards; includes flexible short paths, uppercase normalization, live preview wiring and a short-path atomic activation lifecycle. |
| `node tests/admin-access-regression.mjs` | `13 passed, 0 failed`. |
| `node tests/admin-access-preview-behavior.mjs` | `31 passed, 0 failed` — live complete URL preview, uppercase normalization, Copy full URL, frontend rejection, optional suggestion. |
| `node tests/support-settings-ui-behavior.mjs` | `18 passed, 0 failed` — compact accordion, section hash opening, provider-hidden fields, no nested scroll, no secret echoes. |
| `node tests/support-admin-inbox-behavior.mjs` | `51 passed, 0 failed` — real admin inbox JS in jsdom: all inbox fields, Join → In progress, agent name, human composer, Leave/Close/Reopen, CSRF and polling. |
| `node tests/support-chat-behavior.mjs` | `58 passed, 0 failed`. |
| `node tests/support-chat-optimistic-behavior.mjs` | `63 passed, 0 failed`. |
| `node tests/admin-notification-regression.mjs` | `5 passed, 0 failed`. |
| `node tests/navigation-drawer-behavior.mjs` | `22` drawer checks passed. |
| `node tests/no-autosave-behavior.mjs` | `30 passed, 0 failed`. |
| `node tests/provider-identity-assets.mjs` | `5` provider identity checks passed. |
| `node tests/provider-test-behavior.mjs` | `38 passed, 0 failed`. |
| `node tests/page-transition-behavior.mjs` (jsdom + fixture renderer) | `passed 80 failed 0`. |
| `node tests/slider-behavior.mjs` (jsdom + fixture renderer) | `passed 44 failed 0`. |

`tests/human-support-handoff.php` covers one confirmation, silent follow-ups, duplicate client IDs, repeated handoff, the live-DB AI gate, admin reply visibility, Resume AI and a second genuine cycle, agent takeover, legacy backfill, and the `assign`/`resolve`/`reopen`/`resume_ai` confirmation audit. `tests/admin-access-url.php` covers flexible validation, reserved/root-page/filesystem/live-slug collisions, optional CSPRNG, short-path activation, the no-lockout lifecycle, link rewriting, 404s, unauthenticated admin denial, independent API/endpoint guards and secret-free public files.

**These are not MySQL, HTTP or real-browser tests.** The SQLite translation cannot prove InnoDB `FOR UPDATE`, `ON DUPLICATE KEY UPDATE`, `UTC_TIMESTAMP()`, `information_schema` or foreign-key behavior under real concurrency. `SH_TEST_MYSQL_DSN` is provided for running the same suites against real MySQL/MariaDB. The jsdom harness checks fixed positioning, viewport metrics and messaging logic at source/DOM level, but cannot prove a narrow Android viewport, different viewport heights, address-bar expansion/collapse, safe-area insets, stacking order or actual touch scrolling — those still need a real device/browser. No live HTTP login, custom-route activation round trip, direct-admin/AJAX/upload/export request, storefront/checkout test or cPanel run was performed. DB/HTTP suites that printed the installer page or timed out here (`tests/support-system.php`, `reporting-integration.php`, `installer-demo-data.php`, `email-system.php`, `product-ai-save.php`, `courier-connection-tests.php`, `seo-content-length.php`, the SMTP/HTTP shell tests) were **not** run successfully and are not claimed as passing.

## Remaining issues / still required on staging

* Real PHP 8.1/8.2 + MySQL 8 / MariaDB run of `tests/human-support-handoff.php`, `tests/admin-access-url.php`, `tests/support-system.php` and the existing CI workflow.
* Real Apache/LiteSpeed/cPanel HTTP verification: log in through the new URL, old `/admin.php` and direct `/admin/*.php` return 404, assets/forms/AJAX/uploads/exports/internal links work, unauthenticated customers are denied on every admin action, and the phpMyAdmin recovery SQL restores the default route.
* Concurrency test of `sh_support_confirm_handoff_once()` against InnoDB (two simultaneous handoffs must still produce one row).
* Browser verification that the removed contact section leaves no empty band on desktop/mobile and that no saved-message notice reappears.
* Storefront regression: product, cart, checkout, customer login/registration, orders, payments, AI support and admin panel over real HTTP.
* cPanel hosts without `mod_rewrite` / `AllowOverride All` cannot use the custom URL; the standard route remains available. Static `.htaccess` cannot be DB-conditional, so the PHP bootstrap guard is mandatory.
* Provider text-generation test with real credentials, which is deliberately never assumed working.
* **Human-support SMS:** the store owner must enter real TextBee / custom HTTPS gateway credentials (or configure a real store SMS provider under Security), run **Test SMS** on staging, confirm handset delivery, and then verify one SMS for a real customer handoff with no duplicate on follow-up messages. No real provider credential, carrier acceptance or handset delivery was tested in this environment.
* **Actual deployed SMS 500:** collect `logs/php-error.log`, `logs/app-*.log`, the failing request's `support_sms_diagnostic` event, the provider HTTP status/response and the DB user's privileges before declaring the precise production cause. The local environment reproduced the successful stubbed POST, not the deployed generic 500.
* **Deployed Admin URL:** real MySQL/InnoDB transaction behaviour (`FOR UPDATE`, `ON DUPLICATE KEY UPDATE`) and a live cPanel/Apache/LiteSpeed GET → activation POST round trip with cookies/CSRF/ticket, phpMyAdmin DB read-back, and navigation/forms/AJAX/uploads/security settings through the new route are still untested. Local PHP-WASM/PDO-SQLite and source-level checks cannot prove those.

## Staging acceptance checklist

* Send a customer chat message; verify the customer message and reply persist after refresh and that guest and signed-in histories do not cross accounts.
* Turn AI off and repeat. Verify deterministic FAQ/catalog/order behavior and a clear unavailable message; no provider request should be needed.
* Test an active product, an inactive product, stock/price display, and an unknown product. Only current active catalog records should produce product cards.
* Test an account order, a guest order from its original browser session, and an order ID/number entered from another browser/session. Verify the latter returns no private order facts. Check that no order/payment/cart/inventory state changes.
* Request human support. Confirm **exactly one** localized confirmation, one marker event, pending queue state and AI pause. Assign the thread, send a public reply, add an internal note, resolve, reopen, and explicitly resume AI. Verify internal notes never appear in the customer transcript.
* After handoff, send several customer follow-ups, retry after refresh and from two tabs. Every customer message is saved once, no AI/FAQ/fallback reply or repeated handoff notice is created, unread/assignment/history remain intact, and only an authorized **Resume AI** resumes automatic replies.
* Confirm that only support-authorized admins can access inbox/settings APIs, that CSRF and cross-origin POST checks reject invalid requests, and that input/rate limits fail safely.
* Check desktop and mobile page flow. The dedicated support page transcript and the admin inbox/history grow with the document and use explicit **Load older messages** batches — no nested or fixed-height scrolling areas, and no leftover contact section. The **floating widget** is the intentional exception: it is viewport-fixed and bottom-anchored above the mobile bottom navigation (with safe-area insets); only its message log scrolls internally, scrolling the website behind it must not move the popup, and input/Send/**Talk to a human** stay usable.
* In Support Settings, expand only the SMS accordion and switch providers: verify that TextBee, custom HTTP, store and offline fields are hidden/disabled until selected, that no secret value is visible in HTML, and that desktop/mobile Settings use normal document flow with no internal scrolling box.
* With real credentials, run **Test SMS** once and check the secure diagnostic log for HTTP status, cURL errors and a redacted provider response. Confirm no duplicate on refresh/back-button, useful failures for wrong credentials/outbound HTTPS blocks, human support with SMS disabled, and one notification with no duplicate follow-ups after a real handoff.
* Choose short and longer custom Admin paths including uppercase input, verify the complete lowercase URL preview and Copy full URL, then Save Proposed Route → Verify and Activate. Confirm the old URL works until activation succeeds and that the path can be changed again through the same secure process.
* Re-test ordinary account, checkout, payment, order, tracking and inventory workflows, plus the admin URL checks in [ADMIN-ACCESS-URL.md](ADMIN-ACCESS-URL.md).
