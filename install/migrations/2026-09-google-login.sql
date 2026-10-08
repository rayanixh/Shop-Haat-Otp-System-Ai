-- Google OAuth 2.0 login — reference migration.
-- NOTE: This is applied AUTOMATICALLY on the first page load after deploy
-- (install/schema.php, SH_OTP_SCHEMA_VERSION = 4). Run manually only if you
-- prefer to migrate by hand; every statement is safe to re-run.

ALTER TABLE `users` ADD COLUMN `auth_provider` VARCHAR(20) NOT NULL DEFAULT 'email';
ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(64) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `avatar_source` VARCHAR(20) DEFAULT NULL;
ALTER TABLE `users` ADD UNIQUE KEY `uq_users_google_id` (`google_id`);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('google_login_enabled', '0'),
  ('google_client_id', ''),
  ('google_client_secret', '');
