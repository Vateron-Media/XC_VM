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
                $ApiIP = json_decode(file_get_contents("https://raw.githubusercontent.com/Vateron-Media/XC_VM_Update/refs/heads/main/api_server.json"), true);
                $ApiURL = 'http://' . $ApiIP['ip'] . '/api/v1/update?version=' . XC_VM_VERSION;

                $UpdateData = json_decode(file_get_contents($ApiURL), true); // ["url": "link to archive", "md5": "hash of this archive"]
            } else {
                $rURL = null;
                foreach (CoreUtilities::$rServers as $rServer) {
                    if ($rServer['is_main']) {
                        $rURL = 'http://' . $rServer['server_ip'] . ':' . $rServer['http_broadcast_port'] . '/api?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&action=request_update&type=' . intval(CoreUtilities::$rServers[SERVER_ID]['server_type']);
                        break;
                    }
                }
                if ($rURL) {
                    $UpdateData = json_decode(file_get_contents($rURL), true);
                } else {
                    exit(0);
                }
            }
            if ($UpdateData && 0 < strlen($UpdateData['url'])) {
                $rData = fopen($UpdateData['url'], 'rb');
                $rOutputDir = TMP_PATH . '.update.tar.gz';
                $rOutput = fopen($rOutputDir, 'wb');
                stream_copy_to_stream($rData, $rOutput);
                fclose($rData);
                fclose($rOutput);
                if (md5_file($rOutputDir) == $UpdateData['md5']) {
                    $db->query('UPDATE `servers` SET `status` = 5 WHERE `id` = ?;', SERVER_ID);
                    $rCommand = 'sudo /usr/bin/python3 ' . MAIN_HOME . 'update "' . $rOutputDir . '" "' . $UpdateData['md5'] . '" > /dev/null 2>&1 &';
                    shell_exec($rCommand);
                    exit(1);
                }
                exit(-1);
            }
            exit(0);
        case 'post-update':
            if (CoreUtilities::$rServers[SERVER_ID]['is_main'] && CoreUtilities::$rSettings['auto_update_lbs']) {
                foreach (CoreUtilities::$rServers as $rServer) {
                    if (($rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180) || !$rServer['is_main']) {
                        $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
                    }
                }
            }
            $db->query('UPDATE `servers` SET `status` = 1, `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
            // if (!CoreUtilities::$rServers[SERVER_ID]['is_main']) {
            // Here we write the files that need to be deleted after the update
            if (file_exists(MAIN_HOME . 'bin/youtube')) {
                unlink(MAIN_HOME . 'bin/youtube');
            }
            // }
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
    if (is_object($db)) {
        $db->close_mysql();
    }
}
