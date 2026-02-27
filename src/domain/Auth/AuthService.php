<?php

/**
 * Консолидированный сервис аутентификации (§2.4).
 * Объединяет: CodeService, HMACService, HMACValidator.
 */
class AuthService {
	// ──────────────────────────────────────────────
	// Из CodeService
	// ──────────────────────────────────────────────

	public static function processCode($rData, $rGetCodeCallback, $rUpdateCodesCallback) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($rGetCodeCallback, $rData['edit']), $rData);
			$rOrigCode = $rArray['code'];
		} else {
			$rArray = verifyPostTable('access_codes', $rData);
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

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `access_codes`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			call_user_func($rUpdateCodesCallback);
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID, 'orig_code' => $rOrigCode, 'new_code' => $rData['code']));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	// ──────────────────────────────────────────────
	// Из HMACService
	// ──────────────────────────────────────────────

	public static function processHMAC($rData, $rSettings, $rGetHMACTokenCallback) {
		global $db;
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($rGetHMACTokenCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('hmac_keys', $rData);
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
				$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ? AND `id` <> ?;', CoreUtilities::encryptData($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA), $rData['edit']);
				if (0 < $db->num_rows()) {
					return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
				}
			}
		} else {
			$db->query('SELECT `id` FROM `hmac_keys` WHERE `key` = ?;', CoreUtilities::encryptData($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));
			if (0 < $db->num_rows()) {
				return array('status' => STATUS_EXISTS_HMAC, 'data' => $rData);
			}
		}

		if ($rData['keygen'] != 'HMAC KEY HIDDEN') {
			$rArray['key'] = CoreUtilities::encryptData($rData['keygen'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
		}

		$rPrepare = prepareArray($rArray);
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

	public static function validateHMAC($rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0, $rDecryptCallback = null) {
		global $db;
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
			$rSecret = call_user_func($rDecryptCallback, $rKey['key'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rResult = hash_hmac('sha256', (string) $rStreamID . '##' . $rExtension . '##' . $rExpiry . '##' . $rMACIP . '##' . $rIdentifier . '##' . $rMaxConnections, $rSecret);

			if (md5($rResult) == md5($rHMAC)) {
				$rKeyID = $rKey['id'];
				break;
			}
		}

		return $rKeyID;
	}
}
