ALTER TABLE `epg_data` ADD INDEX IF NOT EXISTS `epg_merge_lookup` (`epg_id`, `channel_id`, `end`, `start`);
