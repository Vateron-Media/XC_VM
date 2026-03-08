<?php

class ProcessChecker {
	public static function isPIDsRunning($rServerIDS, $rPIDs, $rEXE) {
		global $rServers;
		if (!is_array($rServerIDS)) {
			$rServerIDS = array(intval($rServerIDS));
		}
		$rPIDs = array_map('intval', $rPIDs);
		$rOutput = array();
		foreach ($rServerIDS as $rServerID) {
			if (is_array($rServers) && array_key_exists($rServerID, $rServers)) {
				$rResponse = CurlClient::serverRequest($rServerID, $rServers[$rServerID]['api_url_ip'] . '&action=pidsAreRunning', array('program' => $rEXE, 'pids' => $rPIDs));
				if ($rResponse) {
					$rDecoded = json_decode($rResponse, true);
					if (is_array($rDecoded)) {
						$rOutput[$rServerID] = array_map('trim', $rDecoded);
					} else {
						$rOutput[$rServerID] = false;
					}
				} else {
					$rOutput[$rServerID] = false;
				}
			}
		}
		return $rOutput;
	}

	public static function isPIDRunning($rServerID, $rPID, $rEXE) {
		global $rServers;
		if (!is_null($rPID) && is_numeric($rPID) && is_array($rServers) && array_key_exists($rServerID, $rServers)) {
			if (!($rOutput = self::isPIDsRunning($rServerID, array($rPID), $rEXE))) {
				return false;
			}
			return $rOutput[$rServerID][$rPID];
		}
		return false;
	}

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
}
