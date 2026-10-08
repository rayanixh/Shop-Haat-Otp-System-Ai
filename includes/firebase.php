<?php
/**
 * Account email verification backed by Firebase Authentication.
 *
 * The site keeps its own PHP/MySQL accounts. For each customer with a real
 * email address a "shadow" Firebase Auth user is created server-side through
 * the Identity Toolkit REST API (Web API key only — no service account, no
 * Composer). Firebase sends its real verification email; the verified state is
 * always read back from Firebase (accounts:lookup / accounts:update with the
 * oobCode) and never taken from the browser.
 *
 * Settings: firebase_enabled, firebase_api_key (encrypted), firebase_project_id,
 *           email_verification_required (block checkout until verified).
 * Users:    email_verified, email_verified_at, firebase_uid, firebase_secret
 *           (encrypted random password of the shadow account), email_verify_sent_at,
 *           email_verify_checked_at.
 */
require_once SH_ROOT . '/includes/verification.php'; // sh_verify_encrypt / decrypt / http
require_once SH_ROOT . '/includes/phone-otp.php';    // sh_security_log

function sh_fb_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        if (sh_setting('firebase_schema_v', '') === '1') { return; }
        $pdo = sh_db();
        $cols = [
            'email_verified'          => 'TINYINT(1) NOT NULL DEFAULT 0',
            'email_verified_at'       => 'DATETIME DEFAULT NULL',
            'firebase_uid'            => 'VARCHAR(64) DEFAULT NULL',
            'firebase_secret'         => 'TEXT DEFAULT NULL',
            'email_verify_sent_at'    => 'DATETIME DEFAULT NULL',
            'email_verify_checked_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($cols as $col => $def) {
            $n = (int)sh_val('SELECT COUNT(*) FROM information_schema.columns
                              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['users', $col], 0);
            if ($n === 0) { $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def"); }
        }
        sh_setting_save('firebase_schema_v', '1');
    } catch (Throwable $e) {
        sh_log_exception($e, 'firebase-schema');
    }
}

function sh_fb_enabled(): bool
{
    return sh_setting('firebase_enabled', '0') === '1' && sh_fb_api_key() !== '';
}
function sh_fb_api_key(): string
{
    return sh_verify_decrypt((string)sh_setting('firebase_api_key', ''));
}
/** Where Firebase sends the customer after the hosted verification page. */
function sh_fb_continue_url(): string
{
    return sh_site_url() . '/verify-email.php';
}

