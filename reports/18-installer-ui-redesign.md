# 18 — Installation wizard UI redesign

> **SUPERSEDED — the owner asked for the original installation page back.**
> The redesign described below was reverted in `install/index.php`; the page is
> the original one again, with only overflow, box and responsive fixes plus the
> removal of duplicated helper text. See
> [report 20](20-installer-restore-and-overflow-fix.md) for what was restored,
> what was fixed, and the browser evidence. This report is kept as a record of
> the work that was undone.

**Scope: presentation only.** The wizard keeps the installation engine it already had. What changed
is the markup, the stylesheet and the layout of `install/index.php`, plus a browser harness that
proves the new interface still installs a real store.

| | |
| --- | --- |
| Screen | `install/index.php` (the only file whose rendering changed) |
| Delivered | 2026-11-14 · branch `arena/c6d8fd27-shop-haat-otp-system-ai` · commits `23ef115`…`b5cbacf` |
| Browser evidence | CI run **`43640083658`** (`b5cbacf`), job "Installer UI (real install, phone widths, screenshots)" — **162 checks, 0 failures** |
| Static suites | `security-regression` 185/0/1 · `otp-security` 199/0 · `rate-limit-failsafe` 34/0 · `trusted-proxy` 58/0 · `php -l` **243/243** · security scan **0 findings** |
| Screenshots | `docs/installer-ui/` (21 PNGs, produced by the run above) |
| Transcripts | `docs/installer-ui/installer.txt`, `installer-blocked.txt`, `installer-repair.txt` |

---

## 1. What the brief asked for, and where each item is answered

| Requirement | Where it is now |
| --- | --- |
| Header: logo, title, welcome copy, step indicator, progress | §3 masthead: ShopHaat mark, "Install your store", two-sentence lead, four labelled steps, progress bar with a live "Step 2 of 4 · Database configuration" line |
| Progress must reflect the real workflow, no fake progress | §4 — the bar is derived from results the backend produced, never from a timer or a stored flag |
| Server requirements: name left, value right, badges, error detail only when needed | §5 — seven rows, real values, `Passed`/`Failed` badges, a fix block that appears only when something failed |
| Optional extensions: compact, labelled optional, non-blocking, 2 columns → 1 | §6 — seven items, `Optional` chip, purpose + fallback, two columns on desktop and one column ≤ 760px |
| Summary block: required passed/failed, optional available, readiness | §5 — four tiles rendered from the same arrays the rows are rendered from |
| Action area: Refresh, Continue, guidance, safe errors | §7 — Refresh re-runs the real checks (a page-level GET), Continue submits the same form the backend already validated; the raw exception text of a failed install is no longer printed |
| Mobile 320/360/375/390/412/430, no overflow, no clipping, no masking | §8 — asserted in Chromium at all six widths, twice (ready and blocked), plus the completion screen at 320 and 430 |
| Code quality: semantic markup, escaping, no duplicate/inline CSS, CSRF untouched | §9 |
| Screenshots + honest testing report | §8, §9, §10 and §11 |

## 2. What did **not** change

The install engine is byte-identical: every database call, the repair path, the owner-unlock
path, the seeding transaction, the lock file, `sh_db_state()`, `sh_install_access()`,
`sh_csrf_token()`/`sh_csrf_valid()`, `sh_upload_image()`, `sh_password_min_length()` and the
order of validation are the code that was already there. The requirement checks still call the
same functions in the same order:

```php
version_compare(PHP_VERSION, '8.1.0', '>=')   extension_loaded('pdo')
extension_loaded('pdo_mysql')                  extension_loaded('json')
is_writable(SH_CONFIG_DIR)                    is_writable(SH_UPLOAD_DIR)   is_writable(SH_LOG_DIR)
```

`$reqOk` is still "no required check failed", and it still gates the submit button and the
`POST` handler ("Please resolve the server requirement issues listed above before installing.").
The optional list is still built by filtering `sh_extension_requirements()` on `required === false`,
so the seven optional extensions and their fallbacks come from `includes/compat.php`, not from
the template.

Two presentation-side defects were also fixed, and both are honest improvements rather than
behaviour changes:

* **`$notice` was computed and never rendered.** "Installation completed successfully.",
  "Installer unlocked…", "Database repaired against …" and the owner password-reset notice were
  all assigned to a variable the template never echoed, so the success path ended on a page with
  no confirmation. The notice is now rendered as a status callout (the browser run asserts the
  text).
* **A failed install printed the raw exception.** `'Installation failed: ' . $e->getMessage()`
  could surface the database host, user or a filesystem path. The exception is still written to
  the install log by `sh_log_exception()`; the page now says what happened without the internals.

## 3. The screen

