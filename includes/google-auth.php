<?php
/**
 * "Continue with Google" — OAuth 2.0 / OpenID Connect sign-in.
 *
 * Security posture:
 *  - Authorization Code flow with PKCE, `state` (CSRF) and `nonce` (replay).
 *  - The Client Secret is stored encrypted at rest and is only ever used
 *    server-side in the token exchange. It is never rendered to the browser.
 *  - The ID token is verified locally: RS256 signature against Google's JWKS,
 *    issuer, audience, expiry and nonce. Google's response is never trusted blindly.
 *  - `email_verified` must be true before an account is linked or created.
 *  - Session ID is regenerated on login via the existing sh_login_user().
 *
 * Works alongside the existing Email + Password login; nothing here replaces it.
 */

const SH_GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const SH_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const SH_GOOGLE_JWKS_URL  = 'https://www.googleapis.com/oauth2/v3/certs';
const SH_GOOGLE_ISSUERS   = ['https://accounts.google.com', 'accounts.google.com'];

/* ------------------------------------------------------------------ *
 * Settings + secret storage
 * ------------------------------------------------------------------ */

/** Encryption key derived from DB credentials (which live outside public reach). */
function sh_google_secret_key(): string
{
    $cfg = sh_db_config() ?? [];
    return hash('sha256', 'shophaat|google|' . ($cfg['name'] ?? '') . '|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''), true);
}

function sh_google_encrypt(string $plain): string
{
    if ($plain === '') { return ''; }
    if (!function_exists('openssl_encrypt')) { return 'plain:' . $plain; }
    $iv = random_bytes(16);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', sh_google_secret_key(), OPENSSL_RAW_DATA, $iv);
    if ($ct === false) { return 'plain:' . $plain; }
    return 'enc:' . base64_encode($iv . $ct);
}

function sh_google_decrypt(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') { return ''; }
    if (str_starts_with($stored, 'plain:')) { return substr($stored, 6); }
    if (!str_starts_with($stored, 'enc:')) { return $stored; }
    if (!function_exists('openssl_decrypt')) { return ''; }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) <= 16) { return ''; }
    $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', sh_google_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $pt === false ? '' : $pt;
}

function sh_google_client_id(): string
{
    return trim((string)sh_setting('google_client_id', ''));
}

/** Server-side only. Never echo the return value into HTML/JS. */
function sh_google_client_secret(): string
{
    return sh_google_decrypt((string)sh_setting('google_client_secret', ''));
}

function sh_google_has_secret(): bool
{
    return trim((string)sh_setting('google_client_secret', '')) !== '';
}

/** Admin toggle ON and credentials present — the single source of truth for showing the button. */
function sh_google_enabled(): bool
{
    return (string)sh_setting('google_login_enabled', '0') === '1'
        && sh_google_client_id() !== ''
        && sh_google_has_secret();
}

/** Exact redirect URI the admin must register in Google Cloud Console. Detected dynamically. */
function sh_google_callback_url(): string
{
    return sh_site_url() . '/auth/google/callback.php';
}

function sh_google_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function sh_google_is_localhost(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/* ------------------------------------------------------------------ *
 * Small helpers
 * ------------------------------------------------------------------ */

function sh_google_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function sh_google_b64url_decode(string $txt): string
{
    $pad = strlen($txt) % 4;
    if ($pad) { $txt .= str_repeat('=', 4 - $pad); }
    $out = base64_decode(strtr($txt, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

/** GET/POST helper with cURL and a stream fallback (shared hosting friendly). */
function sh_google_http(string $method, string $url, array $form = [], int $timeout = 15): array
{
    $payload = $form ? http_build_query($form) : '';
    $headers = ['Accept: application/json'];
    if ($method === 'POST') { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = $payload; }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) { return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err ?: 'Connection failed.']; }
        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => (string)$res, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
    }

    $http = ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'ignore_errors' => true];
    if ($method === 'POST') { $http['content'] = $payload; }
    $ctx = stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    set_error_handler(static fn(): bool => true);
    try { $res = file_get_contents($url, false, $ctx); }
    finally { restore_error_handler(); }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    if ($res === false) { return ['ok' => false, 'status' => $code, 'body' => '', 'error' => 'Connection failed.']; }
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => (string)$res, 'error' => $code >= 300 ? 'HTTP ' . $code : ''];
}

