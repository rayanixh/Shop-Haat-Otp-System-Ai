<?php
/**
 * Meta WhatsApp Cloud API — configuration, webhook parsing and storage.
 *
 * Design notes:
 *  - The access token is stored encrypted at rest and is NEVER echoed to a
 *    browser, written to a log, or included in an API response.
 *  - Every parser is defensive: Meta payload fields are all treated as optional.
 *  - Nothing in here throws out to the caller; the webhook must always answer 200.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const SH_WA_DEFAULT_API_VERSION = 'v21.0';

/* ------------------------------------------------------------------ *
 * Secret storage
 * ------------------------------------------------------------------ */

/**
 * Key used to encrypt secrets at rest. Derived from the database credentials,
 * which live in config/database.php outside the web root's reach.
 */
function sh_wa_secret_key(): string
{
    $cfg = sh_db_config() ?? [];
    return hash('sha256', 'shophaat|' . ($cfg['name'] ?? '') . '|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''), true);
}

/** Encrypt a secret for storage. Falls back to plain text only if OpenSSL is absent. */
function sh_wa_encrypt(string $plain): string
{
    if ($plain === '') { return ''; }
    if (!function_exists('openssl_encrypt')) { return 'plain:' . $plain; }
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', sh_wa_secret_key(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) { return 'plain:' . $plain; }
    return 'enc:' . base64_encode($iv . $ct);
}

/** Decrypt a stored secret. Accepts legacy plain values transparently. */
function sh_wa_decrypt(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') { return ''; }
    if (str_starts_with($stored, 'plain:')) { return substr($stored, 6); }
    if (!str_starts_with($stored, 'enc:')) { return $stored; } // saved before encryption existed
    if (!function_exists('openssl_decrypt')) { return ''; }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) <= 16) { return ''; }
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', sh_wa_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}

/* ------------------------------------------------------------------ *
 * Configuration
 * ------------------------------------------------------------------ */

/**
 * Full WhatsApp configuration.
 * The access token is returned ONLY when $withSecrets is true (server-side use).
 */
function sh_wa_config(bool $withSecrets = false): array
{
    $cfg = [
        'enabled'          => (string)sh_setting('whatsapp_enabled', '0') === '1',
        'phone_number_id'  => trim((string)sh_setting('whatsapp_phone_id', '')),
        'business_id'      => trim((string)sh_setting('whatsapp_business_id', '')),
        'api_version'      => trim((string)sh_setting('whatsapp_api_version', SH_WA_DEFAULT_API_VERSION)) ?: SH_WA_DEFAULT_API_VERSION,
        'recipient'        => trim((string)sh_setting('whatsapp_recipient', '')),
        'verify_token'     => trim((string)sh_setting('whatsapp_verify_token', '')),
        'app_secret'       => '',
        'has_token'        => trim((string)sh_setting('whatsapp_token', '')) !== '',
        'has_verify_token' => trim((string)sh_setting('whatsapp_verify_token', '')) !== '',
        'debug'            => (string)sh_setting('whatsapp_webhook_debug', '0') === '1',
    ];
    if ($withSecrets) {
        $cfg['access_token'] = sh_wa_decrypt((string)sh_setting('whatsapp_token', ''));
        $cfg['app_secret']   = sh_wa_decrypt((string)sh_setting('whatsapp_app_secret', ''));
    }
    return $cfg;
}

/** The access token, server-side only. Never expose the return value. */
function sh_wa_access_token(): string
{
    return sh_wa_decrypt((string)sh_setting('whatsapp_token', ''));
}

/** Public callback URL to register with Meta. */
function sh_wa_webhook_url(): string
{
    return rtrim(sh_site_url(), '/') . '/webhook.php';
}

/** Generate a strong verify token (used by the admin "generate" action). */
function sh_wa_generate_verify_token(): string
{
    return 'shophaat_' . bin2hex(random_bytes(16));
}

/* ------------------------------------------------------------------ *
 * Logging (never records secrets)
 * ------------------------------------------------------------------ */

