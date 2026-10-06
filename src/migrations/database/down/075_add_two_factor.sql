-- Reverse 075_add_two_factor.sql.
DROP TABLE IF EXISTS `users_2fa`;
ALTER TABLE `users_groups` DROP COLUMN IF EXISTS `require_2fa`;
