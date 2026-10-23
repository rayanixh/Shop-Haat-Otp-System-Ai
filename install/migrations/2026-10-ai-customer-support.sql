-- ShopHaat AI Customer Support — additive schema migration.
--
-- The application also creates these tables safely on first use. This file is
-- supplied for operators who prefer phpMyAdmin/manual deployment. It creates
-- only support-owned tables; it does not alter users, products, orders, stock,
-- carts, payment tables, or existing AI provider/model configuration.
--
-- BACK UP FIRST. On cPanel, use phpMyAdmin → Export (Custom) for the database,
-- or the host's database backup tool. Run this file against the ShopHaat schema.
-- All DDL is CREATE TABLE IF NOT EXISTS and may be re-run.
--
-- UTC: application writes UTC_TIMESTAMP() explicitly to these DATETIME fields.
--
-- Rollback: see 2026-10-ai-customer-support-rollback.sql. Dropping these tables
-- deletes support conversations, messages, handoff history, FAQs and metrics;
-- export them first if that data must be retained.

CREATE TABLE IF NOT EXISTS `support_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `guest_token_hash` CHAR(64) DEFAULT NULL,
  `subject` VARCHAR(160) DEFAULT NULL,
  `status` ENUM('open','pending','resolved','assigned') NOT NULL DEFAULT 'open',
  `assigned_admin_id` INT UNSIGNED DEFAULT NULL,
  `ai_paused` TINYINT(1) NOT NULL DEFAULT 0,
  `unread_for_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `unread_for_customer` TINYINT(1) NOT NULL DEFAULT 0,
  `last_message_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_user_status` (`user_id`,`status`,`updated_at`),
  KEY `idx_support_guest_status` (`guest_token_hash`,`status`,`updated_at`),
  KEY `idx_support_status_updated` (`status`,`updated_at`),
  KEY `idx_support_assignee` (`assigned_admin_id`,`status`,`updated_at`),
  KEY `idx_support_retention` (`status`,`updated_at`),
  CONSTRAINT `fk_support_conversation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_conversation_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `client_message_id` CHAR(36) DEFAULT NULL,
  `sender_admin_id` INT UNSIGNED DEFAULT NULL,
  `sender` ENUM('customer','assistant','admin','internal_note','system') NOT NULL,
  `source` ENUM('ai','fallback','manual','system') NOT NULL DEFAULT 'manual',
  `body` MEDIUMTEXT NOT NULL,
  `extra_json` TEXT DEFAULT NULL,
  `reply_status` ENUM('none','pending','complete','failed','paused') NOT NULL DEFAULT 'none',
  `reply_started_at` DATETIME DEFAULT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_client_message` (`conversation_id`,`client_message_id`),
  KEY `idx_support_message_conversation` (`conversation_id`,`id`),
  KEY `idx_support_message_reply` (`reply_to_message_id`),
  KEY `idx_support_message_usage` (`sender`,`source`,`created_at`),
  KEY `idx_support_message_retention` (`created_at`),
  CONSTRAINT `fk_support_message_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_message_admin` FOREIGN KEY (`sender_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_handoff_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `event_key` CHAR(36) DEFAULT NULL,
  `actor_type` ENUM('customer','admin','system') NOT NULL,
  `actor_admin_id` INT UNSIGNED DEFAULT NULL,
  `event` VARCHAR(40) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_handoff_event_key` (`event_key`),
  KEY `idx_support_handoff_conversation` (`conversation_id`,`id`),
  KEY `idx_support_handoff_created` (`created_at`),
  CONSTRAINT `fk_support_handoff_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_handoff_admin` FOREIGN KEY (`actor_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_faqs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `seed_key` VARCHAR(60) DEFAULT NULL,
  `question_en` VARCHAR(300) NOT NULL,
  `question_bn` VARCHAR(300) DEFAULT NULL,
  `question_banglish` VARCHAR(300) DEFAULT NULL,
  `answer_en` TEXT NOT NULL,
  `answer_bn` TEXT DEFAULT NULL,
  `answer_banglish` TEXT DEFAULT NULL,
  `keywords` VARCHAR(500) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_faq_seed` (`seed_key`),
  KEY `idx_support_faq_status_sort` (`status`,`sort_order`,`id`),
  CONSTRAINT `fk_support_faq_admin` FOREIGN KEY (`updated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ai_usage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `guest_token_hash` CHAR(64) DEFAULT NULL,
  `provider_id` INT UNSIGNED DEFAULT NULL,
  `model` VARCHAR(120) DEFAULT NULL,
  `outcome` ENUM('success','failed','fallback','limited') NOT NULL,
  `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `diagnostic_code` VARCHAR(40) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_support_usage_created` (`created_at`,`outcome`),
  KEY `idx_support_usage_user_day` (`user_id`,`created_at`),
  KEY `idx_support_usage_guest_day` (`guest_token_hash`,`created_at`),
  KEY `idx_support_usage_conversation` (`conversation_id`,`created_at`),
  CONSTRAINT `fk_support_usage_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_support_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_rate_limits` (
  `bucket_key` CHAR(64) NOT NULL,
  `hits` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_support_rate_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
