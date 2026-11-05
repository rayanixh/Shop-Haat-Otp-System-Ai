<?php
/**
 * AI Customer Support schema owned by the ShopHaat application.
 *
 * Kept separate from the existing AI Auto Work generation tables: provider
 * credentials and model configuration remain in ai_providers / ai_models.
 */
function sh_ai_support_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        "CREATE TABLE IF NOT EXISTS support_conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED DEFAULT NULL,
            guest_token_hash CHAR(64) DEFAULT NULL,
            customer_name VARCHAR(120) NOT NULL DEFAULT '',
            customer_email VARCHAR(190) NOT NULL DEFAULT '',
            customer_phone VARCHAR(30) NOT NULL DEFAULT '',
            subject VARCHAR(160) DEFAULT NULL,
            status ENUM('open','pending','resolved','assigned','closed') NOT NULL DEFAULT 'open',
            assigned_admin_id INT UNSIGNED DEFAULT NULL,
            ai_paused TINYINT(1) NOT NULL DEFAULT 0,
            unread_for_admin TINYINT(1) NOT NULL DEFAULT 0,
            unread_for_customer TINYINT(1) NOT NULL DEFAULT 0,
            lang VARCHAR(10) NOT NULL DEFAULT 'en',
            last_product_id INT UNSIGNED DEFAULT NULL,
            last_order_number VARCHAR(30) DEFAULT NULL,
            context_summary TEXT NULL,
            unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
            unread_customer INT UNSIGNED NOT NULL DEFAULT 0,
            last_message_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_support_user_status (user_id, status, updated_at),
            KEY idx_support_guest_status (guest_token_hash, status, updated_at),
            KEY idx_support_status_updated (status, updated_at),
            KEY idx_support_assignee (assigned_admin_id, status, updated_at),
            KEY idx_support_retention (status, updated_at),
            CONSTRAINT fk_support_conversation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_support_conversation_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",

        "CREATE TABLE IF NOT EXISTS support_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            reply_to_message_id BIGINT UNSIGNED DEFAULT NULL,
            client_message_id VARCHAR(64) DEFAULT NULL,
            sender_admin_id INT UNSIGNED DEFAULT NULL,
            sender ENUM('customer','ai','assistant','admin','internal_note','system') NOT NULL,
            source ENUM('ai','fallback','manual','system') NOT NULL DEFAULT 'manual',
            body MEDIUMTEXT NOT NULL,
            extra_json TEXT DEFAULT NULL,
            reply_status ENUM('none','pending','complete','failed','paused') NOT NULL DEFAULT 'none',
            reply_started_at DATETIME DEFAULT NULL,
            is_internal TINYINT(1) NOT NULL DEFAULT 0,
            admin_id INT UNSIGNED DEFAULT NULL,
            visibility ENUM('public','internal') NOT NULL DEFAULT 'public',
            lang VARCHAR(10) NOT NULL DEFAULT '',
            client_msg_id VARCHAR(64) DEFAULT NULL,
            facts_json TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_client_message (conversation_id, client_message_id),
            KEY idx_support_legacy_client_message (conversation_id, client_msg_id),
            KEY idx_support_message_conversation (conversation_id, id),
            KEY idx_support_message_reply (reply_to_message_id),
            KEY idx_support_message_usage (sender, source, created_at),
            KEY idx_support_message_retention (created_at),
            CONSTRAINT fk_support_message_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_support_message_admin FOREIGN KEY (sender_admin_id) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",

        "CREATE TABLE IF NOT EXISTS support_handoff_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            event_key CHAR(36) DEFAULT NULL,
            legacy_event_id BIGINT UNSIGNED DEFAULT NULL,
            actor_type ENUM('customer','admin','system') NOT NULL,
            actor_admin_id INT UNSIGNED DEFAULT NULL,
            event VARCHAR(40) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_handoff_event_key (event_key),
            UNIQUE KEY uq_support_handoff_legacy_event (legacy_event_id),
            KEY idx_support_handoff_conversation (conversation_id, id),
            KEY idx_support_handoff_created (created_at),
            CONSTRAINT fk_support_handoff_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_support_handoff_admin FOREIGN KEY (actor_admin_id) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",

        "CREATE TABLE IF NOT EXISTS support_faqs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            seed_key VARCHAR(60) DEFAULT NULL,
            title VARCHAR(160) NOT NULL DEFAULT '',
            question_en VARCHAR(300) NOT NULL DEFAULT '',
            question_bn VARCHAR(300) DEFAULT NULL,
            question_banglish VARCHAR(300) DEFAULT NULL,
            answer_en TEXT NOT NULL,
            answer_bn TEXT DEFAULT NULL,
            answer_banglish TEXT DEFAULT NULL,
            keywords VARCHAR(500) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_faq_seed (seed_key),
            KEY idx_support_faq_status_sort (status, sort_order, id),
            CONSTRAINT fk_support_faq_admin FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
        ) $E",

        // Operational metrics contain no message bodies, prompts, addresses or raw IPs.
        "CREATE TABLE IF NOT EXISTS support_ai_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            guest_token_hash CHAR(64) DEFAULT NULL,
            provider_id INT UNSIGNED DEFAULT NULL,
            model VARCHAR(120) DEFAULT NULL,
            outcome ENUM('success','failed','fallback','limited') NOT NULL,
            prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            diagnostic_code VARCHAR(40) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_support_usage_created (created_at, outcome),
            KEY idx_support_usage_user_day (user_id, created_at),
            KEY idx_support_usage_guest_day (guest_token_hash, created_at),
            KEY idx_support_usage_conversation (conversation_id, created_at),
            CONSTRAINT fk_support_usage_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_support_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) $E",

        // Fixed-window rate buckets are keyed only by an irreversible digest.
        "CREATE TABLE IF NOT EXISTS support_rate_limits (
            bucket_key CHAR(64) NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (bucket_key),
            KEY idx_support_rate_expiry (expires_at)
        ) $E",
    ];
}

