<?php
/**
 * CSRF protection — per-session token, verified on every state-changing request.
 */

function sh_csrf_token(): string
{
    sh_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sh_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(sh_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function sh_csrf_valid(?string $token = null): bool
{
    sh_session_start();
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    $stored = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $stored !== '' && hash_equals($stored, $token);
}

/** Hard guard for form posts (HTML) and API calls (JSON). */
function sh_csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
    if (sh_csrf_valid()) { return; }
    sh_log_line('security', 'CSRF token rejected');
    if (sh_wants_json()) {
        sh_json(['success' => false, 'error' => 'Your session expired. Please refresh the page.'], 419);
    }
    sh_render_error_page(419, 'Session expired', 'Your session expired for security reasons. Please go back and try again.');
}
