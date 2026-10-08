-- AI Auto Work: multi-provider architecture.
-- The application runs this automatically (ai/schema.php → sh_ai_migrate(),
-- triggered on first admin visit after the update and by "Re-run migration").
-- This file documents the equivalent DDL for reference / manual environments.
-- Every statement is idempotent on MySQL 5.7+/MariaDB 10.3+.

CREATE TABLE IF NOT EXISTS ai_providers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  driver VARCHAR(40) NOT NULL,                 -- openai | openrouter | gemini | anthropic | openai_compatible
  base_url VARCHAR(255) DEFAULT NULL,
  api_key TEXT DEFAULT NULL,                   -- AES-256-CBC, prefixed enc:
  auth_header VARCHAR(120) DEFAULT NULL,
  extra_headers TEXT DEFAULT NULL,             -- JSON object
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_models (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id INT UNSIGNED NOT NULL,
  model_id VARCHAR(120) NOT NULL,
  name VARCHAR(160) NOT NULL,
  input_text TINYINT(1) NOT NULL DEFAULT 1,
  input_image TINYINT(1) NOT NULL DEFAULT 0,
  output_text TINYINT(1) NOT NULL DEFAULT 1,
  output_image TINYINT(1) NOT NULL DEFAULT 0,
  context_length INT UNSIGNED DEFAULT NULL,
  prompt_price DECIMAL(16,10) DEFAULT NULL,    -- USD per token (OpenRouter)
  completion_price DECIMAL(16,10) DEFAULT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('api','manual') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aim_model (provider_id, model_id),
  KEY idx_aim_caps (provider_id, status, output_image)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing tables: additive columns only (the PHP migration checks
-- information_schema first, so it never fails on a column that exists).
ALTER TABLE ai_generations ADD COLUMN provider_id INT UNSIGNED DEFAULT NULL AFTER provider;
ALTER TABLE ai_generations MODIFY model VARCHAR(120) DEFAULT NULL;
ALTER TABLE ai_generations ADD COLUMN prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0 AFTER tokens_used;
ALTER TABLE ai_generations ADD COLUMN completion_tokens INT UNSIGNED NOT NULL DEFAULT 0 AFTER prompt_tokens;
ALTER TABLE ai_generations ADD COLUMN cost DECIMAL(14,8) DEFAULT NULL AFTER completion_tokens;
ALTER TABLE ai_generations ADD COLUMN fallback_used TINYINT(1) NOT NULL DEFAULT 0 AFTER cost;
ALTER TABLE ai_queue ADD COLUMN provider_id INT UNSIGNED DEFAULT NULL AFTER attempts;
ALTER TABLE ai_queue ADD COLUMN model VARCHAR(120) DEFAULT NULL AFTER provider_id;

-- Data migration (done in PHP, once): if ai_providers is empty and
-- ai_settings has the legacy 'api_key', an OpenAI provider row is created from
-- api_key / model / image_model / api_base, then the two legacy keys are removed.

-- New ai_settings keys (created on save, no seed required):
--   task_<task>_provider, task_<task>_model    per-task routing
--   fallback_enabled, fallback_provider, fallback_model,
--   fallback_image_provider, fallback_image_model
