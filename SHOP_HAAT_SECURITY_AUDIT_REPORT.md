# Shop-Haat Security Audit Report

**Audit type:** Pre-release, audit-only source and evidence review
**Review date:** 2026-11-18 (UTC)
**Repository/branch:** `rayanixh/Shop-Haat-Otp-System-Ai`, `arena/f74e2a9c-shop-haat-otp-system-ai`
**Starting commit:** `64a2421fcb80a85af949e289e31502aaf96c8716`
**Scope restriction:** No application, configuration, database, UI, or behavior files were changed. This report is the only file created for this audit.

> **Overall conclusion — NOT A RELEASE CERTIFICATION.** The sampled source shows several strong controls: parameterized data queries in reviewed routes, server-recomputed prices, explicit customer ownership checks, CSRF helpers, admin role checks, authentication-boundary session ID regeneration, and defense-in-depth upload restrictions. This review also confirms a public order-tracking brute-force/abuse weakness, and identifies conditional hosting, CSP-enforcement, payment-integration, and verification gaps. There was no PHP runtime, live database, web server, or browser available to execute this audit's tests. Historical reports describe substantial earlier testing, but those results were not reproduced and are not presented as results of this audit. Do not infer that the whole application or any production deployment is secure.

## 1. Executive summary

This audit reviewed selected security-critical source paths and deployment guards in a PHP/MySQL storefront with customer and admin areas, APIs, an installer, uploads, OTP/email authentication, manual payments, gateway callback handling, and third-party integrations. The repository contains 246 PHP files, 11 top-level API PHP files, 77 admin PHP files, 67 test files, and 414 tracked files at the time of the inventory check. These counts are descriptive, not coverage evidence.

### Headline results

- **1 confirmed application-layer weakness (MEDIUM):** Public order tracking has no application-level request throttle. The order-number format embeds the sequential database ID and a date, and lookup requires only order number plus customer phone. A remote caller can repeatedly enumerate candidate order numbers and phone values. The API discloses limited order/payment status, total, and placement time when matched. Infrastructure-level protections were not inspected.
- **2 conditional/known residual risks (LOW):** `X-Forwarded-Proto` is trusted for HTTPS detection when no trusted-proxy list is configured; uploads are validated and named safely but not re-encoded, so actual non-execution depends on web-server behavior and the shipped Apache/LiteSpeed rules being active.
- **CSP is PARTIAL by default:** the shipped default is report-only, so it reports rather than blocks script execution. Enforcement is a deployment setting and was not observed on a live site.
- **Payment readiness is PARTIAL / NOT TESTED:** order totals are calculated server-side in reviewed code and manual submissions remain pending for review. Gateway callback code fails closed without a bound transaction and official verification, but the inspected code says no gateway initiation driver is implemented. Live provider setup and callback behavior were not tested.
- **Admin and customer controls:** Source inspection found centralized CSRF handling and page/action permission checks across sampled admin routes, plus session ID regeneration on login. Route-by-route runtime authorization, CSRF rejection, rate-limit behavior, and session cookie behavior remain untested.
- **No application test suite was executed in this audit.** PHP and database CLIs were unavailable. The installed security skill search found no `awesome-skills-security` or other matching security-skill installation in the searched filesystem paths; none is claimed as used.

### Immediate release posture

Do not expose public tracking broadly without an edge/application throttle and monitoring. Before accepting real orders, verify that production HTTPS/proxy settings, server-level upload non-execution, CSP enforcement, secrets/config access restrictions, and live payment/manual reconciliation procedures are correct. Run the manual deployment checks in §20.

## 2. Scope, objective, and exclusions

### Included

- PHP/MySQL application source and security-relevant helpers sampled from customer, admin, API, installer, uploads, payments, OTP, and deployment-control paths.
- Existing audit/remediation/verification reports and selected tests as documentary context only.
- Static review of root and selected directory `.htaccess` files, `.user.ini`, `.gitignore`, and example configuration templates.
- Risk review across customer privacy/IDOR, pricing/payments, admin auth/authorization/CSRF/rate limiting, uploads, injection/XSS/path handling, headers/session, configuration exposure, installer, API, and integrations.

### Excluded / not performed

- No live or staging target was supplied or contacted.
- No dynamic requests, credential guessing, data extraction, destructive checks, real customer data, real payment changes, or third-party provider calls were performed.
- No PHP lint, PHP tests, MySQL tests, browser tests, dependency audit, full source-to-sink review of all 246 PHP files, configuration of an actual host, or penetration test was performed.
- No claim is made that historical reports' CI/browser/database results are reproducible against this checkout or current production.

## 3. Audit procedure and evidence handling

The review started with branch/worktree verification and source inspection before considering tests. It then examined relevant source, hardening files, selected security tests, and earlier audit/risk reports. Searches and code excerpts were used to locate key flows. This was a **targeted static review**, not an exhaustive formal verification.

Evidence is categorized as follows:

