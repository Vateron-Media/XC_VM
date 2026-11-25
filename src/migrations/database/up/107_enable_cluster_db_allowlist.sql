-- Migration 040 may already be recorded with its former opt-in default.
ALTER TABLE `settings` MODIFY COLUMN `cluster_db_allowlist` tinyint(1) DEFAULT '1';
UPDATE `settings` SET `cluster_db_allowlist` = '1';
