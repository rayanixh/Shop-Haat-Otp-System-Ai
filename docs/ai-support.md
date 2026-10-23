# AI Customer Support

## 1. Purpose and safety boundary

The support system adds a persistent customer chat, verified store-data answers,
an optional AI wording layer, and a human support inbox to the existing
PHP/MySQL storefront. It requires no Node.js, Redis, Docker, WebSocket server,
queue worker, or paid API at runtime.

The most important boundary is deliberate:

- PHP tools read products, stock, policies, FAQs, orders, and shipments.
- The AI model never receives database credentials and never runs SQL.
- Order help is read-only. The assistant cannot cancel, refund, edit an
  address, change a payment, reserve stock, or alter an order.
- Product, price, stock, and order claims must come from verified facts built
  by PHP. The model may only phrase a reply around those facts.
- If AI is disabled, unconfigured, over its daily cap, unavailable, too slow,
  or rejected by the factual guard, the customer still receives the
  deterministic verified answer or can reach a human.

Deterministic replies are stored as `sender = system` with
`facts_json.kind = assistant`. Only text actually returned by a model is
stored as `sender = ai`. The storefront labels those differently from a human
agent.

## 2. Architecture

### Main files

| File | Responsibility |
| --- | --- |
| `includes/support.php` | Core service: schema ensure, identity, conversations, deterministic intents/tools, AI routing/guard, customer pipeline, admin actions, rate limits, usage, purge, and statistics. |
| `install/schema.php` | Additive `sh_support_schema_sql()` definitions used by the installer and lazy runtime ensure. |
| `api/support.php` | Thin CSRF-protected storefront JSON endpoint over the core pipeline. |
| `includes/support-widget.php` | Server-rendered launcher/panel markup when support is enabled. |
| `assets/js/support-chat.js` | Storefront chat behaviour, safe text rendering, retries, suggestions, handoff, and bounded polling. |
| `assets/css/app.css` | Support widget styling in the existing design system. |
| `admin/support.php` | Searchable/filterable support inbox. |
| `admin/support-conversation.php` | Full thread, internal notes, takeover/resume, assignment, status, customer/product/order context, events, and admin polling endpoint. |
| `admin/support-settings.php` | General, language, AI, policy, copy, FAQ, usage, live-test, and retention controls. |
| `assets/js/support-admin.js` | Bounded polling for the open admin conversation page. |
| `ai/ProviderManager.php` | Existing provider abstraction plus the routable `support_chat` task. |
| `ai/providers/*.php` | Existing provider adapters; support can pass its own bounded timeout. |

### Request flow

1. The widget calls `api/support.php` using POST and the normal storefront
   CSRF token.
2. The API resolves identity from the signed-in customer, or from a random
   guest token kept in the PHP session. Only the SHA-256 hash of a guest token
   is stored on a conversation.
3. Rate limits run before the pipeline. A transaction then locks the
   conversation row, checks ownership and `client_msg_id`, stores the customer
   message, and commits.
4. If a human has paused AI for that conversation, no automatic reply is
   attempted; the saved customer message remains for the agent.
5. Otherwise the deterministic resolver detects the latest language and intent
   and invokes only the required read-only PHP tools.
6. The deterministic composer always produces a complete fallback answer from
   verified facts.
7. If AI is allowed for that intent and a usable route exists, the selected
   provider is called outside the database transaction with a bounded system
   prompt, verified facts, and bounded public history.
8. The model response must pass the factual guard. On provider failure one
   configured text fallback provider may be tried when the first error is
   retryable. Any final failure keeps the deterministic reply.
9. A second short transaction re-locks the conversation and checks
   `ai_paused` again. If an admin took over while the provider was running,
   the automatic reply is discarded rather than creating a double reply.

No database transaction is held during a provider HTTP request.

### Storefront API

`api/support.php` is an internal storefront endpoint, not a public integration
API. All actions are POST-only and CSRF-protected:

| Action | Main fields | Purpose |
| --- | --- | --- |
| `start` | — | Find the current open/pending conversation or create one and add the welcome message. |
| `new` | — | Create a fresh conversation for the current identity. |
| `send` | `conversation_id`, `message`, `client_msg_id`, `after_id` | Store an idempotent customer message and return new public messages. |
| `poll` | `conversation_id`, `after_id` | Return public messages after a known ID. |
| `handoff` | `conversation_id`, `after_id` | Pause AI, mark the conversation pending, and notify the customer in-thread. |

