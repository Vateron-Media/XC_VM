<?php

class CategoryService {
	public static function reorder($rData) {
		global $db;
		$rPostCategories = json_decode($rData['categories'], true);

		if (0 >= count($rPostCategories)) {
		} else {
			foreach ($rPostCategories as $rOrder => $rPostCategory) {
				$db->query('UPDATE `streams_categories` SET `cat_order` = ?, `parent_id` = 0 WHERE `id` = ?;', intval($rOrder) + 1, $rPostCategory['id']);
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function process($rData) {
		global $db;
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

	// ──────────── Из CategoryRepository ────────────

	public static function getFromDatabase($rGetCacheCallback, $rSetCacheCallback, $rType = null, $rForce = false) {
		global $db;
		if (is_string($rType)) {
			$db->query('SELECT t1.* FROM `streams_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			return (0 < $db->num_rows() ? $db->get_rows(true, 'id') : array());
		}

		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'categories', 20);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$db->query('SELECT t1.* FROM `streams_categories` t1 ORDER BY t1.cat_order ASC');
		$rCategories = (0 < $db->num_rows() ? $db->get_rows(true, 'id') : array());

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'categories', $rCategories);
		}

		return $rCategories;
	}

	public static function filterLoaded($rCategories, $rType = null) {
		$rReturn = array();
		foreach ($rCategories as $rCategory) {
			if ($rCategory['category_type'] != $rType && $rType) {
			} else {
				$rReturn[] = $rCategory;
			}
		}
		return $rReturn;
	}

	/**
	 * Возвращает ID всех категорий, помеченных как «для взрослых».
	 *
	 * @param array $rCategories Массив загруженных категорий
	 * @return int[]
	 */
	public static function getAdultIDs($rCategories) {
		$rReturn = array();
		foreach ($rCategories as $rCategory) {
			if ($rCategory['is_adult']) {
				$rReturn[] = intval($rCategory['id']);
			}
		}
		return $rReturn;
	}
}
