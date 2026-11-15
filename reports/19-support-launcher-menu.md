# 19 — Support launcher: circular button and a three-channel menu

Branch `arena/c6d8fd27-shop-haat-otp-system-ai` · PR #16 · final commit `b8851f9`
Browser evidence: **run `43679495646`** — Support launcher UI, **177 checks, 0 failures**
Installer UI re-ran unchanged in the same push (run `43679495626`): **success**

---

## 1. What the widget was before

The storefront already had a floating support widget: a wide orange pill that said
"Support", which opened a chat sheet (`#sh-schat-sheet`) served by `api/support.php`
with `action=start|new|poll|send|handoff`. That sheet — AI replies, conversation
history, owner session handling, unread counts, *Talk to a human*, the escalation
workflow — is the product. **It was not redesigned and not reimplemented.**

Two presentation problems were fixed and one feature was added: a pill that was too
wide for a phone corner, an `aria-expanded` that described the panel instead of the
button's own menu, and no path to the Facebook or WhatsApp channels the shop actually
answers on.

## 2. Launcher

| Property | Value |
| --- | --- |
| Size | 56 × 56 px, `aspect-ratio: 1/1`, `min/max-width` pinned, `flex: none` — it cannot be stretched by its parent |
| Shape | `border-radius: 50%`, `padding: 0`, `margin: 0`, `box-sizing: border-box` |
| Colour | `var(--sh-brand)` `#e8501b`, white 24 px chat glyph, darkens on hover/open |
| Position | `position: fixed`, desktop `right: 16px / bottom: 16px + env(safe-area-inset-bottom)`; ≤ 900 px `right: 12px / bottom: 74px + safe-area` so it clears the mobile bottom navigation |
| Shadow | `0 10px 26px rgba(20,26,40,.26), 0 2px 6px rgba(20,26,40,.16)` |
| Motion | hover `translateY(-1px)`, press `scale(.97)`, `:focus-visible` 2 px accent ring at 3 px offset |
| Unread badge | pinned at the top-right *inside* the circle (`top:-4px; right:-4px`, 20 px pill, white on brand) — the geometry never changes when it appears |
| Text | none. The label lives in `aria-label` ("Customer support"), not on screen |

Both the button and the menu are direct children of `<body>`, so no transformed or
`overflow:hidden` ancestor can clip the fixed panel or the menu.

## 3. Menu

Three rows, stacked directly above the button, `width: min(248px, 100vw - 32px)`:

| Row | Icon | Behaviour |
| --- | --- | --- |
| **Facebook** | inline official Messenger mark | `<a href="…" target="_blank" rel="noopener noreferrer nofollow">` to the configured `m.me` / `facebook.com` link |
| **WhatsApp** | inline official WhatsApp mark | `<a>` to the configured `wa.me`/`api.whatsapp.com`/group link, with the saved pre-filled message appended as `?text=` |
| **Live Chat** | brand chat glyph | `<button>` that calls the **existing** `open()` path — no second chat system |

* **Animation:** `opacity 0 → 1`, `translateY(10px) → 0`, `scale(.96) → 1`, 160–220 ms,
  `transform-origin: 100% 100%`; closing runs the same transition in reverse because
  `visibility` flips only *after* the fade (`transition: … visibility 0s .22s`).
* **Closes on:** option selection, Escape (focus returns to the launcher), a press
  outside (`pointerdown`), and Tab leaving the menu.
* **Keyboard:** `role="menu"` / `role="menuitem"`, ArrowUp/ArrowDown cycle, Home/End
  jump, focus moves into the menu on open.
* **`prefers-reduced-motion: reduce`:** transitions and transforms are removed, but
  the menu still opens and closes.
* **Never leaves the viewport:** fixed position, right/bottom anchored, width capped
  at the viewport minus the edge margins.
* **Unavailable channel:** if a link is unset *or* invalid, the row renders as
  `is-off` + `aria-disabled="true"` + the text "Not set up" — a state, not a dead
  link. **Live Chat is always available**, whatever the other two do.

## 4. Configuration (existing admin page only)

Admin → Support → **Settings** (`admin/support-settings.php`) gained three fields on
the page that already exists. No new settings page, no duplicate screen.

