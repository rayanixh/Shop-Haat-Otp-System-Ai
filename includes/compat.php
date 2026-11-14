<?php
/**
 * Runtime compatibility shims for optional PHP extensions.
 *
 * ShopHaat runs on shared hosting where the enabled extension set differs from
 * host to host. Rather than disabling validation (or suppressing warnings)
 * when an extension is missing, this file provides a small, predictable
 * fallback for the handful of functions the application actually calls.
 *
 *  - mbstring  : Unicode-aware string helpers. The `sh_compat_mb_*` functions
 *                below work on real UTF-8 code points through PCRE (`/u`), so
 *                length limits, truncation and masking keep counting
 *                characters instead of bytes. When mbstring is not loaded the
 *                plain `mb_*` names are defined as thin wrappers around them.
 *                Invalid UTF-8 falls back to byte semantics — content is never
 *                dropped and no warning is emitted.
 *  - dom/libxml: handled in includes/functions.php (sh_sanitize_html_allowlist)
 *                with a secure allow-listed tokenizer instead of raw HTML.
 *  - iconv     : used when present, otherwise the byte behaviours above.
 *
 * Required (never shimmed, the installer blocks without them): PDO + pdo_mysql,
 * json. See sh_extension_requirements() for the full, single source of truth
 * used by the installer and Admin → System Health.
 *
 * Keeping the shims under their own names means the compatibility layer can be
 * unit-tested on a server where mbstring *is* loaded, without disabling an
 * extension or introducing test-only switches in production code.
 */

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }

// ---------------------------------------------------------------------------
// Extension inventory (required vs optional) — shared by the installer and
// Admin → System Health so both surfaces report the same thing.
// ---------------------------------------------------------------------------
if (!function_exists('sh_extension_requirements')) {
    /**
     * @return array<int,array{ext:string,label:string,required:bool,ok:bool,purpose:string,fallback:string}>
     */
    function sh_extension_requirements(): array
    {
        $rows = [
            ['ext' => 'pdo',       'label' => 'PDO',          'required' => true,  'purpose' => 'Database access',                  'fallback' => ''],
            ['ext' => 'pdo_mysql', 'label' => 'pdo_mysql',    'required' => true,  'purpose' => 'MySQL / MariaDB driver',            'fallback' => ''],
            ['ext' => 'json',      'label' => 'JSON',         'required' => true,  'purpose' => 'API + settings payloads',          'fallback' => ''],
            ['ext' => 'mbstring',  'label' => 'mbstring',     'required' => false, 'purpose' => 'Character-safe length/truncation', 'fallback' => 'UTF-8 shim in includes/compat.php'],
            ['ext' => 'dom',       'label' => 'DOM / libxml', 'required' => false, 'purpose' => 'HTML sanitising for AI drafts',    'fallback' => 'Allow-list tokenizer (no raw HTML)'],
            ['ext' => 'curl',      'label' => 'cURL',         'required' => false, 'purpose' => 'SMS + payment HTTP gateways',      'fallback' => 'Gateway reported as unavailable'],
            ['ext' => 'openssl',   'label' => 'OpenSSL',      'required' => false, 'purpose' => 'SMTP TLS + secure tokens',         'fallback' => 'Mail TLS reported as unavailable'],
            ['ext' => 'gd',        'label' => 'GD',           'required' => false, 'purpose' => 'Image resizing / cropping',        'fallback' => 'Original image kept, no thumbnail'],
            ['ext' => 'fileinfo',  'label' => 'fileinfo',     'required' => false, 'purpose' => 'Upload MIME inspection',           'fallback' => 'Extension/MIME allow-list only'],
            ['ext' => 'intl',      'label' => 'intl',         'required' => false, 'purpose' => 'Locale-aware formatting',          'fallback' => 'Plain number/date formatting'],
        ];
        foreach ($rows as &$row) {
            $row['ok'] = extension_loaded($row['ext']);
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('sh_missing_extensions')) {
    /** @return array<int,string> required extensions that are not loaded */
    function sh_missing_extensions(): array
    {
        $missing = [];
        foreach (sh_extension_requirements() as $row) {
            if ($row['required'] && !$row['ok']) { $missing[] = $row['ext']; }
        }
        return $missing;
    }
}

if (!function_exists('sh_extension_summary')) {
    /** @return array{required_missing:array<int,string>,optional_missing:array<int,string>} */
    function sh_extension_summary(): array
    {
        $missingReq = [];
        $missingOpt = [];
        foreach (sh_extension_requirements() as $row) {
            if ($row['ok']) { continue; }
            if ($row['required']) { $missingReq[] = $row['ext']; } else { $missingOpt[] = $row['ext']; }
        }
        return ['required_missing' => $missingReq, 'optional_missing' => $missingOpt];
    }
}

// ---------------------------------------------------------------------------
// mbstring shims (always defined under sh_compat_* so they are unit-testable).
// ---------------------------------------------------------------------------
if (!function_exists('sh_compat_utf8_chars')) {
    /** @return array<int,string>|null code points, or null when the input is not UTF-8 */
    function sh_compat_utf8_chars(string $s): ?array
    {
        if ($s === '') { return []; }
        $ok = preg_match_all('/./us', $s, $m);
        return $ok === false ? null : $m[0];
    }
}

if (!function_exists('sh_compat_mb_strlen')) {
    function sh_compat_mb_strlen(string $string): int
    {
        $chars = sh_compat_utf8_chars($string);
        return $chars === null ? strlen($string) : count($chars);
    }

    function sh_compat_mb_substr(string $string, int $start, ?int $length = null): string
    {
        $chars = sh_compat_utf8_chars($string);
        if ($chars === null) { return substr($string, $start, $length ?? PHP_INT_MAX); }
        $total = count($chars);
        if ($start < 0) { $start = max(0, $total + $start); }
        if ($start > $total) { return ''; }
        if ($length === null) { return implode('', array_slice($chars, $start)); }
        if ($length < 0) { return implode('', array_slice($chars, $start, max(0, $total + $length - $start))); }
        return implode('', array_slice($chars, $start, $length));
    }

    /**
     * ASCII case folding only. PHP ships no Unicode case table, and mangling
     * bytes would be worse than leaving non-ASCII characters untouched.
     * Every caller in this application folds machine identifiers (slugs,
     * header keys, keywords), so this is lossless where it matters.
     */
    function sh_compat_mb_strtolower(string $string): string { return strtolower($string); }
    function sh_compat_mb_strtoupper(string $string): string { return strtoupper($string); }

    function sh_compat_mb_strpos(string $haystack, string $needle, int $offset = 0)
    {
        $pos = strpos($haystack, $needle, $offset);
        if ($pos === false) { return false; }
        $chars = sh_compat_utf8_chars(substr($haystack, 0, $pos));
        return $chars === null ? $pos : count($chars);
    }

    function sh_compat_mb_strrpos(string $haystack, string $needle)
    {
        $pos = strrpos($haystack, $needle);
        if ($pos === false) { return false; }
        $chars = sh_compat_utf8_chars(substr($haystack, 0, $pos));
        return $chars === null ? $pos : count($chars);
    }

    function sh_compat_mb_strimwidth(string $string, int $start, int $width, string $trimMarker = ''): string
    {
        if (sh_compat_mb_strlen($string) <= $start) { return ''; }
        $slice = sh_compat_mb_substr($string, $start, $width);
        if (sh_compat_mb_strlen($slice) < $width) { return $slice; }
        $markerLen = sh_compat_mb_strlen($trimMarker);
        return sh_compat_mb_substr($string, $start, max(0, $width - $markerLen)) . $trimMarker;
    }

    function sh_compat_mb_check_encoding($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) { if (!sh_compat_mb_check_encoding($v)) { return false; } }
            return true;
        }
        return preg_match('//u', (string)$value) === 1;
    }

    /** Code point for a single UTF-8 character (used by the encoding shim). */
    function sh_compat_mb_ord(string $character): int
    {
        $bytes = array_values(unpack('C*', $character) ?: []);
        if ($bytes === []) { return 0; }
        $b0 = $bytes[0];
        if ($b0 < 0x80) { return $b0; }
        if (($b0 & 0xE0) === 0xC0 && count($bytes) >= 2) { return (($b0 & 0x1F) << 6) | ($bytes[1] & 0x3F); }
        if (($b0 & 0xF0) === 0xE0 && count($bytes) >= 3) {
            return (($b0 & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
        }
        if (($b0 & 0xF8) === 0xF0 && count($bytes) >= 4) {
            return (($b0 & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
        }
        return $b0;
    }

    function sh_compat_mb_convert_encoding($string, string $toEncoding, $fromEncoding = null): string
    {
        if (is_array($string)) {
            return array_map(static fn($v) => sh_compat_mb_convert_encoding($v, $toEncoding, $fromEncoding), $string);
        }
        $string = (string)$string;
        $from = strtoupper((string)(is_array($fromEncoding) ? implode(',', $fromEncoding) : $fromEncoding));
        $to = strtoupper($toEncoding);
        if ($string === '' || $to === $from) { return $string; }
        if ($to === 'UTF-8' && ($from === 'ISO-8859-1' || $from === 'LATIN1')) {
            return preg_replace_callback(
                '/[\x80-\xFF]/',
                static fn($m) => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)),
                $string
            ) ?? $string;
        }
        if ($to === 'ISO-8859-1' && $from === 'UTF-8') {
            return preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', static function ($m) {
                $cp = sh_compat_mb_ord($m[0]);
                return $cp <= 0xFF ? chr($cp) : '?';
            }, $string) ?? $string;
        }
        // Unknown pair: return the bytes unchanged rather than mangling them.
        return $string;
    }

    function sh_compat_mb_decode_mimeheader(string $string): string
    {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($string, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            return is_string($decoded) ? $decoded : $string;
        }
        return $string;
    }
}