/** True when a support-owned table/column exists on this MySQL or MariaDB database. */
function sh_support_schema_has_column(PDO $pdo, string $table, string $column): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) { return false; }
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->execute([$table, $column]);
    return $st->fetchColumn() !== false;
}

function sh_support_schema_has_index(PDO $pdo, string $table, string $index): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $index) !== 1) { return false; }
    $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $st->execute([$table, $index]);
    return $st->fetchColumn() !== false;
}

function sh_support_schema_add_column(PDO $pdo, string $table, string $column, string $definition): bool
{
    if (sh_support_schema_has_column($pdo, $table, $column)) { return false; }
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
        throw new RuntimeException('Invalid support schema identifier.');
    }
    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    return true;
}

/** Preserve support child history if an old schema cascaded conversation deletes. */
function sh_support_schema_restrict_conversation_delete(PDO $pdo, string $table): void
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || !sh_support_schema_has_column($pdo, $table, 'conversation_id')) {
        return;
    }
    $st = $pdo->prepare("SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, kcu.COLUMN_NAME, kcu.REFERENCED_COLUMN_NAME
                         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                         JOIN information_schema.KEY_COLUMN_USAGE kcu
                           ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                          AND kcu.TABLE_NAME = rc.TABLE_NAME
                          AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                         WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = ?
                           AND kcu.REFERENCED_TABLE_NAME = 'support_conversations'");
    $st->execute([$table]);
    $hasRestrict = false;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fk) {
        $name = (string)$fk['CONSTRAINT_NAME'];
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) { throw new RuntimeException('Invalid support foreign-key name.'); }
        $columnMatches = (string)$fk['COLUMN_NAME'] === 'conversation_id'
            && (string)$fk['REFERENCED_COLUMN_NAME'] === 'id';
        $deleteRule = strtoupper((string)$fk['DELETE_RULE']);
        if ($columnMatches && in_array($deleteRule, ['RESTRICT', 'NO ACTION'], true)) {
            $hasRestrict = true;
            continue;
        }
        $pdo->exec('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $name . '`');
    }
    if (!$hasRestrict) {
        $orphanSt = $pdo->query('SELECT COUNT(*) FROM `' . $table . '` child '
            . 'LEFT JOIN `support_conversations` parent ON parent.id = child.conversation_id '
            . 'WHERE parent.id IS NULL');
        if ((int)$orphanSt->fetchColumn() > 0) {
            // Never delete or rewrite legacy rows just to make an optional FK fit.
            // There is no cascade on this table now; keep the history and report it.
            sh_log_line('ai-support-schema', 'Skipped restrictive FK creation for ' . $table . ' because legacy orphan rows were found; no support history was changed.');
            return;
        }
        $name = 'fk_sh_' . $table . '_conversation';
        $pdo->exec('ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` '
            . 'FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE RESTRICT');
    }
}

