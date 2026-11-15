<?php
/**
 * Support launcher menu — link validation and markup contract (no database).
 *
 * The floating launcher offers three options: Facebook, WhatsApp and the
 * existing Live Chat. The two external destinations come from the administrator
 * and are validated in includes/support-links.php; this suite pins that
 * validation (https only, official hosts, country-code numbers) and the static
 * contract between the widget markup, the stylesheet and the settings form, so
 * a dead link or a hardcoded number can never ship.
 *
 *     php tests/support-links.php
 *
 * Exit code 0 = every check passed, 1 = at least one failed.
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/support-links.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

if (!defined('SH_ROOT')) { define('SH_ROOT', dirname(__DIR__)); }
require_once SH_ROOT . '/includes/support-links.php';

$passed = 0; $failed = 0; $failures = [];
function sl_ok(bool $cond, string $msg): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  PASS  $msg\n"; return; }
    $failed++; $failures[] = $msg; echo "  FAIL  $msg\n";
}
function sl_eq($exp, $act, string $msg): void
{
    sl_ok($exp === $act, $msg . ' (expected ' . var_export($exp, true) . ', got ' . var_export($act, true) . ')');
}

echo "Support launcher links (no DB)\n";

/* --- Facebook / Messenger ------------------------------------------------ */
echo "\nFacebook link validation\n";
$fbAccept = [
    'https://m.me/shophaat' => 'https://m.me/shophaat',
    'https://m.me/shophaat?text=hi' => 'https://m.me/shophaat?text=hi',
    'https://facebook.com/shophaat' => 'https://facebook.com/shophaat',
    'https://www.facebook.com/shophaat/posts/1' => 'https://www.facebook.com/shophaat/posts/1',
    'https://web.facebook.com/shophaat' => 'https://web.facebook.com/shophaat',
    'https://www.messenger.com/t/shophaat' => 'https://www.messenger.com/t/shophaat',
    'https://fb.me/shophaat' => 'https://fb.me/shophaat',
];
foreach ($fbAccept as $input => $expected) {
    $result = sh_support_link_check_facebook((string)$input);
    sl_ok($result['ok'] === true, 'Facebook accepts ' . $input);
    sl_eq($expected, $result['url'], 'Facebook keeps ' . $input . ' unchanged');
}
$fbReject = [
    'http://m.me/shophaat' => 'plain http is rejected',
    'm.me/shophaat' => 'a missing scheme is rejected',
    'https://example.com/m.me/shophaat' => 'a non-Facebook host is rejected',
    'https://m.me.evil.example/x' => 'a look-alike host is rejected',
    'https://facebook.com/' => 'a page-less Facebook link is rejected',
    'https://m.me' => 'a page-less Messenger link is rejected',
    'javascript:alert(1)' => 'a javascript: URL is rejected',
    'https://user:pass@m.me/shophaat' => 'embedded credentials are rejected',
    'https://m.me/shophaat"><script>alert(1)</script>' => 'an injected attribute is rejected',
];
foreach ($fbReject as $input => $why) {
    $result = sh_support_link_check_facebook((string)$input);
    sl_ok($result['ok'] === false, 'Facebook rejects: ' . $why);
    sl_ok($result['url'] === '' && $result['error'] !== '', 'Facebook rejection has no URL and says why: ' . $why);
}
sl_eq(['ok' => true, 'url' => '', 'error' => ''], sh_support_link_check_facebook('   '), 'a blank Facebook field is allowed and clears the link');

