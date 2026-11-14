# ShopHaat — Complete Project Inventory

**Purpose:** the factual map of the application, produced by reading the
repository (file listing, module headers, schema, routing rules) — not by
assuming a feature exists because its name appears somewhere.

**Repository:** `rayanixh/Shop-Haat-Otp-System-Ai`, branch
`arena/c6d8fd27-shop-haat-otp-system-ai`.
**Shape:** 221 PHP files, 62 admin modules, 10 API endpoints, 49 database tables,
no framework, no Composer runtime dependency (PHPMailer is vendored in `lib/`).

---

## 1. Architecture

```
Browser ──► PHP page (root *.php)
              └─ config/config.php  ← single bootstrap, loaded by every entry point
                   ├─ includes/errors.php      error handling + scrubbed logging
                   ├─ includes/compat.php      extension inventory + UTF-8 shims
                   ├─ includes/client-ip.php   trusted-proxy client IP
                   ├─ includes/environment.php dev / staging / production + staging gate
                   ├─ includes/security.php    CSP, HSTS, headers, file rate limiter
                   ├─ includes/db.php          PDO (mysql), prepared helpers
                   └─ includes/functions.php   settings, escaping, uploads, icons, …
                        └─ includes/auth.php, admin-auth.php, admin-perms.php …
                   └─ includes/header.php / footer.php     storefront chrome
Admin  ──► admin/*.php  → admin/_layout.php, admin-perms.php (permission matrix)
API    ──► api/*.php    → JSON only, CSRF + method + ownership checks
Cron   ──► cron/backup.php?token=…  → scheduled database backup only
Install──► install/index.php, install/schema.php, install/migrations/*.sql
```

Everything is classic shared-hosting PHP: no build step, no daemon, no queue, no
Redis, no Node, no Docker. File-based state is limited to `logs/` (application
log + rate-limit counters) and `storage/backups/`.

## 2. Entry points

### Customer-facing pages (root)
`index.php` (home), `products.php`, `product.php`, `category.php`, `page.php`,
`cart.php`, `wishlist.php`, `checkout.php`, `payment.php`, `order-success.php`,
`orders.php`, `order-details.php`, `track.php`, `account.php`, `addresses.php`,
`login.php`, `register.php`, `logout.php`, `otp.php`, `verify-email.php`,
`forgot-password.php`, `reset-password.php`, `password.php`, `support.php`,
`help.php`, `sitemap.php`, `robots.php`, `router.php`, `telegram-webhook.php`.

### Admin (`admin/`, 62 files)
Dashboard `dashboard.php`; catalogue `products.php`, `product-edit.php`,
`product-attributes.php`, `stock.php`, `categories.php`, `category-edit.php`,
`homepage.php`, `homepage-edit.php`, `theme.php`, `seo.php`; selling
`orders.php`, `parcels.php`, `payments.php`, `payment-methods.php`,
`payment-method-edit.php`, `payment-gateways.php`, `payment-gateway-edit.php`,
`coupons.php`, `coupon-edit.php`; logistics `couriers.php`, `courier-edit.php`,
`verification.php`, `verification-provider-edit.php`, `verification-fetch.php`;
people `customers.php`, `admins.php`, `admin-edit.php`, `roles.php`,
`role-edit.php`; messaging `inbox.php`, `support.php`,
`support-conversation.php`, `support-settings.php`, `messenger.php`,
`whatsapp.php`, `telegram.php`, `notifications.php`; e-mail `email.php`,
`email-log.php`, `firebase.php`, `guides/{smtp,firebase}.php`; security
`security.php`, `security-logs.php`, `otp-logs.php`, `logs.php`, `audit-log.php`,
`settings-history.php`, `system-health.php`; system `settings.php`, `backups.php`,
`reports.php`, `search.php`, `login.php`, `logout.php`, `custom-route.php`
(secret admin URL), `google-login.php`, `_layout.php`, `_footer.php`,
`_seo-fields.php`, `_verification-card.php`.

