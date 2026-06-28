<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Module\Tmdb\TmdbApiService;

/**
 * WatchService — watch service
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchService {

    use \XcVm\Infrastructure\Database\DatabaseAware;

	public static function editWatchSettings($rData) {
		$db = self::db();
		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genre') {
				$rBouquets = isset($rData['bouquet_' . $rSplit[1]]) ? '[' . implode(',', array_map('intval', $rData['bouquet_' . $rSplit[1]])) . ']' : '[]';
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 1;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genretv') {
				$rBouquets = isset($rData['bouquettv_' . $rSplit[1]]) ? '[' . implode(',', array_map('intval', $rData['bouquettv_' . $rSplit[1]])) . ']' : '[]';
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 2;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		$altTitles = isset($rData['alternative_titles']);
		$fallbackParser = isset($rData['fallback_parser']);
		$db->query('UPDATE `settings` SET `percentage_match` = ?, `scan_seconds` = ?, `thread_count` = ?, `max_genres` = ?, `max_items` = ?, `alternative_titles` = ?, `fallback_parser` = ?;', $rData['percentage_match'], $rData['scan_seconds'], $rData['thread_count'], $rData['max_genres'], $rData['max_items'], $altTitles, $fallbackParser);

		SettingsManager::clearCache();

		return array('status' => STATUS_SUCCESS);
	}

	public static function processWatchFolder($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			$rArray = AdminHelpers::overwriteData(StreamRepository::getWatchFolder($rData['edit']), $rData);
		} else {
			$rArray = QueryHelper::verifyPostTable('watch_folders', $rData);
			unset($rArray['id']);
		}

		$rPath = $rData['selected_path'];
		if (!(0 < strlen($rPath) && $rPath != '/')) {
			return array('status' => STATUS_INVALID_DIR, 'data' => $rData);
		}

		if (isset($rData['edit'])) {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ? AND `id` <> ?;', $rPath, $rArray['server_id'], $rData['folder_type'], $rArray['id']);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ?;', $rPath, $rArray['server_id'], $rData['folder_type']);
		}

		if (0 < $db->get_row()['count']) {
			return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
		}

		$bouquets = is_array($rData['bouquets'] ?? null) ? $rData['bouquets'] : array();
		$fbBouquets = is_array($rData['fb_bouquets'] ?? null) ? $rData['fb_bouquets'] : array();

		$rArray['type'] = $rData['folder_type'];
		$rArray['directory'] = $rPath;
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $bouquets)) . ']';
		$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fbBouquets)) . ']';
		$rArray['allowed_extensions'] = is_array($rData['allowed_extensions'] ?? null) && count($rData['allowed_extensions']) > 0 ? json_encode($rData['allowed_extensions']) : '[]';
		$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
		$rArray['category_id'] = intval($rData['category_id_' . $rData['folder_type']]);
		$rArray['fb_category_id'] = intval($rData['fb_category_id_' . $rData['folder_type']]);

		foreach (array('remove_subtitles', 'duplicate_tmdb', 'extract_metadata', 'fallback_title', 'disable_tmdb', 'ignore_no_match', 'auto_subtitles', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'ffprobe_input', 'delete_missing', 'active') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function getWatchFolders($rType = null) {
		$db = self::db();
		if ($rType) {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` = ? AND `type` <> 'plex' ORDER BY `id` ASC;", $rType);
		} else {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' ORDER BY `id` ASC;");
		}

		return $db->get_rows();
	}

	public static function getWatchCategories($rType = null) {
		$db = self::db();
		$rReturn = array();
		if ($rType) {
			$db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
		} else {
			$db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
		}

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['genre_id']] = $rRow;
		}

		return $rReturn;
	}

	public static function forceWatch($rServerID, $rWatchID) {
		return ApiClient::systemRequest($rServerID, array('action' => 'watch_force', 'id' => $rWatchID));
	}

	public static function enableWatch() {
		return self::db()->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` <> 'plex';");
	}

	public static function disableWatch() {
		return self::db()->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` <> 'plex';");
	}

	public static function killWatch() {
		$db = self::db();
		$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 11 AND `type` <> 'plex';");
		foreach ($db->get_rows() as $rRow) {
			if (ServerRepository::getAll()[$rRow['server_id']]['server_online']) {
				ApiClient::systemRequest($rRow['server_id'], array('action' => 'kill_watch'));
			}
		}
		return true;
	}

	public static function getRecordings() {
		$db = self::db();
		$rRecordings = array();
		$db->query('SELECT * FROM `recordings` ORDER BY `id` DESC;');
		foreach ($db->get_rows() as $rRow) {
			$rRecordings[] = $rRow;
		}
		return $rRecordings;
	}

	public static function deleteRecording($rID) {
		$db = self::db();
		$db->query('SELECT `created_id`, `source_id` FROM `recordings` WHERE `id` = ?;', $rID);
		if ($db->num_rows() > 0) {
			$rRecording = $db->get_row();
			if ($rRecording['created_id']) {
				StreamRepository::deleteStream($rRecording['created_id'], $rRecording['source_id'], true, true);
			}
			shell_exec("kill -9 `ps -ef | grep 'Record[" . intval($rID) . "]' | grep -v grep | awk '{print $2}'`");
			$db->query('DELETE FROM `recordings` WHERE `id` = ?;', $rID);
		}
		return true;
	}

	/**
	 * Sync TMDb movie/TV genres into the watch_categories table (types 1 & 2).
	 *
	 * Lives in the watch module because watch owns the watch_categories table
	 * (previously TMDbService::updateCategories() in the core VOD domain).
	 * Pulls the genre lists from the bundled TMDb client and inserts any that
	 * are missing, de-duplicating existing rows by genre_id.
	 *
	 * @return void
	 */
	public static function updateCategories() {
		$db = self::db();
		$rTMDB = TmdbApiService::createClient(SettingsManager::getAll()['tmdb_api_key']);

		$rCurrentCats = array(1 => array(), 2 => array());
		$db->query('SELECT `id`, `type`, `genre_id` FROM `watch_categories`;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				if (array_key_exists($rRow['type'], $rCurrentCats)) {

					if (in_array($rRow['genre_id'], $rCurrentCats[$rRow['type']])) {
						$db->query('DELETE FROM `watch_categories` WHERE `id` = ?;', $rRow['id']);
					}
					$rCurrentCats[$rRow['type']][] = $rRow['genre_id'];
				}
			}
		}

		$rMovieGenres = $rTMDB->getMovieGenres();

		foreach ($rMovieGenres as $rMovieGenre) {
			if (!in_array($rMovieGenre->getID(), $rCurrentCats[1])) {
				$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
			}

			if (!in_array($rMovieGenre->getID(), $rCurrentCats[2])) {
				$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
			}
		}

		$rTVGenres = $rTMDB->getTVGenres();

		foreach ($rTVGenres as $rTVGenre) {
			if (!in_array($rTVGenre->getID(), $rCurrentCats[1])) {
				$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
			}

			if (!in_array($rTVGenre->getID(), $rCurrentCats[2])) {
				$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
			}
		}
	}

	/**
	 * Remove a deleted bouquet from every watch folder that referenced it.
	 *
	 * Reacts to BouquetDeletedEvent so core bouquet deletion no longer needs to
	 * touch the watch_folders table directly.
	 *
	 * @param int $rBouquetID Deleted bouquet id.
	 * @return void
	 */
	public static function handleBouquetDeleted($rBouquetID) {
		$db = self::db();
		$db->query("SELECT `id`, `bouquets`, `fb_bouquets` FROM `watch_folders` WHERE JSON_CONTAINS(`bouquets`, ?, '\$') OR JSON_CONTAINS(`fb_bouquets`, ?, '\$');", $rBouquetID, $rBouquetID);

		foreach ($db->get_rows() as $rRow) {
			$rBouquets = json_decode($rRow['bouquets'], true) ?: array();
			if (($rKey = array_search($rBouquetID, $rBouquets)) !== false) {
				unset($rBouquets[$rKey]);
			}

			$rFbBouquets = json_decode($rRow['fb_bouquets'], true) ?: array();
			if (($rKey = array_search($rBouquetID, $rFbBouquets)) !== false) {
				unset($rFbBouquets[$rKey]);
			}

			$db->query(
				"UPDATE `watch_folders` SET `bouquets` = ?, `fb_bouquets` = ? WHERE `id` = ?;",
				'[' . implode(',', array_map('intval', $rBouquets)) . ']',
				'[' . implode(',', array_map('intval', $rFbBouquets)) . ']',
				$rRow['id']
			);
		}
	}

	/**
	 * Drop watch scan logs / refresh-queue rows for deleted streams.
	 *
	 * Reacts to StreamsDeletedEvent so core stream deletion no longer needs to
	 * touch the watch_refresh / watch_logs tables directly.
	 *
	 * @param int[] $rStreamIDs Deleted stream ids.
	 * @return void
	 */
	public static function handleStreamsDeleted(array $rStreamIDs) {
		if (empty($rStreamIDs)) {
			return;
		}
		$rIn = implode(',', array_map('intval', $rStreamIDs));
		$db = self::db();
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . $rIn . ');');
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . $rIn . ');');
	}
}
