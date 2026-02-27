<?php

class SeriesService {
	public static function process($rSettings, $rData) {
		global $db;
		return API::processSeriesLegacy($rData);
	}

	public static function import($rData) {
		return API::importSeriesLegacy($rData);
	}

	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rSeries = json_decode($rData['series'], true);
		deleteSeriesMass($rSeries);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($rData) {
		global $db;
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = array();
		$rSeriesIDs = json_decode($rData['series'], true);

		if (0 < count($rSeriesIDs)) {
			$rCategoryMap = array();

			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], array('ADD', 'DEL'))) {
				$db->query('SELECT `id`, `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rSeriesIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
				}
			}

			$rBouquets = BouquetService::getAllSimple();
			$rAddBouquet = $rDelBouquet = array();

			foreach ($rSeriesIDs as $rSeriesID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id']);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rSeriesID] ?: array()) as $rCategoryID) {
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

				$rPrepare = prepareArray($rArray);

				if (0 < count($rPrepare['data'])) {
					$rPrepare['data'][] = $rSeriesID;
					$rQuery = 'UPDATE `streams_series` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_bouquets'])) {
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
				addToBouquet('series', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				removeFromBouquet('series', $rBouquetID, $rRemIDs);
			}

			if (isset($rData['reprocess_tmdb'])) {
				foreach ($rSeriesIDs as $rSeriesID) {
					if (0 < intval($rSeriesID)) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(2, ?, 0);', $rSeriesID);
					}
				}
			}
		}

		return array('status' => STATUS_SUCCESS);
	}

	// ──────────── Из SeriesRepository ────────────

	public static function getSimilar($rID, $rPage = 1) {
		require_once MAIN_HOME . 'includes/libs/tmdb.php';

		if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
		} else {
			$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
		}

		return json_decode(json_encode($rTMDB->getSimilarSeries($rID, $rPage)), true);
	}

	/**
	 * Get all series as id => row array, ordered by title.
	 */
	public static function getList() {
		global $db;
		$rReturn = array();
		$db->query('SELECT `id`, `title` FROM `streams_series` ORDER BY `title` ASC;');

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}
		return $rReturn;
	}

	/**
	 * Update series seasons from TMDB.
	 */
	public static function updateFromTMDB($rID) {
		global $db;
		require_once MAIN_HOME . 'includes/libs/tmdb.php';
		$db->query('SELECT `tmdb_id`, `tmdb_language` FROM `streams_series` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
		} else {
			$rRow = $db->get_row();
			$rTMDBID = $rRow['tmdb_id'];

			if (0 >= strlen($rTMDBID)) {
			} else {
				if (0 < strlen($rRow['tmdb_language'])) {
					$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $rRow['tmdb_language']);
				} else {
					if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
						$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
					} else {
						$rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
					}
				}

				$rReturn = array();
				$rSeasons = json_decode($rTMDB->getTVShow($rTMDBID)->getJSON(), true)['seasons'];

				foreach ($rSeasons as $rSeason) {
					$rSeason['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rSeason['poster_path'];

					if (!CoreUtilities::$rSettings['download_images']) {
					} else {
						$rSeason['cover'] = CoreUtilities::downloadImage($rSeason['cover']);
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
		global $db;
		$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(4, ?, 0);', $rID);
	}

	/**
	 * Generate playlist of episode sources for a series.
	 */
	public static function generatePlaylist($rSeriesNo) {
		global $db;
		$rReturn = array();
		$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC, `episode_num` ASC;', $rSeriesNo);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rRow) {
				$db->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?;', $rRow['stream_id']);

				if (0 >= $db->num_rows()) {
				} else {
					list($rSource) = json_decode($db->get_row()['stream_source'], true);
					$rReturn[] = $rSource;
				}
			}
		}

		return $rReturn;
	}
}
