<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc > 1) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rStreamID = intval($argv[1]);
        checkRunning($rStreamID);
        set_time_limit(0);
        cli_set_process_title('XC_VMCreate[' . $rStreamID . ']');
        $db->query('SELECT * FROM `streams` t1 LEFT JOIN `profiles` t3 ON t1.transcode_profile_id = t3.profile_id WHERE t1.`id` = ?', $rStreamID);
        if ($db->num_rows() != 0) {
            $rStreamInfo = $db->get_row();
            $db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ? AND `parent_id` IS NULL', $rStreamID, SERVER_ID);
            if ($db->num_rows() != 0) {
                $rServerInfo = $db->get_row();
                $rStreamInfo['stream_source'] = json_decode($rStreamInfo['stream_source'], true);
                $rServerInfo['cchannel_rsources'] = json_decode($rServerInfo['cchannel_rsources'], true);
                if ($rServerInfo['cchannel_rsources']) {
                } else {
                    $rServerInfo['cchannel_rsources'] = array();
                }
                $rSourcesLeft = array_diff($rStreamInfo['stream_source'], $rServerInfo['cchannel_rsources']);
                if (empty($rSourcesLeft) && $rStreamInfo['stream_source'] === $rServerInfo['cchannel_rsources']) {
                } else {
                    foreach ($rSourcesLeft as $rSource) {
                        $rMD5 = md5($rSource);
                        if (!file_exists(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid')) {
                        } else {
                            $rCurrentPID = intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));
                            if (!CoreUtilities::isPIDRunning(SERVER_ID, $rCurrentPID, CoreUtilities::$rFFMPEG_CPU)) {
                            } else {
                                exec('kill -9 ' . $rCurrentPID);
                            }
                        }
                        echo 'Processing source: ' . $rSource . '...' . "\n";
                        $rItemPID = CoreUtilities::createChannelItem($rStreamID, $rSource);
                        $db->close_mysql();
                        while (CoreUtilities::isPIDRunning(SERVER_ID, $rItemPID, CoreUtilities::$rFFMPEG_CPU)) {
                            sleep(1);
                        }
                        $rServerInfo['cchannel_rsources'][] = $rSource;
                        $db->db_connect();
                        $db->query('UPDATE `streams_servers` SET `cchannel_rsources` = ? WHERE `server_stream_id` = ?', json_encode($rServerInfo['cchannel_rsources']), $rServerInfo['server_stream_id']);
                        unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid');
                        unlink(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors');
                    }
                    $rOutputList = '';
                    foreach ($rStreamInfo['stream_source'] as $rSource) {
                        if (substr($rSource, 0, 2) == 's:') {
                            $rSplit = explode(':', $rSource, 3);
                            $rServerID = intval($rSplit[1]);
                        } else {
                            $rServerID = SERVER_ID;
                        }
                        if ($rServerID == SERVER_ID && $rStreamInfo['movie_symlink'] == 1) {
                            $rExtension = pathinfo($rSource)['extension'];
                            if (strlen($rExtension) != 0) {
                            } else {
                                $rExtension = 'mp4';
                            }
                        } else {
                            $rExtension = 'ts';
                        }
                        if (!file_exists(CREATED_PATH . $rStreamID . '_' . md5($rSource) . '.' . $rExtension)) {
                        } else {
                            $rOutputList .= "file '" . CREATED_PATH . $rStreamID . '_' . md5($rSource) . '.' . $rExtension . "'" . "\n";
                        }
                    }
                    $rOutputList = base64_encode($rOutputList);
                    shell_exec('echo ' . $rOutputList . ' | base64 --decode > "' . CREATED_PATH . intval($rStreamID) . '_.list"');
                    CoreUtilities::updateStream($rStreamID);
                    $rInt = $rSeconds = 0;
                    $rList = explode("\n", file_get_contents(CREATED_PATH . $rStreamID . '_.list'));
                    $rReturn = array();
                    foreach ($rList as $rItem) {
                        list($rFilename) = explode("'", explode("'", $rItem)[1]);
                        if (!file_exists($rFilename)) {
                        } else {
                            $rFileInfo = CoreUtilities::probeStream($rFilename);
                            $rReturn[] = array('position' => $rInt, 'filename' => basename($rFilename), 'path' => $rFilename, 'stream_info' => $rFileInfo, 'seconds' => $rFileInfo['of_duration'], 'start' => $rSeconds, 'finish' => $rSeconds + $rFileInfo['of_duration']);
                            $rSeconds += $rFileInfo['of_duration'];
                            $rInt++;
                        }
                    }
                    file_put_contents(CREATED_PATH . $rStreamID . '_.info', json_encode($rReturn, JSON_UNESCAPED_UNICODE));
                    echo 'Completed!' . "\n";
                    unlink(CREATED_PATH . $rStreamID . '_.create', getmypid());
                }
            } else {
                echo "Channel doesn't exist on this server." . "\n";
                exit();
            }
        } else {
            echo "Channel doesn't exist." . "\n";
            exit();
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function checkRunning($rStreamID) {
    clearstatcache(true);
    if (!file_exists(CREATED_PATH . $rStreamID . '_.create')) {
    } else {
        $rPID = intval(file_get_contents(CREATED_PATH . $rStreamID . '_.create'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'XC_VMCreate\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if ($rCommand != 'XC_VMCreate[' . $rStreamID . ']') {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
    file_put_contents(CREATED_PATH . $rStreamID . '_.create', getmypid());
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