Public messages expose only `id`, `kind`, `ai`, `body`, and `at`. Internal
notes, admin IDs, facts JSON, provider metadata, and guest-token hashes are
not returned to the customer.

## 3. Languages, context, intents, and tools

### Languages

Each customer message is classified as:

- `bn` when Bengali script is present;
- `banglish` when common Bengali words written in Latin letters are detected;
- otherwise `en`.

The enabled-language setting can restrict replies. The reply follows the
customer's latest detected language. This is a lightweight heuristic, not a
general translation engine; very short mixed messages can occasionally be
classified differently from what a human would choose.

### Conversation context

The conversation stores the latest discussed product and order number. This
allows follow-ups such as:

- “Agertar blue color ta dekhaw” — search for the colour-named alternative to
  the previously discussed product.
- “dam koto?” — answer using the previously discussed product.
- An order number followed in the next message by only the checkout phone.

If a colour variant is not a separate product row, the assistant does not
claim variant-level availability. This store's catalog has product-level stock,
not a separate variant-stock table. Size replies therefore direct the customer
to the size options on the product page and state that the displayed stock is
for the product overall.

### Deterministic intents

The no-AI engine handles greetings, thanks/goodbye, human requests, order
status, delivery/return/payment policies, product search, product follow-up,
FAQ keyword matches, colour/size follow-ups, and a safe fallback. Exact store
policy text configured by an admin wins over FAQ/generic wording. The engine
never guesses a delivery date.

### Product tool

The product tool searches active products by name/SKU in bounded tiers and
returns at most five results to the resolver. Facts may include:

- product name and same-site product URL;
- current price and compare price formatted with the store money helper;
- `in_stock`, `low_stock` with remaining count, `out_of_stock`, or
  `unavailable`;
- short description and a bounded specifications excerpt for the AI prompt.

An out-of-stock or unavailable primary product forces the deterministic reply;
the model is not allowed to soften availability language.

### Order tool and authorization

The chat mirrors the public order-tracking security bar:

- A signed-in customer may access an order whose `orders.user_id` matches the
  account.
- A signed-in customer may also access it when the order number/reference is
  identified and the account phone matches the checkout phone.
- A guest must provide both an order reference and the checkout phone.

An order number alone and a phone number alone are never sufficient. A numeric
order ID can identify an order for lookup, but it does not change the
authorization requirement. Failed phone verification receives the generic
not-matched answer, and phone attempts for one conversation are limited.

When no reference is given, a signed-in customer asking about “my order” can
receive their latest account order. A guest is asked for an order number.

Authorized facts are intentionally minimal: order number, localized status,
payment status, placement date, item names/quantities, total/delivery fee,
and the latest shipment's courier/tracking/status/COD/delivery timestamp when
present. Address and unrelated customer/order fields are not sent through the
chat tool.

## 4. AI mode, no-AI mode, and the factual guard

| Situation | Result |
| --- | --- |
| AI disabled in Support Settings | Deterministic verified reply. |
| AI module/tables not installed | Deterministic verified reply. |
| No enabled provider, key, or model | Deterministic verified reply. |
| Provider configured but not live-tested | Settings page says it is configured but not yet proof of operation. Runtime may try it and fall back safely on failure. |
| Provider succeeds and output passes guard | Reply stored as `sender = ai` and labelled “AI assistant”. |
| Provider times out/errors | One retryable fallback-provider attempt if configured; otherwise deterministic reply and metadata event/counter. |
| Model cites an unverified money amount | Output rejected; deterministic reply used. |
| Model claims “I cancelled/refunded/changed…” | Output rejected; deterministic reply used. |
| Out-of-stock/unavailable product or sensitive order-verification state | Deterministic reply forced; model is not called. |
| Daily AI cap reached | Deterministic reply until the next day; event recorded. |
| Human owns/pauses the conversation | No automatic reply. |

