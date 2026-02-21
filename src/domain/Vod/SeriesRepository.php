<?php

class SeriesRepository {
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
	 * Extracted from admin.php::getSeriesList()
	 */
	public static function getList($db) {
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
	 * Extracted from admin.php::updateSeries()
	 */
	public static function updateFromTMDB($db, $rID) {
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
	 * Extracted from admin.php::updateSeriesAsync()
	 */
	public static function queueRefresh($db, $rID) {
		$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(4, ?, 0);', $rID);
	}

	/**
	 * Generate playlist of episode sources for a series.
	 * Extracted from admin.php::generateSeriesPlaylist()
	 */
	public static function generatePlaylist($db, $rSeriesNo) {
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
