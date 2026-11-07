<?php
/**
 * Human Support Notifications — SMS channel.
 *
 * This is NOT a second notification engine. It reuses:
 *   - the shipped SMS drivers in includes/phone-otp.php (sh_sms_deliver),
 *   - the existing `settings` table for configuration (no new tables),
 *   - the existing support_handoff_events unique key for one-message-per-request
 *     idempotency,
 *   - the existing notification_logs / sh_notify_log() logging when available,
 *   - the existing sh_log_line()/sh_security_log() secure Admin diagnostics.
 *
 * Security rules enforced here:
 *   - provider secrets are read and used server-side ONLY; nothing is rendered
 *     into public HTML/JS and nothing is written unredacted to a log,
 *   - the SMS body contains only a conversation reference and a "customer
 *     waiting" note — no name, phone, email, message text, order, payment,
 *     password, API key, or custom admin route,
 *   - "sent" is reported ONLY when the provider driver confirms acceptance;
 *     offline/test mode never claims a real delivery,
 *   - a disabled or misconfigured channel never affects the customer chat.
 */
declare(strict_types=1);

const SH_SUPPORT_SMS_EVENT = 'human_support_request';
const SH_SUPPORT_SMS_CHANNEL = 'sms';

/** Only carriers the current PHP/cURL shared-hosting stack can actually call. */
function sh_support_sms_providers(): array
{
    return [
        'offline' => [
            'label' => 'Offline / test mode (no real SMS is sent)',
            'real' => false,
            'note' => 'Use only to exercise the flow. No provider is contacted and no success is claimed.',
        ],
        'store' => [
            'label' => 'Use the store’s configured SMS provider (Security → SMS/OTP Provider)',
            'real' => true,
            'note' => 'Reuses the exact provider and credentials already used for phone verification. No duplicate credentials to maintain.',
        ],
        'textbee' => [
            'label' => 'TextBee (dedicated credentials)',
            'real' => true,
            'note' => 'TextBee gateway API key over HTTPS with cURL. Device ID is optional on the current account endpoint.',
        ],
        'generic_http' => [
            'label' => 'Custom SMS API (dedicated credentials)',
            'real' => true,
            'note' => 'Any HTTPS GET/POST JSON or form gateway. Supports a provider success marker.',
        ],
    ];
}

/** Firebase Auth cannot carry server-generated text; it is deliberately absent. */
function sh_support_sms_provider_allowed(string $provider): bool
{
    return array_key_exists($provider, sh_support_sms_providers());
}

function sh_support_sms_setting(string $key, string $default = ''): string
{
    return (string)sh_setting('support_sms_' . $key, $default);
}

function sh_support_sms_save_setting(string $key, string $value): void
{
    sh_setting_save('support_sms_' . $key, $value);
}

/** Server-side phone normalization; falls back to a strict digit rule in tests. */
function sh_support_sms_normalize_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') { return ''; }
    if (function_exists('sh_phone_normalize')) { return (string)sh_phone_normalize($raw); }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : '';
}

/**
 * Map the dedicated configuration onto the exact option names the shipped
 * drivers already understand. Only non-empty values override the shared OTP
 * settings, so a partially configured section can never send with someone
 * else's credentials by accident.
 */
function sh_support_sms_credentials(): array
{
    $map = [
        'textbee_api_key' => 'otp_textbee_api_key',
        'textbee_device_id' => 'otp_textbee_device_id',
        'api_url' => 'otp_api_url',
        'api_method' => 'otp_api_method',
        'api_body' => 'otp_api_body',
        'api_headers' => 'otp_api_headers',
        'api_auth' => 'otp_api_auth',
        'api_key' => 'otp_api_key',
        'api_secret' => 'otp_api_secret',
        'api_token' => 'otp_api_token',
        'api_username' => 'otp_api_username',
        'api_password' => 'otp_api_password',
        'phone_param' => 'otp_phone_param',
        'message_param' => 'otp_message_param',
        'success_field' => 'otp_success_field',
        'success_value' => 'otp_success_value',
        'sender_id' => 'otp_sender_id',
    ];
    $cfg = [];
    foreach ($map as $local => $driverKey) {
        $value = sh_support_sms_setting($local);
        if ($value !== '') { $cfg[$driverKey] = $value; }
    }
    return $cfg;
}

