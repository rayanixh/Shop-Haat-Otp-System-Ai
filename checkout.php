<?php
/**
 * Checkout — customer info, delivery, payment method and order summary.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
sh_require_installed();
require_once SH_ROOT . '/includes/auth.php';
require_once SH_ROOT . '/includes/cart.php';
require_once SH_ROOT . '/includes/payment.php';
require_once SH_ROOT . '/includes/notifications.php';

sh_session_start();
// Checkout requires a signed-in account. Guests are sent to Login/Signup and
// return here after authenticating — their cart is preserved throughout.
$user = sh_require_login('checkout.php');
require_once SH_ROOT . '/includes/firebase.php';
if (sh_fb_checkout_blocked($user)) {
    sh_flash('error', 'Please verify your email address before placing an order.');
    sh_redirect('account.php#account-verification');
}
$coupon = $_SESSION['coupon_code'] ?? null;
$zone = ($_SESSION['delivery_zone'] ?? 'inside');
$errors = [];

$summary = sh_cart_summary($coupon, $zone);
if (!$summary['items'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sh_flash('info', 'Your cart is empty. Add a product before checking out.');
    sh_redirect('cart.php');
}

$methods = sh_payment_methods_available((bool)$summary['has_physical']);

$form = [
    'customer_name'  => $user['name'] ?? '',
    // Phone-only accounts have a synthetic email; don't show it in the form.
    'customer_email' => ($user && !sh_is_synthetic_email((string)$user['email'])) ? $user['email'] : '',
    'customer_phone' => $user['phone'] ?? '',
    'address_line'   => '', 'area' => '', 'city' => 'Dhaka', 'postcode' => '',
    'note' => '', 'delivery_zone' => $zone,
];
// Prefill from the customer's default address
if ($user) {
    try {
        $addr = sh_one('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1', [(int)$user['id']]);
        if ($addr) {
            $form['customer_name'] = $addr['full_name'];
            $form['customer_phone'] = $addr['phone'];
            $form['address_line'] = $addr['address_line'];
            $form['area'] = (string)$addr['area'];
            $form['city'] = $addr['city'];
            $form['postcode'] = (string)$addr['postcode'];
        }
    } catch (Throwable $e) { sh_log_exception($e, 'checkout-address'); }
}
foreach ($form as $k => $v) {
    if (isset($_POST[$k]) && is_string($_POST[$k])) { $form[$k] = trim($_POST[$k]); }
}

// Select a real method before the order is persisted. This avoids treating a
// first/remembered fallback as COD and emitting an order event before the
// customer has actually chosen how to pay.
$rememberedMethodId = (int)($_SESSION['checkout_payment_method_id'] ?? 0);
$selectedMethodId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? sh_int($_POST['payment_method_id'] ?? 0)
    : $rememberedMethodId;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rememberedAvailable = false;
    foreach ($methods as $m) {
        if ((int)$m['id'] === $selectedMethodId) { $rememberedAvailable = true; break; }
    }
    if (!$rememberedAvailable && $methods) { $selectedMethodId = (int)$methods[0]['id']; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sh_csrf_require();
    // Double-submit / idempotency guard: the token is consumed on success, so a
    // replayed POST can never create a second order.
    $orderToken = (string)($_POST['order_token'] ?? '');
    if ($orderToken === '' || !hash_equals(sh_order_idempotency_token(), $orderToken)) {
        $errors['order'] = 'This page has expired. Please refresh and place your order again.';
    }

    $zone = $form['delivery_zone'] === 'outside' ? 'outside' : 'inside';
    $_SESSION['delivery_zone'] = $zone;
    $summary = sh_cart_summary($coupon, $zone);
    // Recompute method availability from the just-refreshed cart snapshot. A
    // product may have changed type/availability in another request since this
    // checkout form was first rendered.
    $methods = sh_payment_methods_available((bool)$summary['has_physical']);

    // The selected method is submitted with the checkout form and revalidated
    // against the currently available server-side list below.
    $chosen = null;
    foreach ($methods as $m) {
        if ((int)$m['id'] === $selectedMethodId) { $chosen = $m; break; }
    }
    $methodId = $chosen ? (int)$chosen['id'] : 0;

    $v = new ShValidator($_POST);
    $v->required('customer_name', 'Full name')->maxLen('customer_name', 110, 'Full name')
      ->email('customer_email', 'Email address')
      ->required('customer_phone', 'Phone number')->phone('customer_phone', 'Phone number')
      ->maxLen('note', 480, 'Order note');
    if ($summary['has_physical']) {
        $v->required('address_line', 'Delivery address')->maxLen('address_line', 240, 'Delivery address')
          ->required('city', 'City')->maxLen('city', 110, 'City');
    }
    $v->custom('payment_method_id', $chosen !== null, 'No payment method is currently available. Please contact customer support.');
    if ($v->fails()) { $errors = $v->errors(); }
    if (!$summary['items']) { $errors['cart'] = 'Your cart is empty.'; }

    if (!$errors) {
        $res = sh_create_order([
            'customer_name'     => $form['customer_name'],
            'customer_email'    => $form['customer_email'],
            'customer_phone'    => $form['customer_phone'],
            'address_line'      => $summary['has_physical'] ? $form['address_line'] : null,
            'area'              => $form['area'] ?: null,
            'city'              => $summary['has_physical'] ? $form['city'] : null,
            'postcode'          => $form['postcode'] ?: null,
            'note'              => $form['note'] ?: null,
            'payment_method_id' => $methodId,
            'coupon_code'       => $coupon,
            'delivery_zone'     => $zone,
        ]);
        if ($res['ok']) {
            unset($_SESSION['coupon_code']);
            $_SESSION['checkout_payment_method_id'] = $methodId;
            sh_order_idempotency_reset();
            // Save the address for signed-in customers
            if ($user && $summary['has_physical']) {
                try {
                    $exists = sh_one('SELECT id FROM addresses WHERE user_id = ? AND address_line = ? LIMIT 1',
                        [(int)$user['id'], $form['address_line']]);
                    if (!$exists) {
                        sh_insert('addresses', [
                            'user_id' => (int)$user['id'], 'label' => 'Home',
                            'full_name' => $form['customer_name'], 'phone' => $form['customer_phone'],
                            'address_line' => $form['address_line'], 'area' => $form['area'] ?: null,
                            'city' => $form['city'], 'postcode' => $form['postcode'] ?: null,
                            'is_default' => 1,
                        ]);
                    }
                } catch (Throwable $e) { sh_log_exception($e, 'save-address'); }
            }
            // Every order continues to the payment step to submit/complete its
            // already-selected payment method.
            sh_redirect('payment.php?id=' . (int)$res['order_id']);
        }
        $errors['order'] = $res['error'];
    }
}

$pageTitle = 'Checkout';
$pageDescription = 'Complete your order securely.';
require_once SH_ROOT . '/includes/header.php';
?>
<div class="sh-wrap">
  <div class="sh-steps" style="margin-top:12px">
    <span class="sh-steps__item sh-steps__item--done"><span class="sh-steps__num"><?= sh_icon('check-circle', 12) ?></span> Cart</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item sh-steps__item--on"><span class="sh-steps__num">2</span> Checkout</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item"><span class="sh-steps__num">3</span> Payment</span>
    <span class="sh-steps__sep"></span>
    <span class="sh-steps__item"><span class="sh-steps__num">4</span> Confirmation</span>
  </div>

  <?php if ($errors): ?>
    <div class="sh-alert sh-alert--error">
      <?= sh_icon('x-circle', 16) ?>
      <div><strong>Please correct the following:</strong>
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= sh_csrf_field() ?>
    <input type="hidden" name="order_token" value="<?= e(sh_order_idempotency_token()) ?>">
    <div class="sh-cartlayout">
      <div>
        <!-- Customer information -->
        <section class="sh-checkout-card">
          <h2 class="sh-checkout-card__title"><?= sh_icon('user', 17) ?> Customer Information</h2>
          <div class="sh-grid2">
            <div class="sh-field">
              <label class="sh-field__label" for="ck-name">Full name <span class="sh-field__req">*</span></label>
              <input class="sh-input <?= isset($errors['customer_name']) ? 'sh-input--error' : '' ?>" id="ck-name"
                     name="customer_name" value="<?= e($form['customer_name']) ?>" required maxlength="110">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="ck-phone">Phone number <span class="sh-field__req">*</span></label>
              <input class="sh-input <?= isset($errors['customer_phone']) ? 'sh-input--error' : '' ?>" id="ck-phone"
                     name="customer_phone" value="<?= e($form['customer_phone']) ?>" required placeholder="01XXXXXXXXX">
            </div>
          </div>
          <div class="sh-field">
            <label class="sh-field__label" for="ck-email">Email address</label>
            <input class="sh-input <?= isset($errors['customer_email']) ? 'sh-input--error' : '' ?>" id="ck-email"
                   type="email" name="customer_email" value="<?= e($form['customer_email']) ?>" placeholder="you@example.com">
            <p class="sh-field__hint">Optional — order updates<?= $summary['has_physical'] ? '' : ' and digital codes' ?> are sent to this address.</p>
          </div>
        </section>

        <!-- Delivery -->
        <?php if ($summary['has_physical']): ?>
        <section class="sh-checkout-card">
          <h2 class="sh-checkout-card__title"><?= sh_icon('map-pin', 17) ?> Delivery Address</h2>
          <div class="sh-field">
            <label class="sh-field__label" for="ck-addr">Street address <span class="sh-field__req">*</span></label>
            <input class="sh-input <?= isset($errors['address_line']) ? 'sh-input--error' : '' ?>" id="ck-addr"
                   name="address_line" value="<?= e($form['address_line']) ?>" required maxlength="240"
                   placeholder="House / road / apartment">
          </div>
          <div class="sh-grid3">
            <div class="sh-field">
              <label class="sh-field__label" for="ck-area">Area</label>
              <input class="sh-input" id="ck-area" name="area" value="<?= e($form['area']) ?>" maxlength="110">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="ck-city">City <span class="sh-field__req">*</span></label>
              <input class="sh-input <?= isset($errors['city']) ? 'sh-input--error' : '' ?>" id="ck-city"
                     name="city" value="<?= e($form['city']) ?>" required maxlength="110">
            </div>
            <div class="sh-field">
              <label class="sh-field__label" for="ck-post">Postcode</label>
              <input class="sh-input" id="ck-post" name="postcode" value="<?= e($form['postcode']) ?>" maxlength="20">
            </div>
          </div>
          <div class="sh-field">
            <span class="sh-field__label">Delivery zone</span>
            <label class="sh-check">
              <input type="radio" name="delivery_zone" value="inside" <?= $form['delivery_zone'] !== 'outside' ? 'checked' : '' ?>>
              <span>Inside city — <?= e(sh_money(sh_setting('delivery_fee_inside', '60'))) ?> (1–2 working days)</span>
            </label>
            <label class="sh-check">
              <input type="radio" name="delivery_zone" value="outside" <?= $form['delivery_zone'] === 'outside' ? 'checked' : '' ?>>
              <span>Outside city — <?= e(sh_money(sh_setting('delivery_fee_outside', '120'))) ?> (2–4 working days)</span>
            </label>
            <p class="sh-field__hint">Changing the zone updates the total after you place the order form submission.</p>
          </div>
          <div class="sh-field" style="margin-bottom:0">
            <label class="sh-field__label" for="ck-note">Order note (optional)</label>
            <textarea class="sh-textarea" id="ck-note" name="note" maxlength="480"
                      placeholder="Delivery instructions, landmark, preferred time…"><?= e($form['note']) ?></textarea>
          </div>
        </section>
        <?php else: ?>
        <section class="sh-checkout-card">
          <h2 class="sh-checkout-card__title"><?= sh_icon('download', 17) ?> Digital Delivery</h2>
          <div class="sh-alert sh-alert--info" style="margin:0">
            <?= sh_icon('info', 16) ?>
            <span>Your order contains digital products only. Codes are delivered to your account and email as soon as the payment is verified — no shipping address is required.</span>
          </div>
        </section>
        <?php endif; ?>

        <!-- Order items -->
        <section class="sh-checkout-card">
          <h2 class="sh-checkout-card__title"><?= sh_icon('package', 17) ?> Order Items (<?= (int)$summary['count'] ?>)</h2>
          <?php foreach ($summary['items'] as $it): ?>
            <div class="sh-mini-item">
              <img class="sh-mini-item__img" src="<?= e(sh_product_image($it['image'])) ?>" alt="" loading="lazy">
              <div class="sh-mini-item__name">
                <?= e($it['name']) ?>
                <div class="sh-mini-item__qty">Qty <?= (int)$it['quantity'] ?> × <?= e(sh_money($it['unit_price'])) ?></div>
              </div>
              <span class="sh-mini-item__price"><?= e(sh_money($it['line_total'])) ?></span>
            </div>
          <?php endforeach; ?>
        </section>
      </div>

      <!-- Summary -->
      <aside class="sh-summary">
        <h2 class="sh-summary__title">Order Summary</h2>
        <div class="sh-summary__row"><span>Subtotal (<?= (int)$summary['count'] ?> items)</span><span><?= e(sh_money($summary['subtotal'])) ?></span></div>
        <?php if ($summary['discount'] > 0): ?>
          <div class="sh-summary__row sh-summary__row--discount">
            <span>Coupon <?= e($summary['coupon']['code'] ?? '') ?></span><span>-<?= e(sh_money($summary['discount'])) ?></span>
          </div>
        <?php endif; ?>
        <div class="sh-summary__row"><span>Delivery</span>
          <span><?= $summary['delivery'] > 0 ? e(sh_money($summary['delivery'])) : 'Free' ?></span></div>
        <div class="sh-summary__total"><span>Total payable</span><span><?= e(sh_money($summary['total'])) ?></span></div>

        <?php if ($methods): ?>
          <div class="sh-field" style="margin:14px 0 2px">
            <span class="sh-field__label">Payment method <span class="sh-field__req">*</span></span>
            <?php foreach ($methods as $paymentMethod): $paymentLogo = sh_payment_logo_url($paymentMethod); ?>
              <label class="sh-check" style="align-items:center;margin:8px 0">
                <input type="radio" name="payment_method_id" value="<?= (int)$paymentMethod['id'] ?>"
                       <?= (int)$paymentMethod['id'] === $selectedMethodId ? 'checked' : '' ?> required>
                <?php if ($paymentLogo !== ''): ?><img src="<?= e($paymentLogo) ?>" alt="" style="width:25px;height:25px;object-fit:contain;margin:0 5px">
                <?php else: ?><?= sh_icon($paymentMethod['type'] === 'cod' ? 'truck' : 'credit-card', 15) ?><?php endif; ?>
                <span><?= e($paymentMethod['name']) ?><?= $paymentMethod['type'] === 'cod' ? ' — Cash on Delivery' : '' ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!$methods): ?>
          <div class="sh-alert sh-alert--warning" style="margin:12px 0 0">
            <?= sh_icon('alert', 16) ?>
            <span>No payment method is currently available. Please contact customer support to complete your order.</span>
          </div>
        <?php endif; ?>
        <button class="sh-btn sh-btn--lg sh-btn--block" style="margin-top:14px" type="submit" <?= $methods ? '' : 'disabled' ?>>
          <?= sh_icon('check-circle', 17) ?> Place Order
        </button>
        <div class="sh-secure-note" style="margin-top:12px">
          <?= sh_icon('lock', 15) ?>
          <span>Your order is processed over a secure connection. Prices and totals are verified on our server before the order is created.</span>
        </div>
      </aside>
    </div>
  </form>
</div>
<?php require_once SH_ROOT . '/includes/footer.php'; ?>
