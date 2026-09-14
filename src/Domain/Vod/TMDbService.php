<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Tmdb\TmdbApiService;

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
	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Load the \TMDB client library (Infrastructure/Tmdb/lib).
	 *
	 * Thin alias for {@see TmdbApiService::requireLibrary()} so Domain call
	 * sites don't need to know where the vendored client lives.
	 */
	public static function requireLibrary(): void {
		TmdbApiService::requireLibrary();
	}

	/**
	 * Build a \TMDB client from panel settings (api key + optional language).
	 *
	 * Единая точка сборки клиента: раньше блок language/api_key дублировался
	 * в getMovie/getSeries/getSeason/addCategories.
	 *
	 * @return \TMDB
	 */
	private static function client(): \TMDB {
		self::requireLibrary();

		$rApiKey = SettingsManager::getString('tmdb_api_key');
		$rLanguage = SettingsManager::getString('tmdb_language');

		return $rLanguage !== '' ? new \TMDB($rApiKey, $rLanguage) : new \TMDB($rApiKey);
	}

	/**
	 * Fetch movie metadata from \TMDB.
	 *
	 * @param int $rID \TMDB movie id.
	 * @return \Movie|null Movie metadata object, or null on failure.
	 */
	public static function getMovie($rID) {
		$rTMDB = self::client();

		return ($rTMDB->getMovie($rID) ?: null);
	}

	/**
	 * Fetch series metadata from \TMDB.
	 *
	 * @param int $rID \TMDB series id.
	 * @return array|null Series metadata, or null on failure.
	 */
	public static function getSeries($rID) {
		$rTMDB = self::client();

		return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
	}

	/**
	 * Fetch season metadata (with episodes) from \TMDB.
	 *
	 * @param int $rID     \TMDB series id.
	 * @param int $rSeason Season number.
	 * @return array|null Season metadata, or null on failure.
	 */
	public static function getSeason($rID, $rSeason) {
		$rTMDB = self::client();

		return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
	}

	/**
	 * Fetch a series trailer URL/key from \TMDB.
	 *
	 * @param int         $rTMDBID   \TMDB series id.
	 * @param string|null $rLanguage Preferred language, or null for default.
	 * @return string|null Trailer reference, or null if none.
	 */
	public static function getSeriesTrailer($rTMDBID, $rLanguage = null) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(SettingsManager::getString('tmdb_api_key'));

		if ($rLanguage) {
			$rURL .= '&language=' . urlencode($rLanguage);
		} else {
			if (0 >= strlen(SettingsManager::getString('tmdb_language'))) {
			} else {
				$rURL .= '&language=' . urlencode(SettingsManager::getString('tmdb_language'));
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
	 * Fetch episode still images from \TMDB.
	 *
	 * @param int $rTMDBID  \TMDB series id.
	 * @param int $rSeason  Season number.
	 * @param int $rEpisode Episode number.
	 * @return array Still image references.
	 */
	public static function getStills($rTMDBID, $rSeason, $rEpisode) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/season/' . intval($rSeason) . '/episode/' . intval($rEpisode) . '/images?api_key=' . urlencode(SettingsManager::getString('tmdb_api_key'));

		if (0 >= strlen(SettingsManager::getString('tmdb_language'))) {
		} else {
			$rURL .= '&language=' . urlencode(SettingsManager::getString('tmdb_language'));
		}

		return json_decode(file_get_contents($rURL), true);
	}

	/**
	 * Import \TMDB genres as VOD categories.
	 *
	 * @return void
	 */
	public static function addCategories() {
		$db = self::db();
		$rTMDB = self::client();

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

		return;
	}
}
