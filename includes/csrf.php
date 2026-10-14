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

/** Safe same-site page to reload after a stale non-authentication form. */
function sh_csrf_return_target(): string
{
    $request = (string)($_SERVER['REQUEST_URI'] ?? '');
    return sh_safe_redirect($request, 'index.php');
}

/** Login URL for an expired customer session without trusting the request URI. */
function sh_csrf_login_url(string $returnTo): string
{
    if (function_exists('sh_login_url')) { return sh_login_url($returnTo); }
    return 'login.php?redirect=' . rawurlencode(sh_safe_redirect($returnTo, 'account.php'));
}

/** Customer expiry markers must never reroute an admin form through customer Login. */
function sh_csrf_is_admin_request(): bool
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return preg_match('~/(?:admin)(?:/|$)~i', $script) === 1;
}

/** Store a one-time expiry notice even on public pages that did not load auth.php. */
function sh_csrf_consume_expired_customer_session(): void
{
    if (function_exists('sh_consume_customer_session_expired_notice')) {
        sh_consume_customer_session_expired_notice();
        return;
    }
    sh_session_start();
    unset($_SESSION['user_id'], $_SESSION['user_login_at'], $_SESSION['csrf_token'], $_SESSION['sh_auth_expired']);
    sh_customer_auth_hint_clear();
    sh_flash('info', 'Your session has expired. Please log in again to continue.');
}

/** @return array{status:int,payload:array<string,mixed>} */
function sh_csrf_json_failure(bool $sessionExpired, string $returnTo): array
{
    if ($sessionExpired) {
        $payload = function_exists('sh_customer_auth_required_payload')
            ? sh_customer_auth_required_payload($returnTo, true)
            : [
                'success' => false,
                'error' => 'Your session has expired. Please log in again to continue.',
                'auth_required' => true,
                'session_expired' => true,
                'login_url' => sh_url(sh_csrf_login_url($returnTo)),
            ];
        return ['status' => 401, 'payload' => $payload];
    }
    return ['status' => 403, 'payload' => [
        'success' => false,
        'error' => 'This form is no longer valid. Refresh the page and try again.',
        'csrf_failed' => true,
        'refresh_required' => true,
    ]];
}

/**
 * Hard guard for form posts (HTML) and API calls (JSON).
 *
 * A lost session has no server-side CSRF token, so it used to be rendered as an
 * legacy error page. Keep CSRF enforcement intact, but distinguish a real expired
 * login from a malformed/stale form: pages go through normal Login or reload
 * routes, and XHR callers receive machine-readable JSON instead of HTML.
 */
function sh_csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
    if (sh_csrf_valid()) { return; }

    sh_log_line('security', 'CSRF token rejected');
    $returnTo = sh_csrf_return_target();
    // The expiry hint belongs only to customer Login recovery. Admin guards
    // maintain their own timeout path, so a stale customer cookie cannot send
    // an admin login/form submission to the storefront.
    $sessionExpired = !sh_csrf_is_admin_request() && sh_customer_session_expired();

    if (sh_wants_json()) {
        // The frontend reloads a stale form without retrying it. Carry a normal
        // flash message into that fresh page so the customer is never left with
        // a silent or framework-style error response.
        if (!$sessionExpired) { sh_flash('info', 'This form has expired. Please refresh and try again.'); }
        $failure = sh_csrf_json_failure($sessionExpired, $returnTo);
        sh_json($failure['payload'], $failure['status']);
    }

    if ($sessionExpired) {
        sh_csrf_consume_expired_customer_session();
        sh_redirect(sh_csrf_login_url($returnTo));
    }

    // A token mismatch with a still-live guest/session is not a login timeout.
    // Reload through the normal page so it receives a new token; never replay a
    // state-changing POST automatically.
    sh_flash('error', 'This form has expired. Please refresh the page and try again.');
    sh_redirect($returnTo);
}
