-- ShopHaat AI Customer Support soft rollback / disable.
--
-- This script DOES NOT drop or truncate any table. Conversations, messages,
-- handoff events, FAQs, usage metrics, legacy columns, and IDs remain intact.
--
-- Set this marker to `disabled` so the support API stays off while the tables
-- are preserved. The compatibility upgrader deliberately does not recreate or
-- alter tables while disabled. To re-enable later, set ai_support_schema_v to
-- `1` (or any non-disabled older version), then load the application once; the
-- idempotent v4 upgrade will verify/add missing columns and restrictive history foreign keys without deleting rows.

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('support_chat_enabled', '0')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('support_chat_ai_enabled', '0')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_support_schema_v', 'disabled')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