/* ------------------------------------------------------------------ *
 * Step 1 — build the authorization URL (state + nonce + PKCE in session)
 * ------------------------------------------------------------------ */

function sh_google_begin(string $redirectAfter = ''): string
{
    sh_session_start();
    $state    = sh_google_b64url_encode(random_bytes(32));
    $nonce    = sh_google_b64url_encode(random_bytes(32));
    $verifier = sh_google_b64url_encode(random_bytes(48));
    $challenge = sh_google_b64url_encode(hash('sha256', $verifier, true));

    $_SESSION['sh_google_oauth'] = [
        'state'    => $state,
        'nonce'    => $nonce,
        'verifier' => $verifier,
        'redirect' => $redirectAfter,
        'at'       => time(),
    ];

    $params = [
        'client_id'             => sh_google_client_id(),
        'redirect_uri'          => sh_google_callback_url(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
        'access_type'           => 'online',
        'prompt'                => 'select_account',
    ];
    return SH_GOOGLE_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/* ------------------------------------------------------------------ *
 * Step 2 — callback: exchange the code and verify the ID token
 *
 * Returns ['ok'=>true,'profile'=>[...]] or ['ok'=>false,'error'=>user message].
 * Technical detail goes to logs/google-auth.log only.
 * ------------------------------------------------------------------ */

function sh_google_complete(array $query): array
{
    sh_session_start();
    $ctx = $_SESSION['sh_google_oauth'] ?? null;
    unset($_SESSION['sh_google_oauth']); // single use, whatever happens next

    $generic = 'Unable to sign in with Google. Please try again.';

    if (!empty($query['error'])) {
        $err = (string)$query['error'];
        sh_log_line('google-auth', 'Provider returned error: ' . $err);
        if (in_array($err, ['access_denied', 'consent_required', 'interaction_required'], true)) {
            return ['ok' => false, 'error' => 'Google login was cancelled.', 'code' => 'cancelled'];
        }
        return ['ok' => false, 'error' => $generic];
    }

    $code  = (string)($query['code'] ?? '');
    $state = (string)($query['state'] ?? '');
    if ($code === '' || $state === '' || !is_array($ctx)) {
        sh_log_line('google-auth', 'Callback without code/state or without a pending session context.');
        return ['ok' => false, 'error' => $generic];
    }
    if ((time() - (int)($ctx['at'] ?? 0)) > 600) {
        sh_log_line('google-auth', 'Callback rejected: OAuth context older than 10 minutes.');
        return ['ok' => false, 'error' => $generic];
    }
    if (!hash_equals((string)$ctx['state'], $state)) {
        sh_log_line('google-auth', 'Callback rejected: state mismatch (possible CSRF).');
        sh_security_log('google_login_state_mismatch');
        return ['ok' => false, 'error' => $generic];
    }

    // ---- Exchange the authorization code (server-to-server, secret never leaves PHP)
    $tok = sh_google_http('POST', SH_GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => sh_google_client_id(),
        'client_secret' => sh_google_client_secret(),
        'redirect_uri'  => sh_google_callback_url(),
        'grant_type'    => 'authorization_code',
        'code_verifier' => (string)$ctx['verifier'],
    ]);
    $tokenData = json_decode($tok['body'] ?? '', true);
    if (!$tok['ok'] || !is_array($tokenData) || empty($tokenData['id_token'])) {
        $why = is_array($tokenData) ? (($tokenData['error'] ?? '') . ' ' . ($tokenData['error_description'] ?? '')) : ($tok['error'] ?? '');
        sh_log_line('google-auth', 'Token exchange failed (HTTP ' . (int)($tok['status'] ?? 0) . '): ' . trim((string)$why));
        return ['ok' => false, 'error' => $generic];
    }

    // ---- Verify the ID token
    $claims = sh_google_verify_id_token((string)$tokenData['id_token'], (string)$ctx['nonce']);
    if ($claims === null) {
        return ['ok' => false, 'error' => 'Google account verification failed.'];
    }

    $emailVerified = $claims['email_verified'] ?? false;
    $emailVerified = $emailVerified === true || $emailVerified === 'true' || $emailVerified === 1 || $emailVerified === '1';
    $email = strtolower(trim((string)($claims['email'] ?? '')));
    if (!$emailVerified || $email === '' || !sh_valid_email($email)) {
        sh_log_line('google-auth', 'Rejected: email missing or not verified for sub ' . substr((string)($claims['sub'] ?? ''), 0, 6) . '…');
        return ['ok' => false, 'error' => 'Google account verification failed.'];
    }

    return ['ok' => true, 'profile' => [
        'sub'     => (string)$claims['sub'],
        'email'   => $email,
        'name'    => trim((string)($claims['name'] ?? '')) ?: (trim((string)($claims['given_name'] ?? '') . ' ' . (string)($claims['family_name'] ?? '')) ?: strstr($email, '@', true)),
        'picture' => (string)($claims['picture'] ?? ''),
    ], 'redirect' => (string)($ctx['redirect'] ?? '')];
}

/**
 * Validates signature (RS256 via Google JWKS), iss, aud, exp, iat and nonce.
 * Returns the claims or null. Falls back to Google's tokeninfo endpoint only if
 * OpenSSL is unavailable on the host.
 */
function sh_google_verify_id_token(string $jwt, string $expectedNonce): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) { sh_log_line('google-auth', 'ID token malformed.'); return null; }
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(sh_google_b64url_decode($h64), true);
    $claims = json_decode(sh_google_b64url_decode($p64), true);
    if (!is_array($header) || !is_array($claims)) { sh_log_line('google-auth', 'ID token JSON invalid.'); return null; }

    $sigOk = false;
    if (function_exists('openssl_verify') && ($header['alg'] ?? '') === 'RS256' && !empty($header['kid'])) {
        $pem = sh_google_jwk_pem((string)$header['kid']);
        if ($pem !== null) {
            $sigOk = openssl_verify($h64 . '.' . $p64, sh_google_b64url_decode($s64), $pem, OPENSSL_ALGO_SHA256) === 1;
        }
        if (!$sigOk) { sh_log_line('google-auth', 'ID token signature verification failed (kid ' . $header['kid'] . ').'); return null; }
    } else {
        // Host without OpenSSL: let Google validate the token over TLS.
        $r = sh_google_http('GET', 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($jwt));
        $info = json_decode($r['body'] ?? '', true);
        if (!$r['ok'] || !is_array($info) || ($info['sub'] ?? '') !== ($claims['sub'] ?? '')) {
            sh_log_line('google-auth', 'tokeninfo fallback verification failed.');
            return null;
        }
        $claims = $info;
    }

    $now = time();
    if (!in_array((string)($claims['iss'] ?? ''), SH_GOOGLE_ISSUERS, true)) { sh_log_line('google-auth', 'ID token issuer rejected.'); return null; }
    if ((string)($claims['aud'] ?? '') !== sh_google_client_id()) { sh_log_line('google-auth', 'ID token audience mismatch.'); return null; }
    if ((int)($claims['exp'] ?? 0) < $now - 60) { sh_log_line('google-auth', 'ID token expired.'); return null; }
    if (isset($claims['iat']) && (int)$claims['iat'] > $now + 300) { sh_log_line('google-auth', 'ID token issued in the future.'); return null; }
    if (!hash_equals($expectedNonce, (string)($claims['nonce'] ?? ''))) { sh_log_line('google-auth', 'ID token nonce mismatch.'); return null; }
    if (empty($claims['sub'])) { return null; }
    return $claims;
}

