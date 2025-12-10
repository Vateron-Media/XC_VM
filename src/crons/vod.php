<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[VOD]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadCron()
{
    global $db;
    $db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND t3.parent_id IS NULL;', SERVER_ID);
    if (0 >= $db->num_rows()) {
    } else {
        $rStreams = $db->get_rows();
        foreach ($rStreams as $rStream) {
            echo "\n\n" . '[*] Checking Stream ' . $rStream['stream_display_name'] . "\n";
            $rPID = intval(file_get_contents(CREATED_PATH . $rStream['id'] . '_.create'));
            if ($rPID && CoreUtilities::isPIDRunning(SERVER_ID, $rPID, PHP_BIN)) {
                echo "\t" . 'Build Is Still Going!' . "\n";
            } else {
                $rSourcesLeft = array_diff(json_decode($rStream['stream_source'], true), json_decode($rStream['cchannel_rsources'], true));
                if (0 < count($rSourcesLeft)) {
                    echo "\t" . 'Needs Updating!' . "\n";
                    CoreUtilities::queueChannel($rStream['id']);
                } else {
                    if (!file_exists(CREATED_PATH . $rStream['id'] . '_.info')) {
                    } else {
                        $rCCInfo = file_get_contents(CREATED_PATH . $rStream['id'] . '_.info');
                        $db->query('UPDATE `streams_servers` SET `cc_info` = ? WHERE `server_id` = ? AND `stream_id` = ?;', $rCCInfo, SERVER_ID, $rStream['id']);
                        unlink(CREATED_PATH . $rStream['id'] . '_.info');
                    }
                    echo "\t" . 'Build Finished' . "\n";
                }
            }
        }
    }
    $db->query('SELECT `id` FROM `recordings` WHERE `status` NOT IN (1,2) AND `source_id` = ? AND ((`start` <= UNIX_TIMESTAMP() AND `end` > UNIX_TIMESTAMP()) OR (`archive` = 1));', SERVER_ID);
    if (0 >= $db->num_rows()) {
    } else {
        foreach ($db->get_rows() as $rRow) {
            shell_exec(PHP_BIN . ' ' . INCLUDES_PATH . 'cli/record.php ' . intval($rRow['id']) . ' > /dev/null 2>/dev/null &');
        }
    }
    exec("ps ax | grep 'ffmpeg' | awk '{print \$1}'", $rPIDs);
    $db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` WHERE `to_analyze` = 1 AND `server_id` = ?', SERVER_ID);
    $rCount = $db->get_row()['count'];
    if ($rCount > 0) {
        if ($rCount <= 1000) {
            $rSteps = [0, $rCount];
        } else {
            $rSteps = range(0, $rCount, 1000);
        }
        if (!$rSteps) {
            $rSteps = array(0);
        }
        foreach ($rSteps as $rStep) {
            $db->query('SELECT t1.*,t2.* FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type AND t3.live = 0 WHERE t1.to_analyze = 1 AND t1.server_id = ? LIMIT ' . $rStep . ', 1000', SERVER_ID);
            if (0 >= $db->num_rows()) {
            } else {
                $rRows = $db->get_rows();
                foreach ($rRows as $rRow) {
                    echo '[*] Checking Movie ' . $rRow['stream_display_name'] . ' ' . "\t\t" . '---> ';
                    if (in_array($rRow['pid'], $rPIDs)) {
                        echo 'ENCODING...' . "\n";
                    } else {
                        $rMoviePath = VOD_PATH . intval($rRow['stream_id']) . '.' . escapeshellcmd($rRow['target_container']);
                        if ($rFFProbee = CoreUtilities::probeStream($rMoviePath)) {
                            $rDuration = (isset($rFFProbee['duration']) ? $rFFProbee['duration'] : 0);
                            sscanf($rDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
                            $rSeconds = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
                            $rSize = filesize($rMoviePath);
                            $rBitrate = round(($rSize * 0.008) / $rSeconds);
                            $rMovieProperties = json_decode($rRow['movie_properties'], true);
                            if (is_array($rMovieProperties)) {
                            } else {
                                $rMovieProperties = array();
                            }
                            if (isset($rMovieProperties['duration_secs']) && $rSeconds == $rMovieProperties['duration_secs']) {
                            } else {
                                $rMovieProperties['duration_secs'] = $rSeconds;
                                $rMovieProperties['duration'] = $rDuration;
                            }
                            if (isset($rMovieProperties['video']) && $rFFProbee['codecs']['video']['codec_name'] == $rMovieProperties['video']) {
                            } else {
                                $rMovieProperties['video'] = $rFFProbee['codecs']['video'];
                            }
                            if (isset($rMovieProperties['audio']) && $rFFProbee['codecs']['audio']['codec_name'] == $rMovieProperties['audio']) {
                            } else {
                                $rMovieProperties['audio'] = $rFFProbee['codecs']['audio'];
                            }
                            if (!CoreUtilities::$rSettings['extract_subtitles']) {
                            } else {
                                if (isset($rMovieProperties['subtitle']) && $rFFProbee['codecs']['subtitle']['codec_name'] == $rMovieProperties['subtitle']) {
                                } else {
                                    $rMovieProperties['subtitle'] = $rFFProbee['codecs']['subtitle'];
                                }
                            }
                            if (isset($rMovieProperties['bitrate']) && $rBitrate == $rMovieProperties['bitrate']) {
                            } else {
                                if (0 < $rBitrate) {
                                    $rMovieProperties['bitrate'] = $rBitrate;
                                } else {
                                    $rBitrate = $rMovieProperties['bitrate'];
                                }
                            }
                            if (!(isset($rFFProbee['codecs']['subtitle']) && CoreUtilities::$rSettings['extract_subtitles'])) {
                            } else {
                                $i = 0;
                                foreach ($rFFProbee['codecs']['subtitle'] as $rSubtitle) {
                                    CoreUtilities::extractSubtitle($rRow['stream_id'], $rMoviePath, $i);
                                    $i++;
                                }
                            }
                            $rCompatible = 0;
                            $rAudioCodec = $rVideoCodec = $rResolution = null;
                            if (!$rFFProbee) {
                            } else {
                                $rCompatible = intval(CoreUtilities::checkCompatibility($rFFProbee));
                                $rAudioCodec = ($rFFProbee['codecs']['audio']['codec_name'] ?: null);
                                $rVideoCodec = ($rFFProbee['codecs']['video']['codec_name'] ?: null);
                                $rResolution = ($rFFProbee['codecs']['video']['height'] ?: null);
                                if (!$rResolution) {
                                } else {
                                    $rResolution = CoreUtilities::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
                                }
                            }
                            $db->query('UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ?', json_encode($rMovieProperties, JSON_UNESCAPED_UNICODE), $rRow['stream_id']);
                            $db->query('UPDATE `streams_servers` SET `bitrate` = ?,`to_analyze` = 0,`stream_status` = 0,`stream_info` = ?,`audio_codec` = ?,`video_codec` = ?,`resolution` = ?,`compatible` = ? WHERE `server_stream_id` = ?', $rBitrate, json_encode($rFFProbee, JSON_UNESCAPED_UNICODE), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rRow['server_stream_id']);

                            // Generate fMP4 HLS playlist for instant VOD playback
                            /* 
                            $rHLSDir = '/home/xc_vm/streams/vod_hls/' . intval($rRow['stream_id']) . '/';
                            if (!is_dir($rHLSDir)) {
                                mkdir($rHLSDir, 0755, true);
                            }

                            $rHLSPlaylist = $rHLSDir . 'playlist.m3u8';
                            if (!file_exists($rHLSPlaylist)) {
                                echo "\t" . 'Generating fMP4 HLS...' . "\n";
                                $rFFmpeg = (CoreUtilities::$rFFMPEG_CPU ?: FFMPEG_BIN_44);
                                $rCmd = 'nice -n 19 ' . $rFFmpeg . ' -y -i ' . escapeshellarg($rMoviePath) .
                                    ' -c copy' .
                                    ' -f hls' .
                                    ' -hls_segment_type fmp4' .
                                    ' -hls_time 4' .
                                    ' -hls_list_size 0' .
                                    ' -hls_playlist_type vod' .
                                    ' -hls_fmp4_init_filename init.mp4' .
                                    ' -hls_segment_filename ' . escapeshellarg($rHLSDir . 'seg_%d.m4s') .
                                    ' -threads 1' .
                                    ' -max_muxing_queue_size 256' .
                                    ' ' . escapeshellarg($rHLSPlaylist) .
                                    ' >/dev/null 2>&1 &';
                                shell_exec($rCmd);
                            }
                            */

                            echo 'VALID' . "\n";
                        } else {
                            $db->query('UPDATE `streams_servers` SET `to_analyze` = 0,`stream_status` = 1 WHERE `server_stream_id` = ?', $rRow['server_stream_id']);
                            echo 'BROKEN' . "\n";
                        }
                        CoreUtilities::updateStream($rRow['stream_id']);
                    }
                }
            }
        }
    }
}
function shutdown()
{
    global $db;
    global $rIdentifier;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
