<?php
declare(strict_types=1);

/**
 * Central PHP error handling and application logging.
 *
 * This file is loaded at the very start of the application bootstrap, so it
 * deliberately has no dependencies on the rest of the ShopHaat helpers.
 */

/** Redact credentials and one-time secrets before writing diagnostics to disk. */
function sh_scrub_secrets(string $text): string
{
    $keys = '(?:password|passwd|pass|pwd|secret|token|access[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret|api[_-]?key|apikey|authorization|auth|signature|webhook[_-]?token|verify[_-]?token|bot[_-]?token|csrf[_-]?token|session[_-]?(?:id|token)|otp|private[_-]?key|credential)';

    // Handle authorization headers first so the whole "Bearer token" value is removed.
    $text = preg_replace(
        '~(authorization\s*[:=]\s*)(?:"?\s*)?(?:bearer|basic)\s+[^\s"\',;]+"?~i',
        '$1[redacted]',
        $text
    ) ?? $text;
    $text = preg_replace('~\b(?:bearer|basic)\s+[^\s"\',;]+~i', '[redacted]', $text) ?? $text;

    // Redact key/value pairs in query strings, config snippets, JSON, and DSNs.
    $pattern = '~(["\']?\b' . $keys . '\b["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&,;]+)~i';
    return preg_replace($pattern, '$1[redacted]', $text) ?? $text;
}

/** Return a safe, project-relative source location for logs. */
function sh_error_location(string $file): string
{
    $root = defined('SH_ROOT') ? rtrim((string)SH_ROOT, '/\\') : '';
    if ($root !== '' && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return substr($file, strlen($root) + 1);
    }
    return basename($file);
}

/** Append one sanitized, timestamped line to the daily application log. */
function sh_log_line(string $channel, string $message): void
{
    $channel = preg_replace('/[^a-z0-9_.-]+/i', '-', trim($channel)) ?: 'app';
    $message = sh_scrub_secrets($message);
    $message = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message) ?? $message;
    $message = trim(substr($message, 0, 4000));

    $line = '[' . date('Y-m-d H:i:s') . '] [' . $channel . '] ' . $message;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '') {
        $uri = sh_scrub_secrets($uri);
        $uri = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $uri) ?? $uri);
        $line .= ' | uri=' . substr($uri, 0, 2000);
    }

    $directory = defined('SH_LOG_DIR') ? (string)SH_LOG_DIR : dirname(__DIR__) . '/logs';
    $path = rtrim($directory, '/\\') . '/app-' . date('Y-m-d') . '.log';
    $written = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        // Logging must never cause the request itself to fail.
        @error_log('ShopHaat application log write failed: ' . $message);
    }
}

/** Log an exception without dumping arguments or credentials from its trace. */
function sh_log_exception(Throwable $exception, string $context = 'exception'): void
{
    $location = sh_error_location($exception->getFile()) . ':' . $exception->getLine();
    $message = trim($context . ': ' . get_class($exception) . ': ' . $exception->getMessage() . ' at ' . $location);

    $frames = [];
    foreach (array_slice($exception->getTrace(), 0, 8) as $frame) {
        $call = (string)($frame['class'] ?? '') . (string)($frame['type'] ?? '') . (string)($frame['function'] ?? '');
        if (isset($frame['file'])) {
            $call .= ' at ' . sh_error_location((string)$frame['file']) . ':' . (int)($frame['line'] ?? 0);
        }
        if ($call !== '') { $frames[] = $call; }
    }
    if ($frames) { $message .= ' | trace=' . implode(' <- ', $frames); }

    sh_log_line('exception', $message);
}