/** Remove anything token-shaped before a line is written anywhere. */
function sh_wa_redact(string $text): string
{
    $cfg = sh_wa_config(true);
    foreach ([$cfg['access_token'] ?? '', $cfg['app_secret'] ?? '', $cfg['verify_token']] as $secret) {
        if (is_string($secret) && strlen($secret) >= 8) {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }
    // Long Meta-style bearer tokens.
    $text = preg_replace('/\bEAA[A-Za-z0-9_\-]{20,}/', '[redacted]', $text) ?? $text;
    return function_exists('sh_scrub_secrets') ? sh_scrub_secrets($text) : $text;
}

/** Webhook diagnostic log. Only writes when debug mode is on, except for errors. */
function sh_wa_log(string $message, bool $isError = false): void
{
    if (!$isError && (string)sh_setting('whatsapp_webhook_debug', '0') !== '1') { return; }
    sh_log_line('whatsapp', sh_wa_redact($message));
}

/** Remember the last webhook event / error for the admin status panel. */
function sh_wa_touch(string $key, string $value): void
{
    try { sh_setting_save($key, sh_wa_redact($value)); } catch (Throwable $e) { /* never fatal */ }
}

/* ------------------------------------------------------------------ *
 * Payload helpers — every field is treated as optional
 * ------------------------------------------------------------------ */

/** Safely read a nested array path, e.g. sh_wa_get($p, 'entry.0.changes.0.value'). */
function sh_wa_get($data, string $path, $default = null)
{
    $node = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($node) && array_key_exists($seg, $node)) { $node = $node[$seg]; continue; }
        return $default;
    }
    return $node;
}

/** Keep only digits from a phone number, capped to a sane length. */
function sh_wa_clean_phone($value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    return substr($digits, 0, 24);
}

/** Convert a Meta unix timestamp to a DATETIME string. */
function sh_wa_timestamp($value): ?string
{
    $ts = (int)$value;
    if ($ts <= 0) { return null; }
    if ($ts > 32503680000) { $ts = (int)($ts / 1000); } // milliseconds
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Reduce any supported message type to readable text.
 * Unknown types return a short descriptor rather than failing.
 */
function sh_wa_extract_text(array $m): string
{
    $type = (string)($m['type'] ?? 'unknown');
    switch ($type) {
        case 'text':     return (string)sh_wa_get($m, 'text.body', '');
        case 'button':   return trim((string)sh_wa_get($m, 'button.text', '') . ' ' . (string)sh_wa_get($m, 'button.payload', ''));
        case 'interactive':
            $r = sh_wa_get($m, 'interactive.button_reply.title')
              ?? sh_wa_get($m, 'interactive.list_reply.title')
              ?? sh_wa_get($m, 'interactive.nfm_reply.body');
            return (string)($r ?? '[interactive reply]');
        case 'reaction': return trim('Reacted ' . (string)sh_wa_get($m, 'reaction.emoji', ''));
        case 'location':
            $lat = sh_wa_get($m, 'location.latitude'); $lng = sh_wa_get($m, 'location.longitude');
            $name = (string)sh_wa_get($m, 'location.name', '');
            return trim($name . ' (' . (string)$lat . ', ' . (string)$lng . ')');
        case 'contacts':
            $n = sh_wa_get($m, 'contacts.0.name.formatted_name', '');
            return 'Shared contact' . ($n ? ': ' . $n : '');
        case 'image': case 'video': case 'audio': case 'document': case 'sticker':
            $cap = (string)sh_wa_get($m, $type . '.caption', '');
            $fn  = (string)sh_wa_get($m, $type . '.filename', '');
            $bit = $cap !== '' ? $cap : $fn;
            return '[' . $type . ']' . ($bit !== '' ? ' ' . $bit : '');
        case 'order':    return '[order] ' . (string)sh_wa_get($m, 'order.catalog_id', '');
        case 'system':   return (string)sh_wa_get($m, 'system.body', '[system message]');
        case 'unsupported':
            return '[unsupported message] ' . (string)sh_wa_get($m, 'errors.0.title', '');
        default:         return '[' . $type . ']';
    }
}

/** Trim stored payloads so a huge message can never blow up the row. */
function sh_wa_payload_json(array $data, int $max = 60000): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) { return '{}'; }
    $json = sh_wa_redact($json);
    return strlen($json) > $max ? substr($json, 0, $max) : $json;
}

