# 20 — Installation page restored, overflow fixed

Branch `arena/c6d8fd27-shop-haat-otp-system-ai` · PR #16
Browser evidence: Installer UI run **`43690682363`** — **fresh 123, blocked 64, repair 82 checks; 0 failures**

---

## 1. The page is the original one again

`install/index.php` is restored from `269187c`, the commit the page had before the
redesign, and nothing of the redesign is left:

| Removed by the restore | |
| --- | --- |
| four-step indicator and progress bar | summary metrics (`Required passed 7/7`, …) |
| verdict strip (“Ready to install”) | sticky action bar and its *Refresh requirements* button |
| `callout` alerts | `panel` / `panel--done` wrappers |
| two-column optional-extension cards with chips and badges | `.sr-only` state suffixes |

What is back: the centred `SH ShopHaat` mark, the `Install your store` heading and its
one-line subtitle, one **Server requirements** card holding the seven required rows and
the seven optional rows with their green values on the right, the **Database
connection**, **Website** and **Administrator account** cards on a two-column grid that
collapses at 640 px, the full-width orange **Install ShopHaat** button, the restricted
repair card and the owner unlock card. Same colours (`--brand: #e8501b`), same 10 px
card radius, same 820 px page width, same 15 px system font stack, same spacing.

The whole change against the original file is **20 lines added, 4 changed**:

```
install/index.php | 24 ++++++++++++++++++++----
```

## 2. The four fixes

### Overflow (the brief's item 1 and 3)

```css
.wrap{width:100%;min-width:0}          .card{min-width:0}
.req li{gap:6px 12px;align-items:baseline}
.req li>span{min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}
.req li>span:first-child{flex:1 1 auto}
.req li>span:last-child{flex:0 1 auto;margin-left:auto;text-align:right}
.card h2,.head h1,.head p,.card p,.alert,.hint,label,.check,.done{overflow-wrap:anywhere}
code{overflow-wrap:anywhere}           .btn{max-width:100%;white-space:normal}
.f-in{max-width:100%;min-width:0}      .grid>div{min-width:0}
.actions a{max-width:100%;text-align:center}   .alert li{overflow-wrap:anywhere}
```

* A long requirement name or a long detected value **wraps inside its own cell**
  instead of widening the row, so the status never leaves its box and the name never
  runs under it (`min-width:0` defeats the flex minimum-content floor, which is what
  lets a flex child push its parent wider).
* Long extension descriptions (“mbstring — Character-safe length/truncation”) break
  after the dash and continue on a second line; the status keeps its own space and
  drops to the right of it.
* Paths and `<code>` samples wrap (`/config/database.php`, `config/install-unlock.key`).
* Buttons are capped at their parent’s width and are allowed to break their label
  (`max-width:100%; white-space:normal`), so the primary button can never be wider
  than the card it belongs to.
* **No `overflow:hidden` anywhere** — the browser test asserts the stylesheet has no
  clipping rule at all, and no element in the page is clipped by its own box.

There are no icons on this page, so nothing can overlap text; the one alignment that
matters — the status column — is geometry-checked (see §4).

### Duplicate heading and repeated explanation (item 4)

* The completion card repeated the page title: `<h1>Installation complete</h1>` was
  followed by `<h2>Setup finished</h2>`. The second heading is gone; the two sentences
  under it stay.
* The optional-extensions paragraph (two lines of explanation under the heading)
  was removed entirely on request — the list now follows its heading directly.
  Nothing else was trimmed: every requirement row, every install instruction, the
  restricted-repair explanation and the owner-unlock instructions are intact, and all
  error messages remain.


### The result the server computed is now shown

The page calculated a result string on completion (`Database repaired against <db>…`,
`Installation completed successfully.`) and never rendered it, so a repair finished
with no confirmation at all. It is now the first line of the completion card. The
“The installer is locked and will not run again.” line and the *Delete the `/install`
folder* warning are untouched.

### A failed install no longer prints the raw exception

`$errors[] = 'Installation failed: ' . $e->getMessage();` put the exception text on the
page, which for a database failure can name the host, the user and the driver. It is
still logged (`sh_log_exception`) and the page now says *“Installation failed. The
details were written to the server log.”* The `alert--bad` block and its list are
unchanged.

## 3. What was not touched

