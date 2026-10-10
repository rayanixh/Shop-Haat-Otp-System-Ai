<?php
/**
 * Shared helpers: settings, escaping, money, slugs, icons, uploads, pagination.
 */

function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function sh_json($data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Whether this request expects an API response instead of an HTML redirect.
 *
 * Authentication guards are used by both browser pages and JSON endpoints.  This
 * helper deliberately does not treat a browser's common wildcard Accept header as JSON,
 * otherwise an expired browser session would receive a raw 401 instead of the
 * sign-in page.
 */
function sh_wants_json(): bool
{
    if (defined('SH_JSON_CONTEXT') && SH_JSON_CONTEXT) { return true; }

    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (preg_match('~(?:^|/)api(?:/|$)~i', $path) === 1) { return true; }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json')
        || str_contains($accept, 'application/problem+json');
}

function sh_redirect(string $path): void
{
    if (!headers_sent()) {
        header('Location: ' . (preg_match('~^https?://~', $path) ? $path : sh_url($path)));
    }
    exit;
}

// ---------------------------------------------------------------------------
// Settings (cached per request)
// ---------------------------------------------------------------------------
function sh_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }
    $cache = [];
    try {
        foreach (sh_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'settings');
    }
    return $cache;
}

function sh_setting(string $key, $default = ''){
    $s = sh_settings();
    return array_key_exists($key, $s) && $s[$key] !== null ? $s[$key] : $default;
}

/**
 * Versioned asset URL. Uses the file's modification time so browsers pick up
 * CSS/JS changes immediately after an upload, without a manual version bump.
 */
function sh_asset(string $relPath): string
{
    $full = SH_ROOT . '/' . ltrim($relPath, '/');
    $ver = is_file($full) ? (string)filemtime($full) : '1';
    return sh_url($relPath) . '?v=' . $ver;
}

/**
 * Resolve a promo image reference to a public URL.
 *
 * Values prefixed "promo/" are the bundled defaults under assets/images/promo/.
 * Anything else is an admin upload living in uploads/promo/.
 * Returns '' when the file is missing so callers can fall back cleanly.
 */
/**
 * Public URL for a category image uploaded from the admin panel.
 *
 * Only the bare filename is stored in the database; the path is rebuilt here so
 * a stored value can never be used for path traversal. Returns '' when the file
 * is missing so the caller can fall back to the placeholder.
 */
function sh_category_image(?string $file): string
{
    $name = basename(trim((string)$file));
    if ($name === '' || $name === '.' || $name === '..') { return ''; }
    return is_file(SH_UPLOAD_DIR . '/categories/' . $name)
        ? sh_url('uploads/categories/' . rawurlencode($name))
        : '';
}

function sh_promo_image(?string $file): string
{
    $file = trim((string)$file);
    if ($file === '') { return ''; }

    if (str_starts_with($file, 'promo/')) {
        $rel = 'assets/images/' . $file;
        return is_file(SH_ROOT . '/' . $rel) ? sh_url($rel) : '';
    }
    $name = basename($file);
    return is_file(SH_UPLOAD_DIR . '/promo/' . $name)
        ? sh_url('uploads/promo/' . rawurlencode($name))
        : '';
}

/**
 * Homepage promo content, loaded from the database.
 * $placement is 'slider' or 'card'. Only active rows are returned, in admin order.
 */
function sh_promo_items(string $placement): array
{
    static $cache = [];
    if (isset($cache[$placement])) { return $cache[$placement]; }
    try {
        $rows = sh_all(
            'SELECT * FROM promo_slides WHERE placement = ? AND status = 1 ORDER BY sort_order ASC, id ASC',
            [$placement]
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'promo-items');
        $rows = [];
    }
    return $cache[$placement] = $rows;
}