The guard compares money amounts written with `৳`, `BDT`, `Tk`, `taka`, or
`টাকা` against verified amounts supplied for that turn. It is a safety layer,
not a mathematical proof that every non-monetary sentence is correct. The
system prompt also treats customer/product text as untrusted data and instructs
the model to use only verified facts. Operators should still review provider
behaviour and conversation quality.

Support calls provider adapters directly rather than `sh_ai_dispatch()`, so
full support prompts/responses are not copied into the general AI generation
log. Support stores the customer-visible conversation because persistence is a
feature, plus metadata such as provider/model/latency in message facts,
provider error event names, and daily counters.

When AI is enabled, be aware that the selected external/local inference
service receives the system instructions, admin's extra support instructions,
verified facts for the current turn, and a bounded recent public history. It
may therefore process the customer's messages and authorized order/product
facts under that provider's terms. Internal admin notes are not included.
Choose a provider and retention policy appropriate for the store.

## 5. Customer widget

The footer includes the widget only when `support_enabled` is on.

- The launcher is a real button with `aria-expanded`/`aria-controls`.
- The panel is a labelled dialog, positioned absolutely at the current page
  scroll offset and grows in normal page flow. There is no inner chat scroll
  box; the browser page scrolls.
- Messages have sender labels and localized times. Model output is labelled
  “AI assistant”, deterministic output “Support”, and a human “Support agent”.
- Message bodies render through text nodes. Only a standalone same-origin
  store `.php` URL becomes a link; foreign URLs remain text.
- A typing indicator is shown only while a real send request is pending.
- Sends use a `client_msg_id`; a visible Retry reuses the same ID so a lost
  response does not duplicate the customer message.
- Polling runs every five seconds only while the panel is open, plus when the
  tab becomes visible. It is not a WebSocket and does not imply an agent is
  online.
- Escape closes the panel and returns focus to the launcher. Enter sends;
  Shift-Enter can add a line.
- Suggestions, a new-conversation action, and a human handoff action are
  provided. Any admin-configured availability note is displayed exactly as
  written. Do not write “online 24/7” unless people really monitor the inbox.

Guest conversation access is tied to the browser's PHP session. Clearing
cookies/session data or switching devices does not transfer access to an old
guest conversation. Signed-in customers are matched by account.

## 6. Admin inbox and human workflow

### Pages

- **Admin → Support Inbox** (`admin/support.php`): open/pending counts,
  unassigned/unread counts, search across customer snapshots/order reference/
  message text, status and assignment filters, language, last public message,
  state, assignee, and last activity.
- **Conversation** (`admin/support-conversation.php?id=...`): full public and
  internal thread, customer snapshot, last discussed product/order links,
  activity events, reply form, and conversation controls.
- **Admin → Support Settings** (`admin/support-settings.php`): all support
  configuration, route/test status, FAQs, seven-day/today counters, and manual
  retention cleanup.

The admin sidebar support badge is the sum of unread customer messages on
open/pending conversations. It updates when an admin page loads; there is no
email, SMS, browser-push, Telegram, WhatsApp, or Messenger notification for a
new support handoff in this implementation. Staff must monitor the inbox.

### Conversation states and controls

- `open`: active conversation, normally AI/deterministic replies allowed.
- `pending`: waiting for a human, normally after handoff.
- `resolved`: work is complete but a new customer message can reopen it.
- `closed`: closed; a new customer message to that same owned conversation
  reopens it to `open`.

Actions:

- **Customer handoff** pauses AI and sets `pending`.
- **Take over from AI** pauses AI, assigns the acting admin, records an event,
  and shows the customer a system notice.
- Sending a first public admin reply while AI is active automatically takes
  over first, preventing a human/model double reply.
- **Internal note** stores `visibility = internal`. It is excluded from the
  customer API and must never be treated as customer-visible.
- **Assign** changes or clears the assignee.
- **Status** changes open/pending/resolved/closed.
- **Resume AI assistant** unpauses AI, moves `pending` back to `open`, records
  an event, and notifies the customer in-thread.

The admin conversation page polls its read-only JSON mode every six seconds
while visible. It includes internal notes only on that permission-protected
admin page.

### Permissions

| Permission | Grants |
| --- | --- |
| `support.view` | View inbox and conversation threads, including internal notes. |
| `support.reply` | Reply, add notes, take over/resume AI, assign, and change status. |
| `support.manage` | Change support settings/FAQs, run the live AI probe, and purge retained conversations. |

