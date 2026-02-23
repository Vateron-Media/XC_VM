<?php

class PortalHandler
{
    /**
     * Phase 1: Pre-init stub responses (no DB needed).
     * Exits immediately if the action is handled, otherwise returns void.
     *
     * @param string|null $rReqType
     * @param string|null $rReqAction
     */
    public static function handlePreInit($rReqType, $rReqAction)
    {
        if ($rReqType && $rReqAction) {
            switch ($rReqType) {
                case 'stb':
                    switch ($rReqAction) {
                        case 'get_ad':
                            exit(json_encode(array('js' => array())));

                        case 'get_storages':
                            exit(json_encode(array('js' => array())));

                        case 'log':
                            exit(json_encode(array('js' => true)));

                        case 'get_countries':
                            exit(json_encode(array('js' => array())));

                        case 'get_timezones':
                            exit(json_encode(array('js' => array())));

                        case 'get_cities':
                            exit(json_encode(array('js' => array())));

                        case 'search_cities':
                            exit(json_encode(array('js' => array())));
                    }

                    break;

                case 'remote_pvr':
                    switch ($rReqAction) {
                        case 'start_record_on_stb':
                            exit(json_encode(array('js' => true)));

                        case 'stop_record_on_stb':
                            exit(json_encode(array('js' => true)));

                        case 'get_active_recordings':
                            exit(json_encode(array('js' => array())));
                    }

                    break;

                case 'media_favorites':
                    exit(json_encode(array('js' => '')));

                case 'tvreminder':
                    exit(json_encode(array('js' => array())));

                case 'series':
                case 'vod':
                    switch ($rReqAction) {
                        case 'set_not_ended':
                            exit(json_encode(array('js' => true)));

                        case 'del_link':
                            exit(json_encode(array('js' => true)));

                        case 'log':
                            exit(json_encode(array('js' => 1)));
                    }

                    break;

                case 'downloads':
                    exit(json_encode(array('js' => true)));

                case 'weatherco':
                    exit(json_encode(array('js' => false)));

                case 'course':
                    exit(json_encode(array('js' => true)));

                case 'account_info':
                    switch ($rReqAction) {
                        case 'get_terms_info':
                            exit(json_encode(array('js' => true)));

                        case 'get_payment_info':
                            exit(json_encode(array('js' => true)));

                        case 'get_demo_video_parts':
                            exit(json_encode(array('js' => true)));

                        case 'get_agreement_info':
                            exit(json_encode(array('js' => true)));
                    }

                    break;

                case 'tv_archive':
                    switch ($rReqAction) {
                        case 'set_played_timeshift':
                            exit(json_encode(array('js' => true)));

                        case 'set_played':
                            exit(json_encode(array('js' => true)));

                        case 'update_played_timeshift_end_time':
                            exit(json_encode(array('js' => true)));
                    }

                    break;

                case 'itv':
                    switch ($rReqAction) {
                        case 'set_fav_status':
                            exit(json_encode(array('js' => array())));

                        case 'set_played':
                            exit(json_encode(array('js' => true)));
                    }

                    break;
            }
        }
    }

