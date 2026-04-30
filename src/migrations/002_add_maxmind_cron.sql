ALTER TABLE `settings`
	ADD COLUMN IF NOT EXISTS `maxmind_account_id`  VARCHAR(20)  DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `maxmind_license_key` VARCHAR(64)  DEFAULT NULL,
	ADD COLUMN IF NOT EXISTS `maxmind_editions`    MEDIUMTEXT   DEFAULT NULL;

INSERT INTO `crontab` (`filename`, `time`, `enabled`) SELECT 'maxmind', '0 4 * * 2', 1 WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'maxmind');
