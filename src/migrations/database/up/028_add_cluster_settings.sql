-- Cluster API settings (MAIN <-> LB API plan, section 11). All inert until
-- cluster_api_enabled = 1 and `cluster:init` has run. ClusterSettings::normalize()
-- clamps every value on save; these are the defaults.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `cluster_api_enabled` tinyint(1) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `cluster_api_port` int(11) DEFAULT '0',
      ADD COLUMN IF NOT EXISTS `cluster_main_host` varchar(255) DEFAULT '',
      ADD COLUMN IF NOT EXISTS `cluster_transport` varchar(16) DEFAULT 'auto',
      ADD COLUMN IF NOT EXISTS `lb_token_rotation_min` int(11) DEFAULT '60',
      ADD COLUMN IF NOT EXISTS `lb_revocation_mode` varchar(16) DEFAULT 'graceful',
      ADD COLUMN IF NOT EXISTS `lb_partition_tolerance_h` int(11) DEFAULT '12',
      ADD COLUMN IF NOT EXISTS `lb_fence_drain_min` int(11) DEFAULT '10',
      ADD COLUMN IF NOT EXISTS `lb_telemetry_interval_sec` int(11) DEFAULT '2',
      ADD COLUMN IF NOT EXISTS `cluster_offline_after_sec` int(11) DEFAULT '30',
      ADD COLUMN IF NOT EXISTS `cluster_orphan_conn_ttl_sec` int(11) DEFAULT '120',
      ADD COLUMN IF NOT EXISTS `lb_offline_admission` varchar(8) DEFAULT 'local',
      ADD COLUMN IF NOT EXISTS `cluster_kill_on_line_disable` tinyint(1) DEFAULT '1',
      ADD COLUMN IF NOT EXISTS `cluster_ingest_concurrency` int(11) DEFAULT '6',
      ADD COLUMN IF NOT EXISTS `lb_new_node_mode` varchar(16) DEFAULT 'legacy',
      ADD COLUMN IF NOT EXISTS `lb_scan_roots` varchar(1024) DEFAULT '["/home/xc_vm/content","/mnt","/media"]',
      ADD COLUMN IF NOT EXISTS `servers_stats_retention_days` int(11) DEFAULT '30',
      ADD COLUMN IF NOT EXISTS `cluster_audit_retention_days` int(11) DEFAULT '30',
      ADD COLUMN IF NOT EXISTS `cluster_agent_upgrade_parallel` int(11) DEFAULT '1';
