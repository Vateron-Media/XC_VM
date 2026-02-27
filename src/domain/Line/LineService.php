<?php

class LineService {
	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rLines = json_decode($rData['lines'], true);
		LineRepository::deleteMany($rLines);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		return API::massEditLinesLegacy($rData);
	}

	public static function process($rData) {
		return API::processLineLegacy($rData);
	}

	public static function deleteLineSignal($rCached, $rMainID, $rUserID, $rForce = false) {
		self::updateLineSignal($rCached, $rMainID, $rUserID, $rForce);
	}

	public static function deleteLinesSignal($rCached, $rMainID, $rUserIDs, $rForce = false) {
		self::updateLinesSignal($rCached, $rMainID, $rUserIDs);
	}

	public static function updateLineSignal($rCached, $rMainID, $rUserID, $rForce = false) {
		global $db;
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_line', 'id' => $rUserID)));
			}
			return true;
		}
		return false;
	}

	public static function updateLinesSignal($rCached, $rMainID, $rUserIDs) {
		global $db;
		if ($rCached) {
			$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			if ($db->get_row()['count'] != 0) {
			} else {
				$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), json_encode(array('type' => 'update_lines', 'id' => $rUserIDs)));
			}
			return true;
		}
		return false;
	}
}
