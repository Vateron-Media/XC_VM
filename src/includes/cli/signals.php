<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        shell_exec('kill -9 $(ps aux | grep signals | grep -v grep | grep -v ' . getmypid() . " | awk '{print \$2}')");
        $rLastCheck = null;
        $rInterval = 60;
        $rMD5 = md5_file(__FILE__);
        if (CoreUtilities::$rSettings['redis_handler']) {
            CoreUtilities::connectRedis();
        }
        while (true && $db && $db->ping()) {
            if (!$rLastCheck && $rInterval < time() - $rLastCheck) {
                if (CoreUtilities::isRunning()) {
                    if (md5_file(__FILE__) == $rMD5) {
                        CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
                        CoreUtilities::$rServers = CoreUtilities::getServers(true);
                        $rLastCheck = time();
                    } else {
                        echo 'File changed! Break.' . "\n";
                    }
                } else {
                    echo 'Not running! Break.' . "\n";
                }
            }
            if (!(CoreUtilities::$rSettings['redis_handler'] && (!CoreUtilities::$redis || !CoreUtilities::$redis->ping())) && $db->query('SELECT `signal_id`, `pid`, `rtmp` FROM `signals` WHERE `server_id` = ? AND `pid` IS NOT NULL ORDER BY `signal_id` ASC LIMIT 100', SERVER_ID)) {
                if ($db->num_rows() > 0) {
                    $rIDs = array();
                    foreach ($db->get_rows() as $rRow) {
                        $rIDs[] = $rRow['signal_id'];
                        $rPID = $rRow['pid'];
                        if ($rRow['rtmp'] == 0) {
                            if (!empty($rPID) && file_exists('/proc/' . $rPID) && is_numeric($rPID) && 0 < $rPID) {
                                shell_exec('kill -9 ' . intval($rPID));
                            }
                        } else {
                            shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . CoreUtilities::$rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rPID) . '" >/dev/null 2>/dev/null &');
                        }
                    }
                    if (count($rIDs) > 0) {
                        $db->query('DELETE FROM `signals` WHERE `signal_id` IN (' . implode(',', $rIDs) . ')');
                    }
                }
                if ($db->query('SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 ORDER BY `signal_id` ASC LIMIT 1000;', SERVER_ID)) {
                    if (0 >= $db->num_rows()) {
                    } else {
                        $rDeletedLines = $rUpdatedStreams = $rUpdatedLines = $rIDs = array();
                        foreach ($db->get_rows() as $rRow) {
                            $rCustomData = json_decode($rRow['custom_data'], true);
                            $rIDs[] = $rRow['signal_id'];
                            switch ($rCustomData['type']) {
                                case 'update_stream':
                                    if (in_array($rCustomData['id'], $rUpdatedStreams)) {
                                    } else {
                                        $rUpdatedStreams[] = $rCustomData['id'];
                                    }
                                    break;
                                case 'update_line':
                                    if (in_array($rCustomData['id'], $rUpdatedLines)) {
                                    } else {
                                        $rUpdatedLines[] = $rCustomData['id'];
                                    }
                                    break;
                                case 'update_streams':
                                    foreach ($rCustomData['id'] as $rID) {
                                        if (in_array($rID, $rUpdatedStreams)) {
                                        } else {
                                            $rUpdatedStreams[] = $rID;
                                        }
                                    }
                                    break;
                                case 'update_lines':
                                    foreach ($rCustomData['id'] as $rID) {
                                        if (in_array($rID, $rUpdatedLines)) {
                                        } else {
                                            $rUpdatedLines[] = $rID;
                                        }
                                    }
                                    break;
                                case 'delete_con':
                                    unlink(CONS_TMP_PATH . $rCustomData['uuid']);
                                    break;
                                case 'delete_vod':
                                    exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rCustomData['id']) . '.*');
                                    break;
                                case 'delete_vods':
                                    foreach ($rCustomData['id'] as $rID) {
                                        exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rID) . '.*');
                                    }
                                    break;
                            }
                        }
                        if (count($rUpdatedStreams) > 0) {
                            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "streams_update" "' . implode(',', $rUpdatedStreams) . '"');
                        }
                        if (0 >= count($rUpdatedLines)) {
                        } else {
                            shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "lines_update" "' . implode(',', $rUpdatedLines) . '"');
                        }
                        if (0 >= count($rIDs)) {
                        } else {
                            $db->query('DELETE FROM `signals` WHERE `signal_id` IN (' . implode(',', $rIDs) . ')');
                        }
                    }
                    if (CoreUtilities::$rSettings['redis_handler']) {
                        $rSignals = array();
                        foreach (CoreUtilities::$redis->sMembers('SIGNALS#' . SERVER_ID) as $rKey) {
                            $rSignals[] = $rKey;
                        }
                        if (0 >= count($rSignals)) {
                        } else {
                            $rSignalData = CoreUtilities::$redis->mGet($rSignals);
                            $rIDs = array();
                            foreach ($rSignalData as $rData) {
                                $rRow = igbinary_unserialize($rData);
                                $rIDs[] = $rRow['key'];
                                $rPID = $rRow['pid'];
                                if ($rRow['rtmp'] == 0) {
                                    if (!empty($rPID) && file_exists('/proc/' . $rPID) && is_numeric($rPID) && 0 < $rPID) {
                                        shell_exec('kill -9 ' . intval($rPID));
                                    }
                                } else {
                                    shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . CoreUtilities::$rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rPID) . '" >/dev/null 2>/dev/null &');
                                }
                            }
                            CoreUtilities::$redis->multi()->del($rIDs)->sRem('SIGNALS#' . SERVER_ID, ...$rSignals)->exec();
                        }
                    }
                    usleep(250000);
                }
                break;
            }
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
