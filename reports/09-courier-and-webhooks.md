# 09 — Courier integrations and inbound webhooks

**Status: PASSED (39 checks) · CREDENTIAL REQUIRED (live courier/mail provider)**

```
$ php tests/webhooks-and-retries.php
passed 39   failed 0
WEBHOOK/RETRY SUITE: ALL CHECKS PASSED
```

Section 1 of that suite executes the real email-webhook verifier; the courier and
notification checks are contracts over the shipped code. Nothing here claims a live courier
API was contacted.

## Email provider webhook (`api/email-events.php` → `sh_email_webhook_verify()`)

Two proofs are accepted because providers differ: a shared key in the URL, or an
HMAC-SHA256 of the raw body in a signature header. Executed matrix:

| Case | Result |
| --- | --- |
| no secret configured | refused — *"No webhook secret is configured."* |
| secret shorter than 24 characters | refused |
| wrong `?key=` | refused |
| wrong `?secret=` | refused |
| correct key | accepted |
| key compared case-sensitively | `UPPERCASE` key refused |
| correct HMAC in `X-ShopHaat-Signature` | accepted |
| `sha256=` prefix | tolerated |
| UPPERCASE hex signature | accepted |
| signature in generic `X-Signature` | accepted |
| valid signature, one byte of body changed | **refused** (tamper proof) |
| well-formed but wrong signature | refused |
| `zzzz` signature | refused |
| 63-character signature | refused |

Both comparisons use `hash_equals()` (asserted in source). Verification happens **before**
anything is written. The endpoint is rate limited (`sh_file_rate_limit('email_webhook', …)`,
120/min per IP, fail-open so telemetry is never dropped) and logs every refusal with the
reason.

Replay is handled by idempotency: the event id is stored, and replaying it changes nothing.

### Residual risk, stated rather than hidden

* The URL-key form puts the secret in an access log. It exists because some providers cannot
  sign. **Recommendation:** enable HMAC on the provider side and use the header form; keep the
  key form only for providers that have no alternative.
* There is no timestamp/nonce on the HMAC. That is acceptable *because* the payload is
  idempotent, but it means a captured body can be replayed to no effect — not "detected".
* An HTTPS-only deployment is assumed; the endpoint cannot tell whether TLS terminated
  correctly in front of it.

## Courier integrations (`includes/courier.php`)

Couriers are **polled**, not called back: there is no inbound courier webhook to authenticate,
so the security property is what the app does when it calls out.

| Property | Verified |
| --- | --- |
| TLS verification on | `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2` |
| Redirects never followed | `CURLOPT_FOLLOWLOCATION => false` — credentials in a POST body cannot be bounced to another host |
| Every request has a timeout | `CURLOPT_TIMEOUT => $timeout` (stream fallback sets the same) |
| Credentials redacted from logs | `$bad = ['password', 'secret', 'token', 'api_key', 'api_access_token', 'client_secret', 'signature']` |
| Credentials never put in a browser-visible URL | no `$_GET['api_key']`-style parameter anywhere |
| Failure is reported as failure | non-2xx → `sh_courier_api_error()`; a delivery is never inferred from a timeout |

Provider status → order status mapping only moves an order forward through its lifecycle;
the mapping is in `sh_courier_status_hint()` and covered by
`tests/courier-connection-tests.php`, which **requires a real provider account and a MySQL
server** and therefore did not run here.

## What could not be executed

| Item | Why | Where to run it |
| --- | --- | --- |
| A real Pathao/Steadfast/RedX/Paperfly call | no account, no network egress to those hosts | staging with merchant credentials |
| A real Brevo/Mailgun event webhook | no provider, no public URL | staging + provider dashboard test event |
| WhatsApp Cloud API webhook (`includes/whatsapp.php`) | needs a Meta app | staging |
| Telegram bot control surface (`includes/telegram.php`) | needs a bot token | staging |

Each of those is a ten-minute check once credentials exist, and each is listed in report 14's
manual checklist rather than being waved through here.
