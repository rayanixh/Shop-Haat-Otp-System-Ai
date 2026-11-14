<?php
/**
 * Phone number + OTP authentication engine (signup & login ONLY).
 *
 * Design goals (shared hosting / plain PHP / MySQL):
 *  - Phone is the primary, canonical customer identifier. 017XXXXXXXX,
 *    +88017XXXXXXXX and 88017XXXXXXXX all normalise to one form.
 *  - OTPs are generated server-side, stored only as password hashes, never
 *    logged, and never returned to the client on success. They are single-use,
 *    expire after a few minutes, and a resend issues a NEW code and invalidates
 *    the previous one.
 *  - Provider agnostic: `offline` (test only), `textbee`, `firebase` and a
 *    fully configurable `generic_http` driver. Credentials stay server-side and
 *    are never written to logs.
 *  - Rate limiting (per phone + per IP + daily cap), resend cooldown and
 *    attempt limits protect against brute-force and abuse.
 *  - Purposes are `signup` and `login` (plus an admin `test`). There is
 *    deliberately NO order / checkout / COD / online-payment OTP.
 *  - When the master switch (otp_enabled) is OFF, signup/login still work using
 *    the safest available flow (immediate, unverified account creation and
 *    direct sign-in), so authentication never breaks.
 */

require_once SH_ROOT . '/includes/functions.php';
require_once SH_ROOT . '/includes/errors.php';

/** Allowed OTP purposes. Keep in sync with UI copy and admin filters. */
function sh_otp_purposes(): array
{
    return ['signup', 'login', 'test'];
}

function sh_otp_enabled(): bool
{
    return sh_auth_mode() === 'phone_otp';
}

/** Whether signup must complete phone OTP before the account is created. */
function sh_otp_require_signup(): bool
{
    return sh_otp_enabled();
}

