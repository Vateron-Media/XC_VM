<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        set_time_limit(0);
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rMethod = (1 < count($argv) ? $argv[1] : null);
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function loadcli() {
    global $db;
    global $rMethod;
    global $rID;
    switch ($rMethod) {
        case 'images':
            $rImages = array();
            $db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
            $rCount = $db->get_row()['count'];
        if ($rCount > 0) {
                $rSteps = range(0, $rCount, 1000);
                if ($rSteps) {
                } else {
                    $rSteps = array(0);
                }
                foreach ($rSteps as $rStep) {
                    try {
                        $db->query('SELECT `stream_icon`, `movie_properties` FROM `streams` LIMIT ' . $rStep . ', 1000;');
                        $rResults = $db->get_rows();
                        foreach ($rResults as $rResult) {
                            $rProperties = json_decode($rResult['movie_properties'], true);
                            if (empty($rResult['stream_icon']) || substr($rResult['stream_icon'], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rResult['stream_icon'];
                            }
                            if (empty($rProperties['movie_image']) || substr($rProperties['movie_image'], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rProperties['movie_image'];
                            }
                            if (empty($rProperties['cover_big']) || substr($rProperties['cover_big'], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rProperties['cover_big'];
                            }
                            if (empty($rProperties['backdrop_path'][0]) || substr($rProperties['backdrop_path'][0], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rProperties['backdrop_path'][0];
                            }
                        }
                    } catch (Exception $e) {
                        echo 'Error: ' . $e . "\n";
                    }
                }
            }
            $db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
            $rCount = $db->get_row()['count'];
        if ($rCount > 0) {
                $rSteps = range(0, $rCount, 1000);
                if ($rSteps) {
                } else {
                    $rSteps = array(0);
                }
                foreach ($rSteps as $rStep) {
                    try {
                        $db->query('SELECT `cover`, `cover_big` FROM `streams_series` LIMIT ' . $rStep . ', 1000;');
                        $rResults = $db->get_rows();
                        foreach ($rResults as $rResult) {
                            if (empty($rResult['cover']) || substr($rResult['cover'], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rResult['cover'];
                            }
                            if (empty($rResult['cover_big']) || substr($rResult['cover_big'], 0, 2) != 's:') {
                            } else {
                                $rImages[] = $rResult['cover_big'];
                            }
                        }
                    } catch (Exception $e) {
                        echo 'Error: ' . $e . "\n";
                    }
                }
            }
            $rImages = array_unique($rImages);
            foreach ($rImages as $rImage) {
                $rSplit = explode(':', $rImage, 3);
                if (intval($rSplit[1]) != SERVER_ID) {
                } else {
                    $rImageSplit = explode('/', $rSplit[2]);
                    $rPathInfo = pathinfo($rImageSplit[count($rImageSplit) - 1]);
                    $rImage = $rPathInfo['filename'];
                    $rOriginalURL = CoreUtilities::decryptData($rImage, CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
                    if (empty($rOriginalURL) || substr($rOriginalURL, 0, 4) != 'http') {
                    } else {
                        if (file_exists(IMAGES_PATH . $rPathInfo['basename'])) {
                        } else {
                            echo 'Downloading: ' . $rOriginalURL . "\n";
                            CoreUtilities::downloadImage($rOriginalURL);
                        }
                    }
                }
            }
            break;
        case 'duplicates':
            $rGroups = $rStreamIDs = array();
            $db->query('SELECT `a`.`id`, `a`.`stream_source` FROM `streams` `a` INNER JOIN (SELECT  `stream_source`, COUNT(*) `totalCount` FROM `streams` WHERE `type` IN (2,5) GROUP BY `stream_source`) `b` ON `a`.`stream_source` = `b`.`stream_source` WHERE `b`.`totalCount` > 1;');
            foreach ($db->get_rows() as $rRow) {
                $rGroups[md5($rRow['stream_source'])][] = $rRow['id'];
            }
            foreach ($rGroups as $rID => $rGroupIDs) {
                array_shift($rGroupIDs);
                foreach ($rGroupIDs as $rStreamID) {
                    $rStreamIDs[] = intval($rStreamID);
                }
            }
            if (0 >= count($rStreamIDs)) {
            } else {
                foreach (array_chunk($rStreamIDs, 100) as $rChunk) {
                    deleteStreams($rChunk);
                }
            }
            break;
        case 'bouquets':
            $rStreamIDs = array(array(), array());
            $db->query('SELECT `id` FROM `streams`;');
            if (0 >= $db->num_rows()) {
            } else {
                foreach ($db->get_rows() as $rRow) {
                    $rStreamIDs[0][] = intval($rRow['id']);
                }
            }
            $db->query('SELECT `id` FROM `streams_series`;');
            if (0 >= $db->num_rows()) {
            } else {
                foreach ($db->get_rows() as $rRow) {
                    $rStreamIDs[1][] = intval($rRow['id']);
                }
            }
            $db->query('SELECT * FROM `bouquets` ORDER BY `bouquet_order` ASC;');
            if (0 >= $db->num_rows()) {
            } else {
                foreach ($db->get_rows() as $rBouquet) {
                    $UpdateData = array(array(), array(), array(), array());
                    foreach (json_decode($rBouquet['bouquet_channels'], true) as $rID) {
                        if (!(0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0]))) {
                        } else {
                            $UpdateData[0][] = intval($rID);
                        }
                    }
                    foreach (json_decode($rBouquet['bouquet_movies'], true) as $rID) {
                        if (!(0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0]))) {
                        } else {
                            $UpdateData[1][] = intval($rID);
                        }
                    }
                    foreach (json_decode($rBouquet['bouquet_radios'], true) as $rID) {
                        if (!(0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0]))) {
                        } else {
                            $UpdateData[2][] = intval($rID);
                        }
                    }
                    foreach (json_decode($rBouquet['bouquet_series'], true) as $rID) {
                        if (!(0 < intval($rID) && in_array(intval($rID), $rStreamIDs[1]))) {
                        } else {
                            $UpdateData[3][] = intval($rID);
                        }
                    }
                    $db->query("UPDATE `bouquets` SET `bouquet_channels` = '[" . implode(',', array_map('intval', $UpdateData[0])) . "]', `bouquet_movies` = '[" . implode(',', array_map('intval', $UpdateData[1])) . "]', `bouquet_radios` = '[" . implode(',', array_map('intval', $UpdateData[2])) . "]', `bouquet_series` = '[" . implode(',', array_map('intval', $UpdateData[3])) . "]' WHERE `id` = ?;", $rBouquet['id']);
                }
            }
            break;
    }
}
function deleteStreams($rIDs) {
    global $db;
    $db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
    $db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
    $db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_streams', 'id' => $rIDs)));
    foreach (array_keys(CoreUtilities::$rServers) as $rServerID) {
        $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
    }
    return true;
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
