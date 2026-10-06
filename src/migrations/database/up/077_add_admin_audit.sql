-- The admin action trail (Core\Audit\AdminAudit): who changed what from the
-- admin panel and the Admin API, with the outcome. Kept by backups, and not
-- clearable from the panel.
CREATE TABLE IF NOT EXISTS `admin_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `date` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `source` varchar(8) COLLATE utf8_unicode_ci NOT NULL,
  `action` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `result` tinyint(1) DEFAULT NULL,
  `detail` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `date` (`date`),
  KEY `user_id` (`user_id`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
