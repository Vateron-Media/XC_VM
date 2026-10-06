-- Reverse 081_add_expiry_reminders.sql.
DELETE FROM `crontab` WHERE `filename` = 'reminders';
DROP TABLE IF EXISTS `line_reminders`;
ALTER TABLE `settings`
      DROP COLUMN IF EXISTS `reminders_days`,
      DROP COLUMN IF EXISTS `reminders_email`,
      DROP COLUMN IF EXISTS `reminders_mag`,
      DROP COLUMN IF EXISTS `reminders_webhook`,
      DROP COLUMN IF EXISTS `reminders_message`,
      DROP COLUMN IF EXISTS `reminders_telegram`;
ALTER TABLE `lines` DROP COLUMN IF EXISTS `telegram`;
DROP TABLE IF EXISTS `telegram_chats`;