/* --- WhatsApp ------------------------------------------------------------ */
echo "\nWhatsApp number and link validation\n";
$waAccept = [
    '+8801700000000' => 'https://wa.me/8801700000000',
    '8801700000000' => 'https://wa.me/8801700000000',
    '+880 170 000 0000' => 'https://wa.me/8801700000000',
    '(+880) 170-000-0000' => 'https://wa.me/8801700000000',
    '008801700000000' => 'https://wa.me/8801700000000',
    'https://wa.me/8801700000000' => 'https://wa.me/8801700000000',
    'https://wa.me/8801700000000?text=Hello' => 'https://wa.me/8801700000000?text=Hello',
    'https://api.whatsapp.com/send?phone=8801700000000&text=Hello' => 'https://api.whatsapp.com/send?phone=8801700000000&text=Hello',
    'https://chat.whatsapp.com/AbCdEf123456' => 'https://chat.whatsapp.com/AbCdEf123456',
];
foreach ($waAccept as $input => $expected) {
    $result = sh_support_link_check_whatsapp((string)$input);
    sl_ok($result['ok'] === true, 'WhatsApp accepts ' . $input);
    sl_eq($expected, $result['url'], 'WhatsApp normalises ' . $input);
}
$waReject = [
    '123' => 'a too-short number is rejected',
    '01700000000' => 'a number without a country code is rejected',
    '+880170000000000000' => 'a too-long number is rejected',
    'call me maybe' => 'free text is rejected',
    'http://wa.me/8801700000000' => 'plain http is rejected',
    'https://wa.me/help' => 'a wa.me link without a number is rejected',
    'https://chat.whatsapp.com/' => 'an incomplete group invite is rejected',
    'https://example.com/wa.me/8801700000000' => 'a non-WhatsApp host is rejected',
    'javascript:alert(1)' => 'a javascript: URL is rejected',
];
foreach ($waReject as $input => $why) {
    $result = sh_support_link_check_whatsapp((string)$input);
    sl_ok($result['ok'] === false, 'WhatsApp rejects: ' . $why);
    sl_ok($result['url'] === '', 'WhatsApp rejection stores no URL: ' . $why);
}
sl_eq(['ok' => true, 'url' => '', 'error' => ''], sh_support_link_check_whatsapp(''), 'a blank WhatsApp field is allowed and hides the option');

/* --- Rendered launcher markup ------------------------------------------- */
echo "\nRendered launcher menu\n";

/** Minimal view-layer doubles so the real partial can be rendered offline. */
$GLOBALS['sl_settings'] = [];
if (!function_exists('sh_setting')) {
    function sh_setting(string $key, $default = '')
    {
        return array_key_exists($key, $GLOBALS['sl_settings']) ? $GLOBALS['sl_settings'][$key] : $default;
    }
}
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('sh_url')) {
    function sh_url(string $path): string { return 'https://shop.example.bd/' . ltrim($path, '/'); }
}
if (!function_exists('sh_icon')) {
    function sh_icon(string $name, int $size = 20, string $class = ''): string
    {
        return '<svg class="sh-icon ' . $class . '" data-icon="' . $name . '"></svg>';
    }
}
function sl_render(array $settings): string
{
    $GLOBALS['sl_settings'] = $settings;
    ob_start();
    include SH_ROOT . '/includes/support-widget.php';
    return (string)ob_get_clean();
}
function sl_fab_html(string $html): string
{
    $start = strpos($html, 'id="sh-schat-fab"');
    if ($start === false) { return ''; }
    $open = strrpos(substr($html, 0, $start), '<button');
    $close = strpos($html, '</button>', $start);
    return ($open === false || $close === false) ? '' : substr($html, (int)$open, (int)$close - (int)$open);
}

