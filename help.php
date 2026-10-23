<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();

$faqs = [
    ['How long does delivery take?', 'Orders inside the city are usually delivered within 1–2 working days. Outside the city, delivery takes 2–4 working days. '],
    ['Which payment methods can I use?', 'You can pay with bKash, Nagad or Rocket by sending money to our merchant number and submitting the transaction ID, or choose Cash on Delivery for physical products. Automatic card and mobile banking gateways are available when enabled by the store.'],
    ['When is my manual payment approved?', 'Manual payments are verified by our team, never automatically. Once we match your transaction ID with our records the order moves to Processing and you are notified.'],
    ['Can I return a product?', 'Products can be replaced within 7 days if they arrive defective or incorrect. Items that have been opened and used cannot be returned.'],
    ['How do I track my order?', 'Use the Track Order page with your order number and the phone number you used at checkout, or open My Orders if you have an account.'],
    ['Is my payment information safe?', 'We never ask for your PIN or OTP and we do not store card details. Only the transaction ID is recorded so that we can verify your payment.'],
];

// When the support migration is installed, its enabled FAQs are also the Help
// Centre source of truth. Keep the existing published copy as a safe fallback
// so a partially upgraded store still renders without requiring support tables.
try {
    $supportFaqTableExists = (int)sh_val(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        ['support_faqs'], 0
    ) > 0;
    if ($supportFaqTableExists) {
        $configuredFaqs = sh_all('SELECT question_en, answer_en FROM support_faqs WHERE status = 1 ORDER BY sort_order ASC, id ASC');
        $faqSeedMarker = (string)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'ai_support_faq_seeded_v' LIMIT 1", [], '');
        if ($configuredFaqs || $faqSeedMarker === '1') {
            $faqs = array_map(static fn(array $faq): array => [(string)$faq['question_en'], (string)$faq['answer_en']], $configuredFaqs);
        }
    }
} catch (Throwable $e) {
    // The legacy Help Centre copy remains visible when the optional FAQ table cannot be read.
}


// SEO: an owner-overridable page key, a real canonical and a breadcrumb.
$pageSeoKey = 'help';
$pageTitle = 'Help Centre';
$pageDescription = 'Answers to common questions about ordering, delivery, payment and returns at '
    . sh_setting('site_name', 'ShopHaat') . '.';
$pageCanonical = 'help.php';
$pageBreadcrumbs = [['name' => 'Home', 'url' => 'index.php'], ['name' => 'Help Centre', 'url' => '']];
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Help Centre</span></nav>
  <div class="sh-section" style="max-width:860px;margin:0 auto">
    <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('help', 18) ?> Help Centre</h1></div>
    <?php if (!$faqs): ?><p style="font-size:13.5px;color:var(--sh-ink-2);line-height:1.75">There are no published help articles right now. Contact support if you need assistance.</p><?php endif; ?>
    <?php foreach ($faqs as $i => $f): ?>
      <details style="border-bottom:1px solid var(--sh-line-2);padding:12px 0" <?= $i === 0 ? 'open' : '' ?>>
        <summary style="font-weight:700;font-size:14px;cursor:pointer"><?= e($f[0]) ?></summary>
        <p style="font-size:13.5px;color:var(--sh-ink-2);line-height:1.75;margin-top:8px"><?= e($f[1]) ?></p>
      </details>
    <?php endforeach; ?>
    <div style="margin-top:18px">
      <a class="sh-btn" href="<?= e(sh_url('support.php')) ?>"><?= sh_icon('headphones', 15) ?> Contact support</a>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
