<?php
/**
 * Trusted-proxy / client-IP resolution tests.
 *
 * The rate limiters for OTP, customer login, admin login, support chat and the
 * installer all key on sh_client_ip(). If a caller can choose that value, every
 * one of those limits can be reset at will, so this suite asserts the exact
 * trust boundary rather than the happy path only.
 *
 * Runs without a database: includes/client-ip.php has no dependencies and the
 * settings are supplied by the sh_setting() stub below.
 *
 *     php tests/trusted-proxy.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$GLOBALS['sh_test_settings'] = [];
$GLOBALS['sh_test_failures'] = 0;
$GLOBALS['sh_test_passes'] = 0;

/** Stand-in for the database-backed settings reader. */
function sh_setting(string $key, $default = '')
{
    return array_key_exists($key, $GLOBALS['sh_test_settings']) ? $GLOBALS['sh_test_settings'][$key] : $default;
}

require_once __DIR__ . '/../includes/client-ip.php';

function t_ok(string $name, bool $pass, string $detail = ''): void
{
    if ($pass) {
        $GLOBALS['sh_test_passes']++;
        echo "  ok   {$name}\n";
        return;
    }
    $GLOBALS['sh_test_failures']++;
    echo "  FAIL {$name}" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
}

function t_eq(string $name, $expected, $actual): bool
{
    t_ok($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    return $expected === $actual;
}

function t_sec(string $title): void
{
    echo "\n" . $title . "\n";
}

/**
 * Resolve a client IP for a synthetic request.
 *
 * @param array<string,string> $server   $_SERVER keys to set (REMOTE_ADDR etc.)
 * @param array<string,string> $settings proxy settings for this case
 */
function ip_case(array $server, array $settings = [], ?string $envMode = null): string
{
    $GLOBALS['sh_test_settings'] = $settings;
    $saved = $_SERVER;
    foreach (['REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO'] as $k) {
        unset($_SERVER[$k]);
    }
    foreach ($server as $k => $v) { $_SERVER[$k] = $v; }
    $oldEnv = getenv('SH_PROXY_MODE');
    if ($envMode === null) { putenv('SH_PROXY_MODE'); } else { putenv('SH_PROXY_MODE=' . $envMode); }
    $ip = sh_client_ip();
    if ($oldEnv === false) { putenv('SH_PROXY_MODE'); } else { putenv('SH_PROXY_MODE=' . $oldEnv); }
    $_SERVER = $saved;
    $GLOBALS['sh_test_settings'] = [];
    return $ip;
}

// ===========================================================================
t_sec('1. Address parsing');
// ===========================================================================
t_eq('a plain IPv4 address is accepted', '203.0.113.7', sh_ip_normalize('203.0.113.7'));
t_eq('surrounding whitespace is ignored', '203.0.113.7', sh_ip_normalize(" 203.0.113.7 "));
t_eq('an IPv4 address with a port is unwrapped', '203.0.113.7', sh_ip_normalize('203.0.113.7:53121'));
t_eq('a bracketed IPv6 address with a port is unwrapped', '2001:db8::1', sh_ip_normalize('[2001:db8::1]:443'));
t_eq('a bare IPv6 address is accepted', '2001:db8::1', sh_ip_normalize('2001:DB8::1'));
t_eq('a hostname is rejected', '', sh_ip_normalize('evil.example.com'));
t_eq('an empty value is rejected', '', sh_ip_normalize(''));
t_eq('a trailing-garbage value is rejected', '', sh_ip_normalize('203.0.113.7<script>'));

t_ok('a public address is recognised', sh_ip_is_public('203.0.113.7'));
t_ok('loopback is not public', !sh_ip_is_public('127.0.0.1'));
t_ok('IPv6 loopback is not public', !sh_ip_is_public('::1'));
t_ok('RFC1918 10/8 is not public', !sh_ip_is_public('10.1.2.3'));
t_ok('RFC1918 192.168/16 is not public', !sh_ip_is_public('192.168.1.10'));
t_ok('link-local is not public', !sh_ip_is_public('169.254.1.1'));
t_ok('IPv6 unique-local is not public', !sh_ip_is_public('fd00::1'));

// ===========================================================================
t_sec('2. CIDR matching');
// ===========================================================================
t_ok('an address inside its own /32 matches', sh_ip_in_cidr('203.0.113.7', '203.0.113.7'));
t_ok('an address outside a /32 does not match', !sh_ip_in_cidr('203.0.113.8', '203.0.113.7'));
t_ok('an address inside a /24 matches', sh_ip_in_cidr('198.51.100.200', '198.51.100.0/24'));
t_ok('an address outside a /24 does not match', !sh_ip_in_cidr('198.51.101.1', '198.51.100.0/24'));
t_ok('a /20 boundary is respected inside', sh_ip_in_cidr('173.245.48.15', '173.245.48.0/20'));
t_ok('a /20 boundary is respected outside', !sh_ip_in_cidr('173.245.63.255', '173.245.48.0/21'));
t_ok('an IPv6 /32 matches', sh_ip_in_cidr('2400:cb00::1', '2400:cb00::/32'));
t_ok('an IPv6 /32 does not match a different prefix', !sh_ip_in_cidr('2400:cb01::1', '2400:cb00::/32'));
t_ok('an IPv4 address never matches an IPv6 range', !sh_ip_in_cidr('203.0.113.7', '::/0'));
t_ok('a malformed range is rejected, not treated as /0', !sh_ip_in_cidr('203.0.113.7', '203.0.113.0/999'));

// ===========================================================================
t_sec('3. Forwarding headers are ignored without a trusted proxy');
// ===========================================================================
t_eq('a spoofed CF-Connecting-IP is ignored',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']));
t_eq('a spoofed X-Forwarded-For is ignored',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
t_eq('both headers spoofed at once are ignored',
    '203.0.113.50',
    ip_case([
        'REMOTE_ADDR' => '203.0.113.50',
        'HTTP_CF_CONNECTING_IP' => '1.2.3.4',
        'HTTP_X_FORWARDED_FOR' => '5.6.7.8, 9.10.11.12',
    ]));
t_eq('a non-IP REMOTE_ADDR (unix socket / broken CGI) degrades to 0.0.0.0',
    '0.0.0.0',
    ip_case(['REMOTE_ADDR' => '/var/run/php-fpm.sock']));
t_eq('a missing REMOTE_ADDR degrades to 0.0.0.0', '0.0.0.0', ip_case([]));

// ===========================================================================
t_sec('4. A trusted proxy is believed, and only for that hop');
// ===========================================================================
$cfTrusted = ['verify_trusted_proxies' => "173.245.48.0/20\n2606:4700::/32"];
t_eq('CF-Connecting-IP is used when the peer is a trusted proxy',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'], $cfTrusted));
t_eq('a private address claimed in CF-Connecting-IP is refused',
    '173.245.48.15',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => '127.0.0.1'], $cfTrusted));
t_eq('a malformed CF-Connecting-IP is refused',
    '173.245.48.15',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'], $cfTrusted));
t_eq('an IPv6 Cloudflare peer works',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '2606:4700::6810:84e5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'], $cfTrusted));
t_eq('an untrusted peer is not believed even when a list exists',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'], $cfTrusted));

// ===========================================================================
t_sec('5. X-Forwarded-For chain parsing');
// ===========================================================================
$lbTrusted = ['verify_trusted_proxies' => '10.0.0.0/8'];
t_eq('the right-most untrusted hop wins',
    '198.51.100.7',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7'], $lbTrusted));
t_eq('trusted hops on the right are skipped',
    '198.51.100.7',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 10.0.0.9'], $lbTrusted));
t_eq('a client-supplied private address at the right is skipped',
    '198.51.100.7',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 192.168.5.5'], $lbTrusted));
t_eq('a chain of only private values falls back to the peer',
    '10.0.0.5',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '192.168.1.1, 10.1.1.1'], $lbTrusted));
t_eq('an entirely garbage chain falls back to the peer',
    '10.0.0.5',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'unknown, , x'], $lbTrusted));
t_eq('a single-value chain is honoured',
    '198.51.100.7',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], $lbTrusted));

