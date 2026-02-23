<?php
/**
 * PlexCron — модуль синхронизации Plex (крон-задача).
 *
 * Извлечён из crons/plex.php (Фаза 5.1).
 * Thread/Multithread вынесены в core/Process/ (Фаза 5 аудит).
 */

require_once __DIR__ . '/../../core/Process/Thread.php';
require_once __DIR__ . '/../../core/Process/Multithread.php';

class PlexCron {
    /**
     * Получить категории Plex из БД.
     *
     * @param int|null $rType Тип категории (3=movie, 4=show)
     * @return array
     */
    public static function getPlexCategories($rType = null) {
        global $db;
        $rReturn = array();
        if ($rType) {
            $db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
        } else {
            $db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
        }
        foreach ($db->get_rows() as $rRow) {
            $rReturn[$rRow['genre']] = $rRow;
        }
        return $rReturn;
    }

    /**
     * Выполнить HTTP-запрос через cURL.
     *
     * @param string $rURL
     * @return string|false
     */
    public static function readURL($rURL) {
        $rCurl = curl_init($rURL);
        curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
        return curl_exec($rCurl);
    }

    /**
     * Нормализовать XML-массив (одиночный элемент → массив элементов).
     *
     * @param array $rArray
     * @return array
     */
    public static function makeArray($rArray) {
        if (isset($rArray['@attributes'])) {
            $rArray = array($rArray);
        }
        return $rArray;
    }

    /**
     * Получить букет по ID.
     *
     * @param int $rID
     * @return array|null
     */
    public static function getBouquet($rID) {
        global $db;
        $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
        if ($db->num_rows() == 1) {
            return $db->get_row();
        }
    }

    /**
     * Проверить и создать новые категории из временных файлов.
     */
    public static function checkCategories() {
        global $db;
        $rPlexCategories = array('movie' => self::getPlexCategories(3), 'show' => self::getPlexCategories(4));
        $rCategories = glob(WATCH_TMP_PATH . '*.pcat');
        $rCatID = array('movie' => 1, 'show' => 1);
        $db->query('SELECT MAX(`genre_id`) AS `max` FROM `watch_categories` WHERE `type` = 3;');
        $rCatID['movie'] = intval($db->get_row()['max']);
        $db->query('SELECT MAX(`genre_id`) AS `max` FROM `watch_categories` WHERE `type` = 4;');
        $rCatID['show'] = intval($db->get_row()['max']);
        foreach ($rCategories as $a539efc67de58f76) {
            $rCategory = json_decode(file_get_contents($a539efc67de58f76), true);
            if (in_array($rCategory['title'], array_keys($rPlexCategories[$rCategory['type']]))) {
            } else {
                $rCatID[$rCategory['type']] += 1;
                $db->query("INSERT INTO `watch_categories` (`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES (?, ?, ?, 0, '[]');", array('movie' => 3, 'show' => 4)[$rCategory['type']], $rCatID[$rCategory['type']], $rCategory['title']);
            }
            unlink($a539efc67de58f76);
        }
    }

    /**
     * Обработать файлы букетов во временной директории.
     */
    public static function checkBouquets() {
        global $db;
        $a39a336ad3894348 = array();
        $rBouquets = glob(WATCH_TMP_PATH . '*.pbouquet');
        foreach ($rBouquets as $D3e2134ebfab5c71) {
            $rBouquet = json_decode(file_get_contents($D3e2134ebfab5c71), true);
            if (isset($a39a336ad3894348[$rBouquet['bouquet_id']])) {
            } else {
                $a39a336ad3894348[$rBouquet['bouquet_id']] = array('movie' => array(), 'series' => array());
            }
            $a39a336ad3894348[$rBouquet['bouquet_id']][$rBouquet['type']][] = $rBouquet['id'];
            unlink($D3e2134ebfab5c71);
        }
        foreach ($a39a336ad3894348 as $rBouquetID => $rBouquetData) {
            $rBouquet = self::getBouquet($rBouquetID);
            if ($rBouquet) {
                foreach (array_keys($rBouquetData) as $rType) {
                    if ($rType == 'movie') {
                        $rColumn = 'bouquet_movies';
                    } else {
                        $rColumn = 'bouquet_series';
                    }
                    $rChannels = json_decode($rBouquet[$rColumn], true);
                    foreach ($rBouquetData[$rType] as $rID) {
                        if (0 >= intval($rID) || in_array($rID, $rChannels)) {
                        } else {
                            $rChannels[] = $rID;
                        }
                    }
                    $db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
                }
            }
        }
    }

