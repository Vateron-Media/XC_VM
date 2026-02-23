<?php
if ((posix_getpwuid(posix_geteuid())['name'] ?? null) === 'xc_vm') {
    set_time_limit(0);

    if (!empty($argc)) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        require_once MAIN_HOME . 'includes/libs/tmdb.php';
        require_once MAIN_HOME . 'modules/tmdb/TmdbPopularCron.php';
        cli_set_process_title('XC_VM[Popular]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        TmdbPopularCron::run();
    } else {
        exit(0);
    }
} else {
    exit("Please run as XC_VM!\n");
}

function shutdown(): void {
    global $db, $rIdentifier;
    if (isset($db) && is_object($db)) {
        $db->close_mysql();
    }
    if (!empty($rIdentifier) && file_exists($rIdentifier)) {
        @unlink($rIdentifier);
    }
}
