-- xc_fanout: run live streams under the daemon's encoder supervisor instead of a
-- per-stream PHP watchdog (console.php monitor). 1 = hand each stream over when it
-- starts; the PHP monitor remains the fallback for a daemon that cannot be reached
-- and for delay / created-channel streams. 0 = the PHP monitor for everything.
-- Written to the daemon's config.json as `supervise` (XC_VM_Fanout ADR 0002).
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_supervise` tinyint(1) DEFAULT '1' AFTER `fanout_source_backend`;
