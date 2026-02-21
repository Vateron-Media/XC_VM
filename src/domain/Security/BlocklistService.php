<?php

class BlocklistService {
	public static function blockIP($db, $rData) {
		if (!validateCIDR($rData['ip'])) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		$rArray = array('ip' => $rData['ip'], 'notes' => $rData['notes'], 'date' => time());
		touch(FLOOD_TMP_PATH . 'block_' . $rData['ip']);
		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function processISP($db, $rData, $rGetISPCallback) {
		if (isset($rData['edit'])) {
			if (!hasPermissions('adv', 'block_isps')) {
				exit();
			}
			$rArray = overwriteData(call_user_func($rGetISPCallback, $rData['edit']), $rData);
		} else {
			if (!hasPermissions('adv', 'block_isps')) {
				exit();
			}
			$rArray = verifyPostTable('blocked_isps', $rData);
			unset($rArray['id']);
		}

		if (isset($rData['blocked'])) {
			$rArray['blocked'] = 1;
		} else {
			$rArray['blocked'] = 0;
		}

		if (strlen($rArray['isp']) == 0) {
			return array('status' => STATUS_INVALID_NAME, 'data' => $rData);
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_isps`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function processRTMPIP($db, $rData, $rGetRTMPIPCallback) {
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($rGetRTMPIPCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('rtmp_ips', $rData);
			unset($rArray['id']);
		}

		foreach (array('push', 'pull') as $rSelection) {
			if (isset($rData[$rSelection])) {
				$rArray[$rSelection] = 1;
			} else {
				$rArray[$rSelection] = 0;
			}
		}

		if (!filter_var($rData['ip'], FILTER_VALIDATE_IP)) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		if (checkExists('rtmp_ips', 'ip', $rData['ip'], 'id', $rArray['id'])) {
			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		}

		if (strlen($rData['password']) == 0) {
			$rArray['password'] = generateString(16);
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `rtmp_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function processUA($db, $rData, $rGetUserAgentCallback) {
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($rGetUserAgentCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('blocked_uas', $rData);
			unset($rArray['id']);
		}

		if (isset($rData['exact_match'])) {
			$rArray['exact_match'] = true;
		} else {
			$rArray['exact_match'] = false;
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_uas`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function checkBlockedUAs($rBlockedUA, $rUserAgent, $rReturn = false) {
		$rUserAgent = strtolower($rUserAgent);
		foreach ($rBlockedUA as $rBlocked) {
			if ($rBlocked['exact_match'] == 1) {
				if ($rBlocked['blocked_ua'] == $rUserAgent) {
					return true;
				}
			} else {
				if (stristr($rUserAgent, $rBlocked['blocked_ua'])) {
					return true;
				}
			}
		}
		return false;
	}

	public static function checkISP($rBlockedISP, $rConISP) {
		foreach ($rBlockedISP as $rISP) {
			if (strtolower($rConISP) == strtolower($rISP['isp'])) {
				return intval($rISP['blocked']);
			}
		}
		return 0;
	}

	public static function checkServer($rBlockedServers, $rASN) {
		return in_array($rASN, $rBlockedServers);
	}
}
