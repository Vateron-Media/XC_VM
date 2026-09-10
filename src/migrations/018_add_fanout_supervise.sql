-- xc_fanout: let the daemon run and watch this node's stream encoders instead of
-- a per-stream PHP watchdog (console.php monitor). See the daemon's
-- docs/adr/0002-monitor-in-daemon.md.
--
-- Off for every existing install. Turning it on is a two-sided opt-in: the panel
-- hands streams over, and the node's daemon must itself be configured to accept
-- them (the `supervise` key in its config.json, which FanoutConfig writes from
-- this setting). Turning it off is the rollback -- MonitorCommand resumes, since
-- its stand-down check asks the daemon and an unreachable one answers "no".
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_supervise` tinyint(1) DEFAULT 0 AFTER `fanout_source_backend`;