/**
 * Additively upgrade the legacy storefront-support tables from main.
 * Existing conversation/message/FAQ IDs and bodies remain untouched. Legacy
 * columns are retained; backfills only populate the new service-layer columns.
 */
function sh_support_schema_upgrade_legacy(PDO $pdo): void
{
    $columns = [
        'support_conversations' => [
            'customer_name' => "VARCHAR(120) NOT NULL DEFAULT ''",
            'customer_email' => "VARCHAR(190) NOT NULL DEFAULT ''",
            'customer_phone' => "VARCHAR(30) NOT NULL DEFAULT ''",
            'subject' => 'VARCHAR(160) DEFAULT NULL',
            'lang' => "VARCHAR(10) NOT NULL DEFAULT 'en'",
            'last_product_id' => 'INT UNSIGNED DEFAULT NULL',
            'last_order_number' => 'VARCHAR(30) DEFAULT NULL',
            'context_summary' => 'TEXT NULL',
            'unread_admin' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'unread_customer' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'unread_for_admin' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'unread_for_customer' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'support_messages' => [
            'reply_to_message_id' => 'BIGINT UNSIGNED DEFAULT NULL',
            'client_message_id' => 'VARCHAR(64) DEFAULT NULL',
            'sender_admin_id' => 'INT UNSIGNED DEFAULT NULL',
            'source' => "ENUM('ai','fallback','manual','system') NOT NULL DEFAULT 'manual'",
            'extra_json' => 'TEXT DEFAULT NULL',
            'reply_status' => "ENUM('none','pending','complete','failed','paused') NOT NULL DEFAULT 'none'",
            'reply_started_at' => 'DATETIME DEFAULT NULL',
            'is_internal' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'admin_id' => 'INT UNSIGNED DEFAULT NULL',
            'visibility' => "ENUM('public','internal') NOT NULL DEFAULT 'public'",
            'lang' => "VARCHAR(10) NOT NULL DEFAULT ''",
            'client_msg_id' => 'VARCHAR(64) DEFAULT NULL',
            'facts_json' => 'TEXT NULL',
        ],
        'support_handoff_events' => [
            'legacy_event_id' => 'BIGINT UNSIGNED DEFAULT NULL',
        ],
        'support_faqs' => [
            'seed_key' => 'VARCHAR(60) DEFAULT NULL',
            'title' => "VARCHAR(160) NOT NULL DEFAULT ''",
            'question_en' => "VARCHAR(300) NOT NULL DEFAULT ''",
            'question_bn' => 'VARCHAR(300) DEFAULT NULL',
            'question_banglish' => 'VARCHAR(300) DEFAULT NULL',
            'updated_by' => 'INT UNSIGNED DEFAULT NULL',
        ],
    ];
    foreach ($columns as $table => $items) {
        foreach ($items as $column => $definition) {
            sh_support_schema_add_column($pdo, $table, $column, $definition);
        }
    }

    $conversationType = strtolower((string)sh_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_conversations' AND COLUMN_NAME = 'status' LIMIT 1", [], ''));
    if ($conversationType !== '' && (!str_contains($conversationType, "'assigned'") || !str_contains($conversationType, "'closed'"))) {
        $pdo->exec("ALTER TABLE support_conversations MODIFY COLUMN status ENUM('open','pending','resolved','assigned','closed') NOT NULL DEFAULT 'open'");
    }
    $senderType = strtolower((string)sh_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_messages' AND COLUMN_NAME = 'sender' LIMIT 1", [], ''));
    if ($senderType !== '' && (!str_contains($senderType, "'assistant'") || !str_contains($senderType, "'internal_note'") || !str_contains($senderType, "'ai'"))) {
        $pdo->exec("ALTER TABLE support_messages MODIFY COLUMN sender ENUM('customer','ai','assistant','admin','internal_note','system') NOT NULL");
    }

    $lastMessageNullable = strtoupper((string)sh_val("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_conversations' AND COLUMN_NAME = 'last_message_at' LIMIT 1", [], 'YES'));
    if ($lastMessageNullable === 'NO') {
        $pdo->exec('ALTER TABLE support_conversations MODIFY COLUMN last_message_at DATETIME DEFAULT NULL');
    }

    $indexes = [
        ['support_messages', 'uq_support_client_message', 'UNIQUE KEY uq_support_client_message (conversation_id, client_message_id)'],
        ['support_messages', 'idx_support_legacy_client_message', 'KEY idx_support_legacy_client_message (conversation_id, client_msg_id)'],
        ['support_handoff_events', 'uq_support_handoff_legacy_event', 'UNIQUE KEY uq_support_handoff_legacy_event (legacy_event_id)'],
        ['support_faqs', 'uq_support_faq_seed', 'UNIQUE KEY uq_support_faq_seed (seed_key)'],
        ['support_faqs', 'idx_support_faq_status_sort', 'KEY idx_support_faq_status_sort (status, sort_order, id)'],
    ];
    foreach ($indexes as [$table, $index, $definition]) {
        if (!sh_support_schema_has_index($pdo, $table, $index)) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition);
        }
    }

    // A legacy CASCADE would erase full support history if a conversation row
    // were removed by a manual/admin action. Restrict deletion on every thread.
    sh_support_schema_restrict_conversation_delete($pdo, 'support_messages');
    sh_support_schema_restrict_conversation_delete($pdo, 'support_events');

    // Legacy assignment is human ownership even if an older code path did not
    // also set ai_paused. Pause those threads and any in-flight customer reply.
    $pdo->exec('UPDATE support_conversations SET ai_paused = 1 WHERE assigned_admin_id IS NOT NULL AND assigned_admin_id > 0 AND ai_paused = 0');
    $pdo->exec("UPDATE support_messages SET reply_status = 'paused', reply_started_at = NULL
                WHERE reply_status = 'pending' AND sender = 'customer'
                  AND conversation_id IN (SELECT id FROM support_conversations WHERE assigned_admin_id IS NOT NULL AND assigned_admin_id > 0)");

    if (sh_support_schema_has_column($pdo, 'support_conversations', 'unread_admin')) {
        $pdo->exec('UPDATE support_conversations SET unread_for_admin = 1 WHERE unread_for_admin = 0 AND unread_admin > 0');
    }
    if (sh_support_schema_has_column($pdo, 'support_conversations', 'unread_customer')) {
        $pdo->exec('UPDATE support_conversations SET unread_for_customer = 1 WHERE unread_for_customer = 0 AND unread_customer > 0');
    }
    if (sh_support_schema_has_column($pdo, 'support_messages', 'admin_id')) {
        $pdo->exec('UPDATE support_messages SET sender_admin_id = admin_id WHERE sender_admin_id IS NULL AND admin_id IS NOT NULL');
    }
    if (sh_support_schema_has_column($pdo, 'support_messages', 'visibility')) {
        $pdo->exec("UPDATE support_messages SET is_internal = 1 WHERE visibility = 'internal' AND is_internal = 0");
    }
    if (sh_support_schema_has_column($pdo, 'support_messages', 'facts_json')) {
        $pdo->exec('UPDATE support_messages SET extra_json = facts_json WHERE extra_json IS NULL AND facts_json IS NOT NULL');
    }
    $pdo->exec("UPDATE support_messages SET source = 'ai' WHERE sender = 'ai' AND source = 'manual'");
    $pdo->exec("UPDATE support_messages SET source = 'system' WHERE sender = 'system' AND source = 'manual'");

    if (sh_support_schema_has_column($pdo, 'support_faqs', 'title')) {
        $pdo->exec("UPDATE support_faqs SET question_en = LEFT(title, 300) WHERE question_en = '' AND title <> ''");
    }
    if (sh_support_schema_has_column($pdo, 'support_events', 'id')
        && sh_support_schema_has_column($pdo, 'support_events', 'conversation_id')
        && sh_support_schema_has_column($pdo, 'support_events', 'event')
        && sh_support_schema_has_column($pdo, 'support_events', 'admin_id')
        && sh_support_schema_has_column($pdo, 'support_events', 'detail')
        && sh_support_schema_has_column($pdo, 'support_events', 'created_at')) {
        $pdo->exec("INSERT IGNORE INTO support_handoff_events
            (conversation_id, legacy_event_id, actor_type, actor_admin_id, event, reason, created_at)
            SELECT e.conversation_id, e.id,
                   CASE WHEN e.admin_id IS NULL THEN 'system' ELSE 'admin' END,
                   e.admin_id, LEFT(e.event, 40), LEFT(COALESCE(e.detail, ''), 255), e.created_at
            FROM support_events e
            LEFT JOIN support_handoff_events h ON h.legacy_event_id = e.id
            WHERE h.id IS NULL");
    }
}
