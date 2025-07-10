<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc == 2) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rStreamID = intval($argv[1]);
        checkRunning($rStreamID);
        set_time_limit(0);
        cli_set_process_title('Thumbnail[' . $rStreamID . ']');
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadcli() {
    global $db;
    global $rStreamID;
    $db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.vframes_server_id WHERE t1.`id` = ? AND t1.`vframes_server_id` = ?', $rStreamID, SERVER_ID);
    if (0 < $db->num_rows()) {
        $rRow = $db->get_row();
        $db->query('UPDATE `streams` SET `vframes_pid` = ? WHERE `id` = ?', getmypid(), $rStreamID);
        CoreUtilities::updateStream($rStreamID);
        $db->close_mysql();
        while (CoreUtilities::isStreamRunning($rRow['pid'], $rStreamID)) {
            shell_exec(CoreUtilities::$rFFMPEG_CPU . ' -y -i "' . STREAMS_PATH . $rStreamID . '_.m3u8" -qscale:v 4 -frames:v 1 "' . STREAMS_PATH . $rStreamID . '_.jpg" >/dev/null 2>/dev/null &');
            sleep(5);
        }
    } else {
        exit();
    }
}
function checkRunning($rStreamID) {
    clearstatcache(true);
    if (!file_exists(STREAMS_PATH . $rStreamID . '_.thumb')) {
    } else {
        $rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.thumb'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'Thumbnail\\[" . $rStreamID . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'Thumbnail[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
    file_put_contents(STREAMS_PATH . $rStreamID . '_.thumb', getmypid());
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
