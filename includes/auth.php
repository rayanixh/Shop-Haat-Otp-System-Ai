<?php
/**
 * Customer authentication + cart ownership.
 */

function sh_login_user(int $userId): void
{
    sh_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_login_at'] = time();
    sh_query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
    sh_merge_guest_cart($userId);
}

function sh_logout_user(): void
{
    sh_session_start();
    unset($_SESSION['user_id'], $_SESSION['user_login_at']);
    session_regenerate_id(true);
}

function sh_user(): ?array
{
    static $user = null;
    static $done = false;
    if ($done) { return $user; }
    $done = true;
    sh_session_start();
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) { return null; }
    // Server-side 24h session: the login expires automatically, after which the
    // customer must sign in again with phone + OTP.
    $loginAt = (int)($_SESSION['user_login_at'] ?? 0);
    if ($loginAt > 0 && (time() - $loginAt) > sh_session_ttl()) {
        unset($_SESSION['user_id'], $_SESSION['user_login_at']);
        return null;
    }
    try {
        $u = sh_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($u !== null) { unset($u['password_hash'], $u['firebase_secret']); }
    } catch (Throwable $e) {
        sh_log_exception($e, 'auth');
        return null;
    }
    if ($u === null || $u['status'] !== 'active') {
        unset($_SESSION['user_id'], $_SESSION['user_login_at']);
        return null;
    }
    $user = $u;
    return $user;
}

function sh_user_id(): int
{
    $u = sh_user();
    return $u ? (int)$u['id'] : 0;
}

function sh_require_login(string $redirectTo = ''): array
{
    $u = sh_user();
    if ($u === null) {
        if (sh_wants_json()) {
            sh_json(['success' => false, 'error' => 'Please sign in to continue.', 'auth_required' => true], 401);
        }
        $target = $redirectTo !== '' ? $redirectTo : ($_SERVER['REQUEST_URI'] ?? '');
        sh_redirect('login.php?redirect=' . urlencode($target));
    }
    return $u;
}

/**
 * Guest carts are keyed by a random cookie token, then merged on login.
 */
function sh_cart_token(): string
{
    sh_session_start();
    if (!empty($_SESSION['cart_token'])) { return $_SESSION['cart_token']; }
    $cookie = $_COOKIE['sh_cart'] ?? '';
    if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        $_SESSION['cart_token'] = $cookie;
        return $cookie;
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['cart_token'] = $token;
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('sh_cart', $token, [
            'expires'  => time() + 86400 * 30,
            'path'     => sh_base_url() . '/',
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);
    }
    return $token;
}

/** Returns the current cart id, creating one when needed. */
function sh_cart_id(bool $create = true): int
{
    static $id = null;
    if ($id !== null && $id > 0) { return $id; }
    $uid = sh_user_id();
    if ($uid > 0) {
        $row = sh_one('SELECT id FROM cart WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
        if ($row) { return $id = (int)$row['id']; }
        if (!$create) { return 0; }
        return $id = sh_insert('cart', ['user_id' => $uid]);
    }
    $token = sh_cart_token();
    $row = sh_one('SELECT id FROM cart WHERE session_token = ? AND user_id IS NULL ORDER BY id DESC LIMIT 1', [$token]);
    if ($row) { return $id = (int)$row['id']; }
    if (!$create) { return 0; }
    return $id = sh_insert('cart', ['session_token' => $token]);
}

function sh_merge_guest_cart(int $userId): void
{
    sh_session_start();
    $token = $_SESSION['cart_token'] ?? ($_COOKIE['sh_cart'] ?? '');
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) { return; }
    try {
        $guest = sh_one('SELECT id FROM cart WHERE session_token = ? AND user_id IS NULL ORDER BY id DESC LIMIT 1', [$token]);
        if ($guest === null) { return; }
        $guestId = (int)$guest['id'];
        $own = sh_one('SELECT id FROM cart WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        if ($own === null) {
            sh_query('UPDATE cart SET user_id = ?, session_token = NULL WHERE id = ?', [$userId, $guestId]);
            return;
        }
        $ownId = (int)$own['id'];
        foreach (sh_all('SELECT product_id, quantity FROM cart_items WHERE cart_id = ?', [$guestId]) as $it) {
            sh_query(
                'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), 99)',
                [$ownId, $it['product_id'], $it['quantity']]
            );
        }
        sh_query('DELETE FROM cart WHERE id = ?', [$guestId]);
    } catch (Throwable $e) {
        sh_log_exception($e, 'cart-merge');
    }
}

/* ---------- Shared login throttling ----------------------------------
 * Used by BOTH the customer and admin sign-in forms, so it lives here
 * in auth.php rather than admin-auth.php.
 * ------------------------------------------------------------------ */
/** Simple session-based login throttle (no extra table needed). */
function sh_login_throttled(string $bucket, int $max = 6, int $window = 900): bool
{
    sh_session_start();
    $key = 'throttle_' . $bucket;
    $d = $_SESSION[$key] ?? ['n' => 0, 't' => time()];
    if (time() - (int)$d['t'] > $window) { return false; }
    return (int)$d['n'] >= $max;
}
function sh_login_fail(string $bucket): void
{
    sh_session_start();
    $key = 'throttle_' . $bucket;
    $d = $_SESSION[$key] ?? ['n' => 0, 't' => time()];
    if (time() - (int)$d['t'] > 900) { $d = ['n' => 0, 't' => time()]; }
    $d['n'] = (int)$d['n'] + 1;
    $_SESSION[$key] = $d;
}
function sh_login_reset(string $bucket): void
{
    sh_session_start();
    unset($_SESSION['throttle_' . $bucket]);
}
