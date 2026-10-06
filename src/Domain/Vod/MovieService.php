<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Events\Vod\VodImportedEvent;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\ImageUtils;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MovieService — movie service
 *
 * @package XC_VM_Domain_Vod
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MovieService {
	use DatabaseAware;

	/**
	 * Create or update a movie from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processMovie', $rData)) {
			set_time_limit(0);
			ini_set('mysql.connect_timeout', 0);
			ini_set('max_execution_time', 0);
			ini_set('default_socket_timeout', 0);

			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_movie')) {
					// The edit starts from the stream as stored: the row cleaner's
					// escaping would be written back into every field not sent.
					$db->query('SELECT * FROM `streams` WHERE `id` = ?;', intval($rData['edit']));
					$rArray = AdminHelpers::overwriteData($db->get_raw_row(), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_movie')) {
					$rArray = QueryHelper::verifyPostTable('streams', $rData);
					$rArray['added'] = time();
					$rArray['type'] = 2;
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if ((string) ($rData['movie_subtitles'] ?? '') !== '') {
				$rSplit = explode(':', $rData['movie_subtitles']);
				$rArray['movie_subtitles'] = ['files' => [$rSplit[2]], 'names' => ['Subtitles'], 'charset' => ['UTF-8'], 'location' => intval($rSplit[1])];
			} else {
				$rArray['movie_subtitles'] = null;
			}

			if (0 < $rArray['transcode_profile_id']) {
				$rArray['enable_transcode'] = 1;
			}

			if (!is_numeric($rArray['year']) || $rArray['year'] < 1900 || intval(date('Y') + 1) < $rArray['year']) {
				$rArray['year'] = null;
			}

			foreach (['read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles'] as $rKey) {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}

			if (isset($rData['restart_on_edit'])) {
				$rRestart = true;
			} else {
				$rRestart = false;
			}

			$rReview = false;
			$rImportStreams = [];

			if (isset($rData['review'])) {
				TMDbService::requireLibrary();

				if (SettingsManager::getString('tmdb_language') !== '') {
					$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'), SettingsManager::getString('tmdb_language'));
				} else {
					$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'));
				}

				$rReview = true;

				foreach ($rData['review'] as $rImportStream) {
					if ($rImportStream['tmdb_id']) {
						$rMovie = $rTMDB->getMovie($rImportStream['tmdb_id']);
						if ($rMovie) {
							$rMovieData = json_decode($rMovie->getJSON(), true);
							$rMovieData['trailer'] = $rMovie->getTrailer();
							$rThumb = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'];
							$rBG = 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'];
							if (SettingsManager::getBool('download_images')) {
								$rThumb = ImageUtils::downloadImage($rThumb, 2);
								$rBG = ImageUtils::downloadImage($rBG);
							}
							$rCast = [];
							foreach ($rMovieData['credits']['cast'] as $rMember) {
								if (count($rCast) < 5) {
									$rCast[] = $rMember['name'];
								}
							}
							$rDirectors = [];
							foreach ($rMovieData['credits']['crew'] as $rMember) {
								if (count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing')) {
									$rDirectors[] = $rMember['name'];
								}
							}
							$rCountry = '';
							if (isset($rMovieData['production_countries'][0]['name'])) {
								$rCountry = $rMovieData['production_countries'][0]['name'];
							}
							$rGenres = [];
							foreach ($rMovieData['genres'] as $rGenre) {
								if (count($rGenres) < 3) {
									$rGenres[] = $rGenre['name'];
								}
							}
							$rSeconds = intval($rMovieData['runtime']) * 60;
							if ((string) $rMovieData['release_date'] !== '') {
								$rYear = intval(substr($rMovieData['release_date'], 0, 4));
							} else {
								$rYear = null;
							}
							$rImportStream['movie_properties'] = ['kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'year' => $rYear, 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => [$rBG], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => $rMovieData['vote_average']];
						}
					}

					unset($rImportStream['tmdb_id']);
					$rImportStream['async'] = false;
					$rImportStream['target_container'] = pathinfo(explode('?', $rImportStream['stream_source'][0])[0])['extension'];

					if (empty($rImportStream['target_container'])) {
						$rImportStream['target_container'] = 'mp4';
					}

					$rImportStreams[] = $rImportStream;
				}
			} else {
				$rImportStreams = [];

				if (!empty($_FILES['m3u_file']['tmp_name'])) {
					if (Authorization::check('adv', 'import_movies')) {
						$rStreamDatabase = [];

						$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

						foreach ($db->get_rows() as $rRow) {
							foreach (json_decode($rRow['stream_source'], true) as $rSource) {
								if ((string) $rSource !== '') {
									$rStreamDatabase[] = $rSource;
								}
							}
						}
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
							if (!in_array($rResult['url'], $rStreamDatabase)) {
								$rPathInfo = pathinfo(explode('?', $rResult['url'])[0]);
								$rImportArray = ['stream_source' => [$rResult['url']], 'stream_icon' => ($rResult['tvg-logo'] ?: ''), 'stream_display_name' => ($rResult['name'] ?: ''), 'movie_properties' => [], 'async' => true, 'target_container' => $rPathInfo['extension']];
								$rImportStreams[] = $rImportArray;
							}
						}
					} else {
						exit();
					}
				} else {
					if (!empty($rData['import_folder'])) {
						if (Authorization::check('adv', 'import_movies')) {
							$rStreamDatabase = [];

							$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

							foreach ($db->get_rows() as $rRow) {
								foreach (json_decode($rRow['stream_source'], true) as $rSource) {
									if ((string) $rSource !== '') {
										$rStreamDatabase[] = $rSource;
									}
								}
							}
							$rParts = explode(':', $rData['import_folder']);

							if (is_numeric($rParts[1])) {
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
										$rImportArray = ['stream_source' => [$rFilePath], 'stream_icon' => '', 'stream_display_name' => $rPathInfo['filename'], 'movie_properties' => [], 'async' => true, 'target_container' => $rPathInfo['extension']];
										$rImportStreams[] = $rImportArray;
									}
								}
							}
						} else {
							exit();
						}
					} else {
						$rImportArray = ['stream_source' => [$rData['stream_source']], 'stream_icon' => $rArray['stream_icon'], 'stream_display_name' => $rArray['stream_display_name'], 'movie_properties' => [], 'async' => false, 'target_container' => $rArray['target_container']];

						if ((string) $rData['tmdb_id'] !== '') {
							$rTMDBURL = 'https://www.themoviedb.org/movie/' . $rData['tmdb_id'];
						} else {
							$rTMDBURL = '';
						}

						if (SettingsManager::getBool('download_images')) {
							$rData['movie_image'] = ImageUtils::downloadImage($rData['movie_image'], 2);
							$rData['backdrop_path'] = ImageUtils::downloadImage($rData['backdrop_path']);
						}

						$rSeconds = intval($rData['episode_run_time']) * 60;
						$rImportArray['movie_properties'] = ['kinopoisk_url' => $rTMDBURL, 'tmdb_id' => $rData['tmdb_id'], 'name' => $rArray['stream_display_name'], 'o_name' => $rArray['stream_display_name'], 'cover_big' => $rData['movie_image'], 'movie_image' => $rData['movie_image'], 'release_date' => $rData['release_date'], 'episode_run_time' => $rData['episode_run_time'], 'youtube_trailer' => $rData['youtube_trailer'], 'director' => $rData['director'], 'actors' => $rData['cast'], 'cast' => $rData['cast'], 'description' => $rData['plot'], 'plot' => $rData['plot'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rData['country'], 'genre' => $rData['genre'], 'backdrop_path' => [$rData['backdrop_path']], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => [], 'audio' => [], 'bitrate' => 0, 'rating' => $rData['rating']];

						if (strlen($rImportArray['movie_properties']['backdrop_path'][0]) == 0) {
							unset($rImportArray['movie_properties']['backdrop_path']);
						}

						if (($rData['movie_symlink'] ?? false) || ($rData['direct_proxy'] ?? false)) {
							$rExtension = pathinfo(explode('?', $rData['stream_source'])[0])['extension'];

							if ($rExtension) {
								$rImportArray['target_container'] = $rExtension;
							} else {
								if (!$rImportArray['target_container']) {
									$rImportArray['target_container'] = 'mp4';
								}
							}
						}

						$rImportStreams[] = $rImportArray;
					}
				}
			}

			if (0 < count($rImportStreams)) {
				$rBouquetCreate = [];
				$rCategoryCreate = [];

				if (!$rReview) {
					foreach (json_decode($rData['bouquet_create_list'] ?? '[]', true) ?? [] as $rBouquet) {
						$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rBouquetID = $db->last_insert_id();
							$rBouquetCreate[$rBouquet] = $rBouquetID;
						}
					}
					foreach (json_decode($rData['category_create_list'] ?? '[]', true) ?? [] as $rCategory) {
						$rPrepare = QueryHelper::prepareArray(['category_type' => 'movie', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rCategoryID = $db->last_insert_id();
							$rCategoryCreate[$rCategory] = $rCategoryID;
						}
					}
				}

				$rRestartIDs = [];

				foreach ($rImportStreams as $rImportStream) {
					$rImportArray = $rArray;

					if ($rReview) {
						$rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rImportStream['category_id'])) . ']';
						$rBouquets = array_map('intval', $rImportStream['bouquets']);
						unset($rImportStream['bouquets']);
					} else {
						$rBouquets = [];

						foreach ($rData['bouquets'] ?? [] as $rBouquet) {
							if (isset($rBouquetCreate[$rBouquet])) {
								$rBouquets[] = $rBouquetCreate[$rBouquet];
							} else {
								if (is_numeric($rBouquet)) {
									$rBouquets[] = intval($rBouquet);
								}
							}
						}
						$rCategories = [];

						foreach ($rData['category_id'] ?? [] as $rCategory) {
							if (isset($rCategoryCreate[$rCategory])) {
								$rCategories[] = $rCategoryCreate[$rCategory];
							} else {
								if (is_numeric($rCategory)) {
									$rCategories[] = intval($rCategory);
								}
							}
						}
						$rImportArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
					}

					if (isset($rImportArray['movie_properties']['rating'])) {
						$rImportArray['rating'] = $rImportArray['movie_properties']['rating'];
					}

					foreach (array_keys($rImportStream) as $rKey) {
						$rImportArray[$rKey] = $rImportStream[$rKey];
					}

					if (!isset($rData['edit'])) {
						$rImportArray['order'] = StreamRepository::getNextOrder();
					}

					$rImportArray['tmdb_id'] = ($rImportStream['movie_properties']['tmdb_id'] ?: null);
					$rSync = $rImportArray['async'];
					unset($rImportArray['async']);
					$rPrepare = QueryHelper::prepareArray($rImportArray);
					$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

					if ($db->query($rQuery, ...$rPrepare['data'])) {
						$rInsertID = $db->last_insert_id();
						$rStreamExists = [];

						if (isset($rData['edit'])) {
							$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);
							foreach ($db->get_rows() as $rRow) {
								$rStreamExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
							}
						}

						$rPath = $rImportArray['stream_source'][0];

						if (substr($rPath, 0, 2) == 's:') {
							$rSplit = explode(':', $rPath, 3);
							$rPath = $rSplit[2];
						}

						// Notify modules (e.g. watch) that a VOD item was imported from this path,
						// instead of core touching the module-owned watch_logs table directly.
						EventDispatcher::dispatch(new VodImportedEvent((int) $rInsertID, (string) $rPath, 1));
						$rStreamsAdded = [];
						$rServerTree = json_decode($rData['server_tree_data'], true);

						foreach ($rServerTree as $rServer) {
							if ($rServer['parent'] != '#') {
								$rServerID = intval($rServer['id']);
								$rStreamsAdded[] = $rServerID;
								if (!isset($rStreamExists[$rServerID])) {
									$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `on_demand`) VALUES(?, ?, 0);', $rInsertID, $rServerID);
								}
							}
						}

						foreach ($rStreamExists as $rServerID => $rDBID) {
							if (!in_array($rServerID, $rStreamsAdded)) {
								StreamRepository::deleteStream($rInsertID, $rServerID, true, false);
							}
						}

						if ($rRestart) {
							$rRestartIDs[] = $rInsertID;
						}

						foreach ($rBouquets as $rBouquet) {
							BouquetService::addItems('movie', $rBouquet, $rInsertID);
						}

						foreach (BouquetService::getAllSimple() as $rBouquet) {
							if (!in_array($rBouquet['id'], $rBouquets)) {
								BouquetService::removeItems('movie', $rBouquet['id'], $rInsertID);
							}
						}

						if ($rSync) {
							$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(1, ?, 0);', $rInsertID);
						}

						StreamProcess::updateStream($rInsertID);
						EventDispatcher::dispatch(new StreamsChangedEvent([(int) $rInsertID]));
					} else {
						foreach ($rBouquetCreate as $rID) {
							$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
						}

						foreach ($rCategoryCreate as $rID) {
							$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
						}

						return ['status' => STATUS_FAILURE, 'data' => $rData];
					}
				}

				if ($rRestart) {
					ApiClient::request(['action' => 'vod', 'sub' => 'start', 'stream_ids' => $rRestartIDs]);
				}

				return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
			}
			return ['status' => STATUS_NO_SOURCES, 'data' => $rData];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Import movies (e.g. from a source/\TMDB) into the catalog.
	 *
	 * @param array $rData Import payload (sources, category, options).
	 * @return array Import result.
	 */
	public static function import(array $rData) {
		$db = self::db();
		if (Authorization::check('adv', 'import_movies')) {
			if (InputValidator::validate('importMovies', $rData)) {
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

				if (isset($rData['disable_tmdb'])) {
					$rDisableTMDB = true;
				} else {
					$rDisableTMDB = false;
				}

				if (isset($rData['ignore_no_match'])) {
					$rIgnoreMatch = true;
				} else {
					$rIgnoreMatch = false;
				}

				$rStreamDatabase = [];
				$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 2;');

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
						if (is_numeric($rParts[1])) {
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

				$rMovieCategories = array_keys(CategoryService::getAllByType('movie'));

				if (0 < count($rImportStreams)) {
					$rBouquets = [];

					$rBouquetCreateList = json_decode(($rData['bouquet_create_list'] ?? '[]'), true);
					if (!is_array($rBouquetCreateList)) {
						$rBouquetCreateList = [];
					}

					foreach ($rBouquetCreateList as $rBouquet) {
						$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rBouquets[] = $db->last_insert_id();
						}
					}

					$rSelectedBouquets = (isset($rData['bouquets']) && is_array($rData['bouquets']) ? $rData['bouquets'] : []);

					foreach ($rSelectedBouquets as $rBouquetID) {
						if (is_numeric($rBouquetID) && in_array($rBouquetID, array_keys(BouquetService::getAll()))) {
							$rBouquets[] = intval($rBouquetID);
						}
					}
					unset($rData['bouquets'], $rData['bouquet_create_list']);

					$rCategories = [];

					$rCategoryCreateList = json_decode(($rData['category_create_list'] ?? '[]'), true);
					if (!is_array($rCategoryCreateList)) {
						$rCategoryCreateList = [];
					}

					foreach ($rCategoryCreateList as $rCategory) {
						$rPrepare = QueryHelper::prepareArray(['category_type' => 'movie', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if ($db->query($rQuery, ...$rPrepare['data'])) {
							$rCategories[] = $db->last_insert_id();
						}
					}

					$rSelectedCategories = (isset($rData['category_id']) && is_array($rData['category_id']) ? $rData['category_id'] : []);

					foreach ($rSelectedCategories as $rCategoryID) {
						if (is_numeric($rCategoryID) && in_array($rCategoryID, $rMovieCategories)) {
							$rCategories[] = intval($rCategoryID);
						}
					}
					unset($rData['category_id'], $rData['category_create_list']);

					$rServerIDs = [];

					$rServerTreeData = json_decode(($rData['server_tree_data'] ?? '[]'), true);
					if (!is_array($rServerTreeData)) {
						$rServerTreeData = [];
					}

					foreach ($rServerTreeData as $rServer) {
						if (is_array($rServer) && ($rServer['parent'] ?? '#') != '#') {
							$rServerID = intval($rServer['id'] ?? 0);
							if (0 < $rServerID) {
								$rServerIDs[] = $rServerID;
							}
						}
					}
					$rItems = [];
					foreach ($rImportStreams as $rImportStream) {
						$rItems[] = ['import' => true, 'type' => 'movie', 'title' => $rImportStream['title'], 'file' => $rImportStream['url'], 'subtitles' => [], 'servers' => $rServerIDs, 'fb_category_id' => $rCategories, 'fb_bouquets' => $rBouquets, 'disable_tmdb' => $rDisableTMDB, 'ignore_no_match' => $rIgnoreMatch, 'bouquets' => [], 'category_id' => [], 'language' => SettingsManager::getString('tmdb_language'), 'read_native' => $rData['read_native'], 'movie_symlink' => $rData['movie_symlink'], 'remove_subtitles' => $rData['remove_subtitles'], 'direct_source' => $rData['direct_source'], 'direct_proxy' => $rData['direct_proxy'], 'auto_encode' => $rRestart, 'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false, 'transcode_profile_id' => $rData['transcode_profile_id'], 'target_container' => $rImportStream['container'], 'max_genres' => SettingsManager::getInt('max_genres'), 'duplicate_tmdb' => true];
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
	 * Bulk delete selected movies.
	 *
	 * @param array $rData Selected movie ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rMovies = json_decode($rData['movies'], true);
		StreamRepository::deleteStreams($rMovies, true);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Apply bulk edits to selected movies.
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

		if (isset($rData['c_movie_symlink'])) {
			if (isset($rData['movie_symlink'])) {
				$rArray['movie_symlink'] = 1;
			} else {
				$rArray['movie_symlink'] = 0;
			}
		}

		if (isset($rData['c_direct_source'])) {
			if (isset($rData['direct_source'])) {
				$rArray['direct_source'] = 1;
			} else {
				$rArray['direct_source'] = 0;
				$rArray['direct_proxy'] = 0;
			}
		}

		if (isset($rData['c_direct_proxy'])) {
			if (isset($rData['direct_proxy'])) {
				$rArray['direct_proxy'] = 1;
				$rArray['direct_source'] = 1;
			} else {
				$rArray['direct_proxy'] = 0;
			}
		}

		if (isset($rData['c_read_native'])) {
			if (isset($rData['read_native'])) {
				$rArray['read_native'] = 1;
			} else {
				$rArray['read_native'] = 0;
			}
		}

		if (isset($rData['c_remove_subtitles'])) {
			if (isset($rData['remove_subtitles'])) {
				$rArray['remove_subtitles'] = 1;
			} else {
				$rArray['remove_subtitles'] = 0;
			}
		}

		if (isset($rData['c_target_container'])) {
			$rArray['target_container'] = $rData['target_container'];
		}

		if (isset($rData['c_transcode_profile_id'])) {
			$rArray['transcode_profile_id'] = $rData['transcode_profile_id'];

			if (0 < $rArray['transcode_profile_id']) {
				$rArray['enable_transcode'] = 1;
			} else {
				$rArray['enable_transcode'] = 0;
			}
		}

		$rStreamIDs = AdminHelpers::confirmIDs(json_decode($rData['streams'], true));

		if (0 < count($rStreamIDs)) {
			$rCategoryMap = [];

			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], ['ADD', 'DEL'])) {
				$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: []);
				}
			}

			$rDeleteServers = $rQueueMovies = $rProcessServers = $rStreamExists = [];
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
			}
			$rBouquets = BouquetService::getAllSimple();
			$rAddBouquet = $rDelBouquet = [];
			$rAddQuery = '';

			foreach ($rStreamIDs as $rStreamID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id']);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rStreamID] ?: []) as $rCategoryID) {
							if (!in_array($rCategoryID, $rCategories)) {
								$rCategories[] = $rCategoryID;
							}
						}
					} elseif ($rData['category_id_type'] == 'DEL') {
						$rNewCategories = $rCategoryMap[$rStreamID];

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
					$rPrepare['data'][] = $rStreamID;
					$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_server_tree'])) {
					$rStreamsAdded = [];
					$rServerTree = json_decode($rData['server_tree_data'], true);

					foreach ($rServerTree as $rServer) {
						if ($rServer['parent'] != '#') {
							$rServerID = intval($rServer['id']);

							if (in_array($rData['server_type'], ['ADD', 'SET'])) {
								$rStreamsAdded[] = $rServerID;

								if (!isset($rStreamExists[$rStreamID][$rServerID])) {
									$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . '),';
									$rProcessServers[$rStreamID][] = $rServerID;
								}
							} elseif (isset($rStreamExists[$rStreamID][$rServerID])) {
								$rDeleteServers[$rServerID][] = $rStreamID;
							}
						}
					}

					if ($rData['server_type'] == 'SET') {
						foreach ($rStreamExists[$rStreamID] as $rServerID => $rDBID) {
							if (!in_array($rServerID, $rStreamsAdded)) {
								$rDeleteServers[$rServerID][] = $rStreamID;

								if (($rKey = array_search($rServerID, $rProcessServers[$rStreamID])) !== false) {
									unset($rProcessServers[$rStreamID][$rKey]);
								}
							}
						}
					}
				}

				if (isset($rData['c_bouquets'])) {
					if ($rData['bouquets_type'] == 'SET') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rStreamID;
						}

						foreach ($rBouquets as $rBouquet) {
							if (!in_array($rBouquet['id'], $rData['bouquets'])) {
								$rDelBouquet[$rBouquet['id']][] = $rStreamID;
							}
						}
					} elseif ($rData['bouquets_type'] == 'ADD') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rStreamID;
						}
					} elseif ($rData['bouquets_type'] == 'DEL') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rDelBouquet[$rBouquet][] = $rStreamID;
						}
					}
				}

				if (isset($rData['reencode_on_edit'])) {
					foreach ($rProcessServers[$rStreamID] as $rServerID) {
						$rQueueMovies[$rServerID][] = $rStreamID;
					}
				}
			}

			foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
				StreamRepository::deleteStreamsByServer($rDeleteIDs, $rServerID, true);
			}

			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				BouquetService::addItems('movie', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				BouquetService::removeItems('movie', $rBouquetID, $rRemIDs);
			}

			if (!empty($rAddQuery)) {
				$rAddQuery = rtrim($rAddQuery, ',');
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`) VALUES ' . $rAddQuery . ';');
			}

			StreamProcess::updateStreams($rStreamIDs);
			EventDispatcher::dispatch(new StreamsChangedEvent(array_values(array_map('intval', $rStreamIDs))));

			if (isset($rData['reencode_on_edit'])) {
				foreach ($rQueueMovies as $rServerID => $rQueueIDs) {
					StreamProcess::queueMovies($rQueueIDs, $rServerID);
				}
			}

			if (isset($rData['reprocess_tmdb'])) {
				StreamProcess::refreshMovies($rStreamIDs, 1);
			}
		}

		return ['status' => STATUS_SUCCESS];
	}

	// ──────────── Из MovieRepository ────────────

	/**
	 * Get movies similar to the given one (paged).
	 *
	 * @param int $rID   Movie id.
	 * @param int $rPage Result page.
	 * @return array Similar movies.
	 */
	public static function getSimilar(int $rID, int $rPage = 1) {
		TMDbService::requireLibrary();

		if (SettingsManager::getString('tmdb_language') !== '') {
			$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'), SettingsManager::getString('tmdb_language'));
		} else {
			$rTMDB = new \TMDB(SettingsManager::getString('tmdb_api_key'));
		}

		return json_decode(json_encode($rTMDB->getSimilarMovies($rID, $rPage)), true);
	}

	/**
	 * Send delete signal for movie files on specified servers.
	 */
	public static function deleteFile($rServerIDs, $rID) {
		$db = self::db();
		if (!is_array($rServerIDs)) {
			$rServerIDs = [$rServerIDs];
		}

		foreach ($rServerIDs as $rServerID) {
			SignalDispatcher::cache(intval($rServerID), ['type' => 'delete_vod', 'id' => $rID], false, false, $db);
		}

		return true;
	}
}
