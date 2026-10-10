<?php
/**
 * Canonical MySQL/MariaDB schema. Returns an ordered list of DDL statements.
 * All InnoDB + utf8mb4 for shared-hosting compatibility.
 */

function sh_schema_tables(): array
{
    return [
        'users', 'admins', 'categories', 'brands', 'products', 'product_images',
        'cart', 'cart_items', 'orders', 'order_items', 'payments', 'payment_methods',
        'payment_gateways', 'product_codes', 'reviews', 'wishlist', 'coupons',
        'addresses', 'settings', 'notifications', 'notification_logs', 'app_logs',
        'promo_slides', 'whatsapp_messages', 'whatsapp_message_statuses',
        'telegram_admin_log', 'telegram_updates',
        'couriers', 'shipments', 'shipment_events',
        'otp_verifications', 'security_logs', 'password_resets',
    ];
}

function sh_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [];

    $sql[] = "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(30) DEFAULT NULL,
        phone_verified TINYINT(1) NOT NULL DEFAULT 0,
        phone_verified_at DATETIME DEFAULT NULL,
        phone_verification_method VARCHAR(30) DEFAULT NULL,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('active','blocked') NOT NULL DEFAULT 'active',
        auth_provider VARCHAR(20) NOT NULL DEFAULT 'email',
        google_id VARCHAR(64) DEFAULT NULL,
        avatar VARCHAR(500) DEFAULT NULL,
        avatar_source VARCHAR(20) DEFAULT NULL,
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_email (email),
        UNIQUE KEY uq_users_google_id (google_id),
        KEY idx_users_phone (phone)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('superadmin','manager') NOT NULL DEFAULT 'superadmin',
        status ENUM('active','disabled') NOT NULL DEFAULT 'active',
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_admins_email (email)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        parent_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(140) NOT NULL,
        icon VARCHAR(60) NOT NULL DEFAULT 'grid',
        image VARCHAR(190) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_categories_slug (slug),
        KEY idx_categories_parent (parent_id),
        CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS brands (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(140) NOT NULL,
        logo VARCHAR(190) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_brands_slug (slug)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id INT UNSIGNED DEFAULT NULL,
        brand_id INT UNSIGNED DEFAULT NULL,
        name VARCHAR(190) NOT NULL,
        slug VARCHAR(210) NOT NULL,
        sku VARCHAR(60) DEFAULT NULL,
        short_description VARCHAR(300) DEFAULT NULL,
        description TEXT,
        specifications TEXT,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        compare_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        stock INT NOT NULL DEFAULT 0,
        low_stock_threshold INT NOT NULL DEFAULT 5,
        product_type ENUM('physical','digital') NOT NULL DEFAULT 'physical',
        image VARCHAR(190) DEFAULT NULL,
        rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
        review_count INT NOT NULL DEFAULT 0,
        sold_count INT NOT NULL DEFAULT 0,
        view_count INT NOT NULL DEFAULT 0,
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        is_flash_sale TINYINT(1) NOT NULL DEFAULT 0,
        flash_sale_ends_at DATETIME DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        meta_title VARCHAR(190) DEFAULT NULL,
        meta_description VARCHAR(300) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_products_slug (slug),
        KEY idx_products_category (category_id),
        KEY idx_products_brand (brand_id),
        KEY idx_products_status (status),
        KEY idx_products_flash (is_flash_sale, status),
        KEY idx_products_featured (is_featured, status),
        KEY idx_products_price (price),
        CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
        CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS product_images (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        image VARCHAR(190) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pimages_product (product_id),
        CONSTRAINT fk_pimages_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS cart (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED DEFAULT NULL,
        session_token CHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_cart_user (user_id),
        KEY idx_cart_token (session_token),
        CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS cart_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cart_product (cart_id, product_id),
        KEY idx_citems_product (product_id),
        CONSTRAINT fk_citems_cart FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE,
        CONSTRAINT fk_citems_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS coupons (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        min_order DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        max_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        usage_limit INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        starts_at DATETIME DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_coupons_code (code)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS addresses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        label VARCHAR(60) NOT NULL DEFAULT 'Home',
        full_name VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        address_line VARCHAR(255) NOT NULL,
        area VARCHAR(120) DEFAULT NULL,
        city VARCHAR(120) NOT NULL,
        postcode VARCHAR(20) DEFAULT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_addresses_user (user_id),
        CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS payment_methods (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(120) NOT NULL,
        type ENUM('manual','cod','gateway') NOT NULL DEFAULT 'manual',
        logo VARCHAR(190) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        instructions TEXT,
        account_number VARCHAR(80) DEFAULT NULL,
        account_type VARCHAR(60) DEFAULT NULL,
        gateway_id INT UNSIGNED DEFAULT NULL,
        extra_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pmethods_code (code),
        KEY idx_pmethods_status (status, sort_order)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS payment_gateways (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(120) NOT NULL,
        driver VARCHAR(50) NOT NULL,
        logo VARCHAR(190) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        credentials TEXT,
        mode ENUM('sandbox','live') NOT NULL DEFAULT 'sandbox',
        callback_url VARCHAR(255) DEFAULT NULL,
        return_url VARCHAR(255) DEFAULT NULL,
        webhook_url VARCHAR(255) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_gateways_code (code)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_number VARCHAR(30) NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        customer_name VARCHAR(120) NOT NULL,
        customer_email VARCHAR(190) NOT NULL,
        customer_phone VARCHAR(30) NOT NULL,
        phone_verified_at DATETIME DEFAULT NULL,
        verification_required TINYINT(1) NOT NULL DEFAULT 0,
        verification_method VARCHAR(30) DEFAULT NULL,
        shipping_address VARCHAR(255) DEFAULT NULL,
        shipping_area VARCHAR(120) DEFAULT NULL,
        shipping_city VARCHAR(120) DEFAULT NULL,
        shipping_postcode VARCHAR(20) DEFAULT NULL,
        order_note VARCHAR(500) DEFAULT NULL,
        payment_method_id INT UNSIGNED DEFAULT NULL,
        payment_method_name VARCHAR(120) DEFAULT NULL,
        coupon_id INT UNSIGNED DEFAULT NULL,
        coupon_code VARCHAR(50) DEFAULT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        has_digital TINYINT(1) NOT NULL DEFAULT 0,
        telegram_message_id VARCHAR(32) DEFAULT NULL,
        status ENUM('pending','awaiting_payment','payment_submitted','payment_verified','payment_rejected','processing','shipped','delivered','completed','cancelled','returned') NOT NULL DEFAULT 'pending',
        payment_status ENUM('unpaid','submitted','verified','rejected','refunded') NOT NULL DEFAULT 'unpaid',
        codes_delivered TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_orders_number (order_number),
        KEY idx_orders_user (user_id),
        KEY idx_orders_status (status),
        KEY idx_orders_created (created_at),
        CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
        CONSTRAINT fk_orders_pmethod FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED DEFAULT NULL,
        product_name VARCHAR(190) NOT NULL,
        product_image VARCHAR(190) DEFAULT NULL,
        product_variant VARCHAR(190) DEFAULT NULL,
        product_type ENUM('physical','digital') NOT NULL DEFAULT 'physical',
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_oitems_order (order_id),
        KEY idx_oitems_product (product_id),
        CONSTRAINT fk_oitems_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_oitems_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        payment_method_id INT UNSIGNED DEFAULT NULL,
        gateway_id INT UNSIGNED DEFAULT NULL,
        method_name VARCHAR(120) DEFAULT NULL,
        kind ENUM('manual','cod','gateway') NOT NULL DEFAULT 'manual',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        transaction_id VARCHAR(120) DEFAULT NULL,
        sender_phone VARCHAR(30) DEFAULT NULL,
        gateway_reference VARCHAR(190) DEFAULT NULL,
        gateway_payload TEXT,
        status ENUM('pending','verified','rejected','failed','cancelled') NOT NULL DEFAULT 'pending',
        submitted_at DATETIME DEFAULT NULL,
        submission_version INT UNSIGNED NOT NULL DEFAULT 0,
        admin_note VARCHAR(255) DEFAULT NULL,
        verified_by INT UNSIGNED DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payments_order (order_id),
        KEY idx_payments_status (status),
        KEY idx_payments_transaction_id (transaction_id),
        UNIQUE KEY uq_payments_gwref (gateway_id, gateway_reference),
        CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_payments_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS product_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        code VARCHAR(255) NOT NULL,
        status ENUM('available','reserved','used') NOT NULL DEFAULT 'available',
        order_id INT UNSIGNED DEFAULT NULL,
        order_item_id INT UNSIGNED DEFAULT NULL,
        delivered_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_codes_product_code (product_id, code),
        KEY idx_codes_status (product_id, status),
        KEY idx_codes_order (order_id),
        CONSTRAINT fk_codes_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
        CONSTRAINT fk_codes_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        author_name VARCHAR(120) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
        title VARCHAR(150) DEFAULT NULL,
        body TEXT,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_reviews_product (product_id, status),
        KEY idx_reviews_user (user_id),
        CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
        CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS wishlist (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wishlist (user_id, product_id),
        KEY idx_wishlist_product (product_id),
        CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(80) NOT NULL,
        setting_value TEXT,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) $E";

    // --- Telegram admin control panel ----------------------------------
    // Audit trail for every action an admin performs from a Telegram button.
    $sql[] = "CREATE TABLE IF NOT EXISTS telegram_admin_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED DEFAULT NULL,
        telegram_admin_id VARCHAR(32) NOT NULL,
        telegram_name VARCHAR(190) DEFAULT NULL,
        action VARCHAR(60) NOT NULL,
        previous_status VARCHAR(40) DEFAULT NULL,
        new_status VARCHAR(40) DEFAULT NULL,
        result ENUM('success','denied','failed','noop') NOT NULL DEFAULT 'success',
        reason VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_tg_order (order_id),
        KEY idx_tg_admin (telegram_admin_id),
        KEY idx_tg_created (created_at)
    ) $E";

    // Telegram redelivers updates until acknowledged; update_id is UNIQUE so a
    // repeated delivery can never run the same action twice.
    $sql[] = "CREATE TABLE IF NOT EXISTS telegram_updates (
        update_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(30) NOT NULL DEFAULT 'update',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (update_id),
        KEY idx_tgu_created (created_at)
    ) $E";

    // --- WhatsApp Cloud API webhook ------------------------------------
    // Inbound customer messages. message_id is UNIQUE so Meta's retries of the
    // same event can never create a duplicate row.
    $sql[] = "CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id VARCHAR(190) NOT NULL,
        phone_number VARCHAR(32) NOT NULL,
        contact_name VARCHAR(190) DEFAULT NULL,
        message_type VARCHAR(40) NOT NULL DEFAULT 'unknown',
        message_text MEDIUMTEXT DEFAULT NULL,
        direction ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
        status VARCHAR(30) NOT NULL DEFAULT 'received',
        wa_timestamp DATETIME DEFAULT NULL,
        raw_payload MEDIUMTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wa_message (message_id),
        KEY idx_wa_phone (phone_number),
        KEY idx_wa_created (created_at),
        KEY idx_wa_status (status)
    ) $E";

    // Delivery receipts. One row per (message, status) so repeats are ignored.
    $sql[] = "CREATE TABLE IF NOT EXISTS whatsapp_message_statuses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id VARCHAR(190) NOT NULL,
        status VARCHAR(30) NOT NULL,
        recipient_id VARCHAR(32) DEFAULT NULL,
        error_code VARCHAR(30) DEFAULT NULL,
        error_title VARCHAR(255) DEFAULT NULL,
        wa_timestamp DATETIME DEFAULT NULL,
        raw_payload MEDIUMTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wa_status (message_id, status),
        KEY idx_was_recipient (recipient_id),
        KEY idx_was_created (created_at)
    ) $E";

    // Homepage promotional content: carousel slides and the three feature cards.
    // Everything the homepage shows is driven from here, never hard-coded.
    $sql[] = "CREATE TABLE IF NOT EXISTS promo_slides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        placement ENUM('slider','card') NOT NULL DEFAULT 'slider',
        slot VARCHAR(40) DEFAULT NULL,
        tag VARCHAR(60) DEFAULT NULL,
        title VARCHAR(160) NOT NULL,
        subtitle VARCHAR(255) DEFAULT NULL,
        button_text VARCHAR(60) DEFAULT NULL,
        button_url VARCHAR(255) DEFAULT NULL,
        image VARCHAR(190) DEFAULT NULL,
        overlay TINYINT UNSIGNED NOT NULL DEFAULT 70,
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_promo_place (placement, status, sort_order),
        UNIQUE KEY uq_promo_slot (slot)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel ENUM('telegram','whatsapp','messenger','email') NOT NULL,
        event VARCHAR(60) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_notif_channel_event (channel, event)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS notification_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED DEFAULT NULL,
        channel VARCHAR(30) NOT NULL,
        event VARCHAR(60) NOT NULL,
        idempotency_key VARCHAR(100) DEFAULT NULL,
        recipient VARCHAR(190) DEFAULT NULL,
        status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_nlogs_order (order_id),
        KEY idx_nlogs_channel (channel, status),
        KEY idx_nlogs_created (created_at),
        UNIQUE KEY uq_notification_idempotency (channel, event, idempotency_key)
    ) $E";

    $sql[] = "CREATE TABLE IF NOT EXISTS app_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        level VARCHAR(20) NOT NULL DEFAULT 'error',
        context VARCHAR(60) NOT NULL DEFAULT 'app',
        message VARCHAR(1000) NOT NULL,
        file_ref VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_applogs_created (created_at)
    ) $E";

    return array_merge($sql, sh_courier_schema_sql(), sh_otp_schema_sql());
}

