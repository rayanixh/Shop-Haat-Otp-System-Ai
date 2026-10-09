<?php
/**
 * Error handling, logging and standalone error pages.
 *
 * Loaded by config/config.php (and includes/phone-otp.php) before anything
 * else, so this file must stay dependency-free: no database, no settings and
 * no other includes — only the SH_LOG_DIR constant (with a fallback) and
 * $_SERVER. It exists so that no page can ever die with a raw HTTP 500.
 *
 * Provided here:
 *   sh_register_error_handlers()  error / exception / shutdown handlers
 *   sh_log_line()                 one line in logs/app-YYYY-MM-DD.log
 *   sh_log_exception()            exceptions, same daily log
 *   sh_render_error_page()        clean standalone HTML (or JSON) error page
 *   sh_scrub_secrets()            redact tokens/credentials before logging
 *   sh_wants_json()               does this request expect a JSON reply?
 *
 * Logging must never break a request: every writer swallows its own errors.
 */

/**
 * Remove anything credential-shaped from a string before it is written to a
 * log or shown in an error response. Covers bearer/basic headers, well-known
 * token formats (Stripe, GitHub, Slack, Google, Meta, Telegram, JWT), long
 * hex/base64 secrets and sensitive key/value pairs such as access_token=… or
 * hub.verify_token=… (the key may be the suffix of a longer name).
 */
function sh_scrub_secrets(string $text): string
{
    if ($text === '') { return $text; }
    $patterns = [
        // Authorization headers.
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '[redacted]',
        // Well-known token shapes.
        '/\bsk_(?:live|test)_[A-Za-z0-9]{16,}\b/'        => '[redacted]', // Stripe secret
        '/\b(?:pk|rk)_(?:live|test)_[A-Za-z0-9]{16,}\b/' => '[redacted]', // Stripe public/restricted
        '/\bwhsec_[A-Za-z0-9]{16,}\b/'                  => '[redacted]', // Stripe webhook secret
        '/\bghp_[A-Za-z0-9]{20,}\b/'                    => '[redacted]', // GitHub token
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/'            => '[redacted]', // GitHub fine-grained PAT
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/'            => '[redacted]', // Slack token
        '/\bAIza[A-Za-z0-9_-]{20,}\b/'                  => '[redacted]', // Google API key
        '/\bya29\.[A-Za-z0-9_-]{20,}\b/'                => '[redacted]', // Google OAuth token
        '/\bEAA[A-Za-z0-9_-]{20,}\b/'                   => '[redacted]', // Meta long-lived token
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{5,}\b/' => '[redacted]', // JWT
        '/\b\d{5,}:[A-Za-z0-9_-]{30,}\b/'               => '[redacted]', // Telegram bot token
        // Generic long secrets: hex or base64url runs.
        '/\b[0-9a-f]{32,}\b/i'                          => '[redacted]',
        '/\b[A-Za-z0-9_-]{40,}\b/'                      => '[redacted]',
        // Sensitive key/value pairs (query strings, JSON-ish text, config dumps).
        '/(["\']?(?:password|passwd|pwd|secret|token|access_token|refresh_token|id_token|api_?key|client_secret|private_key|authorization|auth|signature|session|cookie|set-cookie|x-api-key|x-access-token|pass)["\']?\s*[:=]\s*["\']?)[^\s"\'",;&?]{2,}/i' => '$1[redacted]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $scrubbed = preg_replace($pattern, $replacement, $text);
        if (is_string($scrubbed)) { $text = $scrubbed; }
    }
    return $text;
}

/**
 * Append one line to logs/app-YYYY-MM-DD.log:
 *   [2026-01-31 12:00:00] [channel] message | uri=/request/uri
 * The URI suffix is added for web requests only (cron/CLI omit it). The whole
 * line is scrubbed of secrets before it is written. Never throws.
 */
function sh_log_line(string $channel, string $message): void
{
    try {
        $channel = strtolower((string)(preg_replace('/[^A-Za-z0-9_-]/', '', $channel) ?? ''));
        if ($channel === '') { $channel = 'app'; }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $channel . '] ' . $message;
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (is_string($uri) && $uri !== '') { $line .= ' | uri=' . $uri; }
        $line = sh_scrub_secrets($line);
        $dir = defined('SH_LOG_DIR') ? SH_LOG_DIR : dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
        // Logging must never break the request.
    }
}

/**
 * Log an exception with a context label:
 *   [2026-01-31 12:00:00] [context] PDOException: … in /path/file.php:42 | uri=…
 * Never throws.
 */
