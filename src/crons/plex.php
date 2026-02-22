<?php
/**
 * Plex Sync Cron — точка входа.
 *
 * Логика извлечена в modules/plex/PlexCron.php (Фаза 5.1).
 * Этот файл содержит только CLI-бутстрап и вызов модуля.
 */
ini_set('memory_limit', -1);
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(30711);
        $rForce = null;
        if (count($argv) == 2) {
            $rForce = intval($argv[1]);
        }
        if (!$rForce) {
            if (file_exists(CACHE_TMP_PATH . 'plex_pid')) {
                $rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'plex_pid'));
            } else {
                $rPrevPID = null;
            }
            if ($rPrevPID && CoreUtilities::isProcessRunning($rPrevPID, 'php')) {
                echo 'Plex Sync is already running. Please wait until it finishes.' . "\n";
                exit();
            }
        }
        $rIdentifier = CACHE_TMP_PATH . 'plex_pid';
        file_put_contents($rIdentifier, getmypid());
        cli_set_process_title('XC_VM[Plex Sync]');
        $rScanOffset = (intval(CoreUtilities::$rSettings['scan_seconds']) ?: 3600);
        set_time_limit(0);
        if (!empty(CoreUtilities::$rSettings['tmdb_api_key'])) {
            PlexCron::run();
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
    if (is_object($db)) {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
