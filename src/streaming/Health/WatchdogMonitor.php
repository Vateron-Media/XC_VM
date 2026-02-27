<?php

class WatchdogMonitor {
	public static function getWatchdog($rID, $rLimit = 86400) {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `servers_stats` WHERE `server_id` = ? AND UNIX_TIMESTAMP() - `time` <= ? ORDER BY `time` DESC;', $rID, $rLimit);
		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}
		return $rReturn;
	}
}
