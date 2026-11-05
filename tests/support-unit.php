<?php
/**
 * AI Customer Support — pure-logic tests (no database, no network).
 *
 * Covers the parts that decide safety and honesty without MySQL: language
 * detection (Bengali script / Banglish / English), order-reference and phone
 * extraction, product-query cleanup, the deterministic composer's factual
 * wording, the AI output guard (unverified amounts and action claims are
 * rejected), message shaping for the widget, and the static wiring (CSRF,
 * permissions, no AI-generation-log reuse, no inner scroll box).
 *
 *     php tests/support-unit.php
 */
declare(strict_types=1);

$_SERVER['SCRIPT_NAME'] = '/tests/support-unit.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once dirname(__DIR__) . '/config/config.php';
require_once SH_ROOT . '/includes/support.php';

$passed = 0; $failed = 0; $failures = [];
function su_ok(bool $cond, string $msg): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  PASS  $msg\n"; return; }
    $failed++; $failures[] = $msg; echo "  FAIL  $msg\n";
}
function su_eq($exp, $act, string $msg): void
{
    su_ok($exp === $act, $msg . ' (expected ' . var_export($exp, true) . ', got ' . var_export($act, true) . ')');
}
$src = static fn(string $rel): string => (string)file_get_contents(SH_ROOT . '/' . $rel);

echo "Support unit tests (no DB)\n";

/* --- Language detection -------------------------------------------------- */
su_eq('bn', sh_support_detect_lang('এই জুতাটা কি পাওয়া যাবে?'), 'Bengali script detected as bn');
su_eq('bn', sh_support_detect_lang('আমার অর্ডার কোথায়?'), 'Bengali order question detected as bn');
su_eq('banglish', sh_support_detect_lang('Vai, ei shirt ta ki ache?'), 'Banglish availability detected');
su_eq('banglish', sh_support_detect_lang('amar order ta ekhono ashe nai'), 'Banglish order complaint detected');
su_eq('banglish', sh_support_detect_lang('Agertar blue color ta dekhaw'), 'Banglish follow-up detected');
su_eq('banglish', sh_support_detect_lang('dam koto?'), 'Banglish price question detected');
su_eq('en', sh_support_detect_lang('Do you have this shoe in stock?'), 'English detected');
su_eq('en', sh_support_detect_lang('Where is my order?'), 'English order question detected');
su_eq('en', sh_support_detect_lang('Hello, I need help with a return'), 'English greeting+request detected');

/* --- Extraction ------------------------------------------------------------ */
su_eq('01712345678', sh_support_extract_phone('amar number 01712-345678'), 'BD phone extracted with separators');
su_eq('+8801712345678', sh_support_extract_phone('call +8801712345678 please'), 'BD phone extracted with country code');
su_eq(null, sh_support_extract_phone('order 12345'), 'Short number is not a phone');
su_eq('SH26081501234', sh_support_extract_order_ref('order SH26081501234 kothay'), 'Full order number extracted');
su_eq('SH260815-01234', sh_support_extract_order_ref('Order: sh260815-01234'), 'Dashed order number extracted');
su_eq('12345', sh_support_extract_order_ref('my order id 12345 status ki'), 'Numeric id after "order" extracted');
su_eq(null, sh_support_extract_order_ref('just browsing shoes'), 'No order ref in plain text');

/* --- Product query cleanup --------------------------------------------------- */
su_eq('nike shoe', sh_support_product_query('Nike shoe ache?'), 'Query keeps product words (Banglish)');
su_eq('nike shoe', sh_support_product_query('nike shoe er dam koto?'), 'Query strips price words');
su_eq('running shoes', sh_support_product_query('show me running shoes please'), 'Query strips English filler');

/* --- Composer (deterministic, factual) -------------------------------------- */
$cfg = sh_support_defaults();
$cfg['languages'] = ['bn', 'banglish', 'en'];
$base = ['intent' => 'fallback', 'lang' => 'en', 'query' => '', 'products' => [], 'product' => null,
         'order' => null, 'order_state' => 'none', 'policy' => '', 'faq' => null, 'size_asked' => '',
         'color_asked' => '', 'amounts' => [], 'facts_text' => '', 'force_deterministic' => false, 'context_updates' => []];

