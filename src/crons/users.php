<?php

// Checked

if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    set_time_limit(0);
    ini_set('memory_limit', -1);

    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Users]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rSync = null;

        if (count($argv) == 2 && CoreUtilities::$rServers[SERVER_ID]['is_main']) {
            CoreUtilities::connectRedis();

            if (is_object(CoreUtilities::$redis)) {
                $rSync = intval($argv[1]);

                if ($rSync == 1) {
                    $rDeSync = $rRedisUsers = $rRedisUpdate = $rRedisSet = array();
                    $db->query('SELECT * FROM `lines_live` WHERE `hls_end` = 0;');
                    $rRows = $db->get_rows();

                    if (count($rRows) > 0) {
                        $rStreamIDs = array();

                        foreach ($rRows as $rRow) {
                            $streamId = (int)$rRow['stream_id'];

                            if ($streamId > 0 && !in_array($streamId, $rStreamIDs)) {
                                $rStreamIDs[] = $streamId;
                            }
                        }
                        $rOnDemand = array();

                        if (count($rStreamIDs) > 0) {
                            $db->query('SELECT `stream_id`, `server_id`, `on_demand` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');

                            foreach ($db->get_rows() as $rRow) {
                                $rOnDemand[$rRow['stream_id']][$rRow['server_id']] = intval($rRow['on_demand']);
                            }
                        }

                        $rRedis = CoreUtilities::$redis->multi();

                        foreach ($rRows as $rRow) {
                            echo 'Resynchronising UUID: ' . $rRow['uuid'] . "\n";

                            if (empty($rRow['hmac_id'])) {
                                $rRow['identity'] = $rRow['user_id'];
                            } else {
                                $rRow['identity'] = $rRow['hmac_id'] . '_' . $rRow['hmac_identifier'];
                            }

                            $rRow['on_demand'] = ($rOnDemand[$rRow['stream_id']][$rRow['server_id']] ?: 0);
                            $rRedis->zAdd('LINE#' . $rRow['identity'], $rRow['date_start'], $rRow['uuid']);
                            $rRedis->zAdd('LINE_ALL#' . $rRow['identity'], $rRow['date_start'], $rRow['uuid']);
                            $rRedis->zAdd('STREAM#' . $rRow['stream_id'], $rRow['date_start'], $rRow['uuid']);
                            $rRedis->zAdd('SERVER#' . $rRow['server_id'], $rRow['date_start'], $rRow['uuid']);

                            if ($rRow['user_id']) {
                                $rRedis->zAdd('SERVER_LINES#' . $rRow['server_id'], $rRow['user_id'], $rRow['uuid']);
                            }

                            if ($rRow['proxy_id']) {
                                $rRedis->zAdd('PROXY#' . $rRow['proxy_id'], $rRow['date_start'], $rRow['uuid']);
                            }

                            $rRedis->zAdd('CONNECTIONS', $rRow['date_start'], $rRow['uuid']);
                            $rRedis->zAdd('LIVE', $rRow['date_start'], $rRow['uuid']);
                            $rRedis->set($rRow['uuid'], igbinary_serialize($rRow));
                            $rDeSync[] = $rRow['uuid'];
                        }
                        $rRedis->exec();

                        if (count($rDeSync) > 0) {
                            $db->query("DELETE FROM `lines_live` WHERE `uuid` IN ('" . implode("','", $rDeSync) . "');");
                        }
                    }
                }
            } else {
                exit("Couldn't connect to Redis." . "\n");
            }
        }

        if (CoreUtilities::$rSettings['redis_handler'] && CoreUtilities::$rServers[SERVER_ID]['is_main']) {
            CoreUtilities::$rServers = CoreUtilities::getServers(true);
            $rPHPPIDs = array();

            foreach (CoreUtilities::$rServers as $rServer) {
                $rPHPPIDs[$rServer['id']] = (array_map('intval', json_decode($rServer['php_pids'], true)) ?: array());
            }
        }

        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}

