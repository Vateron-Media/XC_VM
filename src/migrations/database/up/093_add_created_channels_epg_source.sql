INSERT INTO `epg` (`epg_name`, `epg_file`, `days_keep`) SELECT 'Created Channels', '', 7 WHERE NOT EXISTS (SELECT 1 FROM `epg` WHERE `epg_name` = 'Created Channels');