$bare = sl_render([]);
sl_eq(3, substr_count($bare, 'role="menuitem"'), 'an unconfigured store still renders exactly three options');
sl_eq(2, substr_count($bare, 'aria-disabled="true"'), 'unconfigured Facebook and WhatsApp render as unavailable, not dead links');
sl_eq(2, substr_count($bare, 'Not set up'), 'the unavailable options explain themselves');
sl_ok(!str_contains($bare, 'href="https://m.me') && !str_contains($bare, 'href="https://wa.me'), 'no external link is emitted without a configured destination');
sl_ok(str_contains($bare, 'id="sh-schat-opt-chat"') && !str_contains($bare, 'role="menuitem" aria-disabled="true" data-channel="chat"'), 'Live Chat stays a real, enabled button when nothing else is configured');
sl_ok(str_contains($bare, 'aria-label="Open support options"'), 'the launcher has an accessible label');
sl_ok(!str_contains(sl_fab_html($bare), 'Support'), 'the launcher markup contains no "Support" wording next to the icon');
sl_ok(!str_contains(sl_fab_html($bare), 'sh-schat-fab-label'), 'the old wide pill label element is gone');
sl_ok(str_contains(sl_fab_html($bare), 'aria-expanded="false"'), 'the launcher exposes its collapsed state');
sl_ok(str_contains(sl_fab_html($bare), 'aria-haspopup="menu"'), 'the launcher declares a popup menu');
sl_ok(str_contains(sl_fab_html($bare), 'aria-controls="sh-schat-menu"'), 'the launcher controls the options menu');
sl_ok(str_contains($bare, 'id="sh-schat-menu"') && str_contains($bare, 'role="menu"'), 'the options menu exists with a menu role');
sl_ok(str_contains($bare, 'aria-hidden="true" hidden'), 'the menu starts hidden');
sl_ok(substr_count($bare, 'sh-brandglyph') === 2, 'exactly two official brand marks are inline (Facebook + WhatsApp)');
sl_ok(!preg_match('~wa\.me/[0-9]{6,}~', $bare) && !preg_match('~m\.me/[A-Za-z0-9]~', $bare), 'no phone number or page link is rendered when nothing is configured');

$full = sl_render([
    'support_chat_facebook_url' => 'https://m.me/shophaat',
    'support_chat_whatsapp' => '+8801700000000',
    'support_chat_whatsapp_message' => 'Hi ShopHaat, I need help with an order.',
]);
sl_eq(3, substr_count($full, 'role="menuitem"'), 'a configured store renders exactly three options');
sl_eq(0, substr_count($full, 'aria-disabled="true"'), 'configured options are real controls');
sl_eq(0, substr_count($full, 'Not set up'), 'a configured store shows no unavailable state');
sl_ok(str_contains($full, 'href="https://m.me/shophaat"'), 'the Facebook option links to the configured Messenger page');
sl_ok(str_contains($full, 'href="https://wa.me/8801700000000?text=Hi%20ShopHaat%2C%20I%20need%20help%20with%20an%20order."'),
    'the WhatsApp option carries the configured pre-filled message');
sl_eq(2, substr_count($full, 'target="_blank"'), 'both external options open in a new tab');
sl_eq(2, substr_count($full, 'rel="noopener noreferrer nofollow"'), 'external options are opened with noopener/noreferrer');
sl_ok(str_contains($full, 'id="sh-schat-opt-chat"'), 'Live Chat is still offered alongside the external channels');
sl_ok(str_contains($full, 'sh-schat-sheet') && str_contains($full, 'id="sh-schat-messages"'), 'the existing chat panel is rendered unchanged');

$invalid = sl_render([
    'support_chat_facebook_url' => 'http://m.me/shophaat',
    'support_chat_whatsapp' => 'javascript:alert(1)',
    'support_chat_whatsapp_message' => 'Hi',
]);
sl_ok(!str_contains($invalid, 'href="http://m.me/shophaat"') && !str_contains($invalid, 'javascript:alert'), 'an invalid stored value is never rendered as a link');
sl_eq(2, substr_count($invalid, 'aria-disabled="true"'), 'invalid stored values fall back to the unavailable state');
sl_ok(str_contains($invalid, 'id="sh-schat-opt-chat"'), 'Live Chat survives an invalid configuration');

