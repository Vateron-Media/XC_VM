-- telegram module — teardown
-- Drops tables owned by the module on uninstall.
DROP TABLE IF EXISTS `telegram_logs`;
DROP TABLE IF EXISTS `telegram_bots`;