function processDeletions($rDelete, $rDelStream = array()) {
    global $db;
    $rTime = time();

    if (CoreUtilities::$rSettings['redis_handler']) {
        if ($rDelete['count'] > 0) {
            $rRedis = CoreUtilities::$redis->multi();

            foreach ($rDelete['line'] as $rUserID => $rUUIDs) {
                $rRedis->zRem('LINE#' . $rUserID, ...$rUUIDs);
                $rRedis->zRem('LINE_ALL#' . $rUserID, ...$rUUIDs);
            }

            foreach ($rDelete['stream'] as $rStreamID => $rUUIDs) {
                $rRedis->zRem('STREAM#' . $rStreamID, ...$rUUIDs);
            }

            foreach ($rDelete['server'] as $rServerID => $rUUIDs) {
                $rRedis->zRem('SERVER#' . $rServerID, ...$rUUIDs);
                $rRedis->zRem('SERVER_LINES#' . $rServerID, ...$rUUIDs);
            }

            foreach ($rDelete['proxy'] as $rProxyID => $rUUIDs) {
                $rRedis->zRem('PROXY#' . $rProxyID, ...$rUUIDs);
            }

            if (count($rDelete['uuid']) > 0) {
                $rRedis->zRem('CONNECTIONS', ...$rDelete['uuid']);
                $rRedis->zRem('LIVE', ...$rDelete['uuid']);
                $rRedis->sRem('ENDED', ...$rDelete['uuid']);
                $rRedis->del(...$rDelete['uuid']);
            }

            $rRedis->exec();
        }
    } else {
        foreach ($rDelete as $rServerID => $rConnections) {
            if (count($rConnections) > 0) {
                $db->query("DELETE FROM `lines_live` WHERE `uuid` IN ('" . implode("','", $rConnections) . "')");
            }
        }
    }

    foreach ((CoreUtilities::$rSettings['redis_handler'] ? $rDelete['server'] : $rDelete) as $rServerID => $rConnections) {
        if ($rServerID != SERVER_ID) {
            $rQuery = '';

            foreach ($rConnections as $rConnection) {
                $rQuery .= '(' . $rServerID . ',1,' . $rTime . ',' . $db->escape(json_encode(array('type' => 'delete_con', 'uuid' => $rConnection))) . '),';
            }
            $rQuery = rtrim($rQuery, ',');

            if (!empty($rQuery)) {
                $db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES ' . $rQuery . ';');
            }
        }
    }

    foreach ($rDelStream as $rStreamID => $rConnections) {
        foreach ($rConnections as $rConnection) {
            @unlink(CONS_TMP_PATH . $rStreamID . '/' . $rConnection);
        }
    }

    if (CoreUtilities::$rSettings['redis_handler']) {
        return array('line' => array(), 'server' => array(), 'server_lines' => array(), 'proxy' => array(), 'stream' => array(), 'uuid' => array(), 'count' => 0);
    }

    return array();
}

