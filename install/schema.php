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
        'email_challenges', 'email_log',
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
        -- Email verification. Owned by the email system (includes/mail.php);
        -- the Firebase sync and the admin customer list read the same columns.
        email_verified TINYINT(1) NOT NULL DEFAULT 0,
        email_verified_at DATETIME DEFAULT NULL,
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
        image_orig VARCHAR(190) DEFAULT NULL,
        image_crop TEXT DEFAULT NULL,
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

    return array_merge($sql, sh_courier_schema_sql(), sh_otp_schema_sql(), sh_email_schema_sql());
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
 * Record whether this store was given the built-in courier accounts.
 *
 * The registry is sample content. Writing the decision means no later request
 * can decide to fill an empty list: only an install that chose Demo Data = ON
 * gets the entries, and a store that predates this marker gets them once during
 * its upgrade.
 */
function sh_courier_state_save(PDO $pdo, string $state): void
{
    $ok = $state === 'installed' ? 'installed' : 'none';
    $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $st->execute(['courier_registry_state']);
    if ($st->fetchColumn() === false) {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['courier_registry_state', $ok]);
    }
}

/**
 * Default courier registry. Every entry is added disabled so nothing is
 * "active" until the merchant explicitly enables it and supplies credentials.
 *
 * Called only for an install that chose Demo Data = ON (see
 * sh_install_seed_all()) and once for a pre-registry upgrade.
 */
function sh_courier_seed(PDO $pdo): void
{
    // Courier accounts are business records, so an empty store must not claim
    // to have accounts with any courier. Every row starts disabled until the
    // owner supplies real credentials.
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

/**
 * Email tables. Shared by the installer, the lazy migration on existing stores
 * and the tests, so all three apply exactly the same DDL.
 */
function sh_email_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        // One row per emailed verification challenge (signup verification,
        // password reset, sensitive-change confirmation). Only a bcrypt hash of
        // the code is stored: the plaintext exists in the email and nowhere
        // else, so a database dump cannot be used to verify somebody else's
        // address or reset their password. Purpose is part of every lookup, so a
        // signup code can never be replayed against the reset flow.
        "CREATE TABLE IF NOT EXISTS email_challenges (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            email VARCHAR(190) NOT NULL,
            purpose VARCHAR(40) NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            status ENUM('pending','used','expired','revoked','locked') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 5,
            resend_count INT NOT NULL DEFAULT 0,
            max_resends INT NOT NULL DEFAULT 5,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            last_sent_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email_chal_lookup (email, purpose, status, id),
            KEY idx_email_chal_user (user_id),
            KEY idx_email_chal_expiry (expires_at),
            CONSTRAINT fk_email_chal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) $E",

        // Delivery log: what was attempted, over which transport, and what the
        // provider/SMTP server answered. Never holds a message body, a code or a
        // credential. "accepted" only means the server took the message; the
        // delivered/bounced states are written by the provider webhook.
        "CREATE TABLE IF NOT EXISTS email_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            recipient VARCHAR(190) NOT NULL,
            recipient_domain VARCHAR(120) DEFAULT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'generic',
            subject VARCHAR(180) DEFAULT NULL,
            transport VARCHAR(20) NOT NULL DEFAULT 'smtp',
            status ENUM('accepted','failed','skipped','delivered','bounced','complaint','blocked','unsubscribed') NOT NULL DEFAULT 'accepted',
            category VARCHAR(30) NOT NULL DEFAULT 'accepted',
            error_text VARCHAR(500) DEFAULT NULL,
            message_id VARCHAR(190) DEFAULT NULL,
            provider_event_id VARCHAR(120) DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email_log_created (created_at),
            KEY idx_email_log_status (status),
            KEY idx_email_log_msgid (message_id),
            KEY idx_email_log_purpose (purpose),
            KEY idx_email_log_user (user_id),
            CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) $E",
    ];
}


// ---------------------------------------------------------------------------
// Phone OTP verification & security tables
// ---------------------------------------------------------------------------

/** Bumped whenever the OTP schema shape changes; drives the lazy migration. */
const SH_OTP_SCHEMA_VERSION = 4;

/**
 * Email verification schema version. Bumped when the mail tables/defaults
 * change so existing stores pick the change up without a reinstall.
 */
