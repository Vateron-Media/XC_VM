<?php
if (posix_getpwuid(posix_geteuid())['name'] === 'xc_vm') {
    if ($argc && $argc > 3) {
        $rStreamID = intval($argv[1]);
        $rStreamSources = json_decode(base64_decode($argv[2]), true);
        $rStreamArguments = json_decode(base64_decode($argv[3]), true);

        if (!is_array($rStreamSources) || !is_array($rStreamArguments)) {
            echo "Failed to decode stream parameters\n";
            exit(1);
        }

        echo "=== LLOD STARTUP ===\n";
        echo "Stream ID: $rStreamID\n";
        echo "Stream sources count: " . count($rStreamSources) . "\n";
        echo "Stream arguments count: " . count($rStreamArguments) . "\n";
        echo "====================\n\n";

        define('MAIN_HOME', '/home/xc_vm/');
        define('STREAMS_PATH', MAIN_HOME . 'content/streams/');
        define('INCLUDES_PATH', MAIN_HOME . 'includes/');
        define('CACHE_TMP_PATH', MAIN_HOME . 'tmp/cache/');
        define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
        define('FFMPEG', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffmpeg');
        define('FFPROBE', MAIN_HOME . 'bin/ffmpeg_bin/4.0/ffprobe');
        define('PACKET_SIZE', 188);
        define('BUFFER_SIZE', 12032);
        define('TIMEOUT', 20);
        define('SEGMENT_DURATION', 4);

        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            echo "Settings file found at: " . CACHE_TMP_PATH . "settings\n";

            checkRunning($rStreamID);
            register_shutdown_function('shutdown');
            set_time_limit(0);
            error_reporting(E_WARNING | E_PARSE);
            cli_set_process_title('LLOD[' . $rStreamID . ']');
            require INCLUDES_PATH . 'ts.php';

            $rFP = $rSegmentFile = null;
            $rSegmentStatus = array();

            $rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));

            if ($rSettings === false || !is_array($rSettings)) {
                echo "Failed to unserialize settings\n";
                exit(1);
            }

            echo "Settings loaded successfully\n";
            echo "Segment list size: " . $rSettings['seg_list_size'] . "\n";
            echo "Segment delete threshold: " . $rSettings['seg_delete_threshold'] . "\n";
            echo "Request prebuffer: " . $rSettings['request_prebuffer'] . "\n";

            $rSegListSize = $rSettings['seg_list_size'];
            $rSegDeleteThreshold = $rSettings['seg_delete_threshold'];
            $rRequestPrebuffer = $rSettings['request_prebuffer'];

            echo "Starting LLOD processing...\n\n";

            startllod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold);
        } else {
            echo 'Settings not cached!' . "\n";
            exit(0);
        }
    } else {

        echo 'LLOD cannot be directly run!' . "\n";
        echo "Arguments received: $argc\n";
        if ($argc > 0) {
            for ($i = 0; $i < $argc; $i++) {
                echo "  argv[$i]: " . (strlen($argv[$i]) > 100 ? substr($argv[$i], 0, 100) . "..." : $argv[$i]) . "\n";
            }
        }

        exit(0);
    }
} else {

    exit('Please run as XC_VM!' . "\n");
}

function deleteOldSegments($rStreamID, $rKeep, $rThreshold, &$rSegmentStatus) {
    echo "Stream ID: $rStreamID\n";
    echo "Keep segments: $rKeep\n";
    echo "Delete threshold: $rThreshold\n";

    $rReturn = array();

    if (empty($rSegmentStatus)) {
        return $rReturn;
    }

    $rCurrentSegment = max(array_keys($rSegmentStatus));

    echo "Current segment: $rCurrentSegment\n";
    echo "Segment status array size: " . count($rSegmentStatus) . "\n";

    foreach ($rSegmentStatus as $rSegmentID => $rStatus) {
        if ($rStatus) {
            if ($rSegmentID < $rCurrentSegment - ($rKeep + $rThreshold) + 1) {
                echo "Marking segment $rSegmentID for deletion\n";

                $rSegmentStatus[$rSegmentID] = false;
                $deleted = @unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.ts');
                @unlink(STREAMS_PATH . $rStreamID . '_' . $rSegmentID . '.m4s');
                echo "Unlink result for segment $rSegmentID: " . ($deleted ? "success" : "failed") . "\n";
            } else {
                if ($rSegmentID !== $rCurrentSegment) {
                    $rReturn[] = $rSegmentID;
                }
            }
        }
    }

    echo "Segments to keep (before slice): " . count($rReturn) . "\n";

    if ($rKeep >= count($rReturn)) {
        echo "Keep threshold larger than available segments, keeping all\n";
    } else {
        $rReturn = array_slice($rReturn, count($rReturn) - $rKeep, $rKeep);
        echo "Segments to keep (after slice): " . count($rReturn) . "\n";
    }

    return $rReturn;
}

