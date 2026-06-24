<?php

namespace XcVm\Core\Auth;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\AdminHelpers;

use XcVm\Core\Http\Request;
use XcVm\Core\Database\QueryHelper;
/**
 * Консолидированный сервис аутентификации.
 * Объединяет: \CodeService, HMACService, HMACValidator.
 *
 * @package XC_VM_Domain_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AuthService {
	// ──────────────────────────────────────────────
	// Из \CodeService
	// ──────────────────────────────────────────────

	/**
	 * Create or update an access code from admin form data.
	 *
	 * Validates code length, reserved names and uniqueness, normalizes the
	 * group/whitelist fields, then upserts the row.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => payload].
	 */
	public static function processCode($rData) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = \XcVm\Core\Util\AdminHelpers::overwriteData(AuthRepository::getCodeById($rData['edit']), $rData);
			$rOrigCode = $rArray['code'];
		} else {
			$rArray = QueryHelper::verifyPostTable('access_codes', $rData);
			$rOrigCode = null;
			unset($rArray['id']);
		}

		if (isset($rData['enabled'])) {
			$rArray['enabled'] = 1;
		} else {
			$rArray['enabled'] = 0;
		}

		if (isset($rData['groups'])) {
			$rArray['groups'] = array();
			foreach ($rData['groups'] as $rGroupID) {
				$rArray['groups'][] = intval($rGroupID);
			}
		} elseif (!is_array($rArray['groups'] ?? null)) {
			$rArray['groups'] = is_string($rArray['groups'] ?? null) ? (json_decode($rArray['groups'], true) ?: []) : [];
		}

		if (in_array($rData['type'], array(0, 1, 3, 4))) {
			$rArray['groups'] = '[' . implode(',', array_map('intval', $rArray['groups'])) . ']';
		} else {
			$rArray['groups'] = '[]';
		}

		if (!isset($rData['whitelist'])) {
			$rArray['whitelist'] = '[]';
		}

		if ($rData['type'] != 2 && strlen($rData['code']) < 8) {
			return array('status' => STATUS_CODE_LENGTH, 'data' => $rData);
		}

		if ($rData['type'] == 2 && empty($rData['code'])) {
			return array('status' => STATUS_INVALID_CODE, 'data' => $rData);
		}

		if (in_array($rData['code'], array('admin', 'stream', 'images', 'player_api', 'player', 'playlist', 'epg', 'live', 'movie', 'series', 'status', 'nginx_status', 'get', 'panel_api', 'xmltv', 'probe', 'thumb', 'timeshift', 'auth', 'vauth', 'tsauth', 'hls', 'play', 'key', 'api', 'c'))) {
			return array('status' => STATUS_RESERVED_CODE, 'data' => $rData);
		}

		if (isset($rData['edit'])) {
			$db->query('SELECT `id` FROM `access_codes` WHERE `code` = ? AND `id` <> ?;', $rData['code'], $rData['edit']);
		} else {
			$db->query('SELECT `id` FROM `access_codes` WHERE `code` = ?;', $rData['code']);
		}

		if (0 < $db->num_rows()) {
			return array('status' => STATUS_EXISTS_CODE, 'data' => $rData);
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `access_codes`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			AuthRepository::updateCodes();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID, 'orig_code' => $rOrigCode, 'new_code' => $rData['code']));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	// ──────────────────────────────────────────────
	// Из HMACService
	// ──────────────────────────────────────────────

	/**
	 * Create or update an HMAC key from admin form data.
	 *
	 * Validates the 32-char key and description, enforces uniqueness, and stores
	 * the key encrypted with the live-streaming password.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => payload or insert_id].
	 */
	public static function processHMAC($rData) {
		global $db, $rSettings;
		if (isset($rData['edit'])) {
			$rArray = \XcVm\Core\Util\AdminHelpers::overwriteData(AuthRepository::getHMACById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('hmac_keys', $rData);
			unset($rArray['id']);
		}

		if (isset($rData['enabled'])) {
			$rArray['enabled'] = 1;
		} else {
			$rArray['enabled'] = 0;
		}

		if ($rData['keygen'] != 'HMAC KEY HIDDEN' && strlen($rData['keygen']) != 32) {
			return array('status' => STATUS_NO_KEY, 'data' => $rData);
		}

		if (strlen($rData['notes']) == 0) {
			return array('status' => STATUS_NO_DESCRIPTION, 'data' => $rData);
		}

		if (isset($rData['edit'])) {
			if ($rData['keygen'] != 'HMAC KEY HIDDEN') {
				$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ? AND `id` <> ?;', \XcVm\Core\Util\Encryption::encrypt($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA), $rData['edit']);
				if (0 < $db->num_rows()) {
					return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
				}
			}
		} else {
			$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ?;', \XcVm\Core\Util\Encryption::encrypt($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));
			if (0 < $db->num_rows()) {
				return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
			}
		}

		if ($rData['keygen'] != 'HMAC KEY HIDDEN') {
			$rArray['key'] = \XcVm\Core\Util\Encryption::encrypt($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `hmac_keys`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	// ──────────────────────────────────────────────
	// Из HMACValidator
	// ──────────────────────────────────────────────

	/**
	 * Validate a streaming HMAC token against all enabled keys.
	 *
	 * Recomputes the SHA-256 HMAC over the stream parameters for each enabled key
	 * (from cache or DB) and returns the id of the first matching key.
	 *
	 * @param string     $rHMAC           Token supplied by the client.
	 * @param int|string $rExpiry         Token expiry component.
	 * @param int|string $rStreamID       Stream id component.
	 * @param string     $rExtension      Stream extension component.
	 * @param string     $rIP             Request IP (must match $rMACIP when both set).
	 * @param string     $rMACIP          Bound MAC/IP component.
	 * @param string     $rIdentifier     Optional identifier component.
	 * @param int        $rMaxConnections Max-connections component.
	 * @return int|null Matching HMAC key id, or null if no key matches.
	 */
	public static function validateHMAC($rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0) {
		global $db, $rSettings;
		$rCached = $rSettings['enable_cache'];
		if (0 < strlen($rIP) && 0 < strlen($rMACIP) && $rIP != $rMACIP) {
			return null;
		}

		$rKeyID = null;
		if ($rCached) {
			$rKeys = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'hmac_keys'));
		} else {
			$rKeys = array();
			$db->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `enabled` = 1;');
			foreach ($db->get_rows() as $rKey) {
				$rKeys[] = $rKey;
			}
		}

		foreach ($rKeys as $rKey) {
			$rSecret = \XcVm\Core\Util\Encryption::decrypt($rKey['key'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rResult = hash_hmac('sha256', (string) $rStreamID . '##' . $rExtension . '##' . $rExpiry . '##' . $rMACIP . '##' . $rIdentifier . '##' . $rMaxConnections, $rSecret);

			if (md5($rResult) == md5($rHMAC)) {
				$rKeyID = $rKey['id'];
				break;
			}
		}

		return $rKeyID;
	}
}
