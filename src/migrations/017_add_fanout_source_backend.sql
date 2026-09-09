-- xc_fanout: how a NON-mp2t source is turned into MPEG-TS.
--   auto   — convert in-process where the daemon's native reader can, ffmpeg otherwise
--   ffmpeg — always spawn ffmpeg (the pre-0.12 behaviour, kept as the kill-switch)
--   native — native only, no fallback; for checking what is eligible on a node
-- Default/values mirror the daemon schema (XC_VM_Fanout internal/config/config.go).
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `fanout_source_backend` varchar(8) DEFAULT 'auto' AFTER `fanout_idle_buffer_ratio`;
