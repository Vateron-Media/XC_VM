-- Weekly check for module update availability (sources: git / url / platform).
-- Regenerated into the xc_vm crontab as `console.php cron:module_updates`
-- (the crontab table maps `filename` → `cron:<filename>`). Read-only: it only
-- records `available_version` in config/modules.php, never downloads/applies files.
-- Runs Tuesdays 04:30 (just after the MaxMind GeoIP job at 04:00).
INSERT INTO `crontab` (`filename`, `time`, `enabled`)
	SELECT 'module_updates', '30 4 * * 2', 1
	WHERE NOT EXISTS (SELECT 1 FROM `crontab` WHERE `filename` = 'module_updates');
