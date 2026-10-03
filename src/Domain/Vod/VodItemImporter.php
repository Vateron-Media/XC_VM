<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Infrastructure\Tmdb\TmdbApiService;
use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * VodItemImporter — imports one movie or episode file: parses the release
 * name, matches it on TMDb and writes the stream (and the series, if new).
 *
 * Runs as `console.php vod_import_item`, one process per file, for both the
 * manual Movies/Series import (`import` = true) and the watch module's folder
 * scan. Each file's outcome is dispatched as a VodImportResultEvent.
 *
 * @package XC_VM_Domain_Vod
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class VodItemImporter {
	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** How long to trust the series_*.data cache before re-reading from the DB. */
	private const SERIES_CACHE_TTL_SECONDS = 360;

	/**
	 * Add an item to a bouquet (via the DB when importing, otherwise as a file for checkBouquets()).
	 *
	 * @param string $rType
	 * @param int $rBouquetID
	 * @param int $rID
	 * @param bool $rImport
	 * @param string $rSourceFile
	 */
	public static function addToBouquet($rType, $rBouquetID, $rID, $rImport, $rSourceFile) {
		if ($rImport) {
			BouquetService::addItems($rType, $rBouquetID, $rID);
		} else {
			file_put_contents(WATCH_TMP_PATH . md5($rSourceFile . '_' . $rType . '_' . $rBouquetID . '_' . $rID) . '.bouquet', json_encode(['type' => $rType, 'bouquet_id' => $rBouquetID, 'id' => $rID]));
		}
	}

	/**
	 * Parse a release name (guessit / ptn).
	 *
	 * @param string $rRelease
	 * @param string $rType
	 * @return array|null
	 */
	public static function parserelease($rRelease, $rType = 'guessit') {
		if ($rType == 'guessit') {
			$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg($rRelease . '.mkv');
		} else {
			$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'bin/python/release.py ' . escapeshellarg(str_replace('-', '_', $rRelease));
		}
		$rResult = json_decode(shell_exec($rCommand), true);
		if (!is_array($rResult)) {
			$rResult = [];
		}
		// Explicit "S01 E01" / "S01E01" pattern: parsers (PTN especially) break
		// on numeric titles like "9-1-1" — they take a digit from the title as
		// the season and produce a garbage title. If the source string has an
		// explicit SxxExx, reliably extract season/episode/title via regex and
		// override.
		if (preg_match('/^(.+?)[\s._-]+[Ss](\d{1,3})[\s._-]*[Ee](\d{1,4})/', $rRelease, $rMatch)) {
			$rCleanTitle = trim(preg_replace('/\s+/', ' ', preg_replace('/[._]+/', ' ', $rMatch[1])));
			// Strip a trailing year from the title ("Boston Blue (2025)" →
			// "Boston Blue") — it breaks the TMDB search. The year is kept
			// separately for the search. Guard: don't touch it if the year is
			// the whole title (e.g. the series "1923").
			if (preg_match('/^(.*?)[\s._-]*\(?((?:19|20)\d{2})\)?$/', $rCleanTitle, $rYearMatch) && trim($rYearMatch[1]) !== '') {
				$rResult['year'] = intval($rYearMatch[2]);
				$rCleanTitle = trim($rYearMatch[1]);
			}
			if ($rCleanTitle !== '') {
				$rResult['title'] = $rCleanTitle;
				$rResult['season'] = intval($rMatch[2]);
				$rResult['episode'] = intval($rMatch[3]);
			}
		}
		return $rResult;
	}

	/**
	 * Get a movie from the cache by TMDB ID.
	 *
	 * @param int $rTMDBID
	 * @return array|null
	 */
	public static function getMovie($rTMDBID) {
		if (file_exists(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache')) {
			return json_decode(file_get_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache'), true);
		}
		return null;
	}

	/**
	 * Get an episode from the cache by TMDB ID, season and number.
	 *
	 * @param int $rTMDBID
	 * @param int $rSeason
	 * @param int $rEpisode
	 * @return array|null
	 */
	public static function getEpisode($rTMDBID, $rSeason, $rEpisode) {
		if (file_exists(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache')) {
			$rData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache'), true);
			if (isset($rData[$rSeason . '_' . $rEpisode])) {
				return $rData[$rSeason . '_' . $rEpisode];
			}
		}
		return null;
	}

	/**
	 * Normalize a title for comparison.
	 *
	 * @param string $rTitle
	 * @return string
	 */
	public static function parseTitle($rTitle) {
		// Normalize separators: dash, underscore AND dot → space, so titles
		// like "9-1-1" and abbreviations like "S.W.A.T." match regardless of
		// how the parser rendered them: parsers give "S W A T", while TMDb
		// gives "S.W.A.T.". Without dot normalization such titles scored
		// ~53% and got filtered out by the threshold. The dot is also
		// removed from the keep-set of the regex below. The comparison is
		// symmetric on both sides — it doesn't break existing matches.
		$rTitle = str_replace(['-', '_', '.'], ' ', $rTitle);
		$rTitle = strtolower(preg_replace("/(?![=\$'€%-])\\p{P}/u", '', $rTitle));
		return trim(preg_replace('/\\s+/u', ' ', $rTitle));
	}

	/**
	 * Probe the source via ffprobe.
	 *
	 * @param string $rFilename
	 * @return array|null
	 */
	public static function checksource($rFilename) {
		$rCommand = 'timeout 10 ' . FfmpegPaths::probe() . ' -show_streams -show_format -v quiet ' . escapeshellarg($rFilename) . ' -of json';
		return json_decode(shell_exec($rCommand), true);
	}

	/**
	 * Get a series by TMDB ID.
	 *
	 * @param int $rID
	 * @return array|null
	 */
	public static function getSeriesByTMDB($rID) {
		$db = self::db();
		if (!(file_exists(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') && time() - filemtime(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data') < self::SERIES_CACHE_TTL_SECONDS)) {
			$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);
			if ($db->num_rows() == 1) {
				return $db->get_row();
			}
			return null;
		} else {
			return json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . intval($rID) . '.data'), true);
		}
	}

	/**
	 * Get a series' trailer URL from TMDB.
	 *
	 * @param int $rTMDBID
	 * @param string|null $rLanguage
	 * @return string
	 */
	public static function getSeriesTrailer($rTMDBID, $rLanguage = null) {
		$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);
		if ($rLanguage) {
			$rURL .= '&language=' . urlencode($rLanguage);
		} else {
			if (strlen(SettingsManager::getAll()['tmdb_language']) > 0) {
				$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
			}
		}
		$rJSON = json_decode(file_get_contents($rURL), true);
		foreach ($rJSON['results'] as $rVideo) {
			if (strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube') {
				return $rVideo['key'];
			}
		}
		return '';
	}

	/**
	 * Get a series by ID.
	 *
	 * @param int $rID
	 * @return array|null
	 */
	public static function getSerie($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
		return null;
	}

	/**
	 * Get the next order number for streams.
	 *
	 * @return int
	 */
	public static function getNextOrder() {
		$db = self::db();
		$db->query('SELECT MAX(`order`) AS `order` FROM `streams`;');
		if ($db->num_rows() != 1) {
			return 0;
		}
		return intval($db->get_row()['order']) + 1;
	}

	/**
	 * Report a file's processing outcome.
	 *
	 * @param int $rThreadType
	 * @param string $rFile
	 * @param int $rStatus VodImportResultEvent::STATUS_*
	 * @param int $rStreamID
	 */
	private static function reportResult($rThreadType, $rFile, $rStatus, $rStreamID = 0) {
		EventDispatcher::dispatch(new VodImportResultEvent((int) $rThreadType, (int) SERVER_ID, (string) $rFile, (int) $rStatus, (int) $rStreamID));
	}

	/**
	 * Shared "upgrade in place" logic: if the new file isn't better than the already
	 * imported one, leave it alone. Otherwise updates streams/streams_servers, logs the
	 * outcome and hands control to $rWriteCache for the type-specific cache rewrite
	 * (movie_*.cache / series_*.cache), then always throws VodItemImportHalt to stop processing.
	 *
	 * @param array $rUpgradeData
	 * @param array $rThreadData
	 * @param string $rFile
	 * @param array $rImportArray
	 * @param int $rThreadType
	 * @param string $rLabel
	 * @param callable $rWriteCache
	 * @return never
	 */
	public static function applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, $rLabel, callable $rWriteCache) {
		if (!$rThreadData['auto_upgrade']) {
			echo 'Upgrade disabled' . "\n";
			throw new VodItemImportHalt();
		}
		if (substr($rUpgradeData['source'], 0, 3 + strlen(strval(SERVER_ID))) != 's:' . SERVER_ID . ':') {
			echo "Old file path doesn't match this server, don't upgrade." . "\n";
			throw new VodItemImportHalt();
		}
		list(, $rActualPath) = explode('s:' . SERVER_ID . ':', $rUpgradeData['source']);
		if (file_exists($rActualPath) && filesize($rActualPath) >= filesize($rFile)) {
			echo "File isn't a better source, don't upgrade." . "\n";
			throw new VodItemImportHalt();
		}
		echo 'Upgrade ' . $rLabel . '!' . "\n";
		$db = self::db();
		$db->query('UPDATE `streams` SET `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rImportArray['stream_source'], $rImportArray['target_container'], $rUpgradeData['id']);
		$db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?', $rUpgradeData['id'], SERVER_ID);
		EventDispatcher::dispatch(new StreamsChangedEvent([(int) $rUpgradeData['id']]));
		if ($rThreadData['auto_encode']) {
			StreamProcess::queueMovie($rUpgradeData['id']);
		}
		self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_UPGRADED);
		$rWriteCache($rUpgradeData);
		throw new VodItemImportHalt();
	}

	/**
	 * Top-N names from TMDB cast/crew (was duplicated for movie and series).
	 *
	 * @param array $rCredits
	 * @param int $rLimit
	 * @return string[]
	 */
	public static function extractTopCast(array $rCredits, $rLimit = 5) {
		$rCast = [];
		foreach (($rCredits['cast'] ?? []) as $rMember) {
			if (count($rCast) >= $rLimit) {
				break;
			}
			$rCast[] = $rMember['name'];
		}
		return $rCast;
	}

	/**
	 * Top-N directors (department/known_for_department == Directing) from TMDB crew.
	 *
	 * @param array $rCredits
	 * @param int $rLimit
	 * @return string[]
	 */
	public static function extractTopDirectors(array $rCredits, $rLimit = 5) {
		$rDirectors = [];
		foreach (($rCredits['crew'] ?? []) as $rMember) {
			if (count($rDirectors) >= $rLimit) {
				break;
			}
			if (($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing') && !in_array($rMember['name'], $rDirectors)) {
				$rDirectors[] = $rMember['name'];
			}
		}
		return $rDirectors;
	}

	/**
	 * Top-N TMDB genre names (the limit is passed through as-is to preserve
	 * the existing movie behavior (fixed at 3) vs. series (max_genres setting)).
	 *
	 * @param array $rGenres
	 * @param int $rLimit
	 * @return string[]
	 */
	public static function extractTopGenreNames(array $rGenres, $rLimit) {
		$rNames = [];
		foreach ($rGenres as $rGenre) {
			if (count($rNames) >= $rLimit) {
				break;
			}
			$rNames[] = $rGenre['name'];
		}
		return $rNames;
	}

	/**
	 * Add to $rCategoryIDs the categories mapped from TMDB genres via watch_categories.
	 *
	 * @param array $rGenres
	 * @param array $rWatchCategoryMap watch_categories for the relevant type, keyed by genre_id
	 * @param int $rMaxGenres
	 * @param array $rCategoryIDs
	 * @return array
	 */
	public static function resolveGenreCategoryIDs(array $rGenres, array $rWatchCategoryMap, $rMaxGenres, array $rCategoryIDs) {
		$rParsed = (0 < $rMaxGenres) ? array_slice($rGenres, 0, (int) $rMaxGenres) : $rGenres;
		foreach ($rParsed as $rGenre) {
			$rGenreId = (int) ($rGenre['id'] ?? 0);
			$rCategoryID = (int) ($rWatchCategoryMap[$rGenreId]['category_id'] ?? 0);
			if ($rCategoryID > 0 && !in_array($rCategoryID, $rCategoryIDs, true)) {
				$rCategoryIDs[] = $rCategoryID;
			}
		}
		return $rCategoryIDs;
	}

	/**
	 * Add to $rBouquetIDs the bouquets mapped from TMDB genres via watch_categories.
	 *
	 * @param array $rGenres
	 * @param array $rWatchCategoryMap watch_categories for the relevant type, keyed by genre_id
	 * @param int $rMaxGenres
	 * @param array $rBouquetIDs
	 * @return array
	 */
	public static function resolveGenreBouquetIDs(array $rGenres, array $rWatchCategoryMap, $rMaxGenres, array $rBouquetIDs) {
		$rParsed = (0 < $rMaxGenres) ? array_slice($rGenres, 0, (int) $rMaxGenres) : $rGenres;
		foreach ($rParsed as $rGenre) {
			$rGenreId = (int) ($rGenre['id'] ?? 0);
			$rBouquets = json_decode($rWatchCategoryMap[$rGenreId]['bouquets'] ?? '[]', true) ?: [];
			foreach ($rBouquets as $rBouquetID) {
				if (!in_array($rBouquetID, $rBouquetIDs)) {
					$rBouquetIDs[] = $rBouquetID;
				}
			}
		}
		return $rBouquetIDs;
	}

	/**
	 * Common $rImportArray fields set in all three run() branches (movie-match,
	 * series-match, no-match). $rSetEnableTranscode=false preserves the existing
	 * series-match behavior, where enable_transcode has historically never been set.
	 *
	 * @param array $rImportArray
	 * @param array $rThreadData
	 * @param bool $rSetEnableTranscode
	 */
	private static function applyCommonStreamSettings(array &$rImportArray, array $rThreadData, $rSetEnableTranscode = true) {
		$rImportArray['read_native'] = $rThreadData['read_native'];
		$rImportArray['movie_symlink'] = $rThreadData['movie_symlink'];
		$rImportArray['remove_subtitles'] = $rThreadData['remove_subtitles'];
		$rImportArray['transcode_profile_id'] = $rThreadData['transcode_profile_id'];
		if ($rSetEnableTranscode && $rThreadData['transcode_profile_id'] > 0) {
			$rImportArray['enable_transcode'] = 1;
		}
		if ($rThreadData['import']) {
			$rImportArray['direct_source'] = $rThreadData['direct_source'];
			$rImportArray['direct_proxy'] = $rThreadData['direct_proxy'];
		}
		$rImportArray['order'] = self::getNextOrder();
	}

	/**
	 * Find the best TMDB match for a parsed title/alt title.
	 *
	 * Searches by title (and retries without the year if nothing was found with
	 * it), then picks the candidate with the highest title similarity percentage
	 * (an exact alt-title or main-title match scores an instant 100%). If nothing
	 * matched by direct comparison but alternative_titles is enabled and the year
	 * matches, additionally checks TMDB's alternative titles.
	 *
	 * @param object $rTMDB TMDB client (searchMovie/searchTVShow/getMovieTitles/getSeriesTitles).
	 * @param array $rThreadData
	 * @param array $rSettings
	 * @param string $rTitle
	 * @param string|null $rAltTitle
	 * @param int|null $rYear
	 * @return object|null The Movie/TVShow object with the highest similarity percentage, or null.
	 */
	public static function findBestTmdbMatch($rTMDB, array $rThreadData, array $rSettings, $rTitle, $rAltTitle, $rYear) {
		$rMatches = [];
		$rSearchYear = $rYear;
		foreach (range(0, 1) as $rIgnoreYear) {
			if ($rIgnoreYear) {
				if ($rSearchYear) {
					$rSearchYear = null;
				} else {
					break;
				}
			}
			if ($rThreadData['type'] == 'movie') {
				print_r('Searching Movie: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
				$rResults = $rTMDB->searchMovie($rTitle, $rSearchYear);
			} else {
				print_r('Searching TV Show: ' . $rTitle . ' Year: ' . $rSearchYear . "\n");
				$rResults = $rTMDB->searchTVShow($rTitle, $rSearchYear);
			}
			foreach ($rResults as $rResultArr) {
				$tmdbTitles = [];

				if ($rThreadData['type'] === 'movie') {
					$tmdbTitles[] = $rResultArr->get('title');
					$tmdbTitles[] = $rResultArr->get('original_title');
				} else {
					$tmdbTitles[] = $rResultArr->get('name');
					$tmdbTitles[] = $rResultArr->get('original_name');
				}

				$tmdbTitles = array_filter($tmdbTitles);

				$rPercentage = 0;
				$rPercentageAlt = 0;

				foreach ($tmdbTitles as $tmdbTitle) {
					similar_text(self::parseTitle($rTitle), self::parseTitle($tmdbTitle), $p);
					$rPercentage = max($rPercentage, $p);

					if ($rAltTitle) {
						similar_text(self::parseTitle($rAltTitle), self::parseTitle($tmdbTitle), $pAlt);
						$rPercentageAlt = max($rPercentageAlt, $pAlt);
					}
				}

				$rReleaseDate = (string) ($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date'));
				$rReleaseYear = intval(substr($rReleaseDate, 0, 4));
				if ($rSettings['percentage_match'] <= $rPercentage || $rSettings['percentage_match'] <= $rPercentageAlt) {
					if ($rSearchYear && !in_array($rReleaseYear, range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
					} else {
						if ($rAltTitle && self::parseTitle(($rResultArr->get('title') ?: $rResultArr->get('name'))) == self::parseTitle($rAltTitle)) {
							$rMatches = [['percentage' => 100, 'data' => $rResultArr]];
							break;
						}
						foreach ($tmdbTitles as $tmdbTitle) {
							if ($rAltTitle && self::parseTitle($tmdbTitle) === self::parseTitle($rAltTitle)) {
								$rMatches = [['percentage' => 100, 'data' => $rResultArr]];
								break 2;
							}

							if (!$rAltTitle && self::parseTitle($tmdbTitle) === self::parseTitle($rTitle)) {
								$rMatches = [['percentage' => 100, 'data' => $rResultArr]];
								break 2;
							}
						}
						$rMatches[] = ['percentage' => $rPercentage, 'data' => $rResultArr];
					}
				} else {
					if ($rThreadData['alternative_titles'] && in_array($rReleaseYear, range(intval($rSearchYear) - 1, intval($rSearchYear) + 1))) {
						$rPartialMatch = false;

						foreach ($tmdbTitles as $tmdbTitle) {
							if (strpos(self::parseTitle($rTitle), self::parseTitle($tmdbTitle)) === 0) {
								$rPartialMatch = true;
								break;
							}

							if ($rAltTitle && strpos(self::parseTitle($rAltTitle), self::parseTitle($tmdbTitle)) === 0) {
								$rPartialMatch = true;
								break;
							}
						}
						if ($rPartialMatch) {
							if ($rThreadData['type'] == 'movie') {
								$rTitleData = $rTMDB->getMovieTitles($rResultArr->get('id'));
							} else {
								$rTitleData = $rTMDB->getSeriesTitles($rResultArr->get('id'));
							}
							$rAlternativeTitles = (is_array($rTitleData) && isset($rTitleData['titles']) && is_array($rTitleData['titles']) ? $rTitleData['titles'] : []);
							foreach ($rAlternativeTitles as $rAlternativeTitle) {
								if ($rAltTitle && self::parseTitle($rAlternativeTitle['title']) == self::parseTitle($rAltTitle)) {
									$rMatches = [['percentage' => 100, 'data' => $rResultArr]];
									break;
								}
								if (self::parseTitle($rAlternativeTitle['title']) != self::parseTitle($rTitle) || $rAltTitle) {
								} else {
									$rMatches = [['percentage' => 100, 'data' => $rResultArr]];
									break;
								}
							}
						}
					}
				}
			}
			if (count($rMatches) > 0) {
				break;
			}
		}
		if (count($rMatches) > 0) {
			$rMax = max(array_column($rMatches, 'percentage'));
			$rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
				return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
			}, $rMatches));
			list($rMatch) = array_values($rKeys);
			return $rMatch;
		}
		return null;
	}

	/**
	 * Build $rImportArray for a movie once a TMDB match was found: checks whether
	 * an already-imported copy should be upgraded (may stop processing via
	 * applyUpgrade(), which throws VodItemImportHalt), then fills in
	 * movie_properties/cast/genres/categories.
	 *
	 * @param object $rTMDB
	 * @param object $rMatch The matched TMDB Movie.
	 * @param array $rThreadData
	 * @param array $rSettings
	 * @param array $rWatchCategories watch_categories for both types, keyed by type.
	 * @param string $rFile
	 * @param int $rThreadType
	 * @param array $rImportArray The current (partially filled) array for the INSERT into streams.
	 * @param array $rCategoryIDs
	 * @param array $rBouquetIDs
	 * @param string|null $rLanguage
	 * @return array ['importArray' => array, 'categoryIDs' => array, 'bouquetIDs' => array]
	 */
	public static function buildMovieImportArray($rTMDB, $rMatch, array $rThreadData, array $rSettings, array $rWatchCategories, $rFile, $rThreadType, array $rImportArray, array $rCategoryIDs, array $rBouquetIDs, $rLanguage) {
		if ($rThreadData['duplicate_tmdb']) {
			$rUpgradeData = null;
		} else {
			$rUpgradeData = self::getMovie($rMatch->get('id'));
		}

		if ($rUpgradeData) {
			self::applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, 'movie', function ($rUpgradeData) use ($rMatch, $rFile) {
				file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(['id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile]));
			});
		}
		$rMovie = $rTMDB->getMovie($rMatch->get('id'));
		$rMovieData = json_decode($rMovie->getJSON(), true);
		$rMovieData['trailer'] = $rMovie->getTrailer();
		$rThumb = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'];
		$rBG = 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'];
		if ($rSettings['download_images']) {
			$rThumb = ImageUtils::downloadImage($rThumb);
			$rBG = ImageUtils::downloadImage($rBG);
		}
		$rCast = self::extractTopCast($rMovieData['credits']);
		$rDirectors = self::extractTopDirectors($rMovieData['credits']);
		$rCountry = '';
		if (isset($rMovieData['production_countries'][0]['name'])) {
			$rCountry = $rMovieData['production_countries'][0]['name'];
		}
		$rGenres = self::extractTopGenreNames($rMovieData['genres'], 3);
		$rSeconds = intval($rMovieData['runtime']) * 60;
		$rImportArray['stream_display_name'] = $rMovieData['title'];
		if (strlen($rMovieData['release_date']) > 0) {
			$rImportArray['year'] = intval(substr($rMovieData['release_date'], 0, 4));
		}
		$rImportArray['tmdb_id'] = ($rMovieData['id'] ?: null);
		$rImportArray['movie_properties'] = ['kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => [$rBG], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => $rMovieData['vote_average']];
		$rImportArray['rating'] = ($rImportArray['movie_properties']['rating'] ?: 0);
		self::applyCommonStreamSettings($rImportArray, $rThreadData);
		$rImportArray['tmdb_language'] = $rLanguage;
		if (count($rCategoryIDs) == 0 && !empty($rMovieData['genres']) && is_array($rMovieData['genres'])) {
			$rCategoryIDs = self::resolveGenreCategoryIDs($rMovieData['genres'], $rWatchCategories[1], $rThreadData['max_genres'], $rCategoryIDs);
		}
		if (count($rBouquetIDs) == 0) {
			$rBouquetIDs = self::resolveGenreBouquetIDs($rMovieData['genres'] ?? [], $rWatchCategories[1], $rThreadData['max_genres'], $rBouquetIDs);
		}
		return ['importArray' => $rImportArray, 'categoryIDs' => $rCategoryIDs, 'bouquetIDs' => $rBouquetIDs];
	}

	/**
	 * Build $rImportArray for an episode once a TMDB series match was found: checks
	 * whether an already-imported episode should be upgraded (may stop processing
	 * via applyUpgrade(), which throws VodItemImportHalt), creates/updates the series
	 * record under a file lock (one process per TMDB show id at a time), then fills
	 * in the episode title/movie_properties. May also stop processing by throwing
	 * VodItemImportHalt if no category could be resolved for a brand-new series (the
	 * lock is released before that throw).
	 *
	 * @param object $rTMDB
	 * @param object $rMatch The matched TMDB TVShow.
	 * @param array|null $rRelease The parserelease() result for the current file.
	 * @param array $rThreadData
	 * @param array $rSettings
	 * @param array $rWatchCategories watch_categories for both types, keyed by type.
	 * @param string $rFile
	 * @param int $rThreadType
	 * @param int $rTimeout How long to wait for another process's lock on this series (seconds).
	 * @param int|null $rReleaseSeason
	 * @param int|null $rReleaseEpisode
	 * @param array $rImportArray The current (partially filled) array for the INSERT into streams.
	 * @param array $rCategoryIDs
	 * @param array $rBouquetIDs
	 * @param string|null $rLanguage
	 * @param callable|null $rFetchTrailer (int $rTMDBID, ?string $rLanguage): string. Defaults to
	 *        self::getSeriesTrailer(), a real HTTP call to api.themoviedb.org with no other seam;
	 *        tests for the "brand-new series" branch inject a fake here instead.
	 * @return array ['importArray' => array, 'categoryIDs' => array, 'bouquetIDs' => array, 'series' => array|null]
	 */
	public static function buildSeriesImportArray($rTMDB, $rMatch, $rRelease, array $rThreadData, array $rSettings, array $rWatchCategories, $rFile, $rThreadType, $rTimeout, $rReleaseSeason, $rReleaseEpisode, array $rImportArray, array $rCategoryIDs, array $rBouquetIDs, $rLanguage, ?callable $rFetchTrailer = null) {
		if ($rFetchTrailer === null) {
			$rFetchTrailer = [self::class, 'getSeriesTrailer'];
		}
		$rShow = $rTMDB->getTVShow($rMatch->get('id'));
		if ($rThreadData['duplicate_tmdb']) {
			$rUpgradeData = null;
		} else {
			$rUpgradeData = self::getEpisode($rMatch->get('id'), $rReleaseSeason, $rReleaseEpisode);
		}
		if ($rUpgradeData) {
			self::applyUpgrade($rUpgradeData, $rThreadData, $rFile, $rImportArray, $rThreadType, 'episode', function ($rUpgradeData) use ($rMatch, $rReleaseSeason, $rReleaseEpisode, $rFile) {
				$rCacheData = json_decode(file_get_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache'), true);
				$rCacheData[$rReleaseSeason . '_' . $rReleaseEpisode] = ['id' => $rUpgradeData['id'], 'source' => 's:' . SERVER_ID . ':' . $rFile];
				file_put_contents(WATCH_TMP_PATH . 'series_' . $rMatch->get('id') . '.cache', json_encode($rCacheData));
			});
		}
		$rShowData = json_decode($rShow->getJSON(), true);
		$rSeries = null;
		if ($rShowData['id']) {
			$db = self::db();
			while (file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
				if ($rTimeout < time() - filemtime(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
					unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
				}
				usleep(100000);
			}
			$rFileLock = fopen(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']), 'w');
			while (!flock($rFileLock, LOCK_EX)) {
				usleep(100000);
			}
			fwrite($rFileLock, (string) time());
			$rSeasonData = [];
			foreach ($rShowData['seasons'] as $rSeason) {
				$rSeason['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rSeason['poster_path'];
				if ($rSettings['download_images']) {
					$rSeason['cover'] = ImageUtils::downloadImage($rSeason['cover'], 2);
				}
				$rSeason['cover_big'] = $rSeason['cover'];
				unset($rSeason['poster_path']);
				$rSeasonData[] = $rSeason;
			}
			$rSeries = self::getSeriesByTMDB($rShowData['id']);
			if (!$rSeries) {
				$rSeriesArray = ['title' => $rShowData['name'], 'category_id' => [], 'episode_run_time' => 0, 'tmdb_id' => $rShowData['id'], 'cover' => '', 'genre' => '', 'plot' => $rShowData['overview'], 'cast' => '', 'rating' => $rShowData['vote_average'], 'director' => '', 'release_date' => $rShowData['first_air_date'], 'last_modified' => time(), 'seasons' => $rSeasonData, 'backdrop_path' => [], 'youtube_trailer' => '', 'year' => null];
				$rSeriesArray['youtube_trailer'] = $rFetchTrailer($rShowData['id'], (!empty($rThreadData['language']) ? $rThreadData['language'] : $rSettings['tmdb_language']));
				$rSeriesArray['cover'] = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rShowData['poster_path'];
				$rSeriesArray['cover_big'] = $rSeriesArray['cover'];
				$rSeriesArray['backdrop_path'] = ['https://image.tmdb.org/t/p/w1280' . $rShowData['backdrop_path']];
				if ($rSettings['download_images']) {
					$rSeriesArray['cover'] = ImageUtils::downloadImage($rSeriesArray['cover'], 2);
					$rSeriesArray['backdrop_path'] = [ImageUtils::downloadImage($rSeriesArray['backdrop_path'][0])];
				}
				$rCast = self::extractTopCast($rShowData['credits']);
				$rSeriesArray['cast'] = implode(', ', $rCast);
				$rDirectors = self::extractTopDirectors($rShowData['credits']);
				$rSeriesArray['director'] = implode(', ', $rDirectors);
				$rGenres = self::extractTopGenreNames($rShowData['genres'], $rThreadData['max_genres']);
				if ($rShowData['first_air_date']) {
					$rSeriesArray['year'] = intval(substr($rShowData['first_air_date'], 0, 4));
				}
				$rSeriesArray['genre'] = implode(', ', $rGenres);
				$rSeriesArray['episode_run_time'] = intval($rShowData['episode_run_time'][0] ?? 0);
				if (count($rCategoryIDs) == 0) {
					$rCategoryIDs = self::resolveGenreCategoryIDs($rShowData['genres'], $rWatchCategories[2], $rThreadData['max_genres'], $rCategoryIDs);
				}
				if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
					if (is_array($rThreadData['fb_category_id'])) {
						$rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
					} else {
						$rCategoryIDs = [intval($rThreadData['fb_category_id'])];
					}
				}
				if (count($rBouquetIDs) == 0) {
					$rBouquetIDs = self::resolveGenreBouquetIDs($rShowData['genres'], $rWatchCategories[2], $rThreadData['max_genres'], $rBouquetIDs);
				}
				if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
					if (is_array($rThreadData['fb_bouquets'])) {
						$rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
					} else {
						$rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
					}
				}
				if (count($rCategoryIDs) != 0) {
					$rSeriesArray['tmdb_language'] = $rLanguage;
					$rSeriesArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
					$rPrepare = QueryHelper::prepareArray($rSeriesArray);
					$rQuery = 'INSERT INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rSeries = self::getSerie($rInsertID);
						file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
						foreach ($rBouquetIDs as $rBouquet) {
							self::addToBouquet('series', $rBouquet, $rInsertID, $rThreadData['import'], $rFile);
						}
					} else {
						$rSeries = null;
					}
				} else {
					flock($rFileLock, LOCK_UN);
					unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
					self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_NO_CATEGORY);
					throw new VodItemImportHalt();
				}
			} else {
				$db->query('UPDATE `streams_series` SET `seasons` = ? WHERE `id` = ?;', json_encode($rSeasonData, JSON_UNESCAPED_UNICODE), $rSeries['id']);
				if (!file_exists(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']))) {
					file_put_contents(WATCH_TMP_PATH . 'series_' . intval($rShowData['id']), json_encode($rSeries));
				}
			}
			flock($rFileLock, LOCK_UN);
			unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
			self::applyCommonStreamSettings($rImportArray, $rThreadData, false);
			if ($rReleaseSeason !== null && $rReleaseEpisode !== null) {
				if (is_array($rRelease['episode']) && count($rRelease['episode']) == 2) {
					$rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rRelease['episode'][0]) . '-' . sprintf('%02d', $rRelease['episode'][1]);
				} else {
					$rImportArray['stream_display_name'] = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode);
				}
				$rEpisodes = json_decode($rTMDB->getSeason($rShowData['id'], intval($rReleaseSeason))->getJSON(), true);
				foreach (($rEpisodes['episodes'] ?? []) as $rEpisode) {
					if (intval($rEpisode['episode_number']) == $rReleaseEpisode) {
						$rImage = '';
						if (strlen($rEpisode['still_path'] ?? '') > 0) {
							$rImage = 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'];
							if ($rSettings['download_images']) {
								$rImage = ImageUtils::downloadImage($rImage, 5);
							}
						}
						if (strlen($rEpisode['name'] ?? '') > 0) {
							$rImportArray['stream_display_name'] .= ' - ' . $rEpisode['name'];
						}
						$rSeconds = intval($rShowData['episode_run_time'][0] ?? 0) * 60;
						$rImportArray['movie_properties'] = ['tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rReleaseSeason];
						if (strlen($rImportArray['movie_properties']['movie_image']) == 0) {
							unset($rImportArray['movie_properties']['movie_image']);
						}
					}
				}
				if (strlen($rImportArray['stream_display_name']) == 0) {
					$rImportArray['stream_display_name'] = 'No Episode Title';
				}
			}
		}
		return ['importArray' => $rImportArray, 'categoryIDs' => $rCategoryIDs, 'bouquetIDs' => $rBouquetIDs, 'series' => $rSeries];
	}

	/**
	 * Resolve fallback category/bouquets (when genre resolution came up empty) and
	 * write the final row into `streams` (+ streams_servers, bouquets/streams_episodes,
	 * auto_encode, result event). Always throws VodItemImportHalt on every path, once the
	 * outcome has already been logged via reportResult() — there's no "return to
	 * run()" path after this call, same as applyUpgrade().
	 *
	 * @param array $rThreadData
	 * @param int $rThreadType
	 * @param string $rFile
	 * @param object|null $rMatch The matched TMDB result (for the movie cache), or null.
	 * @param array|null $rSeries The series record (for series_no/streams_episodes), or null.
	 * @param int|null $rReleaseSeason
	 * @param int|null $rReleaseEpisode
	 * @param array $rImportArray
	 * @param array $rCategoryIDs
	 * @param array $rBouquetIDs
	 * @return never
	 */
	public static function persistImport($rThreadData, $rThreadType, $rFile, $rMatch, $rSeries, $rReleaseSeason, $rReleaseEpisode, array $rImportArray, array $rCategoryIDs, array $rBouquetIDs) {
		$db = self::db();
		if ($rThreadData['type'] == 'movie') {
			if (count($rCategoryIDs) == 0 && !empty($rThreadData['fb_category_id'])) {
				if (is_array($rThreadData['fb_category_id'])) {
					$rCategoryIDs = array_map('intval', $rThreadData['fb_category_id']);
				} else {
					$rCategoryIDs = [intval($rThreadData['fb_category_id'])];
				}
			}
			if (count($rBouquetIDs) == 0 && !empty($rThreadData['fb_bouquets'])) {
				if (is_array($rThreadData['fb_bouquets'])) {
					$rBouquetIDs = array_map('intval', $rThreadData['fb_bouquets']);
				} else {
					$rBouquetIDs = json_decode($rThreadData['fb_bouquets'], true);
				}
			}
			$rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
			if (count($rCategoryIDs) == 0) {
				self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_NO_CATEGORY);
				throw new VodItemImportHalt();
			}
		} else {
			if ($rSeries) {
				$rImportArray['series_no'] = $rSeries['id'];
			} else {
				self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_NO_MATCH);
				throw new VodItemImportHalt();
			}
		}
		if ($rThreadData['subtitles']) {
			$rImportArray['movie_subtitles'] = $rThreadData['subtitles'];
		}
		$rImportArray['added'] = time();
		$rPrepare = QueryHelper::prepareArray($rImportArray);
		$rQuery = 'INSERT INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			if ($rThreadData['import']) {
				foreach ($rThreadData['servers'] as $rServerID) {
					$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, $rServerID);
				}
			} else {
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`) VALUES(?, ?, NULL);', $rInsertID, SERVER_ID);
			}
			if ($rThreadData['type'] == 'movie') {
				if ($rMatch && !$rThreadData['import']) {
					file_put_contents(WATCH_TMP_PATH . 'movie_' . $rMatch->get('id') . '.cache', json_encode(['id' => $rInsertID, 'source' => 's:' . SERVER_ID . ':' . $rFile]));
				}
				foreach ($rBouquetIDs as $rBouquet) {
					self::addToBouquet('movie', $rBouquet, $rInsertID, $rThreadData['import'], $rFile);
				}
			} else {
				$db->query('INSERT INTO `streams_episodes`(`season_num`, `series_id`, `stream_id`, `episode_num`) VALUES(?, ?, ?, ?);', $rReleaseSeason, $rSeries['id'], $rInsertID, $rReleaseEpisode);
			}
			EventDispatcher::dispatch(new StreamsChangedEvent([(int) $rInsertID]));
			if ($rThreadData['auto_encode']) {
				if ($rThreadData['import']) {
					foreach ($rThreadData['servers'] as $rServerID) {
						StreamProcess::queueMovie($rInsertID, $rServerID);
					}
				} else {
					StreamProcess::queueMovie($rInsertID);
				}
			}
			echo 'Success!' . "\n";
			self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_IMPORTED, $rInsertID);
			throw new VodItemImportHalt();
		} else {
			echo 'Insert failed!' . "\n";
			self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_INSERT_FAILED);
			throw new VodItemImportHalt();
		}
	}

	/**
	 * Import the file a `vod_import_item` payload (base64-encoded JSON thread
	 * data) describes.
	 *
	 * @param string $rPayload
	 * @param int    $rTimeout
	 * @return void
	 */
	public static function runPayload(string $rPayload, int $rTimeout = 60) {
		$rThreadData = json_decode((string) base64_decode(trim($rPayload), true), true);
		if (!is_array($rThreadData)) {
			echo "vod_import_item: payload must be base64-encoded JSON object\n";
			return;
		}
		self::run($rThreadData, $rTimeout);
	}

	/**
	 * Import one file described by $rThreadData; the outcome is dispatched as a
	 * VodImportResultEvent.
	 *
	 * @param array|null  $rThreadData
	 * @param int         $rTimeout How long to wait for another process's series lock (seconds).
	 * @param object|null $rTMDB    TMDB client; built from the settings when null (tests pass a fake).
	 */
	public static function run($rThreadData = null, $rTimeout = 60, $rTMDB = null) {
		if (!is_array($rThreadData)) {
			echo "vod_import_item: invalid thread data\n";
			return;
		}

		$rThreadData['file'] = strval($rThreadData['file'] ?? '');
		$rThreadData['directory'] = strval($rThreadData['directory'] ?? '');
		$rThreadData['import'] = !empty($rThreadData['import']);
		$rThreadData['type'] = strval($rThreadData['type'] ?? '');
		$rThreadData['extract_metadata'] = !empty($rThreadData['extract_metadata']);
		$rThreadData['ffprobe_input'] = !empty($rThreadData['ffprobe_input']);
		$rThreadData['fallback_parser'] = !empty($rThreadData['fallback_parser']);
		$rThreadData['fallback_title'] = !empty($rThreadData['fallback_title']);
		$rThreadData['disable_tmdb'] = !empty($rThreadData['disable_tmdb']);
		$rThreadData['ignore_no_match'] = !empty($rThreadData['ignore_no_match']);
		$rThreadData['alternative_titles'] = !empty($rThreadData['alternative_titles']);

		$rSettings = SettingsManager::getAll();

		if (($rThreadData['file'] === '') || (!$rThreadData['import'] && $rThreadData['directory'] === '')) {
			echo "vod_import_item: missing file or directory\n";
			return;
		}

		if (!in_array($rThreadData['type'], ['movie', 'series'], true)) {
			echo "vod_import_item: unsupported type\n";
			return;
		}

		if (strpos($rThreadData['file'], $rThreadData['directory']) === 0 || $rThreadData['import']) {
			$rWatchCategories = (is_array($rThreadData['watch_categories'] ?? null) ? $rThreadData['watch_categories'] : []);
			if (!isset($rWatchCategories[1]) || !is_array($rWatchCategories[1])) {
				$rWatchCategories[1] = [];
			}
			if (!isset($rWatchCategories[2]) || !is_array($rWatchCategories[2])) {
				$rWatchCategories[2] = [];
			}
			$rLanguage = !empty($rThreadData['language']) ? (string) $rThreadData['language'] : null;
			$rReleaseSeason = null;
			$rReleaseEpisode = null;
			$rYear = null;

			$rTMDB ??= TmdbApiService::createClient((string) ($rSettings['tmdb_api_key'] ?? ''), $rLanguage);
			if ($rThreadData['type'] != 'movie') {
				$rThreadData['extract_metadata'] = false;
			}
			$rImportArray = QueryHelper::verifyPostTable('streams');
			if (!is_array($rImportArray)) {
				echo "vod_import_item: failed to prepare import schema\n";
				return;
			}
			$rImportArray['type'] = ['movie' => 2, 'series' => 5][$rThreadData['type']];
			$rThreadType = ['movie' => 1, 'series' => 2][$rThreadData['type']];
			$rFile = $rThreadData['file'];
			if ($rThreadData['import']) {
				$rImportArray['stream_source'] = json_encode([$rFile], JSON_UNESCAPED_UNICODE);
			} else {
				$rImportArray['stream_source'] = json_encode(['s:' . SERVER_ID . ':' . $rFile], JSON_UNESCAPED_UNICODE);
			}
			if ($rThreadData['target_container'] != 'auto' && $rThreadData['target_container']) {
				$rImportArray['target_container'] = $rThreadData['target_container'];
			} else {
				$rImportArray['target_container'] = pathinfo(explode('?', $rFile)[0])['extension'];
			}
			if (empty($rImportArray['target_container'])) {
				$rImportArray['target_container'] = 'mp4';
			}
			$rSourceData = null;
			if ($rThreadData['ffprobe_input'] || $rThreadData['extract_metadata']) {
				$rSourceData = self::checksource($rFile);
			}
			if (!$rThreadData['ffprobe_input'] || isset($rSourceData['streams'])) {
				$rMatch = $rPaths = null;
				$rMetaMatch = false;
				if ($rThreadData['extract_metadata'] && isset($rSourceData['format']) && !empty($rSourceData['tags']['title'])) {
					if (!empty($rSourceData['tags']['date'])) {
						$rYear = (intval(explode('-', $rSourceData['tags']['date'])[0]));
					}
					$rPaths = [$rSourceData['tags']['title']];
					$rMetaMatch = true;
				}
				if (!$rPaths) {
					if ($rThreadData['fallback_title']) {
						$rPaths = [pathinfo($rFile)['filename'], basename(pathinfo($rFile)['dirname'])];
					} else {
						$rPaths = [pathinfo($rFile)['filename']];
					}
					$rMetaMatch = false;
				}
				foreach ($rPaths as $rFilename) {
					echo 'Scanning: ' . $rFilename . "\n";
					$rTitle = null;
					$rAltTitle = null;
					if ($rThreadData['import']) {
						$rFilename = $rThreadData['title'];
					}
					if ($rThreadData['fallback_parser'] && !$rThreadData['disable_tmdb'] && !$rMetaMatch) {
						$rParseTypes = [$rSettings['parse_type'], ($rSettings['parse_type'] == 'guessit' ? 'ptn' : 'guessit')];
					} else {
						$rParseTypes = [$rSettings['parse_type']];
					}
					foreach ($rParseTypes as $rParseType) {
						if ($rThreadData['disable_tmdb'] || $rMetaMatch) {
						} else {
							$rRelease = self::parserelease($rFilename, $rParseType);
							$rTitle = $rRelease['title'];
							if (isset($rRelease['excess'])) {
								// Strip the excess token as a WHOLE WORD — never
								// trim($title, $excess): its 2nd arg is a char-mask,
								// so trim('Marshals…', 'MULTI') eats the leading 'M'
								// and yields 'arshals…', breaking the TMDb search.
								$rExcess = is_array($rRelease['excess']) ? ($rRelease['excess'][0] ?? '') : $rRelease['excess'];
								if ($rExcess !== '') {
									$rTitle = preg_replace('/\b' . preg_quote((string) $rExcess, '/') . '\b/u', ' ', $rTitle);
									$rTitle = trim(preg_replace('/\s+/u', ' ', $rTitle));
								}
							}
							if (isset($rRelease['group'])) {
								$rAltTitle = $rTitle . '-' . $rRelease['group'];
							} else {
								if (isset($rRelease['alternative_title'])) {
									$rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
								}
							}
							if (isset($rRelease['year'])) {
								if (!$rYear) {
									$rYear = intval($rRelease['year']);
								}
							}

							if ($rThreadData['type'] != 'movie') {
								if ($rReleaseSeason == null && isset($rRelease['season'])) {
									$rReleaseSeason = $rRelease['season'];
								}
								if ($rReleaseEpisode == null && isset($rRelease['episode'])) {
									if (is_array($rRelease['episode'])) {
										$rReleaseEpisode = $rRelease['episode'][0];
									} else {
										$rReleaseEpisode = $rRelease['episode'];
									}
								}
							}
						}
						if (!($rThreadData['type'] == 'series' && ($rReleaseSeason === null || $rReleaseEpisode === null))) {
							if (!$rTitle) {
								$rTitle = $rFilename;
							}
							echo 'Title: ' . $rTitle . "\n";
							if (!$rThreadData['disable_tmdb']) {
								$rFoundMatch = self::findBestTmdbMatch($rTMDB, $rThreadData, $rSettings, $rTitle, $rAltTitle, $rYear);
								if ($rFoundMatch) {
									$rMatch = $rFoundMatch;
								}
							}
							if ($rMatch) {
								break 2;
							}
						} else {
							self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_NO_MATCH);
							return;
						}
					}
				}
				if (!$rMatch && !$rThreadData['ignore_no_match']) {
					echo 'No match!' . "\n";
					self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_NO_MATCH);
					return;
				}
				$rBouquetIDs = [];
				$rCategoryIDs = [];
				if (!empty($rThreadData['category_id'])) {
					if (is_array($rThreadData['category_id'])) {
						$rCategoryIDs = array_map('intval', $rThreadData['category_id']);
					} else {
						$rCategoryIDs = [intval($rThreadData['category_id'])];
					}
				}
				if (!empty($rThreadData['bouquets'])) {
					if (is_array($rThreadData['bouquets'])) {
						$rBouquetIDs = array_map('intval', $rThreadData['bouquets']);
					} else {
						$rBouquetIDs = json_decode($rThreadData['bouquets'], true);
					}
				}
				try {
					if ($rMatch) {
						if ($rThreadData['type'] == 'movie') {
							$rBuilt = self::buildMovieImportArray($rTMDB, $rMatch, $rThreadData, $rSettings, $rWatchCategories, $rFile, $rThreadType, $rImportArray, $rCategoryIDs, $rBouquetIDs, $rLanguage);
							$rImportArray = $rBuilt['importArray'];
							$rCategoryIDs = $rBuilt['categoryIDs'];
							$rBouquetIDs = $rBuilt['bouquetIDs'];
						} else {
							$rBuilt = self::buildSeriesImportArray($rTMDB, $rMatch, $rRelease ?? null, $rThreadData, $rSettings, $rWatchCategories, $rFile, $rThreadType, $rTimeout, $rReleaseSeason, $rReleaseEpisode, $rImportArray, $rCategoryIDs, $rBouquetIDs, $rLanguage);
							$rImportArray = $rBuilt['importArray'];
							$rCategoryIDs = $rBuilt['categoryIDs'];
							$rBouquetIDs = $rBuilt['bouquetIDs'];
							$rSeries = $rBuilt['series'];
						}
					} else {
						if ($rThreadData['type'] == 'movie') {
							$rImportArray['stream_display_name'] = $rTitle;
							if ($rYear) {
								$rImportArray['year'] = $rYear;
							}
						} else {
							if ($rReleaseSeason !== null && $rReleaseEpisode !== null) {
								$rImportArray['stream_display_name'] = $rTitle . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode) . ' - ';
							}
						}
						self::applyCommonStreamSettings($rImportArray, $rThreadData);
						$rImportArray['tmdb_language'] = $rLanguage;
					}
					self::persistImport($rThreadData, $rThreadType, $rFile, $rMatch, $rSeries ?? null, $rReleaseSeason, $rReleaseEpisode, $rImportArray, $rCategoryIDs, $rBouquetIDs);
				} catch (VodItemImportHalt) {
					// applyUpgrade()/buildSeriesImportArray()/persistImport() already
					// logged the outcome via reportResult() before throwing — this
					// mirrors the exit() they used to call (vod_import_item always runs
					// as its own process, so returning here ends it the same way).
					return;
				}
			} else {
				echo 'File is broken!' . "\n";
				self::reportResult($rThreadType, $rFile, VodImportResultEvent::STATUS_BROKEN_FILE);
				return;
			}
		} else {
			echo 'Incorrect root directory!';
			return;
		}
	}
}
