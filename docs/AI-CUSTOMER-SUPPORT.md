# AI Customer Support: deployment and rollback

This guide covers the additive customer-support feature in the existing ShopHaat PHP + MySQL/MariaDB application. It does not replace checkout, payment, inventory, accounts, or order workflows. The support AI is read-only; if chat or AI is off/unavailable, the existing Contact Support email form and normal storefront continue to work.

## What was audited

- ShopHaat uses PHP 8.1+, PDO/MySQL, vanilla JavaScript and shared-hosting/cPanel deployment; no application runtime dependency on Node, Composer, Redis, a queue or a worker was added.
- Existing admin auth/roles, CSRF, settings, store catalog, orders, guest-order session allowlist, shipment/courier data, and AI provider/model adapters are reused.
- Public catalog lookup filters to active products (`products.status = 1`). Order lookup is restricted to the signed-in account or the existing guest-order session allowlist. A guessed order number or phone alone does not authorize private chat lookup.
- Provider calls use the existing AI adapter layer, not the legacy generation dispatcher that records prompts. Only bounded recent messages from the authorized support thread and the minimum verified facts for that turn are sent. API keys are never rendered in the support page.

## Before deployment

1. Deploy to staging first if available. Save a full database export from cPanel/phpMyAdmin and a file backup of the current release. Keep both outside the public web root.
2. Confirm the host meets the project’s existing requirements: PHP 8.1+, `pdo`, `pdo_mysql`, `mbstring`, and MySQL 5.7+ or MariaDB 10.3+. Ensure the database user can create tables and indexes, or plan to run the SQL migration using phpMyAdmin.
3. Upload the branch’s changed PHP, CSS, JavaScript and migration files together. Do not deploy only the UI or only the API/schema.
4. Run `install/migrations/2026-10-ai-customer-support.sql` against the ShopHaat database. It creates six support-owned tables and can be run again safely. It does not alter the catalog, customers, orders, carts, payments, stock or existing AI provider/model tables.
5. Sign in as the store owner and open **Admin → Customer Support → Support Inbox**. The role helper provisions the least-privilege `support_agent` role if it is missing. Assign that role to a trusted admin, or explicitly grant `support.manage` to an existing role. Existing `support_staff` roles are not elevated automatically.
6. Only admins who have both `support.manage` and the existing `ai.use` permission can configure or test AI. Provider credentials remain managed in **AI Auto Work → AI Providers**.

The support tables are also created idempotently by the existing application schema integration/first-use schema check when database permissions allow. The manual migration is the recommended, reviewable cPanel deployment step. If automatic setup cannot run, the admin page/API reports that the support database is not ready instead of silently claiming success.

## Configure the service

1. In **Customer Support → Settings & FAQs**, choose whether customer chat and human handoff are enabled. The existing email contact form remains on `support.php`.
2. Set only verified support hours and public contact details. Blank email/phone overrides fall back to the existing store contact settings.
3. Review the seeded FAQs against the store’s current policies. Enabled English FAQs are also shown in the public Help Centre; enabled multilingual answers can be used by chat. Do not store customer data, API keys, passwords, OTPs or internal-only instructions in FAQs.
4. Add the current delivery, payment, and returns/replacements policies in the appropriate language fields. Explicit configured policy text takes precedence over older seeded FAQ answers in deterministic fallback.
5. If AI is wanted, use an already configured provider whose endpoint is HTTPS and whose selected model is marked text-capable. Set conservative time, output-token, global-daily and per-customer/session limits. Save the AI configuration, then use **Save settings & test provider**.
6. AI is considered available only after the selected model returns actual text from its provider test. A key-acceptance response or a token-limit result alone does not count. The live provider may still fail later; bounded inference failures fall back to verified support data. Do not treat a past successful test as a live-availability guarantee.

The customer experience stays functional with AI disabled: FAQ answers, active-catalog matches and authorized order lookup are deterministic. If no verified answer exists, the chat says so and directs the customer to configured contact details rather than inventing business facts.

## Staging acceptance checklist

Run these against the actual deployed PHP/MySQL environment and a test provider/account before enabling customer traffic:

- Send a customer chat message; verify the customer message and reply persist after refresh and that guest and signed-in histories do not cross accounts.
- Turn AI off and repeat. Verify deterministic FAQ/catalog/order behavior and a clear unavailable message; no provider request should be needed.
- Test an active product, an inactive product, stock/price display, and an unknown product. Only current active catalog records should produce product cards.
- Test an account order, a guest order from its original browser session, and an order ID/number entered from another browser/session. Verify the latter returns no private order facts. Check that no order/payment/cart/inventory state changes.
- Request human support. Confirm the localized system message, event, pending queue state and AI pause. Assign the thread, send a public reply, add an internal note, pause/resume AI, resolve and reopen. Verify internal notes never appear in the customer transcript.
- Confirm that only support-authorized admins can access inbox/settings APIs, that CSRF and cross-origin POST checks reject invalid requests, and that input/rate limits fail safely.
- Check desktop and mobile page flow. Support transcript/inbox/history must grow with the document and use explicit **Load older messages** batches—no nested or fixed-height scrolling areas.
- Re-test the existing Contact Support email form and ordinary account, checkout, payment, order, tracking and inventory workflows.

## Shared-hosting behavior

- Provider requests are synchronous to avoid requiring a worker. Timeouts and response sizes are bounded; inference is not sent through the legacy AI-generation logger.
- A provider outage, disabled AI, a daily limit, invalid configuration or an empty provider answer uses deterministic fallback or a safe error. AI never writes to cart/order/payment/inventory state.
- No database connection, provider key, customer data or browser was available in the implementation sandbox. The source-level checks run during implementation are listed in the pull-request report; they do not replace the staging checklist above.

## Rollback

**The rollback SQL permanently deletes support conversations/messages, handoff history, FAQ edits and usage metrics. Export any records that must be retained first.**

1. In Support Settings, disable customer chat and AI. Preserve the current database and file backups.
2. Export `support_conversations`, `support_messages`, `support_handoff_events`, `support_faqs`, `support_ai_usage` and `support_rate_limits` if support history must be retained.
3. If removing the feature from the release, restore the backed-up pre-feature PHP/assets and schema integration together. The existing Contact Support email form and unrelated store workflows remain.
4. If dropping support data, run `install/migrations/2026-10-ai-customer-support-rollback.sql`. It drops only the six support-owned tables, forces customer chat and support AI off, and writes an `ai_support_schema_v=disabled` marker so a still-deployed copy of the support code will not recreate them on the next request. The inbox/API will remain unavailable until the forward migration is re-applied.
5. The rollback preserves the other `support_chat_*` settings and the shared AI provider configuration in the existing settings tables. The chat/AI enable switches remain `0`; explicitly re-enable them in Support Settings only after review. If the owner wants to discard the remaining support-specific configuration, remove only those `support_chat_*` keys after review. Do not delete `ai_providers`, `ai_models`, or general AI settings.
6. To re-enable later, deploy the support code and re-run the forward migration. The schema check clears the disabled marker when all tables exist; the FAQ seed marker is reset by rollback so the default FAQs can be seeded again.

## Validation limits for this change

The implementation sandbox has no PHP CLI, Composer, project database credentials/configuration or real provider credentials. Native `php -l`, migration execution against MySQL/MariaDB, browser testing in a real browser, checkout/order regression tests and a real provider text-generation test were therefore not run. Do not report a provider as operational until the store operator completes the actual provider test and the staging checks above.
