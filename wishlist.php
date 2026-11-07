<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';

sh_session_start();
$user = sh_require_login('wishlist.php');
$pageTitle = 'Wishlist';
$pageDescription = 'Products saved to your ShopHaat wishlist.';
$pageCanonical = 'wishlist.php';
$pageBreadcrumbs = [['name' => 'Home', 'url' => 'index.php'], ['name' => 'Wishlist', 'url' => '']];
$accountPage = 'wishlist';

try {
    $items = sh_all(
        'SELECT p.* FROM wishlist w
         JOIN products p ON p.id = w.product_id
         WHERE w.user_id = ? AND p.status = 1
         ORDER BY w.created_at DESC, w.id DESC',
        [(int)$user['id']]
    );
} catch (Throwable $e) {
    sh_log_exception($e, 'wishlist');
    $items = [];
}
$shWishlist = array_map('intval', array_column($items, 'id'));
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <nav class="sh-breadcrumb"><a href="<?= e(sh_url('index.php')) ?>">Home</a> <?= sh_icon('chevron-right', 13) ?> <span aria-current="page">Wishlist</span></nav>
  <div class="sh-account">
    <?php require_once SH_ROOT . '/includes/account-nav.php'; ?>
    <section class="sh-account__content" aria-labelledby="wishlist-title">
      <div class="sh-section">
        <div class="sh-section__head"><h1 class="sh-section__title" id="wishlist-title"><?= sh_icon('heart', 18) ?> Wishlist</h1></div>
        <?php if (!$items): ?>
          <div class="sh-empty">
            <span class="sh-empty__icon"><?= sh_icon('heart', 26) ?></span>
            <h2 class="sh-empty__title">No saved products yet</h2>
            <p class="sh-empty__text">Save products with the heart button to find them here.</p>
            <a class="sh-btn" href="<?= e(sh_url('products.php')) ?>">Browse products</a>
          </div>
        <?php else: ?>
          <div class="sh-product-grid sh-product-grid--5">
            <?php foreach ($items as $p) { include SH_ROOT . '/includes/product-card.php'; } ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
