<?php

namespace XcVm\Domain\User;

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Util\GeoIP;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Infrastructure\Signal\SignalQueue;

/**
 * UserRepository — user repository
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UserRepository {
	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Whether a freshly looked-up ISP should be written back to the line: only
	 * when an ISP was actually detected ($rConIspName non-empty) and it differs
	 * from the stored isp_desc while the line is not already flagged in
	 * violation. Guards the persist step against a GeoIP lookup miss, which
	 * would otherwise read the undefined isp_asn key and store a null ISP.
	 *
	 * @param string|null $rConIspName Detected ISP name (null/'' on a miss).
	 * @param int         $rIspViolate Current isp_violate flag.
	 * @param string|null $rIspDesc    ISP currently stored on the line.
	 * @return bool
	 */
	public static function ispChanged($rConIspName, $rIspViolate, $rIspDesc): bool {
		return !empty($rConIspName)
			&& $rIspViolate == 0
			&& strtolower((string) $rConIspName) != strtolower((string) $rIspDesc);
	}

	/**
	 * Load the raw line row for a streaming request from cache files or the DB,
	 * resolving credentials (32-char access token, username+password, or id).
	 * `$rUserID` is resolved in place (by reference) so the caller's cached
	 * re-verification sees the same value the original inline code did.
	 *
	 * @param mixed       $db        Database handler.
	 * @param bool        $rCached   Whether to read from the file cache.
	 * @param array       $rSettings Settings (case_sensitive_line).
	 * @param int|null    $rUserID   Line id; resolved from a token/cache file when absent.
	 * @param string|null $rUsername Username or access token.
	 * @param string|null $rPassword Password.
	 * @return array|false The line row, or false when it cannot be resolved.
	 */
	private static function loadUserRow($db, $rCached, $rSettings, &$rUserID, $rUsername, $rPassword) {
		if ($rCached) {
			if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
				$rKey = $rSettings['case_sensitive_line'] ? $rUsername : strtolower($rUsername);
				$rTokenPath = LINES_TMP_PATH . 'line_t_' . $rKey;
				$rUserID = file_exists($rTokenPath) ? intval(file_get_contents($rTokenPath)) : 0;
			} elseif (!empty($rUsername) && !empty($rPassword)) {
				$rKey = $rSettings['case_sensitive_line'] ? ($rUsername . '_' . $rPassword) : (strtolower($rUsername) . '_' . strtolower($rPassword));
				$rCachePath = LINES_TMP_PATH . 'line_c_' . $rKey;
				$rUserID = file_exists($rCachePath) ? intval(file_get_contents($rCachePath)) : 0;
			} elseif (empty($rUserID)) {
				return false;
			}

			if (!$rUserID) {
				return false;
			}
			$rInfoPath = LINES_TMP_PATH . 'line_i_' . $rUserID;
			return file_exists($rInfoPath) ? igbinary_unserialize(file_get_contents($rInfoPath)) : false;
		}

		if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
			$db->query('SELECT * FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `access_token` = ? AND LENGTH(`access_token`) = 32', $rUsername);
		} elseif (!empty($rUsername) && !empty($rPassword)) {
			$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `username` = ? AND `password` = ? LIMIT 1', $rUsername, $rPassword);
		} elseif (!empty($rUserID)) {
			$db->query('SELECT `lines`.*, `mag_devices`.`token` AS `mag_token` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `id` = ?', $rUserID);
		} else {
			return false;
		}

		return 0 < $db->num_rows() ? $db->get_row() : false;
	}

	/**
	 * Re-verify cached credentials against the loaded row: the access token must
	 * match for a 32-char token lookup, or username+password for a credential
	 * lookup. Any other case (e.g. an id lookup) needs no re-check.
	 *
	 * @param array       $rUserInfo Loaded line row.
	 * @param int|null    $rUserID   Resolved line id.
	 * @param string|null $rUsername Username or access token.
	 * @param string|null $rPassword Password.
	 * @return bool True when the credentials are valid.
	 */
	private static function verifyCachedCredentials($rUserInfo, $rUserID, $rUsername, $rPassword): bool {
		if (empty($rPassword) && empty($rUserID) && strlen($rUsername) == 32) {
			return $rUsername == $rUserInfo['access_token'];
		}
		if (!empty($rUsername) && !empty($rPassword)) {
			return $rUsername == $rUserInfo['username'] && $rPassword == $rUserInfo['password'];
		}
		return true;
	}

	/**
	 * Decode the JSON line fields (allowed_ips/ua/bouquet/allowed_outputs) into
	 * arrays and normalise them. Pure.
	 *
	 * @param array $rUserInfo Raw line row.
	 * @return array The row with decoded array fields.
	 */
	private static function decodeUserFields(array $rUserInfo): array {
		$rAllowedIPS = json_decode($rUserInfo['allowed_ips'], true);
		$rAllowedUa = json_decode($rUserInfo['allowed_ua'], true);
		$rUserInfo['bouquet'] = json_decode($rUserInfo['bouquet'], true);
		$rUserInfo['allowed_ips'] = array_filter(array_map('trim', is_array($rAllowedIPS) ? $rAllowedIPS : []));
		$rUserInfo['allowed_ua'] = array_filter(array_map('trim', is_array($rAllowedUa) ? $rAllowedUa : []));
		$rUserInfo['allowed_outputs'] = array_map('intval', json_decode($rUserInfo['allowed_outputs'], true));
		return $rUserInfo;
	}

	/**
	 * The output-format keys a line may use, filtered by its allowed_outputs.
	 *
	 * @param mixed $db              Database handler.
	 * @param bool  $rCached         Read the format list from the file cache.
	 * @param array $rAllowedOutputs Access-output ids the line is allowed.
	 * @return array<int,string> Output keys.
	 */
	private static function resolveOutputFormats($db, $rCached, array $rAllowedOutputs): array {
		if ($rCached) {
			$rRows = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'output_formats'));
		} else {
			$db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');
			$rRows = $db->get_rows();
		}

		$rFormats = array();
		foreach ($rRows as $rRow) {
			if (in_array(intval($rRow['access_output_id']), $rAllowedOutputs)) {
				$rFormats[] = $rRow['output_key'];
			}
		}
		return $rFormats;
	}

	/**
	 * Aggregate the stream/series/vod/live/radio ids reachable through a line's
	 * bouquets, keyed as they are stored on the user row. Pure.
	 *
	 * @param array $rBouquet  The line's bouquet ids.
	 * @param array $rBouquets Bouquet map (id => ['streams','series','channels','movies','radios']).
	 * @return array{channel_ids:int[],series_ids:int[],vod_ids:int[],live_ids:int[],radio_ids:int[]}
	 */
	private static function aggregateBouquetIds($rBouquet, $rBouquets): array {
		$rChannelIDs = $rSeriesIDs = $rVODIDs = $rLiveIDs = $rRadioIDs = array();
		foreach ($rBouquet as $rID) {
			if (isset($rBouquets[$rID]['streams'])) {
				$rChannelIDs = array_merge($rChannelIDs, $rBouquets[$rID]['streams']);
			}
			if (isset($rBouquets[$rID]['series'])) {
				$rSeriesIDs = array_merge($rSeriesIDs, $rBouquets[$rID]['series']);
			}
			if (isset($rBouquets[$rID]['channels'])) {
				$rLiveIDs = array_merge($rLiveIDs, $rBouquets[$rID]['channels']);
			}
			if (isset($rBouquets[$rID]['movies'])) {
				$rVODIDs = array_merge($rVODIDs, $rBouquets[$rID]['movies']);
			}
			if (isset($rBouquets[$rID]['radios'])) {
				$rRadioIDs = array_merge($rRadioIDs, $rBouquets[$rID]['radios']);
			}
		}
		return array(
			'channel_ids' => array_map('intval', array_unique($rChannelIDs)),
			'series_ids' => array_map('intval', array_unique($rSeriesIDs)),
			'vod_ids' => array_map('intval', array_unique($rVODIDs)),
			'live_ids' => array_map('intval', array_unique($rLiveIDs)),
			'radio_ids' => array_map('intval', array_unique($rRadioIDs)),
		);
	}

	/**
	 * The distinct category ids reachable through a line's bouquets. Pure.
	 *
	 * @param array $rBouquet     The line's bouquet ids.
	 * @param array $rCategoryMap Bouquet id => category id list.
	 * @return array<int,mixed> Distinct category ids.
	 */
	private static function resolveCategoryIds($rBouquet, $rCategoryMap): array {
		$rAllowedCategories = array();
		foreach ($rBouquet as $rID) {
			$rAllowedCategories = array_merge($rAllowedCategories, ($rCategoryMap[$rID] ?: array()));
		}
		return array_values(array_unique($rAllowedCategories));
	}

	/**
	 * Apply the county_override_1st rule: when it is on and the line has no
	 * forced country yet, resolve one from the client IP and persist it — queued
	 * via the signal queue in cache mode, or a direct UPDATE otherwise.
	 *
	 * @param array  $rUserInfo Line row.
	 * @param array  $rSettings Settings.
	 * @param bool   $rCached   Cache mode.
	 * @param string $rIP       Client IP.
	 * @param mixed  $db        Database handler.
	 * @return array The (possibly) updated row.
	 */
	private static function applyForcedCountry(array $rUserInfo, $rSettings, $rCached, $rIP, $db): array {
		if ($rSettings['county_override_1st'] == 1 && empty($rUserInfo['forced_country']) && !empty($rIP) && $rUserInfo['max_connections'] == 1) {
			$rUserInfo['forced_country'] = GeoIP::getCountry($rIP)['registered_country']['iso_code'];
			if ($rCached) {
				SignalQueue::push('forced_country/' . $rUserInfo['id'], $rUserInfo['forced_country']);
			} else {
				$db->query('UPDATE `lines` SET `forced_country` = ? WHERE `id` = ?', $rUserInfo['forced_country'], $rUserInfo['id']);
			}
		}
		return $rUserInfo;
	}

	/**
	 * Resolve and apply ISP metadata for the client IP when show_isps is on:
	 * con_isp_name / isp_asn / isp_violate / isp_is_server, enforcing the ISP
	 * lock and persisting a changed ISP. Always initialises the three flags.
	 *
	 * @param array  $rUserInfo Line row.
	 * @param array  $rSettings Settings.
	 * @param bool   $rCached   Cache mode.
	 * @param string $rIP       Client IP.
	 * @param mixed  $db        Database handler.
	 * @return array The updated row.
	 */
	private static function applyIspInfo(array $rUserInfo, $rSettings, $rCached, $rIP, $db): array {
		$rUserInfo['con_isp_name'] = null;
		$rUserInfo['isp_violate'] = 0;
		$rUserInfo['isp_is_server'] = 0;

		if ($rSettings['show_isps'] == 1 && !empty($rIP)) {
			$rISPLock = GeoIP::getISP($rIP);
			if (is_array($rISPLock) && !empty($rISPLock['isp'])) {
				$rUserInfo['con_isp_name'] = $rISPLock['isp'];
				$rUserInfo['isp_asn'] = $rISPLock['autonomous_system_number'];
				$rUserInfo['isp_violate'] = GeoIP::isISPBlocked($rUserInfo['con_isp_name'], BlocklistService::getBlockedISP());
				if ($rSettings['block_svp'] == 1) {
					$rUserInfo['isp_is_server'] = intval(GeoIP::isASNBlocked($rUserInfo['isp_asn'], BlocklistService::getBlockedServers()));
				}
			}

			if (!empty($rUserInfo['con_isp_name']) && $rSettings['enable_isp_lock'] == 1 && $rUserInfo['is_stalker'] == 0 && $rUserInfo['is_isplock'] == 1 && !empty($rUserInfo['isp_desc']) && strtolower($rUserInfo['con_isp_name']) != strtolower($rUserInfo['isp_desc'])) {
				$rUserInfo['isp_violate'] = 1;
			}

			if (self::ispChanged($rUserInfo['con_isp_name'], $rUserInfo['isp_violate'], $rUserInfo['isp_desc'])) {
				if ($rCached) {
					SignalQueue::push('isp/' . $rUserInfo['id'], json_encode(array($rUserInfo['con_isp_name'], $rUserInfo['isp_asn'])));
				} else {
					$db->query('UPDATE `lines` SET `isp_desc` = ?, `as_number` = ? WHERE `id` = ?', $rUserInfo['con_isp_name'], $rUserInfo['isp_asn'], $rUserInfo['id']);
				}
			}
		}
		return $rUserInfo;
	}

	/**
	 * Fetch an admin/reseller user matching the given login credentials.
	 *
	 * @param string $rUsername Username.
	 * @param string $rPassword Plain-text password.
	 * @return array|null The user row, or null if credentials are invalid.
	 */
	public static function getAuthUserByCredentials($rUsername, $rPassword) {
		$db = self::db();
		$db->query('SELECT `id`, `username`, `password`, `member_group_id`, `status` FROM `users` WHERE `username` = ? LIMIT 1;', $rUsername);

		if ($db->num_rows() == 1) {
			$rRow = $db->get_row();

			if (Authenticator::checkPassword($rPassword, $rRow['password'])) {
				return $rRow;
			}
		}
		return null;
	}

	/**
	 * List resellers under a given owner.
	 *
	 * @param int  $rOwner       Owner user id.
	 * @param bool $rIncludeSelf Include the owner in the result.
	 * @return array Reseller rows.
	 */
	public static function getResellers($rOwner, $rIncludeSelf = true) {
		$db = self::db();
		if ($rIncludeSelf) {
			$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ? OR `id` = ? ORDER BY `username` ASC;', $rOwner, $rOwner);
		} else {
			$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ? ORDER BY `username` ASC;', $rOwner);
		}

		return $db->get_rows(true, 'id');
	}

	/**
	 * Get the direct sub-users (reports) of the current user.
	 *
	 * @param array $rPermissions Effective permissions.
	 * @param array $rUserInfo    Current user row.
	 * @param bool  $rIncludeSelf Include the user themselves.
	 * @return array Direct report rows.
	 */
	public static function getDirectReports($rPermissions, $rUserInfo, $rIncludeSelf = true) {
		$db = self::db();
		$rUserIDs = $rPermissions['direct_reports'];

		if ($rIncludeSelf) {
			$rUserIDs[] = $rUserInfo['id'];
		}

		$rReturn = array();

		if (0 < count($rUserIDs)) {
			$db->query('SELECT * FROM `users` WHERE `owner_id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ') ORDER BY `username` ASC;');

			if (0 < $db->num_rows()) {
				foreach ($db->get_rows() as $rRow) {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Resolve the parent of a user within the permission scope.
	 *
	 * @param array $rPermissions Effective permissions.
	 * @param array $rUserInfo    Current user row.
	 * @param int   $rID          Target user id.
	 * @return int Resolved parent user id.
	 */
	public static function getParent($rPermissions, $rUserInfo, $rID) {
		if (!isset($rPermissions['users'][$rID]['parent']) || $rPermissions['users'][$rID]['parent'] == 0 || $rPermissions['users'][$rID]['parent'] == $rUserInfo['id']) {
			return $rID;
		}

		return self::getParent($rPermissions, $rUserInfo, $rPermissions['users'][$rID]['parent']);
	}

	/**
	 * Get all descendant sub-users of a user (full report tree).
	 *
	 * @param int $rUser User id.
	 * @return array Sub-user rows keyed by id.
	 */
	public static function getSubUsers($rUser) {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT `id`, `username` FROM `users` WHERE `owner_id` = ?;', $rUser);

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['id']] = array('username' => $rRow['username'], 'parent' => $rUser);

			foreach (self::getSubUsers($rRow['id']) as $rUserID => $rUserData) {
				$rReturn[$rUserID] = $rUserData;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a line by id.
	 *
	 * @param int $rID Line id.
	 * @return array|null The line row, or null if not found.
	 */
	public static function getLineById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `lines` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
		return null;
	}

	/**
	 * Fetch a registered (panel) user by id.
	 *
	 * @param int $rID User id.
	 * @return array|null The user row, or null if not found.
	 */
	public static function getRegisteredUserById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `users` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
		return null;
	}

	/**
	 * List registered users under an owner.
	 *
	 * @param int|null $rOwner       Owner id, or null for all.
	 * @param bool     $rIncludeSelf Include the owner in the result.
	 * @return array Registered user rows.
	 */
	public static function getRegisteredUsers($rOwner = null, $rIncludeSelf = true) {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `users` ORDER BY `username` ASC;');

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				if (!$rOwner || $rRow['owner_id'] == $rOwner || $rRow['id'] == $rOwner && $rIncludeSelf) {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}

		if (count($rReturn) == 0) {
			$rReturn[-1] = array();
		}

		return $rReturn;
	}

	/**
	 * Resolve a streaming user's info (auth + entitlements).
	 *
	 * Looks the user up by id or username/password, then assembles their
	 * bouquets and, optionally, allowed channel ids and active connections.
	 *
	 * @param array       $rSettings        Panel settings.
	 * @param bool        $rCached          Use cached lookups.
	 * @param array       $rBouquets        Bouquet definitions.
	 * @param int|null    $rUserID          User id (when known).
	 * @param string|null $rUsername        Username (credential lookup).
	 * @param string|null $rPassword        Password (credential lookup).
	 * @param bool        $rGetChannelIDs   Include allowed channel ids.
	 * @param bool        $rGetConnections  Include active connections.
	 * @param string      $rIP              Client IP.
	 * @return array|null User info, or null if not found.
	 */
	public static function getStreamingUserInfo($rSettings, $rCached, $rBouquets, $rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '') {
		$db = self::db();
		$rUserInfo = null;

		if (!($rUserInfo = self::loadUserRow($db, $rCached, $rSettings, $rUserID, $rUsername, $rPassword))) {
			return false;
		}

		if ($rCached && !self::verifyCachedCredentials($rUserInfo, $rUserID, $rUsername, $rPassword)) {
			return false;
		}

		$rUserInfo = self::applyForcedCountry($rUserInfo, $rSettings, $rCached, $rIP, $db);
		$rUserInfo = self::decodeUserFields($rUserInfo);

		$rUserInfo['output_formats'] = self::resolveOutputFormats($db, $rCached, $rUserInfo['allowed_outputs']);
		$rUserInfo = self::applyIspInfo($rUserInfo, $rSettings, $rCached, $rIP, $db);

		if ($rGetChannelIDs) {
			$rUserInfo = array_merge($rUserInfo, self::aggregateBouquetIds($rUserInfo['bouquet'], $rBouquets));
		}

		$rCategoryMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'category_map'));
		$rUserInfo['category_ids'] = self::resolveCategoryIds($rUserInfo['bouquet'], $rCategoryMap);
		return $rUserInfo;
	}

	/**
	 * Resolve user info for streaming endpoints (wrapper over getStreamingUserInfo).
	 *
	 * @param int|null    $rUserID         User id (when known).
	 * @param string|null $rUsername       Username (credential lookup).
	 * @param string|null $rPassword       Password (credential lookup).
	 * @param bool        $rGetChannelIDs  Include allowed channel ids.
	 * @param bool        $rGetConnections Include active connections.
	 * @param string      $rIP             Client IP.
	 * @return array|null User info, or null if not found.
	 */
	public static function getUserInfo($rUserID = null, $rUsername = null, $rPassword = null, $rGetChannelIDs = false, $rGetConnections = false, $rIP = '') {
		global $rSettings;
		return self::getStreamingUserInfo($rSettings, $rSettings['enable_cache'], BouquetService::getAll(), $rUserID, $rUsername, $rPassword, $rGetChannelIDs, $rGetConnections, $rIP);
	}

	/**
	 * Resolve Enigma2 device user info.
	 *
	 * @param array $rDevice          Device row (MAC etc.).
	 * @param bool  $rGetChannelIDs   Include allowed channel ids.
	 * @param bool  $rGetConnections  Include active connections.
	 * @return array|null Device user info, or null if not found.
	 */
	public static function getE2Info($rDevice, $rGetChannelIDs = false, $rGetConnections = false) {
		$db = self::db();
		if (empty($rDevice['device_id'])) {
			$db->query('SELECT * FROM `enigma2_devices` WHERE `mac` = ?', $rDevice['mac']);
		} else {
			$db->query('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?', $rDevice['device_id']);
		}
		if (0 >= $db->num_rows()) {
			return false;
		}
		$rReturn = array(
			'enigma2' => $db->get_row(),
			'user_info' => array(),
			'pair_line_info' => array(),
		);

		if ($rUserInfo = self::getUserInfo($rReturn['enigma2']['user_id'], null, null, $rGetChannelIDs, $rGetConnections)) {
			$rReturn['user_info'] = $rUserInfo;

			if (!is_null($rReturn['user_info']['pair_id'])
				&& ($rUserInfo = self::getUserInfo($rReturn['user_info']['pair_id'], null, null, $rGetChannelIDs, $rGetConnections))) {
				$rReturn['pair_line_info'] = $rUserInfo;
			}
		}

		return $rReturn;
	}
}