1. **Current source observation:** code or configuration read in this audit. Findings refer to file paths and relevant functions or routes; line numbers are omitted because they can drift.
2. **Historical evidence:** claims found in existing reports; useful context, not newly executed test results.
3. **Inference / suspected risk:** plausible impact whose deployment conditions or runtime behavior were not observed.
4. **Untested:** cannot be promoted to PASS without a runtime, live environment, provider, or independent test.

No real secret values were copied into this report. Examples and configuration templates were reviewed; actual production credentials/configuration are absent from the checkout and were not accessed.

## 4. Environment and available tooling

- Checkout was clean at audit start; no working-tree changes were present.
- Node.js and Git were available. PHP CLI, Composer, MySQL/MariaDB CLI, and the searched static-analysis commands (`trivy`, `semgrep`) were not found in the environment.
- A filesystem search for `awesome-skills-security` / security-skill-named installations returned no matches in the searched paths. **No skill execution is claimed.**
- No local web server, disposable database, or browser runtime was established for this audit.
- Outbound internet restrictions and no provider credentials further precluded real integrations. No network testing was attempted.

**Consequence:** all statuses below reflect source/document review, not executed security behavior, unless explicitly stated otherwise. There are no new dynamic PASS results in this report.

## 5. Application architecture and relevant assets

The code is a PHP storefront bootstrapped by `config/config.php`, with shared helpers under `includes/`, customer pages at repository root, JSON endpoints in `api/`, administration in `admin/`, and installation/schema logic in `install/`. MySQL access is centralized in `includes/db.php`; business logic includes `includes/auth.php`, `includes/admin-auth.php`, `includes/admin-perms.php`, `includes/csrf.php`, `includes/cart.php`, and `includes/payment.php`.

Security-relevant assets examined include:

- `config/config.php` — boot, sessions, environment, URL/HTTPS determination, install state, headers.
- `includes/security.php`, `includes/client-ip.php`, `includes/environment.php` — headers, CSP, proxy-derived IP and environment.
- `includes/auth.php`, `includes/admin-auth.php`, `includes/admin-perms.php`, `includes/csrf.php` — identities, session lifecycle, permissions, CSRF.
- `includes/cart.php`, `includes/payment.php`, `api/checkout.php`, `api/payment.php`, `api/orders.php`, `track.php`, `order-details.php` — pricing, order visibility and payment workflows.
- `includes/functions.php`, `includes/category-image.php`, `uploads/.htaccess` — image validation, file writes and serving controls.
- `install/index.php`, `includes/install-guard.php` — installer/repair authorization.
- Root `.htaccess`, `.user.ini`, and directory guards under `config/`, `includes/`, `ai/`, `lib/`, `logs/`, `tests/`, and `uploads/`.

## 6. Severity and status definitions

### Severity

- **CRITICAL:** unauthenticated/low-complexity compromise of the store or broad data/control-plane takeover.
- **HIGH:** likely significant account, payment, privacy, or privileged-impact exposure.
- **MEDIUM:** meaningful abuse or data exposure requiring some guessing, conditions, or bounded impact.
- **LOW:** limited or deployment-dependent weakness with constrained impact.
- **INFORMATIONAL:** hardening, readiness, or assurance gap without a confirmed exploitable defect.

### Verification statuses

- **PASS:** sufficient current evidence for the specific stated control and scope. A source-only PASS is explicitly a source observation and does not prove deployment behavior.
- **FAIL:** current evidence shows the stated control is absent or inadequate.
- **PARTIAL:** some protection exists but is incomplete, optional, or conditional.
- **NOT TESTED:** behavior could not be established from adequate evidence or was not executed.
- **NOT APPLICABLE:** the control does not apply to the feature in scope.

A library/function's existence by itself is never treated as PASS.

## 7. Findings summary

