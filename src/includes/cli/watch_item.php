<?php
/**
 * Watch Item CLI — точка входа.
 *
 * Логика извлечена в modules/watch/WatchItem.php (Фаза 5.2).
 * Этот файл содержит только CLI-бутстрап и вызов модуля.
 */
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        $rTimeout = 60;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        require str_replace('\\', '/', dirname($argv[0])) . '/../../modules/watch/WatchItem.php';
        require INCLUDES_PATH . 'libs/tmdb.php';
        require INCLUDES_PATH . 'libs/tmdb_release.php';
        $rThreadData = json_decode(base64_decode($argv[1]), true);
        if ($rThreadData) {
            file_put_contents(WATCH_TMP_PATH . getmypid() . '.wpid', time());
            WatchItem::run();
        } else {
            exit();
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}

function shutdown() {
    global $db;
    global $rShowData;
    if (is_array($rShowData) && $rShowData['id'] && file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
        unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
    }
    if (is_object($db)) {
        $db->close_mysql();
    }
    @unlink(WATCH_TMP_PATH . @getmypid() . '.wpid');
}

