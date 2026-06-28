<?php

namespace XcVm\Domain\Security;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;

/**
 * BlocklistService — blocklist service
 *
 * @package XC_VM_Domain_Security
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BlocklistService {
	use \XcVm\Infrastructure\Database\DatabaseAware;
	/**
	 * Add an IP (or CIDR) to the blocklist.
	 *
	 * @param array $rData Submitted IP/notes data.
	 * @return array Result status payload.
	 */
	public static function blockIP($rData) {
		$db = self::db();
		if (!AdminHelpers::validateCIDR($rData['ip'])) {
			return array('status' => STATUS_INVALID_IP, 'data' => $rData);
		}

		$rArray = array('ip' => $rData['ip'], 'notes' => $rData['notes'], 'date' => time());
		touch(FLOOD_TMP_PATH . 'block_' . $rData['ip']);
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Create or update a blocked ISP entry.
	 *
	 * @param array $rData Submitted ISP data (includes `edit` id when updating).
	 * @return array Result status payload.
	 */
	public static function processISP($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'block_isps')) {
				exit();
			}
			$rArray = AdminHelpers::overwriteData(BlocklistService::getISPById($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'block_isps')) {
				exit();
			}
			$rArray = QueryHelper::verifyPostTable('blocked_isps', $rData);
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

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_isps`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Create or update an allowed RTMP IP entry.
	 *
	 * @param array $rData Submitted RTMP IP data (includes `edit` id when updating).
	 * @return array Result status payload.
	 */
	public static function processRTMPIP($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(BlocklistService::getRTMPIPById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('rtmp_ips', $rData);
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

		if (QueryHelper::checkExists('rtmp_ips', 'ip', $rData['ip'], 'id', $rArray['id'])) {
			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		}

		if (strlen($rData['password']) == 0) {
			$rArray['password'] = AdminHelpers::generateString(16);
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `rtmp_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Create or update a blocked user-agent entry.
	 *
	 * @param array $rData Submitted user-agent data (includes `edit` id when updating).
	 * @return array Result status payload.
	 */
	public static function processUA($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(BlocklistService::getUserAgentById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('blocked_uas', $rData);
			unset($rArray['id']);
		}

		if (isset($rData['exact_match'])) {
			$rArray['exact_match'] = true;
		} else {
			$rArray['exact_match'] = false;
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `blocked_uas`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Check whether a user agent matches the blocked-UA list.
	 *
	 * @param array  $rBlockedUA Blocked user-agent patterns.
	 * @param string $rUserAgent User agent to test.
	 * @param bool   $rReturn    Return the matched entry instead of a boolean.
	 * @return bool|mixed True/match if blocked, false otherwise.
	 */
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

	/**
	 * Check a user agent against the blocklist and block the request if matched.
	 *
	 * @param array  $rBlockedUA Blocked user-agent patterns.
	 * @param string $rUserAgent User agent to test.
	 * @param bool   $rReturn    Return the matched entry instead of acting.
	 * @return bool|mixed True/match if blocked, false otherwise.
	 */
	public static function checkAndBlockUA($rBlockedUA, $rUserAgent, $rReturn = false) {
		$db = self::db();
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

	/**
	 * Check whether a connection's ISP is blocked.
	 *
	 * @param array  $rBlockedISP Blocked ISP list.
	 * @param string $rConISP     Connection ISP to test.
	 * @return bool True if blocked.
	 */
	public static function checkISP($rBlockedISP, $rConISP) {
		foreach ($rBlockedISP as $rISP) {
			if (strtolower($rConISP) == strtolower($rISP['isp'])) {
				return intval($rISP['blocked']);
			}
		}
		return 0;
	}

	/**
	 * Check whether a server/ASN is blocked.
	 *
	 * @param array      $rBlockedServers Blocked server/ASN list.
	 * @param int|string $rASN            ASN to test.
	 * @return bool True if blocked.
	 */
	public static function checkServer($rBlockedServers, $rASN) {
		return in_array($rASN, $rBlockedServers);
	}

	// ──────────── Из BlocklistRepository ────────────

	/**
	 * Determine whether an IP is a known proxy.
	 *
	 * @param string $rIP IP address.
	 * @return bool True if the IP is a known proxy.
	 */
	public static function isProxy($rIP) {
		$rProxies = self::getProxyIPs();
		if (isset($rProxies[$rIP])) {
			return $rProxies[$rIP];
		}
		return null;
	}

	/**
	 * Get the known proxy IP list (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Proxy IPs.
	 */
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

	/**
	 * Get the blocked user-agent list (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Blocked user agents.
	 */
	public static function getBlockedUA($rForce = false) {
		$db = self::db();
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

	/**
	 * Get the blocked IP list (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Blocked IPs.
	 */
	public static function getBlockedIPs($rForce = false) {
		$db = self::db();
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

	/**
	 * Get the blocked ISP list (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Blocked ISPs.
	 */
	public static function getBlockedISP($rForce = false) {
		$db = self::db();
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

	/**
	 * Get the blocked servers/ASN list (cached).
	 *
	 * @param bool $rForce Bypass the cache.
	 * @return array Blocked servers/ASNs.
	 */
	public static function getBlockedServers($rForce = false) {
		$db = self::db();
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

	/**
	 * Get a lightweight blocked-IP list.
	 *
	 * @return array Blocked IPs (reduced).
	 */
	public static function getBlockedIPsSimple() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `blocked_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Get a lightweight RTMP-IP list.
	 *
	 * @return array RTMP IPs (reduced).
	 */
	public static function getRTMPIPsSimple() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `rtmp_ips` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Get the list of allowed RTMP IPs.
	 *
	 * @return array Allowed RTMP IPs.
	 */
	public static function getAllowedRTMP() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT `ip`, `password`, `push`, `pull` FROM `rtmp_ips`');
		foreach ($db->get_rows() as $rRow) {
			$rReturn[gethostbyname($rRow['ip'])] = array('password' => $rRow['password'], 'push' => boolval($rRow['push']), 'pull' => boolval($rRow['pull']));
		}
		return $rReturn;
	}

	/**
	 * Delete a blocked IP entry.
	 *
	 * @param int $rID Entry id.
	 * @return bool True on success.
	 */
	public static function deleteBlockedIP($rID) {
		$db = self::db();
		$db->query('SELECT `id`, `ip` FROM `blocked_ips` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$rRow = $db->get_row();
		$db->query('DELETE FROM `blocked_ips` WHERE `id` = ?;', $rID);

		if (!file_exists(FLOOD_TMP_PATH . 'block_' . $rRow['ip'])) {
		} else {
			unlink(FLOOD_TMP_PATH . 'block_' . $rRow['ip']);
		}

		return true;
	}

	/**
	 * Delete a blocked ISP entry.
	 *
	 * @param int $rID Entry id.
	 * @return bool True on success.
	 */
	public static function deleteBlockedISP($rID) {
		$db = self::db();
		$db->query('SELECT `id` FROM `blocked_isps` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `blocked_isps` WHERE `id` = ?;', $rID);

		return true;
	}

	/**
	 * Delete a blocked user-agent entry.
	 *
	 * @param int $rID Entry id.
	 * @return bool True on success.
	 */
	public static function deleteBlockedUA($rID) {
		$db = self::db();
		$db->query('SELECT `id` FROM `blocked_uas` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `blocked_uas` WHERE `id` = ?;', $rID);

		return true;
	}

	/**
	 * Remove all blocked IP entries.
	 *
	 * @return bool True on success.
	 */
	public static function flushIPs() {
		$db = self::db();
		global $rServers;
		global $rProxyServers;
		$db->query('TRUNCATE `blocked_ips`;');
		shell_exec('rm ' . FLOOD_TMP_PATH . 'block_*');

		foreach ($rServers as $rServer) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
		}

		foreach ($rProxyServers as $rServer) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
		}

		return true;
	}

	/**
	 * Fetch all blocked user-agent entries.
	 *
	 * @return array User-agent rows.
	 */
	public static function getAllUserAgents() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `blocked_uas` ORDER BY `id` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch all blocked ISP entries.
	 *
	 * @return array ISP rows.
	 */
	public static function getAllISPs() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `blocked_isps` ORDER BY `id` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a blocked user-agent entry by id.
	 *
	 * @param int $rID Entry id.
	 * @return array|null The row, or null if not found.
	 */
	public static function getUserAgentById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `blocked_uas` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
		return null;
	}

	/**
	 * Fetch a blocked ISP entry by id.
	 *
	 * @param int $rID Entry id.
	 * @return array|null The row, or null if not found.
	 */
	public static function getISPById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `blocked_isps` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
		return null;
	}

	/**
	 * Delete an RTMP IP entry.
	 *
	 * @param int $rID Entry id.
	 * @return bool True on success.
	 */
	public static function deleteRTMPIP($rID) {
		$db = self::db();
		$db->query('SELECT `id` FROM `rtmp_ips` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `rtmp_ips` WHERE `id` = ?;', $rID);

		return true;
	}

	/**
	 * Fetch an RTMP IP entry by id.
	 *
	 * @param int $rID Entry id.
	 * @return array|null The row, or null if not found.
	 */
	public static function getRTMPIPById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `rtmp_ips` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			return $db->get_row();
		}
		return null;
	}
}
