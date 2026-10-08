<?php
/**
 * Phone OTP endpoint — send / verify. Always returns JSON.
 * Used only for `signup` and `login` (plus the superadmin `test` send).
 * The phone is never trusted from the client for signup/login — it is resolved
 * from the server-side pending session context.
 */
declare(strict_types=1);
define('SH_JSON_CONTEXT', true);
require_once dirname(__DIR__) . '/config/config.php';

if (sh_db_config() === null || !sh_is_locked()) {
    sh_json(['success' => false, 'error' => 'The store is not installed yet.'], 503);
}
try { sh_db(); sh_otp_schema_ensure(); } catch (Throwable $e) {
    sh_log_exception($e, 'api-otp-db');
    sh_json(['success' => false, 'error' => 'Service temporarily unavailable.'], 503);
}

require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/admin-auth.php';

sh_session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_json(['success' => false, 'error' => 'POST required.'], 405);
}
sh_csrf_require();

if (!sh_otp_enabled()) {
    sh_json(['success' => false, 'error' => 'Phone verification is currently disabled.'], 403);
}

$action = sh_post('action');
$purpose = sh_post('purpose');
if (!in_array($purpose, sh_otp_purposes(), true)) {
    sh_json(['success' => false, 'error' => 'Invalid verification purpose.'], 400);
}

$user = sh_user();
$admin = null;
if ($purpose === 'test') {
    $admin = sh_admin();
    if ($admin === null || $admin['role'] !== 'superadmin') {
        sh_json(['success' => false, 'error' => 'Not authorized.'], 403);
    }
}

/** Resolve the phone + user id this purpose is allowed to verify (server-side). */
function sh_otp_resolve(string $purpose): ?array
{
    switch ($purpose) {
        case 'signup':
            $p = sh_pending_signup();
            return $p !== null ? ['phone' => $p['phone'], 'user_id' => null] : null;
        case 'login':
            $p = sh_pending_login();
            return $p !== null ? ['phone' => $p['phone'], 'user_id' => (int)$p['user_id']] : null;
        case 'test':
            return ['phone' => sh_phone_normalize(sh_post('phone')), 'user_id' => null];
    }
    return null;
}

try {
    // -----------------------------------------------------------------------
    // Signup "Connect": validate the name + phone, hold them server-side as a
    // pending signup, and request the OTP through the configured provider.
    // Called by the AJAX button on register.php. The phone is trusted from the
    // client only on this first step — exactly as the server form already does.
    // -----------------------------------------------------------------------
    if ($action === 'signup') {
        if ($purpose !== 'signup') {
            sh_json(['success' => false, 'error' => 'Invalid verification purpose.'], 400);
        }
        $name = sh_post('name');
        $rawPhone = sh_post('phone');
        $redirect = sh_post('redirect');

        $v = new ShValidator($_POST);
        $v->required('name', 'Full name')->minLen('name', 2, 'Full name')->maxLen('name', 110, 'Full name')
          ->required('phone', 'Phone number')->phone('phone', 'Phone number');
        if (empty($_POST['terms'])) { $v->custom('terms', false, 'You must accept the terms and conditions.'); }
        if ($v->fails()) {
            sh_json(['success' => false, 'error' => $v->firstError(), 'errors' => $v->errors()], 422);
        }

        $phone = sh_phone_normalize($rawPhone);
        if ($phone === '') {
            sh_json(['success' => false, 'error' => 'Please enter a valid phone number.', 'errors' => ['phone' => 'Please enter a valid phone number.']], 422);
        }
        if (sh_find_user_by_phone($phone) !== null) {
            sh_json([
                'success' => false,
                'error' => 'An account already exists with this phone number. Please log in instead.',
                'errors' => ['phone' => 'An account already exists with this phone number. Please log in instead.'],
            ], 409);
        }

        $target = sh_safe_redirect($redirect, 'account.php');
        sh_pending_signup_save($name, $phone, $target);
        $issue = sh_otp_issue($phone, 'signup', null);
        if (!$issue['ok']) {
            sh_pending_signup_clear();
            sh_json(['success' => false, 'error' => $issue['error'] ?? 'Could not send the code. Please try again.'], 429);
        }
        sh_json([
            'success' => true,
            'message' => 'Verification code sent.',
            'phone' => $phone,
            'masked' => sh_phone_mask_login($phone),
            'expires_in' => sh_otp_expiry_seconds(),
            'cooldown' => sh_otp_resend_cooldown(),
            'length' => sh_otp_length(),
        ]);
    }

    $ctx = sh_otp_resolve($purpose);
    $phone = $ctx !== null ? sh_phone_normalize((string)$ctx['phone']) : '';
    $otpUserId = $ctx !== null ? $ctx['user_id'] : null;

    if ($action === 'send') {
        if ($phone === '') {
            sh_json(['success' => false, 'error' => 'We could not find the phone number to verify. Please start again.'], 400);
        }
        $res = sh_otp_issue($phone, $purpose, $otpUserId);
        if (!$res['ok']) {
            sh_json(['success' => false, 'error' => $res['error'] ?? 'Could not send the code.'], 429);
        }
        $out = [
            'success' => true,
            'message' => 'Verification code sent.',
            'expires_in' => sh_otp_expiry_seconds(),
            'cooldown' => sh_otp_resend_cooldown(),
        ];
        // The offline/test driver returns a code so the admin can exercise the
        // flow without an SMS provider. It is ONLY ever surfaced for the admin
        // "test" purpose — never to customers.
        if ($purpose === 'test' && !empty($res['code'])) {
            $out['code'] = $res['code'];
        }
        sh_json($out);
    }

    if ($action === 'verify') {
        if ($phone === '') {
            sh_json(['success' => false, 'error' => 'We could not find the phone number to verify. Please start again.'], 400);
        }
        $code = trim((string)($_POST['code'] ?? ''));
        $res = sh_otp_verify($phone, $purpose, $code, $otpUserId);
        if (!$res['ok']) {
            sh_json(['success' => false, 'error' => $res['error'] ?? 'Incorrect code.']);
        }
        $done = sh_otp_complete($purpose, $phone);
        if (!$done['ok']) {
            sh_json(['success' => false, 'error' => $done['error'] ?? 'Verification could not be completed.']);
        }
        sh_json(['success' => true, 'redirect' => $done['redirect'] ?? null]);
    }

    sh_json(['success' => false, 'error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    sh_log_exception($e, 'api-otp');
    sh_json(['success' => false, 'error' => 'The request could not be completed.'], 500);
}
