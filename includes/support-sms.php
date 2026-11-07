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
 *   - the existing sh_log_line()/sh_security_log() admin-only error logging.
 *
 * Security rules enforced here:
 *   - provider secrets are read and used server-side ONLY; nothing is rendered
 *     into public HTML/JS and nothing is written to a log,
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
            'note' => 'TextBee gateway API key + device ID, sent over HTTPS with cURL.',
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

    if ($provider === 'offline') {
        return $fail('offline', 'offline', $phone);
    }
    if ($provider === 'firebase') {
        return $fail('provider_unsupported', 'firebase', $phone);
    }
    if (!in_array($provider, ['textbee', 'generic_http'], true)) {
        return $fail('provider_unsupported', $provider, $phone);
    }
    if ($selected === 'textbee') {
        $cfg = sh_support_sms_credentials();
        if (($cfg['otp_textbee_api_key'] ?? '') === '' || ($cfg['otp_textbee_device_id'] ?? '') === '') {
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
    }
    // In "store" mode the shared OTP configuration is used verbatim; its own
    // drivers validate credentials and report failures honestly.
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
        'insecure_endpoint' => 'The custom SMS API URL must use HTTPS. Update it and save settings.',
        'ready' => 'Configuration looks complete. A real test SMS is still required to confirm delivery.',
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
 * Remove anything that could be a credential or raw provider payload before a
 * string reaches a log. Logging raw provider responses is never allowed.
 */
function sh_support_sms_scrub(string $text): string
{
    $text = function_exists('sh_scrub_secrets') ? (string)sh_scrub_secrets($text) : $text;
    $text = preg_replace('/\b(?:api[_-]?key|token|secret|password|authorization|bearer|x-api-key)\b\s*[:=]?\s*\S+/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/(?:[?&](?:key|token|api_key|secret|password|access_token)=)[^&\s]+/i', '[redacted]', $text) ?? $text;
    $text = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted-token]', $text) ?? $text;
    return mb_substr(trim($text), 0, 400);
}

/** Admin-only log: outcome and a redacted reason, never a secret or a body. */
function sh_support_sms_log(string $outcome, string $detail, ?int $conversationId = null): void
{
    $safe = sh_support_sms_scrub($detail);
    $line = 'Human-support SMS ' . $outcome
        . ($conversationId !== null && $conversationId > 0 ? ' conversation#' . $conversationId : '')
        . ': ' . $safe;
    sh_log_line('support-sms', $line);
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

/**
 * Send one SMS. Never reports success from a stale flag or a queued state:
 * only an explicit ok=true from the live provider driver counts.
 *
 * @return array{ok:bool,status:string,error:string,provider:string}
 */
function sh_support_sms_send(string $message, ?int $conversationId = null): array
{
    $ready = sh_support_sms_ready();
    if (!$ready['ok']) {
        $status = $ready['reason'] === 'disabled' ? 'disabled' : 'skipped';
        sh_support_sms_log($status === 'disabled' ? 'skipped' : 'failed',
            sh_support_sms_ready_message($ready['reason']), $conversationId);
        return ['ok' => false, 'status' => $status,
            'error' => sh_support_sms_ready_message($ready['reason']), 'provider' => $ready['provider']];
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
        } elseif (function_exists('sh_otp_send')) {
            $res = sh_otp_send($provider, $phoneE164, $message);
        } else {
            $res = ['ok' => false, 'error' => 'The SMS transport is not available on this install.'];
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) { sh_log_exception($e, 'support-sms'); }
        $res = ['ok' => false, 'error' => 'SMS provider error.'];
    }
    if (!is_array($res)) { $res = ['ok' => false, 'error' => 'SMS provider returned an invalid response.']; }

    if (!empty($res['ok'])) {
        sh_support_sms_log('sent', 'Provider ' . $provider . ' accepted the message.', $conversationId);
        return ['ok' => true, 'status' => 'sent', 'error' => '', 'provider' => $provider];
    }
    $error = sh_support_sms_scrub((string)($res['error'] ?? 'SMS provider did not accept the message.'));
    sh_support_sms_log('failed', 'Provider ' . $provider . ' rejected the message: ' . $error, $conversationId);
    return ['ok' => false, 'status' => 'failed',
        'error' => $error !== '' ? $error : 'SMS provider did not accept the message.', 'provider' => $provider];
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
}

function sh_support_sms_clear_test(): void
{
    foreach (['test_ok', 'test_at', 'test_message', 'test_code'] as $k) {
        sh_support_sms_save_setting($k, '');
    }
    sh_support_sms_log('skipped', 'Last SMS test result cleared by an authorized admin.');
}

/**
 * Real provider test from the admin settings page. No code path here can claim
 * "SMS sent" without an accepted provider response.
 */
function sh_support_sms_test(): array
{
    $message = sh_support_sms_message(0, true);
    $result = sh_support_sms_send($message, null);
    if (!empty($result['ok'])) {
        $out = [
            'ok' => true,
            'code' => 'accepted',
            'message' => 'Test SMS accepted by ' . $result['provider']
                . '. Provider acceptance is not proof of handset delivery; check the handset to confirm.',
        ];
    } else {
        $out = [
            'ok' => false,
            'code' => $result['status'] === 'disabled' ? 'disabled' : 'not_sent',
            'message' => 'Test SMS was NOT sent. ' . $result['error'],
        ];
    }
    sh_support_sms_record_test($out);
    return $out;
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
        'api_body' => ['secret' => false],
        'api_headers' => ['secret' => false],
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
            $errors[] = 'A custom SMS API URL must start with https://. The value was saved but SMS will stay disabled for this endpoint.';
        }
        sh_support_sms_save_setting($key, mb_substr($value, 0, 2000));
    }

    // Changing configuration invalidates the previous test result: never show a
    // stale "connected" badge for credentials that are no longer in use.
    sh_support_sms_clear_test();
    return ['ok' => $errors === [], 'errors' => $errors];
}
