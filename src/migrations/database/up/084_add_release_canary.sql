-- The fleet canary for the binaries every server takes from GitHub (xc_fanout,
-- xc_agent): with a canary set, the others take no release newer than the pin
-- MAIN raises once the canary has run it long enough (ReleaseCanary). 0, the
-- default, is off: every server takes the newest.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `lb_binary_canary_server` int(11) DEFAULT '0';
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `lb_binary_canary_hours` int(11) DEFAULT '24';
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `lb_release_pin` varchar(32) DEFAULT '';
