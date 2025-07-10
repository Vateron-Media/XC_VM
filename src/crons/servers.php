<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Servers]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function pingServer($rIP, $rPort) {
    $rStartTime = microtime(true);
    $rSocket = fsockopen($rIP, $rPort, $rErrNo, $rErrStr, 3);
    $rStopTime = microtime(true);
    if (!$rSocket) {
        $rStatus = -1;
    } else {
        fclose($rSocket);
        $rStatus = floor(($rStopTime - $rStartTime) * 1000);
    }
    return $rStatus;
}
function loadCron() {
    global $db;
    CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
    if (CoreUtilities::isRunning()) {
        $rServers = CoreUtilities::getServers(true);
        if ($rServers[SERVER_ID]['is_main'] && CoreUtilities::$rSettings['redis_handler']) {
            exec('pgrep -u xc_vm redis-server', $rRedis);
            if (count($rRedis) == 0) {
                echo 'Restarting Redis!' . "\n";
                shell_exec(MAIN_HOME . 'bin/redis/redis-server ' . MAIN_HOME . '/bin/redis/redis.conf > /dev/null 2>/dev/null &');
            }
        }
        $rSignals = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep signals | grep -v grep | grep -v pgrep | wc -l')));
        if ($rSignals == 0) {
            shell_exec(PHP_BIN . ' ' . CLI_PATH . 'signals.php > /dev/null 2>/dev/null &');
        }
        if ($rServers[SERVER_ID]['is_main']) {
            $rCache = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep cache_handler | grep -v grep | grep -v pgrep | wc -l')));
            if (CoreUtilities::$rSettings['enable_cache'] && $rCache == 0) {
                shell_exec(PHP_BIN . ' ' . CLI_PATH . 'cache_handler.php > /dev/null 2>/dev/null &');
            } else {
                if (CoreUtilities::$rSettings['enable_cache'] || 0 >= $rCache) {
                } else {
                    echo 'Killing Cache Handler' . "\n";
                    exec("pgrep -U xc_vm | xargs ps | grep cache_handler | awk '{print \$1}'", $rPIDs);
                    foreach ($rPIDs as $rPID) {
                        if (0 >= intval($rPID)) {
                        } else {
                            shell_exec('kill -9 ' . intval($rPID));
                        }
                    }
                }
            }
        }
        $rNetwork = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep network | grep -v grep | grep -v pgrep | wc -l')));
        if ($rNetwork == 0) {
            shell_exec(BIN_PATH . 'network > /dev/null 2>/dev/null &');
        }
        $rWatchdog = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep watchdog | grep -v grep | grep -v pgrep | wc -l')));
        if ($rWatchdog == 0) {
            shell_exec(PHP_BIN . ' ' . CLI_PATH . 'watchdog.php > /dev/null 2>/dev/null &');
        }
        $rQueue = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep queue | grep -v grep | grep -v pgrep | wc -l')));
        if ($rQueue != 0) {
        } else {
            shell_exec(PHP_BIN . ' ' . CLI_PATH . 'queue.php > /dev/null 2>/dev/null &');
        }
        $rOnDemand = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep ondemand | grep -v grep | grep -v pgrep | wc -l')));
        if (CoreUtilities::$rSettings['on_demand_instant_off'] && $rOnDemand == 0) {
            shell_exec(PHP_BIN . ' ' . CLI_PATH . 'ondemand.php > /dev/null 2>/dev/null &');
        } else {
            if (CoreUtilities::$rSettings['on_demand_instant_off'] || 0 >= $rOnDemand) {
            } else {
                echo 'Killing On-Demand Instant-Off' . "\n";
                exec("pgrep -U xc_vm | xargs ps | grep ondemand | awk '{print \$1}'", $rPIDs);
                foreach ($rPIDs as $rPID) {
                    if (0 >= intval($rPID)) {
                    } else {
                        shell_exec('kill -9 ' . intval($rPID));
                    }
                }
            }
        }
        $rScanner = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep scanner | grep -v grep | grep -v pgrep | wc -l')));
        if (CoreUtilities::$rSettings['on_demand_checker'] && $rScanner == 0) {
            shell_exec(PHP_BIN . ' ' . CLI_PATH . 'scanner.php > /dev/null 2>/dev/null &');
        } else {
            if (CoreUtilities::$rSettings['on_demand_checker'] || 0 >= $rScanner) {
            } else {
                echo 'Killing On-Demand Scanner' . "\n";
                exec("pgrep -U xc_vm | xargs ps | grep scanner | awk '{print \$1}'", $rPIDs);
                foreach ($rPIDs as $rPID) {
                    if (0 >= intval($rPID)) {
                    } else {
                        shell_exec('kill -9 ' . intval($rPID));
                    }
                }
            }
        }
        $rStats = CoreUtilities::getStats();
        $rWatchdog = json_decode($rServers[SERVER_ID]['watchdog_data'], true);
        $rCPUAverage = ($rWatchdog['cpu_average_array'] ?: array());
        if (0 >= count($rCPUAverage)) {
        } else {
            $rStats['cpu'] = round(array_sum($rCPUAverage) / count($rCPUAverage), 2);
        }
        $rHardware = array('total_ram' => $rStats['total_mem'], 'total_used' => $rStats['total_mem_used'], 'cores' => $rStats['cpu_cores'], 'threads' => $rStats['cpu_cores'], 'kernel' => $rStats['kernel'], 'total_running_streams' => $rStats['total_running_streams'], 'cpu_name' => $rStats['cpu_name'], 'cpu_usage' => $rStats['cpu'], 'network_speed' => $rStats['network_speed'], 'bytes_sent' => $rStats['bytes_sent'], 'bytes_received' => $rStats['bytes_received']);
        if (fsockopen($rServers[SERVER_ID]['server_ip'], $rServers[SERVER_ID]['http_broadcast_port'], $rErrNo, $rErrStr, 3) || fsockopen($rServers[SERVER_ID]['server_ip'], $rServers[SERVER_ID]['https_broadcast_port'], $rErrNo, $rErrStr, 3)) {
            $rRemoteStatus = true;
        } else {
            $rRemoteStatus = false;
        }
        if (CoreUtilities::$rSettings['redis_handler']) {
            $rConnections = $rServers[SERVER_ID]['connections'];
            $rUsers = $rServers[SERVER_ID]['users'];
            $rAllUsers = 0;
            foreach (array_keys($rServers) as $rServerID) {
                if ($rServers[$rServerID]['server_online']) {
                    $rAllUsers += $rServers[$rServerID]['users'];
                }
            }
        } else {
            $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', SERVER_ID);
            $rConnections = intval($db->get_row()['count']);
            $db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', SERVER_ID);
            $rUsers = intval($db->num_rows());
            $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
            $rAllUsers = intval($db->num_rows());
        }
        $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', SERVER_ID);
        $rStreams = intval($db->get_row()['count']);
        $rPing = 0;
        if (!$rServers[SERVER_ID]['is_main']) {
            $rMainID = null;
            foreach ($rServers as $rServerID => $rServerArray) {
                if ($rServerArray['is_main']) {
                    $rMainID = $rServerID;
                    break;
                }
            }
            if ($rMainID) {
                $rPing = pingserver($rServers[$rMainID]['server_ip'], $rServers[$rMainID]['http_broadcast_port']);
            }
        }
        $rSysCtl = file_get_contents('/etc/sysctl.conf');
        $rGovernors = array();
        $rGovernor = null;
        if (!shell_exec('which cpufreq-info')) {
        } else {
            $rGovernors = array_filter(explode(' ', trim(shell_exec('cpufreq-info -g'))));
            $rGovernor = explode(' ', trim(shell_exec('cpufreq-info -p')));
        }
        $rAddresses = array_values(array_unique(array_map('trim', explode("\n", shell_exec("ip -4 addr | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'")))));
        $db->query('INSERT INTO `servers_stats`(`server_id`, `connections`, `total_users`, `users`, `streams`, `cpu`, `cpu_cores`, `cpu_avg`, `total_mem`, `total_mem_free`, `total_mem_used`, `total_mem_used_percent`, `total_disk_space`, `uptime`, `total_running_streams`, `bytes_sent`, `bytes_received`, `bytes_sent_total`, `bytes_received_total`, `cpu_load_average`, `gpu_info`, `iostat_info`, `time`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP());', SERVER_ID, $rConnections, $rAllUsers, $rUsers, $rStreams, $rStats['cpu'], $rStats['cpu_cores'], $rStats['cpu_avg'], $rStats['total_mem'], $rStats['total_mem_free'], $rStats['total_mem_used'], $rStats['total_mem_used_percent'], $rStats['total_disk_space'], $rStats['uptime'], $rStats['total_running_streams'], $rStats['bytes_sent'], $rStats['bytes_received'], $rStats['bytes_sent_total'], $rStats['bytes_received_total'], $rStats['cpu_load_average'], json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE), json_encode($rStats['iostat_info'], JSON_UNESCAPED_UNICODE));
        $db->query('UPDATE `servers` SET `remote_status` = ?, `xc_vm_version` = ?, `xc_vm_revision` = ?, `server_hardware` = ?,`whitelist_ips` = ?, `governors` = ?, `sysctl` = ?, `video_devices` = ?, `audio_devices` = ?, `gpu_info` = ?, `interfaces` = ?, `time_offset` = ' . intval(time()) . ' - UNIX_TIMESTAMP(), `ping` = ? WHERE `id` = ?', $rRemoteStatus, XC_VM_VERSION, XC_VM_REVISION, json_encode($rHardware, JSON_UNESCAPED_UNICODE), json_encode($rAddresses, JSON_UNESCAPED_UNICODE), json_encode($rGovernors, JSON_UNESCAPED_UNICODE), $rSysCtl, json_encode($rStats['video_devices'], JSON_UNESCAPED_UNICODE), json_encode($rStats['audio_devices'], JSON_UNESCAPED_UNICODE), json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE), json_encode($rStats['interfaces'], JSON_UNESCAPED_UNICODE), $rPing, SERVER_ID);
        if ($rServers[SERVER_ID]['is_main']) {
            foreach ($rServers as $rServerID => $rServerArray) {
                if ($rServerArray['server_online'] != $rServerArray['last_status']) {
                    $db->query('UPDATE `servers` SET `last_status` = ? WHERE `id` = ?;', $rServerArray['server_online'], $rServerID);
                }
            }
            $db->query('DELETE FROM `signals` WHERE `time` <= ?;', time() - 86400);
        }
    } else {
        echo 'XC_VM not running...' . "\n";
    }
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
