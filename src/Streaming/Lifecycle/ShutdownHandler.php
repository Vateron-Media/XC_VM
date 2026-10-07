<?php

namespace XcVm\Streaming\Lifecycle;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Cache\CacheReader;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Общий shutdown handler для streaming endpoints (live, vod, timeshift).
 *
 * Заменяет дублированные function shutdown() в трёх файлах.
 * auth.php и rtmp.php используют собственный (другую логику — BruteforceGuard).
 *
 * Использование:
 *   register_shutdown_function([ShutdownHandler::class, 'handle'], 'live');
 *
 * @package XC_VM_Streaming_Lifecycle
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ShutdownHandler {
	/**
	 * @param string $rContext  'live' | 'vod' | 'timeshift'
	 */
	public static function handle(string $rContext = 'live') {
		global $rCloseCon, $rTokenData, $rPID, $rChannelInfo, $rStreamID, $rServers, $db;
		$rSettings = CacheReader::get('settings');

		if ($rCloseCon) {
			$rLastRead = time() - intval($rServers[SERVER_ID]['time_offset']);

			// A node in mode 2 has no other store: its agent did not answer.
			if (!self::closeInRegistry((string) ($rTokenData['uuid'] ?? ''), (int) $rPID, $rLastRead) && !NodeRole::refusesConnects()) {
				if (!empty($rSettings['redis_handler'])) {
					if (!RedisManager::isConnected()) {
						RedisManager::ensureConnected();
					}

					$rConnection = ConnectionTracker::getConnection($rTokenData['uuid']);

					if ($rConnection && $rConnection['pid'] == $rPID) {
						ConnectionTracker::updateConnection($rConnection, ['hls_last_read' => $rLastRead], 'close');
					}
				} else {
					if (!is_object($db)) {
						DatabaseFactory::connectLazy();
					}

					$db->query(
						'UPDATE `lines_live` SET `hls_end` = 1, `hls_last_read` = ? WHERE `uuid` = ? AND `pid` = ?;',
						$rLastRead,
						$rTokenData['uuid'],
						$rPID
					);
				}
			}

			// live: clean up both connection tmp files
			// vod/timeshift: clean up the token touch file so it can't be reused after expiry
			@unlink(CONS_TMP_PATH . $rTokenData['uuid']);
			if ($rContext === 'live') {
				@unlink(CONS_TMP_PATH . $rStreamID . '/' . $rTokenData['uuid']);
			}
		}

		// Только live: on-demand instant off
		if ($rContext === 'live' && !empty($rSettings['on_demand_instant_off']) && !empty($rChannelInfo['on_demand'])) {
			ConnectionTracker::removeFromQueue($rStreamID, $rPID);
		}

		// Закрытие ресурсов
		if (empty($rSettings['redis_handler']) && is_object($db)) {
			DatabaseFactory::close();
		} elseif (!empty($rSettings['redis_handler']) && RedisManager::isConnected()) {
			RedisManager::closeInstance();
		}
	}

	/**
	 * The viewer's close in this node's own registry, where its agent holds it
	 * (CONNECTIONS on): the agent mirrors it to MAIN as a P0 event. False when
	 * the record is not the agent's (or it did not answer) and the close
	 * belongs in MAIN's store, as before.
	 */
	public static function closeInRegistry(string $rUUID, int $rPID, int $rLastRead): bool {
		if ($rUUID === '' || !AgentConnections::enabled()) {
			return false;
		}
		$rRecord = AgentConnections::get($rUUID);
		if (!is_array($rRecord) || (int) ($rRecord['pid'] ?? 0) !== $rPID) {
			return false;
		}
		return AgentConnections::put($rUUID, array_merge($rRecord, ['hls_end' => 1, 'hls_last_read' => $rLastRead])) === true;
	}
}