/**
 * Courier & parcel tables. Kept in a dedicated function so both the installer
 * and the lazy schema-ensure routine can apply exactly the same DDL.
 */
function sh_courier_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS couriers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(50) NOT NULL,
            driver VARCHAR(50) NOT NULL DEFAULT 'manual',
            logo VARCHAR(190) DEFAULT NULL,
            tracking_url VARCHAR(255) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            credentials TEXT,
            status TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_couriers_code (code)
        ) $E",

        "CREATE TABLE IF NOT EXISTS shipments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_number VARCHAR(30) NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            courier_id INT UNSIGNED DEFAULT NULL,
            tracking_number VARCHAR(120) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            recipient_name VARCHAR(120) NOT NULL,
            recipient_phone VARCHAR(30) NOT NULL,
            recipient_address VARCHAR(255) DEFAULT NULL,
            package_weight DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            package_type VARCHAR(60) NOT NULL DEFAULT 'Parcel',
            cod_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            note VARCHAR(500) DEFAULT NULL,
            courier_payload TEXT,
            created_by INT UNSIGNED DEFAULT NULL,
            booked_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_shipments_number (shipment_number),
            KEY idx_shipments_order (order_id),
            KEY idx_shipments_courier (courier_id),
            KEY idx_shipments_status (status),
            KEY idx_shipments_created (created_at),
            CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
            CONSTRAINT fk_shipments_courier FOREIGN KEY (courier_id) REFERENCES couriers (id) ON DELETE SET NULL,
            CONSTRAINT fk_shipments_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",

        "CREATE TABLE IF NOT EXISTS shipment_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id INT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            admin_id INT UNSIGNED DEFAULT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'admin',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_shipment_events_shipment (shipment_id),
            KEY idx_shipment_events_created (created_at),
            CONSTRAINT fk_shipment_events_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE CASCADE,
            CONSTRAINT fk_shipment_events_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",
    ];
}