function sh_setting_save(string $key, string $value): void
{
    // Audit trail for admin-made settings changes (secrets are masked, never stored).
    static $skip = ['admin_tools_schema_v', 'admin_roles_schema_v', 'health_cache', 'backup_last_auto', 'backup_cron_token', 'otp_schema_v', 'fb_schema_v', 'verify_schema_v'];
    $old = (string)sh_setting($key, '');
    $audit = $old !== $value && !in_array($key, $skip, true) && !str_ends_with($key, '_schema_v')
        && function_exists('sh_admin') && PHP_SAPI !== 'cli' && sh_admin() !== null;
    sh_query(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
        [$key, $value]
    );
    sh_settings(true);
    if ($audit && function_exists('sh_audit')) {
        $secret = (bool)preg_match('~(secret|token|password|api_key|apikey|_key$|private|salt)~i', $key) || str_starts_with($value, 'enc:') || str_starts_with($old, 'enc:');
        $mask = static fn(string $v): string => $v === '' ? '(empty)' : '•••• (hidden)';
        sh_audit('settings_changed', 'settings', null, $key, $secret ? $mask($old) : mb_substr($old, 0, 300), $secret ? $mask($value) : mb_substr($value, 0, 300));
    }
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------
function sh_currency(): string
{
    return (string)sh_setting('currency_symbol', 'BDT ');
}

function sh_money($amount): string
{
    $n = number_format((float)$amount, 2, '.', ',');
    // Trim a trailing .00 for compact marketplace-style pricing.
    if (substr($n, -3) === '.00') { $n = substr($n, 0, -3); }
    return sh_currency() . $n;
}

function sh_slug(string $text): string
{
    $text = preg_replace('~[^\p{L}\p{Nd}]+~u', '-', $text) ?? $text;
    $text = trim($text, '-');
    // iconv warns on untranslatable bytes; we fall back to the original string.
    set_error_handler(static fn(): bool => true);
    try {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    } finally {
        restore_error_handler();
    }
    if ($ascii !== false && trim($ascii, '-?') !== '') { $text = $ascii; }
    $text = strtolower(preg_replace('~[^-\w]+~', '', $text) ?? $text);
    $text = trim(preg_replace('~-+~', '-', $text) ?? $text, '-');
    return $text === '' ? 'item-' . substr(bin2hex(random_bytes(4)), 0, 6) : $text;
}

function sh_unique_slug(string $table, string $base, int $ignoreId = 0): string
{
    $slug = sh_slug($base);
    $try = $slug;
    $i = 2;
    while (true) {
        $row = sh_one("SELECT id FROM `$table` WHERE slug = ? AND id <> ? LIMIT 1", [$try, $ignoreId]);
        if ($row === null) { return $try; }
        $try = $slug . '-' . $i++;
        if ($i > 200) { return $slug . '-' . bin2hex(random_bytes(3)); }
    }
}

function sh_excerpt(?string $text, int $len = 120): string
{
    $t = trim(strip_tags((string)$text));
    return mb_strlen($t) <= $len ? $t : mb_substr($t, 0, $len - 1) . '…';
}

function sh_time_ago($datetime): string
{
    $ts = strtotime((string)$datetime);
    if (!$ts) { return ''; }
    $d = time() - $ts;
    if ($d < 60) { return 'just now'; }
    if ($d < 3600) { return floor($d / 60) . ' min ago'; }
    if ($d < 86400) { return floor($d / 3600) . ' hr ago'; }
    if ($d < 2592000) { return floor($d / 86400) . ' d ago'; }
    return date('d M Y', $ts);
}

function sh_discount_percent($price, $compare): int
{
    $price = (float)$price; $compare = (float)$compare;
    if ($compare <= 0 || $compare <= $price) { return 0; }
    return (int)round((($compare - $price) / $compare) * 100);
}

// ---------------------------------------------------------------------------
// Images
// ---------------------------------------------------------------------------
function sh_product_image(?string $file, string $size = 'md'): string
{
    if ($file && is_file(SH_UPLOAD_DIR . '/products/' . basename($file))) {
        return sh_url('uploads/products/' . rawurlencode(basename($file)));
    }
    return sh_url('assets/images/placeholder.svg');
}

/**
 * Return the configured, externally reachable HTTPS storefront URL.
 *
 * Product photos sent to third-party providers must never be built from a
 * localhost/private host header.  The installer stores site_url, which is the
 * canonical source; an HTTPS request URL is only a backwards-compatible
 * fallback for installs whose setting predates that field.
 */
function sh_public_site_url(): string
{
    $configured = trim((string)sh_setting('site_url', ''));
    $candidates = $configured !== '' ? [$configured] : [sh_site_url()];

    foreach ($candidates as $candidate) {
        $candidate = rtrim(trim((string)$candidate), '/');
        if ($candidate === '' || !sh_is_public_https_url($candidate)) { continue; }
        return $candidate;
    }
    return '';
}

/** True only for an absolute public HTTPS origin/path, never localhost/private IPs. */
function sh_is_public_https_url(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) { return false; }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') { return false; }
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) { return false; }
    $host = strtolower(trim((string)($parts['host'] ?? '')));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) { return false; }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) === 1;
}

