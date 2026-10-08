<?php
/**
 * AI Auto Work — database migration.
 *
 * Safe to run repeatedly: every statement is CREATE TABLE IF NOT EXISTS or a
 * guarded ALTER, so it never destroys existing data and never duplicates a
 * column that is already there.
 */

/** Tables this module owns. */
function sh_ai_tables(): array
{
    return ['ai_settings', 'ai_providers', 'ai_models', 'ai_generations', 'ai_queue', 'ai_prompt_templates', 'blog_posts', 'product_tags'];
}

/** Does a column already exist? Used so migrations stay idempotent. */
function sh_ai_column_exists(string $table, string $column): bool
{
    try {
        return (int)sh_val(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column], 0
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sh_ai_table_exists(string $table): bool
{
    try {
        return (int)sh_val(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?', [$table], 0
        ) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** DDL for the AI module. MySQL 5.7 / MariaDB 10.3 compatible. */
function sh_ai_schema_sql(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [];

    // Key/value settings, kept separate from the store's own settings table so
    // the API key can be encrypted and access-controlled independently.
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_settings (
        setting_key VARCHAR(60) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) $E";

    // Configured AI providers. One row per account/endpoint; the driver column
    // picks the adapter class. API keys are stored encrypted (enc:...).
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_providers (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(80) NOT NULL,
        driver VARCHAR(40) NOT NULL,
        base_url VARCHAR(255) DEFAULT NULL,
        api_key TEXT DEFAULT NULL,
        auth_header VARCHAR(120) DEFAULT NULL,
        extra_headers TEXT DEFAULT NULL,
        default_model VARCHAR(120) DEFAULT NULL,
        default_image_model VARCHAR(120) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        models_synced_at DATETIME DEFAULT NULL,
        last_tested_at DATETIME DEFAULT NULL,
        last_test_ok TINYINT(1) DEFAULT NULL,
        last_test_note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_aip_driver (driver),
        KEY idx_aip_status (status, is_default)
    ) $E";

    // Model catalogue per provider, with capability flags so image tasks can
    // refuse a text-only model before any request is made.
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_models (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider_id INT UNSIGNED NOT NULL,
        model_id VARCHAR(120) NOT NULL,
        name VARCHAR(160) NOT NULL,
        input_text TINYINT(1) NOT NULL DEFAULT 1,
        input_image TINYINT(1) NOT NULL DEFAULT 0,
        output_text TINYINT(1) NOT NULL DEFAULT 1,
        output_image TINYINT(1) NOT NULL DEFAULT 0,
        context_length INT UNSIGNED DEFAULT NULL,
        prompt_price DECIMAL(16,10) DEFAULT NULL,
        completion_price DECIMAL(16,10) DEFAULT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        source ENUM('api','manual') NOT NULL DEFAULT 'manual',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aim_model (provider_id, model_id),
        KEY idx_aim_caps (provider_id, status, output_image)
    ) $E";

    // Every generation attempt, success or failure.
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_generations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_id INT UNSIGNED DEFAULT NULL,
        type VARCHAR(40) NOT NULL,
        reference_type VARCHAR(30) DEFAULT NULL,
        reference_id INT UNSIGNED DEFAULT NULL,
        prompt MEDIUMTEXT DEFAULT NULL,
        response MEDIUMTEXT DEFAULT NULL,
        status ENUM('success','failed') NOT NULL DEFAULT 'success',
        provider VARCHAR(40) DEFAULT NULL,
        provider_id INT UNSIGNED DEFAULT NULL,
        model VARCHAR(120) DEFAULT NULL,
        tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
        prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        cost DECIMAL(14,8) DEFAULT NULL,
        fallback_used TINYINT(1) NOT NULL DEFAULT 0,
        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_aig_type (type),
        KEY idx_aig_ref (reference_type, reference_id),
        KEY idx_aig_status (status),
        KEY idx_aig_created (created_at)
    ) $E";

    // Bulk jobs, processed in controlled batches so we never fire N requests at once.
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(40) NOT NULL,
        admin_id INT UNSIGNED DEFAULT NULL,
        task VARCHAR(40) NOT NULL,
        reference_type VARCHAR(30) NOT NULL DEFAULT 'product',
        reference_id INT UNSIGNED NOT NULL,
        status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        provider_id INT UNSIGNED DEFAULT NULL,
        model VARCHAR(120) DEFAULT NULL,
        result MEDIUMTEXT DEFAULT NULL,
        error_message VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aiq_job (batch_id, task, reference_type, reference_id),
        KEY idx_aiq_batch (batch_id, status),
        KEY idx_aiq_status (status)
    ) $E";

    // Editable prompt templates so prompts are not scattered through the code.
    $sql[] = "CREATE TABLE IF NOT EXISTS ai_prompt_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_key VARCHAR(40) NOT NULL,
        label VARCHAR(120) NOT NULL,
        system_prompt TEXT DEFAULT NULL,
        user_prompt TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_aipt_key (template_key)
    ) $E";

    // The store had no blog; Blog AI needs somewhere to save drafts.
    $sql[] = "CREATE TABLE IF NOT EXISTS blog_posts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL,
        slug VARCHAR(210) NOT NULL,
        excerpt VARCHAR(500) DEFAULT NULL,
        content MEDIUMTEXT DEFAULT NULL,
        cover_image VARCHAR(190) DEFAULT NULL,
        meta_title VARCHAR(190) DEFAULT NULL,
        meta_description VARCHAR(300) DEFAULT NULL,
        meta_keywords VARCHAR(300) DEFAULT NULL,
        author_id INT UNSIGNED DEFAULT NULL,
        source ENUM('manual','ai') NOT NULL DEFAULT 'manual',
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        published_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_blog_slug (slug),
        KEY idx_blog_status (status, published_at)
    ) $E";

    // Product tags (the store had none). Unique per product so duplicates are impossible.
    $sql[] = "CREATE TABLE IF NOT EXISTS product_tags (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        tag VARCHAR(60) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ptag (product_id, tag),
        KEY idx_ptag_tag (tag),
        CONSTRAINT fk_ptag_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
    ) $E";

    return $sql;
}

