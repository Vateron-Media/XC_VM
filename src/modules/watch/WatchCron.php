<?php

/**
 * WatchCron — крон-задача Watch Folder.
 *
 * Извлечён из crons/watch.php (Фаза 5.2).
 * Thread/Multithread вынесены в core/Process/ (Фаза 5 аудит).
 */

require_once __DIR__ . '/../../core/Process/Thread.php';
require_once __DIR__ . '/../../core/Process/Multithread.php';

class WatchCron {
    /**
     * Получить категории Watch из БД.
     *
     * @param int|null $rType Тип категории (1=movie, 2=series)
     * @return array
     */
    public static function getWatchCategories($rType = null) {
        global $db;
        $rReturn = array();
        if ($rType) {
            $db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
        } else {
            $db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
        }
        foreach ($db->get_rows() as $rRow) {
            $rReturn[$rRow['genre_id']] = $rRow;
        }
        return $rReturn;
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
        if ($db->num_rows() != 1) {
        } else {
            return $db->get_row();
        }
    }

    /**
     * Обработать файлы букетов во временной директории.
     */
    public static function checkBouquets() {
        global $db;
        $a39a336ad3894348 = array();
        $rBouquets = glob(WATCH_TMP_PATH . '*.bouquet');
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
            if (!$rBouquet) {
            } else {
                foreach (array('movie', 'series') as $rType) {
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
     * Основная точка входа крона Watch Folder.
     * Заменяет loadCron().
     */
    public static function run() {
        global $db;
        global $rThreadCount;
        global $rScanOffset;
        global $F7fa29461a8a5ee2;
        global $rForce;
        $rWatchCategories = array(1 => self::getWatchCategories(1), 2 => self::getWatchCategories(2));
        if (0 >= count(glob(WATCH_TMP_PATH . '*.bouquet'))) {
        } else {
            self::checkBouquets();
        }
        if (!$rForce) {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` = ? AND `active` = 1 AND (UNIX_TIMESTAMP() - `last_run` > ? OR `last_run` IS NULL) ORDER BY `id` ASC;", SERVER_ID, $rScanOffset);
        } else {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` = ? AND `id` = ?;", SERVER_ID, $rForce);
        }
        $rRows = $db->get_rows();
        if (0 >= count($rRows)) {
        } else {
            shell_exec('rm -f ' . WATCH_TMP_PATH . '*.wpid');
            $rSeriesTMDB = $rStreamDatabase = array();
            $rTMDBDatabase = array('movie' => array(), 'series' => array());
            echo 'Generating cache...' . "\n";
            $db->query('SELECT `id`, `tmdb_id` FROM `streams_series` WHERE `tmdb_id` IS NOT NULL AND `tmdb_id` > 0;');
            foreach ($db->get_rows() as $rRow) {
                $rSeriesTMDB[$rRow['id']] = $rRow['tmdb_id'];
            }
            $db->query('SELECT `streams`.`id`, `streams_episodes`.`series_id`, `streams_episodes`.`season_num`, `streams_episodes`.`episode_num`, `streams`.`stream_source` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams_servers`.`server_id` = ?;', SERVER_ID);
            foreach ($db->get_rows() as $rRow) {
                $rStreamDatabase[] = $rRow['stream_source'];
                $rTMDBID = $rSeriesTMDB[$rRow['series_id']];
                if (!$rTMDBID) {
                } else {
                    list($rSource) = json_decode($rRow['stream_source'], true);
                    $rTMDBDatabase['series'][$rTMDBID][$rRow['season_num'] . '_' . $rRow['episode_num']] = array('id' => $rRow['id'], 'source' => $rSource);
                }
            }
            $db->query('SELECT `streams`.`id`, `streams`.`stream_source`, `streams`.`movie_properties` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 2 AND `streams_servers`.`server_id` = ?;', SERVER_ID);
            foreach ($db->get_rows() as $rRow) {
                $rStreamDatabase[] = $rRow['stream_source'];
                $rTMDBID = (json_decode($rRow['movie_properties'], true)['tmdb_id'] ?: null);
                if (!$rTMDBID) {
                } else {
                    list($rSource) = json_decode($rRow['stream_source'], true);
                    $rTMDBDatabase['movie'][$rTMDBID] = array('id' => $rRow['id'], 'source' => $rSource);
                }
            }
            exec('find ' . WATCH_TMP_PATH . ' -maxdepth 1 -name "*.cache" -print0 | xargs -0 rm');
            foreach ($rTMDBDatabase['series'] as $rTMDBID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache', json_encode($rData));
            }
            foreach ($rTMDBDatabase['movie'] as $rTMDBID => $rData) {
                file_put_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache', json_encode($rData));
            }
            unset($rTMDBDatabase);
            echo 'Finished generating cache!' . "\n";
        }
        foreach ($rRows as $rRow) {
            $db->query('UPDATE `watch_folders` SET `last_run` = UNIX_TIMESTAMP() WHERE `id` = ?;', $rRow['id']);
            $rExtensions = json_decode($rRow['allowed_extensions'], true);
            if ($rExtensions) {
            } else {
                $rExtensions = array();
            }
            if (count($rExtensions) == 0) {
                $rExtensions = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'flv', 'wmv', 'mov', 'ts');
            }
            $rSubtitles = $rFiles = array();
            if (0 < strlen($rRow['rclone_dir'])) {
                $rCommand = 'rclone --config "' . CONFIG_PATH . 'rclone.conf" lsjson ' . escapeshellarg($rRow['rclone_dir']) . ' -R --fast-list --files-only';
                exec($rCommand, $a364ed03b3639bd1, $Ee034ad5c6b0c8a3);
                $rData = implode(' ', $a364ed03b3639bd1);
                if (!substr($rData, 0, 1) != '[') {
                } else {
                    $rData = '[' . explode('[', $rData, 1)[1];
                }
                $a364ed03b3639bd1 = json_decode($rData, true);
                foreach ($a364ed03b3639bd1 as $rFile) {
                    $rFile['Path'] = rtrim($rRow['directory'], '/') . '/' . $rFile['Path'];
                    if (!(count($rExtensions) == 0 || in_array(strtolower(pathinfo($rFile['Name'])['extension']), $rExtensions))) {
                    } else {
                        $rFiles[] = $rFile['Path'];
                    }
                    if (!isset($rRow['auto_subtitles'])) {
                    } else {
                        if (!in_array(strtolower(pathinfo($rFile['Path'])['extension']), array('srt', 'sub', 'sbv'))) {
                        } else {
                            $rSubtitles[] = $rFile['Path'];
                        }
                    }
                }
            } else {
                if (0 < count($rExtensions)) {
                    $rExtensions = escapeshellcmd(implode('|', $rExtensions));
                    $rCommand = '/usr/bin/find "' . escapeshellcmd($rRow['directory']) . '" -regex ".*\\.\\(' . $rExtensions . '\\)"';
                } else {
                    $rCommand = '/usr/bin/find "' . escapeshellcmd($rRow['directory']) . '"';
                }
                exec($rCommand, $rFiles, $Ee034ad5c6b0c8a3);
                if (isset($rRow['auto_subtitles'])) {
                    $rExtensions = escapeshellcmd(implode('|', array('srt', 'sub', 'sbv')));
                    $rCommand = '/usr/bin/find "' . escapeshellcmd($rRow['directory']) . '" -regex ".*\\.\\(' . $rExtensions . '\\)"';
                    exec($rCommand, $rSubtitles, $Ee034ad5c6b0c8a3);
                } else {
                    $rSubtitles = array();
                }
            }
            $rThreadData = array();
            foreach ($rFiles as $rFile) {
                if (time() - filemtime($rFile) >= 30) {
                    if (in_array(json_encode(array('s:' . SERVER_ID . ':' . $rFile), JSON_UNESCAPED_UNICODE), $rStreamDatabase)) {
                    } else {
                        $rPathInfo = pathinfo($rFile);
                        $d8c5b5dc1e354db6 = array();
                        if (!isset($rRow['auto_subtitles'])) {
                        } else {
                            foreach (array('srt', 'sub', 'sbv') as $rExt) {
                                $rSubtitle = $rPathInfo['dirname'] . '/' . $rPathInfo['filename'] . '.' . $rExt;
                                if (!in_array($rSubtitle, $rSubtitles)) {
                                } else {
                                    $d8c5b5dc1e354db6 = array('files' => array($rSubtitle), 'names' => array('Subtitles'), 'charset' => array('UTF-8'), 'location' => SERVER_ID);
                                    break;
                                }
                            }
                        }
                        $rThreadData[] = array('folder_id' => $rRow['id'], 'type' => $rRow['type'], 'directory' => $rRow['directory'], 'file' => $rFile, 'subtitles' => $d8c5b5dc1e354db6, 'category_id' => $rRow['category_id'], 'bouquets' => $rRow['bouquets'], 'disable_tmdb' => $rRow['disable_tmdb'], 'ignore_no_match' => $rRow['ignore_no_match'], 'fb_bouquets' => $rRow['fb_bouquets'], 'fb_category_id' => $rRow['fb_category_id'], 'language' => $rRow['language'], 'watch_categories' => $rWatchCategories, 'read_native' => $rRow['read_native'], 'movie_symlink' => $rRow['movie_symlink'], 'remove_subtitles' => $rRow['remove_subtitles'], 'auto_encode' => $rRow['auto_encode'], 'auto_upgrade' => $rRow['auto_upgrade'], 'fallback_title' => $rRow['fallback_title'], 'ffprobe_input' => $rRow['ffprobe_input'], 'transcode_profile_id' => $rRow['transcode_profile_id'], 'max_genres' => intval(CoreUtilities::$rSettings['max_genres']), 'duplicate_tmdb' => $rRow['duplicate_tmdb'], 'target_container' => $rRow['target_container'], 'alternative_titles' => CoreUtilities::$rSettings['alternative_titles'], 'fallback_parser' => CoreUtilities::$rSettings['fallback_parser']);
                        if (!(0 < $F7fa29461a8a5ee2 && count($rThreadData) == $F7fa29461a8a5ee2)) {
                        } else {
                            break;
                        }
                    }
                }
            }
            if (count($rThreadData) > 0) {
                echo 'Scan complete! Adding ' . count($rThreadData) . ' files...' . "\n";
            }
            $cacheDataKey = array();
            foreach ($rThreadData as $rData) {
                $rCommand = '/usr/bin/timeout 60 ' . PHP_BIN . ' ' . INCLUDES_PATH . 'cli/watch_item.php "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '"';
                $cacheDataKey[] = $rCommand;
            }
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
        }
    }
}