function loadCron() {
    global $db;
    global $rPHPPIDs;

    if (CoreUtilities::$rSettings['redis_handler']) {
        CoreUtilities::connectRedis();
    }

    $rStartTime = time();

    if (!CoreUtilities::$rSettings['redis_handler'] || CoreUtilities::$rServers[SERVER_ID]['is_main']) {
        $rAutoKick = CoreUtilities::$rSettings['user_auto_kick_hours'] * 3600;
        $rLiveKeys = $rDelete = $rDeleteStream = array();

        if (CoreUtilities::$rSettings['redis_handler']) {
            $rRedisDelete = array('line' => array(), 'server' => array(), 'server_lines' => array(), 'proxy' => array(), 'stream' => array(), 'uuid' => array(), 'count' => 0);
            $rUsers = array();
            list($rKeys, $rConnections) = CoreUtilities::getConnections();
            $i = 0;

            for ($rSize = count($rConnections); $i < $rSize; $i++) {
                $rConnection = $rConnections[$i];

                if (is_array($rConnection)) {
                    $rUsers[$rConnection['identity']][] = $rConnection;
                    $rLiveKeys[] = $rConnection['uuid'];
                } else {
                    $rRedisDelete['count']++;
                    $rRedisDelete['uuid'][] = $rKeys[$i];
                }
            }
            unset($rConnections);
        } else {
            $rUsers = CoreUtilities::getConnections((CoreUtilities::$rServers[SERVER_ID]['is_main'] ? null : SERVER_ID));
        }

        $rRestreamerArray = $rMaxConnectionsArray = array();
        $rUserIDs = CoreUtilities::confirmIDs(array_keys($rUsers));

        if (count($rUserIDs) > 0) {
            $db->query('SELECT `id`, `max_connections`, `is_restreamer` FROM `lines` WHERE `id` IN (' . implode(',', $rUserIDs) . ');');

            foreach ($db->get_rows() as $rRow) {
                $rMaxConnectionsArray[$rRow['id']] = $rRow['max_connections'];
                $rRestreamerArray[$rRow['id']] = $rRow['is_restreamer'];
            }
        }

        if (CoreUtilities::$rSettings['redis_handler'] && CoreUtilities::$rServers[SERVER_ID]['is_main']) {
            foreach (CoreUtilities::getEnded() as $rConnection) {
                if (is_array($rConnection)) {
                    if (!in_array($rConnection['container'], array('ts', 'hls', 'rtmp')) && time() - $rConnection['hls_last_read'] < 300) {
                        $rClose = false;
                    } else {
                        $rClose = true;
                    }

                    if ($rClose) {
                        echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                        CoreUtilities::closeConnection($rConnection, false, false);
                        $rRedisDelete['count']++;
                        $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                        $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                        $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                        $rRedisDelete['uuid'][] = $rConnection['uuid'];

                        if ($rConnection['proxy_id']) {
                            $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                        }
                    }
                }
            }

            if ($rRedisDelete['count'] >= 1000) {
                $rRedisDelete = processdeletions($rRedisDelete, $rRedisDelete['stream']);
            }
        }

        foreach ($rUsers as $rUserID => $rConnections) {
            $rActiveCount = 0;
            $rMaxConnections = $rMaxConnectionsArray[$rUserID];
            $rIsRestreamer = ($rRestreamerArray[$rUserID] ?: false);

            foreach ($rConnections as $rKey => $rConnection) {
                if ($rConnection['server_id'] == SERVER_ID || CoreUtilities::$rSettings['redis_handler']) {
                    if (is_null($rConnection['exp_date']) || $rConnection['exp_date'] >= $rStartTime) {
                        $rTotalTime = $rStartTime - $rConnection['date_start'];

                        if (!($rAutoKick != 0 && $rAutoKick <= $rTotalTime) || $rIsRestreamer) {
                            if ($rConnection['container'] == 'hls') {
                                if (30 <= $rStartTime - $rConnection['hls_last_read'] || $rConnection['hls_end'] == 1) {
                                    echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                                    CoreUtilities::closeConnection($rConnection, false, false);

                                    if (CoreUtilities::$rSettings['redis_handler']) {
                                        $rRedisDelete['count']++;
                                        $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                                        $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                                        $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                                        $rRedisDelete['uuid'][] = $rConnection['uuid'];

                                        if ($rConnection['user_id']) {
                                            $rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
                                        }

                                        if ($rConnection['proxy_id']) {
                                            $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                                        }
                                    } else {
                                        $rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
                                        $rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
                                    }
                                }
                            } else {
                                if ($rConnection['container'] != 'rtmp') {
                                    if ($rConnection['server_id'] == SERVER_ID) {
                                        $rIsRunning = CoreUtilities::isProcessRunning($rConnection['pid'], 'php-fpm');
                                    } else {
                                        if ($rConnection['date_start'] <= CoreUtilities::$rServers[$rConnection['server_id']]['last_check_ago'] - 1 && 0 < count($rPHPPIDs[$rConnection['server_id']])) {
                                            $rIsRunning = in_array(intval($rConnection['pid']), $rPHPPIDs[$rConnection['server_id']]);
                                        } else {
                                            $rIsRunning = true;
                                        }
                                    }

                                    if (($rConnection['hls_end'] == 1 && ($rStartTime - $rConnection['hls_last_read']) >= 300) || !$rIsRunning) {
                                        echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                                        CoreUtilities::closeConnection($rConnection, false, false);

                                        if (CoreUtilities::$rSettings['redis_handler']) {
                                            $rRedisDelete['count']++;
                                            $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                                            $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                                            $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                                            $rRedisDelete['uuid'][] = $rConnection['uuid'];

                                            if ($rConnection['user_id']) {
                                                $rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
                                            }

                                            if ($rConnection['proxy_id']) {
                                                $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                                            }
                                        } else {
                                            $rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
                                            $rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
                                        }
                                    }
                                }
                            }
                        } else {
                            echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                            CoreUtilities::closeConnection($rConnection, false, false);

                            if (CoreUtilities::$rSettings['redis_handler']) {
                                $rRedisDelete['count']++;
                                $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                                $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                                $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                                $rRedisDelete['uuid'][] = $rConnection['uuid'];

                                if ($rConnection['user_id']) {
                                    $rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
                                }

                                if ($rConnection['proxy_id']) {
                                    $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                                }
                            } else {
                                $rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
                                $rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
                            }
                        }
                    } else {
                        echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                        CoreUtilities::closeConnection($rConnection, false, false);

                        if (CoreUtilities::$rSettings['redis_handler']) {
                            $rRedisDelete['count']++;
                            $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                            $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                            $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                            $rRedisDelete['uuid'][] = $rConnection['uuid'];

                            if ($rConnection['user_id']) {
                                $rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
                            }

                            if ($rConnection['proxy_id']) {
                                $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                            }
                        } else {
                            $rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
                            $rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
                        }
                    }
                }

                if (!$rConnection['hls_end']) {
                    $rActiveCount++;
                }
            }

            if (CoreUtilities::$rServers[SERVER_ID]['is_main'] && 0 < $rMaxConnections && $rMaxConnections < $rActiveCount) {
                foreach ($rConnections as $rConnection) {
                    if (!$rConnection['hls_end']) {
                        echo 'Close connection: ' . $rConnection['uuid'] . "\n";
                        CoreUtilities::closeConnection($rConnection, false, false);

                        if (CoreUtilities::$rSettings['redis_handler']) {
                            $rRedisDelete['count']++;
                            $rRedisDelete['line'][$rConnection['identity']][] = $rConnection['uuid'];
                            $rRedisDelete['stream'][$rConnection['stream_id']][] = $rConnection['uuid'];
                            $rRedisDelete['server'][$rConnection['server_id']][] = $rConnection['uuid'];
                            $rRedisDelete['uuid'][] = $rConnection['uuid'];

                            if ($rConnection['user_id']) {
                                $rRedisDelete['server_lines'][$rConnection['server_id']][] = $rConnection['uuid'];
                            }

                            if ($rConnection['proxy_id']) {
                                $rRedisDelete['proxy'][$rConnection['proxy_id']][] = $rConnection['uuid'];
                            }
                        } else {
                            $rDelete[$rConnection['server_id']][] = $rConnection['uuid'];
                            $rDeleteStream[$rConnection['stream_id']] = $rDelete[$rConnection['server_id']];
                        }

                        $rActiveCount--;
                    }

                    if ($rActiveCount >= $rMaxConnections) {
                        break;
                    }
                }
            }

            if (CoreUtilities::$rSettings['redis_handler'] && 1000 <= $rRedisDelete['count']) {
                $rRedisDelete = processdeletions($rRedisDelete, $rRedisDelete['stream']);
            } else {
                if (!CoreUtilities::$rSettings['redis_handler'] && count($rDelete) >= 1000) {
                    $rDelete = processdeletions($rDelete, $rDeleteStream);
                }
            }
        }

        if (CoreUtilities::$rSettings['redis_handler'] && 0 < $rRedisDelete['count']) {
            processdeletions($rRedisDelete, $rRedisDelete['stream']);
        } else {
            if (!CoreUtilities::$rSettings['redis_handler'] && count($rDelete) > 0) {
                processdeletions($rDelete, $rDeleteStream);
            }
        }
    }

    $rConnectionSpeeds = glob(DIVERGENCE_TMP_PATH . '*');

    if (count($rConnectionSpeeds) > 0) {
        if (CoreUtilities::$rSettings['redis_handler']) {
            $rStreamMap = $rBitrates = array();
            $db->query('SELECT `stream_id`, `bitrate` FROM `streams_servers` WHERE `server_id` = ? AND `bitrate` IS NOT NULL;', SERVER_ID);

            foreach ($db->get_rows() as $rRow) {
                $rStreamMap[intval($rRow['stream_id'])] = intval($rRow['bitrate'] / 8 * 0.92);
            }
            $rUUIDs = array();

            foreach ($rConnectionSpeeds as $rConnectionSpeed) {
                if (!empty($rConnectionSpeed)) {
                    $rUUIDs[] = basename($rConnectionSpeed);
                }
            }

            if (count($rUUIDs) > 0) {
                $rConnections = array_map('igbinary_unserialize', CoreUtilities::$redis->mGet($rUUIDs));

                foreach ($rConnections as $rConnection) {
                    if (is_array($rConnection)) {
                        $rBitrates[$rConnection['uuid']] = $rStreamMap[intval($rConnection['stream_id'])];
                    }
                }
            }

            unset($rStreamMap);
        } else {
            $rBitrates = array();
            $db->query('SELECT `lines_live`.`uuid`, `streams_servers`.`bitrate` FROM `lines_live` LEFT JOIN `streams_servers` ON `lines_live`.`stream_id` = `streams_servers`.`stream_id` AND `lines_live`.`server_id` = `streams_servers`.`server_id` WHERE `lines_live`.`server_id` = ?;', SERVER_ID);

            foreach ($db->get_rows() as $rRow) {
                $rBitrates[$rRow['uuid']] = intval($rRow['bitrate'] / 8 * 0.92);
            }
        }

        if (!CoreUtilities::$rSettings['redis_handler']) {
            $rUUIDMap = array();
            $db->query('SELECT `uuid`, `activity_id` FROM `lines_live`;');

            foreach ($db->get_rows() as $rRow) {
                $rUUIDMap[$rRow['uuid']] = $rRow['activity_id'];
            }
        }

        $rLiveQuery = $rDivergenceUpdate = array();

        foreach ($rConnectionSpeeds as $rConnectionSpeed) {
            if (!empty($rConnectionSpeed)) {
                $rUUID = basename($rConnectionSpeed);
                $rAverageSpeed = intval(file_get_contents($rConnectionSpeed));
                $rDivergence = intval(($rAverageSpeed - $rBitrates[$rUUID]) / $rBitrates[$rUUID] * 100);

                if ($rDivergence > 0) {
                    $rDivergence = 0;
                }

                $rDivergenceUpdate[] = "('" . $rUUID . "', " . abs($rDivergence) . ')';

                if (!CoreUtilities::$rSettings['redis_handler'] && isset($rUUIDMap[$rUUID])) {
                    $rLiveQuery[] = '(' . $rUUIDMap[$rUUID] . ', ' . abs($rDivergence) . ')';
                }
            }
        }

        if (count($rDivergenceUpdate) > 0) {
            $rUpdateQuery = implode(',', $rDivergenceUpdate);
            $db->query('INSERT INTO `lines_divergence`(`uuid`,`divergence`) VALUES ' . $rUpdateQuery . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
        }

        if (!CoreUtilities::$rSettings['redis_handler'] && count($rLiveQuery) > 0) {
            $rLiveQueryStr = implode(',', $rLiveQuery);
            $db->query('INSERT INTO `lines_live`(`activity_id`,`divergence`) VALUES ' . $rLiveQueryStr . ' ON DUPLICATE KEY UPDATE `divergence`=VALUES(`divergence`);');
        }


        shell_exec('rm -f ' . DIVERGENCE_TMP_PATH . '*');
    }

    if (CoreUtilities::$rServers[SERVER_ID]['is_main']) {
        if (CoreUtilities::$rSettings['redis_handler']) {
            $db->query("DELETE FROM `lines_divergence` WHERE `uuid` NOT IN ('" . implode("','", $rLiveKeys) . "');");
        } else {
            $db->query('DELETE FROM `lines_divergence` WHERE `uuid` NOT IN (SELECT `uuid` FROM `lines_live`);');
        }
    }

    if (CoreUtilities::$rServers[SERVER_ID]['is_main']) {
        $db->query('DELETE FROM `lines_live` WHERE `uuid` IS NULL;');
    }
}

function shutdown() {
    global $db;
    global $rIdentifier;

    if (is_object($db)) {
        $db->close_mysql();
    }

    @unlink($rIdentifier);
}
