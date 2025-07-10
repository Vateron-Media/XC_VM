<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Stats]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rTimeout = 60;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadCron() {
    global $db;
    if (!CoreUtilities::$rServers[SERVER_ID]['is_main']) {
    } else {
        $rTime = time();
        $rDates = array('today' => array($rTime - 86400, $rTime), 'week' => array($rTime - 604800, $rTime), 'month' => array($rTime - 2592000, $rTime), 'all' => array(0, $rTime));
        $db->query('TRUNCATE `streams_stats`;');
        foreach ($rDates as $rType => $rDate) {
            $rStats = array();
            $db->query('SELECT `stream_id`, COUNT(*) AS `connections`, SUM(`date_end` - `date_start`) AS `time`, COUNT(DISTINCT(`user_id`)) AS `users` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id`;', $rDate[0], $rDate[1]);
            if (0 >= $db->num_rows()) {
            } else {
                foreach ($db->get_rows() as $rRow) {
                    $rStats[$rRow['stream_id']] = array('rank' => 0, 'time' => intval($rRow['time']), 'connections' => $rRow['connections'], 'users' => $rRow['users']);
                }
            }
            $db->query('SELECT `stream_id`, SUM(`date_end` - `date_start`) AS `time` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id` ORDER BY `time` DESC, `stream_id` DESC;', $rDate[0], $rDate[1]);
            if (0 >= $db->num_rows()) {
            } else {
                $rRank = 1;
                foreach ($db->get_rows() as $rRow) {
                    if (!isset($rStats[$rRow['stream_id']])) {
                    } else {
                        $rStats[$rRow['stream_id']]['rank'] = $rRank;
                        $rRank++;
                    }
                }
            }
            foreach ($rStats as $rStreamID => $rArray) {
                $db->query('INSERT INTO `streams_stats`(`stream_id`, `rank`, `time`, `connections`, `users`, `type`) VALUES(?, ?, ?, ?, ?, ?);', $rStreamID, $rArray['rank'], $rArray['time'], $rArray['connections'], $rArray['users'], $rType);
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
