<?php

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
@header('Content-type: text/javascript');
$rReqType = (!empty($_REQUEST['type']) ? $_REQUEST['type'] : null);
$rReqAction = (!empty($_REQUEST['action']) ? $_REQUEST['action'] : null);

require __DIR__ . '/../modules/ministra/PortalHandler.php';

// Phase 1: Pre-init stub responses (no DB needed)
PortalHandler::handlePreInit($rReqType, $rReqAction);

register_shutdown_function('shutdown');
require '/home/xc_vm/www/stream/init.php';

if (!$rSettings['disable_ministra']) {
	if (!in_array($rReqAction, array('get_categories', 'get_genres', 'get_ordered_list', 'get_all_channels', 'get_all_fav_channels', 'get_all_fav_radio'))) {
	} else {
		$rCategories = CacheReader::get('categories');
	}

	$rIP = $_SERVER['REMOTE_ADDR'];
	$rCountryCode = GeoIPService::getIPInfo($rIP)['country']['iso_code'];
	$rMAC = (!empty($rRequest['mac']) ? $rRequest['mac'] : $_COOKIE['mac']);
	$rUserAgent = (!empty($_SERVER['HTTP_X_USER_AGENT']) ? $_SERVER['HTTP_X_USER_AGENT'] : null);
	$rGMode = (!empty($rRequest['gmode']) ? intval($rRequest['gmode']) : null);
	$rDebug = $rSettings['enable_debug_stalker'];
	$rDevice = array();
	$rTypes = array('live', 'created_live');
	$rForceProtocol = ($rSettings['mag_disable_ssl'] ? 'http' : null);
	$rUpdateCache = false;

	if (!($rReqType == 'stb' && $rReqAction == 'handshake')) {


		if (function_exists('getallheaders')) {
			$rHeaders = getallheaders();
		} else {
			$rHeaders = getHeaders();
		}

		$rAuthToken = null;
		$rAuthHeader = (!empty($rHeaders['Authorization']) ? $rHeaders['Authorization'] : null);

		if (!($rAuthHeader && preg_match('/Bearer\\s+(.*)$/i', $rAuthHeader, $rMatches))) {
		} else {
			$rAuthToken = trim($rMatches[1]);
		}

		if (!$rAuthToken) {
		} else {
			$rVerify = igbinary_unserialize(Encryption::decrypt($rAuthToken, $rSettings['live_streaming_pass'], OPENSSL_EXTRA));
			$rDevice = (isset($rVerify['id']) ? getdevice($rVerify['id']) : array());

			if ($rDevice['token'] != $rVerify['token']) {
				$rDevice = array();
			} else {
				$rDevice['authenticated'] = true;
				updatecache();
			}
		}

		if ($rDevice && $rReqType == 'stb' && $rReqAction == 'get_profile') {
			$rSerialNumber = (!empty($rRequest['sn']) ? $rRequest['sn'] : null);
			$rSTBType = (!empty($rRequest['stb_type']) ? $rRequest['stb_type'] : null);
			$rVersion = (!empty($rRequest['ver']) ? $rRequest['ver'] : null);
			$rImageVersion = (!empty($rRequest['image_version']) ? $rRequest['image_version'] : null);
			$rDeviceID = (!empty($rRequest['device_id']) ? $rRequest['device_id'] : null);
			$rDeviceID2 = (!empty($rRequest['device_id2']) ? $rRequest['device_id2'] : null);
			$rHWVersion = (!empty($rRequest['hw_version']) ? $rRequest['hw_version'] : null);
			$rVerified = true;

			if (empty($rSettings['allowed_stb_types']) || in_array(strtolower($rSTBType), $rSettings['allowed_stb_types'])) {
			} else {
				$rVerified = false;
			}

			//MAGSCAN
			//If No SerialNumber Is Posted
			if (empty($rSerialNumber)) {
				$rBanData = array('ip' => $rIP, 'notes' => "[MS] No Serial Number", 'date' => time());
				touch(FLOOD_TMP_PATH . 'block_' . $rIP);
				$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rBanData['ip'], $rBanData['notes'], $rBanData['date']);
				http_response_code(404);
				die();
			}
			//If Posted SN is different from Device
			if (!empty($rDevice['sn']) && $rDevice['sn'] !== $rSerialNumber) {
				$rBanData = array('ip' => $rIP, 'notes' => "[MS] Invalid Serial Number", 'date' => time());
				touch(FLOOD_TMP_PATH . 'block_' . $rIP);
				$db->query('INSERT INTO `blocked_ips` (`ip`,`notes`,`date`) VALUES(?,?,?)', $rBanData['ip'], $rBanData['notes'], $rBanData['date']);
				http_response_code(404);
				die();
			}
			//MANGSCAN

			if (!$rDevice['lock_device']) {
			} else {
				if (empty($rDevice['sn']) || $rDevice['sn'] == $rSerialNumber) {
				} else {
					$rVerified = false;
				}

				if (empty($rDevice['device_id']) || $rDevice['device_id'] == $rDeviceID) {
				} else {
					$rVerified = false;
				}

				if (empty($rDevice['device_id2']) || $rDevice['device_id2'] == $rDeviceID2) {
				} else {
					$rVerified = false;
				}

				if (empty($rDevice['hw_version']) || $rDevice['hw_version'] == $rHWVersion) {
				} else {
					$rVerified = false;
				}
			}

			if (empty($rSettings['stalker_lock_images']) || in_array($rVersion, $rSettings['stalker_lock_images'])) {
			} else {
				$rVerified = false;
			}

			if ($rDebug) {
				$rVerified = true;
			}

			if ($rVerified) {
				$rDevice['ip'] = $rIP;
				$rDevice['stb_type'] = $rSTBType;
				$rDevice['sn'] = $rSerialNumber;
				$rDevice['ver'] = $rVersion;
				$rDevice['image_version'] = $rImageVersion;
				$rDevice['device_id'] = $rDeviceID;
				$rDevice['device_id2'] = $rDeviceID2;
				$rDevice['hw_version'] = $rHWVersion;
				$rDevice['get_profile_vars']['ip'] = ($rIP ?: '127.0.0.1');
				$rDevice['get_profile_vars']['image_version'] = $rImageVersion;
				$rDevice['get_profile_vars']['stb_type'] = $rSTBType;
				$rDevice['get_profile_vars']['hw_version'] = $rHWVersion;
				$rDevice['authenticated'] = true;
				$db->query('UPDATE `mag_devices` SET `ip` = ?, `stb_type` = ?, `sn` = ?, `ver` = ?, `image_version` = ?, `device_id` = ?, `device_id2` = ?, `hw_version` = ? WHERE `mag_id` = ?;', $rIP, $rSTBType, $rSerialNumber, $rVersion, $rImageVersion, $rDeviceID, $rDeviceID2, $rHWVersion, $rDevice['mag_id']);
				updatecache();
			} else {
				unlink(MINISTRA_TMP_PATH . 'ministra_' . $rDevice['id']);
				$rDevice = array();
			}
		}

		$rAuthenticated = ($rDevice['authenticated'] ?: false);

		if (empty($rDevice['locale']) && !empty($_COOKIE['locale'])) {
			$rDevice['locale'] = $_COOKIE['locale'];
		} else {
			$rDevice['locale'] = 'en_GB.utf8';
		}

		$rMagData = array();
		$rProfile = array('id' => $rDevice['mag_id'], 'name' => $rDevice['mag_id'], 'sname' => '', 'pass' => '', 'use_embedded_settings' => '', 'parent_password' => '0000', 'bright' => '200', 'contrast' => '127', 'saturation' => '127', 'video_out' => '', 'volume' => '70', 'playback_buffer_bytes' => '0', 'playback_buffer_size' => '0', 'audio_out' => '1', 'mac' => $rMAC, 'ip' => '127.0.0.1', 'ls' => '', 'version' => '', 'lang' => '', 'locale' => $rDevice['locale'], 'city_id' => '0', 'hd' => '1', 'main_notify' => '1', 'fav_itv_on' => '0', 'now_playing_start' => '2018-02-18 17:33:43', 'now_playing_type' => '1', 'now_playing_content' => 'Test channel', 'additional_services_on' => '1', 'time_last_play_tv' => '0000-00-00 00:00:00', 'time_last_play_video' => '0000-00-00 00:00:00', 'operator_id' => '0', 'storage_name' => '', 'hd_content' => '0', 'image_version' => 'undefined', 'last_change_status' => '0000-00-00 00:00:00', 'last_start' => '2018-02-18 17:33:38', 'last_active' => '2018-02-18 17:33:43', 'keep_alive' => '2018-02-18 17:33:43', 'screensaver_delay' => '10', 'phone' => '', 'fname' => '', 'login' => '', 'password' => '', 'stb_type' => '', 'num_banks' => '0', 'tariff_plan_id' => '0', 'comment' => null, 'now_playing_link_id' => '0', 'now_playing_streamer_id' => '0', 'just_started' => '1', 'last_watchdog' => '2018-02-18 17:33:39', 'created' => '2018-02-18 14:40:12', 'plasma_saving' => '0', 'ts_enabled' => '0', 'ts_enable_icon' => '1', 'ts_path' => '', 'ts_max_length' => '3600', 'ts_buffer_use' => 'cyclic', 'ts_action_on_exit' => 'no_save', 'ts_delay' => 'on_pause', 'video_clock' => 'Off', 'verified' => '0', 'hdmi_event_reaction' => 1, 'pri_audio_lang' => '', 'sec_audio_lang' => '', 'pri_subtitle_lang' => '', 'sec_subtitle_lang' => '', 'subtitle_color' => '16777215', 'subtitle_size' => '20', 'show_after_loading' => '', 'play_in_preview_by_ok' => null, 'hw_version' => 'undefined', 'openweathermap_city_id' => '0', 'theme' => '', 'settings_password' => '0000', 'expire_billing_date' => '0000-00-00 00:00:00', 'reseller_id' => null, 'account_balance' => '', 'client_type' => 'STB', 'hw_version_2' => '62', 'blocked' => '0', 'units' => 'metric', 'tariff_expired_date' => null, 'tariff_id_instead_expired' => null, 'activation_code_auto_issue' => '1', 'last_itv_id' => 0, 'updated' => array('id' => '1', 'uid' => '1', 'anec' => '0', 'vclub' => '0'), 'rtsp_type' => '4', 'rtsp_flags' => '0', 'stb_lang' => 'en', 'display_menu_after_loading' => '', 'record_max_length' => 180, 'web_proxy_host' => '', 'web_proxy_port' => '', 'web_proxy_user' => '', 'web_proxy_pass' => '', 'web_proxy_exclude_list' => '', 'demo_video_url' => '', 'tv_quality' => 'high', 'tv_quality_filter' => '', 'is_moderator' => false, 'timeslot_ratio' => 0.33333333333333, 'timeslot' => 40, 'kinopoisk_rating' => '1', 'enable_tariff_plans' => '', 'strict_stb_type_check' => '', 'cas_type' => 0, 'cas_params' => null, 'cas_web_params' => null, 'cas_additional_params' => array(), 'cas_hw_descrambling' => 0, 'cas_ini_file' => '', 'logarithm_volume_control' => '', 'allow_subscription_from_stb' => '1', 'deny_720p_gmode_on_mag200' => '1', 'enable_arrow_keys_setpos' => '1', 'show_purchased_filter' => '', 'timezone_diff' => 0, 'enable_connection_problem_indication' => '1', 'invert_channel_switch_direction' => '', 'play_in_preview_only_by_ok' => false, 'enable_stream_error_logging' => '', 'always_enabled_subtitles' => ($rSettings['always_enabled_subtitles'] == 1 ? '1' : ''), 'enable_service_button' => '', 'enable_setting_access_by_pass' => '', 'tv_archive_continued' => '', 'plasma_saving_timeout' => '600', 'show_tv_only_hd_filter_option' => '', 'tv_playback_retry_limit' => '0', 'fading_tv_retry_timeout' => '1', 'epg_update_time_range' => 0.6, 'store_auth_data_on_stb' => false, 'account_page_by_password' => '', 'tester' => false, 'enable_stream_losses_logging' => '', 'external_payment_page_url' => '', 'max_local_recordings' => '10', 'tv_channel_default_aspect' => 'fit', 'default_led_level' => '10', 'standby_led_level' => '90', 'show_version_in_main_menu' => '1', 'disable_youtube_for_mag200' => '1', 'auth_access' => false, 'epg_data_block_period_for_stb' => '5', 'standby_on_hdmi_off' => '1', 'force_ch_link_check' => '', 'stb_ntp_server' => 'pool.ntp.org', 'overwrite_stb_ntp_server' => '', 'hide_tv_genres_in_fullscreen' => null, 'advert' => null);
		$rLocales['get_locales']['English'] = 'en_GB.utf8';
		$rLocales['get_locales']['Ελληνικά'] = 'el_GR.utf8';
		$rMagData['get_years'] = array('js' => array(array('id' => '*', 'title' => '*')));

		foreach (range(1900, date('Y')) as $rYear) {
			$rMagData['get_years']['js'][] = array('id' => $rYear, 'title' => $rYear);
		}
		$rMagData['get_abc'] = array('js' => array(array('id' => '*', 'title' => '*'), array('id' => 'A', 'title' => 'A'), array('id' => 'B', 'title' => 'B'), array('id' => 'C', 'title' => 'C'), array('id' => 'D', 'title' => 'D'), array('id' => 'E', 'title' => 'E'), array('id' => 'F', 'title' => 'F'), array('id' => 'G', 'title' => 'G'), array('id' => 'H', 'title' => 'H'), array('id' => 'I', 'title' => 'I'), array('id' => 'G', 'title' => 'G'), array('id' => 'K', 'title' => 'K'), array('id' => 'L', 'title' => 'L'), array('id' => 'M', 'title' => 'M'), array('id' => 'N', 'title' => 'N'), array('id' => 'O', 'title' => 'O'), array('id' => 'P', 'title' => 'P'), array('id' => 'Q', 'title' => 'Q'), array('id' => 'R', 'title' => 'R'), array('id' => 'S', 'title' => 'S'), array('id' => 'T', 'title' => 'T'), array('id' => 'U', 'title' => 'U'), array('id' => 'V', 'title' => 'V'), array('id' => 'W', 'title' => 'W'), array('id' => 'X', 'title' => 'X'), array('id' => 'W', 'title' => 'W'), array('id' => 'Z', 'title' => 'Z')));
		$rTimezone = (empty($_COOKIE['timezone']) || $_COOKIE['timezone'] == 'undefined' ? $rSettings['default_timezone'] : $_COOKIE['timezone']);

		if (in_array($rTimezone, DateTimeZone::listIdentifiers())) {
		} else {
			$rTimezone = $rSettings['default_timezone'];
		}

		if (!$rAuthenticated) {
			$rDevice['theme_type'] = $rSettings['mag_default_type'];
		}

		if ($rDevice['theme_type'] == 0) {
			$rTheme = 'xc_vm';
		} else {
			$rTheme = (empty($rSettings['stalker_theme']) ? 'default' : $rSettings['stalker_theme']);
		}

		$rLanguage = array('en_GB.utf8' => array('weather_comfort' => 'Comfort', 'weather_pressure' => 'Pressure', 'weather_mmhg' => 'mm Hg', 'weather_wind' => 'Wind', 'weather_speed' => 'm/s', 'weather_humidity' => 'Humidity', 'current_weather_unavailable' => 'Current weather unavailable', 'current_weather_not_configured' => 'The weather is not configured', 'karaoke_view' => 'VIEW', 'karaoke_sort' => 'SORT', 'karaoke_search' => 'SEARCH', 'karaoke_sampling' => 'PICKING', 'karaoke_by_letter' => 'BY LETTER', 'karaoke_by_performer' => 'BY NAME', 'karaoke_by_title' => 'BY TITLE', 'karaoke_title' => 'KARAOKE', 'layer_page' => 'Page', 'layer_from' => 'of', 'layer_found' => 'Total', 'layer_records' => 'items', 'layer_loading' => 'LOADING...', 'Loading...' => 'Loading...', 'mbrowser_title' => 'Media Browser', 'mbrowser_connected' => 'connected', 'mbrowser_disconnected' => 'disconnected', 'mbrowser_not_found' => 'not found', 'usb_drive' => 'USB drive', 'player_limit_notice' => 'The number of connections is limited.<br>Try again later', 'player_file_missing' => 'File missing', 'player_server_error' => 'Server error', 'player_access_denied' => 'Access denied', 'player_server_unavailable' => 'Server unavailable', 'player_series' => 'part', 'player_season' => 'Season', 'player_track' => 'Track', 'player_off' => 'Off', 'player_subtitle' => 'Subtitles', 'player_claim' => 'Complain', 'player_on_sound' => 'on sound', 'player_on_video' => 'on video', 'player_audio' => 'Audio', 'player_ty' => 'Thank you, your opinion will be taken into account', 'series_by_one_play' => 'play one ⇅', 'series_continuously_play' => 'play continuously ⇅', 'aspect_fit' => 'fit on', 'aspect_big' => 'zoom', 'aspect_opt' => 'optimal', 'aspect_exp' => 'stretch', 'aspect_cmb' => 'combined', 'radio_title' => 'Radio Stations', 'radio_sort' => 'SORT', 'radio_favorite' => 'FAVOURITE', 'radio_search' => 'SEARCH', 'radio_by_number' => 'By Number', 'radio_by_title' => 'By Title', 'radio_only_favorite' => 'Only Favourites', 'radio_fav_add' => 'add', 'radio_fav_del' => 'del', 'radio_search_box' => 'SEARCH', 'tv_view' => 'VIEW', 'tv_sort' => 'SORT', 'favorite' => 'FAVOURITE', 'tv_favorite' => 'FAVOURITE', 'tv_move' => 'MOVE', 'tv_by_number' => 'By Number', 'tv_by_title' => 'By Title', 'tv_only_favorite' => 'Only Favourites', 'tv_only_hd' => 'HD Only', 'tv_list' => 'Standard List', 'tv_list_w_info' => 'List With Player', 'tv_title' => 'Live TV', 'vclub_info' => 'information', 'sclub_info' => 'information', 'vclub_year' => 'Year', 'vclub_country' => 'Country', 'vclub_genre' => 'Genre', 'vclub_length' => 'Length', 'vclub_minutes' => 'min', 'vclub_director' => 'Director', 'vclub_cast' => 'Cast', 'vclub_rating' => 'Rating', 'vclub_age' => 'Age', 'vclub_rating_mpaa' => 'Rating MPAA', 'vclub_view' => 'VIEW', 'vclub_sort' => 'SORT', 'vclub_search' => 'SEARCH', 'vclub_fav' => 'FAVOURITE', 'vclub_other' => 'OTHER', 'vclub_find' => 'FIND', 'vclub_by_letter' => 'Alphabetical', 'vclub_by_genre' => 'Genre', 'vclub_by_year' => 'Year', 'vclub_by_rating' => 'Rating', 'vclub_search_box' => 'Search', 'vclub_query_box' => 'Filter', 'vclub_by_title' => 'Alphabetical', 'vclub_by_addtime' => 'Date Added', 'vclub_top' => 'Rating', 'vclub_only_hd' => 'HD Only', 'vclub_only_favorite' => 'Favourites', 'vclub_only_purchased' => 'purchased', 'vclub_not_ended' => 'not ended', 'vclub_list' => 'Standard List', 'vclub_list_w_info' => 'List With Poster', 'vclub_title' => 'Movies', 'sclub_title' => 'TV Series', 'vclub_purchased' => 'Purchased', 'vclub_rent_expires_in' => 'rent expires in', 'cut_off_msg' => 'your device is not active<br>', 'month_arr' => array('JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'), 'week_arr' => array('SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'), 'year' => '', 'records_title' => 'RECORDS', 'ears_back' => 'BACK', 'ears_about_movie' => 'ABOUT', 'ears_tv_guide' => 'GUIDE', 'ears_about_package' => 'INFO', 'settings_title' => 'Settings', 'parent_settings_cancel' => 'CANCEL', 'parent_settings_save' => 'SAVE', 'parent_settings_old_pass' => 'Current password', 'parent_settings_title' => 'PARENTAL SETTINGS', 'parent_settings_title_short' => 'PARENTAL', 'parent_settings_new_pass' => 'New password', 'parent_settings_repeat_new_pass' => 'Repeat new password', 'settings_saved' => 'Settings saved', 'settings_saved_reboot' => 'Settings saved.<br>The STB will be rebooted. Press OK.', 'settings_check_error' => 'Error filling fields', 'settings_saving_error' => 'Saving error', 'localization_settings_title' => 'LOCALIZATION', 'localization_label' => 'Language of the interface', 'country_label' => 'Country', 'city_label' => 'City', 'localization_page_button_info' => 'Use PAGE-/+ buttons to move through several menu items', 'settings_software_update' => 'SOFTWARE UPDATE', 'update_settings_cancel' => 'CANCEL', 'update_settings_start_update' => 'START UPDATE', 'update_from_http' => 'From HTTP', 'update_from_usb' => 'From USB', 'update_source' => 'Source', 'update_method_select' => 'Method select', 'empty' => 'EMPTY', 'course_title' => 'Exchange rate on', 'course_title_nbu' => 'NBU exchange rate on', 'course_title_cbr' => 'CBR exchange rate on', 'dayweather_title' => 'WEATHER', 'dayweather_pressure' => 'pres.:', 'dayweather_mmhg' => 'mm Hg', 'dayweather_wind' => 'wind:', 'dayweather_speed' => 'm/s', 'infoportal_title' => 'INFOPORTAL', 'cityinfo_title' => 'CITY INFO', 'cityinfo_main' => 'emergency', 'cityinfo_help' => 'help', 'cityinfo_other' => 'other', 'cityinfo_sort' => 'VIEW', 'horoscope_title' => 'HOROSCOPE', 'anecdote_title' => 'JOKES', 'anecdote_goto' => 'GOTO', 'anecdote_like' => 'LIKE', 'anecdote_bookmark' => 'BOOKMARK', 'anecdote_to_bookmark' => 'TO BOOKMARK', 'anecdote_pagebar_title' => 'JOKE', 'mastermind_title' => 'MASTERMIND', 'mastermind_rules' => 'RULES', 'mastermind_rating' => 'RATING', 'mastermind_bull' => 'B', 'mastermind_cow' => 'C', 'mastermind_rules_text' => 'Your task is to guess the number of unduplicated four digits, the first of them - not zero. Every your guess will be compared with the number put forth a STB. If you guessed a digit, but it is not in place, then it is a COW (C). If you guessed, and a number, and its location, this BULL (B).', 'mastermind_move_cursor' => 'Moving the cursor', 'mastermind_cell_numbers' => 'Enter numbers into cells', 'mastermind_step_confirmation' => 'Confirmation of the step', 'mastermind_page' => 'Page', 'mastermind_history_moves' => 'Moving through the pages of history moves', 'msg_service_off' => 'Service is disabled', 'msg_channel_not_available' => 'Channel is not available', 'epg_title' => 'TV Guide', 'epg_record' => 'RECORD', 'epg_remind' => 'REMIND', 'epg_memo' => 'Memo', 'epg_goto_ch' => 'Goto channel', 'epg_close_msg' => 'Close', 'epg_on_ch' => 'on channel', 'epg_now_begins' => 'now begins', 'epg_on_time' => 'in', 'epg_started' => 'started', 'epg_more' => 'MORE', 'epg_category' => 'Category', 'epg_director' => 'Director', 'epg_actors' => 'Stars', 'epg_desc' => 'Description', 'search_box_languages' => array('en'), 'date_format' => '{0}, {2} {1}, {3}', 'time_format' => '{0}:{1}', 'timezone_label' => 'Timezone', 'ntp_server' => 'NTP Server', 'remote_pvr_del' => 'DELETE', 'remote_pvr_stop' => 'STOP', 'remote_pvr_del_confirm' => 'Do you really want to delete this record?', 'remote_pvr_stop_confirm' => 'Do you really want to stop this record?', 'alert_confirm' => 'Confirm', 'alert_cancel' => 'Cancel', 'recorder_server_error' => 'Server error. Try again later.', 'record_duration' => 'RECORDING DURATION', 'rest_length_title' => 'FREE on the server, h', 'channel_recording_restricted' => 'Recording this channel is forbidden', 'recording_disabled' => "Recording isn't available on this device", 'playback_settings_buffer_size' => 'Buffer size', 'playback_settings_time' => 'Time, sec', 'playback_settings_title' => 'PLAYBACK', 'cancel_btn' => 'CANCEL', 'settings_cancel' => 'CANCEL', 'playback_settings_cancel' => 'CANCEL', 'exit_btn' => 'EXIT', 'yes_btn' => 'YES', 'close_btn' => 'CLOSE', 'ok_btn' => 'OK', 'pay_btn' => 'PAY', 'play_btn' => 'PLAY', 'start_btn' => 'START', 'add_btn' => 'ADD', 'settings_save' => 'SAVE', 'playback_settings_save' => 'SAVE', 'audio_out' => 'Audio out', 'audio_out_analog' => 'Analog only', 'audio_out_analog_spdif' => 'Analog and S/PDIF 2-channel PCM', 'audio_out_spdif' => 'S/PDIF raw or 2-channel PCM', 'game' => 'GAME', 'downloads_title' => 'DOWNLOADS', 'not_found_mounted_devices' => 'Not found mounted devices', 'downloads_add_download' => 'Add download', 'downloads_device' => 'Device', 'downloads_file_name' => 'File name', 'downloads_ok' => 'Ok', 'downloads_cancel' => 'Cancel', 'downloads_create' => 'CREATE', 'downloads_move_up' => 'MOVE UP', 'downloads_move_down' => 'MOVE DOWN', 'downloads_delete' => 'DELETE', 'downloads_record' => 'RECORDING', 'downloads_download' => 'DOWNLOAD', 'downloads_record_and_file' => 'RECORDING AND FILE', 'playback_limit_title' => 'Duration of continuous playback', 'playback_limit_off' => 'Without limit', 'playback_hours' => 'hours', 'playback_limit_reached' => 'Reached limit the duration of continuous playback. To continue playback, press the OK or EXIT.', 'common_settings_title' => 'GENERAL SETTINGS', 'screensaver_delay_title' => 'Screensaver interval', 'screensaver_off' => 'Disabled', 'screensaver_minutes' => 'min', 'demo_video_title' => 'DEMO VIDEO', 'account_info_title' => 'My Account', 'coming_soon' => 'Coming soon', 'account_info' => 'INFORMATION', 'account_payment' => 'MODERN PORTAL', 'account_pay' => 'PAY', 'account_agreement' => 'LEGACY PORTAL', 'account_terms' => '', 'demo_video' => 'Video instruction', 'tv_quality' => 'QUALITY', 'tv_quality_low' => 'low', 'tv_quality_medium' => 'medium', 'tv_quality_high' => 'high', 'tv_fav_add' => 'add', 'tv_fav_del' => 'del', 'internet' => 'Internet', 'network_status_title' => 'NETWORK STATUS', 'network_status_refresh' => 'REFRESH', 'test_speed' => 'Speed test', 'speedtest_testing' => 'testing...', 'speedtest_error' => 'error', 'speedtest_waiting' => 'waiting...', 'lan_up' => 'UP', 'lan_down' => 'DOWN', 'download_stopped' => 'stopped', 'download_waiting_queue' => 'waiting queue', 'download_running' => 'running', 'download_completed' => 'completed', 'download_temporary_error' => 'temporary error', 'download_permanent_error' => 'permanent error', 'auth_title' => 'Authentication', 'auth_login' => 'Login', 'auth_password' => 'Password', 'auth_error' => 'Error', 'play_or_download' => 'Play this url or start download?', 'player_play' => 'Play', 'player_download' => 'Download', 'play_all' => 'PLAY ALL', 'on' => 'ON', 'off' => 'OFF', 'smb_auth' => 'Network authentication', 'smb_username' => 'Login', 'smb_password' => 'Password', 'exit_title' => 'Do you really want to exit?', 'identical_download_exist' => 'There is an active downloads from this server', 'alert_form_title' => 'Alert', 'confirm_form_title' => 'Confirm', 'notice_form_title' => 'Notice', 'select_form_title' => 'Select', 'media_favorites' => 'Favourites', 'stb_type_not_supported' => 'your set-top box is not supported', 'Phone' => 'Subscription Expire Date', 'Tariff plan' => 'Tariff plan', 'User' => 'User', 'SERVICES MANAGEMENT' => 'SERVICES MANAGEMENT', 'SUBSCRIBE' => 'SUBSCRIBE', 'UNSUBSCRIBE' => 'UNSUBSCRIBE', 'package_info_title' => 'PACKAGE INFO', 'package_type' => 'Type', 'package_content' => 'Content', 'package_description' => 'Description', 'confirm_service_subscribe_text' => 'Are you really want to subscribe to this service?', 'confirm_service_unsubscribe_text' => 'Are you really want to unsubscribe from this service?', 'confirm_service_price_text' => 'The service costs {0}', 'service_subscribe_success' => 'You have successfully subscribed to the service.', 'service_unsubscribe_success' => 'You have successfully unsubscribed from the service.', 'service_subscribe_success_reboot' => 'You have successfully subscribed to the service. STB will be rebooted.', 'service_unsubscribe_success_reboot' => 'You have successfully unsubscribed from the service. STB will be rebooted.', 'service_subscribe_fail' => 'An error in the management of subscriptions.', 'service_subscribe_server_error' => 'Server error. Try again later.', 'package_price_measurement' => 'package_price_measurement', 'rent_movie_text' => 'Do you really want to rent this movie?', 'rent_movie_price_text' => 'The movie costs {0}', 'rent_duration_text' => 'Rent duration: {0}h', 'Account number' => 'Account number', 'Password' => 'Password', 'End date' => 'End date', '3D mode' => '3D mode', 'mode {0}' => 'mode {0}', 'no epg' => 'no epg', 'wrong epg' => 'wrong epg', 'iso_title' => 'Part', 'error_channel_nothing_to_play' => 'Channel not available', 'error_channel_limit' => 'Channel temporary unavailable', 'error_channel_not_available_for_zone' => 'Channel not available for this region', 'error_channel_link_fault' => 'Channel not available. Server error.', 'error_channel_access_denied' => 'Access denied', 'blocking_account_info' => 'Account Info', 'blocking_account_payment' => 'Payment', 'blocking_account_reboot' => 'Reboot', 'archive_continue_playing_text' => 'Continue playing?', 'archive_yes' => 'YES', 'archive_no' => 'NO', 'time_shift_exit_confirm_text' => 'Do you want to quit the Time Shift mode?', 'mbrowser_sort_by_name' => 'by name', 'mbrowser_sort_by_date' => 'by date', 'Connection problem' => 'Connection problem', 'Authentication problem' => 'Authentication problem', 'Account balance' => 'Account balance', 'remote_pvr_confirm_text' => 'Start recording on the server?', 'remote_deferred_pvr_confirm_text' => 'Do you really want to schedule a recording on the server?', 'pvr_target_select_text' => 'Select where to save the record', 'usb_target_btn' => 'USB Storage', 'server_target_btn' => 'Server', 'save_path' => 'Path', 'file_name' => 'Filename', 'usb_device' => 'USB Device', 'rec_stop_msg' => 'rec_stop_msg', 'rec_file_missing' => 'The recorded file is missing', 'rec_not_ended' => 'Recording is not finished yet', 'rec_channel_has_scheduled_recording' => 'The channel already has a scheduled recording', 'pvr_error_wrong_param' => 'PVR Error: Wrong parameter', 'pvr_error_memory' => 'PVR Error: Not enough memory to complete the operation', 'pvr_error_duration' => 'PVR Error: Incorrect recording range', 'pvr_error_not_found' => 'PVR Error: Task not found', 'pvr_error_wrong_filename' => 'PVR Error: Wrong filename', 'pvr_error_record_exist' => 'PVR Error: Record file already exists', 'pvr_error_url_open_error' => 'PVR Error: Error opening channel URL', 'pvr_error_file_open_error' => 'PVR Error: Error opening file', 'pvr_error_rec_limit' => 'PVR Error: Exceeded the maximum number simultaneous recordings', 'pvr_error_end_of_stream' => 'PVR Error: End of stream', 'pvr_error_file_write_error' => 'PVR Error: Error writing to file', 'pvr_start_time' => 'Start time', 'pvr_end_time' => 'End time', 'pvr_duration' => 'Duration', 'rec_options_form_title' => 'Recording', 'local_pvr_interrupted' => 'Recording on USB device interrupted', 'parent_password_error' => 'Wrong', 'parent_password_title' => 'Parent control', 'settings_password_title' => 'Access control', 'password_label' => 'Password', 'encoding_label' => 'Encoding', 'network_folder' => 'Network folder', 'server_ip' => 'IP address', 'server_path' => 'Path', 'local_folder' => 'Local folder', 'server_type' => 'Type', 'server_login' => 'Login', 'server_password' => 'Password', 'add_folder' => 'ADD', 'server_ip_placeholder' => 'Server address', 'server_path_placeholder' => 'Path to the folder', 'local_folder_placeholder' => 'Folder name in favourites', 'error' => 'error', 'mount_failed' => 'Mount failed', 'ad_skip' => 'SKIP', 'commercial' => 'COMMERCIAL', 'videoClockTitle' => 'Clock', 'videoClock_off' => 'Hidden', 'videoClock_upRight' => 'Top Right', 'videoClock_upLeft' => 'Top Left', 'videoClock_downRight' => 'Bottom Right', 'videoClock_downLeft' => 'Bottom Left', 'settings_unavailable' => 'Settings section is currently unavailable', 'no_dvb_channels_title' => 'No channels available', 'go_to_dvb_settings' => 'You can configure DVB channels in the settings menu', 'apps_title' => 'Applications', 'coming_soon_video' => 'Video will be available soon', 'app_install_confirm' => 'Install application?', 'audioclub_title' => 'AUDIO CLUB', 'track_search' => 'TRACK SEARCH', 'album_search' => 'ALBUM SEARCH', 'add_to_playlist' => 'ADD TO PLAYLIST', 'remove_from_playlist' => 'DEL FROM PLAYLIST', 'playlist' => 'PLAYLIST', 'audioclub_year' => 'Year', 'audioclub_country' => 'Country', 'audioclub_languages' => 'Language', 'audioclub_language' => 'Language', 'audioclub_performer' => 'Artist', 'audioclub_album' => 'Album', 'audioclub_albums' => 'Albums', 'audioclub_tracks' => 'Compositions', 'audioclub_select_playlist' => 'Playlist select', 'audioclub_playlist' => 'Playlist', 'new_btn' => 'NEW', 'playlist_name' => 'Name', 'audioclub_new_playlist' => 'New Playlist', 'audioclub_saving_error' => 'Error while saving', 'audioclub_create_new' => 'CREATE NEW', 'remove_from_playlist_confirm' => 'Do you really want to delete this track from playlist?', 'delete_playlist_confirm' => 'Do you really want to delete this playlist?', 'audioclub_remove_playlist' => 'DELETE', 'vk_music_title' => 'VK MUSIC', 'all_title' => 'All', 'outdated_firmware' => 'Firmware of your STB is outdated.<br>Please update it.', 'LOGOUT' => 'LOGOUT', 'confirm_logout_title' => 'Confirm', 'confirm_logout' => 'Do you really want to log out?'));

		if ($rTheme == 'xc_vm') {
			$rPageItems = 10;
		} else {
			$rPageItems = 14;
			$rLanguage['en_GB.utf8']['layer_found'] = 'Found';
			$rLanguage['en_GB.utf8']['series_by_one_play'] = 'one series';
			$rLanguage['en_GB.utf8']['series_continuously_play'] = 'continuously';
			$rLanguage['en_GB.utf8']['radio_by_number'] = 'by number';
			$rLanguage['en_GB.utf8']['radio_by_title'] = 'by title';
			$rLanguage['en_GB.utf8']['radio_only_favorite'] = 'only favourite';
			$rLanguage['en_GB.utf8']['tv_by_number'] = 'by number';
			$rLanguage['en_GB.utf8']['tv_by_title'] = 'by title';
			$rLanguage['en_GB.utf8']['tv_only_favorite'] = 'only favourite';
			$rLanguage['en_GB.utf8']['tv_only_hd'] = 'HD only';
			$rLanguage['en_GB.utf8']['tv_list'] = 'list';
			$rLanguage['en_GB.utf8']['tv_list_w_info'] = 'list + info';
			$rLanguage['en_GB.utf8']['vclub_info'] = 'information about the movie';
			$rLanguage['en_GB.utf8']['sclub_info'] = 'information about the series';
			$rLanguage['en_GB.utf8']['vclub_by_letter'] = 'BY LETTER';
			$rLanguage['en_GB.utf8']['vclub_by_genre'] = 'BY GENRE';
			$rLanguage['en_GB.utf8']['vclub_by_year'] = 'BY YEAR';
			$rLanguage['en_GB.utf8']['vclub_by_rating'] = 'BY RATING';
			$rLanguage['en_GB.utf8']['vclub_search_box'] = 'search';
			$rLanguage['en_GB.utf8']['vclub_query_box'] = 'picking';
			$rLanguage['en_GB.utf8']['vclub_by_title'] = 'by title';
			$rLanguage['en_GB.utf8']['vclub_by_addtime'] = 'by addtime';
			$rLanguage['en_GB.utf8']['vclub_top'] = 'rating';
			$rLanguage['en_GB.utf8']['vclub_only_hd'] = 'HD only';
			$rLanguage['en_GB.utf8']['vclub_only_favorite'] = 'favourite only';
			$rLanguage['en_GB.utf8']['vclub_list'] = 'list';
			$rLanguage['en_GB.utf8']['vclub_list_w_info'] = 'list + info';
			$rLanguage['en_GB.utf8']['vclub_title'] = 'Video On Demand';
			$rLanguage['en_GB.utf8']['ears_back'] = '<br>B<br>A<br>C<br>K<br>';
			$rLanguage['en_GB.utf8']['ears_about_movie'] = '<br>A<br>B<br>O<br>U<br>T<br><br>M<br>O<br>V<br>I<br>E<br>';
			$rLanguage['en_GB.utf8']['ears_tv_guide'] = '<br>T<br>V<br><br>G<br>U<br>I<br>D<br>E<br>';
			$rLanguage['en_GB.utf8']['ears_about_package'] = '<br>P<br>A<br>C<br>K<br>A<br>G<br>E<br><br>I<br>N<br>F<br>O<br>';
			$rLanguage['en_GB.utf8']['internet'] = 'internet';
			$rLanguage['en_GB.utf8']['play_all'] = 'Play all';
			$rLanguage['en_GB.utf8']['on'] = 'on';
			$rLanguage['en_GB.utf8']['off'] = 'off';
		}

		// Build context for PortalHandler
		$ctx = [
			'device'        => &$rDevice,
			'profile'       => $rProfile,
			'language'      => $rLanguage,
			'magData'       => $rMagData,
			'locales'       => $rLocales,
			'timezone'      => $rTimezone,
			'theme'         => $rTheme,
			'pageItems'     => $rPageItems,
			'forceProtocol' => $rForceProtocol,
			'player'        => '',
			'mac'           => $rMAC,
			'ip'            => $rIP,
			'authenticated' => $rAuthenticated,
			'gMode'         => $rGMode,
			'debug'         => $rDebug,
		];

		// Phase 4: Unauthenticated STB actions (get_profile, get_localization, get_modules)
		if ($rReqType == 'stb') {
			PortalHandler::handleStbPublic($rReqAction, $ctx);
		}

		// Phase 5: Authenticated actions
		if ($rAuthenticated) {
			PortalHandler::handleAuthenticated($rReqType, $rReqAction, $ctx);
		} else {
			// Phase 6: Unauthenticated — bruteforce check
			PortalHandler::handleUnauthenticated($rReqType, $rReqAction, $ctx);
		}
	} else {
		// Phase 7: Handshake
		PortalHandler::handleHandshake($rMAC);
	}
} else {
	exit();
}