$r = $base; $r['intent'] = 'product'; $r['lang'] = 'banglish';
$r['product'] = ['id' => 7, 'name' => 'Trail Runner Shoe', 'url' => '/product.php?slug=trail', 'price' => 1250.0,
                 'price_fmt' => 'Tk 1,250', 'compare' => 1500.0, 'compare_fmt' => 'Tk 1,500', 'stock' => 3,
                 'availability' => 'low_stock', 'short' => '', 'spec' => ''];
$r['products'] = [$r['product']]; $r['size_asked'] = '42';
$out = sh_support_compose($r, $cfg);
su_ok(str_contains($out, 'Tk 1,250') && str_contains($out, '3 ti baki'), 'Banglish product reply carries verified price + low stock count');
su_ok(str_contains($out, '42') && str_contains($out, 'product page'), 'Size answer is honest: options on product page, product-level stock');

$r['product']['availability'] = 'out_of_stock'; $r['products'] = [$r['product']]; $r['size_asked'] = '';
$out = sh_support_compose($r, $cfg);
su_ok(str_contains($out, 'stock e nai'), 'Out-of-stock is stated plainly in Banglish');

$r2 = $base; $r2['intent'] = 'order'; $r2['order_state'] = 'need_phone'; $r2['lang'] = 'bn';
$out = sh_support_compose($r2, $cfg);
su_ok(str_contains($out, 'ফোন নম্বর'), 'Bengali need-phone reply asks for checkout phone (both-factor)');
$r2['order_state'] = 'not_found';
su_ok(sh_support_compose($r2, $cfg) !== '', 'Not-found order reply is generic (no enumeration hints)');

$r3 = $base; $r3['intent'] = 'order'; $r3['order_state'] = 'found';
$r3['order'] = ['order_number' => 'SH26081501234', 'status' => 'shipped', 'payment_status' => 'paid',
                'placed' => '2026-08-15 10:00:00', 'total' => 2250.0, 'total_fmt' => 'Tk 2,250',
                'delivery_fee' => 60.0, 'items' => ['Trail Runner Shoe ×1'], 'item_count' => 1,
                'shipment' => ['courier' => 'FastCourier', 'tracking' => 'FC9988', 'status' => 'in_transit', 'cod' => 0.0, 'delivered_at' => '']];
$out = sh_support_compose($r3, $cfg);
su_ok(str_contains($out, 'SH26081501234') && str_contains($out, 'FC9988') && str_contains($out, 'Shipped'), 'Order reply uses real status + tracking from facts');

/* --- AI guard ----------------------------------------------------------------- */
$gr = $base; $gr['amounts'] = [1250.0, 1500.0];
su_ok(sh_support_ai_guard('এটির দাম ৳1,250 এবং স্টকে আছে।', $gr), 'Guard allows the verified price (Bengali)');
su_ok(sh_support_ai_guard('The price is BDT 1250 only.', $gr), 'Guard allows verified price without separators');
su_ok(!sh_support_ai_guard('Special offer: only ৳999 today!', $gr), 'Guard rejects an invented lower price');
su_ok(!sh_support_ai_guard('দাম মাত্র ৯৯৯ টাকা — sorry, 999 taka!', $gr), 'Guard rejects invented taka amount');
su_ok(!sh_support_ai_guard('Done — I have cancelled your order.', $gr), 'Guard rejects first-person cancel claim');
su_ok(!sh_support_ai_guard('আমি বাতিল করে দিয়েছি।', $gr), 'Guard rejects Bengali cancel claim');
su_ok(!sh_support_ai_guard('refund kore dilam', $gr), 'Guard rejects Banglish refund claim');
$gr2 = $base; $gr2['amounts'] = [];
su_ok(sh_support_ai_guard('Delivery usually takes 2-3 days inside Dhaka.', $gr2), 'Guard allows amount-free text');
su_ok(!sh_support_ai_guard('Delivery charge is ৳60.', $gr2), 'Guard rejects any amount when none are verified');