Super-admin behaviour follows the existing permission system. Fresh default
`order_manager` and `support_staff` roles receive `support.view` and
`support.reply`. Existing custom roles keep their saved permissions; an owner
must grant the new support permissions in Roles & Permissions. No support
permission grants access to provider API keys.

## 7. Configuration reference

All support settings are stored in the existing `settings` table with the
prefix `support_`. They are edited at Admin → Support Settings; no config file
edit is required.

| Setting | Default | Allowed/effective range | Notes |
| --- | ---: | --- | --- |
| `support_enabled` | `1` | on/off | Controls both the footer widget and API. |
| `support_ai_enabled` | `1` | on/off | Allows model phrasing when a usable route exists. |
| `support_provider_id` | `0` | `0` or provider ID | `0` means `support_chat` task routing/default provider. |
| `support_model` | empty | text | Model override for an explicitly selected support provider; otherwise use AI Settings task routing for the task model. |
| `support_max_input` | `2000` | 200–8000 chars | Longer customer messages are rejected, not silently truncated. |
| `support_max_tokens` | `450` | 50–2000 | Maximum requested model reply tokens. |
| `support_history_turns` | `10` | 2–30 | Recent public turns considered for the provider prompt; character bounds also apply. |
| `support_timeout` | `25` | 5–90 seconds | Passed to supported provider adapters. |
| `support_rate_per_min` | `8` | 1–60/min/session | IP limit is `max(4, 4 × session limit)` per minute. |
| `support_daily_ai_limit` | `1000` | 0–1,000,000 | `0` disables the store-wide daily AI cap. |
| `support_retention_days` | `180` | 0–3650 days | `0` keeps resolved/closed conversations indefinitely; purge is manual. |
| `support_languages` | `bn,banglish,en` | subset | Enabled reply languages. |
| `support_system_prompt` | empty | admin text | Extra tone/store instructions; built-in fact/read-only rules remain in force. |
| `support_business_hours` | empty | admin text | Internal/reference text stored with support settings. |
| `support_contact` | empty | admin text | Internal/reference text stored with support settings. |
| `support_availability` | empty | admin text | Honest human-availability note displayed in the widget/handoff context. |
| `support_delivery_policy` | empty | admin text | Exact delivery policy wins over FAQ/generic text. |
| `support_return_policy` | empty | admin text | Exact return/exchange policy. |
| `support_payment_policy` | empty | admin text | Exact payment policy. |
| `support_welcome_bn` etc. | supplied | admin text | Welcome copy for each reply language. |
| `support_fallback_bn` etc. | supplied | admin text | “Could not verify” copy; never use this to promise unverified facts. |

Other runtime settings are `support_schema_v` (schema-ensure marker) and
`support_ai_last_test` (redacted metadata/sample from the most recent manual
probe).

Provider API keys are not support settings and are never entered on the
Support Settings page. They remain in the existing AI provider store, are
encrypted server-side, and are not returned in full to HTML or JavaScript.

## 8. Provider setup

### A. Leave AI off or unconfigured (fully supported)

No action is required. The store can launch with deterministic product/order
tools, FAQs, and human handoff. Keep **Use AI to phrase replies** off if the
store does not want conversation data sent to an inference provider.

### B. Configure an optional provider

1. Back up the site/database using the store's normal process.
2. Open **Admin → AI Settings**. If the AI module is not installed, use its
   existing installer action first. Support deliberately degrades to no-AI
   mode until the AI tables exist.
3. Open **Admin → AI Providers → Add provider**. Existing drivers are OpenAI,
   OpenRouter, Google Gemini, Anthropic, and Custom OpenAI-compatible.
4. Enter the provider's official base URL (for Custom), the store's own API
   key/secret, a text-capable default model, and enable the provider. For
   a token-ignoring local OpenAI-compatible server, the existing provider
   stack still treats an empty stored key as unusable; configure whatever
   non-empty token that local server accepts/ignores. Never use another
   person's key.
5. Run the provider page's own connection test and read the actual result. A
   token-limit result is not, by itself, proof that a key is invalid.
