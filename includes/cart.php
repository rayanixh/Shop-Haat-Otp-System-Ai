<?php
/**
 * Cart engine. Prices, discounts and totals are ALWAYS recomputed from the
 * database — no client-supplied price is ever trusted.
 */

require_once __DIR__ . '/auth.php';
// Structured colour/gallery relations are additive. Running the idempotent
// version check here keeps cart APIs safe immediately after an upgrade, even
// when a shopper has an old non-product page cached in the browser.
require_once __DIR__ . '/product-schema.php';
sh_product_schema_ensure();

const SH_MAX_QTY = 20;

function sh_cart_items(): array
{
    $cartId = sh_cart_id(false);
    if ($cartId <= 0) { return []; }
    // A checkout can start while a transaction is already open, which defers
    // the additive migration. Keep a pre-migration install orderable with its
    // original product-only cart lines instead of failing on a missing column.
    $variantSelect = sh_cart_has_variant_column()
        ? 'ci.variant AS selected_colour'
        : "'' AS selected_colour";
    return sh_all(
        'SELECT ci.id AS item_id, ci.quantity, ' . $variantSelect . ',
                p.id AS product_id, p.name, p.slug, p.price,
                p.compare_price, p.stock, p.image, p.product_type, p.status, p.sku,
                c.name AS category_name
         FROM cart_items ci
         JOIN products p ON p.id = ci.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE ci.cart_id = ?
         ORDER BY ci.id DESC',
        [$cartId]
    );
}

/**
 * Recalculate the whole cart server-side.
 * @return array{items:array,subtotal:float,discount:float,delivery:float,total:float,count:int,savings:float,coupon:?array,issues:array}
 */
function sh_cart_summary(?string $couponCode = null, string $deliveryZone = 'inside'): array
{
    $rows = sh_cart_items();
    $items = [];
    $subtotal = 0.0;
    $savings = 0.0;
    $count = 0;
    $issues = [];
    $hasPhysical = false;

    foreach ($rows as $r) {
        $qty = max(1, min(SH_MAX_QTY, (int)$r['quantity']));
        $available = (int)$r['stock'];
        $unavailable = ((int)$r['status'] !== 1);
        // Digital products are no longer sold: the code-delivery feature that
        // fulfilled them has been removed, so such a line is never ordered.
        $notOrderable = (string)($r['product_type'] ?? 'physical') !== 'physical';

        if ($unavailable) {
            $issues[] = $r['name'] . ' is no longer available and was not included.';
            continue;
        }
        if ($notOrderable) {
            $issues[] = $r['name'] . ' can no longer be ordered online and was not included. Please contact support.';
            continue;
        }
        if ($available <= 0) {
            $issues[] = $r['name'] . ' is out of stock and was not included.';
            continue;
        }
        if ($qty > $available) {
            $qty = $available;
            $issues[] = 'Only ' . $available . ' unit(s) of ' . $r['name'] . ' are in stock; the quantity was adjusted.';
        }
        $price = (float)$r['price'];
        $line = round($price * $qty, 2);
        $subtotal += $line;
        if ((float)$r['compare_price'] > $price) {
            $savings += round(((float)$r['compare_price'] - $price) * $qty, 2);
        }
        $count += $qty;
        if ($r['product_type'] === 'physical') { $hasPhysical = true; }

        $baseVariant = sh_order_item_variant((string)($r['category_name'] ?? ''), (string)($r['sku'] ?? ''));
        $colour = trim((string)($r['selected_colour'] ?? ''));
        $variantParts = array_values(array_filter([$baseVariant, $colour], static fn(string $v): bool => $v !== ''));
        $items[] = [
            'item_id'       => (int)$r['item_id'],
            'product_id'    => (int)$r['product_id'],
            'name'          => $r['name'],
            'slug'          => $r['slug'],
            'image'         => $r['image'],
            'product_type'  => $r['product_type'],
            'variant'       => implode(' · ', $variantParts),
            'colour'        => $colour,
            'unit_price'    => $price,
            'compare_price' => (float)$r['compare_price'],
            'quantity'      => $qty,
            'stock'         => $available,
            'line_total'    => $line,
        ];
    }

    $subtotal = round($subtotal, 2);

    // Coupon (validated against the database, never the browser)
    $discount = 0.0;
    $coupon = null;
    if ($couponCode !== null && $couponCode !== '' && $subtotal > 0) {
        $res = sh_coupon_evaluate($couponCode, $subtotal);
        if ($res['valid']) {
            $coupon = $res['coupon'];
            $discount = $res['discount'];
        } else {
            $issues[] = $res['error'];
        }
    }

    // Delivery
    $delivery = 0.0;
    if ($hasPhysical && $subtotal > 0) {
        $inside = (float)sh_setting('delivery_fee_inside', '60');
        $outside = (float)sh_setting('delivery_fee_outside', '120');
        $free = (float)sh_setting('free_delivery_over', '0');
        $delivery = ($deliveryZone === 'outside') ? $outside : $inside;
        if ($free > 0 && ($subtotal - $discount) >= $free) { $delivery = 0.0; }
    }

    $total = round(max(0, $subtotal - $discount) + $delivery, 2);

    return [
        'items'    => $items,
        'subtotal' => $subtotal,
        'discount' => round($discount, 2),
        'delivery' => round($delivery, 2),
        'total'    => $total,
        'count'    => $count,
        'savings'  => round($savings, 2),
        'coupon'   => $coupon,
        'issues'   => $issues,
        'has_physical' => $hasPhysical,
    ];
}

