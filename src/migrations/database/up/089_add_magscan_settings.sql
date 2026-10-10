-- MAGSCAN Settings: the page's three lists (whitelisted and blacklisted MAC
-- addresses, whitelisted addresses) as JSON, read by the guard that counts
-- MAC guesses. The page had nowhere to save them.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `magscan_settings` mediumtext COLLATE utf8_unicode_ci;
