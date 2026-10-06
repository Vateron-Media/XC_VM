-- Reverse 078_add_maintenance_mode.sql.
ALTER TABLE `settings`
      DROP COLUMN IF EXISTS `maintenance_mode`,
      DROP COLUMN IF EXISTS `maintenance_until`,
      DROP COLUMN IF EXISTS `maintenance_message`;
