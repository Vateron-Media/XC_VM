<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc > 1) {
        $rStreamID = intval($argv[1]);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        checkRunning($rStreamID);
        register_shutdown_function('shutdown');
        set_time_limit(0);
        cli_set_process_title('XC_VMProxy[' . $rStreamID . ']');
        $db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
        if ($db->num_rows() > 0) {
            file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
            @unlink(STREAMS_PATH . $rStreamID . '_.pid');
            $rStreamInfo = $db->get_row();
            $db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
            $rStreamArguments = $db->get_rows(true, 'argument_key');
            define('PAT_HEADER', "�\r");
            define('PACKET_SIZE', 188);
            define('BUFFER_SIZE', 12032);
            define('PAT_PERIOD', 2);
            define('TIMEOUT', 20);
            define('CLOSE_EMPTY', 3000);
            define('STORE_PREBUFFER', 1128000);
            define('MAX_PREBUFFER', 10528000);
            $rFP = null;
            startproxy($rStreamID, $rStreamInfo, $rStreamArguments);
        } else {
            CoreUtilities::stopStream($rStreamID);
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
    if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
    } else {
        $rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'XC_VMProxy\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'XC_VMProxy[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
}
function startProxy($rStreamID, $rStreamInfo, $rStreamArguments) {
    global $rFP;
    global $db;
    if (file_exists(CONS_TMP_PATH . $rStreamID . '/')) {
    } else {
        mkdir(CONS_TMP_PATH . $rStreamID);
    }
    $rUserAgent = (isset($rStreamArguments['user_agent']) ? ($rStreamArguments['user_agent']['value'] ?: $rStreamArguments['user_agent']['argument_default_value']) : 'Mozilla/5.0');
    $rOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true), 'http' => array('method' => 'GET', 'user_agent' => $rUserAgent, 'timeout' => TIMEOUT, 'header' => ''));
    if (!isset($rStreamArguments['proxy'])) {
    } else {
        $rOptions['http']['proxy'] = 'tcp://' . $rStreamArguments['proxy']['value'];
        $rOptions['http']['request_fulluri'] = true;
    }
    if (!isset($rStreamArguments['cookie'])) {
    } else {
        $rOptions['http']['header'] .= 'Cookie: ' . $rStreamArguments['cookie']['value'] . "\r\n";
    }
    if (!CoreUtilities::$rSettings['request_prebuffer']) {
    } else {
        $rOptions['http']['header'] .= 'X-XC_VM-Prebuffer: 1' . "\r\n";
    }
    $rContext = stream_context_create($rOptions);
    $rURLs = json_decode($rStreamInfo['stream_source'], true);
    $rFP = getActiveStream($rURLs, $rContext);
    if (is_resource($rFP)) {
    } else {
        $rHeaders = (!empty($rOptions['http']['header']) ? '-headers ' . escapeshellarg($rOptions['http']['header']) : '');
        $rProxy = (!empty($rStreamArguments['proxy']) ? '-http_proxy ' . escapeshellarg($rStreamArguments['proxy']) : '');
        $rCommand = CoreUtilities::$rFFMPEG_CPU . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -user_agent ' . escapeshellarg($rUserAgent) . ' ' . $rHeaders . ' ' . $rProxy . ' -i ' . escapeshellarg($rFP) . ' -map 0 -c copy -mpegts_flags +initial_discontinuity -pat_period ' . PAT_PERIOD . ' -f mpegts -';
        $rFP = popen($rCommand, 'rb');
    }
    if ($rFP) {
        $db->query('UPDATE `streams_servers` SET `monitor_pid` = ?, `pid` = ?, `stream_started` = ?, `stream_status` = 0, `to_analyze` = 0 WHERE `server_stream_id` = ?', getmypid(), getmypid(), time(), $rStreamInfo['server_stream_id']);
        if (!CoreUtilities::$rSettings['enable_cache']) {
        } else {
            CoreUtilities::updateStream($rStreamID);
        }
        shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
        file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', getmypid());
        $db->close_mysql();
        $rLastSocket = null;
        stream_set_blocking($rFP, false);
        $rExcessBuffer = $rAnalyseBuffer = $rPrebuffer = $rBuffer = $rPacket = '';
        $rHasPrebuffer = $rPATHeaders = array();
        $rAnalysed = $rPAT = false;
        $rFirstKeyframe = false;
        while (!feof($rFP)) {
            stream_set_timeout($rFP, TIMEOUT);
            $rBuffer = $rBuffer . $rExcessBuffer . fread($rFP, BUFFER_SIZE - strlen($rBuffer . $rExcessBuffer));
            $rExcessBuffer = '';
            $rPacketNum = floor(strlen($rBuffer) / PACKET_SIZE);
            if (0 < $rPacketNum) {
                if (strlen($rBuffer) == $rPacketNum * PACKET_SIZE) {
                } else {
                    $rExcessBuffer = substr($rBuffer, $rPacketNum * PACKET_SIZE, strlen($rBuffer) - $rPacketNum * PACKET_SIZE);
                    $rBuffer = substr($rBuffer, 0, $rPacketNum * PACKET_SIZE);
                }
                foreach (str_split($rBuffer, PACKET_SIZE) as $rPacket) {
                    list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
                    $rSync = $rHeader >> 24 & 255;
                    if ($rSync != 71) {
                    } else {
                        if (substr($rPacket, 6, 4) == PAT_HEADER) {
                            $rPAT = true;
                            $rPATHeaders = array();
                        } else {
                            $rAdaptationField = $rHeader >> 4 & 3;
                            if (($rAdaptationField & 2) !== 2) {
                            } else {
                                if (!(0 < count($rPATHeaders) && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == "\x07" . 'P')) {
                                } else {
                                    if ($rPrebuffer && STORE_PREBUFFER > strlen($rPrebuffer)) {
                                    } else {
                                        $rPrebuffer = implode('', $rPATHeaders) . $rPacket;
                                    }
                                    $rFirstKeyframe = true;
                                    $rPAT = false;
                                    $rPATHeaders = array();
                                }
                            }
                        }
                    }
                    if (!($rPAT && count($rPATHeaders) < 10)) {
                    } else {
                        $rPATHeaders[] = $rPacket;
                    }
                    if (!(strlen($rPrebuffer) < MAX_PREBUFFER && $rFirstKeyframe)) {
                    } else {
                        $rPrebuffer .= $rPacket;
                    }
                    if ($rAnalysed) {
                    } else {
                        $rAnalyseBuffer .= $rPacket;
                        if (3000 * PACKET_SIZE > strlen($rAnalyseBuffer)) {
                        } else {
                            echo 'Write analysis buffer' . "\n";
                            file_put_contents(STREAMS_PATH . $rStreamID . '.analyse', $rAnalyseBuffer);
                            $rAnalyseBuffer = null;
                            $rAnalysed = true;
                        }
                    }
                }
                $rSockets = getSockets();
                if (0 < count($rSockets)) {
                    $rLastSocket = round(microtime(true) * 1000);
                    foreach ($rSockets as $rSocketID) {
                        $rSocketFile = CONS_TMP_PATH . $rStreamID . '/' . $rSocketID;
                        if (!(file_exists($rSocketFile) && (!isset($rHasPrebuffer[$rSocketID]) || !empty($rBuffer)))) {
                        } else {
                            $rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
                            socket_set_nonblock($rSocket);
                            if (!isset($rHasPrebuffer[$rSocketID])) {
                                if (empty($rPrebuffer)) {
                                } else {
                                    echo 'Send prebuffer: ' . strlen($rPrebuffer) . ' bytes' . "\n";
                                    $rHasPrebuffer[$rSocketID] = true;
                                    foreach (str_split($rPrebuffer, BUFFER_SIZE) as $rChunk) {
                                        socket_sendto($rSocket, $rChunk, BUFFER_SIZE, 0, $rSocketFile);
                                    }
                                }
                            } else {
                                if (empty($rBuffer)) {
                                } else {
                                    socket_sendto($rSocket, $rBuffer, BUFFER_SIZE, 0, $rSocketFile);
                                }
                            }
                            socket_close($rSocket);
                        }
                    }
                } else {
                    if ($rLastSocket) {
                    } else {
                        $rLastSocket = round(microtime(true) * 1000);
                    }
                    if (CLOSE_EMPTY > round(microtime(true) * 1000) - $rLastSocket) {
                    } else {
                        echo 'No sockets waiting, close stream' . "\n";
                    }
                }
                $rBuffer = '';
                break;
            }
            if ($rLastSocket && 100000 >= round(microtime(true) * 1000) - $rLastSocket) {
            } else {
                $rSockets = getSockets();
                if (0 < count($rSockets)) {
                    $rLastSocket = round(microtime(true) * 1000);
                    if (empty($rPrebuffer)) {
                    } else {
                        foreach ($rSockets as $rSocketID) {
                            if (isset($rHasPrebuffer[$rSocketID])) {
                            } else {
                                $rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
                                socket_set_nonblock($rSocket);
                                echo 'Send prebuffer: ' . strlen($rPrebuffer) . ' bytes' . "\n";
                                $rHasPrebuffer[$rSocketID] = true;
                                foreach (str_split($rPrebuffer, BUFFER_SIZE) as $rChunk) {
                                    socket_sendto($rSocket, $rChunk, BUFFER_SIZE, 0, CONS_TMP_PATH . $rStreamID . '/' . $rSocketID);
                                }
                                socket_close($rSocket);
                            }
                        }
                    }
                } else {
                    if ($rLastSocket) {
                    } else {
                        $rLastSocket = round(microtime(true) * 1000);
                    }
                    if (CLOSE_EMPTY > round(microtime(true) * 1000) - $rLastSocket) {
                    } else {
                        echo 'No sockets waiting, close stream' . "\n";
                    }
                }
            }
        }
        fclose($rFP);
        $db->db_connect();
        $db->query('UPDATE `streams_servers` SET `monitor_pid` = null, `pid` = null, `stream_status` = 1 WHERE `server_stream_id` = ?;', $rStreamInfo['server_stream_id']);
        if (!CoreUtilities::$rSettings['enable_cache']) {
        } else {
            CoreUtilities::updateStream($rStreamID);
        }
        exit();
        if ($rPacketNum != 0) {
        } else {
            usleep(10000);
        }
    } else {
        echo 'Failed!' . "\n";
        CoreUtilities::streamLog($rStreamID, SERVER_ID, 'STREAM_START_FAIL');
        $db->query('UPDATE `streams_servers` SET `monitor_pid` = null, `pid` = null, `stream_status` = 1 WHERE `server_stream_id` = ?;', $rStreamInfo['server_stream_id']);
        if (!CoreUtilities::$rSettings['enable_cache']) {
        } else {
            CoreUtilities::updateStream($rStreamID);
        }
    }
}
function getSockets() {
    global $rStreamID;
    $rSockets = array();
    if (!($rHandle = opendir(CONS_TMP_PATH . $rStreamID . '/'))) {
    } else {
        while (false !== ($rFilename = readdir($rHandle))) {
            if (!($rFilename != '.' && $rFilename != '..')) {
            } else {
                $rSockets[] = $rFilename;
            }
        }
        closedir($rHandle);
    }
    return $rSockets;
}
function getActiveStream($rURLs, $rContext) {
    foreach ($rURLs as $rURL) {
        $rURL = CoreUtilities::parseStreamURL($rURL);
        $rFP = @fopen($rURL, 'rb', false, $rContext);
        if (!$rFP) {
        } else {
            $rMetadata = stream_get_meta_data($rFP);
            $rHeaders = array();
            foreach ($rMetadata['wrapper_data'] as $rLine) {
                if (strpos($rLine, 'HTTP') !== 0) {
                    list($rKey, $rValue) = explode(': ', $rLine);
                    $rHeaders[$rKey] = $rValue;
                } else {
                    $rHeaders[0] = $rLine;
                }
            }
            $rContentType = (is_array($rHeaders['Content-Type']) ? $rHeaders['Content-Type'][count($rHeaders['Content-Type']) - 1] : $rHeaders['Content-Type']);
            if (strtolower($rContentType) == 'video/mp2t') {
                return $rFP;
            }
            fclose($rFP);
            if (!in_array(strtolower($rContentType), array('application/x-mpegurl', 'application/vnd.apple.mpegurl', 'audio/x-mpegurl'))) {
            } else {
                return $rURL;
            }
        }
    }
}
function shutdown() {
    global $rStreamID;
    global $rFP;
    @unlink(STREAMS_PATH . $rStreamID . '_.monitor');
    @unlink(STREAMS_PATH . $rStreamID . '_.pid');
    shell_exec('rm -rf ' . CONS_TMP_PATH . $rStreamID . '/');
    if (!is_resource($rFP)) {
    } else {
        @fclose($rFP);
    }
}
