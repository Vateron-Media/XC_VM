<?php

class SeriesService {
	public static function process($rSettings, $rData) {
		global $db;
		if (InputValidator::validate('processSeries', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_series')) {
					$rArray = overwriteData(getSerie($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_series')) {
					$rArray = verifyPostTable('streams_series', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (!CoreUtilities::$rSettings['download_images']) {
			} else {
				$rData['cover'] = CoreUtilities::downloadImage($rData['cover'], 2);
				$rData['backdrop_path'] = CoreUtilities::downloadImage($rData['backdrop_path']);
			}

			if (strlen($rData['backdrop_path']) == 0) {
				$rArray['backdrop_path'] = array();
			} else {
				$rArray['backdrop_path'] = array($rData['backdrop_path']);
			}

			$rArray['last_modified'] = time();
			$rArray['cover'] = $rData['cover'];
			$rArray['cover_big'] = $rData['cover'];
			$rBouquetCreate = array();

			foreach (json_decode($rData['bouquet_create_list'], true) as $rBouquet) {
				$rPrepare = prepareArray(array('bouquet_name' => $rBouquet, 'bouquet_channels' => array(), 'bouquet_movies' => array(), 'bouquet_series' => array(), 'bouquet_radios' => array()));
				$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (!$db->query($rQuery, ...$rPrepare['data'])) {
				} else {
					$rBouquetID = $db->last_insert_id();
					$rBouquetCreate[$rBouquet] = $rBouquetID;
				}
			}
			$rCategoryCreate = array();

			foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
				$rPrepare = prepareArray(array('category_type' => 'series', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
				$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if (!$db->query($rQuery, ...$rPrepare['data'])) {
				} else {
					$rCategoryID = $db->last_insert_id();
					$rCategoryCreate[$rCategory] = $rCategoryID;
				}
			}
			$rBouquets = array();

			foreach ($rData['bouquets'] as $rBouquet) {
				if (isset($rBouquetCreate[$rBouquet])) {
					$rBouquets[] = $rBouquetCreate[$rBouquet];
				} else {
					if (!is_numeric($rBouquet)) {
					} else {
						$rBouquets[] = intval($rBouquet);
					}
				}
			}
			$rCategories = array();

			foreach ($rData['category_id'] as $rCategory) {
				if (isset($rCategoryCreate[$rCategory])) {
					$rCategories[] = $rCategoryCreate[$rCategory];
				} else {
					if (!is_numeric($rCategory)) {
					} else {
						$rCategories[] = intval($rCategory);
					}
				}
			}
			$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
			$rPrepare = prepareArray($rArray);
			$rQuery = 'REPLACE INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				SeriesService::queueRefresh($rInsertID);

				foreach ($rBouquets as $rBouquet) {
					addToBouquet('series', $rBouquet, $rInsertID);
				}

				foreach (BouquetService::getAllSimple() as $rBouquet) {
					if (in_array($rBouquet['id'], $rBouquets)) {
					} else {
						removeFromBouquet('series', $rBouquet['id'], $rInsertID);
					}
				}

				return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
			} else {
				foreach ($rBouquetCreate as $rBouquet => $rID) {
					$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
				}

				foreach ($rCategoryCreate as $rCategory => $rID) {
					$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	public static function import($rData) {
		global $db;
		if (Authorization::check('adv', 'import_movies')) {
			if (InputValidator::validate('importSeries', $rData)) {
				$rPostData = $rData;

				foreach (array('read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles') as $rKey) {
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

				$rStreamDatabase = array();
				$db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 5;');

				foreach ($db->get_rows() as $rRow) {
					foreach (json_decode($rRow['stream_source'], true) as $rSource) {
						if (0 >= strlen($rSource)) {
						} else {
							$rStreamDatabase[] = $rSource;
						}
					}
				}
				$rImportStreams = array();

				if (!empty($_FILES['m3u_file']['tmp_name'])) {
					$rFile = '';

					if (empty($_FILES['m3u_file']['tmp_name']) || strtolower(pathinfo(explode('?', $_FILES['m3u_file']['name'])[0], PATHINFO_EXTENSION)) != 'm3u') {
					} else {
						$rFile = file_get_contents($_FILES['m3u_file']['tmp_name']);
					}

					preg_match_all('/(?P<tag>#EXTINF:[-1,0])|(?:(?P<prop_key>[-a-z]+)=\\"(?P<prop_val>[^"]+)")|(?<name>,[^\\r\\n]+)|(?<url>http[^\\s]*:\\/\\/.*\\/.*)/', $rFile, $rMatches);
					$rResults = array();
					$rIndex = -1;

					for ($i = 0; $i < count($rMatches[0]); $i++) {
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
						if (empty($rResult['url']) || in_array($rResult['url'], $rStreamDatabase)) {
						} else {
							$rPathInfo = pathinfo(explode('?', $rResult['url'])[0]);

							if (!empty($rPathInfo['extension'])) {
							} else {
								$rPathInfo['extension'] = ($rData['target_container'] ?: 'mp4');
							}

							$rImportStreams[] = array('url' => $rResult['url'], 'title' => ($rResult['name'] ?: ''), 'container' => ($rData['movie_symlink'] || $rData['direct_source'] ? $rPathInfo['extension'] : $rData['target_container']));
						}
					}
				} else {
					if (empty($rData['import_folder'])) {
					} else {
						$rParts = explode(':', $rData['import_folder']);

						if (!is_numeric($rParts[1])) {
						} else {
							if (isset($rData['scan_recursive'])) {
								$rFiles = scanRecursive(intval($rParts[1]), $rParts[2], array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'));
							} else {
								$rFiles = array();

								foreach (listDir(intval($rParts[1]), rtrim($rParts[2], '/'), array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'))['files'] as $rFile) {
									$rFiles[] = rtrim($rParts[2], '/') . '/' . $rFile;
								}
							}

							foreach ($rFiles as $rFile) {
								$rFilePath = 's:' . intval($rParts[1]) . ':' . $rFile;

								if (empty($rFilePath) || in_array($rFilePath, $rStreamDatabase)) {
								} else {
									$rPathInfo = pathinfo($rFile);

									if (!empty($rPathInfo['extension'])) {
									} else {
										$rPathInfo['extension'] = ($rData['target_container'] ?: 'mp4');
									}

									$rImportStreams[] = array('url' => $rFilePath, 'title' => $rPathInfo['filename'], 'container' => ($rData['movie_symlink'] || $rData['direct_source'] ? $rPathInfo['extension'] : $rData['target_container']));
								}
							}
						}
					}
				}

				$rSeriesCategories = array_keys(getCategories('series'));

				if (0 < count($rImportStreams)) {
					$rBouquets = array();

					foreach (json_decode($rData['bouquet_create_list'], true) as $rBouquet) {
						$rPrepare = prepareArray(array('bouquet_name' => $rBouquet, 'bouquet_channels' => array(), 'bouquet_movies' => array(), 'bouquet_series' => array(), 'bouquet_radios' => array()));
						$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rBouquets[] = $db->last_insert_id();
						}
					}

					foreach ($rData['bouquets'] as $rBouquetID) {
						if (!(is_numeric($rBouquetID) && in_array($rBouquetID, array_keys(CoreUtilities::$rBouquets)))) {
						} else {
							$rBouquets[] = intval($rBouquetID);
						}
					}
					unset($rData['bouquets'], $rData['bouquet_create_list']);

					$rCategories = array();

					foreach (json_decode($rData['category_create_list'], true) as $rCategory) {
						$rPrepare = prepareArray(array('category_type' => 'series', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0));
						$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

						if (!$db->query($rQuery, ...$rPrepare['data'])) {
						} else {
							$rCategories[] = $db->last_insert_id();
						}
					}

					foreach ($rData['category_id'] as $rCategoryID) {
						if (!(is_numeric($rCategoryID) && in_array($rCategoryID, $rSeriesCategories))) {
						} else {
							$rCategories[] = intval($rCategoryID);
						}
					}
					unset($rData['category_id'], $rData['category_create_list']);

					$rServerIDs = array();

					foreach (json_decode($rData['server_tree_data'], true) as $rServer) {
						if ($rServer['parent'] == '#') {
						} else {
							$rServerIDs[] = intval($rServer['id']);
						}
					}
					$rWatchCategories = array(1 => WatchService::getWatchCategories(1), 2 => WatchService::getWatchCategories(2));

					foreach ($rImportStreams as $rImportStream) {
						$rData = array('import' => true, 'type' => 'series', 'title' => $rImportStream['title'], 'file' => $rImportStream['url'], 'subtitles' => array(), 'servers' => $rServerIDs, 'fb_category_id' => $rCategories, 'fb_bouquets' => $rBouquets, 'disable_tmdb' => false, 'ignore_no_match' => false, 'bouquets' => array(), 'category_id' => array(), 'language' => CoreUtilities::$rSettings['tmdb_language'], 'watch_categories' => $rWatchCategories, 'read_native' => $rData['read_native'], 'movie_symlink' => $rData['movie_symlink'], 'remove_subtitles' => $rData['remove_subtitles'], 'direct_source' => $rData['direct_source'], 'direct_proxy' => $rData['direct_proxy'], 'auto_encode' => $rRestart, 'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false, 'transcode_profile_id' => $rData['transcode_profile_id'], 'target_container' => $rImportStream['container'], 'max_genres' => intval(CoreUtilities::$rSettings['max_genres']), 'duplicate_tmdb' => true);
						$rCommand = '/usr/bin/timeout 300 ' . PHP_BIN . ' ' . INCLUDES_PATH . 'cli/watch_item.php "' . base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)) . '" > /dev/null 2>/dev/null &';
						shell_exec($rCommand);
					}

					return array('status' => STATUS_SUCCESS);
				} else {
					return array('status' => STATUS_NO_SOURCES, 'data' => $rPostData);
				}
			} else {
				return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
			}
		} else {
			exit();
		}
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
