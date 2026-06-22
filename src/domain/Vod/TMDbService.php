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
	private static $db = null;

	/**
	 * Inject the database handler (dependency injection).
	 *
	 * @param object $db Database handler.
	 * @return void
	 */
	public static function setDb($db): void {
		self::$db = $db;
	}

	/**
	 * Get the injected database handler.
	 *
	 * @return object Database handler.
	 * @throws \RuntimeException If setDb() was not called first.
	 */
	private static function db(): object {
		if (self::$db === null) {
			throw new \RuntimeException(static::class . '::setDb() must be called before use.');
		}
		return self::$db;
	}
	/**
	 * Fetch movie metadata from TMDB.
	 *
	 * @param int $rID TMDB movie id.
	 * @return array|null Movie metadata, or null on failure.
	 */
	public static function getMovie($rID) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return ($rTMDB->getMovie($rID) ?: null);
	}

	/**
	 * Fetch series metadata from TMDB.
	 *
	 * @param int $rID TMDB series id.
	 * @return array|null Series metadata, or null on failure.
	 */
	public static function getSeries($rID) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
	}

	/**
	 * Fetch season metadata (with episodes) from TMDB.
	 *
	 * @param int $rID     TMDB series id.
	 * @param int $rSeason Season number.
	 * @return array|null Season metadata, or null on failure.
	 */
	public static function getSeason($rID, $rSeason) {
		require_once MAIN_HOME . 'modules/tmdb/lib/TmdbClient.php';

		if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
		} else {
			$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
		}

		return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
	}

	/**
	 * Fetch a series trailer URL/key from TMDB.
	 *
	 * @param int         $rTMDBID   TMDB series id.
	 * @param string|null $rLanguage Preferred language, or null for default.
	 * @return string|null Trailer reference, or null if none.
	 */
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

	/**
	 * Fetch episode still images from TMDB.
	 *
	 * @param int $rTMDBID  TMDB series id.
	 * @param int $rSeason  Season number.
	 * @param int $rEpisode Episode number.
	 * @return array Still image references.
	 */
	public static function getStills($rTMDBID, $rSeason, $rEpisode) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/season/' . intval($rSeason) . '/episode/' . intval($rEpisode) . '/images?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);

		if (0 >= strlen(SettingsManager::getAll()['tmdb_language'])) {
		} else {
			$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
		}

		return json_decode(file_get_contents($rURL), true);
	}

	/**
	 * Import TMDB genres as VOD categories.
	 *
	 * @return void
	 */
	public static function addCategories() {
		$db = self::db();
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

	/**
	 * Refresh the TMDB genre → category mappings.
	 *
	 * @return void
	 */
	public static function updateCategories() {
		$db = self::db();
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
