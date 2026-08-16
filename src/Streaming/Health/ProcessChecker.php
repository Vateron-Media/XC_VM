<?php

namespace XcVm\Streaming\Health;


/**
 * ProcessChecker — process checker
 *
 * @package XC_VM_Streaming_Health
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProcessChecker {
	public static function checkPID($rPID, $rSearch) {
		if (!is_array($rSearch)) {
			$rSearch = array($rSearch);
		}
		if (file_exists('/proc/' . $rPID)) {
			$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
			foreach ($rSearch as $rTerm) {
				if (stristr($rCommand, $rTerm)) {
					return true;
				}
			}
		}
		return false;
	}

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
