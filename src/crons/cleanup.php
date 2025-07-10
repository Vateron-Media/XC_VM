<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
        cli_set_process_title('XC_VM[Cleanup]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rTimeout = 3600;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        loadCron();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadCron() {
    global $db;
    if (intval(CoreUtilities::$rSettings['cleanup']) != 1) {
    } else {
        $rStreams = array();
        $db->query('SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` IN (1,3,4) AND `streams_servers`.`server_id` = ?;', SERVER_ID);
        foreach ($db->get_rows() as $rRow) {
            $rStreams[] = intval($rRow['id']);
        }
        foreach (glob(STREAMS_PATH . '*') as $rFilename) {
            $rID = intval(rtrim(explode('.', basename($rFilename))[0], '_')) . "\n";
            if (0 >= $rID || in_array($rID, $rStreams)) {
            } else {
                echo 'Deleting: ' . $rFilename . "\n";
                unlink($rFilename);
            }
        }
        $rArchive = array();
        $db->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `type` = 1 AND `tv_archive_server_id` = ? AND `tv_archive_duration` > 0;', SERVER_ID);
        foreach ($db->get_rows() as $rRow) {
            $rArchive[intval($rRow['id'])] = $rRow['tv_archive_duration'];
        }
        date_default_timezone_set('UTC');
        foreach (glob(ARCHIVE_PATH . '*') as $rStreamID) {
            $rID = intval(basename($rStreamID));
            if (!(0 < $rID && is_dir(ARCHIVE_PATH . $rID))) {
            } else {
                if (!isset($rArchive[$rID])) {
                    echo 'Deleting: ' . $rStreamID . "\n";
                    exec('rm -rf ' . $rStreamID);
                } else {
                    $rDuration = $rArchive[$rID];
                    $rDeleteBefore = time() - $rDuration * 86400 + 3600;
                    foreach (glob(ARCHIVE_PATH . $rID . '/*') as $rArchiveFile) {
                        list($rDate, $rTime) = explode(':', explode('.', basename($rArchiveFile))[0]);
                        list($rHour, $rMinute) = explode('-', $rTime);
                        $rFileTime = strtotime($rDate . ' ' . $rHour . ':' . $rMinute . ':00');
                        if ($rFileTime >= $rDeleteBefore) {
                        } else {
                            echo 'Deleting: ' . $rArchiveFile . "\n";
                            unlink($rArchiveFile);
                        }
                    }
                }
            }
        }
        $rCreated = array();
        $db->query('SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 3 AND `streams_servers`.`server_id` = ?;', SERVER_ID);
        foreach ($db->get_rows() as $rRow) {
            $rCreated[] = intval($rRow['id']);
        }
        foreach (glob(CREATED_PATH . '*') as $rFilename) {
            $rID = intval(rtrim(explode('.', basename($rFilename))[0], '_')) . "\n";
            if (0 >= $rID || in_array($rID, $rCreated)) {
            } else {
                echo 'Deleting: ' . $rFilename . "\n";
                unlink($rFilename);
            }
        }
    }
    if (intval(CoreUtilities::$rSettings['check_vod']) != 1) {
    } else {
        $db->query('SELECT `server_stream_id`, `id`, `target_container`, `movie_properties`, `stream_status` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `server_id` = ? AND `type` IN (2,5) AND `streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0;', SERVER_ID);
        if (0 >= $db->num_rows()) {
        } else {
            $rRows = $db->get_rows();
            foreach ($rRows as $rRow) {
                $rMoviePath = VOD_PATH . $rRow['id'] . '.' . $rRow['target_container'];
                if ($rRow['stream_status'] == 0) {
                    if (file_exists($rMoviePath)) {
                    } else {
                        echo 'BAD MOVIE' . "\n";
                        $db->query('UPDATE `streams_servers` SET `stream_status` = 1 WHERE `server_stream_id` = ?', $rRow['server_stream_id']);
                        CoreUtilities::updateStream($rRow['id']);
                    }
                } else {
                    if ($rRow['stream_status'] != 1) {
                    } else {
                        if (!(file_exists($rMoviePath) && ($rFFProbee = CoreUtilities::probeStream($rMoviePath)))) {
                        } else {
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
                            $db->query('UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ?', json_encode($rMovieProperties, JSON_UNESCAPED_UNICODE), $rRow['id']);
                            $db->query('UPDATE `streams_servers` SET `bitrate` = ?,`to_analyze` = 0,`stream_status` = 0,`stream_info` = ?, `audio_codec` = ?, `video_codec` = ?, `resolution` = ?, `compatible` = ? WHERE `server_stream_id` = ?', $rBitrate, json_encode($rFFProbee, JSON_UNESCAPED_UNICODE), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rRow['server_stream_id']);
                            CoreUtilities::updateStream($rRow['id']);
                            echo 'VALID MOVIE' . "\n";
                        }
                    }
                }
            }
        }
        $db->query("SELECT `id`, `stream_display_name`, `server_stream_id` FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND JSON_CONTAINS(t3.cchannel_rsources, t1.stream_source) AND JSON_CONTAINS(t1.stream_source, t3.cchannel_rsources) AND t3.pids_create_channel = '[]';", SERVER_ID);
        if (0 >= $db->num_rows()) {
        } else {
            $rStreams = $db->get_rows();
            foreach ($rStreams as $rStream) {
                echo "\n\n" . '[*] Checking Channel ' . $rStream['stream_display_name'] . "\n";
                if (file_exists(CREATED_PATH . $rStream['id'] . '_.list')) {
                    $rList = explode("\n", file_get_contents(CREATED_PATH . $rStream['id'] . '_.list'));
                    $rExisting = glob(CREATED_PATH . $rStream['id'] . '*.*');
                    $rFailure = false;
                    $rActualFiles = array();
                    foreach ($rList as $rItem) {
                        $rFilename = trim(explode("'", explode("'", $rItem)[1])[0]);
                        if (0 >= strlen($rFilename)) {
                        } else {
                            if (in_array($rFilename, $rExisting)) {
                                $rActualFiles[] = $rFilename;
                            } else {
                                $rFailure = true;
                            }
                        }
                    }
                    if (!$rFailure) {
                    } else {
                        echo 'BAD CHANNEL' . "\n";
                        $db->query('UPDATE `streams_servers` SET `cchannel_rsources` = ? WHERE `server_stream_id` = ?;', json_encode($rActualFiles, JSON_UNESCAPED_UNICODE), $rStream['server_stream_id']);
                        CoreUtilities::updateStream($rStream['id']);
                    }
                } else {
                    echo 'BAD CHANNEL' . "\n";
                    $db->query("UPDATE `streams_servers` SET `cchannel_rsources` = '[]' WHERE `server_stream_id` = ?;", $rStream['server_stream_id']);
                    CoreUtilities::updateStream($rStream['id']);
                }
            }
        }
    }
    $rTables = array('lines_activity' => array('keep_activity', 'date_end'), 'lines_logs' => array('keep_client', 'date'), 'login_logs' => array('keep_login', 'date'), 'streams_errors' => array('keep_errors', 'date'), 'streams_logs' => array('keep_restarts', 'date'), 'ondemand_check' => array('on_demand_scan_keep', 'date'));
    foreach ($rTables as $rTable => $rArray) {
        if (!(CoreUtilities::$rSettings[$rArray[0]] && 0 < CoreUtilities::$rSettings[$rArray[0]])) {
        } else {
            $rDeleteBefore = time() - intval(CoreUtilities::$rSettings[$rArray[0]]);
            $db->query('DELETE FROM `' . $rTable . '` WHERE `' . $rArray[1] . '` < ?;', $rDeleteBefore);
        }
    }
}
function shutdown() {
    global $db;
    global $rIdentifier;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
    @unlink($rIdentifier);
}
