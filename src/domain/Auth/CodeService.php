<?php

class CodeService {
	public static function process($db, $rData, $rGetCodeCallback, $rUpdateCodesCallback) {
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
}
