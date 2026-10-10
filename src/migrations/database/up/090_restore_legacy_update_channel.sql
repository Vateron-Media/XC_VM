-- Stable 2.3.9 and the betas up to 2.4.x pick the release a server updates to
-- from `settings`.`update_channel` ('stable' or 'unstable'; anything else,
-- a missing column included, is read as 'stable'). 016 split the setting per
-- repository and dropped the column, so a load balancer still on such a
-- release under an updated MAIN looked at stable releases only and answered
-- "Already up to date" for ever. The column is back as a copy of the panel's
-- channel in the values those releases know; a settings save keeps it in step
-- (UpdateChannels::withLegacy()).
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `update_channel` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable' AFTER `update_channel_fanout`;
UPDATE `settings` SET `update_channel` = IF(`update_channel_main` IN ('beta', 'dev', 'unstable'), 'unstable', 'stable');