const SH_EMAIL_SCHEMA_VERSION = 1;

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
 * Defaults for the email system. Seeded once per store; an existing value is
 * never overwritten, so an operator's choice survives every upgrade.
 *
 * @return array<string,string>
 */
function sh_email_settings_defaults(): array
{
    return [
        // Signup email verification through SMTP OTP.
        'email_verify_enabled'          => '1',
        // Gate: both keys pre-exist (email_verification_required belongs to the
        // checkout gate that was already shipped). Default 0 = nobody is locked out.
        'email_verify_required'         => '0',
        'email_verify_required_login'   => '0',
        // Forgot-password by emailed code, and the change-notification mails.
        'email_forgot_enabled'          => '1',
        'email_notify_password_change'  => '1',
        'email_otp_on_password_change'  => '0',
        // Challenge behaviour.
        'email_otp_expiry_minutes'      => '10',
        'email_otp_max_attempts'        => '5',
        'email_otp_max_resends'         => '5',
        'email_otp_resend_cooldown'     => '60',
        'email_otp_email_limit'         => '3',
        'email_otp_ip_limit'            => '10',
        // Delivery transport. SMTP is the default; the Brevo HTTPS API is an
        // explicit opt-in and never selected automatically.
        'mail_transport'                => 'smtp',
        'brevo_api_enabled'             => '0',
        'brevo_api_key'                 => '',
        // Provider feedback and signing. smtp_allow_self_signed is retired and
        // kept at 0: outgoing TLS verification is always enforced.
        'email_webhook_secret'          => '',
        'smtp_allow_self_signed'        => '0',
        'dkim_enabled'                  => '0',
        'dkim_domain'                   => '',
        'dkim_selector'                 => '',
        'dkim_private_key_path'         => '',
        // Set to 1 by the migration after pre-existing accounts were grandfathered.
        'email_verify_grandfathered'    => '0',
    ];
}