function getSeriesItems($rUserID, $rType = 'series', $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array()) {
	global $rDevice;
	global $db;
	$rSeriesIDs = (is_array($rDevice['series_ids'] ?? null) ? $rDevice['series_ids'] : array());
	$rSeriesFav = (is_array($rDevice['fav_channels']['series'] ?? null) ? $rDevice['fav_channels']['series'] : array());

	if (0 < count($rSeriesIDs)) {
		$db->query('SELECT *, (SELECT MAX(`streams`.`added`) FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) AS `last_modified_stream` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rSeriesIDs)) . ') ORDER BY `last_modified_stream` DESC, `last_modified` DESC;');
		$rSeries = $db->get_rows(true, 'id');
	} else {
		$rSeries = array();
	}

	$rOutputSeries = array();

	foreach ($rSeries as $rSeriesID => $rSeriesO) {
		$rSeriesO['last_modified'] = $rSeriesO['last_modified_stream'];
		$rSeriesCategories = json_decode($rSeriesO['category_id'], true);
		if (!is_array($rSeriesCategories)) {
			$rSeriesCategories = array();
		}

		if (!empty($rCategoryID) && !in_array($rCategoryID, $rSeriesCategories)) {
		} else {
			if (in_array($rCategoryID, $rSeriesCategories)) {
				$rSeriesO['category_id'] = $rCategoryID;
			} else {
				$rSeriesO['category_id'] = ($rSeriesCategories[0] ?? 0);
			}

			if ((empty($rSearchBy) || stristr($rSeriesO['title'], $rSearchBy)) && !(!empty($rPicking['abc']) && $rPicking['abc'] != '*' && strtoupper(substr($rSeriesO['title'], 0, 1)) != $rPicking['abc']) && !(!empty($rPicking['genre']) && $rPicking['genre'] != '*' && $rSeriesO['category_id'] != $rPicking['genre']) && !(!empty($rPicking['years']) && $rPicking['years'] != '*' && $rSeriesO['year'] != $rPicking['years'])) {
				if (empty($rFav)) {
				} else {
					$rFound = false;

					if (empty($rSeriesFav) || !in_array($rSeriesID, $rSeriesFav)) {
					} else {
						$rFound = true;
					}

					if (!$rFound) {
						goto C4d803244552131c;
					}
				}
				$rOutputSeries[$rSeriesID] = $rSeriesO;
				C4d803244552131c:
			}
		}
	}

	switch ($rOrderBy) {
		case 'name':
			uasort($rOutputSeries, 'sortArrayStreamName');

			break;

		case 'rating':
		case 'top':
			uasort($rOutputSeries, 'sortArrayStreamRating');

			break;

		case 'number':
			uasort($rOutputSeries, 'sortArrayStreamNumber');

			break;

		default:
			uasort($rOutputSeries, 'sortArrayStreamAdded');
	}

	return $rOutputSeries;
}