| ID | Severity | Status | Finding | Evidence / condition |
|---|---|---|---|---|
| F-01 | MEDIUM | FAIL (application layer) | Public order tracking is not rate-limited in the reviewed routes; order IDs are predictable/sequential and phone is the second factor. | `track.php`; `api/orders.php`; `includes/functions.php::sh_order_number`; external edge throttling unknown. |
| F-02 | LOW | PARTIAL | HTTPS/proxy detection accepts `X-Forwarded-Proto: https` from any peer when no trusted-proxy entries are configured. | `config/config.php::sh_request_is_https`; `includes/client-ip.php::sh_forwarded_proto_trusted`; deployment-dependent. |
| F-03 | LOW | PARTIAL | Uploaded raster images are validated but not re-encoded; safety against interpreter/misconfiguration risks relies partly on host behavior and Apache/LiteSpeed rules. | `includes/functions.php::sh_upload_validate_image`, `sh_upload_image`; `uploads/.htaccess`; Nginx/host behavior untested. |
| F-04 | LOW / INFO | PARTIAL | CSP defaults to report-only, not enforcement; inline style remains allowed. | `includes/security.php::sh_security_header_list`, `sh_csp_policy`; actual deployment setting unknown. |
| F-05 | HIGH operational | PARTIAL | No gateway initiation driver is implemented, so configured gateway credentials do not make automatic checkout ready. | `includes/payment.php::sh_gateway_can_initiate`, `sh_payment_method_available_for_order`; no live-provider run. This is a readiness blocker, not evidence of payment forgery. |
| F-06 | MEDIUM assurance | NOT TESTED | Full route-by-route customer/admin authorization, two-customer IDOR, and API negative tests were not run in this audit. | Runtime unavailable; selected code and historical reports reviewed. |
| F-07 | MEDIUM assurance | NOT TESTED | Effective headers, CSP enforcement, cookies, web-server routing, upload interpreter denial, secret/config denial, and TLS were not observed on an actual host. | Apache/LiteSpeed/Nginx/PHP-FPM behavior is deployment-dependent. |
| F-08 | MEDIUM assurance | NOT TESTED | Provider signatures/authenticity and retry/replay behavior for courier, messaging and webhook integrations were not tested against provider specifications. | `reports/09-courier-and-webhooks.md` is historical, not reproduced. |

No CRITICAL or HIGH exploitable application vulnerability was confirmed by the limited static review. This does **not** establish absence of one across the full application.

## 8. Detailed confirmed vulnerability — public tracking abuse (F-01)

**Severity:** MEDIUM
**Status:** FAIL for application-layer throttling; edge/WAF protection NOT TESTED
**Relevant weakness classes:** CWE-307 (improper restriction of excessive authentication attempts / request attempts), CWE-200 (potential limited information exposure).

### Evidence

- `track.php` accepts a public POST and looks up an order by order number and customer-phone variants. It enforces the common CSRF token, but the reviewed route contains no rate-limit call.
- `api/orders.php` permits the `track` action without a customer login and queries by order number and exact phone. It invokes CSRF validation, but the reviewed route contains no rate-limit call.
- `includes/functions.php::sh_order_number()` constructs order numbers from a configurable two-letter prefix, the date, and a zero-padded order ID. This makes the ID component predictable rather than secret.
- `api/orders.php` returns order number, order/payment status, total and placed time on a successful match. The public page also renders a status timeline. No address or full customer record is returned by the inspected API response.

### Plausible attack scenario

An unauthenticated attacker can obtain their own CSRF token/session, repeatedly submit candidate order numbers and candidate phone numbers, and automate the lookup. The CSRF token is not an anti-automation control: an attacker can use their own session and token. Sequential IDs reduce the search space for order numbers. A matching order/phone pair could reveal the limited status/timing/amount data returned by the public tracker; repeated traffic can also impose database/log/application load.

### Impact and limits

The current evidence supports a **bounded order-status/privacy and resource-abuse risk**, not access to the full account, addresses, or payment credentials. Exploitability depends on order-number volume/prefix/date knowledge, phone-number guessability, and upstream controls. External rate limits, bot mitigation, or network controls may reduce exposure, but none were available to verify.

### Recommendation

Apply a shared, atomic per-IP and per-order-identifier limiter to **both** public tracking routes, with escalating delay/temporary block, bounded input and monitoring. Keep responses uniform for misses and matches. Avoid relying solely on PHP session counters or CSRF. Consider an opaque high-entropy tracking capability or authenticated tracking in place of guessable order numbers plus phone. Do not permanently lock out legitimate shared-NAT users based on a single key.

## 9. Additional risks and deployment-dependent findings

### F-02 — forwarded-protocol trust

`sh_request_is_https()` returns true for `X-Forwarded-Proto: https` when no proxy list is configured; `includes/client-ip.php::sh_forwarded_proto_trusted()` explicitly treats an empty trust list as compatible with existing deployments. This affects Secure-cookie decisions, generated absolute URLs, and HSTS selection. It does not by itself prove a remote account compromise, but a direct-origin deployment or untrusted proxy chain makes the signal spoofable. Configure the exact trusted proxy ranges; verify direct-origin requests cannot supply a trusted forwarding header. This report does not test a deployed edge.

### F-03 — upload re-encoding / host behavior

The reviewed upload handler checks upload provenance, parses image dimensions/type, allows JPEG/PNG/WebP/GIF, derives a random server-side filename/extension, and does not trust the client filename. `uploads/.htaccess` disables/denies common executable extensions, removes handlers/types, disables the mod_php engine when applicable, denies listing/dotfiles, sets `nosniff`, and applies a restrictive CSP to SVG. However, the file is not re-encoded. `.htaccess` protection does not apply to Nginx and may be overridden/unsupported on some hosts; actual response MIME and PHP execution behavior are untested. Deploy uploads outside the executable document root or enforce a verified server-level no-interpreter policy; consider decode/re-encode for accepted formats.

### F-04 — report-only CSP

