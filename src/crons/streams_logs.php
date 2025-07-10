<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Stream Logs]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rLog = LOGS_TMP_PATH . 'stream_log.log';
        if (!file_exists($rLog)) {
        } else {
            $rQuery = rtrim(parseLog($rLog), ',');
            if (empty($rQuery)) {
            } else {
                $db->query('INSERT INTO `streams_logs` (`stream_id`,`server_id`,`action`,`source`,`date`) VALUES ' . $rQuery . ';');
            }
            unlink($rLog);
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function parseLog($rLog) {
    $rQuery = '';
    if (!file_exists($rLog)) {
    } else {
        $rFP = fopen($rLog, 'r');
        while (!feof($rFP)) {
            $rLine = trim(fgets($rFP));
            if (!empty($rLine)) {
                $rLine = json_decode(base64_decode($rLine), true);
                if (!$rLine['stream_id']) {
                } else {
                    $rQuery .= '(' . $rLine['stream_id'] . ',' . SERVER_ID . ",'" . $rLine['action'] . "','" . $rLine['source'] . "','" . $rLine['time'] . "'),";
                }
                break;
            }
        }
        fclose($rFP);
    }
    return $rQuery;
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
