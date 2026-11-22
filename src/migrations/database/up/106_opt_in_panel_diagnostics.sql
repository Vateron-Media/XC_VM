ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `diagnostics_panel_logs` tinyint(1) NOT NULL DEFAULT '1';
