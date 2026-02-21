<?php

class SettingsService {
	public static function edit($db, $rData, $rClearSettingsCacheCallback) {
		foreach (array('user_agent', 'http_proxy', 'cookie', 'headers') as $rKey) {
			$db->query('UPDATE `streams_arguments` SET `argument_default_value` = ? WHERE `argument_key` = ?;', ($rData[$rKey] ?: null), $rKey);
			unset($rData[$rKey]);
		}

		$rArray = verifyPostTable('settings', $rData, true);

		foreach (array('php_loopback', 'restreamer_bypass_proxy', 'request_prebuffer', 'modal_edit', 'group_buttons', 'enable_search', 'on_demand_checker', 'ondemand_balance_equal', 'disable_mag_token', 'allow_cdn_access', 'dts_legacy_ffmpeg', 'mag_load_all_channels', 'disable_xmltv_restreamer', 'disable_playlist_restreamer', 'ffmpeg_warnings', 'reseller_ssl_domain', 'extract_subtitles', 'show_category_duplicates', 'vod_sort_newest', 'header_stats', 'mag_keep_extension', 'keep_protocol', 'read_native_hls', 'player_allow_playlist', 'player_allow_bouquet', 'player_hide_incompatible', 'player_allow_hevc', 'force_epg_timezone', 'check_vod', 'ignore_keyframes', 'save_login_logs', 'save_restart_logs', 'mag_legacy_redirect', 'restrict_playlists', 'monitor_connection_status', 'kill_rogue_ffmpeg', 'show_images', 'on_demand_instant_off', 'on_demand_failure_exit', 'playlist_from_mysql', 'ignore_invalid_users', 'legacy_mag_auth', 'ministra_allow_blank', 'block_proxies', 'block_streaming_servers', 'ip_subnet_match', 'debug_show_errors', 'enable_debug_stalker', 'restart_php_fpm', 'restream_deny_unauthorised', 'api_probe', 'legacy_panel_api', 'hide_failures', 'verify_host', 'encrypt_playlist', 'encrypt_playlist_restreamer', 'mag_disable_ssl', 'legacy_get', 'legacy_xmltv', 'save_closed_connection', 'show_tickets', 'stream_logs_save', 'client_logs_save', 'streams_grouped', 'cloudflare', 'cleanup', 'dashboard_stats', 'dashboard_status', 'dashboard_map', 'dashboard_display_alt', 'recaptcha_enable', 'ip_logout', 'disable_player_api', 'disable_playlist', 'disable_xmltv', 'disable_enigma2', 'disable_ministra', 'enable_isp_lock', 'block_svp', 'disable_ts', 'disable_ts_allow_restream', 'disable_hls', 'disable_hls_allow_restream', 'disable_rtmp', 'disable_rtmp_allow_restream', 'case_sensitive_line', 'county_override_1st', 'disallow_2nd_ip_con', 'use_mdomain_in_lists', 'encrypt_hls', 'disallow_empty_user_agents', 'detect_restream_block_user', 'download_images', 'api_redirect', 'use_buffer', 'audio_restart_loss', 'show_isps', 'priority_backup', 'rtmp_random', 'show_connected_video', 'show_not_on_air_video', 'show_banned_video', 'show_expired_video', 'show_expiring_video', 'show_all_category_mag', 'always_enabled_subtitles', 'enable_connection_problem_indication', 'show_tv_channel_logo', 'show_channel_logo_in_preview', 'disable_trial', 'restrict_same_ip', 'js_navigate') as $rSetting) {
			if (isset($rData[$rSetting])) {
				$rArray[$rSetting] = 1;
			} else {
				$rArray[$rSetting] = 0;
			}
		}

		if (!isset($rData['allowed_stb_types_for_local_recording'])) {
			$rArray['allowed_stb_types_for_local_recording'] = array();
		}

		if (!isset($rData['allowed_stb_types'])) {
			$rArray['allowed_stb_types'] = array();
		}

		if (!isset($rData['allow_countries'])) {
			$rArray['allow_countries'] = array('ALL');
		}

		if ($rArray['mag_legacy_redirect']) {
			if (!file_exists(MAIN_HOME . 'www/c/')) {
				$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', SERVER_ID, time(), json_encode(array('action' => 'enable_ministra')));
			}
		} else {
			if (file_exists(MAIN_HOME . 'www/c/')) {
				$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', SERVER_ID, time(), json_encode(array('action' => 'disable_ministra')));
			}
		}

		if (100 < $rArray['search_items']) {
			$rArray['search_items'] = 100;
		}

		if ($rArray['search_items'] <= 0) {
			$rArray['search_items'] = 1;
		}

		$rPrepare = prepareArray($rArray);
		if (count($rPrepare['data']) <= 0) {
			return array('status' => STATUS_FAILURE);
		}

		$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . ';';
		if ($db->query($rQuery, ...$rPrepare['data'])) {
			call_user_func($rClearSettingsCacheCallback);
			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_FAILURE);
	}

	public static function editBackup($db, $rData, $rClearSettingsCacheCallback) {
		$rArray = verifyPostTable('settings', $rData, true);

		foreach (array('dropbox_remote') as $rSetting) {
			if (isset($rData[$rSetting])) {
				$rArray[$rSetting] = 1;
			} else {
				$rArray[$rSetting] = 0;
			}
		}

		if (!isset($rData['allowed_stb_types_for_local_recording'])) {
			$rArray['allowed_stb_types_for_local_recording'] = array();
		}

		if (!isset($rData['allowed_stb_types'])) {
			$rArray['allowed_stb_types'] = array();
		}

		$rPrepare = prepareArray($rArray);
		if (count($rPrepare['data']) <= 0) {
			return array('status' => STATUS_FAILURE);
		}

		$rQuery = 'UPDATE `settings` SET ' . $rPrepare['update'] . ';';
		if ($db->query($rQuery, ...$rPrepare['data'])) {
			call_user_func($rClearSettingsCacheCallback);
			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_FAILURE);
	}

	public static function editCacheCron($db, $rData, $rClearSettingsCacheCallback) {
		$rCheck = array(false, false);
		$rCron = array('*', '*', '*', '*', '*');
		$rPattern = '/^[0-9\/*,-]+$/';
		$rCron[0] = $rData['minute'];
		preg_match($rPattern, $rCron[0], $rMatches);
		$rCheck[0] = 0 < count($rMatches);
		$rCron[1] = $rData['hour'];
		preg_match($rPattern, $rCron[1], $rMatches);
		$rCheck[1] = 0 < count($rMatches);
		$rCronOutput = implode(' ', $rCron);

		if (isset($rData['cache_changes'])) {
			$rCacheChanges = true;
		} else {
			$rCacheChanges = false;
		}

		if ($rCheck[0] && $rCheck[1]) {
			$db->query("UPDATE `crontab` SET `time` = ? WHERE `filename` = 'cache_engine.php';", $rCronOutput);
			$db->query('UPDATE `settings` SET `cache_thread_count` = ?, `cache_changes` = ?;', $rData['cache_thread_count'], $rCacheChanges);

			if (file_exists(TMP_PATH . 'crontab')) {
				unlink(TMP_PATH . 'crontab');
			}

			call_user_func($rClearSettingsCacheCallback);
			return array('status' => STATUS_SUCCESS);
		}

		return array('status' => STATUS_FAILURE);
	}
}