/**
 * Run the migration. Returns a per-step report for the installer UI.
 * Never throws: a failure is reported, not fatal.
 */
function sh_ai_migrate(): array
{
    $report = [];
    $pdo = sh_db();

    foreach (sh_ai_schema_sql() as $ddl) {
        preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $ddl, $m);
        $table = $m[1] ?? 'table';
        $existed = sh_ai_table_exists($table);
        try {
            $pdo->exec($ddl);
            $report[] = ['item' => $table, 'ok' => true,
                         'note' => $existed ? 'Already present — left untouched' : 'Created'];
        } catch (Throwable $e) {
            sh_log_exception($e, 'ai-migrate');
            $report[] = ['item' => $table, 'ok' => false, 'note' => 'Could not be created'];
        }
    }

    // Category SEO columns: the store has none, products already do.
    foreach ([
        'meta_title'       => "ALTER TABLE categories ADD COLUMN meta_title VARCHAR(190) DEFAULT NULL",
        'meta_description' => "ALTER TABLE categories ADD COLUMN meta_description VARCHAR(300) DEFAULT NULL",
        'meta_keywords'    => "ALTER TABLE categories ADD COLUMN meta_keywords VARCHAR(300) DEFAULT NULL",
    ] as $col => $ddl) {
        if (sh_ai_column_exists('categories', $col)) {
            $report[] = ['item' => 'categories.' . $col, 'ok' => true, 'note' => 'Already present'];
            continue;
        }
        try {
            $pdo->exec($ddl);
            $report[] = ['item' => 'categories.' . $col, 'ok' => true, 'note' => 'Added'];
        } catch (Throwable $e) {
            sh_log_exception($e, 'ai-migrate-col');
            $report[] = ['item' => 'categories.' . $col, 'ok' => false, 'note' => 'Could not be added'];
        }
    }

    // Products already carry meta_title / meta_description — reuse, never duplicate.
    foreach (['meta_title', 'meta_description'] as $col) {
        $report[] = ['item' => 'products.' . $col, 'ok' => sh_ai_column_exists('products', $col),
                     'note' => sh_ai_column_exists('products', $col)
                        ? 'Existing column reused (no duplicate created)' : 'Missing'];
    }
    if (!sh_ai_column_exists('products', 'meta_keywords')) {
        try {
            $pdo->exec("ALTER TABLE products ADD COLUMN meta_keywords VARCHAR(300) DEFAULT NULL");
            $report[] = ['item' => 'products.meta_keywords', 'ok' => true, 'note' => 'Added'];
        } catch (Throwable $e) {
            $report[] = ['item' => 'products.meta_keywords', 'ok' => false, 'note' => 'Could not be added'];
        }
    } else {
        $report[] = ['item' => 'products.meta_keywords', 'ok' => true, 'note' => 'Already present'];
    }

    // Columns added by the multi-provider release (guarded, idempotent).
    foreach ([
        ['ai_generations', 'provider_id',       "ALTER TABLE ai_generations ADD COLUMN provider_id INT UNSIGNED DEFAULT NULL AFTER provider"],
        ['ai_generations', 'prompt_tokens',     "ALTER TABLE ai_generations ADD COLUMN prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0 AFTER tokens_used"],
        ['ai_generations', 'completion_tokens', "ALTER TABLE ai_generations ADD COLUMN completion_tokens INT UNSIGNED NOT NULL DEFAULT 0 AFTER prompt_tokens"],
        ['ai_generations', 'cost',              "ALTER TABLE ai_generations ADD COLUMN cost DECIMAL(14,8) DEFAULT NULL AFTER completion_tokens"],
        ['ai_generations', 'fallback_used',     "ALTER TABLE ai_generations ADD COLUMN fallback_used TINYINT(1) NOT NULL DEFAULT 0 AFTER cost"],
        ['ai_queue',       'provider_id',       "ALTER TABLE ai_queue ADD COLUMN provider_id INT UNSIGNED DEFAULT NULL AFTER attempts"],
        ['ai_queue',       'model',             "ALTER TABLE ai_queue ADD COLUMN model VARCHAR(120) DEFAULT NULL AFTER provider_id"],
    ] as [$table, $col, $ddl]) {
        if (sh_ai_column_exists($table, $col)) { continue; }
        try {
            $pdo->exec($ddl);
            $report[] = ['item' => $table . '.' . $col, 'ok' => true, 'note' => 'Added'];
        } catch (Throwable $e) {
            sh_log_exception($e, 'ai-migrate-col');
            $report[] = ['item' => $table . '.' . $col, 'ok' => false, 'note' => 'Could not be added'];
        }
    }
    try {
        $pdo->exec("ALTER TABLE ai_generations MODIFY model VARCHAR(120) DEFAULT NULL");
    } catch (Throwable $e) { /* already wide enough */ }

    $report = array_merge($report, sh_ai_migrate_legacy_provider());
    sh_ai_seed_templates();
    return $report;
}