function convertTypes($rTypes) {
	$rReturn = array();
	$rTypeInt = array('live' => 1, 'movie' => 2, 'created_live' => 3, 'radio_streams' => 4, 'series' => 5);

	foreach ($rTypes as $rType) {
		$rReturn[] = $rTypeInt[$rType];
	}

	return $rReturn;
}

function getItems($rTypes = array(), $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $additionalOptions = null) {
	global $rDevice;
	global $rSettings;
	global $rCategories;
	global $db;
	$rAdded = false;
	$rChannels = array();

	foreach ($rTypes as $rType) {
		switch ($rType) {
			case 'live':
			case 'created_live':
				if (!$rAdded) {
					$rChannels = array_merge($rChannels, (is_array($rDevice['live_ids'] ?? null) ? $rDevice['live_ids'] : array()));
					$rAdded = true;
				}
				break;

			case 'movie':
				$rChannels = array_merge($rChannels, (is_array($rDevice['vod_ids'] ?? null) ? $rDevice['vod_ids'] : array()));
				break;

			case 'radio_streams':
				$rChannels = array_merge($rChannels, (is_array($rDevice['radio_ids'] ?? null) ? $rDevice['radio_ids'] : array()));
				break;

			case 'series':
				$rChannels = array_merge($rChannels, (is_array($rDevice['episode_ids'] ?? null) ? $rDevice['episode_ids'] : array()));
				break;
		}
	}
	$rStreams = array('count' => 0, 'streams' => array());
	$rAdultCategories = CategoryService::getAdultIDs($rCategories);
	$rKey = $rStart + 1;
	$rWhereV = $rWhere = array();

	if (0 >= count($rTypes)) {
	} else {
		$rWhere[] = '`type` IN (' . implode(',', convertTypes($rTypes)) . ')';
	}

	if (empty($rCategoryID)) {
	} else {
		$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
		$rWhereV[] = $rCategoryID;
	}

	if (empty($rPicking['genre']) || $rPicking['genre'] == '*') {
	} else {
		$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
		$rWhereV[] = $rPicking['genre'];
	}

	$rChannels = StreamSorter::sortChannels($rChannels);

	if (empty($rFav)) {
	} else {
		$favoriteChannelIds = array();

		foreach ($rTypes as $rType) {
			$rTypeFav = (is_array($rDevice['fav_channels'][$rType] ?? null) ? $rDevice['fav_channels'][$rType] : array());
			foreach ($rTypeFav as $rStreamID) {
				$favoriteChannelIds[] = intval($rStreamID);
			}
		}
		$rChannels = array_intersect($favoriteChannelIds, $rChannels);
	}

	if (empty($rSearchBy)) {
	} else {
		$rWhere[] = '`stream_display_name` LIKE ?';
		$rWhereV[] = '%' . $rSearchBy . '%';
	}

	if (empty($rPicking['abc']) || $rPicking['abc'] == '*') {
	} else {
		$rWhere[] = 'UCASE(LEFT(`stream_display_name`, 1)) = ?';
		$rWhereV[] = strtoupper($rPicking['abc']);
	}

	if (empty($rPicking['years']) || $rPicking['years'] == '*') {
	} else {
		$rWhere[] = '`year` = ?';
		$rWhereV[] = $rPicking['years'];
	}

	$rWhere[] = '`id` IN (' . implode(',', $rChannels) . ')';

	$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

	switch ($rOrderBy) {
		case 'name':
			$rOrder = '`stream_display_name` ASC';

			break;

		case 'top':
		case 'rating':
			$rOrder = '`rating` DESC';

			break;

		case 'added':
			$rOrder = '`added` DESC';

			break;

		case 'number':
		default:
			if ($rSettings['channel_number_type'] != 'manual') {
				$rOrder = 'FIELD(id,' . implode(',', $rChannels) . ')';
			} else {
				$rOrder = '`order` ASC';
			}


			break;
	}
	if (0 < count($rChannels)) {
		if (!$additionalOptions) {
			$db->query("SELECT COUNT(`id`) AS `count` FROM `streams` " . $rWhereString . ";", ...$rWhereV);
			$rStreams["count"] = $db->get_row()["count"];
			if ($rLimit) {
				$A6d7047f2fda966c = "SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` " . $rWhereString . " ORDER BY " . $rOrder . " LIMIT " . $rStart . ", " . $rLimit . ";";
			} else {
				$A6d7047f2fda966c = "SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` " . $rWhereString . " ORDER BY " . $rOrder . ";";
			}
			$db->query($A6d7047f2fda966c, ...$rWhereV);
			$rRows = $db->get_rows();
		} else {
			$rWhereV[] = $additionalOptions;
			$db->query("SELECT * FROM (SELECT @row_number:=@row_number+1 AS `pos`, `id` FROM `streams`, (SELECT @row_number:=0) AS `t` " . $rWhereString . " ORDER BY " . $rOrder . ") `ids` WHERE `ids`.`id` = ?;", ...$rWhereV);
			return $db->get_row()["pos"] ?: NULL;
		}
	} else {
		if ($additionalOptions) {
			return NULL;
		}
		$rRows = [];
	}
	foreach ($rRows as $rStream) {
		$rStream["snumber"] = $rKey;
		$rStream["number"] = $rStream["snumber"];

		if (in_array($rCategoryID, json_decode($rStream["category_id"], true))) {
			$rStream["category_id"] = $rCategoryID;
		} else {
			list($rStream["category_id"]) = json_decode($rStream["category_id"], true);
		}

		if (in_array($rStream["category_id"], $rAdultCategories)) {
			$rStream["is_adult"] = 1;
		} else {
			$rStream["is_adult"] = 0;
		}

		$rStream["now_playing"] = getEPG($rStream["id"], time(), time() + 86400)[0] ?: NULL;
		$rStream["stream_info"] = json_decode($rStream["stream_info"], true);
		$rStreams["streams"][$rStream["id"]] = $rStream;
		$rKey++;
	}
	return $rStreams;
}