// ===========================================================================
t_sec('6. Modes');
// ===========================================================================
t_eq('mode none ignores a trusted proxy header entirely',
    '173.245.48.15',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'],
        $cfTrusted + ['verify_proxy_mode' => 'none']));
t_eq('mode xff ignores CF-Connecting-IP',
    '10.0.0.5',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'],
        $lbTrusted + ['verify_proxy_mode' => 'xff']));
t_eq('mode xff reads the X-Forwarded-For chain',
    '198.51.100.7',
    ip_case(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7'],
        $lbTrusted + ['verify_proxy_mode' => 'xff']));
t_eq('an unknown mode falls back to auto',
    '203.0.113.50',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'],
        $cfTrusted + ['verify_proxy_mode' => 'banana']));
t_eq('an environment override beats the stored setting',
    '173.245.48.15',
    ip_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50'], $cfTrusted, 'none'));

// ===========================================================================
t_sec('7. Configuration hygiene');
// ===========================================================================
$GLOBALS['sh_test_settings'] = ['verify_trusted_proxies' => "173.245.48.0/20, bogus-entry, 999.1.1.1, 2001:db8::/129, 10.0.0.5"];
$list = sh_trusted_proxy_entries();
t_ok('invalid entries are dropped, valid ones kept',
    $list === ['173.245.48.0/20', '10.0.0.5'], implode(',', $list));
t_ok('a single address entry is treated as a /32 host', sh_ip_is_trusted_proxy('10.0.0.5', $list));
t_ok('an address one bit away from that host is not trusted', !sh_ip_is_trusted_proxy('10.0.0.6', $list));
$GLOBALS['sh_test_settings'] = [];

// ===========================================================================
t_sec('8. HTTPS detection behind a proxy');
// ===========================================================================
function https_case(array $server, array $settings = []): bool
{
    $GLOBALS['sh_test_settings'] = $settings;
    $saved = $_SERVER;
    foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_PROTO', 'HTTPS'] as $k) { unset($_SERVER[$k]); }
    foreach ($server as $k => $v) { $_SERVER[$k] = $v; }
    $https = function_exists('sh_forwarded_proto_trusted') && sh_forwarded_proto_trusted()
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $_SERVER = $saved;
    $GLOBALS['sh_test_settings'] = [];
    return $https;
}
t_ok('with no proxy list the historical behaviour is preserved',
    https_case(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_X_FORWARDED_PROTO' => 'https']));
t_ok('a listed proxy may report https',
    https_case(['REMOTE_ADDR' => '173.245.48.15', 'HTTP_X_FORWARDED_PROTO' => 'https'], $cfTrusted));
t_ok('an unlisted peer may not report https once a list exists',
    !https_case(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_X_FORWARDED_PROTO' => 'https'], $cfTrusted));

// ===========================================================================
t_sec('9. Wiring: one implementation, used everywhere');
// ===========================================================================
$functionsSrc = (string)file_get_contents(__DIR__ . '/../includes/functions.php');
$configSrc = (string)file_get_contents(__DIR__ . '/../config/config.php');
$verificationSrc = (string)file_get_contents(__DIR__ . '/../includes/verification.php');
$clientSrc = (string)file_get_contents(__DIR__ . '/../includes/client-ip.php');

t_ok('includes/functions.php loads the resolver', str_contains($functionsSrc, "require_once __DIR__ . '/client-ip.php'"));
t_ok('config.php loads the resolver before any page logic', str_contains($configSrc, "includes/client-ip.php"));
t_ok('sh_client_ip() is defined exactly once', substr_count($functionsSrc . $clientSrc, 'function sh_client_ip(') === 1);
t_ok('courier verification delegates instead of re-parsing headers',
    str_contains($verificationSrc, 'sh_client_ip()')
    && !preg_match('~\$_SERVER\[\'HTTP_X_FORWARDED_FOR\'\]~', $verificationSrc));
t_ok('the old unconditional header reads are gone from functions.php',
    !preg_match("~\\\$_SERVER\\['HTTP_(CF_CONNECTING_IP|X_FORWARDED_FOR)'\\]~", $functionsSrc));

$ipUsers = ['includes/auth.php', 'includes/phone-otp.php', 'includes/mail.php', 'includes/support.php', 'install/index.php', 'api/csp-report.php'];
$allUseIt = true;
foreach ($ipUsers as $rel) {
    $src = (string)file_get_contents(__DIR__ . '/../' . $rel);
    if (!str_contains($src, 'sh_client_ip')) { $allUseIt = false; }
}
t_ok('every rate-limited module resolves the IP through sh_client_ip()', $allUseIt);

// ===========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
printf("passed %d   failed %d\n", $GLOBALS['sh_test_passes'], $GLOBALS['sh_test_failures']);
echo $GLOBALS['sh_test_failures'] === 0 ? "TRUSTED PROXY SUITE: ALL CHECKS PASSED\n" : "TRUSTED PROXY SUITE: FAILURES PRESENT\n";
exit($GLOBALS['sh_test_failures'] === 0 ? 0 : 1);
