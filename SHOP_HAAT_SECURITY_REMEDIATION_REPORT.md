# ShopHaat — Phone OTP Security Remediation Report

**Scope:** the phone verification (OTP) subsystem — issuing, delivery, verification,
rate limiting, provider configuration, admin safety guards, and the PHP
environment assumptions the rest of the application was silently making.

**Method:** everything below was changed in place on the existing project. No UI
redesign, no rewritten authentication flow, no demo behaviour added to
production, no limit relaxed to make a test pass. Every claim in the *Test
evidence* section comes from a suite that was actually executed; the limits of
that evidence are listed explicitly in *What was NOT verified*.

---

## 1. Findings that were fixed

| # | Severity | Finding (before) | Consequence |
|---|----------|------------------|-------------|
| 1 | Critical | A failed SMS delivery deleted the verification row, and every rate limit was computed by counting rows in `otp_verifications`. | A delivery failure erased its own trace, so an attacker could request codes without limit (SMS bombing, provider bill, number enumeration). |
| 2 | Critical | Limits were plain `COUNT(*)` reads followed by a separate insert. | Two concurrent requests both passed the check ("check-then-act" race), so a burst could exceed the configured allowance. |
| 3 | Critical | `phone_otp` mode could be enabled (or installed) while the provider was unusable, and the `offline` driver — which reports success without sending an SMS — could be selected on a live store. | Customers reached a login form that could never complete; worse, the offline driver would "verify" any number while returning the code to the client. |
| 4 | Critical | `admin/security.php` printed the last issued OTP value in a banner with no environment or provider gate. | A live code could be displayed on a production admin screen. |
| 5 | High | Rate-limit history lived in the same table as live codes. Deleting or expiring a code reset all counters. | Limits were trivially bypassed; there was no auditable request history. |
| 6 | High | `resend_count` was written on every insert but never read or enforced. | The "max resends" setting did nothing; a customer could request unlimited replacement codes. |
| 7 | High | `strip_tags()` was the fallback sanitizer when `DOMDocument` is missing. | `strip_tags()` keeps attributes, so `<p/onmouseover=…>` or `<a href="javascript:…">` survived on hosts without the DOM extension (common on budget shared hosting). |
| 8 | Medium | `mbstring` was treated as a hard install requirement, and `mb_*` was called unconditionally. | Installation failed on hosts without mbstring; where it was bypassed, calls fatally errored. |
| 9 | Medium | `sh_phone_normalize()` collapsed `8801…` and `+8801…` but left the local `01…` form alone. | The most common customer input produced a second identity: two rate buckets, two customer rows, and an invalid E.164 (`+0171…`) for the SMS gateway. |
| 10 | Medium | `$http_response_header` was read after a stream request. | Deprecated in PHP 8.4+; it emitted a notice on every admin page load. |
| 11 | Low | A provider registered from code (`sh_otp_register_driver`) was still reported as an "unknown provider". | Custom transports could never pass the readiness check. |

## 2. What was implemented

### 2.1 Dedicated rate-limit storage (hashed, append-only)

Two new tables, created by the runtime DDL **and** by the installer schema
(`sh_otp_rate_schema_sql()`, `SH_OTP_SCHEMA_VERSION` 4 → 5):

* `otp_rate_limits(id, phone_key CHAR(64), ip_key CHAR(64), purpose, outcome, provider, user_id, created_at)`
  — indexes `idx_orl_phone_time`, `idx_orl_ip_time`, `idx_orl_created`.
* `otp_rate_locks(bucket_key CHAR(64) PRIMARY KEY, locked_at)` — index `idx_orl_lock_age`.

Identifiers are **never stored in plaintext**: `phone_key`/`ip_key` are
`hash_hmac('sha256', "kind:value", pepper)` where the pepper is a per-install
random value kept in settings. `sh_otp_rate_key()` normalises the number before
hashing, so `+8801…`, `8801…` and `01…` cannot open three different buckets.