/** The actual carrier that will be contacted, after resolving "store" mode. */
function sh_support_sms_resolved_provider(): string
{
    $provider = sh_support_sms_setting('provider', 'offline');
    if ($provider !== 'store') { return $provider; }
    return function_exists('sh_otp_provider') ? (string)sh_otp_provider() : 'offline';
}

/** Cryptographically secure bytes, with OpenSSL as a fallback and no weak PRNG. */
function sh_support_sms_secure_bytes(int $length): string
{
    try { return random_bytes($length); }
    catch (Throwable $e) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length);
            if (is_string($bytes) && strlen($bytes) === $length) { return $bytes; }
        }
        throw new RuntimeException('Secure random source unavailable.');
    }
}

/** Short, secret-free reference for the secure Admin diagnostic log. */
function sh_support_sms_diagnostic_id(): string
{
    if (function_exists('sh_sms_diagnostic_id')) { return (string)sh_sms_diagnostic_id(); }
    try { return 'SMS-' . bin2hex(sh_support_sms_secure_bytes(4)); }
    catch (Throwable $e) {
        return 'SMS-' . substr(hash('sha256', microtime(true) . (string)getmypid() . __FILE__), 0, 8);
    }
}

/**
 * One-use per rendered Settings page. A successful/rejected Test SMS consumes
 * the nonce, so a refresh/back-button resubmission cannot send a duplicate.
 */
function sh_support_sms_test_nonce(): string
{
    if (function_exists('sh_session_start')) { sh_session_start(); }
    if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
    try {
        $nonce = bin2hex(sh_support_sms_secure_bytes(16));
    } catch (Throwable $e) {
        // Fail closed: without a secure nonce, duplicate Test SMS protection
        // cannot be guaranteed. The UI will show a useful expired-form error.
        $_SESSION['support_sms_test_nonces'] = [];
        $_SESSION['support_sms_test_locked'] = true;
        return '';
    }
    $nonces = $_SESSION['support_sms_test_nonces'] ?? [];
    $nonces = is_array($nonces) ? array_values(array_unique(array_map('strval', $nonces))) : [];
    $nonces[] = $nonce;
    $_SESSION['support_sms_test_nonces'] = array_slice($nonces, -3);
    $_SESSION['support_sms_test_locked'] = false;
    return $nonce;
}

function sh_support_sms_has_test_nonce(): bool
{
    if (!isset($_SESSION) || !is_array($_SESSION)) { return false; }
    return !empty($_SESSION['support_sms_test_locked'])
        || !empty($_SESSION['support_sms_test_nonces']);
}

function sh_support_sms_consume_test_nonce(string $nonce): bool
{
    if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
    if (!empty($_SESSION['support_sms_test_locked']) || $nonce === '') { return false; }
    $nonces = $_SESSION['support_sms_test_nonces'] ?? [];
    if (!is_array($nonces)) { return false; }
    foreach ($nonces as $index => $stored) {
        if (is_string($stored) && hash_equals($stored, $nonce)) {
            unset($nonces[$index]);
            $_SESSION['support_sms_test_nonces'] = array_values($nonces);
            $_SESSION['support_sms_test_locked'] = true;
            return true;
        }
    }
    return false;
}

function sh_support_sms_clear_test_nonces(): void
{
    if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
    $_SESSION['support_sms_test_nonces'] = [];
    $_SESSION['support_sms_test_locked'] = false;
}

/**
 * @return array{ok:bool,reason:string,provider:string,phone:string}
 */
