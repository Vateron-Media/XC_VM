<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc > 1) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $recordingID = intval($argv[1]);
        checkRunning($recordingID);
        set_time_limit(0);
        cli_set_process_title('Record[' . $recordingID . ']');
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function downloadAndSaveImage($rFilename, $rImage) {
    if (!(0 < strlen($rImage) && substr(strtolower($rImage), 0, 4) == 'http')) {
    } else {
        $rExt = 'jpg';
        $rPrevPath = IMAGES_PATH . $rFilename . '.' . $rExt;
        if (file_exists($rPrevPath)) {
            return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
        }
        $rCurl = curl_init();
        curl_setopt($rCurl, CURLOPT_URL, $rImage);
        curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($rCurl, CURLOPT_TIMEOUT, 5);
        $rData = curl_exec($rCurl);
        if (0 >= strlen($rData)) {
        } else {
            $rPath = IMAGES_PATH . $rFilename . '.' . $rExt;
            file_put_contents($rPath, $rData);
            if (!file_exists($rPath)) {
            } else {
                return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
            }
        }
    }
}
function getNextOrder() {
    global $db;
    $db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');
    if ($db->num_rows() != 1) {
        return 0;
    }
    return intval($db->get_row()['order']) + 1;
}
function getBouquet($rID) {
    global $db;
    $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
    if ($db->num_rows() != 1) {
    } else {
        return $db->get_row();
    }
}
function addToBouquet($rBouquetID, $rID) {
    global $db;
    $rBouquet = getBouquet($rBouquetID);
    $rMovies = json_decode($rBouquet['bouquet_movies'], true);
    if (in_array($rID, $rMovies)) {
    } else {
        $rMovies[] = intval($rID);
    }
    $rMovies = '[' . implode(',', array_map('intval', $rMovies)) . ']';
    $db->query('UPDATE `bouquets` SET `bouquet_movies` = ? WHERE `id` = ?;', $rMovies, $rBouquetID);
}
function loadcli() {
    global $db;
    global $recordingID;
    $db->query('SELECT * FROM `recordings` WHERE `id` = ?;', $recordingID);
    if (0 < $db->num_rows()) {
        $rFails = $totalBytes = 0;
        $isComplete = false;
        $recordingData = $db->get_row();
        if ($recordingData['start'] - 60 <= time() && time() <= $recordingData['end'] || $recordingData['archive']) {
            $rPID = (file_exists(STREAMS_PATH . $recordingData['stream_id'] . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $recordingData['stream_id'] . '_.pid')) : 0);
            $rPlaylist = STREAMS_PATH . $recordingData['stream_id'] . '_.m3u8';
            if (0 < $rPID && file_exists($rPlaylist)) {
                $db->query('UPDATE `recordings` SET `status` = 1 WHERE `id` = ?;', $recordingID);
                $db->close_mysql();
                while (CoreUtilities::isStreamRunning($rPID, $recordingData['stream_id']) && file_exists($rPlaylist)) {
                    if ($recordingData['archive']) {
                        $rDuration = intval(($recordingData['end'] - $recordingData['start']) / 60);
                        $rFP = @fopen('http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/admin/timeshift?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&stream=' . $recordingData['stream_id'] . '&start=' . $recordingData['start'] . '&duration=' . $rDuration . '&extension=ts', 'r');
                    } else {
                        $rFP = @fopen('http://127.0.0.1:' . CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port'] . '/admin/live?password=' . CoreUtilities::$rSettings['live_streaming_pass'] . '&stream=' . $recordingData['stream_id'] . '&extension=ts', 'r');
                    }
                    if (!$rFP) {
                    } else {
                        echo 'Recording...' . "\n";
                        if ($recordingData['archive']) {
                            $rWriteFile = fopen(ARCHIVE_PATH . $recordingID . '.ts', 'w');
                        } else {
                            $rWriteFile = fopen(ARCHIVE_PATH . $recordingID . '.ts', 'a');
                        }
                        while (!feof($rFP)) {
                            $rData = stream_get_line($rFP, 4096);
                            if (empty($rData)) {
                            } else {
                                $totalBytes += $rData;
                                fwrite($rWriteFile, $rData);
                                fflush($rWriteFile);
                                $rFails = 0;
                            }
                            if ($recordingData['end'] > time() || $recordingData['archive']) {
                            } else {
                                $isComplete = true;
                                fclose($rWriteFile);
                            }
                        }
                        fclose($rFP);
                        if (!$recordingData['archive']) {
                        } else {
                            $isComplete = true;
                        }
                    }
                    $rFails++;
                    if ($rFails != 5) {
                        echo 'Broken pipe! Restarting...' . "\n";
                        sleep(1);
                        break;
                    }
                    if (10485760 > $totalBytes) {
                    } else {
                        $isComplete = true;
                    }
                    echo 'Too many fails!' . "\n";
                }
            } else {
                echo 'Channel is not running.' . "\n";
            }
        } else {
            echo 'Programme is not currently airing.' . "\n";
        }
        if ($db->connected) {
        } else {
            $db->db_connect();
        }
        if (!$isComplete) {
        } else {
            if (file_exists(ARCHIVE_PATH . $recordingID . '.ts') && 0 < filesize(ARCHIVE_PATH . $recordingID . '.ts')) {
                echo 'Recording complete! Converting to MP4...' . "\n";
                if (empty($recordingData['stream_icon'])) {
                } else {
                    $recordingData['stream_icon'] = downloadAndSaveImage($recordingData['stream_icon']);
                }
                $rSeconds = intval($recordingData['end'] - $recordingData['start']);
                $rImportArray = verifyPostTable('streams');
                $rImportArray['type'] = 2;
                $rImportArray['stream_source'] = '[]';
                $rImportArray['target_container'] = 'mp4';
                $rImportArray['stream_display_name'] = $recordingData['title'];
                $rImportArray['year'] = date('Y');
                $rImportArray['movie_properties'] = array('kinopoisk_url' => null, 'tmdb_id' => null, 'name' => $recordingData['title'], 'o_name' => $recordingData['title'], 'cover_big' => $recordingData['stream_icon'], 'movie_image' => $recordingData['stream_icon'], 'release_date' => date('Y-m-d', $recordingData['start']), 'episode_run_time' => intval($rSeconds / 60), 'youtube_trailer' => null, 'director' => '', 'actors' => '', 'cast' => '', 'description' => trim($recordingData['description']), 'plot' => trim($recordingData['description']), 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => '', 'genre' => '', 'backdrop_path' => array(), 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => 0);
                $rImportArray['rating'] = 0;
                $rImportArray['read_native'] = 0;
                $rImportArray['movie_symlink'] = 0;
                $rImportArray['remove_subtitles'] = 0;
                $rImportArray['transcode_profile_id'] = 0;
                $rImportArray['order'] = getNextOrder();
                $rImportArray['added'] = time();
                $rImportArray['category_id'] = '[' . implode(',', array_map('intval', json_decode($recordingData['category_id'], true))) . ']';
                $rPrepare = prepareArray($rImportArray);
                $rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                if ($db->query($rQuery, ...$rPrepare['data'])) {
                    $rInsertID = $db->last_insert_id();
                    $rRet = shell_exec(FFMPEG_BIN_40 . " -i '" . ARCHIVE_PATH . $recordingID . '.ts' . "' -c:v copy -c:a copy '" . VOD_PATH . $rInsertID . '.mp4' . "'");
                    @unlink(ARCHIVE_PATH . $recordingID . '.ts');
                    if (file_exists(VOD_PATH . $rInsertID . '.mp4')) {
                        foreach (json_decode($recordingData['bouquets'], true) as $rBouquet) {
                            addToBouquet($rBouquet, $rInsertID);
                        }
                        $db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode(array(VOD_PATH . $rInsertID . '.mp4')), $rInsertID);
                        $db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `pid`, `to_analyze`) VALUES(?, ?, NULL, 1, 1);', $rInsertID, SERVER_ID);
                        $db->query('UPDATE `recordings` SET `status` = 2, `created_id` = ? WHERE `id` = ?;', $rInsertID, $recordingID);
                    } else {
                        echo "Couldn't convert to MP4" . "\n";
                        $isComplete = false;
                    }
                } else {
                    echo 'Failed to insert into database!' . "\n";
                    $isComplete = false;
                }
            } else {
                echo 'Recording size is 0 bytes.' . "\n";
                $isComplete = false;
            }
        }
        if ($isComplete) {
        } else {
            echo 'Recording incomplete!' . "\n";
            $db->query('UPDATE `recordings` SET `status` = 3 WHERE `id` = ?;', $recordingID);
            @unlink(ARCHIVE_PATH . $recordingID . '.ts');
        }
    } else {
        echo "Recording entry doesn't exist." . "\n";
    }
}
function checkRunning($recordingID) {
    clearstatcache(true);
    if (!file_exists(ARCHIVE_PATH . $recordingID . '_.record')) {
    } else {
        $rPID = intval(file_get_contents(ARCHIVE_PATH . $recordingID . '_.record'));
    }
    if (empty($rPID)) {
        shell_exec("kill -9 `ps -ef | grep 'Record\\[" . intval($recordingID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
    } else {
        if (!file_exists('/proc/' . $rPID)) {
        } else {
            $rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
            if (!($rCommand == 'Record[' . $recordingID . ']' && is_numeric($rPID) && 0 < $rPID)) {
            } else {
                posix_kill($rPID, 9);
            }
        }
    }
    file_put_contents(ARCHIVE_PATH . $recordingID . '_.record', getmypid());
}
function preparecolumn($rValue) {
    return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
}
function prepareArray($rArray) {
    $rUpdate = $rColumns = $rPlaceholder = $rData = array();
    foreach (array_keys($rArray) as $rKey) {
        $rColumns[] = '`' . preparecolumn($rKey) . '`';
        $rUpdate[] = '`' . preparecolumn($rKey) . '` = ?';
    }
    foreach (array_values($rArray) as $rValue) {
        if (!is_array($rValue)) {
        } else {
            $rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
        }
        $rPlaceholder[] = '?';
        $rData[] = $rValue;
    }
    return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $rUpdate));
}
function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
    global $db;
    $rReturn = array();
    $db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);
    foreach ($db->get_rows() as $rRow) {
        if ($rRow['column_default'] != 'NULL') {
        } else {
            $rRow['column_default'] = null;
        }
        $rForceDefault = false;
        if ($rRow['is_nullable'] != 'NO' || $rRow['column_default']) {
        } else {
            if (in_array($rRow['data_type'], array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'))) {
                $rRow['column_default'] = 0;
            } else {
                $rRow['column_default'] = '';
            }
            $rForceDefault = true;
        }
        if (array_key_exists($rRow['column_name'], $rData)) {
            if (empty($rData[$rRow['column_name']]) && !is_numeric($rData[$rRow['column_name']]) && is_null($rRow['column_default'])) {
                $rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
            } else {
                $rReturn[$rRow['column_name']] = $rData[$rRow['column_name']];
            }
        } else {
            if ($rOnlyExisting) {
            } else {
                $rReturn[$rRow['column_name']] = $rRow['column_default'];
            }
        }
    }
    return $rReturn;
}
function shutdown() {
    global $db;
    global $recordingID;
    if (!file_exists(ARCHIVE_PATH . $recordingID . '_.record')) {
    } else {
        unlink(ARCHIVE_PATH . $recordingID . '_.record');
    }
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
