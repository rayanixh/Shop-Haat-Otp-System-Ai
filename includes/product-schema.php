<?php
/**
 * Product columns added after the original catalogue schema.
 *
 *   1. `features`        — the "Features and benefits" list, one item per line.
 *                          Kept separate from `specifications` (which is a
 *                          label/value table) so the product page can render
 *                          both without guesswork.
 *   2. `image_alt`       — the alt text for the featured image. Empty means the
 *                          product name is used, which is the old behaviour.
 *   3. `ai_draft_token`  — an idempotency key for AI Auto Work. One generated
 *                          draft can only ever create one product, even when the
 *                          administrator double-clicks Save or a request is
 *                          retried after a timeout.
 *
 * The migration is additive and idempotent: on a fresh install the columns come
 * from install/schema.php, and on an existing store they are added once, the
 * next time an admin or storefront page touches a product.
 */
declare(strict_types=1);

/**
 * Ensure the extra product columns and the draft-token index exist.
 *
 * Runs at most once per request and never inside a transaction (MySQL commits
 * DDL implicitly, which would break a checkout that is already in flight).
 */
function sh_product_schema_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    try {
        // One cached settings read on the hot path; the information_schema
        // probes below only run until the columns have been added once.
        if (sh_setting('product_schema_v', '') === '1') { return; }
        $pdo = sh_db();
        if ($pdo->inTransaction()) { return; }

        $cols = [
            'features'       => 'TEXT DEFAULT NULL',
            'image_alt'      => 'VARCHAR(190) DEFAULT NULL',
            'ai_draft_token' => 'VARCHAR(64) DEFAULT NULL',
        ];
        foreach ($cols as $col => $def) {
            $exists = (int)sh_val(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['products', $col],
                0
            );
            if ($exists === 0) {
                $pdo->exec('ALTER TABLE products ADD COLUMN `' . $col . '` ' . $def);
            }
        }

        // A unique index is what makes the token a real guard rather than a
        // convention: two concurrent save requests cannot both insert it.
        $indexed = (int)sh_val(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['products', 'uq_products_draft_token'],
            0
        );
        if ($indexed === 0) {
            try {
                $pdo->exec('ALTER TABLE products ADD UNIQUE KEY uq_products_draft_token (ai_draft_token)');
            } catch (Throwable $e) {
                // A store may already contain duplicate tokens from a manual
                // import; the save path still checks the token in a transaction,
                // so a missing index is a hardening loss, not a broken save.
                if (function_exists('sh_log_line')) {
                    sh_log_line('product-schema', 'Could not add the draft-token index: ' . $e->getMessage());
                }
            }
        }
        if (function_exists('sh_setting_save')) {
            sh_setting_save('product_schema_v', '1');
        }
    } catch (Throwable $e) {
        if (function_exists('sh_log_exception')) {
            sh_log_exception($e, 'product-schema');
        }
    }
}