function sh_log_exception(Throwable $e, string $context): void
{
    try {
        sh_log_line($context, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    } catch (Throwable $ignored) {
        // Logging must never break the request.
    }
}

/**
 * Does this request expect a JSON reply? True for the /api/* endpoints (they
 * define SH_JSON_CONTEXT), for /api/ URLs, for XHR/fetch calls the storefront
 * JS makes, and for explicit ?json / ?ajax requests.
 */
function sh_wants_json(): bool
{
    if (defined('SH_JSON_CONTEXT')) { return true; }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($uri) && $uri !== '') {
        $path = parse_url($uri, PHP_URL_PATH);
        if (is_string($path) && preg_match('~/api/~', $path) === 1) { return true; }
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (is_string($accept) && stripos($accept, 'application/json') !== false) { return true; }
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (is_string($xhr) && strcasecmp($xhr, 'XMLHttpRequest') === 0) { return true; }
    if (isset($_GET['json']) || isset($_GET['ajax'])) { return true; }
    return false;
}

/**
 * Render a clean, self-contained error page (or a JSON error for API/XHR
 * requests) and stop. Never leaks exception details — callers pass safe,
 * human-readable copy. Uses inline styles only: it can run before the theme,
 * the database or any other include is available.
 */
function sh_render_error_page(int $status, string $title, string $message, ?string $actionUrl = null, string $actionLabel = ''): void
{
    if (sh_wants_json()) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if ($actionLabel === '' && $actionUrl !== null && $actionUrl !== '') {
        $actionLabel = (stripos($actionUrl, 'install') !== false) ? 'Run the installer' : 'Continue';
    }
    $btn = ($actionUrl !== null && $actionUrl !== '')
        ? '<a class="sh-err__btn" href="' . $esc($actionUrl) . '">' . $esc($actionLabel) . '</a>'
        : '';
    $code = (int)$status;
    $t = $esc($title);
    $m = $esc($message);

    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$t} — ShopHaat</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px;
         background: #f2f3f6; color: #1f2430;
         font: 14px/1.55 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  .sh-err { width: 100%; max-width: 430px; background: #fff; border: 1px solid #e4e7ec; border-radius: 10px;
            padding: 30px 26px; text-align: center; box-shadow: 0 8px 28px rgba(20,25,40,.08); }
  .sh-err__mark { display: inline-grid; place-items: center; width: 44px; height: 44px; border-radius: 10px;
                  background: #151b2b; color: #e8501b; font-weight: 800; font-size: 17px; margin-bottom: 14px; }
  .sh-err__code { margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: .8px;
                  text-transform: uppercase; color: #6b7385; }
  .sh-err__title { margin: 0 0 8px; font-size: 19px; font-weight: 700; color: #1f2430; }
  .sh-err__msg { margin: 0 0 18px; font-size: 13.5px; line-height: 1.6; color: #414958; }
  .sh-err__btn { display: inline-block; background: #e8501b; color: #fff; text-decoration: none;
                 font-weight: 700; font-size: 13.5px; padding: 10px 20px; border-radius: 6px; }
  .sh-err__btn:hover { background: #cc4415; }
  .sh-err__foot { margin: 16px 0 0; font-size: 11.5px; color: #6b7385; }
  @media (max-width: 380px) { .sh-err { padding: 24px 18px; } }
</style>
</head>
<body>
  <main class="sh-err">
    <span class="sh-err__mark">SH</span>
    <p class="sh-err__code">Error {$code}</p>
    <h1 class="sh-err__title">{$t}</h1>
    <p class="sh-err__msg">{$m}</p>
    {$btn}
    <p class="sh-err__foot">ShopHaat</p>
  </main>
</body>
</html>

HTML;
    exit;
}

/**
 * Install the global error handlers. Warnings, notices and deprecations are
 * logged and suppressed (never displayed, never fatal). Uncaught exceptions
 * and fatal errors are logged with full detail and answered with a clean
 * 500 page instead of a raw server error.
 */
function sh_register_error_handlers(): void
{
    set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        // Respect @-suppression and the configured error_reporting level.
        if ((error_reporting() & $errno) === 0) { return true; }
        // Recoverable/type errors and E_USER_ERROR stay fatal (PHP default);
        // the shutdown handler below logs them and renders the clean 500 page.
        if ($errno === E_RECOVERABLE_ERROR || $errno === E_USER_ERROR) { return false; }
        $names = [
            E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated',
            E_USER_WARNING => 'User warning', E_USER_NOTICE => 'User notice',
            E_USER_DEPRECATED => 'User deprecated', E_STRICT => 'Strict standards',
        ];
        $name = $names[$errno] ?? ('Error ' . $errno);
        sh_log_line('php', 'PHP ' . $name . ': ' . $errstr . ' in ' . $errfile . ':' . $errline);
        return true;
    });

    set_exception_handler(static function (Throwable $e): void {
        try {
            sh_log_exception($e, 'uncaught');
            sh_render_error_page(
                500,
                'Something went wrong',
                'An unexpected error occurred. Please try again in a moment. If the problem continues, contact the site administrator.'
            );
        } catch (Throwable $ignored) {
            if (!headers_sent()) { http_response_code(500); }
            echo 'Something went wrong. Please try again in a moment.';
        }
        exit;
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) { return; }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatal, true)) { return; }
        $msg = $err['message'] . ' in ' . $err['file'] . ':' . $err['line'];
        sh_log_line('php', 'PHP Fatal error: ' . $msg);
        error_log('ShopHaat fatal error: ' . $msg);
        if (headers_sent()) { return; }
        if (function_exists('sh_render_error_page')) {
            sh_render_error_page(500, 'Something went wrong', 'An unexpected error occurred. Please try again in a moment.');
        }
    });
}
