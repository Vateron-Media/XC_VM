<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        $db->close_mysql();
        cli_set_process_title('XC_VM[TMP]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        foreach (array(TMP_PATH, CRONS_TMP_PATH, DIVERGENCE_TMP_PATH, FLOOD_TMP_PATH, MINISTRA_TMP_PATH, SIGNALS_TMP_PATH, LOGS_TMP_PATH) as $rTmpPath) {
            foreach (scandir($rTmpPath) as $rFile) {
                if (!(600 <= time() - filemtime($rTmpPath . $rFile) && stripos($rFile, 'ministra_') === false)) {
                } else {
                    unlink($rTmpPath . $rFile);
                }
            }
        }
        foreach (scandir(PLAYLIST_PATH) as $rFile) {
            if (CoreUtilities::$rSettings['cache_playlists'] > time() - filemtime(PLAYLIST_PATH . $rFile)) {
            } else {
                unlink(PLAYLIST_PATH . $rFile);
            }
        }
        clearstatcache();
        @unlink($rIdentifier);
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
