# 14 — Full browser regression

**Status: NOT EXECUTED — this sandbox has no browser and no web server that can execute PHP.**

```
$ command -v chromium chromium-browser google-chrome firefox
(no output)

$ ls .playwright-cli 2>/dev/null
(not installed; the CLI cannot download binaries — only npm/pypi/github are reachable)
```

The repository ships `.mjs` browser suites (`tests/*-behavior.mjs`, `tests/ui-ux-audit.mjs`).
They need a served application plus Playwright. **None of them ran here**, and I am not
reporting them as passing.

## What did run, and what it is worth

| Suite | Result | What it covers |
| --- | --- | --- |
| `tests/product-management-render.php` | 16 fixture cases render | The product page, cart and product-form HTML are produced by the real templates against fixture data (no browser, no assertions on interaction) |
| `tests/security-regression.php` | 185 passed, 1 skipped | Markup-level contracts: no inline handlers, nonce present, no `onclick=`, no `javascript:` URLs |
| `tests/csp-readiness.php` | 31 passed | Policy, nonces, collector safety |
| `tests/idor-scan.php` | 42 passed | Route guards (including the AJAX fragments a browser calls) |
| `tests/admin-access-url.php` | 240 passed, 9 not run | The 9 need to spawn a PHP subprocess; php-wasm reports a non-executable `PHP_BINARY` and there is no `php` on `PATH` (see below) |

That is a code-level safety net. It is **not** a browser regression: it cannot see layout,
focus order, a broken fetch, or a JavaScript exception.

## The 9 checks that could not run (environment, not regressions)

`admin-access-url.php` probes the admin guard by writing a small PHP file and running it with
`shell_exec(PHP_BINARY …)`:

```
$ php -r 'var_dump(is_executable(PHP_BINARY));'
bool(false)   // /tmp/php-wasm-bin…/php — a temporary binary a subprocess cannot run
```

The same 9 fail at HEAD before any change in this phase. On a machine with a normal PHP binary
they execute; nothing in the guard changed here.

## The regression script to run on staging (copy-paste)

```bash
# 0. prerequisites
npm i -D @playwright/test && npx playwright install chromium
php -S 127.0.0.1:8080 -t .        # or your staging URL in SH_TEST_BASE_URL

# 1. the shipped behaviour suites
SH_TEST_BASE_URL=http://127.0.0.1:8080 npx playwright test tests/*.mjs
#   admin-access-preview-behavior   admin-access-regression
#   admin-notification-regression   navigation-drawer-behavior
#   no-autosave-behavior            page-transition-behavior
#   product-management-behavior     provider-identity-assets
#   provider-test-behavior          slider-behavior
#   support-admin-inbox-behavior    support-chat-behavior
#   support-chat-composer-behavior  support-chat-optimistic-behavior
#   support-settings-ui-behavior    ui-ux-audit

# 2. the manual matrix that no script replaces
```

### Manual matrix (30 minutes, ordered by risk)

| # | Step | Expect |
| --- | --- | --- |
| 1 | Log in as customer A, open another customer's `order-details.php?id=<B>` | 404 "Order not found", no data leaked (report 02) |
| 2 | Same for `payment.php?id=<B>` | redirect to login, then 404 |
| 3 | Upload a 5 MB JPEG in Admin → Product → Images | gallery shows it; `uploads/products/` has a `2026…-<16hex>.jpg`, mode 644 |
| 4 | Upload a `.php` renamed to `.png` | refused, "not a readable image" |
| 5 | Request `/uploads/products/<file>.php` directly | 403/404, never executed |
| 6 | Admin → Orders → the courier verification fragment (AJAX) | loads; an expired session shows the refresh hint, not a broken page |
| 7 | Admin → Payments → verify a payment | one audit row, one email, order → processing |
| 8 | Click Verify again on the same payment | "already verified", no second email |
| 9 | Submit a manual payment with a TrxID already used on another order | "already been submitted for another order" |
| 10 | Admin → Backups → Create backup now | row appears as completed with a size; the file downloads |
| 11 | Run `cron/backup.php --token=…` while a download is in progress | "Skipped: another backup is already running" or a completed run; **the downloaded file is never deleted** |
| 12 | Admin → Security → Move the OTP pepper, on a host where `config/` is read-only | error explaining nothing changed + manual steps (report 13) |
| 13 | Mobile viewport 390 px: storefront, cart, checkout, admin dashboard | no horizontal scroll, filters reachable, tables scroll inside their card |
| 14 | Keyboard-only: tab through checkout | visible focus, no trap, the submit button reachable |
| 15 | Console on every one of the above | no uncaught error, no CSP "Refused to…" in report-only mode (report 07) |

## Honest statement

Anything a browser would reveal — a broken image, a JS exception, a layout regression, a focus
trap — is **unverified in this environment**. The static suites above reduce the risk; they do
not replace step 15.
