UPDATE `crontab` SET `filename` = REPLACE(`filename`, '.php', '') WHERE `filename` LIKE '%.php';
