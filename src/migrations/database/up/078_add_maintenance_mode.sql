-- Maintenance mode (Core\Config\Maintenance): while on, the client APIs answer
-- a maintenance message and resellers are refused; it ends by itself at
-- `maintenance_until` (a Unix time; 0: no end).
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `maintenance_mode` tinyint(1) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `maintenance_until` int(11) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `maintenance_message` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL;
