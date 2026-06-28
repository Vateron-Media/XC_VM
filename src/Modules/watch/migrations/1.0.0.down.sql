-- watch module schema — version 1.0.0 (down)
-- Reverses 1.0.0.up.sql. Plex is uninstalled first (it depends on watch),
-- so its data is already gone by the time these run.
DROP TABLE IF EXISTS `watch_refresh`;
DROP TABLE IF EXISTS `watch_logs`;
DROP TABLE IF EXISTS `watch_folders`;
DROP TABLE IF EXISTS `watch_categories`;