function sh_logo_image(?string $file): string
{
    if ($file && is_file(SH_UPLOAD_DIR . '/logos/' . basename($file))) {
        return sh_url('uploads/logos/' . rawurlencode(basename($file)));
    }
    return '';
}

/**
 * Secure image upload: checks extension, real MIME, size and dimensions.
 * @return array{ok:bool,file?:string,error?:string}
 */
/** Server upload ceiling in bytes: the smaller of upload_max_filesize and post_max_size. */
function sh_server_upload_limit(): int
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '-1') { return PHP_INT_MAX; }
        $unit = strtolower(substr($v, -1));
        $n = (int)$v;
        return match ($unit) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    };
    $u = $toBytes((string)ini_get('upload_max_filesize'));
    $p = $toBytes((string)ini_get('post_max_size'));
    return max(1, min($u, $p));
}

/** Human-readable size, e.g. 2 MB. */
function sh_bytes_label(int $bytes): string
{
    if ($bytes >= 1048576) { return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'; }
    if ($bytes >= 1024) { return round($bytes / 1024) . ' KB'; }
    return $bytes . ' bytes';
}

/**
 * Secure image upload: checks extension, real MIME, size and dimensions.
 *
 * $maxBytes 0 means "no application limit" — only the server's own PHP ceiling applies.
 *
 * @return array{ok:bool,file?:string,error?:string}
 */
/**
 * Verify that an uploaded file is actually reachable over HTTP.
 *
 * A common shared-hosting failure is an .htaccess directive (typically an
 * unguarded php_flag) that makes Apache return 500 for the whole /uploads
 * folder. The file exists and the database is right, but every image appears
 * broken. This fetches one real image so the admin can see the true cause.
 *
 * @return array{ok:bool,status:int,error?:string,url?:string}
 */
function sh_uploads_reachable(string $subdir = 'products'): array
{
    $dir = SH_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) { return ['ok' => false, 'status' => 0, 'error' => 'Folder /uploads/' . $subdir . ' does not exist.']; }

    $sample = null;
    foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) { $sample = basename($f); break; }
    }
    if ($sample === null) { return ['ok' => true, 'status' => 0, 'error' => 'No image uploaded yet — nothing to test.']; }

    $url = rtrim(sh_site_url(), '/') . '/uploads/' . $subdir . '/' . rawurlencode($sample);
    $status = 0; $body = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents($url, false, stream_context_create(
                ['http' => ['timeout' => 10, 'ignore_errors' => true]]));
        } finally { restore_error_handler(); }
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }

    if ($status === 200 && $body !== false && $body !== '') {
        return ['ok' => true, 'status' => 200, 'url' => $url];
    }
    if ($status === 500) {
        return ['ok' => false, 'status' => 500, 'url' => $url,
            'error' => 'Apache returns HTTP 500 for /uploads/. This is almost always a bad directive in '
                     . 'uploads/.htaccess — on FastCGI/PHP-FPM hosting "php_flag" must be wrapped in '
                     . '<IfModule mod_php.c>. Re-upload the fixed uploads/.htaccess from this project.'];
    }
    if ($status === 403) {
        return ['ok' => false, 'status' => 403, 'url' => $url,
            'error' => 'Apache denies access to /uploads/. Check folder permissions (755) and any '
                     . 'security rules your host adds.'];
    }
    return ['ok' => false, 'status' => $status, 'url' => $url,
        'error' => 'The uploads folder is not reachable over HTTP (status ' . $status . ').'];
}

