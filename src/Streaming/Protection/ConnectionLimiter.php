<?php

namespace XcVm\Streaming\Protection;

use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Lifecycle\ShutdownHandler;

/**
 * ConnectionLimiter — connection limiter
 *
 * @package XC_VM_Streaming_Protection
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ConnectionLimiter {
	public static function writeOfflineActivity($rServerID, $rProxyID, $rUserID, $rStreamID, $rStart, $rUserAgent, $rIP, $rExtension, $rGeoIP, $rISP, $rExternalDevice = '', $rDivergence = 0, $rIsHMAC = null, $rIdentifier = '') {
		global $rSettings;
		ConnectionTracker::writeOfflineActivity($rSettings, intval($rServerID), intval($rProxyID ?? 0), intval($rUserID), intval($rStreamID), intval($rStart), strval($rUserAgent), strval($rIP), strval($rExtension), strval($rGeoIP), strval($rISP), $rExternalDevice, intval($rDivergence), $rIsHMAC, $rIdentifier);
	}

	/**
	 * Enforce a line's connection limit by closing its oldest connections,
	 * preferring ones from the requesting device (same IP + user agent), then the
	 * same IP, then any.
	 *
	 * @param int|null    $rUserID        Line id (null for an HMAC identity).
	 * @param int         $rMaxConnections The line's limit.
	 * @param int|null    $rIsHMAC        HMAC id, or null for a line.
	 * @param string      $rIdentifier    HMAC identifier.
	 * @param string|null $rIP            Requesting IP.
	 * @param string|null $rUserAgent     Requesting user agent.
	 * @param string|null $rCurrentUUID   The requesting connection's uuid — never
	 *                                    closed. A worker used to spare itself by
	 *                                    pid, but a daemon-served viewer's row has
	 *                                    pid 0, so the new viewer could evict itself.
	 * @return int|null Connections closed, or null when within the limit.
	 */
	public static function closeConnections(?int $rUserID, int $rMaxConnections, ?int $rIsHMAC = null, string $rIdentifier = '', ?string $rIP = null, ?string $rUserAgent = null, ?string $rCurrentUUID = null) {
		global $rSettings, $rServers, $db;
		$redis = RedisManager::instance();
		if ($rSettings['redis_handler']) {
			if (!$redis instanceof \Redis) {
				return null;
			}
			$rConnections = [];
			// An HMAC identity's connections are keyed by "<hmac_id>_<identifier>",
			// not a line id (which is null for it).
			$rIdentity = $rIsHMAC ? $rIsHMAC . '_' . $rIdentifier : intval($rUserID);
			$rKeys = $redis->zRangeByScore('LINE#' . $rIdentity, '-inf', '+inf');
			$rKeys = is_array($rKeys) ? $rKeys : [];
			$rToKill = count($rKeys) - $rMaxConnections;
			if ($rToKill > 0) {
				foreach (array_map('igbinary_unserialize', $redis->mGet($rKeys)) as $rConnection) {
					if (is_array($rConnection)) {
						$rConnections[] = $rConnection;
					}
				}
				unset($rKeys);
				// Oldest first, ties by uuid: every request orders them alike.
				usort($rConnections, static fn(array $rA, array $rB): int => [(int) ($rA['date_start'] ?? 0), (string) ($rA['uuid'] ?? '')] <=> [(int) ($rB['date_start'] ?? 0), (string) ($rB['uuid'] ?? '')]);
			} else {
				return null;
			}
		} else {
			if ($rIsHMAC) {
				$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`hmac_id` = ? AND `lines_live`.`hls_end` = 0 AND `lines_live`.`hmac_identifier` = ? ORDER BY `lines_live`.`activity_id` ASC', $rIsHMAC, $rIdentifier);
			} else {
				$db->query('SELECT `lines_live`.*, `on_demand` FROM `lines_live` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `lines_live`.`stream_id` AND `streams_servers`.`server_id` = `lines_live`.`server_id` WHERE `lines_live`.`user_id` = ? AND `lines_live`.`hls_end` = 0 ORDER BY `lines_live`.`activity_id` ASC', $rUserID);
			}
			$rConnectionCount = $db->num_rows();
			$rToKill = $rConnectionCount - $rMaxConnections;
			if ($rToKill > 0) {
				$rConnections = $db->get_rows();
			} else {
				return null;
			}
		}

		// The caller's viewer IP (MAIN admitting for a node passes the viewer's, not its own REMOTE_ADDR).
		$rIP ??= $_SERVER['REMOTE_ADDR'] ?? null;
		$rKilled = 0;
		$rDelSID = $rDelUUID = $rIDs = $rClosed = [];
		if ($rIP && $rUserAgent) {
			$rKillTypes = [2, 1, 0];
		} else {
			if ($rIP) {
				$rKillTypes = [1, 0];
			} else {
				$rKillTypes = [0];
			}
		}

		// Only connections older than the requesting one: two viewers opening
		// at once on a full line each saw the other and closed it, both gone.
		// The newer one closes the older, and the older closes nothing newer.
		$rOlder = count($rConnections);
		foreach ($rConnections as $rIndex => $rConnection) {
			if ($rCurrentUUID !== null && ($rConnection['uuid'] ?? null) === $rCurrentUUID) {
				$rOlder = $rIndex;
				break;
			}
		}

		foreach ($rKillTypes as $rKillOwnIP) {
			$i = 0;
			while ($i < $rOlder && $rKilled < $rToKill) {
				if ($rKilled != $rToKill) {
					$rIsCurrent = $rCurrentUUID !== null && ($rConnections[$i]['uuid'] ?? null) === $rCurrentUUID;
					// Each pass starts over: one closed by an earlier pass is not closed, nor counted, again.
					if (!isset($rClosed[$i]) && !$rIsCurrent && $rConnections[$i]['pid'] != getmypid()) {
						if ($rConnections[$i]['user_ip'] == $rIP && $rConnections[$i]['user_agent'] == $rUserAgent && $rKillOwnIP == 2 || $rConnections[$i]['user_ip'] == $rIP && $rKillOwnIP == 1 || $rKillOwnIP == 0) {
							if (self::closeConnection($rConnections[$i])) {
								$rClosed[$i] = true;
								$rKilled++;
								if ($rConnections[$i]['container'] != 'hls') {
									if ($rSettings['redis_handler']) {
										$rIDs[] = $rConnections[$i];
									} else {
										$rIDs[] = intval($rConnections[$i]['activity_id']);
									}
									$rDelUUID[] = $rConnections[$i]['uuid'];
									$rDelSID[$rConnections[$i]['stream_id']][] = $rConnections[$i]['uuid'];
								}
								if ($rConnections[$i]['on_demand'] && $rConnections[$i]['server_id'] == SERVER_ID && $rSettings['on_demand_instant_off'] && isset($rConnections[$i]['pid'])) {
									ConnectionTracker::removeFromQueue($rConnections[$i]['stream_id'], intval($rConnections[$i]['pid']));
								}
							}
						}
					}
					$i++;
				} else {
					break;
				}
			}
		}

		if ($rIDs !== []) {
			if ($rSettings['redis_handler']) {
				$rUUIDs = [];
				$rRedis = $redis->multi();
				foreach ($rIDs as $rConnection) {
					$rRedis->zRem('LINE#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('LINE_ALL#' . $rConnection['identity'], $rConnection['uuid']);
					$rRedis->zRem('STREAM#' . $rConnection['stream_id'], $rConnection['uuid']);
					$rRedis->zRem('SERVER#' . $rConnection['server_id'], $rConnection['uuid']);
					if ($rConnection['user_id']) {
						$rRedis->zRem('SERVER_LINES#' . $rConnection['server_id'], $rConnection['uuid']);
					}
					if ($rConnection['proxy_id']) {
						$rRedis->zRem('PROXY#' . $rConnection['proxy_id'], $rConnection['uuid']);
					}
					$rRedis->del($rConnection['uuid']);
					$rUUIDs[] = $rConnection['uuid'];
				}
				$rRedis->zRem('CONNECTIONS', ...$rUUIDs);
				$rRedis->zRem('LIVE', ...$rUUIDs);
				$rRedis->sRem('ENDED', ...$rUUIDs);
				$rRedis->exec();
			} else {
				$db->query('DELETE FROM `lines_live` WHERE `activity_id` IN (' . implode(',', array_map('intval', $rIDs)) . ')');
			}

			foreach ($rDelUUID as $rUUID) {
				@unlink(CONS_TMP_PATH . $rUUID);
			}
			foreach ($rDelSID as $rStreamID => $rUUIDs) {
				foreach ($rUUIDs as $rUUID) {
					@unlink(CONS_TMP_PATH . $rStreamID . '/' . $rUUID);
				}
			}
		}

		return $rKilled;
	}

	public static function closeConnection($rActivityInfo) {
		global $rSettings, $rServers, $db;
		$redis = RedisManager::instance();
		if (empty($rActivityInfo)) {
			return false;
		}

		if (!is_array($rActivityInfo)) {
			if (!$rSettings['redis_handler']) {
				if (strlen(strval($rActivityInfo)) == 32) {
					$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ?', $rActivityInfo);
				} else {
					$db->query('SELECT * FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo);
				}
				$rActivityInfo = $db->get_row();
			} else {
				$raw = $redis->get($rActivityInfo);
				$rActivityInfo = ($raw !== false) ? igbinary_unserialize($raw) : null;
			}
		}

		if (!is_array($rActivityInfo)) {
			return false;
		}
		// Another node's viewer, closed by MAIN: before the store changes, so an
		// upsert of it already on its way cannot open it again (ConnectionIngest).
		if (($rActivityInfo['server_id'] ?? SERVER_ID) != SERVER_ID && class_exists(ClusterRoute::class)) {
			ClusterRoute::tombstone(intval($rActivityInfo['server_id']), (string) ($rActivityInfo['uuid'] ?? ''), intval($rActivityInfo['date_start'] ?? 0));
		}

		if ($rActivityInfo['container'] == 'rtmp') {
			if ($rActivityInfo['server_id'] == SERVER_ID) {
				shell_exec('wget --timeout=2 -O /dev/null -o /dev/null "' . $rServers[SERVER_ID]['rtmp_mport_url'] . 'control/drop/client?clientid=' . intval($rActivityInfo['pid']) . '" >/dev/null 2>/dev/null &');
			} else {
				if ($rSettings['redis_handler']) {
					ConnectionTracker::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 1);
				} else {
					SignalDispatcher::kill(intval($rActivityInfo['server_id']), intval($rActivityInfo['pid']), true, $db);
				}
			}
		} else {
			if ($rActivityInfo['container'] == 'hls' || $rActivityInfo['container'] == 'm3u8') {
				if ($rSettings['redis_handler']) {
					ConnectionTracker::updateConnection($rActivityInfo, [], 'close');
				} else {
					$db->query('UPDATE `lines_live` SET `hls_end` = 1 WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
				}
				// segment.php serves a daemon HLS segment only while this marker
				// exists: removing it ends the kicked player within one segment
				// instead of at its next playlist refresh.
				if ($rActivityInfo['server_id'] == SERVER_ID && !empty($rActivityInfo['uuid'])) {
					@unlink(CONS_TMP_PATH . $rActivityInfo['uuid']);
				}
			} elseif (intval($rActivityInfo['pid']) === 0) {
				// Daemon-served live TS (ADR 0003): no worker to kill — the daemon
				// serving it drops the uuid (directly, or via its node's signals).
				ConnectionTracker::dropDaemonViewer($rActivityInfo);
			} else {
				if ($rActivityInfo['server_id'] == SERVER_ID) {
					if ($rActivityInfo['pid'] != getmypid() && is_numeric($rActivityInfo['pid']) && 0 < $rActivityInfo['pid']) {
						posix_kill(intval($rActivityInfo['pid']), 9);
					}
				} else {
					if ($rSettings['redis_handler']) {
						ConnectionTracker::redisSignal($rActivityInfo['pid'], $rActivityInfo['server_id'], 0);
					} else {
						SignalDispatcher::kill(intval($rActivityInfo['server_id']), intval($rActivityInfo['pid']), false, $db);
					}
				}
			}
		}

		// A node whose agent holds its viewers (CONNECTIONS on) hears of the
		// close too (MAIN only), or its registry would resume a kicked HLS
		// viewer on the next playlist request.
		$rHLS = $rActivityInfo['container'] == 'hls' || $rActivityInfo['container'] == 'm3u8';
		if ($rActivityInfo['server_id'] != SERVER_ID && !empty($rActivityInfo['uuid']) && class_exists(ClusterRoute::class)) {
			ClusterRoute::closeConnection(intval($rActivityInfo['server_id']), (string) $rActivityInfo['uuid'], !$rHLS);
		}

		// An HLS viewer is only ended here: its record stays, and what removes
		// it (the sweep, or its node's conn.close) writes its activity row, as
		// for any ended viewer. Written here too, it was counted twice.
		if (!$rHLS) {
			self::writeOfflineActivity($rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'] ?? '', $rActivityInfo['divergence'] ?? 0, $rActivityInfo['hmac_id'] ?? null, $rActivityInfo['hmac_identifier'] ?? '');
		}
		return true;
	}

	/**
	 * An RTMP client's play ended (nginx-rtmp's play_done): on a node whose
	 * agent holds the viewer it ends there (the agent tells MAIN); otherwise its
	 * connection is removed and its activity written, in either store. In Redis the record is
	 * named after the client id (rtmp.php: md5) and only this server's RTMP
	 * record for that client is closed, as ended: the client is gone, so
	 * nothing is dropped.
	 */
	public static function closeRTMP($rPID) {
		global $db, $rSettings, $rServers;
		if (empty($rPID)) {
			return false;
		}

		// A viewer this node's agent holds (CONNECTIONS on): it ends there, and
		// the agent tells MAIN, as a worker's viewer ends (ShutdownHandler).
		if (ShutdownHandler::closeInRegistry(ConnectionTracker::rtmpUuid($rPID), (int) $rPID, time() - intval($rServers[SERVER_ID]['time_offset'] ?? 0))) {
			return true;
		}
		// A node in mode 2 has no other store: its agent did not answer.
		if (NodeRole::refusesConnects()) {
			return false;
		}

		if (!empty($rSettings['redis_handler'])) {
			// ponytail: md5($rPID) is the key before rtmpUuid(); drop it once no play opened before the upgrade is left.
			// nosemgrep: php.lang.security.weak-crypto.weak-crypto
			foreach ([ConnectionTracker::rtmpUuid($rPID), md5((string) $rPID)] as $rUUID) {
				$rRecord = ConnectionTracker::getConnection($rUUID);
				if (is_array($rRecord) && ($rRecord['container'] ?? '') === 'rtmp' && (int) ($rRecord['server_id'] ?? 0) === (int) SERVER_ID && (string) ($rRecord['pid'] ?? '') === (string) $rPID) {
					return ConnectionTracker::closeConnection(['hls_end' => 1] + $rRecord);
				}
			}
			return false;
		}

		$db->query("SELECT * FROM `lines_live` WHERE `container` = 'rtmp' AND `pid` = ? AND `server_id` = ?", $rPID, SERVER_ID);
		if (0 >= $db->num_rows()) {
			return false;
		}

		$rActivityInfo = $db->get_row();
		$db->query('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rActivityInfo['activity_id']);
		self::writeOfflineActivity($rActivityInfo['server_id'], $rActivityInfo['proxy_id'], $rActivityInfo['user_id'], $rActivityInfo['stream_id'], $rActivityInfo['date_start'], $rActivityInfo['user_agent'], $rActivityInfo['user_ip'], $rActivityInfo['container'], $rActivityInfo['geoip_country_code'], $rActivityInfo['isp'], $rActivityInfo['external_device'] ?? '', $rActivityInfo['divergence'] ?? 0, $rActivityInfo['hmac_id'] ?? null, $rActivityInfo['hmac_identifier'] ?? '');
		return true;
	}
}