Outcomes recorded: `pending`, `requested`, `failed`, `throttled`. A failed
delivery is now **counted**, not erased — the verification row is dropped (so no
unusable code lingers) while the request stays in the rate log.

### 2.2 Atomic reservation instead of check-then-act

`sh_otp_rate_begin()` opens a transaction, takes `SELECT … FOR UPDATE` row locks
on the phone and IP buckets (`sh_otp_rate_lock()`), re-counts inside the lock,
inserts a `pending` row, and commits. `sh_otp_rate_finish()` then marks it
`requested` or `failed`. Two simultaneous requests can no longer both win the
last slot, and the per-number window cannot be double-booked.

The three limits are enforced in SQL, in the same transaction:

1. per number — `otp_phone_rate_limit` requests per `otp_phone_rate_window`;
2. per device/IP — `otp_ip_rate_limit` per `otp_ip_rate_window`;
3. per number per day — `otp_daily_limit` (calendar day, `CURDATE()`).

A resend cooldown (`otp_resend_cooldown`) and a hard `otp_max_resends` cap are
applied on top, so neither setting is decorative any more.

`sh_otp_rate_cleanup()` (retention 30 days, locks 1 day) is invoked
probabilistically (1 in 200 requests) from the issue path; it never touches
`otp_verifications`.

### 2.3 Upgrades and backward compatibility

* `sh_otp_rate_ensure()` creates the tables lazily when the version stamp is
  missing (`otp_rate_schema_version`), so a store that updates by replacing
  files still gets working limits on its first OTP request. Only a *success* is
  cached: a transient failure is retried rather than refusing OTP for the whole
  request.
* `sh_otp_rate_backfill()` copies up to 2000 verification rows from the last 24
  hours into the new log (hashed), once, guarded by the
  `otp_rate_backfilled` marker — so limits are not silently reset at deploy
  time. It is read-only with respect to `otp_verifications`.
* `install/migrations/2026-11-otp-rate-limits.sql` — `CREATE TABLE IF NOT EXISTS`
  only, re-runnable, stamps `otp_schema_version=5` and `otp_rate_schema_version=1`.
* `install/migrations/2026-11-otp-rate-limits-rollback.sql` — drops the two new
  tables and nothing else.
* No migration deletes or rewrites customers, orders, admins or verification
  rows, and **no migration changes an existing store's authentication mode**.

### 2.4 Provider readiness and production safety

* `sh_otp_provider_config_errors()` / `sh_otp_provider_ready()` /
  `sh_otp_operational()` / `sh_otp_configuration_error()` return admin-safe,
  secret-free reasons.
* `offline` is refused unless `sh_is_development()`; `firebase` is refused
  (it issues its own codes); `textbee` requires a key and cURL;
  `generic_http` requires an HTTPS URL and cURL. Transports registered from code
  are recognised as known and ready.
* `includes/environment.php` — `sh_environment()`/`sh_is_development()` with
  **production as the default**, resolved from `SH_ENVIRONMENT` (constant,
  `config/environment.php`, then the environment variable). A typo resolves to
  production, i.e. the safe answer.
* `api/otp.php` — every failure now maps through `sh_otp_http_status()`:
  429 for rate limits/cooldown, 503 for configuration problems, 502 for provider
  rejection, 422 for invalid input. A code is echoed only when
  `sh_otp_allow_code_echo()` is true (development **and** the offline driver),
  and only for the superadmin `test` purpose.
* `admin/security.php` — refuses to enable phone OTP when the provider is not
  ready, refuses to save `offline` outside development, validates the provider
  against the whitelist, shows a readiness callout, and gates/relabels the
  test-code banner.
* `admin/settings.php` — the authentication-mode switch is blocked while
  `phone_otp` is selected and the provider cannot deliver.
* `admin/system-health.php` and `install/index.php` — required vs optional
  extensions are reported separately, with the fallback each optional one uses,
  plus an OTP readiness item.

### 2.5 Verification and resets