function sh_upload_image(array $file, string $subdir, int $maxBytes = 0, int $maxDim = 6000): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        // Report the ACTUAL reason instead of a vague catch-all.
        $limit = sh_bytes_label(sh_server_upload_limit());
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The image is larger than this server allows (' . $limit . '). '
                . 'Either choose a smaller image, or raise upload_max_filesize and post_max_size '
                . 'in your hosting control panel (cPanel: Select PHP Version, then Options).',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted before it finished. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload folder configured. Contact your host.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file to disk. Check folder permissions.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked this upload.',
            default               => 'The upload failed (error code ' . (int)$file['error'] . ').',
        };
        return ['ok' => false, 'error' => $msg];
    }
    if ($maxBytes > 0 && $file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'File is larger than ' . sh_bytes_label($maxBytes) . '.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Invalid upload source.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    // A non-image upload makes getimagesize() warn; that is a validation result, not an error.
    set_error_handler(static fn(): bool => true);
    try {
        $info = getimagesize($file['tmp_name']);
    } finally {
        restore_error_handler();
    }
    if ($info === false) {
        // Common on modern phones: HEIC/HEIF photos are not readable by PHP.
        $name = strtolower((string)($file['name'] ?? ''));
        if (preg_match('/\.(heic|heif|avif)$/', $name)) {
            return ['ok' => false, 'error' => 'This looks like a HEIC/HEIF photo, which browsers cannot display. '
                . 'In your phone camera settings choose "Most Compatible" / JPEG, or convert the photo to JPG and try again.'];
        }
        return ['ok' => false, 'error' => 'That file is not a readable image. Please upload a JPG, PNG, WebP or GIF.'];
    }
    $mime = $info['mime'] ?? '';
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Unsupported image type (' . ($mime ?: 'unknown')
            . '). Please upload a JPG, PNG, WebP or GIF.'];
    }
    if ($info[0] < 16 || $info[1] < 16) {
        return ['ok' => false, 'error' => 'Image is too small (minimum 16x16 pixels).'];
    }
    if ($info[0] > $maxDim || $info[1] > $maxDim) {
        return ['ok' => false, 'error' => "Image dimensions must not exceed {$maxDim}px."];
    }
    // The extension is NOT trusted or required: getimagesize() above already
    // verified the real image type, and the saved filename is derived from that.
    // Rejecting on extension used to block valid uploads with no extension or
    // an unexpected one (e.g. photos straight off a phone).

    $dir = SH_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        sh_log_line('upload', 'Cannot create upload directory: ' . $dir);
        return ['ok' => false, 'error' => 'The folder /uploads/' . $subdir
            . ' could not be created. Set /uploads to permission 755 (or 775) in your hosting file manager.'];
    }
    if (!is_writable($dir)) {
        sh_log_line('upload', 'Upload directory not writable: ' . $dir);
        return ['ok' => false, 'error' => 'The folder /uploads/' . $subdir
            . ' is not writable. Set it to permission 755 (or 775) in your hosting file manager.'];
    }
    // Safe, non-executable, unpredictable filename.
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded file.'];
    }
    if (!chmod($dir . '/' . $name, 0644)) {
        sh_log_line('upload', 'Could not set permissions on ' . $name);
    }
    return ['ok' => true, 'file' => $name];
}

function sh_delete_upload(?string $file, string $subdir): void
{
    if (!$file) { return; }
    $path = SH_UPLOAD_DIR . '/' . $subdir . '/' . basename($file);
    if (is_file($path) && !unlink($path)) {
        sh_log_line('upload', 'Could not delete file ' . $path);
    }
}