| Field | Stored key | Rule |
| --- | --- | --- |
| Facebook / Messenger link | `support_chat_facebook_url` | `https` only, host must be `m.me`, `facebook.com`, `www.facebook.com`, `web.facebook.com`, `messenger.com`; a page path is required; no credentials, no whitespace/quotes/backslashes, ≤ 512 chars |
| WhatsApp number or link | `support_chat_whatsapp` | a typed number (`+8801700000000`, `0088…`, spaces/dashes/parentheses allowed) is normalised to `https://wa.me/<digits>`; a link must be `https` on `wa.me`, `api.whatsapp.com`, `web.whatsapp.com`, `chat.whatsapp.com` and must carry a phone number or a group code |
| Pre-filled message | `support_chat_whatsapp_message` | optional, 300 chars, single-spaced; appended to the WhatsApp link as `text=` |

Validation lives in `includes/support-links.php` (pure functions, no DB) and is called
from the existing `sh_support_settings_save()`; a rejected value returns the field
error and **saves nothing**. Nothing is hardcoded and no example number is shipped:
the only phone number in the tree is the placeholder inside an error message.

## 5. The defect this work uncovered

The first real-browser run failed with the customer message marked **"Could not send
right now… Retry"** even though the server had answered `200 {"ok":true,…}`.

Cause: `ai/providers/AIHttpClient.php` had its `require_once …/includes/compat.php`
line **above** `<?php` (introduced by the PHP 8.4 `$http_response_header` fix in
`e3437fc`). PHP printed those two lines verbatim, so every JSON endpoint that loaded
that class returned

```
require_once dirname(__DIR__, 2) . '/includes/compat.php';   // sh_http_last_status()
{"ok":true,"conversation":{…},"messages":[{"id":1,…}]}
```

`r.json()` threw, the widget marked an already-saved message as failed, the retry
duplicated it, and no reply was ever rendered. The file now opens with `<?php`; a
`require` probe returns **0 stray bytes**.

Guard added to `tests/security-regression.php` so it cannot come back:

1. every `.php` file must start with `<?php` (repo-wide; this caught exactly 1 offender);
2. no file in `ai/`, `api/`, `config/`, `includes/`, `cron/`, `tools/`, `install/` may
   end on a closing tag, which would emit whitespace into a response.
   Page templates are deliberately excluded — they end with `?>` by design.

## 6. Files changed

| File | Lines | What |
| --- | --- | --- |
| `includes/support-widget.php` | +50/−6 | circle markup, badge, `role="menu"` list, three options, unavailable state |
| `includes/support-links.php` | +204 (new) | link validation/normalisation and the URL accessors |
| `assets/css/app.css` §38 | +245/−46 | launcher, menu, rows, mobile offsets, reduced motion |
| `assets/js/support-chat.js` | +99/−4 | menu open/close, focus, arrows, Escape, outside press, reverse transition; chat code untouched |
| `admin/support-settings.php` | +37/−1 | three fields, saved-value echo, field errors |
| `assets/css/support-admin.css` | +13/−8 | field/row styling for the new settings |
| `includes/ai-support.php` | +19 | validation wired into the existing `sh_support_settings_save()`; **no chat, AI, prompt, handoff or unread code changed** |
| `ai/providers/AIHttpClient.php` | +3/−3 | the `<?php` position fix above |
| `tests/support-links.php` | +243 (new) | 127 checks |
| `tests/support-chat-behavior.mjs` | +81/−5 | menu checks added to the existing 49-check chat suite (76 checks total) |
| `tests/browser/support-launcher.mjs` | +658 (new) | the Chromium suite |
| `.github/workflows/support-launcher.yml` | +179 (new) | MySQL 8 + Chromium job |
| `tests/security-regression.php` | +25 | the stray-output guard |
| `reports/19-support-launcher-menu.md`, `docs/support-launcher/` | new | this report and the evidence |

`api/support.php` is **unchanged** — the endpoint, its actions, its rate limits and its
payloads are exactly as they were.

## 7. Tests actually run

