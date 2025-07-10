<?php
ini_set('memory_limit', -1);
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        $rForce = null;
        if (count($argv) != 2) {
        } else {
            $rForce = intval($argv[1]);
        }
        if ($rForce) {
        } else {
            if (file_exists(CACHE_TMP_PATH . 'watch_pid')) {
                $rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'watch_pid'));
            } else {
                $rPrevPID = null;
            }
            if (!($rPrevPID && CoreUtilities::isProcessRunning($rPrevPID, 'php'))) {
            } else {
                echo 'Watch folder is already running. Please wait until it finishes.' . "\n";
                exit();
            }
        }
        file_put_contents(CACHE_TMP_PATH . 'watch_pid', getmypid());
        cli_set_process_title('XC_VM[Watch Folder]');
        $rScanOffset = (intval(CoreUtilities::$rSettings['scan_seconds']) ?: 3600);
        $rThreadCount = (intval(CoreUtilities::$rSettings['thread_count']) ?: 50);
        $F7fa29461a8a5ee2 = (intval(CoreUtilities::$rSettings['max_items']) ?: 0);
        set_time_limit(0);
        if (strlen(CoreUtilities::$rSettings['tmdb_api_key']) != 0) {
            loadCron();
        } else {
            exit('No TMDb API key.');
        }
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
function getWatchCategories($rType = null) {
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
function loadCron() {
    global $db;
    global $rThreadCount;
    global $rScanOffset;
    global $F7fa29461a8a5ee2;
    global $rForce;
    $rWatchCategories = array(1 => getWatchCategories(1), 2 => getWatchCategories(2));
    if (0 >= count(glob(WATCH_TMP_PATH . '*.bouquet'))) {
    } else {
        checkBouquets();
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
        if (count($rExtensions) != 0) {
        } else {
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
        if (0 >= count($rThreadData)) {
        } else {
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
        checkBouquets();
    }
}
function getBouquet($rID) {
    global $db;
    $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
    if ($db->num_rows() != 1) {
    } else {
        return $db->get_row();
    }
}
function checkBouquets() {
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
        $rBouquet = getBouquet($rBouquetID);
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
function shutdown() {
    global $db;
    global $rIdentifier;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
