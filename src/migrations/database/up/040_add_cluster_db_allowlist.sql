-- Default-on firewall allowlist for MAIN's MariaDB (3306) and Redis (6379): on,
-- only MAIN, the LBs and proxies not yet in cluster mode 2, and the extra
-- IPs/CIDRs below may connect (DbAllowlist, applied by the root signals cron).
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_db_allowlist` tinyint(1) DEFAULT '1';
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `cluster_db_allowlist_extra` varchar(1024) DEFAULT '';