| Suite | Result | Scope |
| --- | --- | --- |
| `tests/support-links.php` | **127 passed, 0 failed** | validation matrix (http rejected, non-official host rejected, credentials rejected, injected characters, 513-char link, bare number, `wa.me` link, group link), three rendered widget variants, markup + CSS contract |
| `tests/support-chat-behavior.mjs` (jsdom) | **76 passed, 0 failed** | menu open/close, focus order, arrows, Escape, outside click, `rel`/`target`, Live Chat routing, plus the pre-existing chat checks |
| `tests/ui-ux-audit.mjs` (jsdom) | **80 passed, 0 failed** | static audit incl. the new launcher/menu styles |
| `tests/security-regression.php` | **186 passed, 0 failed, 1 skipped** | incl. the new stray-output guard |
| `tests/browser/support-launcher.mjs` (CI, Chromium 131 + MySQL 8.0.46) | **177 passed, 0 failed** | see below |
| Installer UI (`installer.mjs`) | success, unchanged | regression only |

The browser job (`.github/workflows/support-launcher.yml`) starts PHP's built-in server
with `log_errors=1`, seeds a demo store, signs in as admin and runs:

1. **Appearance** — 56 px, `aspect-ratio` square, `border-radius` circle, brand colour,
   24 px glyph, no visible text, badge inside the circle, fixed position.
2. **Menu** — exactly three `role="menuitem"` rows, order Facebook/WhatsApp/Live Chat,
   above the launcher, inside the viewport, official icon paths present.
3. **Animation** — computed opacity/transform while opening, reverse on close, and
   `transition: none` under `prefers-reduced-motion: reduce`.
4. **Close paths** — Escape (and focus returns), outside press (URL unchanged), row
   selection.
5. **Real navigation** — Facebook and WhatsApp each open a popup whose URL equals the
   saved link (+ `text=` for WhatsApp) and whose `rel` contains `noopener`.
6. **Configuration** — a real admin save, a rejected `http://` link (error shown, value
   not stored), a rejected non-official host, and the unconfigured store showing
   "Not set up" while Live Chat stays usable.
7. **Live chat** — the existing panel opens, header/new/close/composer/Talk to a human
   are intact, a real message is sent over `action=send` and rendered, no bubble is
   marked failed, and the same conversation escalates on *Talk to a human*.
8. **Responsive** — 320 / 360 / 375 / 390 / 412 / 430 px and 1280 px: no horizontal
   overflow, the circle is never stretched, the menu never leaves the viewport.
9. **Console** — no support-related error, no CSP violation attributed to the launcher.

### A note on the "AI reply" check

The CI store reports *"AI Auto Work is not installed"*, and
`sh_support_process_customer_message()` deliberately **saves the message without
inventing an answer** when no provider is available. The suite therefore reads the
store's real AI state from the settings page and asserts the correct behaviour for it:
with a provider it requires a verified catalogue answer (`৳`/`BDT`/stock); without one
it requires that nothing is fabricated, that the message is still saved and
acknowledged, and that the panel shows no failure. Asserting a model answer in a store
that has no model would have been the wrong test.

## 8. Screenshots

`docs/support-launcher/` — 19 PNGs, from the same CI run:

* `support-launcher-desktop-1280-{closed,open,chat,handoff}.png`
* `support-launcher-{320,360,375,390,412,430}px-{closed,open}.png`
* `support-launcher-reduced-motion-390px.png`
* `support-launcher-unconfigured-390px-open.png` — the two channels marked "Not set up"
* `support-launcher-admin-desktop-1280.png` — the new settings fields
* `support-launcher.txt` — the full transcript (177 PASS lines)

## 9. Residual limits

* **No live provider in CI.** Reply generation is proven by the PHP integration
  suites and by the branch's configured-provider path, not by a paid model call.
* **The badge count is per-session.** The badge reflects unread assistant messages
  the panel has not displayed yet; a fully closed-tab "new reply" notification would
  need server push, which is out of scope and was not added.
* **[Report-Only] CSP notices** (`script-src 'unsafe-eval'`) in the transcript come
  from the harness's own `page.evaluate`, are attributed by
  `securitypolicyviolation`, and are not produced by the launcher — the transcript
  records 0 launcher-attributed violations.
* The WhatsApp pre-filled message is only appended when the saved link is a phone
  link; group invite links have no `text` parameter by design.
