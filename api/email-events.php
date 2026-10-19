<?php
/**
 * Provider event webhook (delivered / bounced / blocked / complaint).
 *
 * Security rules for this endpoint, because it is reachable from the internet:
 *   - the request must carry the shared secret (constant-time compare) or a valid
 *     HMAC-SHA256 signature over the raw body;
 *   - only JSON is accepted, only a small allowlist of event names is acted on,
 *     and the payload is never evaluated, echoed back or used in a query;
 *   - the body size is capped, the method must be POST, and the event id makes
 *     replays idempotent;
 *   - with no secret configured the endpoint refuses every request, so an
 *     unconfigured store cannot be updated by an anonymous caller.
 *
 * It answers with a small JSON object and never leaks configuration details.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (!sh_is_locked()) {
    sh_json(['ok' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); } catch (Throwable $e) {
    sh_log_exception($e, 'email-webhook-db');
    sh_json(['ok' => false, 'error' => 'Service temporarily unavailable.'], 503);
}

require_once SH_ROOT . '/includes/mail.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sh_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$raw = (string)file_get_contents('php://input', false, null, 0, 262144);
if ($raw === '' || strlen($raw) > 262144) {
    sh_json(['ok' => false, 'error' => 'Empty or oversized payload.'], 413);
}

// Rate limit by client IP so a broken provider retry loop cannot hammer the DB.
$ip = function_exists('sh_client_ip') ? (string)sh_client_ip() : '';
if ($ip !== '' && !sh_file_rate_limit('email_webhook', $ip, 120, 60)) {
    sh_json(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
    }
}

$verify = sh_email_webhook_verify($raw, $headers, $_GET);
if (empty($verify['ok'])) {
    // Log the refusal without the payload: it may contain customer addresses.
    sh_log_line('mail', 'Webhook refused from ' . ($ip !== '' ? $ip : 'unknown') . ': ' . (string)($verify['error'] ?? 'signature'));
    sh_json(['ok' => false, 'error' => 'Signature verification failed.'], 401);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    sh_json(['ok' => false, 'error' => 'Malformed JSON.'], 400);
}

// Brevo sends lists of events; other providers send a single object.
$events = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];
$applied = 0;
$statuses = [];
foreach (array_slice($events, 0, 50) as $event) {
    if (!is_array($event)) { continue; }
    $result = sh_email_event_apply($event, (string)($headers['x-event-id'] ?? ''));
    if (!empty($result['ok'])) {
        $applied += (int)$result['applied'];
        if ((string)$result['status'] !== '') { $statuses[] = (string)$result['status']; }
    }
}

sh_log_line('mail', 'Webhook accepted from ' . ($ip !== '' ? $ip : 'unknown') . ': ' . $applied . ' row(s) updated');
sh_json(['ok' => true, 'applied' => $applied, 'statuses' => array_values(array_unique($statuses))]);
