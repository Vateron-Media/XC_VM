<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Live Checker]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadCron() {
    global $db;
    if (!CoreUtilities::isRunning()) {
        echo 'XC_VM not running...' . "\n";
    }
    if (CoreUtilities::$rSettings['redis_handler']) {
        CoreUtilities::connectRedis();
    }
    $rActivePIDs = array();
    $rStreamIDs = array();
    if (CoreUtilities::$rSettings['redis_handler']) {
        $db->query('SELECT t2.stream_display_name, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1', SERVER_ID, SERVER_ID);
    } else {
        $db->query("SELECT t2.stream_display_name, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, clients.online_clients, clients_hls.online_clients_hls, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY stream_id) AS clients ON clients.stream_id = t1.stream_id LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients_hls FROM `lines_live` WHERE `server_id` = ? AND `container` = 'hls' AND `hls_end` = 0 GROUP BY stream_id) AS clients_hls ON clients_hls.stream_id = t1.stream_id WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1", SERVER_ID, SERVER_ID, SERVER_ID, SERVER_ID);
    }
    if (0 >= $db->num_rows()) {
    } else {
        foreach ($db->get_rows() as $rStream) {
            echo 'Stream ID: ' . $rStream['stream_id'] . "\n";
            $rStreamIDs[] = $rStream['stream_id'];
            if (CoreUtilities::isMonitorRunning($rStream['monitor_pid'], $rStream['stream_id']) || $rStream['on_demand']) {
                if (!($rStream['on_demand'] == 1 && $rStream['attached'] == 0)) {
                } else {
                    if (!CoreUtilities::$rSettings['redis_handler']) {
                    } else {
                        $rCount = 0;
                        $rKeys = CoreUtilities::$redis->zRangeByScore('STREAM#' . $rStream['stream_id'], '-inf', '+inf');
                        if (0 >= count($rKeys)) {
                        } else {
                            $rConnections = array_map('igbinary_unserialize', CoreUtilities::$redis->mGet($rKeys));
                            foreach ($rConnections as $rConnection) {
                                if (!($rConnection && $rConnection['server_id'] == SERVER_ID)) {
                                } else {
                                    $rCount++;
                                }
                            }
                        }
                        $rStream['online_clients'] = $rCount;
                    }
                    $rAdminQueue = $rQueue = 0;
                    if (!(CoreUtilities::$rSettings['on_demand_instant_off'] && file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id'])))) {
                    } else {
                        foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id']))) ?: array()) as $rPID) {
                            if (!CoreUtilities::isProcessRunning($rPID, 'php-fpm')) {
                            } else {
                                $rQueue++;
                            }
                        }
                    }
                    if (!file_exists(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']))) {
                    } else {
                        if (time() - filemtime(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id'])) <= 30) {
                            $rAdminQueue = 1;
                        } else {
                            unlink(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']));
                        }
                    }
                    if (!($rQueue == 0 && $rAdminQueue == 0 && $rStream['online_clients'] == 0 && (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.m3u8') || intval(CoreUtilities::$rSettings['on_demand_wait_time']) < time() - intval($rStream['stream_started']) || $rStream['stream_status'] == 1))) {
                    } else {
                        echo 'Stop on-demand stream...' . "\n\n";
                        CoreUtilities::stopStream($rStream['stream_id'], true);
                    }
                }
                if ($rStream['vframes_server_id'] != SERVER_ID || CoreUtilities::isThumbnailRunning($rStream['vframes_pid'], $rStream['stream_id'])) {
                } else {
                    echo 'Start Thumbnail...' . "\n";
                    CoreUtilities::startThumbnail($rStream['stream_id']);
                }
                if ($rStream['tv_archive_server_id'] != SERVER_ID || CoreUtilities::isArchiveRunning($rStream['tv_archive_pid'], $rStream['stream_id'])) {
                } else {
                    echo 'Start TV Archive...' . "\n";
                    shell_exec(PHP_BIN . ' ' . CLI_PATH . 'archive.php ' . intval($rStream['stream_id']) . ' >/dev/null 2>/dev/null & echo $!');
                }
                foreach (glob(STREAMS_PATH . $rStream['stream_id'] . '_*.ts.enc') as $rFile) {
                    if (file_exists(rtrim($rFile, '.enc'))) {
                    } else {
                        unlink($rFile);
                    }
                }
                if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.pid')) {
                    $rPID = intval(file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.pid'));
                } else {
                    $rPID = intval(shell_exec("ps aux | grep -v grep | grep '/" . intval($rStream['stream_id']) . "_.m3u8' | awk '{print \$2}'"));
                }
                $rActivePIDs[] = intval($rPID);
                $rPlaylist = STREAMS_PATH . $rStream['stream_id'] . '_.m3u8';
                if (!(CoreUtilities::isStreamRunning($rPID, $rStream['stream_id']) && file_exists($rPlaylist))) {
                } else {
                    echo 'Update Stream Information...' . "\n";
                    $rBitrate = CoreUtilities::getStreamBitrate('live', STREAMS_PATH . $rStream['stream_id'] . '_.m3u8');
                    if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.progress')) {
                        $rProgress = file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.progress');
                        unlink(STREAMS_PATH . $rStream['stream_id'] . '_.progress');
                        if (!$rStream['fps_restart']) {
                        } else {
                            file_put_contents(STREAMS_PATH . $rStream['stream_id'] . '_.progress_check', $rProgress);
                        }
                    } else {
                        $rProgress = $rStream['progress_info'];
                    }
                    if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info')) {
                        $rStreamInfo = file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info');
                        unlink(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info');
                    } else {
                        $rStreamInfo = $rStream['stream_info'];
                    }
                    $rCompatible = 0;
                    $rAudioCodec = $rVideoCodec = $rResolution = null;
                    if (!$rStreamInfo) {
                    } else {
                        $rStreamJSON = json_decode($rStreamInfo, true);
                        $rCompatible = intval(CoreUtilities::checkCompatibility($rStreamJSON));
                        $rAudioCodec = ($rStreamJSON['codecs']['audio']['codec_name'] ?: null);
                        $rVideoCodec = ($rStreamJSON['codecs']['video']['codec_name'] ?: null);
                        $rResolution = ($rStreamJSON['codecs']['video']['height'] ?: null);
                        if (!$rResolution) {
                        } else {
                            $rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
                        }
                    }
                    if ($rStream['pid'] != $rPID) {
                        $db->query('UPDATE `streams_servers` SET `pid` = ?, `progress_info` = ?, `stream_info` = ?, `compatible` = ?, `bitrate` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ? WHERE `server_stream_id` = ?', $rPID, $rProgress, $rStreamInfo, $rCompatible, $rBitrate, $rAudioCodec, $rVideoCodec, $rResolution, $rStream['server_stream_id']);
                    } else {
                        $db->query('UPDATE `streams_servers` SET `progress_info` = ?, `stream_info` = ?, `compatible` = ?, `bitrate` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ? WHERE `server_stream_id` = ?', $rProgress, $rStreamInfo, $rCompatible, $rBitrate, $rAudioCodec, $rVideoCodec, $rResolution, $rStream['server_stream_id']);
                    }
                }
                echo "\n";
            } else {
                echo 'Start monitor...' . "\n\n";
                CoreUtilities::startMonitor($rStream['stream_id']);
                usleep(50000);
            }
        }
    }
    $db->query('SELECT `streams`.`id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`direct_source` = 1 AND `streams`.`direct_proxy` = 1 AND `streams_servers`.`server_id` = ? AND `streams_servers`.`pid` > 0;', SERVER_ID);
    if (0 >= $db->num_rows()) {
    } else {
        foreach ($db->get_rows() as $rStream) {
            if (!file_exists(STREAMS_PATH . $rStream['id'] . '.analyse')) {
            } else {
                $rFFProbeOutput = CoreUtilities::probeStream(STREAMS_PATH . $rStream['id'] . '.analyse');
                if (!$rFFProbeOutput) {
                } else {
                    $rBitrate = $rFFProbeOutput['bitrate'] / 1024;
                    $rCompatible = intval(CoreUtilities::checkCompatibility($rFFProbeOutput));
                    $rAudioCodec = ($rFFProbeOutput['codecs']['audio']['codec_name'] ?: null);
                    $rVideoCodec = ($rFFProbeOutput['codecs']['video']['codec_name'] ?: null);
                    $rResolution = ($rFFProbeOutput['codecs']['video']['height'] ?: null);
                    if (!$rResolution) {
                    } else {
                        $rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
                    }
                }
                echo 'Stream ID: ' . $rStream['id'] . "\n";
                echo 'Update Stream Information...' . "\n";
                $db->query('UPDATE `streams_servers` SET `bitrate` = ?, `stream_info` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `compatible` = ? WHERE `stream_id` = ? AND `server_id` = ?', $rBitrate, json_encode($rFFProbeOutput), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rStream['id'], SERVER_ID);
            }
            $rUUIDs = array();
            $rConnections = CoreUtilities::getConnections(SERVER_ID, null, $rStream['id']);
            foreach ($rConnections as $rUserID => $rItems) {
                foreach ($rItems as $rItem) {
                    $rUUIDs[] = $rItem['uuid'];
                }
            }
            if (!($rHandle = opendir(CONS_TMP_PATH . $rStream['id'] . '/'))) {
            } else {
                while (false !== ($rFilename = readdir($rHandle))) {
                    if (!($rFilename != '.' && $rFilename != '..')) {
                    } else {
                        if (in_array($rFilename, $rUUIDs)) {
                        } else {
                            unlink(CONS_TMP_PATH . $rStream['id'] . '/' . $rFilename);
                        }
                    }
                }
                closedir($rHandle);
            }
        }
    }
    $db->query('SELECT `stream_id` FROM `streams_servers` WHERE `on_demand` = 1 AND `server_id` = ?;', SERVER_ID);
    $rOnDemandIDs = array_keys($db->get_rows(true, 'stream_id'));
    $rProcesses = shell_exec('ps aux | grep XC_VM');
    if (!preg_match_all('/XC_VM\\[(.*)\\]/', $rProcesses, $rMatches)) {
    } else {
        $rRemove = array_diff($rMatches[1], $rStreamIDs);
        $rRemove = array_diff($rRemove, $rOnDemandIDs);
        foreach ($rRemove as $rStreamID) {
            if (is_numeric($rStreamID)) {
                echo 'Kill Stream ID: ' . $rStreamID . "\n";
                shell_exec("kill -9 `ps -ef | grep '/" . intval($rStreamID) . '_.m3u8\\|XC_VM\\[' . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
                shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
            }
        }
    }
    if (!CoreUtilities::$rSettings['kill_rogue_ffmpeg']) {
    } else {
        exec("ps aux | grep -v grep | grep '/*_.m3u8' | awk '{print \$2}'", $rFFMPEG);
        foreach ($rFFMPEG as $rPID) {
            if (!(is_numeric($rPID) && 0 < intval($rPID)) || in_array($rPID, $rActivePIDs)) {
            } else {
                echo 'Kill Roque PID: ' . $rPID . "\n";
                shell_exec('kill -9 ' . $rPID . ';');
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
