SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table structure for table `access_codes`
--

CREATE TABLE IF NOT EXISTS `access_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `type` tinyint(4) DEFAULT '0',
  `enabled` tinyint(4) DEFAULT '0',
  `groups` mediumtext COLLATE utf8_unicode_ci,
  `whitelist` mediumtext COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_asns`
--

CREATE TABLE IF NOT EXISTS `blocked_asns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asn` int(5) DEFAULT '0',
  `isp` varchar(256) DEFAULT NULL,
  `domain` varchar(256) DEFAULT NULL,
  `country` varchar(16) DEFAULT NULL,
  `num_ips` int(16) DEFAULT '0',
  `type` varchar(64) DEFAULT NULL,
  `blocked` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `asn` (`asn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `blocked_asns`
--

CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(39) COLLATE utf8_unicode_ci DEFAULT NULL,
  `notes` mediumtext COLLATE utf8_unicode_ci,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_2` (`ip`),
  UNIQUE KEY `ip_3` (`ip`),
  KEY `ip` (`ip`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_isps`
--

CREATE TABLE IF NOT EXISTS `blocked_isps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `isp` mediumtext,
  `blocked` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `blocked_uas`
--

CREATE TABLE IF NOT EXISTS `blocked_uas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `exact_match` int(11) DEFAULT '0',
  `attempts_blocked` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `exact_match` (`exact_match`),
  KEY `user_agent` (`user_agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bouquets`
--

CREATE TABLE IF NOT EXISTS `bouquets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bouquet_name` mediumtext COLLATE utf8_unicode_ci,
  `bouquet_channels` mediumtext COLLATE utf8_unicode_ci,
  `bouquet_movies` mediumtext COLLATE utf8_unicode_ci,
  `bouquet_radios` mediumtext COLLATE utf8_unicode_ci,
  `bouquet_series` mediumtext COLLATE utf8_unicode_ci,
  `bouquet_order` int(16) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `crontab`
--

CREATE TABLE IF NOT EXISTS `crontab` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `time` varchar(128) COLLATE utf8_unicode_ci DEFAULT '* * * * *',
  `enabled` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `enabled` (`enabled`),
  KEY `filename` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `crontab`
--

INSERT INTO `crontab` (`id`, `filename`, `time`, `enabled`) VALUES
(2, 'lines_logs', '* * * * *', 1),
(3, 'epg', '0 0 * * *', 1),
(5, 'streams', '* * * * *', 1),
(6, 'activity', '* * * * *', 1),
(7, 'servers', '* * * * *', 1),
(8, 'cache', '* * * * *', 1),
(9, 'stats', '0 * * * *', 1),
(10, 'errors', '* * * * *', 1),
(11, 'tmdb', '0 * * * *', 1),
(12, 'tmp', '* * * * *', 1),
(13, 'users', '* * * * *', 1),
(14, 'vod', '* * * * *', 1),
(15, 'series', '* * * * *', 1),
(16, 'watch', '*/5 * * * *', 1),
(17, 'backups', '* * * * *', 1),
(18, 'streams_logs', '* * * * *', 1),
(19, 'update', '0 0 * * *', 1),
(20, 'cleanup', '0 * * * *', 1),
(22, 'certbot', '0 0 * * *', 1),
(24, 'cache_engine', '*/5 * * * *', 1),
(25, 'providers', '0 * * * *', 1),
(26, 'tmdb_popular', '0 * * * *', 1),
(27, 'plex', '*/5 * * * *', 1),
(28, 'maxmind', '0 4 * * 2', 1),
(29, 'proxy', '0 5 * * *', 1);

-- --------------------------------------------------------

--
-- Table structure for table `detect_restream`
--

CREATE TABLE IF NOT EXISTS `detect_restream` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `blocked` tinyint(1) DEFAULT NULL,
  `ports_open` mediumtext COLLATE utf8_unicode_ci,
  `time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `detect_restream_logs`
--

CREATE TABLE IF NOT EXISTS `detect_restream_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `time` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enigma2_actions`
--

CREATE TABLE IF NOT EXISTS `enigma2_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) DEFAULT NULL,
  `type` mediumtext,
  `key` mediumtext,
  `command` mediumtext,
  `command2` mediumtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `enigma2_devices`
--

CREATE TABLE IF NOT EXISTS `enigma2_devices` (
  `device_id` int(12) NOT NULL AUTO_INCREMENT,
  `mac` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `modem_mac` varchar(255) DEFAULT NULL,
  `local_ip` varchar(255) DEFAULT NULL,
  `public_ip` varchar(255) DEFAULT NULL,
  `key_auth` varchar(255) DEFAULT NULL,
  `enigma_version` varchar(255) DEFAULT NULL,
  `cpu` varchar(255) DEFAULT NULL,
  `version` varchar(255) DEFAULT NULL,
  `lversion` mediumtext,
  `token` varchar(32) DEFAULT NULL,
  `last_updated` int(11) DEFAULT NULL,
  `watchdog_timeout` int(11) DEFAULT NULL,
  `lock_device` tinyint(4) DEFAULT '0',
  `telnet_enable` tinyint(4) DEFAULT '1',
  `ftp_enable` tinyint(4) DEFAULT '1',
  `ssh_enable` tinyint(4) DEFAULT '1',
  `dns` varchar(255) DEFAULT NULL,
  `original_mac` varchar(255) DEFAULT NULL,
  `rc` tinyint(4) DEFAULT '1',
  `mac_filter` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  KEY `mac` (`mac`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `epg`
--

CREATE TABLE IF NOT EXISTS `epg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `epg_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `epg_file` varchar(300) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_updated` int(11) DEFAULT NULL,
  `days_keep` int(11) DEFAULT '7',
  `data` longtext COLLATE utf8_unicode_ci,
  `offset` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `epg_channels`
--

CREATE TABLE IF NOT EXISTS `epg_channels` (
  `id` int(8) NOT NULL AUTO_INCREMENT,
  `epg_id` int(8) DEFAULT NULL,
  `channel_id` varchar(64) DEFAULT NULL,
  `name` varchar(256) DEFAULT NULL,
  `langs` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `epg_data`
--

CREATE TABLE IF NOT EXISTS `epg_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `epg_id` int(11) DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `lang` varchar(10) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `start` int(11) DEFAULT NULL,
  `end` int(11) DEFAULT NULL,
  `description` mediumtext CHARACTER SET utf8 COLLATE utf8_unicode_ci,
  `channel_id` varchar(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `epg_id` (`epg_id`),
  KEY `start` (`start`),
  KEY `end` (`end`),
  KEY `lang` (`lang`),
  KEY `channel_id` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `hmac_keys`
--

CREATE TABLE IF NOT EXISTS `hmac_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `notes` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `enabled` tinyint(4) DEFAULT '1',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lines`
--

CREATE TABLE IF NOT EXISTS `lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `username` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `exp_date` int(11) DEFAULT NULL,
  `admin_enabled` int(11) DEFAULT '1',
  `enabled` int(11) DEFAULT '1',
  `admin_notes` text COLLATE utf8_unicode_ci,
  `reseller_notes` text COLLATE utf8_unicode_ci,
  `bouquet` mediumtext COLLATE utf8_unicode_ci,
  `allowed_outputs` mediumtext COLLATE utf8_unicode_ci,
  `max_connections` int(11) DEFAULT '1',
  `is_restreamer` tinyint(4) DEFAULT '0',
  `is_trial` tinyint(4) DEFAULT '0',
  `is_mag` tinyint(4) DEFAULT '0',
  `is_e2` tinyint(4) DEFAULT '0',
  `is_stalker` tinyint(4) DEFAULT '0',
  `is_isplock` tinyint(4) DEFAULT '0',
  `allowed_ips` mediumtext COLLATE utf8_unicode_ci,
  `allowed_ua` mediumtext COLLATE utf8_unicode_ci,
  `created_at` int(11) DEFAULT NULL,
  `pair_id` int(11) DEFAULT NULL,
  `force_server_id` int(11) DEFAULT '0',
  `as_number` varchar(30) COLLATE utf8_unicode_ci DEFAULT NULL,
  `isp_desc` mediumtext COLLATE utf8_unicode_ci,
  `forced_country` varchar(3) COLLATE utf8_unicode_ci DEFAULT NULL,
  `bypass_ua` tinyint(4) DEFAULT '0',
  `play_token` mediumtext COLLATE utf8_unicode_ci,
  `last_expiration_video` int(11) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `access_token` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `contact` text COLLATE utf8_unicode_ci,
  `last_activity` int(11) DEFAULT NULL,
  `last_activity_array` mediumtext COLLATE utf8_unicode_ci,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `exp_date` (`exp_date`),
  KEY `is_restreamer` (`is_restreamer`),
  KEY `admin_enabled` (`admin_enabled`),
  KEY `enabled` (`enabled`),
  KEY `is_trial` (`is_trial`),
  KEY `created_at` (`created_at`),
  KEY `pair_id` (`pair_id`),
  KEY `is_mag` (`is_mag`),
  KEY `username` (`username`),
  KEY `password` (`password`),
  KEY `is_e2` (`is_e2`),
  KEY `order_default` (`id`,`is_mag`,`is_e2`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lines_activity`
--

CREATE TABLE IF NOT EXISTS `lines_activity` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `proxy_id` int(11) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `user_ip` varchar(39) COLLATE utf8_unicode_ci DEFAULT NULL,
  `container` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `date_start` int(11) DEFAULT NULL,
  `date_end` int(11) DEFAULT NULL,
  `geoip_country_code` varchar(22) COLLATE utf8_unicode_ci DEFAULT NULL,
  `isp` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `external_device` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `divergence` float DEFAULT '0',
  `hmac_id` tinyint(4) DEFAULT NULL,
  `hmac_identifier` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `user_id` (`user_id`),
  KEY `stream_id` (`stream_id`),
  KEY `server_id` (`server_id`),
  KEY `date_end` (`date_end`),
  KEY `container` (`container`),
  KEY `geoip_country_code` (`geoip_country_code`),
  KEY `date_start` (`date_start`),
  KEY `date_start_2` (`date_start`,`date_end`),
  KEY `user_ip` (`user_ip`),
  KEY `user_agent` (`user_agent`),
  KEY `isp` (`isp`),
  KEY `parent_id` (`proxy_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lines_divergence`
--

CREATE TABLE IF NOT EXISTS `lines_divergence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `divergence` float DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `uuid` (`uuid`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lines_live`
--

CREATE TABLE IF NOT EXISTS `lines_live` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `proxy_id` int(11) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `user_ip` varchar(39) COLLATE utf8_unicode_ci DEFAULT NULL,
  `container` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `active_pid` int(11) DEFAULT NULL,
  `date_start` int(11) DEFAULT NULL,
  `date_end` int(11) DEFAULT NULL,
  `geoip_country_code` varchar(22) COLLATE utf8_unicode_ci DEFAULT NULL,
  `isp` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `external_device` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `divergence` float DEFAULT '0',
  `hls_last_read` int(11) DEFAULT NULL,
  `hls_end` tinyint(4) DEFAULT '0',
  `fingerprinting` tinyint(4) DEFAULT '0',
  `hmac_id` tinyint(4) DEFAULT NULL,
  `hmac_identifier` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `uuid` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `user_agent` (`user_agent`),
  KEY `user_ip` (`user_ip`),
  KEY `container` (`container`),
  KEY `pid` (`pid`),
  KEY `active_pid` (`active_pid`),
  KEY `geoip_country_code` (`geoip_country_code`),
  KEY `user_id` (`user_id`),
  KEY `stream_id` (`stream_id`),
  KEY `server_id` (`server_id`),
  KEY `date_start` (`date_start`),
  KEY `date_end` (`date_end`),
  KEY `hls_end` (`hls_end`),
  KEY `parent_id` (`proxy_id`) USING BTREE,
  KEY `fingerprinting` (`fingerprinting`),
  KEY `hmac_id` (`hmac_id`),
  KEY `hmac_identifier` (`hmac_identifier`),
  KEY `uuid` (`uuid`),
  KEY `idx_server_stream_hls` (`server_id`,`stream_id`,`hls_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lines_logs`
--

CREATE TABLE IF NOT EXISTS `lines_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `client_status` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `query_string` mediumtext COLLATE utf8_unicode_ci,
  `user_agent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `extra_data` mediumtext COLLATE utf8_unicode_ci,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `access_code` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `login_ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mag_claims`
--

CREATE TABLE IF NOT EXISTS `mag_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mag_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `real_type` varchar(10) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mag_id` (`mag_id`),
  KEY `stream_id` (`stream_id`),
  KEY `real_type` (`real_type`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `mag_devices`
--

CREATE TABLE IF NOT EXISTS `mag_devices` (
  `mag_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `bright` int(10) DEFAULT '200',
  `contrast` int(10) DEFAULT '127',
  `saturation` int(10) DEFAULT '127',
  `aspect` mediumtext COLLATE utf8_unicode_ci,
  `video_out` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'rca',
  `volume` int(5) DEFAULT '50',
  `playback_buffer_bytes` int(50) DEFAULT '0',
  `playback_buffer_size` int(50) DEFAULT '0',
  `audio_out` int(5) DEFAULT '1',
  `mac` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ls` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ver` varchar(300) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lang` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `locale` varchar(30) COLLATE utf8_unicode_ci DEFAULT 'en_GB.utf8',
  `city_id` int(11) DEFAULT '0',
  `hd` int(10) DEFAULT '1',
  `main_notify` int(5) DEFAULT '1',
  `fav_itv_on` int(5) DEFAULT '0',
  `now_playing_start` int(50) DEFAULT NULL,
  `now_playing_type` int(11) DEFAULT '0',
  `now_playing_content` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `time_last_play_tv` int(50) DEFAULT NULL,
  `time_last_play_video` int(50) DEFAULT NULL,
  `hd_content` int(11) DEFAULT '1',
  `image_version` varchar(350) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_change_status` int(11) DEFAULT NULL,
  `last_start` int(11) DEFAULT NULL,
  `last_active` int(11) DEFAULT NULL,
  `keep_alive` int(11) DEFAULT NULL,
  `playback_limit` int(11) DEFAULT '3',
  `screensaver_delay` int(11) DEFAULT '10',
  `stb_type` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `sn` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_watchdog` int(50) DEFAULT NULL,
  `created` int(11) DEFAULT NULL,
  `country` varchar(5) COLLATE utf8_unicode_ci DEFAULT NULL,
  `plasma_saving` int(11) DEFAULT '0',
  `ts_enabled` int(11) DEFAULT '0',
  `ts_enable_icon` int(11) DEFAULT '1',
  `ts_path` varchar(35) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ts_max_length` int(11) DEFAULT '3600',
  `ts_buffer_use` varchar(15) COLLATE utf8_unicode_ci DEFAULT 'cyclic',
  `ts_action_on_exit` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'no_save',
  `ts_delay` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'on_pause',
  `video_clock` varchar(10) COLLATE utf8_unicode_ci DEFAULT 'Off',
  `rtsp_type` int(11) DEFAULT '4',
  `rtsp_flags` int(11) DEFAULT '0',
  `stb_lang` varchar(15) COLLATE utf8_unicode_ci DEFAULT 'en',
  `display_menu_after_loading` int(11) DEFAULT '1',
  `record_max_length` int(11) DEFAULT '180',
  `plasma_saving_timeout` int(11) DEFAULT '600',
  `now_playing_link_id` int(11) DEFAULT NULL,
  `now_playing_streamer_id` int(11) DEFAULT NULL,
  `device_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `device_id2` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `hw_version` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `parent_password` varchar(20) COLLATE utf8_unicode_ci DEFAULT '0000',
  `spdif_mode` int(11) DEFAULT '1',
  `show_after_loading` varchar(60) COLLATE utf8_unicode_ci DEFAULT 'main_menu',
  `play_in_preview_by_ok` int(11) DEFAULT '1',
  `hdmi_event_reaction` int(11) DEFAULT '1',
  `mag_player` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'ffmpeg',
  `play_in_preview_only_by_ok` varchar(10) COLLATE utf8_unicode_ci DEFAULT 'true',
  `watchdog_timeout` int(11) DEFAULT NULL,
  `fav_channels` mediumtext COLLATE utf8_unicode_ci,
  `tv_archive_continued` mediumtext COLLATE utf8_unicode_ci,
  `tv_channel_default_aspect` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'fit',
  `last_itv_id` int(11) DEFAULT '0',
  `units` varchar(20) COLLATE utf8_unicode_ci DEFAULT 'metric',
  `token` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lock_device` tinyint(4) DEFAULT '0',
  `theme_type` tinyint(1) DEFAULT '0',
  `mac_filter` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`mag_id`),
  KEY `user_id` (`user_id`),
  KEY `mac` (`mac`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mag_events`
--

CREATE TABLE IF NOT EXISTS `mag_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` tinyint(3) DEFAULT '0',
  `mag_device_id` int(11) DEFAULT NULL,
  `event` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `need_confirm` tinyint(3) DEFAULT '0',
  `msg` mediumtext COLLATE utf8_unicode_ci,
  `reboot_after_ok` tinyint(3) DEFAULT '0',
  `auto_hide_timeout` tinyint(3) DEFAULT '0',
  `send_time` int(50) DEFAULT NULL,
  `additional_services_on` tinyint(3) DEFAULT '1',
  `anec` tinyint(3) DEFAULT '0',
  `vclub` tinyint(3) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `mag_device_id` (`mag_device_id`),
  KEY `event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mag_logs`
--

CREATE TABLE IF NOT EXISTS `mag_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mag_id` int(11) DEFAULT NULL,
  `action` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mag_id` (`mag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mysql_syslog`
--

CREATE TABLE IF NOT EXISTS `mysql_syslog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `error` longtext COLLATE utf8_unicode_ci,
  `username` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `database` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  `server_id` tinyint(4) DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ondemand_check`
--

CREATE TABLE IF NOT EXISTS `ondemand_check` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `status` int(1) DEFAULT NULL,
  `source_id` int(4) DEFAULT NULL,
  `source_url` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  `video_codec` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `audio_codec` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `resolution` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `response` int(11) DEFAULT NULL,
  `fps` int(11) DEFAULT NULL,
  `errors` mediumtext COLLATE utf8_unicode_ci,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `output_devices`
--

CREATE TABLE IF NOT EXISTS `output_devices` (
  `device_id` int(11) NOT NULL AUTO_INCREMENT,
  `device_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `device_key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `device_filename` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `device_header` mediumtext COLLATE utf8_unicode_ci,
  `device_conf` mediumtext COLLATE utf8_unicode_ci,
  `device_footer` mediumtext COLLATE utf8_unicode_ci,
  `default_output` int(11) DEFAULT '0',
  `copy_text` mediumtext COLLATE utf8_unicode_ci,
  PRIMARY KEY (`device_id`),
  KEY `device_key` (`device_key`),
  KEY `default_output` (`default_output`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `output_devices`
--

INSERT INTO `output_devices` (`device_id`, `device_name`, `device_key`, `device_filename`, `device_header`, `device_conf`, `device_footer`, `default_output`, `copy_text`) VALUES
(1, 'M3U Standard', 'm3u', 'playlist_{USERNAME}.m3u', '#EXTM3U', '#EXTINF:-1,{CHANNEL_NAME}\r\n{URL}', '', 2, NULL),
(2, 'M3U Plus', 'm3u_plus', 'playlist_{USERNAME}_plus.m3u', '#EXTM3U', '#EXTINF:-1 xc_vm-id=\"{XC_VM_ID}\" tvg-id=\"{CHANNEL_ID}\" tvg-name=\"{CHANNEL_NAME}\" tvg-logo=\"{CHANNEL_ICON}\" group-title=\"{CATEGORY}\",{CHANNEL_NAME}\r\n{URL}', '', 2, NULL),
(3, 'Simple List', 'simple', 'simple_{USERNAME}.txt', '', '{URL} #Name: {CHANNEL_NAME}', '', 2, NULL),
(4, 'Ariva', 'ariva', 'ariva_{USERNAME}.txt', '', '{CHANNEL_NAME},{URL}', '', 2, NULL),
(5, 'DreamBox OE 2.0', 'dreambox', 'userbouquet.favourites.tv', '#NAME {BOUQUET_NAME}', '#SERVICE {ESR_ID}{SID}{URL#:}\r\n#DESCRIPTION {CHANNEL_NAME}', '', 2, NULL),
(6, 'Enigma 2 OE 1.6', 'enigma16', 'userbouquet.favourites.tv', '#NAME {BOUQUET_NAME}', '#SERVICE 4097{SID}{URL#:}\r\n#DESCRIPTION {CHANNEL_NAME}', '', 2, NULL),
(7, 'Enigma 2 OE 1.6 Auto Script', 'enigma216_script', 'iptv.sh', 'USERNAME=\"{USERNAME}\";PASSWORD=\"{PASSWORD}\";bouquet=\"{BOUQUET_NAME}\";directory=\"/etc/enigma2/iptv.sh\";url=\"{SERVER_URL}playlist/$USERNAME/$PASSWORD/enigma16?output={OUTPUT_KEY}\";rm /etc/enigma2/userbouquet.\"$bouquet\"__tv_.tv;wget -O /etc/enigma2/userbouquet.\"$bouquet\"__tv_.tv $url;if ! cat /etc/enigma2/bouquets.tv | grep -v grep | grep -c $bouquet > /dev/null;then echo \"[+] Creating IPTV folder...\";cat /etc/enigma2/bouquets.tv | sed -n 1p > /etc/enigma2/new_bouquets.tv;echo \'#SERVICE 1:7:1:0:0:0:0:0:0:0:FROM BOUQUET \"userbouquet.\'$bouquet\'__tv_.tv\" ORDER BY bouquet\' >> /etc/enigma2/new_bouquets.tv; cat /etc/enigma2/bouquets.tv | sed -n \'2,$p\' >> /etc/enigma2/new_bouquets.tv;rm /etc/enigma2/bouquets.tv;mv /etc/enigma2/new_bouquets.tv /etc/enigma2/bouquets.tv;fi;rm /usr/bin/enigma2_pre_start.sh;echo \"Writing to file...\";echo \"/bin/sh \"$directory\" > /dev/null 2>&1 &\" > /usr/bin/enigma2_pre_start.sh;chmod 777 /usr/bin/enigma2_pre_start.sh;wget -qO - \"http://127.0.0.1/web/servicelistreload?mode=2\";wget -qO - \"http://127.0.0.1/web/servicelistreload?mode=2\"; read -p \"Press enter to complete setup and reboot\";;', '', '', 2, 'wget -O /etc/enigma2/iptv.sh {DEVICE_LINK} && chmod 777 /etc/enigma2/iptv.sh && /etc/enigma2/iptv.sh'),
(8, 'Enigma 2 OE 2.0 Auto Script', 'enigma22_script', 'iptv.sh', 'USERNAME=\"{USERNAME}\";PASSWORD=\"{PASSWORD}\";bouquet=\"{BOUQUET_NAME}\";directory=\"/etc/enigma2/iptv.sh\";url=\"{SERVER_URL}playlist/$USERNAME/$PASSWORD/dreambox?output={OUTPUT_KEY}\";rm /etc/enigma2/userbouquet.\"$bouquet\"__tv_.tv;wget -O /etc/enigma2/userbouquet.\"$bouquet\"__tv_.tv $url;if ! cat /etc/enigma2/bouquets.tv | grep -v grep | grep -c $bouquet > /dev/null;then echo \"[+] Creating IPTV folder...\";cat /etc/enigma2/bouquets.tv | sed -n 1p > /etc/enigma2/new_bouquets.tv;echo \'#SERVICE 1:7:1:0:0:0:0:0:0:0:FROM BOUQUET \"userbouquet.\'$bouquet\'__tv_.tv\" ORDER BY bouquet\' >> /etc/enigma2/new_bouquets.tv; cat /etc/enigma2/bouquets.tv | sed -n \'2,$p\' >> /etc/enigma2/new_bouquets.tv;rm /etc/enigma2/bouquets.tv;mv /etc/enigma2/new_bouquets.tv /etc/enigma2/bouquets.tv;fi;rm /usr/bin/enigma2_pre_start.sh;echo \"Writing to file...\";echo \"/bin/sh \"$directory\" > /dev/null 2>&1 &\" > /usr/bin/enigma2_pre_start.sh;chmod 777 /usr/bin/enigma2_pre_start.sh;wget -qO - \"http://127.0.0.1/web/servicelistreload?mode=2\";wget -qO - \"http://127.0.0.1/web/servicelistreload?mode=2\"; read -p \"Press enter to complete setup and reboot\";', '', '', 2, 'wget -O /etc/enigma2/iptv.sh {DEVICE_LINK} && chmod 777 /etc/enigma2/iptv.sh && /etc/enigma2/iptv.sh'),
(9, 'Fortec999/Prifix9400/Starport', 'fps', 'Royal.cfg', '', 'IPTV: { {CHANNEL_NAME} } { {URL} }', '', 2, NULL),
(10, 'Geant/Starsat/Tiger/Qmax/Hyper/Royal', 'gst', '{USERNAME}_list.txt', '', 'I: {URL} {CHANNEL_NAME}', '', 2, NULL),
(11, 'GigaBlue', 'gigablue', 'userbouquet.favourites.tv', '#NAME {BOUQUET_NAME}', '#SERVICE 4097:0:1:0:0:0:0:0:0:0:{URL#:}\r\n#DESCRIPTION {CHANNEL_NAME}', '', 2, NULL),
(12, 'MediaStar / StarLive v4', 'mediastar', 'tvlist.txt', '', '{CHANNEL_NAME} {URL}', '', 2, NULL),
(13, 'Octagon', 'octagon', 'internettv.feed', '', '[TITLE]\r\n{CHANNEL_NAME}\r\n[URL]\r\n{URL}\r\n[DESCRIPTION]\r\nIPTV\r\n[TYPE]\r\nLive', '', 2, NULL),
(14, 'Octagon Auto Script', 'octagon_script', 'iptv', 'USERNAME=\"{USERNAME}\";PASSWORD=\"{PASSWORD}\";url=\"{SERVER_URL}get.php?username=$USERNAME&password=$PASSWORD&type=octagon&output={OUTPUT_KEY}\";rm /var/freetvplus/internettv.feed;wget -O /var/freetvplus/internettv.feed1 $url;chmod 777 /var/freetvplus/internettv.feed1;awk -v BINMODE=3 -v RS=\'(\\r\\n|\\n)\' -v ORS=\'\\n\' \'{ print }\' /var/freetvplus/internettv.feed1 > /var/freetvplus/internettv.feed;rm /var/freetvplus/internettv.feed1', '', '', 2, 'wget -qO /var/bin/iptv {DEVICE_LINK}'),
(15, 'Revolution 60/60 | Sunplus', 'revosun', 'network_iptv.cfg', '', 'IPTV: { {CHANNEL_NAME} } { {URL} }', '', 2, NULL),
(16, 'Spark', 'spark', 'webtv_usr.xml', '<?xml version=\"1.0\"?>\r\n<webtvs>', '<webtv title=\"{CHANNEL_NAME}\" urlkey=\"0\" url=\"{URL}\" description=\"\" iconsrc=\"{CHANNEL_ICON}\" iconsrc_b=\"\" group=\"0\" type=\"0\" />', '</webtvs>', 2, NULL),
(17, 'Starlive v3/StarSat HD6060/AZclass', 'starlivev3', 'iptvlist.txt', '', '{CHANNEL_NAME},{URL}', '', 2, NULL),
(18, 'StarLive v5', 'starlivev5', 'channel.jason', '', '', '', 2, NULL),
(19, 'WebTV List', 'webtvlist', 'webtv list.txt', '', 'Channel name:{CHANNEL_NAME}\r\nURL:{URL}', '[Webtv channel END]', 2, NULL),
(20, 'Zorro', 'zorro', 'iptv.cfg', '<NETDBS_TXT_VER_1>', 'IPTV: { {CHANNEL_NAME} } { {URL} } -HIDE_URL', '', 2, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `output_formats`
--

CREATE TABLE IF NOT EXISTS `output_formats` (
  `access_output_id` int(11) NOT NULL AUTO_INCREMENT,
  `output_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `output_key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `output_ext` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`access_output_id`),
  KEY `output_key` (`output_key`),
  KEY `output_ext` (`output_ext`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `output_formats`
--

INSERT INTO `output_formats` (`access_output_id`, `output_name`, `output_key`, `output_ext`) VALUES
(1, 'HLS', 'm3u8', 'm3u8'),
(2, 'MPEGTS', 'ts', 'ts'),
(3, 'RTMP', 'rtmp', '');

-- --------------------------------------------------------

--
-- Table structure for table `panel_logs`
--

CREATE TABLE IF NOT EXISTS `panel_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL DEFAULT 'pdo',
  `log_message` longtext DEFAULT NULL,
  `log_extra` longtext DEFAULT NULL,
  `line` int(11) DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `unique` varchar(32) DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `env` varchar(32) NOT NULL DEFAULT 'cli',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `panel_stats`
--

CREATE TABLE IF NOT EXISTS `panel_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
  `time` int(16) DEFAULT '0',
  `count` float DEFAULT '0',
  `server_id` int(16) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `profiles`
--

CREATE TABLE IF NOT EXISTS `profiles` (
  `profile_id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `profile_options` mediumtext COLLATE utf8_unicode_ci,
  PRIMARY KEY (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `providers`
--

CREATE TABLE IF NOT EXISTS `providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `port` int(5) DEFAULT '80',
  `username` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `data` mediumtext COLLATE utf8_unicode_ci,
  `last_changed` int(11) DEFAULT NULL,
  `legacy` tinyint(1) DEFAULT '0',
  `enabled` tinyint(1) DEFAULT '1',
  `status` tinyint(1) DEFAULT '0',
  `ssl` tinyint(1) DEFAULT '0',
  `hls` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `providers_streams`
--

CREATE TABLE IF NOT EXISTS `providers_streams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `category_id` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `category_array` mediumtext COLLATE utf8_unicode_ci,
  `stream_display_name` mediumtext COLLATE utf8_unicode_ci,
  `stream_icon` mediumtext COLLATE utf8_unicode_ci,
  `channel_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `added` int(11) DEFAULT NULL,
  `modified` int(11) DEFAULT NULL,
  `type` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'live',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `channel_id` (`channel_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queue`
--

CREATE TABLE IF NOT EXISTS `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `added` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recordings`
--

CREATE TABLE IF NOT EXISTS `recordings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `created_id` int(11) DEFAULT NULL,
  `category_id` longtext COLLATE utf8_unicode_ci,
  `bouquets` longtext COLLATE utf8_unicode_ci,
  `title` mediumtext COLLATE utf8_unicode_ci,
  `description` mediumtext COLLATE utf8_unicode_ci,
  `stream_icon` mediumtext COLLATE utf8_unicode_ci,
  `start` int(11) DEFAULT NULL,
  `end` int(11) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `archive` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rtmp_ips`
--

CREATE TABLE IF NOT EXISTS `rtmp_ips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `notes` mediumtext COLLATE utf8_unicode_ci,
  `push` tinyint(1) DEFAULT NULL,
  `pull` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `servers`
--

CREATE TABLE IF NOT EXISTS `servers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_type` int(1) DEFAULT '0',
  `xc_vm_version` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `domain_name` mediumtext COLLATE utf8_unicode_ci,
  `server_ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `private_ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_main` int(16) DEFAULT '0',
  `enabled` int(16) DEFAULT '1',
  `parent_id` text COLLATE utf8_unicode_ci,
  `http_broadcast_port` int(11) DEFAULT '80',
  `https_broadcast_port` int(11) DEFAULT '443',
  `http_ports_add` mediumtext COLLATE utf8_unicode_ci,
  `https_ports_add` mediumtext COLLATE utf8_unicode_ci,
  `total_clients` int(11) DEFAULT '250',
  `network_interface` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'eth0',
  `status` tinyint(4) DEFAULT '-1',
  `enable_geoip` int(11) DEFAULT '0',
  `geoip_countries` mediumtext COLLATE utf8_unicode_ci,
  `last_check_ago` int(11) DEFAULT '0',
  `server_hardware` mediumtext COLLATE utf8_unicode_ci,
  `total_services` int(11) DEFAULT '3',
  `persistent_connections` tinyint(4) DEFAULT '0',
  `rtmp_port` int(11) DEFAULT '8880',
  `geoip_type` varchar(13) COLLATE utf8_unicode_ci DEFAULT 'low_priority',
  `isp_names` mediumtext COLLATE utf8_unicode_ci,
  `isp_type` varchar(13) COLLATE utf8_unicode_ci DEFAULT 'low_priority',
  `enable_isp` tinyint(4) DEFAULT '0',
  `network_guaranteed_speed` int(11) DEFAULT '1000',
  `timeshift_only` tinyint(4) DEFAULT '0',
  `whitelist_ips` mediumtext COLLATE utf8_unicode_ci,
  `watchdog_data` mediumtext COLLATE utf8_unicode_ci,
  `video_devices` mediumtext COLLATE utf8_unicode_ci,
  `audio_devices` mediumtext COLLATE utf8_unicode_ci,
  `gpu_info` mediumtext COLLATE utf8_unicode_ci,
  `interfaces` mediumtext COLLATE utf8_unicode_ci,
  `random_ip` tinyint(4) DEFAULT '0',
  `enable_proxy` tinyint(4) DEFAULT '0',
  `enable_https` tinyint(4) DEFAULT '0',
  `certbot_renew` tinyint(4) DEFAULT '0',
  `certbot_ssl` mediumtext COLLATE utf8_unicode_ci,
  `uuid` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `use_disk` tinyint(1) DEFAULT '0',
  `last_status` tinyint(4) DEFAULT '1',
  `time_offset` int(11) DEFAULT '0',
  `ping` int(11) DEFAULT '0',
  `requests_per_second` int(11) DEFAULT '0',
  `php_pids` longtext COLLATE utf8_unicode_ci,
  `connections` int(16) DEFAULT '0',
  `users` int(16) DEFAULT '0',
  `remote_status` tinyint(1) DEFAULT '1',
  `governors` mediumtext COLLATE utf8_unicode_ci,
  `governor` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `sysctl` mediumtext COLLATE utf8_unicode_ci,
  `order` int(11) DEFAULT NULL,
  `enable_gzip` tinyint(1) DEFAULT '0',
  `limit_requests` int(11) DEFAULT '0',
  `limit_burst` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `total_clients` (`total_clients`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `servers`
--

INSERT INTO `servers` (`id`, `server_type`, `xc_vm_version`, `server_name`, `domain_name`, `server_ip`, `private_ip`, `is_main`, `enabled`, `parent_id`, `http_broadcast_port`, `https_broadcast_port`, `http_ports_add`, `https_ports_add`, `total_clients`, `network_interface`, `status`, `enable_geoip`, `geoip_countries`, `last_check_ago`, `server_hardware`, `total_services`, `persistent_connections`, `rtmp_port`, `geoip_type`, `isp_names`, `isp_type`, `enable_isp`, `network_guaranteed_speed`, `timeshift_only`, `whitelist_ips`, `watchdog_data`, `video_devices`, `audio_devices`, `gpu_info`, `interfaces`, `random_ip`, `enable_proxy`, `enable_https`, `certbot_renew`, `certbot_ssl`, `uuid`, `use_disk`, `last_status`, `time_offset`, `ping`, `requests_per_second`, `php_pids`, `connections`, `users`, `remote_status`, `governors`, `governor`, `sysctl`, `order`, `enable_gzip`, `limit_requests`, `limit_burst`) VALUES
(1, 0, '2.0.0', 'Main Server', '', '127.0.0.1', '', 1, 1, '0', 80, 443, NULL, NULL, 1000, 'auto', 1, 0, '[]', 0, NULL, 4, 0, 8880, 'low_priority', '', 'low_priority', 0, 1000, 0, '[\"127.0.0.1\"]', NULL, '[]', '[]', '[]', '[\"eth0\"]', 0, 0, 0, 0, NULL, NULL, 0, 0, 0, 0, 0, NULL, 0, 0, 1, NULL, NULL, NULL, NULL, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `servers_stats`
--

CREATE TABLE IF NOT EXISTS `servers_stats` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) DEFAULT '0',
  `connections` int(11) DEFAULT '0',
  `streams` int(11) DEFAULT '0',
  `users` int(11) DEFAULT '0',
  `cpu` float DEFAULT '0',
  `cpu_cores` int(11) DEFAULT '0',
  `cpu_avg` float DEFAULT '0',
  `total_mem` int(11) DEFAULT '0',
  `total_mem_free` int(11) DEFAULT '0',
  `total_mem_used` int(11) DEFAULT '0',
  `total_mem_used_percent` float DEFAULT '0',
  `total_disk_space` bigint(20) DEFAULT '0',
  `uptime` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `total_running_streams` int(11) DEFAULT '0',
  `bytes_sent` bigint(20) DEFAULT '0',
  `bytes_received` bigint(20) DEFAULT '0',
  `bytes_sent_total` bigint(128) DEFAULT '0',
  `bytes_received_total` bigint(128) DEFAULT '0',
  `cpu_load_average` float DEFAULT '0',
  `gpu_info` mediumtext COLLATE utf8_unicode_ci,
  `iostat_info` mediumtext COLLATE utf8_unicode_ci,
  `time` int(16) DEFAULT '0',
  `total_users` int(11) DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL,
  `server_name` mediumtext COLLATE utf8_unicode_ci,
  `default_timezone` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'Europe/London',
  `allowed_stb_types` mediumtext COLLATE utf8_unicode_ci,
  `client_prebuffer` int(11) DEFAULT '30',
  `split_clients` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'equal',
  `stream_max_analyze` int(11) DEFAULT '5000000',
  `show_not_on_air_video` tinyint(4) DEFAULT '1',
  `not_on_air_video_path` mediumtext COLLATE utf8_unicode_ci,
  `show_banned_video` tinyint(4) DEFAULT '1',
  `banned_video_path` mediumtext COLLATE utf8_unicode_ci,
  `show_expired_video` tinyint(4) DEFAULT '1',
  `expired_video_path` mediumtext COLLATE utf8_unicode_ci,
  `show_expiring_video` tinyint(4) DEFAULT '1',
  `expiring_video_path` mediumtext COLLATE utf8_unicode_ci,
  `mag_container` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'ts',
  `api_container` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'ts',
  `probesize` int(11) DEFAULT '5000000',
  `allowed_ips_admin` mediumtext COLLATE utf8_unicode_ci,
  `block_svp` tinyint(4) DEFAULT '1',
  `allow_countries` mediumtext COLLATE utf8_unicode_ci,
  `user_auto_kick_hours` int(11) DEFAULT '4',
  `disallow_empty_user_agents` tinyint(4) DEFAULT '1',
  `show_all_category_mag` tinyint(4) DEFAULT '1',
  `flood_limit` int(11) DEFAULT '40',
  `flood_ips_exclude` mediumtext COLLATE utf8_unicode_ci,
  `flood_seconds` int(11) DEFAULT '2',
  `vod_bitrate_plus` int(11) DEFAULT '60',
  `read_buffer_size` int(11) DEFAULT '8192',
  `seg_time` int(3) DEFAULT '6',
  `seg_list_size` int(3) DEFAULT '6',
  `tv_channel_default_aspect` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'fit',
  `playback_limit` int(11) DEFAULT '4',
  `show_tv_channel_logo` tinyint(4) DEFAULT '1',
  `show_channel_logo_in_preview` tinyint(4) DEFAULT '1',
  `enable_connection_problem_indication` tinyint(4) DEFAULT '1',
  `vod_limit_perc` int(11) DEFAULT '150',
  `allowed_stb_types_for_local_recording` mediumtext COLLATE utf8_unicode_ci,
  `stalker_theme` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'digital',
  `rtmp_random` tinyint(4) DEFAULT '1',
  `api_ips` mediumtext COLLATE utf8_unicode_ci,
  `shared_mount_prefixes` mediumtext COLLATE utf8_unicode_ci,
  `use_buffer` tinyint(4) DEFAULT '0',
  `restreamer_prebuffer` tinyint(4) DEFAULT '0',
  `audio_restart_loss` tinyint(4) DEFAULT '0',
  `stalker_lock_images` mediumtext COLLATE utf8_unicode_ci,
  `channel_number_type` varchar(25) COLLATE utf8_unicode_ci DEFAULT 'bouquet',
  `stb_change_pass` tinyint(4) DEFAULT '0',
  `enable_debug_stalker` tinyint(4) DEFAULT '0',
  `online_capacity_interval` smallint(6) DEFAULT '10',
  `always_enabled_subtitles` tinyint(4) DEFAULT '1',
  `test_download_url` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'https://speed.hetzner.de/100MB.bin',
  `api_pass` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `message_of_day` mediumtext COLLATE utf8_unicode_ci,
  `enable_isp_lock` tinyint(4) DEFAULT '1',
  `show_isps` tinyint(4) DEFAULT '1',
  `save_closed_connection` tinyint(4) DEFAULT '1',
  `client_logs_save` tinyint(4) DEFAULT '1',
  `case_sensitive_line` tinyint(4) DEFAULT '1',
  `county_override_1st` tinyint(4) DEFAULT '0',
  `disallow_2nd_ip_con` tinyint(4) DEFAULT '0',
  `split_by` varchar(255) COLLATE utf8_unicode_ci DEFAULT 'con',
  `use_mdomain_in_lists` tinyint(4) DEFAULT '1',
  `priority_backup` tinyint(4) DEFAULT '1',
  `tmdb_api_key` mediumtext COLLATE utf8_unicode_ci,
  `mag_security` tinyint(4) DEFAULT '1',
  `hls_accelerator` tinyint(1) DEFAULT '1',
  `backups_pid` int(5) DEFAULT '0',
  `backups_to_keep` int(5) DEFAULT '0',
  `cc_time` int(10) DEFAULT '0',
  `dashboard_stats` tinyint(1) DEFAULT '1',
  `default_entries` int(5) DEFAULT '50',
  `disable_trial` tinyint(1) DEFAULT '0',
  `download_images` tinyint(1) DEFAULT '1',
  `dropbox_keep` tinyint(1) DEFAULT '0',
  `dropbox_remote` tinyint(1) DEFAULT '0',
  `ip_logout` tinyint(1) DEFAULT '1',
  `last_backup` int(10) DEFAULT '0',
  `login_flood` tinyint(1) DEFAULT '15',
  `recaptcha_enable` tinyint(1) DEFAULT '0',
  `reseller_restrictions` tinyint(1) DEFAULT '0',
  `stats_pid` int(5) DEFAULT '0',
  `tmdb_pid` int(5) DEFAULT '0',
  `watch_pid` int(5) DEFAULT '0',
  `automatic_backups` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'off',
  `dropbox_token` varchar(1800) COLLATE utf8_unicode_ci DEFAULT '',
  `recaptcha_v2_secret_key` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `recaptcha_v2_site_key` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `tmdb_language` varchar(16) COLLATE utf8_unicode_ci DEFAULT '',
  `release_parser` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'python',
  `language` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'en',
  `encrypt_hls` tinyint(1) DEFAULT '0',
  `disable_ts` tinyint(1) DEFAULT '0',
  `disable_ts_allow_restream` tinyint(1) DEFAULT '0',
  `disable_hls` tinyint(1) DEFAULT '0',
  `disable_hls_allow_restream` tinyint(1) DEFAULT '0',
  `disable_rtmp` tinyint(1) DEFAULT '1',
  `disable_rtmp_allow_restream` tinyint(1) DEFAULT '0',
  `date_format` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'Y-m-d',
  `datetime_format` varchar(16) COLLATE utf8_unicode_ci DEFAULT 'Y-m-d H:i:s',
  `streams_grouped` tinyint(1) DEFAULT '1',
  `disable_player_api` tinyint(1) DEFAULT '0',
  `disable_playlist` tinyint(1) DEFAULT '0',
  `disable_xmltv` tinyint(1) DEFAULT '0',
  `disable_enigma2` tinyint(1) DEFAULT '1',
  `disable_ministra` tinyint(1) DEFAULT '0',
  `api_redirect` tinyint(1) DEFAULT '0',
  `movie_year_append` tinyint(1) DEFAULT '0',
  `log_clear` int(11) DEFAULT '31',
  `cleanup` tinyint(1) DEFAULT '1',
  `fingerprint_max` int(11) DEFAULT '25',
  `bruteforce_mac_attempts` tinyint(4) DEFAULT '5',
  `bruteforce_username_attempts` tinyint(4) DEFAULT '10',
  `bruteforce_frequency` int(11) DEFAULT '300',
  `auto_update_lbs` tinyint(4) DEFAULT '1',
  `update_version` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `dashboard_display_alt` tinyint(4) DEFAULT '1',
  `dashboard_map` tinyint(4) DEFAULT '1',
  `cpu_limit` tinyint(4) DEFAULT '0',
  `mem_limit` int(11) DEFAULT '0',
  `detect_restream_servers` tinyint(1) DEFAULT '1',
  `detect_restream_block_user` tinyint(1) DEFAULT '0',
  `detect_restream_block_ip` tinyint(1) DEFAULT '0',
  `detect_restream_ports` varchar(1024) COLLATE utf8_unicode_ci DEFAULT '[25461,25550,31210]',
  `cloudflare` tinyint(1) DEFAULT '0',
  `js_navigate` tinyint(1) DEFAULT '1',
  `encrypt_playlist` tinyint(1) DEFAULT '1',
  `encrypt_playlist_restreamer` tinyint(1) DEFAULT '1',
  `stream_logs_save` tinyint(1) DEFAULT '1',
  `restrict_same_ip` tinyint(1) DEFAULT '1',
  `show_tickets` tinyint(1) DEFAULT '1',
  `percentage_match` tinyint(1) DEFAULT '80',
  `thread_count` int(11) DEFAULT '4',
  `scan_seconds` int(11) DEFAULT '86400',
  `verify_host` tinyint(4) DEFAULT '1',
  `max_genres` tinyint(4) DEFAULT '3',
  `legacy_get` tinyint(4) DEFAULT '0',
  `legacy_xmltv` tinyint(4) DEFAULT '0',
  `mag_disable_ssl` tinyint(4) DEFAULT '0',
  `hide_failures` tinyint(4) DEFAULT '0',
  `legacy_panel_api` tinyint(4) DEFAULT '0',
  `connection_loop_per` tinyint(4) DEFAULT '0',
  `connection_loop_count` tinyint(4) DEFAULT '0',
  `api_probe` tinyint(4) DEFAULT '1',
  `max_simultaneous_downloads` tinyint(4) DEFAULT '2',
  `restart_php_fpm` tinyint(4) DEFAULT '1',
  `cache_playlists` int(11) DEFAULT '0',
  `send_xc_vm_header` int(11) DEFAULT '1',
  `request_prebuffer` int(11) DEFAULT '1',
  `debug_show_errors` tinyint(4) DEFAULT '0',
  `block_streaming_servers` tinyint(4) DEFAULT '0',
  `block_proxies` tinyint(4) DEFAULT '0',
  `ip_subnet_match` tinyint(4) DEFAULT '0',
  `last_cache` int(11) DEFAULT '0',
  `last_cache_taken` int(11) DEFAULT '0',
  `ministra_allow_blank` int(11) DEFAULT '0',
  `enable_cache` tinyint(4) DEFAULT '1',
  `legacy_mag_auth` tinyint(4) DEFAULT '0',
  `ignore_invalid_users` tinyint(4) DEFAULT '0',
  `on_demand_instant_off` tinyint(4) DEFAULT '0',
  `on_demand_failure_exit` tinyint(4) DEFAULT '0',
  `on_demand_wait_time` tinyint(4) DEFAULT '20',
  `playlist_from_mysql` tinyint(4) DEFAULT '0',
  `show_images` tinyint(4) DEFAULT '1',
  `kill_rogue_ffmpeg` tinyint(4) DEFAULT '1',
  `monitor_connection_status` tinyint(4) DEFAULT '1',
  `stream_fail_sleep` tinyint(4) DEFAULT '10',
  `custom_ip_header` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `send_protection_headers` tinyint(4) DEFAULT '0',
  `send_altsvc_header` tinyint(4) DEFAULT '0',
  `send_server_header` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `send_unique_header` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `send_unique_header_domain` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `max_items` int(11) DEFAULT '0',
  `restrict_playlists` tinyint(4) DEFAULT '1',
  `mag_legacy_redirect` tinyint(4) DEFAULT '0',
  `save_login_logs` tinyint(4) DEFAULT '1',
  `save_restart_logs` tinyint(4) DEFAULT '1',
  `keep_activity` int(11) DEFAULT '0',
  `keep_client` int(11) DEFAULT '0',
  `keep_login` int(11) DEFAULT '0',
  `keep_errors` int(11) DEFAULT '0',
  `keep_restarts` int(11) DEFAULT '0',
  `ignore_keyframes` int(11) DEFAULT '0',
  `seg_delete_threshold` int(11) DEFAULT '4',
  `fails_per_time` int(11) DEFAULT '86400',
  `thread_count_movie` tinyint(3) DEFAULT '5',
  `thread_count_show` tinyint(3) DEFAULT '1',
  `redirect_timeout` tinyint(1) DEFAULT '5',
  `create_expiration` tinyint(3) DEFAULT '5',
  `redis_handler` tinyint(1) DEFAULT '0',
  `redis_password` varchar(512) COLLATE utf8_unicode_ci DEFAULT '',
  `force_epg_timezone` tinyint(1) DEFAULT '0',
  `check_vod` tinyint(1) DEFAULT '0',
  `max_encode_movies` int(11) DEFAULT '10',
  `max_encode_cc` int(11) DEFAULT '1',
  `queue_loop` int(11) DEFAULT '5',
  `cache_thread_count` int(4) DEFAULT '4',
  `cache_changes` tinyint(1) DEFAULT '1',
  `player_blur` tinyint(1) DEFAULT '0',
  `player_opacity` tinyint(1) DEFAULT '10',
  `player_allow_playlist` tinyint(1) DEFAULT '1',
  `player_allow_bouquet` tinyint(1) DEFAULT '1',
  `player_hide_incompatible` tinyint(1) DEFAULT '0',
  `player_allow_hevc` tinyint(1) DEFAULT '0',
  `read_native_hls` tinyint(1) DEFAULT '1',
  `keep_protocol` tinyint(1) DEFAULT '0',
  `ffmpeg_cpu` varchar(8) COLLATE utf8_unicode_ci DEFAULT '4.0',
  `ffmpeg_gpu` varchar(8) COLLATE utf8_unicode_ci DEFAULT '4.0',
  `header_stats` tinyint(1) DEFAULT '1',
  `mag_keep_extension` tinyint(1) DEFAULT '1',
  `show_connected_video` tinyint(1) DEFAULT '1',
  `connected_video_path` mediumtext COLLATE utf8_unicode_ci,
  `disallow_2nd_ip_max` int(11) DEFAULT '1',
  `probesize_ondemand` int(11) DEFAULT '256000',
  `ffmpeg_warnings` int(11) DEFAULT '1',
  `vod_sort_newest` tinyint(1) DEFAULT '0',
  `mag_message` mediumtext COLLATE utf8_unicode_ci,
  `mag_default_type` tinyint(1) DEFAULT '0',
  `fps_delay` int(11) DEFAULT '600',
  `fps_check_type` tinyint(1) DEFAULT '1',
  `show_category_duplicates` tinyint(1) DEFAULT '0',
  `probe_extra_wait` int(11) DEFAULT '10',
  `restream_deny_unauthorised` tinyint(1) DEFAULT '1',
  `extract_subtitles` tinyint(1) DEFAULT '0',
  `reseller_ssl_domain` tinyint(1) DEFAULT '1',
  `disable_xmltv_restreamer` tinyint(1) DEFAULT '0',
  `disable_playlist_restreamer` tinyint(1) DEFAULT '0',
  `mag_load_all_channels` tinyint(1) DEFAULT '0',
  `connection_sync_timer` int(11) DEFAULT '1',
  `segment_wait_time` int(11) DEFAULT '20',
  `total_users` int(11) DEFAULT '0',
  `dts_legacy_ffmpeg` tinyint(1) DEFAULT '0',
  `allow_cdn_access` tinyint(1) DEFAULT '0',
  `disable_mag_token` tinyint(1) DEFAULT '0',
  `ondemand_balance_equal` tinyint(1) DEFAULT '0',
  `update_data` mediumtext COLLATE utf8_unicode_ci,
  `dashboard_status` tinyint(1) DEFAULT '1',
  `on_demand_checker` tinyint(1) DEFAULT '0',
  `on_demand_scan_time` int(16) DEFAULT '3600',
  `on_demand_max_probe` int(16) DEFAULT '5',
  `on_demand_scan_keep` int(16) DEFAULT '604800',
  `parse_type` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'ptn',
  `fallback_parser` tinyint(1) DEFAULT '0',
  `alternative_titles` tinyint(1) DEFAULT '0',
  `search_items` int(3) DEFAULT '20',
  `enable_search` tinyint(4) DEFAULT '1',
  `modal_edit` tinyint(1) DEFAULT '1',
  `group_buttons` tinyint(1) DEFAULT '1',
  `license` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `stop_failures` tinyint(3) DEFAULT '3',
  `restreamer_bypass_proxy` tinyint(1) DEFAULT '0',
  `mysql_sleep_kill` int(11) DEFAULT '21600',
  `reissues` mediumtext COLLATE utf8_unicode_ci,
  `status_uuid` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `live_streaming_pass` varchar(512) COLLATE utf8_unicode_ci DEFAULT NULL,
  `platform_api_key` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `update_channel` varchar(12) COLLATE utf8_unicode_ci DEFAULT 'stable',
  `maxmind_account_id` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL,
  `maxmind_license_key` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `maxmind_editions` mediumtext COLLATE utf8_unicode_ci,
  `threshold_cpu` tinyint(3) DEFAULT '67',
  `threshold_mem` tinyint(3) DEFAULT '67',
  `threshold_disk` tinyint(3) DEFAULT '67',
  `threshold_network` tinyint(3) DEFAULT '67',
  `threshold_clients` tinyint(3) DEFAULT '67',
  `auth_flood_seconds` int(11) DEFAULT '10',
  `auth_flood_limit` int(11) DEFAULT '30',
  `auth_flood_sleep` int(11) DEFAULT '1',
  `auto_unban_ip` tinyint(1) DEFAULT '0',
  `ban_duration_value` int(11) DEFAULT '24',
  `ban_duration_unit` varchar(10) COLLATE utf8_unicode_ci DEFAULT 'hours',
  `php_loopback` tinyint(1) DEFAULT '1',
  `fanout_hls_window` int(11) DEFAULT '6',
  `fanout_grace_sec` int(11) DEFAULT '10',
  `fanout_write_timeout_sec` int(11) DEFAULT '15',
  `fanout_chunk_bytes` int(11) DEFAULT '12032',
  `fanout_max_gop_bytes` int(11) DEFAULT '10528000',
  `fanout_source_insecure` tinyint(1) DEFAULT '1',
  `fanout_default_prebuffer_sec` int(11) DEFAULT '0',
  `fanout_idle_buffer_grace_sec` int(11) DEFAULT '30',
  `fanout_idle_buffer_ratio` decimal(3,2) DEFAULT '0.50'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `server_name`, `default_timezone`, `allowed_stb_types`, `client_prebuffer`, `split_clients`, `stream_max_analyze`, `show_not_on_air_video`, `not_on_air_video_path`, `show_banned_video`, `banned_video_path`, `show_expired_video`, `expired_video_path`, `show_expiring_video`, `expiring_video_path`, `mag_container`, `api_container`, `probesize`, `allowed_ips_admin`, `block_svp`, `allow_countries`, `user_auto_kick_hours`, `disallow_empty_user_agents`, `show_all_category_mag`, `flood_limit`, `flood_ips_exclude`, `flood_seconds`, `vod_bitrate_plus`, `read_buffer_size`, `seg_time`, `seg_list_size`, `tv_channel_default_aspect`, `playback_limit`, `show_tv_channel_logo`, `show_channel_logo_in_preview`, `enable_connection_problem_indication`, `vod_limit_perc`, `allowed_stb_types_for_local_recording`, `stalker_theme`, `rtmp_random`, `api_ips`, `use_buffer`, `restreamer_prebuffer`, `audio_restart_loss`, `stalker_lock_images`, `channel_number_type`, `stb_change_pass`, `enable_debug_stalker`, `online_capacity_interval`, `always_enabled_subtitles`, `test_download_url`, `api_pass`, `message_of_day`, `enable_isp_lock`, `show_isps`, `save_closed_connection`, `client_logs_save`, `case_sensitive_line`, `county_override_1st`, `disallow_2nd_ip_con`, `split_by`, `use_mdomain_in_lists`, `priority_backup`, `tmdb_api_key`, `mag_security`, `hls_accelerator`, `backups_pid`, `backups_to_keep`, `cc_time`, `dashboard_stats`, `default_entries`, `disable_trial`, `download_images`, `dropbox_keep`, `dropbox_remote`, `ip_logout`, `last_backup`, `login_flood`, `recaptcha_enable`, `reseller_restrictions`, `stats_pid`, `tmdb_pid`, `watch_pid`, `automatic_backups`, `dropbox_token`, `recaptcha_v2_secret_key`, `recaptcha_v2_site_key`, `tmdb_language`, `release_parser`, `language`, `encrypt_hls`, `disable_ts`, `disable_ts_allow_restream`, `disable_hls`, `disable_hls_allow_restream`, `disable_rtmp`, `disable_rtmp_allow_restream`, `date_format`, `datetime_format`, `streams_grouped`, `disable_player_api`, `disable_playlist`, `disable_xmltv`, `disable_enigma2`, `disable_ministra`, `api_redirect`, `movie_year_append`, `log_clear`, `cleanup`, `fingerprint_max`, `bruteforce_mac_attempts`, `bruteforce_username_attempts`, `bruteforce_frequency`, `auto_update_lbs`, `update_version`, `dashboard_display_alt`, `dashboard_map`, `cpu_limit`, `mem_limit`, `detect_restream_servers`, `detect_restream_block_user`, `detect_restream_block_ip`, `detect_restream_ports`, `cloudflare`, `js_navigate`, `encrypt_playlist`, `encrypt_playlist_restreamer`, `stream_logs_save`, `restrict_same_ip`, `show_tickets`, `percentage_match`, `thread_count`, `scan_seconds`, `verify_host`, `max_genres`, `legacy_get`, `legacy_xmltv`, `mag_disable_ssl`, `hide_failures`, `legacy_panel_api`, `connection_loop_per`, `connection_loop_count`, `api_probe`, `max_simultaneous_downloads`, `restart_php_fpm`, `cache_playlists`, `send_xc_vm_header`, `request_prebuffer`, `debug_show_errors`, `block_streaming_servers`, `block_proxies`, `ip_subnet_match`, `last_cache`, `last_cache_taken`, `ministra_allow_blank`, `enable_cache`, `legacy_mag_auth`, `ignore_invalid_users`, `on_demand_instant_off`, `on_demand_failure_exit`, `on_demand_wait_time`, `playlist_from_mysql`, `show_images`, `kill_rogue_ffmpeg`, `monitor_connection_status`, `stream_fail_sleep`, `custom_ip_header`, `send_protection_headers`, `send_altsvc_header`, `send_server_header`, `send_unique_header`, `send_unique_header_domain`, `max_items`, `restrict_playlists`, `mag_legacy_redirect`, `save_login_logs`, `save_restart_logs`, `keep_activity`, `keep_client`, `keep_login`, `keep_errors`, `keep_restarts`, `ignore_keyframes`, `seg_delete_threshold`, `fails_per_time`, `thread_count_movie`, `thread_count_show`, `redirect_timeout`, `create_expiration`, `redis_handler`, `redis_password`, `force_epg_timezone`, `check_vod`, `max_encode_movies`, `max_encode_cc`, `queue_loop`, `cache_thread_count`, `cache_changes`, `player_blur`, `player_opacity`, `player_allow_playlist`, `player_allow_bouquet`, `player_hide_incompatible`, `player_allow_hevc`, `read_native_hls`, `keep_protocol`, `ffmpeg_cpu`, `ffmpeg_gpu`, `header_stats`, `mag_keep_extension`, `show_connected_video`, `connected_video_path`, `disallow_2nd_ip_max`, `probesize_ondemand`, `ffmpeg_warnings`, `vod_sort_newest`, `mag_message`, `mag_default_type`, `fps_delay`, `fps_check_type`, `show_category_duplicates`, `probe_extra_wait`, `restream_deny_unauthorised`, `extract_subtitles`, `reseller_ssl_domain`, `disable_xmltv_restreamer`, `disable_playlist_restreamer`, `mag_load_all_channels`, `connection_sync_timer`, `segment_wait_time`, `total_users`, `dts_legacy_ffmpeg`, `allow_cdn_access`, `disable_mag_token`, `ondemand_balance_equal`, `update_data`, `dashboard_status`, `on_demand_checker`, `on_demand_scan_time`, `on_demand_max_probe`, `on_demand_scan_keep`, `parse_type`, `fallback_parser`, `alternative_titles`, `search_items`, `enable_search`, `modal_edit`, `group_buttons`, `license`, `stop_failures`, `restreamer_bypass_proxy`, `mysql_sleep_kill`, `reissues`, `status_uuid`, `live_streaming_pass`, `update_channel`, `threshold_cpu`, `threshold_mem`, `threshold_disk`, `threshold_network`, `threshold_clients`, `auth_flood_seconds`, `auth_flood_limit`, `auth_flood_sleep`, `php_loopback`, `shared_mount_prefixes`, `fanout_hls_window`, `fanout_grace_sec`, `fanout_write_timeout_sec`, `fanout_chunk_bytes`, `fanout_max_gop_bytes`, `fanout_source_insecure`, `fanout_default_prebuffer_sec`, `fanout_idle_buffer_grace_sec`, `fanout_idle_buffer_ratio`) VALUES
(1, 'XC_VM', 'Europe/London', '[]', 10, 'equal', 5000000, 1, '', 1, '', 1, '', 1, '', 'ts', 'ts', 5000000, '', 1, '[\"ALL\"]', 3, 1, 1, 40, '', 2, 400, 8192, 10, 6, 'fit', 3, 1, 1, 1, 10, '[]', 'default', 1, '', 0, 0, 0, '', 'bouquet', 1, 0, 10, 0, 'https://speed.hetzner.de/100MB.bin', '', 'Welcome to XC_VM', 1, 1, 1, 1, 1, 0, 0, 'conn', 1, 1, '', 1, 1, 0, 0, 0, 1, 50, 0, 1, 10, 0, 1, 0, 20, 0, 1, 0, 0, 0, 'daily', '', '', '', '', 'python', 'en', 0, 0, 0, 0, 0, 0, 0, 'Y-m-d', 'Y-m-d H:i:s', 1, 0, 0, 0, 0, 0, 0, 0, 31, 1, 25, 10, 10, 300, 1, '1.1.0', 1, 0, 0, 0, 1, 0, 0, '[25461,25550,31210]', 0, 1, 1, 0, 1, 1, 0, 80, 50, 86400, 0, 3, 1, 1, 0, 0, 1, 0, 0, 1, 2, 1, 0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 20, 0, 1, 1, 1, 10, '', 0, 0, '', '', '', 0, 1, 0, 1, 1, 0, 0, 0, 0, 0, 0, 4, 86400, 5, 1, 5, 5, 0, '', 0, 0, 10, 1, 5, 10, 1, 0, 10, 1, 1, 0, 0, 1, 0, '4.0', '4.0', 1, 1, 1, NULL, 1, 256000, 1, 0, 'You can switch between the modern and legacy themes by using the <span class=\"label\">Green</span> and <span class=\"label\">Yellow</span> buttons on your remote control. Doing so will restart your device.', 1, 600, 1, 0, 10, 1, 0, 1, 0, 0, 0, 1, 20, 0, 0, 0, 0, 0, NULL, 1, 0, 3600, 5, 604800, 'ptn', 0, 0, 20, 1, 1, 1, NULL, 3, 0, 21600, NULL, NULL, NULL, 'stable', 67, 67, 67, 67, 67, 10, 30, 1, 1, '/opt/arr/media/', 6, 10, 15, 12032, 10528000, 1, 0, 30, 0.50);

-- --------------------------------------------------------

--
-- Table structure for table `signals`
--

CREATE TABLE IF NOT EXISTS `signals` (
  `signal_id` int(11) NOT NULL AUTO_INCREMENT,
  `pid` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `rtmp` tinyint(4) DEFAULT '0',
  `time` int(11) DEFAULT NULL,
  `custom_data` mediumtext COLLATE utf8_unicode_ci,
  `cache` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`signal_id`),
  KEY `server_id` (`server_id`),
  KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams`
--

CREATE TABLE IF NOT EXISTS `streams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) DEFAULT NULL,
  `category_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `stream_display_name` mediumtext COLLATE utf8_unicode_ci,
  `stream_source` mediumtext COLLATE utf8_unicode_ci,
  `stream_icon` mediumtext COLLATE utf8_unicode_ci,
  `notes` mediumtext COLLATE utf8_unicode_ci,
  `enable_transcode` tinyint(4) DEFAULT '0',
  `transcode_attributes` mediumtext COLLATE utf8_unicode_ci,
  `custom_ffmpeg` mediumtext COLLATE utf8_unicode_ci,
  `movie_properties` mediumtext COLLATE utf8_unicode_ci,
  `movie_subtitles` mediumtext COLLATE utf8_unicode_ci,
  `read_native` tinyint(4) DEFAULT '1',
  `target_container` text COLLATE utf8_unicode_ci,
  `stream_all` tinyint(4) DEFAULT '0',
  `remove_subtitles` tinyint(4) DEFAULT '0',
  `custom_sid` varchar(150) COLLATE utf8_unicode_ci DEFAULT NULL,
  `epg_id` int(11) DEFAULT NULL,
  `channel_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `epg_lang` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `order` int(11) DEFAULT '0',
  `auto_restart` mediumtext COLLATE utf8_unicode_ci,
  `transcode_profile_id` int(11) DEFAULT '0',
  `gen_timestamps` tinyint(4) DEFAULT '1',
  `added` int(11) DEFAULT NULL,
  `series_no` int(11) DEFAULT '0',
  `direct_source` tinyint(4) DEFAULT '0',
  `tv_archive_duration` int(11) DEFAULT '0',
  `tv_archive_server_id` int(11) DEFAULT '0',
  `tv_archive_pid` int(11) DEFAULT '0',
  `vframes_server_id` int(11) DEFAULT '0',
  `vframes_pid` int(11) DEFAULT '0',
  `movie_symlink` tinyint(4) DEFAULT '0',
  `rtmp_output` tinyint(4) DEFAULT '0',
  `allow_record` tinyint(4) DEFAULT '0',
  `probesize_ondemand` int(11) DEFAULT '128000',
  `custom_map` mediumtext COLLATE utf8_unicode_ci,
  `external_push` mediumtext COLLATE utf8_unicode_ci,
  `delay_minutes` int(11) DEFAULT '0',
  `tmdb_language` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `llod` tinyint(4) DEFAULT '0',
  `year` int(4) DEFAULT NULL,
  `rating` float NOT NULL DEFAULT '0',
  `plex_uuid` varchar(256) COLLATE utf8_unicode_ci DEFAULT '',
  `uuid` varchar(32) COLLATE utf8_unicode_ci DEFAULT NULL,
  `epg_offset` int(11) DEFAULT '0',
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `similar` mediumtext COLLATE utf8_unicode_ci,
  `tmdb_id` int(11) DEFAULT NULL,
  `adaptive_link` mediumtext COLLATE utf8_unicode_ci,
  `title_sync` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `fps_restart` tinyint(1) DEFAULT '0',
  `fps_threshold` int(11) DEFAULT '90',
  `direct_proxy` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `enable_transcode` (`enable_transcode`),
  KEY `read_native` (`read_native`),
  KEY `epg_id` (`epg_id`),
  KEY `channel_id` (`channel_id`),
  KEY `transcode_profile_id` (`transcode_profile_id`),
  KEY `order` (`order`),
  KEY `direct_source` (`direct_source`),
  KEY `rtmp_output` (`rtmp_output`),
  KEY `uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams_arguments`
--

CREATE TABLE IF NOT EXISTS `streams_arguments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `argument_cat` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_description` mediumtext COLLATE utf8_unicode_ci,
  `argument_wprotocol` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_cmd` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_type` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `argument_default_value` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `streams_arguments`
--

INSERT INTO `streams_arguments` (`id`, `argument_cat`, `argument_name`, `argument_description`, `argument_wprotocol`, `argument_key`, `argument_cmd`, `argument_type`, `argument_default_value`) VALUES
(1, 'fetch', 'User Agent', 'Set a Custom User Agent', 'http', 'user_agent', '-user_agent \"%s\"', 'text', 'Mozilla/5.0'),
(2, 'fetch', 'HTTP Proxy', 'Set an HTTP Proxy in this format: ip:port', 'http', 'proxy', '-http_proxy \"%s\"', 'text', NULL),
(3, 'transcode', 'Average Video Bit Rate', 'With this you can change the bitrate of the target video. It is very useful in case you want your video to be playable on slow internet connections', NULL, 'bitrate', '-b:v %dk', 'text', NULL),
(4, 'transcode', 'Average Audio Bitrate', 'Change Audio Bitrate', NULL, 'audio_bitrate', '-b:a %dk', 'text', NULL),
(5, 'transcode', 'Minimum Bitrate Tolerance', '-minrate FFmpeg argument. Specify the minimum bitrate tolerance here. Specify in kbps. Enter INT number.', NULL, 'minimum_bitrate', '-minrate %dk', 'text', NULL),
(6, 'transcode', 'Maximum Bitrate Tolerance', '-maxrate FFmpeg argument. Specify the maximum bitrate tolerance here.Specify in kbps. Enter INT number. ', NULL, 'maximum_bitrate', '-maxrate %dk', 'text', NULL),
(7, 'transcode', 'Buffer Size', '-bufsize is the rate control buffer. Basically it is assumed that the receiver/end player will buffer that much data so its ok to fluctuate within that much. Specify in kbps. Enter INT number.', NULL, 'bufsize', '-bufsize %dk', 'text', NULL),
(8, 'transcode', 'CRF Value', 'The range of the quantizer scale is 0-51: where 0 is lossless, 23 is default, and 51 is worst possible. A lower value is a higher quality and a subjectively sane range is 18-28. Consider 18 to be visually lossless or nearly so: it should look the same or ', NULL, 'crf', '-crf %d', 'text', NULL),
(9, 'transcode', 'Scaling', 'Change the Width & Height of the target Video. (Eg. 320:240 ) .  If we\'d like to keep the aspect ratio, we need to specify only one component, either width or height, and set the other component to -1. (eg 320:-1)', NULL, 'scaling', '-filter_complex \"scale=%s\"', 'text', NULL),
(10, 'transcode', 'Aspect', 'Change the target Video Aspect. (eg 16:9)', NULL, 'aspect', '-aspect %s', 'text', NULL),
(11, 'transcode', 'Target Video FrameRate', 'Set the frame rate', NULL, 'video_frame_rate', '-r %d', 'text', NULL),
(12, 'transcode', 'Audio Sample Rate', 'Set the Audio Sample rate in Hz', NULL, 'audio_sample_rate', '-ar %d', 'text', NULL),
(13, 'transcode', 'Audio Channels', 'Specify Audio Channels', NULL, 'audio_channels', '-ac %d', 'text', NULL),
(14, 'transcode', 'Remove Sensitive Parts (delogo filter)', 'With this filter you can remove sensitive parts in your video. You will just specifiy the x & y pixels where there is a sensitive area and the width and height that will be removed. Example Use: x=0:y=0:w=100:h=77:band=10 ', NULL, 'delogo', '-filter_complex \"delogo=%s\"', 'text', NULL),
(15, 'transcode', 'Threads', 'Specify the number of threads you want to use for the transcoding process. Entering 0 as value will make FFmpeg to choose the most optimal settings', NULL, 'threads', '-threads %d', 'text', NULL),
(16, 'transcode', 'Logo Path', 'Add your Own Logo to the stream. The logo will be placed in the upper left. Please be sure that you have selected H.264 as codec otherwise this option won\'t work. Note that adding your own logo will consume A LOT of cpu power', NULL, 'logo', '-i \"%s\" -filter_complex \"overlay\"', 'text', NULL),
(17, 'fetch', 'Cookie', 'Set an HTTP Cookie that might be useful to fetch your INPUT Source.', 'http', 'cookie', '-cookies \'%s\'', 'text', NULL),
(18, 'transcode', 'DeInterlacing Filter', 'It check pixels of previous, current and next frames to re-create the missed field by some local adaptive method (edge-directed interpolation) and uses spatial check to prevent most artifacts. ', NULL, '', '-filter_complex \"yadif\"', 'radio', '0'),
(19, 'fetch', 'Headers', 'Set Custom Headers', 'http', 'headers', '-headers \'%s\'', 'text', NULL),
(20, 'fetch', 'Force Input Audio Codec', 'Force FFmpeg to interpret the input audio stream as a specific codec (e.g., aac, ac3). Useful for streams with corrupted PMT metadata.', NULL, 'force_input_acodec', '-acodec %s', 'text', NULL),
(21, 'fetch', 'Skip FFProbe', 'Skip codec detection via ffprobe. Assumes h264 video and AAC audio. Use for streams with corrupted PMT where ffprobe misdetects codecs.', NULL, 'skip_ffprobe', '', 'radio', '0');

-- --------------------------------------------------------

--
-- Table structure for table `streams_categories`
--

CREATE TABLE IF NOT EXISTS `streams_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_type` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `category_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `parent_id` int(11) DEFAULT '0',
  `cat_order` int(11) DEFAULT '0',
  `is_adult` int(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `category_type` (`category_type`),
  KEY `cat_order` (`cat_order`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams_episodes`
--

CREATE TABLE IF NOT EXISTS `streams_episodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `season_num` int(11) DEFAULT NULL,
  `episode_num` int(11) DEFAULT NULL,
  `series_id` int(11) DEFAULT NULL,
  `stream_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `season_num` (`season_num`),
  KEY `series_id` (`series_id`),
  KEY `stream_id` (`stream_id`),
  KEY `episode_num` (`episode_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `streams_errors`
--

CREATE TABLE IF NOT EXISTS `streams_errors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  `error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `stream_id` (`stream_id`) USING BTREE,
  KEY `server_id` (`server_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `streams_logs`
--

CREATE TABLE IF NOT EXISTS `streams_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `action` varchar(500) DEFAULT NULL,
  `source` varchar(1024) DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `streams_options`
--

CREATE TABLE IF NOT EXISTS `streams_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `argument_id` int(11) DEFAULT NULL,
  `value` text COLLATE utf8_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `stream_id` (`stream_id`),
  KEY `argument_id` (`argument_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams_series`
--

CREATE TABLE IF NOT EXISTS `streams_series` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `category_id` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `cover` varchar(255) DEFAULT NULL,
  `cover_big` varchar(255) DEFAULT NULL,
  `genre` varchar(255) DEFAULT NULL,
  `plot` mediumtext,
  `cast` mediumtext,
  `rating` int(11) DEFAULT NULL,
  `director` varchar(255) DEFAULT NULL,
  `release_date` varchar(255) DEFAULT NULL,
  `last_modified` int(11) DEFAULT NULL,
  `tmdb_id` int(11) DEFAULT NULL,
  `seasons` mediumtext,
  `episode_run_time` int(11) DEFAULT '0',
  `backdrop_path` mediumtext,
  `youtube_trailer` mediumtext,
  `tmdb_language` varchar(50) DEFAULT NULL,
  `year` int(4) DEFAULT NULL,
  `plex_uuid` varchar(256) DEFAULT '',
  `similar` mediumtext,
  PRIMARY KEY (`id`),
  KEY `last_modified` (`last_modified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `streams_servers`
--

CREATE TABLE IF NOT EXISTS `streams_servers` (
  `server_stream_id` int(11) NOT NULL AUTO_INCREMENT,
  `stream_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `to_analyze` tinyint(4) DEFAULT '0',
  `stream_status` int(11) DEFAULT '0',
  `stream_started` int(11) DEFAULT NULL,
  `stream_info` mediumtext COLLATE utf8_unicode_ci,
  `monitor_pid` int(11) DEFAULT NULL,
  `aes_pid` int(11) DEFAULT NULL,
  `current_source` mediumtext COLLATE utf8_unicode_ci,
  `bitrate` int(11) DEFAULT NULL,
  `progress_info` mediumtext COLLATE utf8_unicode_ci,
  `cc_info` mediumtext COLLATE utf8_unicode_ci,
  `on_demand` tinyint(4) DEFAULT '0',
  `delay_pid` int(11) DEFAULT NULL,
  `delay_available_at` int(11) DEFAULT NULL,
  `pids_create_channel` mediumtext COLLATE utf8_unicode_ci,
  `cchannel_rsources` mediumtext COLLATE utf8_unicode_ci,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `compatible` tinyint(1) DEFAULT '0',
  `audio_codec` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `video_codec` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `resolution` int(5) DEFAULT NULL,
  `ondemand_check` int(16) DEFAULT NULL,
  PRIMARY KEY (`server_stream_id`),
  UNIQUE KEY `stream_id_2` (`stream_id`,`server_id`),
  KEY `stream_id` (`stream_id`),
  KEY `pid` (`pid`),
  KEY `server_id` (`server_id`),
  KEY `stream_status` (`stream_status`),
  KEY `stream_started` (`stream_started`),
  KEY `parent_id` (`parent_id`),
  KEY `to_analyze` (`to_analyze`),
  KEY `idx_server_ondemand_pid` (`server_id`,`on_demand`,`pid`),
  KEY `idx_parent_pid_monitor` (`parent_id`,`pid`,`monitor_pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams_stats`
--

CREATE TABLE IF NOT EXISTS `streams_stats` (
  `id` int(16) NOT NULL AUTO_INCREMENT,
  `stream_id` int(16) DEFAULT '0',
  `rank` int(16) DEFAULT '0',
  `time` int(16) DEFAULT '0',
  `connections` int(16) DEFAULT '0',
  `users` int(16) DEFAULT '0',
  `type` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
  `dateadded` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `streams_types`
--

CREATE TABLE IF NOT EXISTS `streams_types` (
  `type_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `type_key` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `type_output` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `live` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`type_id`),
  KEY `type_key` (`type_key`),
  KEY `type_output` (`type_output`),
  KEY `live` (`live`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `streams_types`
--

INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES
(1, 'Live Streams', 'live', 'live', 1),
(2, 'Movies', 'movie', 'movie', 0),
(3, 'Created Channels', 'created_live', 'live', 1),
(4, 'Radio Stations', 'radio_streams', 'live', 1),
(5, 'TV Series', 'series', 'series', 0);

-- --------------------------------------------------------

--
-- Table structure for table `syskill_log`
--

CREATE TABLE IF NOT EXISTS `syskill_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process` varchar(16) COLLATE utf8_unicode_ci DEFAULT NULL,
  `pid` int(11) DEFAULT NULL,
  `cpu` float DEFAULT NULL,
  `mem` int(11) DEFAULT NULL,
  `reason` varchar(256) COLLATE utf8_unicode_ci DEFAULT NULL,
  `command` mediumtext COLLATE utf8_unicode_ci,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `title` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `status` int(11) DEFAULT '1',
  `admin_read` tinyint(4) DEFAULT '0',
  `user_read` tinyint(4) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `status` (`status`),
  KEY `admin_read` (`admin_read`),
  KEY `user_read` (`user_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets_replies`
--

CREATE TABLE IF NOT EXISTS `tickets_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) DEFAULT NULL,
  `admin_reply` tinyint(4) DEFAULT NULL,
  `message` mediumtext COLLATE utf8_unicode_ci,
  `date` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `date_registered` int(11) DEFAULT NULL,
  `last_login` int(11) DEFAULT NULL,
  `member_group_id` int(11) DEFAULT NULL,
  `credits` float DEFAULT '0',
  `notes` mediumtext COLLATE utf8_unicode_ci,
  `status` tinyint(2) DEFAULT '1',
  `reseller_dns` mediumtext COLLATE utf8_unicode_ci,
  `owner_id` int(11) DEFAULT '0',
  `override_packages` text COLLATE utf8_unicode_ci,
  `hue` varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL,
  `theme` int(1) DEFAULT '0',
  `ui_prefs` text COLLATE utf8_unicode_ci DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `api_key` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lang` varchar(50) COLLATE utf8_unicode_ci DEFAULT 'en',
  PRIMARY KEY (`id`),
  KEY `member_group_id` (`member_group_id`),
  KEY `username` (`username`),
  KEY `password` (`password`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_credits_logs`
--

CREATE TABLE IF NOT EXISTS `users_credits_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `target_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `amount` float DEFAULT NULL,
  `date` int(11) DEFAULT NULL,
  `reason` mediumtext,
  PRIMARY KEY (`id`),
  KEY `target_id` (`target_id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `users_groups`
--

CREATE TABLE IF NOT EXISTS `users_groups` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` mediumtext COLLATE utf8_unicode_ci,
  `is_admin` tinyint(4) DEFAULT '0',
  `is_reseller` tinyint(4) DEFAULT NULL,
  `total_allowed_gen_trials` int(11) DEFAULT '0',
  `total_allowed_gen_in` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `delete_users` tinyint(4) DEFAULT '0',
  `allowed_pages` mediumtext COLLATE utf8_unicode_ci,
  `can_delete` tinyint(4) DEFAULT '1',
  `create_sub_resellers` tinyint(4) DEFAULT '0',
  `create_sub_resellers_price` float DEFAULT '0',
  `reseller_client_connection_logs` tinyint(4) DEFAULT '1',
  `can_view_vod` tinyint(4) DEFAULT '1',
  `allow_download` tinyint(4) DEFAULT '1',
  `minimum_trial_credits` int(16) DEFAULT '1',
  `allow_restrictions` tinyint(4) DEFAULT '1',
  `allow_change_username` tinyint(4) DEFAULT '1',
  `allow_change_password` tinyint(4) DEFAULT '1',
  `minimum_username_length` int(16) DEFAULT '8',
  `minimum_password_length` int(16) DEFAULT '8',
  `allow_change_bouquets` tinyint(1) DEFAULT '0',
  `notice_html` mediumtext COLLATE utf8_unicode_ci,
  `subresellers` mediumtext COLLATE utf8_unicode_ci,
  PRIMARY KEY (`group_id`),
  KEY `is_admin` (`is_admin`),
  KEY `is_reseller` (`is_reseller`),
  KEY `can_delete` (`can_delete`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Dumping data for table `users_groups`
--

INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `delete_users`, `allowed_pages`, `can_delete`, `create_sub_resellers`, `create_sub_resellers_price`, `reseller_client_connection_logs`, `can_view_vod`, `allow_download`, `minimum_trial_credits`, `allow_restrictions`, `allow_change_username`, `allow_change_password`, `minimum_username_length`, `minimum_password_length`, `allow_change_bouquets`, `notice_html`, `subresellers`) VALUES
(1, 'Administrators', 1, 0, 0, 'day', 0, '[]', 0, 0, 0, 0, 0, 1, 0, 0, 1, 1, 8, 8, 0, NULL, NULL),
(2, 'Resellers', 0, 1, 100000, 'month', 1, '[]', 0, 0, 0, 1, 1, 1, 0, 1, 1, 1, 8, 8, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users_logs`
--

CREATE TABLE IF NOT EXISTS `users_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner` int(11) DEFAULT NULL,
  `type` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `action` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `log_id` int(11) DEFAULT NULL,
  `package_id` int(11) DEFAULT NULL,
  `cost` int(16) DEFAULT NULL,
  `credits_after` int(16) DEFAULT NULL,
  `date` int(30) DEFAULT NULL,
  `deleted_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users_packages`
--

CREATE TABLE IF NOT EXISTS `users_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `package_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `is_addon` tinyint(4) DEFAULT '0',
  `is_trial` tinyint(4) DEFAULT '0',
  `is_official` tinyint(4) DEFAULT '0',
  `trial_credits` float DEFAULT '0',
  `official_credits` float DEFAULT '0',
  `trial_duration` int(11) DEFAULT '0',
  `trial_duration_in` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `official_duration` int(11) DEFAULT '0',
  `official_duration_in` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `groups` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `bouquets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `addon_packages` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_line` tinyint(4) DEFAULT '0',
  `is_mag` tinyint(4) DEFAULT '0',
  `is_e2` tinyint(4) DEFAULT '0',
  `is_restreamer` tinyint(4) DEFAULT '0',
  `is_isplock` tinyint(4) DEFAULT '0',
  `output_formats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `max_connections` int(11) DEFAULT '1',
  `force_server_id` int(11) DEFAULT '0',
  `forced_country` varchar(2) COLLATE utf8_unicode_ci DEFAULT NULL,
  `lock_device` tinyint(4) DEFAULT '1',
  `check_compatible` tinyint(4) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `is_trial` (`is_trial`),
  KEY `is_official` (`is_official`),
  KEY `can_gen_mag` (`is_mag`) USING BTREE,
  KEY `can_gen_e2` (`is_e2`) USING BTREE,
  KEY `is_line` (`is_line`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `enigma2_devices`
--
ALTER TABLE `enigma2_devices` ADD FULLTEXT KEY `search` (`mac_filter`,`public_ip`);

--
-- Indexes for table `lines`
--
ALTER TABLE `lines` ADD FULLTEXT KEY `search` (`username`,`admin_notes`,`reseller_notes`,`last_ip`,`contact`);

--
-- Indexes for table `mag_devices`
--
ALTER TABLE `mag_devices` ADD FULLTEXT KEY `search` (`mac_filter`,`ip`);

--
-- Indexes for table `streams`
--
ALTER TABLE `streams` ADD FULLTEXT KEY `search` (`stream_display_name`,`stream_source`,`notes`,`channel_id`);

--
-- Indexes for table `streams_series`
--
ALTER TABLE `streams_series` ADD FULLTEXT KEY `search` (`title`,`plot`,`cast`,`director`);

--
-- Indexes for table `users`
--
ALTER TABLE `users` ADD FULLTEXT KEY `search` (`username`,`email`,`ip`,`notes`,`reseller_dns`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
