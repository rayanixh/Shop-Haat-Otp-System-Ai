<?php
/**
 * Telegram Bot webhook — admin control panel.
 *
 *   POST — Telegram update (message or callback_query)
 *   GET  — liveness probe only (Telegram never GETs this URL)
 *
 * Contract: answer fast, never emit a 500, never leak the bot token or any
 * internal detail. Telegram retries anything that is not acknowledged, so we
 * acknowledge first and process afterwards.
 *
 * Register with:  https://your-domain.com/telegram-webhook.php
 */
declare(strict_types=1);

define('SH_JSON_CONTEXT', true);

// Final safety net: a fatal below still returns 200 rather than an error page.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        error_log('ShopHaat telegram webhook fatal: ' . $e['message']);
        echo 'OK';
    }
});

function sh_tgwh_respond(int $code, string $body = ''): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo $body;
    exit;
}

try {
    require_once __DIR__ . '/config/config.php';
} catch (Throwable $e) {
    error_log('ShopHaat telegram webhook bootstrap failure: ' . $e->getMessage());
    sh_tgwh_respond(200, 'OK');
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    sh_tgwh_respond(405, 'Method Not Allowed');
}

if (sh_db_config() === null || !sh_is_locked()) {
    error_log('ShopHaat telegram webhook called before installation completed.');
    sh_tgwh_respond($method === 'POST' ? 200 : 503, $method === 'POST' ? 'OK' : 'Not installed');
}

try {
    sh_db();
    require_once SH_ROOT . '/includes/telegram.php';
} catch (Throwable $e) {
    // Acknowledge POSTs so Telegram stops retrying while the DB is down.
    error_log('ShopHaat telegram webhook dependency failure: ' . $e->getMessage());
    sh_tgwh_respond($method === 'POST' ? 200 : 503, $method === 'POST' ? 'OK' : 'Service unavailable');
}

/* ------------------------------------------------------------------ *
 * GET — liveness only, never reveals configuration
 * ------------------------------------------------------------------ */
if ($method === 'GET') {
    sh_tgwh_respond(200, 'ShopHaat Telegram webhook is active.');
}

/* ------------------------------------------------------------------ *
 * POST — a Telegram update
 * ------------------------------------------------------------------ */

$declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > 1048576) { // 1 MB is far beyond any legitimate update
    sh_tg_log('Rejected oversized update (' . $declared . ' bytes).', true);
    sh_tgwh_respond(413, 'Payload too large');
}

// Telegram echoes the secret we registered with setWebhook.
$expectedSecret = sh_tg_webhook_secret();
if ($expectedSecret !== '') {
    $got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($got === '' || !hash_equals($expectedSecret, $got)) {
        sh_tg_log('Rejected update with a bad or missing secret token.', true);
        sh_tgwh_respond(403, 'Forbidden');
    }
}

$raw = file_get_contents('php://input');
if ($raw === false) { $raw = ''; }
if (strlen($raw) > 1048576) {
    sh_tgwh_respond(413, 'Payload too large');
}

$update = json_decode($raw, true);
if (!is_array($update)) {
    // Not retryable — acknowledge so Telegram does not loop.
    sh_tg_log('Malformed JSON update ignored (' . strlen($raw) . ' bytes).', true);
    sh_tgwh_respond(200, 'OK');
}

// Acknowledge Telegram before doing the work, where the SAPI allows it.
$flush = static function (): void {
    if (headers_sent()) { return; }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Connection: close');
    $out = 'OK';
    header('Content-Length: ' . strlen($out));
    echo $out;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        set_error_handler(static fn(): bool => true);
        try { while (ob_get_level() > 0) { ob_end_flush(); } } finally { restore_error_handler(); }
        flush();
    }
};

try {
    $flush();
    sh_tg_process_update($update);
} catch (Throwable $e) {
    try { sh_log_exception($e, 'telegram-webhook'); } catch (Throwable $ignored) {}
    if (!headers_sent()) { sh_tgwh_respond(200, 'OK'); }
}

exit;