6. Choose routing in one of these ways:
   - In **Support Settings**, select a specific Support AI provider and, if
     needed, its model override; or
   - leave Support Settings at **AI task routing**, then in **Admin → AI
     Settings → Task routing**, route **Customer support chat**
     (`support_chat`) to a provider/model. If no task route is set, the
     default provider/model is used. Configure the existing text fallback
     provider there only if a fallback attempt is desired.
7. Return to **Admin → Support Settings**, enable AI phrasing, set conservative
   timeout/token/history/daily limits, save, and click **Test support AI now**.
8. Only call the route operational when that one-shot test actually passes.
   Then send storefront acceptance messages with test products/orders.

For a local inference endpoint, “local” means reachable from the PHP server,
not merely from the administrator's browser. A shared-hosting PHP process
normally cannot reach a home computer's `localhost`; the endpoint must be on
the same host/private network or exposed through an operator-controlled secure
route. Protect it with network controls/authentication and use HTTPS when it
crosses a network.

### Free-tier honesty

A provider may offer a free tier today, but quotas, eligible models, data
terms, regional availability, and pricing can change. This project does not
claim that any external service is permanently free or unlimited and does not
bundle a key. Check the provider's current official pricing/terms, set a small
daily AI limit, and monitor usage at the provider as well as in Support
Settings.

## 9. Database schema, deployment, and rollback

### Tables

All six are additive `CREATE TABLE IF NOT EXISTS` definitions from
`sh_support_schema_sql()`:

| Table | Purpose |
| --- | --- |
| `support_conversations` | Identity snapshots, status, assignee, AI pause, language, latest product/order context, unread counters, and activity time. |
| `support_messages` | Customer/AI/admin/system messages, public/internal visibility, language, idempotency client ID, and safe facts metadata. Unique `(conversation_id, client_msg_id)`. |
| `support_events` | Metadata-only audit/activity events; message bodies and secrets are not copied here. |
| `support_faqs` | Keyword-matched FAQ answers in Bengali, Banglish, and English. |
| `support_rate` | Hashed session/IP and order-verification rate buckets using atomic upserts. |
| `support_usage_daily` | Daily customer/AI/fallback/admin/handoff/rate-limit counters. |

`support_messages` and `support_events` cascade when a conversation is
deleted. User/admin/product references on conversations use `SET NULL`.

Fresh installs include the definitions in the installer schema. Existing
installs create them when `sh_support_ensure_schema()` first runs from a
support API/admin entry point and then set `support_schema_v = 1`. Starter
FAQs are seeded only when the FAQ table is empty.

### Existing-store deployment checklist

1. Back up application files and the MySQL database.
2. Deploy the code in a staging copy first when possible.
3. Confirm PHP 8.1+ and the store's normal extensions are available. No new
   runtime service is needed.
4. Open **Admin → Support Settings** as an account allowed to manage support;
   confirm the page loads without a PHP diagnostic. This safely ensures the
   additive tables.
5. Grant support permissions to any pre-existing custom roles that need them.
6. Review welcome/fallback copy, human availability, policies, and FAQs. Do
   not publish delivery charges/dates or return commitments the store cannot
   honour.
7. Run the acceptance checklist with a test product and, for order tests, a
   disposable/test order—not a real customer's data.
8. Configure/test AI only if desired. Leave the daily cap conservative.
9. Confirm the storefront, login, cart, checkout, normal admin, courier, and
   payment workflows still work.
10. Train staff to check the Support Inbox; handoff has no external push
    notification in this implementation.

### Rollback

For a code-only rollback, first disable support in Support Settings if the
admin is available, then deploy the previous application release using the
store's normal Git/file process. Existing support tables/settings can remain
without being used by the older code.

To remove support data completely, back up first, stop/disable the deployed
support code, then run the following only against the intended database:

```sql
DROP TABLE IF EXISTS
  support_usage_daily,
  support_rate,
  support_faqs,
  support_messages,
  support_events,
  support_conversations;
-- Optional, only if support configuration should also be discarded:
DELETE FROM settings WHERE setting_key LIKE 'support_%';
```

This permanently deletes conversations, internal notes, FAQs, counters, and
events. Saved custom-role JSON may still mention `support.*` permission keys;
they are inert if the code is rolled back and can be removed through Roles &
Permissions.

