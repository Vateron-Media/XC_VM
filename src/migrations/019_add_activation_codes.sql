-- Smart Activation Codes (core feature): prepaid voucher codes that a
-- subscriber redeems to provision a line. Generated/managed from admin and
-- reseller, redeemed through the player activation portal.
--   status: 1=Ready/Stock, 2=Active/Bound, 0=Disabled
-- Kept in sync with bin/install/database.sql (fresh-install baseline).
CREATE TABLE IF NOT EXISTS `activation_codes` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `activation_code` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
  `batch_name` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `subscriber_id` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Ready/Stock, 2=Active/Bound, 0=Disabled',
  `created_by` int(11) NOT NULL DEFAULT 0,
  `package_id` int(11) DEFAULT NULL,
  `bouquets` mediumtext COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_adult` tinyint(1) NOT NULL DEFAULT 0,
  `is_trial` tinyint(1) NOT NULL DEFAULT 0,
  `purchase_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `dns_base` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `forced_country` varchar(3) COLLATE utf8_unicode_ci DEFAULT NULL,
  `max_connections` int(11) NOT NULL DEFAULT 1,
  `mac` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `device_id` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
  `activated_at` int(11) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_activation_code` (`activation_code`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_subscriber_id` (`subscriber_id`),
  KEY `idx_batch_name` (`batch_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Flag lines auto-provisioned by an activation code, so the normal Lines
-- list/filters (admin + reseller) exclude them.
ALTER TABLE `lines`
      ADD COLUMN IF NOT EXISTS `is_activecode` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `lines`
      ADD KEY IF NOT EXISTS `idx_is_activecode` (`is_activecode`);
