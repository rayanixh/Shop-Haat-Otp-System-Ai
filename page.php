<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();

$site = (string)sh_setting('site_name', 'ShopHaat');
$pages = [
    'about' => ['About Us', [
        'Who we are' => $site . ' is an online marketplace built for shoppers across Bangladesh. We bring electronics, home essentials, fashion, groceries, beauty products and instantly delivered digital goods together in one place.',
        'What we stand for' => 'Every seller on our platform is verified before listing, and every product is checked before dispatch. We publish honest prices, show real customer reviews and never inflate discounts.',
        'How we deliver' => 'Orders placed before 4:00 PM are usually handed to our courier partners the same working day. Digital products are delivered automatically the moment a payment is verified.',
    ]],
    'terms' => ['Terms & Conditions', [
        'Acceptance of terms' => 'By browsing or ordering from ' . $site . ' you agree to these terms. If you do not accept them, please do not use the service.',
        'Orders and pricing' => 'All prices are shown in the store currency and include applicable taxes unless stated otherwise. We reserve the right to cancel an order where a product is mispriced, unavailable, or where payment cannot be verified.',
        'Payments' => 'Manual mobile financial service payments are verified by our team before an order is processed. Submitting a transaction ID does not by itself confirm payment. Fraudulent or duplicate transaction IDs will result in the order being cancelled.',
        'Digital products' => 'Digital codes are single-use and are issued to exactly one customer. Once a code has been delivered it cannot be returned, refunded or exchanged.',
        'Account responsibility' => 'You are responsible for keeping your account password confidential and for all activity carried out through your account.',
    ]],
    'privacy' => ['Privacy Policy', [
        'Information we collect' => 'We collect the name, email address, phone number and delivery address you provide at registration or checkout, plus the transaction ID you submit for manual payments.',
        'How we use it' => 'Your information is used to process orders, deliver products, verify payments, provide customer support and send order notifications. We do not sell your personal data.',
        'Payment data' => 'We never collect or store your PIN, OTP or full card details. Automatic gateway payments are handled entirely on the provider secure infrastructure.',
        'Data security' => 'Passwords are stored using one-way hashing. Access to customer data is restricted to authorised administrators and all administrative actions require authentication.',
        'Your choices' => 'You may update your profile at any time from your account, or contact support to request removal of your account data.',
    ]],
    'returns' => ['Returns & Refunds', [
        'Replacement window' => 'Physical products may be replaced within 7 days of delivery if they arrive damaged, defective or different from what was ordered.',
        'How to request' => 'Contact customer support with your order number and a photo of the item. Once approved, our courier will collect the product and a replacement will be dispatched.',
        'Non-returnable items' => 'Digital codes, opened consumable goods and personal care products that have been used cannot be returned.',
        'Refund timing' => 'Where a refund is approved, the amount is returned through the original payment channel within 5 to 10 working days.',
    ]],
    'shipping' => ['Shipping Information', [
        'Delivery areas' => 'We deliver nationwide. Standard charges are ' . sh_money(sh_setting('delivery_fee_inside', '60')) . ' inside the city and ' . sh_money(sh_setting('delivery_fee_outside', '120')) . ' outside the city.',
        'Free delivery' => 'Orders above ' . sh_money(sh_setting('free_delivery_over', '3000')) . ' qualify for free delivery.',
        'Delivery times' => 'Inside the city: 1–2 working days. Outside the city: 2–4 working days. Digital products: delivered instantly after payment verification.',
        'Order tracking' => 'You can check the current stage of your order at any time from the Track Order page or from My Orders.',
    ]],
];

$key = sh_get('p', 'about');
if (!isset($pages[$key])) {
    http_response_code(404);
    $pageTitle = 'Page not found';
    require_once SH_ROOT . '/includes/header.php';
    echo '<div class="sh-wrap"><div class="sh-empty" style="margin-top:20px">'
        . '<span class="sh-empty__icon">' . sh_icon('info', 26) . '</span>'
        . '<h1 class="sh-empty__title">Page not found</h1>'
        . '<p class="sh-empty__text">The page you are looking for does not exist.</p>'
        . '<a class="sh-btn" href="' . e(sh_url('index.php')) . '">Back to homepage</a></div></div>';
    require_once SH_ROOT . '/includes/footer.php';
    exit;
}

[$title, $sections] = $pages[$key];
$pageTitle = $title;
$pageDescription = $title . ' — ' . $site;
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page"><?= e($title) ?></span></nav>
  <div class="sh-section" style="max-width:860px;margin:0 auto">
    <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('info', 18) ?> <?= e($title) ?></h1></div>
    <?php foreach ($sections as $h => $body): ?>
      <h2 style="font-size:15px;font-weight:700;margin:16px 0 6px"><?= e($h) ?></h2>
      <p style="font-size:13.8px;line-height:1.8;color:var(--sh-ink-2)"><?= e($body) ?></p>
    <?php endforeach; ?>
    <p style="font-size:12.5px;color:var(--sh-muted);margin-top:20px">Last updated <?= e(date('d M Y')) ?>.</p>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
