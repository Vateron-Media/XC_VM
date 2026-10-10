<?php

use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Infrastructure\Cache\CacheReader;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\AsyncFileOperations;
use XcVm\Streaming\Auth\StreamAuth;
use XcVm\Streaming\Auth\StreamAuthMiddleware;
use XcVm\Streaming\Codec\FfmpegPaths;
use XcVm\Streaming\Delivery\HLSGenerator;
use XcVm\Streaming\Delivery\OffAirHandler;
use XcVm\Streaming\Delivery\SegmentReader;
use XcVm\Streaming\Delivery\SignalSender;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Streaming\Fanout\FanoutMode;
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
$rProxyID = null;
$rServerID = SERVER_ID;
$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = (empty($_SERVER["HTTP_USER_AGENT"]) ? '' : htmlentities(trim($_SERVER["HTTP_USER_AGENT"])));
$rConSpeedFile = null;
$rDivergence = 0;
$rCloseCon = false;
$rPID = getmypid();
$rStartTime = time();
$rVideoCodec = null;

// A link is one plain value: a request that sends a list of them has none.
if (isset($rRequest["token"]) && !is_array($rRequest["token"])) {
	$rTokenData = StreamAuthMiddleware::decryptToken($rRequest["token"], $rSettings, $rServers, $rIP);

	if (!isset($rTokenData["video_path"]) && !isset($rTokenData["off_air"])) {
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
		OffAirHandler::forStream($rStreamID);
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
		readfile(OffAirHandler::tokenVideo($rTokenData));

		exit();
	}
} else {
	generateError("NO_TOKEN_SPECIFIED");
}

