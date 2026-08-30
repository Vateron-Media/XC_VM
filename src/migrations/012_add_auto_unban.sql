-- Auto-unban: expire automatic IP bans (flood/bruteforce) after a configurable
-- duration. `auto_unban_ip` toggles it; `ban_duration_value` + `ban_duration_unit`
-- (minutes/hours/days) set the lifetime. The prune runs in RootSignalsCronJob and
-- the existing blocked_ips->iptables sync propagates removals to the nodes; manual
-- admin bans are never touched. These columns previously existed only via a manual
-- ALTER on some servers — this makes them part of the schema.
ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `auto_unban_ip` TINYINT(1) DEFAULT 0 AFTER `auth_flood_sleep`,
  ADD COLUMN IF NOT EXISTS `ban_duration_value` INT(11) DEFAULT 24 AFTER `auto_unban_ip`,
  ADD COLUMN IF NOT EXISTS `ban_duration_unit` VARCHAR(10) DEFAULT 'hours' AFTER `ban_duration_value`;
