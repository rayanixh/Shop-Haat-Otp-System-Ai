<?php
/**
 * Optional starter catalogue. Original content only — no third-party brand
 * assets, no external images. Placeholder artwork is generated locally as SVG.
 */

function sh_demo_placeholder(string $label, string $c1, string $c2): string
{
    $dir = SH_UPLOAD_DIR . '/products';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        sh_log_line('install', 'Could not create demo image directory: ' . $dir);
        return '';
    }
    $file = 'demo-' . substr(sha1($label . $c1), 0, 12) . '.svg';
    $path = $dir . '/' . $file;
    if (!is_file($path)) {
        $init = mb_strtoupper(mb_substr(trim($label), 0, 1));
        $safe = htmlspecialchars(mb_substr($label, 0, 26), ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" width="400" height="400" role="img" aria-label="{$safe}">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
<stop offset="0" stop-color="{$c1}"/><stop offset="1" stop-color="{$c2}"/></linearGradient></defs>
<rect width="400" height="400" fill="#f6f7f9"/>
<rect x="52" y="52" width="296" height="296" rx="26" fill="url(#g)" opacity=".16"/>
<circle cx="200" cy="176" r="74" fill="url(#g)" opacity=".9"/>
<text x="200" y="200" font-family="system-ui,Segoe UI,Arial" font-size="66" font-weight="700"
 fill="#ffffff" text-anchor="middle">{$init}</text>
<text x="200" y="300" font-family="system-ui,Segoe UI,Arial" font-size="19" font-weight="600"
 fill="#4a5060" text-anchor="middle">{$safe}</text>
</svg>
SVG;
        if (file_put_contents($path, $svg) === false) {
            sh_log_line('install', 'Could not write demo image: ' . $path);
        }
    }
    return $file;
}

