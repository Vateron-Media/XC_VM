-- telegram module — master schema
-- Full CREATE for a fresh install. Owns: telegram_bots, telegram_logs.

CREATE TABLE IF NOT EXISTS `telegram_bots` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `bot_token` VARCHAR(255) NOT NULL,
  `bot_username` VARCHAR(100) DEFAULT NULL,
  `chat_id` VARCHAR(100) NOT NULL,
  `chat_title` VARCHAR(255) DEFAULT NULL,
  `content_types` VARCHAR(255) NOT NULL DEFAULT 'movies',
  `categories` TEXT DEFAULT NULL,
  `image_type` ENUM('poster','backdrop','none') NOT NULL DEFAULT 'poster',
  `notify_on_movie_complete` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_on_episode` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_on_live` TINYINT(1) NOT NULL DEFAULT 0,
  `custom_template` TEXT DEFAULT NULL,
  `silent_notification` TINYINT(1) NOT NULL DEFAULT 0,
  `include_button` TINYINT(1) NOT NULL DEFAULT 0,
  `button_text` VARCHAR(100) DEFAULT NULL,
  `button_url` VARCHAR(500) DEFAULT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `total_sent` INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at` INT(10) UNSIGNED DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_telegram_bots_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_logs` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` INT(10) UNSIGNED NOT NULL,
  `stream_id` INT(10) UNSIGNED NOT NULL,
  `content_type` ENUM('movie','episode','live','test') NOT NULL,
  `chat_id` VARCHAR(100) NOT NULL,
  `message_id` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('sent','failed') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `sent_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_telegram_logs_bot_id` (`bot_id`),
  INDEX `idx_telegram_logs_sent_at` (`sent_at`),
  CONSTRAINT `fk_telegram_logs_bot` FOREIGN KEY (`bot_id`) REFERENCES `telegram_bots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