/* ------------------------------------------------------------------ *
 * Storage
 * ------------------------------------------------------------------ */

/**
 * Store one inbound message. Idempotent: a repeated message_id updates nothing
 * and reports 'duplicate'.
 *
 * @return array{ok:bool,result:string,error?:string}
 */
function sh_wa_store_message(array $message, array $contacts = [], array $meta = []): array
{
    $waId = trim((string)($message['id'] ?? ''));
    if ($waId === '') { return ['ok' => false, 'result' => 'skipped', 'error' => 'Message has no id.']; }

    $from = sh_wa_clean_phone($message['from'] ?? '');
    $name = '';
    foreach ($contacts as $c) {
        if (!is_array($c)) { continue; }
        if (sh_wa_clean_phone($c['wa_id'] ?? '') === $from || $name === '') {
            $name = (string)sh_wa_get($c, 'profile.name', '');
            if (sh_wa_clean_phone($c['wa_id'] ?? '') === $from) { break; }
        }
    }

    $row = [
        'message_id'   => substr($waId, 0, 190),
        'phone_number' => $from,
        'contact_name' => $name !== '' ? substr($name, 0, 190) : null,
        'message_type' => substr((string)($message['type'] ?? 'unknown'), 0, 40),
        'message_text' => sh_wa_extract_text($message),
        'direction'    => 'inbound',
        'status'       => 'received',
        'wa_timestamp' => sh_wa_timestamp($message['timestamp'] ?? 0),
        'raw_payload'  => sh_wa_payload_json(['message' => $message, 'metadata' => $meta]),
    ];

    try {
        // INSERT IGNORE relies on the UNIQUE key, so concurrent retries are safe.
        $pdo = sh_db();
        $st = $pdo->prepare(
            'INSERT IGNORE INTO whatsapp_messages
             (message_id, phone_number, contact_name, message_type, message_text,
              direction, status, wa_timestamp, raw_payload)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $row['message_id'], $row['phone_number'], $row['contact_name'], $row['message_type'],
            $row['message_text'], $row['direction'], $row['status'], $row['wa_timestamp'], $row['raw_payload'],
        ]);
        if ($st->rowCount() === 0) {
            return ['ok' => true, 'result' => 'duplicate'];
        }
        return ['ok' => true, 'result' => 'stored'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'whatsapp-store-message');
        return ['ok' => false, 'result' => 'error', 'error' => 'Database write failed.'];
    }
}

/**
 * Store a delivery status and mirror it onto the message row.
 * Idempotent through UNIQUE (message_id, status).
 */
function sh_wa_store_status(array $status): array
{
    $waId = trim((string)($status['id'] ?? ''));
    $state = trim((string)($status['status'] ?? ''));
    if ($waId === '' || $state === '') {
        return ['ok' => false, 'result' => 'skipped', 'error' => 'Status missing id or state.'];
    }

    try {
        $pdo = sh_db();
        $st = $pdo->prepare(
            'INSERT IGNORE INTO whatsapp_message_statuses
             (message_id, status, recipient_id, error_code, error_title, wa_timestamp, raw_payload)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([
            substr($waId, 0, 190),
            substr($state, 0, 30),
            sh_wa_clean_phone($status['recipient_id'] ?? '') ?: null,
            substr((string)sh_wa_get($status, 'errors.0.code', ''), 0, 30) ?: null,
            substr((string)sh_wa_get($status, 'errors.0.title', ''), 0, 255) ?: null,
            sh_wa_timestamp($status['timestamp'] ?? 0),
            sh_wa_payload_json(['status' => $status]),
        ]);
        $fresh = $st->rowCount() > 0;

        // Keep the message row's status in step when we know about that message.
        $upd = $pdo->prepare('UPDATE whatsapp_messages SET status = ? WHERE message_id = ?');
        $upd->execute([substr($state, 0, 30), substr($waId, 0, 190)]);

        return ['ok' => true, 'result' => $fresh ? 'stored' : 'duplicate'];
    } catch (Throwable $e) {
        sh_log_exception($e, 'whatsapp-store-status');
        return ['ok' => false, 'result' => 'error', 'error' => 'Database write failed.'];
    }
}

/* ------------------------------------------------------------------ *
 * Webhook processing
 * ------------------------------------------------------------------ */

/**
 * Verify Meta's X-Hub-Signature-256 header when an app secret is configured.
 * Returns true when no secret is set (verification optional but recommended).
 */
function sh_wa_verify_signature(string $rawBody, ?string $header): bool
{
    $secret = sh_wa_decrypt((string)sh_setting('whatsapp_app_secret', ''));
    if ($secret === '') { return true; }
    $header = trim((string)$header);
    if (!str_starts_with($header, 'sha256=')) { return false; }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, substr($header, 7));
}

