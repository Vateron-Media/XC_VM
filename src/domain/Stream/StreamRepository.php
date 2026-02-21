<?php

class StreamRepository {
	public static function getErrors($db, $rStreamID, $rAmount = 250) {
		$rReturn = array();
		$db->query('SELECT * FROM (SELECT MAX(`date`) AS `date`, `error` FROM `streams_errors` WHERE `stream_id` = ? GROUP BY `error`) AS `output` ORDER BY `date` DESC LIMIT ' . intval($rAmount) . ';', $rStreamID);

		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}

		return $rReturn;
	}

	public static function getById($db, $rID) {
		$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
	}

	public static function getStats($db, $rStreamID) {
		$rReturn = array();
		$db->query('SELECT * FROM `streams_stats` WHERE `stream_id` = ?;', $rStreamID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['type']] = $rRow;
			}
		}

		foreach (array('today', 'week', 'month', 'all') as $rType) {
			if (isset($rReturn[$rType])) {
			} else {
				$rReturn[$rType] = array('rank' => 0, 'users' => 0, 'connections' => 0, 'time' => 0);
			}
		}

		return $rReturn;
	}

	public static function getPIDs($db, $rServerID, $rSettings) {
		$rReturn = array();
		$db->query('SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`type`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`delay_pid` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams_servers`.`server_id` = ?;', $rServerID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				foreach (array('pid', 'monitor_pid', 'delay_pid') as $rPIDType) {
					if (!$rRow[$rPIDType]) {
					} else {
						$rReturn[$rRow[$rPIDType]] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => $rPIDType);
					}
				}
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `tv_archive_pid` FROM `streams` WHERE `tv_archive_server_id` = ?;', $rServerID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['tv_archive_pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'timeshift');
			}
		}

		$db->query('SELECT `id`, `stream_display_name`, `type`, `vframes_pid` FROM `streams` WHERE `vframes_server_id` = ?;', $rServerID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['vframes_pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'vframes');
			}
		}

		if ($rSettings['redis_handler']) {
			$rStreamIDs = $rStreamMap = array();
			$rConnections = CoreUtilities::getRedisConnections(null, $rServerID, null, true, false, false);

			foreach ($rConnections as $rConnection) {
				if (in_array($rConnection['stream_id'], $rStreamIDs)) {
				} else {
					$rStreamIDs[] = intval($rConnection['stream_id']);
				}
			}

			if (0 >= count($rStreamIDs)) {
			} else {
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

			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					$rReturn[$rRow['pid']] = array('id' => $rRow['id'], 'title' => $rRow['stream_display_name'], 'type' => $rRow['type'], 'pid_type' => 'activity');
				}
			}
		}

		return $rReturn;
	}

	public static function getOptions($db, $rID) {
		$rReturn = array();
		$db->query('SELECT * FROM `streams_options` WHERE `stream_id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['argument_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getSystemRows($db, $rID) {
		$rReturn = array();
		$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['server_id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getNextOrder($db) {
		$db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');

		if ($db->num_rows() != 1) {
			return 0;
		}

		return intval($db->get_row()['order']) + 1;
	}
}
