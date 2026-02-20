<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        $rPID = getmypid();
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);
        CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
        $rSplit = 10000;
        $rThreadCount = (CoreUtilities::$rSettings['cache_thread_count'] ?: 10);
        $rForce = false;
        $rGroupStart = $rGroupMax = $rType = null;
        if (1 < count($argv)) {
            $rType = $argv[1];
            if ($rType == 'streams_update' || $rType == 'lines_update') {
                $rUpdateIDs = array_map('intval', explode(',', $argv[2]));
            } else {
                if (2 >= count($argv)) {
                } else {
                    $rGroupStart = intval($argv[2]);
                    $rGroupMax = intval($argv[3]);
                }
            }
            if ($rType != 'force') {
            } else {
                echo 'Forcing cache regen...' . "\n";
                CoreUtilities::$rSettings['cache_changes'] = false;
                $rForce = true;
            }
        } else {
            shell_exec("kill -9 \$(ps aux | grep 'cache_engine' | grep -v grep | grep -v " . $rPID . " | awk '{print \$2}')");
        }
        loadCron($rType, $rGroupStart, $rGroupMax);
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
class Thread {
    public $process = null;
    public $pipes = null;
    public $buffer = null;
    public $output = null;
    public $error = null;
    public $timeout = null;
    public $start_time = null;
    public function __construct() {
        $this->process = 0;
        $this->buffer = '';
        $this->pipes = (array) null;
        $this->output = '';
        $this->error = '';
        $this->start_time = time();
        $this->timeout = 0;
    }
    public static function create($command) {
        $t = new Thread();
        $descriptor = array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w'));
        $t->process = proc_open($command, $descriptor, $t->pipes);
        stream_set_blocking($t->pipes[1], 0);
        stream_set_blocking($t->pipes[2], 0);
        return $t;
    }
    public function isActive() {
        $this->buffer .= $this->listen();
        $f = stream_get_meta_data($this->pipes[1]);
        return !$f['eof'];
    }
    public function close() {
        $r = proc_close($this->process);
        $this->process = null;
        return $r;
    }
    public function tell($thought) {
        fwrite($this->pipes[0], $thought);
    }
    public function listen() {
        $buffer = $this->buffer;
        $this->buffer = '';
        while ($r = fgets($this->pipes[1], 1024)) {
            $buffer .= $r;
            $this->output .= $r;
        }
        return $buffer;
    }
    public function getStatus() {
        return proc_get_status($this->process);
    }
    public function isBusy() {
        return 0 < $this->timeout && $this->start_time + $this->timeout < time();
    }
    public function getError() {
        $buffer = '';
        while ($r = fgets($this->pipes[2], 1024)) {
            $buffer .= $r;
        }
        return $buffer;
    }
}
class Multithread {
    public $output = array();
    public $error = array();
    public $thread = null;
    public $commands = array();
    public $hasPool = false;
    public $toExecuted = array();
    public function __construct($commands, $sizePool = 0) {
        $this->hasPool = 0 < $sizePool;
        if (!$this->hasPool) {
        } else {
            $this->toExecuted = array_splice($commands, $sizePool);
        }
        $this->commands = $commands;
        foreach ($this->commands as $key => $command) {
            $this->thread[$key] = Thread::create($command);
        }
    }
    public function run() {
        while (0 < count($this->commands)) {
            foreach ($this->commands as $key => $command) {
                if (!isset($this->thread[$key])) {
                    unset($this->commands[$key]);
                    self::launchNextInQueue();
                    continue;
                }
                if (!isset($this->output[$key])) {
                    $this->output[$key] = '';
                }
                if (!isset($this->error[$key])) {
                    $this->error[$key] = '';
                }
                $this->output[$key] .= @$this->thread[$key]->listen();
                $this->error[$key] .= @$this->thread[$key]->getError();
                if ($this->thread[$key]->isActive()) {
                    $this->output[$key] .= $this->thread[$key]->listen();
                    if (!$this->thread[$key]->isBusy()) {
                    } else {
                        $this->thread[$key]->close();
                        unset($this->commands[$key]);
                        self::launchNextInQueue();
                    }
                } else {
                    $this->thread[$key]->close();
                    unset($this->commands[$key]);
                    self::launchNextInQueue();
                }
            }
        }
        return $this->output;
    }
    public function launchNextInQueue() {
        if (count($this->toExecuted) != 0) {
            reset($this->toExecuted);
            $keyToExecuted = key($this->toExecuted);
            $this->commands[$keyToExecuted] = $this->toExecuted[$keyToExecuted];
            $this->thread[$keyToExecuted] = Thread::create($this->toExecuted[$keyToExecuted]);
            unset($this->toExecuted[$keyToExecuted]);
        } else {
            return true;
        }
    }
}
function getChangedStreams() {
    global $db;
    $rReturn = array('changes' => array(), 'delete' => array());
    $rExisting = array();
    $db->query('SELECT `id`, GREATEST(IFNULL(UNIX_TIMESTAMP(`streams`.`updated`), 0), IFNULL(MAX(UNIX_TIMESTAMP(`streams_servers`.`updated`)), 0)) AS `updated` FROM `streams` LEFT JOIN `streams_servers` ON `streams`.`id` = `streams_servers`.`stream_id` GROUP BY `id`;');
    if (!($db->dbh && $db->result)) {
    } else {
        if (0 >= $db->result->rowCount()) {
        } else {
            foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                if (file_exists(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) && ((filemtime(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) ?: 0)) >= $rRow['updated']) {
                } else {
                    $rReturn['changes'][] = $rRow['id'];
                }
                $rExisting[] = $rRow['id'];
            }
        }
    }
    $rExisting = array_flip($rExisting);
    foreach (glob(STREAMS_TMP_PATH . 'stream_*') as $rFile) {
        $rStreamID = intval(end(explode('_', $rFile)));
        if (isset($rExisting[$rStreamID])) {
        } else {
            $rReturn['delete'][] = $rStreamID;
        }
    }
    return $rReturn;
}
function loadCron($rType, $rGroupStart, $rGroupMax) {
    global $db;
    global $rSplit;
    global $rUpdateIDs;
    global $rThreadCount;
    global $rForce;
    $rStartTime = time();
    if (CoreUtilities::isRunning()) {
        if (CoreUtilities::$rCached || isset($rUpdateIDs)) {
            switch ($rType) {
                case 'lines':
                    generateLines($rGroupStart, $rGroupMax);
                    break;
                case 'lines_update':
                    generateLines(null, null, $rUpdateIDs);
                    break;
                case 'series':
                    generateSeries($rGroupStart, $rGroupMax);
                    break;
                case 'streams':
                    generateStreams($rGroupStart, $rGroupMax);
                    break;
                case 'streams_update':
                    generateStreams(null, null, $rUpdateIDs);
                    break;
                case 'groups':
                    generateGroups();
                    break;
                case 'lines_per_ip':
                    generateLinesPerIP();
                    break;
                case 'theft_detection':
                    generateTheftDetection();
                    break;
                default:
                    $cacheInitTime = $rSeriesCategories = array();
                    $db->query('SELECT `series_id`, MAX(`streams`.`added`) AS `last_modified` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` GROUP BY `series_id`;');
                    foreach ($db->get_rows() as $rRow) {
                        $cacheInitTime[$rRow['series_id']] = $rRow['last_modified'];
                    }
                    $db->query('SELECT * FROM `streams_series`;');
                    if (!$db->result) {
                    } else {
                        if (0 >= $db->result->rowCount()) {
                        } else {
                            foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                                if (!isset($cacheInitTime[$rRow['id']])) {
                                } else {
                                    $rRow['last_modified'] = $cacheInitTime[$rRow['id']];
                                }
                                $rSeriesCategories[$rRow['id']] = json_decode($rRow['category_id'], true);
                                file_put_contents(SERIES_TMP_PATH . 'series_' . $rRow['id'], igbinary_serialize($rRow));
                            }
                        }
                    }
                    file_put_contents(SERIES_TMP_PATH . 'series_categories', igbinary_serialize($rSeriesCategories));
                    $rDelete = array('streams' => array(), 'lines_i' => array(), 'lines_c' => array(), 'lines_t' => array());
                    $cacheDataKey = array();
                    if (CoreUtilities::$rSettings['cache_changes']) {
                        $rChanges = getChangedLines();
                        $rDelete['lines_i'] = $rChanges['delete_i'];
                        $rDelete['lines_c'] = $rChanges['delete_c'];
                        $rDelete['lines_t'] = $rChanges['delete_t'];
                        if (0 >= count($rChanges['changes'])) {
                        } else {
                            foreach (array_chunk($rChanges['changes'], $rSplit) as $rChunk) {
                                $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "lines_update" "' . implode(',', $rChunk) . '"';
                            }
                        }
                    } else {
                        $db->query('SELECT COUNT(*) AS `count` FROM `lines`;');
                        $rLinesCount = $db->get_row()['count'];
                        $cacheValidityCheck = $rSplit > $rLinesCount ? [0, $rLinesCount] : range(0, $rLinesCount, $rSplit);
                        if ($cacheValidityCheck) {
                        } else {
                            $cacheValidityCheck = array(0);
                        }
                        foreach ($cacheValidityCheck as $rStart) {
                            $rMax = $rSplit;
                            if ($rLinesCount >= $rStart + $rMax) {
                            } else {
                                $rMax = $rLinesCount - $rStart;
                            }
                            $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "lines" ' . $rStart . ' ' . $rMax;
                        }
                    }
                    // get the number of episodes in a TV series
                    $db->query('SELECT COUNT(*) AS `count` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5);');

                    $cacheRetrieveMethod = (int) $db->get_row()['count'];
                    $cacheStoreMethod = [];

                    if ($cacheRetrieveMethod > 0) {
                        // step: 0, 10000, 20000 ...
                        for ($rStart = 0; $rStart < $cacheRetrieveMethod; $rStart += $rSplit) {
                            // restriction for the current chunk
                            $rMax = min($rSplit, $cacheRetrieveMethod - $rStart);
                            // save the start of the current chunk for subsequent file processing
                            $cacheStoreMethod[] = $rStart;

                            $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "series" ' . $rStart . ' ' . $rMax;
                        }
                    } else {
                        // if there are no records — at least one call
                        $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "series" 0 0';
                    }
                    if (CoreUtilities::$rSettings['cache_changes']) {
                        $rChanges = getchangedstreams();
                        $rDelete['streams'] = $rChanges['delete'];
                        if (0 >= count($rChanges['changes'])) {
                        } else {
                            foreach (array_chunk($rChanges['changes'], $rSplit) as $rChunk) {
                                $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "streams_update" "' . implode(',', $rChunk) . '"';
                            }
                        }
                    } else {
                        $db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
                        $cacheDeleteMethod = (int)$db->get_row()['count'];
                        $cacheCleanupTrigger = range(0, $cacheDeleteMethod, $rSplit);
                        if (!$cacheCleanupTrigger) {
                            $cacheCleanupTrigger = array(0);
                        }
                        foreach ($cacheCleanupTrigger as $rStart) {
                            $rMax = $rSplit;
                            if ($cacheDeleteMethod >= $rStart + $rMax) {
                            } else {
                                $rMax = $cacheDeleteMethod - $rStart;
                            }
                            $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "streams" ' . $rStart . ' ' . $rMax;
                        }
                    }
                    $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "groups"';
                    $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "lines_per_ip"';
                    $cacheDataKey[] = PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "theft_detection"';
                    $cacheMetadataKey = new Multithread($cacheDataKey, $rThreadCount);
                    $cacheMetadataKey->run();
                    unset($cacheDataKey);
                    $rSeriesEpisodes = $rSeriesMap = array();
                    foreach ($cacheStoreMethod as $rStart) {
                        if (!file_exists(SERIES_TMP_PATH . 'series_map_' . $rStart)) {
                        } else {
                            foreach (igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_map_' . $rStart)) as $rStreamID => $rSeriesID) {
                                $rSeriesMap[$rStreamID] = $rSeriesID;
                            }
                            unlink(SERIES_TMP_PATH . 'series_map_' . $rStart);
                        }
                        if (!file_exists(SERIES_TMP_PATH . 'series_episodes_' . $rStart)) {
                        } else {
                            $rSeasonData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_episodes_' . $rStart));
                            foreach (array_keys($rSeasonData) as $rSeriesID) {
                                if (isset($rSeriesEpisodes[$rSeriesID])) {
                                } else {
                                    $rSeriesEpisodes[$rSeriesID] = array();
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
                    if (CoreUtilities::$rSettings['cache_changes']) {
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
                        foreach (array(STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH) as $rTmpPath) {
                            foreach (scandir($rTmpPath) as $rFile) {
                                if (filemtime($rTmpPath . $rFile) >= $rStartTime - 1) {
                                } else {
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
            generateGroups();
            echo 'Generating lines per ip...' . "\n";
            generateLinesPerIP();
            echo 'Detecting theft of VOD...' . "\n";
            generateTheftDetection();
            echo 'Clearing old data...' . "\n";
            foreach (array(STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH) as $rTmpPath) {
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
function generateLines($rStart = null, $rCount = null, $cacheLockMechanism = array()) {
    global $db;
    global $rSplit;
    global $rForce;
    if (!is_null($rCount)) {
    } else {
        $rCount = count($cacheLockMechanism);
    }
    if ($rCount > 0) {
        if (!is_null($rStart)) {
            $rEnd = $rStart + $rCount - 1;
            // If the step is greater than or equal to the range size, return only the initial value
            if ($rSplit >= ($rEnd - $rStart + 1)) {
                $rSteps = [$rStart];
            }
        } else {
            $rSteps = [null];
        }
        $rExists = [];
        foreach ($rSteps as $rStep) {
            if (!is_null($rStep)) {
                if ($rStart + $rCount < $rStep + $rSplit) {
                    $rMax = ($rStart + $rCount) - $rStep;
                } else {
                    $rMax = $rSplit;
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
                        $rKey = (CoreUtilities::$rSettings['case_sensitive_line'] ? $rUserInfo['username'] . '_' . $rUserInfo['password'] : strtolower($rUserInfo['username'] . '_' . $rUserInfo['password']));
                        file_put_contents(LINES_TMP_PATH . 'line_c_' . $rKey, $rUserInfo['id']);
                        if (!empty($rUserInfo['access_token'])) {
                            file_put_contents(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token'], $rUserInfo['id']);
                        }
                    }
                }
                $db->result = null;
            }
        }
        if (0 >= count($cacheLockMechanism)) {
        } else {
            foreach ($cacheLockMechanism as $rForceID) {
                if (in_array($rForceID, $rExists) || !file_exists(LINES_TMP_PATH . 'line_i_' . $rForceID)) {
                } else {
                    unlink(LINES_TMP_PATH . 'line_i_' . $rForceID);
                }
            }
        }
    }
}
function generateStreams($rStart = null, $rCount = null, $cacheLockMechanism = array()) {
    global $db;
    global $rSplit;
    global $rForce;
    if (!is_null($rCount)) {
    } else {
        $rCount = count($cacheLockMechanism);
    }
    if (0 >= $rCount) {
    } else {
        $rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
        if (!is_null($rStart)) {
            $rEnd = $rStart + $rCount - 1;
            // If the step is greater than or equal to the range size, return only the initial value
            if ($rSplit >= ($rEnd - $rStart + 1)) {
                $rSteps = [$rStart];
            }
        } else {
            $rSteps = [null];
        }
        $rExists = [];
        foreach ($rSteps as $rStep) {
            if (!is_null($rStep)) {
                if ($rStart + $rCount < $rStep + $rSplit) {
                    $rMax = ($rStart + $rCount) - $rStep;
                } else {
                    $rMax = $rSplit;
                }
                $db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t1.direct_proxy,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key,t1.tmdb_id,t1.adaptive_link FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type LIMIT ' . $rStep . ', ' . $rMax . ';');
            } else {
                $db->query('SELECT t1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t1.direct_proxy,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key,t1.tmdb_id,t1.adaptive_link FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type WHERE `t1`.`id` IN (' . implode(',', $cacheLockMechanism) . ');');
            }
            if (!$db->result) {
            } else {
                if (0 >= $db->result->rowCount()) {
                } else {
                    $rRows = $db->result->fetchAll(PDO::FETCH_ASSOC);
                    $rStreamMap = $rStreamIDs = array();
                    foreach ($rRows as $rRow) {
                        $rStreamIDs[] = $rRow['id'];
                    }
                    if (0 >= count($rStreamIDs)) {
                    } else {
                        $db->query('SELECT `stream_id`, `server_id`, `pid`, `to_analyze`, `stream_status`, `monitor_pid`, `on_demand`, `delay_available_at`, `bitrate`, `parent_id`, `on_demand`, `stream_info`, `video_codec`, `audio_codec`, `resolution`, `compatible` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ')');
                        if (!$db->result) {
                        } else {
                            if (0 >= $db->result->rowCount()) {
                            } else {
                                foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                                    $rStreamMap[intval($rRow['stream_id'])][intval($rRow['server_id'])] = $rRow;
                                }
                            }
                            $db->result = null;
                        }
                    }
                    foreach ($rRows as $rStreamInfo) {
                        $rExists[] = $rStreamInfo['id'];
                        if ($rStreamInfo['direct_source']) {
                        } else {
                            unset($rStreamInfo['stream_source']);
                        }
                        $rOutput = array('info' => $rStreamInfo, 'bouquets' => ($rBouquetMap[intval($rStreamInfo['id'])] ?: array()), 'servers' => (isset($rStreamMap[intval($rStreamInfo['id'])]) ? $rStreamMap[intval($rStreamInfo['id'])] : array()));
                        file_put_contents(STREAMS_TMP_PATH . 'stream_' . $rStreamInfo['id'], igbinary_serialize($rOutput));
                    }
                    unset($rRows, $rStreamMap, $rStreamIDs);
                }
                $db->result = null;
            }
        }
        if (0 >= count($cacheLockMechanism)) {
        } else {
            foreach ($cacheLockMechanism as $rForceID) {
                if (in_array($rForceID, $rExists) || !file_exists(STREAMS_TMP_PATH . 'stream_' . $rForceID)) {
                } else {
                    unlink(STREAMS_TMP_PATH . 'stream_' . $rForceID);
                }
            }
        }
    }
}
function generateSeries($rStart, $rCount) {
    global $db;
    global $rSplit;
    $rSeriesMap = array();
    $rSeriesEpisodes = array();
    if ($rCount > 0) {
        if (is_null($rStart)) {
            $rSteps = [null];
        } else {
            $rEnd = $rStart + $rCount - 1;
            $rangeLength = $rEnd - $rStart + 1;

            // If the step is >= the range length, we return only the initial value.
            if ($rSplit >= $rangeLength) {
                $rSteps = [$rStart];
            } else {
                $rSteps = range($rStart, $rEnd, $rSplit);
            }
        }
        foreach ($rSteps as $rStep) {
            if ($rStart + $rCount < $rStep + $rSplit) {
                $rMax = ($rStart + $rCount) - $rStep;
            } else {
                $rMax = $rSplit;
            }
            $db->query('SELECT `stream_id`, `series_id`, `season_num`, `episode_num` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5) ORDER BY `series_id` ASC, `season_num` ASC, `episode_num` ASC LIMIT ' . $rStep . ', ' . $rMax . ';');
            foreach ($db->get_rows() as $rRow) {
                if (!($rRow['stream_id'] && $rRow['series_id'])) {
                } else {
                    $rSeriesMap[intval($rRow['stream_id'])] = intval($rRow['series_id']);
                    if (isset($rSeriesEpisodes[$rRow['series_id']])) {
                    } else {
                        $rSeriesEpisodes[$rRow['series_id']] = array();
                    }
                    $rSeriesEpisodes[$rRow['series_id']][$rRow['season_num']][] = array('episode_num' => $rRow['episode_num'], 'stream_id' => $rRow['stream_id']);
                }
            }
        }
    }
    file_put_contents(SERIES_TMP_PATH . 'series_episodes_' . $rStart, igbinary_serialize($rSeriesEpisodes));
    file_put_contents(SERIES_TMP_PATH . 'series_map_' . $rStart, igbinary_serialize($rSeriesMap));
    unset($rSeriesMap);
}
function generateGroups() {
    global $db;
    $db->query('SELECT `group_id` FROM `users_groups`;');
    foreach ($db->get_rows() as $rGroup) {
        $rBouquets = $rReturn = array();
        $db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rGroup['group_id']);
        foreach ($db->get_rows() as $rRow) {
            foreach (json_decode($rRow['bouquets'], true) as $rID) {
                if (in_array($rID, $rBouquets)) {
                } else {
                    $rBouquets[] = $rID;
                }
            }
            if (!$rRow['is_line']) {
            } else {
                $rReturn['create_line'] = true;
            }
            if (!$rRow['is_mag']) {
            } else {
                $rReturn['create_mag'] = true;
            }
            if (!$rRow['is_e2']) {
            } else {
                $rReturn['create_enigma'] = true;
            }
        }
        if (0 >= count($rBouquets)) {
        } else {
            $db->query('SELECT * FROM `bouquets` WHERE `id` IN (' . implode(',', array_map('intval', $rBouquets)) . ');');
            $rSeriesIDs = array();
            $rStreamIDs = array();
            foreach ($db->get_rows() as $rRow) {
                if (!$rRow['bouquet_channels']) {
                } else {
                    $rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_channels'], true));
                }
                if (!$rRow['bouquet_movies']) {
                } else {
                    $rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_movies'], true));
                }
                if (!$rRow['bouquet_radios']) {
                } else {
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
            $rCategories = array();
            if (0 >= count($rReturn['stream_ids'])) {
            } else {
                $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['stream_ids'])) . ');');
                foreach ($db->get_rows() as $rRow) {
                    if (!$rRow['category_id']) {
                    } else {
                        $rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
                    }
                }
            }
            if (0 >= count($rReturn['series_ids'])) {
            } else {
                $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['series_ids'])) . ');');
                foreach ($db->get_rows() as $rRow) {
                    if (!$rRow['category_id']) {
                    } else {
                        $rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
                    }
                }
            }
            $rReturn['category_ids'] = array_unique($rCategories);
        }
        file_put_contents(CACHE_TMP_PATH . 'permissions_' . intval($rGroup['group_id']), igbinary_serialize($rReturn));
    }
}
function generateLinesPerIP() {
    global $db;
    $rLinesPerIP = array(3600 => array(), 86400 => array(), 604800 => array(), 0 => array());
    foreach (array_keys($rLinesPerIP) as $rTime) {
        if (0 < $rTime) {
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
function generateTheftDetection() {
    global $db;
    $rTheftDetection = array(3600 => array(), 86400 => array(), 604800 => array(), 0 => array());
    foreach (array_keys($rTheftDetection) as $rTime) {
        if (0 < $rTime) {
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
function getChangedLines() {
    global $db;
    $rReturn = array('changes' => array(), 'delete_i' => array(), 'delete_c' => array(), 'delete_t' => array());
    $cacheMemoryAllocation = glob(LINES_TMP_PATH . 'line_i_*');
    $cacheFailureHandler = glob(LINES_TMP_PATH . 'line_c_*');
    $cacheSuccessIndicator = glob(LINES_TMP_PATH . 'line_t_*');
    $cacheRevalidationCheck = $cacheDataCompression = $cacheDataDecompression = array();
    $db->query('SELECT `id`, `username`, `password`, `access_token`, UNIX_TIMESTAMP(`updated`) AS `updated` FROM `lines`;');
    if (!($db->dbh && $db->result)) {
    } else {
        if (0 >= $db->result->rowCount()) {
        } else {
            foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
                if (file_exists(LINES_TMP_PATH . 'line_i_' . $rRow['id']) && ((filemtime(LINES_TMP_PATH . 'line_i_' . $rRow['id']) ?: 0)) >= $rRow['updated']) {
                } else {
                    $rReturn['changes'][] = $rRow['id'];
                }
                $cacheRevalidationCheck[] = $rRow['id'];
                $cacheDataCompression[] = (CoreUtilities::$rSettings['case_sensitive_line'] ? $rRow['username'] . '_' . $rRow['password'] : strtolower($rRow['username'] . '_' . $rRow['password']));
                if (!$rRow['access_token']) {
                } else {
                    $cacheDataDecompression[] = $rRow['access_token'];
                }
            }
        }
    }
    $cacheRevalidationCheck = array_flip($cacheRevalidationCheck);
    foreach ($cacheMemoryAllocation as $rFile) {
        $rUserID = (intval(explode('line_i_', $rFile, 2)[1]) ?: null);
        if (!$rUserID || isset($cacheRevalidationCheck[$rUserID])) {
        } else {
            $rReturn['delete_i'][] = $rUserID;
        }
    }
    $cacheDataCompression = array_flip($cacheDataCompression);
    foreach ($cacheFailureHandler as $rFile) {
        $cacheExpirationTime = (explode('line_c_', $rFile, 2)[1] ?: null);
        if (!$cacheExpirationTime || isset($cacheDataCompression[$cacheExpirationTime])) {
        } else {
            $rReturn['delete_c'][] = $cacheExpirationTime;
        }
    }
    $cacheDataDecompression = array_flip($cacheDataDecompression);
    foreach ($cacheSuccessIndicator as $rFile) {
        $rToken = (explode('line_t_', $rFile, 2)[1] ?: null);
        if (!$rToken || isset($cacheDataDecompression[$rToken])) {
        } else {
            $rReturn['delete_t'][] = $rToken;
        }
    }
    return $rReturn;
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