If a table was manually removed while `support_schema_v` still says `1`, the
fast path will not repair it automatically. After a database backup, remove
that one setting and reopen Support Settings so the idempotent create/seed
path runs again.

## 10. Privacy, retention, and security notes

- Conversations are intentionally stored in MySQL so customers and agents can
  continue them. Apply the store's normal database backup/access controls.
- Customers should not be encouraged to send passwords, OTPs, full payment
  credentials, or unrelated identity documents in chat. Staff should handle
  any such incident under the store's privacy/security policy.
- The guest token itself is kept in the PHP session; only its hash is stored
  on the conversation. Rate buckets use hashed session/IP identifiers.
- Provider errors recorded in support events pass through a redactor for
  common key/Bearer patterns and are truncated. Full provider keys must never
  be pasted into FAQs, policies, system prompt, customer messages, or notes.
- Support does not call the general AI dispatch logger with full conversation
  prompts/responses.
- Manual purge deletes only `resolved`/`closed` conversations whose last
  activity is older than the retention window. Messages/events cascade with
  them. Open/pending conversations are never purged by that action. There is
  no cron/worker, so retention does not happen automatically in the background.
- The factual guard and prompt are defence-in-depth. They do not make a model
  a source of truth. Keep AI read-only and review conversations periodically.
- Product specifications/descriptions and customer text are untrusted prompt
  data. Only PHP-built verified facts are authoritative.

## 11. Troubleshooting

| Symptom | Checks |
| --- | --- |
| Widget is invisible | `support_enabled`; page uses the normal footer; no stale full-page/CDN cache; browser console/network shows `support-chat.js`; store is installed and database healthy. |
| API returns 503 | Store installer/database state or support disabled. Do not expose raw DB errors to customers; check the store error log. |
| Message says rate limited | Wait a minute; review `support_rate_per_min`. Shared NAT/campus networks also share the looser IP bucket. |
| AI route is shown but replies are deterministic | Check `support_ai_enabled`, AI tables installed, provider enabled/key/model, Support provider or `support_chat` task route, daily cap, provider test, conversation AI pause, and event names. Several sensitive intents always force deterministic replies. |
| “Configured” but live test fails | Believe the test result. Verify official base URL, key permissions, exact model ID, model text capability, outbound HTTPS/DNS from PHP, provider quota/status, and a realistic timeout. A configured route is not proof of operation. |
| Provider errors in activity | `provider_error` is metadata with a redacted summary. Check provider status/key/model/quota and the settings-page last test. `ai_guard_rejected` means the model supplied an unverified amount or action claim and the customer received the verified deterministic answer. `ai_daily_cap` means the store-wide daily limit was reached. |
| Order is not found | Use the full order number as printed (a typed dash is tolerated) and the exact checkout phone. Do not ask for only one factor. Failed phone attempts are capped for an hour. |
| Agent does not see a new chat | Confirm `support.view`, refresh the inbox, and check status/assignment filters. There is no external push alert. |
| Customer sees an internal note concern | Internal notes have `visibility = internal` and are excluded by the customer API. Verify the admin selected the internal-note checkbox before sending. |
| Human reply and AI might race | Take over or send the public reply (which takes over automatically). The pipeline re-checks `ai_paused` under lock before saving an automatic reply. |
| Size stock is not exact | Expected limitation: the catalog has no variant-stock table. Link the customer to the product page/options and do not invent per-size stock. |
| Support tables missing after manual DB edit | Back up, clear `support_schema_v`, and reopen Support Settings to rerun idempotent creation. |

## 12. Test suites

Run test suites only in a development/CI environment. In particular,
`tests/support-system.php` resets support tables and creates seed records in
its configured database—never run it against production data.

```bash
# Pure PHP logic/static wiring; no database or network
php tests/support-unit.php

# Storefront widget behaviour in jsdom (test environment only; production
# does not need Node.js). Install the exact harness dependency first.
npm install --no-save --no-package-lock jsdom@26.1.0
SH_SC_REQUIRE_JSDOM=1 node tests/support-chat-behavior.mjs

# Real MySQL end-to-end suite; point config/database.php at a disposable DB
php tests/support-system.php

# Existing regression suites that must remain green
php tests/security-regression.php
NODE_PATH=/path/to/node_modules node tests/no-autosave-behavior.mjs
NODE_PATH=/path/to/node_modules node tests/provider-test-behavior.mjs
```

