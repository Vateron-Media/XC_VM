<?php

/**
 * CacheEngineCronJob — cache engine cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class CacheEngineCronJob implements CommandInterface {
    use CronTrait;

    private $rPID;
    private $rSplit = 10000;
    private $rThreadCount;
    private $rForce = false;
    private $rUpdateIDs = [];

    public function getName(): string {
        return 'cron:cache_engine';
    }

    public function getDescription(): string {
        return 'Cron: generate cache for lines, streams, series, groups';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->rPID = getmypid();
        register_shutdown_function([$this, 'shutdown']);

        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        SettingsManager::set(SettingsRepository::getAll(true));
        $this->rThreadCount = (SettingsManager::getAll()['cache_thread_count'] ?: 10);

        $rType = null;
        $rGroupStart = $rGroupMax = null;

        if (!empty($rArgs[0])) {
            $rType = $rArgs[0];
            if ($rType == 'streams_update' || $rType == 'lines_update') {
                $this->rUpdateIDs = array_map('intval', explode(',', $rArgs[1] ?? ''));
            } else {
                if (isset($rArgs[1]) && isset($rArgs[2])) {
                    $rGroupStart = intval($rArgs[1]);
                    $rGroupMax = intval($rArgs[2]);
                }
            }
            if ($rType == 'force') {
                echo 'Forcing cache regen...' . "\n";
                SettingsManager::update('cache_changes', false);
                $this->rForce = true;
            }
        } else {
            shell_exec("kill -9 \$(ps aux | grep 'cache_engine' | grep -v grep | grep -v " . $this->rPID . " | awk '{print \$2}')");
        }

        require_once MAIN_HOME . 'core/Process/Thread.php';
        require_once MAIN_HOME . 'core/Process/Multithread.php';

        $this->loadCron($rType, $rGroupStart, $rGroupMax);

        return 0;
    }

    private function getChangedStreams(): array {
        global $db;
        $rReturn = ['changes' => [], 'delete' => []];
        $rExisting = [];
        $db->query('SELECT `id`, GREATEST(IFNULL(UNIX_TIMESTAMP(`streams`.`updated`), 0), IFNULL(MAX(UNIX_TIMESTAMP(`streams_servers`.`updated`)), 0)) AS `updated` FROM `streams` LEFT JOIN `streams_servers` ON `streams`.`id` = `streams_servers`.`stream_id` GROUP BY `id`;');
        if ($db->dbh && $db->result) {
            if ($db->result->rowCount() > 0) {
                foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                    if (!file_exists(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) || (filemtime(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) ?: 0) < $rRow['updated']) {
                        $rReturn['changes'][] = $rRow['id'];
                    }
                    $rExisting[] = $rRow['id'];
                }
            }
        }
        $rExisting = array_flip($rExisting);
        foreach (glob(STREAMS_TMP_PATH . 'stream_*') as $rFile) {
            $rParts = explode('_', $rFile);
            $rStreamID = intval(end($rParts));
            if (!isset($rExisting[$rStreamID])) {
                $rReturn['delete'][] = $rStreamID;
            }
        }
        return $rReturn;
    }

    private function getChangedLines(): array {
        global $db;
        $rReturn = ['changes' => [], 'delete_i' => [], 'delete_c' => [], 'delete_t' => []];
        $cacheMemoryAllocation = glob(LINES_TMP_PATH . 'line_i_*');
        $cacheFailureHandler = glob(LINES_TMP_PATH . 'line_c_*');
        $cacheSuccessIndicator = glob(LINES_TMP_PATH . 'line_t_*');
        $cacheRevalidationCheck = $cacheDataCompression = $cacheDataDecompression = [];
        $db->query('SELECT `id`, `username`, `password`, `access_token`, UNIX_TIMESTAMP(`updated`) AS `updated` FROM `lines`;');
        if ($db->dbh && $db->result) {
            if ($db->result->rowCount() > 0) {
                foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                    if (!file_exists(LINES_TMP_PATH . 'line_i_' . $rRow['id']) || (filemtime(LINES_TMP_PATH . 'line_i_' . $rRow['id']) ?: 0) < $rRow['updated']) {
                        $rReturn['changes'][] = $rRow['id'];
                    }
                    $cacheRevalidationCheck[] = $rRow['id'];
                    $cacheDataCompression[] = (SettingsManager::getAll()['case_sensitive_line'] ? $rRow['username'] . '_' . $rRow['password'] : strtolower($rRow['username'] . '_' . $rRow['password']));
                    if ($rRow['access_token']) {
                        $cacheDataDecompression[] = $rRow['access_token'];
                    }
                }
            }
        }
        $cacheRevalidationCheck = array_flip($cacheRevalidationCheck);
        foreach ($cacheMemoryAllocation as $rFile) {
            $rUserID = (intval(explode('line_i_', $rFile, 2)[1]) ?: null);
            if ($rUserID && !isset($cacheRevalidationCheck[$rUserID])) {
                $rReturn['delete_i'][] = $rUserID;
            }
        }
        $cacheDataCompression = array_flip($cacheDataCompression);
        foreach ($cacheFailureHandler as $rFile) {
            $cacheExpirationTime = (explode('line_c_', $rFile, 2)[1] ?: null);
            if ($cacheExpirationTime && !isset($cacheDataCompression[$cacheExpirationTime])) {
                $rReturn['delete_c'][] = $cacheExpirationTime;
            }
        }
        $cacheDataDecompression = array_flip($cacheDataDecompression);
        foreach ($cacheSuccessIndicator as $rFile) {
            $rToken = (explode('line_t_', $rFile, 2)[1] ?: null);
            if ($rToken && !isset($cacheDataDecompression[$rToken])) {
                $rReturn['delete_t'][] = $rToken;
            }
        }
        return $rReturn;
    }

    private function loadCron($rType, $rGroupStart, $rGroupMax): void {
        global $db;
        $rStartTime = time();
        if (ProcessManager::isNginxRunning()) {
            if (SettingsManager::getAll()['enable_cache'] || !empty($this->rUpdateIDs)) {
                switch ($rType) {
                    case 'lines':
                        $this->generateLines($rGroupStart, $rGroupMax);
                        break;
                    case 'lines_update':
                        $this->generateLines(null, null, $this->rUpdateIDs);
                        break;
                    case 'series':
                        $this->generateSeries($rGroupStart, $rGroupMax);
                        break;
                    case 'streams':
                        $this->generateStreams($rGroupStart, $rGroupMax);
                        break;
                    case 'streams_update':
                        $this->generateStreams(null, null, $this->rUpdateIDs);
                        break;
                    case 'groups':
                        $this->generateGroups();
                        break;
                    case 'lines_per_ip':
                        $this->generateLinesPerIP();
                        break;
                    case 'theft_detection':
                        $this->generateTheftDetection();
                        break;
                    default:
                        $cacheInitTime = $rSeriesCategories = [];
                        $db->query('SELECT `series_id`, MAX(`streams`.`added`) AS `last_modified` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` GROUP BY `series_id`;');
                        foreach ($db->get_rows() as $rRow) {
                            $cacheInitTime[$rRow['series_id']] = $rRow['last_modified'];
                        }
                        $db->query('SELECT * FROM `streams_series`;');
                        if ($db->result) {
                            if ($db->result->rowCount() > 0) {
                                foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                                    if (isset($cacheInitTime[$rRow['id']])) {
                                        $rRow['last_modified'] = $cacheInitTime[$rRow['id']];
                                    }
                                    $rSeriesCategories[$rRow['id']] = json_decode($rRow['category_id'], true);
                                    file_put_contents(SERIES_TMP_PATH . 'series_' . $rRow['id'], igbinary_serialize($rRow));
                                }
                            }
                        }
                        file_put_contents(SERIES_TMP_PATH . 'series_categories', igbinary_serialize($rSeriesCategories));
                        $rDelete = ['streams' => [], 'lines_i' => [], 'lines_c' => [], 'lines_t' => []];
                        $cacheDataKey = [];
                        if (SettingsManager::getAll()['cache_changes']) {
                            $rChanges = $this->getChangedLines();
                            $rDelete['lines_i'] = $rChanges['delete_i'];
                            $rDelete['lines_c'] = $rChanges['delete_c'];
                            $rDelete['lines_t'] = $rChanges['delete_t'];
                            if (count($rChanges['changes']) > 0) {
                                foreach (array_chunk($rChanges['changes'], $this->rSplit) as $rChunk) {
                                    $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines_update" "' . implode(',', $rChunk) . '"';
                                }
                            }
                        } else {
                            $db->query('SELECT COUNT(*) AS `count` FROM `lines`;');
                            $rLinesCount = $db->get_row()['count'];
                            $cacheValidityCheck = $this->rSplit > $rLinesCount ? [0, $rLinesCount] : range(0, $rLinesCount, $this->rSplit);
                            if (!$cacheValidityCheck) {
                                $cacheValidityCheck = [0];
                            }
                            foreach ($cacheValidityCheck as $rStart) {
                                $rMax = $this->rSplit;
                                if ($rLinesCount < $rStart + $rMax) {
                                    $rMax = $rLinesCount - $rStart;
                                }
                                $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines" ' . $rStart . ' ' . $rMax;
                            }
                        }
                        $db->query('SELECT COUNT(*) AS `count` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5);');
                        $cacheRetrieveMethod = (int) $db->get_row()['count'];
                        $cacheStoreMethod = [];
                        if ($cacheRetrieveMethod > 0) {
                            for ($rStart = 0; $rStart < $cacheRetrieveMethod; $rStart += $this->rSplit) {
                                $rMax = min($this->rSplit, $cacheRetrieveMethod - $rStart);
                                $cacheStoreMethod[] = $rStart;
                                $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "series" ' . $rStart . ' ' . $rMax;
                            }
                        } else {
                            $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "series" 0 0';
                        }
                        if (SettingsManager::getAll()['cache_changes']) {
                            $rChanges = $this->getChangedStreams();
                            $rDelete['streams'] = $rChanges['delete'];
                            if (count($rChanges['changes']) > 0) {
                                foreach (array_chunk($rChanges['changes'], $this->rSplit) as $rChunk) {
                                    $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "streams_update" "' . implode(',', $rChunk) . '"';
                                }
                            }
                        } else {
                            $db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
                            $cacheDeleteMethod = (int) $db->get_row()['count'];
                            $cacheCleanupTrigger = range(0, $cacheDeleteMethod, $this->rSplit);
                            if (!$cacheCleanupTrigger) {
                                $cacheCleanupTrigger = [0];
                            }
                            foreach ($cacheCleanupTrigger as $rStart) {
                                $rMax = $this->rSplit;
                                if ($cacheDeleteMethod < $rStart + $rMax) {
                                    $rMax = $cacheDeleteMethod - $rStart;
                                }
                                $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "streams" ' . $rStart . ' ' . $rMax;
                            }
                        }
                        $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "groups"';
                        $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines_per_ip"';
                        $cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "theft_detection"';
                        $cacheMetadataKey = new Multithread($cacheDataKey, $this->rThreadCount);
                        $cacheMetadataKey->run();
                        unset($cacheDataKey);
                        $rSeriesEpisodes = $rSeriesMap = [];
                        foreach ($cacheStoreMethod as $rStart) {
                            if (file_exists(SERIES_TMP_PATH . 'series_map_' . $rStart)) {
                                foreach (igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_map_' . $rStart)) as $rStreamID => $rSeriesID) {
                                    $rSeriesMap[$rStreamID] = $rSeriesID;
                                }
                                unlink(SERIES_TMP_PATH . 'series_map_' . $rStart);
                            }
                            if (file_exists(SERIES_TMP_PATH . 'series_episodes_' . $rStart)) {
                                $rSeasonData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_episodes_' . $rStart));
                                foreach (array_keys($rSeasonData) as $rSeriesID) {
                                    if (!isset($rSeriesEpisodes[$rSeriesID])) {
                                        $rSeriesEpisodes[$rSeriesID] = [];
                                    }
                                    foreach (array_keys($rSeasonData[$rSeriesID]) as $rSeasonNum) {
                                        foreach ($rSeasonData[$rSeriesID][$rSeasonNum] as $rEpisode) {
                                            $rSeriesEpisodes[$rSeriesID][$rSeasonNum][] = $rEpisode;
                                        }
                                    }
                                }
                                unlink(SERIES_TMP_PATH . 'series_episodes_' . $rStart);
                            }
                        }
                        file_put_contents(SERIES_TMP_PATH . 'series_map', igbinary_serialize($rSeriesMap));
                        foreach ($rSeriesEpisodes as $rSeriesID => $rSeasons) {
                            file_put_contents(SERIES_TMP_PATH . 'episodes_' . $rSeriesID, igbinary_serialize($rSeasons));
                        }
                        if (SettingsManager::getAll()['cache_changes']) {
                            foreach ($rDelete['streams'] as $rStreamID) {
                                @unlink(STREAMS_TMP_PATH . 'stream_' . $rStreamID);
                            }
                            foreach ($rDelete['lines_i'] as $rUserID) {
                                @unlink(LINES_TMP_PATH . 'line_i_' . $rUserID);
                            }
                            foreach ($rDelete['lines_c'] as $cacheExpirationTime) {
                                @unlink(LINES_TMP_PATH . 'line_c_' . $cacheExpirationTime);
                            }
                            foreach ($rDelete['lines_t'] as $rToken) {
                                @unlink(LINES_TMP_PATH . 'line_t_' . $rToken);
                            }
                        } else {
                            foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rTmpPath) {
                                foreach (scandir($rTmpPath) as $rFile) {
                                    if (filemtime($rTmpPath . $rFile) < $rStartTime - 1) {
                                        unlink($rTmpPath . $rFile);
                                    }
                                }
                            }
                        }
                        echo 'Cache updated!' . "\n";
                        file_put_contents(CACHE_TMP_PATH . 'cache_complete', time());
                        $db->query('UPDATE `settings` SET `last_cache` = ?, `last_cache_taken` = ?;', time(), time() - $rStartTime);
                        break;
                }
            } else {
                echo 'Cache is disabled.' . "\n";
                echo 'Generating group permissions...' . "\n";
                $this->generateGroups();
                echo 'Generating lines per ip...' . "\n";
                $this->generateLinesPerIP();
                echo 'Detecting theft of VOD...' . "\n";
                $this->generateTheftDetection();
                echo 'Clearing old data...' . "\n";
                foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rTmpPath) {
                    foreach (scandir($rTmpPath) as $rFile) {
                        unlink($rTmpPath . $rFile);
                    }
                }
                file_put_contents(CACHE_TMP_PATH . 'cache_complete', time());
                exit();
            }
        } else {
            echo 'XC_VM not running...' . "\n";
            exit();
        }
    }

    private function generateLines($rStart = null, $rCount = null, $cacheLockMechanism = []): void {
        global $db;
        if (is_null($rCount)) {
            $rCount = count($cacheLockMechanism);
        }
        if ($rCount > 0) {
            if (!is_null($rStart)) {
                $rEnd = $rStart + $rCount - 1;
                if ($this->rSplit >= ($rEnd - $rStart + 1)) {
                    $rSteps = [$rStart];
                }
            } else {
                $rSteps = [null];
            }
            $rExists = [];
            foreach ($rSteps as $rStep) {
                if (!is_null($rStep)) {
                    if ($rStart + $rCount < $rStep + $this->rSplit) {
                        $rMax = ($rStart + $rCount) - $rStep;
                    } else {
                        $rMax = $this->rSplit;
                    }
                    $db->query('SELECT `id`, `username`, `password`, `exp_date`, `created_at`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `is_trial`, `is_restreamer`, `is_stalker`, `is_mag`, `is_e2`, `is_isplock`, `allowed_ips`, `allowed_ua`, `pair_id`, `force_server_id`, `isp_desc`, `forced_country`, `bypass_ua`, `last_expiration_video`, `access_token`, `mag_devices`.`token` AS `mag_token`, `admin_notes`, `reseller_notes` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LIMIT ' . $rStep . ', ' . $rMax . ';');
                } else {
                    $db->query('SELECT `id`, `username`, `password`, `exp_date`, `created_at`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `is_trial`, `is_restreamer`, `is_stalker`, `is_mag`, `is_e2`, `is_isplock`, `allowed_ips`, `allowed_ua`, `pair_id`, `force_server_id`, `isp_desc`, `forced_country`, `bypass_ua`, `last_expiration_video`, `access_token`, `mag_devices`.`token` AS `mag_token`, `admin_notes`, `reseller_notes` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `id` IN (' . implode(',', $cacheLockMechanism) . ');');
                }
                if ($db->result) {
                    if ($db->result->rowCount() > 0) {
                        foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rUserInfo) {
                            $rExists[] = $rUserInfo['id'];
                            file_put_contents(LINES_TMP_PATH . 'line_i_' . $rUserInfo['id'], igbinary_serialize($rUserInfo));
                            $rKey = (SettingsManager::getAll()['case_sensitive_line'] ? $rUserInfo['username'] . '_' . $rUserInfo['password'] : strtolower($rUserInfo['username'] . '_' . $rUserInfo['password']));
                            file_put_contents(LINES_TMP_PATH . 'line_c_' . $rKey, $rUserInfo['id']);
                            if (!empty($rUserInfo['access_token'])) {
                                file_put_contents(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token'], $rUserInfo['id']);
                            }
                        }
                    }
                    $db->result = null;
                }
            }
            if (count($cacheLockMechanism) > 0) {
                foreach ($cacheLockMechanism as $rForceID) {
                    if (!in_array($rForceID, $rExists) && file_exists(LINES_TMP_PATH . 'line_i_' . $rForceID)) {
                        unlink(LINES_TMP_PATH . 'line_i_' . $rForceID);
                    }
                }
            }
        }
    }

    private function generateStreams($rStart = null, $rCount = null, $cacheLockMechanism = []): void {
        global $db;
        if (is_null($rCount)) {
            $rCount = count($cacheLockMechanism);
        }
        if ($rCount > 0) {
            $rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
            if (!is_null($rStart)) {
                $rEnd = $rStart + $rCount - 1;
                if ($this->rSplit >= ($rEnd - $rStart + 1)) {
                    $rSteps = [$rStart];
                }
            } else {
                $rSteps = [null];
            }
            $rExists = [];
            foreach ($rSteps as $rStep) {
                if (!is_null($rStep)) {
                    if ($rStart + $rCount < $rStep + $this->rSplit) {
                        $rMax = ($rStart + $rCount) - $rStep;
                    } else {
                        $rMax = $this->rSplit;
                    }
                    $db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t1.direct_proxy,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key,t1.tmdb_id,t1.adaptive_link FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type LIMIT ' . $rStep . ', ' . $rMax . ';');
                } else {
                    $db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t1.direct_proxy,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key,t1.tmdb_id,t1.adaptive_link FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', $cacheLockMechanism) . ');');
                }
                if ($db->result) {
                    if ($db->result->rowCount() > 0) {
                        $rRows = $db->result->fetchAll(PDO::FETCH_ASSOC);
                        $rStreamMap = $rStreamIDs = [];
                        foreach ($rRows as $rRow) {
                            $rStreamIDs[] = $rRow['id'];
                        }
                        if (count($rStreamIDs) > 0) {
                            $db->query('SELECT `stream_id`, `server_id`, `pid`, `to_analyze`, `stream_status`, `monitor_pid`, `on_demand`, `delay_available_at`, `bitrate`, `parent_id`, `on_demand`, `stream_info`, `video_codec`, `audio_codec`, `resolution`, `compatible` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ')');
                            if ($db->result) {
                                if ($db->result->rowCount() > 0) {
                                    foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                                        $rStreamMap[intval($rRow['stream_id'])][intval($rRow['server_id'])] = $rRow;
                                    }
                                }
                                $db->result = null;
                            }
                        }
                        foreach ($rRows as $rStreamInfo) {
                            $rExists[] = $rStreamInfo['id'];
                            if (!$rStreamInfo['direct_source']) {
                                unset($rStreamInfo['stream_source']);
                            }
                            $rOutput = ['info' => $rStreamInfo, 'bouquets' => ($rBouquetMap[intval($rStreamInfo['id'])] ?? []), 'servers' => ($rStreamMap[intval($rStreamInfo['id'])] ?? [])];
                            file_put_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamInfo['id'], igbinary_serialize($rOutput));
                        }
                        unset($rRows, $rStreamMap, $rStreamIDs);
                    }
                    $db->result = null;
                }
            }
            if (count($cacheLockMechanism) > 0) {
                foreach ($cacheLockMechanism as $rForceID) {
                    if (!in_array($rForceID, $rExists) && file_exists(STREAMS_TMP_PATH . 'stream_' . $rForceID)) {
                        unlink(STREAMS_TMP_PATH . 'stream_' . $rForceID);
                    }
                }
            }
        }
    }

    private function generateSeries($rStart, $rCount): void {
        global $db;
        $rSeriesMap = [];
        $rSeriesEpisodes = [];
        if ($rCount > 0) {
            if (is_null($rStart)) {
                $rSteps = [null];
            } else {
                $rEnd = $rStart + $rCount - 1;
                $rangeLength = $rEnd - $rStart + 1;
                if ($this->rSplit >= $rangeLength) {
                    $rSteps = [$rStart];
                } else {
                    $rSteps = range($rStart, $rEnd, $this->rSplit);
                }
            }
            foreach ($rSteps as $rStep) {
                if ($rStart + $rCount < $rStep + $this->rSplit) {
                    $rMax = ($rStart + $rCount) - $rStep;
                } else {
                    $rMax = $this->rSplit;
                }
                $db->query('SELECT `stream_id`, `series_id`, `season_num`, `episode_num` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5) ORDER BY `series_id` ASC, `season_num` ASC, `episode_num` ASC LIMIT ' . $rStep . ', ' . $rMax . ';');
                foreach ($db->get_rows() as $rRow) {
                    if ($rRow['stream_id'] && $rRow['series_id']) {
                        $rSeriesMap[intval($rRow['stream_id'])] = intval($rRow['series_id']);
                        if (!isset($rSeriesEpisodes[$rRow['series_id']])) {
                            $rSeriesEpisodes[$rRow['series_id']] = [];
                        }
                        $rSeriesEpisodes[$rRow['series_id']][$rRow['season_num']][] = ['episode_num' => $rRow['episode_num'], 'stream_id' => $rRow['stream_id']];
                    }
                }
            }
        }
        file_put_contents(SERIES_TMP_PATH . 'series_episodes_' . $rStart, igbinary_serialize($rSeriesEpisodes));
        file_put_contents(SERIES_TMP_PATH . 'series_map_' . $rStart, igbinary_serialize($rSeriesMap));
        unset($rSeriesMap);
    }

    private function generateGroups(): void {
        global $db;
        $db->query('SELECT `group_id` FROM `users_groups`;');
        foreach ($db->get_rows() as $rGroup) {
            $rBouquets = $rReturn = [];
            $db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rGroup['group_id']);
            foreach ($db->get_rows() as $rRow) {
                foreach (json_decode($rRow['bouquets'], true) as $rID) {
                    if (!in_array($rID, $rBouquets)) {
                        $rBouquets[] = $rID;
                    }
                }
                if ($rRow['is_line']) {
                    $rReturn['create_line'] = true;
                }
                if ($rRow['is_mag']) {
                    $rReturn['create_mag'] = true;
                }
                if ($rRow['is_e2']) {
                    $rReturn['create_enigma'] = true;
                }
            }
            if (count($rBouquets) > 0) {
                $db->query('SELECT * FROM `bouquets` WHERE `id` IN (' . implode(',', array_map('intval', $rBouquets)) . ');');
                $rSeriesIDs = [];
                $rStreamIDs = [];
                foreach ($db->get_rows() as $rRow) {
                    if ($rRow['bouquet_channels']) {
                        $rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_channels'], true));
                    }
                    if ($rRow['bouquet_movies']) {
                        $rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_movies'], true));
                    }
                    if ($rRow['bouquet_radios']) {
                        $rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_radios'], true));
                    }
                    foreach (json_decode($rRow['bouquet_series'], true) as $rSeriesID) {
                        $rSeriesIDs[] = $rSeriesID;
                        $db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ?;', $rSeriesID);
                        foreach ($db->get_rows() as $rEpisode) {
                            $rStreamIDs[] = $rEpisode['stream_id'];
                        }
                    }
                }
                $rReturn['stream_ids'] = array_unique($rStreamIDs);
                $rReturn['series_ids'] = array_unique($rSeriesIDs);
                $rCategories = [];
                if (count($rReturn['stream_ids']) > 0) {
                    $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['stream_ids'])) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        if ($rRow['category_id']) {
                            $rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
                        }
                    }
                }
                if (count($rReturn['series_ids']) > 0) {
                    $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['series_ids'])) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        if ($rRow['category_id']) {
                            $rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
                        }
                    }
                }
                $rReturn['category_ids'] = array_unique($rCategories);
            }
            file_put_contents(CACHE_TMP_PATH . 'permissions_' . intval($rGroup['group_id']), igbinary_serialize($rReturn));
        }
    }

    private function generateLinesPerIP(): void {
        global $db;
        $rLinesPerIP = [3600 => [], 86400 => [], 604800 => [], 0 => []];
        foreach (array_keys($rLinesPerIP) as $rTime) {
            if ($rTime > 0) {
                $db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `date_start` >= ? AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;', time() - $rTime);
            } else {
                $db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;');
            }
            foreach ($db->get_rows() as $rRow) {
                $rLinesPerIP[$rTime][] = $rRow;
            }
        }
        file_put_contents(CACHE_TMP_PATH . 'lines_per_ip', igbinary_serialize($rLinesPerIP));
    }

    private function generateTheftDetection(): void {
        global $db;
        $rTheftDetection = [3600 => [], 86400 => [], 604800 => [], 0 => []];
        foreach (array_keys($rTheftDetection) as $rTime) {
            if ($rTime > 0) {
                $db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `date_start` >= ? AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5)) GROUP BY `lines_activity`.`user_id` ORDER BY `vod_count` DESC LIMIT 1000;', time() - $rTime);
            } else {
                $db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5)) GROUP BY `lines_activity`.`user_id` ORDER BY `vod_count` DESC LIMIT 1000;');
            }
            foreach ($db->get_rows() as $rRow) {
                $rTheftDetection[$rTime][] = $rRow;
            }
        }
        file_put_contents(CACHE_TMP_PATH . 'theft_detection', igbinary_serialize($rTheftDetection));
    }

    public function shutdown(): void {
        global $db;
        if (is_object($db)) {
            $db->close_mysql();
        }
    }
}
