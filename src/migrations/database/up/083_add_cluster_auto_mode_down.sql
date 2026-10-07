-- Opt-in: a load balancer in mode 2 that says for this many minutes that it no
-- longer reads its streams on itself goes back to mode 1 (ClusterAdmin::
-- autoModeDown, cron:cluster). 0, the default, is off.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_auto_mode_down_min` smallint(5) unsigned DEFAULT '0';
