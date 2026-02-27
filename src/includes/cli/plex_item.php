<?php

/**
 * Plex Item CLI — точка входа.
 *
 * Логика извлечена в modules/plex/PlexItem.php (Фаза 5.1).
 * Этот файл содержит только CLI-бутстрап и вызов модуля.
 */
setlocale(LC_ALL, 'en_US.UTF-8');
putenv('LC_ALL=en_US.UTF-8');

if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(30711);
        $rStreamDatabase = (json_decode(file_get_contents(WATCH_TMP_PATH . 'stream_database.pcache'), true) ?: array());
        $rThreadData = json_decode(base64_decode($argv[1]), true);

        if ($rThreadData) {
            file_put_contents(WATCH_TMP_PATH . getmypid() . '.ppid', time());

            if ($rThreadData['type'] == 'movie') {
                $rTimeout = 60;
            } else {
                $rTimeout = 600;
            }

            set_time_limit($rTimeout);
            ini_set('max_execution_time', $rTimeout);
            PlexItem::run();
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

    if (is_object($db)) {
        $db->close_mysql();
    }

    @unlink(WATCH_TMP_PATH . @getmypid() . '.ppid');
}
