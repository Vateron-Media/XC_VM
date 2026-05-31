-- Add `sent` flag to panel_logs to mark logs that were already sent to the central API
ALTER TABLE `panel_logs`
  ADD COLUMN `sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `date`;