function updateSegments($rStreamID, $segments) {
    if (empty($segments)) {
        return;
    }

    $duration = SEGMENT_DURATION;

    $m3u8  = "#EXTM3U\n";
    $m3u8 .= "#EXT-X-VERSION:3\n";
    $m3u8 .= "#EXT-X-TARGETDURATION:{$duration}\n";
    $m3u8 .= "#EXT-X-MEDIA-SEQUENCE:" . reset($segments) . "\n";

    foreach ($segments as $seg) {
        $m3u8 .= "#EXTINF:{$duration}.000000,\n";
        $m3u8 .= "{$rStreamID}_{$seg}.ts\n";
    }

    if (@file_put_contents(STREAMS_PATH . $rStreamID . '_.m3u8', $m3u8, LOCK_EX) === false) {
        writeError($rStreamID, '[LLOD] Failed to write playlist file');
        return;
    }

    echo "Playlist updated (" . count($segments) . " segments)\n";
}

function writeError($rStreamID, $rError) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $rError\n";

    echo $logMessage;

    @file_put_contents(STREAMS_PATH . $rStreamID . '.errors', $logMessage, FILE_APPEND | LOCK_EX);
}

function startllod($rStreamID, $rStreamSources, $rStreamArguments, $rRequestPrebuffer, $rSegListSize, $rSegDeleteThreshold) {
    global $rSegmentStatus, $rFP, $rSegmentFile;

    $segmentDuration = SEGMENT_DURATION;
    $segmentStart = microtime(true);

    if (!file_exists(CONS_TMP_PATH . $rStreamID)) {
        if (!@mkdir(CONS_TMP_PATH . $rStreamID, 0777, true)) {
            writeError($rStreamID, '[LLOD] Failed to create connection directory');
            return;
        }
    }

    $ua = $rStreamArguments['user_agent']['value'] ?? 'Mozilla/5.0';

    $context = stream_context_create([
        'http' => [
            'timeout'    => TIMEOUT,
            'user_agent' => $ua,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ]);

    $rFP = getActiveStream($rStreamID, $rStreamSources, $context);
    if (!$rFP) {
        echo "No active stream\n";
        return;
    }

    stream_set_blocking($rFP, true);

    shell_exec('rm -f ' . STREAMS_PATH . escapeshellarg($rStreamID) . '_*.ts');

    $segment = 0;
    $rSegmentFile = fopen(STREAMS_PATH . $rStreamID . "_{$segment}.ts", 'wb');

    if (!$rSegmentFile) {
        writeError($rStreamID, '[LLOD] Failed to create initial segment file');
        fclose($rFP);
        return;
    }

    $rSegmentStatus[$segment] = true;

    echo "Segment #{$segment} opened\n";

    $buffer = '';
    $lastData = time();

    while (!feof($rFP)) {
        $data = fread($rFP, BUFFER_SIZE);

        if ($data === '' || $data === false) {
            if (time() - $lastData > TIMEOUT) {
                writeError($rStreamID, '[LLOD] stream timeout');
                break;
            }
            usleep(10000);
            continue;
        }

        $lastData = time();
        $buffer .= $data;

        $packets = floor(strlen($buffer) / PACKET_SIZE);
        if ($packets > 0) {
            $writeSize = $packets * PACKET_SIZE;
            fwrite($rSegmentFile, substr($buffer, 0, $writeSize));
            $buffer = substr($buffer, $writeSize);
        }

        if ((microtime(true) - $segmentStart) >= $segmentDuration) {
            fclose($rSegmentFile);
            echo "Segment #{$segment} closed\n";

            $segment++;
            $segmentStart = microtime(true);

            $rSegmentFile = fopen(STREAMS_PATH . $rStreamID . "_{$segment}.ts", 'wb');

            if (!$rSegmentFile) {
                writeError($rStreamID, '[LLOD] Failed to create segment file #' . $segment);
                fclose($rFP);
                return;
            }

            $rSegmentStatus[$segment] = true;

            echo "Segment #{$segment} opened\n";

            $remain = deleteOldSegments(
                $rStreamID,
                $rSegListSize,
                $rSegDeleteThreshold,
                $rSegmentStatus
            );

            updateSegments($rStreamID, $remain);
        }
    }

    if (is_resource($rSegmentFile)) {
        fclose($rSegmentFile);
    }
    if (is_resource($rFP)) {
        fclose($rFP);
    }
}

function getActiveStream($rStreamID, $rURLs, $rContext) {
    echo "Trying to get active stream from " . count($rURLs) . " URL(s)\n";

    foreach ($rURLs as $index => $rURL) {
        echo "\nAttempting source " . ($index + 1) . "/" . count($rURLs) . ": $rURL\n";

        $rFP = @fopen($rURL, 'rb', false, $rContext);

        if ($rFP) {
            echo "Connection successful\n";

            $rMetadata = stream_get_meta_data($rFP);
            echo "Stream metadata obtained\n";

            $rHeaders = array();

            if (!empty($rMetadata['wrapper_data']) && is_array($rMetadata['wrapper_data'])) {
                foreach ($rMetadata['wrapper_data'] as $rLine) {
                    if (strpos($rLine, 'HTTP') !== 0) {
                        $pos = strpos($rLine, ':');
                        if ($pos !== false) {
                            $rKey = substr($rLine, 0, $pos);
                            $rValue = trim(substr($rLine, $pos + 1));
                            $rHeaders[$rKey] = $rValue;
                        }
                    } else {
                        $rHeaders[0] = $rLine;
                    }
                }
            }

            echo "Response headers:\n";
            foreach ($rHeaders as $key => $value) {
                echo "  $key: $value\n";
            }

            $rContentType = $rHeaders['Content-Type'] ?? '';
            echo "Content-Type: $rContentType\n";

            if (stripos($rContentType, 'video/mp2t') !== false) {
                echo "Content-Type is valid MPEG-TS\n";
                echo "=== getActiveStream() successful ===\n\n";
                return $rFP;
            }

            $contentTypeInfo = $rHeaders['Content-Type'] ?? 'unknown';
            writeError($rStreamID, "[LLOD] Source isn't MPEG-TS: " . $rURL . ' - ' . $contentTypeInfo);
            fclose($rFP);
        } else {
            $rError = null;

            if (isset($http_response_header)) {
                foreach ($http_response_header as $rKey => $rHeader) {
                    if (preg_match('#HTTP/[0-9\\.]+\\s+([0-9]+)#', $rHeader, $rOutput)) {
                        $rError = $rHeader;
                    }
                }
            }

            $errorMsg = (!empty($rError) ? $rError : 'Invalid source');
            echo "Connection failed: $errorMsg\n";
            writeError($rStreamID, '[LLOD] ' . $errorMsg . ': ' . $rURL);
        }
    }

    echo "=== failed - no valid sources found ===\n\n";
    return false;
}

function checkRunning($rStreamID) {
    echo "Checking for existing process for stream $rStreamID\n";

    clearstatcache(true);

    $monitorFile = STREAMS_PATH . $rStreamID . '_.monitor';
    if (file_exists($monitorFile)) {
        $rPID = intval(file_get_contents($monitorFile));
        echo "Monitor file found, PID: $rPID\n";
    } else {
        echo "No monitor file found\n";
        $rPID = null;
    }

    if (empty($rPID)) {
        $killCmd = "kill -9 `ps -ef | grep 'LLOD\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`";
        echo "No PID from monitor, executing kill command: $killCmd\n";
        shell_exec($killCmd);
    } else {
        if (file_exists('/proc/' . $rPID)) {
            echo "Process directory exists: /proc/$rPID\n";

            $cmdlineFile = '/proc/' . $rPID . '/cmdline';
            if (file_exists($cmdlineFile)) {
                $rCommand = trim(file_get_contents($cmdlineFile));
                echo "Process command line: $rCommand\n";

                $expectedCommand = 'LLOD[' . $rStreamID . ']';
                if ($rCommand === $expectedCommand && is_numeric($rPID) && 0 < $rPID) {
                    echo "Killing existing process PID: $rPID\n";
                    posix_kill($rPID, 9);
                } else {
                    echo "Process command doesn't match expected: '$rCommand' != '$expectedCommand'\n";
                }
            } else {
                echo "Command line file not found\n";
            }
        } else {
            echo "Process directory doesn't exist, process not running\n";
        }
    }
}

function shutdown() {
    global $rFP;
    global $rSegmentFile;
    global $rStreamID;

    if (is_resource($rSegmentFile)) {
        echo "Closing segment file\n";
        @fclose($rSegmentFile);
    } else {
        echo "Segment file is not a resource\n";
    }

    if (is_resource($rFP)) {
        echo "Closing stream resource\n";
        @fclose($rFP);
    } else {
        echo "Stream resource is not a resource\n";
    }
}
