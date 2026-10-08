<?php
/**
 * Cart engine. Prices, discounts and totals are ALWAYS recomputed from the
 * database — no client-supplied price is ever trusted.
 */

require_once __DIR__ . '/auth.php';

const SH_MAX_QTY = 20;

function sh_cart_items(): array
{
    $cartId = sh_cart_id(false);
    if ($cartId <= 0) { return []; }
    return sh_all(
        'SELECT ci.id AS item_id, ci.quantity, p.id AS product_id, p.name, p.slug, p.price,
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

        if ($unavailable) {
            $issues[] = $r['name'] . ' is no longer available and was not included.';
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

        $items[] = [
            'item_id'       => (int)$r['item_id'],
            'product_id'    => (int)$r['product_id'],
            'name'          => $r['name'],
            'slug'          => $r['slug'],
            'image'         => $r['image'],
            'product_type'  => $r['product_type'],
            'variant'       => sh_order_item_variant((string)($r['category_name'] ?? ''), (string)($r['sku'] ?? '')),
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
function sh_cart_add(int $productId, int $qty = 1): array
{
    $qty = max(1, min(SH_MAX_QTY, $qty));
    $p = sh_one('SELECT id, name, stock, status FROM products WHERE id = ? LIMIT 1', [$productId]);
    if ($p === null || (int)$p['status'] !== 1) {
        return ['ok' => false, 'error' => 'This product is not available.'];
    }
    if ((int)$p['stock'] <= 0) {
        return ['ok' => false, 'error' => 'This product is currently out of stock.'];
    }
    $cartId = sh_cart_id(true);
    $existing = sh_one('SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId]);
    $newQty = (int)($existing['quantity'] ?? 0) + $qty;
    if ($newQty > (int)$p['stock']) {
        $newQty = (int)$p['stock'];
        if ($existing && (int)$existing['quantity'] >= $newQty) {
            return ['ok' => false, 'error' => 'No additional stock is available for this product.'];
        }
    }
    $newQty = min(SH_MAX_QTY, $newQty);
    sh_query(
        'INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
        [$cartId, $productId, $newQty]
    );
    return ['ok' => true, 'count' => sh_cart_count()];
}

function sh_cart_update(int $productId, int $qty): array
{
    $cartId = sh_cart_id(false);
    if ($cartId <= 0) { return ['ok' => false, 'error' => 'Your cart is empty.']; }
    if ($qty <= 0) { return sh_cart_remove($productId); }
    $p = sh_one('SELECT stock, status FROM products WHERE id = ? LIMIT 1', [$productId]);
    if ($p === null || (int)$p['status'] !== 1) {
        return ['ok' => false, 'error' => 'This product is not available.'];
    }
    $qty = min(SH_MAX_QTY, $qty);
    if ($qty > (int)$p['stock']) {
        return ['ok' => false, 'error' => 'Only ' . (int)$p['stock'] . ' unit(s) available.'];
    }
    sh_query('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?', [$qty, $cartId, $productId]);
    return ['ok' => true, 'count' => sh_cart_count()];
}

function sh_cart_remove(int $productId): array
{
    $cartId = sh_cart_id(false);
    if ($cartId > 0) {
        sh_query('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $productId]);
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
function sh_coupon_evaluate(string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    if ($code === '') { return ['valid' => false, 'error' => 'Enter a coupon code.', 'discount' => 0.0]; }
    $c = sh_one('SELECT * FROM coupons WHERE code = ? LIMIT 1', [$code]);
    if ($c === null || (int)$c['status'] !== 1) {
        return ['valid' => false, 'error' => 'This coupon code is not valid.', 'discount' => 0.0];
    }
    $now = time();
    if (!empty($c['starts_at']) && strtotime($c['starts_at']) > $now) {
        return ['valid' => false, 'error' => 'This coupon is not active yet.', 'discount' => 0.0];
    }
    if (!empty($c['expires_at']) && strtotime($c['expires_at']) < $now) {
        return ['valid' => false, 'error' => 'This coupon has expired.', 'discount' => 0.0];
    }
    if ((int)$c['usage_limit'] > 0 && (int)$c['used_count'] >= (int)$c['usage_limit']) {
        return ['valid' => false, 'error' => 'This coupon has reached its usage limit.', 'discount' => 0.0];
    }
    if ($subtotal < (float)$c['min_order']) {
        return ['valid' => false, 'error' => 'This coupon requires a minimum order of ' . sh_money($c['min_order']) . '.', 'discount' => 0.0];
    }
    $discount = $c['type'] === 'percent'
        ? $subtotal * ((float)$c['value'] / 100)
        : (float)$c['value'];
    if ((float)$c['max_discount'] > 0) { $discount = min($discount, (float)$c['max_discount']); }
    $discount = round(min($discount, $subtotal), 2);
    return ['valid' => true, 'error' => '', 'discount' => $discount, 'coupon' => $c];
}
