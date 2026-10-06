-- Reverse 082_add_offsite_backups.sql.
DELETE FROM `crontab` WHERE `filename` = 'backup_verify';
DROP TABLE IF EXISTS `backup_targets`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `backup_verify`;
