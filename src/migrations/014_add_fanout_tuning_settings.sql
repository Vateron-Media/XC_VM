-- Panel-owned xc_fanout daemon tuning knobs (previously daemon-only in config.json).
-- Defaults/ranges mirror the daemon schema (XC_VM_Fanout internal/config/config.go).
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_hls_window` int(11) DEFAULT 6 AFTER `shared_mount_prefixes`,
      ADD COLUMN IF NOT EXISTS `fanout_grace_sec` int(11) DEFAULT 10 AFTER `fanout_hls_window`,
      ADD COLUMN IF NOT EXISTS `fanout_write_timeout_sec` int(11) DEFAULT 15 AFTER `fanout_grace_sec`,
      ADD COLUMN IF NOT EXISTS `fanout_chunk_bytes` int(11) DEFAULT 12032 AFTER `fanout_write_timeout_sec`,
      ADD COLUMN IF NOT EXISTS `fanout_max_gop_bytes` int(11) DEFAULT 10528000 AFTER `fanout_chunk_bytes`,
      ADD COLUMN IF NOT EXISTS `fanout_source_insecure` tinyint(1) DEFAULT 1 AFTER `fanout_max_gop_bytes`,
      ADD COLUMN IF NOT EXISTS `fanout_default_prebuffer_sec` int(11) DEFAULT 0 AFTER `fanout_source_insecure`,
      ADD COLUMN IF NOT EXISTS `fanout_idle_buffer_grace_sec` int(11) DEFAULT 30 AFTER `fanout_default_prebuffer_sec`,
      ADD COLUMN IF NOT EXISTS `fanout_idle_buffer_ratio` decimal(3,2) DEFAULT 0.50 AFTER `fanout_idle_buffer_grace_sec`;