/**
 * Walk a decoded Meta payload and persist everything it contains.
 * Never throws; returns a small summary for logging.
 */
function sh_wa_process_payload(array $payload): array
{
    $summary = ['messages' => 0, 'duplicates' => 0, 'statuses' => 0, 'errors' => 0, 'ids' => []];

    $entries = $payload['entry'] ?? [];
    if (!is_array($entries)) { return $summary; }

    foreach ($entries as $entry) {
        if (!is_array($entry)) { continue; }
        $changes = $entry['changes'] ?? [];
        if (!is_array($changes)) { continue; }

        foreach ($changes as $change) {
            if (!is_array($change)) { continue; }
            $value = $change['value'] ?? [];
            if (!is_array($value)) { continue; }

            $meta     = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
            $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];

            foreach ((is_array($value['messages'] ?? null) ? $value['messages'] : []) as $m) {
                if (!is_array($m)) { continue; }
                $r = sh_wa_store_message($m, $contacts, $meta);
                if (!$r['ok']) { $summary['errors']++; }
                elseif ($r['result'] === 'duplicate') { $summary['duplicates']++; }
                else { $summary['messages']++; }
                if (!empty($m['id'])) { $summary['ids'][] = (string)$m['id']; }
            }

            foreach ((is_array($value['statuses'] ?? null) ? $value['statuses'] : []) as $st) {
                if (!is_array($st)) { continue; }
                $r = sh_wa_store_status($st);
                if (!$r['ok']) { $summary['errors']++; }
                elseif ($r['result'] === 'stored') { $summary['statuses']++; }
            }

            // Meta also reports errors at value level; record them without failing.
            foreach ((is_array($value['errors'] ?? null) ? $value['errors'] : []) as $err) {
                if (!is_array($err)) { continue; }
                sh_wa_log('Payload error ' . (string)($err['code'] ?? '') . ': ' . (string)($err['title'] ?? ''), true);
            }
        }
    }
    return $summary;
}

/* ------------------------------------------------------------------ *
 * Diagnostics
 * ------------------------------------------------------------------ */

