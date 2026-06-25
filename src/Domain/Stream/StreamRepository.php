<?php

namespace XcVm\Domain\Stream;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Vod\MovieService;

use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
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
	private static $db = null;

	/**
	 * Inject the database handler (dependency injection).
	 *
	 * @param \XcVm\Core\Database\DatabaseHandler $db Database handler.
	 * @return void
	 */
	public static function setDb($db): void {
		self::$db = $db;
	}

	/**
	 * Get the injected database handler.
	 *
	 * @return object Database handler.
	 * @throws \RuntimeException If setDb() was not called first.
	 */
	private static function db(): object {
		if (self::$db === null) {
			throw new \RuntimeException(static::class . '::setDb() must be called before use.');
		}
		return self::$db;
	}
	/**
	 * Fetch recent error-log entries for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @param int $rAmount    Maximum number of entries.
	 * @return array Error rows.
	 */
	public static function getErrors($rStreamID, $rAmount = 250) {
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
	public static function getById($rID) {
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
	public static function getStats($rStreamID) {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `streams_stats` WHERE `stream_id` = ?;', $rStreamID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['type']] = $rRow;
			}
		}

		foreach (array('today', 'week', 'month', 'all') as $rType) {
			if (!isset($rReturn[$rType])) {
				$rReturn[$rType] = array('rank' => 0, 'users' => 0, 'connections' => 0, 'time' => 0);
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
	public static function getPIDs($rServerID) {
		global $rSettings;
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`type`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`delay_pid` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				foreach (array('pid', 'monitor_pid', 'delay_pid') as $rPIDType) {
					if ($rRow[$rPIDType]) {
						$rReturn[$rRow[$rPIDType]] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => $rPIDType);
					}
				}
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `tv_archive_pid` FROM `streams` WHERE `tv_archive_server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['tv_archive_pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'timeshift');
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `vframes_pid` FROM `streams` WHERE `vframes_server_id` = ?;', $rServerID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['vframes_pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'vframes');
			}
		}

		if ($rSettings['redis_handler']) {
			$rStreamIDs = $rStreamMap = array();
			$rConnections = ConnectionTracker::getRedisConnections(null, $rServerID, null, true, false, false);

			foreach ($rConnections as $rConnection) {
				if (!in_array($rConnection['stream_id'], $rStreamIDs)) {
					$rStreamIDs[] = intval($rConnection['stream_id']);
				}
			}

			if (count($rStreamIDs) > 0) {
				$db->query('SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rStreamMap[$rRow['id']] = array($rRow['stream_display_name'], $rRow['type']);
				}
			}

			foreach ($rConnections as $rRow) {
				$rReturn[$rRow['pid']] = array('id' => $rRow['stream_id'], 'title' => $rStreamMap[$rRow['stream_id']][0], 'type' => $rStreamMap[$rRow['stream_id']][1], 'pid_type' => 'activity');
			}
		} else {
			$db->query('SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`type`, `lines_live`.`pid` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` WHERE `lines_live`.`server_id` = ?;', $rServerID);

			if ($db->num_rows() > 0) {
				foreach ($db->get_rows() as $rRow) {
					$rReturn[$rRow['pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'activity');
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
	public static function getOptions($rID) {
		$db = self::db();
		$rReturn = array();
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
	public static function getSystemRows($rID) {
		$db = self::db();
		$rReturn = array();
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
	public static function getEncodeErrors($rID) {
		$db = self::db();
		$rErrors = array();
		$db->query('SELECT `server_id`, `error` FROM `streams_errors` WHERE `stream_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			$rErrors[intval($rRow['server_id'])] = $rRow['error'];
		}

		return $rErrors;
	}

	/**
	 * Resolve selectable streams from a set of sources.
	 *
	 * @param array $rSources Source identifiers.
	 * @return array Matching stream selections.
	 */
	public static function getSelections($rSources) {
		$db = self::db();
		$rReturn = array();

		foreach ($rSources as $rSource) {
			$db->query("SELECT `id` FROM `streams` WHERE `type` IN (2,5) AND `stream_source` LIKE ? ESCAPE '|' LIMIT 1;", '%' . str_replace('/', '\\/', $rSource) . '"%');

			if ($db->num_rows() != 1) {
			} else {
				$rReturn[] = intval($db->get_row()['id']);
			}
		}

		return $rReturn;
	}

	/**
	 * Delete a single stream and its associated data.
	 *
	 * @param int  $rID                Stream id.
	 * @param int  $rServerID          Restrict deletion to a server (-1 for all).
	 * @param bool $rDeleteFiles       Also remove on-disk stream files.
	 * @param bool $f2d619cb38696890   Internal flag controlling cascade behavior.
	 * @return bool True on success.
	 */
	public static function deleteStream($rID, $rServerID = -1, $rDeleteFiles = true, $f2d619cb38696890 = true) {
		$db = self::db();
		$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$rType = $db->get_row()['type'];
		$rRemaining = 0;

		if ($rServerID == -1) {
		} else {
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
			$db->query('DELETE FROM `watch_logs` WHERE `stream_id` = ?;', $rID);
			$db->query('DELETE FROM `recordings` WHERE `created_id` = ? OR `stream_id` = ?;', $rID, $rID);
			$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` = ?;', $rID);
			$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
			$rServerIDs = array();

			foreach ($db->get_rows() as $rRow) {
				$rServerIDs[] = $rRow['server_id'];
			}

			if (!($rDeleteFiles && 0 < count($rServerIDs) && in_array($rType, array(2, 5)))) {
			} else {
				MovieService::deleteFile($rServerIDs, $rID);
			}

			$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
		} else {
			$rServerIDs = array($rServerID);
			$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rID, $rServerID);

			if (!($rDeleteFiles && in_array($rType, array(2, 5)))) {
			} else {
				MovieService::deleteFile(array($rServerID), $rID);
			}
		}

		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		StreamProcess::updateStream($rID);
		BouquetService::scan();

		return true;
	}

	/**
	 * Bulk delete streams.
	 *
	 * @param int[] $rIDs         Stream ids.
	 * @param bool  $rDeleteFiles Also remove on-disk stream files.
	 * @return bool True on success.
	 */
	public static function deleteStreams($rIDs, $rDeleteFiles = false) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
		} else {
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

			if ($rDeleteFiles) {
				foreach (array_keys(ServerRepository::getAll()) as $rServerID) {
					$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
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
	public static function deleteStreamsByServer($rIDs, $rServerID, $rDeleteFiles = false) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
		} else {
			$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);
			$db->query('UPDATE `streams_servers` SET `parent_id` = NULL WHERE `parent_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);

			if ($rDeleteFiles) {
				$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
			}
		}

		return true;
	}

	/**
	 * Fetch a watch-folder row by id.
	 *
	 * @param int $rID Watch-folder id.
	 * @return array|false The row, or false if not found.
	 */
	public static function getWatchFolder($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `watch_folders` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
		return false;
	}

	/**
	 * Delete a watch folder.
	 *
	 * @param int $rID Watch-folder id.
	 * @return bool True on success.
	 */
	public static function deleteWatchFolder($rID) {
		$db = self::db();
		$db->query('SELECT `id` FROM `watch_folders` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `watch_folders` WHERE `id` = ?;', $rID);

		return true;
	}
}