```
┌ masthead (white, full width) ─────────────────────────────────────────────┐
│  [SH] ShopHaat                              (New installation | Installed)│
│  Install your store                                                       │
│  Welcome to ShopHaat. This one-time setup checks your hosting account…    │
│  ① Server requirements  ② Database config  ③ Store config  ④ Complete     │
│  ▬▬▬▬▬▬▬▬░░░░░░░░░░░░░░░░░░░░   Step 2 of 4 · Database configuration     │
└───────────────────────────────────────────────────────────────────────────┘
┌ Server requirements ─────────────────────────────── [✓ Ready to install] ┐
│ PHP version 8.1 or newer            8.2.35   [✓ Passed]                   │
│ /config directory writable        Writable   [✓ Passed]                   │
│ … 7/7 required passed · 0 failed · 7/7 optional · Ready                   │
└───────────────────────────────────────────────────────────────────────────┘
┌ Optional extensions ─────────────────────────────────────────────[Optional]┐
│ mbstring            [✓ Available]   DOM / libxml        [✓ Available]     │
│ cURL                [✓ Available]   OpenSSL             [✓ Available]     │
└───────────────────────────────────────────────────────────────────────────┘
┌ ② Database configuration  ③ Store configuration   Administrator account ┐
└───────────────────────────────────────────────────────────────────────────┘
┌ Ready when you are ──────────  [⟳ Refresh requirements]  [Continue →]     ┐
└───────────────────────────────────────────────────────────────────────────┘
```

Section numbers follow the step labels, so the form is not a fourth undifferentiated box: the
database block is step 2, the store block step 3, and the administrator block sits inside step 3
as a sub-section because the same submit creates it.

## 4. Why the progress indicator cannot lie

There is no timer, no cookie and no "step" query parameter. The state of every step is computed
from what the backend already knows on that request:

| Real state | Steps shown | Bar | Line under it |
| --- | --- | --- | --- |
| A required check fails | 1 current, 2–4 pending | **0 %** | Step 1 of 4 · Server requirements |
| All required checks pass, nothing submitted yet | 1 complete, 2 current, 3–4 pending | **25 %** | Step 2 of 4 · Database configuration |
| Installer wrote the lock file (`$done`) | 1–4 complete | **100 %** | All four steps are complete |
| Repair mode (installed store, needs attention) | 1 complete, 2 current, 3 *skipped*, 4 pending | **25 %** | Step 2 of 4 · Database configuration |

Skipped step 3 is struck through and carries the screen-reader text "not part of a repair", so a
repair never claims to have configured a store. The bar's `aria-valuenow` is the completed-step
percentage; the browser run asserts 0 % in the blocked phase, 25 % when the requirements pass and
100 % only after a real installation.

## 5. Server requirements

| Row | Requirement shown | Detected by | Fix hint (only shown when it fails) |
| --- | --- | --- | --- |
| PHP version | 8.1 or newer | `version_compare(PHP_VERSION, '8.1.0', '>=')` | "Switch this hosting account to PHP 8.1 or newer (cPanel → Select PHP Version), then reload this page." |
| PDO extension | — | `extension_loaded('pdo')` | "Enable the PDO extension for the selected PHP version in cPanel → Select PHP Version → Extensions." |
| pdo_mysql driver | — | `extension_loaded('pdo_mysql')` | "Enable pdo_mysql. The store cannot reach MySQL or MariaDB without this driver." |
| JSON extension | — | `extension_loaded('json')` | "Enable the JSON extension for this PHP version." |
| /config directory | writable | `is_writable(SH_CONFIG_DIR)` | "Set the config folder permission to 0755 in cPanel → File Manager → Permissions." |
| /uploads directory | writable | `is_writable(SH_UPLOAD_DIR)` | same, for `uploads` |
| /logs directory | writable | `is_writable(SH_LOG_DIR)` | same, for `logs` |

The value column is the detected value itself — `8.2.35`, `Enabled`, `Writable` — never a
hardcoded string. The CI run compares the PHP row against `php -r 'echo PHP_VERSION;'` from the
same build (`8.2.35` vs `8.2.35`), and the blocked run is produced by making the three folders
genuinely read-only for the runner account, so the failure text and the fix hint are exercised
rather than described.

The summary tiles are computed in the same pass as the rows (`$reqPassed`, `$reqFailed`,
`$optAvailable`, `$optTotal`), and the "Overall readiness" tile is `Ready` only when
`$reqFailed === 0` — the same condition that enables the submit button.

## 6. Optional extensions

Hardcoded nowhere: the list is `sh_extension_requirements()` filtered on `required === false`,
so it is always these seven, with the purpose and fallback text the application actually ships.

