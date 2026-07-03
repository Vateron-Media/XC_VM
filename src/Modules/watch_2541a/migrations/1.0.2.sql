-- watch module — version delta 1.0.1 (forward-only)
-- Upgrades panels installed on an older schema: adds the delete_missing flag to
-- watch_folders. Fresh installs get this column from database.sql (master) and
-- never run this file. Idempotent, so it is a no-op where the column already
-- exists (e.g. from the former core migration 004_add_watch_delete_missing.sql).
ALTER TABLE `watch_folders`
	ADD COLUMN IF NOT EXISTS `delete_missing` tinyint(1) DEFAULT 0 AFTER `active`;