// ---------------------------------------------------------------------------
// Lucide-style SVG icon system (no emojis anywhere in the UI)
// ---------------------------------------------------------------------------
function sh_icon(string $name, int $size = 20, string $class = ''): string
{
    static $paths = null;
    if ($paths === null) {
        $paths = [
            'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
            'user'          => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'user-plus'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
            'smartphone'    => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
            'package'       => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
            'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'settings'      => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            'credit-card'   => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
            'clock'         => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'check-circle'  => '<path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/>',
            'x-circle'      => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
            'bell'          => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'sliders'       => '<line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/>',
            'trash'         => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'pencil'        => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
            'heart'         => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
            'menu'          => '<line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
            'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
            'chevron-up'    => '<path d="m18 15-6-6-6 6"/>',
            'cpu'           => '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2"/><path d="M15 20v2"/><path d="M2 15h2"/><path d="M2 9h2"/><path d="M20 15h2"/><path d="M20 9h2"/><path d="M9 2v2"/><path d="M9 20v2"/>',
            'home'          => '<path d="M3 9.5 12 3l9 6.5V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
            'grid'          => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
            'zap'           => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'star'          => '<path d="M11.5 2.5a.6.6 0 0 1 1 0l2.4 5 5.4.8a.6.6 0 0 1 .3 1l-3.9 3.8.9 5.4a.6.6 0 0 1-.85.63L12 16.6l-4.8 2.5a.6.6 0 0 1-.86-.63l.92-5.4-3.9-3.8a.6.6 0 0 1 .32-1l5.4-.8z"/>',
            'truck'         => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
            'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
            'rotate'        => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
            'headphones'    => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
            'plus'          => '<path d="M5 12h14"/><path d="M12 5v14"/>',
            'minus'         => '<path d="M5 12h14"/>',
            'copy'          => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
            'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'log-in'        => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
            'layout'        => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
            'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'key'           => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
            'send'          => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
            'message'       => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
            'mail'          => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'trending-up'   => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
            'dollar'        => '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'alert'         => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
            'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
            'phone'         => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
            'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
            'external'      => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
            'eye'           => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'box'           => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
            'list'          => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
            'filter'        => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'help'          => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
            'refresh'       => '<path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/>',
            'lock'          => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'file-text'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'database'      => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
            'bar-chart'     => '<line x1="12" x2="12" y1="20" y2="10"/><line x1="18" x2="18" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="16"/>',
            'history'       => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'image'         => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        ];
    }
    $d = $paths[$name] ?? $paths['info'];
    $cls = 'sh-icon' . ($class !== '' ? ' ' . $class : '');
    return '<svg class="' . e($cls) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Star rating markup (SVG only). */
function sh_stars(float $rating, int $count = 0): string
{
    $full = (int)floor($rating + 0.001);
    $html = '<span class="sh-rating" aria-label="Rated ' . number_format($rating, 1) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $on = $i <= $full ? ' sh-rating__star--on' : '';
        $html .= '<svg class="sh-rating__star' . $on . '" width="13" height="13" viewBox="0 0 24 24" aria-hidden="true">'
            . '<path d="M11.5 2.5a.6.6 0 0 1 1 0l2.4 5 5.4.8a.6.6 0 0 1 .3 1l-3.9 3.8.9 5.4a.6.6 0 0 1-.85.63L12 16.6l-4.8 2.5a.6.6 0 0 1-.86-.63l.92-5.4-3.9-3.8a.6.6 0 0 1 .32-1l5.4-.8z" '
            . 'fill="' . ($i <= $full ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
    }
    if ($count > 0) { $html .= '<span class="sh-rating__count">(' . $count . ')</span>'; }
    $html .= '</span>';
    return $html;
}

// ---------------------------------------------------------------------------
// Flash messages
// ---------------------------------------------------------------------------
function sh_flash(string $type, string $message): void
{
    sh_session_start();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function sh_flash_pull(): array
{
    sh_session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------
function sh_paginate(int $total, int $perPage, int $current, string $baseUrl): string
{
    $pages = (int)max(1, ceil($total / max(1, $perPage)));
    if ($pages < 2) { return ''; }
    $current = max(1, min($pages, $current));
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($baseUrl, $sep) {
        if ($disabled) { return '<span class="sh-pager__item sh-pager__item--off">' . $label . '</span>'; }
        $cls = 'sh-pager__item' . ($active ? ' sh-pager__item--active' : '');
        return '<a class="' . $cls . '" href="' . e($baseUrl . $sep . 'page=' . $p) . '">' . $label . '</a>';
    };
    $out = '<nav class="sh-pager" aria-label="Pagination">';
    $out .= $link($current - 1, sh_icon('chevron-left', 16), false, $current <= 1);
    $start = max(1, $current - 2);
    $end = min($pages, $start + 4);
    $start = max(1, $end - 4);
    if ($start > 1) { $out .= $link(1, '1') . ($start > 2 ? '<span class="sh-pager__gap">…</span>' : ''); }
    for ($p = $start; $p <= $end; $p++) { $out .= $link($p, (string)$p, $p === $current); }
    if ($end < $pages) { $out .= ($end < $pages - 1 ? '<span class="sh-pager__gap">…</span>' : '') . $link($pages, (string)$pages); }
    $out .= $link($current + 1, sh_icon('chevron-right', 16), false, $current >= $pages);
    return $out . '</nav>';
}

function sh_order_number(int $id): string
{
    return sh_order_number_prefix() . date('ymd') . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

/**
 * Admin-configurable 2-letter order number prefix (Settings → Order Settings).
 * Falls back to "SH" for installs that predate the setting, or if a somehow
 * invalid value is stored, so order numbers can never break or collide with
 * a changed format.
 */
function sh_order_number_prefix(): string
{
    $p = strtoupper(trim((string)sh_setting('order_number_prefix', 'SH')));
    return preg_match('/^[A-Z]{2}$/', $p) ? $p : 'SH';
}

function sh_status_label(string $status): string
{
    $map = [
        'pending'           => 'Order Placed',
        'awaiting_payment'  => 'Awaiting Payment',
        'payment_submitted' => 'Payment Submitted',
        'payment_verified'  => 'Payment Verified',
        'payment_rejected'  => 'Payment Rejected',
        'processing'        => 'Processing',
        'shipped'           => 'Shipped',
        'delivered'         => 'Delivered',
        'returned'          => 'Returned',
        'completed'         => 'Completed',
        'cancelled'         => 'Cancelled',
        'verified'          => 'Verified',
        'rejected'          => 'Rejected',
        'failed'            => 'Failed',
        'sent'              => 'Sent',
        'skipped'           => 'Skipped',
    ];
    return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
}

/**
 * Package / variant text stored with each order line: "Category · SKU". The
 * products table has no variants, so the category (e.g. "UID Top Up") and SKU
 * are the descriptive facts worth freezing at order time.
 */
function sh_order_item_variant(string $category, string $sku): string
{
    $parts = array_values(array_filter([trim($category), trim($sku) !== '' ? 'SKU ' . trim($sku) : ''], static fn($v) => $v !== ''));
    return implode(' · ', $parts);
}

function sh_status_class(string $status): string
{
    $ok = ['completed', 'delivered', 'payment_verified', 'verified', 'sent'];
    $warn = ['pending', 'awaiting_payment', 'payment_submitted', 'processing', 'shipped', 'skipped'];
    $bad = ['payment_rejected', 'cancelled', 'returned', 'rejected', 'failed'];
    if (in_array($status, $ok, true)) { return 'sh-badge--ok'; }
    if (in_array($status, $warn, true)) { return 'sh-badge--warn'; }
    if (in_array($status, $bad, true)) { return 'sh-badge--bad'; }
    return 'sh-badge--muted';
}

// ---------------------------------------------------------------------------
// Phone number helpers (Bangladesh-aware canonicalisation)
// ---------------------------------------------------------------------------
/**
 * Normalise a phone number to a canonical, comparable form.
 * Bangladeshi numbers (01XXXXXXXXX / 8801XXXXXXXXX / +8801XXXXXXXXX) all become
 * "8801XXXXXXXXX". Generic international numbers are kept as digits.
 * Returns '' when the number cannot be normalised.
 */
function sh_phone_normalize(?string $phone): string
{
    $p = preg_replace('/[\s\-().]/', '', (string)$phone);
    if ($p === null || $p === '') { return ''; }
    if (preg_match('/^(?:\+?880)?(1[3-9]\d{8})$/', $p, $m)) {
        return '880' . $m[1];
    }
    $p = ltrim($p, '+');
    return preg_match('/^\d{8,15}$/', $p) ? $p : '';
}

/** Canonical "8801XXXXXXXXX" back to the familiar "01XXXXXXXXX" display form. */
function sh_phone_display(?string $phone): string
{
    $p = (string)$phone;
    if (preg_match('/^880(1[3-9]\d{8})$/', $p, $m)) {
        return '0' . $m[1];
    }
    return $p;
}

/** Mask a phone for display in logs and admin views, e.g. 017****5678. */
function sh_phone_mask(?string $phone): string
{
    $p = sh_phone_display((string)$phone);
    if (preg_match('/^(\d{3})(\d{4})(\d+)$/', $p, $m)) {
        return $m[1] . '****' . $m[3];
    }
    if (mb_strlen($p) > 6) {
        return mb_substr($p, 0, 3) . '****' . mb_substr($p, -4);
    }
    return $p;
}

/**
 * Aggressive mask for the OTP modal: keeps only the leading "01" and stars the
 * rest (e.g. 01**********). Used so the number being verified is never fully
 * exposed on screen.
 */
function sh_phone_mask_login(?string $phone): string
{
    $p = sh_phone_display((string)$phone);
    if (mb_strlen($p) < 3) { return $p; }
    return mb_substr($p, 0, 2) . str_repeat('*', max(0, mb_strlen($p) - 2));
}

/** All plausible stored formats of a phone, for duplicate lookups. */
function sh_phone_variants(?string $phone): array
{
    $canon = sh_phone_normalize($phone);
    if ($canon === '') { return []; }
    $display = sh_phone_display($canon);
    $local = substr($canon, 3);
    return array_values(array_unique([$canon, $display, '0' . $local, '+' . $canon]));
}

/** Best-effort client IP, aware of common shared-hosting reverse proxies. */
function sh_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v === '') { continue; }
        $first = trim(explode(',', $v)[0]);
        if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) { return $first; }
    }
    return '0.0.0.0';
}

