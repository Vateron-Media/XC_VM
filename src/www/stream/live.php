<?php
set_time_limit(0);
require_once "init.php";
register_shutdown_function([ShutdownHandler::class, 'handle'], 'live');
unset($rSettings["watchdog_data"]);
unset($rSettings["server_hardware"]);

StreamAuthMiddleware::sendStreamHeaders($rSettings, $rServers);

$rCreateExpiration = ($rSettings["create_expiration"] ?: 5);
$rProxyID = NULL;
$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = (empty($_SERVER["HTTP_USER_AGENT"]) ? '' : htmlentities(trim($_SERVER["HTTP_USER_AGENT"])));
$rConSpeedFile = NULL;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rStartTime = time();
$rVideoCodec = NULL;

if (isset($rRequest["token"])) {
    $rTokenData = StreamAuthMiddleware::decryptToken($rRequest["token"], $rSettings, $rServers, $rIP);

    if (!isset($rTokenData["video_path"])) {
        if (isset($rTokenData["hmac_id"])) {
            $rIsHMAC = $rTokenData["hmac_id"];
            $rIdentifier = $rTokenData["identifier"];
            $rUsername = null;
            $rPassword = null;
        } else {
            $rIsHMAC = null;
            $rIdentifier = null;
            $rUsername = $rTokenData["username"];
            $rPassword = $rTokenData["password"];
        }

        $rStreamID = intval($rTokenData["stream_id"]);
        $rExtension = $rTokenData["extension"];
        $rChannelInfo = $rTokenData["channel_info"];
        $rUserInfo = $rTokenData["user_info"];
        $rActivityStart = $rTokenData["activity_start"];
        $rExternalDevice = $rTokenData["external_device"];
        $rVideoCodec = $rTokenData["video_codec"];
        $rCountryCode = $rTokenData["country_code"];
        $rPlaylist = "";
    } else {
        header("Content-Type: video/mp2t");
        readfile($rTokenData["video_path"]);

        exit();
    }
} else {
    generateError("NO_TOKEN_SPECIFIED");
}

if (!in_array($rExtension, array('ts', 'm3u8'))) {
    $rExtension = $rSettings["api_container"];
}

if (($rChannelInfo["proxy"] ?? false) && $rExtension != "ts") {
    generateError("USER_DISALLOW_EXT");
}

if ($rSettings["use_buffer"] == 0) {
    header("X-Accel-Buffering: no");
}