`includes/security.php` sets report-only by default unless the stored security setting is `enforce`. In report-only mode the browser does not block policy violations. The policy permits `'unsafe-inline'` styles; it does not permit inline scripts without a nonce. Treat the current default as monitoring, not XSS prevention. Review real reports, then enforce on staging and production after compatibility checks. `style-src 'unsafe-inline'` is a documented residual, not a confirmed exploit.

### F-05 — automatic payments are not ready

`sh_gateway_can_initiate()` in `includes/payment.php` returns `false`. The route therefore marks otherwise configured online gateways unavailable, and the callback path requires a matching pre-bound reference plus provider verification. The SSLCommerz verifier checks reference, successful status, amount, and currency when the response includes it; other drivers return unsupported. This is fail-closed against unverified settlement in the inspected path, but is not proof of end-to-end payment correctness. Do not advertise automated gateway payments as working until a supported initiation/verification flow is implemented and tested with provider sandbox accounts. Manual payment verification remains an operational control and needs trained admin separation/reconciliation.

## 10. Customer authentication, OTP, and session management

### Source observations

- `includes/auth.php::sh_login_user()` and `includes/admin-auth.php::sh_admin_login()` call `session_regenerate_id(true)` at login; CSRF tokens are cleared at the authentication boundary.
- Customer session validation checks a server-side login timestamp, TTL, database account status, and clears password/Firebase fields from the returned user record.
- Admin sessions use a separate session key and two-hour idle timeout (`includes/admin-auth.php`). Admin records are reloaded and status checked.
- Customer and admin password login paths use `password_verify`; the customer password flow uses `password_needs_rehash` on successful login. Failure messages are generally generic for credential mismatch.
- Login throttling combines a per-session counter with database audit-table counting (`includes/auth.php::sh_auth_throttle_blocked`). The source comments say audit-table read failure fails open. The application database itself is also required for authentication; impact under partial outage is unknown.
- Phone OTP logic is in `includes/phone-otp.php`; it hashes issued codes using `password_hash`, checks with `password_verify`, and uses database rate-limit records. Existing reports describe stronger OTP protections, but those results were not executed in this audit.
- Session cookie settings in `config/config.php` and `.user.ini` include HttpOnly, strict mode, cookie-only mode, and SameSite Lax. Secure is conditional on HTTPS detection (see F-02).

### Assessment

**PARTIAL / NOT TESTED.** The inspected source has relevant safeguards, but no login abuse, OTP resend/replay/expiry, session fixation, cookie, account recovery, Google OAuth, email verification, or concurrent-session tests were performed. Confirm production session storage permissions, HTTPS-only delivery, proxy trust, cookie attributes, and provider delivery settings.

## 11. Authorization, privacy, and IDOR

### Source observations

- `addresses.php` obtains the current authenticated user and scopes list/update/delete statements by `user_id`.
- `order-details.php` checks `sh_order_can_view()` before rendering. `sh_order_can_view()` allows the authenticated owner or a guest order present in that session's `guest_orders` list; guest order creation has been removed/blocked in the inspected order creation helper.
- `api/orders.php` requires login for non-tracking actions and applies `sh_order_can_view()` before returning an order status.
- `includes/payment.php::sh_create_order()` rejects unauthenticated users; orders are created for the current server session identity, not a caller-supplied user ID.
- Admin pages sampled use `sh_require_admin()` and module permissions such as `products.view`, `payments.view`, `orders.view`, and `customers.view`; mutation paths commonly call `sh_csrf_require()` and action-specific permission checks.
- `includes/admin-perms.php` defines a permission catalogue and excludes owner-only privileges for non-superadmin roles.

### Assessment

**Source-level PASS for the specific predicates inspected; end-to-end NOT TESTED.** No two-customer browser session or full route enumeration was run. Static ownership predicates are encouraging but do not establish authorization across every object/API/export/alternate route. Validate every customer identifier, order, address, payment proof, support conversation, download, and admin subresource with two isolated customers and least-privilege admin roles.

Historical `SHOP_HAAT_REMAINING_RISKS.md` records that a signed-in second-customer browser IDOR walk remained open at the time of that document. This current audit did not close it.

## 12. Product pricing, checkout, inventory, and payment controls

### Source observations

- `includes/cart.php::sh_cart_summary()` reads product prices and stock from the database, calculates line totals, validates coupons against database records, calculates delivery, and returns a server-derived total.
- `api/checkout.php` recalculates summary server-side. `includes/payment.php::sh_create_order()` accepts a payment method and delivery/coupon selections, but derives totals from the cart summary and requires an authenticated user. It rechecks product status/type/price/stock inside a transaction with row locks and decrements stock within the order transaction.
- Payment submissions in `sh_submit_manual_payment()` are recorded as pending, with transaction ID duplicate checks and database uniqueness work documented in schema/migrations. Admin approval/rejection functions and payment routes were read partially; the separate `tests/payment-security.php` was inspected but not executed.
- `api/payment.php` requires authenticated customer + CSRF for manual submissions. Its callback action requires a known active/configured gateway, existing order, pre-bound reference, and a verification result of verified/declined before settlement. Unsupported drivers are refused.
- Gateway initiation returns false for all drivers in the inspected implementation. Provider setup and live settlement were not tested.

