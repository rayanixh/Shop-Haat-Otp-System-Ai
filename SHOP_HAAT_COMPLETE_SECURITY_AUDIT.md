# ShopHaat — Complete Security Audit

**Method:** code review of every entry point, targeted static analysis
(`tools/security-scan.php`, 189 files), and the project's automated suites
(622 assertions executed in this environment) — not a penetration test, and not
a substitute for one.

**Environment used:** PHP 8.5.12 (WASM CLI). No MySQL server, no web server and
no browser were available in this sandbox. Every statement marked *verified* below
was produced by an executed test; everything unverified is marked as such.

Legend for **Kind**:
`V` = confirmed vulnerability · `B` = confirmed functional bug ·
`C` = configuration risk · `P` = performance issue ·
`R` = recommended improvement · `A` = untested assumption.

---

## F-101 · Client IP was attacker-controlled (V, Critical) — FIXED

| Field | Detail |
|---|---|
| File | `includes/functions.php` → `sh_client_ip()` (now `includes/client-ip.php`) |
| Location | former body: `foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] …)` |
| Root cause | Forwarding headers were read before any check that the request arrived through a proxy. |
| Impact | Anyone could send `X-Forwarded-For: <random>` and get a fresh rate-limit bucket per request. Affected: OTP per-IP window (`sh_otp_rate_begin`), customer login throttle (`sh_auth_throttle_blocked`), admin login throttle, support-chat limits (`includes/support.php`), installer unlock (`install/index.php`), CSP report limiting, security-log "Where" values. Effectively removed the per-IP half of every limit. |
| Evidence | Code read; a one-line `curl -H 'X-Forwarded-For: 1.2.3.4'` against any rate-limited endpoint returns a different `sh_client_ip()` each time (reproduced in the unit suite's "spoofed header" cases). |
| Recommended | Trust `REMOTE_ADDR` unless the peer is a configured proxy; validate every address; walk XFF right-to-left; never hardcode a Cloudflare list. |
| Implemented | New `includes/client-ip.php`: strict IP/CIDR parsing, `sh_proxy_mode()` (`auto`/`none`/`cloudflare`/`xff`), `sh_trusted_proxy_entries()` (constant, env or setting, invalid entries dropped), `sh_ip_is_public()` filter for header-supplied values, right-to-left chain walk, live-fetched + cached Cloudflare ranges (`sh_cloudflare_ranges()`, 7-day cache under `logs/cache/`, fail-closed), and `sh_forwarded_proto_trusted()`. `sh_verify_client_ip()`/`sh_verify_ip_in_cidr()` in `includes/verification.php` now delegate to the single implementation. Admin → Security has a one-click "Trust Cloudflare ranges" action that saves the fetched ranges; Admin → System Health reports the policy. |
| Test evidence | `tests/trusted-proxy.php` — 58 assertions, all passing: spoofed CF/XFF ignored, trusted-peer CF honoured, private/malformed values refused, XFF chain walked correctly in both directions, `none`/`xff`/unknown modes, invalid list entries dropped, one implementation only. |
| Remaining limitation | With no trusted list configured, a Cloudflare deployment sees the Cloudflare edge address as the client (limits become coarser, never weaker). The admin action or `SH_TRUSTED_PROXIES` fixes it. |

## F-102 · OTP pepper had no external secret store and no rotation path (V, High) — FIXED

| Field | Detail |
|---|---|
| File | `includes/phone-otp.php` → `sh_otp_rate_pepper()` |
| Root cause | The HMAC pepper was only ever stored in the `settings` table, so it travelled in every database export and every phpMyAdmin backup; there was no way to supply it from the host and no way to rotate it without silently resetting every limit (identifiers are one-way). |
| Impact | Disclosure of the pepper allows correlating hashed identifiers back to phone numbers by brute force over a 10-digit space (≈1.6×10¹⁰ candidates × the known 01[3-9] prefixes — feasible offline). Rotation would have been a silent fail-open for 24 hours. |
| Implemented | `includes/secrets.php`: resolution order constant → environment variable → `config/secrets.php` → database (legacy). Never logs, never renders, empty values ignored, broken file ignored. `sh_otp_rate_peppers()` accepts retired peppers; `sh_otp_rate_key_candidates()` lets every limit query count rows hashed with a previous pepper, so a rotation preserves history. `SH_SECRET_OTP_RATE_PEPPER_PREVIOUS` for explicit rotation. Admin → Security shows the *source label only* and offers "Move to config/secrets.php" (atomic write, `0600`, refuses to overwrite a different value, audited). |
| Test evidence | `tests/secrets.php` (36) — resolution order, source labels, broken-config degradation, write/refuse/round-trip, no value in any page/log. `tests/otp-security.php` §11 (20 assertions) — a row hashed with the retired pepper still counts towards the limit after rotation, new rows use the current pepper, a missing pepper never silently disables limits. |
| Remaining limitation | A pepper that is already leaked stays leaked until rotated; the report documents the rotation procedure. |

## F-103 · Staging was treated as development (C, Medium) — FIXED

| Field | Detail |
|---|---|
| File | `includes/environment.php` → `sh_environment()` |
| Root cause | The development list contained `staging`, so a staging copy allowed the offline OTP driver and OTP code display. |
| Impact | A staging site (often a copy of production data, often publicly reachable) would "verify" any phone number without sending an SMS and could display live codes. |
| Implemented | Three environments — `development`, `staging`, `production` — with production as the default and for every unrecognised value. `sh_is_staging()`, `sh_is_production()`, `sh_debug_allowed()` (development only), and an optional HTTP-basic staging gate (`SH_STAGING_USER`/`SH_STAGING_PASS`, constant or environment) applied once from `config/config.php`. Unprotected staging is reported in Admin → Security and Admin → System Health instead of failing silently. `config/environment.example.php` documents all three. |
| Test evidence | `tests/environment.php` (61) — every accepted spelling, typos fail closed, privilege matrix, gate behaviour with/without credentials. `tests/otp-security.php` §12 (19) — staging refuses the offline driver, echoes no code, produces no debug output, and still accepts a correctly configured real provider. |
| Remaining limitation | `test`/`testing` still map to development for backward compatibility with existing local runs; that is documented. |

## F-104 · `X-Forwarded-Proto` accepted from any peer (C, Low) — MITIGATED

| Field | Detail |
|---|---|
| File | `config/config.php` → `sh_request_is_https()` |
| Root cause | The header was believed unconditionally, so a client could make the application believe it was serving over HTTPS (affects `Secure` cookie flags, generated URLs, HSTS emission). |
| Impact | Self-inflicted only: an attacker forcing `https` for their own session loses their cookie; generated links are the same origin. No cross-user impact found. |
| Implemented | With a trusted-proxy list configured, the header is only believed from a listed peer (`sh_forwarded_proto_trusted()`). With no list configured the historical behaviour is kept so an existing Cloudflare/LiteSpeed deployment cannot be broken. |
| Test evidence | `tests/trusted-proxy.php` §8 (3 assertions). |
| Remaining limitation | Documented in `SHOP_HAAT_REMAINING_RISKS.md`: a store with no proxy list keeps the lenient behaviour for this header only. |

## F-105 · CSP defaulted to report-only (C, Informational) — VERIFIED SAFE TO ENFORCE

The policy has been nonce-based for a while, but "enforce" was never verified.
Static verification now shows the application never relies on anything the policy
blocks:

* 0 inline event-handler attributes (`onclick=…` etc.) across all templates;
* 0 inline `<script>` blocks without the per-request nonce;
* 0 scripts loaded from another origin;
* 0 uses of `eval()` or `new Function()` in `assets/js/*.js`;
* `script-src 'self' 'nonce-…'` with neither `unsafe-inline` nor `unsafe-eval`;
* `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors` configured;
* report receiver (`api/csp-report.php`) exists and is rate limited;
* admin rollback (`report-only` / `enforce` / `off`) present on Admin → Security.

**Evidence:** `tests/csp-readiness.php` (31 assertions, passing).
**Not verified:** actual browser execution of every page under `enforce`. The
recommendation is therefore: enforce on a staging deployment, browse customer and
admin flows, then enforce in production — the switch and the rollback already
exist. `style-src 'unsafe-inline'` is retained deliberately (hundreds of existing
inline `style=` attributes; removing it is a redesign, not a security fix).

## F-106 · Daily OTP limit briefly degenerated into a 60-second window (B, Medium) — FOUND AND FIXED DURING THIS PHASE

| Field | Detail |
|---|---|
| File | `includes/phone-otp.php` → `sh_otp_rate_begin()` |
| Root cause | While centralising the limit queries into `sh_otp_rate_count()`, one call site (the daily limit) kept the old rolling-window argument, so "requests today" became "requests in the last 60 seconds". |
| Impact | The per-number daily cap would have been ~1440× higher than configured. |
| Evidence | Caught by a new assertion: a rate row backdated one hour must still count towards today (`tests/otp-security.php` §4 — "the same request still counts towards today", "an hour-old request blocks the request once the daily limit is 1"). |
| Implemented | The window is now a closed enum (`rolling` with an explicit number of seconds, or `today` → `created_at >= CURDATE()`), and the SQL text is fixed while only `?` placeholders are generated. |
| Remaining limitation | None known. |

## F-107 · Upload handling (A→verified, no change required)

Reviewed `sh_upload_image()`, `sh_product_upload_image()`,
`sh_promo_handle_upload()`, `includes/category-image.php`, `sh_delete_upload()`,
`uploads/.htaccess`. Confirmed: real MIME from `getimagesize()` (not the client
header), allow-list JPG/PNG/WebP/GIF, 16–6000 px, random 16-hex-character
filename with the extension derived from the verified MIME, `0644`, no SVG, no
PHP/HTML/JS reachable, `basename()` before delete, `.htaccess` denies executable
extensions and dotfiles. **Residual risk (A):** images are not re-encoded, so a
polyglot file could exist inside an accepted image; it cannot execute on any
server that honours the extension→handler mapping, but a host that executes
arbitrary extensions would need `uploads/` to be non-executable at the server
level (documented in the deployment checklist).

## F-108 · Static analysis (R, Informational)

`php tools/security-scan.php` → **0 findings across 189 files** (rules cover
request data reaching SQL, unescaped output, dangerous functions, weak token
generation, missing authorization/CSRF, hardcoded secrets, repository hygiene).
A clean run means "no known-bad pattern", not "no vulnerability".

---

## Areas reviewed and found sound (no change made)

| Area | What was checked |
|---|---|
| SQL | Every query uses PDO prepared statements; the only dynamic identifiers are table names from internal allow-lists and now-allowlisted rate-limit columns. No `query()` with interpolated input found in application code (static rule + review). |
| CSRF | `includes/csrf.php` issues per-session tokens, `sh_csrf_require()` on every state-changing API and admin POST, tokens rotated at login. |
| Session | `session_regenerate_id(true)` on login and logout, 24 h server-side timestamp, `HttpOnly`+`SameSite=Lax`+`Secure`-when-HTTPS cookies, strict mode and cookie-only sessions in `.user.ini`. |
| Admin authorization | `admin/_layout.php` + `sh_require_perm()` at the top of each module; role matrix in `includes/admin-perms.php`. Spot-checked sensitive screens (`admins.php`, `roles.php`, `backups.php`, `settings.php`, `security.php`). |
| Login throttling | Session counter + `security_logs` counters keyed by client IP and hashed identity; admin login has a tighter budget (5/15 min per identity, 25/15 min per IP). |
| XSS | `e()` used for output; the allow-list sanitizer now has a DOM-less fallback that emits no attributes (previous phase). |
| Secrets in logs | `sh_scrub_secrets()` redacts password/token/OTP/authorization patterns before anything is written to `logs/`. |
| Payment price integrity | Checkout totals are recalculated server-side from database rows (`api/checkout.php`, `includes/payment.php`); browser-submitted totals are not trusted. **Full end-to-end payment audit is still pending — see below.** |
| Backups | Pure-PHP dump into `storage/backups/` with `.htaccess` deny + `index.php` 403, token-authenticated cron; **restore has not been rehearsed** (pending). |

## Not yet audited (explicitly open)

1. **Payments (Phase 5)** — transaction-id uniqueness, duplicate submission,
   manual verification workflow, status-transition validation. Code was read at a
   high level only.
2. **IDOR sweep (Phase 6)** — order/profile/address/tracking ownership on every
   customer endpoint and AJAX handler.
3. **Database audit (Phase 4)** — index and query-plan review requires a live
   MySQL/MariaDB server, which this environment does not have.
4. **Performance (Phase 12)** — no profiling performed; no claims made.
5. **Functional/browser regression (Phases 15, 17)** — no web server or browser
   here; the jsdom suites were not re-run after these changes.
6. **Courier/webhook verification (Phase 10)**, **notification retry (Phase 11)**
   — reviewed at file level only.
7. **Backup restore rehearsal (Phase 14)** — not performed.

These are listed again, with owners and risk wording, in
`SHOP_HAAT_REMAINING_RISKS.md`.