* `sh_otp_consume()` is a single atomic `UPDATE … WHERE verified_at IS NULL AND
  expires_at > NOW() AND attempts < max_attempts`; `rowCount() !== 1` means the
  code was already used, expired, or exhausted. A losing concurrent request can
  therefore never be accepted twice.
* Expiry is decided by the database clock, never by comparing PHP `time()`
  against a MySQL datetime string; attempts are bumped in SQL and capped by
  `max_attempts`.
* Codes remain `password_hash()` values. Plaintext never enters the database, the
  rate log, or the security log (which records a masked number only).

### 2.6 Missing-extension resilience

* `includes/compat.php` — pure-PHP UTF-8 shims (`sh_compat_mb_strlen`,
  `sh_compat_mb_substr`, truncation, positions, encoding validation, Latin-1
  conversion); `mb_*` is used when present, the shim otherwise.
* `sh_extension_requirements()` marks `pdo`, `pdo_mysql`, `json` as required and
  `mbstring`, `dom`, `curl`, `openssl`, `fileinfo` as optional with their
  fallbacks.
* `includes/functions.php` — `sh_sanitize_html_fallback()`, a tokenizer used when
  `DOMDocument` is unavailable: allow-listed elements only, **no attributes ever
  emitted**, `script`/`style`-like containers dropped with their content, other
  unknown elements unwrapped, output auto-closed. Prose such as `a < b and 1 > 0`
  survives as escaped text because a real tag never has whitespace after `<`.
* `$http_response_header` (deprecated in PHP 8.4) replaced by
  `stream_get_meta_data()['wrapper_data']`, which works on every supported
  version.

## 3. Test evidence

