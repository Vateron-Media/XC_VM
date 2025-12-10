<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';

        // Kill old ondemand processes (except ourselves)
        shell_exec('kill -9 $(ps aux | grep -E "ondemand\.php" | grep -v grep | grep -v ' . getmypid() . ' | awk \'{print $2}\')');

        if (!CoreUtilities::$rSettings['on_demand_instant_off']) {
            echo 'On-Demand - Instant Off setting is disabled.' . "\n";
            exit();
        }

        // Connect Redis if enabled
        if (CoreUtilities::$rSettings['redis_handler']) {
            CoreUtilities::connectRedis();
        }

        $rMainID = CoreUtilities::getMainID();
        $rLastCheck = null;
        $rInterval = 60; // update settings once per minute
        $rMD5 = md5_file(__FILE__);

        while (true) {
            if (!$db || !$db->ping() || (CoreUtilities::$rSettings['redis_handler'] && CoreUtilities::$redis && !CoreUtilities::$redis->ping())) {
                break;
            }

            if (!$rLastCheck || time() - $rLastCheck > $rInterval || md5_file(__FILE__) !== $rMD5) {
                CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
                $rLastCheck = time();
                $rMD5 = md5_file(__FILE__);
            }

            $rRows = [];

            // === Getting active on-demand streams ===
            if (CoreUtilities::$rSettings['redis_handler'] && CoreUtilities::$redis) {
                // Redis mode
                $db->query("SELECT stream_id FROM streams_servers WHERE server_id = ? AND on_demand = 1 AND pid IS NOT NULL AND pid > 0", SERVER_ID);
                $rStreamIDs = $db->get_column();

                $rAttached = [];
                if (!empty($rStreamIDs)) {
                    $placeholders = str_repeat('?,', count($rStreamIDs) - 1) . '?';
                    $db->query("SELECT stream_id, COUNT(*) AS cnt FROM streams_servers WHERE parent_id = ? AND pid > 0 AND monitor_pid > 0 AND stream_id IN ($placeholders) GROUP BY stream_id", SERVER_ID, ...$rStreamIDs);
                    $rAttachedRows = $db->get_rows(true, 'stream_id');
                    foreach ($rAttachedRows as $id => $row) {
                        $rAttached[$id] = (int) $row['cnt'];
                    }
                }

                $rConnections = CoreUtilities::getStreamConnections($rStreamIDs, false, false);

                foreach ($rStreamIDs as $rStreamID) {
                    $rRows[] = [
                        'stream_id' => $rStreamID,
                        'online_clients' => count($rConnections[$rStreamID][SERVER_ID] ?? []),
                        'attached' => $rAttached[$rStreamID] ?? 0
                    ];
                }
            } else {
                // Without Redis
                $db->query("SELECT stream_id FROM streams_servers WHERE server_id = ? AND on_demand = 1 AND pid IS NOT NULL AND pid > 0", SERVER_ID);
                $rActive = $db->get_column();

                if (empty($rActive)) {
                    usleep(800000);
                    continue;
                }

                $placeholders = str_repeat('?,', count($rActive) - 1) . '?';

                // Online clients
                $online = [];
                $db->query("SELECT stream_id, COUNT(*) AS cnt FROM lines_live WHERE server_id = ? AND hls_end = 0 AND stream_id IN ($placeholders) GROUP BY stream_id", SERVER_ID, ...$rActive);
                $onlineRows = $db->get_rows(true, 'stream_id');
                foreach ($onlineRows as $id => $row) {
                    $online[$id] = (int) $row['cnt'];
                }

                // Attached servers
                $attached = [];
                $db->query("SELECT stream_id, COUNT(*) AS cnt FROM streams_servers WHERE parent_id = ? AND pid > 0 AND monitor_pid > 0 AND stream_id IN ($placeholders) GROUP BY stream_id", SERVER_ID, ...$rActive);
                $attachedRows = $db->get_rows(true, 'stream_id');
                foreach ($attachedRows as $id => $row) {
                    $attached[$id] = (int) $row['cnt'];
                }

                foreach ($rActive as $stream_id) {
                    $rRows[] = [
                        'stream_id' => $stream_id,
                        'online_clients' => $online[$stream_id] ?? 0,
                        'attached' => $attached[$stream_id] ?? 0
                    ];
                }
            }

            // === Killing unused streams ===
            foreach ($rRows as $rRow) {
                if ($rRow['online_clients'] > 0 || $rRow['attached'] > 0)
                    continue;

                $rStreamID = $rRow['stream_id'];
                $pidFile = STREAMS_PATH . $rStreamID . '_.pid';
                $monitorFile = STREAMS_PATH . $rStreamID . '_.monitor';

                if (!file_exists($pidFile))
                    continue;

                $rPID = (int) @file_get_contents($pidFile);
                $rMonitorPID = file_exists($monitorFile) ? (int) @file_get_contents($monitorFile) : 0;

                // Queue
                $rQueue = 0;
                $queueFile = SIGNALS_TMP_PATH . 'queue_' . $rStreamID;
                if (file_exists($queueFile)) {
                    $queue = @igbinary_unserialize(@file_get_contents($queueFile)) ?: [];
                    foreach ($queue as $pid) {
                        if (CoreUtilities::isProcessRunning($pid, 'php-fpm'))
                            $rQueue++;
                    }
                }

                $rAdminQueue = (file_exists(SIGNALS_TMP_PATH . 'admin_' . $rStreamID) && time() - @filemtime(SIGNALS_TMP_PATH . 'admin_' . $rStreamID) <= 30) ? 1 : 0;

                // Add grace period: don't kill stream if it started less than 30 seconds ago
                $rStreamAge = 0;
                if (file_exists($pidFile)) {
                    $rStreamAge = time() - @filemtime($pidFile);
                }

                if ($rQueue > 0 || $rAdminQueue > 0 || $rStreamAge < 30) {
                    continue;
                }

                echo "Killing a stream without viewers: ID $rStreamID\n";

                if ($rMonitorPID > 0)
                    @posix_kill($rMonitorPID, 9);
                if ($rPID > 0)
                    @posix_kill($rPID, 9);

                @shell_exec('rm -f ' . STREAMS_PATH . $rStreamID . '_*');
                @unlink($queueFile);
                @unlink(SIGNALS_TMP_PATH . 'admin_' . $rStreamID);

                $db->query("UPDATE streams_servers SET bitrate = NULL, current_source = NULL, to_analyze = 0, pid = NULL, stream_started = NULL, stream_info = NULL, audio_codec = NULL, video_codec = NULL, resolution = NULL, compatible = 0, stream_status = 0, monitor_pid = NULL WHERE stream_id = ? AND server_id = ?", $rStreamID, SERVER_ID);

                $db->query("INSERT INTO signals (server_id, cache, time, custom_data) VALUES (?, 1, ?, ?)", $rMainID, time(), json_encode(['type' => 'update_stream', 'id' => $rStreamID]));

                CoreUtilities::updateStream($rStreamID);
            }

            usleep(800000);
        }

        if (is_object($db))
            $db->close_mysql();
        shell_exec('(sleep 2; ' . PHP_BIN . ' ' . __FILE__ . ' ) > /dev/null 2>&1 &');
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