/** Does a table exist in the current database? (guards lazy migrations) */
function sh_table_exists(string $table): bool
{
    static $known = [];
    if (array_key_exists($table, $known)) { return $known[$table]; }
    try {
        $n = (int)sh_val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table], 0
        );
    } catch (Throwable $e) {
        $n = 0;
    }
    return $known[$table] = $n > 0;
}

/**
 * Check an existing schema column without interpolating user-controlled names.
 * Used for optional backwards-compatible fields such as a customer username.
 */
function sh_table_has_column(string $table, string $column): bool
{
    static $known = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $known)) { return $known[$key]; }
    try {
        $n = (int)sh_val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column], 0
        );
    } catch (Throwable $e) {
        $n = 0;
    }
    return $known[$key] = $n > 0;
}

/** Fetch a single column from the users table by id. */
function sh_user_field(int $userId, string $field): ?string
{
    $allow = ['name', 'email', 'phone'];
    if (!in_array($field, $allow, true)) { return null; }
    return (string)sh_val("SELECT `$field` FROM users WHERE id = ? LIMIT 1", [$userId], '');
}

/**
 * Server-side open-redirect guard. Accepts only a safe internal path (relative,
 * no scheme, no traversal, safe characters). Returns $fallback otherwise.
 *
 * REQUEST_URI includes the installation subfolder on shared hosting.  Strip that
 * prefix before returning a path to sh_redirect(), which adds the base URL back;
 * without this a login return target could become /shop/shop/account.php.
 */
