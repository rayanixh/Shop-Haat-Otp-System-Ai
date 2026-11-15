-- ShopHaat payments — one transaction reference per store (2026-11)
--
-- WHY
-- ---
-- A manual bKash/Nagad/Rocket submission stores the customer's transaction ID in
-- payments.transaction_id. Before this migration the only protection against two
-- orders claiming the same TrxID was sh_submit_manual_payment(), which locks the
-- payments rows it can already see inside one transaction:
--
--     SELECT id FROM payments WHERE transaction_id = ? AND order_id <> ? FOR UPDATE
--
-- That closes the sequential case. It cannot close the concurrent one: two
-- requests for two different orders, sent at the same instant, both find no
-- existing row, and both insert. The store then has one real payment and one
-- order marked "payment submitted" that can be approved by mistake.
--
-- A UNIQUE index makes the database the referee. NULL is allowed many times,
-- which is what every unpaid/pending row uses, so this never blocks a retry.
--
-- WHAT IT DOES
-- ------------
--   1. Reports duplicate non-NULL transaction IDs (nothing is changed yet).
--   2. Normalises '' to NULL (an empty string is not a reference and would
--      collide with itself under a unique index).
--   3. Adds uq_payments_transaction_id only when no duplicates remain, and
--      replaces the old non-unique idx_payments_transaction_id with it.
--
-- The application also applies this automatically (sh_payment_submission_schema_ensure())
-- and postpones the retry for a week if duplicates block it, so a store with a
-- broken history keeps working instead of failing every checkout.
--
-- Run in phpMyAdmin (or mysql CLI) against the ShopHaat database.

-- 1. Inspect duplicates. If this returns rows, decide which order keeps the
--    reference (usually the one whose payment is verified) before step 3.
SELECT transaction_id, COUNT(*) AS claims, GROUP_CONCAT(id ORDER BY id) AS payment_ids,
       GROUP_CONCAT(order_id ORDER BY id) AS order_ids
FROM payments
WHERE transaction_id IS NOT NULL AND transaction_id <> ''
GROUP BY transaction_id
HAVING claims > 1;

-- 2. An empty string is not a reference.
UPDATE payments SET transaction_id = NULL WHERE transaction_id = '';

-- 3. Create the constraint only when the data is clean. Any duplicate makes
--    MySQL refuse the ALTER; that refusal is the safe outcome, not an error to
--    force past.
SET @dupes := (
    SELECT COUNT(*) FROM (
        SELECT transaction_id FROM payments
        WHERE transaction_id IS NOT NULL
        GROUP BY transaction_id HAVING COUNT(*) > 1
    ) d
);

SET @has_plain := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'idx_payments_transaction_id'
);
SET @has_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
      AND INDEX_NAME = 'uq_payments_transaction_id'
);

SET @sql := IF(@dupes > 0 OR @has_unique > 0,
    'SELECT ''skipped: resolve the duplicate transaction IDs above, then run this script again'' AS result',
    IF(@has_plain > 0,
        'ALTER TABLE payments DROP INDEX idx_payments_transaction_id, ADD UNIQUE KEY uq_payments_transaction_id (transaction_id)',
        'ALTER TABLE payments ADD UNIQUE KEY uq_payments_transaction_id (transaction_id)'));

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Verify: expect exactly one row, NON_UNIQUE = 0.
SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
  AND INDEX_NAME IN ('uq_payments_transaction_id', 'idx_payments_transaction_id');