| Extension | Purpose | When missing |
| --- | --- | --- |
| mbstring | Character-safe length/truncation | `UTF-8 shim in includes/compat.php` |
| DOM / libxml | HTML sanitising for AI drafts | `Allow-list tokenizer (no raw HTML)` |
| cURL | SMS + payment HTTP gateways | `Gateway reported as unavailable` |
| OpenSSL | SMTP TLS + secure tokens | `Mail TLS reported as unavailable` |
| GD | Image resizing / cropping | `Original image kept, no thumbnail` |
| fileinfo | Upload MIME inspection | `Extension/MIME allow-list only` |
| intl | Locale-aware formatting | `Plain number/date formatting` |

Presentation follows the brief: an `Optional` chip in the header, a one-line explanation that
nothing here blocks the install, a neutral `Available` chip for what is present and an amber
`Fallback` chip plus the "Uses: …" line for what is not. These rows never render a red badge, and
the browser run asserts that no error styling exists inside the optional grid.

## 7. Action area

* **Refresh requirements** — a `GET` submit that reloads the page, which is exactly how the
  checks have always been re-evaluated. It cannot be mistaken for a form submit: it lives in its
  own `<form>` beside the primary button, and the panel shows the time of the check
  ("Checked on this server at 02:42 (server time)").
* **Continue** — `type="submit" form="install-form"`, i.e. the same `POST` the old button sent to
  the same handler, carrying the same CSRF token and the same field names. Disabled exactly when
  `$reqOk` is false, which is the rule the previous button used.
* **Guidance** — one sentence in each state: what Continue does, or what to fix and where.
* **Errors** — the existing messages, unchanged, plus the two presentation fixes in §2. The
  browser run scans the rendered page for `/home/…`, `/var/www` and `C:\inetpub` and finds none.

## 8. Mobile verification

Chromium, six widths, on both the ready and the blocked screen, plus the completion screen at
320 and 430:

| Check | 320 | 360 | 375 | 390 | 412 | 430 |
| --- | --- | --- | --- | --- | --- | --- |
| no sideways scroll (`scrollWidth ≤ innerWidth`) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| no element outside the viewport | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| no label squeezed below 48 px | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| controls ≥ 40 px tall | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| optional extensions in one column | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

The third row exists because the first screenshot round exposed a real defect: at 320 px the
extension name was compressed into a one-character column beside its chip ("m / b / s / t…"), a
layout that both overflow checks passed because nothing left the viewport. It is fixed
(`flex: 1 1 110px` plus wrapping), and the guard now fails the run if any name renders narrower
than 48 px.

Screenshots (`docs/installer-ui/`):

| State | Files |
| --- | --- |
| Requirements ready | `installer-desktop-1280px.png`, `installer-requirements-{320,360,375,390,412,430}px.png` |
| Requirements blocked | `installer-blocked-desktop-1280px.png`, `installer-blocked-{320,360,375,390,412,430}px.png` |
| Installation complete | `installer-done-desktop-1280px.png`, `installer-done-320px.png`, `installer-done-430px.png` |
| Repair screen | `installer-repair-desktop-1280px.png`, `installer-repair-done-desktop-1280px.png` |

## 9. Accessibility and code quality

* Landmarks and headings: one `<h1>`, `<main>`, `<section>` per panel with `aria-labelledby`,
  `<ol>`/`<ul>` for the step rail, the rows and the optional grid.
* The current step carries `aria-current="step"`; each step's state is also in text via a
  visually hidden suffix, so colour is never the only signal. The progress bar is a real
  `role="progressbar"` with `aria-valuenow`.
* Every input has a `<label for>` (asserted); the failed rows are listed in an `role="alert"`
  callout; notices use `role="status"`.
* Focus rings are visible (`:focus-visible`, brand outline, 2 px offset) and every control is at
  least 40 px tall.
* Escaping: all dynamic text goes through `e()`; the one inline style is the progress width,
  cast to `(int)` (asserted: exactly one `[style]` in the document).
* No inline event handlers (the shipped CSP check also asserts this), the existing inline script
  keeps `sh_csp_nonce_attr()`, and the stylesheet no longer contains `overflow: hidden` anywhere —
  the visually-hidden utility uses `clip-path` instead (asserted in the run).
* The installer remained self-contained: `assets/css/app.css` is rendered by PHP through the
  storefront theme and cannot be relied on before the store exists, so the page keeps its own
  stylesheet, now organised in numbered sections with the storefront's palette tokens.

## 10. Evidence

```
$ node tests/browser/installer.mjs          # SH_INSTALL_PHASE=fresh
Installer UI (fresh): 101 passed, 0 failed

$ SH_INSTALL_PHASE=requirements-fail node tests/browser/installer.mjs
Installer UI (requirements-fail): 44 passed, 0 failed

$ SH_INSTALL_PHASE=repair node tests/browser/installer.mjs
Installer UI (repair): 17 passed, 0 failed
```