function sh_support_sms_ready(): array
{
    $fail = static fn(string $reason, string $provider = '', string $phone = ''): array =>
        ['ok' => false, 'reason' => $reason, 'provider' => $provider, 'phone' => $phone];

    if (sh_support_sms_setting('enabled', '0') !== '1') {
        return $fail('disabled');
    }
    $phone = sh_support_sms_normalize_phone(sh_support_sms_setting('phone'));
    if ($phone === '') { return $fail('phone_missing', '', ''); }

    $selected = sh_support_sms_setting('provider', 'offline');
    if (!sh_support_sms_provider_allowed($selected)) { return $fail('provider_invalid', $selected, $phone); }
    $provider = sh_support_sms_resolved_provider();

    if ($provider === 'offline') { return $fail('offline', 'offline', $phone); }
    if ($provider === 'firebase') { return $fail('provider_unsupported', 'firebase', $phone); }
    if (!in_array($provider, ['textbee', 'generic_http'], true)) {
        return $fail('provider_unsupported', $provider, $phone);
    }
    if (!function_exists('curl_init')) {
        return $fail('curl_missing', $provider, $phone);
    }

    if ($selected === 'textbee') {
        $cfg = sh_support_sms_credentials();
        // Current TextBee uses an account-level endpoint; deviceId is optional.
        if (($cfg['otp_textbee_api_key'] ?? '') === '') {
            return $fail('credentials_missing', $provider, $phone);
        }
    } elseif ($selected === 'generic_http') {
        $cfg = sh_support_sms_credentials();
        if (($cfg['otp_api_url'] ?? '') === '') {
            return $fail('credentials_missing', $provider, $phone);
        }
        $url = (string)$cfg['otp_api_url'];
        if (preg_match('~^https://~i', $url) !== 1) {
            return $fail('insecure_endpoint', $provider, $phone);
        }
    } elseif ($selected === 'store') {
        // Shared OTP configuration is used verbatim; give a useful readiness
        // failure before attempting a real provider call.
        if ($provider === 'textbee' && trim((string)sh_setting('otp_textbee_api_key', '')) === '') {
            return $fail('store_credentials_missing', $provider, $phone);
        }
        if ($provider === 'generic_http') {
            $url = trim((string)sh_setting('otp_api_url', ''));
            if ($url === '') { return $fail('store_credentials_missing', $provider, $phone); }
            if (preg_match('~^https://~i', $url) !== 1) { return $fail('store_insecure_endpoint', $provider, $phone); }
        }
    }
    return ['ok' => true, 'reason' => 'ready', 'provider' => $provider, 'phone' => $phone];
}

function sh_support_sms_ready_message(string $reason): string
{
    return match ($reason) {
        'disabled' => 'Human-support SMS notifications are disabled. No message was sent.',
        'phone_missing' => 'Enter a valid support mobile number before testing or enabling SMS notifications.',
        'provider_invalid' => 'The selected SMS provider is not supported. Choose an integrable provider and save settings.',
        'offline' => 'Offline / test mode: no real SMS was sent. Choose a real provider to deliver notifications.',
        'provider_unsupported' => 'The store’s current SMS provider cannot send this text (Firebase Auth issues its own codes). Choose TextBee, a custom HTTPS SMS API, or configure one here.',
        'credentials_missing' => 'Provider credentials are incomplete. Enter the required fields and save settings.',
        'store_credentials_missing' => 'The store’s shared SMS provider credentials are incomplete. Configure them in Security → SMS/OTP Provider.',
        'insecure_endpoint' => 'The custom SMS API URL must use HTTPS. Update it and save settings.',
        'store_insecure_endpoint' => 'The store’s shared SMS API URL must use HTTPS. Update it in Security → SMS/OTP Provider.',
        'curl_missing' => 'PHP cURL is not enabled on this server. Ask the host to enable outbound HTTPS/cURL before testing SMS.',
        'ready' => 'Configuration looks complete. A real test SMS is still required to confirm provider acceptance; acceptance is not handset delivery.',
        default => 'SMS is not configured correctly. No message was sent.',
    };
}

/** Short, secret-free body. Reference only — never the private admin route. */
function sh_support_sms_message(int $conversationId, bool $test = false): string
{
    if ($test) {
        return 'ShopHaat human-support SMS test. No customer is waiting; no action needed.';
    }
    return 'ShopHaat: a customer is waiting for human support. Conversation #' . $conversationId
        . '. Sign in to your Admin Inbox to reply.';
}

/**
 * Remove anything that could be a credential, phone number or raw provider
 * payload before a string reaches a log. Raw secrets are never logged.
 */
