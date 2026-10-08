<?php
/** Shared customer account sidebar. Expects $accountPage. */
require_once __DIR__ . '/auth.php';
$accountPage = $accountPage ?? '';
$u = sh_user();
$links = [
    'account'   => ['account.php',   'user',    'My Profile'],
    'orders'    => ['orders.php',    'package', 'My Orders'],
    'digital'   => ['digital.php',   'key',     'Digital Purchases'],
    'wishlist'  => ['wishlist.php',  'heart',   'Wishlist'],
    'addresses' => ['addresses.php', 'map-pin', 'Addresses'],
];
// Phone-only accounts have no password, so hide password management for them.
$hasPassword = $u !== null && !sh_is_synthetic_email((string)$u['email']);
if ($hasPassword) {
    $links['password'] = ['password.php', 'lock', 'Change Password'];
}
?>
<nav class="sh-account__nav" aria-label="Account">
  <?php if ($u): ?>
    <div class="sh-account__user">
      <?php $avatarUrl = sh_user_avatar_url($u); ?>
      <span class="sh-account__avatar"><?php if ($avatarUrl !== ''): ?><img src="<?= e($avatarUrl) ?>" alt="" referrerpolicy="no-referrer" loading="lazy" onerror="this.remove()"><?php else: ?><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?><?php endif; ?></span>
      <div style="min-width:0">
        <p class="sh-account__name"><?= e($u['name']) ?></p>
        <p class="sh-account__email"><?= e(sh_is_synthetic_email((string)$u['email']) ? sh_phone_display((string)$u['phone']) : (string)$u['email']) ?></p>
      </div>
    </div>
  <?php endif; ?>
  <?php foreach ($links as $key => [$href, $icon, $label]): ?>
    <a class="sh-account__link <?= $accountPage === $key ? 'sh-account__link--on' : '' ?>" href="<?= e(sh_url($href)) ?>">
      <?= sh_icon($icon, 17) ?><span><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
  <a class="sh-account__link" href="<?= e(sh_url('logout.php')) ?>"><?= sh_icon('log-out', 17) ?><span>Sign out</span></a>
</nav>
