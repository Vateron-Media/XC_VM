-- Add delete_missing flag to watch_folders
ALTER TABLE `watch_folders` ADD COLUMN `delete_missing` tinyint(1) DEFAULT 0 AFTER `active`;
