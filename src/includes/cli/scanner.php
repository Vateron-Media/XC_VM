<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        shell_exec('kill -9 $(ps aux | grep scanner | grep -v grep | grep -v ' . getmypid() . " | awk '{print \$2}')");
        if (CoreUtilities::$rSettings['on_demand_checker']) {
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
                if ($db->query('SELECT `streams`.* FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams_servers`.`pid` IS NULL AND `streams_servers`.`on_demand` = 1 AND `streams_servers`.`parent_id` IS NULL AND `streams`.`type` = 1 AND `streams`.`direct_source` = 0 AND `streams_servers`.`server_id` = ? AND (UNIX_TIMESTAMP() - (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) > ? OR (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) IS NULL);', SERVER_ID, (CoreUtilities::$rSettings['on_demand_scan_time'] ?: 3600))) {
                    if (0 >= $db->num_rows()) {
                    } else {
                        foreach ($db->get_rows() as $rRow) {
                            echo '[' . $rRow['id'] . '] - ' . $rRow['stream_display_name'] . "\n";
                            $db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rRow['id']);
                            $rStreamArguments = $db->get_rows();
                            $rProbesize = (intval($rRow['probesize_ondemand']) ?: 512000);
                            $rAnalyseDuration = '10000000';
                            $rTimeout = intval($rAnalyseDuration / 1000000) + CoreUtilities::$rSettings['probe_extra_wait'];
                            if (!(CoreUtilities::$rSettings['on_demand_max_probe'] < $rTimeout && 0 < CoreUtilities::$rSettings['on_demand_max_probe'])) {
                            } else {
                                $rTimeout = intval(CoreUtilities::$rSettings['on_demand_max_probe']);
                            }
                            $rFFProbee = 'timeout ' . $rTimeout . ' ' . CoreUtilities::$rFFPROBE . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -i {STREAM_SOURCE} -loglevel error -print_format json -show_streams -show_format 2>' . STREAMS_TMP_PATH . $rRow['id'] . '._errors';
                            $rSources = json_decode($rRow['stream_source'], true);
                            $rSourceID = 0;
                            $rErrors = null;
                            foreach ($rSources as $rSource) {
                                $rProcessed = false;
                                $rRealSource = $rSource;
                                $rStreamSource = CoreUtilities::parseStreamURL($rSource);
                                echo 'Checking source: ' . $rSource . "\n";
                                $rURLInfo = parse_url($rStreamSource);
                                $rIsXC_VM = CoreUtilities::detectXC_VM($rStreamSource);
                                if (!($rIsXC_VM && CoreUtilities::$rSettings['send_xc_vm_header'])) {
                                } else {
                                    foreach (array_keys($rStreamArguments) as $rID) {
                                        if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
                                        } else {
                                            $rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Detect:1';
                                            $rProcessed = true;
                                        }
                                    }
                                    if ($rProcessed) {
                                    } else {
                                        $rStreamArguments[] = array('value' => 'X-XC_VM-Detect:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
                                    }
                                }
                                if (!($rIsXC_VM && CoreUtilities::$rSettings['request_prebuffer'] == 1)) {
                                } else {
                                    foreach (array_keys($rStreamArguments) as $rID) {
                                        if ($rStreamArguments[$rID]['argument_key'] != 'headers') {
                                        } else {
                                            $rStreamArguments[$rID]['value'] .= "\r\n" . 'X-XC_VM-Prebuffer:1';
                                            $rProcessed = true;
                                        }
                                    }
                                    if ($rProcessed) {
                                    } else {
                                        $rStreamArguments[] = array('value' => 'X-XC_VM-Prebuffer:1', 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
                                    }
                                }
                                $rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
                                $rFetchOptions = implode(' ', CoreUtilities::getArguments($rStreamArguments, $rProtocol, 'fetch'));
                                if (!($rIsXC_VM && CoreUtilities::$rSettings['api_probe'])) {
                                } else {
                                    $rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . ':' . $rURLInfo['port'] . '/probe/' . base64_encode($rURLInfo['path']);
                                    $rTime = round(microtime(true) * 1000);
                                    $rFFProbeOutput = json_decode(CoreUtilities::getURL($rProbeURL), true);
                                    $rTimeTaken = round(microtime(true) * 1000) - $rTime;
                                    if (!($rFFProbeOutput && isset($rFFProbeOutput['streams']))) {
                                    } else {
                                        echo 'Got stream information via API' . "\n";
                                        break;
                                    }
                                }
                                $rTime = round(microtime(true) * 1000);
                                $rFFProbeOutput = json_decode(shell_exec(str_replace(array('{FETCH_OPTIONS}', '{STREAM_SOURCE}'), array($rFetchOptions, escapeshellarg($rStreamSource)), $rFFProbee)), true);
                                $rTimeTaken = round(microtime(true) * 1000) - $rTime;
                                if (!(file_exists(STREAMS_TMP_PATH . $rRow['id'] . '._errors') && 0 < filesize(STREAMS_TMP_PATH . $rRow['id'] . '._errors'))) {
                                } else {
                                    if ($rErrors || $rSourceID != 0) {
                                    } else {
                                        $rErrors = file_get_contents(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
                                    }
                                    unlink(STREAMS_TMP_PATH . $rRow['id'] . '._errors');
                                }
                                if (!($rFFProbeOutput && isset($rFFProbeOutput['streams']))) {
                                    if (!$rRow['llod']) {
                                        $rSourceID++;
                                    } else {
                                        break;
                                    }
                                } else {
                                    echo 'Got stream information via ffprobe' . "\n";
                                    break;
                                }
                            }
                            if (!empty($rFFProbeOutput)) {
                                echo 'Source live!' . "\n";
                                $rFFProbeOutput = CoreUtilities::parseFFProbe($rFFProbeOutput);
                                $rAudioCodec = ($rFFProbeOutput['codecs']['audio']['codec_name'] ?: null);
                                $rVideoCodec = ($rFFProbeOutput['codecs']['video']['codec_name'] ?: null);
                                $rResolution = ($rFFProbeOutput['codecs']['video']['height'] ?: null);
                                $rVideoBitrate = ($rFFProbeOutput['codecs']['video']['bit_rate'] ?: 0);
                                $rAudioBitrate = ($rFFProbeOutput['codecs']['audio']['bit_rate'] ?: 0);
                                $rFPS = (intval(explode('/', $rFFProbeOutput['codecs']['video']['r_frame_rate'])[0]) ?: 0);
                                if ($rFPS != 0) {
                                } else {
                                    $rFPS = (intval(explode('/', $rFFProbeOutput['codecs']['video']['avg_frame_rate'])[0]) ?: 0);
                                }
                                if (1000 > $rFPS) {
                                } else {
                                    $rFPS = intval($rFPS / 1000);
                                }
                                if (!$rResolution) {
                                } else {
                                    $rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
                                }
                                $rStatus = 1;
                            } else {
                                echo 'Source down!' . "\n";
                                $rFPS = $rAudioCodec = $rVideoCodec = $rResolution = $rTimeTaken = null;
                                $rSourceID = $rStatus = 0;
                            }
                            $rSource = $rSources[$rSourceID];
                            $db->query('INSERT INTO `ondemand_check`(`stream_id`, `server_id`, `status`, `source_id`, `source_url`, `fps`, `video_codec`, `audio_codec`, `resolution`, `response`, `errors`, `date`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rRow['id'], SERVER_ID, $rStatus, $rSourceID, $rSource, $rFPS, $rVideoCodec, $rAudioCodec, $rResolution, $rTimeTaken, $rErrors, time());
                            $db->query('UPDATE `streams_servers` SET `ondemand_check` = ? WHERE `stream_id` = ? AND `server_id` = ?;', $db->last_insert_id(), $rRow['id'], SERVER_ID);
                            echo "\n";
                        }
                    }
                    sleep(60);
                    break;
                }
            }
            if (!is_object($db)) {
            } else {
                $db->close_mysql();
            }
            shell_exec('(sleep 1; ' . PHP_BIN . ' ' . __FILE__ . ' ) > /dev/null 2>/dev/null &');
        } else {
            echo 'On-Demand - Source Scanner is disabled.' . "\n";
            exit();
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
