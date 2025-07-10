<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    if ($argc) {
        require str_replace('\\', '/', dirname($argv[0])) . '/../includes/admin.php';
        cli_set_process_title('XC_VM[Series]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        loadCron();
        @unlink($rIdentifier);
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadCron() {
    global $db;
    if (time() - CoreUtilities::$rSettings['cc_time'] < 3600) {
        exit();
    }
    $db->query('UPDATE `settings` SET `cc_time` = ?;', time());
    $db->query('SELECT `id`, `stream_display_name`, `series_no`, `stream_source` FROM `streams` WHERE `type` = 3 AND `series_no` <> 0;');
    if (0 >= $db->num_rows()) {
    } else {
        foreach ($db->get_rows() as $rRow) {
            $rPlaylist = generateSeriesPlaylist(intval($rRow['series_no']));
            if (!$rPlaylist['success']) {
            } else {
                $rSourceArray = json_decode($rRow['stream_source'], true);
                $rUpdate = false;
                foreach ($rPlaylist['sources'] as $rSource) {
                    if (in_array($rSource, $rSourceArray)) {
                    } else {
                        $rUpdate = true;
                    }
                }
                if (!$rUpdate) {
                } else {
                    $db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode($rPlaylist['sources'], JSON_UNESCAPED_UNICODE), $rRow['id']);
                    echo 'Updated: ' . $rRow['stream_display_name'] . "\n";
                }
            }
        }
    }
    scanBouquets();
}
