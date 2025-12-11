<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        shell_exec('kill -9 $(ps aux | grep queue.php | grep -v grep | grep -v ' . getmypid() . " | awk '{print \$2}') > /dev/null 2>&1");
        $rLastCheck = null;
        $rInterval = 60;
        $rMD5 = md5_file(__FILE__);
        while (true && $db->ping()) {
            if ($rLastCheck && $rInterval > time() - $rLastCheck) {
            } else {
                if (md5_file(__FILE__) == $rMD5) {
                    CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
                    $rLastCheck = time();
                } else {
                    echo 'File changed! Break.' . "\n";
                }
            }
            if ($db->query("SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = 'movie' ORDER BY `added` ASC;", SERVER_ID)) {
                $rDelete = $rInProgress = array();
                if (0 >= $db->num_rows()) {
                } else {
                    foreach ($db->get_rows() as $rRow) {
                        if ($rRow['pid'] && (CoreUtilities::isProcessRunning($rRow['pid'], 'ffmpeg') || CoreUtilities::isProcessRunning($rRow['pid'], PHP_BIN))) {
                            $rInProgress[] = $rRow['pid'];
                        } else {
                            $rDelete[] = $rRow['id'];
                        }
                    }
                }
                $rFreeSlots = (0 < CoreUtilities::$rSettings['max_encode_movies'] ? intval(CoreUtilities::$rSettings['max_encode_movies']) - count($rInProgress) : 50);
                if (0 >= $rFreeSlots) {
                } else {
                    if ($db->query("SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = 'movie' ORDER BY `added` ASC LIMIT " . $rFreeSlots . ';', SERVER_ID)) {
                        if (0 >= $db->num_rows()) {
                        } else {
                            foreach ($db->get_rows() as $rRow) {
                                $rPID = CoreUtilities::startMovie($rRow['stream_id']);
                                if ($rPID) {
                                    $db->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ?;', $rPID, $rRow['id']);
                                } else {
                                    $rDelete[] = $rRow['id'];
                                }
                            }
                        }
                    }
                }
                if ($db->query("SELECT `id`, `pid` FROM `queue` WHERE `server_id` = ? AND `pid` IS NOT NULL AND `type` = 'channel' ORDER BY `added` ASC;", SERVER_ID)) {
                    $rInProgress = array();
                    if (0 >= $db->num_rows()) {
                    } else {
                        foreach ($db->get_rows() as $rRow) {
                            if ($rRow['pid'] && CoreUtilities::isProcessRunning($rRow['pid'], PHP_BIN)) {
                                $rInProgress[] = $rRow['pid'];
                            } else {
                                $rDelete[] = $rRow['id'];
                            }
                        }
                    }
                    $rFreeSlots = (0 < CoreUtilities::$rSettings['max_encode_cc'] ? intval(CoreUtilities::$rSettings['max_encode_cc']) - count($rInProgress) : 1);
                    if (0 >= $rFreeSlots) {
                    } else {
                        if ($db->query("SELECT `id`, `stream_id` FROM `queue` WHERE `server_id` = ? AND `pid` IS NULL AND `type` = 'channel' ORDER BY `added` ASC LIMIT " . $rFreeSlots . ';', SERVER_ID)) {
                            if (0 >= $db->num_rows()) {
                            } else {
                                foreach ($db->get_rows() as $rRow) {
                                    if (!file_exists(CREATED_PATH . $rRow['stream_id'] . '_.create')) {
                                    } else {
                                        unlink(CREATED_PATH . $rRow['stream_id'] . '_.create');
                                    }
                                    shell_exec(PHP_BIN . ' ' . CLI_PATH . 'created.php ' . intval($rRow['stream_id']) . ' >/dev/null 2>/dev/null &');
                                    $rPID = null;
                                    foreach (range(1, 3) as $i) {
                                        if (!file_exists(CREATED_PATH . $rRow['stream_id'] . '_.create')) {
                                            usleep(100000);
                                        } else {
                                            $rPID = intval(file_get_contents(CREATED_PATH . $rRow['stream_id'] . '_.create'));
                                            break;
                                        }
                                    }
                                    if ($rPID) {
                                        $db->query('UPDATE `queue` SET `pid` = ? WHERE `id` = ?;', $rPID, $rRow['id']);
                                    } else {
                                        $rDelete[] = $rRow['id'];
                                    }
                                }
                            }
                        }
                    }
                    if (0 >= count($rDelete)) {
                    } else {
                        $db->query('DELETE FROM `queue` WHERE `id` IN (' . implode(',', $rDelete) . ');');
                    }
                    sleep((0 < CoreUtilities::$rSettings['queue_loop'] ? intval(CoreUtilities::$rSettings['queue_loop']) : 5));
                }
                // break; // REMOVED BREAK TO ALLOW LOOPING
            }
        }
        if (!is_object($db)) {
        } else {
            $db->close_mysql();
        }
        // shell_exec('(sleep 1; ' . PHP_BIN . ' ' . __FILE__ . ' ) > /home/xc_vm/tmp/queue_restart.log 2>&1 &'); // REMOVED RESTART
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