/**
 * Default courier registry. Every entry is added disabled so nothing is
 * "active" until the merchant explicitly enables it and supplies credentials.
 */
function sh_courier_seed(PDO $pdo): void
{
    $rows = [
        ['Steadfast',              'steadfast', 'steadfast', 'On-demand nationwide courier. Merchant API uses Api-Key + Secret-Key headers.'],
        ['Pathao Courier',         'pathao',    'pathao',    'Pathao merchant delivery. API integration registered — credentials pending.'],
        ['RedX',                   'redx',      'redx',      'RedX parcel delivery. API integration registered — credentials pending.'],
        ['eCourier',               'ecourier',  'ecourier',  'eCourier on-demand delivery. API integration registered — credentials pending.'],
        ['Paperfly',               'paperfly',  'paperfly',  'Paperfly nationwide delivery. API integration registered — credentials pending.'],
        ['Sundarban Courier',      'sundarban', 'sundarban', 'Sundarban Courier Service. API integration registered — credentials pending.'],
        ['SA Paribahan',           'saparibahan', 'custom',  'Traditional courier. Manual tracking numbers.'],
    ];
    $st = $pdo->prepare('INSERT IGNORE INTO couriers
        (name, code, driver, description, status, sort_order)
        VALUES (?,?,?,?,0,?)');
    $i = 1;
    foreach ($rows as [$name, $code, $driver, $desc]) {
        $st->execute([$name, $code, $driver, $desc, $i++]);
    }
}

// ---------------------------------------------------------------------------
// Phone OTP verification & security tables
// ---------------------------------------------------------------------------

/** Bumped whenever the OTP schema shape changes; drives the lazy migration. */
const SH_OTP_SCHEMA_VERSION = 4;

/**
 * OTP + security tables. Shared by the installer and the lazy migration so
 * both apply exactly the same DDL.
 */
function sh_otp_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS otp_verifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            phone VARCHAR(30) NOT NULL,
            purpose VARCHAR(30) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            provider VARCHAR(30) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 5,
            resend_count INT NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_otp_phone_purpose (phone, purpose),
            KEY idx_otp_user (user_id),
            KEY idx_otp_created (created_at),
            CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) $E",

        "CREATE TABLE IF NOT EXISTS security_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            event VARCHAR(60) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            metadata TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_seclog_event (event),
            KEY idx_seclog_user (user_id),
            KEY idx_seclog_created (created_at)
        ) $E",

        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pwreset_user (user_id),
            KEY idx_pwreset_token (token_hash),
            CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) $E",
    ];
}

