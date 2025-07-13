<?php
set_time_limit(0);
if ($argc && count($argv) == 2) {
    register_shutdown_function('shutdown');
    require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
    $rCommand = $argv[1];
    loadcli();
} else {
    exit(0);
}
function loadcli() {
    global $db;
    global $rCommand;
    switch ($rCommand) {
        case 'update':
            if (CoreUtilities::$rServers[SERVER_ID]['is_main']) {
                // если я правильно понял он делает запрос к api и получает ссылку на архив и его хеш сумму
                // $rUpdate = checkUpdate(XC_VM_VERSION);
                // $rUpdate = ["url": "ссылка на архив", "md5": "хеш сума этого архива"];
                $rUpdate = NULL;
            } else {
                $rVersion = null;
                $rURL = null;
                foreach (CoreUtilities::$rServers as $rServer) {
                    if (!$rServer['is_main']) {
                    } else {
                        $rURL = 'http://' . $rServer['server_ip'] . ':' . $rServer['http_broadcast_port'] . '/api?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&action=request_update&type=' . intval(CoreUtilities::$rServers[SERVER_ID]['server_type']);
                        $rVersion = $rServer['xc_vm_version'];
                        break;
                    }
                }
                if ($rURL) {
                    $rUpdate = json_decode(file_get_contents($rURL), true);
                } else {
                    exit(0);
                }
            }
            if ($rUpdate && 0 < strlen($rUpdate['url'])) {
                $rData = fopen($rUpdate['url'], 'rb');
                $rOutputDir = TMP_PATH . '.update.tar.gz';
                $rOutput = fopen($rOutputDir, 'wb');
                stream_copy_to_stream($rData, $rOutput);
                fclose($rData);
                fclose($rOutput);
                if (md5_file($rOutputDir) == $rUpdate['md5']) {
                    $db->query('UPDATE `servers` SET `status` = 5 WHERE `id` = ?;', SERVER_ID);
                    $rCommand = 'sudo /usr/bin/python3 ' . MAIN_HOME . 'update "' . $rOutputDir . '" "' . $rUpdate['md5'] . '" > /dev/null 2>&1 &';
                    shell_exec($rCommand);
                    exit(1);
                }
                exit(-1);
            }
            exit(0);
        case 'post-update':
            if (!(CoreUtilities::$rServers[SERVER_ID]['is_main'] && CoreUtilities::$rSettings['auto_update_lbs'])) {
            } else {
                foreach (CoreUtilities::$rServers as $rServer) {
                    if (!($rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180) || $rServer['is_main']) {
                    } else {
                        $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
                    }
                }
            }
            $db->query('UPDATE `servers` SET `status` = 1, `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
            if (!CoreUtilities::$rServers[SERVER_ID]['is_main']) {
                if (!file_exists('/etc/init.d/xc_vm')) {
                } else {
                    unlink('/etc/init.d/xc_vm');
                }
                if (!file_exists(MAIN_HOME . 'www/stream/auth.php')) {
                } else {
                    unlink(MAIN_HOME . 'www/stream/auth.php');
                }
                if (!file_exists(MAIN_HOME . 'includes/aes.php')) {
                } else {
                    unlink(MAIN_HOME . 'includes/aes.php');
                }
                if (!file_exists(MAIN_HOME . 'crons/status.php')) {
                } else {
                    unlink(MAIN_HOME . 'crons/status.php');
                }
                if (!file_exists(MAIN_HOME . 'includes/handler.php')) {
                } else {
                    unlink(MAIN_HOME . 'includes/handler.php');
                }
                if (!file_exists(MAIN_HOME . 'includes/nhandler.php')) {
                } else {
                    unlink(MAIN_HOME . 'includes/nhandler.php');
                }
                if (!file_exists(MAIN_HOME . 'includes/cli/closed_cons.php')) {
                } else {
                    unlink(MAIN_HOME . 'includes/cli/closed_cons.php');
                }
                if (!file_exists(MAIN_HOME . 'bin/ffmpeg')) {
                } else {
                    unlink(MAIN_HOME . 'bin/ffmpeg');
                }
                if (!file_exists(MAIN_HOME . 'bin/ffprobe')) {
                } else {
                    unlink(MAIN_HOME . 'bin/ffprobe');
                }
            }
            foreach (array('http', 'https') as $rType) {
                $rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/ports/' . $rType . '.conf');
                if (stripos($rPortConfig, ' reuseport') === false) {
                } else {
                    file_put_contents(MAIN_HOME . 'bin/nginx/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
                }
            }
            if (!file_exists(MAIN_HOME . 'bin/redis')) {
            } else {
                exec('rm -rf ' . MAIN_HOME . 'bin/redis');
            }
            exec('sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
            exec('sudo systemctl daemon-reload');
            exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
            exec('sudo ' . MAIN_HOME . 'status');
            break;
    }
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
