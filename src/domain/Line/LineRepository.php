<?php

class LineRepository {
	public static function deleteMany($db, $rIDs) {
		$rIDs = confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		CoreUtilities::deleteLines($rIDs);
		$db->query('DELETE FROM `lines` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_logs` WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$rPairIDs = array();
		$db->query('SELECT `id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			if (0 >= $rRow['id'] || in_array($rRow['id'], $rPairIDs)) {
			} else {
				$rPairIDs[] = $rRow['id'];
			}
		}

		if (0 >= count($rPairIDs)) {
		} else {
			$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = (' . implode(',', $rPairIDs) . ');');
			CoreUtilities::updateLines($rPairIDs);
		}

		return true;
	}
}
