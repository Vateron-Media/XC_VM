-- How long System Logs (`mysql_syslog`) are kept, in seconds; 0 keeps them
-- for ever, as the other Keep Logs For settings. cron:cleanup prunes by it.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `keep_syslog` int(11) DEFAULT '0' AFTER `keep_restarts`;
