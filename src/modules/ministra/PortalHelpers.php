<?php
/**
 * PortalHelpers — статические хелперы для Ministra/Stalker портала.
 *
 * Извлечено из ministra/portal.php — функции для получения контента
 * (каналы, фильмы, сериалы, радио, EPG) и управления устройствами.
 */
class PortalHelpers
{
    // ─── Устройство / кэш ───────────────────────────────────────────

    /**
     * Получить устройство MAG по ID или MAC.
     * Загружает из кэша (igbinary) или БД, собирает права из букетов.
     */
    public static function getDevice($rID = null, $rMAC = null)
    {
        global $db;
        global $rIP;
        StreamingUtilities::$rBouquets = StreamingUtilities::getCache('bouquets');
        $rDevice = ($rID && file_exists(MINISTRA_TMP_PATH . 'ministra_' . $rID)
            ? igbinary_unserialize(file_get_contents(MINISTRA_TMP_PATH . 'ministra_' . $rID))
            : null);

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
                $rUserInfo = StreamingUtilities::getUserInfo($rDevice['user_id'], null, null, true, false, $rIP);
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

                if (!empty($rDevice['fav_channels']['series'])) {
                } else {
                    $rDevice['fav_channels']['series'] = array();
                }

                if (!empty($rDevice['fav_channels']['radio_streams'])) {
                } else {
                    $rDevice['fav_channels']['radio_streams'] = array();
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
                    if (!isset(StreamingUtilities::$rBouquets[$rID]['streams'])) {
                    } else {
                        $rChannelIDs = array_merge($rChannelIDs, StreamingUtilities::$rBouquets[$rID]['streams']);
                    }

                    if (!isset(StreamingUtilities::$rBouquets[$rID]['series'])) {
                    } else {
                        $rSeriesIDs = array_merge($rSeriesIDs, StreamingUtilities::$rBouquets[$rID]['series']);
                    }

                    if (!isset(StreamingUtilities::$rBouquets[$rID]['channels'])) {
                    } else {
                        $rLiveIDs = array_merge($rLiveIDs, StreamingUtilities::$rBouquets[$rID]['channels']);
                    }

                    if (!isset(StreamingUtilities::$rBouquets[$rID]['movies'])) {
                    } else {
                        $rVODIDs = array_merge($rVODIDs, StreamingUtilities::$rBouquets[$rID]['movies']);
                    }

                    if (!isset(StreamingUtilities::$rBouquets[$rID]['radios'])) {
                    } else {
                        $rRadioIDs = array_merge($rRadioIDs, StreamingUtilities::$rBouquets[$rID]['radios']);
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

    /**
     * Сохранить кэш устройства в файл.
     */
    public static function updateCache(&$rDevice)
    {
        file_put_contents(MINISTRA_TMP_PATH . 'ministra_' . $rDevice['mag_id'], igbinary_serialize($rDevice));
    }

    // ─── EPG ─────────────────────────────────────────────────────────

    /**
     * Получить EPG для стрима.
     */
    public static function getEPG($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false)
    {
        $rReturn = array();
        $rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID)
            ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID))
            : array());

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

    /**
     * Получить EPG для нескольких стримов.
     */
    public static function getEPGs($rStreamIDs, $rStartDate = null, $rFinishDate = null)
    {
        $rReturn = array();

        foreach ($rStreamIDs as $rStreamID) {
            $rReturn[$rStreamID] = self::getEPG($rStreamID, $rStartDate, $rFinishDate);
        }

        return $rReturn;
    }

    /**
     * Получить конкретную программу.
     */
    public static function getProgramme($rStreamID, $rProgrammeID)
    {
        $rData = self::getEPG($rStreamID, null, null, true);

        if (!isset($rData[$rProgrammeID])) {
        } else {
            return $rData[$rProgrammeID];
        }
    }

    // ─── Контент: общий запрос ───────────────────────────────────────