/** Health report for the admin panel. Contains no secrets. */
function sh_wa_health(): array
{
    $cfg = sh_wa_config();
    $checks = [];

    $checks[] = ['label' => 'PHP version', 'ok' => PHP_VERSION_ID >= 80100, 'value' => PHP_VERSION];
    $checks[] = ['label' => 'webhook.php present', 'ok' => is_file(SH_ROOT . '/webhook.php'), 'value' => is_file(SH_ROOT . '/webhook.php') ? 'Found' : 'Missing'];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || str_starts_with(sh_site_url(), 'https://');
    $checks[] = ['label' => 'HTTPS', 'ok' => $https, 'value' => $https ? 'Enabled' : 'Not detected — Meta requires HTTPS'];

    $checks[] = ['label' => 'JSON extension', 'ok' => function_exists('json_decode'), 'value' => function_exists('json_decode') ? 'Available' : 'Missing'];
    $checks[] = ['label' => 'cURL or stream fallback', 'ok' => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
                 'value' => function_exists('curl_init') ? 'cURL' : ((bool)ini_get('allow_url_fopen') ? 'streams' : 'None')];
    $checks[] = ['label' => 'OpenSSL (token encryption)', 'ok' => function_exists('openssl_encrypt'),
                 'value' => function_exists('openssl_encrypt') ? 'Available' : 'Missing — tokens stored unencrypted'];

    $dbOk = false; $dbNote = 'Not connected';
    try { sh_db(); $dbOk = true; $dbNote = 'Connected'; } catch (Throwable $e) { $dbNote = 'Unavailable'; }
    $checks[] = ['label' => 'Database connection', 'ok' => $dbOk, 'value' => $dbNote];

    $tablesOk = false;
    if ($dbOk) {
        try {
            $n = (int)sh_val("SELECT COUNT(*) FROM information_schema.tables
                              WHERE table_schema = DATABASE()
                                AND table_name IN ('whatsapp_messages','whatsapp_message_statuses')", [], 0);
            $tablesOk = $n === 2;
        } catch (Throwable $e) { $tablesOk = false; }
    }
    $checks[] = ['label' => 'Webhook tables', 'ok' => $tablesOk, 'value' => $tablesOk ? 'Present' : 'Missing — re-run the installer'];

    $checks[] = ['label' => 'Verify token set', 'ok' => $cfg['has_verify_token'], 'value' => $cfg['has_verify_token'] ? 'Configured' : 'Not set'];
    $checks[] = ['label' => 'Access token set', 'ok' => $cfg['has_token'], 'value' => $cfg['has_token'] ? 'Stored (encrypted)' : 'Not set'];
    $checks[] = ['label' => 'Phone number ID', 'ok' => $cfg['phone_number_id'] !== '', 'value' => $cfg['phone_number_id'] !== '' ? 'Configured' : 'Not set'];
    $checks[] = ['label' => 'Logs directory writable', 'ok' => is_writable(SH_LOG_DIR), 'value' => is_writable(SH_LOG_DIR) ? 'Writable' : 'Not writable'];

    return $checks;
}

/**
 * Call Meta's Graph API to confirm the credentials really work.
 * Returns a safe result — the token is never included.
 */
function sh_wa_test_connection(): array
{
    $cfg = sh_wa_config(true);
    if ($cfg['phone_number_id'] === '') { return ['ok' => false, 'error' => 'Phone Number ID is not set.']; }
    if (($cfg['access_token'] ?? '') === '') { return ['ok' => false, 'error' => 'Access Token is not set.']; }

    $url = 'https://graph.facebook.com/' . rawurlencode($cfg['api_version'])
         . '/' . rawurlencode($cfg['phone_number_id'])
         . '?fields=display_phone_number,verified_name,quality_rating';

    $body = null; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['access_token']],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) { return ['ok' => false, 'error' => 'Connection failed: ' . sh_wa_redact($err ?: 'unknown')]; }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
            'header' => 'Authorization: Bearer ' . $cfg['access_token'],
        ]]);
        set_error_handler(static fn(): bool => true);
        try { $body = file_get_contents($url, false, $ctx); } finally { restore_error_handler(); }
        if ($body === false) { return ['ok' => false, 'error' => 'Connection failed.']; }
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) {
            $code = (int)$mm[1];
        }
    }

    $json = json_decode((string)$body, true);
    if ($code >= 200 && $code < 300 && is_array($json)) {
        return [
            'ok' => true,
            'number' => (string)($json['display_phone_number'] ?? ''),
            'name'   => (string)($json['verified_name'] ?? ''),
            'quality'=> (string)($json['quality_rating'] ?? ''),
        ];
    }
    $msg = is_array($json) ? (string)sh_wa_get($json, 'error.message', '') : '';
    return ['ok' => false, 'error' => sh_wa_redact($msg !== '' ? $msg : 'Meta returned HTTP ' . $code)];
}
