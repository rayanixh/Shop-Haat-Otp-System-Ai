<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/payment.php';

sh_session_start();
$user = sh_require_login();
$rows = sh_all('SELECT pc.code, pc.delivered_at, oi.product_name, o.order_number, o.id AS order_id
    FROM product_codes pc
    JOIN orders o ON o.id = pc.order_id
    LEFT JOIN order_items oi ON oi.id = pc.order_item_id
    WHERE o.user_id = ? AND pc.status = \'used\'
    ORDER BY pc.delivered_at DESC, pc.id DESC', [(int)$user['id']]);

$accountPage = 'digital';
$pageTitle = 'Digital Purchases';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Digital Purchases</span></nav>
  <div class="sh-account">
    <?php require SH_ROOT . '/includes/account-nav.php'; ?>
    <div>
      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title"><?= sh_icon('key', 18) ?> Digital Purchases (<?= count($rows) ?>)</h1></div>
        <?php if (!$rows): ?>
          <div style="text-align:center;padding:30px 10px">
            <span class="sh-empty__icon"><?= sh_icon('key', 24) ?></span>
            <p class="sh-empty__title" style="font-size:16px">No digital codes yet</p>
            <p class="sh-empty__text">Codes from digital products appear here once your payment is verified.</p>
            <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Browse digital products</a>
          </div>
        <?php else: ?>
          <div class="sh-codebox">
            <?php foreach ($rows as $r): ?>
              <div class="sh-codebox__row">
                <span class="sh-codebox__label">
                  <?= e((string)$r['product_name']) ?>
                  <br><span style="font-size:11px;opacity:.7">Order <?= e($r['order_number']) ?> · <?= e($r['delivered_at'] ? date('d M Y', strtotime($r['delivered_at'])) : '') ?></span>
                </span>
                <span class="sh-codebox__code"><?= e($r['code']) ?></span>
                <button class="sh-btn sh-btn--sm" type="button" data-copy="<?= e($r['code']) ?>"><?= sh_icon('copy', 13) ?> <span data-copy-label>Copy</span></button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