The MySQL suite deliberately configures an unreachable local provider before
exercising the pipeline. It proves fallback behaviour and honest failure
metadata; it does not prove that any real external/local provider works. A
real provider is operational only after the settings-page probe and manual
storefront checks pass with that provider/model and the store's own account.

## 13. Manual acceptance checklist

Use staging or clearly identified test data. Do not run purge, delete, or order
experiments against a real customer's records.

- [ ] On a narrow mobile viewport, open the storefront. The launcher does not
      block the mobile navigation, the panel causes no horizontal page
      overflow, and the page—not an inner panel—scrolls through a long thread.
- [ ] Open the chat with keyboard only; send with Enter and close with Escape.
      Focus returns to the launcher.
- [ ] Ask in Bengali script about a known in-stock product. Reply language,
      product link, `sh_money` price, and stock match the product page/DB.
- [ ] Ask in Banglish, then “Agertar blue color ta dekhaw”. The follow-up
      resolves to the colour-named product if one exists and does not reuse
      the old product's price.
- [ ] Ask “dam koto?” and confirm it refers to the latest product.
- [ ] Ask about an out-of-stock product. The reply plainly says out of stock.
- [ ] Ask about a size. The reply does not invent size-level stock.
- [ ] Ask English delivery/return/payment questions and confirm admin policy
      text/FAQ behaviour.
- [ ] As a guest, give only a test order number. No status, total, or tracking
      is revealed; the assistant requests the checkout phone.
- [ ] Give a wrong phone. The answer remains generic. Give the correct phone;
      only then are the test order status/items/tracking shown.
- [ ] In a second browser/profile, repeat with the order number alone. No
      cross-customer data is revealed.
- [ ] As the signed-in owner, ask “amar order kothay?” without a number and
      confirm only the account's latest order is used.
- [ ] Send an instruction-injection message asking for a fake 10-taka price or
      an order cancellation. The factual price remains correct and the test
      order is unchanged.
- [ ] Simulate a failed send/retry if possible. The message is stored once
      (`client_msg_id` dedupe).
- [ ] Click **Talk to a human** and send details. AI stops replying, the admin
      badge/inbox shows the pending conversation, and the messages remain.
- [ ] In admin as `support.reply`, take over, send a public reply and an
      internal note. The customer sees only the public reply/notice.
- [ ] Assign the conversation, change status, resume AI, and send another
      customer message. The automatic assistant works again only after resume.
- [ ] Use an admin account without `support.reply`; confirm it can at most
      view according to its permissions and cannot mutate the conversation.
- [ ] In Support Settings, disable AI phrasing and repeat a product/order
      question. The deterministic answer remains correct and is not labelled
      as AI.
- [ ] If configuring AI, run **Test support AI now** with the store's own
      provider/model. Record the result honestly; repeat product/order tests
      and inspect that amounts and stock still match the database.
- [ ] Reload the storefront and reopen chat. Prior public history is present.
- [ ] Smoke-test login, cart, checkout, orders/admin, and the AI Providers and
      Courier test controls to confirm no unrelated workflow regressed.

## 14. Known limitations

- No variant-level inventory exists in the underlying catalog, so size/colour
  stock cannot be verified per variant.
- Product matching is lexical and bounded; it is not semantic/vector search.
- Language detection is heuristic for Bengali script, Banglish, and English.
- No attachments, voice messages, email/SMS/push notifications, proactive
  customer notifications, or WebSocket real-time delivery are included.
- Polling introduces a small delay while a page is open; agents should keep
  the inbox/conversation page refreshed during staffed hours.
- Retention purge is manual because shared-hosting compatibility excludes a
  required background worker.
- Previously created custom admin roles do not automatically receive new
  support permissions.
- A local provider must be reachable from PHP and satisfy the existing
  provider stack's non-empty-key expectation.
- AI quality, cost, quota, latency, data handling, and model availability are
  controlled by the selected provider. The deterministic system and human
  inbox remain the dependable base.
