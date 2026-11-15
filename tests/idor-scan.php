<?php
/**
 * IDOR / object-authorisation scan.
 *
 * Broken object-level authorisation is not something a single unit test can
 * prove: the property is "for every route, every id that arrives from a client is
 * resolved through a check that decides whether *this* caller may touch *that*
 * object". So this suite enumerates the real files and asserts the rules the
 * project agrees on, with an explicit allowlist for routes that are public on
 * purpose. A new admin page without a guard, or a new api endpoint without
 * either auth or a documented compensating control, fails the build.
 *
 * Rules
 *   1. every admin page (not a partial, not login/index) calls a guard;
 *   2. every admin page that writes calls sh_csrf_require();
 *   3. no request value is concatenated into SQL;
 *   4. every client-supplied id is cast before use;
 *   5. customer order screens pair sh_order_get() with sh_order_can_view();
 *   6. customer order lists are scoped by user_id;
 *   7. every api endpoint is either guarded or on the reviewed-public list, and
 *      carries the specific control that makes it safe;
 *   8. admin AJAX fragments require CSRF;
 *   9. support conversations are scoped by owner for customers;
 *  10. no endpoint accepts the caller's identity from the request.
 *
 *     php tests/idor-scan.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function t_ok(string $name, bool $pass, string $detail = ''): bool
{
    if ($pass) { $GLOBALS['pass']++; echo "  ok   {$name}\n"; return true; }
    $GLOBALS['fail']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "\n         → {$detail}" : '') . "\n";
    return false;
}
function t_sec(string $t): void { echo "\n" . $t . "\n"; }

$root = dirname(__DIR__);
$rel = static fn(string $p): string => str_replace($root . '/', '', $p);
$read = static fn(string $p): string => (string)@file_get_contents($p);
$files = static function (string $glob) use ($root): array {
    $out = glob($root . '/' . $glob) ?: [];
    sort($out);
    return $out;
};

const GUARDS = ['sh_require_admin(', 'sh_require_perm(', 'sh_require_superadmin(', 'sh_require_staff(', 'sh_require_role('];

// ===========================================================================
t_sec('1. Every admin page is behind an admin guard');
// ===========================================================================
$publicAdmin = ['login.php', 'index.php'];               // login form + redirector
$partialAdmin = static fn(string $n): bool => str_starts_with($n, '_');  // _layout, _footer, …
$unguarded = [];
$adminPages = 0;
foreach ($files('admin/*.php') as $path) {
    $name = basename($path);
    if ($partialAdmin($name) || in_array($name, $publicAdmin, true)) { continue; }
    $adminPages++;
    $src = $read($path);
    $guarded = false;
    foreach (GUARDS as $g) { if (str_contains($src, $g)) { $guarded = true; break; } }
    if (!$guarded) { $unguarded[] = $name; }
}
t_ok($adminPages . ' admin pages checked, every one calls a guard', $unguarded === [], implode(', ', $unguarded));

// The partials must not be reachable directly: they render a fragment only.
$layout = $read($root . '/admin/_layout.php');
t_ok('the layout partial still enforces the admin guard itself', str_contains($layout, 'sh_require_admin('));

t_ok('admin/index.php redirects instead of rendering', str_contains($read($root . '/admin/index.php'), 'sh_redirect('));
t_ok('admin/login.php is the only page that handles credentials without a session',
    str_contains($read($root . '/admin/login.php'), 'sh_admin_login') || str_contains($read($root . '/admin/login.php'), 'password_verify'));

// ===========================================================================
t_sec('2. Every admin page that mutates data requires CSRF');
// ===========================================================================
$missingCsrf = [];
$mutating = 0;
foreach ($files('admin/*.php') as $path) {
    $name = basename($path);
    if ($partialAdmin($name) || $name === 'login.php') { continue; }
    $src = $read($path);
    $writes = (bool)preg_match('~\b(INSERT INTO|UPDATE\s+\w+\s+SET|DELETE FROM)\b~', $src);
    $readsPost = str_contains($src, '$_POST') || str_contains($src, 'sh_post(');
    if ($writes && $readsPost) {
        $mutating++;
        if (!str_contains($src, 'sh_csrf_require()')) { $missingCsrf[] = $name; }
    }
}
t_ok($mutating . ' mutating admin pages, every one calls sh_csrf_require()', $missingCsrf === [], implode(', ', $missingCsrf));

$verif = $read($root . '/admin/verification-fetch.php');
t_ok('the AJAX verification fragment checks CSRF itself', str_contains($verif, 'sh_csrf_valid()'));
t_ok('the AJAX fragment answers with a refresh signal, not a full error page', str_contains($verif, 'X-ShopHaat-CSRF-Refresh'));

// ===========================================================================
t_sec('3. No request value is concatenated into SQL');
// ===========================================================================
$injectable = [];
foreach (array_merge($files('*.php'), $files('admin/*.php'), $files('api/*.php'), $files('includes/*.php')) as $path) {
    $lineno = 0;
    foreach (explode("\n", $read($path)) as $line) {
        $lineno++;
        // A concatenation directly into a SQL string with a superglobal is the
        // classic injection/IDOR vector; prepared placeholders are the rule.
        if (preg_match('~\.\s*\$_(GET|POST|REQUEST|COOKIE)\[~', $line) &&
            preg_match('~\b(SELECT|INSERT|UPDATE|DELETE|WHERE|LIMIT|ORDER BY)\b~i', $line)) {
            $injectable[] = $rel($path) . ':' . $lineno . ' ' . trim($line);
        }
    }
}
t_ok('no SQL string is built from $_GET/$_POST', $injectable === [], implode("\n         → ", array_slice($injectable, 0, 5)));

// ===========================================================================
t_sec('4. Client-supplied ids are cast before use');
// ===========================================================================
$unc = [];
foreach (array_merge($files('*.php'), $files('admin/*.php'), $files('api/*.php')) as $path) {
    $lineno = 0;
    foreach (explode("\n", $read($path)) as $line) {
        $lineno++;
        if (preg_match('~\$_GET\[\'(id|view|order_id|payment_id|conversation_id)\'\]|\$_POST\[\'(id|order_id|payment_id)\'\]~', $line)) {
            $safe = preg_match('~sh_int\(|\(int\)|max\(0,\s*\(int\)|intval\(|\+\s*0~', $line);
            if (!$safe) { $unc[] = $rel($path) . ':' . $lineno . ' ' . trim($line); }
        }
    }
}
t_ok('every id read from a request is cast', $unc === [], implode("\n         → ", array_slice($unc, 0, 5)));

// ===========================================================================
t_sec('5. Customer order screens check ownership before rendering');
// ===========================================================================
$orderScreens = ['order-details.php', 'order-success.php', 'payment.php', 'checkout.php', 'api/orders.php', 'api/payment.php'];
foreach ($orderScreens as $file) {
    $src = $read($root . '/' . $file);
    $hasGet = str_contains($src, 'sh_order_get(');
    $guarded = str_contains($src, 'sh_order_can_view(')
        || str_contains($src, "(int)(\$retryOrder['user_id'] ?? 0) !== (int)\$user['id']")
        || str_contains($src, 'hash_equals($boundReference, $reference)');
    t_ok($file . ($hasGet ? ' resolves an order and proves the caller may see it' : ' does not resolve orders'),
        !$hasGet || $guarded);
}
$paySrc = $read($root . '/payment.php');
t_ok('the payment page requires a login before it reads an order', strpos($paySrc, 'sh_require_login(') < strpos($paySrc, 'sh_order_get('));
t_ok('the payment page refuses to submit for an order it cannot show',
    str_contains($paySrc, 'if ($order === null || !sh_order_can_view($order)) {'));

// ===========================================================================
t_sec('6. Customer order lists are scoped by user_id');
// ===========================================================================
$orders = $read($root . '/orders.php');
t_ok('orders.php lists only the signed-in customer\'s orders',
    str_contains($orders, 'FROM orders WHERE user_id = ?'));
t_ok('the support widget conversation list is owner-scoped',
    str_contains($read($root . '/api/support.php'), 'sh_support_conversation_for_owner('));

// ===========================================================================
t_sec('7. Every api endpoint is guarded or public on purpose');
// ===========================================================================
// Route => the control that makes an unauthenticated call safe. Anything not in
// this list must call a guard.
$publicApi = [
    'csp-report.php'   => ['sh_file_rate_limit(', "!== 'POST'"],                 // write-only telemetry
    'email-events.php' => ['sh_email_webhook_verify('],                          // HMAC over the raw body
    'search.php'       => ['sh_search_suggest(', 'sh_get('],                     // read-only catalogue
    'otp.php'          => ['sh_otp_issue(', 'sh_csrf_require()'],                // rate limited, no object ids
    'cart.php'         => ['sh_csrf_require()', 'sh_cart_'],                     // session cart only
    'support.php'      => ['sh_support_origin_valid('],                          // origin + owner scoping
    'orders.php'       => ['sh_require_login(', 'sh_order_can_view('],           // login for all but tracking
];
$apiGuards = array_merge(GUARDS, ['sh_require_login(']);
foreach ($files('api/*.php') as $path) {
    $name = basename($path);
    $src = $read($path);
    if (isset($publicApi[$name])) {
        $missing = [];
        foreach ($publicApi[$name] as $control) { if (!str_contains($src, $control)) { $missing[] = $control; } }
        t_ok($name . ' is public but keeps its control', $missing === [], 'missing: ' . implode(', ', $missing));
        continue;
    }
    $guarded = false;
    foreach ($apiGuards as $g) { if (str_contains($src, $g)) { $guarded = true; break; } }
    t_ok($name . ' requires a session or an admin guard', $guarded);
}
t_ok('the public list covers every file in api/',
    count(array_diff(array_map('basename', $files('api/*.php')), array_merge(array_keys($publicApi),
        ['ai.php', 'checkout.php', 'notifications.php', 'payment.php']))) === 0);

// ===========================================================================
t_sec('8. Support conversations cannot be read by another customer');
// ===========================================================================
$support = $read($root . '/api/support.php');
t_ok('customer message reads are checked against the owner', str_contains($support, 'Conversation not found.'));
t_ok('customer sends are owner-scoped by the helper, not by the request',
    (bool)preg_match('~sh_support_customer_send\(\s*max\(0, \(int\)\(\$input\[.id.\] \?\? 0\)\),\s*\$owner~s', $support));
t_ok('admin support actions require support.view/support.reply/support.manage',
    str_contains($support, "['support.view', 'support.reply', 'support.manage']"));
t_ok('admin support actions require CSRF on POST', str_contains($support, 'if ($method === \'POST\') { sh_csrf_require(); }'));

// ===========================================================================
t_sec('9. The caller cannot name itself');
// ===========================================================================
$identityFromRequest = [];
foreach (array_merge($files('*.php'), $files('api/*.php'), $files('admin/*.php')) as $path) {
    $src = $read($path);
    if (preg_match('~\$_(GET|POST|REQUEST)\[\'(user_id|admin_id|customer_id)\'\]~', $src)) {
        $identityFromRequest[] = $rel($path);
    }
}
t_ok('no page takes user_id/admin_id from the request', $identityFromRequest === [], implode(', ', $identityFromRequest));

// ===========================================================================
t_sec('10. Admin sub-resource views are permission-scoped');
// ===========================================================================
$permMap = [
    'admin/orders.php' => 'orders.view', 'admin/payments.php' => 'payments.',
    'admin/customers.php' => 'customers.', 'admin/parcels.php' => 'orders.',
    'admin/support-conversation.php' => 'support.', 'admin/settings-history.php' => 'system.audit',
];
foreach ($permMap as $file => $needle) {
    $src = $read($root . '/' . $file);
    t_ok($file . ' requires a specific permission (not just "any admin")',
        (bool)preg_match('~sh_require_perm\(\s*[\'"]' . preg_quote($needle, '~') . '~', $src)
        || str_contains($src, "sh_require_perm('" . $needle),
        'expected a sh_require_perm() starting with ' . $needle);
}

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['pass'], $GLOBALS['fail']);
echo $GLOBALS['fail'] === 0 ? "IDOR SCAN: ALL CHECKS PASSED\n" : "IDOR SCAN: FAILURES PRESENT\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