    /**
     * Phase 4: Unauthenticated STB actions (get_profile, get_localization, log, get_modules).
     * Called when $rReqType == 'stb' from the outer switch.
     * Exits if action is handled, otherwise returns void.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array with device, profile, language, theme, authenticated, etc.
     */
    public static function handleStbPublic($rReqAction, &$ctx)
    {
        switch ($rReqAction) {
            case 'get_profile':
                $rTotal = ($ctx['authenticated'] ? array_merge($ctx['profile'], $ctx['device']['get_profile_vars']) : $ctx['profile']);
                $rTotal['status'] = intval(!$ctx['authenticated']);
                $rTotal['update_url'] = (empty(StreamingUtilities::$rSettings['update_url']) ? '' : StreamingUtilities::$rSettings['update_url']);
                $rTotal['test_download_url'] = (empty(StreamingUtilities::$rSettings['test_download_url']) ? '' : StreamingUtilities::$rSettings['test_download_url']);
                $rTotal['default_timezone'] = StreamingUtilities::$rSettings['default_timezone'];
                $rTotal['default_locale'] = $ctx['device']['locale'];
                $rTotal['allowed_stb_types'] = StreamingUtilities::$rSettings['allowed_stb_types'];
                $rTotal['allowed_stb_types_for_local_recording'] = StreamingUtilities::$rSettings['allowed_stb_types'];
                $rTotal['storages'] = array();
                $rTotal['tv_channel_default_aspect'] = (empty(StreamingUtilities::$rSettings['tv_channel_default_aspect']) ? 'fit' : StreamingUtilities::$rSettings['tv_channel_default_aspect']);
                $rTotal['playback_limit'] = (empty(StreamingUtilities::$rSettings['playback_limit']) ? false : intval(StreamingUtilities::$rSettings['playback_limit']));

                if (!empty($rTotal['playback_limit'])) {
                } else {
                    $rTotal['enable_playback_limit'] = false;
                }

                $rTotal['show_tv_channel_logo'] = !empty(StreamingUtilities::$rSettings['show_tv_channel_logo']);
                $rTotal['show_channel_logo_in_preview'] = !empty(StreamingUtilities::$rSettings['show_channel_logo_in_preview']);
                $rTotal['enable_connection_problem_indication'] = !empty(StreamingUtilities::$rSettings['enable_connection_problem_indication']);
                $rTotal['hls_fast_start'] = '1';
                $rTotal['check_ssl_certificate'] = 0;
                $rTotal['enable_buffering_indication'] = 1;
                $rTotal['watchdog_timeout'] = mt_rand(80, 120);

                if (!(empty($rTotal['aspect']) && StreamingUtilities::$rServers[SERVER_ID]['server_protocol'] == 'https')) {
                } else {
                    $rTotal['aspect'] = '16';
                }

                exit(json_encode(array('js' => $rTotal), JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_localization':
                exit(json_encode(array('js' => $ctx['language'][$ctx['device']['locale']])));

            case 'log':
                exit(json_encode(array('js' => true)));

            case 'get_modules':
                $rModules = array('all_modules' => array('media_browser', 'vclub', 'tv', 'sclub', 'radio', 'dvb', 'tv_archive', 'time_shift', 'time_shift_local', 'epg.reminder', 'epg.recorder', 'epg', 'epg.simple', 'downloads_dialog', 'downloads', 'records', 'pvr_local', 'settings.parent', 'settings.localization', 'settings.update', 'settings.playback', 'settings.common', 'settings.network_status', 'settings', 'account', 'internet', 'logout', 'account_menu'), 'switchable_modules' => array('sclub', 'vlub'), 'disabled_modules' => array('records', 'downloads', 'settings.update', 'settings.common', 'pvr_local', 'media_browser'), 'restricted_modules' => array(), 'template' => $ctx['theme'], 'launcher_url' => '', 'launcher_profile_url' => '');

                exit(json_encode(array('js' => $rModules)));
        }
    }

    /**
     * Phase 5: Authenticated action dispatcher.
     * Sets up player, then dispatches to the appropriate sub-handler.
     *
     * @param string $rReqType
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleAuthenticated($rReqType, $rReqAction, &$ctx)
    {
        $ctx['device']['mag_player'] = trim($ctx['device']['mag_player'], "'\"");
        $ctx['player'] = (!empty($ctx['device']['mag_player']) ? $ctx['device']['mag_player'] . ' ' : 'ffmpeg ');

        switch ($rReqType) {
            case 'stb':
                self::handleStbSettings($rReqAction, $ctx);

                break;

            case 'watchdog':
                self::handleWatchdog($rReqAction, $ctx);

                break;

            case 'audioclub':
                self::handleAudioclub($rReqAction, $ctx);

                break;

            case 'itv':
                self::handleItv($rReqAction, $ctx);

                break;

            case 'vod':
                self::handleVod($rReqAction, $ctx);

                break;

            case 'series':
                self::handleSeries($rReqAction, $ctx);

                break;

            case 'account_info':
                self::handleAccountInfo($rReqAction, $ctx);

                break;

            case 'radio':
                self::handleRadio($rReqAction, $ctx);

                break;

            case 'tv_archive':
                self::handleTvArchive($rReqAction, $ctx);

                break;

            case 'epg':
                self::handleEpg($rReqAction, $ctx);

                break;
        }
    }

    /**
     * Phase 5 sub-handler: Authenticated STB settings actions.
     * Actions: set_modern, set_legacy, get_preload_images, get_settings_profile,
     *          get_locales, get_tv_aspects, set_volume, set_aspect, set_stream_error,
     *          set_screensaver_delay, set_playback_buffer, set_plasma_saving,
     *          set_parent_password, set_locale, set_hdmi_reaction.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleStbSettings($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'set_modern':
                $ctx['device']['theme_type'] = 0;
                $db->query('UPDATE `mag_devices` SET `theme_type` = 0 WHERE `mag_id` = ?', $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('data' => true)));

            case 'set_legacy':
                $ctx['device']['theme_type'] = 1;
                $db->query('UPDATE `mag_devices` SET `theme_type` = 1 WHERE `mag_id` = ?', $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('data' => true)));

            case 'get_preload_images':
                $rMode = (is_numeric($ctx['gMode']) ? 'i_' . $ctx['gMode'] : 'i');
                $rImages = array('template/' . $ctx['theme'] . '/' . $rMode . '/alert_triangle.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/archive.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/archive_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/bg.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/bg2.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/ears_arrow_l.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/ears_arrow_r.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/hd.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/hd_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mb_prev_bg.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_hor_surround.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_account.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_default.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_internet.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_mb.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_radio.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_setting.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_tv.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_video.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/mm_ico_youtube.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/left_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/logo.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/play.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/play_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/rec.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/rec_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/right_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/star.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/star_white.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/tv_prev_bg.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/volume_bar.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/volume_bg.png', 'template/' . $ctx['theme'] . '/' . $rMode . '/volume_off.png');

                exit(json_encode(array('js' => $rImages)));

            case 'get_settings_profile':
                $db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?', $ctx['device']['mag_id']);
                $rInfo = $db->get_row();
                $rSettings = array('js' => array('modules' => array(array('name' => 'lock'), array('name' => 'lang'), array('name' => 'update'), array('name' => 'net_info', 'sub' => array(array('name' => 'wired'), array('name' => 'pppoe', 'sub' => array(array('name' => 'dhcp'), array('name' => 'dhcp_manual'), array('name' => 'disable'))), array('name' => 'wireless'), array('name' => 'speed'))), array('name' => 'video'), array('name' => 'audio'), array('name' => 'net', 'sub' => array(array('name' => 'ethernet', 'sub' => array(array('name' => 'dhcp'), array('name' => 'dhcp_manual'), array('name' => 'manual'), array('name' => 'no_ip'))), array('name' => 'pppoe', 'sub' => array(array('name' => 'dhcp'), array('name' => 'dhcp_manual'), array('name' => 'disable'))), array('name' => 'wifi', 'sub' => array(array('name' => 'dhcp'), array('name' => 'dhcp_manual'), array('name' => 'manual'))), array('name' => 'speed'))), array('name' => 'advanced'), array('name' => 'dev_info'), array('name' => 'reload'), array('name' => 'internal_portal'), array('name' => 'reboot'))));
                $rSettings['js']['parent_password'] = $rInfo['parent_password'];
                $rSettings['js']['update_url'] = StreamingUtilities::$rSettings['update_url'];
                $rSettings['js']['test_download_url'] = StreamingUtilities::$rSettings['test_download_url'];
                $rSettings['js']['playback_buffer_size'] = $rInfo['playback_buffer_size'];
                $rSettings['js']['screensaver_delay'] = $rInfo['screensaver_delay'];
                $rSettings['js']['plasma_saving'] = $rInfo['plasma_saving'];
                $rSettings['js']['spdif_mode'] = $rInfo['spdif_mode'];
                $rSettings['js']['ts_enabled'] = $rInfo['ts_enabled'];
                $rSettings['js']['ts_enable_icon'] = $rInfo['ts_enable_icon'];
                $rSettings['js']['ts_path'] = $rInfo['ts_path'];
                $rSettings['js']['ts_max_length'] = $rInfo['ts_max_length'];
                $rSettings['js']['ts_buffer_use'] = $rInfo['ts_buffer_use'];
                $rSettings['js']['ts_action_on_exit'] = $rInfo['ts_action_on_exit'];
                $rSettings['js']['ts_delay'] = $rInfo['ts_delay'];
                $rSettings['js']['hdmi_event_reaction'] = $rInfo['hdmi_event_reaction'];
                $rSettings['js']['pri_audio_lang'] = $ctx['profile']['pri_audio_lang'];
                $rSettings['js']['show_after_loading'] = $rInfo['show_after_loading'];
                $rSettings['js']['sec_audio_lang'] = $ctx['profile']['sec_audio_lang'];

                if (StreamingUtilities::$rSettings['always_enabled_subtitles'] == 1) {
                    $rSettings['js']['pri_subtitle_lang'] = $ctx['profile']['pri_subtitle_lang'];
                    $rSettings['js']['sec_subtitle_lang'] = $ctx['profile']['sec_subtitle_lang'];
                } else {
                    $rSettings['js']['sec_subtitle_lang'] = '';
                    $rSettings['js']['pri_subtitle_lang'] = $rSettings['js']['sec_subtitle_lang'];
                }

                exit(json_encode($rSettings));

            case 'get_locales':
                $db->query('SELECT `locale` FROM `mag_devices` WHERE `mag_id` = ?', $ctx['device']['mag_id']);
                $rSelected = $db->get_row();
                $rOutput = array();

                foreach ($ctx['locales']['get_locales'] as $country => $code) {
                    $rSelected = ($rSelected['locale'] == $code ? 1 : 0);
                    $rOutput[] = array('label' => $country, 'value' => $code, 'selected' => $rSelected);
                }

                exit(json_encode(array('js' => $rOutput)));

            case 'get_tv_aspects':
                if (!empty($ctx['device']['aspect'])) {
                    exit($ctx['device']['aspect']);
                }

                exit(json_encode($ctx['device']['aspect']));

            case 'set_volume':
                $rVolume = StreamingUtilities::$rRequest['vol'];

                if (empty($rVolume)) {
                    break;
                }

                $ctx['device']['volume'] = $rVolume;
                $db->query('UPDATE `mag_devices` SET `volume` = ? WHERE `mag_id` = ?', $rVolume, $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('data' => true)));

            case 'set_aspect':
                $rChannelID = StreamingUtilities::$rRequest['ch_id'];
                $rAspect = StreamingUtilities::$rRequest['aspect'];
                $rDeviceAspect = $ctx['device']['aspect'];

                if (empty($rDeviceAspect)) {
                    $ctx['device']['aspect'] = array('js' => array($rChannelID => $rAspect));
                    $db->query('UPDATE `mag_devices` SET `aspect` = ? WHERE mag_id = ?', json_encode(array('js' => array($rChannelID => $rAspect))), $ctx['device']['mag_id']);
                } else {
                    $rDeviceAspect = json_decode($rDeviceAspect, true);
                    $rDeviceAspect['js'][$rChannelID] = $rAspect;
                    $ctx['device']['aspect'] = $rDeviceAspect;
                    $db->query('UPDATE `mag_devices` SET `aspect` = ? WHERE mag_id = ?', json_encode($rDeviceAspect), $ctx['device']['mag_id']);
                }

                updatecache();

                exit(json_encode(array('js' => true)));

            case 'set_stream_error':
                exit(json_encode(array('js' => true)));

            case 'set_screensaver_delay':
                if (empty($_SERVER['HTTP_COOKIE'])) {
                } else {
                    $rDelay = intval(StreamingUtilities::$rRequest['screensaver_delay']);
                    $ctx['device']['screensaver_delay'] = $rDelay;
                    $db->query('UPDATE `mag_devices` SET `screensaver_delay` = ? WHERE `mag_id` = ?', $rDelay, $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'set_playback_buffer':
                if (empty($_SERVER['HTTP_COOKIE'])) {
                } else {
                    $rBufferBytes = intval(StreamingUtilities::$rRequest['playback_buffer_bytes']);
                    $rBufferSize = intval(StreamingUtilities::$rRequest['playback_buffer_size']);
                    $ctx['device']['playback_buffer_bytes'] = $rBufferBytes;
                    $ctx['device']['playback_buffer_size'] = $rBufferSize;
                    $db->query('UPDATE `mag_devices` SET `playback_buffer_bytes` = ? , `playback_buffer_size` = ? WHERE `mag_id` = ?', $rBufferBytes, $rBufferSize, $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'set_plasma_saving':
                $rPlasmaSaving = intval(StreamingUtilities::$rRequest['plasma_saving']);
                $ctx['device']['plasma_saving'] = $rPlasmaSaving;
                $db->query('UPDATE `mag_devices` SET `plasma_saving` = ? WHERE `mag_id` = ?', $rPlasmaSaving, $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('js' => true)));

            case 'set_parent_password':
                if (isset(StreamingUtilities::$rRequest['parent_password']) && isset(StreamingUtilities::$rRequest['pass']) && isset(StreamingUtilities::$rRequest['repeat_pass']) && StreamingUtilities::$rRequest['pass'] == StreamingUtilities::$rRequest['repeat_pass']) {
                    $ctx['device']['parent_password'] = StreamingUtilities::$rRequest['pass'];
                    $db->query('UPDATE `mag_devices` SET `parent_password` = ? WHERE `mag_id` = ?', StreamingUtilities::$rRequest['pass'], $ctx['device']['mag_id']);
                    updatecache();

                    exit(json_encode(array('js' => true)));
                }

                exit(json_encode(array('js' => true)));

            case 'set_locale':
                if (empty(StreamingUtilities::$rRequest['locale'])) {
                } else {
                    $ctx['device']['locale'] = StreamingUtilities::$rRequest['locale'];
                    $db->query('UPDATE `mag_devices` SET `locale` = ? WHERE `mag_id` = ?', StreamingUtilities::$rRequest['locale'], $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => array())));

            case 'set_hdmi_reaction':
                if (empty($_SERVER['HTTP_COOKIE']) || !isset(StreamingUtilities::$rRequest['data'])) {
                } else {
                    $rReaction = StreamingUtilities::$rRequest['data'];
                    $ctx['device']['hdmi_event_reaction'] = $rReaction;
                    $db->query('UPDATE `mag_devices` SET `hdmi_event_reaction` = ? WHERE `mag_id` = ?', $rReaction, $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));
        }
    }

    /**
     * Phase 5 sub-handler: Watchdog actions.
     * Actions: get_events, confirm_event.
     * Also updates last_watchdog before processing action.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleWatchdog($rReqAction, &$ctx)
    {
        global $db;

        $ctx['device']['last_watchdog'] = time();
        $db->query('UPDATE `mag_devices` SET `last_watchdog` = ? WHERE `mag_id` = ?', time(), $ctx['device']['mag_id']);
        updatecache();

        switch ($rReqAction) {
            case 'get_events':
                $db->query('SELECT * FROM `mag_events` WHERE `mag_device_id` = ? AND `status` = 0 ORDER BY `id` ASC LIMIT 1', $ctx['device']['mag_id']);
                $rData = array('data' => array('msgs' => 0, 'additional_services_on' => 1));

                if (0 >= $db->num_rows()) {
                } else {
                    $rEvents = $db->get_row();
                    $db->query('SELECT count(*) FROM `mag_events` WHERE `mag_device_id` = ? AND `status` = 0 ', $ctx['device']['mag_id']);
                    $rMessages = $db->get_col();
                    $rData = array('data' => array('msgs' => $rMessages, 'id' => $rEvents['id'], 'event' => $rEvents['event'], 'need_confirm' => $rEvents['need_confirm'], 'msg' => $rEvents['msg'], 'reboot_after_ok' => $rEvents['reboot_after_ok'], 'auto_hide_timeout' => $rEvents['auto_hide_timeout'], 'send_time' => date('d-m-Y H:i:s', $rEvents['send_time']), 'additional_services_on' => $rEvents['additional_services_on'], 'updated' => array('anec' => $rEvents['anec'], 'vclub' => $rEvents['vclub'])));
                    $rAutoStatus = array('reboot', 'reload_portal', 'play_channel', 'cut_off');

                    if (!in_array($rEvents['event'], $rAutoStatus)) {
                    } else {
                        $db->query('UPDATE `mag_events` SET `status` = 1 WHERE `id` = ?', $rEvents['id']);
                    }
                }

                exit(json_encode(array('js' => $rData), JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'confirm_event':
                if (empty(StreamingUtilities::$rRequest['event_active_id'])) {
                    break;
                }

                $rActiveID = StreamingUtilities::$rRequest['event_active_id'];
                $db->query('UPDATE `mag_events` SET `status` = 1 WHERE `id` = ?', $rActiveID);

                exit(json_encode(array('js' => array('data' => 'ok'))));
        }
    }

    /**
     * Phase 5 sub-handler: Audioclub actions.
     * Actions: get_categories.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleAudioclub($rReqAction, &$ctx)
    {
        switch ($rReqAction) {
            case 'get_categories':
                $rOutput = array();
                $rOutput['js'] = array();

                if (StreamingUtilities::$rSettings['show_all_category_mag'] != 1) {
                } else {
                    $rOutput['js'][] = array('id' => '*', 'title' => 'All', 'alias' => '*', 'censored' => 0);
                }

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'movie' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name'], 'alias' => $rCategory['category_name'], 'censored' => intval($rCategory['is_adult']));
                    }
                }

                exit(json_encode($rOutput));
        }
    }

    /**
     * Phase 5 sub-handler: ITV (live TV) actions.
     * Actions: create_link, set_claim, set_fav, get_fav_ids, get_all_channels,
     *          get_ordered_list, get_all_fav_channels, get_epg_info, get_short_epg,
     *          set_last_id, get_genres.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleItv($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'create_link':
                $rCommand = StreamingUtilities::$rRequest['cmd'];
                $rValue = 'http://localhost/ch/';
                list($rStreamID, $rStreamValue) = explode('_', substr($rCommand, strpos($rCommand, $rValue) + strlen($rValue)));

                if (empty($rStreamValue)) {
                    $rEncData = 'ministra::live/' . $ctx['device']['username'] . '/' . $ctx['device']['password'] . '/' . $rStreamID . '/' . StreamingUtilities::$rSettings['mag_container'] . '/' . $ctx['device']['token'];
                    $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                    $rURL = $ctx['player'] . ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

                    if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                    } else {
                        $rURL .= '?ext=.' . StreamingUtilities::$rSettings['mag_container'];
                    }
                } else {
                    $rURL = $ctx['player'] . $rStreamValue;
                }

                exit(json_encode(array('js' => array('id' => $rStreamID, 'cmd' => $rURL), 'streamer_id' => 0, 'link_id' => 0, 'load' => 0, 'error' => '')));

            case 'set_claim':
                if (empty(StreamingUtilities::$rRequest['id']) || empty(StreamingUtilities::$rRequest['real_type'])) {
                } else {
                    $rID = intval(StreamingUtilities::$rRequest['id']);
                    $rRealType = StreamingUtilities::$rRequest['real_type'];
                    $rDate = date('Y-m-d H:i:s');
                    $db->query('INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)', $rID, $ctx['device']['mag_id'], $rRealType, $rDate);
                }

                exit(json_encode(array('js' => true)));

            case 'set_fav':
                $rChannels = (empty(StreamingUtilities::$rRequest['fav_ch']) ? '' : StreamingUtilities::$rRequest['fav_ch']);
                $rChannels = array_filter(array_map('intval', explode(',', $rChannels)));
                $ctx['device']['fav_channels']['live'] = $rChannels;
                $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('js' => true)));

            case 'get_fav_ids':
                exit(json_encode(array('js' => $ctx['device']['fav_channels']['live'])));

            case 'get_all_channels':
                $rGenre = (empty(StreamingUtilities::$rRequest['genre']) || !is_numeric(StreamingUtilities::$rRequest['genre']) ? null : intval(StreamingUtilities::$rRequest['genre']));

                exit(getStreams($rGenre, true));

            case 'get_ordered_list':
                $rFav = (!empty(StreamingUtilities::$rRequest['fav']) ? 1 : null);
                $rSortBy = (!empty(StreamingUtilities::$rRequest['sortby']) ? StreamingUtilities::$rRequest['sortby'] : null);
                $rGenre = (empty(StreamingUtilities::$rRequest['genre']) || !is_numeric(StreamingUtilities::$rRequest['genre']) ? null : intval(StreamingUtilities::$rRequest['genre']));
                $rSearch = (!empty(StreamingUtilities::$rRequest['search']) ? StreamingUtilities::$rRequest['search'] : null);

                exit(getStreams($rGenre, false, $rFav, $rSortBy, $rSearch));

            case 'get_all_fav_channels':
                $rGenre = (empty(StreamingUtilities::$rRequest['genre']) || !is_numeric(StreamingUtilities::$rRequest['genre']) ? null : intval(StreamingUtilities::$rRequest['genre']));

                exit(getStreams($rGenre, true, 1));

            case 'get_epg_info':
                exit(json_encode(array('js' => array('data' => array())), JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_short_epg':
                if (empty(StreamingUtilities::$rRequest['ch_id'])) {
                } else {
                    $rChannelID = StreamingUtilities::$rRequest['ch_id'];
                    $rEPG = array('js' => array());
                    $rTime = time();
                    $rEPGData = array();

                    if (!file_exists(EPG_PATH . 'stream_' . intval($rChannelID))) {
                    } else {
                        $rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rChannelID));

                        foreach ($rRows as $rRow) {
                            if (!($rRow['start'] <= $rTime && $rTime <= $rRow['end'] || $rTime <= $rRow['start'])) {
                            } else {
                                $rRow['start_timestamp'] = $rRow['start'];
                                $rRow['stop_timestamp'] = $rRow['end'];
                                $rEPGData[] = $rRow;
                            }
                        }
                    }

                    if (empty($rEPGData)) {
                    } else {
                        $rTimeDifference = (StreamingUtilities::getDiffTimezone($ctx['timezone']) ?: 0);
                        $i = 0;

                        for ($n = 0; $n < count($rEPGData); $n++) {
                            if ($rEPGData[$n]['end'] >= time()) {
                                $rStartTime = new DateTime();
                                $rStartTime->setTimestamp($rEPGData[$n]['start']);
                                $rStartTime->modify((string) $rTimeDifference . ' seconds');
                                $rEndTime = new DateTime();
                                $rEndTime->setTimestamp($rEPGData[$n]['end']);
                                $rEndTime->modify((string) $rTimeDifference . ' seconds');
                                $rEPG['js'][$i]['id'] = $rEPGData[$n]['id'];
                                $rEPG['js'][$i]['ch_id'] = $rChannelID;
                                $rEPG['js'][$i]['correct'] = $rStartTime->format('Y-m-d H:i:s');
                                $rEPG['js'][$i]['time'] = $rStartTime->format('Y-m-d H:i:s');
                                $rEPG['js'][$i]['time_to'] = $rEndTime->format('Y-m-d H:i:s');
                                $rEPG['js'][$i]['duration'] = $rEPGData[$n]['stop_timestamp'] - $rEPGData[$n]['start_timestamp'];
                                $rEPG['js'][$i]['name'] = $rEPGData[$n]['title'];
                                $rEPG['js'][$i]['descr'] = $rEPGData[$n]['description'];
                                $rEPG['js'][$i]['real_id'] = $rChannelID . '_' . $rEPGData[$n]['start_timestamp'];
                                $rEPG['js'][$i]['category'] = '';
                                $rEPG['js'][$i]['director'] = '';
                                $rEPG['js'][$i]['actor'] = '';
                                $rEPG['js'][$i]['start_timestamp'] = $rStartTime->getTimestamp();
                                $rEPG['js'][$i]['stop_timestamp'] = $rEndTime->getTimestamp();
                                $rEPG['js'][$i]['t_time'] = $rStartTime->format('H:i');
                                $rEPG['js'][$i]['t_time_to'] = $rEndTime->format('H:i');
                                $rEPG['js'][$i]['mark_memo'] = 0;
                                $rEPG['js'][$i]['mark_archive'] = 0;

                                if (count($rEPG['js']) != ((intval(StreamingUtilities::$rRequest['size']) ?: 4))) {
                                    $i++;
                                }
                            }
                        }
                    }
                }

                exit(json_encode($rEPG, JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'set_last_id':
                $rChannelID = intval(StreamingUtilities::$rRequest['id']);

                if (0 >= $rChannelID) {
                } else {
                    $ctx['device']['last_itv_id'] = $rChannelID;
                    $db->query('UPDATE `mag_devices` SET `last_itv_id` = ? WHERE `mag_id` = ?', $rChannelID, $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'get_genres':
                $rOutput = array();
                $rNumber = 1;

                if (StreamingUtilities::$rSettings['show_all_category_mag'] != 1) {
                } else {
                    $rOutput['js'][] = array('id' => '*', 'title' => 'All', 'alias' => 'All', 'active_sub' => true, 'censored' => 0);
                }

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'live' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name'], 'modified' => '', 'number' => $rNumber++, 'alias' => strtolower($rCategory['category_name']), 'censored' => intval($rCategory['is_adult']));
                    }
                }

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * Phase 5 sub-handler: VOD (Video On Demand) actions.
     * Actions: set_claim, set_fav, del_fav, get_categories, get_genres_by_category_alias,
     *          get_years, get_ordered_list, create_link, get_abc.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleVod($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'set_claim':
                if (empty(StreamingUtilities::$rRequest['id']) || empty(StreamingUtilities::$rRequest['real_type'])) {
                } else {
                    $rID = intval(StreamingUtilities::$rRequest['id']);
                    $rRealType = StreamingUtilities::$rRequest['real_type'];
                    $rDate = date('Y-m-d H:i:s');
                    $db->query('INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)', $rID, $ctx['device']['mag_id'], $rRealType, $rDate);
                }

                exit(json_encode(array('js' => true)));

            case 'set_fav':
                if (empty(StreamingUtilities::$rRequest['video_id'])) {
                } else {
                    $rVideoID = intval(StreamingUtilities::$rRequest['video_id']);

                    if (in_array($rVideoID, $ctx['device']['fav_channels']['movie'])) {
                    } else {
                        $ctx['device']['fav_channels']['movie'][] = $rVideoID;
                    }

                    $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'del_fav':
                if (empty(StreamingUtilities::$rRequest['video_id'])) {
                } else {
                    $rVideoID = intval(StreamingUtilities::$rRequest['video_id']);

                    foreach ($ctx['device']['fav_channels']['movie'] as $rKey => $rValue) {
                        if ($rValue != $rVideoID) {
                        } else {
                            unset($ctx['device']['fav_channels']['movie'][$rKey]);

                            goto B79ca0d52db6b02d; //break;
                        }
                    }
                    B79ca0d52db6b02d:
                    $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'get_categories':
                $rOutput = array();
                $rOutput['js'] = array();

                if (StreamingUtilities::$rSettings['show_all_category_mag'] != 1) {
                } else {
                    $rOutput['js'][] = array('id' => '*', 'title' => 'All', 'alias' => '*', 'censored' => 0);
                }

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'movie' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name'], 'alias' => $rCategory['category_name'], 'censored' => intval($rCategory['is_adult']));
                    }
                }

                exit(json_encode($rOutput));

            case 'get_genres_by_category_alias':
                $rOutput = array();
                $rOutput['js'][] = array('id' => '*', 'title' => '*');

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'movie' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name']);
                    }
                }

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_years':
                exit(json_encode($ctx['magData']['get_years']));

            case 'get_ordered_list':
                $rCategory = (!empty(StreamingUtilities::$rRequest['category']) && is_numeric(StreamingUtilities::$rRequest['category']) ? StreamingUtilities::$rRequest['category'] : null);
                $rFav = (!empty(StreamingUtilities::$rRequest['fav']) ? 1 : null);
                $rSortBy = (!empty(StreamingUtilities::$rRequest['sortby']) ? StreamingUtilities::$rRequest['sortby'] : 'added');
                $rSearch = (!empty(StreamingUtilities::$rRequest['search']) ? StreamingUtilities::$rRequest['search'] : null);
                $rPicking = array();
                $rPicking['abc'] = (!empty(StreamingUtilities::$rRequest['abc']) ? StreamingUtilities::$rRequest['abc'] : '*');
                $rPicking['genre'] = (!empty(StreamingUtilities::$rRequest['genre']) ? StreamingUtilities::$rRequest['genre'] : '*');
                $rPicking['years'] = (!empty(StreamingUtilities::$rRequest['years']) ? StreamingUtilities::$rRequest['years'] : '*');

                exit(getMovies($rCategory, $rFav, $rSortBy, $rSearch, $rPicking));

            case 'create_link':
                $rCommand = StreamingUtilities::$rRequest['cmd'];
                $rSeries = (!empty(StreamingUtilities::$rRequest['series']) ? (int) StreamingUtilities::$rRequest['series'] : 0);
                $rError = '';

                if (!stristr($rCommand, '/media/')) {
                    $rCommand = json_decode(base64_decode($rCommand), true);
                } else {
                    $rCommand = array('series_data' => $rCommand, 'type' => 'series');
                }

                if (!$rSeries) {
                } else {
                    $rCommand['type'] = 'series';
                }

                $rValid = false;

                switch ($rCommand['type']) {
                    case 'movie':
                        $rValid = in_array($rCommand['stream_id'], $ctx['device']['vod_ids']);

                        break;

                    case 'series':
                        if (empty($rCommand['series_data'])) {
                        } else {
                            list($rCommand['series_id'], $rCommand['season_num']) = explode(':', basename($rCommand['series_data'], '.mpg'));
                        }

                        $db->query('SELECT t1.stream_id,t2.target_container FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id WHERE t1.`series_id` = ? AND t1.`season_num` = ? ORDER BY `episode_num` ASC LIMIT ' . intval($rSeries - 1) . ', 1', $rCommand['series_id'], $rCommand['season_num']);

                        if (0 < $db->num_rows()) {
                            $rRow = $db->get_row();
                            $rCommand['stream_id'] = $rRow['stream_id'];
                            $rCommand['target_container'] = $rRow['target_container'];
                            $rValid = in_array($rCommand['series_id'], $ctx['device']['series_ids']);
                        } else {
                            $rError = 'player_file_missing';
                        }
                }
                $rEncData = 'ministra::' . $rCommand['type'] . '/' . $ctx['device']['username'] . '/' . $ctx['device']['password'] . '/' . $rCommand['stream_id'] . '/' . $rCommand['target_container'] . '/' . $ctx['device']['token'];
                $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                $rURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

                if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                } else {
                    $rURL .= '?ext=.' . $rCommand['target_container'];
                }

                $rOutput = array('js' => array('id' => $rCommand['stream_id'], 'cmd' => $ctx['player'] . $rURL, 'load' => '', 'subtitles' => array(), 'error' => $rError));

                exit(json_encode($rOutput));

            case 'get_abc':
                exit(json_encode($ctx['magData']['get_abc']));
        }
    }

    /**
     * Phase 5 sub-handler: Series actions.
     * Actions: set_claim, set_fav, del_fav, get_categories, get_genres_by_category_alias,
     *          get_years, get_ordered_list, get_abc.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleSeries($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'set_claim':
                if (empty(StreamingUtilities::$rRequest['id']) || empty(StreamingUtilities::$rRequest['real_type'])) {
                } else {
                    $rID = intval(StreamingUtilities::$rRequest['id']);
                    $rRealType = StreamingUtilities::$rRequest['real_type'];
                    $rDate = date('Y-m-d H:i:s');
                    $db->query('INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)', $rID, $ctx['device']['mag_id'], $rRealType, $rDate);
                }

                exit(json_encode(array('js' => true)));

            case 'set_fav':
                if (empty(StreamingUtilities::$rRequest['video_id'])) {
                } else {
                    $rVideoID = intval(StreamingUtilities::$rRequest['video_id']);

                    if (in_array($rVideoID, $ctx['device']['fav_channels']['series'])) {
                    } else {
                        $ctx['device']['fav_channels']['series'][] = $rVideoID;
                    }

                    $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'del_fav':
                if (empty(StreamingUtilities::$rRequest['video_id'])) {
                } else {
                    $rVideoID = intval(StreamingUtilities::$rRequest['video_id']);

                    foreach ($ctx['device']['fav_channels']['series'] as $rKey => $rValue) {
                        if ($rValue != $rVideoID) {
                        } else {
                            unset($ctx['device']['fav_channels']['series'][$rKey]);

                            goto c2cd03c4f6bdbdea; //break;
                        }
                    }
                    c2cd03c4f6bdbdea:
                    $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                    updatecache();
                }

                exit(json_encode(array('js' => true)));

            case 'get_categories':
                $rOutput = array();
                $rOutput['js'] = array();

                if (StreamingUtilities::$rSettings['show_all_category_mag'] != 1) {
                } else {
                    $rOutput['js'][] = array('id' => '*', 'title' => 'All', 'alias' => '*', 'censored' => 0);
                }

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'series' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name'], 'alias' => $rCategory['category_name'], 'censored' => intval($rCategory['is_adult']));
                    }
                }

                exit(json_encode($rOutput));

            case 'get_genres_by_category_alias':
                $rOutput = array();
                $rOutput['js'][] = array('id' => '*', 'title' => '*');

                foreach (StreamingUtilities::$rCategories as $rCategoryID => $rCategory) {
                    if ($rCategory['category_type'] == 'series' && in_array($rCategory['id'], $ctx['device']['category_ids'])) {
                        $rOutput['js'][] = array('id' => $rCategory['id'], 'title' => $rCategory['category_name']);
                    }
                }

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_years':
                exit(json_encode($ctx['magData']['get_years']));

            case 'get_ordered_list':
                $rCategory = (!empty(StreamingUtilities::$rRequest['category']) && is_numeric(StreamingUtilities::$rRequest['category']) ? StreamingUtilities::$rRequest['category'] : null);
                $rFav = (!empty(StreamingUtilities::$rRequest['fav']) ? 1 : null);
                $rSortBy = (!empty(StreamingUtilities::$rRequest['sortby']) ? StreamingUtilities::$rRequest['sortby'] : 'added');
                $rSearch = (!empty(StreamingUtilities::$rRequest['search']) ? StreamingUtilities::$rRequest['search'] : null);
                $rMovieID = (!empty(StreamingUtilities::$rRequest['movie_id']) ? (int) StreamingUtilities::$rRequest['movie_id'] : null);
                $rPicking = array();
                $rPicking['abc'] = (!empty(StreamingUtilities::$rRequest['abc']) ? StreamingUtilities::$rRequest['abc'] : '*');
                $rPicking['genre'] = (!empty(StreamingUtilities::$rRequest['genre']) ? StreamingUtilities::$rRequest['genre'] : '*');
                $rPicking['years'] = (!empty(StreamingUtilities::$rRequest['years']) ? StreamingUtilities::$rRequest['years'] : '*');

                exit(getSeries($rMovieID, $rCategory, $rFav, $rSortBy, $rSearch, $rPicking));

            case 'get_abc':
                exit(json_encode($ctx['magData']['get_abc']));
        }
    }

    /**
     * Phase 5 sub-handler: Account info actions.
     * Actions: get_main_info.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleAccountInfo($rReqAction, &$ctx)
    {
        switch ($rReqAction) {
            case 'get_main_info':
                if (empty($ctx['device']['exp_date'])) {
                    $rExpiry = 'Unlimited';
                } else {
                    $rExpiry = date('F j, Y, g:i a', $ctx['device']['exp_date']);
                }

                exit(json_encode(array('js' => array('mac' => $ctx['mac'], 'phone' => $rExpiry, 'message' => htmlspecialchars_decode(str_replace("\n", '<br/>', StreamingUtilities::$rSettings['mag_message']))))));
        }
    }

    /**
     * Phase 5 sub-handler: Radio actions.
     * Actions: get_ordered_list, get_all_fav_radio, set_fav, get_fav_ids.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleRadio($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'get_ordered_list':
                $rFav = (!empty(StreamingUtilities::$rRequest['fav']) ? 1 : null);
                $rSortBy = (!empty(StreamingUtilities::$rRequest['sortby']) ? StreamingUtilities::$rRequest['sortby'] : 'added');

                exit(getStations(null, $rFav, $rSortBy));

            case 'get_all_fav_radio':
                exit(getStations(null, 1, null));

            case 'set_fav':
                $f3f9f9fa3c58c22b = (empty(StreamingUtilities::$rRequest['fav_radio']) ? '' : StreamingUtilities::$rRequest['fav_radio']);
                $f3f9f9fa3c58c22b = array_filter(array_map('intval', explode(',', $f3f9f9fa3c58c22b)));
                $ctx['device']['fav_channels']['radio_streams'] = $f3f9f9fa3c58c22b;
                $db->query('UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?', json_encode($ctx['device']['fav_channels']), $ctx['device']['mag_id']);
                updatecache();

                exit(json_encode(array('js' => true)));

            case 'get_fav_ids':
                exit(json_encode(array('js' => $ctx['device']['fav_channels']['radio_streams'])));
        }
    }

    /**
     * Phase 5 sub-handler: TV Archive actions.
     * Actions: get_next_part_url, create_link, get_link_for_channel.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleTvArchive($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'get_next_part_url':
                if (empty(StreamingUtilities::$rRequest['id'])) {
                } else {
                    $rID = StreamingUtilities::$rRequest['id'];
                    $rStreamID = substr($rID, 0, strpos($rID, '_'));
                    $rDate = strtotime(substr($rID, strpos($rID, '_') + 1));
                    $rRow = (getepg($rStreamID, $rDate, $rDate + 86400)[0] ?: null);

                    if (!$rRow) {
                    } else {
                        $rRow = $db->get_row();
                        $rProgramStart = $rRow['start'];
                        $rDuration = intval(($rRow['end'] - $rRow['start']) / 60);
                        $rTitle = $rRow['title'];
                        $rEncData = 'ministra::timeshift/' . $ctx['device']['username'] . '/' . $ctx['device']['password'] . '/' . $rDuration . '/' . $rProgramStart . '/' . $rStreamID . '/' . $ctx['device']['token'];
                        $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                        $rURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken . '?&osd_title=' . $rTitle;

                        if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                        } else {
                            $rURL .= '&ext=.ts';
                        }

                        exit(json_encode(array('js' => $ctx['player'] . $rURL)));
                    }
                }

                exit(json_encode(array('js' => false)));

            case 'create_link':
                $rCommand = (empty(StreamingUtilities::$rRequest['cmd']) ? '' : StreamingUtilities::$rRequest['cmd']);
                list($rEPGDataID, $rStreamID) = explode('_', pathinfo($rCommand)['filename']);
                $rRow = (getprogramme($rStreamID, $rEPGDataID) ?: null);

                if (!$rRow) {
                    break;
                }

                $rStart = $rRow['start'];
                $rDuration = intval(($rRow['end'] - $rRow['start']) / 60);
                $rEncData = 'ministra::timeshift/' . $ctx['device']['username'] . '/' . $ctx['device']['password'] . '/' . $rDuration . '/' . $rStart . '/' . $rStreamID . '/' . $ctx['device']['token'];
                $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                $rURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

                if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                } else {
                    $rURL .= '?ext=.ts';
                }

                $rOutput['js'] = array('id' => 0, 'cmd' => $ctx['player'] . $rURL, 'storage_id' => '', 'load' => 0, 'error' => '', 'download_cmd' => $rURL, 'to_file' => '');

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_link_for_channel':
                $rOutput = array();
                $rChannelID = (!empty(StreamingUtilities::$rRequest['ch_id']) ? intval(StreamingUtilities::$rRequest['ch_id']) : 0);
                $rStart = strtotime(date('Ymd-H'));
                $rEncData = 'ministra::timeshift/' . $ctx['device']['username'] . '/' . $ctx['device']['password'] . '/60/' . $rStart . '/' . $rChannelID . '/' . $ctx['device']['token'];
                $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                $rURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken . ((StreamingUtilities::$rSettings['mag_keep_extension'] ? '?ext=.ts' : '')) . ' position:' . (intval(date('i')) * 60 + intval(date('s'))) . ' media_len:' . (intval(date('H')) * 3600 + intval(date('i')) * 60 + intval(date('s')));
                $rOutput['js'] = array('id' => 0, 'cmd' => $ctx['player'] . $rURL, 'storage_id' => '', 'load' => 0, 'error' => '');

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * Phase 5 sub-handler: EPG (Electronic Program Guide) actions.
     * Actions: get_week, get_data_table, get_simple_data_table, get_all_program_for_ch.
     *
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleEpg($rReqAction, &$ctx)
    {
        global $db;

        switch ($rReqAction) {
            case 'get_week':
                $k = -16;
                $i = 0;
                $rEPGWeek = array();
                $rCurDate = strtotime(date('Y-m-d'));

                while ($k < 10) { // >=  fixed???
                    $rThisDate = $rCurDate + $k * 86400;
                    $rEPGWeek['js'][$i]['f_human'] = date('D d F', $rThisDate);
                    $rEPGWeek['js'][$i]['f_mysql'] = date('Y-m-d', $rThisDate);
                    $rEPGWeek['js'][$i]['today'] = ($k == 0 ? 1 : 0);
                    $k++;
                    $i++;
                }

                exit(json_encode($rEPGWeek));

            case 'get_data_table':
                exit(json_encode(array('js' => array()), JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_simple_data_table':
                if (empty(StreamingUtilities::$rRequest['ch_id']) || empty(StreamingUtilities::$rRequest['date'])) {
                    exit();
                }

                $rChannelID = StreamingUtilities::$rRequest['ch_id'];
                $rReqDate = StreamingUtilities::$rRequest['date'];
                $rPage = intval(StreamingUtilities::$rRequest['p']);
                $rPageItems = ($ctx['theme'] == 'xc_vm' ? 7 : 10);
                $rDefaultPage = false;
                $rEPGDatas = array();
                $rStartTime = strtotime($rReqDate . ' 00:00:00');
                $rEndTime = strtotime($rReqDate . ' 23:59:59');

                if (!file_exists(EPG_PATH . 'stream_' . intval($rChannelID))) {
                } else {
                    $rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rChannelID));

                    foreach ($rRows as $rRow) {
                        if (!($rStartTime <= $rRow['start'] && $rRow['start'] <= $rEndTime)) {
                        } else {
                            $rRow['start_timestamp'] = $rRow['start'];
                            $rRow['stop_timestamp'] = $rRow['end'];
                            $rEPGDatas[] = $rRow;
                        }
                    }
                }

                if (file_exists(STREAMS_TMP_PATH . 'stream_' . intval($rChannelID))) {
                    $rStreamRow = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannelID)))['info'];
                } else {
                    $db->query('SELECT `tv_archive_duration` FROM `streams` WHERE `id` = ?;', StreamingUtilities::$rRequest['ch_id']);

                    if (0 >= $db->num_rows()) {
                    } else {
                        $rStreamRow = $db->get_row();
                    }
                }

                $rChannelIDx = 0;

                foreach ($rEPGDatas as $rKey => $rEPGData) {
                    if (!($rEPGData['start_timestamp'] <= time() && time() <= $rEPGData['stop_timestamp'])) {
                    } else {
                        $rChannelIDx = $rKey + 1;

                        goto Aeb56a67ad642976; //break;
                    }
                }
                Aeb56a67ad642976:
                if ($rPage != 0) {
                } else {
                    $rDefaultPage = true;
                    $rPage = ceil($rChannelIDx / $rPageItems);

                    if ($rPage != 0) {
                    } else {
                        $rPage = 1;
                    }

                    if ($rReqDate == date('Y-m-d')) {
                    } else {
                        $rPage = 1;
                        $rDefaultPage = false;
                    }
                }

                $rProgram = array_slice($rEPGDatas, ($rPage - 1) * $rPageItems, $rPageItems);
                $rData = array();
                $rTimeDifference = StreamingUtilities::getDiffTimezone($ctx['timezone']);

                for ($i = 0; $i < count($rProgram); $i++) {
                    $open = 0;

                    if (time() > $rProgram[$i]['stop_timestamp']) {
                    } else {
                        $open = 1;
                    }

                    $rStartTime = new DateTime();
                    $rStartTime->setTimestamp($rProgram[$i]['start']);
                    $rStartTime->modify((string) $rTimeDifference . ' seconds');
                    $rEndTime = new DateTime();
                    $rEndTime->setTimestamp($rProgram[$i]['end']);
                    $rEndTime->modify((string) $rTimeDifference . ' seconds');
                    $rData[$i]['id'] = $rProgram[$i]['id'] . '_' . $rChannelID;
                    $rData[$i]['ch_id'] = $rChannelID;
                    $rData[$i]['time'] = $rStartTime->format('Y-m-d H:i:s');
                    $rData[$i]['time_to'] = $rEndTime->format('Y-m-d H:i:s');
                    $rData[$i]['duration'] = $rProgram[$i]['stop_timestamp'] - $rProgram[$i]['start_timestamp'];
                    $rData[$i]['name'] = $rProgram[$i]['title'];
                    $rData[$i]['descr'] = $rProgram[$i]['description'];
                    $rData[$i]['real_id'] = $rChannelID . '_' . $rProgram[$i]['start'];
                    $rData[$i]['category'] = '';
                    $rData[$i]['director'] = '';
                    $rData[$i]['actor'] = '';
                    $rData[$i]['start_timestamp'] = $rStartTime->getTimestamp();
                    $rData[$i]['stop_timestamp'] = $rEndTime->getTimestamp();
                    $rData[$i]['t_time'] = $rStartTime->format('H:i');
                    $rData[$i]['t_time_to'] = $rEndTime->format('H:i');
                    $rData[$i]['open'] = $open;
                    $rData[$i]['mark_memo'] = 0;
                    $rData[$i]['mark_rec'] = 0;
                    $rData[$i]['mark_archive'] = (!empty($rStreamRow['tv_archive_duration']) && $rEndTime->getTimestamp() < time() && strtotime('-' . $rStreamRow['tv_archive_duration'] . ' days') <= $rEndTime->getTimestamp() ? 1 : 0);
                }

                if ($rDefaultPage) {
                    $rCurrentPage = $rPage;
                    $rSelectedItem = $rChannelIDx - ($rPage - 1) * $rPageItems;
                } else {
                    $rCurrentPage = 0;
                    $rSelectedItem = 0;
                }

                $rOutput = array();
                $rOutput['js']['cur_page'] = $rCurrentPage;
                $rOutput['js']['selected_item'] = $rSelectedItem;
                $rOutput['js']['total_items'] = count($rEPGDatas);
                $rOutput['js']['max_page_items'] = $rPageItems;
                $rOutput['js']['data'] = $rData;

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

            case 'get_all_program_for_ch':
                $rOutput = array();
                $rOutput['js'] = array();
                $rChannelID = (empty(StreamingUtilities::$rRequest['ch_id']) ? 0 : intval(StreamingUtilities::$rRequest['ch_id']));
                $rTimeDifference = StreamingUtilities::getDiffTimezone($ctx['timezone']);

                if (file_exists(STREAMS_TMP_PATH . 'stream_' . intval($rChannelID))) {
                    $rStreamRow = igbinary_unserialize(file_get_contents(STREAMS_TMP_PATH . 'stream_' . intval($rChannelID)))['info'];
                } else {
                    $db->query('SELECT `tv_archive_duration` FROM `streams` WHERE `id` = ?;', StreamingUtilities::$rRequest['ch_id']);

                    if (0 >= $db->num_rows()) {
                    } else {
                        $rStreamRow = $db->get_row();
                    }
                }

                $rTime = strtotime(date('Y-m-d 00:00:00'));

                if (!file_exists(EPG_PATH . 'stream_' . intval($rChannelID))) {
                } else {
                    $rRows = igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rChannelID));

                    foreach ($rRows as $rRow) {
                        if ($rTime > $rRow['start']) {
                        } else {
                            $rRow['start_timestamp'] = $rRow['start'];
                            $rRow['stop_timestamp'] = $rRow['end'];
                            $rStartTime = new DateTime();
                            $rStartTime->setTimestamp($rRow['start']);
                            $rStartTime->modify((string) $rTimeDifference . ' seconds');
                            $rEndTime = new DateTime();
                            $rEndTime->setTimestamp($rRow['end']);
                            $rEndTime->modify((string) $rTimeDifference . ' seconds');
                            $rOutput['js'][] = array('start_timestamp' => $rStartTime->getTimestamp(), 'stop_timestamp' => $rEndTime->getTimestamp(), 'name' => $rRow['title']);
                        }
                    }
                }

                exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * Phase 6: Unauthenticated fallthrough handler.
     * If not authenticated and action is stb/get_profile, performs bruteforce check.
     * Then exits.
     *
     * @param string $rReqType
     * @param string $rReqAction
     * @param array  &$ctx Context array
     */
    public static function handleUnauthenticated($rReqType, $rReqAction, &$ctx)
    {
        if (!($rReqType == 'stb' && $rReqAction == 'get_profile')) {
        } else {
            BruteforceGuard::checkBruteforce($ctx['ip'], $ctx['mac']);
            BruteforceGuard::checkFlood();
        }

        exit();
    }

    /**
     * Phase 7: Handshake — token generation.
     * Generates a new token for the device identified by MAC, updates DB, and exits with token.
     *
     * @param string $rMAC
     */
    public static function handleHandshake($rMAC)
    {
        global $db;

        $rDevice = getdevice(null, $rMAC);
        $rVerifyToken = null;

        if ($rDevice) {
            $rDevice['token'] = strtoupper(md5(uniqid(rand(), true)));
            $rVerifyToken = StreamingUtilities::encryptData(igbinary_serialize(array('id' => $rDevice['mag_id'], 'token' => $rDevice['token'])), StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
            $rDevice['authenticated'] = false;
            $db->query('UPDATE `mag_devices` SET `token` = ? WHERE `mag_id` = ?', $rDevice['token'], $rDevice['mag_id']);
            $db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_line', 'id' => $rDevice['user_id'])));
            updatecache();
        } else {
            $rDevice = array();
        }

        exit(json_encode(array('js' => array('token' => $rVerifyToken))));
    }
}