/**
 * Column additions for existing installations (CREATE TABLE IF NOT EXISTS never
 * alters a table that is already present, so existing installs are migrated
 * here — information_schema is checked instead of relying on a MySQL-version
 * specific "ADD COLUMN IF NOT EXISTS").
 */
function sh_otp_schema_columns(): array
{
    return [
        'users' => [
            'phone_verified'            => "TINYINT(1) NOT NULL DEFAULT 0",
            'phone_verified_at'         => "DATETIME DEFAULT NULL",
            'phone_verification_method' => "VARCHAR(30) DEFAULT NULL",
            // Google OAuth login (v4)
            'auth_provider'             => "VARCHAR(20) NOT NULL DEFAULT 'email'",
            'google_id'                 => "VARCHAR(64) DEFAULT NULL",
            'avatar'                    => "VARCHAR(500) DEFAULT NULL",
            'avatar_source'             => "VARCHAR(20) DEFAULT NULL",
        ],
        'orders' => [
            'phone_verified_at'     => "DATETIME DEFAULT NULL",
            'verification_required' => "TINYINT(1) NOT NULL DEFAULT 0",
            'verification_method'   => "VARCHAR(30) DEFAULT NULL",
        ],
        'otp_verifications' => [
            'provider' => "VARCHAR(30) DEFAULT NULL",
        ],
    ];
}

