-- Split the single global `update_channel` setting into per-repository release
-- channels: MAIN (panel), BIN (compiled binaries) and FANOUT (xc_fanout daemon).
-- The GeoLite/ASN data repo (UPDATE) and the proxy archive repo (PROXY) have no
-- channel of their own and follow the MAIN channel.
--
-- Existing installs carry their previous selection into all three columns; the
-- legacy 'unstable' value normalizes to 'beta'. The obsolete `update_channel`
-- column is then dropped. `update_channel` is still present at migration time
-- (base database.sql), so the copy below always resolves.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `update_channel_main` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable' AFTER `live_streaming_pass`,
      ADD COLUMN IF NOT EXISTS `update_channel_bin` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable' AFTER `update_channel_main`,
      ADD COLUMN IF NOT EXISTS `update_channel_fanout` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable' AFTER `update_channel_bin`;
UPDATE `settings` SET
      `update_channel_main` = IF(`update_channel` = 'unstable', 'beta', COALESCE(NULLIF(`update_channel`, ''), 'stable')),
      `update_channel_bin` = `update_channel_main`,
      `update_channel_fanout` = `update_channel_main`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `update_channel`;
