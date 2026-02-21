<?php

class HMACService {
	public static function process($db, $rData, $rSettings, $rGetHMACTokenCallback) {
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
}
