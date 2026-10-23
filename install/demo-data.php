<?php
/**
 * Optional starter catalogue ("demo data").
 *
 * Everything here runs only when the operator selected Demo Data → ON in the
 * installer. The storefront never calls this file, so a page view, a login, an
 * upgrade or a background request cannot insert sample content, and there is no
 * admin screen that can seed or remove it: the choice is made once, at install
 * time, and afterwards the owner manages real content in the normal admin pages.
 *
 * sh_demo_manifest() is the single source of truth for the sample content.
 *
 * Original content only — no third-party brand assets, no external images.
 * Placeholder artwork is generated locally as SVG.
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

/**
 * The bundled starter catalogue, as data.
 *
 * @return array<string,mixed>
 */
function sh_demo_manifest(): array
{
    // Image colours for the generated placeholder artwork.
    $palette = [
        ['#e8501b', '#f59e0b'], ['#1d4ed8', '#38bdf8'], ['#0f766e', '#34d399'],
        ['#7c3aed', '#c084fc'], ['#be123c', '#fb7185'], ['#b45309', '#fbbf24'],
        ['#0369a1', '#22d3ee'], ['#4d7c0f', '#a3e635'],
    ];

    // [name, icon, description]
    $cats = [
        ['Electronics', 'zap', 'Phones, audio and everyday gadgets'],
        ['Home & Kitchen', 'home', 'Appliances and kitchen essentials'],
        ['Fashion', 'tag', 'Clothing, footwear and accessories'],
        ['Groceries', 'package', 'Daily food and household supplies'],
        ['Beauty & Care', 'heart', 'Skincare, haircare and grooming'],
        ['Books & Stationery', 'list', 'Books, notebooks and office supplies'],
        ['Sports & Outdoor', 'trending-up', 'Fitness gear and outdoor equipment'],
    ];

    // [name, category, maker, price, compare, stock, type, featured, flash, sold, rating, reviews, short]
    // The maker word is already part of each product's display name. It is kept
    // in the rows for readability only — the store has no brand system, so it is
    // never stored as data of its own.
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

        ['Papermill A5 Hardcover Notebook', 'Books & Stationery', 'Papermill', 320, 450, 180, 'physical', 0, 0, 297, 4.3, 66, '192-page ruled notebook with elastic closure and ribbon marker.'],
        ['Papermill Gel Pen Pack of 10', 'Books & Stationery', 'Papermill', 240, 350, 220, 'physical', 0, 1, 512, 4.1, 84, 'Smooth 0.5mm gel pens with quick-drying ink.'],
        ['Papermill Desk Organiser Set', 'Books & Stationery', 'Papermill', 890, 1250, 44, 'physical', 0, 0, 118, 4.2, 37, 'Five-piece desktop organiser for pens, notes and small stationery.'],

        ['Trekpoint Adjustable Dumbbell 10kg', 'Sports & Outdoor', 'Trekpoint', 2450, 3300, 26, 'physical', 0, 0, 96, 4.3, 41, 'Vinyl-coated adjustable dumbbell set with secure collar locks.'],
        ['Trekpoint Anti-Slip Yoga Mat 6mm', 'Sports & Outdoor', 'Trekpoint', 1150, 1650, 58, 'physical', 1, 1, 231, 4.5, 107, 'High-density TPE mat with dual-sided grip texture and carry strap.'],
        ['Trekpoint Insulated Steel Bottle 1L', 'Sports & Outdoor', 'Trekpoint', 950, 1400, 87, 'physical', 0, 0, 344, 4.4, 121, 'Double-wall vacuum bottle keeping drinks hot or cold for 18 hours.'],
    ];

    // [rating, title, body]
    $texts = [
        [5, 'Exactly as described', 'Delivery was quick and the product matched the listing photos. Packaging was sealed properly.'],
        [4, 'Good value for the price', 'Works well for daily use. Took two days to arrive in Dhaka, which is reasonable.'],
        [5, 'Will order again', 'Second time buying from this store. Genuine product and the support team responded quickly.'],
        [4, 'Solid quality', 'Build quality is better than I expected at this price point. Recommended.'],
        [3, 'Decent but packaging could improve', 'The item is fine, though the outer box arrived slightly dented by the courier.'],
    ];

    // Author names used for the sample reviews.
        $names = ['Rakib H.', 'Nusrat J.', 'Tanvir A.', 'Sadia K.', 'Mahmud R.', 'Farhana S.', 'Imran C.', 'Priya D.'];

    $coupons = [
        // [code, type, value, min_order, max_discount (0 = no cap), usage_limit (0 = unlimited), valid_days]
        ['WELCOME10', 'percent', 10, 1000, 500, 500, 90],
        ['SAVE200', 'fixed', 200, 2000, 0, 300, 60],
        ['FREESHIP', 'fixed', 60, 800, 0, 1000, 120],
    ];

    // Homepage promo content: the slider slides and the three feature cards.
    // [placement, slot, tag, title, subtitle, button_text, button_url, image, overlay, sort_order]
    $promos = [
        ['slider', null, 'Flash Sale Live', 'Up to 40% off electronics and home essentials',
            'Limited stock on daily deals. New offers added every morning at 10:00 AM.',
            'Shop Flash Sale', 'products.php?flash=1', 'promo/slide-flash.jpg', 70, 1],
        ['slider', null, 'Everyday Value', 'Groceries and daily essentials at honest prices',
            'Free delivery on orders over the qualifying amount inside the city.',
            'Start Shopping', 'products.php?sort=popular', 'promo/slide-grocery.jpg', 70, 3],
        ['card', 'card_1', null, 'New Arrivals', 'Fresh stock added this week',
            'Explore', 'products.php?sort=newest', 'promo/card-new.jpg', 70, 1],
        ['card', 'card_2', null, 'Clearance Offers', '20% off or more',
            'View deals', 'products.php?discount=20', 'promo/card-clearance.jpg', 70, 2],
        ['card', 'card_3', null, 'Track Your Order', 'Live status in seconds',
            'Track now', 'track.php', 'promo/card-track.jpg', 70, 3],
    ];

    return [
        'version'    => 1,
        'palette'    => $palette,
        // Clearly-fake wallet numbers used only by a demo install, so the manual
        // routes are testable immediately. A demo-OFF install leaves them blank.
        'demo_accounts' => [
            'bkash'  => '01700000000',
            'nagad'  => '01800000000',
            'rocket' => '018000000001',
        ],
        'categories' => $cats,
        'products'   => $rows,
        'review_pool' => ['texts' => $texts, 'names' => $names],
        'coupons'    => $coupons,
        'promos'     => $promos,
    ];
}