/**
 * Upgrade path: the first release stored a single OpenAI key in ai_settings.
 * Move it into an ai_providers row once, so nothing the admin configured is lost.
 */
function sh_ai_migrate_legacy_provider(): array
{
    if (!sh_ai_table_exists('ai_providers')) { return []; }
    try {
        if ((int)sh_val('SELECT COUNT(*) FROM ai_providers', [], 0) > 0) { return []; }
        $s = [];
        foreach (sh_all("SELECT setting_key, setting_value FROM ai_settings WHERE setting_key IN ('api_key','model','image_model','api_base','provider')") as $r) {
            $s[$r['setting_key']] = (string)$r['setting_value'];
        }
        if (trim($s['api_key'] ?? '') === '') { return []; }
        $base = trim($s['api_base'] ?? '');
        if ($base !== '' && !preg_match('#/v\d+/?$#', $base)) { $base = rtrim($base, '/') . '/v1'; }
        $id = (int)sh_insert('ai_providers', [
            'name' => 'OpenAI', 'driver' => 'openai', 'base_url' => $base,
            'api_key' => $s['api_key'],                       // already encrypted
            'default_model' => $s['model'] ?? 'gpt-4o-mini',
            'default_image_model' => $s['image_model'] ?? 'dall-e-3',
            'status' => 1, 'is_default' => 1, 'sort_order' => 1,
        ]);
        if (function_exists('sh_ai_models_seed')) {
            sh_ai_providers_all(true);
            sh_ai_models_seed($id);
            foreach ([$s['model'] ?? '', $s['image_model'] ?? ''] as $mid) { if ($mid !== '') { sh_ai_model_ensure($id, $mid); } }
        }
        sh_query("DELETE FROM ai_settings WHERE setting_key IN ('api_key','api_base')");
        return [['item' => 'ai_providers (legacy OpenAI key)', 'ok' => true, 'note' => 'Moved into the providers table']];
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-migrate-legacy');
        return [['item' => 'ai_providers (legacy OpenAI key)', 'ok' => false, 'note' => 'Could not be migrated']];
    }
}

/** Install the default prompt templates once. */
function sh_ai_seed_templates(): void
{
    $defaults = sh_ai_default_templates();
    try {
        $st = sh_db()->prepare(
            'INSERT IGNORE INTO ai_prompt_templates (template_key, label, system_prompt, user_prompt)
             VALUES (?,?,?,?)'
        );
        foreach ($defaults as $key => $t) {
            $st->execute([$key, $t['label'], $t['system'], $t['user']]);
        }
    } catch (Throwable $e) {
        sh_log_exception($e, 'ai-seed-templates');
    }
}

/** Is the AI module installed? */
function sh_ai_installed(bool $refresh = false): bool
{
    static $ok = null;
    if ($ok !== null && !$refresh) { return $ok; }
    try {
        $n = (int)sh_val(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('ai_settings','ai_generations','ai_queue','ai_prompt_templates','ai_providers','ai_models')", [], 0);
        return $ok = ($n === 6);
    } catch (Throwable $e) {
        return $ok = false;
    }
}