/** Default OTP configuration. Provider defaults to offline (test) mode. */
function sh_otp_settings_defaults(): array
{
    return [
        // Single customer authentication mode: exactly one of these is active.
        'authentication_mode'  => 'email_password',
        // Provider configuration (offline + textbee + firebase + generic HTTP)
        'otp_provider'         => 'offline',
        // Custom HTTP SMS API
        'otp_api_url'          => '',
        'otp_api_method'       => 'post_json',
        'otp_api_body'         => '{"phone":"{phone}","message":"{message}","sender":"{sender}","api_key":"{api_key}","api_secret":"{api_secret}"}',
        'otp_api_headers'      => '{"Content-Type":"application/json"}',
        'otp_api_key'          => '',
        'otp_api_secret'       => '',
        'otp_api_auth'         => '',
        'otp_api_token'        => '',
        'otp_api_username'     => '',
        'otp_api_password'     => '',
        'otp_phone_param'      => 'phone',
        'otp_message_param'    => 'message',
        'otp_success_field'    => '',
        'otp_success_value'    => '',
        // TextBee
        'otp_textbee_api_key'  => '',
        'otp_textbee_device_id' => '',
        // Firebase (optional)
        'otp_firebase_api_key' => '',
        'otp_firebase_sender_id' => '',
        // Shared
        'otp_sender_id'        => 'ShopHaat',
        'otp_message'          => 'Your ShopHaat verification code is {code}. It expires in {minutes} minutes. Do not share it with anyone.',
        // OTP behaviour
        'otp_length'           => '6',
        'otp_expiry_minutes'   => '5',
        'otp_max_attempts'     => '5',
        'otp_max_resends'      => '3',
        'otp_resend_cooldown'  => '60',
        // Rate limiting
        'otp_daily_limit'      => '20',
        'otp_phone_rate_limit' => '5',
        'otp_phone_rate_window' => '60',
        'otp_ip_rate_limit'    => '10',
        'otp_ip_rate_window'   => '60',
        // Google OAuth 2.0 login (additional option, independent of the mode above)
        'google_login_enabled' => '0',
        'google_client_id'     => '',
        'google_client_secret' => '',
    ];
}

