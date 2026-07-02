-- watch module — teardown (single deletion file)
-- Drops every table the module owns. Runs on uninstall. plex is uninstalled
-- first (it depends on watch), so its data is already gone by the time these run.
DROP TABLE IF EXISTS `watch_refresh`;
DROP TABLE IF EXISTS `watch_logs`;
DROP TABLE IF EXISTS `watch_folders`;
DROP TABLE IF EXISTS `watch_categories`;
