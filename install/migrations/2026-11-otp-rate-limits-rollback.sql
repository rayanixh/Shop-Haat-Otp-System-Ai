-- ShopHaat OTP rate limiting — rollback for schema v5 (2026-11)
--
-- What this does NOT do: it never touches users, orders, payments, admins,
-- settings or otp_verifications rows. Verification codes and their history are
-- left exactly as they are, so a rollback cannot lock anybody out or destroy
-- an audit trail.
--
-- Dropping the two rate-limit tables returns request throttling to the legacy
-- behaviour (counts of otp_verifications rows, which a failed SMS delivery
-- removes). That is the risk this migration exists to fix, so only roll back
-- if the new tables are the cause of a production incident.
--
-- Order of operations when rolling back:
--   1. Take a database backup (Admin → Backups, or phpMyAdmin → Export).
--   2. Run the DROP statements below.
--   3. Set otp_rate_schema_version to 0 so the application can recreate the
--      tables if you re-apply the fix:
--        UPDATE settings SET setting_value = '0' WHERE setting_key = 'otp_rate_schema_version';
--        DELETE FROM settings WHERE setting_key = 'otp_rate_backfilled';
--      (Deleting the backfill marker lets a later re-upgrade copy the current
--      otp_verifications history again; it never duplicates because the marker
--      gates it, so also delete the leftover rows if you re-run it:
--        TRUNCATE TABLE otp_rate_limits;   -- only if the tables still exist)
--   4. Leaving otp_schema_version at 5 is safe: the upgrader is idempotent and
--      will only recreate the missing tables on the next request.
--
-- To fully reverse the code changes, deploy the previous release; the new code
-- tolerates missing rate-limit tables by failing closed with a clear "try
-- again shortly" message rather than silently disabling limits.

DROP TABLE IF EXISTS `otp_rate_locks`;
DROP TABLE IF EXISTS `otp_rate_limits`;

UPDATE `settings` SET `setting_value` = '0' WHERE `setting_key` = 'otp_rate_schema_version';
DELETE FROM `settings` WHERE `setting_key` = 'otp_rate_backfilled';
