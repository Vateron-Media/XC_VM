-- Off-site backups (Domain\Backup): the S3 and SFTP targets cron:backups copies
-- each backup and recovery bundle to (credentials here, MAIN only, never in
-- settings), the last weekly restore test's result, and cron:backup_verify on
-- Sundays at 05:00 on MAIN.
CREATE TABLE IF NOT EXISTS `backup_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(16) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `keep` int(11) NOT NULL DEFAULT '0',
  `config` text COLLATE utf8_unicode_ci,
  `created` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `backup_verify` text COLLATE utf8_unicode_ci;

INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) SELECT 'backup_verify', '0 5 * * 0', 1, 'main' WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'backup_verify');