function sh_safe_redirect(string $raw, string $fallback = 'account.php'): string
{
    $raw = trim((string)$raw);
    if ($raw === '') { return $fallback; }
    if (preg_match('/[\x00-\x1f\x7f]/', $raw) === 1) { return $fallback; }
    if (preg_match('~^(?:https?:)?//~i', $raw)) { return $fallback; }
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $raw)) { return $fallback; }
    if (str_contains($raw, '\\') || str_contains($raw, "\0") || str_contains($raw, '#')) { return $fallback; }

    // Reject encoded protocol-relative paths, traversal and backslashes too.
    $decoded = $raw;
    for ($i = 0; $i < 2; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) { break; }
        $decoded = $next;
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1
        || preg_match('~^(?:https?:)?//~i', $decoded) === 1
        || preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $decoded) === 1
        || str_contains($decoded, '\\')
        || str_contains($decoded, '#')) {
        return $fallback;
    }

    $path = ltrim($raw, '/');
    if ($path === '') { return $fallback; }
    if (preg_match('~^[a-zA-Z0-9_./?=&%+\-]+$~', $path) !== 1) { return $fallback; }

    $decodedPath = ltrim($decoded, '/');
    $pathOnly = explode('?', $decodedPath, 2)[0];
    foreach (explode('/', $pathOnly) as $seg) {
        if ($seg === '..' || $seg === '.') { return $fallback; }
    }

    $base = trim(sh_base_url(), '/');
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = ltrim(substr($path, strlen($base)), '/');
    }
    return $path !== '' ? $path : $fallback;
}


/**
 * Synthetic email for phone-only accounts. The users.email column is NOT NULL,
 * so phone-only signups store a derived, clearly-fake address. These accounts
 * authenticate by phone + OTP only — the synthetic email is never used for
 * sign-in or password recovery.
 */
function sh_synthetic_email(string $phone): string
{
    return 'phone+' . $phone . '@user.shophaat.local';
}

function sh_is_synthetic_email(string $email): bool
{
    return (bool)preg_match('/^phone\+[0-9]+@user\.shophaat\.local$/', $email);
}

/**
 * The single customer authentication mode. Exactly one mode is active at any
 * time — `email_password` or `phone_otp` — controlled by the admin from
 * Settings → Authentication.
 */
function sh_auth_mode(): string
{
    return sh_setting('authentication_mode', 'email_password') === 'phone_otp'
        ? 'phone_otp'
        : 'email_password';
}