if (!in_array($rExtension, ['ts', 'm3u8'])) {
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
		$rProxyID = null;
	}

	// Fanout (ADR 0002/0003, P2): route proxy-mode live TS through the xc_fanout
	// daemon. If its control socket is present AND registering the source
	// succeeds, this stream is committed to the daemon path: we skip the whole
	// start block below (the daemon owns the producer — starts the puller on the
	// first viewer, stops it after the last leaves) and no streams_servers pid
	// is written, so the streams cron leaves it alone; the delivery arm then
	// hands the byte path to nginx via X-Accel-Redirect.
	//
	// The rollback is the admin's master switch, settings.fanout_enabled = 0
	// (FanoutMode), or a licence that denies fanout: $rLegacy is then true and
	// every arm below takes its pre-fanout path — ProxyCommand for proxy
	// streams, the PHP monitor, the on-disk HLS playlist and the per-viewer TS
	// chase-read. Stopping the daemon is NOT a rollback: with fanout on and the
	// daemon down (or its socket stale, or registration failing) $rFanout stays
	// false and the viewer gets not-on-air until the keepalive brings the
	// daemon back (~2 s).
	$rLegacy = FanoutMode::legacyDelivery($rSettings);
	$rFanout = false;
	if (!empty($rChannelInfo["proxy"]) && LicenseGate::fanoutUsable()) {
		DatabaseFactory::connectLazy();
		$rStreamRow = StreamSource::sourceRow(intval($rStreamID), $db);
		$rStreamArguments = StreamSource::arguments(intval($rStreamID), true, $db);
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
		$rChannelInfo["pid"] = null;
		$rStart = FanoutMode::startFor($rLegacy, !empty($rChannelInfo["proxy"]), $rChannelInfo["on_demand"] == 1);

		if ($rStart === FanoutMode::START_PROXY) {
			// Fanout off: the pre-fanout proxy producer, on demand or not (the PHP
			// monitor does not run direct sources). One ProxyCommand per stream
			// pulls the source and feeds each viewer's socket (relayed in the TS
			// arm below); it exits a few seconds after its last viewer. Viewers
			// arriving together start it once: a second ProxyCommand would kill
			// the first while it is still opening the source.
			$rStartLock = StreamProcess::lockOnDemandStart($rStreamID);
			AsyncFileOperations::clearFileCache();
			$rChannelInfo["monitor_pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor", false)) ?: null);
			if (!($rChannelInfo["monitor_pid"] && ProcessManager::isNamedProcessRunning($rChannelInfo["monitor_pid"], "XC_VMProxy", $rStreamID))) {
				@unlink(STREAMS_PATH . $rStreamID . "_.monitor");
				@unlink(STREAMS_PATH . $rStreamID . "_.pid");
				AsyncFileOperations::clearFileCache();
				$rChannelInfo["monitor_pid"] = null;
				StreamProcess::startProxy($rStreamID);

				if (AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rStreamID . "_.monitor", 300, 10)) {
					$rChannelInfo["monitor_pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor", false)) ?: null);
				}
			}
			StreamProcess::unlockOnDemandStart($rStartLock);

			if (!$rChannelInfo["monitor_pid"]) {
				OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
			}

			$rChannelInfo["pid"] = $rChannelInfo["monitor_pid"];
		} elseif ($rStart === FanoutMode::START_MONITOR) {
			// One viewer starts a stopped stream; others arriving meanwhile wait
			// here, then read the monitor it started (see lockOnDemandStart).
			$rStartLock = StreamProcess::lockOnDemandStart($rStreamID);
			if ($rStartLock) {
				AsyncFileOperations::clearFileCache();
				$rChannelInfo["monitor_pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor", false)) ?: $rChannelInfo["monitor_pid"]);
			}

			// Watched = a live PHP monitor, or the fanout supervisor (whose pid
			// the PHP check rightly rejects). Either way, do not start another.
			if (!StreamProcess::isWatched($rStreamID, $rChannelInfo["monitor_pid"])) {
				if ($rActivityStart + $rCreateExpiration < StreamAuthMiddleware::mainNow($rServers)) {
					generateError("TOKEN_EXPIRED");
				}

				// Both pids are dead (checked above), so their files are leftovers
				// of the last run — e.g. a monitor that gave up under
				// on_demand_failure_exit. Clear them, or the waits below would read
				// them back as the new monitor/producer before it wrote its own.
				@unlink(STREAMS_PATH . $rStreamID . "_.monitor");
				@unlink(STREAMS_PATH . $rStreamID . "_.pid");
				AsyncFileOperations::clearFileCache();

				DatabaseFactory::connectLazy(); // the hand-over reads the stream's config
				if (StreamProcess::startMonitor($rStreamID) === StreamProcess::MONITOR_FANOUT) {
					// The daemon is the monitor: there is no _.monitor file to wait
					// for, and waiting its full three seconds would be pure latency
					// on the viewer's connect. It writes _.pid as it launches.
					$rChannelInfo["monitor_pid"] = -1;
				} elseif (AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rStreamID . "_.monitor", 300, 10)) {
					$rChannelInfo["monitor_pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.monitor")) ?: null);
				}
			} elseif (!$rChannelInfo["monitor_pid"]) {
				$rChannelInfo["monitor_pid"] = -1; // supervised; its pid is the daemon's
			}
			// The monitor is up (or known to be failing): the viewers waiting
			// behind this one can see it now.
			StreamProcess::unlockOnDemandStart($rStartLock);

			if (!$rChannelInfo["monitor_pid"]) {
				OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
			}

			// The producer's pid appears once the monitor has probed the source and
			// launched it, which can take most of the on-demand wait on its own (a
			// 10 s analyze window for a non-LLOD start) — so the wait is the
			// configured on_demand_wait_time, not a fixed ~6 s.
			$rPidWaitMs = max(1, intval($rSettings["on_demand_wait_time"])) * 1000;
			AsyncFileOperations::awaitFileExists(STREAMS_PATH . intval($rStreamID) . "_.pid", intdiv($rPidWaitMs, 20), 20);
			$rChannelInfo["pid"] = (intval(AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid", false)) ?: null);

			if (!$rChannelInfo["pid"]) {
				OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
			}
		} else {
			// A proxy stream with fanout on is daemon-only (ADR 0003, Phase E):
			// reaching here means $rFanout is false, i.e. the daemon is
			// unreachable, so show not-on-air (the keepalive brings it back in
			// ~2 s). A dead always-on stream is likewise not-on-air: its watchdog
			// (the cron's) restarts it.
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
					if (!(StreamProcess::isWatched($rStreamID, $rChannelInfo["monitor_pid"]) && ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID))) {
						OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
					}
				}
			}
		} else {
			$maxRetries = intval($rSettings["on_demand_wait_time"]) * 10;
			$foundFile = AsyncFileOperations::awaitAnyFileExists([$rPlaylist], $maxRetries, 100);

			if (!$foundFile) {
				generateError("WAIT_TIME_EXPIRED");
			}
		}

		if (!$rChannelInfo["pid"]) {
			$pidContent = AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid");
			$rChannelInfo["pid"] = $pidContent ? (intval($pidContent) ?: null) : null;
		}
	}

	$rExecutionTime = time() - $rStartTime;
	// MAIN's clock, as the token's start (StreamAuthMiddleware::mainNow()).
	$rExpiresAt = $rActivityStart + $rCreateExpiration + $rExecutionTime;

	ConnectionTracker::openStore($rSettings);

	if ($rSettings["disallow_2nd_ip_con"] && !$rUserInfo["is_restreamer"] && ($rUserInfo["max_connections"] <= $rSettings["disallow_2nd_ip_max"] && 0 < $rUserInfo["max_connections"] || $rSettings["disallow_2nd_ip_max"] == 0)) {
		$rAcceptIP = null;

		$rAcceptIP = ConnectionTracker::acceptedIP($rSettings, $rUserInfo["id"]);

		$rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rAcceptIP, $rIP);

		if ($rAcceptIP && !$rIPMatch) {
			DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "USER_ALREADY_CONNECTED", $rIP);
			OffAirHandler::showVideoServer("show_connected_video", "connected_video_path", $rExtension, $rUserInfo, $rIP, $rCountryCode, $rUserInfo["con_isp_name"], $rServerID, $rProxyID);
		}
	}

	// Deterministic HLS connection id (see ConnectionTracker::hlsConnectionKey):
	// repeated playlist requests / channel re-selects by the same player reuse
	// ONE tracked connection instead of piling up a fresh random-uuid row each
	// time — which the reaper clears only slowly, ballooning the live count on
	// channel switch. Overriding the token uuid here (before $rConnCtx) keeps the
	// connection id, segment auth and the heartbeat file consistent. TS/VOD keep
	// their per-request random uuid.
	if ($rExtension === "m3u8") {
		// The uuid MAIN reserved at mint (the token's adm), released with the viewer.
		$rTokenData["adm_uuid"] = $rTokenData["uuid"] ?? null;
		// HMAC/external-device streams have no real user id in $rUserInfo (0 is a
		// safe placeholder: hlsConnectionKey() only uses $rUserId in the non-HMAC branch).
		$rTokenData["uuid"] = ConnectionTracker::hlsConnectionKey($rIsHMAC, $rIdentifier, $rUserInfo["id"] ?? 0, $rStreamID, $rIP, $rUserAgent);
	}

	// Shared connection context for ConnectionTracker::createLive(); the HLS
	// and TS switch arms differ only in the container and pid they pass.
	$rConnCtx = [
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
		"token" => $rTokenData,
	];

	switch ($rExtension) {
		case "m3u8":
			$rConnection = ConnectionTracker::lookupLive($rSettings, $rConnCtx, "hls", false, true, true);
			if (!isset($rConnection)) {
				if (StreamAuthMiddleware::mainNow($rServers) > $rExpiresAt) {
					generateError("TOKEN_EXPIRED");
				}

				$rResult = ConnectionTracker::createLive($rSettings, $rConnCtx, "hls", null);
			} else {
				$rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rConnection["user_ip"], $rIP);

				if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
					generateError("IP_MISMATCH");
				}

				$rResult = ConnectionTracker::updateLive($rSettings, $rConnection, ["server_id" => $rServerID, "proxy_id" => $rProxyID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"])]);
			}

			if (!$rResult) {
				StreamAuth::refuseAdmission($rStreamID, $rUserInfo, $rIP, $rExtension, $rCountryCode, $rServerID, $rProxyID);
				DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP, $rSettings["redis_handler"] ? "redis unavailable: connection tracking write failed" : $db->error());
				generateError("LINE_CREATE_FAIL");
			}

			StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent, $rTokenData["uuid"]);

			if ($rSettings["redis_handler"]) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			// With fanout on, client HLS is daemon-only (ADR 0003, Phase E). When
			// the stream is fed, serve the daemon's in-RAM segmenter playlist
			// (plain or AES-128, both produced by the daemon), tokenized into
			// auth'd /hls/<token> URLs that segment.php proxies from the daemon. A
			// daemon that's down / not fed ⇒ not-on-air, same as the TS arm.
			// With fanout off ($rLegacy), the on-disk playlist is served instead,
			// its segments by segment.php from STREAMS_PATH (the pre-fanout path).
			$rHLS = false;
			$rDaemonPl = (!$rLegacy && LicenseGate::fanoutUsable() && FanoutClient::isStreamFed($rStreamID)) ? FanoutClient::hlsPlaylist($rStreamID) : null;
			$rHLSVia = FanoutMode::hlsDelivery($rLegacy, $rDaemonPl !== null);
			if ($rHLSVia === FanoutMode::VIA_DAEMON) {
				$rHLS = HLSGenerator::tokenizeDaemonPlaylist($rDaemonPl, $rSettings, (isset($rUsername) ? $rUsername : null), (isset($rPassword) ? $rPassword : null), $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, intval($rChannelInfo["on_demand"]), $rServerID, $rProxyID);
			} elseif ($rHLSVia === FanoutMode::VIA_LEGACY) {
				$rHLS = HLSGenerator::generateHLS($rSettings, $rPlaylist, (isset($rUsername) ? $rUsername : null), (isset($rPassword) ? $rPassword : null), $rStreamID, $rTokenData["uuid"], $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, intval($rChannelInfo["on_demand"]), $rServerID, $rProxyID);
			}

			if ($rHLS) {
				touch(CONS_TMP_PATH . $rTokenData["uuid"]);
				while (ob_get_level()) {
					ob_end_clean();
				}
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
			// With fanout off ($rLegacy) the daemon is never asked: VIA_LEGACY.
			$rTSVia = FanoutMode::tsDelivery($rLegacy, $rChannelInfo["proxy"] ? $rFanout : (!$rLegacy && LicenseGate::fanoutUsable() && FanoutClient::isStreamFed($rStreamID)));
			$rTSDaemon = $rTSVia === FanoutMode::VIA_DAEMON;
			$rConnPID = $rTSDaemon ? 0 : $rPID;

			$rConnection = ConnectionTracker::lookupLive($rSettings, $rConnCtx, $rExtension, true, false, false);
			if (!isset($rConnection)) {
				if (StreamAuthMiddleware::mainNow($rServers) > $rExpiresAt) {
					generateError("TOKEN_EXPIRED");
				}
				$rResult = ConnectionTracker::createLive($rSettings, $rConnCtx, $rExtension, $rConnPID);
			} else {
				$rIPMatch = NetworkUtils::ipMatches($rSettings["ip_subnet_match"], $rConnection["user_ip"], $rIP);

				if (!$rIPMatch && $rSettings["restrict_same_ip"]) {
					DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "IP_MISMATCH", $rIP);
					generateError("IP_MISMATCH");
				}

				// Take over from a worker still serving this token; an ended connection's worker serves someone else now.
				if (!ConnectionTracker::ended($rConnection) && ProcessManager::isRunning($rConnection["pid"], "php-fpm") && $rPID != $rConnection["pid"] && is_numeric($rConnection["pid"]) && 0 < $rConnection["pid"]) {
					posix_kill(intval($rConnection["pid"]), 9);
				}

				$rResult = ConnectionTracker::updateLive($rSettings, $rConnection, ["pid" => $rConnPID, "hls_last_read" => time() - intval($rServers[SERVER_ID]["time_offset"])]);
			}

			if (!$rResult) {
				StreamAuth::refuseAdmission($rStreamID, $rUserInfo, $rIP, $rExtension, $rCountryCode, $rServerID, $rProxyID);
				DatabaseLogger::clientLog($rStreamID, $rUserInfo["id"], "LINE_CREATE_FAIL", $rIP, $rSettings["redis_handler"] ? "redis unavailable: connection tracking write failed" : $db->error());
				generateError("LINE_CREATE_FAIL");
			}

			StreamAuth::validateConnections($rUserInfo, $rIsHMAC, $rIdentifier, $rIP, $rUserAgent, $rTokenData["uuid"]);

			if ($rSettings["redis_handler"]) {
				RedisManager::closeInstance();
			} else {
				DatabaseFactory::close();
			}

			// A daemon viewer's record has pid 0 and outlives this worker: the
			// agent or fanout_sync closes it when the daemon drops the viewer.
			// The shutdown handler has nothing to close for it (it closes only
			// this worker's pid) and would delete its marker at once, so neither
			// is asked of it: no store round-trip after the hand-off.
			$rCloseCon = !$rTSDaemon;

			if ($rSettings["monitor_connection_status"]) {
				ob_implicit_flush(true);
				while (ob_get_level()) {
					ob_end_clean();
				}
			}

			if (!$rTSDaemon) {
				touch(CONS_TMP_PATH . $rTokenData["uuid"]);
			}

			// The daemon hand-off, the same for proxy and non-proxy channels.
			// client_prebuffer / restreamer_prebuffer (seconds) → the daemon
			// front-loads that much keyframe-aligned history on join, filling the
			// player's cache (without it it sends only the current GOP); a
			// restreamer's link that asks for a prebuffer gets a segment's time.
			// The daemon clamps the value to its retention ceiling.
			$rDaemonHandOff = static function () use ($rUserInfo, $rTokenData, $rSettings, $rStreamID, $rVideoCodec): void {
				$rDaemonPrebuffer = $rUserInfo["is_restreamer"]
					? (!empty($rTokenData["prebuffer"])
						? max(1, intval($rSettings["seg_time"]))
						: intval($rSettings["restreamer_prebuffer"] ?? 0))
					: intval($rSettings["client_prebuffer"] ?? 0);
				header("Content-Type: video/mp2t");
				header("X-Accel-Buffering: no");
				header("X-Accel-Redirect: /xc_fanout/" . rawurlencode((string) $rStreamID) . "?c=" . rawurlencode($rTokenData["uuid"]) . "&prebuffer=" . $rDaemonPrebuffer . "&vc=" . rawurlencode((string) $rVideoCodec));
			};

			if ($rChannelInfo["proxy"]) {
				// ────────────────────────────────────────────────────────────────
				// Fanout off: the pre-fanout relay. ProxyCommand (started above)
				// sends the stream to a unix datagram socket this worker binds
				// under CONS_TMP_PATH/<id>/; relay its datagrams to the client.
				// ────────────────────────────────────────────────────────────────
				if ($rTSVia === FanoutMode::VIA_LEGACY) {
					header("Content-Type: video/mp2t");

					if (!file_exists(CONS_TMP_PATH . $rStreamID . "/")) {
						@mkdir(CONS_TMP_PATH . $rStreamID);
					}

					$rSocketFile = CONS_TMP_PATH . $rStreamID . "/" . $rTokenData["uuid"];
					$rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
					@unlink($rSocketFile);
					socket_bind($rSocket, $rSocketFile);
					socket_set_option($rSocket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 20, "usec" => 0]);
					socket_set_nonblock($rSocket);
					// ~16 s without data (200 × 80 ms) ends the relay.
					$rTotalFails = 200;
					$rFails = 0;

					while ($rFails <= $rTotalFails) {
						// 64 MPEG-TS packets per read (188 × 64 = 12032 bytes), the
						// producer's datagram size.
						$rBuffer = @socket_read($rSocket, 188 * 64);

						if ($rBuffer !== false && $rBuffer !== '') {
							$rFails = 0;
							echo $rBuffer;
							flush();
						} else {
							$rFails++;
							usleep(80000);
						}
					}
					socket_close($rSocket);
					@unlink($rSocketFile);
					exit;
				}

				// ────────────────────────────────────────────────────────────────
				// Fanout on: proxy streams are daemon-only (ADR 0003, Phase E).
				// Auth is done and the source was already registered + probed with
				// the daemon above (that set $rFanout; an unreachable/dead daemon
				// already went not-on-air). Hand the byte path to nginx → daemon via
				// X-Accel-Redirect; the FPM worker is freed the instant we return.
				// ────────────────────────────────────────────────────────────────
				if (!$rTSDaemon) {
					OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
				}
				$rDaemonHandOff();
				exit;
			}

			// ────────────────────────────────────────────────────────────────
			// Non-proxy fanout hand-off (ADR 0003, A3). The stream's ffmpeg tees
			// its mpegts into the xc_fanout daemon (A2) from the same process that
			// writes the on-disk HLS, so once we reach delivery (playlist ready
			// above) the daemon has data. If it's reachable and serving this
			// stream, hand the byte path to nginx→daemon via X-Accel-Redirect —
			// like proxy mode. With fanout on, non-proxy TS is daemon-only (ADR
			// 0003, Phase E); daemon down / not fed ⇒ not-on-air, same as the proxy
			// arm. With fanout off the chase-read below serves it.
			// ────────────────────────────────────────────────────────────────
			if ($rTSDaemon) {
				$rDaemonHandOff();
				exit;
			}

			// ────────────────────────────────────────────────────────────────
			// With fanout on, non-proxy TS is daemon-only (ADR 0003, Phase E):
			// reaching here means the daemon isn't serving this stream, so show
			// not-on-air, exactly like the proxy arm above.
			// ────────────────────────────────────────────────────────────────
			if ($rTSVia !== FanoutMode::VIA_LEGACY) {
				OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
			}

			// ────────────────────────────────────────────────────────────────
			// Fanout off: the pre-fanout TS feed. Stream the on-disk HLS segments
			// to the client as one continuous MPEG-TS, chasing ffmpeg's write head.
			// ────────────────────────────────────────────────────────────────
			header("Content-Type: video/mp2t");

			$rSegmentSettings = ["seg_time" => max(1, intval($rSettings["seg_time"]))];
			// Current transfer rate, for the divergence check.
			$rConSpeedFile = DIVERGENCE_TMP_PATH . $rTokenData["uuid"];

			if (file_exists($rPlaylist)) {
				if ($rUserInfo["is_restreamer"]) {
					$rPrebuffer = !empty($rTokenData["prebuffer"]) ? $rSegmentSettings["seg_time"] : intval($rSettings["restreamer_prebuffer"]);
				} else {
					$rPrebuffer = intval($rSettings["client_prebuffer"]);
				}

				if (file_exists(STREAMS_PATH . $rStreamID . "_.dur")) {
					$rDuration = intval(file_get_contents(STREAMS_PATH . $rStreamID . "_.dur"));
					if ($rSegmentSettings["seg_time"] < $rDuration) {
						$rSegmentSettings["seg_time"] = $rDuration;
					}
				}

				// On-demand cold start: this initial prebuffer burst is the
				// client's whole playback buffer (the loop below runs in real
				// time), so wait until the playlist holds $rPrebuffer seconds,
				// not just the fast-start segments that exist the instant it
				// appears. Bounded by on_demand_wait_time. Clients only.
				if ($rChannelInfo["on_demand"] == 1 && !$rUserInfo["is_restreamer"] && $rPrebuffer > 0) {
					$rBufferDeadline = time() + max(1, intval($rSettings["on_demand_wait_time"]));
					while (
						SegmentReader::playlistBufferedSeconds($rPlaylist) < $rPrebuffer
						&& time() < $rBufferDeadline
						&& ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)
					) {
						AsyncFileOperations::efficientSleep(200000);
					}
				}

				$rSegments = SegmentReader::getPlaylistSegments($rPlaylist, $rPrebuffer, $rSegmentSettings["seg_time"]);
			} else {
				$rSegments = null;
			}

			if (!is_null($rSegments)) {
				if (is_array($rSegments)) {
					$rBytes = 0;
					$rStartTime = time();

					foreach ($rSegments as $rSegment) {
						$rSegmentPath = STREAMS_PATH . basename($rSegment);
						if (!file_exists($rSegmentPath)) {
							exit();
						}
						$rBytes += readfile($rSegmentPath);
					}

					$rTotalTime = max(0.1, time() - $rStartTime);
					$rDivergence = intval($rBytes / $rTotalTime / 1024);
					file_put_contents($rConSpeedFile, $rDivergence);

					preg_match('/_(.*)\\./', array_pop($rSegments), $rCurrentSegment);
					$rCurrent = $rCurrentSegment[1];
				} else {
					$rCurrent = $rSegments;
				}
			} elseif (!file_exists($rPlaylist)) {
				$rCurrent = -1;
			} else {
				exit();
			}

			$rFails = 0;
			$rTotalFails = max($rSegmentSettings["seg_time"] * 2, intval($rSettings["segment_wait_time"]) ?: 20);
			$rMonitorCheck = $rLastCheck = time();

			while (true) {
				$rSegmentFile = sprintf("%d_%d.ts", $rStreamID, $rCurrent + 1);
				$rNextSegment = sprintf("%d_%d.ts", $rStreamID, $rCurrent + 2);

				if (!AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rSegmentFile, max(1, $rTotalFails), 1000) || !file_exists(STREAMS_PATH . $rSegmentFile)) {
					exit();
				}

				// This node is fenced and its drain is over (NodeLease: a lapsed
				// lease or MAIN's node.fence): a viewer already inside this long
				// request is dropped at the next segment, as a new one is refused.
				if (NodeLease::refusesEverything($rSettings)) {
					exit();
				}

				// A pending admin "send message" for this viewer: burn it onto the
				// next complete segment (the daemon does this when fanout is on).
				// A `drop` entry (the node's agent writes one per viewer it holds
				// once a fence's drain is over) ends the request here.
				if (file_exists(SIGNALS_PATH . $rTokenData["uuid"])) {
					$rSignalData = json_decode(file_get_contents(SIGNALS_PATH . $rTokenData["uuid"]), true);

					if (is_array($rSignalData) && ($rSignalData["type"] ?? '') == "drop") {
						@unlink(SIGNALS_PATH . $rTokenData["uuid"]);
						exit();
					}

					if (is_array($rSignalData) && ($rSignalData["type"] ?? '') == "signal") {
						AsyncFileOperations::awaitFileExists(STREAMS_PATH . $rNextSegment, max(1, $rTotalFails), 1000);
						FfmpegPaths::resolve((string) ($rSettings["ffmpeg_cpu"] ?? ''), $rSettings["ffmpeg_gpu"] ?? null);
						SignalSender::sendSignal(FfmpegPaths::cpu(), $rSignalData, $rSegmentFile, ($rVideoCodec ?: "h264"));
						unlink(SIGNALS_PATH . $rTokenData["uuid"]);
						$rCurrent++;
						continue;
					}
				}

				$rFails = 0;
				$rTimeStart = time();
				$rFP = fopen(STREAMS_PATH . $rSegmentFile, "r");

				// Drain what ffmpeg has written without sleeping; only at the write
				// head (no data) wait ~1 s and count a stall tick. Sleeping after
				// every read capped throughput at read_buffer_size per second.
				while ($rFails <= $rTotalFails && !file_exists(STREAMS_PATH . $rNextSegment)) {
					$rData = stream_get_line($rFP, $rSettings["read_buffer_size"]);
					if (!empty($rData)) {
						echo $rData;
						$rFails = 0;
						continue;
					}

					if (ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID)) {
						AsyncFileOperations::efficientSleep(1000000);
						$rFails++;
					} else {
						AsyncFileOperations::efficientSleep(100000);
					}
				}

				if (ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID) && $rFails <= $rTotalFails && file_exists(STREAMS_PATH . $rSegmentFile) && is_resource($rFP)) {
					// The next segment exists: send the rest of this one.
					$rSegmentSize = filesize(STREAMS_PATH . $rSegmentFile);
					$rRestSize = $rSegmentSize - ftell($rFP);
					if ($rRestSize > 0) {
						echo stream_get_line($rFP, $rRestSize);
					}

					$rTotalTime = max(0.1, time() - $rTimeStart);
					file_put_contents($rConSpeedFile, intval($rSegmentSize / 1024 / $rTotalTime));
				} elseif ($rUserInfo["is_restreamer"] != 1 && $rFails <= $rTotalFails) {
					// The producer died: wait up to one segment for its restart,
					// then carry on from the new playlist.
					for ($rChecks = 0; $rChecks <= $rSegmentSettings["seg_time"] && !ProcessManager::isStreamAlive($rChannelInfo["pid"], $rStreamID); $rChecks++) {
						if (file_exists(STREAMS_PATH . $rStreamID . "_.pid")) {
							$rPidContent = AsyncFileOperations::readFile(STREAMS_PATH . $rStreamID . "_.pid", false);
							if ($rPidContent) {
								$rChannelInfo["pid"] = intval($rPidContent);
							}
						}
						AsyncFileOperations::efficientSleep(1000000);
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

				fclose($rFP);
				$rFails = 0;
				$rCurrent++;

				if ($rSettings["monitor_connection_status"] && 5 <= time() - $rMonitorCheck) {
					if (connection_status() != CONNECTION_NORMAL) {
						exit();
					}
					$rMonitorCheck = time();
				}

				// Every 5 minutes: re-read the settings and refresh hls_last_read;
				// stop when the connection was closed or taken over by another worker.
				if (time() - $rLastCheck > 300) {
					$rLastCheck = time();
					$rConnection = null;
					$rSettings = CacheReader::get("settings") ?: $rSettings;

					if (!ConnectionTracker::checkIn($rSettings, $rTokenData["uuid"], time() - intval($rServers[SERVER_ID]["time_offset"]), $rPID, $rLastCheck)) {
						exit();
					}
				}
			}
	}
} else {
	OffAirHandler::showNotOnAir($rExtension, $rUserInfo, $rIP, $rCountryCode, $rServerID, $rProxyID);
}