function sortArrayStreamRating($a, $b) {
	if (isset($a['rating'])) {
	} else {
		if (isset($a['movie_properties']) && isset($b['movie_properties'])) {
			if (!is_array($a['movie_properties'])) {
				$a = json_decode($a['movie_properties'], true);
			} else {
				$a = $a['movie_properties'];
			}

			if (!is_array($b['movie_properties'])) {
				$b = json_decode($b['movie_properties'], true);
			} else {
				$b = $b['movie_properties'];
			}
		} else {
			return 0;
		}
	}

	if ($a['rating'] != $b['rating']) {
		return ($b['rating'] < $a['rating'] ? -1 : 1);
	}

	return 0;
}

function sortArrayStreamAdded($a, $b) {
	$rColumn = (isset($a['added']) ? 'added' : 'last_modified');

	if (is_numeric($a[$rColumn])) {
	} else {
		$a[$rColumn] = strtotime($a['added']);
	}

	if (is_numeric($b[$rColumn])) {
	} else {
		$b[$rColumn] = strtotime($b[$rColumn]);
	}

	if ($a[$rColumn] != $b[$rColumn]) {
		return ($b[$rColumn] < $a[$rColumn] ? -1 : 1);
	}

	return 0;
}

function getDevice($rID = null, $rMAC = null) {
	global $db, $rSettings, $rCached;
	global $rIP;
	$rBouquets = CacheReader::get('bouquets');
	$rDevice = ($rID && file_exists(MINISTRA_TMP_PATH . 'ministra_' . $rID) ? ($rDevice = igbinary_unserialize(file_get_contents(MINISTRA_TMP_PATH . 'ministra_' . $rID))) : null);

	if (!$rDevice && $rMAC || $rDevice && 600 < time() - $rDevice['generated']) {
		if ($rMAC) {
			$db->query('SELECT * FROM `mag_devices` WHERE `mac` = ? LIMIT 1', $rMAC);
		} else {
			if ($rDevice) {
				$db->query('SELECT * FROM `mag_devices` WHERE `mac` = ? LIMIT 1', $rDevice['get_profile_vars']['mac']);
			}
		}

		if (0 >= $db->num_rows()) {
		} else {
			$rDevice = $db->get_row();
			$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, $rDevice['user_id'], null, null, true, false, $rIP);
			if (!is_array($rUserInfo)) {
				$rUserInfo = array();
			}
			$rDevice = array_merge($rDevice, $rUserInfo);
			if (is_string($rDevice['allowed_ips'])) {
				$rDevice['allowed_ips'] = json_decode($rDevice['allowed_ips'], true);
			}
			$rDevice['fav_channels'] = (!empty($rDevice['fav_channels']) ? json_decode($rDevice['fav_channels'], true) : array());

			if (!empty($rDevice['fav_channels']['live'])) {
			} else {
				$rDevice['fav_channels']['live'] = array();
			}

			if (!empty($rDevice['fav_channels']['movie'])) {
			} else {
				$rDevice['fav_channels']['movie'] = array();
			}

			if (!empty($rDevice['fav_channels']['radio_streams'])) {
			} else {
				$rDevice['fav_channels']['radio_streams'] = array();
			}

			if (!empty($rDevice['fav_channels']['series']) && is_array($rDevice['fav_channels']['series'])) {
			} else {
				$rDevice['fav_channels']['series'] = array();
			}

			$rDevice['mag_player'] = trim($rDevice['mag_player']);
			unset($rDevice['channel_ids']);
			$rDevice['get_profile_vars'] = array('id' => $rDevice['mag_id'], 'name' => $rDevice['mag_id'], 'parent_password' => ($rDevice['parent_password'] ?: '0000'), 'bright' => ($rDevice['bright'] ?: '200'), 'contrast' => ($rDevice['contrast'] ?: '127'), 'saturation' => ($rDevice['saturation'] ?: '127'), 'video_out' => ($rDevice['video_out'] ?: ''), 'volume' => ($rDevice['volume'] ?: '70'), 'playback_buffer_bytes' => ($rDevice['playback_buffer_bytes'] ?: '0'), 'playback_buffer_size' => ($rDevice['playback_buffer_size'] ?: '0'), 'audio_out' => ($rDevice['audio_out'] ?: '1'), 'mac' => $rDevice['mac'], 'ip' => '127.0.0.1', 'ls' => ($rDevice['ls'] ?: ''), 'lang' => ($rDevice['lang'] ?: ''), 'locale' => ($rDevice['locale'] ?: 'en_GB.utf8'), 'city_id' => ($rDevice['city_id'] ?: '0'), 'hd' => ($rDevice['hd'] ?: '1'), 'main_notify' => ($rDevice['main_notify'] ?: '1'), 'fav_itv_on' => ($rDevice['fav_itv_on'] ?: '0'), 'now_playing_start' => ($rDevice['now_playing_start'] ? date('Y-m-d H:i:s', $rDevice['now_playing_start']) : date('Y-m-d H:i:s')), 'now_playing_type' => ($rDevice['now_playing_type'] ?: '1'), 'now_playing_content' => ($rDevice['now_playing_content'] ?: ''), 'time_last_play_tv' => ($rDevice['time_last_play_tv'] ? date('Y-m-d H:i:s', $rDevice['time_last_play_tv']) : '0000-00-00 00:00:00'), 'time_last_play_video' => ($rDevice['time_last_play_video'] ? date('Y-m-d H:i:s', $rDevice['time_last_play_video']) : '0000-00-00 00:00:00'), 'hd_content' => ($rDevice['hd_content'] ?: '0'), 'image_version' => $rDevice['image_version'], 'last_change_status' => ($rDevice['last_change_status'] ? date('Y-m-d H:i:s', $rDevice['last_change_status']) : '0000-00-00 00:00:00'), 'last_start' => ($rDevice['last_start'] ? date('Y-m-d H:i:s', $rDevice['last_start']) : date('Y-m-d H:i:s')), 'last_active' => ($rDevice['last_active'] ? date('Y-m-d H:i:s', $rDevice['last_active']) : date('Y-m-d H:i:s')), 'keep_alive' => ($rDevice['keep_alive'] ? date('Y-m-d H:i:s', $rDevice['keep_alive']) : date('Y-m-d H:i:s')), 'screensaver_delay' => ($rDevice['screensaver_delay'] ?: '10'), 'stb_type' => $rDevice['stb_type'], 'now_playing_link_id' => ($rDevice['now_playing_link_id'] ?: '0'), 'now_playing_streamer_id' => ($rDevice['now_playing_streamer_id'] ?: '0'), 'last_watchdog' => ($rDevice['last_watchdog'] ? date('Y-m-d H:i:s', $rDevice['last_watchdog']) : date('Y-m-d H:i:s')), 'created' => ($rDevice['created'] ? date('Y-m-d H:i:s', $rDevice['created']) : date('Y-m-d H:i:s')), 'plasma_saving' => ($rDevice['plasma_saving'] ?: '0'), 'ts_enabled' => ($rDevice['ts_enabled'] ?: '0'), 'ts_enable_icon' => ($rDevice['ts_enable_icon'] ?: '1'), 'ts_path' => ($rDevice['ts_path'] ?: ''), 'ts_max_length' => ($rDevice['ts_max_length'] ?: '3600'), 'ts_buffer_use' => ($rDevice['ts_buffer_use'] ?: 'cyclic'), 'ts_action_on_exit' => ($rDevice['ts_action_on_exit'] ?: 'no_save'), 'ts_delay' => ($rDevice['ts_delay'] ?: 'on_pause'), 'video_clock' => ($rDevice['video_clock'] == 'On' ? 'On' : 'Off'), 'hdmi_event_reaction' => ($rDevice['hdmi_event_reaction'] ?: 1), 'show_after_loading' => ($rDevice['show_after_loading'] ?: ''), 'play_in_preview_by_ok' => ($rDevice['play_in_preview_by_ok'] ?: null), 'hw_version' => $rDevice['hw_version'], 'units' => ($rDevice['units'] ?: 'metric'), 'last_itv_id' => ($rDevice['last_itv_id'] ?: 0), 'rtsp_type' => ($rDevice['rtsp_type'] ?: '4'), 'rtsp_flags' => ($rDevice['rtsp_flags'] ?: '0'), 'stb_lang' => ($rDevice['stb_lang'] ?: 'en'), 'display_menu_after_loading' => ($rDevice['display_menu_after_loading'] ?: ''), 'record_max_length' => ($rDevice['record_max_length'] ?: 180), 'play_in_preview_only_by_ok' => ($rDevice['play_in_preview_only_by_ok'] ?: false), 'tv_archive_continued' => ($rDevice['tv_archive_continued'] ?: ''), 'plasma_saving_timeout' => ($rDevice['plasma_saving_timeout'] ?: '600'));
			$rDevice['mac'] = base64_encode($rDevice['mac']);
			$rDevice['generated'] = time();
		}
	} else {
		if (!$rDevice) {
		} else {
			$rLiveIDs = $rVODIDs = $rRadioIDs = $rCategoryIDs = $rChannelIDs = $rSeriesIDs = array();

			foreach ($rDevice['bouquet'] as $rID) {
					if (!isset($rBouquets[$rID]['streams'])) {
				} else {
					$rChannelIDs = array_merge($rChannelIDs, $rBouquets[$rID]['streams']);
				}

				if (!isset($rBouquets[$rID]['series'])) {
				} else {
					$rSeriesIDs = array_merge($rSeriesIDs, $rBouquets[$rID]['series']);
				}

				if (!isset($rBouquets[$rID]['channels'])) {
				} else {
					$rLiveIDs = array_merge($rLiveIDs, $rBouquets[$rID]['channels']);
				}

				if (!isset($rBouquets[$rID]['movies'])) {
				} else {
					$rVODIDs = array_merge($rVODIDs, $rBouquets[$rID]['movies']);
				}

				if (!isset($rBouquets[$rID]['radios'])) {
				} else {
					$rRadioIDs = array_merge($rRadioIDs, $rBouquets[$rID]['radios']);
				}
			}
			$rDevice['channel_ids'] = array_map('intval', array_unique($rChannelIDs));
			$rDevice['series_ids'] = array_map('intval', array_unique($rSeriesIDs));
			$rDevice['vod_ids'] = array_map('intval', array_unique($rVODIDs));
			$rDevice['live_ids'] = array_map('intval', array_unique($rLiveIDs));
			$rDevice['radio_ids'] = array_map('intval', array_unique($rRadioIDs));
		}
	}

	return $rDevice;
}

