<?php

class RecordingService {
	public static function schedule($rData) {
		global $db;
		if (empty($rData['title'])) {
			return array('status' => STATUS_NO_TITLE);
		}
		if (empty($rData['source_id'])) {
			return array('status' => STATUS_NO_SOURCE);
		}

		$rArray = verifyPostTable('recordings', $rData);
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $rData['bouquets'])) . ']';
		$rArray['category_id'] = '[' . implode(',', array_map('intval', $rData['category_id'])) . ']';
		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `recordings`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}
}
