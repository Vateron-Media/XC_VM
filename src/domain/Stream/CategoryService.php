<?php

class CategoryService {
	public static function reorder($rData, $db) {
		$rPostCategories = json_decode($rData['categories'], true);

		if (0 >= count($rPostCategories)) {
		} else {
			foreach ($rPostCategories as $rOrder => $rPostCategory) {
				$db->query('UPDATE `streams_categories` SET `cat_order` = ?, `parent_id` = 0 WHERE `id` = ?;', intval($rOrder) + 1, $rPostCategory['id']);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function process($rData, $db) {
		if (isset($rData['edit'])) {
			$rArray = overwriteData(getCategory($rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('streams_categories', $rData);
			$rArray['cat_order'] = 99;
			unset($rArray['id']);
		}

		if (isset($rData['is_adult'])) {
			$rArray['is_adult'] = 1;
		} else {
			$rArray['is_adult'] = 0;
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}
}