/* --- The existing chat is untouched ------------------------------------- */
echo "\nExisting chat contract\n";
$widget = (string)file_get_contents(SH_ROOT . '/includes/support-widget.php');
foreach (['sh-schat-title', 'sh-schat-new', 'sh-schat-close', 'sh-schat-messages', 'sh-schat-suggest',
          'sh-schat-form', 'sh-schat-input', 'sh-schat-send', 'sh-schat-status', 'sh-schat-handoff',
          'sh-schat-badge', 'api/support.php'] as $needle) {
    sl_ok(str_contains($widget, $needle), 'the chat panel still ships ' . $needle);
}
sl_ok(!str_contains($widget, 'support-links.js') && !str_contains($widget, 'fetch('), 'the widget partial still contains markup only');

/* --- Settings wiring ----------------------------------------------------- */
echo "\nSettings wiring\n";
$save = (string)file_get_contents(SH_ROOT . '/includes/ai-support.php');
sl_ok(str_contains($save, "sh_support_link_check_facebook((string)(\$input['facebook_url']"), 'the save handler uses the Facebook validator');
sl_ok(str_contains($save, "sh_support_link_check_whatsapp((string)(\$input['whatsapp']"), 'the save handler uses the WhatsApp validator');
sl_ok(str_contains($save, "\$values['whatsapp_message']"), 'the pre-filled WhatsApp message is stored');
$admin = (string)file_get_contents(SH_ROOT . '/admin/support-settings.php');
foreach (['name="facebook_url"', 'name="whatsapp"', 'name="whatsapp_message"'] as $field) {
    sl_ok(str_contains($admin, $field), 'the existing support settings form has ' . $field);
}
sl_ok(str_contains($admin, "sh_support_setting('facebook_url'"), 'the settings page reads the saved Facebook link');
sl_ok(substr_count($admin, '<form method="post"') >= 1 && !str_contains($admin, 'support-channels.php'), 'the link settings live in the existing settings page (no duplicate page)');

/* --- Stylesheet contract ------------------------------------------------- */
echo "\nStylesheet contract\n";
$css = (string)file_get_contents(SH_ROOT . '/assets/css/app.css');
$fabCss = '';
if (preg_match('~\.sh-schat-fab \{([^}]*)\}~', $css, $m)) { $fabCss = $m[1]; }
sl_ok($fabCss !== '', 'the launcher has a stylesheet rule');
sl_ok((bool)preg_match('~\.sh-schat-fab, \.sh-schat-menu \{[^}]*--sh-schat-size: 56px~', $css)
    && str_contains($fabCss, 'width: var(--sh-schat-size)') && str_contains($fabCss, 'height: var(--sh-schat-size)')
    && str_contains($fabCss, 'aspect-ratio: 1 / 1') && str_contains($fabCss, 'min-width: var(--sh-schat-size)'),
    'the launcher is a fixed 56px square with a locked 1:1 ratio');
sl_ok(str_contains($fabCss, 'border-radius: 50%'), 'the launcher is a perfect circle');
sl_ok(str_contains($fabCss, 'position: fixed'), 'the launcher stays fixed to the viewport');
sl_ok(str_contains($css, '.sh-schat-menu {'), 'the menu has a stylesheet rule');
sl_ok(str_contains($css, '.sh-schat-menu.is-open'), 'the menu has an open state for the slide-up animation');
sl_ok((bool)preg_match('~\.sh-schat-menu[^{]*\{[^}]*transform: translateY~', $css), 'the menu animates upward from the launcher');
sl_ok((bool)preg_match('~@media \(prefers-reduced-motion: reduce\)~', $css), 'the stylesheet honours prefers-reduced-motion');
$reduced = '';
if (preg_match('~@media \(prefers-reduced-motion: reduce\) \{(.*)~s', $css, $m)) { $reduced = $m[1]; }
sl_ok(str_contains($reduced, '.sh-schat-menu'), 'reduced motion also covers the launcher menu');

/* --- Result -------------------------------------------------------------- */
echo "\n" . str_repeat('-', 60) . "\n";
echo "Passed: $passed  Failed: $failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) { echo "  - $failure\n"; }
    exit(1);
}
echo "All support launcher link checks passed.\n";
exit(0);
