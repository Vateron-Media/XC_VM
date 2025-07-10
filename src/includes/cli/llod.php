<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc > 3) {
        $rStreamID = intval($argv[1]);
        $rStreamSources = json_decode(base64_decode($argv[2]), true);
        $rStreamArguments = json_decode(base64_decode($argv[3]), true);
        define('MAIN_HOME', '/home/xc_vm/');
        define('STREAMS_PATH', MAIN_HOME . 'content/streams/');
        define('INCLUDES_PATH', MAIN_HOME . 'includes/');
        define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
        define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
        define('FFMPEG', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffmpeg');
        define('FFPROBE', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffprobe');
        define('PAT_HEADER', "�\r");
        define('KEYFRAME_HEADER', "\x07P");
        define('PACKET_SIZE', 188);
        define('BUFFER_SIZE', 12032);
        define('PAT_PERIOD', 2);
        define('TIMEOUT', 20);
        define('TIMEOUT_READ', 1);
        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            checkRunning($rStreamID);
            register_shutdown_function('shutdown');
            set_time_limit(0);
            error_reporting(5);
            cli_set_process_title('LLOD[' . $rStreamID . ']');
            require INCLUDES_PATH . 'ts.php';
            $rFP = $rSegmentFile = null;
            $rSegmentDuration = $rSegmentStatus = array();
            $rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
            $rSegListSize = $rSettings['seg_list_size'];
            $rSegDeleteThreshold = $rSettings['seg_delete_threshold'];
            $rRequestPrebuffer = $rSettings['request_prebuffer'];
            $rLastPTS = $rCurPTS = null;
            startllod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold);
        } else {
            echo 'Settings not cached!' . "\n";
            exit(0);
        }
    } else {
        echo 'LLOD cannot be directly run!' . "\n";
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function deleteOldSegments($rStreamID, $rKeep, $rThreshold) {
    global $rSegmentStatus;
    $rReturn = array();
    $rCurrentSegment = max(array_keys($rSegmentStatus));
    foreach ($rSegmentStatus as $rSegmentID => $rStatus) {
        if (!$rStatus) {
        } else {
            if ($rSegmentID < $rCurrentSegment - ($rKeep + $rThreshold) + 1) {
                $rSegmentStatus[$rSegmentID] = false;
                @unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.ts');
            } else {
                if ($rSegmentID == $rCurrentSegment) {
                } else {
                    $rReturn[] = $rSegmentID;
                }
            }
        }
    }
    if ($rKeep >= count($rReturn)) {
    } else {
        $rReturn = array_slice($rReturn, count($rReturn) - $rKeep, $rKeep);
    }
    return $rReturn;
}
function updateSegments($rStreamID, $rSegmentsRemaining) {
    global $rSegmentDuration;
    global $rLastPTS;
    global $rCurPTS;
    $rHLS = '#EXTM3U' . "\n" . '#EXT-X-VERSION:3' . "\n" . '#EXT-X-TARGETDURATION:4' . "\n" . '#EXT-X-MEDIA-SEQUENCE:';
    $rSequence = false;
    foreach ($rSegmentsRemaining as $rSegment) {
        if (!file_exists(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts')) {
        } else {
            if ($rSequence) {
            } else {
                $rHLS .= $rSegment . "\n";
                $rSequence = true;
            }
            if (isset($rSegmentDuration[$rSegment]) || !$rLastPTS) {
            } else {
                $rSegmentDuration[$rSegment] = ($rCurPTS - $rLastPTS) / 90000;
            }
            $rHLS .= '#EXTINF:' . round((isset($rSegmentDuration[$rSegment]) ? $rSegmentDuration[$rSegment] : 10), 0) . '.000000,' . "\n" . $rStreamID . '_' . $rSegment . '.ts' . "\n";
        }
    }
    file_put_contents(STREAMS_PATH . $rStreamID . '_.m3u8', $rHLS);
}
function writeError($rStreamID, $rError) {
    echo $rError . "\n";
    file_put_contents(STREAMS_PATH . $rStreamID . '.errors', $rError . "\n", FILE_APPEND | LOCK_EX);
}
function startllod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold) {
    global $rSegmentStatus;
    global $rSegmentFile;
    global $rFP;
    global $rCurPTS;
    global $rLastPTS;
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
    if (!$rRequestPrebuffer) {
    } else {
        $rOptions['http']['header'] .= 'X-XC_VM-Prebuffer: 1' . "\r\n";
    }
    $rContext = stream_context_create($rOptions);
    $rFP = getActiveStream($rStreamID, $rStreamSources, $rContext);
    if (!$rFP) {
    } else {
        shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
        $rLastSocket = null;
        stream_set_blocking($rFP, true);
        $rExcessBuffer = $rPrebuffer = $rBuffer = $rPacket = '';
        $rHasPrebuffer = $rPATHeaders = array();
        $rNewSegment = $rPAT = false;
        $rFirstWrite = true;
        $rLastPacket = time();
        $rLastSegment = round(microtime(true) * 1000);
        $rSegment = 0;
        $rSegmentFile = fopen(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts', 'wb');
        $rSegmentStatus[$rSegment] = true;
        echo 'PID: ' . getmypid() . "\n";
        while (!feof($rFP)) {
            stream_set_timeout($rFP, TIMEOUT_READ);
            $rBuffer = $rBuffer . $rExcessBuffer . fread($rFP, BUFFER_SIZE - strlen($rBuffer . $rExcessBuffer));
            $rExcessBuffer = '';
            $rPacketNum = floor(strlen($rBuffer) / PACKET_SIZE);
            if (0 >= $rPacketNum) {
            } else {
                $rLastPacket = time();
                if (strlen($rBuffer) == $rPacketNum * PACKET_SIZE) {
                } else {
                    $rExcessBuffer = substr($rBuffer, $rPacketNum * PACKET_SIZE, strlen($rBuffer) - $rPacketNum * PACKET_SIZE);
                    $rBuffer = substr($rBuffer, 0, $rPacketNum * PACKET_SIZE);
                }
                foreach (str_split($rBuffer, PACKET_SIZE) as $rPacket) {
                    list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
                    $rSync = $rHeader >> 24 & 255;
                    if ($rSync == 71) {
                        if (substr($rPacket, 6, 4) == PAT_HEADER) {
                            $rPAT = true;
                            $rPATHeaders = array();
                        } else {
                            $rAdaptationField = $rHeader >> 4 & 3;
                            if (($rAdaptationField & 2) !== 2) {
                            } else {
                                if (!(0 < count($rPATHeaders) && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == KEYFRAME_HEADER)) {
                                } else {
                                    $rPrebuffer = implode('', $rPATHeaders);
                                    $rNewSegment = true;
                                    $rPAT = false;
                                    $rPATHeaders = array();
                                    $rHandler = new TS();
                                    $rHandler->setPacket($rPacket);
                                    $rPacketInfo = $rHandler->parsePacket();
                                    if (!isset($rPacketInfo['pts'])) {
                                    } else {
                                        $rLastPTS = $rCurPTS;
                                        $rCurPTS = $rPacketInfo['pts'];
                                    }
                                    unset($rHandler);
                                }
                            }
                        }
                        if (!($rPAT && count($rPATHeaders) < 10)) {
                        } else {
                            $rPATHeaders[] = $rPacket;
                        }
                        if (!$rNewSegment) {
                        } else {
                            $rPrebuffer .= $rPacket;
                        }
                    } else {
                        writeerror($rStreamID, '[LLOD] No sync byte detected! Stream is out of sync.');
                        $i = 0;
                        while ($i < strlen($rPacket)) {
                            if (substr($rPacket, $i, 2) != 'G' . "\x01") {
                            } else {
                                if (strlen(fread($rFP, $i)) != $i) {
                                } else {
                                    writeerror($rStreamID, '[Loopback] Resynchronised stream. Continuing...');
                                    $rLastPacket = time();
                                    break;
                                }
                            }
                            $i++;
                        }
                        writeerror($rStreamID, "[LLOD] Couldn't rectify out-of-sync data. Exiting.");
                        exit();
                    }
                }
                if ($rNewSegment) {
                    $rLastSegment = round(microtime(true) * 1000);
                    $rPosition = strpos($rBuffer, $rPrebuffer);
                    if (0 >= $rPosition) {
                    } else {
                        $rLastBuffer = substr($rBuffer, 0, $rPosition);
                        if ($rFirstWrite) {
                        } else {
                            fwrite($rSegmentFile, $rLastBuffer, strlen($rLastBuffer));
                        }
                    }
                    if ($rFirstWrite) {
                    } else {
                        fclose($rSegmentFile);
                        $rSegment++;
                        $rSegmentFile = fopen(STREAMS_PATH . $rStreamID . '_' . $rSegment . '.ts', 'wb');
                        $rSegmentStatus[$rSegment] = true;
                        $rSegmentsRemaining = deleteoldsegments($rStreamID, $rSegListSize, $rSegDeleteThreshold);
                        updatesegments($rStreamID, $rSegmentsRemaining);
                    }
                    $rFirstWrite = false;
                    fwrite($rSegmentFile, $rPrebuffer, strlen($rPrebuffer));
                    $rPrebuffer = '';
                    $rNewSegment = false;
                } else {
                    fwrite($rSegmentFile, $rBuffer, strlen($rBuffer));
                }
                $rBuffer = '';
            }
            if (TIMEOUT > time() - $rLastPacket) {
                break;
            }
            echo 'No data, timeout reached' . "\n";
            writeerror($rStreamID, '[LLOD] No data received for ' . TIMEOUT . ' seconds, closing source.');
        }
        if (time() - $rLastPacket >= TIMEOUT) {
        } else {
            writeerror($rStreamID, '[LLOD] Connection to source closed unexpectedly.');
        }
        fclose($rSegmentFile);
        fclose($rFP);
    }
}
function getActiveStream($rStreamID, $rURLs, $rContext) {
    foreach ($rURLs as $rURL) {
        $rFP = @fopen($rURL, 'rb', false, $rContext);
        if ($rFP) {
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
            writeerror($rStreamID, "[LLOD] Source isn't MPEG-TS: " . $rURL . ' - ' . $rHeaders['Content-Type']);
        } else {
            $rError = null;
            foreach ($http_response_header as $rKey => $rHeader) {
                if (!preg_match('#HTTP/[0-9\\.]+\\s+([0-9]+)#', $rHeader, $rOutput)) {
                } else {
                    $rError = $rHeader;
                }
            }
            writeerror($rStreamID, '[LLOD] ' . ((!empty($rError) ? $rError : 'Invalid source')) . ': ' . $rURL);
        }
    }
}
function checkRunning($rStreamID) {
    clearstatcache(true);
    if (!file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
    } else {
        $rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'LLOD\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'LLOD[' . $rStreamID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
}
function shutdown() {
    global $rFP;
    global $rSegmentFile;
    global $rStreamID;
    if (!is_resource($rSegmentFile)) {
    } else {
        @fclose($rSegmentFile);
    }
    if (!is_resource($rFP)) {
    } else {
        @fclose($rFP);
    }
}
