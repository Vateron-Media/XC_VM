-- Record the panel version each error occurred on. It is stamped at error time
-- (Logger::log), carried through panel_logs (ErrorsCronJob) and the report payload
-- (DiagnosticsService), so a log parsed/sent after an update stays attributed to
-- the version it actually happened on — not the current (post-update) one.
ALTER TABLE `panel_logs` ADD COLUMN IF NOT EXISTS `version` VARCHAR(30) DEFAULT NULL;
