-- Persisted payment-submission and per-channel initial-order delivery metadata.
--
-- The application applies this safely at runtime through
-- sh_payment_submission_schema_ensure() and sh_notification_log_schema_ensure(),
-- which check information_schema before every additive ALTER.  This file is a
-- reference for operators who prefer a manual migration on MySQL 5.7+/MariaDB 10.3+.
-- Run each ADD only when the named column/index is absent.

ALTER TABLE payments
  ADD COLUMN submitted_at DATETIME DEFAULT NULL AFTER status,
  ADD COLUMN submission_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER submitted_at,
  ADD KEY idx_payments_transaction_id (transaction_id);

ALTER TABLE notification_logs
  ADD COLUMN idempotency_key VARCHAR(100) DEFAULT NULL AFTER event,
  ADD COLUMN attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD UNIQUE KEY uq_notification_idempotency (channel, event, idempotency_key);
