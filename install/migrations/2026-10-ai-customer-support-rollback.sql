-- ShopHaat AI Customer Support rollback.
--
-- This removes only the support-owned tables introduced by
-- 2026-10-ai-customer-support.sql. It permanently deletes saved conversations,
-- messages, FAQ edits, handoff events and AI usage metrics. Export those tables
-- first if you need to retain customer-support history. It does NOT remove or
-- modify ai_providers, ai_models, ai_settings, products, orders, users, payments,
-- carts or other e-commerce data.
--
-- Application settings are stored as support_chat_* rows in the existing
-- settings / ai_settings tables. Keep the configuration but explicitly switch
-- chat and AI off. Remove the remaining support_chat_* rows manually only if
-- the owner explicitly wants to discard the settings. The schema marker is
-- set to `disabled` so the still-deployed feature code will not recreate tables
-- on the next page request; re-running the forward migration clears it during
-- the first safe schema check.

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `support_ai_usage`;
DROP TABLE IF EXISTS `support_handoff_events`;
DROP TABLE IF EXISTS `support_messages`;
DROP TABLE IF EXISTS `support_rate_limits`;
DROP TABLE IF EXISTS `support_faqs`;
DROP TABLE IF EXISTS `support_conversations`;
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('support_chat_enabled', '0')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('support_chat_ai_enabled', '0')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_support_schema_v', 'disabled')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('ai_support_faq_seeded_v', '0')
  ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
SET FOREIGN_KEY_CHECKS = 1;