/**
 * Whether the sample content was installed on this store.
 *
 * 'installed' — the seeder completed (the marker is written by the seeder itself)
 * 'none'      — the installer recorded explicitly that no sample content was wanted
 * ''          — an install that predates the marker; treated as "do not seed", so
 *               a legacy store is never filled with sample content by accident
 */
function sh_demo_state(PDO $pdo): string
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $st->execute(['demo_data_state']);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Insert the starter catalogue.
 *
 * Idempotent at row level: every record is looked up by its own unique key
 * (slug, code or title) before it is inserted, so running this again cannot
 * duplicate anything.
 *
 * Safety: the catalogue is only ever written when the caller passes
 * $explicitRequest = true, which only the installer does for a validated
 * Demo Data = ON submission. A page view, a login, an upgrade or a stray call
 * can therefore never insert sample content, and a store that already recorded
 * 'installed' is never written to twice.
 *
 * @return array<string,int|string|bool> counts of what was actually inserted
 */
function sh_install_demo_data(PDO $pdo, bool $explicitRequest = false): array
{
    $m = sh_demo_manifest();
    $report = [
        'installed' => false, 'reason' => '',
        'categories' => 0, 'products' => 0, 'reviews' => 0,
        'coupons' => 0, 'slides' => 0, 'cards' => 0,
    ];

    $state = sh_demo_state($pdo);
    if ($state === 'installed') {
        $report['reason'] = 'already installed';
        return $report;
    }
    // Only the installer's validated Demo Data = ON submission passes true. A
    // stray call from any other code path is a no-op, whatever the state says.
    if (!$explicitRequest) {
        $report['reason'] = $state === ''
            ? 'no explicit demo-data request'
            : 'this store already recorded its demo-data choice (' . $state . ')';
        return $report;
    }

    $find = static function (PDO $pdo, string $table, string $column, string $value): int {
        $st = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 1');
        $st->execute([$value]);
        return (int)$st->fetchColumn();
    };

    $catIds = [];
    foreach ($m['categories'] as $i => $c) {
        $slug = sh_slug($c[0]);
        $id = $find($pdo, 'categories', 'slug', $slug);
        if ($id === 0) {
            $pdo->prepare('INSERT INTO categories (name, slug, icon, description, sort_order, status) VALUES (?,?,?,?,?,1)')
                ->execute([$c[0], $slug, $c[1], $c[2], $i + 1]);
            $id = (int)$pdo->lastInsertId();
            $report['categories']++;
        }
        $catIds[$c[0]] = $id;
    }

    $insertProduct = $pdo->prepare(
        'INSERT INTO products (category_id, name, slug, sku, short_description, description, specifications,
            price, compare_price, stock, product_type, image, rating, review_count, sold_count, view_count,
            is_featured, is_flash_sale, flash_sale_ends_at, status, meta_title, meta_description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)'
    );
    $flashEnd = date('Y-m-d H:i:s', time() + 86400 * 2 + 3600 * 5);
    $productIds = [];
    $palette = $m['palette'];
    foreach ($m['products'] as $i => $r) {
        [$name, $cat, $maker, $price, $compare, $stock, $type, $feat, $flash, $sold, $rating, $reviews, $short] = $r;
        $slug = sh_slug($name);
        $id = $find($pdo, 'products', 'slug', $slug);
        if ($id === 0) {
            $pal = $palette[$i % count($palette)];
            $img = sh_demo_placeholder($name, $pal[0], $pal[1]);
            $desc = $short . "\n\n"
                . "Every item sold on our marketplace is sourced from verified suppliers and checked before dispatch. "
                . "Orders placed before 4:00 PM are usually handed to the courier the same working day.\n\n"
                . "The product ships in its original manufacturer packaging with all standard accessories included. A seven-day replacement window applies to manufacturing defects.";
            $specs = "Warranty: 7-day replacement, 6-month service\nCountry of origin: As per manufacturer\nPackage contents: Product, manual, warranty card\nDelivery: 1-3 working days inside Dhaka";
            $insertProduct->execute([
                $catIds[$cat] ?? null,
                $name, $slug, 'SH-' . str_pad((string)($i + 1001), 5, '0', STR_PAD_LEFT),
                $short, $desc, $specs,
                $price, $compare, $stock, $type, $img, $rating, $reviews, $sold, $sold * 3 + 40,
                $feat, $flash, $flash ? $flashEnd : null,
                $name . ' | Buy online at the best price',
                $short,
            ]);
            $id = (int)$pdo->lastInsertId();
            $report['products']++;
        }
        $productIds[] = $id;
    }

    // Sample reviews, only for products that have none of their own — a real
    // review left on a sample product is never joined by a fake one.
    $texts = $m['review_pool']['texts'];
    $names = $m['review_pool']['names'];
    $insertReview = $pdo->prepare("INSERT INTO reviews (product_id, author_name, rating, title, body, status, created_at) VALUES (?,?,?,?,?,'approved',?)");
    foreach ($productIds as $idx => $pid) {
        $has = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE product_id = ?');
        $has->execute([$pid]);
        if ((int)$has->fetchColumn() > 0) { continue; }
        $n = 2 + ($idx % 3);
        for ($i = 0; $i < $n; $i++) {
            $t = $texts[($idx + $i) % count($texts)];
            $insertReview->execute([
                $pid, $names[($idx * 3 + $i) % count($names)], $t[0], $t[1], $t[2],
                date('Y-m-d H:i:s', time() - 86400 * (3 + ($idx + $i) * 2)),
            ]);
            $report['reviews']++;
        }
    }
    // Rating aggregates reflect the real review rows.
    $pdo->exec("UPDATE products p SET
        rating = COALESCE((SELECT ROUND(AVG(r.rating),2) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved'), 0),
        review_count = (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id AND r.status = 'approved')");

    $coupon = $pdo->prepare('INSERT IGNORE INTO coupons (code, type, value, min_order, max_discount, usage_limit, expires_at, status)
                             VALUES (?,?,?,?,?,?,?,1)');
    foreach ($m['coupons'] as $c) {
        $coupon->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], date('Y-m-d H:i:s', time() + 86400 * $c[6])]);
        $report['coupons'] += $coupon->rowCount();
    }

    $insertPromo = $pdo->prepare('INSERT INTO promo_slides
        (placement, slot, tag, title, subtitle, button_text, button_url, image, overlay, sort_order, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)');
    foreach ($m['promos'] as $p) {
        if ($find($pdo, 'promo_slides', 'title', $p[3]) > 0) { continue; }
        $insertPromo->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9]]);
        if ($p[0] === 'card') { $report['cards']++; } else { $report['slides']++; }
    }

    // Demo merchant numbers, so the mobile-wallet routes are testable in a demo
    // store. Only a blank column is ever filled, so a real merchant number is
    // never overwritten; Admin -> Payment Methods lists these for review.
    $fill = $pdo->prepare("UPDATE payment_methods SET account_number = ?
                           WHERE code = ? AND (account_number IS NULL OR account_number = '')");
    foreach ($m['demo_accounts'] as $code => $number) { $fill->execute([$number, $code]); }

    $mark = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $mark->execute(['demo_data_state', 'installed']);
    $mark->execute(['demo_data_version', (string)$m['version']]);

    $report['installed'] = true;
    return $report;
}