### API (`api/`, JSON)
| Endpoint | Purpose | Auth |
|---|---|---|
| `otp.php` | send / verify phone codes (signup, login, superadmin test) | CSRF + pending session context |
| `cart.php` | cart & wishlist mutations | session |
| `checkout.php` | live totals (coupon, delivery zone) recalculated server-side | session |
| `payment.php` | submit / verify manual payments, gateway actions | CSRF + order ownership |
| `orders.php` | order status lookup for the signed-in customer | session + ownership |
| `search.php` | search suggestions (read-only GET) | none |
| `support.php` | support chat (customer + admin) | session / admin perm |
| `notifications.php` | admin notification test send | admin permission |
| `ai.php` | AI auto-work for admins | admin permission |
| `csp-report.php` | CSP violation collector | none (rate limited) |
| `email-events.php` | provider webhook (delivered / bounced / complaint) | shared secret |

## 3. Authentication and authorisation

* Customers: `includes/auth.php` (session + DB user row, 24 h TTL, session id
  regeneration on login, `users.status` gate, cart merge), Google OAuth
  (`includes/google-auth.php`), email verification (`includes/mail.php`),
  phone OTP (`includes/phone-otp.php` + `api/otp.php`).
* Admins: `includes/admin-auth.php` (session + `admins.status`),
  `includes/admin-perms.php` (permission matrix — `roles`, `role-edit`,
  `sh_require_perm()`), optional Google admin login, secret admin URL
  (`includes/admin-access.php`, dispatched by `admin/custom-route.php`).
* Throttling: session counter + `security_logs`-backed counters
  (`sh_auth_throttle_blocked`) keyed on client IP and a hashed identity.
* CSRF: `includes/csrf.php`, `sh_csrf_field()`, `sh_csrf_require()`.

## 4. Database (49 tables, InnoDB, utf8mb4)

| Group | Tables |
|---|---|
| Identity | `users`, `admins`, `password_resets`, `email_challenges`, `otp_verifications`, `otp_rate_limits`, `otp_rate_locks`, `security_logs` |
| Catalogue | `categories`, `brands`, `products`, `product_images`, `product_types`, `product_colours`, `product_colour_selections`, `product_codes`, `reviews`, `wishlist` |
| Commerce | `cart`, `cart_items`, `orders`, `order_items`, `payments`, `payment_methods`, `payment_gateways`, `coupons`, `addresses` |
| Logistics | `couriers`, `shipments`, `shipment_events`, `courier_test_results` |
| Messaging | `notifications`, `notification_logs`, `email_log`, `whatsapp_messages`, `whatsapp_message_statuses`, `telegram_admin_log`, `telegram_updates` |
| Support | `support_conversations`, `support_messages`, `support_events`, `support_faqs`, `support_rate`, `support_usage_daily`, `support_handoff_events`, `support_ai_usage`, `support_rate_limits` |
| Platform | `settings`, `app_logs`, `promo_slides` |

Schema versioning: `SH_OTP_SCHEMA_VERSION` (currently 5) plus
`install/migrations/*.sql`. `sh_otp_schema_ensure()` and
`sh_admin_schema_ensure()` make upgrades lazy — a store that only replaces files
still gets new tables on first use.

## 5. Integrations (all optional, all configured by the owner)

| Area | Providers | Module |
|---|---|---|
| SMS | offline (dev only), TextBee, generic HTTPS gateway | `includes/phone-otp.php`, `includes/support-sms.php` |
| E-mail | SMTP (STARTTLS/SSL), Brevo API, Firebase Auth links | `includes/mail.php`, `includes/firebase.php`, `lib/PHPMailer` |
| Payments | bKash, Nagad, Rocket, Cash on Delivery + custom `payment_gateways` rows | `includes/payment.php`, `api/payment.php`, `payment.php` |
| Couriers | Steadfast, Pathao, RedX, eCourier, Paperfly, Sundarban, SA Paribahan, custom | `includes/courier.php`, `admin/couriers.php` |
| Courier/identity check | IP + courier lookup with trusted-proxy support | `includes/verification.php` |
| AI | OpenAI, Anthropic, Google Gemini, OpenRouter, OpenAI-compatible | `ai/`, `ai/providers/` |
| Chat | Telegram bot + webhook, WhatsApp, built-in support widget | `includes/telegram.php`, `includes/whatsapp.php`, `includes/support*.php` |
| Reports/analytics | in-app only (no third-party tracker by default) | `includes/reporting.php`, `admin/reports.php` |

