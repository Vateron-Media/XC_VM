INSERT INTO `crontab` (`filename`, `time`, `enabled`) SELECT 'proxy', '0 5 * * *', 1 WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'proxy');
