-- Two-factor sign-in (TOTP) for admins and resellers. An account's secret,
-- the hashes of its unused recovery codes and the last time step it signed in
-- with live apart from `users`, which pages and APIs read whole. A group can
-- make two factors compulsory for its members.
CREATE TABLE IF NOT EXISTS `users_2fa` (
  `user_id` int(11) NOT NULL,
  `secret` varchar(64) COLLATE utf8_unicode_ci NOT NULL,
  `recovery` text COLLATE utf8_unicode_ci,
  `last_step` int(11) NOT NULL DEFAULT '0',
  `created` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

ALTER TABLE `users_groups`
      ADD COLUMN IF NOT EXISTS `require_2fa` tinyint(1) DEFAULT '0' AFTER `allow_change_bouquets`;
