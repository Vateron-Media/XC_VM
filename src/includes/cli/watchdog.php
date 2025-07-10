<?php
echo "Start watchdog\n";
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';

        $current_pid = getmypid();
        exec("pgrep -f 'watchdog.php' | grep -v $current_pid | xargs kill -9", $out, $kill_status);

        $rInterval = (intval(CoreUtilities::$rSettings['online_capacity_interval']) ?: 10);
        $rLastRequests = $rLastRequestsTime = $rPrevStat = $rLastCheck = null;
        $rMD5 = md5_file(__FILE__);
        if (CoreUtilities::$rSettings['redis_handler']) {
            CoreUtilities::connectRedis();
        }
        $rWatchdog = json_decode(CoreUtilities::$rServers[SERVER_ID]['watchdog_data'], true);
        $rCPUAverage = ($rWatchdog['cpu_average_array'] ?: array());
        while (true && $db && $db->ping()) {
            if (CoreUtilities::$rSettings['redis_handler'] ?? false) {
                if (!CoreUtilities::$redis || !CoreUtilities::$redis->ping()) {
                    echo "Redis connection lost\n";
                    break;
                }
            }

            if (!$rLastCheck && $rInterval < time() - $rLastCheck) {
                if (CoreUtilities::isRunning()) {
                    if (md5_file(__FILE__) == $rMD5) {
                        CoreUtilities::$rServers = CoreUtilities::getServers(true);
                        CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
                        CoreUtilities::getCapacity(true);
                        CoreUtilities::getCapacity(false);
                        $rLastCheck = time();
                        echo 'Set new time LastCheck' . "\n";
                    } else {
                        echo 'File changed! Break.' . "\n";
                    }
                } else {
                    echo 'Not running! Break.' . "\n";
                }
            }
            
            $rNginx = explode("\n", file_get_contents('http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/nginx_status'));
            list($rAccepted, $rHandled, $rRequests) = explode(' ', trim($rNginx[2]));
            $rRequestsPerSecond = ($rLastRequests ? intval(floatval($rRequests - $rLastRequests) / (time() - $rLastRequestsTime)) : 0);
            $rLastRequests = $rRequests;
            $rLastRequestsTime = time();
            $rStats = CoreUtilities::getStats();
            if (!$rPrevStat) {
                $rPrevStat = file('/proc/stat');
                sleep(2);
            }
            $rStat = file('/proc/stat');
            $rInfoA = explode(' ', preg_replace('!cpu +!', '', $rPrevStat[0]));
            $rInfoB = explode(' ', preg_replace('!cpu +!', '', $rStat[0]));
            $rPrevStat = $rStat;
            $rDiff = array();
            $rDiff['user'] = $rInfoB[0] - $rInfoA[0];
            $rDiff['nice'] = $rInfoB[1] - $rInfoA[1];
            $rDiff['sys'] = $rInfoB[2] - $rInfoA[2];
            $rDiff['idle'] = $rInfoB[3] - $rInfoA[3];
            $rTotal = array_sum($rDiff);
            $rCPU = array();
            foreach ($rDiff as $x => $y) {
                $rCPU[$x] = round($y / $rTotal * 100, 2);
            }
            $rStats['cpu'] = $rCPU['user'] + $rCPU['sys'];
            $rCPUAverage[] = $rStats['cpu'];
            if (30 >= count($rCPUAverage)) {
            } else {
                $rCPUAverage = array_slice($rCPUAverage, count($rCPUAverage) - 30, 30);
            }
            $rStats['cpu_average_array'] = $rCPUAverage;
            $rPHPPIDs = array();
            exec("ps -u xc_vm | grep php-fpm | awk {'print \$1'}", $rPHPPIDs);
            $rConnections = $rUsers = 0;
            if (!CoreUtilities::$rSettings['redis_handler']) {
                $db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ?;', SERVER_ID);
                $rConnections = $db->get_row()['count'];
                $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ? GROUP BY `user_id`;', SERVER_ID);
                $rUsers = $db->num_rows();
                $rResult = $db->query('UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = UNIX_TIMESTAMP(), `requests_per_second` = ?, `php_pids` = ?, `connections` = ?, `users` = ? WHERE `id` = ?;', json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rRequestsPerSecond, json_encode($rPHPPIDs), $rConnections, $rUsers, SERVER_ID);
            } else {
                $rResult = $db->query('UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = UNIX_TIMESTAMP(), `requests_per_second` = ?, `php_pids` = ? WHERE `id` = ?;', json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rRequestsPerSecond, json_encode($rPHPPIDs), SERVER_ID);
            }
            if ($rResult) {
                if (CoreUtilities::$rServers[SERVER_ID]['is_main']) {
                    if (CoreUtilities::$rSettings['redis_handler']) {
                        $rMulti = CoreUtilities::$redis->multi();
                        foreach (array_keys(CoreUtilities::$rServers) as $rServerID) {
                            if (!CoreUtilities::$rServers[$rServerID]['server_online']) {
                            } else {
                                $rMulti->zCard('SERVER#' . $rServerID);
                                $rMulti->zRangeByScore('SERVER_LINES#' . $rServerID, '-inf', '+inf', array('withscores' => true));
                            }
                        }
                        $rResults = $rMulti->exec();
                        $rTotalUsers = array();
                        $i = 0;
                        foreach (array_keys(CoreUtilities::$rServers) as $rServerID) {
                            if (CoreUtilities::$rServers[$rServerID]['server_online']) {
                                $db->query('UPDATE `servers` SET `connections` = ?, `users` = ? WHERE `id` = ?;', $rResults[$i * 2], count(array_unique(array_values($rResults[$i * 2 + 1]))), $rServerID);
                                $rTotalUsers = array_merge(array_values($rResults[$i * 2 + 1]), $rTotalUsers);
                                $i++;
                            }
                        }
                        $db->query('UPDATE `settings` SET `total_users` = ?;', count(array_unique($rTotalUsers)));
                    } else {
                        $db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
                        $rTotalUsers = $db->num_rows();
                        $db->query('UPDATE `settings` SET `total_users` = ?;', $rTotalUsers);
                    }
                }
                echo "Stats updated\n";
                sleep(2);
            } else {
                echo 'Failed, break.' . "\n";
            }
            break;
        }
        if (is_object($db)) {
            $db->close_mysql();
        }
        shell_exec('(sleep 1; ' . PHP_BIN . ' ' . __FILE__ . ' ) > /dev/null 2>/dev/null &');
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
