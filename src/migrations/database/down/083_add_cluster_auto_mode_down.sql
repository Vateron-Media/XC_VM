-- Reverse 083_add_cluster_auto_mode_down.sql.
ALTER TABLE `settings` DROP COLUMN IF EXISTS `cluster_auto_mode_down_min`;
