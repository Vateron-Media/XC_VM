<?php

/**
 * TMDbService — TMDb API integration
 *
 * @package XC_VM_Domain_Vod
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TMDbService {
	public static function getMovie($rID) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return ($rTMDB->getMovie($rID) ?: null);
	}

	public static function getSeries($rID) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
	}

	public static function getSeason($rID, $rSeason) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
	}

	public static function getSeriesTrailer($rTMDBID, $rLanguage = null) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);

		if ($rLanguage) {
			$rURL .= '&language=' . urlencode($rLanguage);
		} else {
			if (0 >= strlen(SettingsManager::getAll()['tmdb_language'])) {
			} else {
				$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
			}
		}

		$rJSON = json_decode(file_get_contents($rURL), true);

		foreach ($rJSON['results'] as $rVideo) {
			if (!(strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube')) {
			} else {
				return $rVideo['key'];
			}
		}

		return '';
	}

	public static function getStills($rTMDBID, $rSeason, $rEpisode) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/season/' . intval($rSeason) . '/episode/' . intval($rEpisode) . '/images?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);

		if (0 >= strlen(SettingsManager::getAll()['tmdb_language'])) {
		} else {
			$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
		}

		return json_decode(file_get_contents($rURL), true);
	}

	public static function addCategories() {
		global $db;
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		$rCurrentCats = array('movie' => array(), 'series' => array());

		$db->query('SELECT `id`, `category_type`, `category_name` FROM `streams_categories`;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				if (array_key_exists($rRow['category_type'], $rCurrentCats)) {
					$rCurrentCats[$rRow['category_type']][] = $rRow['category_name'];
				}
			}
		}

		$rMovieGenres = $rTMDB->getMovieGenres();
		foreach ($rMovieGenres as $rMovieGenre) {
			$movieGenreName = $rMovieGenre->getName();
			if (!in_array($movieGenreName, $rCurrentCats['movie'])) {
				$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('movie', ?);", $movieGenreName);
			}
		}

		$rTVGenres = $rTMDB->getTVGenres();
		foreach ($rTVGenres as $rTVGenre) {
			$seriesGenreName = $rTVGenre->getName();
			if (!in_array($seriesGenreName, $rCurrentCats['series'])) {
				$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('series', ?);", $seriesGenreName);
			}
		}

		return true;
	}

	public static function updateCategories() {
		global $db;
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

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
}