function getEPG($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false) {
	$rReturn = array();
	$rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : array());

	foreach ($rData as $rItem) {
		if ($rStartDate && !($rStartDate < $rItem['end'] && $rItem['start'] < $rFinishDate)) {
		} else {
			if ($rByID) {
				$rReturn[$rItem['id']] = $rItem;
			} else {
				$rReturn[] = $rItem;
			}
		}
	}

	return $rReturn;
}

function getEPGs($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
	$rReturn = array();

	foreach ($rStreamIDs as $rStreamID) {
		$rReturn[$rStreamID] = getepg($rStreamID, $rStartDate, $rFinishDate);
	}

	return $rReturn;
}

function getProgramme($rStreamID, $rProgrammeID) {
	$rData = getepg($rStreamID, null, null, true);

	if (!isset($rData[$rProgrammeID])) {
	} else {
		return $rData[$rProgrammeID];
	}
}

function updateCache() {
	global $rDevice;
	file_put_contents(MINISTRA_TMP_PATH . 'ministra_' . $rDevice['mag_id'], igbinary_serialize($rDevice));
}

function getMovies($rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array()) {
	global $rDevice;
	global $rPageItems;
	global $rForceProtocol;
	$rDefaultPage = false;
	$rPage = (!empty($rRequest['p']) ? $rRequest['p'] : 0);

	if ($rPage != 0) {
	} else {
		$rDefaultPage = true;
		$rPage = 1;
	}

	$rStart = ($rPage - 1) * $rPageItems;
	$rStreams = getitems(array('movie'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, $rPicking, $rStart, $rPageItems);
	$rDatas = array();

	foreach ($rStreams['streams'] as $rMovie) {
		$rProperties = (!is_array($rMovie['movie_properties']) ? json_decode($rMovie['movie_properties'], true) : $rMovie['movie_properties']);
		$rHD = intval(1200 < $rMovie['stream_info']['codecs']['video']['width']);
		$rPostData = array('type' => 'movie', 'stream_id' => $rMovie['id'], 'target_container' => $rMovie['target_container']);
		$rThisMM = date('m');
		$rThisDD = date('d');
		$rThisYY = date('Y');

		if (mktime(0, 0, 0, $rThisMM, $rThisDD, $rThisYY) < $rMovie['added']) {
			$rAddedKey = 'today';
			$rAddedVal = 'Today';
		} else {
			if (mktime(0, 0, 0, $rThisMM, $rThisDD - 1, $rThisYY) < $rMovie['added']) {
				$rAddedKey = 'yesterday';
				$rAddedVal = 'Yesterday';
			} else {
				if (0 < $rMovie['added']) {
					$rAddedKey = 'week_and_more';
					$rDay = date('d', $rMovie['added']);

					if (11 <= $rDay % 100 && $rDay % 100 <= 13) {
						$rAbb = $rDay . 'th';
					} else {
						$rAbb = $rDay . array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th')[$rDay % 10];
					}

					$rAddedVal = date('M', $rMovie['added']) . ' ' . $rAbb . ' ' . date('Y', $rMovie['added']);
				} else {
					$rAddedKey = 'week_and_more';
					$rAddedVal = 'Unknown';
				}
			}
		}

		$rDuration = (isset($rProperties['duration_secs']) ? $rProperties['duration_secs'] : 60);
		$rDatas[] = array('id' => $rMovie['id'], 'owner' => '', 'name' => $rMovie['stream_display_name'], 'tmdb_id' => $rProperties['tmdb_id'], 'old_name' => '', 'o_name' => $rMovie['stream_display_name'], 'fname' => '', 'description' => (empty($rProperties['plot']) ? 'N/A' : $rProperties['plot']), 'pic' => '', 'cost' => 0, 'time' => intval($rDuration / 60), 'file' => '', 'path' => str_replace(' ', '_', $rMovie['stream_display_name']), 'protocol' => '', 'rtsp_url' => '', 'censored' => intval($rMovie['is_adult']), 'series' => array(), 'volume_correction' => 0, 'category_id' => $rMovie['category_id'], 'genre_id' => 0, 'genre_id_1' => 0, 'genre_id_2' => 0, 'genre_id_3' => 0, 'hd' => $rHD, 'genre_id_4' => 0, 'cat_genre_id_1' => $rMovie['category_id'], 'cat_genre_id_2' => 0, 'cat_genre_id_3' => 0, 'cat_genre_id_4' => 0, 'director' => (empty($rProperties['director']) ? 'N/A' : $rProperties['director']), 'actors' => (empty($rProperties['cast']) ? 'N/A' : $rProperties['cast']), 'year' => $rMovie['year'], 'accessed' => 1, 'status' => 1, 'disable_for_hd_devices' => 0, 'added' => date('Y-m-d H:i:s', $rMovie['added']), 'count' => 0, 'count_first_0_5' => 0, 'count_second_0_5' => 0, 'vote_sound_good' => 0, 'vote_sound_bad' => 0, 'vote_video_good' => 0, 'vote_video_bad' => 0, 'rate' => '', 'last_rate_update' => '', 'last_played' => '', 'for_sd_stb' => 0, 'rating_im' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'rating_count_im' => '', 'rating_last_update' => '0000-00-00 00:00:00', 'age' => '12+', 'high_quality' => 0, 'rating_kinopoisk' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'comments' => '', 'low_quality' => 0, 'is_series' => 0, 'year_end' => 0, 'autocomplete_provider' => 'im', 'screenshots' => '', 'is_movie' => 1, 'lock' => $rMovie['is_adult'], 'fav' => (in_array($rMovie['id'], $rDevice['fav_channels']['movie']) ? 1 : 0), 'for_rent' => 0, 'screenshot_uri' => (empty($rProperties['movie_image']) ? '' : ImageUtils::validateURL($rProperties['movie_image'], $rForceProtocol)), 'genres_str' => (empty($rProperties['genre']) ? 'N/A' : $rProperties['genre']), 'cmd' => base64_encode(json_encode($rPostData, JSON_PARTIAL_OUTPUT_ON_ERROR)), $rAddedKey => $rAddedVal, 'has_files' => 0);
	}

	if ($rDefaultPage) {
	} else {
		$rPage = 0;
	}

	$rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => $rPageItems, 'selected_item' => 0, 'cur_page' => $rPage, 'data' => $rDatas));

	return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function getSeasons($rSeriesID) {
	global $db;
	$db->query('SELECT * FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num DESC, t1.episode_num ASC', $rSeriesID);

	return $db->get_rows(true, 'season_num', false);
}

