<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Reference\StatusBadge;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Vod\MovieService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * StreamRepository — stream repository
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamRepository {
	use DatabaseAware;

	/** Events one logSince() call returns at most; the rest stay past its `last` for the next. */
	private const LOG_PAGE = 500;

	/** How far below `last` a missing id is reported as a hole, and how many holes are taken back. */
	private const LOG_HOLE_SPAN = 200;

	/**
	 * The stream log past $rAfter, for the panel's toasts (start, stop, a
	 * failed start…, from every server: a load balancer's entries reach MAIN
	 * within a minute), oldest first, each with its label and the stream's and
	 * server's names.
	 *
	 * - At most LOG_PAGE new entries: `last` is then the last one returned, and
	 *   the rest come next time, so nothing is passed over unread.
	 * - Ids missing near `last` come back as `holes` (a row whose insert
	 *   commits after a higher one's, or one rolled back): the caller sends
	 *   them back as $rHoles, and a row that has appeared since is returned once.
	 * - A cursor past where the log stands means it was emptied since (its ids
	 *   start over): read from its start. A negative one only learns where the
	 *   log stands, so a page shows what happens from then on.
	 *
	 * Null when the database does not answer: the caller must keep its cursor.
	 *
	 * @param list<int> $rHoles
	 * @return array{last: int, events: list<array{id: int, stream_id: int, action: string, label: string, stream: string, server: string}>, holes: list<int>}|null
	 */
	public static function logSince(int $rAfter, array $rHoles = []): ?array {
		$db = self::db();
		if (!$db->query('SELECT MAX(`id`) AS `id` FROM `streams_logs`;')) {
			return null;
		}
		$rTop = (int) ($db->get_row()['id'] ?? 0);
		if ($rAfter < 0) {
			return ['last' => $rTop, 'events' => [], 'holes' => []];
		}
		if ($rAfter > $rTop) {
			$rAfter = 0;
			$rHoles = [];
		}
		$rHoles = array_slice(array_values(array_unique(array_filter(array_map('intval', $rHoles), static fn(int $rID): bool => $rID > 0 && $rID <= $rAfter))), -self::LOG_HOLE_SPAN);
		$rWhere = '(`l`.`id` > ? AND `l`.`id` <= ?)' . ($rHoles !== [] ? ' OR `l`.`id` IN (' . implode(',', $rHoles) . ')' : '');
		if (!$db->query('SELECT `l`.`id`, `l`.`stream_id`, `l`.`action`, `s`.`stream_display_name`, `v`.`server_name` FROM `streams_logs` `l` LEFT JOIN `streams` `s` ON `s`.`id` = `l`.`stream_id` LEFT JOIN `servers` `v` ON `v`.`id` = `l`.`server_id` WHERE ' . $rWhere . ' ORDER BY `l`.`id` ASC LIMIT ' . (self::LOG_PAGE + count($rHoles)) . ';', $rAfter, $rTop)) {
			return null;
		}
		$rEvents = [];
		$rRead = [];
		$rNew = 0;
		$rLast = $rTop;
		foreach ($db->get_raw_rows() as $rRow) {
			$rID = (int) $rRow['id'];
			$rRead[$rID] = true;
			$rAction = (string) $rRow['action'];
			$rEvents[] = [
				'id' => $rID,
				'stream_id' => (int) $rRow['stream_id'],
				'action' => $rAction,
				'label' => StatusBadge::streamLog($rAction) ?: $rAction,
				'stream' => (string) ($rRow['stream_display_name'] ?? '#' . $rRow['stream_id']),
				'server' => (string) ($rRow['server_name'] ?? ''),
			];
			if ($rID > $rAfter && ++$rNew === self::LOG_PAGE) {
				$rLast = $rID;
			}
		}
		$rMissing = [];
		for ($i = max($rAfter, $rLast - self::LOG_HOLE_SPAN) + 1; $i <= $rLast; $i++) {
			if (!isset($rRead[$i])) {
				$rMissing[] = $i;
			}
		}
		foreach ($rHoles as $rID) {
			if (!isset($rRead[$rID])) {
				$rMissing[] = $rID;
			}
		}
		return ['last' => $rLast, 'events' => $rEvents, 'holes' => array_values(array_unique($rMissing))];
	}

	/**
	 * Fetch recent error-log entries for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @param int $rAmount    Maximum number of entries.
	 * @return array Error rows.
	 */
	public static function getErrors(int $rStreamID, int $rAmount = 250) {
		$db = self::db();
		$db->query('SELECT * FROM (SELECT MAX(`date`) AS `date`, `error` FROM `streams_errors` WHERE `stream_id` = ? GROUP BY `error`) AS `output` ORDER BY `date` DESC LIMIT ' . intval($rAmount) . ';', $rStreamID);
		return $db->get_rows();
	}

	/**
	 * Fetch a single stream by id.
	 *
	 * @param int $rID Stream id.
	 * @return array|false The stream row, or false if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
		return false;
	}

	/**
	 * Fetch runtime statistics for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return array Stats data.
	 */
	public static function getStats(int $rStreamID) {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `streams_stats` WHERE `stream_id` = ?;', $rStreamID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['type']] = $rRow;
			}
		}

		foreach (['today', 'week', 'month', 'all'] as $rType) {
			if (!isset($rReturn[$rType])) {
				$rReturn[$rType] = ['rank' => 0, 'users' => 0, 'connections' => 0, 'time' => 0];
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch the stream process PIDs running on a server.
	 *
	 * @param int $rServerID Server id.
	 * @return array PID information keyed by stream.
	 */
	public static function getPIDs(int $rServerID) {
		global $rSettings;
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`type`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`delay_pid` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				foreach (['pid', 'monitor_pid', 'delay_pid'] as $rPIDType) {
					if ($rRow[$rPIDType]) {
						$rReturn[$rRow[$rPIDType]] = ['id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => $rPIDType];
					}
				}
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `tv_archive_pid` FROM `streams` WHERE `tv_archive_server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['tv_archive_pid']] = ['id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'timeshift'];
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `vframes_pid` FROM `streams` WHERE `vframes_server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['vframes_pid']] = ['id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'vframes'];
			}
		}

		if ($rSettings['redis_handler']) {
			$rStreamIDs = $rStreamMap = [];
			$rConnections = ConnectionTracker::getRedisConnections(null, $rServerID, null, true, false, false);

			foreach ($rConnections as $rConnection) {
				if (!in_array($rConnection['stream_id'], $rStreamIDs)) {
					$rStreamIDs[] = intval($rConnection['stream_id']);
				}
			}

			if (count($rStreamIDs) > 0) {
				$db->query('SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rStreamMap[$rRow['id']] = [$rRow['stream_display_name'], $rRow['type']];
				}
			}

			foreach ($rConnections as $rRow) {
				$rReturn[$rRow['pid']] = ['id' => $rRow['stream_id'], 'title' => $rStreamMap[$rRow['stream_id']][0], 'type' => $rStreamMap[$rRow['stream_id']][1], 'pid_type' => 'activity'];
			}
		} else {
			$db->query('SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`type`, `lines_live`.`pid` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` WHERE `lines_live`.`server_id` = ?;', $rServerID);

			if ($db->num_rows() > 0) {
				foreach ($db->get_rows() as $rRow) {
					$rReturn[$rRow['pid']] = ['id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'activity'];
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch the per-stream options/configuration.
	 *
	 * @param int $rID Stream id.
	 * @return array Stream options.
	 */
	public static function getOptions(int $rID) {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `streams_options` WHERE `stream_id` = ?;', $rID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['argument_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch internal/system rows associated with a stream.
	 *
	 * @param int $rID Stream id.
	 * @return array System rows.
	 */
	public static function getSystemRows(int $rID) {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` = ?;', $rID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['server_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Get the next available channel order number.
	 *
	 * @return int Next order value.
	 */
	public static function getNextOrder() {
		$db = self::db();
		$db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');

		if ($db->num_rows() != 1) {
			return 0;
		}


		return intval($db->get_row()['order']) + 1;
	}

	/**
	 * Fetch encoding error records for a stream.
	 *
	 * @param int $rID Stream id.
	 * @return array Encode error rows.
	 */
	public static function getEncodeErrors(int $rID) {
		$db = self::db();
		$rErrors = [];
		$db->query('SELECT `server_id`, `error` FROM `streams_errors` WHERE `stream_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			$rErrors[intval($rRow['server_id'])] = $rRow['error'];
		}

		return $rErrors;
	}

	/**
	 * Delete a single stream and its associated data.
	 *
	 * @param int  $rID                Stream id.
	 * @param int  $rServerID          Restrict deletion to a server (-1 for all).
	 * @param bool $rDeleteFiles       Also remove on-disk stream files.
	 * @param bool $f2d619cb38696890   Internal flag controlling cascade behavior.
	 * @param bool $rScan              Start the bouquet scan; false for a caller that deletes several streams and starts one scan after the last.
	 * @return bool True on success.
	 */
	public static function deleteStream(int $rID, int $rServerID = -1, bool $rDeleteFiles = true, bool $f2d619cb38696890 = true, bool $rScan = true) {
		$db = self::db();
		$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$rType = $db->get_row()['type'];
		$rRemaining = 0;

		if ($rServerID != -1) {
			$db->query('SELECT `server_stream_id` FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` <> ?;', $rID, $rServerID);
			$rRemaining = $db->num_rows();
		}

		if ($rRemaining == 0 && $f2d619cb38696890) {
			$db->query('DELETE FROM `lines_logs` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `mag_claims` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `streams` WHERE `id` = ?;', $rID);
			$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `streams_errors` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `streams_stats` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` = ?;', $rID);
			EventDispatcher::dispatch(new StreamsDeletedEvent([$rID]));
			$db->query('DELETE FROM `recordings` WHERE `created_id` = ? OR `stream_id` = ?;', $rID, $rID);
			$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` = ?;', $rID);
			$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
			$rServerIDs = [];

			foreach ($db->get_rows() as $rRow) {
				$rServerIDs[] = $rRow['server_id'];
			}

			if ($rDeleteFiles && 0 < count($rServerIDs) && in_array($rType, [2, 5])) {
				MovieService::deleteFile($rServerIDs, $rID);
			}

			$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
		} else {
			$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rID, $rServerID);

			if ($rDeleteFiles && in_array($rType, [2, 5])) {
				MovieService::deleteFile([$rServerID], $rID);
			}
			// Taken off one server: that node's replica drops it.
			EventDispatcher::dispatch(new StreamsChangedEvent([$rID]));
		}

		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		StreamProcess::updateStream($rID);

		if ($rScan) {
			BouquetService::scan();
		}

		return true;
	}

	/**
	 * Bulk delete streams.
	 *
	 * @param int[] $rIDs         Stream ids.
	 * @param bool  $rDeleteFiles Also remove on-disk stream files.
	 * @return bool True on success.
	 */
	public static function deleteStreams(array $rIDs, bool $rDeleteFiles = false) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 < count($rIDs)) {
			$db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			EventDispatcher::dispatch(new StreamsDeletedEvent($rIDs));
			$db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
			$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
			SignalDispatcher::cache(intval(SERVER_ID), ['type' => 'update_streams', 'id' => $rIDs], false, false, $db);
			if ($rDeleteFiles) {
				foreach (array_keys(ServerRepository::getAll()) as $rServerID) {
					SignalDispatcher::cache(intval($rServerID), ['type' => 'delete_vods', 'id' => $rIDs], false, false, $db);
				}
			}
			BouquetService::scan();
		}

		return true;
	}

	/**
	 * Delete streams scoped to a specific server.
	 *
	 * @param int[] $rIDs         Stream ids.
	 * @param int   $rServerID    Server id.
	 * @param bool  $rDeleteFiles Also remove on-disk stream files.
	 * @return bool True on success.
	 */
	public static function deleteStreamsByServer(array $rIDs, int $rServerID, bool $rDeleteFiles = false) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 < count($rIDs)) {
			$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);
			$db->query('UPDATE `streams_servers` SET `parent_id` = NULL WHERE `parent_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);
			EventDispatcher::dispatch(new StreamsChangedEvent($rIDs));
			if ($rDeleteFiles) {
				SignalDispatcher::cache(intval($rServerID), ['type' => 'delete_vods', 'id' => $rIDs], false, false, $db);
			}
		}

		return true;
	}
}