/* --- Message shaping ------------------------------------------------------------ */
$pub = sh_support_message_public(['id' => 5, 'sender' => 'system', 'body' => 'Hi', 'created_at' => '2026-01-01 10:00:00',
    'facts_json' => json_encode(['kind' => 'assistant', 'source' => 'deterministic'])]);
su_eq('assistant', $pub['kind'], 'Deterministic assistant message renders as assistant bubble');
su_eq(false, $pub['ai'], 'Deterministic message is NOT flagged as AI');
$pub = sh_support_message_public(['id' => 6, 'sender' => 'ai', 'body' => 'Hi', 'created_at' => 'x', 'facts_json' => null]);
su_eq(true, $pub['ai'], 'Model-generated message IS flagged as AI');
$pub = sh_support_message_public(['id' => 7, 'sender' => 'admin', 'body' => 'Hi', 'created_at' => 'x', 'facts_json' => null]);
su_eq('agent', $pub['kind'], 'Admin message renders as agent');
su_ok(!array_key_exists('admin_id', $pub) && !array_key_exists('facts_json', $pub), 'Public shape leaks no internal fields');

/* --- Static wiring ---------------------------------------------------------------- */
su_ok(str_contains($src('api/support.php'), 'sh_csrf_require()'), 'Support API enforces CSRF');
su_ok(str_contains($src('api/support.php'), 'sh_support_owns('), 'Support API checks conversation ownership');
su_ok(!str_contains($src('includes/support.php'), 'sh_ai_dispatch('), 'Support never uses sh_ai_dispatch (would log full conversations)');
su_ok(str_contains($src('includes/support.php'), 'sh_support_ai_guard('), 'AI replies pass the factual guard');
su_ok(str_contains($src('includes/support.php'), 'FOR UPDATE'), 'Pipeline uses row locks around state changes');
su_ok(str_contains($src('includes/footer.php'), 'support-widget.php'), 'Widget is mounted in the storefront footer');
$permsSrc = $src('includes/admin-perms.php');
su_ok(str_contains($permsSrc, "'support.view'") && str_contains($permsSrc, "'support.reply'") && str_contains($permsSrc, "'support.manage'"), 'All support permissions are registered in the permission catalogue');
$layoutSrc = $src('admin/_layout.php');
su_ok(str_contains($layoutSrc, '$badgeSupport = 0;') && str_contains($layoutSrc, 'SUM(unread_admin)') && str_contains($layoutSrc, "'support.view'") && str_contains($layoutSrc, "'support.manage'"), 'Admin sidebar has a guarded support unread counter and permission-gated links');
su_ok(str_contains($src('ai/ProviderManager.php'), "'support_chat'"), 'support_chat AI task registered');
foreach (['ai/providers/OpenAICompatibleProvider.php', 'ai/providers/GeminiProvider.php', 'ai/providers/AnthropicProvider.php'] as $pf) {
    su_ok(str_contains($src($pf), "\$options['timeout']"), "Caller timeout honored in $pf");
}
$schema = $src('install/schema.php');
su_ok(str_contains($schema, 'UNIQUE KEY uq_supmsg_client'), 'Client message id is unique (idempotent sends)');
su_ok(substr_count($schema, 'CREATE TABLE IF NOT EXISTS support_') === 6, 'Six additive support tables declared');
$js = $src('assets/js/support-chat.js');
su_ok(str_contains($js, 'textContent') && !str_contains($js, 'innerHTML'), 'Widget renders message text via textContent only (no HTML injection)');
$css = $src('assets/css/app.css');
su_ok(preg_match('/\.sh-schat[^{]*\{[^}]*overflow\s*:\s*(auto|scroll)/', $css) !== 1
   && preg_match('/overflow(-x|-y)?\s*:\s*(auto|scroll)/', $css) !== 1, 'No internal scroll box anywhere in CSS (support included)');
su_ok(str_contains($src('admin/support-conversation.php'), "sh_require_perm('support.reply')"), 'Admin actions require support.reply');
su_ok(str_contains($src('admin/support-settings.php'), 'sh_support_ai_probe'), 'Settings page has a real AI probe (never assumed working)');

echo "\nPassed: $passed · Failed: $failed\n";
if ($failed > 0) { foreach ($failures as $f) { echo "  - $f\n"; } exit(1); }
exit(0);
