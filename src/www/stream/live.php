<?php
register_shutdown_function("shutdown");
set_time_limit(0);
require_once "init.php";
unset(StreamingUtilities::$rSettings["watchdog_data"]);
unset(StreamingUtilities::$rSettings["server_hardware"]);
header("Access-Control-Allow-Origin: *");
if (!empty(StreamingUtilities::$rSettings["send_server_header"])) {
    header("Server: " . StreamingUtilities::$rSettings["send_server_header"]);
}
if (StreamingUtilities::$rSettings["send_protection_headers"]) {
    header("X-XSS-Protection: 0");
    header("X-Content-Type-Options: nosniff");
}
if (StreamingUtilities::$rSettings["send_altsvc_header"]) {
    header("Alt-Svc: h3-29=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000,h3-T051=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000,h3-Q050=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000,h3-Q046=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000,h3-Q043=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000,quic=\":" . StreamingUtilities::$rServers[SERVER_ID]["https_broadcast_port"] . "\"; ma=2592000; v=\"46,43\"");
}
if (empty(StreamingUtilities::$rSettings["send_unique_header_domain"]) && !filter_var(HOST, FILTER_VALIDATE_IP)) {
    StreamingUtilities::$rSettings["send_unique_header_domain"] = "." . HOST;
}
if (!empty(StreamingUtilities::$rSettings["send_unique_header"])) {
    $rExpires = new DateTime("+6 months", new DateTimeZone("GMT"));
    header("Set-Cookie: " . StreamingUtilities::$rSettings["send_unique_header"] . "=" . StreamingUtilities::generateString(11) . "; Domain=" . StreamingUtilities::$rSettings["send_unique_header_domain"] . "; Expires=" . $rExpires->format(DATE_RFC2822) . "; Path=/; Secure; HttpOnly; SameSite=none");
}
$rCreateExpiration = StreamingUtilities::$rSettings["create_expiration"] ?: 5;
$rProxyID = NULL;
$rIP = StreamingUtilities::getUserIP();
$rUserAgent = empty($_SERVER["HTTP_USER_AGENT"]) ? "" : htmlentities(trim($_SERVER["HTTP_USER_AGENT"]));
$rConSpeedFile = NULL;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rStartTime = time();
$rVideoCodec = NULL;
if (isset(StreamingUtilities::$rRequest["token"])) {
    $rTokenData = json_decode(StreamingUtilities::decryptData(StreamingUtilities::$rRequest["token"], StreamingUtilities::$rSettings["live_streaming_pass"], OPENSSL_EXTRA), true);
    if (!is_array($rTokenData)) {
        StreamingUtilities::clientLog(0, 0, "LB_TOKEN_INVALID", $rIP);
        generateError("LB_TOKEN_INVALID");
    }
    if (isset($rTokenData["expires"]) && $rTokenData["expires"] < time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]) {
        generateError("TOKEN_EXPIRED");
    }
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
        $rStreamID = (int) $rTokenData["stream_id"];
        $rExtension = $rTokenData["extension"];
        $rChannelInfo = $rTokenData["channel_info"];
        $rUserInfo = $rTokenData["user_info"];
        $rActivityStart = $rTokenData["activity_start"];
        $rExternalDevice = $rTokenData["external_device"];
        $rVideoCodec = $rTokenData["video_codec"];
        $rCountryCode = $rTokenData["country_code"];
    } else {
        header("Content-Type: video/mp2t");
        readfile($rTokenData["video_path"]);
        exit;
    }
} else {
    generateError("NO_TOKEN_SPECIFIED");
}
if (!in_array($rExtension, ['ts', 'm3u8'], true)) {
    $rExtension = StreamingUtilities::$rSettings['api_container'];
}
if ($rChannelInfo["proxy"] && $rExtension != "ts") {
    generateError("USER_DISALLOW_EXT");
}
if (StreamingUtilities::$rSettings["use_buffer"] == 0) {
    header("X-Accel-Buffering: no");
}
if ($rChannelInfo) {
    if ($rChannelInfo["originator_id"]) {
        $rServerID = $rChannelInfo["originator_id"];
        $rProxyID = $rChannelInfo["redirect_id"];
    } else {
        $rServerID = $rChannelInfo["redirect_id"] ?: SERVER_ID;
        $rProxyID = NULL;
    }
    if (file_exists(STREAMS_PATH . $rStreamID . "_.pid")) {
        $rChannelInfo["pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.pid");
    }
    if (file_exists(STREAMS_PATH . $rStreamID . "_.monitor")) {
        $rChannelInfo["monitor_pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.monitor");
    }
    if (StreamingUtilities::$rSettings["on_demand_instant_off"] && $rChannelInfo["on_demand"] == 1) {
        StreamingUtilities::addToQueue($rStreamID, $rPID);
    }
    if (!StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID)) {
        $rChannelInfo["pid"] = NULL;
        if ($rChannelInfo["on_demand"] == 1) {
            if (!StreamingUtilities::isMonitorRunning($rChannelInfo["monitor_pid"], $rStreamID)) {
                if (time() > $rActivityStart + $rCreateExpiration - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]) {
                    generateError("TOKEN_EXPIRED");
                }
                StreamingUtilities::startMonitor($rStreamID);
                for ($rRetries = 0; !file_exists(STREAMS_PATH . (int) $rStreamID . "_.monitor") && $rRetries < 300; $rRetries++) {
                    usleep(10000);
                }
                $rChannelInfo["monitor_pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.monitor") ?: NULL;
            }
            if (!$rChannelInfo["monitor_pid"]) {
                StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
            for ($rRetries = 0; !file_exists(STREAMS_PATH . (int) $rStreamID . "_.pid") && $rRetries < 300; $rRetries++) {
                usleep(10000);
            }
            $rChannelInfo["pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.pid") ?: NULL;
            if (!$rChannelInfo["pid"]) {
                StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
        } else {
            if ($rChannelInfo["proxy"]) {
                if (!($rChannelInfo["monitor_pid"] && StreamingUtilities::isMonitorRunning($rChannelInfo["monitor_pid"], $rStreamID))) {
                    @unlink(STREAMS_PATH . $rStreamID . "_.pid");
                    StreamingUtilities::startProxy($rStreamID);
                    for ($rRetries = 0; !file_exists(STREAMS_PATH . (int) $rStreamID . "_.monitor") && $rRetries < 300; $rRetries++) {
                        usleep(10000);
                    }
                    $rChannelInfo["monitor_pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.monitor");
                }
                if (!$rChannelInfo["monitor_pid"]) {
                    StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
                }
                $rChannelInfo["pid"] = $rChannelInfo["monitor_pid"];
            } else {
                StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
        }
    }
    if (!isset($rChannelInfo["proxy"])) {
        $rRetries = 0;
        $rPlaylist = STREAMS_PATH . $rStreamID . "_.m3u8";
        if ($rExtension == "ts") {
            if (!file_exists($rPlaylist)) {
                $rFirstTS = STREAMS_PATH . $rStreamID . "_0.ts";
                $rFP = NULL;
                while ($rRetries < (int) StreamingUtilities::$rSettings["on_demand_wait_time"] * 10) {
                    if (file_exists($rFirstTS) && !$rFP) {
                        $rFP = fopen($rFirstTS, "r");
                    }
                    if (!(StreamingUtilities::isMonitorRunning($rChannelInfo["monitor_pid"], $rStreamID) && StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID))) {
                        StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
                    }
                    if (!($rFP && fread($rFP, 1))) {
                        usleep(100000);
                        $rRetries++;
                    }
                }
                if ($rFP) {
                    fclose($rFP);
                }
            }
        } else {
            for ($rFirstTS = STREAMS_PATH . $rStreamID . "_.m3u8"; !file_exists($rPlaylist) && !file_exists($rFirstTS) && $rRetries < (int) StreamingUtilities::$rSettings["on_demand_wait_time"] * 10; $rRetries++) {
                usleep(100000);
            }
        }
        if ($rRetries == (int) StreamingUtilities::$rSettings["on_demand_wait_time"] * 10) {
            generateError("WAIT_TIME_EXPIRED");
        }
        if (!$rChannelInfo["pid"]) {
            $rChannelInfo["pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.pid") ?: NULL;
        }
    }
    $rExecutionTime = time() - $rStartTime;
    $rExpiresAt = $rActivityStart + $rCreateExpiration + $rExecutionTime - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"];
    if (StreamingUtilities::$rSettings["redis_handler"]) {
        StreamingUtilities::connectRedis();
    } else {
        StreamingUtilities::connectDatabase();
    }
    if (StreamingUtilities::$rSettings["disallow_2nd_ip_con"] && !$rUserInfo["is_restreamer"] && ($rUserInfo["max_connections"] < StreamingUtilities::$rSettings["disallow_2nd_ip_max"] && 0 < $rUserInfo["max_connections"] || StreamingUtilities::$rSettings["disallow_2nd_ip_max"] == 0)) {
        $rAcceptIP = NULL;
        if (StreamingUtilities::$rSettings["redis_handler"]) {
            $rConnections = StreamingUtilities::getConnections($rUserInfo["id"], true);
            if (count($rConnections) > 0) {
                $rDate = array_column($rConnections, "date_start");
                array_multisort($rDate, SORT_ASC, $rConnections);
                $rAcceptIP = $rConnections[0]["user_ip"];
            }
        } else {
            StreamingUtilities::$db->query("SELECT `user_ip` FROM `lines_live` WHERE `user_id` = ? AND `hls_end` = 0 ORDER BY `activity_id` DESC LIMIT 1;", $rUserInfo["id"]);
            if (StreamingUtilities::$db->num_rows() == 1) {
                $rAcceptIP = StreamingUtilities::$db->get_row()["user_ip"];
            }
        }
        $rIPMatch = StreamingUtilities::$rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rAcceptIP), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rAcceptIP == $rIP;
        if ($rAcceptIP && !$rIPMatch) {
            StreamingUtilities::clientLog($rStreamID, $rUserInfo["id"], "USER_ALREADY_CONNECTED", $rIP);
            StreamingUtilities::showVideoServer("show_connected_video", "connected_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
        }
    }
    switch ($rExtension) {
        case "m3u8":
            if (StreamingUtilities::$rSettings["redis_handler"]) {
                $rConnection = StreamingUtilities::getConnection($rTokenData["uuid"]);
            } else {
                if (isset($rTokenData["adaptive"])) {
                    StreamingUtilities::$db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `container` = 'hls' AND `hls_end` = 0", $rTokenData["uuid"], $rUserInfo["id"]);
                } else {
                    if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                        StreamingUtilities::$db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `server_id` = ? AND `container` = 'hls' AND `stream_id` = ? AND `hls_end` = 0", $rTokenData["uuid"], $rUserInfo["id"], $rServerID, $rStreamID);
                    } else {
                        StreamingUtilities::$db->query("SELECT `activity_id`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `hmac_id` = ? AND `hmac_identifier` = ? AND `server_id` = ? AND `container` = 'hls' AND `stream_id` = ? AND `hls_end` = 0", $rTokenData["uuid"], $rIsHMAC, $rIdentifier, $rServerID, $rStreamID);
                    }
                }
                if (StreamingUtilities::$db->num_rows() > 0) {
                    $rConnection = StreamingUtilities::$db->get_row();
                }
            }
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }
                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    if (StreamingUtilities::$rSettings["redis_handler"]) {
                        $rConnectionData = ["user_id" => $rUserInfo["id"], "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => "hls", "pid" => NULL, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], "on_demand" => $rChannelInfo["on_demand"], "identity" => $rUserInfo["id"], "uuid" => $rTokenData["uuid"]];
                        $rResult = StreamingUtilities::createConnection($rConnectionData);
                    } else {
                        $rResult = StreamingUtilities::$db->query("INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`,`hls_last_read`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?);", $rUserInfo["id"], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, "hls", NULL, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice, time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]);
                    }
                } else {
                    if (StreamingUtilities::$rSettings["redis_handler"]) {
                        $rConnectionData = ["hmac_id" => $rIsHMAC, "hmac_identifier" => $rIdentifier, "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => "hls", "pid" => NULL, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], "on_demand" => $rChannelInfo["on_demand"], "identity" => $rIsHMAC . "_" . $rIdentifier, "uuid" => $rTokenData["uuid"]];
                        $rResult = StreamingUtilities::createConnection($rConnectionData);
                    } else {
                        $rResult = StreamingUtilities::$db->query("INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`,`hls_last_read`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);", $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, "hls", NULL, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice, time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]);
                    }
                }
            } else {
                $rIPMatch = StreamingUtilities::$rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rConnection["user_ip"]), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rConnection["user_ip"] == $rIP;
                if (!$rIPMatch && StreamingUtilities::$rSettings["restrict_same_ip"]) {
                    StreamingUtilities::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }
                if (StreamingUtilities::$rSettings["redis_handler"]) {
                    $rChanges = ["server_id" => $rServerID, "proxy_id" => $rProxyID, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]];
                    if ($rConnection = StreamingUtilities::updateConnection($rConnection, $rChanges, "open")) {
                        $rResult = true;
                    } else {
                        $rResult = false;
                    }
                } else {
                    $rResult = StreamingUtilities::$db->query("UPDATE `lines_live` SET `hls_last_read` = ?, `hls_end` = 0, `server_id` = ?, `proxy_id` = ? WHERE `activity_id` = ?", time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], $rServerID, $rProxyID, $rConnection["activity_id"]);
                }
            }
            if (!$rResult) {
                StreamingUtilities::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP);
                generateError("LINE_CREATE_FAIL");
            }
            StreamingUtilities::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);
            if (StreamingUtilities::$rSettings["redis_handler"]) {
                StreamingUtilities::closeRedis();
            } else {
                StreamingUtilities::closeDatabase();
            }
            $rHLS = StreamingUtilities::generateHLS($rPlaylist, isset($rUsername) ? $rUsername : NULL, isset($rPassword) ? $rPassword : NULL, $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, (int) $rChannelInfo["on_demand"], $rServerID, $rProxyID);
            if ($rHLS) {
                touch(CONS_TMP_PATH . $rTokenData["uuid"]);
                ob_end_clean();
                header("Content-Type: application/x-mpegurl");
                header("Content-Length: " . strlen($rHLS));
                header("Cache-Control: no-store, no-cache, must-revalidate");
                echo $rHLS;
            } else {
                StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
            }
            exit;
            break;
        default:
            if (StreamingUtilities::$rSettings["redis_handler"]) {
                $rConnection = StreamingUtilities::getConnection($rTokenData["uuid"]);
            } else {
                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    StreamingUtilities::$db->query("SELECT `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `user_id` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?;", $rTokenData["uuid"], $rUserInfo["id"], $rServerID, $rExtension, $rStreamID);
                } else {
                    StreamingUtilities::$db->query("SELECT `activity_id`, `pid`, `user_ip` FROM `lines_live` WHERE `uuid` = ? AND `hmac_id` = ? AND `hmac_identifier` = ? AND `server_id` = ? AND `container` = ? AND `stream_id` = ?;", $rTokenData["uuid"], $rIsHMAC, $rIdentifier, $rServerID, $rExtension, $rStreamID);
                }
                if (StreamingUtilities::$db->num_rows() > 0) {
                    $rConnection = StreamingUtilities::$db->get_row();
                }
            }
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }
                if (!isset($rIsHMAC) && is_null($rIsHMAC)) {
                    if (StreamingUtilities::$rSettings["redis_handler"]) {
                        $rConnectionData = ["user_id" => $rUserInfo["id"], "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => $rExtension, "pid" => $rPID, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], "on_demand" => $rChannelInfo["on_demand"], "identity" => $rUserInfo["id"], "uuid" => $rTokenData["uuid"]];
                        $rResult = StreamingUtilities::createConnection($rConnectionData);
                    } else {
                        $rResult = StreamingUtilities::$db->query("INSERT INTO `lines_live` (`user_id`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)", $rUserInfo["id"], $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, $rExtension, $rPID, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice);
                    }
                } else {
                    if (StreamingUtilities::$rSettings["redis_handler"]) {
                        $rConnectionData = ["hmac_id" => $rIsHMAC, "hmac_identifier" => $rIdentifier, "stream_id" => $rStreamID, "server_id" => $rServerID, "proxy_id" => $rProxyID, "user_agent" => $rUserAgent, "user_ip" => $rIP, "container" => $rExtension, "pid" => $rPID, "date_start" => $rActivityStart, "geoip_country_code" => $rCountryCode, "isp" => $rUserInfo["con_isp_name"], "external_device" => $rExternalDevice, "hls_end" => 0, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], "on_demand" => $rChannelInfo["on_demand"], "identity" => $rIsHMAC . "_" . $rIdentifier, "uuid" => $rTokenData["uuid"]];
                        $rResult = StreamingUtilities::createConnection($rConnectionData);
                    } else {
                        $rResult = StreamingUtilities::$db->query("INSERT INTO `lines_live` (`hmac_id`,`hmac_identifier`,`stream_id`,`server_id`,`proxy_id`,`user_agent`,`user_ip`,`container`,`pid`,`uuid`,`date_start`,`geoip_country_code`,`isp`,`external_device`) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $rIsHMAC, $rIdentifier, $rStreamID, $rServerID, $rProxyID, $rUserAgent, $rIP, $rExtension, $rPID, $rTokenData["uuid"], $rActivityStart, $rCountryCode, $rUserInfo["con_isp_name"], $rExternalDevice);
                    }
                }
            } else {
                $rIPMatch = StreamingUtilities::$rSettings["ip_subnet_match"] ? implode(".", array_slice(explode(".", $rConnection["user_ip"]), 0, -1)) == implode(".", array_slice(explode(".", $rIP), 0, -1)) : $rConnection["user_ip"] == $rIP;
                if (!$rIPMatch && StreamingUtilities::$rSettings["restrict_same_ip"]) {
                    StreamingUtilities::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }
                if (StreamingUtilities::isProcessRunning($rConnection["pid"], "php-fpm") && $rPID != $rConnection["pid"] && is_numeric($rConnection["pid"]) && 0 < $rConnection["pid"]) {
                    posix_kill((int) $rConnection["pid"], 9);
                }
                if (StreamingUtilities::$rSettings["redis_handler"]) {
                    $rChanges = ["pid" => $rPID, "hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]];
                    if ($rConnection = StreamingUtilities::updateConnection($rConnection, $rChanges, "open")) {
                        $rResult = true;
                    } else {
                        $rResult = false;
                    }
                } else {
                    $rResult = StreamingUtilities::$db->query("UPDATE `lines_live` SET `hls_end` = 0, `hls_last_read` = ?, `pid` = ? WHERE `activity_id` = ?;", time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], $rPID, $rConnection["activity_id"]);
                }
            }
            if (!$rResult) {
                StreamingUtilities::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP);
                generateError("LINE_CREATE_FAIL");
            }
            StreamingUtilities::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);
            if (StreamingUtilities::$rSettings["redis_handler"]) {
                StreamingUtilities::closeRedis();
            } else {
                StreamingUtilities::closeDatabase();
            }
            $rCloseCon = true;
            if (StreamingUtilities::$rSettings["monitor_connection_status"]) {
                ob_implicit_flush(true);
                while (ob_get_level()) {
                    ob_end_clean();
                }
            }
            touch(CONS_TMP_PATH . $rTokenData["uuid"]);
            if (!$rChannelInfo["proxy"]) {
                header("Content-Type: video/mp2t");
                $rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData["uuid"];
                if (file_exists($rPlaylist)) {
                    if ($rUserInfo["is_restreamer"]) {
                        if ($rTokenData["prebuffer"]) {
                            $rPrebuffer = StreamingUtilities::$rSegmentSettings["seg_time"];
                        } else {
                            $rPrebuffer = StreamingUtilities::$rSettings["restreamer_prebuffer"];
                        }
                    } else {
                        $rPrebuffer = StreamingUtilities::$rSettings["client_prebuffer"];
                    }
                    if (file_exists(STREAMS_PATH . $rStreamID . "_.dur")) {
                        $rDuration = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.dur");
                        if ($rDuration > StreamingUtilities::$rSegmentSettings["seg_time"]) {
                            StreamingUtilities::$rSegmentSettings["seg_time"] = $rDuration;
                        }
                    }
                    $rSegments = StreamingUtilities::getPlaylistSegments($rPlaylist, $rPrebuffer, StreamingUtilities::$rSegmentSettings["seg_time"]);
                } else {
                    $rSegments = NULL;
                }
                if (!is_null($rSegments)) {
                    if (is_array($rSegments)) {
                        $rBytes = 0;
                        $rStartTime = time();
                        foreach ($rSegments as $rSegment) {
                            if (file_exists(STREAMS_PATH . $rSegment)) {
                                $rBytes .= readfile(STREAMS_PATH . $rSegment);
                            } else {
                                exit;
                            }
                        }
                        $rTotalTime = time() - $rStartTime;
                        if ($rTotalTime == 0) {
                            $rTotalTime = 0;
                        }
                        // Рассчитываем скорость в КБ/с (байты -> килобайты)
                        if ($rBytes > 0 && $rTotalTime > 0) {
                            $rDivergence = (int)($rBytes / $rTotalTime / 1024);
                            file_put_contents($rConSpeedFile, $rDivergence);
                        } else {
                            // Записываем 0 при отсутствии данных
                            file_put_contents($rConSpeedFile, 0);
                        }
                        preg_match("/_(.*)\\./", array_pop($rSegments), $rCurrentSegment);
                        $rCurrent = $rCurrentSegment[1];
                    } else {
                        $rCurrent = $rSegments;
                    }
                } else {
                    if (!file_exists($rPlaylist)) {
                        $rCurrent = -1;
                    } else {
                        exit;
                    }
                }
                $rFails = 0;
                $rTotalFails = StreamingUtilities::$rSegmentSettings["seg_time"] * 2;
                if ($rTotalFails < (int) StreamingUtilities::$rSettings["segment_wait_time"] ?: 20) {
                    $rTotalFails = (int) StreamingUtilities::$rSettings["segment_wait_time"] ?: 20;
                }
                $rMonitorCheck = $rLastCheck = time();
                while (true) {
                    $rSegmentFile = sprintf("%d_%d.ts", $rChannelInfo["stream_id"], $rCurrent + 1);
                    $rNextSegment = sprintf("%d_%d.ts", $rChannelInfo["stream_id"], $rCurrent + 2);
                    for ($rChecks = 0; !file_exists(STREAMS_PATH . $rSegmentFile) && $rChecks < $rTotalFails; $rChecks++) {
                        sleep(1);
                    }
                    if (file_exists(STREAMS_PATH . $rSegmentFile)) {
                        if (file_exists(SIGNALS_PATH . $rTokenData["uuid"])) {
                            $rSignalData = json_decode(file_get_contents(SIGNALS_PATH . $rTokenData["uuid"]), true);
                            if ($rSignalData["type"] == "signal") {
                                for ($rChecks = 0; !file_exists(STREAMS_PATH . $rNextSegment) && $rChecks < $rTotalFails; $rChecks++) {
                                    sleep(1);
                                }
                                StreamingUtilities::sendSignal($rSignalData, $rSegmentFile, $rVideoCodec ?: "h264");
                                unlink(SIGNALS_PATH . $rTokenData["uuid"]);
                                $rCurrent++;
                            }
                        }
                        $rFails = 0;
                        $rTimeStart = time();
                        $rFP = fopen(STREAMS_PATH . $rSegmentFile, "r");
                        while ($rFails < $rTotalFails && !file_exists(STREAMS_PATH . $rNextSegment)) {
                            $rData = stream_get_line($rFP, StreamingUtilities::$rSettings["read_buffer_size"]);
                            if (!empty($rData)) {
                                echo $rData;
                                $rData = "";
                                $rFails = 0;
                            } else {
                                if (StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID)) {
                                    sleep(1);
                                    $rFails++;
                                }
                            }
                        }
                        if (StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID) && $rFails < $rTotalFails && file_exists(STREAMS_PATH . $rSegmentFile) && is_resource($rFP)) {
                            $rSegmentSize = filesize(STREAMS_PATH . $rSegmentFile);
                            $rRestSize = $rSegmentSize - ftell($rFP);
                            if ($rRestSize > 0) {
                                echo stream_get_line($rFP, $rRestSize);
                            }
                            $rTotalTime = time() - $rTimeStart;
                            if (0 > $rTotalTime) {
                                $rTotalTime = 0;
                            }
                            file_put_contents($rConSpeedFile, (int) ($rSegmentSize / 1024 / $rTotalTime));
                        } else {
                            if (!($rUserInfo["is_restreamer"] == 1 || $rTotalFails < $rFails)) {
                                for ($rChecks = 0; $rChecks < StreamingUtilities::$rSegmentSettings["seg_time"] && !StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID); $rChecks++) {
                                    if (file_exists(STREAMS_PATH . $rStreamID . "_.pid")) {
                                        $rChannelInfo["pid"] = (int) file_get_contents(STREAMS_PATH . $rStreamID . "_.pid");
                                    }
                                }
                                sleep(1);
                                if ($rChecks < StreamingUtilities::$rSegmentSettings["seg_time"] && StreamingUtilities::isStreamRunning($rChannelInfo["pid"], $rStreamID)) {
                                    if (!file_exists(STREAMS_PATH . $rNextSegment)) {
                                        $rCurrent = -2;
                                    }
                                } else {
                                    exit;
                                }
                            } else {
                                exit;
                            }
                        }
                        fclose($rFP);
                        $rFails = 0;
                        $rCurrent++;
                        if (StreamingUtilities::$rSettings["monitor_connection_status"] && 5 < time() - $rMonitorCheck) {
                            if (connection_status() == CONNECTION_NORMAL) {
                                $rMonitorCheck = time();
                            } else {
                                exit;
                            }
                        }
                        if (time() - $rLastCheck > 300) {
                            $rLastCheck = time();
                            $rConnection = NULL;
                            StreamingUtilities::getCache("settings");
                            StreamingUtilities::$rSettings;
                            if (StreamingUtilities::$rSettings["redis_handler"]) {
                                StreamingUtilities::connectRedis();
                                $rConnection = StreamingUtilities::getConnection($rTokenData["uuid"]);
                                StreamingUtilities::closeRedis();
                            } else {
                                StreamingUtilities::connectDatabase();
                                StreamingUtilities::$db->query("SELECT `pid`, `hls_end` FROM `lines_live` WHERE `uuid` = ?", $rTokenData["uuid"]);
                                if (StreamingUtilities::$db->num_rows() == 1) {
                                    $rConnection = StreamingUtilities::$db->get_row();
                                }
                                StreamingUtilities::closeDatabase();
                            }
                            if (!is_array($rConnection) || $rConnection["hls_end"] != 0 || $rConnection["pid"] != $rPID) {
                                exit;
                            }
                        }
                    } else {
                        exit;
                    }
                }
            } else {
                header("Content-type: video/mp2t");
                if (!file_exists(CONS_TMP_PATH . $rStreamID . "/")) {
                    mkdir(CONS_TMP_PATH . $rStreamID);
                }
                $rSocketFile = CONS_TMP_PATH . $rStreamID . "/" . $rTokenData["uuid"];
                $rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
                @unlink($rSocketFile);
                socket_bind($rSocket, $rSocketFile);
                socket_set_option($rSocket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 20, "usec" => 0]);
                socket_set_nonblock($rSocket);
                $rTotalFails = 200;
                $rFails = 0;
                while ($rFails < $rTotalFails) {
                    $rBuffer = socket_read($rSocket, 12032);
                    if (!empty($rBuffer)) {
                        $rFails = 0;
                        echo $rBuffer;
                    } else {
                        $rFails++;
                        usleep(100000);
                    }
                }
                socket_close($rSocket);
                @unlink($rSocketFile);
            }
    }
} else {
    StreamingUtilities::showVideoServer("show_not_on_air_video", "not_on_air_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
}
function shutdown() {
    global $rCloseCon;
    global $rTokenData;
    global $rPID;
    global $rChannelInfo;
    global $rStreamID;
    StreamingUtilities::getCache("settings");
    StreamingUtilities::$rSettings;
    if ($rCloseCon) {
        if (StreamingUtilities::$rSettings["redis_handler"]) {
            if (!is_object(StreamingUtilities::$redis)) {
                StreamingUtilities::connectRedis();
            }
            $rConnection = StreamingUtilities::getConnection($rTokenData["uuid"]);
            if ($rConnection && $rConnection["pid"] == $rPID) {
                $rChanges = ["hls_last_read" => time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"]];
                StreamingUtilities::updateConnection($rConnection, $rChanges, "close");
            }
        } else {
            if (!is_object(StreamingUtilities::$db)) {
                StreamingUtilities::connectDatabase();
            }
            StreamingUtilities::$db->query("UPDATE `lines_live` SET `hls_end` = 1, `hls_last_read` = ? WHERE `uuid` = ? AND `pid` = ?;", time() - (int) StreamingUtilities::$rServers[SERVER_ID]["time_offset"], $rTokenData["uuid"], $rPID);
        }
        @unlink(CONS_TMP_PATH . $rTokenData["uuid"]);
        @unlink(CONS_TMP_PATH . $rStreamID . "/" . $rTokenData["uuid"]);
    }
    if (StreamingUtilities::$rSettings["on_demand_instant_off"] && $rChannelInfo["on_demand"] == 1) {
        StreamingUtilities::removeFromQueue($rStreamID, $rPID);
    }
    if (!StreamingUtilities::$rSettings["redis_handler"] && is_object(StreamingUtilities::$db)) {
        StreamingUtilities::closeDatabase();
    } else {
        if (StreamingUtilities::$rSettings["redis_handler"] && is_object(StreamingUtilities::$redis)) {
            StreamingUtilities::closeRedis();
        }
    }
}
