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
                $fullPath = $rTmpPath . '/' . $rFile;

                // Skip . and ..
                if ($rFile === '.' || $rFile === '..') {
                    continue;
                }

                // Delete only files that are older than 600 seconds and do not start with 'ministra_'
                if (is_file($fullPath) && time() - filemtime($fullPath) >= 600 && stripos($rFile, 'ministra_') === false) {
                    unlink($fullPath);
                }
            }
        }
        foreach (scandir(PLAYLIST_PATH) as $rFile) {
            $fullPath = rtrim(PLAYLIST_PATH, '/') . '/' . $rFile;

            // Skip . and ..
            if ($rFile === '.' || $rFile === '..') {
                continue;
            }

            // Only files
            if (is_file($fullPath)) {
                // Check if the file is older than the cache duration
                if (CoreUtilities::$rSettings['cache_playlists'] <= time() - filemtime($fullPath)) {
                    unlink($fullPath);
                }
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
