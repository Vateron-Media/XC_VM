<?php

use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\AsyncFileOperations;
use XcVm\Streaming\Auth\StreamAuth;
use XcVm\Streaming\Auth\StreamAuthMiddleware;
use XcVm\Streaming\Delivery\HLSGenerator;
use XcVm\Streaming\Delivery\OffAirHandler;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Streaming\Lifecycle\ShutdownHandler;

/**
 * Live stream delivery endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

set_time_limit(0);
register_shutdown_function([ShutdownHandler::class, 'handle'], 'live');
unset($rSettings["watchdog_data"]);
unset($rSettings["server_hardware"]);

StreamAuthMiddleware::sendStreamHeaders($rSettings, $rServers);

$rCreateExpiration = ($rSettings["create_expiration"] ?: 5);
$rProxyID = NULL;
$rServerID = SERVER_ID;
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

    // Fanout (ADR 0002/0003, P2): route proxy-mode live TS through the xc_fanout
    // daemon when it is reachable — there is NO settings flag; the switch is the
    // daemon itself. If its control socket is present AND registering the source
    // succeeds, this stream is committed to the daemon path: we skip the whole
    // legacy startProxy/startMonitor block below (the daemon owns the producer —
    // starts the puller on the first viewer, stops it after the last leaves) and
    // no streams_servers pid is written, so the streams cron leaves it alone; the
    // delivery arm then hands the byte path to nginx via X-Accel-Redirect.
    //
    // Deciding on *registration success* (not just socket presence) makes the
    // fallback correct: a stopped/unreachable daemon (or a stale socket after a
    // hard kill, or a stream with no source) leaves $rFanout false, so the FULL
    // legacy path runs — startProxy producer included. Stopping the daemon is
    // therefore the rollback: every stream falls back automatically, no flag.
    $rFanout = false;
    if (!empty($rChannelInfo["proxy"]) && file_exists(FANOUT_CTL_SOCK)) {
        DatabaseFactory::connect();
        $db->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rStreamID);
        $rStreamRow = ($db->num_rows() > 0 ? $db->get_row() : array());
        $db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
        $rStreamArguments = $db->get_rows(true, 'argument_key');
        $rSource = FanoutClient::buildSource($rStreamRow, $rStreamArguments);
        $rFanout = !empty($rSource["urls"]) && FanoutClient::register($rStreamID, $rSource);

        // Off-air parity (ADR 0003, Phase C): prewarm the daemon puller and wait
        // for the source to actually produce. A running-but-dead source shows the
        // not-on-air page here, exactly like the legacy startProxy path (whose
        // viewer would otherwise hang on an empty stream). Bounded by
        // on_demand_wait_time; a warm stream returns immediately.
        if ($rFanout && !FanoutClient::probe($rStreamID, max(1, intval($rSettings["on_demand_wait_time"] ?: 5)) * 1000)) {
            OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
        }
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

    if (!$rFanout && !ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)) {
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
                OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
            }

            for ($rRetries = 0; !AsyncFileOperations::awaitFileExists(STREAMS_PATH . intval($rStreamID) . "_.pid", 1, 10) && $rRetries < 300; $rRetries++) {
                AsyncFileOperations::efficientSleep(10000);
            }
            $rChannelInfo["pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid")) ?: NULL);

            if (!$rChannelInfo["pid"]) {
                OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
            }
        } else {
            // Non-on-demand: proxy streams are daemon-only now (ADR 0003, Phase E
            // — the legacy ProxyCommand producer was removed). If we reach here
            // $rFanout is false, i.e. the daemon is unreachable, so show
            // not-on-air (the keepalive brings the daemon back in ~2s). A dead
            // non-proxy stream is likewise not-on-air.
            OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
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
                        OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
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

        $rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rAcceptIP, $rIP);

        if ($rAcceptIP && !$rIPMatch) {
            DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "USER_ALREADY_CONNECTED", $rIP);
            OffAirHandler::showVideoServer("show_connected_video", "connected_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
        }
    }

    // Shared connection context for ConnectionTracker::createLive(); the HLS
    // and TS switch arms differ only in the container and pid they pass.
    $rConnCtx = array(
        "is_hmac" => $rIsHMAC,
        "identifier" => $rIdentifier,
        "user_id" => $rUserInfo["id"],
        "stream_id" => $rStreamID,
        "server_id" => $rServerID,
        "proxy_id" => $rProxyID,
        "user_agent" => $rUserAgent,
        "user_ip" => $rIP,
        "date_start" => $rActivityStart,
        "geoip_country_code" => $rCountryCode,
        "isp" => $rUserInfo["con_isp_name"],
        "external_device" => $rExternalDevice,
        "on_demand" => $rChannelInfo["on_demand"],
        "uuid" => $rTokenData["uuid"],
        "adaptive" => isset($rTokenData["adaptive"]),
        "time_offset" => intval($rServers[SERVER_ID]["time_offset"]),
    );

    switch ($rExtension) {
        case "m3u8":
            $rConnection = ConnectionTracker::lookupLive($rSettings, $rConnCtx, "hls", false, true, true);
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }

                $rResult = ConnectionTracker::createLive($rSettings, $rConnCtx, "hls", NULL);
            } else {
                $rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rConnection["user_ip"], $rIP);

                if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
                    DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }

                $rResult = ConnectionTracker::updateLive($rSettings, $rConnection, array("server_id" => $rServerID, "proxy_id" => $rProxyID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"])));
            }

            if (!$rResult) {
                DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP, $rSettings["redis_handler"] ? "redis unavailable: connection tracking write failed" : $db->error());
                generateError("LINE_CREATE_FAIL");
            }

            StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent);

            if ($rSettings["redis_handler"]) {
                RedisManager::closeInstance();
            } else {
                DatabaseFactory::close();
            }

            // Phase B (ADR 0003): serve HLS from the daemon's in-RAM segmenter
            // when it is fed and the stream is unencrypted (daemon HLS is plain
            // mpegts). The daemon playlist's sequence segments are tokenized into
            // the same auth'd /hls/<token> URLs as the legacy path, marked so
            // segment.php proxies them from the daemon. Encrypted streams / a
            // daemon that's down fall through to the legacy tmpfs HLS.
            $rHLS = false;
            if (file_exists(FANOUT_CTL_SOCK) && FanoutClient::isStreamFed($rStreamID)) {
                $rDaemonPl = FanoutClient::hlsPlaylist($rStreamID);
                if ($rDaemonPl !== NULL) {
                    $rHLS = HLSGenerator::tokenizeDaemonPlaylist($rDaemonPl, $rSettings, (isset($rUsername) ? $rUsername : NULL), (isset($rPassword) ? $rPassword : NULL), $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, intval($rChannelInfo["on_demand"]), $rServerID, $rProxyID);
                }
            }
            if ($rHLS === false) {
                $rHLS = HLSGenerator::generateHLS($rSettings, $rPlaylist, (isset($rUsername) ? $rUsername : NULL), (isset($rPassword) ? $rPassword : NULL), $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, intval($rChannelInfo["on_demand"]), $rServerID, $rProxyID);
            }

            if ($rHLS) {
                touch(CONS_TMP_PATH . $rTokenData["uuid"]);
                ob_end_clean();
                header("Content-Type: application/x-mpegurl");
                header("Content-Length: " . strlen($rHLS));
                header("Cache-Control: no-store, no-cache, must-revalidate");
                echo $rHLS;
            } else {
                OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
            }

            exit();

        default:
            // Decide TS delivery once: daemon (proxy already registered+probed,
            // or non-proxy fed) vs the legacy per-viewer feed. Daemon-served TS
            // connections are recorded with pid=0 — their auth worker returns
            // immediately under X-Accel, so the reaper's isRunning(pid) check
            // can't track them; the fanout_sync daemon reconciles pid=0 rows
            // against the daemon's live-connection set (see UsersCronJob).
            $rTSDaemon = false;
            if ($rChannelInfo["proxy"]) {
                $rTSDaemon = $rFanout;
            } elseif (file_exists(FANOUT_CTL_SOCK) && FanoutClient::isStreamFed($rStreamID)) {
                $rTSDaemon = true;
            }
            $rConnPID = $rTSDaemon ? 0 : $rPID;

            $rConnection = ConnectionTracker::lookupLive($rSettings, $rConnCtx, $rExtension, true, false, false);
            if (!isset($rConnection)) {
                if (time() > $rExpiresAt) {
                    generateError("TOKEN_EXPIRED");
                }
                $rResult = ConnectionTracker::createLive($rSettings, $rConnCtx, $rExtension, $rConnPID);
            } else {
                $rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rConnection["user_ip"], $rIP);

                if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
                    DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
                    generateError("IP_MISMATCH");
                }

                if (ProcessManager::isRunning($rConnection["pid"], "php-fpm") && $rPID != $rConnection["pid"] && is_numeric($rConnection["pid"]) && 0 < $rConnection["pid"]) {
                    posix_kill(intval($rConnection["pid"]), 9);
                }

                $rResult = ConnectionTracker::updateLive($rSettings, $rConnection, array("pid" => $rConnPID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"])));
            }

            if (!$rResult) {
                DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP, $rSettings["redis_handler"] ? "redis unavailable: connection tracking write failed" : $db->error());
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
                // Proxy streams are daemon-only (ADR 0003, Phase E — the legacy
                // ProxyCommand producer + socket relay were removed). Auth is done
                // and the source was already registered + probed with the daemon
                // above (that set $rFanout; an unreachable/dead daemon already went
                // not-on-air). Hand the byte path to nginx → daemon via
                // X-Accel-Redirect; the FPM worker is freed the instant we return.
                // ────────────────────────────────────────────────────────────────
                if (!$rFanout) {
                    OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
                }
                // client_prebuffer / restreamer_prebuffer (seconds) → the daemon
                // front-loads that much keyframe-aligned history on join, filling
                // the player's cache. Without it the daemon sends only the current
                // GOP (~1s, "no cache"). Same hand-off contract as the non-proxy
                // $rTSDaemon branch below; the daemon clamps to its retention ceiling.
                $rDaemonPrebuffer = $rUserInfo["is_restreamer"]
                    ? (!empty($rTokenData["prebuffer"])
                        ? intval($rSegmentSettings["seg_time"] ?? 0)
                        : intval($rSettings["restreamer_prebuffer"] ?? 0))
                    : intval($rSettings["client_prebuffer"] ?? 0);
                header("Content-Type: video/mp2t");
                header("X-Accel-Buffering: no");
                header("X-Accel-Redirect: /xc_fanout/" . rawurlencode((string) $rStreamID) . "?c=" . rawurlencode($rTokenData["uuid"]) . "&prebuffer=" . $rDaemonPrebuffer);
                exit;
            }

            // ────────────────────────────────────────────────────────────────
            // Non-proxy fanout hand-off (ADR 0003, A3). The stream's ffmpeg tees
            // its mpegts into the xc_fanout daemon (A2) from the same process that
            // writes the on-disk HLS, so once we reach delivery (playlist ready
            // above) the daemon has data. If it's reachable and serving this
            // stream, hand the byte path to nginx→daemon via X-Accel-Redirect —
            // like proxy mode. Non-proxy TS is daemon-only (ADR 0003, Phase E — the
            // legacy per-viewer .ts chase-read was removed); daemon down / not fed
            // ⇒ not-on-air, same as the proxy arm.
            // ────────────────────────────────────────────────────────────────
            if ($rTSDaemon) {
                // client_prebuffer / restreamer_prebuffer (seconds) → the daemon
                // front-loads that much keyframe-aligned history on join, filling
                // the player's cache. The X-Accel hand-off must pass them through or
                // they would be lost
                // (the daemon otherwise sends only the current GOP). The daemon
                // clamps the value to its own retention ceiling.
                $rDaemonPrebuffer = $rUserInfo["is_restreamer"]
                    ? (!empty($rTokenData["prebuffer"])
                        ? intval($rSegmentSettings["seg_time"] ?? 0)
                        : intval($rSettings["restreamer_prebuffer"] ?? 0))
                    : intval($rSettings["client_prebuffer"] ?? 0);
                header("Content-Type: video/mp2t");
                header("X-Accel-Buffering: no");
                header("X-Accel-Redirect: /xc_fanout/" . rawurlencode((string) $rStreamID) . "?c=" . rawurlencode($rTokenData["uuid"]) . "&prebuffer=" . $rDaemonPrebuffer);
                exit;
            }

            // ────────────────────────────────────────────────────────────────
            // Non-proxy TS is daemon-only now (ADR 0003, Phase E — the legacy
            // per-viewer .ts chase-read was removed). Reaching here means the
            // daemon isn't serving this stream ($rTSDaemon false), so show
            // not-on-air, exactly like the proxy arm above. Stopping the daemon
            // is the rollback (git revert this commit).
            // ────────────────────────────────────────────────────────────────
            OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
    }
} else {
    OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
}
