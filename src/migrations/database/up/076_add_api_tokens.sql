-- Named API tokens (Core\Auth\ApiTokens): several per admin or reseller,
-- each with a scope, an optional address list and expiry, stored as a SHA-256
-- hash. `api_legacy_keys` keeps the old single `users.api_key` working until an
-- operator turns it off.
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `hash` char(64) COLLATE utf8_unicode_ci NOT NULL,
  `prefix` varchar(16) COLLATE utf8_unicode_ci NOT NULL,
  `scope` varchar(16) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'full',
  `allow_sql` tinyint(1) NOT NULL DEFAULT '0',
  `ips` text COLLATE utf8_unicode_ci,
  `expires` int(11) DEFAULT NULL,
  `last_used` int(11) DEFAULT NULL,
  `last_ip` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `created` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `api_legacy_keys` tinyint(1) DEFAULT '1' AFTER `api_ips`;
