<?php

class BlocklistService {
	public static function blockIP($rData) {
		global $db;
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

	public static function processISP($rData) {
		global $db;
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'block_isps')) {
				exit();
			}
			$rArray = overwriteData(getISP($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'block_isps')) {
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

	public static function processRTMPIP($rData) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = overwriteData(getRTMPIP($rData['edit']), $rData);
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

	public static function processUA($rData) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = overwriteData(getUserAgent($rData['edit']), $rData);
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

	public static function checkAndBlockUA($rBlockedUA, $rUserAgent, $rReturn = false) {
		global $db;
		$rUserAgent = strtolower($rUserAgent);
		$rFoundID = false;
		foreach ($rBlockedUA as $rKey => $rBlocked) {
			if ($rBlocked['exact_match'] == 1) {
				if ($rBlocked['blocked_ua'] == $rUserAgent) {
					$rFoundID = $rKey;
					break;
				}
			} else {
				if (stristr($rUserAgent, $rBlocked['blocked_ua'])) {
					$rFoundID = $rKey;
					break;
				}
			}
		}
		if (0 < $rFoundID) {
			$db->query('UPDATE `blocked_uas` SET `attempts_blocked` = `attempts_blocked`+1 WHERE `id` = ?', $rFoundID);
			if ($rReturn) {
				return true;
			}
			exit();
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

	// ──────────── Из BlocklistRepository ────────────

	public static function isProxy($rIP) {
		$rProxies = self::getProxyIPs();
		if (isset($rProxies[$rIP])) {
			return $rProxies[$rIP];
		}
		return null;
	}

	public static function getProxyIPs($rForce = false) {
		global $rServers;
		if (!$rForce) {
			$rCache = FileCache::getCache('proxy_servers', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		foreach ($rServers as $rServer) {
			if ($rServer['server_type'] == 1) {
				$rOutput[$rServer['server_ip']] = $rServer;
				if ($rServer['private_ip']) {
					$rOutput[$rServer['private_ip']] = $rServer;
				}
			}
		}

		FileCache::setCache('proxy_servers', $rOutput);

		return $rOutput;
	}

	public static function getBlockedUA($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('blocked_ua', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$db->query('SELECT id,exact_match,LOWER(user_agent) as blocked_ua FROM `blocked_uas`');
		$rOutput = $db->get_rows(true, 'id');

		FileCache::setCache('blocked_ua', $rOutput);

		return $rOutput;
	}

	public static function getBlockedIPs($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('blocked_ips', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT `ip` FROM `blocked_ips`');
		foreach ($db->get_rows() as $rRow) {
			$rOutput[] = $rRow['ip'];
		}

		FileCache::setCache('blocked_ips', $rOutput);

		return $rOutput;
	}

	public static function getBlockedISP($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('blocked_isp', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$db->query('SELECT id,isp,blocked FROM `blocked_isps`');
		$rOutput = $db->get_rows();

		FileCache::setCache('blocked_isp', $rOutput);

		return $rOutput;
	}

	public static function getBlockedServers($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('blocked_servers', 20);
			if ($rCache !== false) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT `asn` FROM `blocked_asns` WHERE `blocked` = 1;');
		foreach ($db->get_rows() as $rRow) {
			$rOutput[] = $rRow['asn'];
		}

		FileCache::setCache('blocked_servers', $rOutput);

		return $rOutput;
	}

	public static function getBlockedIPsSimple() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `blocked_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getRTMPIPsSimple() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `rtmp_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getAllowedRTMP() {
		global $db;
		$rReturn = array();
		$db->query('SELECT `ip`, `password`, `push`, `pull` FROM `rtmp_ips`');
		foreach ($db->get_rows() as $rRow) {
			$rReturn[gethostbyname($rRow['ip'])] = array('password' => $rRow['password'], 'push' => boolval($rRow['push']), 'pull' => boolval($rRow['pull']));
		}
		return $rReturn;
	}
}
