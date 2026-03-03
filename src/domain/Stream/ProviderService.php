<?php

class ProviderService {
	public static function process($rData) {
		global $db;
		if (InputValidator::validate('processProvider', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'streams')) {
					$rArray = overwriteData(getStreamProvider($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'streams')) {
					$rArray = verifyPostTable('providers', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			foreach (array('enabled', 'ssl', 'hls', 'legacy') as $rKey) {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}

			if (isset($rData['edit'])) {
				$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? AND `id` <> ? LIMIT 1;', $rArray['ip'], $rArray['username'], $rData['edit']);
			} else {
				$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? LIMIT 1;', $rArray['ip'], $rArray['username']);
			}

			if (0 >= $db->num_rows()) {
				$rPrepare = prepareArray($rArray);
				$rQuery = 'REPLACE INTO `providers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}
}