function getSeries($rMovieID = null, $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array()) {
	global $rDevice;
	global $db;
	global $rPageItems;
	global $rForceProtocol;
	$rPage = (!empty($rRequest['p']) ? $rRequest['p'] : 0);
	$rDefaultPage = false;

	if (empty($rMovieID)) {
		$rItems = getseriesitems($rDevice['user_id'], 'series', $rCategoryID, $rFav, $rOrderBy, $rSearchBy, $rPicking);
	} else {
		$rItems = getSeasons($rMovieID);
		$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rMovieID);
		$rSeriesInfo = $db->get_row();
	}
	if (!is_array($rItems)) {
		$rItems = array();
	}
	$rSeriesFav = (is_array($rDevice['fav_channels']['series'] ?? null) ? $rDevice['fav_channels']['series'] : array());

	$rCounter = count($rItems);
	$rChannelIDx = 0;

	if ($rPage != 0) {
	} else {
		$rDefaultPage = true;
		$rPage = ceil($rChannelIDx / $rPageItems);

		if ($rPage != 0) {
		} else {
			$rPage = 1;
		}
	}

	$rItems = array_slice($rItems, ($rPage - 1) * $rPageItems, $rPageItems, true);
	$rDatas = array();

	foreach ($rItems as $rKey => $rMovie) {
		if (is_null($rFav) || $rFav != 1) {
		} else {
			if (in_array($rMovie['id'], $rSeriesFav)) {
			} else {
				$rCounter--;
			}
		}

		if (!empty($rSeriesInfo)) {
			$rProperties = $rSeriesInfo;
			$rMaxAdded = 0;

			foreach ($rMovie as $vod) {
				if ($rMaxAdded >= $vod['added']) {
				} else {
					$rMaxAdded = $vod['added'];
				}
			}
		} else {
			$rProperties = $rMovie;
			$rMaxAdded = $rMovie['last_modified'];
		}

		$rPostData = array('series_id' => $rMovieID, 'season_num' => $rKey, 'type' => 'series');
		$rThisMM = date('m');
		$rThisDD = date('d');
		$rThisYY = date('Y');

		if (mktime(0, 0, 0, $rThisMM, $rThisDD, $rThisYY) < $rMaxAdded) {
			$rAddedKey = 'today';
			$rAddedVal = 'Today';
		} else {
			if (mktime(0, 0, 0, $rThisMM, $rThisDD - 1, $rThisYY) < $rMaxAdded) {
				$rAddedKey = 'yesterday';
				$rAddedVal = 'Yesterday';
			} else {
				if (mktime(0, 0, 0, $rThisMM, $rThisDD - 7, $rThisYY) < $rMaxAdded) {
					$rAddedKey = 'week_and_more';
					$rAddedVal = 'Last Week';
				} else {
					$rAddedKey = 'week_and_more';

					if (0 < $rMaxAdded) {
						$rAddedVal = date('F', $rMaxAdded) . ' ' . date('Y', $rMaxAdded);
					} else {
						$rAddedVal = 'Unknown';
					}
				}
			}
		}

		if (!empty($rSeriesInfo)) {
			if ($rKey == 0) {
				$rTitle = 'Specials';
			} else {
				$rTitle = 'Season ' . $rKey;
			}
		} else {
			$rTitle = $rMovie['title'];
		}

		$rDatas[] = array('id' => $rProperties['id'], 'owner' => '', 'name' => $rTitle, 'tmdb_id' => $rProperties['tmdb_id'], 'old_name' => '', 'o_name' => $rTitle, 'fname' => '', 'description' => (empty($rProperties['plot']) ? 'N/A' : $rProperties['plot']), 'pic' => '', 'cost' => 0, 'time' => 'N/a', 'file' => '', 'path' => str_replace(' ', '_', $rProperties['title']), 'protocol' => '', 'rtsp_url' => '', 'censored' => 0, 'series' => (!empty($rSeriesInfo) ? range(1, count($rMovie)) : array()), 'volume_correction' => 0, 'category_id' => $rProperties['category_id'], 'genre_id' => 0, 'genre_id_1' => 0, 'genre_id_2' => 0, 'genre_id_3' => 0, 'hd' => 1, 'genre_id_4' => 0, 'cat_genre_id_1' => $rProperties['category_id'], 'cat_genre_id_2' => 0, 'cat_genre_id_3' => 0, 'cat_genre_id_4' => 0, 'director' => (empty($rProperties['director']) ? 'N/A' : $rProperties['director']), 'actors' => (empty($rProperties['cast']) ? 'N/A' : $rProperties['cast']), 'year' => (empty($rProperties['release_date']) ? 'N/A' : $rProperties['release_date']), 'accessed' => 1, 'status' => 1, 'disable_for_hd_devices' => 0, 'added' => date('Y-m-d H:i:s', $rMaxAdded), 'count' => 0, 'count_first_0_5' => 0, 'count_second_0_5' => 0, 'vote_sound_good' => 0, 'vote_sound_bad' => 0, 'vote_video_good' => 0, 'vote_video_bad' => 0, 'rate' => '', 'last_rate_update' => '', 'last_played' => '', 'for_sd_stb' => 0, 'rating_im' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'rating_count_im' => '', 'rating_last_update' => '0000-00-00 00:00:00', 'age' => '12+', 'high_quality' => 0, 'rating_kinopoisk' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'comments' => '', 'low_quality' => 0, 'is_series' => 1, 'year_end' => 0, 'autocomplete_provider' => 'im', 'screenshots' => '', 'is_movie' => 1, 'lock' => 0, 'fav' => (in_array($rProperties['id'], $rSeriesFav) ? 1 : 0), 'for_rent' => 0, 'screenshot_uri' => (empty($rProperties['cover']) ? '' : ImageUtils::validateURL($rProperties['cover'], $rForceProtocol)), 'genres_str' => (empty($rProperties['genre']) ? 'N/A' : $rProperties['genre']), 'cmd' => (!empty($rSeriesInfo) ? base64_encode(json_encode($rPostData, JSON_PARTIAL_OUTPUT_ON_ERROR)) : ''), $rAddedKey => $rAddedVal, 'has_files' => (empty($rMovieID) ? 1 : 0));
	}

	if ($rDefaultPage) {
		$rCurrentPage = $rPage;
		$rSelectedItem = $rChannelIDx - ($rPage - 1) * $rPageItems;
	} else {
		$rCurrentPage = 0;
		$rSelectedItem = 0;
	}

	$rOutput = array('js' => array('total_items' => $rCounter, 'max_page_items' => $rPageItems, 'selected_item' => $rSelectedItem, 'cur_page' => $rCurrentPage, 'data' => $rDatas));

	return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function sortArrayStreamNumber($a, $b) {
	if ($a['number'] != $b['number']) {
		return ($a['number'] < $b['number'] ? -1 : 1);
	}

	return 0;
}