### Assessment

**PARTIAL.** The sampled source does not trust client-supplied total/price and has meaningful transaction controls. Do not equate this with tested concurrency, correct tax/shipping/coupon policy, reconciliation, refund, provider signature, callback idempotency, or live payment readiness. A gateway that is absent/unavailable should not be advertised as a usable payment choice; verify checkout UI/API consistency on staging.

## 13. Admin authentication, authorization, CSRF, and abuse resistance

### Source observations

- The admin authentication module validates active administrator records and session idle time. Login rotates the session ID.
- `sh_require_admin()` centrally invokes CSRF for authenticated admin POST requests; pages frequently also call `sh_csrf_require()` directly. `includes/csrf.php` compares tokens with `hash_equals()` and returns/redirects on mismatch.
- `sh_require_perm()` calls the admin guard and rejects missing role permissions. Sampled product, order, customer, payment, integrations, support, reports, backup, logs, role and security pages demonstrate feature-specific permission calls. Some routes use distinct view/action permissions.
- Admin login uses both session and database-backed throttling according to `admin/login.php`; persistence and IP attribution depend on database logging and trusted client IP configuration.
- The custom admin route is explicitly not authentication by itself; route dispatch still invokes session and permission guards. The standard `/admin.php` route is denied by root rewrite rules.

### Assessment

**PARTIAL / NOT TESTED.** Source review supports the presence of controls on sampled paths, but there was no role-by-role HTTP access test, forced browsing, POST-without-CSRF test, custom-route lifecycle test, or login lockout exercise. The `.htaccess` behavior and route hiding are not substitutes for authorization. Review all write APIs and actions for permission checks at the mutation point.

## 14. Input validation, SQL injection, XSS, and redirects

### Source observations

- Reviewed business queries generally use positional PDO placeholders. `sh_insert()` and `sh_update()` construct SQL identifiers from helper arguments; sampled call patterns appear application-defined, but this was not exhaustively traced. No exploitable request-to-query interpolation was confirmed in the reviewed excerpts.
- `includes/functions.php::sh_safe_redirect()` rejects schemes, protocol-relative URLs, control characters, backslashes, fragments, encoded traversal and unsafe characters. `sh_customer_return_target()` further blocks admin/API/install and authentication return targets.
- Sampled order/address/account templates escape customer-controlled fields through `e()`/`htmlspecialchars`. Admin product descriptions, CMS/page content, SEO metadata, support messages, AI-generated content, and every rendering sink were not exhaustively traced.
- A broad search did not reveal application `eval()`/`unserialize()` use in the sampled application files. Tooling contains controlled shell execution (`tools/restore-rehearsal.php`) and should be restricted to trusted operator/CI use; it is not an HTTP route.

### Assessment

**PARTIAL.** No SQL injection or XSS was confirmed from sampled paths. This is not a whole-program XSS or SQL-injection audit. Test stored and reflected fields in storefront, admin, support, AI, CSV/export, JSON and email contexts; ensure escaping matches HTML attribute/text/URL/JS context. Confirm identifier-helper callers remain allowlisted.

## 15. Upload security and path handling

### Source observations

- `sh_upload_image()` checks upload error state and `is_uploaded_file()` before parsing the source. `sh_upload_validate_image()` enforces configured byte and dimension limits, uses `getimagesize()` MIME, allows raster formats, and chooses the stored extension from detected MIME. Stored names are generated from date plus random bytes; client filename is not used for storage.
- Uploads are written below `SH_UPLOAD_DIR` to selected subdirectories; delete helpers use `basename()` to confine deletions. The category-image helper uses basename and generated output names for derived images.
- `uploads/.htaccess` disables indexes and common script handlers, denies common executable and HTML extensions and dotfiles, sets `nosniff`, and sandbox-restricts served SVG.
- Uploads are not re-encoded. Nginx, LiteSpeed, Apache `AllowOverride`, PHP-FPM, MIME mapping, actual file-serving behavior and upload happy path were not tested.

### Assessment

**PARTIAL.** Source-level validation is strong in the examined flow, but effective non-execution depends on deployed server configuration (F-03). Check every upload entry point, including logos, product gallery, category crop/data URL, promotional images, transitions, payment proofs and AI-generated files. Verify limits at the host, image decompression/resource limits, and no execute permission in all aliases/symlinked paths.

## 16. Security headers, HTTPS, and browser policy

### Source observations