/** Render a small safe HTML or JSON error response and stop the request. */
function sh_render_error_page(int $status, string $title, string $message, string $actionUrl = ''): void
{
    if ($status < 400 || $status > 599) { $status = 500; }

    // Discard any buffered partial page so an uncaught error cannot leak a
    // broken document or a mixture of HTML and JSON.
    while (ob_get_level() > 0) { @ob_end_clean(); }

    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if (defined('SH_JSON_CONTEXT') && SH_JSON_CONTEXT) {
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header('Content-Type: text/html; charset=utf-8');
        }
    }

    if (defined('SH_JSON_CONTEXT') && SH_JSON_CONTEXT) {
        echo json_encode(
            ['success' => false, 'error' => $message],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeActionUrl = '';
    if ($actionUrl !== '' && str_starts_with($actionUrl, '/') && !str_starts_with($actionUrl, '//')) {
        $safeActionUrl = htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $safeTitle . ' | ShopHaat</title>'
        . '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#f5f6f8;color:#20283a;font:15px/1.6 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.error-card{width:min(100%,520px);padding:28px;border:1px solid #e1e4ea;border-radius:12px;background:#fff;box-shadow:0 8px 30px rgba(20,28,45,.08)}.error-code{margin:0 0 8px;color:#e8501b;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}h1{margin:0 0 8px;font-size:22px;line-height:1.3}p{margin:0;color:#626b7b}.error-action{display:inline-flex;margin-top:20px;min-height:40px;align-items:center;justify-content:center;padding:8px 16px;border-radius:6px;background:#e8501b;color:#fff;font-weight:700;text-decoration:none}.error-action:hover{background:#cc4415}@media(max-width:480px){.error-card{padding:22px}h1{font-size:20px}}</style>'
        . '</head><body><main class="error-card"><p class="error-code">Error ' . (int)$status . '</p>'
        . '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>'
        . ($safeActionUrl !== '' ? '<a class="error-action" href="' . $safeActionUrl . '">Continue</a>' : '')
        . '</main></body></html>';
    exit;
}

/** Convert non-fatal PHP diagnostics into private logs, never browser output. */
function sh_handle_php_error(int $severity, string $message, string $file, int $line): bool
{
    if ((error_reporting() & $severity) === 0) { return true; }
    if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) { return false; }

    $levels = [
        E_WARNING => 'warning',
        E_NOTICE => 'notice',
        E_USER_WARNING => 'user-warning',
        E_USER_NOTICE => 'user-notice',
        E_DEPRECATED => 'deprecated',
        E_USER_DEPRECATED => 'user-deprecated',
    ];
    $level = $levels[$severity] ?? ('level-' . $severity);
    sh_log_line('php', $level . ': ' . $message . ' at ' . sh_error_location($file) . ':' . $line);
    return true;
}

/** Safe fallback for exceptions that escaped the request's normal handling. */
function sh_handle_uncaught_exception(Throwable $exception): void
{
    try { sh_log_exception($exception, 'uncaught'); }
    catch (Throwable $loggingFailure) { @error_log('ShopHaat: uncaught exception could not be logged.'); }

    sh_render_error_page(500, 'Something went wrong', 'The request could not be completed. Please try again.');
}

/** Handle fatal runtime errors that PHP does not pass to set_error_handler(). */
function sh_handle_shutdown_error(): void
{
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }

    $message = (string)($error['message'] ?? 'Unknown fatal error');
    $file = (string)($error['file'] ?? 'unknown');
    $line = (int)($error['line'] ?? 0);
    sh_log_line('fatal', $message . ' at ' . sh_error_location($file) . ':' . $line);
    sh_render_error_page(500, 'Something went wrong', 'The request could not be completed. Please try again.');
}

/** Register handlers once for this request. */
function sh_register_error_handlers(): void
{
    static $registered = false;
    if ($registered) { return; }
    $registered = true;

    set_error_handler('sh_handle_php_error');
    set_exception_handler('sh_handle_uncaught_exception');

    // The Telegram endpoint has its own earlier shutdown handler which must
    // acknowledge fatal webhook deliveries with HTTP 200 and a plain "OK".
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'telegram-webhook.php') {
        register_shutdown_function('sh_handle_shutdown_error');
    }
}