Three phases in one job (`.github/workflows/installer-ui.yml`), against `mysql:8.0`, PHP 8.2 and
Chromium 131:

1. **fresh** — the requirements screen, the refresh action, the form's field/CSRF/label
   inventory, the six phone widths, then a complete installation through the form: the run checks
   that `config/database.php` and `config/installed.lock` did not exist before, fills the form,
   submits, waits for the completion panel, and then verifies that `/install/` redirects, that
   the storefront returns 200 and shows the site name it just configured, and that
   `admin/login.php` returns 200.
2. **requirements-fail** — a copy of the checkout with `config/`, `uploads/` and `logs/` set to
   `0555`, served on a second port. The workflow refuses to treat "the folders were still
   writable" as a pass: it greps the HTML for `Not writable` first and aborts if the state was not
   really produced.
3. **repair** — the installed store with its lock file removed, which is the realistic
   "restored a partial file copy" state. Asserts the restricted panel, the `Repair mode` chip, the
   struck-through step 3, that the repair form offers **no** database credentials, that the saved
   password is never written into the page, and then runs the repair and confirms the notice, the
   untouched administrator and a working storefront.

The job writes its screenshots and the three transcripts to `docs/installer-ui/`, so every claim
above can be re-read from the repository without downloading an artifact.

Locally (sandbox, no browser): `php -l` 243/243, security scan 0 findings, `security-regression`
185 passed / 0 failed / 1 skipped, `otp-security` 199/0, `rate-limit-failsafe` 34/0,
`trusted-proxy` 58/0. The four existing CI jobs also stayed green on the same PHP file
(`43639473898`, run for `5e26f5f`).

## 11. Defects found while redesigning, and what is *not* covered

Six defects, five of them presentation:

| # | Defect | Where it came from | Now |
| --- | --- | --- | --- |
| 1 | `$notice` never rendered | pre-existing template | rendered as a status callout; asserted |
| 2 | raw exception text on a failed install | pre-existing template | generic message on the page, full detail in `/logs` |
| 3 | inherited list bullets in the optional grid | first redesign pass | `list-style: none`; caught in screenshot review |
| 4 | extension name squeezed to one character at 320 px | first redesign pass | wrap + `flex-basis`; new 48 px guard |
| 5 | optional grid stayed two columns on phones | first redesign pass | one column ≤ 760 px; asserted at every width |
| 6 | redundant "Required" middle column in every row | first redesign pass | removed; the badge already said it |

Honest limits of this evidence:

* **Chromium only.** No Firefox or Safari/WebKit run. The CSS uses nothing exotic
  (grid, flex, `clip-path`), but that is an argument, not a measurement.
* **Widths 431–1279 px are not asserted.** The brief named desktop and 320–430; the tablet band
  is covered only by the responsive rules, not by a test.
* **No real cPanel host.** The blocked state is produced with permission bits on the CI runner.
  On a host that ignores permission bits (a root-owned FastCGI pool, for example) `is_writable()`
  answers differently — the page reports whatever it is told, which is the point, but only one
  of those two host behaviours is exercised here.
* **`php -S` is not Apache.** The installer sends no `.htaccess`-dependent output, but that is a
  code-reading conclusion.
* **No JavaScript-disabled run.** The page needs no JavaScript to render or submit; the only
  script is the password-length meter. Not asserted.
* **No localization/RTL run.**
* The screenshots are Chromium renders of a real page; nothing in them is mocked.

## 12. Files

| File | Change |
| --- | --- |
| `install/index.php` | Presentation layer rewritten (head, masthead, requirement/summary/optional panels, form sections, action bar, done state, responsive CSS); detection, validation, repair/unlock paths and CSRF untouched apart from the two fixes in §2 |
| `tests/browser/installer.mjs` | New: 162 browser checks in three phases |
| `.github/workflows/installer-ui.yml` | New job: MySQL 8.0 + PHP 8.2 + Chromium, three phases, screenshots and transcripts published to `docs/installer-ui/` |
| `docs/installer-ui/` | 21 screenshots and 3 transcripts from run `43640083658` |
| `reports/18-installer-ui-redesign.md` | This report |

## 13. Verdict

**DELIVERED.** The installation wizard is a new interface over the existing engine: 162 browser
checks pass in CI, including a complete installation through the redesigned form, the blocked
state built from genuinely unwritable folders, and the repair screen on an installed store. All
four existing CI jobs remained green on the same PHP file, and the local static suites did not
move.

What a reader should not conclude: that this was verified on Firefox/Safari, on a tablet viewport,
or on a real cPanel host — see §11.
