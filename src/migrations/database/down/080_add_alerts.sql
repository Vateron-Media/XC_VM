-- Reverse 080_add_alerts.sql.
DELETE FROM `crontab` WHERE `filename` = 'alerts';
DROP TABLE IF EXISTS `alert_channels`;
DROP TABLE IF EXISTS `alert_rules`;
DROP TABLE IF EXISTS `alert_state`;
DROP TABLE IF EXISTS `alert_log`;