function sh_cart_count(): int
{
    try {
        $cartId = sh_cart_id(false);
        if ($cartId <= 0) { return 0; }
        return (int)sh_val(
            'SELECT COALESCE(SUM(ci.quantity),0) FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = ? AND p.status = 1',
            [$cartId],
            0
        );
    } catch (Throwable $e) {
        sh_log_exception($e, 'cart-count');
        return 0;
    }
}

/** @return array{ok:bool,error?:string,count?:int} */
function sh_cart_add(int $productId, int $qty = 1, string $variant = ''): array
{
    $qty = max(1, min(SH_MAX_QTY, $qty));
    $p = sh_one('SELECT id, name, stock, status, product_type FROM products WHERE id = ? LIMIT 1', [$productId]);
    if ($p === null || (int)$p['status'] !== 1) {
        return ['ok' => false, 'error' => 'This product is not available.'];
    }
    if ((string)($p['product_type'] ?? 'physical') !== 'physical') {
        return ['ok' => false, 'error' => 'This product can no longer be ordered online. Please contact support.'];
    }
    if ((int)$p['stock'] <= 0) {
        return ['ok' => false, 'error' => 'This product is currently out of stock.'];
    }
    // Never trust a client-supplied colour: only a value from this product's
    // structured colour selections may become a cart/order variant.
    $choice = sh_product_colour_variant($productId, $variant);
    if (!$choice['ok']) { return ['ok' => false, 'error' => (string)$choice['error']]; }
    $variant = (string)$choice['variant'];

    $cartId = sh_cart_id(true);
    $hasVariant = sh_cart_has_variant_column();
    if ($hasVariant) {
        $existing = sh_one('SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant = ?', [$cartId, $productId, $variant]);
        // The stock limit applies to the product across all colour lines, not to
        // each colour independently.
        $otherQty = (int)sh_val('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant <> ?', [$cartId, $productId, $variant], 0);
    } else {
        // Legacy unique key is (cart_id, product_id); a colour cannot create a
        // second line until the additive migration has run.
        $existing = sh_one('SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId]);
        $otherQty = 0;
    }
    $availableForThisColour = max(0, (int)$p['stock'] - $otherQty);
    $newQty = (int)($existing['quantity'] ?? 0) + $qty;
    if ($existing && (int)$existing['quantity'] >= $availableForThisColour) {
        return ['ok' => false, 'error' => 'No additional stock is available for this product.'];
    }
    $newQty = min($newQty, $availableForThisColour, SH_MAX_QTY);
    if ($newQty < 1) {
        return ['ok' => false, 'error' => 'No additional stock is available for this product.'];
    }
    if ($hasVariant) {
        sh_query(
            'INSERT INTO cart_items (cart_id, product_id, variant, quantity) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
            [$cartId, $productId, $variant, $newQty]
        );
    } else {
        sh_query(
            'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
            [$cartId, $productId, $newQty]
        );
    }
    return ['ok' => true, 'count' => sh_cart_count()];
}

function sh_cart_update(int $productId, int $qty, string $variant = ''): array
{
    $cartId = sh_cart_id(false);
    if ($cartId <= 0) { return ['ok' => false, 'error' => 'Your cart is empty.']; }
    $variant = trim(mb_substr($variant, 0, 190));
    if ($qty <= 0) { return sh_cart_remove($productId, $variant); }
    $p = sh_one('SELECT stock, status, product_type FROM products WHERE id = ? LIMIT 1', [$productId]);
    if ($p === null || (int)$p['status'] !== 1) {
        return ['ok' => false, 'error' => 'This product is not available.'];
    }
    if ((string)($p['product_type'] ?? 'physical') !== 'physical') {
        return ['ok' => false, 'error' => 'This product can no longer be ordered online. Please contact support.'];
    }
    $qty = min(SH_MAX_QTY, $qty);
    $hasVariant = sh_cart_has_variant_column();
    $otherQty = $hasVariant
        ? (int)sh_val('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant <> ?', [$cartId, $productId, $variant], 0)
        : 0;
    $available = max(0, (int)$p['stock'] - $otherQty);
    if ($qty > $available) {
        return ['ok' => false, 'error' => 'Only ' . $available . ' unit(s) available.'];
    }
    if ($hasVariant) {
        sh_query('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ? AND variant = ?', [$qty, $cartId, $productId, $variant]);
    } else {
        sh_query('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?', [$qty, $cartId, $productId]);
    }
    return ['ok' => true, 'count' => sh_cart_count()];
}

function sh_cart_remove(int $productId, string $variant = ''): array
{
    $cartId = sh_cart_id(false);
    $variant = trim(mb_substr($variant, 0, 190));
    if ($cartId > 0) {
        if (sh_cart_has_variant_column()) {
            sh_query('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ? AND variant = ?', [$cartId, $productId, $variant]);
        } else {
            sh_query('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId]);
        }
    }
    return ['ok' => true, 'count' => sh_cart_count()];
}

function sh_cart_clear(): array
{
    $cartId = sh_cart_id(false);
    if ($cartId > 0) { sh_query('DELETE FROM cart_items WHERE cart_id = ?', [$cartId]); }
    return ['ok' => true, 'count' => 0];
}

// ---------------------------------------------------------------------------
// Coupons
// ---------------------------------------------------------------------------
/**
 * Evaluate one already-loaded coupon row. The checkout transaction uses this
 * after SELECT ... FOR UPDATE so a usage-limited coupon cannot be oversold by
 * two concurrent orders.
 */
function sh_coupon_evaluate_record(?array $c, float $subtotal): array
{
    if ($c === null || (int)($c['status'] ?? 0) !== 1) {
        return ['valid' => false, 'error' => 'This coupon code is not valid.', 'discount' => 0.0];
    }
    $now = time();
    if (!empty($c['starts_at']) && strtotime((string)$c['starts_at']) > $now) {
        return ['valid' => false, 'error' => 'This coupon is not active yet.', 'discount' => 0.0];
    }
    if (!empty($c['expires_at']) && strtotime((string)$c['expires_at']) < $now) {
        return ['valid' => false, 'error' => 'This coupon has expired.', 'discount' => 0.0];
    }
    if ((int)($c['usage_limit'] ?? 0) > 0 && (int)($c['used_count'] ?? 0) >= (int)$c['usage_limit']) {
        return ['valid' => false, 'error' => 'This coupon has reached its usage limit.', 'discount' => 0.0];
    }
    if ($subtotal < (float)($c['min_order'] ?? 0)) {
        return ['valid' => false, 'error' => 'This coupon requires a minimum order of ' . sh_money($c['min_order']) . '.', 'discount' => 0.0];
    }
    $discount = ($c['type'] ?? '') === 'percent'
        ? $subtotal * ((float)($c['value'] ?? 0) / 100)
        : (float)($c['value'] ?? 0);
    if ((float)($c['max_discount'] ?? 0) > 0) { $discount = min($discount, (float)$c['max_discount']); }
    $discount = round(min($discount, $subtotal), 2);
    return ['valid' => true, 'error' => '', 'discount' => $discount, 'coupon' => $c];
}

function sh_coupon_evaluate(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    if ($code === '') { return ['valid' => false, 'error' => 'Enter a coupon code.', 'discount' => 0.0]; }
    return sh_coupon_evaluate_record(sh_one('SELECT * FROM coupons WHERE code = ? LIMIT 1', [$code]), $subtotal);
}