function sh_email_seed_settings(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (sh_email_settings_defaults() as $k => $v) { $st->execute([$k, (string)$v]); }
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

/**
 * The four payment routes every store needs to take an order: three manual
 * mobile-banking wallets and cash on delivery, plus the three disabled gateway
 * definitions the admin panel edits.
 *
 * These are structural configuration, not demo content: they carry no merchant
 * numbers (the account number is left blank until the owner enters a real one,
 * and sh_payment_method_available_for_order() keeps a blank method out of
 * checkout), no credentials and no fake business records. Seeding them with the
 * starter catalogue instead would leave a demo-off install unable to record a
 * single order, and would leave the reporting integration fixtures with nothing
 * to test against.
 *
 * Idempotent: INSERT IGNORE keyed on the unique method/gateway code.
 */
function sh_seed_essential_starters(PDO $pdo): void
{
    $pm = $pdo->prepare('INSERT IGNORE INTO payment_methods
        (code, name, type, description, instructions, account_number, account_type, sort_order, status)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $pm->execute(['bkash', 'bKash', 'manual', 'Send money from your bKash app or dial *247#.',
        "1. Open the bKash app and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Complete the transaction and copy the Transaction ID (TrxID).\n5. Submit the TrxID and your bKash number below.",
        '', 'Personal', 1, 1]); // Blank until the owner enters the real merchant number
    $pm->execute(['nagad', 'Nagad', 'manual', 'Send money using the Nagad app or dial *167#.',
        "1. Open Nagad and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Copy the Transaction ID from the confirmation message.\n5. Submit the Transaction ID and your Nagad number below.",
        '', 'Personal', 2, 1]);
    $pm->execute(['rocket', 'Rocket', 'manual', 'Send money using Rocket (DBBL) or dial *322#.',
        "1. Open Rocket and choose Send Money.\n2. Enter the merchant number shown above.\n3. Enter the exact amount.\n4. Copy the Transaction ID (TxnID).\n5. Submit the Transaction ID and your Rocket number below.",
        '', 'Personal', 3, 1]);
    $pm->execute(['cod', 'Cash on Delivery', 'cod', 'Pay in cash when your order arrives.',
        "Keep the exact amount ready. Our delivery partner will collect the payment at your door. Cash on Delivery is available for physical products only.",
        null, null, 4, 1]);

    // Gateway definitions (disabled until real credentials are supplied)
    $gw = $pdo->prepare('INSERT IGNORE INTO payment_gateways (code, name, driver, description, mode, status, sort_order)
                         VALUES (?,?,?,?,?,?,?)');
    $gw->execute(['sslcommerz', 'SSLCommerz', 'sslcommerz', 'Cards, mobile banking and net banking via SSLCommerz.', 'sandbox', 0, 1]);
    $gw->execute(['aamarpay', 'aamarPay', 'aamarpay', 'Cards and mobile financial services via aamarPay.', 'sandbox', 0, 2]);
    $gw->execute(['shurjopay', 'shurjoPay', 'shurjopay', 'Payment aggregation via shurjoPay.', 'sandbox', 0, 3]);
}

/**
 * One install run's complete seeding decision.
 *
 * Kept in one function so the installer and the test suite exercise exactly the
 * same path: every install gets the structure-level starters, and the starter
 * catalogue runs only when the operator asked for it. When they did not, the
 * refusal is recorded in the settings table so a later automatic run (a page
 * view, a login, an upgrade) can never reconsider — only an explicit new install
 * request can, and that always comes through $withDemo = true.
 *
 * @return array<string,mixed> short report for the install log
 */
function sh_install_seed_all(PDO $pdo, array $opts, bool $withDemo): array
{
    // One transaction for the whole decision: if any part of the seed fails the
    // store is left without a half-written catalogue, and the installer can be
    // run again. DDL has already run (and auto-committed) before this point.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        sh_schema_seed($pdo, $opts);
        sh_seed_essential_starters($pdo);

        if ($withDemo) {
            // Courier accounts are registered business relationships, not
            // structure: they are part of the sample dataset, so a demo-OFF
            // install starts with an empty courier list for the owner to fill in.
            sh_courier_seed($pdo);
            sh_courier_state_save($pdo, 'installed');
            $report = sh_install_demo_data($pdo, true);
            if ($ownTransaction) { $pdo->commit(); }
            return ['demo' => true, 'report' => $report];
        }

        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $st->execute(['demo_data_state']);
        if ($st->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
                ->execute(['demo_data_state', 'none']);
        }
        sh_courier_state_save($pdo, 'none');
        if ($ownTransaction) { $pdo->commit(); }
        return ['demo' => false, 'report' => []];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Create the store: the first administrator account, plus the seeding decision.
 *
 * This is the only place a fresh install writes an administrator, and it is a
 * function so the installer and the MySQL test suite exercise the very same
 * code. Inside one transaction it:
 *
 *   - refuses to run when the database already holds an administrator, so a
 *     repeated install can never reset somebody's password;
 *   - hashes the password exactly as submitted (never trimmed, never compared
 *     against a sample) with password_hash();
 *   - runs sh_install_seed_all() with the validated Demo Data choice.
 *
 * With $ownerRecovery (set only after the installer accepted the owner unlock
 * file) an existing account is not duplicated: the one row whose address was
 * submitted gets the new password and is activated. Nothing else is changed and
 * no sample content is added, so the authorised recovery path cannot reshape a
 * live store.
 *
 * @param array{site_name?:string,admin_email?:string,admin_password?:string,demo_data?:string} $form
 * @return array<string,mixed> the seeding report
 * @throws RuntimeException when an administrator exists and recovery was not authorised
 */
function sh_install_create_store(PDO $pdo, array $form, string $logo = '', string $siteUrl = '', bool $ownerRecovery = false): array
{
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        $existing = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($existing > 0) {
            if (!$ownerRecovery) {
                throw new RuntimeException(
                    'This database already contains an administrator account, so no new one was created '
                    . 'and its password was left unchanged. Sign in with the existing account. If you have '
                    . 'lost that password, unlock the installer as the store owner and run the full setup '
                    . 'again to reset that one account.'
                );
            }
            // Authorised recovery: exactly one row, matched by the submitted
            // address. No other administrator is read or written.
            $find = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
            $find->execute([(string)($form['admin_email'] ?? '')]);
            $id = (int)$find->fetchColumn();
            if ($id === 0) {
                throw new RuntimeException(
                    'No administrator account uses that email address, so no password was changed. '
                    . 'Enter the address of an existing administrator.'
                );
            }
            $upd = $pdo->prepare("UPDATE admins SET password_hash = ?, status = 'active' WHERE id = ?");
            $upd->execute([password_hash((string)($form['admin_password'] ?? ''), PASSWORD_DEFAULT), $id]);
            if ($ownTransaction) { $pdo->commit(); }
            return ['demo' => false, 'report' => [], 'recovered' => true];
        }
        $adm = $pdo->prepare('INSERT INTO admins (name, email, password_hash, role, status) VALUES (?,?,?,?,\'active\')');
        $adm->execute([
            'Store Administrator',
            (string)($form['admin_email'] ?? ''),
            password_hash((string)($form['admin_password'] ?? ''), PASSWORD_DEFAULT),
            'superadmin',
        ]);

        $report = sh_install_seed_all($pdo, [
            'site_name'   => (string)($form['site_name'] ?? ''),
            'site_url'    => $siteUrl,
            'logo'        => $logo,
            'admin_email' => (string)($form['admin_email'] ?? ''),
        ], ($form['demo_data'] ?? '0') === '1');

        if ($ownTransaction) { $pdo->commit(); }
        return $report;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * Structure-level starters only: the settings a store needs to render at all,
 * the notification matrix, the payment routes and the OTP/email defaults.
 *
 * This runs on every install, including installs where the operator declined the
 * starter catalogue, so it must never insert business records — no products,
 * categories, brands, customers, orders, reviews, coupons, slides, promotions,
 * courier accounts or placeholder company details. Those live in
 * install/demo-data.php and only run when the operator asks for them.
 */
function sh_schema_seed(PDO $pdo, array $opts): void
{
    $siteName = $opts['site_name'] ?? 'ShopHaat';
    $siteUrl  = $opts['site_url'] ?? '';
    $logo     = $opts['logo'] ?? '';
    $email    = $opts['admin_email'] ?? '';

    $settings = [
        'site_name'            => $siteName,
        'site_tagline'         => 'Everyday essentials, electronics and home products',
        'site_description'     => $siteName . ' is an online marketplace for electronics, home essentials, fashion, groceries and beauty products.',
        'site_url'             => $siteUrl,
        'site_logo'            => $logo,
        'site_favicon'         => '',
        'currency_symbol'      => 'BDT ',
        'currency_code'        => 'BDT',
        'contact_email'        => $email,
        // Deliberately blank: an unconfigured store must not publish a made-up
        // phone number or office address as if it were the real business.
        'contact_phone'        => '',
        'contact_address'      => '',
        'footer_about'         => '',
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
        'mail_transport'       => 'smtp',
        'smtp_enabled'         => '0',
        'smtp_host'            => '',
        'smtp_port'            => '587',
        'smtp_encryption'      => 'tls',
        'smtp_username'        => '',
        'smtp_password'        => '',
        'smtp_from_email'      => $email,
        'smtp_from_name'       => $siteName,
        'brevo_api_enabled'    => '0',
        'brevo_api_key'        => '',
        'admin_notify_email'   => $email,
    ];
    $st = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settings as $k => $v) { $st->execute([$k, (string)$v]); }

    // Notification event matrix
    $events = ['order_created', 'payment_submitted', 'payment_approved', 'payment_rejected',
               'order_processing', 'order_completed', 'order_cancelled', 'low_stock'];
    // Sensible defaults: every event is switched ON for all channels so a freshly
    // configured integration works immediately. A channel that has no credentials is
    // still skipped safely, so enabling these by default cannot break anything.
    $ns = $pdo->prepare('INSERT IGNORE INTO notifications (channel, event, enabled) VALUES (?,?,1)');
    foreach (['telegram', 'whatsapp', 'messenger', 'email'] as $ch) {
        foreach ($events as $ev) { $ns->execute([$ch, $ev]); }
    }

    // Phone verification settings (defaults; provider is offline/test until configured).
    sh_otp_seed_settings($pdo);

    // Email verification defaults. The mail tables themselves come from
    // sh_schema_sql(); these keys only carry the switches and the challenge
    // policy, so nothing here creates business content.
    sh_email_seed_settings($pdo);
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['email_schema_version', (string)SH_EMAIL_SCHEMA_VERSION]);
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute(['otp_schema_version', (string)SH_OTP_SCHEMA_VERSION]);
}
