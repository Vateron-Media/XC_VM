<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\Multithread;
use XcVm\Domain\Stream\StreamRepository;

/**
 * WatchCron — cron task for watch folders.
 *
 * This class implements the scheduled job that scans configured watch
 * directories (local or rclone), detects new media files, prepares
 * work items and dispatches `watch_item` console jobs to import or
 * update streams and bouquets.
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../../Core/Process/Thread.php';
require_once __DIR__ . '/../../Core/Process/Multithread.php';

/**
 * Class WatchCron
 *
 * Provides utility methods and the entrypoint for the watch cron job.
 */
class WatchCron {
    use \XcVm\Infrastructure\Database\DatabaseAware;


    /**
    * Get watch categories from the database.
    *
    * @param int|null $rType Category type (1 = movie, 2 = series). When null
    *                      returns all categories.
    * @return array Associative array keyed by `genre_id` of category rows.
     */
    public static function getWatchCategories($rType = null) {
        $db = self::db();
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
        * Get bouquet by its ID.
        *
        * @param int $rID Bouquet identifier.
        * @return array|null Bouquet row array or null when not found.
     */
    public static function getBouquet($rID) {
        $db = self::db();
        $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
        if ($db->num_rows() == 1) {
            return $db->get_row();
        }
    }

    /**
        * Process bouquet files found in the temporary directory.
        *
        * Reads `.bouquet` JSON files from the configured temporary path and
        * merges their contents into the corresponding bouquets in the
        * database.
     */
    public static function checkBouquets() {
        $db = self::db();
        $a39a336ad3894348 = array();
        $rBouquets = glob(WATCH_TMP_PATH . '*.bouquet');
        foreach ($rBouquets as $D3e2134ebfab5c71) {
            $rBouquet = json_decode(file_get_contents($D3e2134ebfab5c71), true);
            if (!isset($a39a336ad3894348[$rBouquet['bouquet_id']])) {
                $a39a336ad3894348[$rBouquet['bouquet_id']] = array('movie' => array(), 'series' => array());
            }
            $a39a336ad3894348[$rBouquet['bouquet_id']][$rBouquet['type']][] = $rBouquet['id'];
            unlink($D3e2134ebfab5c71);
        }
        foreach ($a39a336ad3894348 as $rBouquetID => $rBouquetData) {
            $rBouquet = self::getBouquet($rBouquetID);
            if ($rBouquet) {
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
     * Cleanup missing streams for a folder.
     *
     * Removes streams from the database when their source files no longer
     * exist in the scanned file list and the folder is configured with
     * `delete_missing` enabled.
     *
     * @param array $rFolderRow Watch folder row from DB.
     * @param array $rExistingFiles Array of found file paths for the folder.
     * @return void
     */
    public static function cleanupMissing($rFolderRow, $rExistingFiles) {
        $db = self::db();
        $rTypeMap = array('movie' => 2, 'series' => 3);
        $rType = $rTypeMap[$rFolderRow['type']] ?? 0;
        if (!$rType) return;
        $rExistingLookup = array_flip($rExistingFiles);
        $rDir = rtrim($rFolderRow['directory'], '/') . '/';
        $db->query('SELECT s.id, s.stream_source FROM streams s LEFT JOIN streams_servers ss ON ss.stream_id = s.id WHERE s.type = ? AND ss.server_id = ?', $rType, SERVER_ID);
        $rDeleted = 0;
        foreach ($db->get_rows() as $rStream) {
            $rSource = json_decode($rStream['stream_source'], true);
            if (!$rSource || empty($rSource[0])) continue;
            $rPrefix = 's:' . SERVER_ID . ':';
            if (substr($rSource[0], 0, strlen($rPrefix)) !== $rPrefix) continue;
            $rFilePath = substr($rSource[0], strlen($rPrefix));
            if ($rFilePath && substr($rFilePath, 0, strlen($rDir)) === $rDir && !isset($rExistingLookup[$rFilePath])) {
                StreamRepository::deleteStream($rStream['id'], SERVER_ID, true, true);
                $rDeleted++;
            }
        }
        if ($rDeleted > 0) {
            echo 'Deleted' . $rDeleted . ' missing from ' . $rFolderRow['directory'] . "\n";
        }
    }

    /**
     * Run the watch cron.
     *
     * Scans configured watch folders, prepares import jobs for newly
     * discovered media files and dispatches processing commands. When
     * `$rForce` is provided, only the folder with that ID is processed.
     *
     * @param int|false $rForce Folder ID to force-run, or false to run normal schedule.
     * @return void
     */
    public static function run($rForce) {
        $db = self::db();
        global $rThreadCount;
        global $rScanOffset;
        global $F7fa29461a8a5ee2;
        $rWatchCategories = array(1 => self::getWatchCategories(1), 2 => self::getWatchCategories(2));
        if (count(glob(WATCH_TMP_PATH . '*.bouquet')) > 0) {
            self::checkBouquets();
        }
        if (!$rForce) {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` = ? AND `active` = 1 AND (UNIX_TIMESTAMP() - `last_run` > ? OR `last_run` IS NULL) ORDER BY `id` ASC;", SERVER_ID, $rScanOffset);
        } else {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` = ? AND `id` = ?;", SERVER_ID, $rForce);
        }
        $rRows = $db->get_rows();
        if (count($rRows) > 0) {
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
                if ($rTMDBID) {
                    list($rSource) = json_decode($rRow['stream_source'], true);
                    $rTMDBDatabase['series'][$rTMDBID][$rRow['season_num'] . '_' . $rRow['episode_num']] = array('id' => $rRow['id'], 'source' => $rSource);
                }
            }
            $db->query('SELECT `streams`.`id`, `streams`.`stream_source`, `streams`.`movie_properties` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 2 AND `streams_servers`.`server_id` = ?;', SERVER_ID);
            foreach ($db->get_rows() as $rRow) {
                $rStreamDatabase[] = $rRow['stream_source'];
                $rTMDBID = (json_decode($rRow['movie_properties'], true)['tmdb_id'] ?: null);
                if ($rTMDBID) {
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
            if (!$rExtensions) {
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
                    if (count($rExtensions) == 0 || in_array(strtolower(pathinfo($rFile['Name'])['extension']), $rExtensions)) {
                        $rFiles[] = $rFile['Path'];
                    }
                    if (isset($rRow['auto_subtitles'])) {
                        if (in_array(strtolower(pathinfo($rFile['Path'])['extension']), array('srt', 'sub', 'sbv'))) {
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
                        if (isset($rRow['auto_subtitles'])) {
                            foreach (array('srt', 'sub', 'sbv') as $rExt) {
                                $rSubtitle = $rPathInfo['dirname'] . '/' . $rPathInfo['filename'] . '.' . $rExt;
                                if (in_array($rSubtitle, $rSubtitles)) {
                                    $d8c5b5dc1e354db6 = array('files' => array($rSubtitle), 'names' => array('Subtitles'), 'charset' => array('UTF-8'), 'location' => SERVER_ID);
                                    break;
                                }
                            }
                        }
                        $rThreadData[] = array('folder_id' => $rRow['id'], 'type' => $rRow['type'], 'directory' => $rRow['directory'], 'file' => $rFile, 'subtitles' => $d8c5b5dc1e354db6, 'category_id' => $rRow['category_id'], 'bouquets' => $rRow['bouquets'], 'disable_tmdb' => $rRow['disable_tmdb'], 'ignore_no_match' => $rRow['ignore_no_match'], 'fb_bouquets' => $rRow['fb_bouquets'], 'fb_category_id' => $rRow['fb_category_id'], 'language' => $rRow['language'], 'watch_categories' => $rWatchCategories, 'read_native' => $rRow['read_native'], 'movie_symlink' => $rRow['movie_symlink'], 'remove_subtitles' => $rRow['remove_subtitles'], 'auto_encode' => $rRow['auto_encode'], 'auto_upgrade' => $rRow['auto_upgrade'], 'fallback_title' => $rRow['fallback_title'], 'ffprobe_input' => $rRow['ffprobe_input'], 'transcode_profile_id' => $rRow['transcode_profile_id'], 'max_genres' => intval(SettingsManager::getAll()['max_genres']), 'duplicate_tmdb' => $rRow['duplicate_tmdb'], 'target_container' => $rRow['target_container'], 'alternative_titles' => SettingsManager::getAll()['alternative_titles'], 'fallback_parser' => SettingsManager::getAll()['fallback_parser']);
                        if (0 < $F7fa29461a8a5ee2 && count($rThreadData) == $F7fa29461a8a5ee2) {
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
                $rCommand = '/usr/bin/timeout 60 ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php watch_item "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '"';
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
            if (!empty($rRow['delete_missing'])) {
                self::cleanupMissing($rRow, $rFiles);
            }
            self::checkBouquets();
        }
    }
}
