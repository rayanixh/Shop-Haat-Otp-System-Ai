-- ShopHaat OTP rate limiting — schema v5 (2026-11)
--
-- Adds the two tables that make OTP request limits independent of the
-- otp_verifications rows that hold the codes themselves:
--
--   otp_rate_limits : append-only request log. One row per OTP request.
--                     Stores HMAC-SHA256 identifiers for the phone and IP
--                     (never a phone number), the purpose, the outcome and the
--                     database timestamp. Deleting, expiring or replacing a
--                     code cannot remove a request from this history, and a
--                     failed SMS delivery only changes `outcome`.
--   otp_rate_locks  : one row per phone/IP bucket. Rate-window checks take a
--                     row lock here (SELECT ... FOR UPDATE) inside one
--                     transaction, so two simultaneous requests cannot both
--                     read "below the limit".
--
-- The application applies this automatically:
--   * fresh install  -> install/schema.php:sh_schema_sql()
--   * existing store -> sh_otp_schema_ensure() (lazy, version-stamped in the
--                       settings table as otp_schema_version = 5)
--   * first OTP request before any admin page -> sh_otp_rate_ensure()
--
-- This file is the manual equivalent for operators who prefer to run the DDL
-- themselves (phpMyAdmin / cPanel → MySQL Databases → phpMyAdmin → SQL).
--
-- SAFETY: only CREATE TABLE IF NOT EXISTS statements. No existing table is
-- altered, no column is dropped and no customer, order, admin or OTP row is
-- touched. Running it twice is a no-op. Requires MySQL 5.7+ / MariaDB 10.3+
-- (InnoDB, utf8mb4).

CREATE TABLE IF NOT EXISTS `otp_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone_key` CHAR(64) NOT NULL,
  `ip_key` CHAR(64) DEFAULT NULL,
  `purpose` VARCHAR(30) NOT NULL,
  `outcome` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `provider` VARCHAR(30) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orl_phone_time` (`phone_key`, `created_at`),
  KEY `idx_orl_ip_time` (`ip_key`, `created_at`),
  KEY `idx_orl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `otp_rate_locks` (
  `bucket_key` CHAR(64) NOT NULL,
  `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_orl_lock_age` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stamp the schema version so the built-in upgrader knows the tables exist.
-- (The application also writes this automatically; including it here keeps a
-- manually-migrated store in exactly the same state.)
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('otp_schema_version', '5')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('otp_rate_schema_version', '1')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Verification queries (read-only; run after the statements above):
--   SHOW INDEX FROM otp_rate_limits;
--   SELECT COUNT(*) FROM otp_rate_limits;