function sh_support_sms_scrub(string $text): string
{
    $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
    if (function_exists('sh_scrub_secrets')) { $text = (string)sh_scrub_secrets($text); }
    $text = preg_replace('/\b(?:api[_-]?key|token|secret|password|authorization|bearer|x-api-key)\b\s*[:=]?\s*\S+/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/(?:[?&](?:key|token|api_key|secret|password|access_token)=)[^&\s]+/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted-token]', $text) ?? $text;
    $text = preg_replace('/\+?\d{8,15}/', '[phone]', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return mb_substr(trim($text), 0, 400);
}

/** Admin-only log: outcome and a redacted reason, never a secret or a body. */
function sh_support_sms_log(string $outcome, string $detail, ?int $conversationId = null): void
{
    $safe = sh_support_sms_scrub($detail);
    $line = 'Human-support SMS ' . $outcome
        . ($conversationId !== null && $conversationId > 0 ? ' conversation#' . $conversationId : '')
        . ': ' . $safe;
    if (function_exists('sh_log_line')) { sh_log_line('support-sms', $line); }
    if (function_exists('sh_security_log')) {
        sh_security_log('support_sms_' . $outcome, null, [
            'conversation_id' => $conversationId,
            'result' => $outcome,
            'detail' => mb_substr($safe, 0, 200),
        ]);
    }
    // Reuse the existing unified notification_logs table. The full engine is
    // not loaded on a customer handoff (it pulls in the mail/WhatsApp modules),
    // so write the same columns directly when sh_notify_log() is unavailable.
    $logChannel = in_array($outcome, ['sent', 'failed', 'skipped'], true) ? $outcome : 'failed';
    if (!function_exists('sh_table_exists') || !sh_table_exists('notification_logs')) { return; }
    try {
        if (function_exists('sh_notify_log')) {
            sh_notify_log(null, SH_SUPPORT_SMS_CHANNEL, SH_SUPPORT_SMS_EVENT,
                $logChannel, null, mb_substr($safe, 0, 300));
            return;
        }
        sh_insert('notification_logs', [
            'channel' => SH_SUPPORT_SMS_CHANNEL,
            'event' => SH_SUPPORT_SMS_EVENT,
            'recipient' => null,
            'status' => $logChannel,
            'error_message' => mb_substr($safe, 0, 500),
        ]);
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms-log'); }
    }
}

/** Detailed, scrubbed provider response for Admin → Security Logs and app-*.log. */
function sh_support_sms_log_provider_diagnostic(array $diagnostic, string $outcome, ?int $conversationId = null): string
{
    $allowed = [
        'id', 'provider', 'endpoint', 'method', 'auth', 'recipient', 'http_status',
        'curl_errno', 'curl_error', 'response', 'acceptance', 'ssl_verify',
        'timeout', 'duration_ms', 'code', 'error',
    ];
    $safe = [];
    foreach ($allowed as $key) {
        $value = $diagnostic[$key] ?? '';
        $safe[$key] = is_scalar($value) ? sh_support_sms_scrub((string)$value) : '';
    }
    $safe['id'] = preg_replace('/[^A-Za-z0-9-]/', '', (string)$safe['id']) ?: sh_support_sms_diagnostic_id();
    $safe['outcome'] = $outcome;
    $safe['conversation_id'] = $conversationId;
    $json = (string)json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (function_exists('sh_log_line')) { sh_log_line('support-sms-diagnostic', $json); }
    if (function_exists('sh_security_log')) {
        sh_security_log('support_sms_diagnostic', null, array_merge($safe, [
            'result' => $outcome,
            'detail' => mb_substr((string)($safe['acceptance'] ?: $safe['error'] ?: $outcome), 0, 200),
        ]));
    }
    return (string)$safe['id'];
}

/** Ensure every transport outcome has a diagnostic, even for custom/stub drivers. */
function sh_support_sms_transport_diagnostic(array $result, string $provider, string $phone, string $error): array
{
    $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
    $diagnostic += [
        'id' => sh_support_sms_diagnostic_id(),
        'provider' => $provider,
        'endpoint' => '',
        'method' => '',
        'auth' => '',
        'recipient' => '',
        'http_status' => 0,
        'curl_errno' => 0,
        'curl_error' => '',
        'response' => '',
        'acceptance' => '',
        'ssl_verify' => '',
        'timeout' => 0,
        'duration_ms' => 0,
        'code' => (string)($result['code'] ?? ''),
        'error' => $error,
    ];
    $masked = function_exists('sh_phone_mask') ? (string)sh_phone_mask($phone) : '[phone]';
    if ((string)$diagnostic['recipient'] === '') { $diagnostic['recipient'] = $masked; }
    return $diagnostic;
}

/**
 * Send one SMS. Never reports success from a stale flag or a queued state:
 * only an explicit ok=true from the live provider driver counts.
 *
 * @return array{ok:bool,status:string,error:string,provider:string,code:string,diagnostic_id:string}
 */
function sh_support_sms_send(string $message, ?int $conversationId = null): array
{
    $ready = sh_support_sms_ready();
    if (!$ready['ok']) {
        $status = $ready['reason'] === 'disabled' ? 'disabled' : 'skipped';
        $error = sh_support_sms_ready_message($ready['reason']);
        $diagnosticId = sh_support_sms_log_provider_diagnostic([
            'id' => sh_support_sms_diagnostic_id(),
            'provider' => $ready['provider'],
            'code' => $ready['reason'],
            'error' => $error,
            'acceptance' => 'Not attempted; configuration is incomplete.',
        ], $status === 'disabled' ? 'skipped' : 'failed', $conversationId);
        sh_support_sms_log($status === 'disabled' ? 'skipped' : 'failed',
            sh_support_sms_ready_message($ready['reason']) . ' [' . $diagnosticId . ']', $conversationId);
        return ['ok' => false, 'status' => $status, 'error' => $error, 'provider' => $ready['provider'],
            'code' => $ready['reason'], 'diagnostic_id' => $diagnosticId];
    }

    $provider = $ready['provider'];
    $phoneE164 = '+' . ltrim($ready['phone'], '+');
    // Dedicated credentials only when this section owns the configuration;
    // "store" mode deliberately passes no overrides so the shared OTP carrier
    // settings are used verbatim.
    $selected = sh_support_sms_setting('provider', 'offline');
    $cfg = in_array($selected, ['textbee', 'generic_http'], true) ? sh_support_sms_credentials() : [];
    try {
        if (function_exists('sh_sms_deliver')) {
            $res = sh_sms_deliver($provider, $phoneE164, $message, $cfg);
        } else {
            $res = ['ok' => false, 'error' => 'The SMS transport is not available on this install.', 'code' => 'transport_missing'];
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms'); }
        $res = ['ok' => false, 'error' => 'SMS provider failed unexpectedly.', 'code' => 'provider_exception'];
    }
    if (!is_array($res)) { $res = ['ok' => false, 'error' => 'SMS provider returned an invalid response.', 'code' => 'invalid_response']; }
    $error = sh_support_sms_scrub((string)($res['error'] ?? 'SMS provider did not accept the message.'));
    if ($error === '') { $error = 'SMS provider did not accept the message.'; }
    $code = (string)($res['code'] ?? (!empty($res['ok']) ? 'accepted' : 'provider_failed'));
    $diagnostic = sh_support_sms_transport_diagnostic($res, $provider, $phoneE164, $error);
    $diagnostic['code'] = $code;
    if (!empty($res['ok'])) {
        $diagnosticId = sh_support_sms_log_provider_diagnostic($diagnostic, 'accepted', $conversationId);
        sh_support_sms_log('sent', 'Provider ' . $provider . ' accepted the message [' . $diagnosticId . '].', $conversationId);
        return ['ok' => true, 'status' => 'sent', 'error' => '', 'provider' => $provider,
            'code' => $code, 'diagnostic_id' => $diagnosticId];
    }
    $diagnosticId = sh_support_sms_log_provider_diagnostic($diagnostic, 'failed', $conversationId);
    sh_support_sms_log('failed', 'Provider ' . $provider . ' rejected the message [' . $diagnosticId . ']: ' . $error, $conversationId);
    return ['ok' => false, 'status' => 'failed', 'error' => $error, 'provider' => $provider,
        'code' => $code, 'diagnostic_id' => $diagnosticId];
}

/**
 * Durable one-notification-per-cycle claim. Uses the existing
 * support_handoff_events UNIQUE(event_key) index; the same table already
 * serializes the one handoff confirmation. The seed is the AI -> human cycle
 * number, so the "Talk to a human" button and a message whose text asks for a
 * human converge on ONE claim. An explicit Resume AI increments the cycle and
 * permits exactly one new notification for a genuinely new request.
 */
function sh_support_sms_claim(int $conversationId, string $seed): bool
{
    if ($conversationId <= 0 || $seed === '') { return false; }
    $key = 'sms' . substr(hash('sha256', 'shophaat-support-sms|' . $conversationId . '|' . $seed), 0, 33);
    $existing = sh_one('SELECT id FROM support_handoff_events WHERE event_key = ? LIMIT 1', [$key]);
    if ($existing !== null) { return false; }
    try {
        sh_query('INSERT INTO support_handoff_events
            (conversation_id, event_key, actor_type, actor_admin_id, event, reason, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = id', [
                $conversationId, $key, 'system', null, 'sms_notification_claim',
                'One SMS per human-support request.', gmdate('Y-m-d H:i:s'),
            ]);
        return true;
    } catch (Throwable $e) {
        // A concurrent writer already claimed this exact request.
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms-claim'); }
        return false;
    }
}

/**
 * Called ONLY after a genuine new AI -> human handoff has committed. Retries,
 * refreshes and later customer messages never reach this function. Any failure
 * here is logged and swallowed: chat, inbox and AI state are unaffected.
 *
 * @return array{status:string,error:string}
 */
function sh_support_sms_notify_handoff(int $conversationId, string $eventKey): array
{
    try {
        if (sh_support_sms_setting('enabled', '0') !== '1') {
            return ['status' => 'disabled', 'error' => sh_support_sms_ready_message('disabled')];
        }
        // Canonical seed: ONE notification per AI -> human ownership cycle.
        // A request can arrive from the "Talk to a human" button OR from a
        // message whose text asks for a human; both paths must converge on the
        // same claim. Explicit Resume AI starts a new cycle and permits one
        // new notification for a genuinely new request after closure.
        $cycle = (int)sh_val(
            "SELECT COUNT(*) FROM support_handoff_events WHERE conversation_id = ? AND event IN ('resume_ai','ai_resumed')",
            [$conversationId], 0
        );
        if (!sh_support_sms_claim($conversationId, 'cycle-' . $cycle)) {
            return ['status' => 'duplicate', 'error' => 'Already notified for this request.'];
        }
        $result = sh_support_sms_send(sh_support_sms_message($conversationId), $conversationId);
        return ['status' => $result['status'], 'error' => $result['error']];
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms-handoff'); }
        sh_support_sms_log('failed', 'Notification could not be attempted: ' . get_class($e), $conversationId);
        return ['status' => 'failed', 'error' => 'Notification could not be attempted.'];
    }
}

/** Save the last test outcome so the settings page shows a clear, honest result. */
function sh_support_sms_record_test(array $result): void
{
    sh_support_sms_save_setting('test_ok', !empty($result['ok']) ? '1' : '0');
    sh_support_sms_save_setting('test_at', gmdate('Y-m-d H:i:s'));
    sh_support_sms_save_setting('test_message', mb_substr((string)($result['message'] ?? ''), 0, 400));
    sh_support_sms_save_setting('test_code', (string)($result['code'] ?? ''));
    sh_support_sms_save_setting('test_diagnostic_id', (string)($result['diagnostic_id'] ?? ''));
}

function sh_support_sms_clear_test(): void
{
    foreach (['test_ok', 'test_at', 'test_message', 'test_code', 'test_diagnostic_id'] as $k) {
        sh_support_sms_save_setting($k, '');
    }
    sh_support_sms_clear_test_nonces();
    sh_support_sms_log('skipped', 'Last SMS test result cleared by an authorized admin.');
}

/** Secret-free diagnostic hint shown next to the test result. */
function sh_support_sms_diagnostic_hint(string $id = ''): string
{
    return $id !== ''
        ? 'Diagnostic ' . $id . ': Admin → Security Logs → support_sms_diagnostic (secrets redacted).'
        : 'Provider HTTP status, cURL errors and redacted responses are available in Admin → Security Logs → support_sms_diagnostic.';
}

/**
 * Real provider test from the admin settings page. No code path here can claim
 * "SMS sent" without an accepted provider response. A one-use nonce from the
 * rendered form prevents refresh/back-button duplicate sends.
 */
function sh_support_sms_test(array $post = []): array
{
    if (sh_support_sms_has_test_nonce()) {
        $nonce = trim((string)($post['test_nonce'] ?? ''));
        if ($nonce === '' || !sh_support_sms_consume_test_nonce($nonce)) {
            $out = [
                'ok' => false,
                'code' => 'test_nonce_invalid',
                'diagnostic_id' => '',
                'message' => 'Test SMS was NOT sent. This form expired or was already used; reload Settings and try once.',
            ];
            sh_support_sms_record_test($out);
            return $out;
        }
    }
    try {
        $message = sh_support_sms_message(0, true);
        $result = sh_support_sms_send($message, null);
        $diagnosticId = (string)($result['diagnostic_id'] ?? '');
        if (!empty($result['ok'])) {
            $out = [
                'ok' => true,
                'code' => 'accepted',
                'diagnostic_id' => $diagnosticId,
                'message' => 'Test SMS accepted by ' . $result['provider']
                    . '. Provider acceptance is not proof of handset delivery; check the handset to confirm. '
                    . sh_support_sms_diagnostic_hint($diagnosticId),
            ];
        } else {
            $out = [
                'ok' => false,
                'code' => (string)($result['code'] ?? ($result['status'] === 'disabled' ? 'disabled' : 'not_sent')),
                'diagnostic_id' => $diagnosticId,
                'message' => 'Test SMS was NOT sent. ' . $result['error'] . ' '
                    . sh_support_sms_diagnostic_hint($diagnosticId),
            ];
        }
        sh_support_sms_record_test($out);
        return $out;
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms-test'); }
        $diagnosticId = sh_support_sms_log_provider_diagnostic([
            'id' => sh_support_sms_diagnostic_id(),
            'provider' => sh_support_sms_resolved_provider(),
            'code' => 'test_exception',
            'error' => get_class($e),
            'acceptance' => 'Unexpected server error before provider acceptance.',
        ], 'failed', null);
        $out = [
            'ok' => false,
            'code' => 'test_exception',
            'diagnostic_id' => $diagnosticId,
            'message' => 'Test SMS was NOT sent. Unexpected server error. ' . sh_support_sms_diagnostic_hint($diagnosticId),
        ];
        sh_support_sms_record_test($out);
        return $out;
    }
}

/** Fields the settings form is allowed to write; secret blanks keep old values. */
function sh_support_sms_fields(): array
{
    return [
        'enabled' => ['secret' => false],
        'phone' => ['secret' => false],
        'provider' => ['secret' => false],
        'sender_id' => ['secret' => false],
        'textbee_api_key' => ['secret' => true],
        'textbee_device_id' => ['secret' => true],
        'api_url' => ['secret' => false],
        'api_method' => ['secret' => false],
        'api_body' => ['secret' => true],
        'api_headers' => ['secret' => true],
        'api_auth' => ['secret' => false],
        'api_key' => ['secret' => true],
        'api_secret' => ['secret' => true],
        'api_token' => ['secret' => true],
        'api_username' => ['secret' => false],
        'api_password' => ['secret' => true],
        'phone_param' => ['secret' => false],
        'message_param' => ['secret' => false],
        'success_field' => ['secret' => false],
        'success_value' => ['secret' => false],
    ];
}

/**
 * @return array{ok:bool,errors:array<int,string>}
 */
function sh_support_sms_save(array $post): array
{
    try {
        $errors = [];
        $fields = sh_support_sms_fields();
        $enabled = isset($post['enabled']) && (string)$post['enabled'] === '1' ? '1' : '0';
        $provider = trim((string)($post['provider'] ?? 'offline'));
        if (!sh_support_sms_provider_allowed($provider)) {
            $errors[] = 'Choose one of the supported SMS providers.';
            $provider = 'offline';
        }
        $phoneRaw = trim((string)($post['phone'] ?? ''));
        $phone = $phoneRaw === '' ? '' : sh_support_sms_normalize_phone($phoneRaw);
        if ($phoneRaw !== '' && $phone === '') {
            $errors[] = 'The support mobile number is not valid. Use digits only, optionally with a country code.';
        }
        sh_support_sms_save_setting('enabled', $enabled);
        sh_support_sms_save_setting('phone', $phone);
        sh_support_sms_save_setting('provider', $provider);

        foreach ($fields as $key => $meta) {
            if (in_array($key, ['enabled', 'phone', 'provider'], true)) { continue; }
            if (!array_key_exists($key, $post)) { continue; }
            $value = trim((string)$post[$key]);
            if ($meta['secret'] && $value === '') { continue; } // blank keeps the stored secret
            if ($key === 'api_method' && !in_array($value, ['post_json', 'post_form', 'get'], true)) {
                $value = 'post_json';
            }
            if ($key === 'api_auth' && !in_array($value, ['', 'bearer', 'basic'], true)) {
                $value = '';
            }
            if ($key === 'api_url' && $value !== '' && preg_match('~^https://~i', $value) !== 1) {
                $errors[] = 'A custom SMS API URL must start with https://. The insecure value was not saved.';
                continue;
            }
            sh_support_sms_save_setting($key, mb_substr($value, 0, 2000));
        }

        // Changing configuration invalidates the previous test result: never show a
        // stale "connected" badge for credentials that are no longer in use.
        sh_support_sms_clear_test();
        return ['ok' => $errors === [], 'errors' => $errors];
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms-save'); }
        $diagnosticId = sh_support_sms_log_provider_diagnostic([
            'id' => sh_support_sms_diagnostic_id(),
            'provider' => sh_support_sms_resolved_provider(),
            'code' => 'settings_save_exception',
            'error' => get_class($e),
            'acceptance' => 'Settings were not saved.',
        ], 'failed', null);
        sh_support_sms_log('failed', 'Settings save failed [' . $diagnosticId . ']: ' . get_class($e));
        return ['ok' => false, 'errors' => [
            'SMS settings could not be saved because of an unexpected server error. No credentials were changed in this step. '
            . sh_support_sms_diagnostic_hint($diagnosticId),
        ]];
    }
}

/** Variables used by the compact Settings card; secrets are never returned. */
function sh_support_sms_ui_vars(): array
{
    $providers = sh_support_sms_providers();
    $selected = sh_support_sms_setting('provider', 'offline');
    if (!sh_support_sms_provider_allowed($selected)) { $selected = 'offline'; }
    return [
        'providers' => $providers,
        'selected' => $selected,
        'enabled' => sh_support_sms_setting('enabled', '0') === '1',
        'phone' => sh_support_sms_setting('phone'),
        'ready' => sh_support_sms_ready(),
        'ready_message' => sh_support_sms_ready_message(sh_support_sms_ready()['reason']),
        'store_provider' => function_exists('sh_otp_provider') ? (string)sh_otp_provider() : 'offline',
        'sender_id' => sh_support_sms_setting('sender_id', 'ShopHaat'),
        'textbee_api_key' => sh_support_sms_setting('textbee_api_key'),
        'textbee_device_id' => sh_support_sms_setting('textbee_device_id'),
        'api_url' => sh_support_sms_setting('api_url'),
        'api_method' => sh_support_sms_setting('api_method', 'post_json'),
        'api_body' => sh_support_sms_setting('api_body'),
        'api_headers' => sh_support_sms_setting('api_headers'),
        'api_auth' => sh_support_sms_setting('api_auth'),
        'api_key' => sh_support_sms_setting('api_key'),
        'api_secret' => sh_support_sms_setting('api_secret'),
        'api_token' => sh_support_sms_setting('api_token'),
        'api_username' => sh_support_sms_setting('api_username'),
        'api_password' => sh_support_sms_setting('api_password'),
        'phone_param' => sh_support_sms_setting('phone_param', 'phone'),
        'message_param' => sh_support_sms_setting('message_param', 'message'),
        'success_field' => sh_support_sms_setting('success_field'),
        'success_value' => sh_support_sms_setting('success_value'),
        'test_nonce' => sh_support_sms_test_nonce(),
        'test_ok' => sh_support_sms_setting('test_ok'),
        'test_at' => sh_support_sms_setting('test_at'),
        'test_message' => sh_support_sms_setting('test_message'),
        'test_code' => sh_support_sms_setting('test_code'),
        'test_diagnostic_id' => sh_support_sms_setting('test_diagnostic_id'),
    ];
}
