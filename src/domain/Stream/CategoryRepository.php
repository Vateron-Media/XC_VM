<?php

class CategoryRepository {
	public static function getFromDatabase($db, $rGetCacheCallback, $rSetCacheCallback, $rType = null, $rForce = false) {
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
}
