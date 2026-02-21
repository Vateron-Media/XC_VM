<?php

class EpgRepository {
	public static function findByName($db, $rEPGName) {
		$db->query('SELECT `id`, `data` FROM `epg`;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				foreach (json_decode($rRow['data'], true) as $rChannelID => $rChannelData) {
					if ($rChannelID == $rEPGName) {
						if (count($rChannelData['langs']) > 0) {
							$rEPGLang = $rChannelData['langs'][0];
						} else {
							$rEPGLang = '';
						}

						return array('channel_id' => $rChannelID, 'epg_lang' => $rEPGLang, 'epg_id' => intval($rRow['id']));
					}
				}
			}
		}
	}

	public static function getById($db, $rID) {
		$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
	}

	public static function search($rArray, $rKey, $rValue) {
		$rResults = array();
		self::searchRecursive($rArray, $rKey, $rValue, $rResults);
		return $rResults;
	}

	private static function searchRecursive($rArray, $rKey, $rValue, &$rResults) {
		if (is_array($rArray)) {
			if (isset($rArray[$rKey]) && $rArray[$rKey] == $rValue) {
				$rResults[] = $rArray;
			}
			foreach ($rArray as $subarray) {
				self::searchRecursive($subarray, $rKey, $rValue, $rResults);
			}
		}
	}

	public static function getStreamEpg($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false) {
		$rReturn = array();
		$rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : array());

		foreach ($rData as $rItem) {
			if (!$rStartDate || ($rStartDate < $rItem['end'] && $rItem['start'] < $rFinishDate)) {
				if ($rByID) {
					$rReturn[$rItem['id']] = $rItem;
				} else {
					$rReturn[] = $rItem;
				}
			}
		}

		return $rReturn;
	}

	public static function getStreamsEpg($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
		$rReturn = array();
		foreach ($rStreamIDs as $rStreamID) {
			$rReturn[$rStreamID] = self::getStreamEpg($rStreamID, $rStartDate, $rFinishDate);
		}
		return $rReturn;
	}

	public static function getProgramme($rStreamID, $rProgrammeID) {
		$rData = self::getStreamEpg($rStreamID, null, null, true);
		if (isset($rData[$rProgrammeID])) {
			return $rData[$rProgrammeID];
		}
	}
}
