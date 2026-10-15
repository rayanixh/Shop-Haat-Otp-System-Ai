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
function sh_otp_issue(string $phone, string $purpose, ?int $userId, string $ip = null): array
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
function sh_otp_driver_registry(): array
{
    static $drivers = null;
    if ($drivers === null) { $drivers = []; }
    return $drivers;
}

/** Register a custom provider driver: fn(string $phone, string $message): array{ok:bool,error:?string} */
function sh_otp_register_driver(string $name, callable $fn): void
{
    $drivers = &sh_otp_driver_registry();
    $drivers[$name] = $fn;
}

function sh_otp_send(string $provider, string $phone, string $message): array
{
    $drivers = sh_otp_driver_registry();
    if (isset($drivers[$provider])) {
        try {
            return $drivers[$provider]($phone, $message);
        } catch (Throwable $e) {
            sh_log_exception($e, 'otp-provider');
            return ['ok' => false, 'error' => 'SMS provider error.'];
        }
    }
    if ($provider === 'offline') {
        return sh_otp_provider_offline($phone, $message);
    }
    if ($provider === 'textbee') {
        return sh_otp_provider_textbee($phone, $message);
    }
    if ($provider === 'firebase') {
        return sh_otp_provider_firebase($phone, $message);
    }
    if ($provider === 'generic_http') {
        return sh_otp_provider_generic($phone, $message);
    }
    return ['ok' => false, 'error' => 'SMS provider is not configured.'];
}

/** Test driver: never delivered anywhere; only for confirming the flow works. */
function sh_otp_provider_offline(string $phone, string $message): array
{
    return ['ok' => true, 'error' => null];
}

/** E.164 formatting for providers that require it (e.g. +8801712345678). */
function sh_otp_e164(string $phone): string
{
    $p = sh_phone_normalize($phone);
    return $p === '' ? $p : '+' . $p;
}

/**
 * TextBee driver — https://api.textbee.dev/api/v1/gateway/devices/{device_id}/send-sms
 * JSON body { recipients: [E.164], message }, header `x-api-key: <api key>`.
 */
function sh_otp_provider_textbee(string $phone, string $message): array
{
    $apiKey = trim((string)sh_setting('otp_textbee_api_key', ''));
    $deviceId = trim((string)sh_setting('otp_textbee_device_id', ''));
    if ($apiKey === '' || $deviceId === '') {
        return ['ok' => false, 'error' => 'TextBee API key and Device ID are both required.'];
    }
    $url = 'https://api.textbee.dev/api/v1/gateway/devices/' . rawurlencode($deviceId) . '/send-sms';
    $body = json_encode(['recipients' => [sh_otp_e164($phone)], 'message' => $message]);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL is required for SMS delivery.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Could not reach TextBee: ' . $err];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => "TextBee rejected the request (HTTP {$code})."];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * Firebase (optional). Firebase Auth's phone sign-in issues its OWN code and
 * cannot carry this store's server-generated OTP, so this driver never fakes a
 * delivery — it reports the limitation so the store owner picks a carrier that
 * can actually deliver the code (TextBee or a custom HTTP gateway).
 */
function sh_otp_provider_firebase(string $phone, string $message): array
{
    return ['ok' => false,
        'error' => 'Firebase Auth cannot deliver this store\'s verification code. Choose TextBee or a custom HTTP SMS gateway instead.'];
}

/**
 * Generic HTTP driver. Supports GET / form-post / JSON-post bodies with
 * {phone} {message} {sender} {api_key} {api_secret} {token} {username}
 * {password} placeholders, custom headers, an optional success marker in the
 * response, optional bearer/basic auth, and a simple phone/message param
 * fallback when no body template is supplied.
 */
function sh_otp_provider_generic(string $phone, string $message): array
{
    $url = trim((string)sh_setting('otp_api_url', ''));
    if ($url === '') {
        return ['ok' => false, 'error' => 'SMS API URL is not configured.'];
    }
    $method = strtolower((string)sh_setting('otp_api_method', 'post_json'));
    $bodyTpl = (string)sh_setting('otp_api_body', '');
    $headersTpl = (string)sh_setting('otp_api_headers', '');
    $successField = (string)sh_setting('otp_success_field', '');
    $successValue = (string)sh_setting('otp_success_value', '');
    $sender = (string)sh_setting('otp_sender_id', 'ShopHaat');

    $placeholders = [
        '{phone}'     => $phone,
        '{message}'   => $message,
        '{sender}'    => $sender,
        '{api_key}'   => (string)sh_setting('otp_api_key', ''),
        '{api_secret}' => (string)sh_setting('otp_api_secret', ''),
        '{token}'     => (string)sh_setting('otp_api_token', ''),
        '{username}'  => (string)sh_setting('otp_api_username', ''),
        '{password}'  => (string)sh_setting('otp_api_password', ''),
    ];

    // Fallback: no body template => build from the phone/message param names.
    if (trim($bodyTpl) === '') {
        $phoneParam = (string)sh_setting('otp_phone_param', 'phone');
        $messageParam = (string)sh_setting('otp_message_param', 'message');
        $pairs = [$phoneParam => $phone, $messageParam => $message];
        $body = $method === 'post_json' ? json_encode($pairs) : http_build_query($pairs);
    } else {
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $bodyTpl);
    }
    $headers = sh_otp_parse_headers($headersTpl, $placeholders);

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL is required for SMS delivery.'];
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method === 'get') {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $options[CURLOPT_URL] = $url . $sep . $body;
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    $httpHeaders = [];
    foreach ($headers as $k => $v) { $httpHeaders[] = "$k: $v"; }
    // Optional auth: bearer token header, or HTTP basic auth.
    $auth = strtolower((string)sh_setting('otp_api_auth', ''));
    $token = (string)sh_setting('otp_api_token', '');
    if ($auth === 'bearer' && $token !== '') {
        $httpHeaders[] = 'Authorization: Bearer ' . $token;
    }
    if ($auth === 'basic' && (string)sh_setting('otp_api_username', '') !== '') {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = (string)sh_setting('otp_api_username', '') . ':' . (string)sh_setting('otp_api_password', '');
    }
    if ($httpHeaders) { $options[CURLOPT_HTTPHEADER] = $httpHeaders; }
    curl_setopt_array($ch, $options);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Could not reach the SMS provider: ' . $err];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => "SMS provider rejected the request (HTTP {$code})."];
    }
    if ($successField !== '') {
        if (!sh_otp_response_has($resp, $successField, $successValue)) {
            return ['ok' => false, 'error' => 'SMS provider reported a failure.'];
        }
    }
    return ['ok' => true, 'error' => null];
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
