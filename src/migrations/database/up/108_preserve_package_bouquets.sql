ALTER TABLE `users_packages`
    ADD COLUMN IF NOT EXISTS `preserve_bouquets_on_renewal` TINYINT(4) NOT NULL DEFAULT 0 AFTER `check_compatible`;
