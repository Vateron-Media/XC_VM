ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `can_set_forced_country` tinyint(1) NOT NULL DEFAULT '0' AFTER `allowed_bouquets`;
