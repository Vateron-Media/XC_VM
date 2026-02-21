<?php

class EpgService {
	public static function process($rData, $db, $rGetEPGCallback) {
		if (isset($rData['edit'])) {
			if (!hasPermissions('adv', 'epg_edit')) {
				exit();
			}
			$rArray = overwriteData(call_user_func($rGetEPGCallback, $rData['edit']), $rData);
		} else {
			if (!hasPermissions('adv', 'add_epg')) {
				exit();
			}
			$rArray = verifyPostTable('epg', $rData);
			unset($rArray['id']);
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `epg`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function getChannelEpg($rStream, $rArchive = false, $rGetEpgCallback = null) {
		if (!$rStream || !$rStream['channel_id']) {
			return array();
		}

		if (!$rGetEpgCallback) {
			$rGetEpgCallback = array('CoreUtilities', 'getEPG');
		}

		if ($rArchive) {
			return call_user_func($rGetEpgCallback, $rStream['id'], time() - $rStream['tv_archive_duration'] * 86400, time());
		}

		return call_user_func($rGetEpgCallback, $rStream['id'], time(), time() + 1209600);
	}
}
