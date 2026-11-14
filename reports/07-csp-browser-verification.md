# 07 — CSP verification in a real browser

**Status: NOT EXECUTED — no browser, and no web server that can execute PHP, exists in this
sandbox.** The static half (31 checks) passes; the dynamic half is a copy-paste procedure.

```
$ php tests/csp-readiness.php
passed 31   failed 0
```

## Why it cannot run here

`tests/csp-readiness.php` reads the policy, the templates and the nonce plumbing. To *verify*
a CSP you need a browser that loads real pages and reports violations:

* The sandbox PHP is a WASM CLI build — it has no built-in web server, so `php -S` cannot
  serve the app.
* No headless Chromium/Firefox is installed, and the browser binaries are not reachable from
  the four allowed network hosts.

An "it looks fine" claim about CSP is exactly the kind of statement this project has been
avoiding. So: nothing below is presented as a result.

## What is statically confirmed (31 checks)

* The policy ships in **report-only** mode by default (`security_csp_mode`), so enabling it
  cannot break a live store before it is understood.
* `script-src` uses a per-request nonce; inline event handlers were replaced by `data-*`
  attributes handled in `assets/js/app.js`, so no `onclick=` survives in the templates.
* Inline `style="…"` attributes are allowed (`style-src 'unsafe-inline'`) because the
  existing templates use hundreds of them — documented as a deliberate trade-off, not an
  oversight.
* `api/csp-report.php` is POST-only, size-capped, truncates and strips control characters,
  never echoes, and is rate limited with a file-backed counter that works during a database
  outage.
* `frame-ancestors`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` are present.

## The verification procedure (needs a browser)

```bash
# 1. deploy the branch to staging, keep the policy in report-only
#    Admin → Security → Security headers → CSP mode = report-only

# 2. browse every template with DevTools open (Console + Network)
#    storefront: index, category, product, search, cart, checkout, order-success
#    account:    login, signup, verify-email, orders, order-details, payment, wishlist
#    support:    the chat widget open/close/send
#    admin:      dashboard, orders (with the AJAX verification fragment),
#                products, product-edit (image gallery), payments, backups,
#                security, system-health, support inbox
#    installer:  install/index.php (fresh copy, not the live database)

# 3. expected: zero "Refused to load/execute" messages in the Console
#    and zero blocked requests in Network

# 4. read what the browsers actually reported
tail -n 200 logs/*.log | grep -i csp

# 5. only when the report stream is quiet for a full day of real traffic:
#    Admin → Security → CSP mode = enforce, then repeat step 2

# 6. confirm the banner is not clickable away
curl -sI https://your-store/ | grep -i -E 'content-security-policy|frame-options|x-content-type'
```

## Known items to look at during step 2

| Item | Why |
| --- | --- |
| `data:` images | Product/coupon logos sometimes use data URIs; `img-src` must include `data:` |
| Inline `style=` | Allowed by policy; a `style-src` tightening is a separate, larger job |
| `blob:` downloads | The CSV/report download path may use a blob URL (`worker-src`/`blob:`) |
| Admin image previews | `URL.createObjectURL()` output is `blob:` |
| Google/Firebase sign-in | `connect-src`/`frame-src` must allow the identity endpoints if that feature is on |

## Honest summary

If a CSP violation exists in a template, **this report would not find it**. The policy is
ready to be tested, the collector is safe to expose, and the switch to `enforce` is one
setting — but the verification step is a browser session on staging, performed by a person,
and it has not happened yet.
