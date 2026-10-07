-- Reverse 084_add_release_canary.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `lb_release_pin`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `lb_binary_canary_hours`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `lb_binary_canary_server`;
