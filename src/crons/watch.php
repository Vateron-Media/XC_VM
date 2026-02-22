<?php
ini_set('memory_limit', -1);
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        require str_replace('\\', '/', dirname($argv[0])) . '/../modules/watch/WatchCron.php';
        $rForce = null;
        if (count($argv) != 2) {
        } else {
            $rForce = intval($argv[1]);
        }
        if ($rForce) {
        } else {
            if (file_exists(CACHE_TMP_PATH . 'watch_pid')) {
                $rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'watch_pid'));
            } else {
                $rPrevPID = null;
            }
            if (!($rPrevPID && CoreUtilities::isProcessRunning($rPrevPID, 'php'))) {
            } else {
                echo 'Watch folder is already running. Please wait until it finishes.' . "\n";
                exit();
            }
        }
        file_put_contents(CACHE_TMP_PATH . 'watch_pid', getmypid());
        cli_set_process_title('XC_VM[Watch Folder]');
        $rScanOffset = (intval(CoreUtilities::$rSettings['scan_seconds']) ?: 3600);
        $rThreadCount = (intval(CoreUtilities::$rSettings['thread_count']) ?: 50);
        $F7fa29461a8a5ee2 = (intval(CoreUtilities::$rSettings['max_items']) ?: 0);
        set_time_limit(0);
        if (strlen(CoreUtilities::$rSettings['tmdb_api_key']) != 0) {
            WatchCron::run();
        } else {
            exit('No TMDb API key.');
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
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
