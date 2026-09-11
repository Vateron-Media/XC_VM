CREATE TABLE IF NOT EXISTS `category_templates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT UNSIGNED NOT NULL COMMENT 'Owner reseller or admin from users table',
  `name` VARCHAR(150) NOT NULL COMMENT 'Template name',
  `is_system` TINYINT(1) DEFAULT 0 COMMENT '1 = System template approved by admin',
  `is_shared` TINYINT(1) DEFAULT 0 COMMENT '1 = Shared with sub-resellers',
  `live_count` INT UNSIGNED DEFAULT 0,
  `vod_count` INT UNSIGNED DEFAULT 0,
  `series_count` INT UNSIGNED DEFAULT 0,
  `radio_count` INT UNSIGNED DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  INDEX `idx_owner` (`owner_id`),
  INDEX `idx_is_system` (`is_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `category_template_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `template_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL COMMENT 'Category id from streams_categories',
  `category_type` ENUM('live', 'movie', 'series', 'radio') NOT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Category display sort order',
  `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = visible, 0 = hidden',
  `custom_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Custom alias name if set',
  INDEX `idx_template` (`template_id`),
  INDEX `idx_category` (`category_id`),
  CONSTRAINT `fk_template_items` FOREIGN KEY (`template_id`) REFERENCES `category_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