if ($rChannelInfo) {
    if ($rChannelInfo["originator_id"]) {
        $rServerID = $rChannelInfo["originator_id"];
        $rProxyID = $rChannelInfo["redirect_id"];
    } else {
        $rServerID = ($rChannelInfo["redirect_id"] ?: SERVER_ID);
        $rProxyID = NULL;
    }

    if (file_exists(STREAMS_PATH . $rStreamID . "_.pid")) {
        $rChannelInfo["pid"] = intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid"));
    }

    if (file_exists(STREAMS_PATH . $rStreamID . "_.monitor")) {
        $rChannelInfo["monitor_pid"] = intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor"));
    }

    if ($rSettings["on_demand_instant_off"] && $rChannelInfo["on_demand"] == 1) {
        ConnectionTracker::addToQueue($rStreamID, $rPID);
    }

    if (!ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)) {
        $rChannelInfo["pid"] = NULL;

        if ($rChannelInfo["on_demand"] == 1) {
            if (!ProcessManager::isMonitorAlive($rChannelInfo["monitor_pid"], $rStreamID)) {
                if (($rActivityStart + $rCreateExpiration) - intval($rServers[SERVER_ID]["time_offset"]) < time()) {
                    generateError("TOKEN_EXPIRED");
                }

                ProcessManager::startMonitor($rStreamID);

                if (AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rStreamID . "_.monitor", 300, 10)) {
                    $rChannelInfo["monitor_pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor")) ?: NULL);
                }
            }

            if (!$rChannelInfo["monitor_pid"]) {
                // print('show_not_on_air_video_1');
                OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }

            for ($rRetries = 0; !AsyncFileOperations::awaitFileExists(STREAMS_PATH . intval($rStreamID) . "_.pid", 1, 10) && $rRetries < 300; $rRetries++) {
                AsyncFileOperations::efficientSleep(10000);
            }
            $rChannelInfo["pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid")) ?: NULL);

            if (!$rChannelInfo["pid"]) {
                // print('show_not_on_air_video_2');
                OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
        } else {
            if ($rChannelInfo["proxy"]) {
                if (!($rChannelInfo["monitor_pid"] && ProcessManager::isMonitorAlive($rChannelInfo["monitor_pid"], $rStreamID))) {
                    @unlink(STREAMS_PATH . $rStreamID . "_.pid");
                    ProcessManager::startProxy($rStreamID);

                    if (AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rStreamID . "_.monitor", 300, 10)) {
                        $rChannelInfo["monitor_pid"] = intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor"));
                    }
                }

                if (!$rChannelInfo["monitor_pid"]) {
                    // print('show_not_on_air_video_3');
                    OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
                }

                $rChannelInfo["pid"] = $rChannelInfo["monitor_pid"];
            } else {
                // print('show_not_on_air_video_4');
                OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
        }
    }

    if (!isset($rChannelInfo["proxy"]) || !$rChannelInfo["proxy"]) {
        $rPlaylist = STREAMS_PATH . $rStreamID . "_.m3u8";

        if ($rExtension == "ts") {
            if (!file_exists($rPlaylist)) {
                $rFirstTS = STREAMS_PATH . $rStreamID . "_0.ts";
                $rFirstAlt = STREAMS_PATH . $rStreamID . "_0.m4s";
                $maxRetries = intval($rSettings["on_demand_wait_time"]) * 10;

                // Use async file monitoring instead of busy-wait loop
                $foundFile = AsyncFileOperations::awaitAnyFileExists([$rFirstTS, $rFirstAlt], $maxRetries, 100);

                if (!$foundFile) {
                    generateError("WAIT_TIME_EXPIRED");
                } else {
                    // Verify stream is still running
                    if (!(ProcessManager::isMonitorAlive($rChannelInfo["monitor_pid"], $rStreamID) && ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID))) {
                        OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
                    }
                }
            }
        } else {
            $maxRetries = intval($rSettings["on_demand_wait_time"]) * 10;
            $foundFile = AsyncFileOperations::awaitAnyFileExists([$rPlaylist, STREAMS_PATH . $rStreamID . "_.m3u8"], $maxRetries, 100);

            if (!$foundFile) {
                generateError("WAIT_TIME_EXPIRED");
            }
        }

        if (!$rChannelInfo["pid"]) {
            $pidContent = AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid");
            $rChannelInfo["pid"] = $pidContent ? (intval($pidContent) ?: NULL) : NULL;
        }
    }

    $rExecutionTime = time() - $rStartTime;
    $rExpiresAt = ($rActivityStart + $rCreateExpiration + $rExecutionTime) - intval($rServers[SERVER_ID]["time_offset"]);

    if ($rSettings["redis_handler"]) {
        RedisManager::ensureConnected();
    } else {
        DatabaseFactory::connect();
    }

    if ($rSettings["disallow_2nd_ip_con"] && !$rUserInfo["is_restreamer"] && ($rUserInfo["max_connections"] <= $rSettings["disallow_2nd_ip_max"] && 0 < $rUserInfo["max_connections"] || $rSettings["disallow_2nd_ip_max"] == 0)) {
        $rAcceptIP = NULL;

        if ($rSettings["redis_handler"]) {
            $rConnections = ConnectionTracker::getLineConnections($rUserInfo["id"], true);

            if (count($rConnections) > 0) {
                $rDate = array_column($rConnections, "date_start");
                array_multisort($rDate, SORT_ASC, $rConnections);
                $rAcceptIP = $rConnections[0]["user_ip"];
            }
        } else {
            $db->query('SELECT `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0 ORDER BY `activity_id` DESC LIMIT 1;', $rUserInfo["id"]);

            if ($db->num_rows() == 1) {
                $rAcceptIP = $db->get_row()["user_ip"];
            }
        }

        $rIPMatch = ($rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rAcceptIP), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rAcceptIP == $rIP);

        if ($rAcceptIP && !$rIPMatch) {
            DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "USER_ALREADY_CONNECTED", $rIP);
            OffAirHandler::showVideoServer($rSettings, $rServers, "show_connected_video", "connected_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
        }
    }

    switch ($rExtension) {
        case "m3u8":
            if ($rSettings["redis_handler"]) {
                $rConnection = ConnectionTracker::getConnection($rTokenData["uuid"]);
            } else {
                if (isset($rTokenData["adaptive"])) {
                    $db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `container` = 'hls' AND `hls_end` = 0", $rTokenData["uuid"], $rUserInfo["id"]);
                } else {
                    if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                        $db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `server_id` = ? AND `container` = 'hls' AND `stream_id` = ? AND `hls_end` = 0", $rTokenData["uuid"], $rUserInfo["id"], $rServerID, $rStreamID);
                    } else {
                        $db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `hmac_id` = ? AND `hmac_identifier` = ? AND `server_id` = ? AND `container` = 'hls' AND `stream_id` = ? AND `hls_end` = 0", $rTokenData["uuid"], $rIsHMAC, $rIdentifier, $rServerID, $rStreamID);
                    }
                }
                if ($db->num_rows() > 0) {
                    $rConnection = $db->get_row();
                }
            }
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }

                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    if ($rSettings["redis_handler"]) {
                        $rConnectionData = array("user_id" => $rUserInfo["id"], "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => "hls", "pid" => NULL, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"]), "on_demand" => $rChannelInfo["on_demand"], "identity" => $rUserInfo["id"], "uuid" => $rTokenData["uuid"]);
                        $rResult = ConnectionTracker::createConnection($rConnectionData);
                    } else {
                        $rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`,`hls_last_read`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?);', $rUserInfo["id"], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'hls', NULL, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice, time() - intval($rServers[SERVER_ID]["time_offset"]));
                    }
                } else {
                    if ($rSettings["redis_handler"]) {
                        $rConnectionData = array("hmac_id" => $rIsHMAC, "hmac_identifier" => $rIdentifier, "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => "hls", "pid" => NULL, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"]), "on_demand" => $rChannelInfo["on_demand"], "identity" => $rIsHMAC . "_" . $rIdentifier, "uuid" => $rTokenData["uuid"]);
                        $rResult = ConnectionTracker::createConnection($rConnectionData);
                    } else {
                        $rResult = $db->query('INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`,`hls_last_read`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);', $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, 'hls', NULL, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice, time() - intval($rServers[SERVER_ID]["time_offset"]));
                    }
                }
            } else {
                $rIPMatch = ($rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rConnection["user_ip"]), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rConnection["user_ip"] == $rIP);

                if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
                    DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }

                if ($rSettings["redis_handler"]) {
                    $rChanges = array("server_id" => $rServerID, "proxy_id" => $rProxyID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"]));

                    if ($rConnection = ConnectionTracker::updateConnection($rConnection, $rChanges, "open")) {
                        $rResult = true;
                    } else {
                        $rResult = false;
                    }
                } else {
                    $rResult = $db->query('UPDATE `lines_live` SET `hls_last_read` = ?, `hls_end` = 0, `server_id` = ?, `proxy_id` = ? WHERE `activity_id` = ?', time() - intval($rServers[SERVER_ID]["time_offset"]), $rServerID, $rProxyID, $rConnection["activity_id"]);
                }
            }

            if (!$rResult) {
                DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP);
                generateError("LINE_CREATE_FAIL");
            }

            StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);

            if ($rSettings["redis_handler"]) {
                RedisManager::closeInstance();
            } else {
                DatabaseFactory::close();
            }

            $rHLS = HLSGenerator::generateHLS($rSettings, $rPlaylist, (isset($rUsername) ? $rUsername : NULL), (isset($rPassword) ? $rPassword : NULL), $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, intval($rChannelInfo["on_demand"]), $rServerID, $rProxyID);

            if ($rHLS) {
                touch(CONS_TMP_PATH . $rTokenData["uuid"]);
                ob_end_clean();
                header("Content-Type: application/x-mpegurl");
                header("Content-Length: " . strlen($rHLS));
                header("Cache-Control: no-store, no-cache, must-revalidate");
                echo $rHLS;
            } else {
                // print('show_not_on_air_video_6');
                OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }

            exit();

        default:
            if ($rSettings["redis_handler"]) {
                $rConnection = ConnectionTracker::getConnection($rTokenData["uuid"]);
            } else {
                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    $db->query('SELECT `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?;', $rTokenData["uuid"], $rUserInfo["id"], $rServerID, $rExtension, $rStreamID);
                } else {
                    $db->query('SELECT `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `hmac_id` = ? AND `hmac_identifier` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?;', $rTokenData["uuid"], $rIsHMAC, $rIdentifier, $rServerID, $rExtension, $rStreamID);
                }

                if ($db->num_rows() > 0) {
                    $rConnection = $db->get_row();
                }
            }
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }
                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    if ($rSettings["redis_handler"]) {
                        $rConnectionData = array("user_id" => $rUserInfo["id"], "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => $rExtension, "pid" => $rPID, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"]), "on_demand" => $rChannelInfo["on_demand"], "identity" => $rUserInfo["id"], "uuid" => $rTokenData["uuid"]);
                        $rResult = ConnectionTracker::createConnection($rConnectionData);
                    } else {
                        $rResult = $db->query('INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)', $rUserInfo["id"], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, $rExtension, $rPID, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice);
                    }
                } else {
                    if ($rSettings["redis_handler"]) {
                        $rConnectionData = array("hmac_id" => $rIsHMAC, "hmac_identifier" => $rIdentifier, "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => $rExtension, "pid" => $rPID, 'date_start' => $rActivityStart, 'geoip_country_code' => $rCountryCode, 'isp' => $rUserInfo["con_isp_name"], 'external_device' => $rExternalDevice, 'hls_end' => 0, 'hls_last_read' => time() - intval($rServers[SERVER_ID]["time_offset"]), 'on_demand' => $rChannelInfo["on_demand"], 'identity' => $rIsHMAC . '_' . $rIdentifier, 'uuid' => $rTokenData["uuid"]);
                        $rResult = ConnectionTracker::createConnection($rConnectionData);
                    } else {
                        $rResult = $db->query('INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, $rExtension, $rPID, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice);
                    }
                }
            } else {
                $rIPMatch = ($rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rConnection["user_ip"]), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rConnection["user_ip"] == $rIP);

                if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
                    DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }

                if (ProcessManager::isRunning($rConnection["pid"], "php-fpm") && $rPID != $rConnection["pid"] && is_numeric($rConnection["pid"]) && 0 < $rConnection["pid"]) {
                    posix_kill(intval($rConnection["pid"]), 9);
                }

                if ($rSettings["redis_handler"]) {
                    $rChanges = array("pid" => $rPID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"]));

                    if ($rConnection = ConnectionTracker::updateConnection($rConnection, $rChanges, "open")) {
                        $rResult = true;
                    } else {
                        $rResult = false;
                    }
                } else {
                    $rResult = $db->query('UPDATE `lines_live` SET `hls_end` = 0, `hls_last_read` = ?, `pid` = ? WHERE `activity_id` = ?;', time() - intval($rServers[SERVER_ID]["time_offset"]), $rPID, $rConnection["activity_id"]);
                }
            }

            if (!$rResult) {
                DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP);
                generateError("LINE_CREATE_FAIL");
            }

            StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);

            if ($rSettings["redis_handler"]) {
                RedisManager::closeInstance();
            } else {
                DatabaseFactory::close();
            }

            $rCloseCon = true;

            if ($rSettings["monitor_connection_status"]) {
                ob_implicit_flush(true);
                while (ob_get_level()) ob_end_clean();
            }

            touch(CONS_TMP_PATH . $rTokenData["uuid"]);

            if ($rChannelInfo["proxy"]) {
                // ────────────────────────────────────────────────────────────────
                // Proxy-режим — оставляем почти как было (usleep 100 мс терпимо)
                // ────────────────────────────────────────────────────────────────
                header("Content-type: video/mp2t");

                if (!file_exists(CONS_TMP_PATH . $rStreamID . "/")) {
                    mkdir(CONS_TMP_PATH . $rStreamID);
                }

                $rSocketFile = CONS_TMP_PATH . $rStreamID . "/" . $rTokenData["uuid"];
                $rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
                @unlink($rSocketFile);
                socket_bind($rSocket, $rSocketFile);
                socket_set_option($rSocket, SOL_SOCKET, SO_RCVTIMEO, array("sec" => 20, "usec" => 0));
                socket_set_nonblock($rSocket);
                $rTotalFails = 200;
                $rFails = 0;

                while ($rFails <= $rTotalFails) {
                    // MPEG-TS packet size = 188 bytes
                    // 64 packets per read:
                    // 188 * 64 = 12032 bytes (~12 KB)
                    $rBuffer = socket_read($rSocket, 188 * 64);

                    if ($rBuffer !== false && $rBuffer !== '') {
                        $rFails = 0;
                        echo $rBuffer;
                        flush();
                    } else {
                        $rFails++;
                        usleep(80000);          // 80 мс вместо 100 мс
                    }
                }
                // cleanup
                socket_close($rSocket);
                @unlink($rSocketFile);
                exit;
            }

            // ────────────────────────────────────────────────────────────────
            // Основной TS-поток (не proxy)
            // ────────────────────────────────────────────────────────────────
            header("Content-Type: video/mp2t");

            // File for storing the current transfer rate
            $rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData["uuid"];

            // Checking if the playlist exists
            if (file_exists($rPlaylist)) {
                // Define the prebuffer based on the user type
                if ($rUserInfo["is_restreamer"]) {
                    if ($rTokenData["prebuffer"]) {
                        $rPrebuffer = $rSegmentSettings["seg_time"];
                    } else {
                        $rPrebuffer = $rSettings["restreamer_prebuffer"];
                    }
                } else {
                    $rPrebuffer = $rSettings["client_prebuffer"];
                }

                // Get stream duration if available
                if (file_exists(STREAMS_PATH . $rStreamID . "_.dur")) {
                    $rDuration = intval(file_get_contents(STREAMS_PATH . $rStreamID . "_.dur"));

                    // If duration is greater than segment time, adjust segment time
                    if ($rSegmentSettings["seg_time"] < $rDuration) {
                        $rSegmentSettings["seg_time"] = $rDuration;
                    }
                }

                // Get list of segments for current prebuffer
                $rSegments = SegmentReader::getPlaylistSegments($rPlaylist, $rPrebuffer, $rSegmentSettings["seg_time"]);
            } else {
                $rSegments = NULL;
            }

            // if segments exist, send them to the client
            if (!is_null($rSegments)) {
                if (is_array($rSegments)) {
                    $rBytes = 0;
                    $rStartTime = time();

                    // Send segments to the client
                    foreach ($rSegments as $rSegment) {
                        $segmentPath = STREAMS_PATH . $rSegment;
                        if (file_exists($segmentPath)) {
                            $rBytes += readfile($segmentPath); // Read and output the segment
                        } else {
                            exit(); // Segment not found, exit
                        }
                    }

                    // Calculating the transfer rate
                    $rTotalTime = max(0.1, time() - $rStartTime);
                    $rDivergence = intval($rBytes / $rTotalTime / 1024);
                    file_put_contents($rConSpeedFile, $rDivergence);

                    // Defining the current segment
                    preg_match('/_(.*)\\./', array_pop($rSegments), $rCurrentSegment);
                    $rCurrent = $rCurrentSegment[1];
                } else {
                    $rCurrent = $rSegments; // If segments are not an array
                }
            } else {
                if (!file_exists($rPlaylist)) {
                    $rCurrent = -1; // Playlist does not exist
                } else {
                    exit();
                }
            }

            // Settings for waiting for the next segment
            $rFails = 0;
            $rTotalFails = max(
                $rSegmentSettings["seg_time"] * 2,
                intval($rSettings["segment_wait_time"]) ?: 20
            );

            $rMonitorCheck = $rLastCheck = time();

            while (true) {
                $rSegmentFile = sprintf("%d_%d.ts", $rChannelInfo["stream_id"], $rCurrent + 1);
                $rNextSegment = sprintf("%d_%d.ts", $rChannelInfo["stream_id"], $rCurrent + 2);

                // Wait for the next segment to appear - using non-blocking async check
                $segmentFound = AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rSegmentFile, max(1, $rTotalFails), 1000);

                if ($segmentFound && file_exists(STREAMS_PATH . $rSegmentFile)) {
                    // We process signals if there are any
                    if (file_exists(SIGNALS_PATH . $rTokenData["uuid"])) {
                        $rSignalData = json_decode(file_get_contents(SIGNALS_PATH . $rTokenData["uuid"]), true);

                        if ($rSignalData["type"] == "signal") {
                            // Wait for the next segment - using non-blocking check
                            AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rNextSegment, max(1, $rTotalFails), 1000);
                            SignalSender::sendSignal($rFFMPEG_CPU, $rSignalData, $rSegmentFile, ($rVideoCodec ?: "h264"));
                            unlink(SIGNALS_PATH . $rTokenData["uuid"]);
                            $rCurrent++;
                        }
                    }

                    // Clear fail counter and open segment file
                    $rFails = 0;
                    $rTimeStart = time();
                    $rFP = fopen(STREAMS_PATH . $rSegmentFile, "r");

                    // Send segment data to the client with adaptive delays
                    while ($rFails <= $rTotalFails && !file_exists(STREAMS_PATH . $rNextSegment)) {
                        $rData = stream_get_line($rFP, $rSettings["read_buffer_size"]);
                        if (!empty($rData)) {
                            echo $rData;
                            $rData = "";
                            $rFails = 0;
                        } else {
                            // No data read (EOF or blocking) - avoid tight-loop, add small delay
                            AsyncFileOperations::efficientSleep(100000); // 100ms to reduce CPU spinning
                        }

                        if (ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)) {
                            AsyncFileOperations::efficientSleep(1000000); // 1 second with better CPU usage
                            $rFails++;
                        } else {
                            // Stream process died - don't spin, add small backoff delay
                            AsyncFileOperations::efficientSleep(100000); // 100ms to reduce CPU when process is dead
                        }
                    }

                    // If the segment is not fully read, send the remaining data
                    if (ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID) && $rFails <= $rTotalFails && file_exists(STREAMS_PATH . $rSegmentFile) && is_resource($rFP)) {
                        $rSegmentSize = filesize(STREAMS_PATH . $rSegmentFile);
                        $rRestSize = $rSegmentSize - ftell($rFP);
                        if ($rRestSize > 0) {
                            echo stream_get_line($rFP, $rRestSize);
                        }

                        $rTotalTime = max(0.1, time() - $rTimeStart);
                        file_put_contents($rConSpeedFile, intval($rSegmentSize / 1024 / $rTotalTime));
                    } else {
                        if (!($rUserInfo["is_restreamer"] == 1 || $rTotalFails < $rFails)) {
                            // Wait for segment recovery with non-blocking checks
                            for ($rChecks = 0; $rChecks <= $rSegmentSettings["seg_time"] && !ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID); $rChecks++) {
                                if (file_exists(STREAMS_PATH . $rStreamID . "_.pid")) {
                                    $pidContent = AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid");
                                    if ($pidContent) {
                                        $rChannelInfo["pid"] = intval($pidContent);
                                    }
                                }
                                AsyncFileOperations::efficientSleep(1000000); // 1 second
                            }

                            if ($rSegmentSettings["seg_time"] >= $rChecks && ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)) {
                                if (!file_exists(STREAMS_PATH . $rNextSegment)) {
                                    $rCurrent = -2;
                                }
                            } else {
                                exit();
                            }
                        } else {
                            exit();
                        }
                    }

                    fclose($rFP);
                    $rFails = 0;
                    $rCurrent++;

                    // Monitor connection status every 5 seconds
                    if ($rSettings["monitor_connection_status"] && 5 <= time() - $rMonitorCheck) {
                        if (connection_status() != CONNECTION_NORMAL) {
                            exit();
                        }
                        $rMonitorCheck = time();
                    }

                    // Every 5 minutes check settings
                    if (time() - $rLastCheck > 300) {
                        $rLastCheck = time();
                        $rConnection = NULL;
                        $rSettings = CacheReader::get('settings');

                        if ($rSettings["redis_handler"]) {
                            RedisManager::ensureConnected();
                            $rConnection = ConnectionTracker::getConnection($rTokenData["uuid"]);
                            RedisManager::closeInstance();
                        } else {
                            DatabaseFactory::connect();
                            $db->query('SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?', $rTokenData["uuid"]);

                            if ($db->num_rows() == 1) {
                                $rConnection = $db->get_row();
                            }

                            DatabaseFactory::close();
                        }

                        if (!is_array($rConnection) || $rConnection["hls_end"] != 0 || $rConnection["pid"] != $rPID) {
                            exit();
                        }
                    }
                } else {
                    exit(); // Segment file does not exist, exit
                }
            }
    }
} else {
    // print('show_not_on_air_video_7');
    OffAirHandler::showVideoServer($rSettings, $rServers, "show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
}