    /**
     * Преобразовать строковые типы в числовые.
     */
    public static function convertTypes($rTypes)
    {
        $rReturn = array();
        $rTypeInt = array('live' => 1, 'movie' => 2, 'created_live' => 3, 'radio_streams' => 4, 'series' => 5);

        foreach ($rTypes as $rType) {
            $rReturn[] = $rTypeInt[$rType];
        }

        return $rReturn;
    }

    /**
     * Универсальный запрос контента.
     * Используется для каналов, фильмов, радио, эпизодов.
     */
    public static function getItems($rDevice, $rTypes = array(), $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $additionalOptions = null)
    {
        global $db;
        $rAdded = false;
        $rChannels = array();

        foreach ($rTypes as $rType) {
            switch ($rType) {
                case 'live':
                case 'created_live':
                    if (!$rAdded) {
                        $rChannels = array_merge($rChannels, $rDevice['live_ids']);
                        $rAdded = true;
                    }
                    break;

                case 'movie':
                    $rChannels = array_merge($rChannels, $rDevice['vod_ids']);
                    break;

                case 'radio_streams':
                    $rChannels = array_merge($rChannels, $rDevice['radio_ids']);
                    break;

                case 'series':
                    $rChannels = array_merge($rChannels, $rDevice['episode_ids']);
                    break;
            }
        }
        $rStreams = array('count' => 0, 'streams' => array());
        $rAdultCategories = StreamingUtilities::getAdultCategories();
        $rKey = $rStart + 1;
        $rWhereV = $rWhere = array();

        if (0 >= count($rTypes)) {
        } else {
            $rWhere[] = '`type` IN (' . implode(',', self::convertTypes($rTypes)) . ')';
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

        $rChannels = StreamingUtilities::sortChannels($rChannels);

        if (empty($rFav)) {
        } else {
            $favoriteChannelIds = array();

            foreach ($rTypes as $rType) {
                foreach ($rDevice['fav_channels'][$rType] as $rStreamID) {
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
                if (StreamingUtilities::$rSettings['channel_number_type'] != 'manual') {
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
                    $rQuery = "SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` " . $rWhereString . " ORDER BY " . $rOrder . " LIMIT " . $rStart . ", " . $rLimit . ";";
                } else {
                    $rQuery = "SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` " . $rWhereString . " ORDER BY " . $rOrder . ";";
                }
                $db->query($rQuery, ...$rWhereV);
                $rRows = $db->get_rows();
            } else {
                $rWhereV[] = $additionalOptions;
                $db->query("SELECT * FROM (SELECT @row_number:=@row_number+1 AS `pos`, `id` FROM `streams`, (SELECT @row_number:=0) AS `t` " . $rWhereString . " ORDER BY " . $rOrder . ") `ids` WHERE `ids`.`id` = ?;", ...$rWhereV);
                return $db->get_row()["pos"] ?: null;
            }
        } else {
            if ($additionalOptions) {
                return null;
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

            $rStream["now_playing"] = self::getEPG($rStream["id"], time(), time() + 86400)[0] ?: null;
            $rStream["stream_info"] = json_decode($rStream["stream_info"], true);
            $rStreams["streams"][$rStream["id"]] = $rStream;
            $rKey++;
        }
        return $rStreams;
    }

    // ─── Контент: сериалы ────────────────────────────────────────────

    /**
     * Получить список сериалов с фильтрацией и сортировкой.
     */
    public static function getSeriesItems($rDevice, $rUserID, $rType = 'series', $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array())
    {
        global $db;

        if (0 < count($rDevice['series_ids'])) {
            $db->query('SELECT *, (SELECT MAX(`streams`.`added`) FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) AS `last_modified_stream` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rDevice['series_ids'])) . ') ORDER BY `last_modified_stream` DESC, `last_modified` DESC;');
            $rSeries = $db->get_rows(true, 'id');
        } else {
            $rSeries = array();
        }

        $rOutputSeries = array();

        foreach ($rSeries as $rSeriesID => $rSeriesO) {
            $rSeriesO['last_modified'] = $rSeriesO['last_modified_stream'];

            if (!empty($rCategoryID) && !in_array($rCategoryID, json_decode($rSeriesO['category_id'], true))) {
            } else {
                if (in_array($rCategoryID, json_decode($rSeriesO['category_id'], true))) {
                    $rSeriesO['category_id'] = $rCategoryID;
                } else {
                    list($rSeriesO['category_id']) = json_decode($rSeriesO['category_id'], true);
                }

                if ((empty($rSearchBy) || stristr($rSeriesO['title'], $rSearchBy)) && !(!empty($rPicking['abc']) && $rPicking['abc'] != '*' && strtoupper(substr($rSeriesO['title'], 0, 1)) != $rPicking['abc']) && !(!empty($rPicking['genre']) && $rPicking['genre'] != '*' && $rSeriesO['category_id'] != $rPicking['genre']) && !(!empty($rPicking['years']) && $rPicking['years'] != '*' && $rSeriesO['year'] != $rPicking['years'])) {
                    if (empty($rFav)) {
                    } else {
                        $rFound = false;

                        if (empty($rDevice['fav_channels'][$rType]) || !in_array($rSeriesID, $rDevice['fav_channels'][$rType])) {
                        } else {
                            $rFound = true;
                        }

                        if (!$rFound) {
                            continue;
                        }
                    }
                    $rOutputSeries[$rSeriesID] = $rSeriesO;
                }
            }
        }

        switch ($rOrderBy) {
            case 'name':
                uasort($rOutputSeries, [self::class, 'sortArrayStreamName']);
                break;

            case 'rating':
            case 'top':
                uasort($rOutputSeries, [self::class, 'sortArrayStreamRating']);
                break;

            case 'number':
                uasort($rOutputSeries, [self::class, 'sortArrayStreamNumber']);
                break;

            default:
                uasort($rOutputSeries, [self::class, 'sortArrayStreamAdded']);
        }

        return $rOutputSeries;
    }

    /**
     * Получить сезоны определённого сериала.
     */
    public static function getSeasons($rSeriesID)
    {
        global $db;
        $db->query('SELECT * FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id=t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num DESC, t1.episode_num ASC', $rSeriesID);

        return $db->get_rows(true, 'season_num', false);
    }

    // ─── Вывод: фильмы ──────────────────────────────────────────────

    /**
     * Получить список фильмов в формате Stalker API.
     */
    public static function getMovies($rDevice, $rPageItems, $rForceProtocol, $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array())
    {
        $rDefaultPage = false;
        $rPage = (!empty(StreamingUtilities::$rRequest['p']) ? StreamingUtilities::$rRequest['p'] : 0);

        if ($rPage != 0) {
        } else {
            $rDefaultPage = true;
            $rPage = 1;
        }

        $rStart = ($rPage - 1) * $rPageItems;
        $rStreams = self::getItems($rDevice, array('movie'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, $rPicking, $rStart, $rPageItems);
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
            $rDatas[] = array('id' => $rMovie['id'], 'owner' => '', 'name' => $rMovie['stream_display_name'], 'tmdb_id' => $rProperties['tmdb_id'], 'old_name' => '', 'o_name' => $rMovie['stream_display_name'], 'fname' => '', 'description' => (empty($rProperties['plot']) ? 'N/A' : $rProperties['plot']), 'pic' => '', 'cost' => 0, 'time' => intval($rDuration / 60), 'file' => '', 'path' => str_replace(' ', '_', $rMovie['stream_display_name']), 'protocol' => '', 'rtsp_url' => '', 'censored' => intval($rMovie['is_adult']), 'series' => array(), 'volume_correction' => 0, 'category_id' => $rMovie['category_id'], 'genre_id' => 0, 'genre_id_1' => 0, 'genre_id_2' => 0, 'genre_id_3' => 0, 'hd' => $rHD, 'genre_id_4' => 0, 'cat_genre_id_1' => $rMovie['category_id'], 'cat_genre_id_2' => 0, 'cat_genre_id_3' => 0, 'cat_genre_id_4' => 0, 'director' => (empty($rProperties['director']) ? 'N/A' : $rProperties['director']), 'actors' => (empty($rProperties['cast']) ? 'N/A' : $rProperties['cast']), 'year' => $rMovie['year'], 'accessed' => 1, 'status' => 1, 'disable_for_hd_devices' => 0, 'added' => date('Y-m-d H:i:s', $rMovie['added']), 'count' => 0, 'count_first_0_5' => 0, 'count_second_0_5' => 0, 'vote_sound_good' => 0, 'vote_sound_bad' => 0, 'vote_video_good' => 0, 'vote_video_bad' => 0, 'rate' => '', 'last_rate_update' => '', 'last_played' => '', 'for_sd_stb' => 0, 'rating_im' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'rating_count_im' => '', 'rating_last_update' => '0000-00-00 00:00:00', 'age' => '12+', 'high_quality' => 0, 'rating_kinopoisk' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'comments' => '', 'low_quality' => 0, 'is_series' => 0, 'year_end' => 0, 'autocomplete_provider' => 'im', 'screenshots' => '', 'is_movie' => 1, 'lock' => $rMovie['is_adult'], 'fav' => (in_array($rMovie['id'], $rDevice['fav_channels']['movie']) ? 1 : 0), 'for_rent' => 0, 'screenshot_uri' => (empty($rProperties['movie_image']) ? '' : StreamingUtilities::validateImage($rProperties['movie_image'], $rForceProtocol)), 'genres_str' => (empty($rProperties['genre']) ? 'N/A' : $rProperties['genre']), 'cmd' => base64_encode(json_encode($rPostData, JSON_PARTIAL_OUTPUT_ON_ERROR)), $rAddedKey => $rAddedVal, 'has_files' => 0);
        }

        if ($rDefaultPage) {
        } else {
            $rPage = 0;
        }

        $rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => $rPageItems, 'selected_item' => 0, 'cur_page' => $rPage, 'data' => $rDatas));

        return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ─── Вывод: сериалы ──────────────────────────────────────────────

    /**
     * Получить список сериалов/сезонов в формате Stalker API.
     */
    public static function getSeries($rDevice, $rPageItems, $rForceProtocol, $rMovieID = null, $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array())
    {
        global $db;
        $rPage = (!empty(StreamingUtilities::$rRequest['p']) ? StreamingUtilities::$rRequest['p'] : 0);
        $rDefaultPage = false;

        if (empty($rMovieID)) {
            $rItems = self::getSeriesItems($rDevice, $rDevice['user_id'], 'series', $rCategoryID, $rFav, $rOrderBy, $rSearchBy, $rPicking);
        } else {
            $rItems = self::getSeasons($rMovieID);
            $db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $rMovieID);
            $rSeriesInfo = $db->get_row();
        }

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
                if (in_array($rMovie['id'], $rDevice['fav_channels']['series'])) {
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

            $rDatas[] = array('id' => $rProperties['id'], 'owner' => '', 'name' => $rTitle, 'tmdb_id' => $rProperties['tmdb_id'], 'old_name' => '', 'o_name' => $rTitle, 'fname' => '', 'description' => (empty($rProperties['plot']) ? 'N/A' : $rProperties['plot']), 'pic' => '', 'cost' => 0, 'time' => 'N/a', 'file' => '', 'path' => str_replace(' ', '_', $rProperties['title']), 'protocol' => '', 'rtsp_url' => '', 'censored' => 0, 'series' => (!empty($rSeriesInfo) ? range(1, count($rMovie)) : array()), 'volume_correction' => 0, 'category_id' => $rProperties['category_id'], 'genre_id' => 0, 'genre_id_1' => 0, 'genre_id_2' => 0, 'genre_id_3' => 0, 'hd' => 1, 'genre_id_4' => 0, 'cat_genre_id_1' => $rProperties['category_id'], 'cat_genre_id_2' => 0, 'cat_genre_id_3' => 0, 'cat_genre_id_4' => 0, 'director' => (empty($rProperties['director']) ? 'N/A' : $rProperties['director']), 'actors' => (empty($rProperties['cast']) ? 'N/A' : $rProperties['cast']), 'year' => (empty($rProperties['release_date']) ? 'N/A' : $rProperties['release_date']), 'accessed' => 1, 'status' => 1, 'disable_for_hd_devices' => 0, 'added' => date('Y-m-d H:i:s', $rMaxAdded), 'count' => 0, 'count_first_0_5' => 0, 'count_second_0_5' => 0, 'vote_sound_good' => 0, 'vote_sound_bad' => 0, 'vote_video_good' => 0, 'vote_video_bad' => 0, 'rate' => '', 'last_rate_update' => '', 'last_played' => '', 'for_sd_stb' => 0, 'rating_im' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'rating_count_im' => '', 'rating_last_update' => '0000-00-00 00:00:00', 'age' => '12+', 'high_quality' => 0, 'rating_kinopoisk' => (empty($rProperties['rating']) ? 'N/A' : $rProperties['rating']), 'comments' => '', 'low_quality' => 0, 'is_series' => 1, 'year_end' => 0, 'autocomplete_provider' => 'im', 'screenshots' => '', 'is_movie' => 1, 'lock' => 0, 'fav' => (in_array($rProperties['id'], $rDevice['fav_channels']['series']) ? 1 : 0), 'for_rent' => 0, 'screenshot_uri' => (empty($rProperties['cover']) ? '' : StreamingUtilities::validateImage($rProperties['cover'], $rForceProtocol)), 'genres_str' => (empty($rProperties['genre']) ? 'N/A' : $rProperties['genre']), 'cmd' => (!empty($rSeriesInfo) ? base64_encode(json_encode($rPostData, JSON_PARTIAL_OUTPUT_ON_ERROR)) : ''), $rAddedKey => $rAddedVal, 'has_files' => (empty($rMovieID) ? 1 : 0));
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

    // ─── Вывод: радио ────────────────────────────────────────────────

    /**
     * Получить список радиостанций в формате Stalker API.
     */
    public static function getStations($rDevice, $rPlayer, $rPageItems, $rCategoryID = null, $rFav = null, $rOrderBy = null)
    {
        $rDefaultPage = false;
        $rPage = (!empty(StreamingUtilities::$rRequest['p']) ? StreamingUtilities::$rRequest['p'] : 0);

        if ($rPage != 0) {
        } else {
            $rDefaultPage = true;
            $rPage = 1;
        }

        $rStart = ($rPage - 1) * $rPageItems;
        $rStreams = self::getItems($rDevice, array('radio_streams'), $rCategoryID, $rFav, $rOrderBy, null, null, $rStart, $rPageItems);
        $rDatas = array();
        $i = 0;

        foreach ($rStreams['streams'] as $rStream) {
            if (StreamingUtilities::$rSettings['mag_security'] == 0) {
                $rEncData = 'ministra::live/' . $rDevice['username'] . '/' . $rDevice['password'] . '/' . $rStream['id'] . '/' . StreamingUtilities::$rSettings['mag_container'] . '/' . $rDevice['token'];
                $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                $rStreamURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

                if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                } else {
                    $rStreamURL .= '?ext=.' . StreamingUtilities::$rSettings['mag_container'];
                }

                $rStreamSourceSt = 0;
            } else {
                $rStreamURL = 'http://localhost/ch/' . $rStream['id'] . '_';
                $rStreamSourceSt = 1;
            }

            $rDatas[] = array('id' => $rStream['id'], 'name' => $rStream['stream_display_name'], 'number' => $i++, 'cmd' => $rPlayer . $rStreamURL, 'count' => 0, 'open' => 1, 'status' => 1, 'volume_correction' => 0, 'use_http_tmp_link' => (string) $rStreamSourceSt, 'fav' => (in_array($rStream['id'], $rDevice['fav_channels']['radio_streams']) ? 1 : 0));
        }

        if ($rDefaultPage) {
        } else {
            $rPage = 0;
        }

        $rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => $rPageItems, 'selected_item' => 0, 'cur_page' => $rPage, 'data' => $rDatas));

        return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ─── Вывод: live каналы ──────────────────────────────────────────

    /**
     * Получить список live-каналов в формате Stalker API.
     */
    public static function getStreams($rDevice, $rPlayer, $rPageItems, $rTimezone, $rForceProtocol, $rCategoryID = null, $rAll = false, $rFav = null, $rOrderBy = null, $rSearchBy = null)
    {
        $rDefaultPage = false;
        $rPage = (isset(StreamingUtilities::$rRequest['p']) ? intval(StreamingUtilities::$rRequest['p']) : 0);
        $rPosition = 0;

        if (!($rPage == 0 && $rCategoryID != -1)) {
        } else {
            $rDefaultPage = true;

            if (StreamingUtilities::$rRequest['p'] != 0 || empty($rDevice['last_itv_id'])) {
            } else {
                $rPosition = self::getItems($rDevice, array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, 0, 0, $rDevice['last_itv_id']);

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
            if (StreamingUtilities::$rSettings['mag_load_all_channels']) {
                $rStreams = self::getItems($rDevice, array('live', 'created_live'), (0 < $rCategoryID ? $rCategoryID : null), $rFav, $rOrderBy, $rSearchBy, null, 0, 0);
            } else {
                return '{"js":{"total_items":0,"max_page_items":14,"selected_item":0,"cur_page":0,"data":[]}}';
            }
        } else {
            if ($rAll) {
                $rStreams = self::getItems($rDevice, array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, 0, 0);
            } else {
                $rStreams = self::getItems($rDevice, array('live', 'created_live'), $rCategoryID, $rFav, $rOrderBy, $rSearchBy, null, $rStart, $rPageItems);
            }
        }

        $rDatas = array();
        $rTimeDifference = StreamingUtilities::getDiffTimezone($rTimezone);

        foreach ($rStreams['streams'] as $rStream) {
            $rHD = intval(1200 < $rStream['stream_info']['codecs']['video']['width']);

            if (StreamingUtilities::$rSettings['mag_security'] == 0) {
                $rEncData = 'ministra::live/' . $rDevice['username'] . '/' . $rDevice['password'] . '/' . $rStream['id'] . '/' . StreamingUtilities::$rSettings['mag_container'] . '/' . $rDevice['token'];
                $rToken = StreamingUtilities::encryptData($rEncData, StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                $rStreamURL = ((StreamingUtilities::$rSettings['mag_disable_ssl'] ? StreamingUtilities::$rServers[SERVER_ID]['http_url'] : StreamingUtilities::$rServers[SERVER_ID]['site_url'])) . 'play/' . $rToken;

                if (!StreamingUtilities::$rSettings['mag_keep_extension']) {
                } else {
                    $rStreamURL .= '?ext=.' . StreamingUtilities::$rSettings['mag_container'];
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

            $rDatas[] = array('id' => intval($rStream['id']), 'name' => $rStream['stream_display_name'], 'number' => (string) $rStream['number'], 'snumber' => (string) $rStream['number'], 'censored' => ($rStream['is_adult'] == 1 ? 1 : 0), 'cmd' => $rPlayer . $rStreamURL, 'cost' => '0', 'count' => '0', 'status' => 1, 'tv_genre_id' => $rStream['category_id'], 'base_ch' => '1', 'hd' => $rHD, 'xmltv_id' => (!empty($rStream['channel_id']) ? $rStream['channel_id'] : ''), 'service_id' => '', 'bonus_ch' => '0', 'volume_correction' => '0', 'use_http_tmp_link' => $rStreamSourceSt, 'mc_cmd' => '', 'enable_tv_archive' => (0 < $rStream['tv_archive_duration'] ? 1 : 0), 'wowza_tmp_link' => '0', 'wowza_dvr' => '0', 'monitoring_status' => '1', 'enable_monitoring' => '0', 'enable_wowza_load_balancing' => '0', 'cmd_1' => '', 'cmd_2' => '', 'cmd_3' => '', 'logo' => StreamingUtilities::validateImage($rStream['stream_icon'], $rForceProtocol), 'correct_time' => '0', 'nimble_dvr' => '0', 'allow_pvr' => (int) $rStream['allow_record'], 'allow_local_pvr' => (int) $rStream['allow_record'], 'allow_remote_pvr' => 0, 'modified' => '', 'allow_local_timeshift' => '1', 'nginx_secure_link' => $rStreamSourceSt, 'tv_archive_duration' => (0 < $rStream['tv_archive_duration'] ? $rStream['tv_archive_duration'] * 24 : 0), 'locked' => 0, 'lock' => $rStream['is_adult'], 'fav' => (in_array($rStream['id'], $rDevice['fav_channels']['live']) ? 1 : 0), 'archive' => (0 < $rStream['tv_archive_duration'] ? 1 : 0), 'genres_str' => '', 'cur_playing' => $rNowPlaying, 'epg' => array(), 'open' => 1, 'cmds' => array(array('id' => (string) $rStream['id'], 'ch_id' => (string) $rStream['id'], 'priority' => '0', 'url' => $rPlayer . $rStreamURL, 'status' => '1', 'use_http_tmp_link' => $rStreamSourceSt, 'wowza_tmp_link' => '0', 'user_agent_filter' => '', 'use_load_balancing' => '0', 'changed' => '', 'enable_monitoring' => '0', 'enable_balancer_monitoring' => '0', 'nginx_secure_link' => $rStreamSourceSt, 'flussonic_tmp_link' => '0')), 'use_load_balancing' => 0, 'pvr' => (int) $rStream['allow_record']);
        }

        if ($rDefaultPage) {
        } else {
            $rPage = 0;
            $rPosition = 0;
        }

        $rOutput = array('js' => array('total_items' => intval($rStreams['count']), 'max_page_items' => intval($rPageItems), 'selected_item' => $rPosition, 'cur_page' => ($rAll ? 0 : $rPage), 'data' => $rDatas));

        return json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ─── Сортировка ──────────────────────────────────────────────────

    public static function sortArrayStreamRating($a, $b)
    {
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

    public static function sortArrayStreamAdded($a, $b)
    {
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

    public static function sortArrayStreamNumber($a, $b)
    {
        if ($a['number'] != $b['number']) {
            return ($a['number'] < $b['number'] ? -1 : 1);
        }

        return 0;
    }

    public static function sortArrayStreamName($a, $b)
    {
        $rColumn = (isset($a['stream_display_name']) ? 'stream_display_name' : 'title');

        return strcmp($a[$rColumn], $b[$rColumn]);
    }

    // ─── Утилиты ─────────────────────────────────────────────────────

    /**
     * Извлечь HTTP-заголовки из $_SERVER.
     */
    public static function getHeaders()
    {
        $rHeaders = array();

        foreach ($_SERVER as $rName => $rValue) {
            if (substr($rName, 0, 5) != 'HTTP_') {
            } else {
                $rHeaders[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($rName, 5)))))] = $rValue;
            }
        }

        return $rHeaders;
    }

    /**
     * Shutdown callback: закрыть MySQL-соединение.
     */
    public static function shutdown()
    {
        global $db;

        if (!is_object($db)) {
        } else {
            $db->close_mysql();
        }
    }
}