The installation engine, `install/schema.php`, `install/demo-data.php`,
`includes/install-guard.php`, the CSRF token, the owner unlock, the fail-closed unlock
rate limit, the demo-data ON/OFF normalisation, the password policy, the repair path
that ignores posted credentials, and the lock file are byte-identical to the original.
No new dependency, no animation, no extra section, no new card, no new page.

## 4. Tests actually run

`tests/browser/installer.mjs` was rewritten for this markup. It no longer trusts the
layout: for every state and every width it measures the rendered page.

| Measurement | What fails it |
| --- | --- |
| sideways scroll | `documentElement.scrollWidth` or `body.scrollWidth` > viewport |
| viewport containment | any element’s box outside `[0, viewport]` |
| **card containment** | any element inside a card outside that card’s content box (padding-aware) |
| **overlap** | a requirement’s name box intersecting its status box |
| **status row** | a status whose top differs from its label’s top by more than 2 px (it wrapped underneath), a status not flush to its row’s right edge, a status that is not right-aligned |
| clipping | any element whose `scrollWidth` exceeds its `clientWidth` under a clipping `overflow` |
| buttons | any `.btn` / `.actions a` wider than its parent or outside it; any control under 40 px tall |
| stylesheet | any `overflow:hidden` rule |
| original design | `--brand` ≠ `#e8501b`, card radius ≠ 10 px, page width ≠ 820 px |
| **no redesign** | any of 22 redesign-only selectors present (`.steps`, `.progress`, `.summary`, `.verdict`, `.actionbar`, `.panel`, `.opt-grid`, `.chip`, `.badge`, `.callout`, …) |

Run in CI (`43690682363`) against MySQL 8.0.46 and PHP 8.2:

| Phase | Result |
| --- | --- |
| fresh — original requirements card, optional list, form fields/CSRF/labels, desktop, **320/360/375/390/412/430 px**, a complete real installation, the completion screen at 1280/320/430, lock file, redirect, storefront, admin sign-in | **123 passed, 0 failed** |
| blocked — `config/`, `uploads/`, `logs/` genuinely `chmod 0555`: three red “Not writable” rows, install button disabled, all six phone widths | **64 passed, 0 failed** |
| repair — restricted card, no credential fields, no stored password in the markup, a wrong unlock text refused in plain language, the unlock card at all six phone widths, a real repair over the saved connection | **82 passed, 0 failed** |

Other suites re-run on this branch after the restore:

| Suite | Result |
| --- | --- |
| `tests/security-regression.php` | **186 passed, 0 failed, 1 skipped** |
| `tests/otp-security.php` | **199 passed, 0 failed** |
| `tests/rate-limit-failsafe.php` | **34 passed, 0 failed** |
| `tests/trusted-proxy.php` | **58 passed, 0 failed** |
| `tests/ui-ux-audit.mjs` | **80 passed, 0 failed** |

Those five assert the installer’s PHP contract by reading the file — the access rules,
the unlock throttle, the demo-data normalisation, the password policy, the absence of a
sweeping upsert — so they are the guard that the restore did not change behaviour. They
pass unchanged.

## 5. Screenshots

`docs/installer-ui/` — published by the same CI run:

* `installer-desktop-1280px.png` — the original page on desktop
* `installer-requirements-{320,360,375,390,412,430}px.png` — the phone matrix
* `installer-blocked-desktop-1280px.png`, `installer-blocked-{320…430}px.png` — real read-only folders
* `installer-repair-desktop-1280px.png`, `installer-repair-{320…430}px.png` — restricted + unlock cards
* `installer-done-desktop-1280px.png`, `installer-done-320px.png`, `installer-done-430px.png`
* `installer.txt`, `installer-blocked.txt`, `installer-repair.txt` — the full transcripts

## 6. Residual notes

* The rows are a two-column grid (`minmax(0,1fr) fit-content(50%)`), so a wrapped
  label never pushes its status onto another line: the status stays on the row and at
  the right edge by construction, and only very long status text (an optional
  extension's “Fallback: …” sentence) wraps inside its own column.
* The page has no JavaScript beyond the original password-length hint, so there is
  nothing else that can overflow at runtime.
* `reports/18-installer-ui-redesign.md` is kept as a record and marked superseded.