    /**
     * Основная точка входа крона Plex.
     * Заменяет loadCron().
     */
    public static function run() {
        global $db;
        global $rScanOffset;
        global $rForce;
        $rPlexCategories = array(3 => self::getPlexCategories(3), 4 => self::getPlexCategories(4));
        self::checkBouquets();
        self::checkCategories();
        if (!$rForce) {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' AND `server_id` = ? AND `active` = 1 AND (UNIX_TIMESTAMP() - `last_run` > ? OR `last_run` IS NULL) ORDER BY `id` ASC;", SERVER_ID, $rScanOffset);
        } else {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` = 'plex' AND `server_id` = ? AND `id` = ?;", SERVER_ID, $rForce);
        }
        $rRows = $db->get_rows();
        if (count($rRows) > 0) {
            shell_exec('rm -f ' . WATCH_TMP_PATH . '*.ppid');
            $rLeafCount = $rUUIDs = $rSeriesTMDB = $rStreamDatabase = array();
            $rTMDBDatabase = array('movie' => array(), 'series' => array());
            $rPlexDatabase = array('movie' => array(), 'series' => array());
            echo 'Generating cache...' . "\n";
            $db->query('SELECT `id`, `tmdb_id`, `plex_uuid` FROM `streams_series` WHERE `tmdb_id` IS NOT NULL AND `tmdb_id` > 0;');
            foreach ($db->get_rows() as $rRow) {
                $rSeriesTMDB[$rRow['id']] = $rRow['tmdb_id'];
                if (!empty($rRow['plex_uuid'])) {
                    $rUUIDs[] = $rRow['plex_uuid'];
                }
            }
            $db->query('SELECT `streams`.`id`, `streams_series`.`plex_uuid`, `streams_episodes`.`series_id`, `streams_episodes`.`season_num`, `streams_episodes`.`episode_num`, `streams`.`stream_source` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` WHERE `streams_servers`.`server_id` = ?;', SERVER_ID);
            foreach ($db->get_rows() as $rRow) {
                $rStreamDatabase[] = $rRow['stream_source'];
                $rTMDBID = ($rSeriesTMDB[$rRow['series_id']] ?: null);
                list($rSource) = json_decode($rRow['stream_source'], true);
                if ($rTMDBID) {
                    $rTMDBDatabase['series'][$rTMDBID][$rRow['season_num'] . '_' . $rRow['episode_num']] = array('id' => $rRow['id'], 'source' => $rSource);
                }
                if (!empty($rRow['plex_uuid'])) {
                    $rPlexDatabase['series'][$rRow['plex_uuid']][$rRow['season_num'] . '_' . $rRow['episode_num']] = array('id' => $rRow['id'], 'source' => $rSource);
                    $rLeafCount[$rRow['plex_uuid']]++;
                }
            }
            $db->query('SELECT `streams`.`id`, `streams`.`plex_uuid`, `streams`.`stream_source`, `streams`.`movie_properties` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 2 AND `streams_servers`.`server_id` = ?;', SERVER_ID);
            foreach ($db->get_rows() as $rRow) {
                $rStreamDatabase[] = $rRow['stream_source'];
                $rTMDBID = (json_decode($rRow['movie_properties'], true)['tmdb_id'] ?: null);
                list($rSource) = json_decode($rRow['stream_source'], true);
                if ($rTMDBID) {
                    $rTMDBDatabase['movie'][$rTMDBID] = array('id' => $rRow['id'], 'source' => $rSource);
                }
                if (!empty($rRow['plex_uuid'])) {
                    $rPlexDatabase['movie'][$rRow['plex_uuid']] = array('id' => $rRow['id'], 'source' => $rSource);
                    $rUUIDs[] = $rRow['plex_uuid'];
                }
            }
            exec('find ' . WATCH_TMP_PATH . ' -maxdepth 1 -name "*.pcache" -print0 | xargs -0 rm');
            file_put_contents(WATCH_TMP_PATH . 'stream_database.pcache', json_encode($rStreamDatabase));
            foreach ($rTMDBDatabase['series'] as $rTMDBID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.pcache', json_encode($rData));
            }
            foreach ($rTMDBDatabase['movie'] as $rTMDBID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.pcache', json_encode($rData));
            }
            foreach ($rPlexDatabase['series'] as $rPlexID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'series_' . $rPlexID . '.pcache', json_encode($rData));
            }
            foreach ($rPlexDatabase['movie'] as $rPlexID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'movie_' . $rPlexID . '.pcache', json_encode($rData));
            }
            unset($rTMDBDatabase, $rPlexDatabase);
            echo 'Finished generating cache!' . "\n";
        }
        foreach ($rRows as $rRow) {
            $rLimit = 100;
            $rThreadData = array();

            // Get a Plex token (with caching)
            $rToken = CoreUtilities::getPlexToken($rRow['plex_ip'], $rRow['plex_port'], $rRow['plex_username'], $rRow['plex_password']);

            $db->query('UPDATE `watch_folders` SET `last_run` = UNIX_TIMESTAMP() WHERE `id` = ?;', $rRow['id']);

            $rSectionURL = 'http://' . $rRow['plex_ip'] . ':' . $rRow['plex_port'] . '/library/sections?X-Plex-Token=' . $rToken;
            $rSections = json_decode(json_encode(simplexml_load_string(self::readURL($rSectionURL))), true);
            $rThreadCount = 1;
            foreach (self::makeArray($rSections['Directory']) as $F24f1be2729b363d) {
                if ($F24f1be2729b363d['@attributes']['type'] == 'movie') {
                    $rThreadCount = (intval(CoreUtilities::$rSettings['thread_count_movie']) ?: 25);
                } else {
                    $rThreadCount = (intval(CoreUtilities::$rSettings['thread_count_show']) ?: 5);
                }
                $rKey = $F24f1be2729b363d['@attributes']['key'];
                if ($rKey == $rRow['directory']) {
                    $B9690335cedc4164 = 'http://' . $rRow['plex_ip'] . ':' . $rRow['plex_port'] . '/library/sections/' . $rKey . '/all?X-Plex-Token=' . $rToken . '&X-Plex-Container-Start=0&X-Plex-Container-Size=1';
                    $rCount = (intval(json_decode(json_encode(simplexml_load_string(self::readURL($B9690335cedc4164))), true)['@attributes']['totalSize']) ?: 0);
                    echo 'Count: ' . $rCount . "\n";
                    if ($rCount > 0) {
                        $rSteps = [];
                        for ($i = 0; $i <= $rCount; $i += $rLimit) {
                            $rSteps[] = $i;
                        }

                        if (!$rSteps) {
                            $rSteps = [0];
                        }
                        foreach ($rSteps as $rStart) {
                            $d7bd8e11c885f937 = 'http://' . $rRow['plex_ip'] . ':' . $rRow['plex_port'] . '/library/sections/' . $rKey . '/all?X-Plex-Token=' . $rToken . '&X-Plex-Container-Start=' . $rStart . '&X-Plex-Container-Size=' . $rLimit . '&sort=updatedAt%3Adesc';
                            $rContent = json_decode(json_encode(simplexml_load_string(self::readURL($d7bd8e11c885f937))), true);
                            if (!isset($rContent['Video'])) {
                                $rContent['Video'] = $rContent['Directory'];
                            }
                            foreach (self::makeArray($rContent['Video']) as $rItem) {
                                $rUUID = $rKey . '_' . $rItem['@attributes']['ratingKey'];
                                $rUpdatedAt = intval($rItem['@attributes']['updatedAt'] ?? 0);
                                $lastRun = intval($rRow['last_run'] ?? 0);
                                $rIsNewOrUpdated = !$lastRun || $rUpdatedAt === 0 || $lastRun < $rUpdatedAt;

                                if ($F24f1be2729b363d['@attributes']['type'] == 'movie') {
                                    // Movies
                                    $rIsMissing = $rRow['scan_missing'] && !in_array($rUUID, $rUUIDs, true);
                                    if ($rIsNewOrUpdated || $rIsMissing || $rForce) {
                                        $rThreadData[] = [
                                            'folder_id' => $rRow['id'],
                                            'type' => 'movie',
                                            'key' => $rItem['@attributes']['ratingKey'],
                                            'uuid' => $rUUID,
                                            'plex_categories' => $rPlexCategories,
                                            'read_native' => $rRow['read_native'],
                                            'movie_symlink' => $rRow['movie_symlink'],
                                            'remove_subtitles' => $rRow['remove_subtitles'],
                                            'auto_encode' => $rRow['auto_encode'],
                                            'auto_upgrade' => $rRow['auto_upgrade'],
                                            'transcode_profile_id' => $rRow['transcode_profile_id'],
                                            'max_genres' => intval(CoreUtilities::$rSettings['max_genres'] ?? 5),
                                            'plex' => true,
                                            'ip' => $rRow['plex_ip'],
                                            'port' => $rRow['plex_port'],
                                            'token' => $rToken,
                                            'fb_bouquets' => $rRow['fb_bouquets'],
                                            'store_categories' => $rRow['store_categories'],
                                            'category_id' => $rRow['category_id'],
                                            'bouquets' => $rRow['bouquets'],
                                            'fb_category_id' => $rRow['fb_category_id'],
                                            'check_tmdb' => $rRow['check_tmdb'],
                                            'target_container' => $rRow['target_container'],
                                            'server_add' => $rRow['server_add'],
                                            'direct_proxy' => $rRow['direct_proxy']
                                        ];
                                    }
                                } else {
                                    // TV series
                                    $rCurrentLeafCount = intval($rItem['@attributes']['leafCount'] ?? 0);
                                    $rPreviousLeafCount = $rLeafCount[$rUUID] ?? 0;
                                    $rLeafCountChanged = $rCurrentLeafCount != $rPreviousLeafCount;
                                    $rIsMissing = $rRow['scan_missing'] && empty($rLeafCount[$rUUID]);

                                    if ($rIsNewOrUpdated || $rLeafCountChanged || $rIsMissing) {
                                        $rThreadData[] = [
                                            'folder_id' => $rRow['id'],
                                            'type' => $F24f1be2729b363d['@attributes']['type'],
                                            'key' => $rItem['@attributes']['ratingKey'],
                                            'uuid' => $rUUID,
                                            'plex_categories' => $rPlexCategories,
                                            'read_native' => $rRow['read_native'],
                                            'movie_symlink' => $rRow['movie_symlink'],
                                            'remove_subtitles' => $rRow['remove_subtitles'],
                                            'auto_encode' => $rRow['auto_encode'],
                                            'auto_upgrade' => $rRow['auto_upgrade'],
                                            'transcode_profile_id' => $rRow['transcode_profile_id'],
                                            'max_genres' => intval(CoreUtilities::$rSettings['max_genres'] ?? 5),
                                            'plex' => true,
                                            'ip' => $rRow['plex_ip'],
                                            'port' => $rRow['plex_port'],
                                            'token' => $rToken,
                                            'fb_bouquets' => $rRow['fb_bouquets'],
                                            'store_categories' => $rRow['store_categories'],
                                            'category_id' => $rRow['category_id'],
                                            'bouquets' => $rRow['bouquets'],
                                            'fb_category_id' => $rRow['fb_category_id'],
                                            'check_tmdb' => $rRow['check_tmdb'],
                                            'target_container' => $rRow['target_container'],
                                            'server_add' => $rRow['server_add'],
                                            'direct_proxy' => $rRow['direct_proxy']
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    break;
                }
            }
            if (count($rThreadData) > 0) {
                echo 'Scan complete! Adding ' . count($rThreadData) . ' files...' . "\n";
            }

            $cacheDataKey = array();
            foreach ($rThreadData as $rData) {
                if ($rData['type'] == 'movie') {
                    $rCommand = '/usr/bin/timeout 20 ' . PHP_BIN . ' ' . INCLUDES_PATH . 'cli/plex_item.php "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '"';
                } else {
                    $rCommand = '/usr/bin/timeout 300 ' . PHP_BIN . ' ' . INCLUDES_PATH . 'cli/plex_item.php "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '"';
                }
                $cacheDataKey[] = $rCommand;
            }
            unset($rThreadData);
            $db->close_mysql();
            if ($rThreadCount <= 1) {
                foreach ($cacheDataKey as $rCommand) {
                    shell_exec($rCommand);
                }
            } else {
                $cacheMetadataKey = new Multithread($cacheDataKey, $rThreadCount);
                $cacheMetadataKey->run();
            }
            $db->db_connect();
            self::checkBouquets();
            self::checkCategories();
        }
    }
}