Environment: PHP 8.5.12 (WASM CLI), extensions pdo, pdo_sqlite, pdo_mysql,
json, mbstring, dom, curl, openssl. All PHP files in the repository pass
`php -l` (0 syntax errors). MySQL/MariaDB is not installed in this sandbox, so
the suite ran against SQLite with a PDO shim that translates only the MySQL
constructs this module uses (`NOW()`, `CURDATE()`, `DATE_ADD/SUB … INTERVAL ?`,
`TIMESTAMPDIFF`, `GREATEST`, `<=>`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`,
`FOR UPDATE`, `information_schema` probes).

```
$ php tests/otp-security.php
passed 157   failed 0
OTP SECURITY SUITE: ALL CHECKS PASSED

$ php tests/security-regression.php
passed 185   failed 0   skipped 1        (baseline unchanged; 1 skip = HTTP headers, N/A here)

$ php tests/support-unit.php
Passed: 58 · Failed: 0                  (baseline unchanged)

$ php tests/support-sms-transport.php
SMS transport contract and diagnostics (no live provider): passed 25, failed 0
```

(`tests/support-system.php` needs a live MySQL server and could not run here; it
was not part of the baseline either.)

`tests/otp-security.php` (new, 10 sections) covers:

1. Schema/migration/rollback: runtime DDL identical to installer DDL, idempotent,
   hashed columns only, indexes present, canonical inventory, version bump,
   migration re-runnable without `DROP`/`DELETE`/`ALTER`, rollback limited to the
   two new tables, lazy creation, version stamping, backfill runs once, existing
   verification rows preserved.
2. Successful request: code issued, code returned only through the dev gate,
   rate row recorded as `requested` with a 64-char HMAC identifier (phone and IP),
   no OTP value and only a masked number in the security log, production request
   stores a `$`-prefixed hash, no code in a production response.
3. Failed delivery: three failures are reported as `delivery`, no verification row
   is left behind, **all three requests are still counted**, the phone limit
   blocks afterwards, and neither deleting OTP rows nor expiring them resets it.
4. Per-IP window and daily limit: exactly N allowed, N+1 blocked with the right
   message, another IP unaffected, daily count ignores other numbers.
5. Verification: correct code verifies, single use, replay refused, wrong attempt
   increments, exhaustion refuses even the correct code, attempts never exceed
   `max_attempts`, expired code refused, purpose binding, user binding, newest
   code supersedes the previous one.
6. Concurrency: one winner consumes the row, the loser is refused, consumed
   exactly once, a second consume is a no-op, a row at the cap cannot be
   consumed, one slot per window, `FOR UPDATE` reservation used.
7. Production provider safety: offline refused on production with a
   provider-neutral customer message and an admin-side configuration record,
   TextBee missing key, non-HTTPS gateway, Firebase, unknown provider, offline
   allowed only in development, admin guards on both screens, no installation
   default enables phone OTP, upgrade never rewrites the authentication mode.
8. Retention/privacy: cleanup removes only old history, recent history kept, no
   plaintext number anywhere, stable HMAC per number, distinct numbers and
   phone/IP kinds stay distinct.
9. Compatibility: UTF-8 shims, phone masking, `+880`/`880`/`01` normalisation,
   extension requirement split, installer and system-health reporting, the
   DOM-less sanitizer against nine payloads, no attributes emitted.
10. Regression: CSRF still required, POST-only, superadmin-only test purpose,
    unknown purpose rejected, every rate statement prepared with placeholders,
    no interpolated phone/IP, hashes only, CSPRNG, SQL-side expiry/attempt
    enforcement, email+password mode leaves OTP off, mode round-trips.

## 4. What was NOT verified (be explicit before deploying)

* **The MySQL DDL path was not executed.** No MySQL server exists in this
  environment; on SQLite the suite instead asserts that the shipped DDL is
  idempotent and identical to the installer's, and that an un-stamped install
  fails **closed** (requests refused) rather than silently disabling limits.
  Run once against a real database before going live:
  `SH_TEST_MYSQL_DSN='mysql:host=…;dbname=…' php tests/otp-security.php`
  — the suite then drops and re-creates both tables to exercise the real DDL.
* **No SMS was actually sent.** TextBee and custom gateway transports were
  exercised with stubbed drivers; the HTTP client, its timeout and its
  response parsing were not put in front of a live provider.
* **Admin screens were lint-checked and reviewed, not rendered.** There is no
  database or web server in this environment, so `admin/security.php`,
  `admin/settings.php`, `admin/system-health.php` and `install/index.php` were
  not visually inspected after the change.
* **The browser/UX suites from the previous task were not re-run** (no jsdom
  harness and no rendered pages here); no CSS or layout file was touched by this
  work.
* Nothing here is a claim that the application is fully secure or
  production-ready. It is a claim that the listed defects are fixed and that the
  listed checks passed.

## 5. Deployment checklist

1. Deploy the files.
2. Apply the migration — either run
   `install/migrations/2026-11-otp-rate-limits.sql` in phpMyAdmin, or simply open
   any page that issues an OTP (the lazy `sh_otp_rate_ensure()` creates the two
   tables and stamps the version). No column or data of any existing table is
   altered.
3. Leave `config/environment.php` absent (or set `production`) on the live store.
   Only a development/staging copy may define `SH_ENVIRONMENT` as `development`.
4. Admin → Security → Phone verification: confirm the readiness callout is
   green. TextBee needs its API key; a custom gateway needs an HTTPS URL.
5. Admin → System Health: the new OTP item should report the provider as ready,
   and the optional-extension list should name any fallback in use (mbstring,
   DOM, cURL, openssl, fileinfo).
6. Smoke test: request a code, receive it, verify it, and confirm a second
   request inside the cooldown is refused with HTTP 429.
7. Rollback (only if needed): run
   `install/migrations/2026-11-otp-rate-limits-rollback.sql`. Codes, customers
   and orders are untouched; limits fall back to the previous behaviour.

## 6. Recommended follow-ups (not done here)

* Run the MySQL-mode suite on staging and record the result.
* Re-run the browser audit (`tests/ui-ux-audit.mjs`,
  `tests/product-management-behavior.mjs`) after the admin screens are rendered
  against a seeded database.
* Add a scheduled job (cron) for `sh_otp_rate_cleanup()` instead of relying on
  the 1-in-200 probabilistic call.
* Consider notifying the admin (email/System Health badge) when the provider
  becomes unready while `phone_otp` is the active mode.
