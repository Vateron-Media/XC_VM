<?php
function checkRunning($rStreamID)
{
    clearstatcache(true);
    if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
    } else {
        $rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'XC_VM\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'XC_VM[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
}
if ((posix_getpwuid(posix_geteuid())['name'] != 'xc_vm')) {
    exit('Please run as XC_VM!' . "\n");
}
if ((!@$argc || ($argc <= 1))) {
    exit(0);
}
$rStreamID = intval($argv[1]);
$rRestart = !empty($argv[2]);
require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
checkRunning($rStreamID);
set_time_limit(0);
cli_set_process_title('XC_VM[' . $rStreamID . ']');
$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
if (($db->num_rows() <= 0)) {
    CoreUtilities::stopStream($rStreamID);
    exit();
}
$rStreamInfo = $db->get_row();
$db->query('UPDATE `streams_servers` SET `monitor_pid` = ? WHERE `server_stream_id` = ?', getmypid(), $rStreamInfo['server_stream_id']);
if (CoreUtilities::$rSettings['enable_cache']) {
    CoreUtilities::updateStream($rStreamID);
}
$rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : $rStreamInfo['pid']);
$rAutoRestart = json_decode($rStreamInfo['auto_restart'], true);
$rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
$rDelayPID = $rStreamInfo['delay_pid'];
$rParentID = $rStreamInfo['parent_id'];
$rStreamProbe = false;
$rSources = array();
$rSegmentTime = CoreUtilities::$rSegmentSettings['seg_time'];
$rPrioritySwitch = false;
$rMaxFails = 0;
if (($rParentID == 0)) {
    $rSources = json_decode($rStreamInfo['stream_source'], true);
    //////////////////////////
}
if (0 >= $rParentID) {
    $rCurrentSource = $rStreamInfo['current_source'];
} else {
    $rCurrentSource = 'Loopback: #' . $rParentID;
}
$rLastSegment = $rForceSource = null;
$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
$rStreamArguments = $db->get_rows();
if (!(0 < $rStreamInfo['delay_minutes']) && ($rStreamInfo['parent_id'] == 0)) {
    $rDelay = false;
    $rFolder = STREAMS_PATH;
} else {
    $rFolder = DELAY_PATH;
    $rPlaylist = DELAY_PATH . $rStreamID . '_.m3u8';
    $rDelay = true;
}
$rFirstRun = true;
$rTotalCalls = 0;
if (CoreUtilities::isStreamRunning($rPID, $rStreamID)) {
    echo 'Stream is running.' . "\n";
    if ($rRestart) {
        $rTotalCalls = MONITOR_CALLS;
        if ((is_numeric($rPID) && (0 < $rPID))) {
            shell_exec('kill -9 ' . intval($rPID));
        }
        shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
        file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
        if (($rDelay && CoreUtilities::isDelayRunning($rDelayPID, $rStreamID) && is_numeric($rDelayPID) && (0 < $rDelayPID))) {
            shell_exec('kill -9 ' . intval($rDelayPID));
        }
        usleep(50000);
        $rDelayPID = $rPID = 0;
    }
} else {
    file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
}
if (CoreUtilities::$rSettings['kill_rogue_ffmpeg']) {
    exec('ps aux | grep -v grep | grep \'/' . $rStreamID . '_.m3u8\' | awk \'{print $2}\'', $rFFMPEG);
    foreach ($rFFMPEG as $rRoguePID) {
        if ((is_numeric($rRoguePID) && (0 < intval($rRoguePID)) && (intval($rRoguePID) != intval($rPID)))) {
            shell_exec('kill -9 ' . $rRoguePID . ';');
        }
    }
}
goto label235;
label235:
if (true) {
    if (!(0 < $rPID)) {
        goto label471;
    }
    $db->close_mysql();
    $rStartedTime = $rDurationChecked = $rAudioChecked = $rCheckedTime = $rBackupsChecked = time();
    $rMD5 = md5_file($rPlaylist);
    $D97a4f098a8d1bf8 = CoreUtilities::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist);
    $b4015d24aedaf0db = null;
    goto label592;
    label592: //while
    if ((CoreUtilities::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist))) {
        if (!(!empty($rAutoRestart['days']) && !empty($rAutoRestart['at']))) {
            goto label195;
        }
        list($rHour, $rMinutes) = explode(':', $rAutoRestart['at']);
        if (!(in_array(date('l'), $rAutoRestart['days']) && (date('H') == $rHour))) {
            goto label195;
        }
        if (!($rMinutes == date('i'))) {
            goto label195;
        }
        echo 'Auto-restart' . "\n";
        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'AUTO_RESTART', $rCurrentSource);
        $D97a4f098a8d1bf8 = false;
        goto label1186;
    }
    goto label1186;
    label195:
    if (($rStreamProbe || (!file_exists(STREAMS_PATH . $rStreamID . '_.dur') && (300 < (time() - $rDurationChecked))))) {
        echo 'Probe Stream' . "\n";
        $rSegment = CoreUtilities::getPlaylistSegments($rPlaylist, 10)[0];
        if (!empty($rSegment)) {
            if (((300 < (time() - $rDurationChecked)) && ($rSegment == $rLastSegment))) {
                CoreUtilities::streamLog($rStreamID, SERVER_ID, 'FFMPEG_ERROR', $rCurrentSource);
                goto label1186;
            }
            $rLastSegment = $rSegment;
            $E02429d2ee600884 = CoreUtilities::probeStream($rFolder . $rSegment);
            if ((10 < intval($E02429d2ee600884['of_duration']))) {
                $E02429d2ee600884['of_duration'] = 10;
            }
            file_put_contents(STREAMS_PATH . $rStreamID . '_.dur', intval($E02429d2ee600884['of_duration']));
            if (($rSegmentTime < intval($E02429d2ee600884['of_duration']))) {
                $rSegmentTime = intval($E02429d2ee600884['of_duration']);
            }
            file_put_contents(STREAMS_PATH . $rStreamID . '_.stream_info', json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE));
            $rStreamInfo['stream_info'] = json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE);
        }
        $rStreamProbe = false;
        $rDurationChecked = time();
        if (!file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
            file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', $rPID);
        }
        if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
            file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
        }
    }
    if (!(($rStreamInfo['fps_restart'] == 1) && (CoreUtilities::$rSettings['fps_delay'] < (time() - $rStartedTime)) && file_exists(STREAMS_PATH . $rStreamID . '_.progress_check'))) {
        goto label298;
    }
    echo 'Checking FPS...' . "\n";
    $d75674a646265e7b = floatval(json_decode(file_get_contents(STREAMS_PATH . $rStreamID . '_.progress_check'), true)['fps']) ?: 0;
    if (!(0 < $d75674a646265e7b)) {
        goto label1847;
    }
    if (!$b4015d24aedaf0db) {
        goto label1087;
    }
    if (!($b4015d24aedaf0db && (($d75674a646265e7b * ($rStreamInfo['fps_threshold'] ?: 100)) < $b4015d24aedaf0db))) {
        goto label1847;
    }
    echo 'FPS dropped below threshold! Break' . "\n";
    CoreUtilities::streamLog($rStreamID, SERVER_ID, 'FPS_DROP_THRESHOLD', $rCurrentSource);
    goto label1186;
    label884:
    $rArguments = implode(' ', CoreUtilities::getArguments($rStreamArguments, $rProtocol, 'fetch'));
    if (($E02429d2ee600884 = CoreUtilities::probeStream($rStreamSource, $rArguments))) {
        echo 'Force new source' . "\n";
        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'FORCE_SOURCE', $rSources[$rForceID]);
        $rForceSource = $rSources[$rForceID];
        unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
        $D97a4f098a8d1bf8 = false;
        goto label1186;
    }
    goto label1631;
    label1631:
    unlink(SIGNALS_TMP_PATH . $rStreamID . '.force');
    label496:
    if ((file_exists(SIGNALS_TMP_PATH . $rStreamID . '.force') && ($rParentID == 0))) {
        $rForceID = intval(file_get_contents(SIGNALS_TMP_PATH . $rStreamID . '.force'));
        $rStreamSource = CoreUtilities::parseStreamURL($rSources[$rForceID]);
        if (($rSources[$rForceID] != $rCurrentSource)) {
            $rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
            goto label884;
        }
        goto label1631;
    }
    if (($rDelay && ($rStreamInfo['delay_available_at'] <= time()) && !CoreUtilities::isDelayRunning($rDelayPID, $rStreamID))) {
        echo 'Start Delay' . "\n";
        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'DELAY_START');
        $rDelayPID = intval(shell_exec(PHP_BIN . ' ' . CLI_PATH . 'delay.php ' . intval($rStreamID) . ' ' . intval($rStreamInfo['delay_minutes']) . ' >/dev/null 2>/dev/null & echo $!'));
    }
    sleep(1);
    goto label592;
}
goto label1880;
label1:
if (!$rStreamInfo['parent_id']) {
    goto label49;
}
$rForceSource = (!is_null(CoreUtilities::$rServers[SERVER_ID]['private_url_ip']) && !is_null(CoreUtilities::$rServers[$rStreamInfo['parent_id']]['private_url_ip']) ? CoreUtilities::$rServers[$rStreamInfo['parent_id']]['private_url_ip'] : CoreUtilities::$rServers[$rStreamInfo['parent_id']]['public_url_ip']) . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode(CoreUtilities::$rSettings['live_streaming_pass']) . '&extension=ts';
label49:
$rData = CoreUtilities::startLLOD($rStreamID, $rStreamInfo, $rStreamInfo['parent_id'] ? array() : $rStreamArguments, $rForceSource);
goto label644;
label1512:
if ($rForceSource) {
    $Ea84d0933a1ef2f0 = $rForceSource;
} else {
    $Ea84d0933a1ef2f0 = json_decode($rStreamInfo['stream_source'], true)[0];
}
$rData = CoreUtilities::startStream($rStreamID, false, $Ea84d0933a1ef2f0, true);
label644:
goto label1131;
label1127:
$rData = CoreUtilities::startLoopback($rStreamID);
label1131:
if ((is_numeric($rData) && ($rData == 0))) {
    $E9d347a502b13abd = true;
    $rMaxFails++;
    if (((0 < CoreUtilities::$rSettings['stop_failures']) && ($rMaxFails == CoreUtilities::$rSettings['stop_failures']))) {
        echo 'Failure limit reached, exiting.' . "\n";
        exit();
        goto label1880;
    }
}
if (!$rData) {
    exit();
    goto label1880;
}
if ($E9d347a502b13abd) {
    goto label562;
}
$rPID = intval($rData['main_pid']);
if ($rPID) {
    file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', $rPID);
}
$rPlaylist = $rData['playlist'];
$rDelay = $rData['delay_enabled'];
$rStreamInfo['delay_available_at'] = $rData['delay_start_at'];
$rParentID = $rData['parent_id'];
if (0 >= $rParentID) {
    $rCurrentSource = trim($rData['stream_source'], '\'"');
} else {
    $rCurrentSource = 'Loopback: #' . $rParentID;
}
$rOffset = $rData['offset'];
$rStreamProbe = true;
echo 'Stream started' . "\n";
echo $rCurrentSource . "\n";
if ($rPrioritySwitch) {
    $rForceSource = null;
    $rPrioritySwitch = false;
}
if (!$rDelay) {
    $rFolder = STREAMS_PATH;
} else {
    $rFolder = DELAY_PATH;
}
$e1bc98ce34937596 = $rFolder . $rStreamID . '_0.ts';
$ea6de21e70c530a9 = false;
$rChecks = 0;
$A63c815f93524582 = (($rSegmentTime * 3) <= 30 ? $rSegmentTime * 3 : 30);
if (!($A63c815f93524582 < 20)) {
    goto label998;
}
$A63c815f93524582 = 20;
goto label998;
label998:
if (true) {
    echo 'Checking for playlist ' . ($rChecks + 1) . ('/' . $A63c815f93524582 . '...' . "\n");
    if (CoreUtilities::isStreamRunning($rPID, $rStreamID)) {
        if (file_exists($rPlaylist)) {
            echo 'Playlist exists!' . "\n";
            goto label1064;
        }
        if ((file_exists($e1bc98ce34937596) && !$ea6de21e70c530a9 && $rStreamInfo['on_demand'])) {
            echo 'Segment exists!' . "\n";
            $ea6de21e70c530a9 = true;
            $rChecks = 0;
            $db->query('UPDATE `streams_servers` SET `stream_status` = 0, `stream_started` = ? WHERE `server_stream_id` = ?', time() - $rOffset, $rStreamInfo['server_stream_id']);
        }
        if (($rChecks == $A63c815f93524582)) {
            echo 'Reached max failures' . "\n";
            $E9d347a502b13abd = true;
            goto label1064;
        }
        $rChecks++;
        sleep(1);
        goto label998;
    }
    echo 'Ffmpeg stopped running' . "\n";
    $E9d347a502b13abd = true;
    goto label1064;
}
goto label1064;
label1064:
goto label562;
label562:
CoreUtilities::$rSettings = CoreUtilities::getSettings();
if (CoreUtilities::isStreamRunning($rPID, $rStreamID) && !$E9d347a502b13abd) {
    goto label1267;
}
echo 'Stream start failed...' . "\n";
if (($rParentID == 0)) {
    CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_START_FAIL', $rCurrentSource);
}
if ((is_numeric($rPID) && (0 < $rPID) && CoreUtilities::isStreamRunning($rPID, $rStreamID))) {
    shell_exec('kill -9 ' . intval($rPID));
}
$db->query('UPDATE `streams_servers` SET `pid` = null, `stream_status` = 1 WHERE `server_stream_id` = ?;', $rStreamInfo['server_stream_id']);
if (CoreUtilities::$rSettings['enable_cache']) {
    CoreUtilities::updateStream($rStreamID);
}
echo 'Sleep for ' . CoreUtilities::$rSettings['stream_fail_sleep'] . ' seconds...';
sleep(CoreUtilities::$rSettings['stream_fail_sleep']);
if (!(CoreUtilities::$rSettings['on_demand_failure_exit'] && $rStreamInfo['on_demand'])) {
    goto label554;
}
echo 'On-demand failed to run!' . "\n";
exit();
goto label1880;
label1186:
if ($D97a4f098a8d1bf8) {
    CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_FAILED', $rCurrentSource);
    echo 'Stream failed!' . "\n";
}
$db->db_connect();
goto label471;
label471:
if (CoreUtilities::isStreamRunning($rPID, $rStreamID)) {
    echo 'Killing stream...' . "\n";
    if ((is_numeric($rPID) && (0 < $rPID))) {
        shell_exec('kill -9 ' . intval($rPID));
    }
    usleep(50000);
}
if (CoreUtilities::isDelayRunning($rDelayPID, $rStreamID)) {
    echo 'Killing stream delay...' . "\n";
    ////////////////////////
    if ((is_numeric($rDelayPID) && (0 < $rDelayPID))) {
        shell_exec('kill -9 ' . intval($rDelayPID));
    }
    usleep(50000);
}
goto label76;
//////////////////////////
label554:
if ((MONITOR_CALLS <= $rTotalCalls)) {
    $rTotalCalls = 0;
}
goto label76;
label76:
if (!CoreUtilities::isStreamRunning($rPID, $rStreamID)) {
    $E9d347a502b13abd = false;
    echo 'Restarting...' . "\n";
    shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
    file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
    $rOffset = 0;
    $rTotalCalls++;
    if ((0 < $rStreamInfo['parent_id']) && CoreUtilities::$rSettings['php_loopback']) {
        goto label1127;
    }
    if ((0 < $rStreamInfo['llod']) && $rStreamInfo['on_demand'] && $rFirstRun) {
        goto label933;
    }
    if ($rStreamInfo['type'] == 3) {
        if (((0 < $rPID) && !$rStreamInfo['parent_id'] && (0 < $rStreamInfo['stream_started']))) {
            $rCCInfo = json_decode($rStreamInfo['cc_info'], true);
            if (($rCCInfo && ((time() - $rStreamInfo['stream_started']) < (intval($rCCInfo[count($rCCInfo) - 1]['finish']) * 0.95)))) {
                $rOffset = time() - $rStreamInfo['stream_started'];
            }
        }
        $rData = CoreUtilities::startStream($rStreamID, false, $rForceSource, false, $rOffset);
        label933:
        if ($rStreamInfo['llod'] == 1) {
            goto label1512;
        }
        goto label1;
    }
    $rData = CoreUtilities::startStream($rStreamID, $rTotalCalls < MONITOR_CALLS, $rForceSource);
    goto label644;
}
goto label235;
label1087:
if (CoreUtilities::$rSettings['fps_check_type'] == 1) {
    goto label1094;
}
$b4015d24aedaf0db = $d75674a646265e7b;
goto label1847;
label1094:
$rSegment = CoreUtilities::getPlaylistSegments($rPlaylist, 10)[0];
if (empty($rSegment)) {
    goto label1847;
}
$E02429d2ee600884 = CoreUtilities::probeStream($rFolder . $rSegment);
if (!(isset($E02429d2ee600884['codecs']['video']['avg_frame_rate']) || isset($E02429d2ee600884['codecs']['video']['r_frame_rate']))) {
    goto label1847;
}
$d75674a646265e7b = $E02429d2ee600884['codecs']['video']['avg_frame_rate'] ?: $E02429d2ee600884['codecs']['video']['r_frame_rate'];
goto label768;
label768:
if (stripos($d75674a646265e7b, '/') !== false) {
    goto label780;
}
$d75674a646265e7b = floatval($d75674a646265e7b);
goto label1052;
label780:
list($Be71401a913607c0, $Cd98e5a46a318d0a) = array_map('floatval', explode('/', $d75674a646265e7b));
goto label1047;
label1047:
$d75674a646265e7b = floatval($Be71401a913607c0 / $Cd98e5a46a318d0a);
label1052:
if (!(0 < $d75674a646265e7b)) {
    goto label1057;
}
$b4015d24aedaf0db = $d75674a646265e7b;
label1057:
goto label1847;
label1267:
echo 'Started! Probe Stream' . "\n";
if ($rFirstRun) {
    $rFirstRun = false;
    CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_START', $rCurrentSource);
} else {
    CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_RESTART', $rCurrentSource);
}
$rSegment = $rFolder . CoreUtilities::getPlaylistSegments($rPlaylist, 10)[0];
$rStreamInfo['stream_info'] = null;
if (file_exists($rSegment)) {
    $E02429d2ee600884 = CoreUtilities::probeStream($rSegment);
    if ((10 < intval($E02429d2ee600884['of_duration']))) {
        $E02429d2ee600884['of_duration'] = 10;
    }
    file_put_contents(STREAMS_PATH . $rStreamID . '_.dur', intval($E02429d2ee600884['of_duration']));
    if (($rSegmentTime < intval($E02429d2ee600884['of_duration']))) {
        $rSegmentTime = intval($E02429d2ee600884['of_duration']);
    }
    if ($E02429d2ee600884) {
        $rStreamInfo['stream_info'] = json_encode($E02429d2ee600884, JSON_UNESCAPED_UNICODE);
        $rBitrate = CoreUtilities::getStreamBitrate('live', STREAMS_PATH . $rStreamID . '_.m3u8');
        $rStreamProbe = false;
        $rDurationChecked = time();
    }
}
$rCompatible = 0;
$rAudioCodec = $rVideoCodec = $rResolution = null;
if (!$rStreamInfo['stream_info']) {
    goto label430;
}
$rStreamJSON = json_decode($rStreamInfo['stream_info'], true);
if (is_array($rStreamJSON)) {
    $rCompatible = intval(CoreUtilities::checkCompatibility($rStreamJSON));
} else {
    $rCompatible = 0;
}
$rAudioCodec = $rStreamJSON['codecs']['audio']['codec_name'] ?: null;
$rVideoCodec = $rStreamJSON['codecs']['video']['codec_name'] ?: null;
$rResolution = $rStreamJSON['codecs']['video']['height'] ?: null;
if (!$rResolution) {
    goto label430;
}
$rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
label430:
if (!$ea6de21e70c530a9 && $rStreamInfo['stream_info'] && $rStreamInfo['on_demand']) {
    if ($rStreamInfo['stream_info']) {
        $db->query('UPDATE `streams_servers` SET `stream_info` = ?, `compatible` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `bitrate` = ?, `stream_status` = 0, `stream_started` = ? WHERE `server_stream_id` = ?', $rStreamInfo['stream_info'], $rCompatible, $rAudioCodec, $rVideoCodec, $rResolution, intval($rBitrate), time() - $rOffset, $rStreamInfo['server_stream_id']);
    } else {
        $db->query('UPDATE `streams_servers` SET `stream_status` = 0, `stream_info` = NULL, `compatible` = 0, `audio_codec` = NULL, `video_codec` = NULL, `resolution` = NULL, `stream_started` = ? WHERE `server_stream_id` = ?', time() - $rOffset, $rStreamInfo['server_stream_id']);
    }
} else {
    $db->query('UPDATE `streams_servers` SET `stream_info` = ?, `compatible` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `bitrate` = ?, `stream_status` = 0 WHERE `server_stream_id` = ?', $rStreamInfo['stream_info'], $rCompatible, $rAudioCodec, $rVideoCodec, $rResolution, intval($rBitrate), $rStreamInfo['server_stream_id']);
}
if (CoreUtilities::$rSettings['enable_cache']) {
    CoreUtilities::updateStream($rStreamID);
}
echo 'End start process' . "\n";
goto label554;
label1847:
unlink(STREAMS_PATH . $rStreamID . '_.progress_check');
label298:
if (!((CoreUtilities::$rSettings['audio_restart_loss'] == 1) && (300 < (time() - $rAudioChecked)))) {
    goto label617;
}
echo 'Checking audio...' . "\n";
$rSegment = CoreUtilities::getPlaylistSegments($rPlaylist, 10)[0];
if (!empty($rSegment)) {
    $E02429d2ee600884 = CoreUtilities::probeStream($rFolder . $rSegment);
    if ((!isset($E02429d2ee600884['codecs']['audio']) || empty($E02429d2ee600884['codecs']['audio']))) {
        echo 'Lost audio! Break' . "\n";
        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'AUDIO_LOSS', $rCurrentSource);
        goto label1186;
    }
    $rAudioChecked = time();
    label617:
    if ((($rSegmentTime * 6) <= time() - $rCheckedTime)) {
        $Fcfb63b23cad3c6e = md5_file($rPlaylist);
        if ($rMD5 != $Fcfb63b23cad3c6e) {
            $rMD5 = $Fcfb63b23cad3c6e;
            $rCheckedTime = time();
            label1851:
            if (CoreUtilities::$rSettings['encrypt_hls']) {
                foreach (glob(STREAMS_PATH . $rStreamID . '_*.ts.enc') as $rFile) {
                    if (!file_exists(rtrim($rFile, '.enc'))) {
                        unlink($rFile);
                    }
                }
            }
            if ((count(json_decode($rStreamInfo['stream_info'], true)) == 0)) {
                $rStreamProbe = true;
            }
            $rCheckedTime = time();
            goto label1095;
        }
        goto label1186;
    }
    label1095:
    if (((CoreUtilities::$rSettings['priority_backup'] == 1) && (1 < count($rSources)) && ($rParentID == 0) && (300 < (time() - $rBackupsChecked)))) {
        echo 'Checking backups...' . "\n";
        $rBackupsChecked = time();
        $rKey = array_search($rCurrentSource, $rSources);
        if ((!is_numeric($rKey) || (0 < $rKey))) {
            foreach ($rSources as $rSource) {
                if (!(($rSource == $rCurrentSource) || ($rSource == $rForceSource))) {
                    $rStreamSource = CoreUtilities::parseStreamURL($rSource);
                    $rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
                    $rArguments = implode(' ', CoreUtilities::getArguments($rStreamArguments, $rProtocol, 'fetch'));
                    if (($E02429d2ee600884 = CoreUtilities::probeStream($rStreamSource, $rArguments))) {
                        echo 'Switch priority' . "\n";
                        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'PRIORITY_SWITCH', $rSource);
                        $rForceSource = $rSource;
                        $rPrioritySwitch = true;
                        $D97a4f098a8d1bf8 = false;
                        goto label1186;
                    }
                }
            }
        }
    }
    goto label496;
}
goto label1186;
label1880:
;