/** Insert defaults without overwriting any value the admin has already saved. */
function sh_otp_seed_settings(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (sh_otp_settings_defaults() as $k => $v) { $st->execute([$k, (string)$v]); }
}

/**
 * Lazy, self-healing migration used on existing installs. Runs once per version
 * (stamped in settings), then becomes a no-op on every request.
 */
function sh_otp_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $ver = (int)sh_val("SELECT setting_value FROM settings WHERE setting_key = 'otp_schema_version' LIMIT 1", [], 0);
        if ($ver >= SH_OTP_SCHEMA_VERSION) { return; }

        $pdo = sh_db();
        foreach (sh_otp_schema_sql() as $ddl) { $pdo->exec($ddl); }

        // Add any missing columns to existing tables.
        foreach (sh_otp_schema_columns() as $table => $defs) {
            $existing = [];
            $st = $pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) { $existing[] = $c; }
            foreach ($defs as $col => $ddl) {
                if (!in_array($col, $existing, true)) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                }
            }
        }

        sh_otp_settings_migrate($pdo);
        sh_google_schema_migrate($pdo);
        sh_otp_seed_settings($pdo);
        sh_setting_save('otp_schema_version', (string)SH_OTP_SCHEMA_VERSION);
    } catch (Throwable $e) {
        sh_log_exception($e, 'otp-schema');
    }
}

/**
 * Version 3 migration for the configurable two-mode auth system:
 *  - introduce the single `authentication_mode` switch (email_password default),
 *  - canonicalise every stored phone number (017… / +88017… / 88017… -> 880…),
 *  - drop the legacy gate switches and order/checkout OTP switches,
 *  - backfill a synthetic email for any phone-only account missing one.
 * Existing customers, orders and other data are never deleted.
 */
function sh_otp_settings_migrate(PDO $pdo): void
{
    // 1. Normalise existing phones in place so 017/+880/880 all resolve to one form.
    try {
        foreach ($pdo->query('SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone <> \'\'') as $row) {
            $canon = sh_phone_normalize((string)$row['phone']);
            if ($canon === '' || $canon === (string)$row['phone']) { continue; }
            $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$canon, (int)$row['id']]);
        }
    } catch (Throwable $e) { sh_log_exception($e, 'otp-phone-normalise'); }

    // 2. Ensure the single authentication mode setting exists (email_password default).
    if (sh_setting('authentication_mode', null) === null) {
        sh_setting_save('authentication_mode', 'email_password');
    }

    // 3. Remove superseded switches — no dual ON/OFF toggles, no order OTP.
    $obsolete = ['otp_enabled', 'otp_require_signup', 'otp_require_login', 'otp_session_hours',
                 'otp_before_register', 'otp_before_login', 'otp_before_checkout',
                 'otp_before_order', 'otp_before_cod', 'otp_before_online', 'otp_every_order'];
    foreach ($obsolete as $k) {
        try { $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$k]); }
        catch (Throwable $e) { sh_log_exception($e, 'otp-settings-cleanup'); }
    }

    // 4. Backfill a synthetic email for accounts that somehow lost theirs.
    try {
        $st = $pdo->prepare('SELECT id, phone FROM users WHERE (email IS NULL OR email = \'\') AND phone IS NOT NULL AND phone <> \'\'');
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')
                ->execute([sh_synthetic_email((string)$row['phone']), (int)$row['id']]);
        }
    } catch (Throwable $e) { sh_log_exception($e, 'otp-email-backfill'); }
}

