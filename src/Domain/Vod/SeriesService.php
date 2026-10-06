<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\ImageUtils;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * SeriesService — series service
 *
 * @package XC_VM_Domain_Vod
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SeriesService {
	use DatabaseAware;

	/**
	 * Create or update a series from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processSeries', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_series')) {
					// The edit starts from the series as stored: the row cleaner's
					// escaping would be written back into every field not sent.
					$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', intval($rData['edit']));
					$rArray = AdminHelpers::overwriteData($db->get_raw_row(), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_series')) {
					$rArray = QueryHelper::verifyPostTable('streams_series', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (SettingsManager::getBool('download_images')) {
				$rData['cover'] = ImageUtils::downloadImage($rData['cover'], 2);
				$rData['backdrop_path'] = ImageUtils::downloadImage($rData['backdrop_path']);
			}

			if (strlen($rData['backdrop_path']) == 0) {
				$rArray['backdrop_path'] = [];
			} else {
				$rArray['backdrop_path'] = [$rData['backdrop_path']];
			}

			$rArray['last_modified'] = time();
			$rArray['cover'] = $rData['cover'];
			$rArray['cover_big'] = $rData['cover'];
			$rBouquetCreate = [];

			foreach ((json_decode($rData['bouquet_create_list'] ?? '', true) ?: []) as $rBouquet) {
				$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
				$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rBouquetID = $db->last_insert_id();
					$rBouquetCreate[$rBouquet] = $rBouquetID;
				}
			}
			$rCategoryCreate = [];

			foreach ((json_decode($rData['category_create_list'] ?? '', true) ?: []) as $rCategory) {
				$rPrepare = QueryHelper::prepareArray(['category_type' => 'series', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
				$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rCategoryID = $db->last_insert_id();
					$rCategoryCreate[$rCategory] = $rCategoryID;
				}
			}
			$rBouquets = [];

			foreach (($rData['bouquets'] ?? []) as $rBouquet) {
				if (isset($rBouquetCreate[$rBouquet])) {
					$rBouquets[] = $rBouquetCreate[$rBouquet];
				} else {
					if (is_numeric($rBouquet)) {
						$rBouquets[] = intval($rBouquet);
					}
				}
			}
			$rCategories = [];

			foreach (($rData['category_id'] ?? []) as $rCategory) {
				if (isset($rCategoryCreate[$rCategory])) {
					$rCategories[] = $rCategoryCreate[$rCategory];
				} else {
					if (is_numeric($rCategory)) {
						$rCategories[] = intval($rCategory);
					}
				}
			}
			$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
			$rPrepare = QueryHelper::prepareArray($rArray);
			$rQuery = 'REPLACE INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				self::queueRefresh($rInsertID);

				foreach ($rBouquets as $rBouquet) {
					BouquetService::addItems('series', $rBouquet, $rInsertID);
				}

				foreach (BouquetService::getAllSimple() as $rBouquet) {
					if (!in_array($rBouquet['id'], $rBouquets)) {
						BouquetService::removeItems('series', $rBouquet['id'], $rInsertID);
					}
				}

				return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
			}
			foreach ($rBouquetCreate as $rID) {
					$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
			}
			foreach ($rCategoryCreate as $rID) {
					$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
			}
			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Import series (e.g. from a source/\TMDB) into the catalog.
	 *
	 * @param array $rData Import payload (sources, category, options).
	 * @return array Import result.
	 */
	public static function import(array $rData) {
		$db = self::db();
		if (Authorization::check('adv', 'import_movies')) {
			if (InputValidator::validate('importSeries', $rData)) {
				$rPostData = $rData;

				foreach (['read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles'] as $rKey) {
					if (isset($rData[$rKey])) {
						$rData[$rKey] = 1;
					} else {
						$rData[$rKey] = 0;
					}
				}

				if (isset($rData['restart_on_edit'])) {
					$rRestart = true;
				} else {
					$rRestart = false;
				}

				$rStreamDatabase = [];
				$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 5;');

				foreach ($db->get_rows() as $rRow) {
					foreach (json_decode($rRow['stream_source'], true) as $rSource) {
						if ((string) $rSource !== '') {
							$rStreamDatabase[] = $rSource;
						}
					}
				}
				$rImportStreams = [];

				if (!empty($_FILES['m3u_file']['tmp_name'])) {
					$rFile = '';

					if (!empty($_FILES['m3u_file']['tmp_name']) && strtolower(pathinfo(explode('?', $_FILES['m3u_file']['name'])[0], PATHINFO_EXTENSION)) == 'm3u') {
						$rFile = file_get_contents($_FILES['m3u_file']['tmp_name']);
					}

					preg_match_all('/(?P<tag>#EXTINF:[-1,0])|(?:(?P<prop_key>[-a-z]+)=\\"(?P<prop_val>[^"]+)")|(?<name>,[^\\r\\n]+)|(?<url>http[^\\s]*:\\/\\/.*\\/.*)/', $rFile, $rMatches);
					$rResults = [];
					$rIndex = -1;
					$counter = count($rMatches[0]);

					for ($i = 0; $i < $counter; $i++) {
						$rItem = $rMatches[0][$i];

						if (!empty($rMatches['tag'][$i])) {
							$rIndex++;
						} else {
							if (!empty($rMatches['prop_key'][$i])) {
								$rResults[$rIndex][$rMatches['prop_key'][$i]] = trim($rMatches['prop_val'][$i]);
							} else {
								if (!empty($rMatches['name'][$i])) {
									$rResults[$rIndex]['name'] = trim(substr($rItem, 1));
								} else {
									if (!empty($rMatches['url'][$i])) {
										$rResults[$rIndex]['url'] = str_replace(' ', '%20', trim($rItem));
									}
								}
							}
						}
					}

					foreach ($rResults as $rResult) {
						if (!empty($rResult['url']) && !in_array($rResult['url'], $rStreamDatabase)) {
							$rPathInfo = pathinfo(explode('?', $rResult['url'])[0]);
							if (empty($rPathInfo['extension'])) {
								$rPathInfo['extension'] = ($rData['target_container'] ?: 'mp4');
							}
							$rImportStreams[] = ['url' => $rResult['url'], 'title' => ($rResult['name'] ?: ''), 'container' => ($rData['movie_symlink'] || $rData['direct_source'] ? $rPathInfo['extension'] : $rData['target_container'])];
						}
					}
				} else {
					if (!empty($rData['import_folder'])) {
						$rParts = explode(':', $rData['import_folder']);
						if (is_numeric($rParts[1] ?? null)) {
							if (isset($rData['scan_recursive'])) {
								$rFiles = ApiClient::scanRecursive(intval($rParts[1]), $rParts[2], ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts']);
							} else {
								$rFiles = [];

								foreach (ApiClient::listDir(intval($rParts[1]), rtrim($rParts[2], '/'), ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'])['files'] as $rFile) {
									$rFiles[] = rtrim($rParts[2], '/') . '/' . $rFile;
								}
							}
							foreach ($rFiles as $rFile) {
								$rFilePath = 's:' . intval($rParts[1]) . ':' . $rFile;

								if (!in_array($rFilePath, $rStreamDatabase)) {
									$rPathInfo = pathinfo($rFile);
									if (empty($rPathInfo['extension'])) {
										$rPathInfo['extension'] = ($rData['target_container'] ?: 'mp4');
									}
									$rImportStreams[] = ['url' => $rFilePath, 'title' => $rPathInfo['filename'], 'container' => ($rData['movie_symlink'] || $rData['direct_source'] ? $rPathInfo['extension'] : $rData['target_container'])];
								}
							}
						}
					}
				}

				$rSeriesCategories = array_keys(CategoryService::getAllByType('series'));

				if (0 < count($rImportStreams)) {
					$rBouquets = [];

					foreach ((json_decode($rData['bouquet_create_list'] ?? '', true) ?: []) as $rBouquet) {
						$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rBouquets[] = $db->last_insert_id();
						}
					}

					foreach (($rData['bouquets'] ?? []) as $rBouquetID) {
						if (is_numeric($rBouquetID) && in_array($rBouquetID, array_keys(BouquetService::getAll()))) {
							$rBouquets[] = intval($rBouquetID);
						}
					}
					unset($rData['bouquets'], $rData['bouquet_create_list']);

					$rCategories = [];

					foreach ((json_decode($rData['category_create_list'] ?? '', true) ?: []) as $rCategory) {
						$rPrepare = QueryHelper::prepareArray(['category_type' => 'series', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rCategories[] = $db->last_insert_id();
						}
					}

					foreach (($rData['category_id'] ?? []) as $rCategoryID) {
						if (is_numeric($rCategoryID) && in_array($rCategoryID, $rSeriesCategories)) {
							$rCategories[] = intval($rCategoryID);
						}
					}
					unset($rData['category_id'], $rData['category_create_list']);

					$rServerIDs = [];

					foreach ((json_decode($rData['server_tree_data'] ?? '', true) ?: []) as $rServer) {
						if ($rServer['parent'] != '#') {
							$rServerIDs[] = intval($rServer['id']);
						}
					}
					$rItems = [];
					foreach ($rImportStreams as $rImportStream) {
						$rItems[] = ['import' => true, 'type' => 'series', 'title' => $rImportStream['title'], 'file' => $rImportStream['url'], 'subtitles' => [], 'servers' => $rServerIDs, 'fb_category_id' => $rCategories, 'fb_bouquets' => $rBouquets, 'disable_tmdb' => false, 'ignore_no_match' => false, 'bouquets' => [], 'category_id' => [], 'language' => SettingsManager::getString('tmdb_language'), 'read_native' => $rData['read_native'], 'movie_symlink' => $rData['movie_symlink'], 'remove_subtitles' => $rData['remove_subtitles'], 'direct_source' => $rData['direct_source'], 'direct_proxy' => $rData['direct_proxy'], 'auto_encode' => $rRestart, 'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false, 'transcode_profile_id' => ($rData['transcode_profile_id'] ?? null), 'target_container' => $rImportStream['container'], 'max_genres' => SettingsManager::getInt('max_genres'), 'duplicate_tmdb' => true];
					}

					return ['status' => VodItemImporter::queueBatch($rItems) ? STATUS_SUCCESS : STATUS_FAILURE];
				}
				return ['status' => STATUS_NO_SOURCES, 'data' => $rPostData];
			}
			return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
		}
		exit();
	}

	/**
	 * Bulk delete selected series.
	 *
	 * @param array $rData Selected series ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rSeries = json_decode($rData['series'], true);
		self::deleteSeriesByIds($rSeries);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Apply bulk edits to selected series.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = [];
		$rSeriesIDs = json_decode($rData['series'], true);

		if (0 < count($rSeriesIDs)) {
			$rCategoryMap = [];

			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], ['ADD', 'DEL'])) {
				$db->query('SELECT `id`, `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rSeriesIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: []);
				}
			}

			$rBouquets = BouquetService::getAllSimple();
			$rAddBouquet = $rDelBouquet = [];

			foreach ($rSeriesIDs as $rSeriesID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id'] ?? []);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rSeriesID] ?: []) as $rCategoryID) {
							if (!in_array($rCategoryID, $rCategories)) {
								$rCategories[] = $rCategoryID;
							}
						}
					} elseif ($rData['category_id_type'] == 'DEL') {
						$rNewCategories = $rCategoryMap[$rSeriesID];

						foreach ($rCategories as $rCategoryID) {
							if (($rKey = array_search($rCategoryID, $rNewCategories)) !== false) {
								unset($rNewCategories[$rKey]);
							}
						}
						$rCategories = $rNewCategories;
					}

					$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
				}

				$rPrepare = QueryHelper::prepareArray($rArray);

				if (0 < count($rPrepare['data'])) {
					$rPrepare['data'][] = $rSeriesID;
					$rQuery = 'UPDATE `streams_series` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_bouquets'])) {
					// browsers omit the multi-select entirely when nothing is checked
					$rData['bouquets'] = $rData['bouquets'] ?? [];

					if ($rData['bouquets_type'] == 'SET') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rSeriesID;
						}

						foreach ($rBouquets as $rBouquet) {
							if (!in_array($rBouquet['id'], $rData['bouquets'])) {
								$rDelBouquet[$rBouquet['id']][] = $rSeriesID;
							}
						}
					} elseif ($rData['bouquets_type'] == 'ADD') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rSeriesID;
						}
					} elseif ($rData['bouquets_type'] == 'DEL') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rDelBouquet[$rBouquet][] = $rSeriesID;
						}
					}
				}
			}

			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				BouquetService::addItems('series', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				BouquetService::removeItems('series', $rBouquetID, $rRemIDs);
			}

			if (isset($rData['reprocess_tmdb'])) {
				foreach ($rSeriesIDs as $rSeriesID) {
					if (0 < intval($rSeriesID)) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(2, ?, 0);', $rSeriesID);
					}
				}
			}
		}

		return ['status' => STATUS_SUCCESS];
	}

	// ──────────── Из SeriesRepository ────────────

	/**
	 * Get series similar to the given one (paged).
	 *
	 * @param int $rID   Series id.
	 * @param int $rPage Result page.
	 * @return array Similar series.
	 */
	public static function getSimilar(int $rID, int $rPage = 1) {
		TMDbService::requireLibrary();

		if (SettingsManager::getString('tmdb_language') !== '') {
			$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'), SettingsManager::getString('tmdb_language'));
		} else {
			$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'));
		}

		return json_decode(json_encode($rTMDB->getSimilarSeries($rID, $rPage)), true);
	}

	/**
	 * Get all series as id => row array, ordered by title.
	 */
	public static function getList() {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT `id`, `title` FROM `streams_series` ORDER BY `title` ASC;');

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}
		return $rReturn;
	}

	/**
	 * Update series seasons from \TMDB.
	 */
	public static function updateFromTMDB($rID) {
		$db = self::db();
		TMDbService::requireLibrary();
		$db->query('SELECT `tmdb_id`, `tmdb_language` FROM `streams_series` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			$rRow = $db->get_row();
			$rTMDBID = $rRow['tmdb_id'];
			if ((string) $rTMDBID !== '') {
				if ((string) $rRow['tmdb_language'] !== '') {
					$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'), $rRow['tmdb_language']);
				} else {
					if (SettingsManager::getString('tmdb_language') !== '') {
						$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'), SettingsManager::getString('tmdb_language'));
					} else {
						$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'));
					}
				}
				$rReturn = [];
				$rSeasons = json_decode($rTMDB->getTVShow($rTMDBID)->getJSON(), true)['seasons'];
				foreach ($rSeasons as $rSeason) {
					$rSeason['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rSeason['poster_path'];

					if (SettingsManager::getBool('download_images')) {
						$rSeason['cover'] = ImageUtils::downloadImage($rSeason['cover']);
					}

					$rSeason['cover_big'] = $rSeason['cover'];
					unset($rSeason['poster_path']);
					$rReturn[] = $rSeason;
				}
				$db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rReturn, JSON_UNESCAPED_UNICODE), $rID);
			}
		}
	}

	/**
	 * Queue async series refresh via watch_refresh table.
	 */
	public static function queueRefresh($rID) {
		$db = self::db();
		$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(4, ?, 0);', $rID);
	}

	/**
	 * Generate playlist of episode sources for a series.
	 */
	public static function generatePlaylist($rSeriesNo) {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC, `episode_num` ASC;', $rSeriesNo);

		if (0 < $db->num_rows()) {
			foreach ($db->get_rows() as $rRow) {
				$db->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?;', $rRow['stream_id']);

				if (0 < $db->num_rows()) {
					list($rSource) = json_decode($db->get_row()['stream_source'], true);
					$rReturn[] = $rSource;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a single series by id.
	 *
	 * @param int $rID Series id.
	 * @return array|false The series row, or false if not found.
	 */
	public static function getById(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return false;
		}

		return $db->get_row();
	}

	/**
	 * Fetch all series.
	 *
	 * @return array Series rows.
	 */
	public static function getAll() {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT * FROM `streams_series` ORDER BY `title` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a series by its \TMDB id.
	 *
	 * @param int $rID \TMDB id.
	 * @return array|false The series row, or false if not found.
	 */
	public static function getByTMDBId(int $rID) {
		$db = self::db();
		$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return false;
		}

		return $db->get_row();
	}

	/**
	 * Delete a series and its episodes (optionally the on-disk files).
	 *
	 * @param int  $rID          Series id.
	 * @param bool $rDeleteFiles Also remove on-disk episode files.
	 * @return bool True on success.
	 */
	public static function deleteSeriesById(int $rID, bool $rDeleteFiles = true) {
		$db = self::db();
		$rSeries = self::getById($rID);

		if (!$rSeries) {
			return false;
		}

		$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			// One bouquet scan for the series, below: not one per episode.
			StreamRepository::deleteStream($rRow['stream_id'], -1, $rDeleteFiles, true, false);
		}
		$db->query('DELETE FROM `streams_episodes` WHERE `series_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_series` WHERE `id` = ?;', $rID);
		BouquetService::scan();

		return true;
	}

	/**
	 * Bulk delete series by ids.
	 *
	 * @param int[] $rIDs Series ids.
	 * @return bool True on success.
	 */
	public static function deleteSeriesByIds(array $rIDs) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		$rStreamIDs = [];
		$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rStreamIDs[] = $rRow['stream_id'];
		}
		$db->query('DELETE FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_series` WHERE `id` IN (' . implode(',', $rIDs) . ');');

		if (0 < count($rStreamIDs)) {
			StreamRepository::deleteStreams($rStreamIDs, true);
		}

		BouquetService::scan();

		return true;
	}
}
