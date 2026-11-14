# 08 — Upload security

**Status: FIXED (validation split, testable) · PASSED (51 checks)**

```
$ php tests/uploads.php
passed 51   failed 0
UPLOAD SECURITY SUITE: ALL CHECKS PASSED
```

## The change

`sh_upload_image()` mixed three jobs: HTTP error decoding, file validation, and writing to
disk. Because `is_uploaded_file()` only returns true for a genuine POST, the validation half
could never be tested outside a browser — which is how a rejection path rots.

It is now two functions:

| Function | Job |
| --- | --- |
| `sh_upload_validate_image(array $file, int $maxBytes = 0, int $maxDim = 6000)` | Pure. Size ceiling, real MIME via `getimagesize()`, minimum and maximum dimensions, HEIC/HEIF advice. Returns the MIME type **and the extension the file will be stored with**. Writes nothing. |
| `sh_upload_image(array $file, string $subdir, int $maxBytes = 0, int $maxDim = 6000)` | Decodes `$_FILES` error codes, runs `is_uploaded_file()` **before** anything parses the client-named temp path, calls the validator, creates the folder, writes with a derived name. |

Order matters: the source check still runs first, so nothing reads an arbitrary local path an
attacker named in `tmp_name`. The validator is what the caller cannot skip.

## Every rejection path, executed

| Input | Result |
| --- | --- |
| `UPLOAD_ERR_NO_FILE` | "No file selected." |
| array-shaped `error` (crafted multipart) | "Invalid upload." |
| `UPLOAD_ERR_INI_SIZE` | names the server ceiling and how to raise it |
| `UPLOAD_ERR_PARTIAL` / `NO_TMP_DIR` / `CANT_WRITE` | each has its own message |
| 500-byte file over the app limit | "File is larger than …" |
| `<?php echo 'pwned'; ?>` named `shell.png` | "not a readable image" |
| PNG header followed by `<?php system($_GET['c']); ?>` | refused (no valid image data) |
| `<svg><script>alert(1)</script></svg>` | refused as an upload |
| plain text | refused |
| HEIC photo | phone-specific advice ("Most Compatible" / JPEG) |
| 16×16 PNG with a 15 px ceiling | "Image dimensions must not exceed 15px." |
| 1×1 PNG | "Image is too small (minimum 16x16 pixels)." |
| GIF named `actually-a-png.png` | detected as `image/gif` → stored `.gif` |
| valid PNG that did not arrive via POST | "Invalid upload source." |

## The stored name cannot be chosen by the attacker

```php
$name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . (string)$checked['ext'];
```

* The extension comes from the MIME type `getimagesize()` detected — not from `$_FILES['name']`.
* 64 random bits make the name unguessable and un-collidable.
* `chmod 0644`; the folder is created `0755` at most.
* The client filename never reaches a filesystem call (asserted in the suite).

## What the web server will refuse to do with `/uploads`

`uploads/.htaccess` (asserted file by file):

* denies `.php .php[0-9] .phtml .phar .pl .py .cgi .sh .htaccess .html .htm`;
* `RemoveHandler` / `RemoveType` for every interpreter, plus `php_flag engine off` guarded by
  `<IfModule mod_php.c>` so PHP-FPM hosts do not return HTTP 500 for the whole folder;
* `Options -Indexes` + `IndexIgnore *` (no directory listing, `.htaccess` not listed);
* dotfiles denied;
* SVG — which the installer writes for demo products — is served with
  `Content-Security-Policy: default-src 'none'; script-src 'none'; object-src 'none'; sandbox`,
  so even a hand-placed SVG cannot run script;
* `X-Content-Type-Options: nosniff` on everything in the folder.

Deletion is confined: `sh_delete_upload('../secret.txt', 'products')` stays inside
`uploads/products/` because the path is reduced with `basename()` (asserted).

## Not executed here

* **The happy path.** `move_uploaded_file()` requires a real POST, and there is no web server
  in this sandbox. One manual upload through Admin → Product → Images on staging closes it.
* **`sh_uploads_reachable()`**, which fetches a real image to detect the Apache-500 case —
  needs a live host (`HOSTING LIMITED`).
* **A huge upload against `upload_max_filesize`** — the code path is exercised via the
  simulated `UPLOAD_ERR_INI_SIZE`, but the host's real ceiling is a staging fact.

## Final verification pass — one stale assertion replaced with real execution

`tests/product-management-integration.php` (case P05) asserted the upload size limit by
matching an exact expression in `includes/functions.php`:

```
$file['size'] > $maxBytes
```

The upload hardening in `ac63117` moved the check into `sh_upload_validate_image()` and made it
overflow-safe:

```php
if ($maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes) {
```

The behaviour was unchanged (and stronger), but the literal no longer matched, so the suite
reported a failure that looked like a security regression and, because the suite exits non-zero,
it turned the CI job red at `e65b2a7`. The assertion was **not** relaxed to make it pass. It was
replaced with the thing it was trying to describe:

* `sh_upload_validate_image()` is extracted from the shipped file and **called** with a reported
  size of `SH_PRODUCT_MAX_IMAGE_BYTES + 1` and with a real 1×1 PNG at exactly the limit;
* the suite asserts the oversize file is rejected and that the write path delegates to the same
  validator (`$checked = sh_upload_validate_image($file, $maxBytes, $maxDim)`), so no future
  refactor can bypass the check silently.

Result: `Product management integration: 169 checks, 0 failures, 16 cases`.