function sh_install_demo_data(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() > 0) { return; }

    $palette = [
        ['#e8501b', '#f59e0b'], ['#1d4ed8', '#38bdf8'], ['#0f766e', '#34d399'],
        ['#7c3aed', '#c084fc'], ['#be123c', '#fb7185'], ['#b45309', '#fbbf24'],
        ['#0369a1', '#22d3ee'], ['#4d7c0f', '#a3e635'],
    ];

    // Categories -------------------------------------------------------------
    $cats = [
        ['Electronics', 'zap', 'Phones, audio and everyday gadgets'],
        ['Home & Kitchen', 'home', 'Appliances and kitchen essentials'],
        ['Fashion', 'tag', 'Clothing, footwear and accessories'],
        ['Groceries', 'package', 'Daily food and household supplies'],
        ['Beauty & Care', 'heart', 'Skincare, haircare and grooming'],
        ['Digital Products', 'key', 'Gift cards, subscriptions and top-ups'],
        ['Books & Stationery', 'list', 'Books, notebooks and office supplies'],
        ['Sports & Outdoor', 'trending-up', 'Fitness gear and outdoor equipment'],
    ];
    $cs = $pdo->prepare('INSERT INTO categories (name, slug, icon, description, sort_order, status) VALUES (?,?,?,?,?,1)');
    $catIds = [];
    foreach ($cats as $i => $c) {
        $cs->execute([$c[0], sh_slug($c[0]), $c[1], $c[2], $i + 1]);
        $catIds[$c[0]] = (int)$pdo->lastInsertId();
    }

    // Brands -----------------------------------------------------------------
    $brands = ['Voltaro', 'Nordane', 'Kitchmate', 'Urbanfit', 'Puradaily', 'Lumira', 'Trekpoint', 'Papermill'];
    $bs = $pdo->prepare('INSERT INTO brands (name, slug, status) VALUES (?,?,1)');
    $brandIds = [];
    foreach ($brands as $b) {
        $bs->execute([$b, sh_slug($b)]);
        $brandIds[$b] = (int)$pdo->lastInsertId();
    }

    // Products ---------------------------------------------------------------
    // [name, category, brand, price, compare, stock, type, featured, flash, sold, rating, reviews, short]
    $rows = [
        ['Voltaro Pulse 60 Wireless Headphones', 'Electronics', 'Voltaro', 3450, 4900, 42, 'physical', 1, 1, 318, 4.6, 128, 'Over-ear Bluetooth 5.3 headphones with 40-hour battery and hybrid noise reduction.'],
        ['Voltaro Beat Buds Pro', 'Electronics', 'Voltaro', 1890, 2790, 88, 'physical', 1, 1, 512, 4.4, 214, 'Compact true wireless earbuds with charging case and low-latency game mode.'],
        ['Nordane 20000mAh Fast Power Bank', 'Electronics', 'Nordane', 2190, 2990, 61, 'physical', 0, 1, 274, 4.5, 96, 'Dual-port 22.5W power bank with digital charge display and pass-through charging.'],
        ['Nordane 65W GaN Charging Adapter', 'Electronics', 'Nordane', 1650, 2200, 35, 'physical', 0, 0, 143, 4.3, 51, 'Compact three-port GaN charger for laptops, tablets and phones.'],
        ['Voltaro SmartFit Activity Watch', 'Electronics', 'Voltaro', 2950, 4500, 27, 'physical', 1, 1, 205, 4.2, 87, 'AMOLED fitness watch with heart-rate tracking, SpO2 and 12-day battery.'],
        ['Nordane 1080p Home Security Camera', 'Electronics', 'Nordane', 2380, 3100, 19, 'physical', 0, 0, 64, 4.1, 33, 'Indoor Wi-Fi camera with motion alerts, night vision and two-way audio.'],

        ['Kitchmate 1.8L Electric Kettle', 'Home & Kitchen', 'Kitchmate', 1450, 1990, 54, 'physical', 1, 1, 389, 4.5, 176, 'Stainless steel kettle with auto shut-off and boil-dry protection.'],
        ['Kitchmate 8-Piece Non-Stick Cookware Set', 'Home & Kitchen', 'Kitchmate', 4850, 6900, 12, 'physical', 1, 0, 97, 4.6, 62, 'Induction-ready granite coated cookware with heat-resistant handles.'],
        ['Kitchmate 500W Blender & Grinder', 'Home & Kitchen', 'Kitchmate', 2350, 3200, 30, 'physical', 0, 0, 158, 4.2, 74, 'Two-jar blender with stainless blades and pulse control.'],
        ['Nordane Rechargeable Table Fan', 'Home & Kitchen', 'Nordane', 2790, 3600, 23, 'physical', 0, 1, 121, 4.0, 45, 'Rechargeable fan with three speeds, LED light and up to 8 hours runtime.'],
        ['Kitchmate Airtight Storage Jar Set of 6', 'Home & Kitchen', 'Kitchmate', 890, 1350, 96, 'physical', 0, 0, 342, 4.4, 118, 'BPA-free containers with locking lids for dry food storage.'],

        ['Urbanfit Everyday Cotton T-Shirt', 'Fashion', 'Urbanfit', 590, 890, 150, 'physical', 0, 1, 640, 4.3, 233, 'Pre-shrunk combed cotton tee with a regular fit, available in core colours.'],
        ['Urbanfit Slim Stretch Denim Jeans', 'Fashion', 'Urbanfit', 1690, 2400, 64, 'physical', 1, 0, 189, 4.2, 88, 'Mid-rise stretch denim with reinforced stitching and five pockets.'],
        ['Trekpoint Canvas Backpack 24L', 'Fashion', 'Trekpoint', 1890, 2700, 41, 'physical', 1, 1, 226, 4.5, 104, 'Water-resistant daypack with padded laptop sleeve and side bottle pockets.'],
        ['Urbanfit Classic Leather Belt', 'Fashion', 'Urbanfit', 750, 1100, 78, 'physical', 0, 0, 173, 4.1, 46, 'Genuine split-leather belt with a brushed alloy buckle.'],
        ['Trekpoint All-Day Running Shoes', 'Fashion', 'Trekpoint', 2650, 3900, 33, 'physical', 0, 1, 147, 4.4, 92, 'Breathable knit upper with cushioned EVA midsole for daily training.'],

        ['Puradaily Premium Basmati Rice 5kg', 'Groceries', 'Puradaily', 980, 1200, 200, 'physical', 0, 0, 812, 4.6, 265, 'Aged long-grain basmati rice, sorted and packed for everyday cooking.'],
        ['Puradaily Cold Pressed Mustard Oil 2L', 'Groceries', 'Puradaily', 720, 900, 140, 'physical', 0, 1, 455, 4.5, 141, 'Traditionally pressed mustard oil with no added preservatives.'],
        ['Puradaily Assorted Nuts Pack 500g', 'Groceries', 'Puradaily', 1150, 1500, 85, 'physical', 1, 0, 268, 4.3, 79, 'Roasted almonds, cashews and pistachios in a resealable pouch.'],
        ['Puradaily Pure Honey 1kg', 'Groceries', 'Puradaily', 1290, 1750, 47, 'physical', 0, 1, 194, 4.7, 113, 'Unblended natural honey collected from Sundarbans region apiaries.'],

        ['Lumira Vitamin C Face Serum 30ml', 'Beauty & Care', 'Lumira', 1250, 1800, 72, 'physical', 1, 1, 356, 4.4, 167, 'Brightening serum with stabilised vitamin C and hyaluronic acid.'],
        ['Lumira Daily Sunscreen SPF 50+', 'Beauty & Care', 'Lumira', 980, 1400, 91, 'physical', 0, 0, 289, 4.5, 132, 'Lightweight non-greasy broad-spectrum sunscreen for daily use.'],
        ['Lumira Argan Hair Repair Oil 100ml', 'Beauty & Care', 'Lumira', 690, 990, 110, 'physical', 0, 1, 401, 4.2, 98, 'Nourishing hair oil blend for dry and frizz-prone hair.'],

        ['ShopHaat Gift Card 500', 'Digital Products', null, 500, 550, 0, 'digital', 1, 0, 620, 4.8, 210, 'Instantly delivered store credit code redeemable across the marketplace.'],
        ['ShopHaat Gift Card 1000', 'Digital Products', null, 1000, 1100, 0, 'digital', 1, 1, 480, 4.8, 188, 'Instantly delivered store credit code worth 1000 in value.'],
        ['Game Top-Up Voucher 1000 Coins', 'Digital Products', null, 850, 1200, 0, 'digital', 1, 1, 733, 4.6, 302, 'Digital top-up code delivered to your account within seconds of payment approval.'],
        ['Streaming Subscription Code 1 Month', 'Digital Products', null, 450, 650, 0, 'digital', 0, 1, 528, 4.5, 174, 'One-month subscription activation code delivered instantly after verification.'],

        ['Papermill A5 Hardcover Notebook', 'Books & Stationery', 'Papermill', 320, 450, 180, 'physical', 0, 0, 297, 4.3, 66, '192-page ruled notebook with elastic closure and ribbon marker.'],
        ['Papermill Gel Pen Pack of 10', 'Books & Stationery', 'Papermill', 240, 350, 220, 'physical', 0, 1, 512, 4.1, 84, 'Smooth 0.5mm gel pens with quick-drying ink.'],
        ['Papermill Desk Organiser Set', 'Books & Stationery', 'Papermill', 890, 1250, 44, 'physical', 0, 0, 118, 4.2, 37, 'Five-piece desktop organiser for pens, notes and small stationery.'],

        ['Trekpoint Adjustable Dumbbell 10kg', 'Sports & Outdoor', 'Trekpoint', 2450, 3300, 26, 'physical', 0, 0, 96, 4.3, 41, 'Vinyl-coated adjustable dumbbell set with secure collar locks.'],
        ['Trekpoint Anti-Slip Yoga Mat 6mm', 'Sports & Outdoor', 'Trekpoint', 1150, 1650, 58, 'physical', 1, 1, 231, 4.5, 107, 'High-density TPE mat with dual-sided grip texture and carry strap.'],
        ['Trekpoint Insulated Steel Bottle 1L', 'Sports & Outdoor', 'Trekpoint', 950, 1400, 87, 'physical', 0, 0, 344, 4.4, 121, 'Double-wall vacuum bottle keeping drinks hot or cold for 18 hours.'],
    ];

    $ps = $pdo->prepare(
        'INSERT INTO products (category_id, brand_id, name, slug, sku, short_description, description, specifications,
            price, compare_price, stock, product_type, image, rating, review_count, sold_count, view_count,
            is_featured, is_flash_sale, flash_sale_ends_at, status, meta_title, meta_description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)'
    );
    $flashEnd = date('Y-m-d H:i:s', time() + 86400 * 2 + 3600 * 5);
    $productIds = [];
    foreach ($rows as $i => $r) {
        [$name, $cat, $brand, $price, $compare, $stock, $type, $feat, $flash, $sold, $rating, $reviews, $short] = $r;
        $pal = $palette[$i % count($palette)];
        $img = sh_demo_placeholder($name, $pal[0], $pal[1]);
        $desc = $short . "\n\n"
            . "Every item sold on our marketplace is sourced from verified suppliers and checked before dispatch. "
            . "Orders placed before 4:00 PM are usually handed to the courier the same working day.\n\n"
            . ($type === 'digital'
                ? "This is a digital product. Once your payment is verified, a unique code is reserved for your order and delivered to your account order page immediately. Codes are single-use and are never issued to more than one customer."
                : "The product ships in its original manufacturer packaging with all standard accessories included. A seven-day replacement window applies to manufacturing defects.");
        $specs = $type === 'digital'
            ? "Delivery: Instant after payment verification\nFormat: Single-use alphanumeric code\nValidity: 12 months from issue\nRegion: Bangladesh\nSupport: 24/7 chat and email"
            : "Brand: " . ($brand ?? 'ShopHaat') . "\nWarranty: 7-day replacement, 6-month service\nCountry of origin: As per manufacturer\nPackage contents: Product, manual, warranty card\nDelivery: 1-3 working days inside Dhaka";
        $ps->execute([
            $catIds[$cat] ?? null,
            $brand !== null ? ($brandIds[$brand] ?? null) : null,
            $name, sh_slug($name), 'SH-' . str_pad((string)($i + 1001), 5, '0', STR_PAD_LEFT),
            $short, $desc, $specs,
            $price, $compare, $stock, $type, $img, $rating, $reviews, $sold, $sold * 3 + 40,
            $feat, $flash, $flash ? $flashEnd : null,
            mb_substr($name . ' | Buy online at the best price', 0, 185),
            mb_substr($short, 0, 290),
        ]);
        $productIds[] = ['id' => (int)$pdo->lastInsertId(), 'type' => $type, 'name' => $name];
    }

    // Digital codes for digital products (stock derives from available codes)
    $cds = $pdo->prepare('INSERT IGNORE INTO product_codes (product_id, code, status) VALUES (?,?,\'available\')');
    foreach ($productIds as $p) {
        if ($p['type'] !== 'digital') { continue; }
        for ($i = 0; $i < 12; $i++) {
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $cds->execute([$p['id'], $code]);
        }
        $pdo->prepare('UPDATE products SET stock = (SELECT COUNT(*) FROM product_codes WHERE product_id = ? AND status = \'available\') WHERE id = ?')
            ->execute([$p['id'], $p['id']]);
    }

    // Reviews
    $texts = [
        [5, 'Exactly as described', 'Delivery was quick and the product matched the listing photos. Packaging was sealed properly.'],
        [4, 'Good value for the price', 'Works well for daily use. Took two days to arrive in Dhaka, which is reasonable.'],
        [5, 'Will order again', 'Second time buying from this store. Genuine product and the support team responded quickly.'],
        [4, 'Solid quality', 'Build quality is better than I expected at this price point. Recommended.'],
        [3, 'Decent but packaging could improve', 'The item is fine, though the outer box arrived slightly dented by the courier.'],
    ];
    $names = ['Rakib H.', 'Nusrat J.', 'Tanvir A.', 'Sadia K.', 'Mahmud R.', 'Farhana S.', 'Imran C.', 'Priya D.'];
    $rs = $pdo->prepare('INSERT INTO reviews (product_id, author_name, rating, title, body, status, created_at) VALUES (?,?,?,?,?,\'approved\',?)');
    foreach ($productIds as $idx => $p) {
        $n = 2 + ($idx % 3);
        for ($i = 0; $i < $n; $i++) {
            $t = $texts[($idx + $i) % count($texts)];
            $rs->execute([
                $p['id'], $names[($idx * 3 + $i) % count($names)], $t[0], $t[1], $t[2],
                date('Y-m-d H:i:s', time() - 86400 * (3 + ($idx + $i) * 2)),
            ]);
        }
    }
    // Recalculate rating aggregates from the real review rows
    $pdo->exec('UPDATE products p SET
        rating = COALESCE((SELECT ROUND(AVG(r.rating),2) FROM reviews r WHERE r.product_id = p.id AND r.status = \'approved\'), 0),
        review_count = (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = \'approved\')');

    // Coupons
    $cp = $pdo->prepare('INSERT IGNORE INTO coupons (code, type, value, min_order, max_discount, usage_limit, expires_at, status)
                         VALUES (?,?,?,?,?,?,?,1)');
    $cp->execute(['WELCOME10', 'percent', 10, 1000, 500, 500, date('Y-m-d H:i:s', time() + 86400 * 90)]);
    $cp->execute(['SAVE200', 'fixed', 200, 2000, 0, 300, date('Y-m-d H:i:s', time() + 86400 * 60)]);
    $cp->execute(['FREESHIP', 'fixed', 60, 800, 0, 1000, date('Y-m-d H:i:s', time() + 86400 * 120)]);
}