/** Fetches Google's JWKS (cached ~6h in logs dir) and returns the PEM for a key id. */
function sh_google_jwk_pem(string $kid): ?string
{
    $cache = SH_LOG_DIR . '/google-jwks.json';
    $keys = null;
    if (is_file($cache) && (time() - (int)filemtime($cache)) < 21600) {
        $keys = json_decode((string)@file_get_contents($cache), true);
    }
    $find = static function ($keys) use ($kid) {
        foreach (($keys['keys'] ?? []) as $k) { if (($k['kid'] ?? '') === $kid) { return $k; } }
        return null;
    };
    $jwk = is_array($keys) ? $find($keys) : null;
    if ($jwk === null) { // unknown kid or stale cache: refresh
        $r = sh_google_http('GET', SH_GOOGLE_JWKS_URL);
        $keys = json_decode($r['body'] ?? '', true);
        if ($r['ok'] && is_array($keys) && !empty($keys['keys'])) {
            @file_put_contents($cache, json_encode($keys));
            $jwk = $find($keys);
        }
    }
    if ($jwk === null || ($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) { return null; }
    return sh_google_rsa_pem(sh_google_b64url_decode($jwk['n']), sh_google_b64url_decode($jwk['e']));
}

/** Builds a PEM public key from RSA modulus + exponent (DER, no extensions needed). */
function sh_google_rsa_pem(string $n, string $e): string
{
    $len = static function (int $l): string {
        if ($l < 0x80) { return chr($l); }
        $tmp = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($tmp)) . $tmp;
    };
    $int = static function (string $x) use ($len): string {
        if ($x !== '' && (ord($x[0]) & 0x80)) { $x = "\0" . $x; }
        return "\x02" . $len(strlen($x)) . $x;
    };
    $rsaSeq = $int($n) . $int($e);
    $rsaKey = "\x30" . $len(strlen($rsaSeq)) . $rsaSeq;
    $bitStr = "\x03" . $len(strlen($rsaKey) + 1) . "\0" . $rsaKey;
    $algId  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki   = "\x30" . $len(strlen($algId . $bitStr)) . $algId . $bitStr;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/* ------------------------------------------------------------------ *
 * Step 3 — map the verified Google profile to a local customer account
 *
 * Returns ['ok'=>true,'user_id'=>int,'created'=>bool] or ['ok'=>false,'error'=>msg].
 * ------------------------------------------------------------------ */

function sh_google_resolve_user(array $p): array
{
    $sub = $p['sub']; $email = $p['email'];

    // 1. Already linked by Google ID.
    $u = sh_one('SELECT id, status, avatar, avatar_source, name FROM users WHERE google_id = ? LIMIT 1', [$sub]);
    if ($u === null) {
        // 2. Existing account with the same (verified) email → link, never duplicate.
        $u = sh_one('SELECT id, status, avatar, avatar_source, name, google_id FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($u !== null && !empty($u['google_id']) && $u['google_id'] !== $sub) {
            sh_log_line('google-auth', 'Email already linked to a different Google account (user #' . (int)$u['id'] . ').');
            return ['ok' => false, 'error' => 'Google account verification failed.'];
        }
        if ($u !== null) {
            if ($u['status'] !== 'active') {
                return ['ok' => false, 'error' => 'This account has been blocked. Please contact customer support.'];
            }
            $data = ['google_id' => $sub];
            if (empty($u['avatar']) && $p['picture'] !== '') { $data['avatar'] = $p['picture']; $data['avatar_source'] = 'google'; }
            sh_update('users', $data, 'id = ?', [(int)$u['id']]);
            require_once SH_ROOT . '/includes/firebase.php';
            sh_fb_mark_verified((int)$u['id'], 'google'); // Google already asserted email_verified
            sh_security_log('google_account_linked', (int)$u['id'], ['email' => mb_substr($email, 0, 3) . '***']);
            return ['ok' => true, 'user_id' => (int)$u['id'], 'created' => false];
        }

        // 3. Brand-new customer. password_hash is NOT NULL in the schema, so a
        //    random unguessable secret is stored; the user can set a real one via
        //    "Forgot password" whenever they wish.
        $uid = sh_insert('users', [
            'name'          => mb_substr($p['name'] !== '' ? $p['name'] : 'Google User', 0, 120),
            'email'         => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'status'        => 'active',
            'auth_provider' => 'google',
            'google_id'     => $sub,
            'avatar'        => $p['picture'] !== '' ? mb_substr($p['picture'], 0, 500) : null,
            'avatar_source' => $p['picture'] !== '' ? 'google' : null,
        ]);
        require_once SH_ROOT . '/includes/firebase.php';
        sh_fb_mark_verified($uid, 'google'); // Google already asserted email_verified
        sh_security_log('account_created', $uid, ['email' => mb_substr($email, 0, 3) . '***', 'via' => 'google']);
                    try { require_once SH_ROOT . '/includes/admin-tools.php'; sh_admin_notify('new_customer', 'New customer registered', (string)$p['name'], 'admin/customers.php?id=' . $uid); } catch (Throwable $e) {}
        return ['ok' => true, 'user_id' => $uid, 'created' => true];
    }

    if ($u['status'] !== 'active') {
        return ['ok' => false, 'error' => 'This account has been blocked. Please contact customer support.'];
    }
    // Refresh the Google picture only when the customer hasn't uploaded their own.
    if ($p['picture'] !== '' && (empty($u['avatar']) || ($u['avatar_source'] ?? '') === 'google') && $u['avatar'] !== $p['picture']) {
        sh_update('users', ['avatar' => mb_substr($p['picture'], 0, 500), 'avatar_source' => 'google'], 'id = ?', [(int)$u['id']]);
    }
    return ['ok' => true, 'user_id' => (int)$u['id'], 'created' => false];
}

/* ------------------------------------------------------------------ *
 * Presentation helpers
 * ------------------------------------------------------------------ */

/** Human label for admin screens. Google ID itself is never rendered. */
function sh_user_login_method(array $u): string
{
    if (!empty($u['google_id']) || ($u['auth_provider'] ?? '') === 'google') { return 'Google'; }
    if (function_exists('sh_is_synthetic_email') && sh_is_synthetic_email((string)($u['email'] ?? ''))) { return 'Phone + OTP'; }
    return 'Email/Password';
}

/** Avatar URL for a user row (only http(s) Google URLs are allowed to be external). */
function sh_user_avatar_url(array $u): string
{
    $a = trim((string)($u['avatar'] ?? ''));
    if ($a === '') { return ''; }
    if (preg_match('#^https://[a-z0-9.-]*googleusercontent\.com/#i', $a)) { return $a; }
    if (preg_match('#^https?://#i', $a)) { return ''; } // unknown external host: don't hot-link
    return sh_url('uploads/avatars/' . ltrim($a, '/'));
}

/** Renders the Google "G" mark as inline SVG (official brand colours, no external asset). */
function sh_google_g_svg(int $size = 18): string
{
    return '<svg class="sh-google-btn__logo" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
        . '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
        . '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
        . '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
        . '</svg>';
}

/**
 * Renders the "Continue with Google" block (divider + button). Outputs nothing
 * when the feature is disabled, so callers can include it unconditionally.
 */
function sh_google_button(string $redirect = '', string $label = 'Continue with Google'): string
{
    if (!sh_google_enabled()) { return ''; }
    $href = sh_url('auth/google/login.php' . ($redirect !== '' ? '?redirect=' . urlencode($redirect) : ''));
    return '<div class="sh-auth__or"><span>or</span></div>'
        . '<a class="sh-google-btn" href="' . e($href) . '" rel="nofollow">'
        . sh_google_g_svg(18)
        . '<span class="sh-google-btn__text">' . e($label) . '</span></a>';
}

/* ------------------------------------------------------------------ *
 * Admin: connection test (no browser round-trip needed)
 * ------------------------------------------------------------------ */

function sh_google_test_connection(): array
{
    $checks = [];
    $cid = sh_google_client_id();
    $checks[] = ['label' => 'Google Login enabled', 'ok' => (string)sh_setting('google_login_enabled', '0') === '1',
                 'value' => (string)sh_setting('google_login_enabled', '0') === '1' ? 'ON' : 'OFF'];
    $cidOk = $cid !== '' && (bool)preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $cid);
    $checks[] = ['label' => 'Client ID format', 'ok' => $cidOk,
                 'value' => $cid === '' ? 'Not set' : ($cidOk ? 'Looks valid' : 'Unexpected format (should end with .apps.googleusercontent.com)')];
    $checks[] = ['label' => 'Client Secret', 'ok' => sh_google_has_secret(),
                 'value' => sh_google_has_secret() ? 'Stored (encrypted)' : 'Not set'];
    $https = sh_google_is_https() || sh_google_is_localhost();
    $checks[] = ['label' => 'HTTPS', 'ok' => $https,
                 'value' => sh_google_is_https() ? 'Secure' : (sh_google_is_localhost() ? 'localhost (allowed by Google for testing)' : 'Not secure — Google requires https:// redirect URIs')];
    $checks[] = ['label' => 'OpenSSL (token signature check)', 'ok' => function_exists('openssl_verify'),
                 'value' => function_exists('openssl_verify') ? 'Available' : 'Missing — tokeninfo fallback will be used'];
    $checks[] = ['label' => 'cURL or stream fallback', 'ok' => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
                 'value' => function_exists('curl_init') ? 'cURL' : ((bool)ini_get('allow_url_fopen') ? 'Stream wrapper' : 'Neither available')];

    $r = sh_google_http('GET', SH_GOOGLE_JWKS_URL, [], 10);
    $keys = json_decode($r['body'] ?? '', true);
    $reach = $r['ok'] && is_array($keys) && !empty($keys['keys']);
    $checks[] = ['label' => 'Reach Google servers', 'ok' => $reach,
                 'value' => $reach ? 'OK (' . count($keys['keys']) . ' signing keys)' : ('Failed: ' . ($r['error'] ?: 'unexpected response'))];

    // Validates the client id/secret pair without a user: Google answers
    // invalid_grant for a bogus code when credentials are right, invalid_client when not.
    $pair = ['ok' => false, 'value' => 'Skipped'];
    if ($cid !== '' && sh_google_has_secret()) {
        $t = sh_google_http('POST', SH_GOOGLE_TOKEN_URL, [
            'code' => 'shophaat-connection-test', 'client_id' => $cid, 'client_secret' => sh_google_client_secret(),
            'redirect_uri' => sh_google_callback_url(), 'grant_type' => 'authorization_code',
        ], 10);
        $j = json_decode($t['body'] ?? '', true);
        $err = is_array($j) ? (string)($j['error'] ?? '') : '';
        if ($err === 'invalid_grant') { $pair = ['ok' => true, 'value' => 'Client ID and Secret accepted by Google']; }
        elseif ($err === 'invalid_client' || $err === 'unauthorized_client') { $pair = ['ok' => false, 'value' => 'Google rejected the Client ID / Secret pair']; }
        elseif ($err === 'redirect_uri_mismatch') { $pair = ['ok' => false, 'value' => 'Redirect URI is not registered in Google Cloud Console']; }
        else { $pair = ['ok' => false, 'value' => 'Could not confirm (' . ($err !== '' ? $err : ($t['error'] ?: 'no response')) . ')']; }
    }
    $checks[] = ['label' => 'Credentials check', 'ok' => $pair['ok'], 'value' => $pair['value']];

    $allOk = true;
    foreach ($checks as $c) { if (!$c['ok']) { $allOk = false; break; } }
    return ['ok' => $allOk, 'checks' => $checks];
}