- `includes/security.php` sets `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, Permissions Policy, COOP, and cross-domain policy headers. HSTS is applied only when request HTTPS is recognized and the setting is enabled. CSP defaults to report-only and has a nonce for inline scripts; style allows inline attributes.
- Root `.htaccess` adds static-file `nosniff`, referrer policy, permissions policy and removes `X-Powered-By`; it intentionally leaves CSP to PHP because the policy is nonce-specific.
- `.user.ini` sets PHP session cookie hardening and `expose_php=Off`; runtime cookie settings similarly use HttpOnly, SameSite Lax and conditionally Secure.
- Request host/base URL handling uses `HTTP_HOST` in `sh_site_url()`; exact host allowlisting and canonical-host behavior were not established in this review. Validate at the reverse proxy/web-server edge and avoid generating security-sensitive absolute URLs from an arbitrary host header.

### Assessment

**PARTIAL / NOT TESTED.** Header helper existence is not evidence that every deployed response carries headers. No actual HTTP response, HTTPS redirect, HSTS max-age, duplicate header, reverse-proxy behavior, host header, cookie, CSP violation, or enforced CSP was observed. F-02 and F-04 remain open.

## 17. Secrets, database configuration, and sensitive file exposure

### Source observations

- `.gitignore` excludes live `config/database.php`, installed lock, installer tokens, logs, uploads and backups. The inspected tracked config files are examples/templates; no live database configuration file was reported by the tracked-file query.
- Root `.htaccess` denies `.git`/`.svn`/`.hg`, blocks direct `config/`, `includes/`, and `ai/` paths, denies common sensitive extensions and dotfiles. Directory `.htaccess` files deny config, includes, AI, library, logs and tests. `.user.ini` and config examples avoid real secret values.
- `includes/secrets.php` supports constant/environment/config file resolution, avoids returning values to the browser, and writes generated secret files with mode 0600. The module documents that a database fallback is less isolated from backups.
- `config/database.example.php` recommends a database-scoped account and non-root user. Actual production account privileges, file permissions, backup encryption, access controls, and web-root layout are not known.
- The report intentionally contains no actual passwords, API keys, tokens, or customer data.

### Assessment

**Source-level PASS for repository exclusions and supplied Apache guards, but deployment exposure NOT TESTED.** Apache rules are not Nginx controls; a missing `.htaccess`/disabled overrides or an exposed backup/config outside expected paths may defeat assumptions. Confirm effective HTTP 403/404 behavior, document root, config location, database least privilege, backup access/encryption/retention, log access, and secret rotation on the deployed host.

## 18. Installer, APIs, and integrations

### Installer

`install/index.php` checks installation/database state and delegates access decisions to `includes/install-guard.php`. The guard allows a fresh installation when no config/lock exists, permits an installed-store repair against the saved DB configuration, and requires a filesystem-proven unlock token to rewrite config/create or modify an admin in restricted situations. Wrong-token attempts use a file-backed fail-closed limiter. This source design is materially safer than a publicly reusable installer, but no installer requests, DB outage paths, lock-file behavior, token storage, permissions, or direct HTTP access were dynamically tested. Keep the installer inaccessible after setup where practical and rehearse recovery on a disposable clone.

### APIs

The sampled customer endpoints enforce method checks, CSRF, login/ownership where relevant, and generic error responses. Public tracking is the exception addressed in F-01. A complete endpoint/action matrix, malformed JSON/multipart handling, cross-origin behavior, API caching and rate-limit coverage were not executed.

### Integrations

`reports/09-courier-and-webhooks.md` historically describes signature checks, TLS verification and retry behavior, but this audit only read it as prior documentation and did not reproduce any test. Courier, mail-event, WhatsApp, Telegram, Google/Firebase, SMS/OTP, AI providers and backup integrations require provider-specific staging checks. Secrets, HMAC rotation, callback URL exposure in access logs, replay/idempotency, TLS validation, timeout behavior, redirect behavior and least-privilege provider scopes remain manual verification items. No external provider host was contacted.

## 19. Verification matrix, test method, and untested areas

### Detailed matrix

| Control / requirement | Status | Evidence and qualification |
|---|---|---|
| Customer login password verification and session regeneration | PARTIAL | Read `login.php`, `includes/auth.php`; runtime login, fixation, rehash and lockout tests not run. |
| Admin login and session regeneration | PARTIAL | Read `admin/login.php`, `includes/admin-auth.php`; live DB-backed throttling and timeout behavior not run. |
| OTP hashing, expiry, replay and rate limiting | NOT TESTED | Read selected `includes/phone-otp.php` references; no provider or database execution. Historical tests are not current audit execution. |
| Customer order ownership in `order-details.php` | PASS (source predicate only) | Read `order-details.php`, `includes/payment.php::sh_order_can_view`; no two-session runtime test. |
| Address owner scoping | PASS (source predicate only) | Read `addresses.php`; no second-user HTTP test. |
| Support conversation object authorization | NOT TESTED | Prior IDOR test source/report reviewed partially; no live customer contexts. |
| Public tracking request throttling | FAIL (application-level) | No limiter in reviewed `track.php`/`api/orders.php`; external WAF unknown. |
| Checkout client price tampering resistance | PASS (source path sampled) | Cart summary derives price from DB; checkout ignores client total; no HTTP tampering test. |
| Stock/coupon concurrency and atomic order lifecycle | NOT TESTED | Transaction/row-lock code read; no MySQL/MariaDB run in this audit. |
| Manual payment submission ownership/CSRF | PARTIAL | `api/payment.php` auth-before-CSRF and helper inspected; no negative runtime test. |
| Payment duplicate transaction-ID constraint | NOT TESTED | Source/migration and `tests/payment-security.php` inspected; no database migration or unique-index execution. |
| Gateway callback forgery resistance | PARTIAL | Bound-reference/provider-verification code inspected; no provider callback, signature, replay or live transaction test. |
| Gateway initiation readiness | FAIL (feature readiness) | `sh_gateway_can_initiate()` returns false; configured credentials alone do not activate checkout. |
| Admin role/permission authorization | PARTIAL | Permission helper and sampled route calls inspected; no exhaustive route/role matrix. |
| Admin CSRF protection | PARTIAL | Central POST guard and helpers inspected; no missing/invalid/reused-token runtime test. |
| SQL injection | NOT TESTED (reviewed queries look parameterized) | Selected code only; dynamic fuzzing and full call graph not performed. |
| XSS/contextual output encoding | NOT TESTED | Sampled templates escape fields; whole-rendering-sink audit and browser payload testing not performed. |
| Open redirect handling | PASS (source helper only) | `sh_safe_redirect()` and customer return-target restrictions read; no fuzz/run. Admin-specific redirect flows remain untested. |
| Upload validation | PASS (source validation only) | `includes/functions.php`; no real multipart happy path, malformed image corpus, or resource exhaustion tests. |
| Upload no-execution on host | NOT TESTED | `.htaccess` inspected; no HTTP request to an Apache/LiteSpeed/Nginx/PHP-FPM host. |
| Filesystem/path traversal | PARTIAL | Sampled upload/delete helpers use basename and generated names; complete path-source review not performed. |
| Security headers / HSTS | NOT TESTED | Header generation and `.htaccess` inspected; no real HTTP response or proxy/TLS configuration. |
| CSP | PARTIAL | Report-only default confirmed in source; enforced mode and browser compatibility not observed. |
| Secure/HttpOnly/SameSite cookies | PARTIAL | Source settings inspected; actual `Set-Cookie` headers and TLS proxy conditions not observed. |
| Config, logs, tests, backups exposure | NOT TESTED | Ignore and Apache rules inspected; effective routes, aliases and actual filesystem contents not tested. |
| Installer lock/unlock/repair controls | PARTIAL | State logic/source inspected; no fresh-install, repair, DB-outage, or brute-force exercise. |
| Webhook/courier provider authenticity | NOT TESTED | Historical report only; no live provider or signature interoperability check. |
| Error/log redaction | PARTIAL | `includes/errors.php` redaction helper inspected; no systematic secret-taint corpus or production log review. |
| Dependency/SCA review | NOT TESTED | No dependency lock/vendor inventory was audited with an SCA tool in this environment. |
| PHP syntax / test suites | NOT TESTED | PHP CLI unavailable; no PHP tests or lint run. |
| MySQL/MariaDB behavior | NOT TESTED | No local database client/server available; historic CI claims not rerun. |
| Browser/staging behavior | NOT TESTED | No live target/browser established. |

### Test execution record

- **Executed in this audit:** branch/worktree checks, file inventory, targeted source/config/report reads, and text searches. No application-level tests were executed.
- **Not executed:** PHP tests/lint, MySQL integration, browser automation, manual HTTP checks, upload attempts, auth/IDOR/CSRF exploitation, provider calls, or production configuration checks.
- The repository contains `tests/payment-security.php`, `tests/idor-scan.php`, other security suites, and `tools/security-scan.php`. Their existence or historical output is not treated as a PASS here.

### Historical evidence explicitly not re-certified

Read-only documentary sources included `SHOP_HAAT_COMPLETE_SECURITY_AUDIT.md`, `SHOP_HAAT_SECURITY_REMEDIATION_REPORT.md`, `SHOP_HAAT_REMAINING_RISKS.md`, `SHOP_HAAT_COMPLETE_PROJECT_INVENTORY.md`, `reports/01-payment-workflow-security.md`, portions of `reports/02-idor-object-authorisation.md` and `reports/03-real-mysql-execution.md`, plus selected `reports/08-upload-security.md`, `reports/09-courier-and-webhooks.md`, and `reports/17-final-four-blockers.md`. Those documents report earlier CI/MySQL/browser/upload results and open residual risks. They are dated historical evidence; the current review did not reproduce those tests and does not upgrade their status.

## 20. Prioritized remediation and manual release checklist

### P0 — before public release / real orders

1. **Throttle public tracking (F-01).** Protect both `track.php` and `api/orders.php` with a shared atomic server-side limiter, appropriate per-IP and per-lookup keys, abuse monitoring, and bounded responses. Confirm any CDN/WAF rule covers both paths and cannot be bypassed through query/API variants.
2. **Decide and verify payment readiness (F-05).** If automated gateways are offered, implement and test an official initiation plus server verification flow for each provider; otherwise leave those methods unavailable and clearly operate manual verification only. Run sandbox payment success, decline, wrong amount/currency, wrong reference, callback replay, duplicate callback, timeout, and provider outage cases. Never accept a browser-declared paid state.
3. **Run authorization and CSRF staging checks (F-06).** Use two separate customer accounts and least-privilege admin roles. Attempt cross-customer order/address/payment/support identifiers across every UI and API path; try direct admin URLs and POST with absent, stale, incorrect and cross-session CSRF tokens. Verify no state changes on rejection.
4. **Verify HTTPS and proxy trust (F-02).** Configure exact proxy CIDRs, test direct origin and forwarded-header spoofing, enforce HTTPS at edge/origin, and confirm Secure cookies and HSTS on actual public responses.
5. **Verify upload execution denial (F-03).** On the actual hosting stack, request test files with executable and polyglot extensions in every upload subdirectory. Confirm no PHP/interpreter execution, no directory listing, correct MIME/nosniff, and safe behavior through aliases/CDN. Prefer non-executable storage outside document root or explicit Nginx rules.

### P1 — before broad customer/admin data exposure

6. **CSP rollout (F-04).** Collect report-only violations on staging, fix legitimate breakage, switch to enforce, and verify storefront, checkout, admin, custom admin route, payment redirects and mobile pages. Keep the residual inline-style allowance documented.
7. **Verify web-root/config protections (F-07).** Test direct HTTP access to config, logs, tests, backups, dotfiles, source-control metadata, SQL dumps and editor backups against the deployed server. Confirm actual document root and that secrets/config are outside public storage when possible.
8. **Review admin roles and critical actions.** Map each sensitive action to a least-privilege permission; ensure payment verification, refunds, user/admin management, backup download/restore, integration credentials and audit/log access are constrained and logged. Exercise failure behavior when audit logging is unavailable.
9. **Run integration verification (F-08).** For every enabled provider, validate webhook signature formats and canonicalization, replay/idempotency, timestamp freshness where supported, TLS certificate verification, redirect behavior, timeouts, retry limits, secret rotation and credential redaction in logs/access logs.
10. **Verify operational secrets and database privileges.** Use distinct production secrets, remove development/offline OTP, test credentials and installer unlock material; rotate exposed credentials. Ensure the application DB user has only required database-scoped privileges and that backup files are encrypted/restricted.

### P2 — assurance and resilience

11. Run PHP lint and all security tests on this exact commit in CI, failing the pipeline on blocked/not-run tests. Run disposable MySQL/MariaDB integration and browser suites; preserve outputs with commit and environment identifiers.
12. Perform a focused SAST/DAST/dependency review with current tools, triage findings, and add regression tests for each confirmed issue. Review all SQL identifier helper callers and all output contexts.
13. Test rate-limit storage outage/full disk, session-store outage, database outage, log-write failure, backup restore, concurrent checkout/payment submission, and alert delivery. Establish backup retention, recovery objectives and incident response ownership.

### Manual verification still required

- Actual DNS/TLS/HTTPS redirection, proxy topology, HSTS, host-header/canonical URL behavior, cookie attributes and cache headers.
- Apache/LiteSpeed/Nginx/PHP-FPM `.htaccess`/`.user.ini` support and upload interpreter-denial tests.
- Test every public/admin/API route with unauthenticated, expired-session, customer A/customer B, admin role, and invalid-CSRF cases.
- Verify app and edge rate limiting for login, OTP issue/verify, signup, support, public tracking, installer unlock, CSP reports, webhooks and expensive endpoints.
- Execute the payment state machine on provider sandbox or documented manual-payment process, with no real money/customer data.
- Validate the install/repair lockout and recovery mechanism on a disposable clone only.
- Confirm secret/config/log/backup files are inaccessible over HTTP and are not included in release artifacts or public backups.
- Exercise live provider webhook signatures and idempotency using test accounts; do not test with production customer/payment records.
- Review logs for secret leakage using synthetic canary credentials and confirm redaction/retention/access controls.
- Re-run PHP lint/tests, database integration, browser tests and staging checks on the release candidate, recording tool versions and commit hash.

### Scope-integrity confirmation

At audit start, `git status --short` was empty and the working tree was clean. No project code, configuration, schema, UI, or functionality was changed during this audit. After creating this report, verify the final status contains only `SHOP_HAAT_SECURITY_AUDIT_REPORT.md` as a new/modified file; this confirmation concerns repository changes only and does not certify runtime security.