/**
 * Version 4: Google OAuth login. Adds the unique index on users.google_id
 * (columns themselves are added by sh_otp_schema_columns()). Never touches data.
 */
function sh_google_schema_migrate(PDO $pdo): void
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute(['users', 'uq_users_google_id']);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_google_id` (`google_id`)');
        }
    } catch (Throwable $e) { sh_log_exception($e, 'google-schema'); }
}

/** Default rows inserted once at install time. */
function sh_schema_seed(PDO $pdo, array $opts): void
{
    $siteName = $opts['site_name'] ?? 'ShopHaat';
    $siteUrl  = $opts['site_url'] ?? '';
    $logo     = $opts['logo'] ?? '';
    $email    = $opts['admin_email'] ?? '';

    $settings = [
        'site_name'            => $siteName,
        'site_tagline'         => 'Everyday essentials, electronics and digital products',
        'site_description'     => $siteName . ' is an online marketplace for electronics, home essentials, fashion and instantly delivered digital products.',
        'site_url'             => $siteUrl,
        'site_logo'            => $logo,
        'site_favicon'         => '',
        'currency_symbol'      => 'BDT ',
        'currency_code'        => 'BDT',
        'contact_email'        => $email,
        'contact_phone'        => '+880 1700-000000',
        'contact_address'      => 'Level 4, Bijoy Sarani, Dhaka 1215, Bangladesh',
        'footer_about'         => 'We deliver genuine products across Bangladesh with fast shipping, easy returns and secure payment options.',
        'footer_copyright'     => '© ' . date('Y') . ' ' . $siteName . '. All rights reserved.',
        'delivery_fee_inside'  => '60',
        'delivery_fee_outside' => '120',
        'free_delivery_over'   => '3000',
        'maintenance_mode'     => '0',
        'products_per_page'    => '24',
        'order_number_prefix'  => 'SH',
        // Integration toggles (stored here, but edited in their own admin sections)
        'telegram_enabled'     => '0',
        'telegram_bot_token'   => '',
        'telegram_chat_id'     => '',
        'whatsapp_enabled'     => '0',
        'whatsapp_provider'    => 'cloud_api',
        'whatsapp_phone_id'    => '',
        'whatsapp_token'       => '',
        'whatsapp_recipient'   => '',
        'messenger_enabled'    => '0',
        'messenger_page_id'    => '',
        'messenger_token'      => '',
        'messenger_recipient'  => '',
        'smtp_enabled'         => '0',
        'smtp_host'            => '',
        'smtp_port'            => '587',
        'smtp_encryption'      => 'tls',
        'smtp_username'        => '',
        'smtp_password'        => '',
        'smtp_from_email'      => $email,
        'smtp_from_name'       => $siteName,
        'admin_notify_email'   => $email,
    ];
    $st = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settings as $k => $v) { $st->execute([$k, (string)$v]); }

    // Payment methods
    $pm = $pdo->prepare('INSERT IGNORE INTO payment_methods
        (code, name, type, description, instructions, account_number, account_type, sort_order, status)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $pm->execute(['bkash', 'bKash', 'manual', 'Send money from your bKash app or dial *247#.',
        "1. Open the bKash app and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Complete the transaction and copy the Transaction ID (TrxID).\n5. Submit the TrxID and your bKash number below.",
        '01700000000', 'Personal', 1, 1]);
    $pm->execute(['nagad', 'Nagad', 'manual', 'Send money using the Nagad app or dial *167#.',
        "1. Open Nagad and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Copy the Transaction ID from the confirmation message.\n5. Submit the Transaction ID and your Nagad number below.",
        '01800000000', 'Personal', 2, 1]);
    $pm->execute(['rocket', 'Rocket', 'manual', 'Send money using Rocket (DBBL) or dial *322#.',
        "1. Open Rocket and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Copy the Transaction ID (TxnID).\n5. Submit the Transaction ID and your Rocket number below.",
        '018000000001', 'Personal', 3, 1]);
    $pm->execute(['cod', 'Cash on Delivery', 'cod', 'Pay in cash when your order arrives.',
        "Keep the exact amount ready. Our delivery partner will collect the payment at your door. Cash on Delivery is available for physical products only.",
        null, null, 4, 1]);

    // Gateway definitions (disabled until real credentials are supplied)
    $gw = $pdo->prepare('INSERT IGNORE INTO payment_gateways (code, name, driver, description, mode, status, sort_order)
                         VALUES (?,?,?,?,?,?,?)');
    $gw->execute(['sslcommerz', 'SSLCommerz', 'sslcommerz', 'Cards, mobile banking and net banking via SSLCommerz.', 'sandbox', 0, 1]);
    $gw->execute(['aamarpay', 'aamarPay', 'aamarpay', 'Cards and mobile financial services via aamarPay.', 'sandbox', 0, 2]);
    $gw->execute(['shurjopay', 'shurjoPay', 'shurjopay', 'Payment aggregation via shurjoPay.', 'sandbox', 0, 3]);

    // Homepage promo content (slider slides + the three feature cards).
    $ps = $pdo->prepare('INSERT IGNORE INTO promo_slides
        (placement, slot, tag, title, subtitle, button_text, button_url, image, overlay, sort_order, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)');
    $ps->execute(['slider', null, 'Flash Sale Live', 'Up to 40% off electronics and home essentials',
        'Limited stock on daily deals. New offers added every morning at 10:00 AM.',
        'Shop Flash Sale', 'products.php?flash=1', 'promo/slide-flash.jpg', 70, 1]);
    $ps->execute(['slider', null, 'Instant Delivery', 'Digital codes delivered the moment payment clears',
        'Gift cards, game top-ups and subscriptions - no waiting for a courier.',
        'Browse Digital', 'products.php?q=gift+card', 'promo/slide-digital.jpg', 70, 2]);
    $ps->execute(['slider', null, 'Everyday Value', 'Groceries and daily essentials at honest prices',
        'Free delivery on orders over the qualifying amount inside the city.',
        'Start Shopping', 'products.php?sort=popular', 'promo/slide-grocery.jpg', 70, 3]);
    $ps->execute(['card', 'card_1', null, 'New Arrivals', 'Fresh stock added this week',
        'Explore', 'products.php?sort=newest', 'promo/card-new.jpg', 70, 1]);
    $ps->execute(['card', 'card_2', null, 'Clearance Offers', '20% off or more',
        'View deals', 'products.php?discount=20', 'promo/card-clearance.jpg', 70, 2]);
    $ps->execute(['card', 'card_3', null, 'Track Your Order', 'Live status in seconds',
        'Track now', 'track.php', 'promo/card-track.jpg', 70, 3]);

    // Notification event matrix
    $events = ['order_created', 'payment_submitted', 'payment_approved', 'payment_rejected',
               'order_processing', 'order_completed', 'order_cancelled', 'code_delivered', 'low_stock'];
    // Sensible defaults: every event is switched ON for all channels so a freshly
    // configured integration works immediately. A channel that has no credentials is
    // still skipped safely, so enabling these by default cannot break anything.
    $ns = $pdo->prepare('INSERT IGNORE INTO notifications (channel, event, enabled) VALUES (?,?,1)');
    foreach (['telegram', 'whatsapp', 'messenger', 'email'] as $ch) {
        foreach ($events as $ev) { $ns->execute([$ch, $ev]); }
    }

    // Courier registry (all entries disabled by default).
    sh_courier_seed($pdo);

    // Phone verification settings (defaults; provider is offline/test until configured).
    sh_otp_seed_settings($pdo);
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['otp_schema_version', (string)SH_OTP_SCHEMA_VERSION]);
}