/** Does this account take part in email verification at all? */
function sh_fb_applies(array $user): bool
{
    $email = (string)($user['email'] ?? '');
    return $email !== '' && !sh_is_synthetic_email($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Identity Toolkit call. Returns ['ok'=>bool,'data'=>array,'error'=>string|null]. */
function sh_fb_call(string $action, array $payload): array
{
    $key = sh_fb_api_key();
    if ($key === '') { return ['ok' => false, 'data' => [], 'error' => 'Firebase is not configured.']; }
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:' . $action . '?key=' . rawurlencode($key);
    $h = sh_verify_http('POST', $url, ['Accept' => 'application/json'], json_encode($payload, JSON_UNESCAPED_SLASHES), 12, true);
    if ($h['ok']) { return ['ok' => true, 'data' => $h['body'], 'error' => null]; }
    $msg = (string)($h['body']['error']['message'] ?? ($h['error'] ?: ('HTTP ' . (int)$h['status'])));
    return ['ok' => false, 'data' => $h['body'], 'error' => $msg];
}

/** Human message for Firebase error codes (never leaks internals). */
function sh_fb_error_text(string $code): string
{
    $code = strtoupper(explode(' ', trim($code))[0] ?? '');
    switch (true) {
        case str_starts_with($code, 'TOO_MANY_ATTEMPTS'): return 'Too many attempts. Please try again later.';
        case $code === 'INVALID_EMAIL':                   return 'The email address is not valid.';
        case $code === 'EMAIL_EXISTS':                    return 'This email already exists in the verification service. Please contact support.';
        case $code === 'OPERATION_NOT_ALLOWED':           return 'Email/password sign-in is disabled in the Firebase project.';
        case $code === 'API_KEY_INVALID' || str_contains($code, 'API_KEY'): return 'The Firebase Web API key is invalid.';
        case $code === 'EXPIRED_OOB_CODE':                return 'This verification link has expired. Please request a new one.';
        case $code === 'INVALID_OOB_CODE':                return 'This verification link is invalid or was already used.';
        case $code === 'USER_DISABLED':                   return 'The verification account is disabled.';
        default:                                          return 'The verification service is temporarily unavailable.';
    }
}

/**
 * Sign in (or create) the shadow Firebase account for a user and return a
 * fresh ID token. Stores firebase_uid + encrypted random secret on first use.
 */
function sh_fb_id_token(array $user): array
{
    sh_fb_schema_ensure();
    if (!sh_fb_applies($user)) { return ['ok' => false, 'error' => 'This account has no email address.']; }
    $email = (string)$user['email'];
    // The secret is never carried in session user arrays; read it fresh.
    $row = sh_one('SELECT firebase_uid, firebase_secret FROM users WHERE id = ? LIMIT 1', [(int)$user['id']]) ?? [];
    $user['firebase_uid'] = $row['firebase_uid'] ?? null;
    $secret = sh_verify_decrypt((string)($row['firebase_secret'] ?? ''));

    if ($secret !== '') {
        $r = sh_fb_call('signInWithPassword', ['email' => $email, 'password' => $secret, 'returnSecureToken' => true]);
        if ($r['ok'] && !empty($r['data']['idToken'])) {
            if (empty($user['firebase_uid']) && !empty($r['data']['localId'])) {
                sh_update('users', ['firebase_uid' => $r['data']['localId']], 'id = ?', [(int)$user['id']]);
            }
            return ['ok' => true, 'token' => (string)$r['data']['idToken'], 'uid' => (string)($r['data']['localId'] ?? '')];
        }
        $code = (string)$r['error'];
        if (!str_starts_with($code, 'EMAIL_NOT_FOUND')) { return ['ok' => false, 'error' => sh_fb_error_text($code)]; }
        // The Firebase user vanished (deleted in console) — recreate below.
    }

    $secret = bin2hex(random_bytes(24));
    $r = sh_fb_call('signUp', ['email' => $email, 'password' => $secret, 'returnSecureToken' => true]);
    if (!$r['ok'] || empty($r['data']['idToken'])) { return ['ok' => false, 'error' => sh_fb_error_text((string)$r['error'])]; }
    sh_update('users', ['firebase_uid' => (string)($r['data']['localId'] ?? ''), 'firebase_secret' => sh_verify_encrypt($secret)], 'id = ?', [(int)$user['id']]);
    return ['ok' => true, 'token' => (string)$r['data']['idToken'], 'uid' => (string)($r['data']['localId'] ?? '')];
}

/** Send (or resend) Firebase's verification email. Enforces a cooldown. */
function sh_fb_send_verification(array $user, int $cooldown = 90): array
{
    sh_fb_schema_ensure();
    if (!sh_fb_enabled()) { return ['ok' => false, 'error' => 'Email verification is not enabled.']; }
    if (!sh_fb_applies($user)) { return ['ok' => false, 'error' => 'Add an email address to your profile first.']; }
    if (!empty($user['email_verified'])) { return ['ok' => true, 'already' => true]; }
    $last = (string)($user['email_verify_sent_at'] ?? '');
    if ($last !== '' && time() - strtotime($last) < $cooldown) {
        $wait = $cooldown - (time() - strtotime($last));
        return ['ok' => false, 'error' => 'A verification email was sent recently. Please wait ' . $wait . ' seconds before requesting another.', 'wait' => $wait];
    }
    $t = sh_fb_id_token($user);
    if (!$t['ok']) { return $t; }
    $r = sh_fb_call('sendOobCode', ['requestType' => 'VERIFY_EMAIL', 'idToken' => $t['token'], 'continueUrl' => sh_fb_continue_url()]);
    if (!$r['ok']) { return ['ok' => false, 'error' => sh_fb_error_text((string)$r['error'])]; }
    sh_update('users', ['email_verify_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$user['id']]);
    sh_security_log('email_verification_sent', (int)$user['id'], ['email' => mb_substr((string)$user['email'], 0, 3) . '***']);
    return ['ok' => true];
}

/** Ask Firebase whether the email is verified and sync the local flag. */
function sh_fb_sync_verified(array $user): array
{
    sh_fb_schema_ensure();
    if (!sh_fb_enabled() || !sh_fb_applies($user)) { return ['ok' => false, 'verified' => !empty($user['email_verified'])]; }
    if (!empty($user['email_verified'])) { return ['ok' => true, 'verified' => true]; }
    $t = sh_fb_id_token($user);
    if (!$t['ok']) { return ['ok' => false, 'verified' => false, 'error' => $t['error']]; }
    $r = sh_fb_call('lookup', ['idToken' => $t['token']]);
    if (!$r['ok']) { return ['ok' => false, 'verified' => false, 'error' => sh_fb_error_text((string)$r['error'])]; }
    $fbUser = $r['data']['users'][0] ?? [];
    $verified = !empty($fbUser['emailVerified']) && strcasecmp((string)($fbUser['email'] ?? ''), (string)$user['email']) === 0;
    $upd = ['email_verify_checked_at' => date('Y-m-d H:i:s')];
    if ($verified) { $upd += ['email_verified' => 1, 'email_verified_at' => date('Y-m-d H:i:s')]; }
    sh_update('users', $upd, 'id = ?', [(int)$user['id']]);
    if ($verified) { sh_security_log('email_verified', (int)$user['id'], ['via' => 'firebase']); }
    return ['ok' => true, 'verified' => $verified];
}

/**
 * Handle a verification link that points directly at this site (Firebase
 * console → Authentication → Templates → custom action URL). Firebase
 * validates the code and reports the email it belongs to.
 */
function sh_fb_apply_oob_code(string $code): array
{
    sh_fb_schema_ensure();
    if (!sh_fb_enabled()) { return ['ok' => false, 'error' => 'Email verification is not enabled.']; }
    $r = sh_fb_call('update', ['oobCode' => $code]);
    if (!$r['ok']) { return ['ok' => false, 'error' => sh_fb_error_text((string)$r['error'])]; }
    $email = (string)($r['data']['email'] ?? '');
    if ($email === '' || empty($r['data']['emailVerified'])) { return ['ok' => false, 'error' => 'Firebase did not confirm the verification.']; }
    $u = sh_one('SELECT id, email FROM users WHERE email = ? LIMIT 1', [$email]);
    if ($u) {
        sh_update('users', ['email_verified' => 1, 'email_verified_at' => date('Y-m-d H:i:s'), 'email_verify_checked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$u['id']]);
        sh_security_log('email_verified', (int)$u['id'], ['via' => 'firebase-link']);
    }
    return ['ok' => true, 'email' => $email, 'user_id' => (int)($u['id'] ?? 0)];
}

/** Mark verified without Firebase (e.g. Google sign-in already asserted the email). */
function sh_fb_mark_verified(int $userId, string $via): void
{
    sh_fb_schema_ensure();
    try {
        sh_update('users', ['email_verified' => 1, 'email_verified_at' => date('Y-m-d H:i:s')], 'id = ? AND email_verified = 0', [$userId]);
        sh_security_log('email_verified', $userId, ['via' => $via]);
    } catch (Throwable $e) { sh_log_exception($e, 'firebase-mark'); }
}

/** Email changed → previous verification no longer applies. */
function sh_fb_reset_for_email_change(): array
{
    sh_fb_schema_ensure();
    return ['email_verified' => 0, 'email_verified_at' => null, 'firebase_uid' => null, 'firebase_secret' => null,
            'email_verify_sent_at' => null, 'email_verify_checked_at' => null];
}

/** Should this signed-in user be blocked from checkout until verified? */
function sh_fb_checkout_blocked(?array $user): bool
{
    if ($user === null || !sh_fb_enabled() || sh_setting('email_verification_required', '0') !== '1') { return false; }
    if (sh_auth_mode() !== 'email_password') { return false; }
    sh_fb_schema_ensure();
    return sh_fb_applies($user) && empty($user['email_verified']);
}