## 6. File uploads

Single choke point: `sh_upload_image()` in `includes/functions.php` — real MIME
from `getimagesize()`, allow-list JPG/PNG/WebP/GIF, 16–6000 px, random
non-guessable filename, `0644`, no SVG, no PHP. Callers:
`sh_product_upload_image()` (products), `sh_promo_handle_upload()` (homepage
slides), `includes/category-image.php` (categories/brands), admin avatar/gallery
paths. `uploads/.htaccess` denies code extensions and dotfiles; `sh_delete_upload()`
removes by `basename()`.

## 7. Cron / scheduled work

* `cron/backup.php?token=…` — token-authenticated scheduled DB backup
  (`sh_backup_run_scheduled()`), notifies admins on success/failure.
* Housekeeping runs opportunistically inside request handling (OTP rate-log
  cleanup 1-in-200 requests; notification/e-mail retry queues).

## 8. Installation and migrations

`install/index.php` (requirements → database → admin → demo data opt-in),
`install/schema.php` (canonical DDL + `sh_schema_tables()`),
`install/demo-data.php` (explicit opt-in, never runs on its own),
`install/migrations/2026-11-otp-rate-limits.sql` (+ rollback).

## 9. Configuration

| File | Purpose |
|---|---|
| `config/config.php` | bootstrap: env, security headers, base URL, sessions |
| `config/database.php` | DB credentials (owner-created, denied by `.htaccess`) |
| `config/database.example.php` | template |
| `config/environment.php` | optional `return 'production'` override + staging gate credentials |
| `config/environment.example.php` | template for the three environments |
| `config/secrets.php` | optional server-side secrets (pepper, cron token) |
| `config/secrets.example.php` | template |
| `config/mail.php` | mail defaults |
| `.htaccess`, `.user.ini`, `uploads/.htaccess`, `logs/.htaccess` | server hardening |

Secrets resolution order: constant → environment variable →
`config/secrets.php` → database `settings` (legacy fallback).

## 10. Tests and tooling (46 files in `tests/`)

PHP suites: `security-regression.php`, `otp-security.php`, `trusted-proxy.php`,
`secrets.php`, `environment.php`, `support-unit.php`, `support-system.php`,
`support-sms-transport.php`, `support-sms-notifications.php`,
`courier-connection-tests.php`, `email-system.php`,
`brevo-api-diagnostics.php`, `smtp-tls-diagnostics.php`,
`product-management-*.php`, `reporting-integration.php`, `session-expiration-http.sh`,
`email-pages-http.sh`, admin-access suites.
Browser suites (jsdom): `ui-ux-audit.mjs`, `product-management-behavior.mjs`,
`navigation-drawer-behavior.mjs`, `slider-behavior.mjs`,
`support-*-behavior.mjs`, `no-autosave-behavior.mjs`,
`page-transition-behavior.mjs`, `provider-test-behavior.mjs`,
`admin-access-*.mjs`.
Tooling: `tools/security-scan.php` (static analyser, 189 files, currently 0
findings), `tools/smtp-tls-inspect.php`.

## 11. Deployment shape

cPanel/shared hosting: PHP ≥ 8.0 (tested here on 8.5.12), MySQL/MariaDB,
`mod_rewrite` recommended (SEO endpoints and the custom admin route), no shell
access required. Required extensions: pdo, pdo_mysql, json. Optional with
fallbacks: mbstring, dom, curl, openssl, fileinfo, gd (thumbnails).

## 12. What is *not* in this application (checked, not assumed)

* No payment-gateway callback/webhook receiver for bKash/Nagad/Rocket — those
  methods are manual (transaction id + admin verification) by design.
* No Redis/Memcached, no queue worker, no Node build.
* No public API for third parties; every `api/*` endpoint is either
  session/CSRF protected or a signed webhook.
* No multi-vendor/marketplace, no POS, no multi-currency.
