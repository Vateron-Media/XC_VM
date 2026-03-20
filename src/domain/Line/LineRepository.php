<?php

class LineRepository {
	public static function deleteMany($rIDs) {
		global $db;
		$rIDs = confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		LineService::deleteLinesSignal($rIDs);
		$db->query('DELETE FROM `lines` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_logs` WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$rPairIDs = array();
		$db->query('SELECT `id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			if ($rRow['id'] > 0 && !in_array($rRow['id'], $rPairIDs)) {
				$rPairIDs[] = $rRow['id'];
			}
		}

		if (count($rPairIDs) > 0) {
			$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = (' . implode(',', $rPairIDs) . ');');
			LineService::updateLinesSignal($rPairIDs);
		}

		return true;
	}

	public static function getOutputFormats() {
		global $db;
		$db->query('SELECT * FROM `output_formats` ORDER BY `access_output_id` ASC;');
		return (0 < $db->num_rows() ? $db->get_rows() : array());
	}
}
