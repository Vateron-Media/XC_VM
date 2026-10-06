-- Alerts (Domain\Alert): where they go (alert_channels, secrets included:
-- MAIN only, never in settings), the rules, each subject's state, and every
-- message sent. cron:alerts evaluates them every minute on MAIN.
CREATE TABLE IF NOT EXISTS `alert_channels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(16) COLLATE utf8_unicode_ci NOT NULL,
  `name` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `config` text COLLATE utf8_unicode_ci,
  `created` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_rules` (
  `rule` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `threshold` int(11) NOT NULL DEFAULT '0',
  `minutes` int(11) NOT NULL DEFAULT '0',
  `channels` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`rule`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_state` (
  `rule` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `subject` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `label` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '0',
  `since` int(11) NOT NULL DEFAULT '0',
  `notified` tinyint(1) NOT NULL DEFAULT '0',
  `sent` tinyint(1) NOT NULL DEFAULT '0',
  `fired_at` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`rule`, `subject`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` int(11) NOT NULL,
  `rule` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `kind` varchar(16) COLLATE utf8_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `text` text COLLATE utf8_unicode_ci,
  `results` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) SELECT 'alerts', '* * * * *', 1, 'main' WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'alerts');
