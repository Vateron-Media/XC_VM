<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Lines Logs]');
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
    $rLog = LOGS_TMP_PATH . 'client_request.log';
    if (!file_exists($rLog)) {
    } else {
        $rQuery = rtrim(parseLog($rLog), ',');
        if (empty($rQuery)) {
        } else {
            $db->query('INSERT INTO `lines_logs` (`stream_id`,`user_id`,`client_status`,`query_string`,`user_agent`,`ip`,`extra_data`,`date`) VALUES ' . $rQuery . ';');
        }
        unlink($rLog);
    }
}
function parseLog($rLog) {
    global $db;
    $rQuery = '';
    $rFP = fopen($rLog, 'r');
    while (!feof($rFP)) {
        $rLine = trim(fgets($rFP));
        if (!empty($rLine)) {
            $rLine = json_decode(base64_decode($rLine), true);
            $rLine = array_map(array($db, 'escape'), $rLine);
            $rQuery .= '(' . $rLine['stream_id'] . ',' . $rLine['user_id'] . ',' . $rLine['action'] . ',' . $rLine['query_string'] . ',' . $rLine['user_agent'] . ',' . $rLine['user_ip'] . ',' . $rLine['extra_data'] . ',' . $rLine['time'] . '),';
            break;
        }
    }
    fclose($rFP);
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