// ---------------------------------------------------------------------------
// Bind the plain mb_* names only when the extension is genuinely missing.
// ---------------------------------------------------------------------------
/**
 * Response headers from the last stream-wrapper HTTP request.
 *
 * `$http_response_header` is deprecated in PHP 8.4, and PHP raises the notice
 * while *compiling* a file that mentions it — so a single reference turns every
 * request into a log line. http_get_last_response_headers() is the supported
 * replacement (8.4+); below that the magic variable is still the only source, so
 * it is read through $GLOBALS, which does not trigger the notice.
 *
 * @return list<string>
 */
function sh_http_last_response_headers(): array
{
    if (function_exists('http_get_last_response_headers')) {
        $headers = http_get_last_response_headers();
        return is_array($headers) ? array_values(array_map('strval', $headers)) : [];
    }
    $legacy = $GLOBALS['http_response_header'] ?? [];
    return is_array($legacy) ? array_values(array_map('strval', $legacy)) : [];
}

/**
 * Status code from the last stream-wrapper HTTP request (0 when unknown).
 */
function sh_http_last_status(): int
{
    foreach (sh_http_last_response_headers() as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) { return (int)$m[1]; }
    }
    return 0;
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int { return sh_compat_mb_strlen($string); }
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return sh_compat_mb_substr($string, $start, $length);
    }
    function mb_strtolower(string $string, ?string $encoding = null): string { return sh_compat_mb_strtolower($string); }
    function mb_strtoupper(string $string, ?string $encoding = null): string { return sh_compat_mb_strtoupper($string); }
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null)
    {
        return sh_compat_mb_strpos($haystack, $needle, $offset);
    }
    function mb_strrpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null)
    {
        return sh_compat_mb_strrpos($haystack, $needle);
    }
    function mb_strimwidth(string $string, int $start, int $width, string $trimMarker = '', ?string $encoding = null): string
    {
        return sh_compat_mb_strimwidth($string, $start, $width, $trimMarker);
    }
    function mb_check_encoding($value, ?string $encoding = null): bool { return sh_compat_mb_check_encoding($value); }
    function mb_internal_encoding(?string $encoding = null)
    {
        static $current = 'UTF-8';
        $prev = $current;
        if ($encoding !== null && $encoding !== '') { $current = $encoding; }
        return $prev;
    }
    function mb_convert_encoding($string, string $toEncoding, $fromEncoding = null): string
    {
        return sh_compat_mb_convert_encoding($string, $toEncoding, $fromEncoding);
    }
    function mb_decode_mimeheader(string $string): string { return sh_compat_mb_decode_mimeheader($string); }
}