/** Whether login must complete phone OTP before a session is established. */
function sh_otp_require_login(): bool
{
    return sh_otp_enabled();
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
function sh_otp_length(): int
{
    $n = (int)sh_setting('otp_length', '6');
    return ($n >= 4 && $n <= 10) ? $n : 6;
}

function sh_otp_expiry_seconds(): int
{
    $m = max(1, (int)sh_setting('otp_expiry_minutes', '5'));
    return $m * 60;
}

function sh_otp_max_attempts(): int
{
    return max(1, (int)sh_setting('otp_max_attempts', '5'));
}

function sh_otp_max_resends(): int
{
    return max(1, (int)sh_setting('otp_max_resends', '3'));
}

function sh_otp_resend_cooldown(): int
{
    return max(0, (int)sh_setting('otp_resend_cooldown', '60'));
}

function sh_otp_daily_limit(): int
{
    return max(1, (int)sh_setting('otp_daily_limit', '20'));
}

function sh_otp_phone_rate_limit(): int
{
    return max(1, (int)sh_setting('otp_phone_rate_limit', '5'));
}

function sh_otp_phone_rate_window(): int
{
    return max(60, (int)sh_setting('otp_phone_rate_window', '60'));
}

function sh_otp_ip_rate_limit(): int
{
    return max(1, (int)sh_setting('otp_ip_rate_limit', '10'));
}

function sh_otp_ip_rate_window(): int
{
    return max(60, (int)sh_setting('otp_ip_rate_window', '60'));
}

/** Customer session lifetime in seconds — 24 hours maximum. */
function sh_session_ttl(): int
{
    return 86400;
}

function sh_otp_provider(): string
{
    return (string)sh_setting('otp_provider', 'offline');
}

function sh_otp_message(string $code, int $minutes): string
{
    $tpl = (string)sh_setting('otp_message', 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.');
    return str_replace(['{code}', '{minutes}'], [$code, (string)$minutes], $tpl);
}

// ---------------------------------------------------------------------------
// Security logging (OTP values and API secrets are NEVER written here)
// ---------------------------------------------------------------------------
function sh_security_log(string $event, ?int $userId = null, array $meta = []): void
{
    try {
        if (!sh_table_exists('security_logs')) { return; }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = sh_client_ip();
        $metaJson = empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        sh_db()->prepare(
            'INSERT INTO security_logs (user_id, event, ip_address, user_agent, metadata)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $event, mb_substr($ip, 0, 45), mb_substr($ua, 0, 255), $metaJson]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'security-log');
    }
}

function sh_otp_log(string $phone, string $purpose, string $status, array $meta = []): void
{
    $meta['phone'] = sh_phone_mask($phone);
    $meta['purpose'] = $purpose;
    $meta['status'] = $status;
    sh_security_log('otp_' . $status, null, $meta);
}

// ---------------------------------------------------------------------------
// User lookup
// ---------------------------------------------------------------------------
/**
 * Find an account by phone, matching every supported written form
 * (017XXXXXXXX / +88017XXXXXXXX / 88017XXXXXXXX) against canonical storage.
 */
function sh_find_user_by_phone(string $phone): ?array
{
    $variants = sh_phone_variants($phone);
    if ($variants === []) { return null; }
    $in = implode(',', array_fill(0, count($variants), '?'));
    return sh_one("SELECT * FROM users WHERE phone IN ($in) ORDER BY id DESC LIMIT 1", $variants);
}

// ---------------------------------------------------------------------------
// Generation & storage
// ---------------------------------------------------------------------------
function sh_otp_generate(int $length): string
{
    $max = (int)str_repeat('9', $length);
    $code = (string)random_int(0, $max);
    return str_pad($code, $length, '0', STR_PAD_LEFT);
}

function sh_otp_insert(string $phone, string $purpose, string $code, ?int $userId, string $provider): int
{
    $hash = password_hash($code, PASSWORD_DEFAULT);
    // `expires_at` is computed with the DATABASE clock (NOW()) so the later
    // `expires_at > NOW()` lookups compare like-for-like. Mixing PHP's date()
    // with MySQL's NOW() breaks on hosts whose PHP and MySQL timezones differ,
    // which makes a freshly issued code instantly "not found".
    sh_db()->prepare(
        'INSERT INTO otp_verifications
            (user_id, phone, purpose, otp_hash, provider, expires_at, attempts, max_attempts, resend_count, ip_address, user_agent)
         VALUES (?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0, ?, 0, ?, ?)'
    )->execute([
        $userId,
        $phone,
        $purpose,
        $hash,
        $provider !== '' ? mb_substr($provider, 0, 30) : null,
        sh_otp_expiry_seconds(),
        sh_otp_max_attempts(),
        mb_substr(sh_client_ip(), 0, 45),
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    return (int)sh_db()->lastInsertId();
}

/**
 * Latest unverified record for the exact phone + purpose + user context.
 * Freshness (`is_fresh`) and age (`age_seconds`) are computed in SQL with the
 * database clock, so lookups never depend on the PHP/MySQL timezone relationship.
 */
function sh_otp_find_latest(string $phone, string $purpose, ?int $userId): ?array
{
    $st = sh_db()->prepare(
        'SELECT *,
                (expires_at > NOW()) AS is_fresh,
                GREATEST(0, TIMESTAMPDIFF(SECOND, created_at, NOW())) AS age_seconds
         FROM otp_verifications
         WHERE phone = ? AND purpose = ? AND verified_at IS NULL AND (user_id <=> ?)
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$phone, $purpose, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Persist an OTP record and hand it to the configured provider driver.
 * Returns ['ok'=>bool, 'code'=>string|null, 'error'=>string|null].
 * `code` is only ever populated for the offline driver and MUST be treated as
 * test-only — never displayed in production UI.
 */
function sh_otp_issue(string $phone, string $purpose, ?int $userId, ?string $ip = null): array
{
    $ip = $ip ?: sh_client_ip();
    if (!sh_otp_enabled()) {
        return ['ok' => false, 'code' => null, 'error' => 'Phone verification is disabled.'];
    }
    if (!in_array($purpose, ['signup', 'login', 'test'], true)) {
        return ['ok' => false, 'code' => null, 'error' => 'Invalid verification purpose.'];
    }
    $phone = sh_phone_normalize($phone);
    if ($phone === '') {
        return ['ok' => false, 'code' => null, 'error' => 'Please provide a valid mobile number.'];
    }

    // ---- Rate limits -----------------------------------------------------
    try {
        $limiter = sh_otp_limit_check($phone, $ip);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-limit');
        $limiter = 'Verification is temporarily unavailable. Please try again shortly.';
    }
    if ($limiter !== null) {
        sh_otp_log($phone, $purpose, 'rate_limited', ['reason' => $limiter]);
        return ['ok' => false, 'code' => null, 'error' => $limiter];
    }

    try {
        $row = sh_otp_find_latest($phone, $purpose, $userId);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-active');
        return ['ok' => false, 'code' => null, 'error' => 'Verification is temporarily unavailable. Please try again shortly.'];
    }
    if ($row) {
        if ((int)$row['resend_count'] >= sh_otp_max_resends()) {
            return ['ok' => false, 'code' => null, 'error' => 'Too many codes have been sent. Please try again later.'];
        }
        $cooldown = sh_otp_resend_cooldown();
        if ($cooldown > 0) {
            // age_seconds is measured with the database clock, consistent with
            // the `created_at` value (CURRENT_TIMESTAMP) it is derived from.
            $age = (int)($row['age_seconds'] ?? 0);
            if ($age < $cooldown) {
                $wait = $cooldown - $age;
                return ['ok' => false, 'code' => null, 'error' => "Please wait {$wait} seconds before requesting another code."];
            }
        }
    }

    // ---- Generate, store, send ------------------------------------------
    $code = sh_otp_generate(sh_otp_length());
    $provider = sh_otp_provider();
    try {
        // A new code invalidates any previous, still-pending code for this
        // phone+purpose so an old code can never be replayed.
        sh_db()->prepare(
            'UPDATE otp_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
             WHERE phone = ? AND purpose = ? AND verified_at IS NULL AND (user_id <=> ?)'
        )->execute([$phone, $purpose, $userId]);
        $id = sh_otp_insert($phone, $purpose, $code, $userId, $provider);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-insert');
        return ['ok' => false, 'code' => null, 'error' => 'Could not prepare verification. Please try again.'];
    }

    $minutes = (int)ceil(sh_otp_expiry_seconds() / 60);
    $result = sh_otp_send($provider, $phone, sh_otp_message($code, $minutes));

    if ($result['ok']) {
        sh_otp_log($phone, $purpose, 'requested', ['provider' => $provider, 'id' => $id]);
        $out = ['ok' => true, 'code' => null, 'error' => null];
        if ($provider === 'offline') { $out['code'] = $code; } // test driver only
        return $out;
    }

    // Failed to deliver: destroy the record so no dead code lingers.
    try { sh_db()->prepare('DELETE FROM otp_verifications WHERE id = ?')->execute([$id]); } catch (Throwable $e) { sh_log_exception($e, 'otp-delete'); }
    sh_otp_log($phone, $purpose, 'failed', ['provider' => $provider, 'error' => $result['error']]);
    return ['ok' => false, 'code' => null, 'error' => $result['error']];
}

/**
 * Verify a code against the active record for the phone+purpose. On success the
 * record is marked verified (single-use) and the caller completes the purpose.
 */
function sh_otp_verify(string $phone, string $purpose, string $code, ?int $userId): array
{
    if (!sh_otp_enabled()) {
        return ['ok' => false, 'error' => 'Phone verification is disabled.'];
    }
    $phone = sh_phone_normalize($phone);
    $code = trim($code);
    if ($phone === '' || $code === '') {
        return ['ok' => false, 'error' => 'Enter the code we sent you.'];
    }
    $row = sh_otp_find_latest($phone, $purpose, $userId);
    if (!$row) {
        return ['ok' => false, 'error' => 'No active verification code found. Please request a new code.'];
    }
    // Expiry is decided by the database clock (is_fresh), never by comparing
    // PHP time() against a MySQL datetime string.
    if ((int)$row['is_fresh'] !== 1) {
        $pdo = sh_db();
        $pdo->prepare('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        sh_otp_log($phone, $purpose, 'expired', ['id' => $row['id']]);
        return ['ok' => false, 'error' => 'This verification code has expired. Please request a new code.'];
    }
    if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }
    if (!password_verify($code, $row['otp_hash'])) {
        $pdo = sh_db();
        $pdo->prepare('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        sh_otp_log($phone, $purpose, 'failed', ['attempts' => (int)$row['attempts'] + 1, 'id' => $row['id']]);
        return ['ok' => false, 'error' => 'Invalid verification code.'];
    }

    // Correct code: consume it so it cannot be replayed.
    $pdo = sh_db();
    $pdo->prepare('UPDATE otp_verifications SET verified_at = NOW() WHERE id = ?')->execute([$row['id']]);
    sh_otp_log($phone, $purpose, 'verified', ['id' => $row['id']]);
    return ['ok' => true, 'error' => null, 'method' => 'sms'];
}

// ---------------------------------------------------------------------------
// Rate limiting (per phone + per IP + daily cap) — implemented in SQL so a
// user cannot bypass it by clearing cookies or opening many sessions.
// ---------------------------------------------------------------------------
function sh_otp_limit_check(string $phone, string $ip): ?string
{
    $pdo = sh_db();

    // All windows are measured with the database clock so the limits hold
    // regardless of any PHP/MySQL timezone difference.
    $st = $pdo->prepare('SELECT COUNT(*) FROM otp_verifications WHERE phone = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    $st->execute([$phone, sh_otp_phone_rate_window()]);
    if ((int)$st->fetchColumn() >= sh_otp_phone_rate_limit()) {
        return 'Too many verification requests for this number. Please wait a few minutes.';
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM otp_verifications WHERE ip_address = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    $st->execute([$ip, sh_otp_ip_rate_window()]);
    if ((int)$st->fetchColumn() >= sh_otp_ip_rate_limit()) {
        return 'Too many verification requests from this device. Please try again later.';
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM otp_verifications WHERE phone = ? AND created_at >= CURDATE()');
    $st->execute([$phone]);
    if ((int)$st->fetchColumn() >= sh_otp_daily_limit()) {
        return 'Daily verification limit reached for this number. Please try again tomorrow.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Provider drivers
// ---------------------------------------------------------------------------
/** @var array<string, callable> */
function &sh_otp_driver_registry(): array
{
    static $drivers = null;
    if ($drivers === null) { $drivers = []; }
    return $drivers;
}

/** Register a custom provider driver: fn(string $phone, string $message, array $cfg): array */
function sh_otp_register_driver(string $name, callable $fn): void
{
    // Returning the static registry by reference is essential: the old value
    // return made every custom registration vanish (and emitted a PHP Notice).
    $drivers = &sh_otp_driver_registry();
    $drivers[$name] = $fn;
}

/**
 * Read an SMS driver option. An explicit override set (used by the dedicated
 * Human Support Notifications configuration) wins; otherwise the shared OTP
 * settings are used. This keeps ONE driver implementation per carrier instead
 * of a second, parallel notification system.
 */
function sh_otp_setting_value(string $key, array $cfg, string $default = ''): string
{
    if (array_key_exists($key, $cfg)) { return (string)$cfg[$key]; }
    return (string)sh_setting($key, $default);
}

/** Short, secret-free reference an admin can quote without exposing provider data. */
function sh_sms_diagnostic_id(): string
{
    try { return 'SMS-' . bin2hex(random_bytes(4)); }
    catch (Throwable $e) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(4);
            if (is_string($bytes) && strlen($bytes) === 4) { return 'SMS-' . bin2hex($bytes); }
        }
        return 'SMS-' . substr(hash('sha256', microtime(true) . (string)getmypid() . __FILE__), 0, 8);
    }
}

/** Redact credentials, long opaque tokens and phone numbers before any log is written. */
function sh_sms_scrub_diagnostic(string $text): string
{
    $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
    if (function_exists('sh_scrub_secrets')) { $text = (string)sh_scrub_secrets($text); }
    $text = preg_replace('/(?:\b(?:x-api-key|api[_-]?key|apikey|token|secret|password|authorization|bearer)\b\s*[:=]?\s*)([^\s,;"\']+)/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/([?&](?:key|token|api_key|secret|password|access_token)=)[^&\s]+/i', '$1[redacted]', $text) ?? $text;
    $text = preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '[redacted-token]', $text) ?? $text;
    $text = preg_replace('/\+?\d{8,15}/', '[phone]', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return mb_substr(trim($text), 0, 400);
}

/** Base diagnostic shape shared by all drivers. No request body is ever logged. */
function sh_sms_diagnostic_base(string $provider, string $phone, string $endpoint, string $method, string $auth): array
{
    $masked = function_exists('sh_phone_mask') ? (string)sh_phone_mask($phone) : '[phone]';
    return [
        'id' => sh_sms_diagnostic_id(),
        'provider' => mb_substr($provider, 0, 40),
        'endpoint' => $endpoint,
        'method' => $method,
        'auth' => $auth,
        'recipient' => $masked,
        'http_status' => 0,
        'curl_errno' => 0,
        'curl_error' => '',
        'response' => '',
        'acceptance' => '',
        'ssl_verify' => 'on',
        'timeout' => 15,
        'duration_ms' => 0,
    ];
}

/** Normalize and scrub a diagnostic before it reaches the secure admin log. */
function sh_sms_diagnostic(array $diagnostic): array
{
    $diagnostic = array_merge(sh_sms_diagnostic_base('', '', '', '', ''), $diagnostic);
    $scrub = ['endpoint', 'curl_error', 'response', 'error', 'acceptance'];
    foreach ($scrub as $key) {
        $diagnostic[$key] = sh_sms_scrub_diagnostic((string)($diagnostic[$key] ?? ''));
    }
    $diagnostic['recipient'] = sh_sms_scrub_diagnostic((string)($diagnostic['recipient'] ?? '[phone]'));
    $diagnostic['provider'] = mb_substr(preg_replace('/[^a-z0-9_.:-]+/i', '-', (string)$diagnostic['provider']) ?: 'unknown', 0, 40);
    $diagnostic['method'] = strtoupper(mb_substr((string)$diagnostic['method'], 0, 10));
    $diagnostic['auth'] = mb_substr((string)$diagnostic['auth'], 0, 30);
    $diagnostic['code'] = mb_substr(preg_replace('/[^a-z0-9_.:-]+/i', '-', (string)($diagnostic['code'] ?? '')) ?: 'unknown', 0, 40);
    $diagnostic['id'] = preg_replace('/[^A-Za-z0-9-]/', '', (string)$diagnostic['id']) ?: sh_sms_diagnostic_id();
    foreach (['http_status', 'curl_errno', 'timeout', 'duration_ms'] as $key) {
        $diagnostic[$key] = max(0, (int)$diagnostic[$key]);
    }
    return $diagnostic;
}

/** One uniform transport result: ok, user-safe error, machine code, secure diagnostic. */
function sh_sms_result(bool $ok, string $error, array $diagnostic, string $code = ''): array
{
    $diagnostic['code'] = $code !== '' ? $code : ($ok ? 'accepted' : 'provider_failed');
    return [
        'ok' => $ok,
        'error' => $error !== '' ? sh_sms_scrub_diagnostic($error) : null,
        'code' => (string)$diagnostic['code'],
        'diagnostic' => sh_sms_diagnostic($diagnostic),
    ];
}

/** Normalize a custom driver's result without leaking raw exceptions or secrets. */
function sh_sms_normalize_result($result, string $provider, string $phone, array $cfg = []): array
{
    $result = is_array($result) ? $result : [];
    $base = sh_sms_diagnostic_base($provider, $phone, '', 'CUSTOM', 'custom_driver');
    $diagnostic = is_array($result['diagnostic'] ?? null)
        ? array_merge($base, $result['diagnostic'])
        : $base;
    $ok = !empty($result['ok']);
    $error = (string)($result['error'] ?? '');
    $code = (string)($result['code'] ?? ($ok ? 'accepted' : 'provider_failed'));
    return sh_sms_result($ok, $error, $diagnostic, $code);
}

/** cURL outcome plus transport metadata, always scrubbed. */
function sh_sms_curl_finish($ch, $response, array $diagnostic, float $started): array
{
    $errno = $response === false ? (int)curl_errno($ch) : 0;
    $error = $response === false ? (string)curl_error($ch) : '';
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $diagnostic['http_status'] = $http;
    $diagnostic['curl_errno'] = $errno;
    $diagnostic['curl_error'] = $error;
    $diagnostic['response'] = is_string($response) ? $response : '';
    $diagnostic['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
    return sh_sms_diagnostic($diagnostic);
}

/** Translate cURL failures, including cPanel outbound HTTPS restrictions, for admins. */
function sh_sms_curl_error_message(int $errno, string $error): string
{
    $safe = sh_sms_scrub_diagnostic($error);
    $prefix = $errno > 0 ? 'cURL error ' . $errno . '. ' : '';
    $detail = match ($errno) {
        6 => 'DNS resolution failed; check outbound DNS and HTTPS access.',
        7 => 'Connection failed; cPanel/shared-hosting outbound HTTPS may be blocked, or the provider is unreachable.',
        28 => 'The request timed out; check outbound HTTPS, firewall and provider status.',
        35, 51, 58, 60, 77 => 'TLS certificate verification failed; update the server CA bundle or contact the host.',
        default => $safe !== '' ? $safe : 'The provider could not be reached.',
    };
    return $prefix . $detail;
}

/** User-safe, provider-specific HTTP status explanation. Raw bodies stay in the secure log. */
function sh_sms_http_error(string $provider, int $status): string
{
    $name = $provider === 'textbee' ? 'TextBee' : 'The SMS provider';
    if ($status === 401 || $status === 403) {
        return $name . ' rejected authentication (HTTP ' . $status . '). Check the API key and account permissions.';
    }
    if ($status === 429) {
        return $name . ' reported a plan/rate limit (HTTP 429). Wait before trying again; do not retry blindly.';
    }
    if ($status === 400) {
        return $name . ' rejected the request (HTTP 400). Check account verification, registered device, sender and recipient format.';
    }
    if ($status >= 500) {
        return $name . ' returned a server error (HTTP ' . $status . '). Try again later.';
    }
    return $name . ' returned HTTP ' . $status . ' without confirming acceptance.';
}

/** TextBee's current contract requires data.success:true and an smsBatchId. */
function sh_sms_textbee_response_state(string $response): array
{
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
        return ['ok' => false, 'reason' => 'Response did not contain a data.success object.'];
    }
    if (($decoded['data']['success'] ?? null) !== true) {
        return ['ok' => false, 'reason' => 'data.success was not true.'];
    }
    if (trim((string)($decoded['data']['smsBatchId'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'No smsBatchId was returned.'];
    }
    return ['ok' => true, 'reason' => 'data.success=true and smsBatchId present.'];
}

/** Generic providers accept HTTP 2xx; an optional success marker must match if set. */
function sh_sms_generic_response_state(string $response, string $field, string $value): array
{
    if ($field === '') {
        return ['ok' => true, 'reason' => 'HTTP 2xx accepted (no success marker configured).'];
    }
    $decoded = json_decode($response, true);
    if ($value === '') {
        $present = is_array($decoded) && sh_array_get($decoded, $field) !== null;
        return $present
            ? ['ok' => true, 'reason' => 'HTTP 2xx and success field present.']
            : ['ok' => false, 'reason' => 'Success field was not present in the response.'];
    }
    $matched = sh_otp_response_has($response, $field, $value);
    return $matched
        ? ['ok' => true, 'reason' => 'HTTP 2xx and success marker matched.']
        : ['ok' => false, 'reason' => 'Success marker did not match the response.'];
}

/**
 * One transport for both OTP codes and human-support SMS.
 * $cfg is an optional set of per-channel credential overrides keyed by the
 * SAME setting names the shipped drivers already use. Every outcome carries a
 * scrubbed diagnostic for the secure Admin log; no result claims handset
 * delivery, only provider acceptance.
 *
 * @return array{ok:bool,error:?string,code:string,diagnostic:array}
 */
function sh_sms_deliver(string $provider, string $phone, string $message, array $cfg = []): array
{
    $drivers = sh_otp_driver_registry();
    if (isset($drivers[$provider])) {
        try {
            return sh_sms_normalize_result($drivers[$provider]($phone, $message, $cfg), $provider, $phone, $cfg);
        } catch (Throwable $e) {
            sh_log_exception($e, 'sms-provider');
            return sh_sms_result(false, 'The SMS provider failed unexpectedly. See secure diagnostics.',
                sh_sms_diagnostic_base($provider, $phone, '', 'CUSTOM', 'custom_driver'), 'provider_exception');
        }
    }
    try {
        if ($provider === 'offline') { return sh_otp_provider_offline($phone, $message); }
        if ($provider === 'textbee') { return sh_otp_provider_textbee($phone, $message, $cfg); }
        if ($provider === 'firebase') { return sh_otp_provider_firebase($phone, $message); }
        if ($provider === 'generic_http') { return sh_otp_provider_generic($phone, $message, $cfg); }
        return sh_sms_result(false, 'SMS provider is not configured.',
            sh_sms_diagnostic_base($provider ?: 'unknown', $phone, '', '', 'none'), 'provider_not_configured');
    } catch (Throwable $e) {
        sh_log_exception($e, 'sms-provider');
        return sh_sms_result(false, 'The SMS provider failed unexpectedly. See secure diagnostics.',
            sh_sms_diagnostic_base($provider, $phone, '', '', 'none'), 'provider_exception');
    }
}

function sh_otp_send(string $provider, string $phone, string $message): array
{
    return sh_sms_deliver($provider, $phone, $message);
}

/** Test driver: never delivered anywhere; only for confirming the flow works. */
function sh_otp_provider_offline(string $phone, string $message): array
{
    $diag = sh_sms_diagnostic_base('offline', $phone, '', 'NONE', 'none');
    $diag['acceptance'] = 'Offline test driver only; no SMS was sent.';
    return sh_sms_result(true, '', $diag, 'offline_test_only');
}

/** E.164 formatting for providers that require it (e.g. +8801712345678). */
function sh_otp_e164(string $phone): string
{
    $p = sh_phone_normalize($phone);
    return $p === '' ? $p : '+' . $p;
}

/**
 * TextBee driver — current documented account endpoint:
 * POST https://api.textbee.dev/api/v1/gateway/send-sms
 * JSON { recipients: [E.164], message, deviceId? }, header x-api-key.
 * A 200 response means queued, NOT handset delivery; data.success:true plus
 * smsBatchId is required before we report provider acceptance.
 */
function sh_otp_provider_textbee(string $phone, string $message, array $cfg = []): array
{
    $apiKey = trim(sh_otp_setting_value('otp_textbee_api_key', $cfg));
    $deviceId = trim(sh_otp_setting_value('otp_textbee_device_id', $cfg));
    $endpoint = 'https://api.textbee.dev/api/v1/gateway/send-sms';
    $diag = sh_sms_diagnostic_base('textbee', $phone, $endpoint, 'POST', 'x-api-key');
    if ($apiKey === '') {
        $diag['acceptance'] = 'No TextBee API key configured.';
        return sh_sms_result(false, 'TextBee API key is required. Device ID is optional with the current account endpoint.', $diag, 'credentials_missing');
    }
    if (!function_exists('curl_init')) {
        $diag['acceptance'] = 'PHP cURL extension unavailable.';
        return sh_sms_result(false, 'PHP cURL is not enabled on this server, so TextBee cannot be reached.', $diag, 'curl_missing');
    }
    $payload = ['recipients' => [sh_otp_e164($phone)], 'message' => $message];
    if ($deviceId !== '') { $payload['deviceId'] = $deviceId; }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return sh_sms_result(false, 'The TextBee request could not be encoded.', $diag, 'payload_error');
    }
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return sh_sms_result(false, 'cURL could not initialize the TextBee request.', $diag, 'curl_init_failed');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if (defined('CURLOPT_SSL_VERIFYHOST')) { $options[CURLOPT_SSL_VERIFYHOST] = 2; }
    curl_setopt_array($ch, $options);
    $started = microtime(true);
    $resp = curl_exec($ch);
    $diag = sh_sms_curl_finish($ch, $resp, $diag, $started);

    if ($resp === false) {
        $diag['acceptance'] = 'No HTTP response; request not queued.';
        return sh_sms_result(false, 'Could not reach TextBee. ' . sh_sms_curl_error_message((int)$diag['curl_errno'], (string)$diag['curl_error']),
            $diag, 'curl_' . (int)$diag['curl_errno']);
    }
    if ($diag['http_status'] >= 400) {
        $diag['acceptance'] = 'HTTP error; request not queued.';
        return sh_sms_result(false, sh_sms_http_error('textbee', (int)$diag['http_status']),
            $diag, 'http_' . (int)$diag['http_status']);
    }
    $state = sh_sms_textbee_response_state((string)$resp);
    $diag['acceptance'] = $state['reason'];
    if (!$state['ok']) {
        return sh_sms_result(false, 'TextBee did not confirm acceptance: ' . $state['reason'], $diag, 'invalid_response');
    }
    return sh_sms_result(true, '', $diag, 'accepted');
}

/**
 * Firebase (optional). Firebase Auth's phone sign-in issues its OWN code and
 * cannot carry this store's server-generated OTP, so this driver never fakes a
 * delivery — it reports the limitation so the store owner picks a carrier that
 * can actually deliver the code (TextBee or a custom HTTP gateway).
 */
function sh_otp_provider_firebase(string $phone, string $message): array
{
    $diag = sh_sms_diagnostic_base('firebase', $phone, '', 'NONE', 'none');
    $diag['acceptance'] = 'Unsupported provider; no SMS was sent.';
    return sh_sms_result(false,
        'Firebase Auth cannot deliver this store\'s verification code. Choose TextBee or a custom HTTP SMS gateway instead.',
        $diag, 'unsupported_provider');
}

/**
 * Generic HTTP driver. Supports GET / form-post / JSON-post bodies with
 * {phone} {phone_e164} {message} {sender} {api_key} {api_secret} {token}
 * {username} {password} placeholders, custom headers, an optional success
 * marker in the response, optional bearer/basic auth, and a phone/message param
 * fallback when no body template is supplied. Only HTTPS endpoints are allowed.
 */
function sh_otp_provider_generic(string $phone, string $message, array $cfg = []): array
{
    $url = trim((string)sh_otp_setting_value('otp_api_url', $cfg, ''));
    $method = strtolower((string)sh_otp_setting_value('otp_api_method', $cfg, 'post_json'));
    if (!in_array($method, ['get', 'post_form', 'post_json'], true)) { $method = 'post_json'; }
    $diag = sh_sms_diagnostic_base('generic_http', $phone, $url, strtoupper($method), 'none');
    if ($url === '') {
        $diag['acceptance'] = 'No endpoint configured.';
        return sh_sms_result(false, 'SMS API URL is not configured.', $diag, 'credentials_missing');
    }
    if (preg_match('~^https://~i', $url) !== 1) {
        $diag['acceptance'] = 'Rejected non-HTTPS endpoint.';
        return sh_sms_result(false, 'Custom SMS API URL must use HTTPS.', $diag, 'insecure_endpoint');
    }
    if (!function_exists('curl_init')) {
        $diag['acceptance'] = 'PHP cURL extension unavailable.';
        return sh_sms_result(false, 'PHP cURL is not enabled on this server, so the SMS gateway cannot be reached.', $diag, 'curl_missing');
    }

    $bodyTpl = (string)sh_otp_setting_value('otp_api_body', $cfg, '');
    $headersTpl = (string)sh_otp_setting_value('otp_api_headers', $cfg, '');
    $successField = (string)sh_otp_setting_value('otp_success_field', $cfg, '');
    $successValue = (string)sh_otp_setting_value('otp_success_value', $cfg, '');
    $sender = (string)sh_otp_setting_value('otp_sender_id', $cfg, 'ShopHaat');

    $placeholders = [
        '{phone}'      => $phone,
        '{phone_e164}' => sh_otp_e164($phone),
        '{message}'    => $message,
        '{sender}'     => $sender,
        '{api_key}'    => (string)sh_otp_setting_value('otp_api_key', $cfg, ''),
        '{api_secret}' => (string)sh_otp_setting_value('otp_api_secret', $cfg, ''),
        '{token}'      => (string)sh_otp_setting_value('otp_api_token', $cfg, ''),
        '{username}'   => (string)sh_otp_setting_value('otp_api_username', $cfg, ''),
        '{password}'   => (string)sh_otp_setting_value('otp_api_password', $cfg, ''),
    ];
    if (trim($bodyTpl) === '') {
        $phoneParam = (string)sh_otp_setting_value('otp_phone_param', $cfg, 'phone');
        $messageParam = (string)sh_otp_setting_value('otp_message_param', $cfg, 'message');
        $pairs = [$phoneParam => $phone, $messageParam => $message];
        $body = $method === 'post_json' ? (string)json_encode($pairs) : http_build_query($pairs);
    } else {
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTpl);
    }
    $headers = sh_otp_parse_headers($headersTpl, $placeholders);
    $hasContentType = false;
    foreach ($headers as $key => $value) {
        if (strtolower((string)$key) === 'content-type') { $hasContentType = true; break; }
    }
    if (!$hasContentType && $method === 'post_json') { $headers['Content-Type'] = 'application/json'; }
    if (!$hasContentType && $method === 'post_form') { $headers['Content-Type'] = 'application/x-www-form-urlencoded'; }

    $ch = curl_init();
    if ($ch === false) {
        return sh_sms_result(false, 'cURL could not initialize the SMS request.', $diag, 'curl_init_failed');
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if (defined('CURLOPT_SSL_VERIFYHOST')) { $options[CURLOPT_SSL_VERIFYHOST] = 2; }
    if ($method === 'get') {
        $options[CURLOPT_URL] = $url . (str_contains($url, '?') ? '&' : '?') . $body;
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    $httpHeaders = [];
    foreach ($headers as $k => $v) { $httpHeaders[] = $k . ': ' . $v; }
    $auth = strtolower((string)sh_otp_setting_value('otp_api_auth', $cfg, ''));
    $token = (string)sh_otp_setting_value('otp_api_token', $cfg, '');
    if ($auth === 'bearer' && $token !== '') {
        $httpHeaders[] = 'Authorization: Bearer ' . $token;
        $diag['auth'] = 'bearer';
    } elseif ($auth === 'basic' && (string)sh_otp_setting_value('otp_api_username', $cfg, '') !== '') {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = (string)sh_otp_setting_value('otp_api_username', $cfg, '') . ':' . (string)sh_otp_setting_value('otp_api_password', $cfg, '');
        $diag['auth'] = 'basic';
    }
    if ($httpHeaders) { $options[CURLOPT_HTTPHEADER] = $httpHeaders; }
    curl_setopt_array($ch, $options);

    $started = microtime(true);
    $resp = curl_exec($ch);
    $diag = sh_sms_curl_finish($ch, $resp, $diag, $started);

    if ($resp === false) {
        $diag['acceptance'] = 'No HTTP response; request not accepted.';
        return sh_sms_result(false, 'Could not reach the SMS provider. ' . sh_sms_curl_error_message((int)$diag['curl_errno'], (string)$diag['curl_error']),
            $diag, 'curl_' . (int)$diag['curl_errno']);
    }
    if ($diag['http_status'] >= 400) {
        $diag['acceptance'] = 'HTTP error; request not accepted.';
        return sh_sms_result(false, sh_sms_http_error('generic_http', (int)$diag['http_status']),
            $diag, 'http_' . (int)$diag['http_status']);
    }
    $state = sh_sms_generic_response_state((string)$resp, $successField, $successValue);
    $diag['acceptance'] = $state['reason'];
    if (!$state['ok']) {
        return sh_sms_result(false, 'SMS provider did not confirm acceptance: ' . $state['reason'], $diag, 'success_marker_missing');
    }
    return sh_sms_result(true, '', $diag, 'accepted');
}

function sh_otp_parse_headers(string $json, array $placeholders): array
{
    $headers = [];
    if (trim($json) === '') { return $headers; }
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
            $headers[(string)$k] = str_replace(array_keys($placeholders), array_values($placeholders), (string)$v);
        }
    }
    return $headers;
}

function sh_otp_response_has(string $response, string $field, string $value): bool
{
    $decoded = json_decode($response, true);
    if (is_array($decoded) && sh_array_get($decoded, $field) !== null) {
        return (string)sh_array_get($decoded, $field) === $value;
    }
    return strpos($response, $value) !== false;
}

function sh_array_get(array $arr, string $key, $default = null)
{
    if (array_key_exists($key, $arr)) { return $arr[$key]; }
    return $default;
}

// ---------------------------------------------------------------------------
// User helpers
// ---------------------------------------------------------------------------
/** Mark a user's phone as verified. Logs the event (phone verified). */
function sh_user_mark_verified(int $userId, string $phone, ?string $method = null): void
{
    $canon = sh_phone_normalize($phone);
    sh_db()->prepare(
        'UPDATE users SET phone_verified = 1, phone_verified_at = NOW(), phone_verification_method = ?, phone = ? WHERE id = ?'
    )->execute([$method ?: 'sms', $canon, $userId]);
    sh_security_log('phone_verified', $userId, ['phone' => sh_phone_mask($canon), 'method' => $method ?: 'sms']);
}

function sh_user_verified(int $userId): bool
{
    $row = sh_val('SELECT phone_verified FROM users WHERE id = ? LIMIT 1', [$userId], 0);
    return (int)$row === 1;
}

// ---------------------------------------------------------------------------
// Pending signup (held server-side between the form step and OTP verification)
// ---------------------------------------------------------------------------
function sh_pending_signup(): ?array
{
    $p = $_SESSION['otp_pending_signup'] ?? null;
    return is_array($p) ? $p : null;
}

function sh_pending_signup_save(string $name, string $phone, string $redirect = ''): void
{
    $_SESSION['otp_pending_signup'] = [
        'name' => $name,
        'phone' => $phone,
        'redirect' => $redirect,
        'created_at' => time(),
    ];
}

function sh_pending_signup_clear(): void
{
    unset($_SESSION['otp_pending_signup']);
}

// ---------------------------------------------------------------------------
// Pending login (phone verified via OTP; holds the user id + phone + redirect)
// ---------------------------------------------------------------------------
function sh_pending_login(): ?array
{
    $p = $_SESSION['otp_pending_login'] ?? null;
    return is_array($p) ? $p : null;
}

function sh_pending_login_save(int $userId, string $phone, string $redirect = ''): void
{
    $_SESSION['otp_pending_login'] = [
        'user_id' => $userId,
        'phone' => $phone,
        'redirect' => $redirect,
        'created_at' => time(),
    ];
}

function sh_pending_login_clear(): void
{
    unset($_SESSION['otp_pending_login']);
}

// ---------------------------------------------------------------------------
// Completion (called only after sh_otp_verify() returned ok)
// ---------------------------------------------------------------------------
function sh_otp_complete(string $purpose, string $phone): array
{
    $phone = sh_phone_normalize($phone);
    switch ($purpose) {
        case 'signup':
            $pend = sh_pending_signup();
            if ($pend === null) {
                return ['ok' => false, 'error' => 'Your session expired. Please sign up again.'];
            }
            if (sh_phone_normalize($pend['phone']) !== $phone) {
                return ['ok' => false, 'error' => 'The verified number does not match your sign-up. Please start again.'];
            }
            // Re-check for a race: the number may have been taken since the form.
            if (sh_find_user_by_phone($phone) !== null) {
                return ['ok' => false, 'error' => 'An account already exists with this phone number. Please log in instead.'];
            }
            $email = sh_synthetic_email($phone);
            $uid = sh_insert('users', [
                'name'                    => $pend['name'],
                'email'                   => $email,
                'phone'                   => $phone,
                'phone_verified'          => 1,
                'phone_verified_at'       => date('Y-m-d H:i:s'),
                'phone_verification_method' => 'sms',
                'password_hash'           => '',
                'status'                  => 'active',
            ]);
            try { require_once SH_ROOT . '/includes/admin-tools.php'; sh_admin_notify('new_customer', 'New customer registered', 'Phone sign-up', 'admin/customers.php?id=' . $uid); } catch (Throwable $e) {}
            $redirect = sh_customer_return_target((string)$pend['redirect'], 'account.php');
            sh_pending_signup_clear();
            sh_login_user($uid); // merges any guest cart
            sh_security_log('account_created', $uid, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => $redirect];

        case 'login':
            $pend = sh_pending_login();
            if ($pend === null) {
                return ['ok' => false, 'error' => 'Your session expired. Please sign in again.'];
            }
            if (sh_phone_normalize($pend['phone']) !== $phone) {
                return ['ok' => false, 'error' => 'The verified number does not match your sign-in. Please start again.'];
            }
            $uid = (int)$pend['user_id'];
            $user = sh_one('SELECT status FROM users WHERE id = ? LIMIT 1', [$uid]);
            if ($user === null || $user['status'] !== 'active') {
                return ['ok' => false, 'error' => 'This account has been blocked. Please contact customer support.'];
            }
            sh_user_mark_verified($uid, $phone, 'sms');
            $redirect = sh_customer_return_target((string)$pend['redirect'], 'account.php');
            sh_pending_login_clear();
            sh_login_user($uid); // merges the guest cart now that verification passed
            sh_security_log('login_success', $uid, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => $redirect];

        case 'test':
            sh_security_log('otp_test', null, ['phone' => sh_phone_mask($phone)]);
            return ['ok' => true, 'redirect' => null];

        default:
            return ['ok' => false, 'error' => 'Unknown verification purpose.'];
    }
}
