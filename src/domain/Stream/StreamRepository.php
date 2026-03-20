<?php

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
}
