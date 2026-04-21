<?php

/**
 * StreamsCronJob — streams cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class StreamsCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:streams';
    }

    public function getDescription(): string {
        return 'Cron: check live streams, monitors, on-demand, rogue PIDs';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Live Checker]');
        $this->loadCron();

        return 0;
    }

    private function loadCron(): void {
        global $db;

        if (!ProcessManager::isNginxRunning()) {
            echo 'XC_VM not running...' . "\n";
        }

        if (SettingsManager::getAll()['redis_handler']) {
            RedisManager::ensureConnected();
        }

        $rActivePIDs = array();
        $rStreamIDs = array();

        if (SettingsManager::getAll()['redis_handler']) {
            $db->query('SELECT t2.stream_display_name, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1', SERVER_ID, SERVER_ID);
        } else {
            $db->query("SELECT t2.stream_display_name, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, clients.online_clients, clients_hls.online_clients_hls, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY stream_id) AS clients ON clients.stream_id = t1.stream_id LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients_hls FROM `lines_live` WHERE `server_id` = ? AND `container` = 'hls' AND `hls_end` = 0 GROUP BY stream_id) AS clients_hls ON clients_hls.stream_id = t1.stream_id WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1", SERVER_ID, SERVER_ID, SERVER_ID, SERVER_ID);
        }

        if ($db->num_rows() > 0) {
            foreach ($db->get_rows() as $rStream) {
                echo 'Stream ID: ' . $rStream['stream_id'] . "\n";
                $rStreamIDs[] = $rStream['stream_id'];

                if (ProcessManager::isMonitorAlive($rStream['monitor_pid'], $rStream['stream_id']) || $rStream['on_demand']) {
                    if ($rStream['on_demand'] == 1 && $rStream['attached'] == 0) {
                        if (SettingsManager::getAll()['redis_handler']) {
                            $rCount = 0;
                            $rRedis = RedisManager::instance();
                            if ($rRedis) {
                                $rKeys = $rRedis->zRangeByScore('STREAM#' . $rStream['stream_id'], '-inf', '+inf');
                                if (count($rKeys) > 0) {
                                    $rConnections = array_map('igbinary_unserialize', $rRedis->mGet($rKeys));
                                    foreach ($rConnections as $rConnection) {
                                        if ($rConnection && $rConnection['server_id'] == SERVER_ID) {
                                            $rCount++;
                                        }
                                    }
                                }
                            }
                            $rStream['online_clients'] = $rCount;
                        }

                        $rAdminQueue = $rQueue = 0;
                        if (SettingsManager::getAll()['on_demand_instant_off'] && file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id']))) {
                            foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id']))) ?: array()) as $rPID) {
                                if (ProcessManager::isRunning($rPID, 'php-fpm')) {
                                    $rQueue++;
                                }
                            }
                        }
                        if (file_exists(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']))) {
                            if (time() - filemtime(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id'])) <= 30) {
                                $rAdminQueue = 1;
                            } else {
                                unlink(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']));
                            }
                        }
                        if ($rQueue == 0 && $rAdminQueue == 0 && $rStream['online_clients'] == 0 && (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.m3u8') || intval(SettingsManager::getAll()['on_demand_wait_time']) < time() - intval($rStream['stream_started']) || $rStream['stream_status'] == 1)) {
                            echo 'Stop on-demand stream...' . "\n\n";
                            StreamProcess::stopStream($rStream['stream_id'], true);
                        }
                    }

                    if ($rStream['vframes_server_id'] == SERVER_ID && !ProcessManager::isNamedProcessRunning($rStream['vframes_pid'], 'Thumbnail', $rStream['stream_id'])) {
                        echo 'Start Thumbnail...' . "\n";
                        StreamProcess::startThumbnail($rStream['stream_id']);
                    }
                    if ($rStream['tv_archive_server_id'] == SERVER_ID && !ProcessManager::isNamedProcessRunning($rStream['tv_archive_pid'], 'TVArchive', $rStream['stream_id'])) {
                        echo 'Start TV Archive...' . "\n";
                        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php archive ' . intval($rStream['stream_id']) . ' >/dev/null 2>/dev/null & echo $!');
                    }

                    foreach (glob(STREAMS_PATH . $rStream['stream_id'] . '_*.ts.enc') as $rFile) {
                        if (!file_exists(rtrim($rFile, '.enc'))) {
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
                    if (ProcessManager::isStreamRunning($rPID, $rStream['stream_id']) && file_exists($rPlaylist)) {
                        echo 'Update Stream Information...' . "\n";
                        $rBitrate = StreamUtils::getStreamBitrate('live', STREAMS_PATH . $rStream['stream_id'] . '_.m3u8');
                        if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.progress')) {
                            $rProgress = file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.progress');
                            unlink(STREAMS_PATH . $rStream['stream_id'] . '_.progress');
                            if ($rStream['fps_restart']) {
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
                        if ($rStreamInfo) {
                            $rStreamJSON = json_decode($rStreamInfo, true);
                            $rCompatible = intval(DiagnosticsService::checkCompatibility($rStreamJSON, SettingsManager::getAll()['player_allow_hevc']));
                            if (is_array($rStreamJSON) && isset($rStreamJSON['codecs']) && is_array($rStreamJSON['codecs'])) {
                                $rAudioCodec = isset($rStreamJSON['codecs']['audio']['codec_name']) ? $rStreamJSON['codecs']['audio']['codec_name'] : null;
                                $rVideoCodec = isset($rStreamJSON['codecs']['video']['codec_name']) ? $rStreamJSON['codecs']['video']['codec_name'] : null;
                                $rResolution = isset($rStreamJSON['codecs']['video']['height']) ? $rStreamJSON['codecs']['video']['height'] : null;
                            }
                            if ($rResolution) {
                                $rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
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
                    StreamProcess::startMonitor($rStream['stream_id']);
                    usleep(50000);
                }
            }
        }

        $db->query('SELECT `streams`.`id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`direct_source` = 1 AND `streams`.`direct_proxy` = 1 AND `streams_servers`.`server_id` = ? AND `streams_servers`.`pid` > 0;', SERVER_ID);
        if ($db->num_rows() > 0) {
            foreach ($db->get_rows() as $rStream) {
                if (file_exists(STREAMS_PATH . $rStream['id'] . '.analyse')) {
                    $rFFProbeOutput = FFprobeRunner::probeStream(STREAMS_PATH . $rStream['id'] . '.analyse');
                    if ($rFFProbeOutput) {
                        $rBitrate = $rFFProbeOutput['bitrate'] / 1024;
                        $rCompatible = intval(DiagnosticsService::checkCompatibility($rFFProbeOutput, SettingsManager::getAll()['player_allow_hevc']));
                        if (is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']) && is_array($rFFProbeOutput['codecs'])) {
                            $rAudioCodec = isset($rFFProbeOutput['codecs']['audio']['codec_name']) ? $rFFProbeOutput['codecs']['audio']['codec_name'] : null;
                            $rVideoCodec = isset($rFFProbeOutput['codecs']['video']['codec_name']) ? $rFFProbeOutput['codecs']['video']['codec_name'] : null;
                            $rResolution = isset($rFFProbeOutput['codecs']['video']['height']) ? $rFFProbeOutput['codecs']['video']['height'] : null;
                        }
                        if ($rResolution) {
                            $rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
                        }
                    }
                    echo 'Stream ID: ' . $rStream['id'] . "\n";
                    echo 'Update Stream Information...' . "\n";
                    $db->query('UPDATE `streams_servers` SET `bitrate` = ?, `stream_info` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `compatible` = ? WHERE `stream_id` = ? AND `server_id` = ?', $rBitrate, json_encode($rFFProbeOutput), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rStream['id'], SERVER_ID);
                }

                $rUUIDs = array();
                $rConnections = ConnectionTracker::getConnections(SERVER_ID, null, $rStream['id']);
                foreach ($rConnections as $rUserID => $rItems) {
                    foreach ($rItems as $rItem) {
                        $rUUIDs[] = $rItem['uuid'];
                    }
                }

                if ($rHandle = opendir(CONS_TMP_PATH . $rStream['id'] . '/')) {
                    while (false !== ($rFilename = readdir($rHandle))) {
                        if ($rFilename != '.' && $rFilename != '..') {
                            if (!in_array($rFilename, $rUUIDs)) {
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
        if (preg_match_all('/XC_VM\\[(.*)\\]/', $rProcesses, $rMatches)) {
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

        if (SettingsManager::getAll()['kill_rogue_ffmpeg']) {
            exec("ps aux | grep -v grep | grep '/*_.m3u8' | awk '{print \$2}'", $rRoguePIDs);
            foreach ($rRoguePIDs as $rPID) {
                if (is_numeric($rPID) && intval($rPID) > 0 && !in_array($rPID, $rActivePIDs)) {
                    echo 'Kill Roque PID: ' . $rPID . "\n";
                    shell_exec('kill -9 ' . $rPID . ';');
                }
            }
        }
    }
}
