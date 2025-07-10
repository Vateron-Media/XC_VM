<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc > 1) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rStreamID = intval($argv[1]);
        checkRunning($rStreamID);
        set_time_limit(0);
        cli_set_process_title('TVArchive[' . $rStreamID . ']');
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadcli() {
    global $db;
    global $rStreamID;
    if (file_exists(ARCHIVE_PATH . $rStreamID)) {
    } else {
        mkdir(ARCHIVE_PATH . $rStreamID);
    }
    $rPID = (file_exists(STREAMS_PATH . $rStreamID . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid')) : 0);
    $rPlaylist = STREAMS_PATH . $rStreamID . '_.m3u8';
    if (0 >= $rPID) {
    } else {
        $db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.tv_archive_server_id WHERE t1.`id` = ? AND t1.`tv_archive_server_id` = ? AND t1.`tv_archive_duration` > 0', $rStreamID, SERVER_ID);
        if (0 >= $db->num_rows()) {
        } else {
            $rRow = $db->get_row();
            if (!CoreUtilities::isProcessRunning($rRow['tv_archive_pid'], PHP_BIN)) {
            } else {
                if (!(is_numeric($rRow['tv_archive_pid']) && 0 < $rRow['tv_archive_pid'])) {
                } else {
                    posix_kill($rRow['tv_archive_pid'], 9);
                }
            }
            if (!empty($rRow['pid'])) {
            } else {
                posix_kill(getmypid(), 9);
            }
            $db->query('UPDATE `streams` SET `tv_archive_pid` = ? WHERE `id` = ?', getmypid(), $rStreamID);
            CoreUtilities::updateStream($rStreamID);
            $db->close_mysql();
            while (CoreUtilities::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist)) {
                $rLastCheck = time();
                deleteSegments($rStreamID, $rRow['tv_archive_duration']);
                $rFileTime = gmdate('Y-m-d:H-i');
                $rFP = @fopen('http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/admin/live?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&stream=' . $rStreamID . '&extension=ts', 'r');
                if (!$rFP) {
                } else {
                    $rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
                    while (!feof($rFP)) {
                        if (3600 > time() - $rLastCheck) {
                        } else {
                            deleteSegments($rStreamID, $rRow['tv_archive_duration']);
                            $rLastCheck = time();
                        }
                        if (gmdate('Y-m-d:H-i') == $rFileTime) {
                        } else {
                            fclose($rWriteFile);
                            if (file_exists(ARCHIVE_PATH . $rStreamID)) {
                            } else {
                                mkdir(ARCHIVE_PATH . $rStreamID);
                            }
                            $rOffset = (CoreUtilities::findKeyframe(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts') ?: 0);
                            file_put_contents(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts.offset', $rOffset);
                            $rFileTime = gmdate('Y-m-d:H-i');
                            $rWriteFile = fopen(ARCHIVE_PATH . $rStreamID . '/' . $rFileTime . '.ts', 'a');
                        }
                        fwrite($rWriteFile, stream_get_line($rFP, 4096));
                        fflush($rWriteFile);
                    }
                    fclose($rFP);
                }
                sleep(1);
            }
        }
        exit();
    }
}
function deleteSegments($rStreamID, $rDuration) {
    $rSegmentCount = intval(count(scandir(ARCHIVE_PATH . $rStreamID . '/')) - 2);
    if ($rDuration * 24 * 60 >= $rSegmentCount) {
    } else {
        $rDelta = $rSegmentCount - $rDuration * 24 * 60;
        $rFiles = array_values(array_filter(explode("\n", shell_exec('ls -tr ' . ARCHIVE_PATH . intval($rStreamID) . " | sed -e 's/\\s\\+/\\n/g'"))));
        for ($i = 0; $i < $rDelta; $i++) {
            unlink(ARCHIVE_PATH . $rStreamID . '/' . $rFiles[$i]);
        }
    }
}
function checkRunning($rStreamID) {
    clearstatcache(true);
    if (!file_exists(STREAMS_PATH . $rStreamID . '_.archive')) {
    } else {
        $rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.archive'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'TVArchive\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'TVArchive[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
    file_put_contents(STREAMS_PATH . $rStreamID . '_.archive', getmypid());
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
