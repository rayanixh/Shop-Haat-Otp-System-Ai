-- Rollback for 2026-11-payment-transaction-unique.sql
--
-- Restores the previous shape: a non-unique lookup index on transaction_id.
-- Only run this if the unique constraint is causing a problem (for example an
-- import tool that legitimately reuses placeholder references). Removing it
-- re-opens the concurrent double-submission window described in the forward
-- migration, so prefer fixing the data.
--
-- The application re-creates the unique index on the next payment submission
-- unless duplicate rows exist, in which case it postpones for a week. To keep it
-- off deliberately, set the setting payment_txn_unique_retry_at to a far-future
-- date.
--
-- Run in phpMyAdmin (or the mysql CLI) against the ShopHaat database.

SET @has_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'uq_payments_transaction_id'
);
SET @has_plain := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'idx_payments_transaction_id'
);

SET @sql := IF(@has_unique = 0,
    'SELECT ''nothing to roll back: the unique index is not present'' AS result',
    IF(@has_plain > 0,
        'ALTER TABLE payments DROP INDEX uq_payments_transaction_id',
        'ALTER TABLE payments DROP INDEX uq_payments_transaction_id, ADD KEY idx_payments_transaction_id (transaction_id)'));

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE settings SET setting_value = DATE_ADD(NOW(), INTERVAL 3650 DAY)
WHERE setting_key = 'payment_txn_unique_retry_at';

-- Verify: NON_UNIQUE = 1 for idx_payments_transaction_id, and no uq_ row.
SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
  AND INDEX_NAME IN ('uq_payments_transaction_id', 'idx_payments_transaction_id');
