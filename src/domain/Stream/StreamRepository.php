<?php

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
	public static function getErrors($rStreamID, $rAmount = 250) {
		global $db;
		$db->query('SELECT * FROM (SELECT MAX(`date`) AS `date`, `error` FROM `streams_errors` WHERE `stream_id` = ? GROUP BY `error`) AS `output` ORDER BY `date` DESC LIMIT ' . intval($rAmount) . ';', $rStreamID);
		return $db->get_rows();
	}

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
	}

	public static function getStats($rStreamID) {
		global $db;
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

	public static function getPIDs($rServerID) {
		global $db, $rSettings;
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

	public static function getOptions($rID) {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `streams_options` WHERE `stream_id` = ?;', $rID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['argument_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getSystemRows($rID) {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` = ?;', $rID);

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['server_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getNextOrder() {
		global $db;
		$db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');

		if ($db->num_rows() != 1) {
			return 0;
		}


		return intval($db->get_row()['order']) + 1;
	}

	public static function getEncodeErrors($rID) {
		global $db;
		$rErrors = array();
		$db->query('SELECT `server_id`, `error` FROM `streams_errors` WHERE `stream_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			$rErrors[intval($rRow['server_id'])] = $rRow['error'];
		}

		return $rErrors;
	}

	public static function getSelections($rSources) {
		global $db;
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

	public static function deleteStream($rID, $rServerID = -1, $rDeleteFiles = true, $f2d619cb38696890 = true) {
		global $db;
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

	public static function deleteStreams($rIDs, $rDeleteFiles = false) {
		global $db;
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

	public static function deleteStreamsByServer($rIDs, $rServerID, $rDeleteFiles = false) {
		global $db;
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

	public static function getWatchFolder($rID) {
		global $db;
		$db->query('SELECT * FROM `watch_folders` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function deleteWatchFolder($rID) {
		global $db;
		$db->query('SELECT `id` FROM `watch_folders` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `watch_folders` WHERE `id` = ?;', $rID);

		return true;
	}
}
