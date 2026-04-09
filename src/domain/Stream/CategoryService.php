<?php

/**
 * CategoryService — category service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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
			$rArray = AdminHelpers::overwriteData(CategoryService::getById($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('streams_categories', $rData);
			$rArray['cat_order'] = 99;
			unset($rArray['id']);
		}

		if (isset($rData['is_adult'])) {
			$rArray['is_adult'] = 1;
		} else {
			$rArray['is_adult'] = 0;
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Возвращает категории с int-ключами (замена legacy getCategories()).
	 * Всегда читает из БД ($rForce = true).
	 *
	 * @param string $rType 'live'|'movie'|'series'|'radio'|null
	 * @return array<int, array>
	 */
	public static function getAllByType($rType = 'live') {
		$rCategories = self::getFromDatabase(($rType ?: null), true);
		$rReturn = array();
		foreach ($rCategories as $rID => $rRow) {
			$rReturn[intval($rID)] = $rRow;
		}
		return $rReturn;
	}

	// ──────────── Из CategoryRepository ────────────

	public static function getFromDatabase($rType = null, $rForce = false) {
		global $db;
		if (is_string($rType)) {
			$db->query('SELECT t1.* FROM `streams_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			return (0 < $db->num_rows() ? $db->get_rows(true, 'id') : array());
		}

		if (!$rForce) {
			$rCache = FileCache::getCache('categories', 20);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$db->query('SELECT t1.* FROM `streams_categories` t1 ORDER BY t1.cat_order ASC');
		$rCategories = (0 < $db->num_rows() ? $db->get_rows(true, 'id') : array());

		FileCache::setCache('categories', $rCategories);

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

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `streams_categories` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return false;
		}

		return $db->get_row();
	}

	public static function deleteById($rID) {
		global $db;
		$rCategory = self::getById($rID);

		if (!$rCategory) {
			return false;
		}

		$db->query("SELECT `id`, `category_id` FROM `streams` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['category_id'] = json_decode($rRow['category_id'], true);

			if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
			} else {
				unset($rRow['category_id'][$rKey]);
			}

			$db->query("UPDATE `streams` SET `category_id` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['category_id'])) . ']', $rRow['id']);
		}
		$db->query("SELECT `id`, `category_id` FROM `streams_series` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['category_id'] = json_decode($rRow['category_id'], true);

			if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
			} else {
				unset($rRow['category_id'][$rKey]);
			}

			$db->query("UPDATE `streams_series` SET `category_id` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['category_id'])) . ']', $rRow['id']);
		}
		$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
		$db->query('UPDATE `watch_folders` SET `category_id` = null WHERE `category_id` = ?;', $rID);
		$db->query('UPDATE `watch_folders` SET `fb_category_id` = null WHERE `fb_category_id` = ?;', $rID);

		return true;
	}
}
