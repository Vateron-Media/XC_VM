<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Infrastructure\Database\DatabaseAware;

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
	use DatabaseAware;

	/**
	 * Persist the display order of categories from posted data.
	 *
	 * @param array $rData Form data with a JSON `categories` list (id + order).
	 * @return array ['status' => STATUS_SUCCESS].
	 */
	public static function reorder($rData) {
		$db = self::db();
		$rawCategories = $rData['categories'] ?? [];
		if (is_string($rawCategories)) {
			$rPostCategories = json_decode($rawCategories, true);
		} elseif (is_array($rawCategories)) {
			$rPostCategories = $rawCategories;
		} else {
			$rPostCategories = [];
		}

		if (is_array($rPostCategories) && count($rPostCategories) > 0) {
			foreach ($rPostCategories as $rOrder => $rPostCategory) {
				$catId = intval($rPostCategory['id'] ?? 0);
				if ($catId > 0) {
					$db->query('UPDATE `streams_categories` SET `cat_order` = ?, `parent_id` = 0 WHERE `id` = ?;', intval($rOrder) + 1, $catId);
				}
			}
			FileCache::delCache('categories');
			// category_map (bouquet => category ids) is left alone: reordering does not
			// change it, and only the heavy cache pass rebuilds it — up to 5 minutes
			// later — while every line authentication reads it.
		}

		if (!defined('STATUS_SUCCESS') && class_exists(\XC_Bootstrap::class)) {
			\XC_Bootstrap::defineStatusConstants();
		}

		return ['status' => defined('STATUS_SUCCESS') ? STATUS_SUCCESS : 1];
	}

	/**
	 * Create or update a stream category from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process($rData) {
		if (!defined('STATUS_SUCCESS') && class_exists(\XC_Bootstrap::class)) {
			\XC_Bootstrap::defineStatusConstants();
		}

		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(self::getById($rData['edit']), $rData);
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
			$catId = isset($rData['edit']) ? intval($rData['edit']) : intval($rInsertID);
			FileCache::delCache('categories');
			// Not category_map: which categories a bouquet reaches depends on its
			// streams, not on a category's name, type or order (see reorder()).

			// Sync any templates and subscribers tied to this category
			if ($catId > 0 && class_exists(CategoryTemplateService::class)) {
				CategoryTemplateService::syncTemplatesForCategory($catId);
			}

			return ['status' => defined('STATUS_SUCCESS') ? STATUS_SUCCESS : 1, 'data' => ['insert_id' => $rInsertID]];
		}

		return ['status' => defined('STATUS_FAILURE') ? STATUS_FAILURE : 0, 'data' => $rData];
	}

	/**
	 * Возвращает категории с int-ключами (замена legacy getCategories()).
	 * Всегда читает из БД ($rForce = true).
	 *
	 * @param string|null $rType 'live'|'movie'|'series'|'radio', or null for every type
	 *                          (the admin global search asks for all of them).
	 * @return array<int, array>
	 */
	public static function getAllByType(?string $rType = 'live') {
		$rCategories = self::getFromDatabase(($rType ?: null), true);
		$rReturn = [];
		foreach ($rCategories as $rID => $rRow) {
			$rReturn[intval($rID)] = $rRow;
		}
		return $rReturn;
	}

	// ──────────── Из CategoryRepository ────────────

	/**
	 * Load categories from the database, by type or all (cached).
	 *
	 * @param string|null $rType  Category type ('live'|'movie'|'series'|'radio') or null for all.
	 * @param bool        $rForce Bypass the file cache when loading all.
	 * @return array Categories keyed by id.
	 */
	public static function getFromDatabase(?string $rType = null, bool $rForce = false) {
		// A node whose replica owns the categories (CONFIG on, and an apply
		// built them) takes its cache however old, even when forced; a process
		// booted from the replica never reads MAIN's database either.
		$rReplica = ReplicaApply::catalogCache(ReplicaSections::CATEGORIES);
		if ($rReplica !== null) {
			return is_string($rType) ? array_filter($rReplica, static fn(mixed $rRow): bool => is_array($rRow) && ($rRow['category_type'] ?? null) === $rType) : $rReplica;
		}
		$db = self::db();
		if (is_string($rType)) {
			$db->query('SELECT t1.* FROM `streams_categories` t1 WHERE t1.category_type = ? GROUP BY t1.id ORDER BY t1.cat_order ASC', $rType);
			return (0 < $db->num_rows() ? $db->get_rows(true, 'id') : []);
		}

		if (!$rForce) {
			$rCache = FileCache::getCache('categories', 20);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$db->query('SELECT t1.* FROM `streams_categories` t1 ORDER BY t1.cat_order ASC');
		$rCategories = (0 < $db->num_rows() ? $db->get_rows(true, 'id') : []);

		FileCache::setCache('categories', $rCategories);

		return $rCategories;
	}

	/**
	 * Filter an already-loaded category list by type.
	 *
	 * @param array       $rCategories Loaded categories.
	 * @param string|null $rType       Type to keep, or null for all.
	 * @return array Filtered categories.
	 */
	public static function filterLoaded(array $rCategories, ?string $rType = null) {
		$rReturn = [];
		foreach ($rCategories as $rCategory) {
			if ($rCategory['category_type'] == $rType || !$rType) {
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
	public static function getAdultIDs(array $rCategories) {
		$rReturn = [];
		foreach ($rCategories as $rCategory) {
			if ($rCategory['is_adult']) {
				$rReturn[] = intval($rCategory['id']);
			}
		}
		return $rReturn;
	}

	/**
	 * Fetch a single category by id.
	 *
	 * @param int $rID Category id.
	 * @return array|false The category row, or false if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `streams_categories` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return false;
		}

		return $db->get_row();
	}

	/**
	 * Delete a category and detach it from streams, series and watch folders.
	 *
	 * @param int $rID Category id.
	 * @return bool True on deletion, false if the category does not exist.
	 */
	public static function deleteById(int $rID) {
		$db = self::db();
		$rCategory = self::getById($rID);

		if (!$rCategory) {
			return false;
		}

		$db->query("SELECT `id`, `category_id` FROM `streams` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);
		$rStreamIDs = [];

		foreach ($db->get_rows() as $rRow) {
			$rRow['category_id'] = json_decode($rRow['category_id'], true);

			if (($rKey = array_search($rID, $rRow['category_id'])) !== false) {
				unset($rRow['category_id'][$rKey]);
			}

			$db->query("UPDATE `streams` SET `category_id` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['category_id'])) . ']', $rRow['id']);
			$rStreamIDs[] = intval($rRow['id']);
		}
		EventDispatcher::dispatch(new StreamsChangedEvent($rStreamIDs));
		$db->query("SELECT `id`, `category_id` FROM `streams_series` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['category_id'] = json_decode($rRow['category_id'], true);

			if (($rKey = array_search($rID, $rRow['category_id'])) !== false) {
				unset($rRow['category_id'][$rKey]);
			}

			$db->query("UPDATE `streams_series` SET `category_id` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['category_id'])) . ']', $rRow['id']);
		}
		$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
		$db->query('UPDATE `watch_categories` SET `category_id` = 0 WHERE `category_id` = ?;', $rID);
		$db->query('UPDATE `watch_folders` SET `category_id` = null WHERE `category_id` = ?;', $rID);
		$db->query('UPDATE `watch_folders` SET `fb_category_id` = null WHERE `fb_category_id` = ?;', $rID);

		return true;
	}

	/**
	 * The TMDb genre → category/bouquet mapping the VOD importer applies
	 * (`watch_categories`; the name predates core owning it), keyed by genre_id.
	 *
	 * @param int|null $rType 1 = movie, 2 = series (3/4 are Plex's); null for all.
	 * @return array
	 */
	public static function getGenreMap(?int $rType = null) {
		$db = self::db();
		if ($rType) {
			$db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
		} else {
			$db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
		}
		$rReturn = [];
		foreach (($db->get_rows() ?: []) as $rRow) {
			$rReturn[$rRow['genre_id']] = $rRow;
		}
		return $rReturn;
	}

	/**
	 * Save the movie and series genre mapping from the settings form:
	 * `genre_<id>` / `genretv_<id>` hold a category id (0 = none),
	 * `bouquet_<id>[]` / `bouquettv_<id>[]` its bouquets.
	 *
	 * @param array $rData
	 * @return void
	 */
	public static function saveGenreMap(array $rData) {
		$db = self::db();
		foreach ([1 => ['genre', 'bouquet'], 2 => ['genretv', 'bouquettv']] as $rType => [$rGenreKey, $rBouquetKey]) {
			foreach ($rData as $rKey => $rValue) {
				$rSplit = explode('_', (string) $rKey, 2);
				if ($rSplit[0] !== $rGenreKey || !isset($rSplit[1]) || !ctype_digit($rSplit[1])) {
					continue;
				}
				$rBouquets = '[' . implode(',', array_map('intval', (array) ($rData[$rBouquetKey . '_' . $rSplit[1]] ?? []))) . ']';
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = ?;', intval($rValue), $rBouquets, intval($rSplit[1]), $rType);
			}
		}
	}
}