function sortArrayStreamName($a, $b) {
	$rColumn = (isset($a['stream_display_name']) ? 'stream_display_name' : 'title');

	return strcmp($a[$rColumn], $b[$rColumn]);
}

function getStations($rCategoryID = null, $rFav = null, $rOrderBy = null) {
	global $rDevice;
	global $rPlayer;
	global $rPageItems;
	global $rRequest;
	global $rSettings;
	global $rServers;
	$rDefaultPage = false;
	$rPage = (!empty($rRequest['p']) ? $rRequest['p'] : 0);

	if ($rPage != 0) {
	} else {
		$rDefaultPage = true;
		$rPage = 1;
	}

	$rStart = ($rPage - 1) * $rPageItems;
	$rStreams = getitems(array('radio_streams'), $rCategoryID, $rFav, $rOrderBy, null, null, $rStart, $rPageItems);
	$rDatas = array();
	$i = $rStart + 1;
	$rFavRadioStreams = $rDevice['fav_channels']['radio_streams'] ?? array();

	foreach ($rStreams['streams'] as $rStream) {
		if ($rSettings['mag_security'] == 0) {
			$rEncData = 'ministra::live/' . $rDevice['username'] . '/' . $rDevice['password'] . '/' . $rStream['id'] . '/' . $rSettings['mag_container'] . '/' . $rDevice['token'];
			$rToken = Encryption::encrypt($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rStreamURL = (($rSettings['mag_disable_ssl'] ? $rServers[SERVER_ID]['http_url'] : $rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

			if (!$rSettings['mag_keep_extension']) {
			} else {
				$rStreamURL .= '?ext=.' . $rSettings['mag_container'];
			}

			$rStreamSourceSt = 0;
		} else {
			$rStreamURL = 'http://localhost/ch/' . $rStream['id'] . '_';
			$rStreamSourceSt = 1;
		}

		$rDatas[] = array('id' => $rStream['id'], 'name' => $rStream['stream_display_name'], 'number' => $i++, 'cmd' => $rPlayer . $rStreamURL, 'count' => 0, 'open' => 1, 'status' => 1, 'volume_correction' => 0, 'use_http_tmp_link' => (string) $rStreamSourceSt, 'fav' => (in_array($rStream['id'], $rFavRadioStreams) ? 1 : 0));
	}

	if ($rDefaultPage) {
	} else {
		$rPage = 0;
	}

	$rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => $rPageItems, 'selected_item' => 0, 'cur_page' => $rPage, 'data' => $rDatas));

	return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function getStreams($rCategoryID = null, $rAll = false, $rFav = null, $rOrderBy = null, $rSearchBy = null) {
	global $rDevice;
	global $rPlayer;
	global $rPageItems;
	global $rRequest;
	global $rSettings;
	global $rTimezone;
	global $rForceProtocol;
	global $rServers;
	$rDefaultPage = false;
	$rPage = (isset($rRequest['p']) ? intval($rRequest['p']) : 0);

	if (!($rPage == 0 && $rCategoryID != -1)) {
	} else {
		$rDefaultPage = true;

		if ($rRequest['p'] != 0 || empty($rDevice['last_itv_id'])) {
		} else {
			$rPosition = getitems(array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, 0, 0, $rDevice['last_itv_id']);

			if ($rPosition) {
				$rPage = floor(($rPosition - 1) / $rPageItems) + 1;
				$rPosition = $rPosition - ($rPage - 1) * $rPageItems;
			} else {
				$rPosition = 0;
			}
		}

		if ($rPage != 0) {
		} else {
			$rPage = 1;
		}
	}

	$rStart = ($rPage - 1) * $rPageItems;

	if ($rCategoryID == -1) {
		if ($rSettings['mag_load_all_channels']) {
			$rStreams = getitems(array('live', 'created_live'), (0 < $rCategoryID ? $rCategoryID : null), $rFav, $rOrderBy, $rSearchBy, null, 0, 0);
		} else {
			return '{"js":{"total_items":0,"max_page_items":14,"selected_item":0,"cur_page":0,"data":[]}}';
		}
	} else {
		if ($rAll) {
			$rStreams = getitems(array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, 0, 0);
		} else {
			$rStreams = getitems(array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, $rStart, $rPageItems);
		}
	}

	$rDatas = array();
	$rTimeDifference = TimeUtils::getDiffTimezone($rTimezone);

	foreach ($rStreams['streams'] as $rStream) {
		$rHD = intval(1200 < $rStream['stream_info']['codecs']['video']['width']);

		if ($rSettings['mag_security'] == 0) {
			$rEncData = 'ministra::live/' . $rDevice['username'] . '/' . $rDevice['password'] . '/' . $rStream['id'] . '/' . $rSettings['mag_container'] . '/' . $rDevice['token'];
			$rToken = Encryption::encrypt($rEncData, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rStreamURL = (($rSettings['mag_disable_ssl'] ? $rServers[SERVER_ID]['http_url'] : $rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

			if (!$rSettings['mag_keep_extension']) {
			} else {
				$rStreamURL .= '?ext=.' . $rSettings['mag_container'];
			}

			$rStreamSourceSt = 0;
		} else {
			$rStreamURL = 'http://localhost/ch/' . $rStream['id'] . '_';
			$rStreamSourceSt = 1;
		}

		if ($rStream['now_playing']) {
			$rStartTime = new DateTime();
			$rStartTime->setTimestamp($rStream['now_playing']['start']);
			$rStartTime->modify((string) $rTimeDifference . ' seconds');
			$rEndTime = new DateTime();
			$rEndTime->setTimestamp($rStream['now_playing']['end']);
			$rEndTime->modify((string) $rTimeDifference . ' seconds');
			$rNowPlaying = $rStartTime->format('H:i') . ' - ' . $rEndTime->format('H:i') . ': ' . $rStream['now_playing']['title'];
		} else {
			$rNowPlaying = 'No channel information is available...';
		}

		$rDatas[] = array('id' => intval($rStream['id']), 'name' => $rStream['stream_display_name'], 'number' => (string) $rStream['number'], 'snumber' => (string) $rStream['number'], 'censored' => ($rStream['is_adult'] == 1 ? 1 : 0), 'cmd' => $rPlayer . $rStreamURL, 'cost' => '0', 'count' => '0', 'status' => 1, 'tv_genre_id' => $rStream['category_id'], 'base_ch' => '1', 'hd' => $rHD, 'xmltv_id' => (!empty($rStream['channel_id']) ? $rStream['channel_id'] : ''), 'service_id' => '', 'bonus_ch' => '0', 'volume_correction' => '0', 'use_http_tmp_link' => $rStreamSourceSt, 'mc_cmd' => '', 'enable_tv_archive' => (0 < $rStream['tv_archive_duration'] ? 1 : 0), 'wowza_tmp_link' => '0', 'wowza_dvr' => '0', 'monitoring_status' => '1', 'enable_monitoring' => '0', 'enable_wowza_load_balancing' => '0', 'cmd_1' => '', 'cmd_2' => '', 'cmd_3' => '', 'logo' => ImageUtils::validateURL($rStream['stream_icon'], $rForceProtocol), 'correct_time' => '0', 'nimble_dvr' => '0', 'allow_pvr' => (int) $rStream['allow_record'], 'allow_local_pvr' => (int) $rStream['allow_record'], 'allow_remote_pvr' => 0, 'modified' => '', 'allow_local_timeshift' => '1', 'nginx_secure_link' => $rStreamSourceSt, 'tv_archive_duration' => (0 < $rStream['tv_archive_duration'] ? $rStream['tv_archive_duration'] * 24 : 0), 'locked' => 0, 'lock' => $rStream['is_adult'], 'fav' => (in_array($rStream['id'], $rDevice['fav_channels']['live']) ? 1 : 0), 'archive' => (0 < $rStream['tv_archive_duration'] ? 1 : 0), 'genres_str' => '', 'cur_playing' => $rNowPlaying, 'epg' => array(), 'open' => 1, 'cmds' => array(array('id' => (string) $rStream['id'], 'ch_id' => (string) $rStream['id'], 'priority' => '0', 'url' => $rPlayer . $rStreamURL, 'status' => '1', 'use_http_tmp_link' => $rStreamSourceSt, 'wowza_tmp_link' => '0', 'user_agent_filter' => '', 'use_load_balancing' => '0', 'changed' => '', 'enable_monitoring' => '0', 'enable_balancer_monitoring' => '0', 'nginx_secure_link' => $rStreamSourceSt, 'flussonic_tmp_link' => '0')), 'use_load_balancing' => 0, 'pvr' => (int) $rStream['allow_record']);
	}

	if ($rDefaultPage) {
	} else {
		$rPage = 0;
		$rPosition = 0;
	}

	$rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => intval($rPageItems), 'selected_item' => $rPosition, 'cur_page' => ($rAll ? 0 : $rPage), 'data' => $rDatas));

	return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function getHeaders() {
	$rHeaders = array();

	foreach ($_SERVER as $rName => $rValue) {
		if (substr($rName, 0, 5) != 'HTTP_') {
		} else {
			$rHeaders[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($rName, 5)))))] = $rValue;
		}
	}

	return $rHeaders;
}

function shutdown() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}
