-- Expiry reminders (Domain\Alert\ExpiryReminders): which reminders were sent
-- (one per line, expiry date and threshold), the settings, and cron:reminders
-- daily at 09:00 on MAIN. A line keeps its subscriber's Telegram username, and
-- telegram_chats the chat each username opened with the reminders' bot
-- (Domain\Alert\TelegramLinks).
CREATE TABLE IF NOT EXISTS `line_reminders` (
  `line_id` int(11) NOT NULL,
  `exp_date` int(11) NOT NULL,
  `days` int(11) NOT NULL,
  `sent` int(11) DEFAULT NULL,
  PRIMARY KEY (`line_id`, `exp_date`, `days`),
  KEY `exp_date` (`exp_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `reminders_days` varchar(64) COLLATE utf8_unicode_ci DEFAULT '7,3,1',
      ADD COLUMN IF NOT EXISTS `reminders_email` tinyint(1) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `reminders_mag` tinyint(1) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `reminders_webhook` tinyint(1) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `reminders_message` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
      ADD COLUMN IF NOT EXISTS `reminders_telegram` int(11) DEFAULT '0';

ALTER TABLE `lines` ADD COLUMN IF NOT EXISTS `telegram` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL AFTER `contact`;

CREATE TABLE IF NOT EXISTS `telegram_chats` (
  `username` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `chat_id` varchar(32) COLLATE utf8_unicode_ci NOT NULL,
  `updated` int(11) DEFAULT NULL,
  PRIMARY KEY (`username`),
  KEY `chat_id` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) SELECT 'reminders', '0 9 * * *', 1, 'main' WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'reminders');
